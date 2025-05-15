<?php
/**
 * @see https://github.com/ithemes/solid-backups/pull/24
 */

_deprecated_file(
	basename( __FILE__ ), // phpcs:ignore StellarWP.XSS.EscapeOutput.OutputNotEscaped
	'3.2.4',
	'',
	esc_html__( 'Requiring `server.php` is not needed anymore. The new `Central_Server_Client` class is autoloaded.', 'it-l10n-ithemes-sync' )
);
