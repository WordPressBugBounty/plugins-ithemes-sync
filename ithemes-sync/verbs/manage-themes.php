<?php

/*
Implementation of the manage-themes verb.
Written by Chris Jean for iThemes.com
Version 1.2.0

Version History
	1.0.0 - 2013-10-01 - Chris Jean
		Initial version
	1.1.0 - 2014-01-29 - Chris Jean
		Added the install action.
	1.2.0 - 2014-05-20 - Chris Jean
		Added install-and-activate.
		Updated install output to include result, slug, and success details.
*/


class Ithemes_Sync_Verb_Manage_themes extends Ithemes_Sync_Verb {
	public static $name        = 'manage-themes';
	public static $description = 'Activate, deactivate, and uninstall themes.';

	private $default_arguments = [];

	public function run( $arguments ) {
		$arguments = Ithemes_Sync_Functions::merge_defaults( $arguments, $this->default_arguments );

		$response = [];

		$actions = [
			'get-enabled-multisite' => 'get_enabled_themes',
			'enable-multisite'      => 'enable_themes',
			'disable-multisite'     => 'disable_themes',
			'install'               => 'install_theme',
			'uninstall'             => 'uninstall_themes',
			'install-and-activate'  => 'install_and_activate_theme',
			'activate'              => 'activate_theme',
		];

		foreach ( $arguments as $action => $data ) {
			if ( isset( $actions[ $action ] ) ) {
				$response[ $action ] = call_user_func( [ $this, $actions[ $action ] ], $data );
			} else {
				$response[ $action ] = 'This action is not recognized';
			}
		}

		if ( isset( $actions['get-actions'] ) ) {
			$response['get-actions'] = array_keys( $actions );
		}

		return $response;
	}

	private function install_and_activate_theme( $theme ) {
		if ( ! is_string( $theme ) ) {
			return [
				'error' => rest_convert_error_to_response(
					new WP_Error( 'invalid_argument', __( 'The install-and-activate action takes a string argument representing a single theme.', 'it-l10n-ithemes-sync' ) )
				)->data,
			];
		}

		$result['install'] = $this->install_theme( $theme );

		if ( isset( $result['install'][ $theme ] ) ) {
			$result['install'] = $result['install'][ $theme ];
		}

		$this->response['install-and-activate'] = $result;

		if ( ! empty( $result['install']['slug'] ) ) {
			$result['activate'] = $this->activate_theme( $result['install']['slug'] );
		} else {
			$result['activate'] = new WP_Error( 'skip-activate', __('Unable to activate due to failed install.', 'it-l10n-ithemes-sync' ) );
		}

		if ( ! is_wp_error( $result['activate'] ) ) {
			$result['success'] = true;
		} else {
			$result['activate'] = [
				'error' => rest_convert_error_to_response( $result['activate'] )->data,
			];
		}

		return $result;
	}

	private function activate_theme( $theme ) {
		if ( ! is_string( $theme ) ) {
			return [
				'error' => rest_convert_error_to_response(
					new WP_Error( 'invalid_argument', __( 'The activate action takes a string argument representing a single theme.', 'it-l10n-ithemes-sync' ) )
				)->data,
			];
		}
		switch_theme( $theme );
		$new_theme                 = wp_get_theme( $theme );
		$result['data']['name']    = $new_theme->get( 'Name' );
		$result['data']['version'] = $new_theme->get( 'Version' );
		return $result;
	}

	private function get_enabled_themes() {
		return get_site_option( 'allowedthemes' );
	}

	private function enable_themes( $themes ) {
		$allowed_themes = get_site_option( 'allowedthemes' );

		foreach ( (array) $themes as $theme ) {
			$allowed_themes[ $theme ] = true;
		}

		update_site_option( 'allowedthemes', $allowed_themes );

		return true;
	}

	private function disable_themes( $themes ) {
		$allowed_themes = get_site_option( 'allowedthemes' );

		foreach ( (array) $themes as $theme ) {
			unset( $allowed_themes[ $theme ] );
		}

		update_site_option( 'allowedthemes', $allowed_themes );

		return true;
	}

	private function install_theme( $theme ) {
		if ( ! is_string( $theme ) ) {
			return [
				'error' => rest_convert_error_to_response(
					new WP_Error( 'invalid_argument', __( 'The install action takes a string argument representing a single theme.', 'it-l10n-ithemes-sync' ) )
				)->data,
			];
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$response = [];

		Ithemes_Sync_Functions::set_time_limit( 300 );

		if ( preg_match( '{^(http|https|ftp)://}i', $theme ) ) {
			$result = $upgrader->install( $theme );
		} else {
			$api = themes_api(
				'theme_information',
				[
					'slug'   => $theme,
					'fields' => [
						'sections' => false,
						'tags'     => false,
					],
				]
			);
			if ( is_wp_error( $api ) ) {
				$result = $api;
			} else {
				$result = $upgrader->install( $api->download_link );
			}
		}

		if ( is_wp_error( $result ) ) {
			return [
				'error'             => rest_convert_error_to_response( $result )->data,
				'upgrader_feedback' => $skin->get_upgrade_messages(),
			];
		} else {
			$response['result'] = $result;

			$theme_info = $upgrader->theme_info();

			if ( $theme_info instanceof WP_Theme ) {
				$response['slug'] = basename( $theme_info->get_stylesheet() );
				$response['name'] = $theme_info->get('Name');
				$response['version'] = $theme_info->get('Version');
			} elseif ( isset( $upgrader->result ) && ! empty( $upgrader->result['destination_name'] ) ) {
				$response['slug'] = $upgrader->result['destination_name'];
			}

			if ( true === $result ) {
				$response['success'] = true;
			}

			if ( $skin->get_errors()->has_errors() ) {
				$response['error'] = rest_convert_error_to_response( $skin->get_errors() )->data;
			}
		}

		$response['upgrader_feedback'] = $skin->get_upgrade_messages();

		Ithemes_Sync_Functions::refresh_theme_updates();

		return $response;
	}

	private function uninstall_themes( $themes ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		require_once ABSPATH . '/wp-admin/includes/theme.php';

		$response = [];

		foreach ( (array) $themes as $theme ) {
			$response[ $theme ] = delete_theme( $theme );
		}

		return $response;
	}
}
