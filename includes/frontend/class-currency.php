<?php
/**
 * Toman transaction mode (mode B).
 *
 * @package USDTF
 */

namespace USDTF\Frontend;

use USDTF\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Makes WooCommerce transact in Toman without touching the database options.
 *
 * The store currency, its position and its decimal setting are filtered at
 * runtime, so switching the plugin off fully restores the original store
 * configuration. Because the price fields hold the canonical Toman price in
 * this mode, carts, orders, gateways, taxes and shipping all work with the
 * same number the customer sees: no display-only fake currency.
 */
final class Currency {

	/**
	 * Currency code used for Iranian Toman.
	 */
	const CODE = 'IRT';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the currency filters.
	 *
	 * Every filter is registered unconditionally and returns the untouched value
	 * while the store transacts in USD, so a mode switch takes effect in the same
	 * request (and disabling the plugin always restores the store settings).
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'woocommerce_currencies', array( $this, 'register_currency' ) );
		add_filter( 'woocommerce_currency_symbols', array( $this, 'filter_symbols' ) );

		add_filter( 'woocommerce_currency', array( $this, 'filter_currency' ) );
		add_filter( 'option_woocommerce_currency', array( $this, 'filter_currency_option' ) );
		add_filter( 'pre_option_woocommerce_currency', array( $this, 'filter_currency_option' ) );
		add_filter( 'option_woocommerce_currency_pos', array( $this, 'filter_position_option' ) );
		add_filter( 'pre_option_woocommerce_currency_pos', array( $this, 'filter_position_option' ) );
		add_filter( 'option_woocommerce_price_num_decimals', array( $this, 'filter_decimals_option' ) );
		add_filter( 'pre_option_woocommerce_price_num_decimals', array( $this, 'filter_decimals_option' ) );

		add_filter( 'woocommerce_currency_symbol', array( $this, 'filter_symbol' ), 10, 2 );
		add_filter( 'wc_get_price_decimals', array( $this, 'filter_price_decimals' ) );
	}

	/**
	 * Force the Toman currency code while Toman mode is active.
	 *
	 * @param string $currency Current currency code.
	 * @return string
	 */
	public function filter_currency( $currency ) {
		return $this->settings->is_toman_mode() ? self::CODE : $currency;
	}

	/**
	 * Force the Toman currency option while Toman mode is active.
	 *
	 * @param mixed $value Stored option value.
	 * @return mixed
	 */
	public function filter_currency_option( $value ) {
		return $this->settings->is_toman_mode() ? self::CODE : $value;
	}

	/**
	 * Toman is a whole unit currency, so the price position is "right with a space".
	 *
	 * @param mixed $value Stored option value.
	 * @return mixed
	 */
	public function filter_position_option( $value ) {
		return $this->settings->is_toman_mode() ? 'right_space' : $value;
	}

	/**
	 * Toman prices do not use decimals.
	 *
	 * @param mixed $value Stored option value.
	 * @return mixed
	 */
	public function filter_decimals_option( $value ) {
		return $this->settings->is_toman_mode() ? '0' : $value;
	}

	/**
	 * Price decimals used by wc_get_price_decimals().
	 *
	 * @param int $decimals Configured decimals.
	 * @return int
	 */
	public function filter_price_decimals( $decimals ) {
		return $this->settings->is_toman_mode() ? 0 : $decimals;
	}

	/**
	 * Register Toman in the currency list.
	 *
	 * @param array $currencies Currencies.
	 * @return array
	 */
	public function register_currency( $currencies ) {
		$currencies[ self::CODE ] = __( 'Iranian Toman', 'usd-to-toman-price-sync-for-woocommerce' );

		return $currencies;
	}

	/**
	 * Register the Toman symbol.
	 *
	 * @param array $symbols Symbols.
	 * @return array
	 */
	public function filter_symbols( $symbols ) {
		$symbols[ self::CODE ] = (string) $this->settings->get( 'toman_suffix' );

		return $symbols;
	}

	/**
	 * Symbol for the active currency.
	 *
	 * @param string $symbol   Current symbol.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public function filter_symbol( $symbol, $currency ) {
		if ( ! $this->settings->is_toman_mode() ) {
			return $symbol;
		}

		if ( '' === $currency || self::CODE === $currency ) {
			return (string) $this->settings->get( 'toman_suffix' );
		}

		return $symbol;
	}
}
