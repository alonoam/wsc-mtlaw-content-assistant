<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approved mood-image pool. An image joins the pool from its media library
 * edit screen ("תמונת אווירה לכלי ה-AI"), optionally tied to practice areas.
 * The assistant only ever uses pool images.
 */
class MTCA_Images {

	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function fields( $fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! mtca_user_can() ) {
			return $fields;
		}
		$in    = get_post_meta( $post->ID, '_mtca_pool', true ) === '1';
		$terms = array_map( 'intval', (array) get_post_meta( $post->ID, '_mtca_pool_terms', true ) );
		$html  = '<label><input type="checkbox" name="attachments[' . (int) $post->ID . '][mtca_pool]" value="1" ' . checked( $in, true, false ) . '> תמונת אווירה מאושרת (ללא אנשים)</label>';
		$html .= '<div style="margin-top:6px;max-height:140px;overflow:auto">';
		foreach ( MTCA_Types::practice_areas() as $id => $name ) {
			$html .= '<label style="display:block"><input type="checkbox" name="attachments[' . (int) $post->ID . '][mtca_pool_terms][]" value="' . (int) $id . '" ' . checked( in_array( $id, $terms, true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
		}
		$html .= '</div>';
		$fields['mtca_pool'] = array( 'label' => 'כלי ה-AI', 'input' => 'html', 'html' => $html, 'helps' => 'בלי תחום מסומן התמונה זמינה לכל התחומים.' );
		return $fields;
	}

	public static function save( $post, $attachment ) {
		if ( ! mtca_user_can() ) {
			return $post;
		}
		update_post_meta( $post['ID'], '_mtca_pool', ! empty( $attachment['mtca_pool'] ) ? '1' : '0' );
		update_post_meta( $post['ID'], '_mtca_pool_terms', array_values( array_filter( array_map( 'intval', (array) ( $attachment['mtca_pool_terms'] ?? array() ) ) ) ) );
		return $post;
	}

	/** Pool images for a practice area (those tied to it first, then the general ones), least recently used first. */
	public static function pool( $term_id = 0 ) {
		$ids = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'post_mime_type'   => 'image',
			'numberposts'      => 300,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array( array( 'key' => '_mtca_pool', 'value' => '1' ) ),
		) );
		$rows = array();
		foreach ( $ids as $id ) {
			$terms = array_map( 'intval', (array) get_post_meta( $id, '_mtca_pool_terms', true ) );
			$match = $term_id && in_array( (int) $term_id, $terms, true );
			if ( $term_id && $terms && ! $match ) {
				continue;
			}
			$rows[] = array(
				'id'    => (int) $id,
				'thumb' => (string) wp_get_attachment_image_url( $id, 'medium' ),
				'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'match' => $match,
				'used'  => (int) get_post_meta( $id, '_mtca_last_used', true ),
			);
		}
		usort( $rows, function ( $a, $b ) {
			return $a['match'] !== $b['match'] ? ( $a['match'] ? -1 : 1 ) : $a['used'] - $b['used'];
		} );
		return $rows;
	}

	public static function pick( $term_id ) {
		$pool = self::pool( (int) $term_id );
		return $pool ? (int) $pool[0]['id'] : 0;
	}

	public static function is_in_pool( $id ) {
		return $id && get_post_meta( (int) $id, '_mtca_pool', true ) === '1';
	}

	public static function mark_used( $id ) {
		update_post_meta( (int) $id, '_mtca_last_used', time() );
	}

	/** Images in the pool per practice area: name => count ("כללי" = not tied to an area). */
	public static function coverage() {
		$out   = array( 'כללי (כל התחומים)' => 0 );
		$areas = MTCA_Types::practice_areas();
		foreach ( $areas as $name ) {
			$out[ $name ] = 0;
		}
		foreach ( self::pool( 0 ) as $row ) {
			$terms = array_map( 'intval', (array) get_post_meta( $row['id'], '_mtca_pool_terms', true ) );
			if ( ! $terms ) {
				$out['כללי (כל התחומים)']++;
			}
			foreach ( $terms as $t ) {
				if ( isset( $areas[ $t ] ) ) {
					$out[ $areas[ $t ] ]++;
				}
			}
		}
		return $out;
	}
}
