<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The content types the assistant can draft, and the site lists the model chooses from. */
class MTCA_Types {

	public static function all() {
		return apply_filters( 'mtca_types', array(
			'success' => array(
				'label'        => 'סיפור הצלחה (פסק דין)',
				'post_type'    => 'successstories',
				'source_label' => 'court ruling or committee decision',
				'author'       => array( 'author' ),
				'images'       => array( 'picture' ),
				'outlet_tax'   => '',
				'link_field'   => '',
				'disclaimer'   => true,
			),
			'journal' => array(
				'label'        => 'מן העיתונות (כתבה)',
				'post_type'    => 'journal',
				'source_label' => 'press article about the firm or quoting its lawyers',
				'author'       => array( 'author', 'athor' ),
				'images'       => array( 'picture_article', 'picture' ),
				'outlet_tax'   => 'journal_category',
				'link_field'   => 'link',
				'disclaimer'   => false,
			),
		) );
	}

	public static function get( $type ) {
		$all = self::all();
		return isset( $all[ $type ] ) ? $all[ $type ] : null;
	}

	/** Text fields of a draft, in display order: key => [label, kind, max length]. */
	public static function text_fields() {
		return array(
			'post_title' => array( 'כותרת הפוסט (H1)', 'text', 110 ),
			'title'      => array( 'כותרת לכרטיס', 'text', 80 ),
			'sub_title'  => array( 'כותרת משנה', 'text', 180 ),
			'text'       => array( 'גוף הטקסט', 'html', 0 ),
			'seo_title'  => array( 'Title ל-SEO', 'text', 60 ),
			'seo_desc'   => array( 'Description ל-SEO', 'text', 155 ),
		);
	}

	/** Top-level practice areas: term_id => name. */
	public static function practice_areas() {
		$terms = get_terms( array( 'taxonomy' => 'general_category', 'hide_empty' => false, 'parent' => 0 ) );
		$out   = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ (int) $t->term_id ] = $t->name;
			}
		}
		return $out;
	}

	/** Published team members: post ID => name. */
	public static function lawyers() {
		$ids = get_posts( array( 'post_type' => 'team', 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids', 'suppress_filters' => true ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = get_the_title( $id );
		}
		return $out;
	}

	/** Media outlets (journal_category): term_id => name. */
	public static function outlets() {
		$terms = get_terms( array( 'taxonomy' => 'journal_category', 'hide_empty' => false ) );
		$out   = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ (int) $t->term_id ] = $t->name;
			}
		}
		return $out;
	}

	/** ID of the list entry whose name matches $name (exact, then contained), 0 when none. */
	public static function match( $name, $list ) {
		$norm = function ( $s ) {
			$s = preg_replace( '/(עו"ד|עו״ד|עורך דין|עורכת דין|אדו\'|ד"ר)/u', '', (string) $s );
			return trim( preg_replace( '/[\s\-־_,.\'"״׳]+/u', ' ', mb_strtolower( $s ) ) );
		};
		$n = $norm( $name );
		if ( $n === '' ) {
			return 0;
		}
		foreach ( $list as $id => $label ) {
			if ( $norm( $label ) === $n ) {
				return (int) $id;
			}
		}
		foreach ( $list as $id => $label ) {
			$l = $norm( $label );
			if ( $l !== '' && ( mb_strpos( $l, $n ) !== false || mb_strpos( $n, $l ) !== false ) ) {
				return (int) $id;
			}
		}
		return 0;
	}
}
