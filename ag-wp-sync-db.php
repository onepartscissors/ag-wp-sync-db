<?php
/*
Plugin Name: AG WP Sync DB
Plugin URI: https://github.com/onepartscissors/ag-wp-sync-db
Description: Export, push, and pull to migrate your WordPress databases. Fork of WP Sync DB with PHP 8 and WordPress 6.7+ fixes and faster syncs.
Author: Ash Glover
Author URI: https://www.ashglover.co.za
Version: 2.1.0
Requires at least: 5.6
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: ag-wp-sync-db
Domain Path: /languages
GitHub Plugin URI: onepartscissors/ag-wp-sync-db
Network: True
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AG_WPSDB_VERSION', '2.1.0' );

// Protocol version reported to remote sites. Kept at 1.5 so ag-wp-sync-db and wp-sync-db 1.5 can sync with each other.
define( 'AG_WPSDB_PROTOCOL_VERSION', '1.5' );

if ( ! defined( 'DS' ) ) {
	define( 'DS', DIRECTORY_SEPARATOR );
}

function ag_wp_sync_db_conflict() {
	return class_exists( 'WPSDB_Base' ) || function_exists( 'wp_sync_db_loaded' );
}

if ( ! ag_wp_sync_db_conflict() ) {
	$GLOBALS['wpsdb_meta']['wp-sync-db']['version'] = AG_WPSDB_PROTOCOL_VERSION;
	$GLOBALS['wpsdb_meta']['wp-sync-db']['folder'] = basename( plugin_dir_path( __FILE__ ) );
}

function ag_wp_sync_db_init() {
	if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) return;

	if ( ag_wp_sync_db_conflict() ) {
		add_action( 'admin_notices', 'ag_wp_sync_db_conflict_notice' );
		add_action( 'network_admin_notices', 'ag_wp_sync_db_conflict_notice' );
		return;
	}

	load_plugin_textdomain( 'ag-wp-sync-db', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	require_once __DIR__ . '/class/wpsdb-base.php';
	require_once __DIR__ . '/class/wpsdb-addon.php';
	require_once __DIR__ . '/class/wpsdb.php';

	global $wpsdb;
	$wpsdb = new WPSDB( __FILE__ );

	if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'WPSDB_CLI' ) ) {
		require_once __DIR__ . '/class/wpsdb-cli.php';
		global $wpsdb_cli;
		$wpsdb_cli = new WPSDB_CLI( __FILE__ );
		WP_CLI::add_command( 'agsdb', 'AG_WPSDB_Command' );
	}

	do_action( 'ag_wpsdb_loaded' );
}
add_action( 'init', 'ag_wp_sync_db_init', 1 );

function ag_wp_sync_db_conflict_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) return;
	echo '<div class="notice notice-error"><p>' . esc_html__( 'AG WP Sync DB is not running because the original WP Sync DB plugin is also active. Deactivate WP Sync DB and WP Sync DB Media Files.', 'ag-wp-sync-db' ) . '</p></div>';
}

// Deactivate the original plugins so both never run together. Settings are shared, so connections carry over.
function ag_wp_sync_db_activate( $network_wide ) {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( array( 'wp-sync-db/wp-sync-db.php', 'wp-sync-db-media-files/wp-sync-db-media-files.php' ), true, $network_wide );
}
register_activation_hook( __FILE__, 'ag_wp_sync_db_activate' );
