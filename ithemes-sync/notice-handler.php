<?php

_deprecated_file(
	basename( __FILE__ ), // phpcs:ignore StellarWP.XSS.EscapeOutput.OutputNotEscaped
	'3.2.4',
	'',
	esc_html__( 'Requiring `notice-handler.php` is not needed anymore. The new `Central_Server_Notifier` class is autoloaded.', 'it-l10n-ithemes-sync' )
);

class Ithemes_Sync_Notice_Handler {
	public function __construct() {
		$GLOBALS['ithemes_sync_notice_handler'] = $this;
	}

	public function add_notice() {
		return false;
	}

	public function get_notices() {
		return [];
	}

	public function send_urgent_notice() {
		return false;
	}

	public function get_urgent_notices() {
		return [];
	}

	public function set_urgent_notices() {}

	public function clear_urgent_notices() {}
}

new Ithemes_Sync_Notice_Handler();
