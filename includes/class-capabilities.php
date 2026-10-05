<?php
/**
 * Capability helpers.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for the capabilities this plugin requires.
 */
final class Capabilities {

	/**
	 * Capability required for every admin action of this plugin.
	 */
	const DEFAULT_CAPABILITY = 'manage_woocommerce';

	/**
	 * Capability used for rate changes and synchronization jobs.
	 *
	 * @return string
	 */
	public static function required() {
		$capability = (string) usdtf_plugin()->settings()->get( 'required_capability' );

		if ( '' === $capability ) {
			$capability = self::DEFAULT_CAPABILITY;
		}

		/**
		 * Filters the capability required to manage USD/Toman pricing.
		 *
		 * @param string $capability Capability name.
		 */
		$capability = (string) apply_filters( 'usdtf_required_capability', $capability );

		return $capability ? $capability : self::DEFAULT_CAPABILITY;
	}

	/**
	 * Whether the current user may manage pricing.
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		return current_user_can( self::required() );
	}

	/**
	 * Whether the given user may manage pricing.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can( $user_id ) {
		return user_can( (int) $user_id, self::required() );
	}
}
