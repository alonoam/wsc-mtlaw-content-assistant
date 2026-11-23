<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Checks that the site has what the assistant writes into, and merges the misspelled author field. */
class MTCA_Check {

	/** Rows for the settings screen: [ type label, field, status, detail ]. */
	public static function report() {
		$rows = array();
		foreach ( MTCA_Types::all() as $type ) {
			$pt = $type['post_type'];
			if ( ! post_type_exists( $pt ) ) {
				$rows[] = array( $type['label'], $pt, 'bad', 'סוג התוכן לא קיים' );
				continue;
			}
			$fields = MTCA_Writer::acf_fields( $pt );
			$expect = array( 'title' => array( 'text', 'textarea', 'wysiwyg' ), 'sub_title' => array( 'text', 'textarea', 'wysiwyg' ), 'text' => array( 'wysiwyg', 'textarea' ) );
			foreach ( $type['images'] as $name ) {
				$expect[ $name ] = array( 'image' );
			}
			if ( $type['link_field'] ) {
				$expect[ $type['link_field'] ] = array( 'link' );
			}
			foreach ( $expect as $name => $types ) {
				if ( ! isset( $fields[ $name ] ) ) {
					$rows[] = array( $type['label'], $name, 'bad', 'השדה לא נמצא בקבוצות ה-ACF של סוג התוכן' );
				} elseif ( ! in_array( $fields[ $name ]['type'], $types, true ) ) {
					$rows[] = array( $type['label'], $name, 'warn', 'סוג השדה: ' . $fields[ $name ]['type'] . ' (ציפיתי ל-' . implode( ' / ', $types ) . ')' );
				} else {
					$rows[] = array( $type['label'], $name, 'ok', $fields[ $name ]['type'] );
				}
			}
			$found = array();
			foreach ( $type['author'] as $name ) {
				if ( isset( $fields[ $name ] ) ) {
					$found[] = $name . ' (' . $fields[ $name ]['type'] . ( ! empty( $fields[ $name ]['multiple'] ) ? ', מרובה' : '' ) . ')';
				}
			}
			$rows[] = array( $type['label'], implode( ' / ', $type['author'] ), $found ? ( count( $found ) > 1 || ! isset( $fields['author'] ) ? 'warn' : 'ok' ) : 'bad', $found ? implode( ', ', $found ) : 'שדה עורך הדין לא נמצא' );
			foreach ( array_filter( array( 'general_category', $type['outlet_tax'] ) ) as $tax ) {
				$rows[] = array( $type['label'], $tax, is_object_in_taxonomy( $pt, $tax ) ? 'ok' : 'bad', is_object_in_taxonomy( $pt, $tax ) ? 'טקסונומיה משויכת' : 'הטקסונומיה לא משויכת לסוג התוכן' );
			}
		}
		$rows[] = array( 'כללי', 'ACF', function_exists( 'update_field' ) ? 'ok' : 'bad', function_exists( 'update_field' ) ? 'פעיל' : 'ACF לא פעיל' );
		$rows[] = array( 'כללי', 'מפתח API', MTCA_Settings::api_key() !== '' ? 'ok' : 'bad', MTCA_Settings::api_key() !== '' ? 'מוגדר ב-wp-config.php' : 'חסר MTCA_CLAUDE_API_KEY ב-wp-config.php' );
		$dir    = MTCA_Storage::ensure_dir();
		$rows[] = array( 'כללי', 'אחסון קובצי מקור', is_writable( $dir ) ? 'ok' : 'bad', is_writable( $dir ) ? 'תיקייה פרטית ניתנת לכתיבה' : 'אין הרשאת כתיבה: ' . $dir );
		return $rows;
	}

	/** Is the old misspelled field (athor) still there on journal posts, with no "author" field next to it? */
	public static function can_merge_author() {
		$fields = MTCA_Writer::acf_fields( 'journal' );
		return isset( $fields['athor'] ) && ! isset( $fields['author'] );
	}

	/**
	 * Rename the ACF field athor -> author on journal posts: the field
	 * definition and the stored values. The theme (1.61+) reads both names,
	 * so the site works before and after. Returns the number of values moved.
	 */
	public static function merge_author() {
		global $wpdb;
		if ( ! self::can_merge_author() ) {
			return new WP_Error( 'mtca_merge', 'אין מה לאחד: השדה athor לא קיים, או ששדה author כבר קיים לצדו.' );
		}
		$fields = MTCA_Writer::acf_fields( 'journal' );
		$field  = $fields['athor'];
		if ( empty( $field['ID'] ) ) {
			return new WP_Error( 'mtca_merge', 'השדה מוגדר בקוד או ב-JSON ולא במסד הנתונים; יש לשנות את שמו שם.' );
		}
		$field['name'] = 'author';
		acf_update_field( $field );

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'journal' ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$old = get_post_meta( $id, 'athor', true );
			if ( $old === '' || $old === array() ) {
				continue;
			}
			if ( get_post_meta( $id, 'author', true ) === '' ) {
				update_post_meta( $id, 'author', wp_slash( $old ) );
				update_post_meta( $id, '_author', $field['key'] );
				$n++;
			}
			delete_post_meta( $id, 'athor' );
			delete_post_meta( $id, '_athor' );
		}
		return $n;
	}
}
