<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private storage for source files. Rulings hold client details, so they are
 * not media library attachments: they live in uploads/mtca-private/ under
 * random names, with direct access denied where the server honours .htaccess.
 */
class MTCA_Storage {

	public static function dir() {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'mtca-private';
	}

	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/** Is this a real PDF within the size limit? Returns '' or an error message. */
	public static function validate_pdf( $path, $size ) {
		if ( $size < 5 || $size > MTCA_MAX_PDF_BYTES ) {
			return sprintf( 'הקובץ גדול מדי (עד %d MB).', (int) ( MTCA_MAX_PDF_BYTES / 1048576 ) );
		}
		$fh   = @fopen( $path, 'rb' );
		$head = $fh ? fread( $fh, 5 ) : '';
		if ( $fh ) {
			fclose( $fh );
		}
		return $head === '%PDF-' ? '' : 'הקובץ אינו PDF תקין.';
	}

	/** Move an uploaded temp file into private storage. Returns the stored file name or WP_Error. */
	public static function store_upload( $tmp_path ) {
		$dir  = self::ensure_dir();
		$name = wp_generate_password( 40, false, false ) . '.pdf';
		if ( ! @move_uploaded_file( $tmp_path, $dir . '/' . $name ) && ! @rename( $tmp_path, $dir . '/' . $name ) ) {
			return new WP_Error( 'mtca_store', 'שמירת הקובץ נכשלה.' );
		}
		@chmod( $dir . '/' . $name, 0640 );
		return $name;
	}

	public static function store_bytes( $bytes ) {
		$dir  = self::ensure_dir();
		$name = wp_generate_password( 40, false, false ) . '.pdf';
		if ( false === @file_put_contents( $dir . '/' . $name, $bytes ) ) {
			return new WP_Error( 'mtca_store', 'שמירת הקובץ נכשלה.' );
		}
		@chmod( $dir . '/' . $name, 0640 );
		return $name;
	}

	/** Absolute path of a stored file, '' unless it is a plain name inside the private directory. */
	public static function path( $name ) {
		$name = (string) $name;
		if ( $name === '' || ! preg_match( '/^[A-Za-z0-9]{40}\.pdf$/', $name ) ) {
			return '';
		}
		$path = self::dir() . '/' . $name;
		return is_file( $path ) ? $path : '';
	}

	public static function delete( $name ) {
		$path = self::path( $name );
		if ( $path ) {
			@unlink( $path );
		}
	}
}
