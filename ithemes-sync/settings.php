<?php
/**
Central management of options storage for Project Sync.
Written by Chris Jean for iThemes.com
Version 1.2.0

Version History
	1.0.0 - 2013-10-01 - Chris Jean
		Initial version
	1.0.1 - 2013-11-18 - Chris Jean
		Updated brace format.
	1.1.0 - 2013-11-19 - Chris Jean
		Added the show_sync option.
	1.2.0 - 2014-03-20 - Chris Jean
		Added validate_authentications(), validate_authentication(), and do_ping_check().
 */

/**
 * Class Ithemes_Sync_Settings.
 */
class Ithemes_Sync_Settings {

	/**
	 * @readonly
	 * @var string
	 */
	private $option_name = 'ithemes-sync-cache';

	/**
	 * @var array|false
	 */
	private $options = false;

	/**
	 * Whether the settings have been modified.
	 *
	 * Used on shutdown to determine whether to save to the database.
	 *
	 * @var bool
	 */
	private $options_modified = false;

	/**
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * @readonly
	 * @var array
	 */
	private $default_options = [
		'authentications' => [],
		'use_ca_patch'    => false,
		'show_sync'       => true,
	];


	/**
	 * Create a settings instance.
	 *
	 * @return void
	 */
	public function __construct() {
		$GLOBALS['ithemes-sync-settings'] = $this;

		add_action( 'shutdown', [ $this, 'shutdown' ] );
	}

	/**
	 * Initialized the settings.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		$this->load();
	}

	/**
	 * @return void
	 */
	public function load() {
		if ( false !== $this->options ) {
			return;
		}

		$this->options = get_site_option( $this->option_name, false );

		if ( ( false === $this->options ) || ! is_array( $this->options ) ) {
			$this->options = [];
		}

		$this->options = array_merge( $this->default_options, $this->options );
	}

	/**
	 * Unloads the settings state.
	 *
	 * @return void
	 */
	public function unload() {
		$this->initialized      = false;
		$this->options          = false;
		$this->options_modified = false;
	}

	/**
	 * @return void
	 */
	public function shutdown() {
		if ( $this->options_modified ) {
			update_site_option( $this->option_name, $this->options );
		}
	}

	/**
	 * @return array
	 */
	public function get_options(): array {
		$this->init();

		return $this->options;
	}

	/**
	 * @param string $key The settings identifier.
	 *
	 * @return mixed|null
	 */
	public function get_option( string $key ) {
		$this->init();

		if ( isset( $this->options[ $key ] ) ) {
			return $this->options[ $key ];
		}

		return null;
	}

	/**
	 * @param array $updates The new options to merge.
	 *
	 * @return void
	 */
	public function update_options( array $updates ) {
		$this->init();

		$this->options          = array_merge( $this->options, $updates );
		$this->options_modified = true;
	}
	/**
	 * Add an authentication to the settings option.
	 *
	 * @param int          $central_site_id  The ID of the site to authenticate.
	 * @param string       $solidwp_username The username of the SolidWP account.
	 * @param string       $key              The private key for the authentication.
	 * @param string|false $wp_user_login    The WordPress user login to associate with the authentication.
	 *
	 * @return true|WP_Error
	 */
	public function add_authentication( $central_site_id, $solidwp_username, $key, $wp_user_login = false ) {
		$this->init();

		if ( ! isset( $this->options['authentications'] ) || ! is_array( $this->options['authentications'] ) ) {
			$this->options['authentications'] = [];
		}

		if ( empty( $wp_user_login ) ) {
			$local_user    = wp_get_current_user();
			$wp_user_login = $local_user->user_login;
		}

		$this->options['authentications'][ $central_site_id ] = [
			'key'        => $key,
			'timestamp'  => time(),
			'local_user' => $wp_user_login,
			'username'   => $solidwp_username,
		];

		// Calling `update_site_options` directly, rather than waiting for shutdown,
		// we want to return an error if the update fails.
		if ( ! update_site_option( $this->option_name, $this->options ) ) {
			return new WP_Error(
				'solid-central.auth.add-authentication-failed',
				__( 'Solid Central authorization failed, unable to save authentication details.', 'it-l10n-ithemes-sync' ),
				[
					'status' => 500,
				]
			);
		}

		// Any other previous modifications were also saved.
		$this->options_modified = false;

		if ( ! empty( $this->options['authentications'] ) ) {
			update_site_option( 'ithemes-sync-authenticated', true );

			return true;
		}

		delete_site_option( 'ithemes_sync_hide_authenticate_notice' );

		return new WP_Error(
			'solid-central.auth.add-authentication-failed',
			__( 'Solid Central authorization failed, encountered an unknown error.', 'it-l10n-ithemes-sync' ),
			[
				'status' => 500,
			]
		);
	}

	/**
	 * Remove a Solid Central user authentication.
	 *
	 * @param int    $user_id The WordPress user ID.
	 * @param string $username The SolidWP username.
	 *
	 * @return true|WP_Error
	 */
	public function remove_authentication( int $user_id, string $username ) {
		$this->init();


		if ( ! isset( $this->options['authentications'] ) || ! is_array( $this->options['authentications'] ) ) {
			$this->options['authentications'] = [];
		}

		if ( ! isset( $this->options['authentications'][ $user_id ] ) ) {
			return new WP_Error( 'unauthenticated-user', __( 'The user is not authenticated. Could not remove authentication for the user.', 'it-l10n-ithemes-sync' ) );
		}

		unset( $this->options['authentications'][ $user_id ] );

		$this->options_modified = true;


		if ( empty( $this->options['authentications'] ) ) {
			delete_site_option( 'ithemes-sync-authenticated' );
			delete_site_option( 'ithemes_sync_hide_authenticate_notice' );
		}


		return true;
	}

	/**
	 * @param int $user_id The WordPress user ID.
	 *
	 * @return false|mixed The user authentications or false if none.
	 */
	public function get_authentication_details( int $user_id ) {
		if ( ! isset( $this->options['authentications'][ $user_id ] ) ) {
			return false;
		}

		return $this->options['authentications'][ $user_id ];
	}

	/**
	 * Validate all previously saved user authentications.
	 *
	 * @return array<int,bool> A map of WordPress user IDs to a bool representing
	 *                         authentication status.
	 */
	public function validate_authentications(): array {
		$validations = [];

		foreach ( $this->options['authentications'] as $user_id => $details ) {
			$validations[ $user_id ] = $this->validate_authentication( $user_id );
		}

		return $validations;
	}

	/**
	 * Validate a previously saved user authentication.
	 *
	 * If a user is not provided the first authentication is used.
	 *
	 * @param int|false $user_id The WordPress user ID, optional.
	 *
	 * @return bool
	 */
	public function validate_authentication( $user_id ): bool {
		require_once $GLOBALS['ithemes_sync_path'] . '/server.php';

		$authentication = $this->get_authentication_details( $user_id );

		if ( empty( $authentication ) ) {
			return false;
		}

		$result = Ithemes_Sync_Server::validate( $user_id, $authentication['username'], $authentication['key'] );

		if ( is_wp_error( $result ) || ! is_array( $result ) || ! isset( $result['success'] ) || ! $result['success'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Use a validation request the Central server to verify connection.
	 *
	 * If a user is not provided the first authentication is used.
	 *
	 * @param int|false $user_id The WordPress user ID, optional.
	 *
	 * @return array|WP_Error
	 */
	public function do_ping_check( $user_id = false ) {
		require_once $GLOBALS['ithemes_sync_path'] . '/server.php';

		if ( empty( $user_id ) ) {
			$user_id = current( array_keys( $this->options['authentications'] ) );
		}

		$authentication = $this->get_authentication_details( $user_id );

		if ( empty( $authentication ) ) {
			return new WP_Error(
				'ithemes-sync-invalid-user',
				__(
					'A valid user was unable to be found. A valid user is required in order to do a successful ping.',
					'it-l10n-ithemes-sync'
				)
			);
		}

		$result = Ithemes_Sync_Server::ping( $user_id, $authentication['username'], $authentication['key'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || ! isset( $result['success'] ) || ! $result['success'] ) {
			return new WP_Error(
				'ithemes-sync-invalid-ping',
				__(
					'An error occurred when attempting to ping the Central server. Please try again later.',
					'it-l10n-ithemes-sync'
				)
			);
		}

		return $result;
	}
}

new Ithemes_Sync_Settings();
