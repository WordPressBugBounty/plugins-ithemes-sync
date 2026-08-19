<?php

/*
Code to render and manage the settings page for Project Sync.
Written by Chris Jean for iThemes.com
Version 1.1.1

Version History
	1.0.0 - 2013-10-02 - Chris Jean
		Initial version
	1.0.1 - 2013-12-02 - Chris Jean
		Changed the deauthenticate() function to deauthentcate a user when the server reports that the user is not found. This prevents the issue where users are uanble to be removed.
	1.0.2 - 2014-02-13 - Chris Jean
		Updated error messages for failed server connections.
		Added wrap class to main settings page wrapper to allow for proper positioning of notices.
	1.1.0 - 2014-03-28 - Chris Jean
		Users are now validated and shown in valid and invalid groups.
	1.1.1 - 2014-10-13 - Chris Jean
		Updated Sync dashboard URL.
*/

use SolidWP\Central\Central_Server\Central_Server_Client;

class Ithemes_Sync_Settings_Page {
	private $page_name          = 'solid-central';
	private $path_url           = '';
	private $self_url           = '';
	private $had_error          = false;
	private $messages           = [];
	private $sync_dashboard_url = 'https://central.solidwp.com/';
	private $is_stellarsite     = false;
	private $options;

	public function __construct() {
		require_once $GLOBALS['ithemes_sync_path'] . '/functions.php';

		$this->is_stellarsite = Ithemes_Sync_Functions::is_stellarsite();
		$this->path_url       = Ithemes_Sync_Functions::get_url( $GLOBALS['ithemes_sync_path'] );

		list( $this->self_url ) = explode( '?', $_SERVER['REQUEST_URI'] );
		$this->self_url        .= '?page=' . $this->page_name;

		$this->sync_dashboard_url = apply_filters( 'sync_api_request_url', $this->sync_dashboard_url );

		add_action( 'ithemes_sync_settings_page_load', [ $this, 'handle_post_action' ] );
		add_action( 'ithemes_sync_settings_page_index', [ $this, 'index' ] );
		add_action( 'admin_print_styles', [ $this, 'add_styles' ] );
		add_action( 'admin_print_scripts', [ $this, 'add_scripts' ] );
	}

	public function add_styles() {
		wp_enqueue_style( 'ithemes-updater-settings-page-style', "{$this->path_url}/css/settings-page.css", [], '3.0.0' );
	}

	public function add_scripts() {
		$var = 'ithemes-updater-settings-page-script';

		$translations = [
			'confirm_dialog_text' => __( 'Are you sure that you wish to disconnect this user?', 'it-l10n-ithemes-sync' ),
		];

		wp_enqueue_script( $var, "{$this->path_url}/js/settings-page.js", [ 'jquery' ], '3.0.0' );
		wp_localize_script( $var, 'ithemes_sync_settings', $translations );
	}

	public function index() {
		$this->options = $GLOBALS['ithemes-sync-settings']->get_options();

		$this->show_settings();
	}

	public function handle_post_action() {
		$post_data = Ithemes_Sync_Functions::get_post_data( [ 'username', 'action', 'user' ], true, true );
		$action    = $post_data['action'];

		if ( 'deauthenticate' == $action ) {
			$this->deauthenticate( $post_data );
		}

		$this->options = $GLOBALS['ithemes-sync-settings']->get_options();
	}

	private function deauthenticate( $data ) {
		$user_details = $GLOBALS['ithemes-sync-settings']->get_authentication_details( $data['user'] );

		$result = Central_Server_Client::disconnect(
			[
				'site_id'     => $data['user'],
				'username'    => $user_details['username'],
				'private_key' => $user_details['key'],
			]
		);

		$is_error = is_wp_error( $result );

		$is_allowed_error = $is_error
							&& $result->get_error_code() === 'solid-central.server-failed-request'
							&& in_array( $result->get_error_data( 'solid-central.server-failed-request' )['status'], [ 401, 404 ], true );

		if ( $is_error && ! $is_allowed_error ) {
			$heading = $result->get_error_message();
			$message = __( 'The user could not be disconnected.', 'it-l10n-ithemes-sync' );

			$this->add_error_message( $heading, $message );

			return;
		}

		$result = $GLOBALS['ithemes-sync-settings']->remove_authentication( $data['user'], $user_details['username'] );

		if ( is_wp_error( $result ) ) {
			$heading = $result->get_error_message();
			$message = __( 'The user could not be disconnected.', 'it-l10n-ithemes-sync' );

			$this->add_error_message( $heading, $message );

			return;
		}

		$heading = '';
		$message = __( 'The user was successfully disconnected.', 'it-l10n-ithemes-sync' );

		$this->add_success_message( $heading, $message );
	}

	private function show_messages() {
		foreach ( $this->messages as $class => $messages ) {
			foreach ( $messages as $message ) {
				if ( $message['messages'] === '' ) {
					$this->show_message( $message['heading'], $message['heading'], $class );
				} else {
					$this->show_message( $message['heading'], $message['messages'], $class );
				}
			}
		}
	}

	private function show_message( $heading, $messages, $class ) {
		?>
		<div class="message <?php echo $class; ?>">
			<?php foreach ( (array) $messages as $message ) : ?>
				<p><?php echo $message; ?></p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function add_message( $heading, $messages, $class ) {
		$this->messages[ $class ][] = compact( 'heading', 'messages' );
	}

	private function add_success_message( $heading, $messages = [] ) {
		$this->add_message( $heading, $messages, 'success' );
	}

	private function add_error_message( $heading, $messages = [] ) {
		$this->add_message( $heading, $messages, 'error' );
		$this->had_error = true;
	}

	private function save_settings() {
		check_admin_referer( 'save_settings', 'ithemes_sync_nonce' );

		$settings_defaults = [];
		$settings          = [];

		foreach ( $settings_defaults as $var => $val ) {
			if ( isset( $_POST[ $var ] ) ) {
				$settings[ $var ] = $_POST[ $var ];
			} else {
				$settings[ $var ] = $val;
			}
		}

		$GLOBALS['ithemes-sync-settings']->update_options( $settings );

		$this->messages[] = __( 'Settings saved', 'it-l10n-ithemes-sync' );
	}

	public function show_settings() {
		if ( ! is_multisite() ) {
			$validations = $GLOBALS['ithemes-sync-settings']->validate_authentications();
		}

		$valid_users   = [];
		$invalid_users = [];

		uksort( $this->options['authentications'], [ $this, 'sort_usernames' ] );

		foreach ( array_keys( $this->options['authentications'] ) as $user_id ) {
			if ( ! isset( $validations ) || $validations[ $user_id ] ) {
				$valid_users[] = $user_id;
			} else {
				$invalid_users[] = $user_id;
			}
		}

		?>
		<div class="ithemes-sync-wrapper wrap">
			<?php if ( empty( $this->options['authentications'] ) ) : ?>
				<h2><?php _e( 'Connect This Site', 'it-l10n-ithemes-sync' ); ?></h2>
			<?php else : ?>
				<h2><?php _e( 'Kadence Central', 'it-l10n-ithemes-sync' ); ?></h2>
			<?php endif; ?>

			<?php $this->show_messages(); ?>

			<?php if ( ! empty( $this->options['authentications'] ) ) : ?>
				<a class="ithemes-sync-button" href="<?php echo $this->sync_dashboard_url; ?>" target="_blank"><?php _e( 'Go Manage Your Connected Sites', 'it-l10n-ithemes-sync' ); ?></a>

				<div class="ithemes-sync-section ithemes-sync-manage-users">
					<h3><?php _e( 'Manage Connected Users', 'it-l10n-ithemes-sync' ); ?></h3>

					<div class="ithemes-sync-section-inner">
						<p>
						<?php
						if ( $this->is_stellarsite ) {
							_e( 'View the list of connected users below.<br/> You can manage the connection to your StellarSite in Kadence Central.', 'it-l10n-ithemes-sync' );
						} else {
							_e( 'Central allows you to connect your site with multiple users.<br/>View the list of connected users below, and disconnect users if needed.', 'it-l10n-ithemes-sync' );
						}
						?>
						</p>

						<?php if ( ! empty( $valid_users ) ) : ?>
						<div class="ithemes-sync-users ithemes-sync-valid-users">
							<h4><?php _e( 'Connected Users', 'it-l10n-ithemes-sync' ); ?></h4>

							<ul>
								<?php foreach ( $valid_users as $user_id ) : ?>
								<li>
									<div class="user"><?php echo esc_attr( $this->options['authentications'][ $user_id ]['username'] ); ?></div>
									<?php
									$query_args = [
										'action' => 'deauthenticate',
										'user'   => $user_id,
									];
									?>
									<?php if ( ! $this->is_stellarsite ) : ?>
									<div class="deauthenticate">
										<a href="<?php echo add_query_arg( $query_args, $this->self_url ); ?>">
											<?php _e( 'Disconnect', 'it-l10n-ithemes-sync' ); ?>
										</a>
									</div>
									<?php endif; ?>
								 </li>
								<?php endforeach; ?>
							</ul>
						</div>
						<?php endif; ?>

						<?php if ( ! empty( $invalid_users ) ) : ?>
							<div class="ithemes-sync-users ithemes-sync-invalid-users">
								<h4><?php _e( 'Invalid Users', 'it-l10n-ithemes-sync' ); ?></h4>

								<p>
									<?php
									_e( 'The following users were not recognized by the server. Reconnection should be performed from the Kadence Central dashboard.', 'it-l10n-ithemes-sync' );
									?>
								</p>

								<ul>
									<?php foreach ( $invalid_users as $user_id ) : ?>
									<li>
										<div class="user"><?php echo esc_attr( $this->options['authentications'][ $user_id ]['username'] ); ?></div>
										<?php
										$query_args = [
											'action' => 'deauthenticate',
											'user'   => $user_id,
										];
										?>
										<?php if ( ! $this->is_stellarsite ) :?>
											<div class="deauthenticate">
												<a href="<?php echo add_query_arg( $query_args, $this->self_url ); ?>">
													<?php _e( 'Disconnect', 'it-l10n-ithemes-sync' ); ?>
												</a>
											</div>
										<?php endif; ?>
									</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php else : ?>
				<div class="ithemes-sync-section ithemes-sync-authorize">
					<div class="ithemes-sync-section-inner">
						<p><?php _e( 'To connect this site, add it from your Kadence Central dashboard.', 'it-l10n-ithemes-sync' ); ?></p>

						<a class="ithemes-sync-button" href="<?php echo esc_url( $this->sync_dashboard_url ); ?>" target="_blank"><?php _e( 'Go to Kadence Central', 'it-l10n-ithemes-sync' ); ?></a>
					</div>
				</div>
			<?php endif; ?>

			<?php do_action( 'sync_dev_render' ); ?>
		</div>
		<?php
	}

	private function sort_usernames( $a, $b ) {
		return strcasecmp( $this->options['authentications'][ $a ]['username'], $this->options['authentications'][ $b ]['username'] );
	}
}

new Ithemes_Sync_Settings_Page();
