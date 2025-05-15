<?php

/*
 * Load the Sync plugin components.
 */

use SolidWP\Central\Admin_Post\Admin_Post_Handler;
use SolidWP\Central\Central_Server\Central_Server_Notifier;

if ( ! defined( 'SOLID_CENTRAL_APP_ID' ) ) {
	define( 'SOLID_CENTRAL_APP_ID', 'aff8ad0a-3359-5385-9bbd-c11b5655814a' );
}

/**
 * @return void
 */
function ithemes_sync_load_request_handler() {
	require_once $GLOBALS['ithemes_sync_path'] . '/request-handler.php';
}

if ( ! empty( $_GET['ithemes-sync-request'] ) ) {
	add_action( 'plugins_loaded', 'ithemes_sync_load_request_handler' );
}

if ( is_admin() ) {
	require $GLOBALS['ithemes_sync_path'] . '/admin.php';
}

require_once $GLOBALS['ithemes_sync_path'] . '/functions.php';
require_once $GLOBALS['ithemes_sync_path'] . '/client-dashboard.php';
require_once $GLOBALS['ithemes_sync_path'] . '/notices.php';
require_once $GLOBALS['ithemes_sync_path'] . '/duplicator.php';
require_once $GLOBALS['ithemes_sync_path'] . '/src/Admin_Post/Admin_Post_Handler.php';
require_once $GLOBALS['ithemes_sync_path'] . '/src/Central_Server/Central_Server_Client.php';
require_once $GLOBALS['ithemes_sync_path'] . '/src/Central_Server/Central_Server_Notifier.php';
require_once $GLOBALS['ithemes_sync_path'] . '/src/REST/Auth.php';
require_once $GLOBALS['ithemes_sync_path'] . '/src/REST/Verb.php';

// Load the REST API routes.
add_action(
	'rest_api_init',
	function () {
		require_once $GLOBALS['ithemes_sync_path'] . '/settings.php';
		require_once $GLOBALS['ithemes_sync_path'] . '/api.php';
		require_once $GLOBALS['ithemes_sync_path'] . '/request-handler.php';

		( new SolidWP\Central\REST\Auth( $GLOBALS['ithemes-sync-settings'] ) )->register_routes();
		( new SolidWP\Central\Rest\Verb( $GLOBALS['ithemes-sync-api'] ) )->register_routes();
	}
);

require_once $GLOBALS['ithemes_sync_path'] . '/settings.php';
$GLOBALS['solid_central_notifier'] = new Central_Server_Notifier( $GLOBALS['ithemes-sync-settings'] );
$GLOBALS['solid_central_notifier']->init();

add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( Central_Server_Notifier::SEND_QUEUED_NOTICES_ACTION ) ) {
			wp_schedule_event( time(), 'hourly', Central_Server_Notifier::SEND_QUEUED_NOTICES_ACTION );
		}

		(new Admin_Post_Handler( $GLOBALS['ithemes-sync-settings'] ))->init();
	}
);

/**
 * Notify the Central server immediately.
 *
 * @param string               $type Notice type. Must be one of the `Central_Server_Notifier::NOTICE_*` constants.
 * @param array<string, mixed> $payload Notice payload.
 *
 * @phpstan-param Central_Server_Notifier::NOTICE_* $type
 *
 * @return true|WP_Error
 */
function solid_central_notify_server( string $type, array $payload ) {
	return $GLOBALS['solid_central_notifier']->notify( $type, $payload );
}

/**
 * Add notice to the queue. Notices will be sent by the cron schedule.
 *
 * @see Central_Server_Notifier::send_queued_notices()
 *
 * @param string               $type Notice type. Must be one of the `Central_Server_Notifier::NOTICE_*` constants.
 * @param array<string, mixed> $payload Notice payload.
 *
 * @phpstan-param Central_Server_Notifier::NOTICE_* $type
 *
 * @return void
 */
function solid_central_add_notice( string $type, array $payload ) {
	$GLOBALS['solid_central_notifier']->add_notice( $type, $payload );
}

/**
 * @return void
 */
function ithemes_sync_handle_activation_hook() {
	set_site_transient( 'ithemes-sync-activated', true, 120 );
	delete_site_option( 'ithemes_sync_hide_authenticate_notice' );
}
register_activation_hook( __FILE__, 'ithemes_sync_handle_activation_hook' );

/**
 * @return void
 */
function ithemes_sync_handle_deactivation_hook() {
	delete_site_option( 'ithemes_sync_hide_authenticate_notice' );
	delete_site_transient( 'ithemes-sync-activated' );
}
register_deactivation_hook( __FILE__, 'ithemes_sync_handle_deactivation_hook' );

/**
 * The SolidWP Updater register action callback.
 *
 * @param mixed $updater An instance of the SolidWP Updater.
 *
 * @return void
 */
function ithemes_sync_updater_register( $updater ) {
	$updater->register( 'ithemes-sync', __FILE__ );
}
add_action( 'ithemes_updater_register', 'ithemes_sync_updater_register' );

require $GLOBALS['ithemes_sync_path'] . '/lib/updater/load.php';

/**
 * Whitelist the Central Server IP addresses in Solid Security to prevent
 * Central from being blacklisted by a customer on their own site
 *
 * @param array<int,string> $white_ips The IPs to filter.
 *
 * @return array<int,string> The filtered Solid Security whitelisted IPs.
 */
function ithemes_sync_itsec_white_ips( array $white_ips ): array {
	$white_ips[] = '69.167.144.237';
	$white_ips[] = '54.159.83.156';
	return $white_ips;
}
add_filter( 'itsec_white_ips', 'ithemes_sync_itsec_white_ips' );

/**
 * Solid Central login `init` action callback.
 *
 * @return false|void
 */
function ithemes_sync_login() {
	if ( ! empty( $_GET['sync-login'] ) ) {
		$option = get_option( 'sync_login_' . sanitize_key( $_GET['sync-login'] ) );
		delete_option( 'sync_login_' . sanitize_key( $_GET['sync-login'] ) );
		$current_time = time();

		if ( $option['expires'] > $current_time ) {
			wp_set_current_user( $option['user_id'] );
			wp_set_auth_cookie( $option['user_id'], false, is_ssl() );
		}

		if ( ! empty( $option['path'] ) ) {
			switch ( $option['path'] ) {
				case 'addpost':
					if ( empty( $option['path_data'] ) ) {
						$post_type = 'post';
					} else {
						$post_type = $option['path_data'];
					}
					$path = 'post-new.php?post_type=' . $option['path_data'];
					break;
				case 'editpost':
					$path = 'post.php?post=' . $option['path_data'] . '&action=edit';
					break;
				case 'duplicatepost':
					list(
						$post_id,
						$post_type ) = explode( '-', $option['path_data'] );
					$path            = 'post-new.php?post_type=' . $post_type . '&ithemes-sync-duplicate-post-id=' . $post_id;
					break;
				case 'addpage':
					$path = 'post-new.php?post_type=page';
					break;
				case 'cd':
					$path = '';
					add_user_meta( $option['user_id'], 'it-sync-refresh-cd', true );
					break;
				default:
					$path = '';
			}
		} else {
			$path = '';
		}
		wp_safe_redirect( admin_url( $path ) );
		die();
	}
	return false;
}
add_action( 'init', 'ithemes_sync_login' );

/**
 * @return void
 */
function ithemes_sync_daily_schedule() {
	global $wpdb;

	$results = $wpdb->get_results(
		$wpdb->prepare(
			"
			SELECT option_name, option_value
			FROM $wpdb->options
			WHERE option_name LIKE %s
			",
			'sync_login_%'
		)
	);

	$current_time = time();
	foreach ( $results as $result ) {
		$data = maybe_unserialize( $result->option_value );
		if ( $data['expires'] < $current_time ) {
			delete_option( $result->option_name );
		}
	}
}
add_action( 'ithemes_sync_daily_schedule', 'ithemes_sync_daily_schedule' );

/**
 * @return void
 */
function ithemes_sync_google_site_verification() {
	$option = get_option( 'ithemes-sync-googst' );
	if ( ! empty( $option ) ) {
		echo "\n\n" . esc_html( $option['code'] ) . "\n\n";
		if ( $option['expiry'] <= time() ) {
			delete_option( 'ithemes-sync-googst' );
		}
	}
}
add_action( 'wp_head', 'ithemes_sync_google_site_verification' );
