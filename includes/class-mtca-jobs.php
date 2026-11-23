<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A job = one source on its way to a draft post. Stored as a private CPT
 * (mtca_job) with meta; never shown on the site.
 * Status: queued -> processing -> ready -> created, or failed.
 */
class MTCA_Jobs {

	const CPT = 'mtca_job';

	public static function register() {
		register_post_type( self::CPT, array(
			'public'              => false,
			'show_ui'             => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'rewrite'             => false,
			'supports'            => array( 'title', 'author' ),
			'can_export'          => false,
		) );
	}

	public static function create( $args ) {
		$id = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_status' => 'private',
			'post_title'  => sanitize_text_field( $args['label'] ),
			'post_author' => get_current_user_id(),
		), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$meta = array(
			'type'        => $args['type'],
			'source_kind' => $args['source_kind'],
			'file'        => $args['file'] ?? '',
			'url'         => $args['url'] ?? '',
			'source_text' => $args['source_text'] ?? '',
			'notes'       => $args['notes'] ?? '',
			'status'      => 'queued',
			'error'       => '',
			'draft'       => array(),
			'chat'        => array(),
			'selected'    => array(),
			'post_id'     => 0,
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, '_mtca_' . $k, wp_slash( $v ) );
		}
		return (int) $id;
	}

	/** The job as an array, or null. */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== self::CPT ) {
			return null;
		}
		$job = array( 'id' => (int) $post->ID, 'label' => $post->post_title, 'user' => (int) $post->post_author, 'date' => $post->post_date );
		foreach ( array( 'type', 'source_kind', 'file', 'url', 'source_text', 'notes', 'status', 'error', 'draft', 'chat', 'selected', 'post_id' ) as $k ) {
			$job[ $k ] = get_post_meta( $post->ID, '_mtca_' . $k, true );
		}
		foreach ( array( 'draft', 'chat', 'selected' ) as $k ) {
			$job[ $k ] = is_array( $job[ $k ] ) ? $job[ $k ] : array();
		}
		return $job;
	}

	public static function set( $id, $key, $value ) {
		update_post_meta( (int) $id, '_mtca_' . $key, wp_slash( $value ) );
	}

	/** May this user open this job? Its owner, or an administrator. */
	public static function can_access( $job ) {
		return $job && mtca_user_can() && ( (int) $job['user'] === get_current_user_id() || current_user_can( 'manage_options' ) );
	}

	public static function recent( $limit = 40 ) {
		$args = array( 'post_type' => self::CPT, 'post_status' => 'private', 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'suppress_filters' => true );
		if ( ! current_user_can( 'manage_options' ) ) {
			$args['author'] = get_current_user_id();
		}
		$out = array();
		foreach ( get_posts( $args ) as $id ) {
			$job = self::get( $id );
			if ( $job ) {
				$out[] = self::public_view( $job, false );
			}
		}
		return $out;
	}

	/** What the browser gets: no source text, no file name. */
	public static function public_view( $job, $full = true ) {
		$type = MTCA_Types::get( $job['type'] );
		$view = array(
			'id'          => $job['id'],
			'label'       => $job['label'],
			'type'        => $job['type'],
			'type_label'  => $type ? $type['label'] : $job['type'],
			'source_kind' => $job['source_kind'],
			'url'         => $job['url'],
			'status'      => $job['status'],
			'error'       => $job['error'],
			'date'        => $job['date'],
			'post_id'     => (int) $job['post_id'],
			'edit_link'   => $job['post_id'] ? get_edit_post_link( (int) $job['post_id'], 'raw' ) : '',
		);
		if ( $full ) {
			$view['notes']    = $job['notes'];
			$view['draft']    = $job['draft'];
			$view['chat']     = $job['chat'];
			$view['selected'] = $job['selected'];
			$view['warnings'] = self::warnings( $job );
		}
		return $view;
	}

	/** Generate the first draft. Returns the updated job or WP_Error. */
	public static function process( $id ) {
		$job = self::get( $id );
		if ( ! $job ) {
			return new WP_Error( 'mtca_nf', 'הפריט לא נמצא.' );
		}
		if ( $job['status'] === 'processing' ) {
			return new WP_Error( 'mtca_busy', 'הפריט כבר בעיבוד.' );
		}
		self::set( $id, 'status', 'processing' );
		self::set( $id, 'error', '' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$draft = MTCA_Claude::draft( $job );
		if ( is_wp_error( $draft ) ) {
			self::set( $id, 'status', 'failed' );
			self::set( $id, 'error', $draft->get_error_message() );
			return self::get( $id );
		}
		$draft = self::clean_draft( $draft );
		self::set( $id, 'draft', $draft );
		self::set( $id, 'selected', self::suggest_selection( $job['type'], $draft ) );
		self::set( $id, 'status', 'ready' );
		if ( ! empty( $draft['post_title'] ) ) {
			wp_update_post( array( 'ID' => (int) $id, 'post_title' => wp_strip_all_tags( $draft['post_title'] ) ) );
		}
		return self::get( $id );
	}

	/** One chat turn. Returns the updated job or WP_Error. */
	public static function chat( $id, $message ) {
		$job = self::get( $id );
		if ( ! $job || empty( $job['draft'] ) ) {
			return new WP_Error( 'mtca_nf', 'אין טיוטה לעדכן.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$res = MTCA_Claude::revise( $job, $message );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$draft = $job['draft'];
		if ( $res['changes'] ) {
			$draft = self::clean_draft( array_merge( $draft, $res['changes'] ) );
			self::set( $id, 'draft', $draft );
			// a changed name re-matches its site entry; the lawyer's own picks stay otherwise
			$selected  = $job['selected'];
			$suggested = self::suggest_selection( $job['type'], $draft );
			foreach ( array( 'practice_area' => 'term', 'lawyer' => 'lawyer', 'outlet' => 'outlet' ) as $field => $sel ) {
				if ( array_key_exists( $field, $res['changes'] ) ) {
					$selected[ $sel ] = $suggested[ $sel ];
				}
			}
			self::set( $id, 'selected', $selected );
		}
		$chat   = $job['chat'];
		$chat[] = array( 'role' => 'user', 'text' => $message );
		$chat[] = array( 'role' => 'assistant', 'text' => $res['reply'], 'changed' => array_keys( $res['changes'] ) );
		self::set( $id, 'chat', array_slice( $chat, -60 ) );
		return self::get( $id );
	}

	/** Allowed HTML in the body, plain text elsewhere, known keys only. */
	public static function clean_draft( $draft ) {
		$out = array();
		foreach ( MTCA_Types::text_fields() as $key => $def ) {
			$val = (string) ( $draft[ $key ] ?? '' );
			if ( $def[1] === 'html' ) {
				$val = wp_kses( $val, array( 'p' => array(), 'h2' => array(), 'ul' => array(), 'li' => array(), 'strong' => array(), 'br' => array() ) );
			} else {
				$val = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $val ) ) );
			}
			$out[ $key ] = str_replace( array( '—', '–' ), '-', $val );
		}
		foreach ( array( 'practice_area', 'lawyer', 'outlet' ) as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $draft[ $key ] ?? '' ) );
		}
		$out['facts'] = array();
		foreach ( (array) ( $draft['facts'] ?? array() ) as $fact ) {
			if ( is_array( $fact ) && isset( $fact['label'], $fact['value'] ) ) {
				$out['facts'][] = array(
					'label' => sanitize_text_field( (string) $fact['label'] ),
					'value' => sanitize_text_field( (string) $fact['value'] ),
					'quote' => sanitize_textarea_field( (string) ( $fact['quote'] ?? '' ) ),
				);
			}
		}
		$flags        = is_array( $draft['flags'] ?? null ) ? $draft['flags'] : array();
		$out['flags'] = array(
			'publication_ban' => ! empty( $flags['publication_ban'] ),
			'closed_doors'    => ! empty( $flags['closed_doors'] ),
			'minor'           => ! empty( $flags['minor'] ),
			'notes'           => sanitize_textarea_field( (string) ( $flags['notes'] ?? '' ) ),
		);
		return $out;
	}

	/** Site entries matching the names in the draft, plus an image from the pool. */
	public static function suggest_selection( $type_key, $draft ) {
		$term = MTCA_Types::match( $draft['practice_area'] ?? '', MTCA_Types::practice_areas() );
		return array(
			'term'   => $term,
			'lawyer' => MTCA_Types::match( $draft['lawyer'] ?? '', MTCA_Types::lawyers() ),
			'outlet' => $type_key === 'journal' ? MTCA_Types::match( $draft['outlet'] ?? '', MTCA_Types::outlets() ) : 0,
			'image'  => MTCA_Images::pick( $term ),
		);
	}

	/**
	 * What the lawyer must look at before a draft post is created.
	 * 'block' items need an explicit acknowledgement.
	 */
	public static function warnings( $job ) {
		$w     = array();
		$draft = $job['draft'];
		if ( empty( $draft ) ) {
			return $w;
		}
		$flags = $draft['flags'] ?? array();
		if ( ! empty( $flags['publication_ban'] ) ) {
			$w[] = array( 'level' => 'block', 'text' => 'במקור מופיע איסור פרסום או הגבלת פרסום.' );
		}
		if ( ! empty( $flags['closed_doors'] ) ) {
			$w[] = array( 'level' => 'block', 'text' => 'הדיון התקיים בדלתיים סגורות.' );
		}
		if ( ! empty( $flags['minor'] ) ) {
			$w[] = array( 'level' => 'block', 'text' => 'במקור מעורב קטין.' );
		}
		if ( ! empty( $flags['notes'] ) ) {
			$w[] = array( 'level' => 'info', 'text' => $flags['notes'] );
		}
		$sel = $job['selected'];
		if ( empty( $sel['lawyer'] ) ) {
			$w[] = array( 'level' => 'warn', 'text' => 'לא נבחר עורך דין. בלי עורך דין חתום הפוסט לא יופיע באף פרופיל.' );
		}
		if ( empty( $sel['term'] ) ) {
			$w[] = array( 'level' => 'warn', 'text' => 'לא נבחר תחום עיסוק.' );
		}
		if ( $job['type'] === 'journal' && empty( $sel['outlet'] ) ) {
			$w[] = array( 'level' => 'warn', 'text' => 'כלי התקשורת "' . ( $draft['outlet'] ?? '' ) . '" אינו ברשימה באתר. יש להוסיף אותו ואת הלוגו שלו ידנית.' );
		}
		foreach ( (array) ( $draft['facts'] ?? array() ) as $fact ) {
			if ( trim( (string) $fact['quote'] ) === '' ) {
				$w[] = array( 'level' => 'warn', 'text' => 'נתון ללא ציטוט מהמקור: ' . $fact['label'] . ' (' . $fact['value'] . ')' );
			}
		}
		if ( $job['type'] === 'journal' && $job['source_text'] !== '' ) {
			$copied = self::copied_runs( $draft['text'] ?? '', $job['source_text'] );
			if ( $copied > 1 ) {
				$w[] = array( 'level' => 'warn', 'text' => sprintf( 'נמצאו %d קטעים שהועתקו מהכתבה כלשונם. מותר ציטוט קצר אחד.', $copied ) );
			}
		}
		return $w;
	}

	/** Number of 10-word runs of the draft that appear verbatim in the source. */
	public static function copied_runs( $draft_html, $source ) {
		$norm  = function ( $s ) {
			return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
		};
		$words = preg_split( '/\s+/u', $norm( $draft_html ) );
		$src   = ' ' . $norm( $source ) . ' ';
		$hits  = 0;
		for ( $i = 0; $i + 10 <= count( $words ); $i++ ) {
			if ( mb_strpos( $src, ' ' . implode( ' ', array_slice( $words, $i, 10 ) ) . ' ' ) !== false ) {
				$hits++;
				$i += 9;
			}
		}
		return $hits;
	}

	public static function delete( $id ) {
		$job = self::get( $id );
		if ( ! $job ) {
			return;
		}
		// the source file stays while a created post still points at it
		if ( $job['file'] && ! $job['post_id'] ) {
			MTCA_Storage::delete( $job['file'] );
		}
		wp_delete_post( (int) $id, true );
	}
}
