<?php
/**
 * Plugin Name: CFOLC Community Sync
 * Description: Sync data from one WordPress site to another.
 * Version: 1.0.0
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CFOLC_COMMUNITY_SYNC_VERSION', '1.7.0' );
define( 'CFOLC_COMMUNITY_SYNC_FILE', __FILE__ );
define( 'CFOLC_COMMUNITY_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once CFOLC_COMMUNITY_SYNC_DIR . 'includes/class-cfolc-community-sync-settings.php';
require_once CFOLC_COMMUNITY_SYNC_DIR . 'includes/class-cfolc-community-sync-api-client.php';
require_once CFOLC_COMMUNITY_SYNC_DIR . 'includes/class-cfolc-community-sync-service.php';
require_once CFOLC_COMMUNITY_SYNC_DIR . 'includes/class-cfolc-community-sync-logger.php';
require_once CFOLC_COMMUNITY_SYNC_DIR . 'includes/class-cfolc-community-sync-scheduler.php';

register_activation_hook(
	__FILE__,
	static function () {
		CFOLC_Community_Sync_Settings::activate();
		CFOLC_Community_Sync_Logger::activate();
		CFOLC_Community_Sync_Scheduler::activate();
	}
);

register_deactivation_hook( __FILE__, array( 'CFOLC_Community_Sync_Scheduler', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		CFOLC_Community_Sync_Settings::init();
		CFOLC_Community_Sync_Scheduler::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once CFOLC_COMMUNITY_SYNC_DIR . 'includes/class-cfolc-community-sync-cli-command.php';
			WP_CLI::add_command( 'cfolc-community', 'CFOLC_Community_Sync_CLI_Command' );
		}
	}
);
