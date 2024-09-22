<?php

/*
Implementation of the manage-site verb to add / remove site from sync.
Written by Glenn Ansley for iThemes.com
Version 1.0.0

Version History
	1.0.0 - 2017-09-15 - Glenn Ansley
		Initial version
*/

require_once $GLOBALS['ithemes_sync_path'] . '/server.php';

class Ithemes_Sync_Verb_Manage_Site extends Ithemes_Sync_Verb {
	public static $name        = 'manage-site';
	public static $description = 'Allows the site to be connected or disconnected from the Central dashboard';

	private $default_arguments = [
		'action'      => false,
		'site_id'     => false,
		'it_username' => false,
		'site_key'    => false,
	];

	public function run( $arguments ) {
		$arguments = Ithemes_Sync_Functions::merge_defaults( $arguments, $this->default_arguments );

		$validate = Ithemes_Sync_Server::validate( $arguments['site_id'], $arguments['it_username'], $arguments['site_key'] );

		if ( is_wp_error( $validate ) ) {
			return new WP_Error( 'invalid-credentials', $validate->get_error_message() );
		}

		if ( empty( $arguments['action'] ) || ! in_array( $arguments['action'], [ 'sync', 'unsync' ] ) ) {
			return new WP_Error( 'missing-action', 'The action argument is missing or invalid. The action value should be sent in the action argument can contain the value `sync` or `unsync`.' );
		}

		if ( 'sync' == $arguments['action'] ) {
			if ( empty( $arguments['site_id'] ) || empty( $arguments['it_username'] ) || empty( $arguments['site_key'] ) ) {
				return new WP_Error( 'missing-data', 'Missing arguments. Please send `site_id`, `it_username`, and `site_key` as array keys in the argument when action is set to `sync`.' );
			}

			require_once $GLOBALS['ithemes_sync_path'] . '/settings.php';

			if ( is_callable( [ $GLOBALS['ithemes-sync-settings'], 'add_authentication' ] ) ) {
				$result = $GLOBALS['ithemes-sync-settings']->add_authentication( $arguments['site_id'], $arguments['it_username'], $arguments['site_key'], $arguments['wp_user_login'] );

				if ( is_wp_error( $result ) ) {
					return $result;
				} else {
					return [
						'success'    => 1,
						'wp_version' => Ithemes_Sync_Functions::get_wordpress_version(),
					];
				}
			} else {
				return new WP_Error( 'method-not-callable', 'Function not found.' ); // This shouldn't happen. Was added during development for tracing purposes.
			}
		}

		return [ 'success' => 0 ];
	}
}
