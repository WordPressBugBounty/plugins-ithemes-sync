<?php
/**
 * Implementation of the create-application-password verb.
 *
 * This verb is used to create an application password for the user.
 *
 * @package Ithemes_Sync
 */

/**
 * Class Ithemes_Sync_Verb_Create_Application_Password
 */
class Ithemes_Sync_Verb_Create_Application_Password extends Ithemes_Sync_Verb {

	// The Verb name.
	public static $name                      = 'create-application-password';
	public static $description               = 'Create an Application Password.';
	public static $status_element_name       = 'application-password';
	public static $show_in_status_by_default = false;

	/**
	 * Run the verb.
	 *
	 * Create an application password for the user.
	 *
	 * @see WP_Application_Passwords::create_new_application_password()
	 *
	 * @param array $arguments The arguments for the verb.
	 *
	 * @return array Array with the user_login, unhashed password, and app password details. False on failure.
	 */
	public function run( $arguments = [] ) {

		if ( ! is_user_logged_in() ) {
			return [
				'errors' => [
					'no-user' => __( 'User is not logged in.', 'it-l10n-ithemes-sync' ),
				],
			];
		}

		$user = wp_get_current_user();

		if ( $user->ID === 0 ) {
			return [
				'errors' => [
					'no-user' => __( 'User is not logged in.', 'it-l10n-ithemes-sync' ),
				],
			];
		}

        $result = Ithemes_Sync_Functions::generate_app_password( $user );
        if ( is_wp_error( $result ) ) {
            return [
                'errors' => [
                    $result->get_error_code() => $result->get_error_message(),
                ],
            ];
        }
        return $result;
	}
}
