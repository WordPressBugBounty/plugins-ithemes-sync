<?php
/**
 * REST API for Solid Central.
 *
 * @package SolidWP\Central\REST
 */

namespace SolidWP\Central\REST;

use Ithemes_Sync_Request_Handler;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class Verb
 *
 * @package SolidWP\Central\REST
 */
class Verb extends \WP_REST_Controller {
	/**
	 * The namespace and rest_base.
	 *
	 * @var string
	 */
	protected $rest_base = 'verb';

	/**
	 * The namespace for the REST API.
	 *
	 * @var string
	 */
	protected $namespace = 'solid-central/v1';

	/**
	 * @var \Ithemes_Sync_API
	 */
	protected $api;

	/**
	 * Auth constructor.
	 *
	 * @param \Ithemes_Sync_API $api The global verb API object.
	 */
	public function __construct(
		\Ithemes_Sync_API $api
	) {
		$this->api = $api;
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			$this->rest_base . '/',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'run' ],
				'permission_callback' => [ $this, 'permission_manage_options' ],
				'args'                => [
					'action'    => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [
							'get-status',
						],
					],
					'arguments' => [
						'type'     => 'object',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Run legacy verb request through REST API.
	 *
	 * @param WP_REST_Request $request The Request object.
	 *
	 * @return WP_Error|WP_REST_Response The Response object, else an Error.
	 */
	public function run( WP_REST_Request $request ) {
		$this->api->init();

		$response = Ithemes_Sync_Request_Handler::for_rest_request()->handle_rest_request( $request );

		return new WP_REST_Response( $response );
	}

	/**
	 * Check if a given request has permission to manage options.
	 *
	 * @return bool
	 */
	public function permission_manage_options() {
		return current_user_can( 'manage_options' );
	}
}
