/* MT Content Assistant: admin screen. Plain JS, no build step. */
(function () {
	'use strict';
	var C = window.MTCA, app = document.getElementById('mtca-app');
	if (!C || !app) { return; }

	var state = { jobs: [], job: null, busy: false, queue: [] };
	var STATUS = { queued: 'ממתין', processing: 'בעיבוד', ready: 'טיוטה מוכנה', failed: 'נכשל', created: 'נוצר פוסט' };

	function el(tag, attrs, kids) {
		var n = document.createElement(tag), k;
		for (k in (attrs || {})) {
			if (k === 'text') { n.textContent = attrs[k]; }
			else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), attrs[k]); }
			else if (attrs[k] !== false && attrs[k] != null) { n.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
		}
		(kids || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}

	function api(method, path, body) {
		var opt = { method: method, headers: { 'X-WP-Nonce': C.nonce }, credentials: 'same-origin' };
		if (body instanceof FormData) { opt.body = body; }
		else if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
		return fetch(C.root + path, opt).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { throw new Error(j && j.message ? j.message : 'שגיאה ' + r.status); }
				return j;
			});
		});
	}

	function toast(msg, bad) {
		var t = el('div', { class: 'mtca-toast' + (bad ? ' is-bad' : ''), role: 'status', text: msg });
		document.body.appendChild(t);
		setTimeout(function () { t.remove(); }, 6000);
	}

	/* Queue: drafts are generated one at a time, driven from the browser. */
	function runQueue() {
		if (state.busy || !state.queue.length) { return; }
		var id = state.queue.shift();
		state.busy = true;
		markStatus(id, 'processing');
		api('POST', '/jobs/' + id + '/process').then(function (job) {
			replaceJob(job);
			if (state.job && state.job.id === job.id) { state.job = job; }
		}).catch(function (e) {
			markStatus(id, 'failed', e.message);
		}).then(function () {
			state.busy = false;
			render();
			runQueue();
		});
		render();
	}
	function markStatus(id, status, error) {
		state.jobs.forEach(function (j) { if (j.id === id) { j.status = status; j.error = error || ''; } });
	}
	function replaceJob(job) {
		state.jobs = state.jobs.map(function (j) { return j.id === job.id ? job : j; });
	}

	function loadJobs() {
		return api('GET', '/jobs').then(function (jobs) { state.jobs = jobs; render(); });
	}
	function openJob(id) {
		api('GET', '/jobs/' + id).then(function (job) { state.job = job; render(); }).catch(function (e) { toast(e.message, true); });
	}

	/* Upload form */
	function uploadForm() {
		var type = el('select', { id: 'mtca-type' }, Object.keys(C.types).map(function (k) { return el('option', { value: k, text: C.types[k] }); }));
		var files = el('input', { type: 'file', id: 'mtca-files', accept: 'application/pdf', multiple: true });
		var url = el('input', { type: 'url', id: 'mtca-url', dir: 'ltr', placeholder: 'https://' });
		var notes = el('textarea', { id: 'mtca-notes', rows: '4', placeholder: 'למשל: להדגיש את קביעת בית הדין לגבי הקשר הסיבתי. לא להזכיר את שם המעסיק.' });
		var btn = el('button', { class: 'button button-primary', type: 'submit', text: 'צור טיוטות' });
		return el('form', {
			class: 'mtca-card', onsubmit: function (e) {
				e.preventDefault();
				if (files.files.length > C.maxFiles) { toast('אפשר להעלות עד ' + C.maxFiles + ' קבצים בבת אחת.', true); return; }
				var fd = new FormData();
				fd.append('type', type.value); fd.append('notes', notes.value); fd.append('url', url.value);
				Array.prototype.forEach.call(files.files, function (f) { fd.append('files[]', f); });
				btn.disabled = true;
				api('POST', '/jobs', fd).then(function (res) {
					(res.errors || []).forEach(function (m) { toast(m, true); });
					(res.jobs || []).forEach(function (j) { state.jobs.unshift(j); state.queue.push(j.id); });
					files.value = ''; url.value = '';
					render(); runQueue();
				}).catch(function (e) { toast(e.message, true); }).then(function () { btn.disabled = false; });
			}
		}, [
			el('h2', { text: 'מקור חדש' }),
			C.hasKey ? null : el('p', { class: 'mtca-warn is-block', text: 'מפתח ה-API לא מוגדר ב-wp-config.php. אי אפשר ליצור טיוטות.' }),
			el('label', { for: 'mtca-type', text: 'סוג התוכן' }), type,
			el('label', { for: 'mtca-files', text: 'קובצי PDF (עד ' + C.maxFiles + ')' }), files,
			el('label', { for: 'mtca-url', text: 'או קישור לעמוד ציבורי' }), url,
			el('label', { for: 'mtca-notes', text: 'בקשות ודגשים' }), notes,
			btn
		]);
	}

	function jobList() {
		var items = state.jobs.map(function (j) {
			return el('li', { class: 'mtca-job is-' + j.status + (state.job && state.job.id === j.id ? ' is-open' : '') }, [
				el('button', { type: 'button', class: 'mtca-job__open', onclick: function () { openJob(j.id); } }, [
					el('span', { class: 'mtca-job__title', text: j.label }),
					el('span', { class: 'mtca-job__meta', text: j.type_label + ' · ' + (STATUS[j.status] || j.status) })
				]),
				j.status === 'failed' ? el('span', { class: 'mtca-job__err', text: j.error }) : null,
				j.status === 'failed' ? el('button', { type: 'button', class: 'button-link', text: 'נסה שוב', onclick: function () { state.queue.push(j.id); runQueue(); } }) : null
			]);
		});
		return el('div', { class: 'mtca-card' }, [el('h2', { text: 'פריטים' }), items.length ? el('ul', { class: 'mtca-jobs' }, items) : el('p', { class: 'description', text: 'עדיין אין פריטים.' })]);
	}

	/* Job view */
	function select(label, id, list, value, onchange) {
		var opts = [el('option', { value: '0', text: 'לא נבחר' })].concat(Object.keys(list).map(function (k) {
			return el('option', { value: k, text: list[k], selected: String(value) === String(k) });
		}));
		return el('div', { class: 'mtca-field' }, [el('label', { for: id, text: label }), el('select', { id: id, onchange: onchange }, opts)]);
	}

	function jobView() {
		var job = state.job;
		if (!job) { return el('div', { class: 'mtca-card mtca-empty' }, [el('p', { text: 'בחרו פריט מהרשימה, או העלו מקור חדש.' })]); }
		var head = el('div', { class: 'mtca-head' }, [
			el('h2', { text: job.label }),
			el('span', { class: 'mtca-badge is-' + job.status, text: STATUS[job.status] || job.status }),
			el('button', { type: 'button', class: 'button-link mtca-del', text: 'מחיקה', onclick: function () {
				if (!window.confirm('למחוק את הפריט ואת קובץ המקור שלו?')) { return; }
				api('DELETE', '/jobs/' + job.id).then(function () { state.job = null; return loadJobs(); }).catch(function (e) { toast(e.message, true); });
			} })
		]);
		if (job.status === 'created') {
			return el('div', { class: 'mtca-card' }, [head, el('p', { text: 'נוצרה טיוטת פוסט. הפרסום נעשה ממסך העריכה, אחרי קריאה ואימות.' }), el('a', { class: 'button button-primary', href: job.edit_link, text: 'פתיחת הטיוטה לעריכה' })]);
		}
		if (job.status !== 'ready') {
			return el('div', { class: 'mtca-card' }, [head, el('p', { text: job.status === 'failed' ? job.error : 'הטיוטה בהכנה. מסמך ארוך יכול לקחת כמה דקות.' })]);
		}

		var d = job.draft, sel = job.selected || {}, inputs = {};
		var blocking = (job.warnings || []).some(function (w) { return w.level === 'block'; });
		var warns = (job.warnings || []).map(function (w) { return el('p', { class: 'mtca-warn is-' + w.level, text: w.text }); });

		var fields = Object.keys(C.fields).map(function (key) {
			var def = C.fields[key], input, count = el('span', { class: 'mtca-count' });
			if (def[1] === 'html') { input = el('textarea', { id: 'mtca-f-' + key, rows: '16', dir: 'rtl' }); }
			else { input = el('input', { id: 'mtca-f-' + key, type: 'text' }); }
			input.value = d[key] || '';
			function upd() { if (def[2]) { count.textContent = input.value.length + ' / ' + def[2]; count.className = 'mtca-count' + (input.value.length > def[2] ? ' is-over' : ''); } }
			input.addEventListener('input', upd); upd();
			inputs[key] = input;
			return el('div', { class: 'mtca-field' }, [el('label', { for: 'mtca-f-' + key, text: def[0] }), input, count]);
		});

		var picked = { term: sel.term || 0, lawyer: sel.lawyer || 0, outlet: sel.outlet || 0, image: sel.image || 0 };
		var imgBox = el('div', { class: 'mtca-images' });
		function loadImages() {
			api('GET', '/images?term=' + picked.term).then(function (rows) {
				imgBox.textContent = '';
				if (!rows.length) { imgBox.appendChild(el('p', { class: 'description', text: 'אין תמונות במאגר לתחום זה. מוסיפים תמונות מספריית המדיה.' })); return; }
				rows.slice(0, 12).forEach(function (r) {
					imgBox.appendChild(el('button', { type: 'button', class: 'mtca-img' + (r.id === picked.image ? ' is-picked' : ''), 'aria-pressed': r.id === picked.image ? 'true' : 'false', onclick: function () { picked.image = r.id; loadImages(); } }, [el('img', { src: r.thumb, alt: r.alt || 'תמונת אווירה' })]));
				});
			});
		}
		loadImages();

		var picks = el('div', { class: 'mtca-picks' }, [
			select('תחום עיסוק' + (d.practice_area ? ' (הצעה: ' + d.practice_area + ')' : ''), 'mtca-s-term', C.areas, picked.term, function (e) { picked.term = parseInt(e.target.value, 10); loadImages(); }),
			select('עורך הדין החתום' + (d.lawyer ? ' (הצעה: ' + d.lawyer + ')' : ''), 'mtca-s-lawyer', C.lawyers, picked.lawyer, function (e) { picked.lawyer = parseInt(e.target.value, 10); }),
			job.type === 'journal' ? select('כלי התקשורת' + (d.outlet ? ' (זוהה: ' + d.outlet + ')' : ''), 'mtca-s-outlet', C.outlets, picked.outlet, function (e) { picked.outlet = parseInt(e.target.value, 10); }) : null
		]);

		var facts = (d.facts || []).length ? el('table', { class: 'widefat striped mtca-facts' }, [
			el('thead', {}, [el('tr', {}, [el('th', { text: 'נתון' }), el('th', { text: 'ערך' }), el('th', { text: 'ציטוט מהמקור' })])]),
			el('tbody', {}, d.facts.map(function (f) { return el('tr', {}, [el('td', { text: f.label }), el('td', { text: f.value }), el('td', { text: f.quote || 'אין ציטוט', class: f.quote ? '' : 'mtca-noquote' })]); }))
		]) : el('p', { class: 'description', text: 'לא סומנו נתונים עובדתיים.' });

		function payload() {
			var f = {}; Object.keys(inputs).forEach(function (k) { f[k] = inputs[k].value; });
			return { fields: f, selected: picked };
		}
		function save() { return api('POST', '/jobs/' + job.id + '/fields', payload()).then(function (j) { state.job = j; replaceJob(j); return j; }); }

		var ack = el('input', { type: 'checkbox', id: 'mtca-ack' });
		var saveBtn = el('button', { type: 'button', class: 'button', text: 'שמירת שינויים', onclick: function () { save().then(function () { toast('נשמר.'); render(); }).catch(function (e) { toast(e.message, true); }); } });
		var createBtn = el('button', { type: 'button', class: 'button button-primary', text: 'צור טיוטת פוסט', onclick: function () {
			if (blocking && !ack.checked) { toast('יש לאשר את ההתרעות לפני יצירת הטיוטה.', true); return; }
			createBtn.disabled = true;
			save().then(function () { return api('POST', '/jobs/' + job.id + '/create', { ack: ack.checked }); }).then(function (res) {
				state.job = res.job; replaceJob(res.job);
				if (res.missing && res.missing.length) { toast('שדות שלא נמצאו ב-ACF ונשמרו כמטא רגיל: ' + res.missing.join(', '), true); }
				render();
			}).catch(function (e) { toast(e.message, true); createBtn.disabled = false; });
		} });

		/* Chat */
		var log = el('div', { class: 'mtca-chat__log', 'aria-live': 'polite' }, (job.chat || []).map(function (t) {
			return el('div', { class: 'mtca-msg is-' + t.role }, [el('p', { text: t.text }), t.changed && t.changed.length ? el('span', { class: 'mtca-msg__changed', text: 'עודכן: ' + t.changed.map(function (k) { return C.fields[k] ? C.fields[k][0] : k; }).join(', ') }) : null]);
		}));
		var msg = el('textarea', { rows: '3', id: 'mtca-msg', placeholder: 'למשל: קצר את הפתיח לשתי שורות. הדגש את אחוזי הנכות שנקבעו.' });
		var send = el('button', { type: 'submit', class: 'button button-primary', text: 'שליחה' });
		var chat = el('form', { class: 'mtca-chat', onsubmit: function (e) {
			e.preventDefault();
			var text = msg.value.trim(); if (!text) { return; }
			send.disabled = true; send.textContent = 'חושב...';
			// keep manual edits: save them first, then ask
			save().then(function () { return api('POST', '/jobs/' + job.id + '/chat', { message: text }); }).then(function (j) {
				state.job = j; replaceJob(j); render();
			}).catch(function (err) { toast(err.message, true); send.disabled = false; send.textContent = 'שליחה'; });
		} }, [el('h3', { text: 'שיחה על הטיוטה' }), log, el('label', { for: 'mtca-msg', class: 'screen-reader-text', text: 'הודעה' }), msg, send]);

		return el('div', { class: 'mtca-card' }, [
			head,
			el('div', { class: 'mtca-warns' }, warns),
			el('div', { class: 'mtca-cols' }, [
				el('div', { class: 'mtca-col' }, fields.concat([picks, el('h3', { text: 'תמונת אווירה' }), imgBox])),
				el('div', { class: 'mtca-col' }, [el('h3', { text: 'נתונים לאימות מול המקור' }), facts, chat])
			]),
			el('div', { class: 'mtca-actions' }, [
				blocking ? el('label', { class: 'mtca-ack' }, [ack, ' קראתי את ההתרעות ואני מאשר/ת ליצור טיוטה']) : null,
				saveBtn, createBtn,
				el('span', { class: 'description', text: 'נוצרת טיוטה בלבד. הפרסום נעשה ידנית ממסך העריכה.' })
			])
		]);
	}

	function render() {
		var y = window.scrollY;
		app.textContent = '';
		app.appendChild(el('div', { class: 'mtca-layout' }, [
			el('div', { class: 'mtca-side' }, [uploadForm(), jobList()]),
			el('div', { class: 'mtca-main' }, [jobView()])
		]));
		var log = app.querySelector('.mtca-chat__log'); if (log) { log.scrollTop = log.scrollHeight; }
		window.scrollTo(0, y);
	}

	render();
	loadJobs().then(function () {
		// resume anything left queued by an interrupted session
		state.jobs.forEach(function (j) { if (j.status === 'queued') { state.queue.push(j.id); } });
		runQueue();
	});
})();
