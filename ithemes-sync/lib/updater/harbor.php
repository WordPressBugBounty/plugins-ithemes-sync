<?php

/**
 * Helper class for LiquidWeb Harbor integration.
 *
 * Wraps all Harbor global functions with function_exists() guards.
 * Returns safe defaults when Harbor is absent.
 *
 * The updater has no direct dependency on Harbor — it only uses
 * Harbor's non-namespaced global functions guarded by function_exists().
 */
class Ithemes_Updater_Harbor {

	/**
	 * Per-request cache for is_product_managed() results.
	 *
	 * @var array<string, bool>
	 */
	private static $managed_cache = array();

	/**
	 * Whether Harbor global functions exist.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'lw_harbor_is_feature_available' )
			&& function_exists( 'lw_harbor_get_unified_license_key' );
	}

	/**
	 * Local-only check for whether a unified key exists.
	 *
	 * Uses Harbor's dedicated function which checks local storage and
	 * registered products for an embedded key without making remote calls.
	 *
	 * @return bool
	 */
	public static function has_unified_key() {
		if ( ! function_exists( 'lw_harbor_has_unified_license_key' ) ) {
			return false;
		}

		return lw_harbor_has_unified_license_key();
	}

	/**
	 * Get the unified key string.
	 *
	 * @return string|null The unified key, or null if unavailable.
	 */
	public static function get_unified_key() {
		if ( ! function_exists( 'lw_harbor_get_unified_license_key' ) ) {
			return null;
		}

		$key = lw_harbor_get_unified_license_key();

		return ! empty( $key ) ? $key : null;
	}

	/**
	 * The gating check. Determines if Harbor manages this product's license.
	 *
	 * Per-request cached to avoid repeated function calls.
	 *
	 * @param string $slug The package slug.
	 *
	 * @return bool
	 */
	public static function is_product_managed( $slug ) {
		if ( ! self::is_available() ) {
			return false;
		}

		if ( isset( self::$managed_cache[ $slug ] ) ) {
			return self::$managed_cache[ $slug ];
		}

		self::$managed_cache[ $slug ] = lw_harbor_is_feature_available( $slug );

		return self::$managed_cache[ $slug ];
	}

	/**
	 * Get the licensed domain.
	 *
	 * @return string
	 */
	public static function get_domain() {
		return function_exists( 'lw_harbor_get_licensed_domain' )
			? lw_harbor_get_licensed_domain()
			: '';
	}


	/**
	 * Build legacy license array for Harbor's filter.
	 *
	 * Returns Legacy_License-compatible arrays for products NOT managed by
	 * Harbor (to avoid circular reporting). Each entry has the shape expected
	 * by Harbor's Legacy\License_Repository::all().
	 *
	 * Uses the full (transient-cached) API response to get per-product
	 * status and expiration data. Only called in wp-admin.
	 *
	 * @return array<int, array<string, mixed>> Array of legacy license data arrays.
	 */
	public static function get_legacy_licenses() {
		if ( ! class_exists( 'Ithemes_Updater_Functions' ) ) {
			require_once $GLOBALS['ithemes_updater_path'] . '/functions.php';
		}
		if ( ! class_exists( 'Ithemes_Updater_Packages' ) ) {
			require_once $GLOBALS['ithemes_updater_path'] . '/packages.php';
		}

		$details  = Ithemes_Updater_Packages::get_full_details();
		$packages = isset( $details['packages'] ) ? $details['packages'] : array();

		if ( empty( $packages ) ) {
			return array();
		}

		$legacy = array();

		foreach ( $packages as $path => $data ) {
			$slug = isset( $data['package'] ) && is_string( $data['package'] ) ? $data['package'] : '';

			if ( $slug === '' ) {
				continue;
			}

			if ( self::is_product_managed( $slug ) ) {
				continue;
			}

			$status = isset( $data['status'] ) && in_array( $data['status'], ['unlicensed', 'active', 'expired' ], true ) ? $data['status'] : '';

			if ( $status === '' ) {
				continue;
			}

			$key       = isset( $data['key'] ) && is_string( $data['key'] ) ? $data['key'] : '';
			$is_active = ( 'active' === $status );

			$entry = array(
				'key'       => $key,
				'slug'      => $slug,
				'name'      => Ithemes_Updater_Functions::get_package_name( $slug ),
				'product'   => 'Kadence (legacy SolidWP)',
				'is_active' => $is_active,
				'page_url'  => admin_url( 'options-general.php?page=ithemes-licensing' ),
			);

			if ( ! empty( $data['expiration'] ) ) {
				$entry['expires_at'] = date( 'Y-m-d', (int) $data['expiration'] );
			}

			$legacy[] = $entry;
		}

		return $legacy;
	}

	/**
	 * Get the URL for Harbor's license management page.
	 *
	 * @return string The admin URL, or empty string if unavailable.
	 */
	public static function get_license_page_url() {
		if ( ! function_exists( 'lw_harbor_get_license_page_url' ) ) {
			return '';
		}

		return lw_harbor_get_license_page_url();
	}

	/**
	 * Reset per-request is_product_managed cache.
	 */
	public static function clear_cache() {
		self::$managed_cache = array();
	}
}
