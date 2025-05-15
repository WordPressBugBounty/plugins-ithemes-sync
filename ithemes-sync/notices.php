<?php

use SolidWP\Central\Central_Server\Central_Server_Notifier;

class Ithemes_Sync_Notices {

	function __construct() {
		if ( empty( $GLOBALS['ithemes_sync_request_handler'] ) ) {
			/* WordPress Core */
			add_action( '_core_updated_successfully', [ $this, 'core_updated_successfully' ] );

			/* Plugins */
			add_action( 'activated_plugin', [ $this, 'activated_plugin' ], 10, 2 );
			add_action( 'deactivated_plugin', [ $this, 'deactivated_plugin' ], 10, 2 );
			add_action( 'delete_plugin', [ $this, 'delete_plugin' ], 10 );
			add_action( 'deleted_plugin', [ $this, 'deleted_plugin' ], 10, 2 );

			/* Themes */
			add_action( 'switch_theme', [ $this, 'switch_theme' ], 10, 2 );
			add_action( 'delete_site_transient_update_themes', [ $this, 'delete_site_transient_update_themes' ] ); // Theme Deleted

			/* Plugins and Themes */
			add_action( 'upgrader_process_complete', [ $this, 'upgrader_process_complete' ], 10, 2 );

			/* Backup Buddy */
			add_action( 'backupbuddy_run_remote_snapshot_response', [ $this, 'backupbuddy_run_remote_snapshot_response' ] );

			/* iThemes Security */
			add_action( 'itsec_log_add', [ $this, 'itsec_log_add' ], 10, 3 );
			add_action( 'itsec_two_factor_interstitial_pre_render', [ $this, 'itsec_two_factor_interstitial_pre_render' ], 10, 2 );
			add_action( 'itsec_site_scanner_scan_complete', [ $this, 'itsec_site_scan_completed' ], 10, 3 );
			add_action( 'itsec_vulnerability_not_seen', [ $this, 'itsec_vulnerability_resolution_update_notice' ], 10, 1 );
			add_action( 'itsec_vulnerability_was_seen', [ $this, 'itsec_vulnerability_resolution_update_notice' ], 10, 1 );
		}
	}

	function backupbuddy_run_remote_snapshot_response( $response ) {
		if ( ! empty( $response['success'] ) ) {
			$response['timestamp'] = time();
			$response['slug']      = 'live_snapshot_success';
			solid_central_notify_server( Central_Server_Notifier::NOTICE_LIVE_SNAPSHOT_SUCCESS, $response );
		}
	}

	function itsec_log_add( $data, $id, $log_type ) {
		if ( ! empty( $data ) && is_array( $data ) ) {
			$type   = $data['type'] ?? '';
			$module = $data['module'] ?? '';

			if ( $type === 'process-stop' && $module === 'malware' ) {
				solid_central_notify_server( Central_Server_Notifier::NOTICE_SECURITY_LOG, $data );
				return;
			}

			if ( $type === 'action' && $module === 'lockout' ) {
				// We delay notifications because it may cause too many requests to the Central server.
				solid_central_add_notice( Central_Server_Notifier::NOTICE_SECURITY_LOG, $data );
				return;
			}

			if ( $type === 'action' ) {
				solid_central_notify_server( Central_Server_Notifier::NOTICE_SECURITY_LOG, $data );
			}
		}
	}

	function itsec_two_factor_interstitial_pre_render( $session, $provider ) {
		$user       = $session->get_user();
		$session_id = $session->get_id();
		if ( $user && $session_id ) {
			solid_central_notify_server(
				Central_Server_Notifier::NOTICE_SECURITY_2FA,
				[
					'user_id'    => $user->ID,
					'session_id' => $session_id,
				]
			);
		}
	}

	function itsec_site_scan_completed( $scan, $site_id, $cached ) {
		solid_central_notify_server(
			Central_Server_Notifier::NOTICE_SITE_SCAN_COMPLETE,
			[
				'scan_id' => $scan->get_id(),
			]
		);
	}

	/**
	 * @param      $vulnerability
	 *
	 * @return void
	 */
	function itsec_vulnerability_resolution_update_notice( $vulnerability ) {
		// If this action was fired from within a site scan complete action, then we don't want to send the notice
		if ( doing_action( 'itsec_site_scanner_scan_complete' ) ) {
			return;
		}

		add_action(
			'shutdown',
			function () use ( $vulnerability ) {
				Ithemes_Sync_Functions::notify_on_itsec_vulnerability_update( $vulnerability );
			}
		);
	}

	function core_updated_successfully( $wp_version ) {
		$data['slug']    = 'wordpress_core_updated';
		$data['version'] = $wp_version;
		solid_central_notify_server( Central_Server_Notifier::NOTICE_CORE_UPDATED, $data );
	}

	function activated_plugin( $plugin_basename, $network_deactivating ) {
		$data         = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_basename, true, false );
		$data['slug'] = 'wordpress_plugin_activated';
		solid_central_notify_server( Central_Server_Notifier::NOTICE_PLUGIN_ACTIVATED, $data );
	}

	function deactivated_plugin( $plugin_basename, $network_deactivating ) {
		$data         = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_basename, true, false );
		$data['slug'] = 'wordpress_plugin_deactivated';
		solid_central_notify_server( Central_Server_Notifier::NOTICE_PLUGIN_DEACTIVATED, $data );
	}

	function delete_plugin( $plugin_file ) {
		if ( empty( $_REQUEST['action'] ) || 'delete-selected' !== $_REQUEST['action'] || empty( $_REQUEST['checked'] ) ) {
			return;
		}

		$plugin_slug = dirname( $plugin_file );
		$data        = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, true, false );

		$deleted_plugins                 = get_option( 'sync_wp_deleted_plugins', [] );
		$deleted_plugins[ $plugin_file ] = $data;
		update_option( 'sync_wp_deleted_plugins', $deleted_plugins );
	}

	function deleted_plugin( $plugin_file, $deleted ) {
		$deleted_plugins = get_option( 'sync_wp_deleted_plugins', [] );
		if ( ! empty( $deleted_plugins[ $plugin_file ] ) ) {
			$data = $deleted_plugins[ $plugin_file ];
			unset( $deleted_plugins[ $plugin_file ] );
			update_option( 'sync_wp_deleted_plugins', $deleted_plugins );
		}
		if ( $deleted ) {
			$data['slug'] = 'wordpress_plugin_uninstalled';
			solid_central_notify_server( Central_Server_Notifier::NOTICE_PLUGIN_UNINSTALLED, $data );
		}
	}

	function switch_theme( $new_name, $new_theme ) {
		if ( empty( $new_theme ) ) {
			return;
		}

		$data            = [];
		$data['slug']    = 'wordpress_theme_activated';
		$data['name']    = $new_theme->get( 'Name' );
		$data['version'] = $new_theme->get( 'Version' );
		solid_central_notify_server( Central_Server_Notifier::NOTICE_THEME_ACTIVATED, $data );
	}

	function delete_site_transient_update_themes( $transient ) {
		if ( empty( $_GET['stylesheet'] ) ) {
			return;
		}

		$data         = [];
		$data['slug'] = 'wordpress_theme_uninstalled';
		$data['name'] = $_GET['stylesheet'];
		solid_central_notify_server( Central_Server_Notifier::NOTICE_THEME_UNINSTALLED, $data );
	}

	function upgrader_process_complete( $upgrader, $extra ) {
		if ( empty( $extra['type'] ) ) {
			return;
		}

		if ( 'plugin' === $extra['type'] ) {
			if ( 'install' === $extra['action'] ) {
				if ( ! $slug = $upgrader->plugin_info() ) {
					return;
				}

				$data         = get_plugin_data( WP_PLUGIN_DIR . '/' . $slug, true, false );
				$data['slug'] = 'wordpress_plugin_installed';
				solid_central_notify_server( Central_Server_Notifier::NOTICE_PLUGIN_INSTALLED, $data );
			}
			if ( 'update' === $extra['action'] ) {
				if ( ! empty( $extra['bulk'] ) && true == $extra['bulk'] ) {
					$slugs = $extra['plugins'];
				} else {
					if ( empty( $upgrader->skin->plugin ) ) {
						return;
					}
					$slugs = [ $upgrader->skin->plugin ];
				}

				foreach ( $slugs as $slug ) {
					$data         = get_plugin_data( WP_PLUGIN_DIR . '/' . $slug, true, false );
					$data['slug'] = 'wordpress_plugin_updated';
					solid_central_notify_server( Central_Server_Notifier::NOTICE_PLUGIN_UPDATED, $data );
				}
			}
		} elseif ( 'theme' === $extra['type'] ) {
			if ( 'install' === $extra['action'] ) {
				$theme = $upgrader->theme_info();
				if ( ! $theme ) {
					return;
				}
				$data            = [];
				$data['slug']    = 'wordpress_theme_installed';
				$data['name']    = $theme->get( 'Name' );
				$data['version'] = $theme->get( 'Version' );
				solid_central_notify_server( Central_Server_Notifier::NOTICE_THEME_INSTALLED, $data );
			}
			if ( 'update' === $extra['action'] ) {
				if ( ! empty( $extra['bulk'] ) && true == $extra['bulk'] ) {
					$slugs = $extra['themes'];
				} else {
					if ( empty( $upgrader->skin->theme ) ) {
						return;
					}
					$slugs = [ $upgrader->skin->theme ];
				}
				foreach ( $slugs as $slug ) {
					$data            = [];
					$data['slug']    = 'wordpress_theme_updated';
					$theme           = wp_get_theme( $slug );
					$data['name']    = $theme->get( 'Name' );
					$data['version'] = $theme->get( 'Version' );
					solid_central_notify_server( Central_Server_Notifier::NOTICE_THEME_UPDATED, $data );
				}
			}
		}
	}
}
new Ithemes_Sync_Notices();
