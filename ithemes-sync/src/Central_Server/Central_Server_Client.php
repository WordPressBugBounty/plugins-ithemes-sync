<?php
/**
 * Client for Central server communication.
 *
 * @package SolidWP\Central\Central_Server
 */

namespace SolidWP\Central\Central_Server;

use WP_Error;

/**
 * @phpstan-type Context array{site_id: int, username: string, private_key: string}
 */
class Central_Server_Client {

	const PLUGIN_API_BASE_URL = 'https://central.solidwp.com/api/plugin';

	/**
	 * Disconnect the site (current user actually) from the Central server.
	 *
	 * @param array $context Context for the request.
	 *
	 * @phpstan-param Context $context
	 *
	 * @return array|WP_Error
	 */
	public static function disconnect( array $context ) {
		return self::request( "/disconnect/{$context['site_id']}", $context );
	}

	/**
	 * Is the site (user) connected?
	 *
	 * @param array $context Context for the request.
	 *
	 * @phpstan-param Context $context
	 *
	 * @return array|WP_Error
	 */
	public static function validate( array $context ) {
		return self::request( "/validate/{$context['site_id']}", $context );
	}

	/**
	 * Retrieve the quota for the site.
	 *
	 * @param array $context Context for the request.
	 *
	 * @phpstan-param Context $context
	 *
	 * @return array|WP_Error
	 */
	public static function ping( array $context ) {
		return self::request( "/ping/{$context['site_id']}", $context );
	}

	/**
	 * Notify the Central server about an event.
	 *
	 * @param string $path Notification route part.
	 * @param array  $context Context for the request.
	 * @param array  $payload Notification payload.
	 *
	 * @phpstan-param Context $context
	 *
	 * @return array|WP_Error
	 */
	public static function notify( string $path, array $context, array $payload = [] ) {
		return self::request( "/notify/{$context['site_id']}/$path", $context, $payload );
	}

	/**
	 * @param string $route Central server route.
	 * @param array  $context Request context. `site_id`, `username`, and `private_key` keys are required.
	 *                        It's used for route and token generation.
	 * @param array  $payload Request payload to be passed as request body.
	 *
	 * @phpstan-param Context $context
	 *
	 * @return array|WP_Error
	 */
	private static function request( string $route, array $context, array $payload = [] ) {
		$base_url = apply_filters( 'sync_api_request_url', self::PLUGIN_API_BASE_URL );
		$path     = $base_url . $route;
		$token    = self::generate_token( $context );

		do_action(
			'solid_central_server_request',
			[
				'path'    => $path,
				'payload' => $payload,
			]
		);

		$response = wp_remote_post(
			$path,
			[
				'timeout' => 30, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout
				'body'    => wp_json_encode( $payload ),
				'headers' => [
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $token,
				],
			]
		);

		do_action( 'solid_central_server_response', $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$http_code     = (int) wp_remote_retrieve_response_code( $response );

		// Empty response body is valid if we have a 204 response.
		$response_body = ( $response_body === '' && $http_code === 204 )
			? []
			: json_decode( wp_remote_retrieve_body( $response ), true );
		$http_code     = (int) wp_remote_retrieve_response_code( $response );

		if ( ! is_array( $response_body ) ) {
			return new WP_Error(
				'solid-central.server-unknown-response',
				__( 'An unrecognized server response format was received from the Kadence Central server.', 'it-l10n-ithemes-sync' )
			);
		}


		if ( $http_code < 200 || $http_code >= 400 ) {
			$wp_error = new WP_Error(
				'solid-central.server-failed-request',
				__( 'There was an error with the request.', 'it-l10n-ithemes-sync' ),
				[ 'status' => $http_code ]
			);

			$errors = $response_body['errors'] ?? [];

			foreach ( $errors as $key => $messages ) {
				foreach ( $messages as $message ) {
					$wp_error->add( $key, $message );
				}
			}

			return $wp_error;
		}

		return $response_body;
	}

	/**
	 * @param array $context Request context.
	 *
	 * @phpstan-param Context $context
	 *
	 * @return string
	 */
	private static function generate_token( array $context ): string {
		$payload   = wp_json_encode(
			[
				'site_id'  => (int) $context['site_id'],
				'username' => $context['username'],
			]
		);
		$signature = hash_hmac( 'sha256', $payload, $context['private_key'] );

		return implode( '.', array_map( 'base64_encode', [ $payload, $signature ] ) );
	}
}
