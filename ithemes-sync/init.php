<?php
/*
 * Plugin Name: Solid Central
 * Plugin URI: https://solidwp.com/central
 * Description: Maximize and amplify your admin with remote, multi-site management. One centralized dashboard to save time.
 * Author: SolidWP
 * Version: 3.2.1
 * Requires at least: 6.4
 * Requires PHP: 7.0
 * Author URI: https://solidwp.com/
 * Domain Path: /lang/
 * iThemes Package: ithemes-sync
 */

if ( ! empty( $GLOBALS['ithemes_sync_path'] ) ) {
	/** @var string $active_plugin_path */
	$active_plugin_path = preg_replace( '|^' . preg_quote( ABSPATH, '|' ) . '|', '', $GLOBALS['ithemes_sync_path'] );
	/** @var string $this_plugin_path */
	$this_plugin_path = preg_replace( '|^' . preg_quote( ABSPATH, '|' ) . '|', '', __DIR__ );

	if ( $active_plugin_path != $this_plugin_path ) {

		add_action(
			'all_admin_notices',
			function () use ( $active_plugin_path, $this_plugin_path ) {
				echo '<div class="error"><p>';
				printf(
					wp_kses(
						/* translators: 1: Active plugin path, 2: This plugin path */
						__(
							'Only one Solid Central plugin can be active at a time. The plugin at <code>%1$s</code> is running while the plugin at <code>%2$s</code> was skipped in order to prevent errors. Please deactivate the plugin that you do not wish to use.',
							'it-l10n-ithemes-sync'
						),
						[
							'code' => [],
						]
					),
					esc_html( $active_plugin_path ),
					esc_html( $this_plugin_path )
				);
				echo '</p></div>';
			},
			0
		);
	}

	return;
}

$GLOBALS['ithemes_sync_path'] = __DIR__;

require $GLOBALS['ithemes_sync_path'] . '/load.php';

/**
 * On activation, set a time, frequency and name of an action hook to be scheduled.
 *
 * @since 1.12.0
 */
function ithemes_sync_activation() {
	if ( ! wp_next_scheduled( 'ithemes_sync_daily_schedule' ) ) {
		wp_schedule_event( strtotime( 'Tomorrow 2AM' ), 'daily', 'ithemes_sync_daily_schedule' );
	}
}

register_activation_hook( __FILE__, 'ithemes_sync_activation' );

/**
 * On deactivation, remove all functions from the scheduled action hook.
 *
 * @since 1.12.0
 */
function ithemes_sync_deactivation() {
	wp_clear_scheduled_hook( 'ithemes_sync_daily_schedule' );
}

register_deactivation_hook( __FILE__, 'ithemes_sync_deactivation' );
