<?php
/**
 * Admin post handler for Solid Central.
 *
 * @package SolidWP\Central\Admin_Post
 */

namespace SolidWP\Central\Admin_Post;

use Ithemes_Sync_Functions;
use Throwable;
use WP_Error;

/**
 * Class Admin_Post_Handler.
 *
 * @package SolidWP\Central\Admin_Post
 */
class Admin_Post_Handler {
	public const HANDLED_ACTIONS = [
		'solid_central_refresh_updates_data',
	];

	public const AUTH_ARG_ACTION = 'action';

	public const AUTH_ARG_SITE_ID = 'site_id';

	public const AUTH_ARG_HASH = 'hash';

	public const AUTH_ARG_SALT = 'salt';

	/** @var \Ithemes_Sync_Settings */
	protected $settings;

	/**
	 * Auth constructor.
	 *
	 * @param \Ithemes_Sync_Settings $settings The global settings object.
	 */
	public function __construct( \Ithemes_Sync_Settings $settings ) {
		$this->settings = $settings;
	}

	public function init() {
		add_action( 'admin_post_nopriv_solid_central_refresh_updates_data', [ $this, 'handle_refresh_updates_data' ] );
	}

	public function handle_refresh_updates_data() {
		$this->authorize_request();

		try {
			$this->disable_ext_object_cache();
			$this->add_third_party_compatibility();
			$this->disable_updater_transient_pre_filters();

			if ( isset( $GLOBALS['ithemes-updater-settings'] ) ) {
				$GLOBALS['ithemes-updater-settings']->flush( 'forced sync flush' );
			}

			Ithemes_Sync_Functions::refresh_plugin_updates();
			Ithemes_Sync_Functions::refresh_theme_updates();
			Ithemes_Sync_Functions::refresh_core_updates();

			$this->send_response();
		} catch ( Throwable $e ) {
			$this->send_response( new WP_Error( 'solid-central.admin-post.refresh-updates-data-error', __( 'Error refreshing updates data.', 'it-l10n-ithemes-sync' ), [ 'status' => 500 ] ) );
		}
	}

	private function authorize_request() {
		if ( ! isset( $_GET[ self::AUTH_ARG_ACTION ] ) || ! in_array( $_GET[ self::AUTH_ARG_ACTION ], self::HANDLED_ACTIONS ) ) {
			return;
		}

		$required_vars = [
			'1' => self::AUTH_ARG_ACTION,
			'2' => self::AUTH_ARG_SITE_ID,
			'3' => self::AUTH_ARG_HASH,
			'4' => self::AUTH_ARG_SALT,
		];

		foreach ( $required_vars as $index => $var ) {
			if ( ! isset( $_GET[ $var ] ) ) {
				$this->send_response( new WP_Error( "solid-central.admin-post.missing-var-$index", __( 'Invalid request.', 'it-l10n-ithemes-sync' ), [ 'status' => 400 ] ) );
			}
		}

		$authentications = $this->settings->get_option( 'authentications' );

		if ( empty( $authentications ) || ! isset( $authentications[ $_GET[ self::AUTH_ARG_SITE_ID ] ] ) ) {
			$this->send_response( new WP_Error( 'solid-central.admin-post.user-not-authenticated', __( 'Request not authorized.', 'it-l10n-ithemes-sync', [ 'status' => 401 ] ) ) );
		}

		$user_data = $authentications[ $_GET[ self::AUTH_ARG_SITE_ID ] ];

		$hash = hash( 'sha256', $_GET[ self::AUTH_ARG_SITE_ID ] . $_GET[ self::AUTH_ARG_ACTION ] . $user_data['key'] . $_GET[ self::AUTH_ARG_SALT ] );

		if ( $hash !== $_GET[ self::AUTH_ARG_HASH ] ) {
			$this->send_response( new WP_Error( 'solid-central.admin-post.hash-mismatch', __( 'Request not authorized.', 'it-l10n-ithemes-sync' ), [ 'status' => 401 ] ) );
		}

		$user = get_user_by( 'login', $user_data['local_user'] );

		if ( ! $user ) {
			$this->send_response( new WP_Error( 'solid-central.admin-post.user-not-found', __( 'Request not authorized.', 'it-l10n-ithemes-sync' ), [ 'status' => 401 ] ) );
		}
	}

	/**
	 * @param array|WP_Error|null $data
	 *
	 * @return void
	 */
	private function send_response( $data = null ) {
		$status = $data ? 200 : 204;

		if ( is_wp_error( $data ) ) {
			$response = rest_convert_error_to_response( $data );
			$status   = $response->status;
			$data     = $response->data;
		}

		status_header( $status );

		if ( $data ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $data );
		}

		exit;
	}

	/**
	 * Disables object caching.
	 *
	 * Many caching plugins supply stale data to Solid Central.
	 *
	 * Copied from `Ithemes_Sync_Request_Handler`.
	 *
	 * @return void
	 */
	private function disable_ext_object_cache() {
		if ( is_callable( 'wp_using_ext_object_cache' ) ) {
			wp_using_ext_object_cache( false );
		}
	}

	/**
	 * Adds third party compatibility.
	 *
	 * Copied from `Ithemes_Sync_Request_Handler`.
	 *
	 * @return void
	 */
	private function add_third_party_compatibility() {
		if ( is_callable( [ 'RGForms', 'check_update' ] ) ) {
			add_filter( 'transient_update_plugins', [ 'RGForms', 'check_update' ] );
			add_filter( 'site_transient_update_plugins', [ 'RGForms', 'check_update' ] );
		}
	}

	/**
	 * Disables updater transient pre-filters.
	 *
	 * Avoid conflicts with plugins that pre-filter the update transients.

	 * Copied from `Ithemes_Sync_Request_Handler`.
	 *
	 * @return void
	 */
	private function disable_updater_transient_pre_filters() {
		add_filter( 'pre_site_transient_update_plugins', '__return_false', 9999 );
		add_filter( 'pre_site_transient_update_themes', '__return_false', 9999 );
		add_filter( 'pre_site_transient_update_core', '__return_false', 9999 );
	}
}
