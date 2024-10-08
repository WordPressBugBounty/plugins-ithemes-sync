<?php

/*
Set up admin interface.
Written by Chris Jean for iThemes.com
Version 1.2.0

Version History
	1.0.0 - 2013-10-02 - Chris Jean
		Initial version
	1.1.0 - 2013-11-19 - Chris Jean
		Added the ability for the show_sync option to control who sees the Sync interface and plugin.
	1.2.0 - 2014-02-14 - Chris Jean
		Added support for ?ithemes-sync-force-display=1 in the admin page to force a hidden Sync plugin to display for that specific user.
*/


require_once $GLOBALS['ithemes_sync_path'] . '/load-translations.php';

class Ithemes_Sync_Admin {
	private $page_name = 'solid-central';

	private $page_ref;

	private $package_details;

	private $registration_link;


	public function __construct() {
		add_action( 'init', [ $this, 'init' ] );
		add_action( 'wp_ajax_ithemes_sync_hide_notice', [ $this, 'hide_authenticate_notice' ] );
		add_action( 'admin_init', [ $this, 'add_privacy_content' ] );
		add_filter( 'heartbeat_received', [$this, 'central_ping' ], 10, 2 );
	}

	public function modify_plugins_page() {
		add_filter( 'all_plugins', [ $this, 'remove_sync_plugin' ] );
	}

	public function remove_sync_plugin( $plugins ) {
		unset( $plugins[ basename( $GLOBALS['ithemes_sync_path'] ) . '/init.php' ] );

		return $plugins;
	}

	public function init() {
		require_once $GLOBALS['ithemes_sync_path'] . '/settings.php';


		$show_sync = $GLOBALS['ithemes-sync-settings']->get_option( 'show_sync' );

		if ( is_array( $show_sync ) ) {
			$show_sync = in_array( get_current_user_id(), $show_sync );
		}

		if ( ! $show_sync && current_user_can( 'manage_options' ) ) {
			$user_id = get_current_user_id();

			if ( isset( $_GET['ithemes-sync-force-display'] ) ) {
				if ( ! empty( $_GET['ithemes-sync-force-display'] ) ) {
					$show_sync = true;
					set_site_transient( "ithemes-sync-force-display-$user_id", true, 600 );

					if ( false === $this->silent_mode_enabled() ) {
						add_action( 'all_admin_notices', [ $this, 'show_force_display_notice' ], 0 );
					}
				} else {
					delete_site_transient( "ithemes-sync-force-display-$user_id" );

					if ( false === $this->silent_mode_enabled() ) {
						add_action( 'all_admin_notices', [ $this, 'show_force_display_disable_notice' ], 0 );
					}
				}
			} elseif ( false !== get_site_transient( "ithemes-sync-force-display-$user_id" ) ) {
				$show_sync = true;

				if ( false === $this->silent_mode_enabled() ) {
					add_action( 'all_admin_notices', [ $this, 'show_force_display_notice' ], 0 );
				}
			}
		}


		if ( $show_sync && ( false === $this->silent_mode_enabled() ) ) {
			if ( ! is_multisite() || is_super_admin() ) {
				add_action( 'admin_menu', [ $this, 'add_admin_pages' ] );
			}

			add_action( 'network_admin_menu', [ $this, 'add_network_admin_pages' ] );


			if ( current_user_can( 'manage_options' ) ) {
				if ( ! get_site_option( 'ithemes-sync-authenticated' ) && ( empty( $_GET['page'] ) || ( $this->page_name != $_GET['page'] ) ) && ! get_site_option( 'ithemes_sync_hide_authenticate_notice' ) ) {
					require_once $GLOBALS['ithemes_sync_path'] . '/functions.php';

					$path_url = Ithemes_Sync_Functions::get_url( $GLOBALS['ithemes_sync_path'] );
					wp_enqueue_style( 'ithemes-updater-admin-notice-style', "$path_url/css/admin-notice.css", [], '3.0.0' );
					wp_enqueue_script( 'ithemes-updater-admin-notice-script', "$path_url/js/admin-notice.js", [ 'jquery' ], '3.0.0' );
					$params = [
						'url' => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'ithemes_sync_hide_notice' ),
					];
					wp_add_inline_script( 'ithemes-updater-admin-notice-script', 'const ithemes_sync_notice = ' . wp_json_encode( $params ) . ';', 'before' );
					if ( false === $this->silent_mode_enabled() ) {
						add_action( 'all_admin_notices', [ $this, 'show_authenticate_notice' ], 0 );
					}

					delete_site_transient( 'ithemes-sync-activated' );
				} elseif ( ! empty( $_GET['activate'] ) && get_site_transient( 'ithemes-sync-activated' ) ) {
					require_once $GLOBALS['ithemes_sync_path'] . '/functions.php';

					$path_url = Ithemes_Sync_Functions::get_url( $GLOBALS['ithemes_sync_path'] );
					wp_enqueue_style( 'ithemes-updater-admin-notice-style', "$path_url/css/admin-notice.css", [], '3.0.0' );
					wp_enqueue_script( 'ithemes-updater-admin-notice-script', "$path_url/js/admin-notice.js", [ 'jquery' ], '3.0.0' );

					if ( false === $this->silent_mode_enabled() ) {
						add_action( 'all_admin_notices', [ $this, 'show_activate_notice' ], 0 );
					}

					delete_site_transient( 'ithemes-sync-activated' );
				}
			}
		} else {
			add_action( 'load-plugins.php', [ $this, 'modify_plugins_page' ] );
		}
	}

	public function show_activate_notice() {
		if ( is_multisite() && is_network_admin() ) {
			$url = network_admin_url( 'settings.php' ) . "?page={$this->page_name}";
		} else {
			$url = admin_url( 'options-general.php' ) . "?page={$this->page_name}";
		}

		?>
	<div class="updated" id="ithemes-sync-notice">
		<?php printf( __( 'Solid Central is active. <a class="ithemes-sync-notice-button" href="%s">Manage Central</a> <a class="ithemes-sync-notice-dismiss" href="#">×</a>', 'it-l10n-ithemes-sync' ), $url ); ?>
	</div>
		<?php
	}

	public function show_authenticate_notice() {
		if ( is_multisite() && is_network_admin() ) {
			$url = network_admin_url( 'settings.php' ) . "?page={$this->page_name}";
		} else {
			$url = admin_url( 'options-general.php' ) . "?page={$this->page_name}";
		}

		?>
	<div class="updated" id="ithemes-sync-notice">
		<?php printf( __( 'Solid Central is almost ready. <a class="ithemes-sync-notice-button" href="%s">Set Up Central</a> <a class="ithemes-sync-notice-hide" href="#">×</a>', 'it-l10n-ithemes-sync' ), $url ); ?>
	</div>
		<?php
	}

	public function show_force_display_notice() {
		$user_id   = get_current_user_id();
		$time      = get_site_option( "_site_transient_timeout_ithemes-sync-force-display-$user_id" );
		$time_diff = human_time_diff( time(), $time );

		$url = admin_url( 'index.php?ithemes-sync-force-display=0' );

		?>
	<div class="updated">
		<p><?php printf( __( 'Solid Central will show for your user for the next %1$s. Click <a href="%2$s">here</a> to hide Solid Central again.', 'it-l10n-ithemes-sync' ), $time_diff, $url ); ?></p>
	</div>
		<?php
	}

	public function show_force_display_disable_notice() {

		?>
	<div class="updated">
		<p><?php _e( 'Solid Central is now hidden from your user again.', 'it-l10n-ithemes-sync' ); ?></p>
	</div>
		<?php
	}

	public function hide_authenticate_notice() {
		check_admin_referer( 'ithemes_sync_hide_notice' );
		if ( current_user_can( 'manage_options' ) ) {
			update_site_option( 'ithemes_sync_hide_authenticate_notice', true );
		} else {
			wp_die();
		}
	}

	public function add_admin_pages() {
		$this->page_ref = add_options_page( __( 'Solid Central', 'it-l10n-ithemes-sync' ), __( 'Solid Central', 'it-l10n-ithemes-sync' ), 'manage_options', $this->page_name, [ $this, 'settings_index' ] );

		add_action( "load-{$this->page_ref}", [ $this, 'load_settings_page' ] );
	}

	public function add_network_admin_pages() {
		$this->page_ref = add_submenu_page( 'settings.php', __( 'Solid Central', 'it-l10n-ithemes-sync' ), __( 'Solid Central', 'it-l10n-ithemes-sync' ), 'manage_options', $this->page_name, [ $this, 'settings_index' ] );

		add_action( "load-{$this->page_ref}", [ $this, 'load_settings_page' ] );
	}

	public function load_settings_page() {
		require_once $GLOBALS['ithemes_sync_path'] . '/settings.php';

		require $GLOBALS['ithemes_sync_path'] . '/settings-page.php';

		do_action( 'ithemes_sync_settings_page_load' );
	}

	public function settings_index() {
		do_action( 'ithemes_sync_settings_page_index' );
	}

	private function set_package_details() {
		if ( false !== $this->package_details ) {
			return;
		}

		require_once $GLOBALS['ithemes_updater_path'] . '/packages.php';
		$this->package_details = Ithemes_Updater_Packages::get_local_details();
	}

	private function set_registration_link() {
		if ( false !== $this->registration_link ) {
			return;
		}

		$url                     = admin_url( 'options-general.php' ) . "?page={$this->page_name}";
		$this->registration_link = sprintf( '<a href="%1$s" title="%2$s">%3$s</a>', $url, __( 'Manage iThemes product licenses to receive automatic upgrade support', 'it-l10n-ithemes-sync' ), __( 'License', 'it-l10n-ithemes-sync' ) );
	}

	public function filter_plugin_action_links( $actions, $plugin_file, $plugin_data, $context ) {
		$this->set_package_details();
		$this->set_registration_link();

		if ( isset( $this->package_details[ $plugin_file ] ) ) {
			$actions[] = $this->registration_link;
		}

		return $actions;
	}

	public function filter_theme_action_links( $actions, $theme ) {
		$this->set_package_details();
		$this->set_registration_link();

		if ( is_object( $theme ) ) {
			$path = basename( $theme->get_stylesheet_directory() ) . '/style.css';
		} elseif ( is_array( $theme ) && isset( $theme['Stylesheet Dir'] ) ) {
			$path = $theme['Stylesheet Dir'] . '/style.css';
		} else {
			$path = '';
		}

		if ( isset( $this->package_details[ $path ] ) ) {
			$actions[] = $this->registration_link;
		}

		return $actions;
	}

	/**
	 * Adds privacy content to wp-admin/tools.php?wp-privacy-policy-guide
	 *
	 * @since 2.0.9
	 * @return void
	 */
	function add_privacy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<div class="wp-suggested-text"><h2>' . __( 'Where we send your data', 'it-l10n-ithemes-sync' ) . '</h2>';
		$content .= sprintf( __( '%1$s%2$sSuggested text:%3$s This web site uses a third party service to manage administrative tasks. If you leave a comment, submit personal information via a contact form, or otherwise exchange personal details with us, it is possible that we may use this service to manage that data. Please visit the %4$sSolidWP Privacy Policy%5$s for more information regarding the way they handle their data.%6$s%7$s', 'it-l10n-ithemes-sync' ), '<p>', '<strong class="privacy-policy-tutorial">', '</strong>', '<a href="https://ithemes.com/privacy-policy/">', '</a>', '</p>', '</div>' );

		wp_add_privacy_policy_content( 'Solid Central', wp_kses_post( wpautop( $content, false ) ) );
	}

	/**
	 * Returns boolean depending on whether silent mode is enabled or not.
	 *
	 * Silent mode kills all sync admin notices as well as the menu item and admin page.
	 *
	 * @since 2.0.14
	 * @return boolean
	 */
	function silent_mode_enabled() {
		return apply_filters( 'ithemes-sync-silent-mode-enabled', false );
	}

	/**
	 * Sends a ping to the Central server.
	 *
	 * The Central Server will respond with data about the Central
	 * account and site connection.
	 *
	 * @since 3.2.0
	 *
	 * @param array $response The response data to send back to the client.
	 * @param array $data     The data sent from the client.
	 *
	 * @return array|WP_Error
	 */
	public function central_ping( $response, $data ) {

		// This value should contain the Central Site id.
		if ( ! isset( $data['central_ping'] ) || ! is_array( $data['central_ping'] ) ) {
			return $response;
		}

		if ( ! isset( $data['central_ping']['site_id'] ) ) {
			return $response;
		}

		require_once $GLOBALS['ithemes_sync_path'] . '/settings.php';

		$site_id = intval( $data['central_ping']['site_id']  );

		$result = $GLOBALS['ithemes-sync-settings']->do_ping_check( $site_id );

		if ( is_wp_error( $result ) ) {
			return $response;
		}

		if ( isset( $result['success'] ) ) {
			unset( $result['success'] );
		}

		// Store in transient for one day.
		set_transient( 'solid_central_ping_' . $site_id, $result, DAY_IN_SECONDS );

		$response['central_ping'] = $result;

		return $response;
	}
}

new Ithemes_Sync_Admin();
