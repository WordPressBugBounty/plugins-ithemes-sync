<?php
/**
 * Service to notify Central server about logs and events.
 *
 * @package SolidWP\Central\Central_Server
 */

namespace SolidWP\Central\Central_Server;

use Ithemes_Sync_Functions;
use Ithemes_Sync_Settings;
use WP_Error;

/**
 * Class Central_Server_Notifier
 *
 * @phpstan-type QueuedNotice array{type: self::NOTICE_*, payload: array<string, mixed>, site_id: int}
 */
class Central_Server_Notifier {

	const QUEUE_OPTION_NAME          = 'solid_central_notice_queue';
	const QUEUE_MAX_LENGTH           = 30;
	const SEND_QUEUED_NOTICES_ACTION = 'solid_central_send_queued_notices';

	const NOTICE_LIVE_SNAPSHOT_SUCCESS           = 'backups-legacy/live-snapshot-success';
	const NOTICE_SECURITY_LOG                    = 'security/{module}/log';
	const NOTICE_SECURITY_2FA                    = 'security/2fa';
	const NOTICE_VULNERABILITY_RESOLUTION_FAILED = 'security/vulnerability-resolution-failed';
	const NOTICE_VULNERABILITY_RESOLUTION        = 'security/vulnerability-resolution';
	const NOTICE_SITE_SCAN_COMPLETE              = 'security/site-scan-complete';
	const NOTICE_PLUGIN_INSTALLED                = 'software/plugin-installed';
	const NOTICE_PLUGIN_ACTIVATED                = 'software/plugin-activated';
	const NOTICE_PLUGIN_UPDATED                  = 'software/plugin-updated';
	const NOTICE_PLUGIN_DEACTIVATED              = 'software/plugin-deactivated';
	const NOTICE_PLUGIN_UNINSTALLED              = 'software/plugin-uninstalled';
	const NOTICE_THEME_INSTALLED                 = 'software/theme-installed';
	const NOTICE_THEME_ACTIVATED                 = 'software/theme-activated';
	const NOTICE_THEME_UPDATED                   = 'software/theme-updated';
	const NOTICE_THEME_UNINSTALLED               = 'software/theme-uninstalled';
	const NOTICE_CORE_UPDATED                    = 'software/core-updated';

	/**
	 * @var Ithemes_Sync_Settings Settings object.
	 */
	private $settings;

	/**
	 * @var array<string, QueuedNotice> Queued notices.
	 */
	private $queued_notices = [];

	/**
	 * @param Ithemes_Sync_Settings $settings Settings object.
	 */
	public function __construct( Ithemes_Sync_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return void
	 */
	public function init() {
		$this->queued_notices = (array) get_site_option( self::QUEUE_OPTION_NAME, [] );
		add_action( 'shutdown', [ $this, 'save_queued_notices' ] );
		// @phpstan-ignore return.void
		add_action( self::SEND_QUEUED_NOTICES_ACTION, [ $this, 'send_queued_notices' ] );
	}

	/**
	 * Save queued notices to the database.
	 *
	 * @return void
	 */
	public function save_queued_notices() {
		if ( count( $this->queued_notices ) > self::QUEUE_MAX_LENGTH ) {
			$this->queued_notices = array_slice( $this->queued_notices, -1 * self::QUEUE_MAX_LENGTH );
		}

		update_site_option( self::QUEUE_OPTION_NAME, $this->queued_notices );
	}

	/**
	 * Add notice to the queue.
	 *
	 * @see self::send_queued_notices() for sending the queued notices.
	 *
	 * @param string               $type Notice type defined by the class NOTICE_* constants.
	 * @param array<string, mixed> $payload Notice payload.
	 *
	 * @phpstan-param self::NOTICE_* $type
	 *
	 * @return void
	 */
	public function add_notice( string $type, array $payload ) {
		$options = $this->settings->get_options();

		foreach ( $options['authentications'] as $site_id => $site ) {
			$notice                                = $this->prepare_notice( $type, $payload, $site_id );
			$this->queued_notices[ $notice['id'] ] = $notice['data'];
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public function send_queued_notices() {
		if ( count( $this->queued_notices ) === 0 ) {
			return true;
		}

		$options = $this->settings->get_options();

		$contexts = [];
		foreach ( $options['authentications'] as $site_id => $site ) {
			$this->send_legacy_cached_notices( (int) $site_id, (string) $site['username'], (string) $site['key'] );

			$contexts[ $site_id ] = [
				'site_id'     => $site_id,
				'username'    => $site['username'],
				'private_key' => $site['key'],
			];
		}

		$batch = [];
		foreach ( $this->queued_notices as $notice_id => $notice ) {
			if ( ! isset( $batch[ $notice['site_id'] ] ) ) {
				$batch[ $notice['site_id'] ] = [];
			}
			$batch[ $notice['site_id'] ][ $notice_id ] = $notice;
		}

		$this->queued_notices = [];
		$response_errors      = [];
		foreach ( $batch as $site_id => $notices ) {
			if ( ! isset( $contexts[ $site_id ] ) ) {
				// Maybe site was disconnected.
				continue;
			}

			$result = Central_Server_Client::notify( 'batch', $contexts[ $site_id ], $notices );

			if ( $this->is_temporary_http_error( $result ) ) {
				$this->queued_notices += $notices;
				$response_errors[]     = $result;
				continue;
			}

			if ( is_wp_error( $result ) ) {
				// Something is really wrong. It doesn't make sense to retry.
				return $result;
			}

			foreach ( $result as $notice_id => $item ) {
				if ( isset( $notices[ $notice_id ] ) && $this->is_temporary_http_code( (int) $item['status'] ) ) {
					$this->queued_notices[ $notice_id ] = $notices[ $notice_id ];
				}
			}
		}

		if ( count( $this->queued_notices ) === 0 ) {
			return true;
		}

		$error = new WP_Error(
			'solid-central.queued-notices-sending-failed',
			__( 'Not all notifications were sent.', 'it-l10n-ithemes-sync' ),
			$this->queued_notices
		);

		foreach ( $response_errors as $response_error ) {
			$error->merge_from( $response_error );
		}

		return $error;
	}

	/**
	 * @param string               $type Notice type defined by the class NOTICE_* constants.
	 * @param array<string, mixed> $payload Notice payload.
	 *
	 * @phpstan-param self::NOTICE_* $type
	 *
	 * @return true|WP_Error
	 */
	public function notify( string $type, array $payload ) {
		$options = $this->settings->get_options();

		$not_notified_site_ids = [];
		$response_errors       = [];
		foreach ( $options['authentications'] as $site_id => $site ) {
			$context = [
				'site_id'     => $site_id,
				'username'    => $site['username'],
				'private_key' => $site['key'],
			];

			$notice = $this->prepare_notice( $type, $payload, $site_id );

			$result = Central_Server_Client::notify( $notice['data']['type'], $context, $notice['data']['payload'] );

			if ( $this->is_temporary_http_error( $result ) ) {
				$this->queued_notices[ $notice['id'] ] = $notice['data'];
				$not_notified_site_ids[]               = $site_id;
				$response_errors[]                     = $result;
				continue;
			}

			if ( is_wp_error( $result ) ) {
				// Something is really wrong. It doesn't make sense to retry.
				return $result;
			}
		}

		if ( count( $not_notified_site_ids ) === 0 ) {
			return true;
		}

		$error = new WP_Error(
			'solid-central.notification-failed',
			__( 'Not all Central users were notified.', 'it-l10n-ithemes-sync' ),
			[ 'not_notified_site_ids' => $not_notified_site_ids ]
		);

		foreach ( $response_errors as $response_error ) {
			$error->merge_from( $response_error );
		}

		return $error;
	}

	/**
	 * @param string               $type Notice type.
	 * @param array<string, mixed> $payload Notice data.
	 * @param int                  $site_id Site ID to send the notice to.
	 *
	 * @phpstan-param self::NOTICE_* $type
	 *
	 * @return array{id: string, data: QueuedNotice}
	 */
	private function prepare_notice( string $type, array $payload, int $site_id ): array {
		foreach ( $payload as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$type = str_replace( '{' . $key . '}', $value, $type );
		}

		if ( ! isset( $payload['timestamp'] ) ) {
			$payload['timestamp'] = time();
		}

		$data = [
			'type'    => $type,
			'payload' => $payload,
			'site_id' => $site_id,
		];

		$notice_id = md5( wp_json_encode( $data ) );

		return [
			'id'   => $notice_id,
			'data' => $data,
		];
	}

	/**
	 * @param mixed $error HTTP error.
	 *
	 * @return bool
	 */
	private function is_temporary_http_error( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$http_code = (int) ( $error->get_error_data( 'solid-central.server-failed-request' )['status'] ?? 0 );

		return $this->is_temporary_http_code( $http_code );
	}

	/**
	 * @param int $http_code HTTP code.
	 *
	 * @return bool
	 */
	private function is_temporary_http_code( int $http_code ): bool {
		return $http_code >= 500 && $http_code < 600;
	}


	/**
	 * @deprecated The method was created for BC reasons and will be removed in a future version.
	 *             It preservers to not lost cached urgent notices after the plugin update.
	 *
	 * @param int    $user_id Site ID.
	 * @param string $username WordPress username.
	 * @param string $private_key Site private key.
	 *
	 * @return void
	 */
	private function send_legacy_cached_notices( int $user_id, string $username, string $private_key ) {
		$urgent_notices = (array) get_site_option( 'ithemes_sync_urgent_notice_cache', [] );
		if ( count( $urgent_notices ) === 0 ) {
			return;
		}

		$action = 'send-urgent-notices';

		$query = [
			'user_id' => $user_id,
			'user'    => $username,
		];

		$salt = hash( 'sha256', uniqid( '', true ) );

		$data = [
			'hash'    => hash( 'sha256', $user_id . $username . $private_key . $salt ),
			'salt'    => $salt,
			'notices' => $urgent_notices,
		];

		$secure_url = apply_filters( 'sync_api_request_url', 'https://central.solidwp.com/plugin-api/' );

		require_once $GLOBALS['ithemes_sync_path'] . '/functions.php';
		$default_query = [
			'wp'           => Ithemes_Sync_Functions::get_wordpress_version(),
			'site'         => get_bloginfo( 'url' ),
			'timestamp'    => time(),
			'auth_version' => '2',
		];

		if ( is_multisite() ) {
			$default_query['ms'] = 1;
		}

		$query           = array_merge( $default_query, $query );
		$query['action'] = $action;

		$request = $action . '?' . http_build_query( $query, '', '&' );

		$remote_post_args = [
			'timeout' => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout
			'body'    => [
				'request' => wp_json_encode( $data ),
			],
		];

		wp_remote_post( $secure_url . $request, $remote_post_args );
		delete_site_option( 'ithemes_sync_urgent_notice_cache' );
	}
}
