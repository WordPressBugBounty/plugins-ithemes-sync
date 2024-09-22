<?php

/*
Misc functions to assist the Sync code.
Written by Chris Jean for iThemes.com
Version 1.9.0

Version History
	1.8.0 - 2014-03-28 - Chris Jean
		Added find_match_in_file() and get_wordpress_db_version().
		get_wordpress_version() now uses find_match_in_file().
	1.8.1 - 2014-04-14 - Chris Jean
		The reported WordPress version now uses wp-includes/version.php in order to get a reliable version number.
		Added mysqli_get_server_info entry to server status details in the event that WordPress is using mysqli.
	1.8.2 - 2014-05-19 - Chris Jean
		Added checks to mysql_get_server_info() and mysqli_get_server_info() calls to avoid notices and errors.
	1.8.3 - 2014-06-23 - Chris Jean
		Silenced possible notice-generating function calls by using @.
		Replaced direct shell_exec() calls with calls to self::run_shell_command().
	1.8.4 - 2014-06-30 - Chris Jean
		Use mysqli_get_host_info instead of mysql_get_host_info when mysqli is being used.
	1.8.5 - 2014-07-11 - Chris Jean
		Added is_callable_function() which checks both is_callable() and the disable_functions ini setting to determine if a function is callable.
		Replaced is_callable() calls for PHP functions with calls to self::is_callable_function() in order to avoid issues with servers that stop processing after a disabled function is run.
	1.8.6 - 2014-10-23 - Chris Jean
		Added a fix to check for functions blacklisted by Suhosin before attempting to execute.
	1.8.7 - 2014-11-06 - Chris Jean
		Fixed warnings that can happen when generating memory details on some systems.
	1.9.0 - 2016-07-20 - Lew Ayotte
		Added get_upload_reports_dir
*/


class Ithemes_Sync_Functions {
	public static function get_url( $path ) {
		$path           = str_replace( '\\', '/', $path );
		$wp_content_dir = str_replace( '\\', '/', WP_CONTENT_DIR );

		if ( 0 === strpos( $path, $wp_content_dir ) ) {
			return content_url( str_replace( $wp_content_dir, '', $path ) );
		}

		$abspath = str_replace( '\\', '/', ABSPATH );

		if ( 0 === strpos( $path, $abspath ) ) {
			return site_url( str_replace( $abspath, '', $path ) );
		}

		$wp_plugin_dir   = str_replace( '\\', '/', WP_PLUGIN_DIR );
		$wpmu_plugin_dir = str_replace( '\\', '/', WPMU_PLUGIN_DIR );

		if ( 0 === strpos( $path, $wp_plugin_dir ) || 0 === strpos( $path, $wpmu_plugin_dir ) ) {
			return plugins_url( basename( $path ), $path );
		}

		return false;
	}

	public static function get_post_data( $vars, $fill_missing = false, $merge_get_query = false ) {
		$data = [];

		foreach ( $vars as $var ) {
			if ( isset( $_POST[ $var ] ) ) {
				$clean_var          = preg_replace( '/^it-updater-/', '', $var );
				$data[ $clean_var ] = $_POST[ $var ];
			} elseif ( $merge_get_query && isset( $_GET[ $var ] ) ) {
				$clean_var          = preg_replace( '/^it-updater-/', '', $var );
				$data[ $clean_var ] = $_GET[ $var ];
			} elseif ( $fill_missing ) {
				$data[ $var ] = '';
			}
		}

		return stripslashes_deep( $data );
	}

	public static function filter_user_has_cap( $capabilities, $caps, $args ) {
		foreach ( $caps as $cap ) {
			$capabilities[ $cap ] = 1;
		}

		return $capabilities;
	}

	public static function get_plugin_details( $args = [] ) {
		if ( ! is_callable( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_callable( 'get_plugins' ) ) {
			return false;
		}


		$plugins = get_plugins();

		$active_plugins         = ( is_callable( 'wp_get_active_and_valid_plugins' ) ) ? wp_get_active_and_valid_plugins() : [];
		$network_active_plugins = ( is_callable( 'wp_get_active_network_plugins' ) ) ? wp_get_active_network_plugins() : [];
		// $mu_plugins = ( is_callable( 'get_mu_plugins' ) ) ? get_mu_plugins() : array();
		// $dropins = ( is_callable( 'get_dropins' ) ) ? get_dropins() : array();

		array_walk( $active_plugins, [ __CLASS__, 'strip_plugin_dir' ] );
		array_walk( $network_active_plugins, [ __CLASS__, 'strip_plugin_dir' ] );

		foreach ( $plugins as $plugin => $data ) {
			if ( in_array( $plugin, $active_plugins ) ) {
				$plugins[ $plugin ]['status'] = 'active';
			} elseif ( in_array( $plugin, $network_active_plugins ) ) {
				$plugins[ $plugin ]['status'] = 'network_active';
			} else {
				$plugins[ $plugin ]['status'] = 'inactive';
			}

			if ( empty( $args['verbose'] ) ) {
				unset( $plugins[ $plugin ]['PluginURI'] );
				unset( $plugins[ $plugin ]['Description'] );
				unset( $plugins[ $plugin ]['Author'] );
				unset( $plugins[ $plugin ]['AuthorURI'] );
				unset( $plugins[ $plugin ]['TextDomain'] );
				unset( $plugins[ $plugin ]['DomainPath'] );
				unset( $plugins[ $plugin ]['Title'] );
				unset( $plugins[ $plugin ]['AuthorName'] );
			} else {
				$path = WP_PLUGIN_DIR . '/' . dirname( $plugin );

				$vcs_details = self::get_repository_directory_details( $path );

				if ( false !== $vcs_details ) {
					$plugins[ $plugin ]['vcs'] = $vcs_details;
				}
			}
		}


		return $plugins;
	}

	public static function get_plugin_data( $path ) {
		if ( ! is_callable( 'get_plugin_data' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_callable( 'get_plugin_data' ) ) {
			return false;
		}

		$wp_plugin_dir = preg_replace( '|\\\|', '/', WP_PLUGIN_DIR );
		$path          = preg_replace( '|\\\|', '/', $path );

		$path = preg_replace( '/^' . preg_quote( $wp_plugin_dir, '/' ) . '/', '', $path );
		$path = preg_replace( '|^/+|', '', $path );
		$path = WP_PLUGIN_DIR . "/$path";

		return get_plugin_data( $path, false, false );
	}

	public static function get_file_data( $file, $type = '' ) {
		$headers = [
			'name'             => 'Name',
			'version'          => 'Version',
			'description'      => 'Description',
			'author'           => 'Author',
			'author-uri'       => 'Author URI',
			'text-domain'      => 'Text Domain',
			'text-domain-path' => 'Domain Path',
			'ithemes-package'  => 'iThemes Package',
		];

		$plugin_headers = [
			'plugin-uri' => 'Plugin URI',
			'network'    => 'Network',
			'sitewide'   => '_sitewide',
		];

		$theme_headers = [
			'theme-uri' => 'ThemeURI',
			'template'  => 'Template',
			'status'    => 'Status',
			'tags'      => 'Tags',
		];


		if ( 'plugin' === $type ) {
			$headers = array_merge( $headers, $plugin_headers );
		} elseif ( 'theme' === $type ) {
			$headers = array_merge( $headers, $theme_headers );
		}

		return get_file_data( $file, $headers );
	}

	public static function find_match_in_file( $file, $expression, $index = false ) {
		$fh = fopen( $file, 'r' );

		$data   = '';
		$retval = false;

		while ( $file_read = fread( $fh, 256 ) ) {
			$data .= $file_read;

			if ( preg_match( $expression, $data, $match ) ) {
				$retval = $match;
				break;
			}
		}

		fclose( $fh );

		if ( false !== $index ) {
			if ( is_array( $retval ) && isset( $retval[ $index ] ) ) {
				return $retval[ $index ];
			} else {
				return false;
			}
		}

		return $retval;
	}

	public static function get_wordpress_version() {
		return self::find_match_in_file( ABSPATH . WPINC . '/version.php', "/\\\$wp_version\s*=\s*(['\"])([^'\"]+)\\1/", 2 );
	}

	public static function get_wordpress_db_version() {
		return self::find_match_in_file( ABSPATH . WPINC . '/version.php', "/\\\$wp_db_version\s*=\s*['\"]?(\d+)/", 1 );
	}

	public static function strip_plugin_dir( &$path ) {
		$path = preg_replace( '|^' . preg_quote( WP_PLUGIN_DIR, '|' ) . '/|', '', $path );
	}

	public static function get_theme_details( $args = [] ) {
		if ( ! is_callable( 'wp_get_themes' ) ) {
			return false;
		}


		$themes = [];

		$active_stylesheet = basename( get_stylesheet_directory() );
		$active_template   = basename( get_template_directory() );

		foreach ( wp_get_themes() as $dir => $theme ) {
			$data = [
				'name'    => $theme['Name'],
				'version' => $theme['Version'],
				'parent'  => $theme->parent_theme,
			];

			if ( ! empty( $args['verbose'] ) ) {
				$data['description'] = $theme['Description'];
				$data['author']      = $theme['Author Name'];
				$data['author-uri']  = $theme['Author URI'];


				$vcs_details = self::get_repository_directory_details( $theme['Stylesheet Dir'] );

				if ( false !== $vcs_details ) {
					$data['vcs'] = $vcs_details;
				}
			}

			if ( empty( $data['parent'] ) ) {
				unset( $data['parent'] );
			} else {
				$data['parent'] = $theme->parent()->stylesheet;
			}

			if ( $dir == $active_stylesheet ) {
				$data['status'] = 'active';
			} elseif ( $dir == $active_template ) {
				$data['status'] = 'active_parent';
			} else {
				$data['status'] = '';
			}


			$themes[ $dir ] = $data;
		}


		return $themes;
	}

	public static function refresh_plugin_updates() {
		require_once ABSPATH . 'wp-includes/update.php';

		if ( is_callable( 'wp_update_plugins' ) ) {
			return wp_update_plugins();
		}

		return false;
	}

	public static function refresh_theme_updates() {
		require_once ABSPATH . 'wp-includes/update.php';

		if ( is_callable( 'wp_update_themes' ) ) {
			return wp_update_themes();
		}

		return false;
	}

	public static function refresh_core_updates() {
		require_once ABSPATH . 'wp-includes/update.php';

		if ( is_callable( 'wp_version_check' ) ) {
			return wp_version_check( [], true );
		}

		return false;
	}

	public static function get_update_details( $args = [] ) {
		if ( ! empty( $args['ithemes-updater-force-refresh'] ) && isset( $GLOBALS['ithemes-updater-settings'] ) ) {
			$GLOBALS['ithemes-updater-settings']->flush( 'forced sync flush' );
		}

		$default_args = [
			'verbose'       => false,
			'force_refresh' => false,
		];
		$args         = array_merge( $default_args, $args );


		$updates = [
			'plugins'      => [],
			'themes'       => [],
			'translations' => [],
			'core'         => [],
		];


		if ( is_array( $args['force_refresh'] ) ) {
			if ( in_array( 'plugins', $args['force_refresh'] ) ) {
				$updates['force-refresh-results']['plugins'] = self::refresh_plugin_updates();
			}
			if ( in_array( 'themes', $args['force_refresh'] ) ) {
				$updates['force-refresh-results']['themes'] = self::refresh_theme_updates();
			}
			if ( in_array( 'core', $args['force_refresh'] ) ) {
				$updates['force-refresh-results']['core'] = self::refresh_core_updates();
			}
		} elseif ( $args['force_refresh'] ) {
			$updates['force-refresh-results']['plugins'] = self::refresh_plugin_updates();
			$updates['force-refresh-results']['themes']  = self::refresh_theme_updates();
			$updates['force-refresh-results']['core']    = self::refresh_core_updates();
		}


		$update_plugins = get_site_transient( 'update_plugins' );

		if ( ! empty( $update_plugins->response ) ) {
			$updates['plugins'] = $update_plugins->response;

			if ( empty( $args['verbose'] ) ) {
				foreach ( $updates['plugins'] as $plugin => $data ) {
					unset( $updates['plugins'][ $plugin ]->id );
					unset( $updates['plugins'][ $plugin ]->slug );
					unset( $updates['plugins'][ $plugin ]->url );
					unset( $updates['plugins'][ $plugin ]->package );
				}
			}
		}

		if ( ! empty( $update_plugins->translations ) ) {
			$updates['translations'] = array_merge( $updates['translations'], $update_plugins->translations );
		}


		$update_themes = get_site_transient( 'update_themes' );

		if ( ! empty( $update_themes->response ) ) {
			$updates['themes'] = $update_themes->response;

			if ( empty( $args['verbose'] ) ) {
				foreach ( $updates['themes'] as $theme => $data ) {
					unset( $updates['themes'][ $theme ]['package'] );
					unset( $updates['themes'][ $theme ]['url'] );
				}
			}
		}

		if ( ! empty( $update_themes->translations ) ) {
			$updates['translations'] = array_merge( $updates['translations'], $update_themes->translations );
		}


		$update_core = get_site_transient( 'update_core' );

		if ( ! empty( $update_core->updates ) ) {
			$updates['core'] = $update_core->updates;

			foreach ( $updates['core'] as $index => $update ) {
				if ( empty( $update->current ) && ! empty( $update->version ) ) {
					$updates['core'][ $index ]->current = $update->version;
				} elseif ( empty( $update->version ) && ! empty( $update->current ) ) {
					$updates['core'][ $index ]->version = $update->current;
				}

				if ( empty( $args['verbose'] ) ) {
					unset( $updates['core'][ $index ]->download );
					unset( $updates['core'][ $index ]->packages );
					unset( $updates['core'][ $index ]->php_version );
					unset( $updates['core'][ $index ]->mysql_version );
					unset( $updates['core'][ $index ]->new_bundled );
					unset( $updates['core'][ $index ]->partial_version );
				}
			}
		}

		if ( ! empty( $update_core->translations ) ) {
			$updates['translations'] = array_merge( $updates['translations'], $update_core->translations );
		}


		return $updates;
	}

	public static function get_wordpress_details( $args = [] ) {
		$details = [
			'version'   => self::get_wordpress_version(),
			'url'       => get_bloginfo( 'url' ),
			'wpurl'     => get_bloginfo( 'wpurl' ),
			'login-url' => wp_login_url(),
			'admin-url' => admin_url(),
		];

		if ( is_callable( 'is_multisite' ) ) {
			if ( is_multisite() ) {
				$details['multisite'] = true;

				if ( is_callable( 'get_current_blog_id' ) ) {
					$details['blogid'] = get_current_blog_id();
				} elseif ( isset( $GLOBALS['blogid'] ) ) {
					$details['blogid'] = $GLOBALS['blogid'];
				}
			} else {
				$details['multisite'] = false;
			}
		}

		if ( ! empty( $args['verbose'] ) ) {
			$vcs_details = self::get_repository_directory_details( ABSPATH );

			if ( false !== $vcs_details ) {
				$details['vcs'] = $vcs_details;
			}
		}

		// Get wordpress environment
		if ( is_callable( 'wp_get_environment_type' ) ) {
			$details['environment'] = wp_get_environment_type();
		}

		return $details;
	}

	public static function get_php_details( $args = [] ) {
		$details['display_errors']  = $GLOBALS['ithemes_sync_request_handler']->original_display_errors;
		$details['error_reporting'] = $GLOBALS['ithemes_sync_request_handler']->original_error_reporting;

		if ( self::is_callable_function( 'ini_get' ) ) {
			$details['disable_functions']               = ini_get( 'disable_functions' );
			$details['suhosin.executor.func.blacklist'] = ini_get( 'suhosin.executor.func.blacklist' );
		}


		$functions = [
			'phpversion',
			'PHP_VERSION',
			'php_sapi_name',
			'PHP_SAPI',
		];

		$details = self::get_function_results( $functions, $details );


		if ( empty( $args['verbose'] ) ) {
			return $details;
		}


		$functions = [
			'zend_version',
			'sys_get_temp_dir',
			'get_loaded_extensions',
		];

		$details = self::get_function_results( $functions, $details );


		if ( self::is_callable_function( 'phpinfo' ) ) {
			ob_start();
			phpinfo();

			$phpinfo = ob_get_clean();
			$phpinfo = preg_replace( '/<[^>]*>/', ' ', $phpinfo );
			$phpinfo = html_entity_decode( $phpinfo, ENT_QUOTES );

			$patterns = [
				'php-version'     => '/^\s*PHP Version\s+(.+)\s*$/mi',
				'build-system'    => '/^\s*System\s+(.+)\s*$/mi',
				'configure'       => '/^\s*Configure Command\s+(.+)\s*$/mi',
				'server-api'      => '/^\s*Server API\s+(.+)\s*$/mi',
				'gd-support'      => '/^\s*GD Support\s+(.+)\s*$/mi',
				'json-support'    => '/^\s*json support\s+(.+)\s*$/mi',
				'mb-support'      => '/^\s*Multibyte Support\s+(.+)\s*$/mi',
				'server-software' => '/^\s*SERVER_SOFTWARE\s+(.+)\s*$/mi',
			];

			$details['phpinfo'] = self::get_pattern_results( $phpinfo, $patterns );
		}


		return $details;
	}

	public static function get_server_details( $args = [] ) {
		$details = [
			'timezone_string' => get_option( 'timezone_string' ),
			'gmt_offset'      => get_option( 'gmt_offset' ),
			'abspath'         => ABSPATH,
		];

		$details['SERVER_ADDR'] = $_SERVER['SERVER_ADDR'] ?? '';

		return $details;
	}

	private static function get_server_ip() {
		$data = wp_remote_get( 'http://ithemes.com/utilities/show-remote-ip.php' );

		if ( ! is_wp_error( $data ) && preg_match( '/(\d+\.\d+\.\d+\.\d+)/', $data['body'], $match ) ) {
			return $match[1];
		}


		$data = wp_remote_get( 'http://ip4.me/' );

		if ( ! is_wp_error( $data ) && preg_match( '/>(\d+\.\d+\.\d+\.\d+)</', $data['body'], $match ) ) {
			return $match[1];
		}


		return false;
	}

	private static function get_function_results( $functions, $data = [] ) {
		foreach ( $functions as $function ) {
			$var = $function;

			if ( false === strpos( $function, '|' ) ) {
				$args = [];
			} else {
				$args     = explode( '|', $function );
				$function = array_shift( $args );

				if ( ( 1 === count( $args ) ) && ( 0 === strpos( $args[0], '[' ) ) ) {
					$new_args = @json_decode( $args[0] );

					if ( ! is_null( $new_args ) ) {
						$args = $new_args;
					}
				}
			}

			if ( self::is_callable_function( $function ) ) {
				$data[ $var ] = call_user_func_array( $function, array_values( $args ) );
			} elseif ( defined( $function ) && empty( $args ) ) {
				$data[ $var ] = constant( $function );
			}
		}

		return $data;
	}

	private static function get_pattern_results( $raw_data, $patterns, $data = [] ) {
		foreach ( $patterns as $name => $pattern ) {
			if ( preg_match( $pattern, $raw_data, $match ) ) {
				$data[ $name ] = $match[1];
			}
		}

		return $data;
	}

	private static function get_shell_command_results( $commands, $data = [] ) {
		foreach ( $commands as $command ) {
			$result = self::run_shell_command( $command );

			if ( false !== $result ) {
				$data[ $command ] = $result;
			}
		}

		return $data;
	}

	private static function run_shell_command( $command ) {
		$command = 'PATH="$PATH:/usr/local/bin:/bin:/usr/bin:/sbin:/usr/sbin:/usr/local/sbin"; ' . $command;

		if ( self::is_callable_function( 'shell_exec' ) ) {
			$result = @shell_exec( $command );

			if ( is_null( $result ) ) {
				return false;
			}

			return $result;
		}

		if ( self::is_callable_function( 'exec' ) ) {
			@exec( $command, $results, $status );

			if ( ! empty( $results ) ) {
				return implode( "\n", $results );
			} elseif ( empty( $status ) ) {
				return '';
			} else {
				return false;
			}
		}

		if ( self::is_callable_function( 'system' ) ) {
			ob_start();
			$return = @system( $command, $status );
			$result = ob_get_clean();

			if ( false === $return ) {
				return false;
			} elseif ( ! empty( $result ) ) {
				return $result;
			} elseif ( empty( $status ) ) {
				return '';
			} else {
				return false;
			}
		}

		if ( self::is_callable_function( 'passthru' ) ) {
			ob_start();
			$return = @passthru( $command, $status );
			$result = ob_get_clean();

			if ( false === $return ) {
				return false;
			} elseif ( ! empty( $result ) ) {
				return $result;
			} elseif ( empty( $status ) ) {
				return '';
			} else {
				return false;
			}
		}

		return false;
	}

	public static function merge_defaults( $values, $defaults, $force = false ) {
		if ( ! self::is_associative_array( $defaults ) ) {
			if ( ! isset( $values ) ) {
				return $defaults;
			}

			if ( false === $force ) {
				return $values;
			}

			if ( isset( $values ) || is_array( $values ) ) {
				return $values;
			}

			return $defaults;
		}

		foreach ( (array) $defaults as $key => $val ) {
			if ( ! isset( $values[ $key ] ) ) {
				$values[ $key ] = null;
			}

			$values[ $key ] = self::merge_defaults( $values[ $key ], $val, $force );
		}

		return $values;
	}

	public static function is_associative_array( &$array ) {
		if ( ! is_array( $array ) || empty( $array ) ) {
			return false;
		}

		$next = 0;

		foreach ( $array as $k => $v ) {
			if ( $k !== $next++ ) {
				return true;
			}
		}

		return false;
	}

	public static function get_users( $query_args = [] ) {
		$default_query_args = [
			'blog_id' => 0,
		];
		$query_args         = array_merge( $default_query_args, $query_args );

		if ( ! empty( $query_args['capability'] ) ) {
			$capabilities = (array) $query_args['capability'];
			unset( $query_args['capability'] );
		}

		$all_users = get_users( $query_args );

		$users = [];

		foreach ( $all_users as $user ) {
			if ( ! empty( $capabilities ) ) {
				$user_can = true;

				foreach ( (array) $capabilities as $capability ) {
					if ( ! user_can( $user, $capability ) ) {
						$user_can = false;
						break;
					}
				}

				if ( ! $user_can ) {
					continue;
				}
			}

			$users[ $user->ID ] = [
				'login'        => $user->data->user_login,
				'display_name' => $user->data->display_name,
			];
		}


		return $users;
	}

	public static function get_sync_settings( $args = [] ) {
		$all_settings = $GLOBALS['ithemes-sync-settings']->get_options();

		if ( ! empty( $args['settings'] ) ) {
			$keys = $args['settings'];
		} elseif ( ! empty( $args['verbose'] ) ) {
			$keys = array_keys( $all_settings );

			$keys = array_flip( $keys );
			unset( $keys['authentications'] );
			$keys = array_flip( $keys );
		} else {
			$keys = [
				'show_sync',
			];
		}

		$settings = [];

		foreach ( $keys as $key ) {
			if ( isset( $all_settings[ $key ] ) ) {
				$settings[ $key ] = $all_settings[ $key ];
			} else {
				$settings[ $key ] = null;
			}
		}

		if ( ! in_array( 'authentications', $keys ) && isset( $settings['authentications'] ) ) {
			unset( $settings['authentications'] );
		}


		return $settings;
	}

	public static function get_supported_verbs( $args = [] ) {
		if ( ! is_callable( [ $GLOBALS['ithemes-sync-api'], 'get_descriptions' ] ) ) {
			return new WP_Error( 'missing-method-api-get_descriptions', 'The Ithemes_Sync_API::get_descriptions function is not callable.' );
		}

		return $GLOBALS['ithemes-sync-api']->get_names();
	}

	public static function get_status_elements( $args = [] ) {
		if ( ! is_callable( [ $GLOBALS['ithemes-sync-api'], 'get_status_elements' ] ) ) {
			return new WP_Error( 'missing-method-api-get_status_elements', 'The Ithemes_Sync_API::get_status_elements function is not callable.' );
		}

		return $GLOBALS['ithemes-sync-api']->get_status_elements();
	}

	public static function get_default_status_elements( $args = [] ) {
		if ( ! is_callable( [ $GLOBALS['ithemes-sync-api'], 'get_default_status_elements' ] ) ) {
			return new WP_Error( 'missing-method-api-get_default_status_elements', 'The Ithemes_Sync_API::get_default_status_elements function is not callable.' );
		}

		return $GLOBALS['ithemes-sync-api']->get_default_status_elements();
	}

	public static function set_time_limit( $seconds = 60 ) {
		if ( is_callable( 'set_time_limit' ) ) {
			@set_time_limit( $seconds );
		}
	}

	public static function get_repository_directory_details( $path ) {
		$vcs_types = [
			'.git' => [
				'name' => 'git',
			],
			'.svn' => [
				'name' => 'subversion',
			],
			'.hg'  => [
				'name' => 'mercurial',
			],
			'.bzr' => [
				'name' => 'bazaar',
			],
		];

		foreach ( $vcs_types as $directory => $details ) {
			if ( is_dir( "$path/$directory" ) ) {
				return $details;
			}
		}

		return false;
	}

	public static function is_callable_function( $function ) {
		if ( ! is_callable( $function ) ) {
			return false;
		}

		$disabled_functions = preg_split( '/\s*,\s*/', (string) ini_get( 'disable_functions' ) );

		if ( in_array( $function, $disabled_functions ) ) {
			return false;
		}

		$disabled_functions = preg_split( '/\s*,\s*/', (string) ini_get( 'suhosin.executor.func.blacklist' ) );

		if ( in_array( $function, $disabled_functions ) ) {
			return false;
		}

		return true;
	}

	public static function get_upload_reports_dir() {
		$wp_upload_dir = wp_upload_dir();
		$reports_path  = apply_filters( 'get_upload_reports_dir', $wp_upload_dir['basedir'] . '/reports' );

		if ( ! file_exists( $reports_path ) ) {
			wp_mkdir_p( $reports_path );
		}

		return $reports_path;
	}

	public static function get_upload_reports_url() {
		$wp_upload_dir = wp_upload_dir();
		return apply_filters( 'get_upload_reports_url', $wp_upload_dir['baseurl'] . '/reports' );
	}

	public static function generate_sync_nonce( $name ) {

		$nonce = [
			'value'      => wp_generate_password( 24 ),
			'expiration' => time() + 3600,
		];

		update_option( 'ithemes-sync-nonce-' . $name, $nonce, false );

		return $nonce;
	}

	public static function validate_sync_nonce( $name, $supplied_nonce ) {
		$nonce = get_option( 'ithemes-sync-nonce-' . $name );

		if ( $nonce !== false && $nonce['expiration'] > time() && hash_equals( $supplied_nonce, $nonce['value'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if sodium library and methods we use are available
	 * Also checks if sodium is fast enough on this system
	 * If available: include the compatiability layer, core utilities, and Base64 UrlSafe classes
	 *
	 * @return bool
	 */
	public static function is_sodium_available() {
		$requiredFiles = [
			'wp-includes/sodium_compat/autoload.php',
		];

		foreach ( $requiredFiles as $file ) {
			if ( file_exists( ABSPATH . $file ) ) {
				require_once ABSPATH . $file;
			} else {
				return false;
			}
		}

		// Verify the functions we need are callable
		if ( ! is_callable( 'sodium_base642bin' ) || ! is_callable( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}

		// Check for a edge-case affecting PHP Maths abilities
		// Sodium_Compat isn't compatible with PHP 7.2.0~7.2.2 due to a bug in the PHP Opcache extension, bail early as it'll fail.
		if (
			! extension_loaded( 'sodium' ) &&
			in_array( PHP_VERSION_ID, [ 70200, 70201, 70202 ], true ) &&
			extension_loaded( 'opcache' )
		) {
			return false;
		}

		// Verify runtime speed of Sodium_Compat is acceptable.
		if ( ! extension_loaded( 'sodium' ) && ! ParagonIE_Sodium_Compat::polyfill_is_fast() ) {

			// Allow for an old version of Sodium_Compat being loaded before the bundled WordPress one.
			if ( method_exists( 'ParagonIE_Sodium_Compat', 'runtime_speed_test' ) ) {
				// Run `ParagonIE_Sodium_Compat::runtime_speed_test()` in optimized integer mode, as that's what is used for signing verifications.
				$old_fastMult                      = ParagonIE_Sodium_Compat::$fastMult;
				ParagonIE_Sodium_Compat::$fastMult = true;
				$sodium_compat_is_fast             = ParagonIE_Sodium_Compat::runtime_speed_test( 100, 10 );
				ParagonIE_Sodium_Compat::$fastMult = $old_fastMult;

				return $sodium_compat_is_fast;
			}       
		}

		return true;
	}

	public static function notify_on_itsec_vulnerability_update( $vulnerability ) {

		$current_user = wp_get_current_user();

		if ( self::set_global_user_for_api_request() ) {
			$request  = new WP_REST_Request( 'GET', '/ithemes-security/v1/site-scanner/vulnerabilities/' . $vulnerability->get_id() );
			$response = rest_do_request( $request );

			if ( $response->is_error() ) {
				$response_error = $response->as_error();
				$errors_array   = [];
				foreach ( $response_error->get_error_codes() as $code ) {
					$errors_array[ $code ] = $response_error->get_error_message( $code );
				}
				ithemes_sync_send_urgent_notice(
					'solid-security',
					'vulnerability-resolution-failed',
					'Solid Security',
					'Solid Security',
					[
						'vuln_id' => $vulnerability->get_id(),
						'message' => $response_error->get_error_message(),
						'errors'  => $errors_array,
					]
				);
			} else {
				ithemes_sync_send_urgent_notice(
					'solid-security',
					'vulnerability-resolution',
					'Solid Security',
					'Solid Security',
					[
						'data'  => $response->get_data(),
						'links' => $response->get_links(),
					]
				);
			}
		} else {
			ithemes_sync_send_urgent_notice(
				'solid-security',
				'vulnerability-resolution-failed',
				'Solid Security',
				'Solid Security',
				[
					'vuln_id' => $vulnerability->get_id(),
					'message' => 'Unable to set current user to admin.',
				]
			);
		}

		if ( $current_user ) {
			wp_set_current_user( $current_user->id );
		} else {
			wp_set_current_user( 0 );
		}
	}

	public static function set_global_user_for_api_request() {
		if ( ! class_exists( 'WP_Roles' ) ) {
			do_action( 'ithemes-sync-add-log', 'The WP_Roles class does not exist. Unable to set current user to admin.' );
			return false;
		}

		$wp_roles = new WP_Roles();

		if ( ! isset( $wp_roles->roles ) ) {
			do_action( 'ithemes-sync-add-log', 'Unable to find user roles. Unable to set current user to admin.', compact( 'wp_roles' ) );
			return false;
		}

		$roles      = $wp_roles->roles;
		$max_caps   = 0;
		$power_role = false;

		foreach ( $roles as $role => $role_data ) {
			if ( ! isset( $role_data['capabilities'] ) ) {
				continue;
			}

			$cap_count = count( $role_data['capabilities'] );
			$new_role  = false;

			if ( $cap_count > $max_caps ) {
				$power_role = $role;
				$max_caps   = $cap_count;
			} elseif ( ( $cap_count == $max_caps ) && ( 'administrator' == $role ) ) {
				$power_role = $role;
				$max_caps   = $cap_count;
			}
		}

		if ( false === $power_role ) {
			if ( isset( $roles['administrator'] ) ) {
				$power_role = 'administrator';
			} else {
				$role_names = array_keys( $roles );
				$power_role = $roles[0];
			}
		}

		if ( false === $power_role ) {
			do_action( 'ithemes-sync-add-log', 'Unable to find a power user role. Unable to set current user to admin.', compact( 'wp_roles' ) );
			return false;
		}


		if ( ! function_exists( 'get_users' ) ) {
			do_action( 'ithemes-sync-add-log', 'get_users() function does not exist. Unable to set current user to admin.' );
			return false;
		}

		$users = get_users( [ 'role' => $power_role ] );

		if ( ! is_array( $users ) ) {
			do_action( 'ithemes-sync-add-log', 'get_users() retured a non-array. Unable to set current user to admin.', $users );
			return false;
		}

		$options = $GLOBALS['ithemes-sync-settings']->get_options();
		foreach ( $options['authentications'] as $user_id => $auth_user ) {
			$auth_details = $GLOBALS['ithemes-sync-settings']->get_authentication_details( $user_id );
			foreach ( $users as $u ) {
				if ( $u->data->user_login === $auth_details['local_user'] ) {
					// Prioritize the Sync user first, if it doesn't match for some reason, we'll fall back to any administrator user
					$user = $u;
					break;

				} else {
					$user = $u;
				}
			}
		}

		if ( isset( $user->ID ) ) {
			$GLOBALS['current_user'] = $user;
		} else {
			do_action( 'ithemes-sync-add-log', 'Unable to find a valid user object for the power user role. Unable to set current user to admin.', $user );
			return false;
		}

		return true;
	}

    /**
     * Generate a WP App Password.
     *
     * @param WP_User $user A WP_User object.
     * @param string  $name The app name.
     *
     * @return array|\WP_Error
     */
    public static function generate_app_password( $user ) {

        // Create the app password.
        $app_password = WP_Application_Passwords::create_new_application_password(
            $user->ID,
            array(
                'name'   => 'SolidWP ' . date_i18n( 'M j, Y g:i:s A' ),
                'app_id' => SOLID_CENTRAL_APP_ID,
            )
        );

        if ( is_wp_error( $app_password ) ) {
            return $app_password;
        }

        return array(
            'user_login' => $user->user_login,
            'password'   => $app_password[0],
            'details'    => $app_password[1],
            'rest_api'   => rest_url(),
        );
    }
}
