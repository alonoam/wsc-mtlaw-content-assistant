<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Admin screens: the assistant itself, and its settings (administrators only). */
class MTCA_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_action( 'edit_form_top', array( __CLASS__, 'edit_notice' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
	}

	public static function menu() {
		if ( ! mtca_user_can() ) {
			return;
		}
		add_menu_page( 'עוזר תוכן AI', 'עוזר תוכן AI', 'edit_posts', 'mtca', array( __CLASS__, 'page' ), 'dashicons-edit-large', 26 );
		add_submenu_page( 'mtca', 'הגדרות עוזר התוכן', 'הגדרות', 'manage_options', 'mtca-settings', array( __CLASS__, 'settings_page' ) );
	}

	public static function assets( $hook ) {
		if ( $hook !== 'toplevel_page_mtca' ) {
			return;
		}
		wp_enqueue_style( 'mtca', MTCA_URL . 'assets/admin.css', array(), MTCA_VERSION );
		wp_enqueue_script( 'mtca', MTCA_URL . 'assets/admin.js', array(), MTCA_VERSION, true );
		$types = array();
		foreach ( MTCA_Types::all() as $key => $type ) {
			$types[ $key ] = $type['label'];
		}
		wp_localize_script( 'mtca', 'MTCA', array(
			'root'     => esc_url_raw( rest_url( MTCA_Rest::NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'types'    => $types,
			'fields'   => MTCA_Types::text_fields(),
			'areas'    => MTCA_Types::practice_areas(),
			'lawyers'  => MTCA_Types::lawyers(),
			'outlets'  => MTCA_Types::outlets(),
			'maxFiles' => MTCA_MAX_FILES,
			'hasKey'   => MTCA_Settings::api_key() !== '',
		) );
	}

	public static function page() {
		if ( ! mtca_user_can() ) {
			wp_die( 'אין הרשאה.' );
		}
		echo '<div class="wrap mtca-wrap" dir="rtl"><h1>עוזר תוכן AI</h1><div id="mtca-app"></div></div>';
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'אין הרשאה.' );
		}
		$msg = '';
		if ( isset( $_POST['mtca_save'] ) && check_admin_referer( 'mtca_settings' ) ) {
			MTCA_Settings::save( wp_unslash( $_POST['mtca'] ?? array() ) );
			$msg = 'ההגדרות נשמרו.';
		}
		if ( isset( $_POST['mtca_merge_author'] ) && check_admin_referer( 'mtca_settings' ) ) {
			$res = MTCA_Check::merge_author();
			$msg = is_wp_error( $res ) ? $res->get_error_message() : sprintf( 'השדה אוחד. %d ערכים הועברו.', (int) $res );
		}
		$s     = MTCA_Settings::all();
		$usage = MTCA_Settings::usage();
		$users = get_users( array( 'capability' => 'edit_posts', 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) );
		?>
		<div class="wrap" dir="rtl">
			<h1>הגדרות עוזר התוכן</h1>
			<?php if ( $msg ) : ?><div class="notice notice-success"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'mtca_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>מפתח API</th><td><?php echo MTCA_Settings::api_key() !== '' ? '<code>********</code> מוגדר ב-wp-config.php' : '<strong>חסר.</strong> יש להוסיף ל-wp-config.php: <code>define( \'MTCA_CLAUDE_API_KEY\', \'...\' );</code>'; ?></td></tr>
					<tr><th><label for="mtca-model">מודל</label></th><td><input id="mtca-model" class="regular-text" dir="ltr" name="mtca[model]" value="<?php echo esc_attr( $s['model'] ); ?>" <?php disabled( defined( 'MTCA_CLAUDE_MODEL' ) && MTCA_CLAUDE_MODEL ); ?>></td></tr>
					<tr><th><label for="mtca-inst">הנחיות קבועות של המשרד</label></th><td><textarea id="mtca-inst" class="large-text" rows="8" name="mtca[instructions]"><?php echo esc_textarea( $s['instructions'] ); ?></textarea><p class="description">סגנון, מונחים מועדפים, דברים שאין לכתוב. חל על כל טיוטה.</p></td></tr>
					<tr><th><label for="mtca-disc">שורת הבהרה בסוף סיפור הצלחה</label></th><td><textarea id="mtca-disc" class="large-text" rows="2" name="mtca[disclaimer]"><?php echo esc_textarea( $s['disclaimer'] ); ?></textarea><p class="description">ריק = בלי שורת הבהרה.</p></td></tr>
					<tr><th><label for="mtca-cap">תקרת שימוש חודשית (טוקנים)</label></th><td><input id="mtca-cap" type="number" min="0" step="100000" name="mtca[token_cap]" value="<?php echo (int) $s['token_cap']; ?>"> <span class="description">0 = ללא תקרה. החודש: <?php echo esc_html( number_format_i18n( $usage['input'] + $usage['output'] ) ); ?> טוקנים ב-<?php echo (int) $usage['calls']; ?> פניות.</span></td></tr>
					<tr><th>משתמשים מורשים</th><td>
						<?php foreach ( $users as $u ) : if ( user_can( $u->ID, 'manage_options' ) ) { continue; } ?>
						<label style="display:block"><input type="checkbox" name="mtca[users][]" value="<?php echo (int) $u->ID; ?>" <?php checked( in_array( (int) $u->ID, array_map( 'intval', $s['users'] ), true ) ); ?>> <?php echo esc_html( $u->display_name ); ?></label>
						<?php endforeach; ?>
						<p class="description">מנהלי האתר מורשים תמיד.</p>
					</td></tr>
				</table>
				<p><button class="button button-primary" name="mtca_save" value="1">שמירה</button></p>

				<h2>בדיקת שדות</h2>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>סוג תוכן</th><th>שדה</th><th>מצב</th><th>פירוט</th></tr></thead>
					<tbody>
					<?php foreach ( MTCA_Check::report() as $row ) : $icon = array( 'ok' => '✔', 'warn' => '⚠', 'bad' => '✖' ); ?>
						<tr><td><?php echo esc_html( $row[0] ); ?></td><td dir="ltr" style="text-align:right"><code><?php echo esc_html( $row[1] ); ?></code></td><td><?php echo esc_html( $icon[ $row[2] ] ); ?></td><td><?php echo esc_html( $row[3] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( MTCA_Check::can_merge_author() ) : ?>
				<p>במן העיתונות שדה עורך הדין נקרא <code>athor</code> (שגיאת כתיב ישנה). האיחוד משנה את שם השדה ל-<code>author</code> ומעביר את הערכים הקיימים. נדרשת תבנית בגרסה 1.61 ומעלה, ומומלץ גיבוי של מסד הנתונים לפני כן.</p>
				<p><button class="button" name="mtca_merge_author" value="1" onclick="return confirm('לאחד את השדה athor לתוך author? הפעולה משנה נתונים במסד הנתונים.');">איחוד השדה</button></p>
				<?php endif; ?>

				<h2>מאגר תמונות האווירה</h2>
				<table class="widefat striped" style="max-width:500px">
					<thead><tr><th>תחום</th><th>תמונות במאגר</th></tr></thead>
					<tbody>
					<?php foreach ( MTCA_Images::coverage() as $name => $n ) : ?>
						<tr><td><?php echo esc_html( $name ); ?></td><td><?php echo (int) $n; ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">תמונה מצטרפת למאגר ממסך העריכה שלה בספריית המדיה (שדה "כלי ה-AI").</p>
			</form>
		</div>
		<?php
	}

	/* Posts created by the assistant */

	public static function post_state( $states, $post ) {
		if ( get_post_meta( $post->ID, '_mtca_ai_draft', true ) === '1' ) {
			$states['mtca'] = 'טיוטת AI, ממתינה לאישור';
		}
		return $states;
	}

	public static function edit_notice( $post ) {
		if ( get_post_meta( $post->ID, '_mtca_ai_draft', true ) !== '1' ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p><strong>טיוטה שנוצרה בעוזר התוכן.</strong> יש לקרוא ולאמת מול המקור לפני פרסום: שמות ופרטים מזהים, סכומים ואחוזים, ותוצאת ההליך.</p></div>';
	}

	/** Publishing is the approval: record who and when, and drop the "AI draft" state. */
	public static function on_publish( $new, $old, $post ) {
		if ( $new === 'publish' && $old !== 'publish' && get_post_meta( $post->ID, '_mtca_ai_draft', true ) === '1' ) {
			update_post_meta( $post->ID, '_mtca_ai_draft', '0' );
			update_post_meta( $post->ID, '_mtca_approved', array( 'user' => get_current_user_id(), 'time' => time() ) );
		}
	}
}
