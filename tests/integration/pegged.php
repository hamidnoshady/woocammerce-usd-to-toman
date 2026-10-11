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

// ---------------------------------------------------------------------------
// Price planner: the pure per-field decision.
// ---------------------------------------------------------------------------
$plan_round_0 = array(
	'rounding'  => 'up',
	'increment' => 1000,
	'decimals'  => 0,
);
$plan_round_2 = array(
	'rounding'  => 'up',
	'increment' => 0.01,
	'decimals'  => 2,
);

// Each case: label, current, seen, usd, stored rate, rate, rounding, action, code, usd out, price out.
$plan_cases = array(
	array( 'generate the anchor from a plain price', '5000000', '', '', 0.0, 270000.0, $plan_round_0, 'generate', '', '18.52', '5000000' ),
	array( 'unchanged when neither price nor rate moved', '5000000', '5000000', '18.52', 270000.0, 270000.0, $plan_round_0, 'unchanged', '', '18.52', '5000000' ),
	array( 'update the price when the rate moved', '5000000', '5000000', '18.52', 270000.0, 300000.0, $plan_round_0, 'update', '', '18.52', '5556000' ),
	array( 'rebase when the price was edited by hand', '6000000', '5556000', '18.52', 300000.0, 300000.0, $plan_round_0, 'rebase', '', '20.00', '6000000' ),
	array( 'clear the anchor when the price was removed', '', '1000', '3.70', 270000.0, 270000.0, $plan_round_0, 'clear', '', '', '' ),
	array( 'skip a field with no price and no anchor', '', '', '', 0.0, 270000.0, $plan_round_0, 'skip', 'no_price', null, null ),
	array( 'skip a non numeric price', 'abc', '', '', 0.0, 270000.0, $plan_round_0, 'skip', 'invalid_price', null, null ),
	array( 'skip a zero price', '0', '', '', 0.0, 270000.0, $plan_round_0, 'skip', 'invalid_price', null, null ),
	array( 'skip a negative price', '-5', '', '', 0.0, 270000.0, $plan_round_0, 'skip', 'invalid_price', null, null ),
	array( 'skip a price below one cent of USD', '1', '', '', 0.0, 270000.0, $plan_round_0, 'skip', 'too_low', null, null ),
	array( 'skip a hand edit below one cent of USD', '1', '5556000', '18.52', 270000.0, 270000.0, $plan_round_0, 'skip', 'too_low', null, null ),
	array( 'skip when there is no rate', '5000000', '', '', 0.0, 0.0, $plan_round_0, 'skip', 'no_rate', null, null ),
	array( 'unchanged with two Woo decimals', '12.50', '12.50', '0.50', 25.0, 25.0, $plan_round_2, 'unchanged', '', '0.50', '12.50' ),
	array( 'equal prices compare at the Woo decimals', '12.5', '12.50', '0.50', 25.0, 25.0, $plan_round_2, 'unchanged', '', '0.50', '12.50' ),
	array( 'rates compare at six decimals', '5000000', '5000000', '18.52', 270000.0, 270000.0000001, $plan_round_0, 'unchanged', '', '18.52', '5000000' ),
	array( 'a rate change beyond six decimals updates', '5000000', '5000000', '18.52', 270000.0, 270000.00001, $plan_round_0, 'update', '', '18.52', '5001000' ),
);

foreach ( $plan_cases as $plan_case ) {
	list( $label, $p_current, $p_seen, $p_usd, $p_stored, $p_rate, $p_rounding, $p_action, $p_code, $p_usd_out, $p_price_out ) = $plan_case;

	$plan = \USDTF\Price_Planner::plan( $p_current, $p_seen, $p_usd, $p_stored, $p_rate, $p_rounding );

	usdtf_it_assert_same( $p_action, $plan['action'], 'planner action: ' . $label );
	usdtf_it_assert_same( $p_code, $plan['code'], 'planner code: ' . $label );

	if ( 'skip' === $p_action ) {
		continue;
	}

	usdtf_it_assert_same( $p_usd_out, $plan['usd'], 'planner USD: ' . $label );
	usdtf_it_assert_same( $p_price_out, $plan['price'], 'planner price: ' . $label );
	usdtf_it_assert_same( $plan['price'], $plan['seen'], 'planner seen equals price: ' . $label );
}

usdtf_it_assert_same( 'generate', \USDTF\Price_Planner::ACTION_GENERATE, 'the generate action constant' );
usdtf_it_assert_same( 'rebase', \USDTF\Price_Planner::ACTION_REBASE, 'the rebase action constant' );
usdtf_it_assert_same( 'update', \USDTF\Price_Planner::ACTION_UPDATE, 'the update action constant' );
usdtf_it_assert_same( 'clear', \USDTF\Price_Planner::ACTION_CLEAR, 'the clear action constant' );
usdtf_it_assert_same( 'unchanged', \USDTF\Price_Planner::ACTION_UNCHANGED, 'the unchanged action constant' );
usdtf_it_assert_same( 'skip', \USDTF\Price_Planner::ACTION_SKIP, 'the skip action constant' );
usdtf_it_pass( 'the price planner decides every case' );

// Leave nothing behind for the legacy scenarios that follow in a full run.
usdtf_it_set_currency( null );
usdtf_it_delete_products();
