<?php

class Ithemes_Sync_Verb_Check_Nonce extends Ithemes_Sync_Verb {
	public static $name                      = 'check-nonce';
	public static $description               = 'Check if supplied nonce matches existing one.';
	public static $status_element_name       = 'nonce';
	public static $show_in_status_by_default = false;

	public function run( $arguments ) {

		require_once $GLOBALS['ithemes_sync_path'] . '/functions.php';

		if ( ! empty( $arguments['nonce'] ) && ! empty( $arguments['nonce-name'] ) ) {

			if ( Ithemes_Sync_Functions::validate_sync_nonce( $arguments['nonce-name'], $arguments['nonce'] ) ) {

				return [ 'matches' => true ];

			}       
		}

		return [ 'matches' => false ];
	}
}
