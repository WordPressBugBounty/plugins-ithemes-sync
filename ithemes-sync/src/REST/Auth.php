<?php
/**
 * REST API for Solid Central.
 *
 * @package SolidWP\Central\REST
 */

namespace SolidWP\Central\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Auth
 *
 * @package SolidWP\Central\REST
 */
class Auth extends \WP_REST_Controller {

	/**
	 * The namespace and rest_base.
	 *
	 * @var string
	 */
	protected $rest_base = 'auth';

	/**
	 * The namespace for the REST API.
	 *
	 * @var string
	 */
	protected $namespace = 'solid-central/v1';

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

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/connections',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_connections' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
				'schema'              => [ $this, 'get_connection_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/start',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'auth_start' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
				'args'                => [
					'type' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'dashboard', 'backups-onboard', 'central-onboard', 'suite-onboard' ],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/verify',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'auth_verify' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
			]
		);

		register_rest_route(
			$this->namespace,
			$this->rest_base . '/key',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'add_key' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
				'args'                => [
					'site_id'          => [
						'type'     => 'integer',
						'required' => true,
					],
					'site_key'         => [
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
					],
					'solidwp_username' => [
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
					],
				],
			]
		);
	}

	/**
	 * Check if a given request has permission to manage options.
	 *
	 * @return bool
	 */
	public function permission_manage_options() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * List the available connections.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_connections() {
		require_once $GLOBALS['ithemes_sync_path'] . '/server.php';
		$authentications = $this->settings->get_option( 'authentications' );

		if ( ! $authentications ) {
			return new WP_REST_Response( [] );
		}

		$collection = [];

		foreach ( $authentications as $site_id => $authentication ) {
			$ping = \Ithemes_Sync_Server::ping( $site_id, $authentication['username'], $authentication['key'] );

			if ( is_wp_error( $ping ) || empty( $ping['success'] ) ) {
				continue;
			}

			$link = apply_filters( 'sync_api_request_url', 'https://central.solidwp.com/site/' . $ping['id'] );

			$response = new WP_REST_Response(
				[
					'id'      => $ping['id'],
					'quota'   => $ping['quota'],
					'backups' => $ping['backups'],
				]
			);
			$response->add_link(
				'alternate',
				$link,
				[
					'type' => 'text/html',
				]
			);
			$collection[] = $this->prepare_response_for_collection( $response );
		}

		return new WP_REST_Response( $collection );
	}

	/**
	 * Gets the schema shape for the connections endpoint.
	 *
	 * @return array
	 */
	public function get_connection_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'solid-central-connection',
			'type'       => 'object',
			'properties' => [
				'id'      => [
					'type' => 'integer',
				],
				'quota'   => [
					'type'       => 'object',
					'properties' => [
						'total'     => [
							'type' => 'string',
						],
						'available' => [
							'type' => 'integer',
						],
						'used'      => [
							'type' => 'integer',
						],
					],
				],
				'backups' => [
					'type'       => 'object',
					'properties' => [
						'connection_status' => [
							'type' => 'string',
							'enum' => [ 'connected', 'disconnected', 'failed', 'unknown' ],
						],
					],
				],
			],
		];
	}

	/**
	 * Start the authentication process.
	 *
	 * This will create an application password for the current user and send the user to Solid Central.
	 * The Response will contain a redirect URL and an expires_at timestamp.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function auth_start( WP_REST_Request $request ) {
		// Create an Application Password for the current user using WP_Application_Passwords.
		$user = wp_get_current_user();

		$app_password_data = \Ithemes_Sync_Functions::generate_app_password( $user );

		if ( is_wp_error( $app_password_data ) ) {
			return $app_password_data;
		}

		// Create the state for later validation.
		$state     = wp_generate_password( 64, false );
		$transient = 'solid_central_auth_state_' . $user->user_login;
		$set_state = set_transient( $transient, wp_hash( $state ), 300 );
		if ( ! $set_state ) {
			return new WP_Error( 'solid-central.auth.start.state_error', __( 'Unable to start the authentication flow.', 'it-l10n-ithemes-sync' ) );
		}

		$url  = apply_filters( 'sync_api_request_url', 'https://central.solidwp.com/plugin-api/' );
		$url .= 'auth-start';

		\Ithemes_Sync_Functions::set_time_limit( 120 );

		// Make a POST request to Central Server.
		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'site_url'     => home_url(),
						'rest_base'    => $app_password_data['rest_api'],
						'wp_username'  => $app_password_data['user_login'],
						'app_password' => $app_password_data['password'],
						'state'        => $state,
						'type'         => $request['type'],
					]
				),
				'timeout' => 90, // phpcs:ignore
			]
		);

		// if there is an error, return it.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $code >= 400 && $code < 500 ) {
			$wp_error = new WP_Error( 'solid-central.auth.start.invalid_request', __( 'There was an error with the request.', 'it-l10n-ithemes-sync' ), [ 'status' => $code ] );

			if ( isset( $response_body->errors ) ) {
				foreach ( (array) $response_body->errors as $key => $errors ) {
					foreach ( $errors as $error ) {
						$wp_error->add( $key, $error );
					}
				}
			}

			return $wp_error;
		} elseif ( $code >= 500 && $code < 600 ) {
			return new WP_Error(
				'solid-central.auth.start.server_error',
				__( 'There was a temporary issue with the Solid Central server. Please try again later.', 'it-l10n-ithemes-sync' ),
				[
					'status' => $code,
				]
			);
		}

		if ( ! isset( $response_body->redirect ) || ! isset( $response_body->expires_at ) ) {
			return new WP_Error( 'solid-central.auth.start.invalid_response', __( 'Invalid response from Solid Central', 'it-l10n-ithemes-sync' ) );
		}

		return new WP_REST_Response( $response_body, 200 );
	}

	/**
	 * Verify the authentication process.
	 *
	 * This will verify the state property from the Response.
	 *
	 * @param WP_REST_Request $request The Request object.
	 *
	 * @return WP_Error|WP_REST_Response The Response object, else an Error.
	 */
	public function auth_verify( WP_REST_Request $request ) {
		if ( ! isset( $request['state'] ) ) {
			return new WP_Error( 'solid-central.auth.verify.state_missing', __( 'State property missing from Solid Central.', 'it-l10n-ithemes-sync' ) );
		}

		$user         = wp_get_current_user();
		$transient    = 'solid_central_auth_state_' . $user->user_login;
		$stored_state = get_transient( $transient );

		if ( false === $stored_state ) {
			return new WP_Error( 'solid-central.auth.verify.state_expired', __( 'Authentication state expired.', 'it-l10n-ithemes-sync' ) );
		}

		if ( ! hash_equals( $stored_state, wp_hash( $request['state'] ) ) ) {
			return new WP_Error( 'solid-central.auth.verify.state_invalid', __( 'Invalid state property from Solid Central.', 'it-l10n-ithemes-sync' ) );
		}

		delete_transient( $transient );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Add a Central authentication for a SolidWP user.
	 *
	 * @param WP_REST_Request $request The Request object.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function add_key( WP_REST_Request $request ) {
		$user       = wp_get_current_user();
		$parameters = $request->get_json_params();

		require_once $GLOBALS['ithemes_sync_path'] . '/settings.php';
		require_once $GLOBALS['ithemes_sync_path'] . '/server.php';

		$validate = \Ithemes_Sync_Server::validate(
			$parameters['site_id'],
			$parameters['solidwp_username'],
			$parameters['site_key']
		);

		if ( is_wp_error( $validate ) ) {
			return new WP_Error(
				'solid-central.auth.invalid-credentials',
				$validate->get_error_message(),
				[
					'status' => 400,
				]
			);
		}

		$result = $GLOBALS['ithemes-sync-settings']->add_authentication(
			$parameters['site_id'],
			$parameters['solidwp_username'],
			$parameters['site_key'],
			$user->user_login
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 204 );
	}
}
