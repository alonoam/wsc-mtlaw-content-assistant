<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin settings (option mtca_settings) and monthly token usage (option mtca_usage). */
class MTCA_Settings {

	const OPTION = 'mtca_settings';

	public static function defaults() {
		return array(
			'model'        => 'claude-sonnet-5-5',
			'instructions' => '',
			'disclaimer'   => '',
			'token_cap'    => 0, // monthly input + output tokens, 0 = no cap
			'users'        => array(),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function save( $input ) {
		$clean = array(
			'model'        => preg_replace( '/[^a-z0-9.\-]/i', '', (string) ( $input['model'] ?? '' ) ),
			'instructions' => sanitize_textarea_field( (string) ( $input['instructions'] ?? '' ) ),
			'disclaimer'   => sanitize_textarea_field( (string) ( $input['disclaimer'] ?? '' ) ),
			'token_cap'    => max( 0, (int) ( $input['token_cap'] ?? 0 ) ),
			'users'        => array_values( array_filter( array_map( 'intval', (array) ( $input['users'] ?? array() ) ) ) ),
		);
		if ( $clean['model'] === '' ) {
			$clean['model'] = self::defaults()['model'];
		}
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	public static function model() {
		return defined( 'MTCA_CLAUDE_MODEL' ) && MTCA_CLAUDE_MODEL ? MTCA_CLAUDE_MODEL : self::get( 'model' );
	}

	public static function api_key() {
		return defined( 'MTCA_CLAUDE_API_KEY' ) ? (string) MTCA_CLAUDE_API_KEY : '';
	}

	/* Usage */

	public static function usage( $month = '' ) {
		$month = $month ? $month : gmdate( 'Y-m' );
		$all   = get_option( 'mtca_usage', array() );
		$row   = isset( $all[ $month ] ) && is_array( $all[ $month ] ) ? $all[ $month ] : array();
		return array_merge( array( 'input' => 0, 'output' => 0, 'calls' => 0 ), $row );
	}

	public static function add_usage( $input, $output ) {
		$month = gmdate( 'Y-m' );
		$all   = get_option( 'mtca_usage', array() );
		$all   = is_array( $all ) ? $all : array();
		$row   = self::usage( $month );
		$row['input']  += (int) $input;
		$row['output'] += (int) $output;
		$row['calls']  += 1;
		$all[ $month ]  = $row;
		update_option( 'mtca_usage', array_slice( $all, -12, null, true ), false );
	}

	public static function over_cap() {
		$cap = (int) self::get( 'token_cap', 0 );
		if ( $cap < 1 ) {
			return false;
		}
		$u = self::usage();
		return ( $u['input'] + $u['output'] ) >= $cap;
	}
}
