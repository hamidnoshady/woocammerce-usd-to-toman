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

// ---------------------------------------------------------------------------
// Settings: unit-aware rounding increments, no obsolete keys.
// ---------------------------------------------------------------------------
$removed_settings_keys = array( 'currency_mode', 'display_toman', 'display_toman_cart', 'persian_digits', 'toman_suffix', 'decimals', 'auto_manage_new_products' );

usdtf_it_reset_plugin_state();
usdtf_it_set_currency( 'IRT' );

usdtf_it_assert_same( array( 1.0, 100.0, 1000.0, 10000.0 ), \USDTF\Settings::allowed_increments(), 'the allowed increments default to the store unit' );
usdtf_it_assert_same( array( 10.0, 1000.0, 10000.0, 100000.0 ), \USDTF\Settings::allowed_increments( 'rial' ), 'the allowed increments can be asked for another unit' );
usdtf_it_assert_same( 1000.0, \USDTF\Settings::sanitize( array( 'increment' => 5 ) )['increment'], 'an increment that is not allowed for Toman falls back to 1000' );
usdtf_it_assert_same( 100.0, \USDTF\Settings::sanitize( array( 'increment' => 100 ) )['increment'], 'a Toman increment of 100 is kept' );
usdtf_it_assert_same( 'up', \USDTF\Settings::defaults()['rounding'], 'rounding defaults to up' );
usdtf_it_assert_same( 1000.0, \USDTF\Settings::defaults()['increment'], 'the Toman default increment is 1000' );

$clean_settings = \USDTF\Settings::sanitize( array( 'increment' => 5 ) );

foreach ( $removed_settings_keys as $removed_key ) {
	usdtf_it_assert( ! array_key_exists( $removed_key, $clean_settings ), 'sanitize() must not return the removed key ' . $removed_key );
	usdtf_it_assert( ! array_key_exists( $removed_key, \USDTF\Settings::defaults() ), 'the defaults must not hold the removed key ' . $removed_key );
}

usdtf_it_assert_same( 15, \USDTF\Settings::sanitize( array( 'batch_size' => 15 ) )['batch_size'], 'a partial update keeps the submitted value' );
usdtf_it_assert_same( 10, \USDTF\Settings::sanitize( array( 'increment' => 100 ) )['batch_size'], 'a partial update keeps the other defaults' );

usdtf_it_set_currency( 'IRR' );
usdtf_it_assert_same( 10000.0, \USDTF\Settings::sanitize( array( 'increment' => 100 ) )['increment'], 'an increment that is not allowed for Rial falls back to 10000' );
usdtf_it_assert_same( 1000.0, \USDTF\Settings::sanitize( array( 'increment' => 1000 ) )['increment'], 'a Rial increment of 1000 is kept' );
usdtf_it_assert_same( 10000.0, \USDTF\Settings::defaults()['increment'], 'the Rial default increment is 10000' );

// The rounding options follow the unit and the Woo decimals.
$settings_object = usdtf_plugin()->settings();
usdtf_it_set_currency( 'IRT' );
$settings_object->update( array( 'increment' => 100.0 ) );
usdtf_it_assert_same( 100.0, $settings_object->rounding_args()['increment'], 'rounding_args() returns a stored allowed increment' );
usdtf_it_assert_same( wc_get_price_decimals(), $settings_object->rounding_args()['decimals'], 'rounding_args() decimals come from WooCommerce' );

$two_decimals = function () {
	return 2;
};
add_filter( 'wc_get_price_decimals', $two_decimals );
usdtf_it_assert_same( 2, $settings_object->rounding_args()['decimals'], 'rounding_args() follows the WooCommerce price decimals' );
remove_filter( 'wc_get_price_decimals', $two_decimals );

usdtf_it_set_currency( 'IRR' );
usdtf_it_assert_same( 10000.0, $settings_object->rounding_args()['increment'], 'a stored increment that the unit does not allow falls back in rounding_args()' );
usdtf_it_assert_same( array( 'rounding', 'increment', 'decimals' ), array_keys( $settings_object->rounding_args() ), 'rounding_args() has exactly the calculator keys' );
usdtf_it_assert_same( 'up', $settings_object->rounding_args()['rounding'], 'rounding_args() returns the rounding mode' );

usdtf_it_set_currency( 'IRT' );
usdtf_it_reset_plugin_state();
usdtf_it_pass( 'the settings are unit aware and free of the obsolete keys' );

// ---------------------------------------------------------------------------
// Jobs: the generated and rebased counters and the new item columns.
// ---------------------------------------------------------------------------
$job_repo = usdtf_plugin()->jobs();
$job_id   = $job_repo->create( array( 'rate' => 270000 ) );
usdtf_it_assert( $job_id > 0, 'the job row must be created' );

usdtf_it_assert_same( 'generated', \USDTF\Job_Repository::ITEM_GENERATED, 'the generated item status' );
usdtf_it_assert_same( 'rebased', \USDTF\Job_Repository::ITEM_REBASED, 'the rebased item status' );

$job_repo->increment(
	$job_id,
	array(
		'generated' => 3,
		'rebased'   => 2,
		'changed'   => 1,
	)
);
$job_counters = $job_repo->get( $job_id )->counters();
usdtf_it_assert_same( 3, $job_counters['generated'], 'the generated counter increments' );
usdtf_it_assert_same( 2, $job_counters['rebased'], 'the rebased counter increments' );
usdtf_it_assert_same( 1, $job_counters['changed'], 'the changed counter still increments' );

$job_repo->set_counter( $job_id, 'generated', 7 );
usdtf_it_assert_same( 7, $job_repo->get( $job_id )->counters()['generated'], 'the generated counter can be set' );

$job_repo->update(
	$job_id,
	array(
		'rebased' => 9,
		'message' => 'x',
	)
);
usdtf_it_assert_same( 9, $job_repo->get( $job_id )->counters()['rebased'], 'the rebased counter can be updated' );

usdtf_it_assert( ! in_array( 'source_regular', \USDTF\Job_Repository::ITEM_COLUMNS, true ), 'the item columns no longer list source_regular' );
usdtf_it_assert( ! in_array( 'toman_regular', \USDTF\Job_Repository::ITEM_COLUMNS, true ), 'the item columns no longer list toman_regular' );
usdtf_it_assert( ! in_array( 'expected_revision', \USDTF\Job_Repository::ITEM_COLUMNS, true ), 'the item columns no longer list expected_revision' );
usdtf_it_assert( in_array( 'usd_regular', \USDTF\Job_Repository::ITEM_COLUMNS, true ), 'the item columns list usd_regular' );
usdtf_it_assert( in_array( 'usd_sale', \USDTF\Job_Repository::ITEM_COLUMNS, true ), 'the item columns list usd_sale' );
usdtf_it_assert_same( count( \USDTF\Job_Repository::ITEM_COLUMNS ), count( \USDTF\Job_Repository::ITEM_FORMATS ), 'every item column has a format' );

$job_repo->add_items(
	$job_id,
	array(
		array(
			'object_id'   => 123,
			'usd_regular' => '18.52',
			'usd_sale'    => '16.00',
			'old_regular' => '5000000',
			'new_regular' => '5001000',
			'status'      => \USDTF\Job_Repository::ITEM_GENERATED,
		),
	)
);
$item_row = \USDTF\Database::get_row( 'SELECT * FROM `' . \USDTF\Database::items_table() . '` WHERE job_id = ' . (int) $job_id );
usdtf_it_assert_same( '18.52', $item_row['usd_regular'], 'an item stores the USD regular anchor' );
usdtf_it_assert_same( '16.00', $item_row['usd_sale'], 'an item stores the USD sale anchor' );
usdtf_it_assert_same( 'generated', $item_row['status'], 'an item can be generated' );
usdtf_it_pass( 'the job counters and item columns carry the pegged model' );
usdtf_it_reset_plugin_state();

// ---------------------------------------------------------------------------
// Upgrade: an old-shaped database is migrated once, and only once.
// ---------------------------------------------------------------------------
global $wpdb;

$items_table = \USDTF\Database::items_table();
$jobs_table  = \USDTF\Database::jobs_table();
$columns_of  = function ( $table ) use ( $wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
};

// Put the tables back in the old shape: no new columns.
foreach ( array( $items_table => array( 'usd_regular', 'usd_sale' ), $jobs_table => array( 'generated', 'rebased' ) ) as $table_name => $new_columns ) {
	foreach ( $new_columns as $new_column ) {
		if ( in_array( $new_column, $columns_of( $table_name ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table_name}` DROP COLUMN `{$new_column}`" );
		}
	}
}

$items_before = $columns_of( $items_table );
$jobs_before  = $columns_of( $jobs_table );
usdtf_it_assert( ! in_array( 'usd_regular', $items_before, true ), 'the old items table has no usd_regular column' );
usdtf_it_assert( ! in_array( 'generated', $columns_of( $jobs_table ), true ), 'the old jobs table has no generated column' );

$old_pid   = usdtf_it_make_priced_product( 'Old shape', 5000000 )->get_id();
$kept_pid  = usdtf_it_make_priced_product( 'Kept shape', 6000000 )->get_id();
$old_metas = array(
	'_usdtf_source_regular'  => '18.52',
	'_usdtf_source_sale'     => '16',
	'_usdtf_derived_regular' => '5000000',
	'_usdtf_derived_sale'    => '4320000',
	'_usdtf_mode_synced'     => 'native',
	'_usdtf_external_source' => 'woo',
	'_usdtf_revision'        => '4',
);

foreach ( $old_metas as $meta_key => $meta_value ) {
	update_post_meta( $old_pid, $meta_key, $meta_value );
	update_post_meta( $kept_pid, $meta_key, $meta_value );
}

update_post_meta( $old_pid, '_usdtf_mode', 'native' );
update_post_meta( $kept_pid, '_usdtf_mode', 'managed' );
update_post_meta( $kept_pid, '_usdtf_usd_regular', '22.22' );
update_post_meta( $kept_pid, '_usdtf_rate', '270000' );

update_option(
	'usdtf_settings',
	array(
		'currency_mode'            => 'toman',
		'rounding'                 => 'nearest',
		'increment'                => 1.0,
		'decimals'                 => 2,
		'batch_size'               => 15,
		'auto_manage_new_products' => true,
		'display_toman'            => true,
		'display_toman_cart'       => false,
		'persian_digits'           => true,
		'toman_suffix'             => 'تومان',
	)
);
update_option( 'usdtf_synced_currency_mode', 'usd', false );
update_option( \USDTF\Installer::VERSION_OPTION, '5', false );
usdtf_plugin()->settings()->all( true );

\USDTF\Installer::maybe_upgrade();

foreach ( array_keys( $old_metas ) as $meta_key ) {
	usdtf_it_assert_same( '', usdtf_it_meta( $old_pid, $meta_key ), 'the upgrade deletes ' . $meta_key . ' from the first product' );
	usdtf_it_assert_same( '', usdtf_it_meta( $kept_pid, $meta_key ), 'the upgrade deletes ' . $meta_key . ' from the second product' );
}

usdtf_it_assert_same( 'excluded', usdtf_it_meta( $old_pid, '_usdtf_mode' ), 'a stored native mode is rewritten to excluded' );
usdtf_it_assert_same( 'managed', usdtf_it_meta( $kept_pid, '_usdtf_mode' ), 'a managed product stays managed' );
usdtf_it_assert_same( '22.22', usdtf_it_meta( $kept_pid, '_usdtf_usd_regular' ), 'the new USD anchor is left alone by the upgrade' );
usdtf_it_assert_same( '270000', usdtf_it_meta( $kept_pid, '_usdtf_rate' ), 'the stored rate is left alone by the upgrade' );
usdtf_it_assert_same( '5000000', usdtf_it_price( $old_pid ), 'the upgrade never touches a WooCommerce price' );

$upgraded_settings = get_option( 'usdtf_settings' );

foreach ( $removed_settings_keys as $removed_key ) {
	usdtf_it_assert( ! array_key_exists( $removed_key, $upgraded_settings ), 'the upgrade drops the settings key ' . $removed_key );
}

usdtf_it_assert_same( 'nearest', $upgraded_settings['rounding'], 'the upgrade keeps the rounding mode' );
usdtf_it_assert_same( 15, $upgraded_settings['batch_size'], 'the upgrade keeps the batch size' );
usdtf_it_assert_same( false, get_option( 'usdtf_synced_currency_mode', false ), 'the upgrade removes the synced currency mode option' );
usdtf_it_assert_same( \USDTF\Database::SCHEMA_VERSION, get_option( \USDTF\Installer::VERSION_OPTION ), 'the upgrade records the new schema version' );
usdtf_it_assert( '5' !== \USDTF\Database::SCHEMA_VERSION, 'the schema version was bumped' );

usdtf_it_assert( in_array( 'usd_regular', $columns_of( $items_table ), true ), 'the upgrade adds items.usd_regular' );
usdtf_it_assert( in_array( 'usd_sale', $columns_of( $items_table ), true ), 'the upgrade adds items.usd_sale' );
usdtf_it_assert( in_array( 'generated', $columns_of( $jobs_table ), true ), 'the upgrade adds jobs.generated' );
usdtf_it_assert( in_array( 'rebased', $columns_of( $jobs_table ), true ), 'the upgrade adds jobs.rebased' );
usdtf_it_assert_same( array(), array_diff( $items_before, $columns_of( $items_table ) ), 'the obsolete items columns stay in place' );
usdtf_it_assert_same( array(), array_diff( $jobs_before, $columns_of( $jobs_table ) ), 'the obsolete jobs columns stay in place' );

// A second run changes nothing, even when the version marker is lost.
$state_after_first = array(
	'settings' => get_option( 'usdtf_settings' ),
	'metas'    => array( get_post_meta( $old_pid ), get_post_meta( $kept_pid ) ),
	'items'    => $columns_of( $items_table ),
	'jobs'     => $columns_of( $jobs_table ),
);

\USDTF\Installer::maybe_upgrade();
update_option( \USDTF\Installer::VERSION_OPTION, '5', false );
\USDTF\Installer::maybe_upgrade();
\USDTF\Installer::migrate_to_pegged_model();

$state_after_second = array(
	'settings' => get_option( 'usdtf_settings' ),
	'metas'    => array( get_post_meta( $old_pid ), get_post_meta( $kept_pid ) ),
	'items'    => $columns_of( $items_table ),
	'jobs'     => $columns_of( $jobs_table ),
);

usdtf_it_assert_same( $state_after_first, $state_after_second, 'running the upgrade again changes nothing' );
usdtf_it_assert_same( \USDTF\Database::SCHEMA_VERSION, get_option( \USDTF\Installer::VERSION_OPTION ), 'the schema version stays current after a second upgrade' );
usdtf_it_pass( 'the upgrade migrates an old database once' );

// Leave nothing behind for the legacy scenarios that follow in a full run.
usdtf_it_set_currency( null );
usdtf_it_delete_products();
