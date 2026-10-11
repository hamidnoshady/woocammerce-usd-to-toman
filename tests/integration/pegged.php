<?php
/**
 * Integration scenarios for the USD-pegged pricing model.
 *
 * Included by run.php, which has booted WordPress, loaded lib.php and defined
 * the shared helpers. Run alone with USDTF_IT_ONLY=pegged.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

// ---------------------------------------------------------------------------
// The harness: a Rial/Toman store and a product that carries only a Woo price.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();

usdtf_it_set_currency( 'IRT' );
$harness = usdtf_it_make_priced_product( 'Harness', 5000000 );

usdtf_it_assert_same( '5000000', usdtf_it_price( $harness->get_id() ), 'the fixture must hold the Woo price' );
usdtf_it_assert_same( '', usdtf_it_meta( $harness->get_id(), '_usdtf_usd_regular' ), 'the fixture must carry no plugin meta' );
usdtf_it_pass( 'the new-model harness runs' );

// ---------------------------------------------------------------------------
// Calculator: USD anchor and store-price maths.
// ---------------------------------------------------------------------------
usdtf_it_assert_same( 18.52, \USDTF\Calculator::usd_from_price( 5000000, 270000 ), 'a store price converts to a USD anchor rounded to 2 decimals' );
usdtf_it_assert_same( 162.56, \USDTF\Calculator::usd_from_price( 43890000, 270000 ), 'the USD anchor rounds half up to 2 decimals' );
usdtf_it_assert_same( null, \USDTF\Calculator::usd_from_price( 0, 270000 ), 'a zero price has no USD anchor' );
usdtf_it_assert_same( null, \USDTF\Calculator::usd_from_price( 100, 0 ), 'a zero rate has no USD anchor' );
usdtf_it_assert_same( null, \USDTF\Calculator::usd_from_price( -1, 1 ), 'a negative price has no USD anchor' );

$round_args = array(
	'rounding'  => 'up',
	'increment' => 1000,
	'decimals'  => 0,
);
usdtf_it_assert_same( 5556000.0, \USDTF\Calculator::price_from_usd( 18.52, 300000, $round_args ), 'an exact USD to price conversion stays on the increment' );
usdtf_it_assert_same( 5501000.0, \USDTF\Calculator::price_from_usd( 18.52, 297000, $round_args ), 'rounding up lifts the price to the next increment' );
$round_args['rounding'] = 'nearest';
usdtf_it_assert_same( 5500000.0, \USDTF\Calculator::price_from_usd( 18.52, 297000, $round_args ), 'rounding to the nearest increment' );
$round_args['rounding'] = 'down';
usdtf_it_assert_same( 5500000.0, \USDTF\Calculator::price_from_usd( 18.52, 297000, $round_args ), 'rounding down drops to the previous increment' );
usdtf_it_assert_same( null, \USDTF\Calculator::price_from_usd( 18.52, 0 ), 'a zero rate has no store price' );
usdtf_it_assert_same( null, \USDTF\Calculator::price_from_usd( 0, 300000 ), 'a zero USD anchor has no store price' );

usdtf_it_assert_same( array( 1.0, 100.0, 1000.0, 10000.0 ), \USDTF\Calculator::increments_for_unit( 'toman' ), 'Toman rounding increments' );
usdtf_it_assert_same( array( 10.0, 1000.0, 10000.0, 100000.0 ), \USDTF\Calculator::increments_for_unit( 'rial' ), 'Rial rounding increments' );
usdtf_it_assert_same( 1000.0, \USDTF\Calculator::default_increment( 'toman' ), 'the Toman default increment' );
usdtf_it_assert_same( 10000.0, \USDTF\Calculator::default_increment( 'rial' ), 'the Rial default increment' );
usdtf_it_assert_same( '5,556,000', \USDTF\Calculator::format_price( 5556000 ), 'prices format with locale thousands separators' );
usdtf_it_assert_same( '18.52', \USDTF\Calculator::format_price( 18.52, 2 ), 'prices format with the requested decimals' );
usdtf_it_pass( 'the calculator maths for the pegged model' );

// ---------------------------------------------------------------------------
// Store currency: the unit comes from WooCommerce, nothing overrides it.
// ---------------------------------------------------------------------------
usdtf_it_set_currency( null );
$stored_currency = get_option( 'woocommerce_currency' );

usdtf_it_set_currency( 'IRT' );
usdtf_it_assert_same( 'IRT', \USDTF\Store_Currency::code(), 'the store currency code is read from WooCommerce' );
usdtf_it_assert_same( 'toman', \USDTF\Store_Currency::unit(), 'IRT is the Toman unit' );
usdtf_it_assert_same( true, \USDTF\Store_Currency::is_supported(), 'IRT is supported' );
usdtf_it_assert_same( 'Toman', \USDTF\Store_Currency::unit_label(), 'the Toman unit label' );

usdtf_it_set_currency( 'IRR' );
usdtf_it_assert_same( 'rial', \USDTF\Store_Currency::unit(), 'IRR is the Rial unit' );
usdtf_it_assert_same( true, \USDTF\Store_Currency::is_supported(), 'IRR is supported' );
usdtf_it_assert_same( 'Rial', \USDTF\Store_Currency::unit_label(), 'the Rial unit label' );

usdtf_it_set_currency( 'USD' );
usdtf_it_assert_same( 'USD', get_woocommerce_currency(), 'the plugin no longer overrides the store currency' );
usdtf_it_assert_same( '', \USDTF\Store_Currency::unit(), 'USD has no unit' );
usdtf_it_assert_same( false, \USDTF\Store_Currency::is_supported(), 'USD is not supported' );
usdtf_it_assert_same( '', \USDTF\Store_Currency::unit_label(), 'USD has no unit label' );

usdtf_it_set_currency( null );
usdtf_it_assert_same( $stored_currency, \USDTF\Store_Currency::code(), 'removing the filter restores the stored currency' );

usdtf_it_assert_same( false, class_exists( '\\USDTF\\Frontend\\Currency' ), 'the currency override class is gone' );
usdtf_it_assert_same( false, class_exists( '\\USDTF\\Frontend\\Display' ), 'the Toman display class is gone' );

usdtf_it_set_currency( 'IRT' );
$managed = usdtf_it_make_priced_product( 'Plain price', 5000000, '', 'managed' );
usdtf_it_assert_same( wc_price( 5000000 ), $managed->get_price_html(), 'a managed product renders with the plain WooCommerce price HTML' );
usdtf_it_pass( 'the store currency is read from WooCommerce without an override' );

// Leave nothing behind for the legacy scenarios that follow in a full run.
usdtf_it_set_currency( null );
usdtf_it_delete_products();
