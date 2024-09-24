<?php

/*
Provides an easy to use interface for communicating with the iThemes Sync server.
Written by Chris Jean for iThemes.com
Version 1.2.0

Version History
	1.0.0 - 2013-10-01 - Chris Jean
		Initial version
	1.0.1 - 2013-02-14 - Chris Jean
		Changed the request timeout to 30 seconds to accomodate sites that have longer delays when communicating with the Sync server.
	1.1.0 - 2014-03-28 - Chris Jean
		Added ping() and send_urgent_notices() function.
	1.1.1 - 2014-04-15 - Chris Jean
		Changed the reported WordPress version from using $wp_version to Ithemes_Sync_Functions::get_wordpress_version().
	1.1.2 - 2014-06-26 - Chris Jean
		Changed the WordPress version used to generate the salted password from using $wp_version to Ithemes_Sync_Functions::get_wordpress_version().
	1.2.0 - 2014-09-30 - Chris Jean
		Added new password hashing via the iThemes_Credentials class.
*/


class Ithemes_Sync_Server {

	public const SECURE_SERVER_URL = 'https://central.solidwp.com/plugin-api/';

	private static $password_iterations = 8;


	/**
	 * @return string|WP_Error The redirect URL to finish site connection.
	 */
	public static function authenticate() {
		$request = new WP_REST_Request( 'POST', '/solid-central/v1/auth/start' );
		$request->set_query_params(
			[
				'type' => 'central-onboard',
			] 
		);
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		if ( empty( $response->get_data()->redirect ) ) {
			return new WP_Error( 'solid-central.auth.start.invalid_response', __( 'Invalid response from Solid Central.', 'it-l10n-ithemes-sync' ) );
		}

		return $response->get_data()->redirect;
	}

	public static function deauthenticate( $user_id, $username, $private_key ) {
		$query = [
			'user_id' => $user_id,
			'user'    => $username,
		];

		$salt = hash( 'sha256', uniqid( '', true ) );

		$data = [
			'hash' => hash( 'sha256', $user_id . $username . $private_key . $salt ),
			'salt' => $salt,
		];

		return self::request( 'deauthenticate-user', $query, $data );
	}

	public static function validate( $user_id, $username, $private_key ) {
		$query = [
			'user_id' => $user_id,
			'user'    => $username,
		];

		$salt = hash( 'sha256', uniqid( '', true ) );

		$data = [
			'hash' => hash( 'sha256', $user_id . $username . $private_key . $salt ),
			'salt' => $salt,
		];

		return self::request( 'validate-user', $query, $data );
	}

	public static function ping( $user_id, $username, $private_key ) {
		$query = [
			'user_id' => $user_id,
			'user'    => $username,
		];

		$salt = hash( 'sha256', uniqid( '', true ) );

		$data = [
			'hash' => hash( 'sha256', $user_id . $username . $private_key . $salt ),
			'salt' => $salt,
		];

		return self::request( 'ping-site', $query, $data );
	}

	public static function send_urgent_notices( $user_id, $username, $private_key, $notices ) {
		$query = [
			'user_id' => $user_id,
			'user'    => $username,
		];

		$salt = hash( 'sha256', uniqid( '', true ) );

		$data = [
			'hash'    => hash( 'sha256', $user_id . $username . $private_key . $salt ),
			'salt'    => $salt,
			'notices' => $notices,
		];

		return self::request( 'send-urgent-notices', $query, $data );
	}

	public static function request( $action, $query = [], $data = [] ) {

		$secure_url = apply_filters( 'sync_api_request_url', self::SECURE_SERVER_URL );

		if ( isset( $data['auth_token'] ) ) {
			$data['iterations'] = self::$password_iterations;
		}

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

		$post_data = [
			'request' => json_encode( $data ),
		];

		$remote_post_args = [
			'timeout' => 30,
			'body'    => $post_data,
		];

		do_action(
			'solid_central_server_request',
			[
				'url'       => $secure_url . $request,
				'post_args' => $remote_post_args,
			]
		);

		$options = [
			'use_ca_patch' => false,
			'use_ssl'      => true,
		];

		$patch_enabled = $GLOBALS['ithemes-sync-settings']->get_option( 'use_ca_patch' );

		if ( $patch_enabled ) {
			$response = self::do_patched_post( $request, $remote_post_args );

			if ( is_wp_error( $response ) ) {
				$response = wp_remote_post( $secure_url . $request, $remote_post_args );
			} else {
				$options['use_ca_patch'] = true;
			}
		} else {
			$response = wp_remote_post( $secure_url . $request, $remote_post_args );

			if ( is_wp_error( $response ) ) {
				$response = self::do_patched_post( $request, $remote_post_args );

				if ( ! is_wp_error( $response ) ) {
					$options['use_ca_patch'] = true;
				}
			}
		}

		do_action( 'solid_central_server_response', $response );

		if ( is_wp_error( $response ) ) {
			$response = wp_remote_post( $secure_url . $request . '&insecure=1', $remote_post_args );

			$options['use_ssl']      = false;
			$options['use_ca_patch'] = false;
		}

		$GLOBALS['ithemes-sync-settings']->update_options( $options );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 != $response['response']['code'] ) {
			return new WP_Error( 'ithemes-sync-server-failed-request', $response['response']['message'] );
		}


		$body = json_decode( $response['body'], true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'ithemes-sync-server-unknown-response', __( 'An unrecognized server response format was received from the iThemes Sync server.', 'it-l10n-ithemes-sync' ) );
		}

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( $body['error']['type'], $body['error']['message'] );
		}

		return $body;
	}

	private static function do_patched_post( $request, $remote_post_args ) {
		$secure_url = apply_filters( 'sync_api_request_url', self::SECURE_SERVER_URL );

		self::enable_ssl_ca_patch();
		$response = wp_remote_post( $secure_url . $request . '&ca_patch=1', $remote_post_args );
		self::disable_ssl_ca_patch();

		return $response;
	}

	private static function get_password_hash( $username, $password ) {
		require_once ABSPATH . 'wp-includes/class-phpass.php';
		require_once $GLOBALS['ithemes_sync_path'] . '/class-ithemes-credentials.php';

		$password = iThemes_Credentials::get_password_hash( $username, $password );

		$salted_password = $password . $username . get_bloginfo( 'url' ) . Ithemes_Sync_Functions::get_wordpress_version();
		$salted_password = substr( $salted_password, 0, max( strlen( $password ), 512 ) );

		$hasher = new PasswordHash( self::$password_iterations, true );
		return $hasher->HashPassword( $salted_password );
	}

	public static function add_ca_patch_to_curl_opts( $handle ) {
		$secure_url = apply_filters( 'sync_api_request_url', self::SECURE_SERVER_URL );
		$url        = curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL );

		if ( ! preg_match( '/^' . preg_quote( $secure_url, '/' ) . '/', $url ) ) {
			return;
		}

		curl_setopt( $handle, CURLOPT_CAINFO, $GLOBALS['ithemes_sync_path'] . '/ca/roots.crt' );
	}

	public static function enable_ssl_ca_patch() {
		add_action( 'http_api_curl', [ __CLASS__, 'add_ca_patch_to_curl_opts' ] );
	}

	public static function disable_ssl_ca_patch() {
		remove_action( 'http_api_curl', [ __CLASS__, 'add_ca_patch_to_curl_opts' ] );
	}
}
