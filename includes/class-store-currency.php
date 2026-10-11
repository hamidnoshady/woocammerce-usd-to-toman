<?php
/**
 * Store currency.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the pricing unit (Toman or Rial) from the WooCommerce store currency.
 *
 * The plugin never changes the currency: a store priced in IRT works in Toman,
 * a store priced in IRR works in Rial, and every other currency is unsupported.
 * Nothing is cached, so the unit follows the currency the moment it changes.
 */
final class Store_Currency {

	/**
	 * Unit of a store priced in IRT.
	 */
	const UNIT_TOMAN = 'toman';

	/**
	 * Unit of a store priced in IRR.
	 */
	const UNIT_RIAL = 'rial';

	/**
	 * Currency code of the store.
	 *
	 * Without WooCommerce loaded (WordPress 6.2 to 6.4 do not enforce the
	 * "Requires Plugins" header, so the plugin can be activated alone) the
	 * stored WooCommerce option is read instead, so activation and the
	 * settings defaults still work and follow the store's own currency.
	 *
	 * @return string
	 */
	public static function code() {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return (string) get_option( 'woocommerce_currency', '' );
		}

		return (string) get_woocommerce_currency();
	}

	/**
	 * Pricing unit of the store.
	 *
	 * @return string `toman`, `rial`, or an empty string for any other currency.
	 */
	public static function unit() {
		switch ( self::code() ) {
			case 'IRT':
				return self::UNIT_TOMAN;

			case 'IRR':
				return self::UNIT_RIAL;

			default:
				return '';
		}
	}

	/**
	 * Whether the store currency is one the plugin can price in.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return '' !== self::unit();
	}

	/**
	 * Translated name of the pricing unit.
	 *
	 * @return string `Toman`, `Rial`, or an empty string for any other currency.
	 */
	public static function unit_label() {
		return self::label_for( self::unit() );
	}

	/**
	 * Translated name of a pricing unit.
	 *
	 * @param string $unit `toman` or `rial`.
	 * @return string `Toman`, `Rial`, or an empty string for anything else.
	 */
	public static function label_for( $unit ) {
		switch ( (string) $unit ) {
			case self::UNIT_TOMAN:
				return __( 'Toman', 'usd-to-toman-price-sync-for-woocommerce' );

			case self::UNIT_RIAL:
				return __( 'Rial', 'usd-to-toman-price-sync-for-woocommerce' );

			default:
				return '';
		}
	}
}
