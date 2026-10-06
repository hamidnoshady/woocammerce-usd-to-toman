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
	 * Capabilities an administrator may pick in the settings.
	 *
	 * An arbitrary capability could be weakened to something like "read" and
	 * would then expose every mutation endpoint to low privileged users, so
	 * the setting itself only accepts this allowlist. Weaker setups remain
	 * possible through the developer filter in required().
	 *
	 * @return string[]
	 */
	public static function allowed() {
		return array(
			self::DEFAULT_CAPABILITY,
			'manage_options',
		);
	}

	/**
	 * Capability used for rate changes and synchronization jobs.
	 *
	 * @return string
	 */
	public static function required() {
		$capability = (string) usdtf_plugin()->settings()->get( 'required_capability' );

		if ( ! in_array( $capability, self::allowed(), true ) ) {
			$capability = self::DEFAULT_CAPABILITY;
		}

		/**
		 * Filters the capability required to manage USD/Toman pricing.
		 *
		 * The filter is the developer escape hatch: the setting itself only
		 * accepts the allowlist from allowed().
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
