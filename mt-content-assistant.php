<?php
/**
 * Plugin Name: MT Content Assistant
 * Description: Turns a court ruling or a press article (PDF or link) into a draft post with its ACF fields filled, using Claude. Drafts only: publishing stays manual.
 * Version: 0.1.0
 * Author: WSC
 * Requires PHP: 7.4
 * Text Domain: mtca
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MTCA_VERSION', '0.1.0' );
define( 'MTCA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MTCA_URL', plugin_dir_url( __FILE__ ) );

/*
 * wp-config.php:
 *   define( 'MTCA_CLAUDE_API_KEY', '...' );   required, the firm's own API key
 *   define( 'MTCA_CLAUDE_MODEL', '...' );     optional, overrides the settings page
 */
if ( ! defined( 'MTCA_API_URL' ) ) {
	define( 'MTCA_API_URL', 'https://api.anthropic.com/v1/messages' );
}
if ( ! defined( 'MTCA_API_VERSION' ) ) {
	define( 'MTCA_API_VERSION', '2026-10-21' );
}
if ( ! defined( 'MTCA_MAX_FILES' ) ) {
	define( 'MTCA_MAX_FILES', 10 );
}
if ( ! defined( 'MTCA_MAX_PDF_BYTES' ) ) {
	define( 'MTCA_MAX_PDF_BYTES', 20 * 1024 * 1024 );
}

require_once MTCA_DIR . 'includes/class-mtca-settings.php';
require_once MTCA_DIR . 'includes/class-mtca-types.php';
require_once MTCA_DIR . 'includes/class-mtca-storage.php';
require_once MTCA_DIR . 'includes/class-mtca-claude.php';
require_once MTCA_DIR . 'includes/class-mtca-jobs.php';
require_once MTCA_DIR . 'includes/class-mtca-images.php';
require_once MTCA_DIR . 'includes/class-mtca-writer.php';
require_once MTCA_DIR . 'includes/class-mtca-check.php';
require_once MTCA_DIR . 'includes/class-mtca-rest.php';
require_once MTCA_DIR . 'includes/class-mtca-admin.php';

/** May the current user use the assistant? Administrators, plus the users picked in the settings. */
function mtca_user_can( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	$allowed = array_map( 'intval', (array) MTCA_Settings::get( 'users', array() ) );
	return in_array( $user_id, $allowed, true ) && user_can( $user_id, 'edit_posts' );
}

add_action( 'init', array( 'MTCA_Jobs', 'register' ) );
add_action( 'rest_api_init', array( 'MTCA_Rest', 'register' ) );
add_action( 'plugins_loaded', array( 'MTCA_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'MTCA_Images', 'init' ) );

register_activation_hook( __FILE__, array( 'MTCA_Storage', 'ensure_dir' ) );
