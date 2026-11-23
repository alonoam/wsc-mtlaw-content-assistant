<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** REST API for the admin screen. Every route needs a logged-in, authorised user (cookie + nonce). */
class MTCA_Rest {

	const NS = 'mtca/v1';

	public static function register() {
		$perm = function () {
			return mtca_user_can();
		};
		$routes = array(
			array( '/jobs', 'GET', 'list_jobs' ),
			array( '/jobs', 'POST', 'create_jobs' ),
			array( '/jobs/(?P<id>\d+)', 'GET', 'get_job' ),
			array( '/jobs/(?P<id>\d+)', 'DELETE', 'delete_job' ),
			array( '/jobs/(?P<id>\d+)/process', 'POST', 'process_job' ),
			array( '/jobs/(?P<id>\d+)/chat', 'POST', 'chat' ),
			array( '/jobs/(?P<id>\d+)/fields', 'POST', 'save_fields' ),
			array( '/jobs/(?P<id>\d+)/create', 'POST', 'create_post' ),
			array( '/images', 'GET', 'images' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route( self::NS, $r[0], array( 'methods' => $r[1], 'callback' => array( __CLASS__, $r[2] ), 'permission_callback' => $perm ) );
		}
	}

	private static function job_or_error( $request ) {
		$job = MTCA_Jobs::get( (int) $request['id'] );
		return MTCA_Jobs::can_access( $job ) ? $job : new WP_Error( 'mtca_nf', 'הפריט לא נמצא.', array( 'status' => 404 ) );
	}

	private static function view( $job ) {
		return is_wp_error( $job ) ? $job : rest_ensure_response( MTCA_Jobs::public_view( $job ) );
	}

	public static function list_jobs() {
		return rest_ensure_response( MTCA_Jobs::recent() );
	}

	public static function get_job( $request ) {
		return self::view( self::job_or_error( $request ) );
	}

	/** One job per uploaded PDF (up to MTCA_MAX_FILES), or one job for a link. */
	public static function create_jobs( $request ) {
		$type  = sanitize_key( (string) $request->get_param( 'type' ) );
		$notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		$url   = esc_url_raw( trim( (string) $request->get_param( 'url' ) ) );
		if ( ! MTCA_Types::get( $type ) ) {
			return new WP_Error( 'mtca_type', 'סוג תוכן לא מוכר.', array( 'status' => 400 ) );
		}
		$created = array();
		$errors  = array();

		$files = $request->get_file_params();
		$files = isset( $files['files'] ) ? $files['files'] : array();
		$count = isset( $files['name'] ) ? count( (array) $files['name'] ) : 0;
		if ( $count > MTCA_MAX_FILES ) {
			return new WP_Error( 'mtca_many', sprintf( 'אפשר להעלות עד %d קבצים בבת אחת.', MTCA_MAX_FILES ), array( 'status' => 400 ) );
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$name = sanitize_file_name( (string) ( (array) $files['name'] )[ $i ] );
			$tmp  = (string) ( (array) $files['tmp_name'] )[ $i ];
			$size = (int) ( (array) $files['size'] )[ $i ];
			if ( (int) ( (array) $files['error'] )[ $i ] !== UPLOAD_ERR_OK || ! is_uploaded_file( $tmp ) ) {
				$errors[] = $name . ': ההעלאה נכשלה.';
				continue;
			}
			$bad = MTCA_Storage::validate_pdf( $tmp, $size );
			if ( $bad ) {
				$errors[] = $name . ': ' . $bad;
				continue;
			}
			$stored = MTCA_Storage::store_upload( $tmp );
			if ( is_wp_error( $stored ) ) {
				$errors[] = $name . ': ' . $stored->get_error_message();
				continue;
			}
			$id = MTCA_Jobs::create( array( 'label' => $name, 'type' => $type, 'source_kind' => 'pdf', 'file' => $stored, 'notes' => $notes ) );
			if ( ! is_wp_error( $id ) ) {
				$created[] = MTCA_Jobs::public_view( MTCA_Jobs::get( $id ), false );
			}
		}

		if ( $url !== '' ) {
			$src = self::fetch_url( $url );
			if ( is_wp_error( $src ) ) {
				$errors[] = $src->get_error_message();
			} else {
				$id = MTCA_Jobs::create( array_merge( $src, array( 'label' => $url, 'type' => $type, 'url' => $url, 'notes' => $notes ) ) );
				if ( ! is_wp_error( $id ) ) {
					$created[] = MTCA_Jobs::public_view( MTCA_Jobs::get( $id ), false );
				}
			}
		}
		if ( ! $created && ! $errors ) {
			$errors[] = 'לא נבחר קובץ ולא הוזן קישור.';
		}
		return rest_ensure_response( array( 'jobs' => $created, 'errors' => $errors ) );
	}

	/** Read a public link: a PDF is stored like an upload, an HTML page is reduced to its text. */
	private static function fetch_url( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'mtca_url', 'הקישור אינו תקין.' );
		}
		$res = wp_safe_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3, 'limit_response_size' => MTCA_MAX_PDF_BYTES, 'user-agent' => 'Mozilla/5.0 (compatible; MTContentAssistant/' . MTCA_VERSION . ')' ) );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return new WP_Error( 'mtca_url', 'לא ניתן לקרוא את הקישור. אם העמוד דורש התחברות או תשלום, העלו את המסמך כ-PDF.' );
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( strpos( $body, '%PDF-' ) === 0 ) {
			$stored = MTCA_Storage::store_bytes( $body );
			return is_wp_error( $stored ) ? $stored : array( 'source_kind' => 'pdf', 'file' => $stored );
		}
		$html = preg_replace( '#<(script|style|noscript|nav|header|footer|aside|form|svg)\b[^>]*>.*?</\1>#is', ' ', $body );
		$text = trim( preg_replace( '/[ \t]+/u', ' ', preg_replace( '/\s*\n\s*/u', "\n", html_entity_decode( wp_strip_all_tags( preg_replace( '#</(p|div|h[1-6]|li|br)>#i', "\n", $html ) ), ENT_QUOTES, 'UTF-8' ) ) ) );
		if ( mb_strlen( $text ) < 300 ) {
			return new WP_Error( 'mtca_url', 'לא נמצא טקסט בעמוד (ייתכן שהוא נטען ב-JavaScript או חסום). העלו את המסמך כ-PDF.' );
		}
		return array( 'source_kind' => 'url', 'source_text' => mb_substr( $text, 0, 60000 ) );
	}

	public static function process_job( $request ) {
		$job = self::job_or_error( $request );
		return is_wp_error( $job ) ? $job : self::view( MTCA_Jobs::process( $job['id'] ) );
	}

	public static function chat( $request ) {
		$job = self::job_or_error( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( $message === '' ) {
			return new WP_Error( 'mtca_empty', 'ההודעה ריקה.', array( 'status' => 400 ) );
		}
		return self::view( MTCA_Jobs::chat( $job['id'], $message ) );
	}

	/** Manual edits from the form: text fields and the site entries picked by the lawyer. */
	public static function save_fields( $request ) {
		$job = self::job_or_error( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( $job['status'] !== 'ready' ) {
			return new WP_Error( 'mtca_state', 'אי אפשר לערוך פריט במצב זה.', array( 'status' => 400 ) );
		}
		$in    = (array) $request->get_param( 'fields' );
		$draft = $job['draft'];
		foreach ( MTCA_Types::text_fields() as $key => $def ) {
			if ( array_key_exists( $key, $in ) ) {
				$draft[ $key ] = (string) $in[ $key ];
			}
		}
		MTCA_Jobs::set( $job['id'], 'draft', MTCA_Jobs::clean_draft( $draft ) );

		$sel = (array) $request->get_param( 'selected' );
		$cur = $job['selected'];
		$ok  = array(
			'term'   => array_keys( MTCA_Types::practice_areas() ),
			'lawyer' => array_keys( MTCA_Types::lawyers() ),
			'outlet' => array_keys( MTCA_Types::outlets() ),
		);
		foreach ( $ok as $key => $allowed ) {
			if ( array_key_exists( $key, $sel ) ) {
				$cur[ $key ] = in_array( (int) $sel[ $key ], $allowed, true ) ? (int) $sel[ $key ] : 0;
			}
		}
		if ( array_key_exists( 'image', $sel ) ) {
			$cur['image'] = MTCA_Images::is_in_pool( (int) $sel['image'] ) ? (int) $sel['image'] : 0;
		}
		MTCA_Jobs::set( $job['id'], 'selected', $cur );
		return self::view( MTCA_Jobs::get( $job['id'] ) );
	}

	public static function create_post( $request ) {
		$job = self::job_or_error( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$res = MTCA_Writer::create( $job, (bool) $request->get_param( 'ack' ) );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		return rest_ensure_response( array_merge( $res, array( 'job' => MTCA_Jobs::public_view( MTCA_Jobs::get( $job['id'] ) ) ) ) );
	}

	public static function delete_job( $request ) {
		$job = self::job_or_error( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		MTCA_Jobs::delete( $job['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function images( $request ) {
		return rest_ensure_response( MTCA_Images::pool( (int) $request->get_param( 'term' ) ) );
	}
}
