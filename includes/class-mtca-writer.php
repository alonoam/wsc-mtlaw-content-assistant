<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Turns a ready job into a draft post. Never publishes. */
class MTCA_Writer {

	/** Top-level ACF fields of a post type: name => field array. */
	public static function acf_fields( $post_type ) {
		$out = array();
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return $out;
		}
		foreach ( acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group['key'] ) as $field ) {
				if ( ! empty( $field['name'] ) && ! isset( $out[ $field['name'] ] ) ) {
					$out[ $field['name'] ] = $field;
				}
			}
		}
		return $out;
	}

	/** Shape a value for the ACF field type it goes into. */
	private static function coerce( $field, $value ) {
		switch ( $field['type'] ?? 'text' ) {
			case 'post_object':
			case 'page_link':
				return ! empty( $field['multiple'] ) ? array( (int) $value ) : (int) $value;
			case 'relationship':
				return array_map( 'intval', (array) $value );
			case 'image':
			case 'file':
				return (int) $value;
			case 'link':
				return is_array( $value ) ? $value : array( 'url' => (string) $value, 'title' => '', 'target' => '' );
			default:
				return $value;
		}
	}

	/** Write one field by name. Returns true when an ACF field took it, false when it fell back to plain meta. */
	private static function put( $fields, $post_id, $name, $value ) {
		if ( isset( $fields[ $name ] ) && function_exists( 'update_field' ) ) {
			update_field( $fields[ $name ]['key'], self::coerce( $fields[ $name ], $value ), $post_id );
			return true;
		}
		update_post_meta( $post_id, $name, wp_slash( $value ) );
		return false;
	}

	/**
	 * Create the draft post. $ack = the lawyer confirmed the blocking warnings.
	 * Returns [ 'post_id', 'edit_link', 'missing' ] or WP_Error.
	 */
	public static function create( $job, $ack ) {
		$type = MTCA_Types::get( $job['type'] );
		if ( ! $type || empty( $job['draft'] ) || $job['status'] !== 'ready' ) {
			return new WP_Error( 'mtca_state', 'הטיוטה אינה מוכנה.' );
		}
		if ( ! post_type_exists( $type['post_type'] ) ) {
			return new WP_Error( 'mtca_pt', 'סוג התוכן ' . $type['post_type'] . ' אינו קיים באתר.' );
		}
		foreach ( MTCA_Jobs::warnings( $job ) as $warning ) {
			if ( $warning['level'] === 'block' && ! $ack ) {
				return new WP_Error( 'mtca_ack', 'יש לאשר את ההתרעות לפני יצירת הטיוטה.' );
			}
		}
		$draft = $job['draft'];
		$sel   = $job['selected'];

		$post_id = wp_insert_post( array(
			'post_type'   => $type['post_type'],
			'post_status' => 'draft',
			'post_title'  => $draft['post_title'],
			'post_author' => get_current_user_id(),
		), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$fields  = self::acf_fields( $type['post_type'] );
		$missing = array();
		$text    = $draft['text'];
		$note    = trim( (string) MTCA_Settings::get( 'disclaimer', '' ) );
		if ( $type['disclaimer'] && $note !== '' ) {
			$text .= "\n<p><em>" . esc_html( $note ) . '</em></p>';
		}
		foreach ( array( 'title' => $draft['title'], 'sub_title' => $draft['sub_title'], 'text' => $text ) as $name => $value ) {
			if ( ! self::put( $fields, $post_id, $name, $value ) ) {
				$missing[] = $name;
			}
		}

		if ( ! empty( $sel['lawyer'] ) && get_post_type( (int) $sel['lawyer'] ) === 'team' ) {
			$done = false;
			foreach ( $type['author'] as $name ) {
				if ( isset( $fields[ $name ] ) ) {
					self::put( $fields, $post_id, $name, (int) $sel['lawyer'] );
					$done = true;
				}
			}
			if ( ! $done ) {
				update_post_meta( $post_id, $type['author'][0], (int) $sel['lawyer'] );
				$missing[] = $type['author'][0];
			}
		}

		if ( ! empty( $sel['image'] ) && MTCA_Images::is_in_pool( (int) $sel['image'] ) ) {
			foreach ( $type['images'] as $name ) {
				if ( isset( $fields[ $name ] ) ) {
					self::put( $fields, $post_id, $name, (int) $sel['image'] );
				} else {
					$missing[] = $name;
				}
			}
			MTCA_Images::mark_used( (int) $sel['image'] );
		}

		if ( $type['link_field'] && $job['url'] ) {
			$link = array( 'url' => esc_url_raw( $job['url'] ), 'title' => 'לכתבה המלאה', 'target' => '_blank' );
			if ( ! self::put( $fields, $post_id, $type['link_field'], $link ) ) {
				$missing[] = $type['link_field'];
			}
		}

		if ( ! empty( $sel['term'] ) ) {
			wp_set_object_terms( $post_id, array( (int) $sel['term'] ), 'general_category' );
		}
		if ( $type['outlet_tax'] && ! empty( $sel['outlet'] ) ) {
			wp_set_object_terms( $post_id, array( (int) $sel['outlet'] ), $type['outlet_tax'] );
		}

		update_post_meta( $post_id, '_yoast_wpseo_title', wp_slash( $draft['seo_title'] ) );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', wp_slash( $draft['seo_desc'] ) );

		// provenance: who, from what, and that a person still has to approve it
		update_post_meta( $post_id, '_mtca_ai_draft', '1' );
		update_post_meta( $post_id, '_mtca_job_id', (int) $job['id'] );
		update_post_meta( $post_id, '_mtca_source_file', $job['file'] );
		update_post_meta( $post_id, '_mtca_source_url', esc_url_raw( $job['url'] ) );
		update_post_meta( $post_id, '_mtca_created', array( 'user' => get_current_user_id(), 'time' => time(), 'ack' => (bool) $ack ) );

		MTCA_Jobs::set( $job['id'], 'post_id', $post_id );
		MTCA_Jobs::set( $job['id'], 'status', 'created' );

		return array( 'post_id' => (int) $post_id, 'edit_link' => get_edit_post_link( $post_id, 'raw' ), 'missing' => array_values( array_unique( $missing ) ) );
	}
}
