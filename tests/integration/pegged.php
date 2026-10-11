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

// ---------------------------------------------------------------------------
// Product pricing model: the USD anchor, the seen price and the mode.
// ---------------------------------------------------------------------------
usdtf_it_set_currency( 'IRT' );
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$pricing  = usdtf_plugin()->pricing();
$products = usdtf_plugin()->products();

usdtf_it_assert_same( array( 'managed', 'excluded' ), \USDTF\Product_Pricing::modes(), 'the pricing modes are managed and excluded' );
usdtf_it_assert_same( '_usdtf_usd_regular', \USDTF\Product_Pricing::META_USD_REGULAR, 'the USD regular meta key' );
usdtf_it_assert_same( '_usdtf_usd_sale', \USDTF\Product_Pricing::META_USD_SALE, 'the USD sale meta key' );
usdtf_it_assert_same( '_usdtf_seen_regular', \USDTF\Product_Pricing::META_SEEN_REGULAR, 'the seen regular meta key' );
usdtf_it_assert_same( '_usdtf_seen_sale', \USDTF\Product_Pricing::META_SEEN_SALE, 'the seen sale meta key' );

foreach ( array( 'MODE_NATIVE', 'META_SOURCE_REGULAR', 'META_SOURCE_SALE', 'META_DERIVED_REGULAR', 'META_DERIVED_SALE', 'META_REVISION', 'META_MODE_SYNCED', 'META_EXTERNAL_SOURCE' ) as $removed_constant ) {
	usdtf_it_assert( ! defined( '\\USDTF\\Product_Pricing::' . $removed_constant ), 'the constant ' . $removed_constant . ' is gone' );
}

foreach ( array( 'get_source', 'validate_source_pair', 'set_source', 'source_error_message', 'get_revision', 'bump_revision', 'import_current_price_as_source', 'ingest_woocommerce_prices', 'on_meta_written', 'on_meta_deleted', 'on_admin_process_product', 'on_admin_process_variation' ) as $removed_method ) {
	usdtf_it_assert( ! method_exists( $pricing, $removed_method ), 'the method ' . $removed_method . ' is gone' );
}

// A product with no plugin meta at all is managed (amendment A1).
$unset_product = usdtf_it_make_priced_product( 'Unset mode', 5000000 );
usdtf_it_assert_same( '', usdtf_it_meta( $unset_product->get_id(), '_usdtf_mode' ), 'the fixture carries no mode meta' );
usdtf_it_assert_same( 'managed', $pricing->get_mode( $unset_product->get_id() ), 'a product with no mode meta is managed' );
usdtf_it_assert_same( true, $pricing->is_managed( $unset_product ), 'a product with no mode meta counts as managed' );

$managed_product = usdtf_it_make_priced_product( 'Managed mode', 5000000, '', 'managed' );
usdtf_it_assert_same( 'managed', $pricing->get_mode( $managed_product ), 'a stored managed mode reads managed' );

$excluded_product = usdtf_it_make_priced_product( 'Excluded mode', 5000000, '', 'excluded' );
usdtf_it_assert_same( 'excluded', $pricing->get_mode( $excluded_product ), 'a stored excluded mode reads excluded' );
usdtf_it_assert_same( false, $pricing->is_managed( $excluded_product ), 'an excluded product is not managed' );

$native_product = usdtf_it_make_priced_product( 'Native mode', 5000000, '', 'native' );
usdtf_it_assert_same( 'excluded', $pricing->get_mode( $native_product ), 'a stored native mode reads excluded' );

usdtf_it_assert_same( true, $pricing->set_mode( $unset_product, 'excluded' ), 'set_mode accepts excluded' );
usdtf_it_assert_same( 'excluded', usdtf_it_meta( $unset_product->get_id(), '_usdtf_mode' ), 'set_mode stores the mode' );
usdtf_it_assert_same( false, $pricing->set_mode( $unset_product, 'native' ), 'set_mode refuses the retired native mode' );
usdtf_it_assert_same( 'excluded', usdtf_it_meta( $unset_product->get_id(), '_usdtf_mode' ), 'a refused mode changes nothing' );
$pricing->set_mode( $unset_product, 'managed' );
usdtf_it_assert_same( 'managed', $pricing->get_mode( $unset_product ), 'set_mode can make a product managed again' );

// save_state then get_snapshot round-trips every field.
$state_product = usdtf_it_make_priced_product( 'State product', 5000000, 4320000 );
$state_id      = $state_product->get_id();

$pricing->save_state(
	$state_id,
	array(
		'usd_regular'  => '18.52',
		'usd_sale'     => '16.00',
		'seen_regular' => '5000000',
		'seen_sale'    => '4320000',
		'rate'         => 270000.0,
		'synced_at'    => 1700000000,
	)
);

$snapshot = $pricing->get_snapshot( $state_id );
usdtf_it_assert_same( $state_id, $snapshot['id'], 'the snapshot carries the product id' );
usdtf_it_assert_same( 0, $snapshot['parent_id'], 'the snapshot carries the parent id' );
usdtf_it_assert_same( 'product', $snapshot['object_type'], 'the snapshot carries the object type' );
usdtf_it_assert_same( 'State product', $snapshot['name'], 'the snapshot carries the name' );
usdtf_it_assert_same( 'managed', $snapshot['mode'], 'the snapshot carries the mode' );
usdtf_it_assert_same( true, $snapshot['managed'], 'the snapshot says the product is managed' );
usdtf_it_assert_same( '5000000', $snapshot['current_regular'], 'the snapshot carries the Woo regular price as a string' );
usdtf_it_assert_same( '4320000', $snapshot['current_sale'], 'the snapshot carries the Woo sale price as a string' );
usdtf_it_assert_same( '5000000', $snapshot['seen_regular'], 'seen_regular round-trips' );
usdtf_it_assert_same( '4320000', $snapshot['seen_sale'], 'seen_sale round-trips' );
usdtf_it_assert_same( '18.52', $snapshot['usd_regular'], 'usd_regular round-trips as a string' );
usdtf_it_assert_same( '16.00', $snapshot['usd_sale'], 'usd_sale round-trips as a string' );
usdtf_it_assert_same( 270000.0, $snapshot['rate'], 'the rate round-trips as a float' );
usdtf_it_assert_same( 1700000000, $snapshot['synced_at'], 'synced_at round-trips as an int' );
usdtf_it_assert_same( '', $snapshot['conflict'], 'the snapshot has no conflict' );
usdtf_it_assert_same( '', $snapshot['last_error'], 'the snapshot has no error' );
usdtf_it_assert_same( '270000', usdtf_it_meta( $state_id, '_usdtf_rate' ), 'the rate is stored as a float string' );
usdtf_it_assert_same( '5000000', usdtf_it_price( $state_id ), 'save_state never touches the Woo price' );

foreach ( array( 'source_regular', 'derived_regular', 'revision', 'mode_synced', 'external_source', 'price' ) as $dropped_key ) {
	usdtf_it_assert( ! array_key_exists( $dropped_key, $snapshot ), 'the snapshot no longer carries ' . $dropped_key );
}

$pricing->mark_conflict( $state_id, 'edited mid job' );
$pricing->mark_error( $state_id, 'boom' );
$snapshot = $pricing->get_snapshot( $state_id );
usdtf_it_assert_same( 'edited mid job', $snapshot['conflict'], 'the snapshot carries the conflict' );
usdtf_it_assert_same( 'boom', $snapshot['last_error'], 'the snapshot carries the error' );
$pricing->clear_conflict( $state_id );
$pricing->clear_error( $state_id );
usdtf_it_assert_same( '', $pricing->get_snapshot( $state_id )['conflict'], 'the conflict can be cleared' );

// A partial state writes only the keys it holds; an empty price removes its row.
$pricing->save_state( $state_id, array( 'usd_sale' => '', 'seen_sale' => '' ) );
$snapshot = $pricing->get_snapshot( $state_id );
usdtf_it_assert_same( '', $snapshot['usd_sale'], 'an empty USD sale clears the anchor' );
usdtf_it_assert_same( '', $snapshot['seen_sale'], 'an empty seen sale clears the seen price' );
usdtf_it_assert_same( '18.52', $snapshot['usd_regular'], 'a partial state leaves the other keys alone' );
usdtf_it_assert_same( array(), get_post_meta( $state_id, '_usdtf_usd_sale', false ), 'an empty USD sale leaves no meta row' );

// A product with nothing stored has an empty snapshot state.
$blank_snapshot = $pricing->get_snapshot( usdtf_it_make_priced_product( 'Blank state', 1000000 ) );
usdtf_it_assert_same( '', $blank_snapshot['usd_regular'], 'a blank product has no USD anchor' );
usdtf_it_assert_same( 0.0, $blank_snapshot['rate'], 'a blank product has no rate' );
usdtf_it_assert_same( 0, $blank_snapshot['synced_at'], 'a blank product was never synced' );
usdtf_it_assert_same( '', $blank_snapshot['current_sale'], 'a product with no sale has an empty sale string' );

usdtf_it_assert_same( 'excluded', \USDTF\Product_Pricing::MODE_EXCLUDED, 'the excluded mode constant' );
usdtf_it_pass( 'the product pricing model round-trips the USD anchor and reads a missing mode as managed' );

// ---------------------------------------------------------------------------
// Scopes: managed, excluded and only_outdated.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
$products->flush_counts_cache();

$scope_unset    = usdtf_it_make_priced_product( 'Scope unset', 5000000 )->get_id();
$scope_managed  = usdtf_it_make_priced_product( 'Scope managed', 5000000, '', 'managed' )->get_id();
$scope_excluded = usdtf_it_make_priced_product( 'Scope excluded', 5000000, '', 'excluded' )->get_id();
$scope_native   = usdtf_it_make_priced_product( 'Scope native', 5000000, '', 'native' )->get_id();
$scope_ids      = array( $scope_unset, $scope_managed, $scope_excluded, $scope_native );

$ids_in = function ( $mode, array $extra = array(), $rate = 0 ) use ( $products, $scope_ids ) {
	$found = $products->get_ids( array_merge( array( 'mode' => $mode, 'ids' => $scope_ids ), $extra ), 1, 100, 'product', 0, $rate );
	sort( $found );

	return $found;
};

usdtf_it_assert_same( array( $scope_unset, $scope_managed ), $ids_in( 'managed' ), 'the managed scope holds the unset and the managed product' );
usdtf_it_assert_same( array( $scope_excluded, $scope_native ), $ids_in( 'excluded' ), 'the excluded scope holds the excluded and the native product' );
usdtf_it_assert_same( $scope_ids, $ids_in( 'all' ), 'the all scope holds every product' );
usdtf_it_assert_same( array( $scope_excluded, $scope_native ), $ids_in( 'native' ), 'a scope saved with the retired native mode means excluded' );
usdtf_it_assert_same( array( $scope_unset, $scope_managed ), $ids_in( 'bogus' ), 'an unknown scope mode falls back to managed' );
usdtf_it_assert_same( 2, $products->count( array( 'mode' => 'managed', 'ids' => $scope_ids ) ), 'count() agrees with the managed scope' );
usdtf_it_assert_same( 'managed', $products->normalize_scope( array() )['mode'], 'the default scope is managed' );

// only_outdated: no USD anchor, or a stored rate that differs from the current one.
usdtf_it_delete_products();

$outdated_none    = usdtf_it_make_priced_product( 'Outdated none', 5000000 )->get_id();
$outdated_rate    = usdtf_it_make_priced_product( 'Outdated rate', 5000000 )->get_id();
$outdated_current = usdtf_it_make_priced_product( 'Outdated current', 5000000 )->get_id();
$outdated_other   = usdtf_it_make_priced_product( 'Outdated excluded', 5000000, '', 'excluded' )->get_id();
$outdated_ids     = array( $outdated_none, $outdated_rate, $outdated_current, $outdated_other );

$pricing->save_state( $outdated_rate, array( 'usd_regular' => '18.52', 'seen_regular' => '5000000', 'rate' => 260000.0 ) );
$pricing->save_state( $outdated_current, array( 'usd_regular' => '18.52', 'seen_regular' => '5000000', 'rate' => 270000.0 ) );

$outdated = $products->get_ids( array( 'mode' => 'managed', 'ids' => $outdated_ids, 'only_outdated' => true ), 1, 100, 'product', 0, 270000.0 );
sort( $outdated );
usdtf_it_assert_same( array( $outdated_none, $outdated_rate ), $outdated, 'only_outdated holds a product with no USD price and one with another rate, not one at the current rate' );

$everything = $products->get_ids( array( 'mode' => 'managed', 'ids' => $outdated_ids, 'only_outdated' => true ), 1, 100, 'product', 0, 0 );
sort( $everything );
usdtf_it_assert_same( array( $outdated_none, $outdated_rate, $outdated_current ), $everything, 'without a rate only_outdated does not filter' );
usdtf_it_assert_same( 2, $products->count( array( 'mode' => 'managed', 'ids' => $outdated_ids, 'only_outdated' => true ), 'product', 0, 270000.0 ), 'count() agrees with only_outdated' );

// A stored rate with the same value as the current one is current, however it was typed.
update_post_meta( $outdated_current, '_usdtf_rate', '270000' );
usdtf_it_assert_same( 2, $products->count( array( 'mode' => 'managed', 'ids' => $outdated_ids, 'only_outdated' => true ), 'product', 0, 270000 ), 'an integer rate matches the stored float string' );

usdtf_it_pass( 'the scopes read a missing mode as managed and native as excluded' );

// ---------------------------------------------------------------------------
// Mode counts: no native, no unset.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
$products->flush_counts_cache();

$baseline = $products->mode_counts();
$products->flush_counts_cache();

usdtf_it_make_priced_product( 'Count unset', 5000000 );
usdtf_it_make_priced_product( 'Count managed', 5000000, '', 'managed' );
usdtf_it_make_priced_product( 'Count excluded', 5000000, '', 'excluded' );
usdtf_it_make_priced_product( 'Count native', 5000000, '', 'native' );

// A variable product whose variations have a mode of their own, independent of the parent.
$variable = new \WC_Product_Variable();
$variable->set_name( 'Count variable' );
$variable->set_status( 'publish' );
$size = new \WC_Product_Attribute();
$size->set_name( 'Size' );
$size->set_options( array( '1', '2', '3' ) );
$size->set_visible( true );
$size->set_variation( true );
$variable->set_attributes( array( $size ) );
$variable->save();
usdtf_it_track_product( $variable );
update_post_meta( $variable->get_id(), '_usdtf_mode', 'excluded' );

$variation_ids = array();

foreach ( array( '1' => '', '2' => 'excluded', '3' => 'native' ) as $size_option => $variation_mode ) {
	$variation = new \WC_Product_Variation();
	$variation->set_parent_id( $variable->get_id() );
	$variation->set_attributes( array( 'size' => (string) $size_option ) );
	$variation->set_regular_price( '5000000' );
	$variation->save();
	usdtf_it_track_product( $variation );

	if ( '' !== $variation_mode ) {
		update_post_meta( $variation->get_id(), '_usdtf_mode', $variation_mode );
	}

	$variation_ids[ $size_option ] = $variation->get_id();
}

usdtf_it_assert_same( 'excluded', $pricing->get_mode( $variable->get_id() ), 'the variable parent is excluded' );
usdtf_it_assert_same( 'managed', $pricing->get_mode( $variation_ids['1'] ), 'a variation with no mode meta is managed whatever its parent is' );
usdtf_it_assert_same( 'excluded', $pricing->get_mode( $variation_ids['2'] ), 'an excluded variation reads excluded' );
usdtf_it_assert_same( 'excluded', $pricing->get_mode( $variation_ids['3'] ), 'a native variation reads excluded' );

$variation_scope = function ( $mode ) use ( $products, $variable ) {
	$found = $products->get_ids( array( 'mode' => $mode ), 1, 100, 'variation', $variable->get_id() );
	sort( $found );

	return $found;
};

usdtf_it_assert_same( array( $variation_ids['1'] ), $variation_scope( 'managed' ), 'the managed variation scope holds the unset variation of an excluded parent' );
usdtf_it_assert_same( array( $variation_ids['2'], $variation_ids['3'] ), $variation_scope( 'excluded' ), 'the excluded variation scope holds the excluded and the native variation' );
usdtf_it_assert_same( array_values( $variation_ids ), $variation_scope( 'all' ), 'the all variation scope holds every variation' );
usdtf_it_assert_same( 3, $products->count_variations( $variable->get_id() ), 'count_variations() counts every variation' );

$mode_counts = $products->mode_counts();
$count_keys  = array_keys( $mode_counts );
sort( $count_keys );
usdtf_it_assert_same( array( 'excluded', 'managed', 'managed_variations', 'products', 'variations' ), $count_keys, 'mode_counts() has no native and no unset key' );
usdtf_it_assert_same( $baseline['managed'] + 2, $mode_counts['managed'], 'the managed count holds the unset and the managed product' );
usdtf_it_assert_same( $baseline['excluded'] + 3, $mode_counts['excluded'], 'the excluded count holds excluded, native and the excluded parent' );
usdtf_it_assert_same( $baseline['products'] + 5, $mode_counts['products'], 'the product count holds every product' );
usdtf_it_assert_same( $baseline['variations'] + 3, $mode_counts['variations'], 'the variation count holds every variation' );
usdtf_it_assert_same( $baseline['managed_variations'] + 1, $mode_counts['managed_variations'], 'the managed variation count holds the unset variation' );

$summary = $products->summary( 0 );
usdtf_it_assert( ! array_key_exists( 'native', $summary ) && ! array_key_exists( 'unset', $summary ), 'the summary has no native and no unset' );
usdtf_it_assert_same( $mode_counts['managed'], $summary['managed'], 'the summary carries the managed count' );
usdtf_it_assert_same( $mode_counts['excluded'], $summary['excluded'], 'the summary carries the excluded count' );
usdtf_it_pass( 'the mode counts carry no native or unset' );

// ---------------------------------------------------------------------------
// Meta keys: the uninstall purge goes through the list, deletion leaves nothing.
// ---------------------------------------------------------------------------
usdtf_it_assert_same(
	array(
		'_usdtf_usd_regular',
		'_usdtf_usd_sale',
		'_usdtf_seen_regular',
		'_usdtf_seen_sale',
		'_usdtf_rate',
		'_usdtf_mode',
		'_usdtf_synced_at',
		'_usdtf_conflict',
		'_usdtf_last_error',
		'_usdtf_last_job',
	),
	\USDTF\Product_Pricing::meta_keys(),
	'meta_keys() lists exactly the new key set'
);

$doomed     = usdtf_it_make_priced_product( 'Doomed product', 5000000, 4320000, 'managed' );
$doomed_id  = $doomed->get_id();

$pricing->save_state(
	$doomed_id,
	array(
		'usd_regular'  => '18.52',
		'usd_sale'     => '16.00',
		'seen_regular' => '5000000',
		'seen_sale'    => '4320000',
		'rate'         => 270000.0,
		'synced_at'    => time(),
	)
);
$pricing->mark_conflict( $doomed_id, 'edited mid job' );
$pricing->mark_error( $doomed_id, 'boom' );
update_post_meta( $doomed_id, '_usdtf_last_job', 7 );

foreach ( \USDTF\Product_Pricing::meta_keys() as $doomed_key ) {
	usdtf_it_assert( '' !== (string) get_post_meta( $doomed_id, $doomed_key, true ), 'the doomed product holds ' . $doomed_key . ' before it is deleted' );
}

$doomed_variation = $variation_ids['2'];
$pricing->save_state( $doomed_variation, array( 'usd_regular' => '18.52', 'rate' => 270000.0, 'synced_at' => time() ) );

wp_delete_post( $doomed_id, true );
wp_delete_post( $doomed_variation, true );

foreach ( array( $doomed_id, $doomed_variation ) as $doomed_post ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $doomed_post ) );
	usdtf_it_assert_same( 0, $remaining, 'deleting a product leaves no meta rows behind for its id' );
}

usdtf_it_pass( 'the meta key list is the new key set and deleting a product leaves no plugin meta' );

// ---------------------------------------------------------------------------
// Recorder and sync runner: Preview then Update through the jobs engine.
// ---------------------------------------------------------------------------
usdtf_it_set_currency( 'IRT' );
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$pg_settings = usdtf_plugin()->settings();
$pg_rates    = usdtf_plugin()->rates();
$pg_jobs     = usdtf_plugin()->jobs();
$pg_recorder = usdtf_plugin()->recorder();

// A Toman store prices without decimals; the shipped default needs a dry run before an update.
$pg_decimals = get_option( 'woocommerce_price_num_decimals' );
update_option( 'woocommerce_price_num_decimals', 0 );
$pg_settings->update( array( 'require_preview' => true ) );
$pg_rates->save_rate( 270000 );

// Every WooCommerce product save counts as a price write.
$GLOBALS['usdtf_it_price_writes'] = 0;
$pg_count_write                   = function () {
	++$GLOBALS['usdtf_it_price_writes'];
};
add_action( 'woocommerce_update_product', $pg_count_write );
add_action( 'woocommerce_update_product_variation', $pg_count_write );

// A dry run, then the update it unlocks, for the given products only.
$pg_preview = function ( array $ids ) {
	$preview = usdtf_it_create_job(
		array(
			'type'  => \USDTF\Job::TYPE_PREVIEW,
			'scope' => array( 'ids' => $ids ),
		)
	);
	usdtf_it_run_job( (int) $preview['id'] );

	return (int) $preview['id'];
};
$pg_update  = function ( array $ids ) {
	$GLOBALS['usdtf_it_price_writes'] = 0;

	$update = usdtf_it_create_job( array( 'scope' => array( 'ids' => $ids ) ) );
	usdtf_it_run_job( (int) $update['id'] );

	return (int) $update['id'];
};
$pg_sync    = function ( array $ids ) use ( $pg_preview, $pg_update ) {
	$pg_preview( $ids );

	return $pg_update( $ids );
};
$pg_item    = function ( $job_id, $object_id ) use ( $pg_jobs ) {
	foreach ( $pg_jobs->items( $job_id, array( 'limit' => 200 ) ) as $item ) {
		if ( (int) $item['object_id'] === (int) $object_id ) {
			return $item;
		}
	}

	return array();
};
$pg_status  = function ( $job_id, $object_id ) {
	$statuses = usdtf_it_item_statuses( $job_id );

	return isset( $statuses[ (int) $object_id ] ) ? $statuses[ (int) $object_id ] : '';
};
$pg_set_woo = function ( $product_id, $regular, $sale = null ) {
	$product = wc_get_product( $product_id );
	$product->set_regular_price( (string) $regular );

	if ( null !== $sale ) {
		$product->set_sale_price( (string) $sale );
	}

	$product->save();
};

// 1. The first run only generates the USD anchor.
$pg_a = usdtf_it_make_priced_product( 'Pegged A', 5000000 )->get_id();

$pg_refused = $runner->create_job( array( 'scope' => array( 'ids' => array( $pg_a ) ) ) );
usdtf_it_assert_same( 'usdtf_preview_required', is_wp_error( $pg_refused ) ? $pg_refused->get_error_code() : '', 'an update without a dry run is refused' );

$pg_job = $pg_sync( array( $pg_a ) );
usdtf_it_assert_same( '5000000', usdtf_it_price( $pg_a ), 'the first run leaves the Woo price alone' );
usdtf_it_assert_same( '18.52', usdtf_it_meta( $pg_a, '_usdtf_usd_regular' ), 'the first run stores the USD anchor' );
usdtf_it_assert_same( '5000000', usdtf_it_meta( $pg_a, '_usdtf_seen_regular' ), 'the first run stores the seen price' );
usdtf_it_assert_same( '270000', usdtf_it_meta( $pg_a, '_usdtf_rate' ), 'the first run stores the rate' );
usdtf_it_assert_same( (string) $pg_job, usdtf_it_meta( $pg_a, '_usdtf_last_job' ), 'the first run records the job' );
usdtf_it_assert_same( 'generated', $pg_status( $pg_job, $pg_a ), 'the first run reports generated' );
usdtf_it_assert_same( '18.52', $pg_item( $pg_job, $pg_a )['usd_regular'], 'the item row stores the USD anchor' );
usdtf_it_assert_same( 1, $pg_jobs->get( $pg_job )->counters()['generated'], 'the job counts one generated product' );
usdtf_it_assert_same( 0, $pg_jobs->get( $pg_job )->counters()['changed'], 'the job counts no changed product' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'generating the anchor saves no product' );
usdtf_it_assert_same( 'up', $pg_jobs->get( $pg_job )->data['rounding'], 'the job row keeps the rounding mode' );
usdtf_it_pass( 'the first run only generates the USD anchor' );

// 2. A second run at the same rate changes nothing.
$pg_job = $pg_sync( array( $pg_a ) );
usdtf_it_assert_same( 'unchanged', $pg_status( $pg_job, $pg_a ), 'the second run reports unchanged' );
usdtf_it_assert_same( 1, $pg_jobs->get( $pg_job )->counters()['unchanged'], 'the job counts one unchanged product' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'the second run saves no product' );
usdtf_it_assert_same( '5000000', usdtf_it_price( $pg_a ), 'the second run keeps the Woo price' );
usdtf_it_pass( 'a second run at the same rate writes nothing' );

// 3. A rate change follows the USD anchor.
$pg_rates->save_rate( 300000 );
$pg_job = $pg_sync( array( $pg_a ) );
usdtf_it_assert_same( '5556000', usdtf_it_price( $pg_a ), 'the new price is the USD anchor times the new rate' );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_a ), 'a rate change reports changed' );
usdtf_it_assert_same( '300000', usdtf_it_meta( $pg_a, '_usdtf_rate' ), 'the new rate is stored' );
usdtf_it_assert_same( '5556000', usdtf_it_meta( $pg_a, '_usdtf_seen_regular' ), 'the written price is the new seen price' );
usdtf_it_assert_same( '18.52', usdtf_it_meta( $pg_a, '_usdtf_usd_regular' ), 'the USD anchor is kept' );
usdtf_it_assert_same( '5000000', $pg_item( $pg_job, $pg_a )['old_regular'], 'the item row keeps the old Woo price' );
usdtf_it_assert_same( '5556000', $pg_item( $pg_job, $pg_a )['new_regular'], 'the item row keeps the new Woo price' );
usdtf_it_assert_same( 1, $pg_jobs->get( $pg_job )->counters()['changed'], 'the job counts one changed product' );
usdtf_it_assert_same( 1, $GLOBALS['usdtf_it_price_writes'], 'the rate change saves the product once' );
usdtf_it_pass( 'a rate change rewrites the Woo price from the USD anchor' );

// 4. A hand edit re-bases the USD anchor.
$pg_set_woo( $pg_a, 6000000 );
$pg_job = $pg_sync( array( $pg_a ) );
usdtf_it_assert_same( 'rebased', $pg_status( $pg_job, $pg_a ), 'a hand edit reports rebased' );
usdtf_it_assert_same( '20.00', usdtf_it_meta( $pg_a, '_usdtf_usd_regular' ), 'the USD anchor follows the hand edit' );
usdtf_it_assert_same( '6000000', usdtf_it_price( $pg_a ), 'the hand-edited Woo price is kept' );
usdtf_it_assert_same( '6000000', usdtf_it_meta( $pg_a, '_usdtf_seen_regular' ), 'the hand edit is the new seen price' );
usdtf_it_assert_same( 1, $pg_jobs->get( $pg_job )->counters()['rebased'], 'the job counts one rebased product' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 're-basing saves no product' );
usdtf_it_pass( 'a hand edit re-bases the USD anchor and keeps the Woo price' );

// 5. Sale prices follow their own anchor; an ended sale clears it; the schedule survives.
$pg_rates->save_rate( 270000 );
$pg_b = usdtf_it_make_priced_product( 'Pegged sale', 5000000, 4000000 )->get_id();

$pg_job = $pg_sync( array( $pg_b ) );
usdtf_it_assert_same( 'generated', $pg_status( $pg_job, $pg_b ), 'a product on sale generates both anchors' );
usdtf_it_assert_same( '18.52', usdtf_it_meta( $pg_b, '_usdtf_usd_regular' ), 'the regular anchor of a sale product' );
usdtf_it_assert_same( '14.81', usdtf_it_meta( $pg_b, '_usdtf_usd_sale' ), 'the sale anchor of a sale product' );
usdtf_it_assert_same( '4000000', usdtf_it_meta( $pg_b, '_usdtf_seen_sale' ), 'the seen sale price' );
usdtf_it_assert_same( '14.81', $pg_item( $pg_job, $pg_b )['usd_sale'], 'the item row stores the USD sale anchor' );

$pg_sale_from = strtotime( '+2 days', time() );
$pg_sale_to   = strtotime( '+20 days', time() );
$pg_scheduled = wc_get_product( $pg_b );
$pg_scheduled->set_date_on_sale_from( $pg_sale_from );
$pg_scheduled->set_date_on_sale_to( $pg_sale_to );
$pg_scheduled->save();

$pg_rates->save_rate( 300000 );
$pg_job = $pg_sync( array( $pg_b ) );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_b ), 'a rate change moves a sale product' );
usdtf_it_assert_same( '5556000', usdtf_it_price( $pg_b ), 'the regular price follows the rate' );
usdtf_it_assert_same( '4443000', usdtf_it_sale( $pg_b ), 'the sale price follows the rate' );
usdtf_it_assert( (float) usdtf_it_sale( $pg_b ) < (float) usdtf_it_price( $pg_b ), 'the sale stays below the regular price' );
usdtf_it_assert_same( '4443000', usdtf_it_meta( $pg_b, '_usdtf_seen_sale' ), 'the written sale price is the new seen sale price' );
$pg_scheduled = wc_get_product( $pg_b );
usdtf_it_assert_same( $pg_sale_from, $pg_scheduled->get_date_on_sale_from( 'edit' ) ? $pg_scheduled->get_date_on_sale_from( 'edit' )->getTimestamp() : 0, 'the scheduled sale start survives' );
usdtf_it_assert_same( $pg_sale_to, $pg_scheduled->get_date_on_sale_to( 'edit' ) ? $pg_scheduled->get_date_on_sale_to( 'edit' )->getTimestamp() : 0, 'the scheduled sale end survives' );

// The sale ends: WooCommerce clears the sale price and its dates.
$pg_scheduled->set_sale_price( '' );
$pg_scheduled->set_date_on_sale_from( '' );
$pg_scheduled->set_date_on_sale_to( '' );
$pg_scheduled->save();

$pg_job = $pg_sync( array( $pg_b ) );
usdtf_it_assert_same( 'rebased', $pg_status( $pg_job, $pg_b ), 'an ended sale reports rebased' );
usdtf_it_assert_same( '', usdtf_it_meta( $pg_b, '_usdtf_usd_sale' ), 'an ended sale clears the USD sale anchor' );
usdtf_it_assert_same( '', usdtf_it_meta( $pg_b, '_usdtf_seen_sale' ), 'an ended sale clears the seen sale price' );
usdtf_it_assert_same( '', usdtf_it_sale( $pg_b ), 'an ended sale stays without a sale price' );
usdtf_it_assert_same( '18.52', usdtf_it_meta( $pg_b, '_usdtf_usd_regular' ), 'an ended sale keeps the regular anchor' );
usdtf_it_assert_same( '5556000', usdtf_it_price( $pg_b ), 'an ended sale keeps the regular price' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'an ended sale saves no product' );
usdtf_it_pass( 'sale prices follow their own anchor, an ended sale clears it, the schedule survives' );

// 6. Variations follow the same rules and the parent range is recalculated.
$pg_v          = usdtf_it_make_priced_variable_product( 'Pegged variable', array( array( 5000000 ), array( 6000000, 5500000 ) ) )->get_id();
$pg_variations = usdtf_plugin()->products()->all_variation_ids( $pg_v );
sort( $pg_variations );
list( $pg_v1, $pg_v2 ) = $pg_variations;

$pg_job = $pg_sync( array( $pg_v ) );
usdtf_it_assert_same( 'generated', $pg_status( $pg_job, $pg_v ), 'a variable product generates its variation anchors' );
usdtf_it_assert_same( '16.67', usdtf_it_meta( $pg_v1, '_usdtf_usd_regular' ), 'the first variation anchor' );
usdtf_it_assert_same( '20.00', usdtf_it_meta( $pg_v2, '_usdtf_usd_regular' ), 'the second variation anchor' );
usdtf_it_assert_same( '18.33', usdtf_it_meta( $pg_v2, '_usdtf_usd_sale' ), 'the second variation sale anchor' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'generating variation anchors saves no product' );

$pg_rates->save_rate( 330000 );
$pg_job = $pg_sync( array( $pg_v ) );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_v ), 'a rate change moves the variations' );
usdtf_it_assert_same( '5502000', usdtf_it_price( $pg_v1 ), 'the first variation follows the rate' );
usdtf_it_assert_same( '6600000', usdtf_it_price( $pg_v2 ), 'the second variation follows the rate' );
usdtf_it_assert_same( '6049000', usdtf_it_sale( $pg_v2 ), 'the second variation sale follows the rate' );
usdtf_it_assert_same( '330000', usdtf_it_meta( $pg_v1, '_usdtf_rate' ), 'a variation stores the new rate' );
usdtf_it_assert_same( 2, $pg_jobs->get( $pg_job )->counters()['variations_changed'], 'both variations count as changed' );

$pg_range = array_map( 'strval', get_post_meta( $pg_v, '_price', false ) );
sort( $pg_range );
usdtf_it_assert_same( array( '5502000', '6049000' ), $pg_range, 'the parent price range is recalculated' );
usdtf_it_pass( 'variations follow the same rules and the parent range is recalculated' );

// 7. Excluded, zero and too-low prices are skipped and nothing is written.
$pg_round = $pg_settings->rounding_args();
$pg_dry   = function ( $product_id ) use ( $pg_recorder, $pg_round ) {
	return $pg_recorder->apply(
		wc_get_product( $product_id ),
		array(
			'dry_run'  => true,
			'rate'     => 330000,
			'rounding' => $pg_round,
		)
	);
};

$pg_excluded = usdtf_it_make_priced_product( 'Pegged excluded', 5000000, '', 'excluded' )->get_id();
$pg_zero     = usdtf_it_make_priced_product( 'Pegged zero', '0' )->get_id();
$pg_tiny     = usdtf_it_make_priced_product( 'Pegged tiny', '1' )->get_id();
$pg_tiny_s   = usdtf_it_make_priced_product( 'Pegged tiny sale', 5000000, '1' )->get_id();
$pg_skips    = array(
	$pg_excluded => array( 'not_managed', '5000000' ),
	$pg_zero     => array( 'invalid_price', '0' ),
	$pg_tiny     => array( 'too_low', '1' ),
	$pg_tiny_s   => array( 'too_low', '5000000' ),
);

foreach ( $pg_skips as $pg_id => $pg_expect ) {
	$pg_result = $pg_dry( $pg_id );
	usdtf_it_assert_same( 'skipped', $pg_result['status'], 'the recorder skips ' . $pg_expect[0] );
	usdtf_it_assert_same( $pg_expect[0], $pg_result['code'], 'the skip code is ' . $pg_expect[0] );
}

$pg_job = $pg_sync( array_keys( $pg_skips ) );

foreach ( $pg_skips as $pg_id => $pg_expect ) {
	usdtf_it_assert_same( 'skipped', $pg_status( $pg_job, $pg_id ), 'the job skips ' . $pg_expect[0] );
	usdtf_it_assert_same( $pg_expect[1], usdtf_it_price( $pg_id ), 'a skipped product keeps its Woo price (' . $pg_expect[0] . ')' );

	foreach ( \USDTF\Product_Pricing::meta_keys() as $pg_key ) {
		if ( '_usdtf_mode' !== $pg_key ) {
			usdtf_it_assert_same( '', usdtf_it_meta( $pg_id, $pg_key ), 'a skipped product gets no ' . $pg_key . ' (' . $pg_expect[0] . ')' );
		}
	}
}

usdtf_it_assert_same( 4, $pg_jobs->get( $pg_job )->counters()['skipped'], 'the job counts four skipped products' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'skipped products are not saved' );
usdtf_it_pass( 'excluded, zero and too-low prices are skipped and nothing is written' );

// R4: an update that rounds to zero and a sale above the regular price fail without a write.
$pg_zero_up = usdtf_it_make_priced_product( 'Pegged rounds to zero', 3000 )->get_id();
usdtf_plugin()->pricing()->save_state(
	$pg_zero_up,
	array(
		'usd_regular'  => '0.01',
		'seen_regular' => '3000',
		'rate'         => 300000.0,
	)
);
$pg_down = array(
	'rounding'  => 'down',
	'increment' => 10000.0,
	'decimals'  => 0,
);

foreach ( array( true, false ) as $pg_dry_run ) {
	$pg_result = $pg_recorder->apply(
		wc_get_product( $pg_zero_up ),
		array(
			'dry_run'  => $pg_dry_run,
			'rate'     => 330000,
			'rounding' => $pg_down,
		)
	);
	usdtf_it_assert_same( 'failed', $pg_result['status'], 'a price that rounds to zero fails' );
	usdtf_it_assert_same( 'zero_price', $pg_result['code'], 'a price that rounds to zero has the zero_price code' );
	usdtf_it_assert_same( false, $pg_result['wrote'], 'a price that rounds to zero is not written' );
	usdtf_it_assert_same( $pg_result['old'], $pg_result['new'], 'a price that rounds to zero reports the old Woo prices as new' );
}

usdtf_it_assert_same( '3000', usdtf_it_price( $pg_zero_up ), 'a price that rounds to zero keeps the Woo price' );
usdtf_it_assert_same( '0.01', usdtf_it_meta( $pg_zero_up, '_usdtf_usd_regular' ), 'a price that rounds to zero keeps the anchor' );
usdtf_it_assert_same( '300000', usdtf_it_meta( $pg_zero_up, '_usdtf_rate' ), 'a price that rounds to zero keeps the stored rate' );
usdtf_it_assert( '' !== usdtf_it_meta( $pg_zero_up, '_usdtf_last_error' ), 'a price that rounds to zero records an error' );

// WooCommerce drops such a sale price when the product is saved, so it is planted in the meta.
$pg_above = usdtf_it_make_priced_product( 'Pegged sale above', 5000000 )->get_id();
update_post_meta( $pg_above, '_sale_price', '6000000' );
$pg_result = $pg_recorder->apply(
	wc_get_product( $pg_above ),
	array(
		'rate'     => 330000,
		'rounding' => $pg_round,
	)
);
usdtf_it_assert_same( 'failed', $pg_result['status'], 'a sale above the regular price fails' );
usdtf_it_assert_same( 'sale_above_regular', $pg_result['code'], 'a sale above the regular price has its code' );
usdtf_it_assert_same( '', usdtf_it_meta( $pg_above, '_usdtf_usd_regular' ), 'a sale above the regular price stores no anchor' );
usdtf_it_assert_same( '6000000', usdtf_it_sale( $pg_above ), 'a sale above the regular price keeps the Woo sale price' );
usdtf_it_pass( 'a price that rounds to zero or a sale above the regular price fails without a write' );

// 8. A Woo price edited between planning and saving is a conflict.
$pg_c1 = usdtf_it_make_priced_product( 'Pegged conflict meta', 5000000 )->get_id();
$pg_c2 = usdtf_it_make_priced_product( 'Pegged conflict crud', 5000000 )->get_id();
$pg_sync( array( $pg_c1, $pg_c2 ) );
usdtf_it_assert_same( '15.15', usdtf_it_meta( $pg_c1, '_usdtf_usd_regular' ), 'the conflict fixture has an anchor' );

$pg_rates->save_rate( 360000 );
$pg_preview( array( $pg_c1, $pg_c2 ) );

$GLOBALS['usdtf_it_before_write'] = array();
$pg_edit_mid_run                  = function ( $product_id ) use ( $pg_c1, $pg_c2 ) {
	$GLOBALS['usdtf_it_before_write'][] = (int) $product_id;

	if ( $pg_c1 === (int) $product_id ) {
		update_post_meta( $pg_c1, '_regular_price', '7000000' );
		update_post_meta( $pg_c1, '_price', '7000000' );
	}

	if ( $pg_c2 === (int) $product_id ) {
		$edited = wc_get_product( $pg_c2 );
		$edited->set_regular_price( '7100000' );
		$edited->save();
	}
};
add_action( 'usdtf_before_price_write', $pg_edit_mid_run );
$pg_job = $pg_update( array( $pg_c1, $pg_c2 ) );
remove_action( 'usdtf_before_price_write', $pg_edit_mid_run );

$pg_fired = $GLOBALS['usdtf_it_before_write'];
sort( $pg_fired );
usdtf_it_assert_same( array( $pg_c1, $pg_c2 ), $pg_fired, 'the before-write action fires once per product' );

foreach ( array( $pg_c1 => '7000000', $pg_c2 => '7100000' ) as $pg_id => $pg_edited ) {
	usdtf_it_assert_same( 'conflict', $pg_status( $pg_job, $pg_id ), 'an edit mid run reports a conflict' );
	usdtf_it_assert_same( $pg_edited, usdtf_it_price( $pg_id ), 'the edited Woo price is kept' );
	usdtf_it_assert( '' !== usdtf_it_meta( $pg_id, '_usdtf_conflict' ), 'the conflict is recorded on the product' );
	usdtf_it_assert_same( '15.15', usdtf_it_meta( $pg_id, '_usdtf_usd_regular' ), 'a conflict keeps the anchor' );
	usdtf_it_assert_same( '5000000', usdtf_it_meta( $pg_id, '_usdtf_seen_regular' ), 'a conflict keeps the seen price' );
	usdtf_it_assert_same( '330000', usdtf_it_meta( $pg_id, '_usdtf_rate' ), 'a conflict keeps the stored rate' );
	usdtf_it_assert_same( '5000000', $pg_item( $pg_job, $pg_id )['old_regular'], 'a conflict item keeps the old Woo price' );
	usdtf_it_assert_same( '5000000', $pg_item( $pg_job, $pg_id )['new_regular'], 'a conflict item reports no new Woo price' );
}

usdtf_it_assert_same( 2, $pg_jobs->get( $pg_job )->counters()['conflicts'], 'the job counts two conflicts' );

$pg_job = $pg_sync( array( $pg_c1, $pg_c2 ) );
usdtf_it_assert_same( 'rebased', $pg_status( $pg_job, $pg_c1 ), 'the next run re-bases on the edited price' );
usdtf_it_assert_same( '19.44', usdtf_it_meta( $pg_c1, '_usdtf_usd_regular' ), 'the next run takes the anchor from the edit' );
usdtf_it_assert_same( '', usdtf_it_meta( $pg_c1, '_usdtf_conflict' ), 'the next run clears the conflict' );
usdtf_it_pass( 'a Woo price edited mid run is a conflict and nothing is written' );

// 9. A dry run writes no price and no plugin meta.
$pg_n      = usdtf_it_make_priced_product( 'Pegged preview only', 5000000 )->get_id();
$pg_before = array(
	$pg_n  => get_post_meta( $pg_n ),
	$pg_a  => get_post_meta( $pg_a ),
	$pg_v1 => get_post_meta( $pg_v1 ),
	$pg_v  => get_post_meta( $pg_v ),
);

$GLOBALS['usdtf_it_price_writes'] = 0;
$pg_job                           = $pg_preview( array( $pg_n, $pg_a, $pg_v1 ) );
usdtf_it_assert_same( 'generated', $pg_status( $pg_job, $pg_n ), 'the dry run reports the anchor it would generate' );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_a ), 'the dry run reports the price it would change' );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_v1 ), 'the dry run reports the variation price it would change' );
usdtf_it_assert_same( 1, $pg_jobs->get( $pg_job )->counters()['generated'], 'the dry run counts the generated product' );
usdtf_it_assert_same( 2, $pg_jobs->get( $pg_job )->counters()['changed'], 'the dry run counts the changed product and variation' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'the dry run saves no product' );

foreach ( $pg_before as $pg_id => $pg_meta ) {
	usdtf_it_assert_same( $pg_meta, get_post_meta( $pg_id ), 'the dry run writes no meta' );
}

usdtf_it_assert_same( '', usdtf_it_meta( $pg_n, '_usdtf_usd_regular' ), 'the dry run stores no anchor' );
usdtf_it_pass( 'a dry run writes no price and no plugin meta' );

// 10. A store currency other than Toman or Rial refuses every job.
usdtf_it_set_currency( 'USD' );
$pg_job_count = count( $pg_jobs->query( array( 'limit' => 200 ) ) );
$pg_a_meta    = get_post_meta( $pg_a );

foreach ( array( \USDTF\Job::TYPE_PREVIEW, \USDTF\Job::TYPE_SYNC ) as $pg_type ) {
	$pg_refused = $runner->create_job(
		array(
			'type'  => $pg_type,
			'scope' => array( 'ids' => array( $pg_a ) ),
		)
	);
	usdtf_it_assert_same( 'usdtf_unsupported_currency', is_wp_error( $pg_refused ) ? $pg_refused->get_error_code() : '', 'a ' . $pg_type . ' job is refused for a USD store' );
	usdtf_it_assert_same( 400, $pg_refused->get_error_data()['status'], 'the refusal is a 400' );
}

$pg_result = $pg_recorder->apply(
	wc_get_product( $pg_a ),
	array(
		'rate'     => 360000,
		'rounding' => $pg_round,
	)
);
usdtf_it_assert_same( 'skipped', $pg_result['status'], 'the recorder refuses to price a USD store' );
usdtf_it_assert_same( 'unsupported_currency', $pg_result['code'], 'the recorder names the unsupported currency' );
usdtf_it_assert_same( $pg_job_count, count( $pg_jobs->query( array( 'limit' => 200 ) ) ), 'a refused job leaves no job row' );
usdtf_it_assert_same( $pg_a_meta, get_post_meta( $pg_a ), 'a refused job writes nothing' );
usdtf_it_assert_same( '6000000', usdtf_it_price( $pg_a ), 'a refused job keeps the Woo price' );
usdtf_it_set_currency( 'IRT' );
usdtf_it_pass( 'a store currency other than Toman or Rial refuses every job' );

// A removed regular price skips the product and keeps its anchor.
$pg_gone = usdtf_it_make_priced_product( 'Pegged removed price', 5000000 )->get_id();
$pg_sync( array( $pg_gone ) );
usdtf_it_assert_same( '13.89', usdtf_it_meta( $pg_gone, '_usdtf_usd_regular' ), 'the removed-price fixture has an anchor' );

$pg_set_woo( $pg_gone, '' );
$pg_result = $pg_dry( $pg_gone );
usdtf_it_assert_same( 'skipped', $pg_result['status'], 'a removed regular price skips the product' );
usdtf_it_assert_same( 'no_price', $pg_result['code'], 'a removed regular price has the no_price code' );

$pg_job = $pg_sync( array( $pg_gone ) );
usdtf_it_assert_same( 'skipped', $pg_status( $pg_job, $pg_gone ), 'the job skips a product whose regular price was removed' );
usdtf_it_assert_same( '13.89', usdtf_it_meta( $pg_gone, '_usdtf_usd_regular' ), 'a removed regular price keeps the anchor' );
usdtf_it_assert_same( '5000000', usdtf_it_meta( $pg_gone, '_usdtf_seen_regular' ), 'a removed regular price keeps the seen price' );
usdtf_it_assert_same( '', usdtf_it_price( $pg_gone ), 'a removed regular price is not written back' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'a removed regular price saves no product' );

$pg_set_woo( $pg_gone, 5400000 );
$pg_job = $pg_sync( array( $pg_gone ) );
usdtf_it_assert_same( 'rebased', $pg_status( $pg_job, $pg_gone ), 'a price entered again re-bases' );
usdtf_it_assert_same( '15.00', usdtf_it_meta( $pg_gone, '_usdtf_usd_regular' ), 'a price entered again sets the anchor' );
usdtf_it_assert_same( '5400000', usdtf_it_price( $pg_gone ), 'a price entered again is kept' );
usdtf_it_pass( 'a removed regular price is skipped and keeps its anchor' );

// (a) A hand edit of the sale price only re-bases the sale and leaves the regular price alone.
$pg_m = usdtf_it_make_priced_product( 'Pegged mixed', 5000000, 4000000 )->get_id();
$pg_sync( array( $pg_m ) );
usdtf_it_assert_same( '11.11', usdtf_it_meta( $pg_m, '_usdtf_usd_sale' ), 'the mixed fixture has a sale anchor' );

$pg_set_woo( $pg_m, 5000000, 3600000 );
$pg_job = $pg_sync( array( $pg_m ) );
usdtf_it_assert_same( 'rebased', $pg_status( $pg_job, $pg_m ), 'a sale-only hand edit reports rebased' );
usdtf_it_assert_same( '10.00', usdtf_it_meta( $pg_m, '_usdtf_usd_sale' ), 'a sale-only hand edit re-bases the sale anchor' );
usdtf_it_assert_same( '3600000', usdtf_it_meta( $pg_m, '_usdtf_seen_sale' ), 'a sale-only hand edit is the new seen sale price' );
usdtf_it_assert_same( '13.89', usdtf_it_meta( $pg_m, '_usdtf_usd_regular' ), 'a sale-only hand edit keeps the regular anchor' );
usdtf_it_assert_same( '5000000', usdtf_it_price( $pg_m ), 'a sale-only hand edit keeps the regular price' );
usdtf_it_assert_same( '3600000', usdtf_it_sale( $pg_m ), 'a sale-only hand edit keeps the edited sale price' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'a sale-only hand edit saves no product' );

// (b) Regular follows the rate while a hand-edited sale re-bases: only the regular field is written.
$pg_set_woo( $pg_m, 5000000, 3780000 );
$pg_rates->save_rate( 400000 );
$pg_job = $pg_sync( array( $pg_m ) );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_m ), 'an update next to a sale re-base reports changed' );
usdtf_it_assert_same( '5556000', usdtf_it_price( $pg_m ), 'the regular price follows the rate' );
usdtf_it_assert_same( '3780000', usdtf_it_sale( $pg_m ), 'the hand-edited sale price stays' );
usdtf_it_assert_same( '9.45', usdtf_it_meta( $pg_m, '_usdtf_usd_sale' ), 'the sale anchor re-bases at the new rate' );
usdtf_it_assert_same( '3780000', usdtf_it_meta( $pg_m, '_usdtf_seen_sale' ), 'the hand-edited sale is the seen sale price' );
usdtf_it_assert_same( '5556000', usdtf_it_meta( $pg_m, '_usdtf_seen_regular' ), 'the written regular price is the seen price' );
usdtf_it_assert_same( 1, $GLOBALS['usdtf_it_price_writes'], 'the product is saved once' );

// (c) A sale pushed above a hand-lowered regular price by a rate rise fails and writes nothing.
$pg_up = usdtf_it_make_priced_product( 'Pegged sale overtakes', 5000000, 4800000 )->get_id();
$pg_sync( array( $pg_up ) );
usdtf_it_assert_same( '12.00', usdtf_it_meta( $pg_up, '_usdtf_usd_sale' ), 'the overtaking fixture has a sale anchor' );

$pg_set_woo( $pg_up, 4900000 );
$pg_rates->save_rate( 440000 );
$pg_job = $pg_sync( array( $pg_up ) );
usdtf_it_assert_same( 'failed', $pg_status( $pg_job, $pg_up ), 'a sale pushed above the regular price fails' );
usdtf_it_assert_same( '4900000', usdtf_it_price( $pg_up ), 'the hand-lowered regular price stays' );
usdtf_it_assert_same( '4800000', usdtf_it_sale( $pg_up ), 'the sale price stays' );
usdtf_it_assert_same( '12.50', usdtf_it_meta( $pg_up, '_usdtf_usd_regular' ), 'the regular anchor stays' );
usdtf_it_assert_same( '400000', usdtf_it_meta( $pg_up, '_usdtf_rate' ), 'the stored rate stays' );
usdtf_it_assert_same( $pg_item( $pg_job, $pg_up )['old_regular'], $pg_item( $pg_job, $pg_up )['new_regular'], 'a failed item reports no new regular price' );
usdtf_it_assert_same( $pg_item( $pg_job, $pg_up )['old_sale'], $pg_item( $pg_job, $pg_up )['new_sale'], 'a failed item reports no new sale price' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'a sale pushed above the regular price saves no product' );
usdtf_it_pass( 'mixed regular and sale actions write only the fields that follow the rate' );

// R8: a computed sale equal to the computed regular price fails (WooCommerce would drop it).
$pg_equal = usdtf_it_make_priced_product( 'Pegged sale equal', 5000000, 4997000 )->get_id();
usdtf_plugin()->pricing()->save_state(
	$pg_equal,
	array(
		'usd_regular'  => '18.52',
		'usd_sale'     => '18.51',
		'seen_regular' => '5000000',
		'seen_sale'    => '4997000',
		'rate'         => 270000.0,
	)
);
$pg_equal_meta = get_post_meta( $pg_equal );
$pg_coarse     = array(
	'rounding'  => 'up',
	'increment' => 10000.0,
	'decimals'  => 0,
);

foreach ( array( true, false ) as $pg_dry_run ) {
	$pg_result = $pg_recorder->apply(
		wc_get_product( $pg_equal ),
		array(
			'dry_run'  => $pg_dry_run,
			'rate'     => 300000,
			'rounding' => $pg_coarse,
		)
	);
	usdtf_it_assert_same( 'failed', $pg_result['status'], 'a sale equal to the regular price fails' );
	usdtf_it_assert_same( 'sale_above_regular', $pg_result['code'], 'a sale equal to the regular price has the sale_above_regular code' );
	usdtf_it_assert_same( false, $pg_result['wrote'], 'a sale equal to the regular price is not written' );
	usdtf_it_assert_same( $pg_result['old'], $pg_result['new'], 'a sale equal to the regular price reports the old Woo prices as new' );

	if ( $pg_dry_run ) {
		usdtf_it_assert_same( $pg_equal_meta, get_post_meta( $pg_equal ), 'a dry run with an equal sale writes no meta' );
	}
}

usdtf_it_assert_same( '5000000', usdtf_it_price( $pg_equal ), 'a sale equal to the regular price keeps the regular price' );
usdtf_it_assert_same( '4997000', usdtf_it_sale( $pg_equal ), 'a sale equal to the regular price keeps the sale price' );
usdtf_it_assert_same( '18.51', usdtf_it_meta( $pg_equal, '_usdtf_usd_sale' ), 'a sale equal to the regular price keeps the sale anchor' );
usdtf_it_assert_same( '270000', usdtf_it_meta( $pg_equal, '_usdtf_rate' ), 'a sale equal to the regular price keeps the stored rate' );
usdtf_it_assert( '' !== usdtf_it_meta( $pg_equal, '_usdtf_last_error' ), 'a sale equal to the regular price records an error' );
usdtf_it_pass( 'a computed sale equal to the regular price fails without a write' );

// R9: a hand edit between a completed Preview and its Update re-bases on the edit.
$pg_between = usdtf_it_make_priced_product( 'Pegged edit between jobs', 5000000 )->get_id();
$pg_sync( array( $pg_between ) );
usdtf_it_assert_same( '11.36', usdtf_it_meta( $pg_between, '_usdtf_usd_regular' ), 'the between-jobs fixture has an anchor' );

$pg_rates->save_rate( 480000 );
$pg_job = $pg_preview( array( $pg_between ) );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_between ), 'the Preview plans a rate update' );

$pg_set_woo( $pg_between, 6000000 );
$pg_job = $pg_update( array( $pg_between ) );
usdtf_it_assert_same( 'rebased', $pg_status( $pg_job, $pg_between ), 'an edit after the Preview re-bases in the Update' );
usdtf_it_assert_same( '6000000', usdtf_it_price( $pg_between ), 'the edit made after the Preview survives' );
usdtf_it_assert_same( '12.50', usdtf_it_meta( $pg_between, '_usdtf_usd_regular' ), 'the anchor is recalculated from the edit' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_price_writes'], 'the Update saves no product after an edit between jobs' );
usdtf_it_pass( 'a hand edit between the Preview and the Update re-bases on the edit' );

// 11. Woo prices with decimals round-trip without drifting.
update_option( 'woocommerce_price_num_decimals', 2 );
$pg_settings->update( array( 'increment' => 1 ) );
$pg_rates->save_rate( 25 );
$pg_d = usdtf_it_make_priced_product( 'Pegged decimals', '12.50' )->get_id();

$pg_job = $pg_sync( array( $pg_d ) );
usdtf_it_assert_same( 'generated', $pg_status( $pg_job, $pg_d ), 'a decimal price generates its anchor' );
usdtf_it_assert_same( '0.50', usdtf_it_meta( $pg_d, '_usdtf_usd_regular' ), 'the anchor of a decimal price' );

$pg_job = $pg_sync( array( $pg_d ) );
usdtf_it_assert_same( 'unchanged', $pg_status( $pg_job, $pg_d ), 'a decimal price is unchanged on the second run' );

$pg_rates->save_rate( 30 );
$pg_job = $pg_sync( array( $pg_d ) );
usdtf_it_assert_same( 'changed', $pg_status( $pg_job, $pg_d ), 'a decimal price follows the rate' );
usdtf_it_assert_same( '15.00', usdtf_it_price( $pg_d ), 'the new decimal price keeps the Woo decimals' );
usdtf_it_assert_same( '15.00', usdtf_it_meta( $pg_d, '_usdtf_seen_regular' ), 'the seen decimal price keeps the Woo decimals' );
usdtf_it_pass( 'Woo prices with decimals round-trip without drifting' );

// 12. The impact estimate counts what a job at the candidate rate would act on.
update_option( 'woocommerce_price_num_decimals', 0 );
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();
$pg_rates->save_rate( 270000 );

$pg_gen  = usdtf_it_make_priced_product( 'Estimate generated', 5000000 )->get_id();
$pg_hand = usdtf_it_make_priced_product( 'Estimate hand edited', 5400000 )->get_id();
$pg_out  = usdtf_it_make_priced_product( 'Estimate excluded', 4000000, '', 'excluded' )->get_id();
$pg_low  = usdtf_it_make_priced_product( 'Estimate too low', 100 )->get_id();
$pg_run  = function () {
	$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
	usdtf_it_run_job( (int) $job['id'] );

	return (int) $job['id'];
};

usdtf_it_assert_same( 2, $pg_rates->estimate_affected( 270000, 1 ), 'products without an anchor are affected, an excluded or too cheap one is not' );
usdtf_it_assert_same( 0, $pg_rates->estimate_affected( 0, 1 ), 'a zero rate affects nothing' );

$pg_run();
usdtf_it_assert_same( 0, $pg_rates->estimate_affected( 270000, 1 ), 'synced products at their own rate are not affected' );
usdtf_it_assert_same( 0, $pg_rates->estimate_affected( 270000.0000001, 1 ), 'rates compare at six decimals' );
usdtf_it_assert_same( 2, $pg_rates->estimate_affected( 330000, 1 ), 'a rate change affects every synced product' );

$pg_rates->save_rate( 300000 );
$pg_run();
usdtf_it_assert_same( '5556000', usdtf_it_price( $pg_gen ), 'the synced product follows the rate' );
usdtf_it_assert_same( 0, $pg_rates->estimate_affected( 300000, 1 ), 'the estimate for the active rate is 0 for synced products' );
usdtf_it_assert_same( 2, $pg_rates->estimate_affected( 330000, 1 ), 'the estimate for a new rate is above 0' );
usdtf_it_assert_same( '4000000', usdtf_it_price( $pg_out ), 'an excluded product keeps its price' );
usdtf_it_assert_same( '', usdtf_it_meta( $pg_out, '_usdtf_usd_regular' ), 'an excluded product gets no anchor' );

// A hand edit is affected at the rate it was synced with: a job would re-base it.
$pg_set_woo( $pg_hand, 7000000 );
usdtf_it_assert_same( 1, $pg_rates->estimate_affected( 300000, 1 ), 'a hand-edited product is affected' );

// The sale price counts too: a removed sale clears its anchor.
$pg_sale = usdtf_it_make_priced_product( 'Estimate sale', 6000000, 5000000 )->get_id();
usdtf_it_assert_same( 2, $pg_rates->estimate_affected( 300000, 1 ), 'a new product with a sale price is affected' );
wp_delete_post( $pg_sale, true );

usdtf_it_set_currency( 'USD' );
usdtf_it_assert_same( 0, $pg_rates->estimate_affected( 330000, 1 ), 'an unsupported store currency affects nothing' );
usdtf_it_set_currency( 'IRT' );
usdtf_it_pass( 'the impact estimate counts the products a job would act on' );

// 13. Rollback runs the previous rate through the normal job path.
$pg_rollback = $runner->rollback();
usdtf_it_assert( ! is_wp_error( $pg_rollback ), 'the rollback job is created' );
usdtf_it_assert_same( \USDTF\Job::TYPE_ROLLBACK, $pg_rollback['type'], 'the rollback is a job of its own type' );
usdtf_it_run_job( (int) $pg_rollback['id'] );
$pg_back = (int) $pg_rollback['id'];

usdtf_it_assert_same( 270000.0, $pg_rates->get_rate(), 'the rollback restores the previous rate' );
usdtf_it_assert_same( 'changed', $pg_status( $pg_back, $pg_gen ), 'the rollback recalculates the product from its USD anchor' );
usdtf_it_assert( abs( (float) usdtf_it_price( $pg_gen ) - 5000000 ) <= 1000, 'the original price returns within the rounding increment' );
usdtf_it_assert_same( '270000', usdtf_it_meta( $pg_gen, '_usdtf_rate' ), 'the product carries the restored rate' );
usdtf_it_assert_same( '18.52', usdtf_it_meta( $pg_gen, '_usdtf_usd_regular' ), 'the rollback keeps the USD anchor' );

usdtf_it_assert_same( 'rebased', $pg_status( $pg_back, $pg_hand ), 'a hand edit re-bases instead of being rolled back' );
usdtf_it_assert_same( '7000000', usdtf_it_price( $pg_hand ), 'the hand-edited product keeps its edit' );
usdtf_it_assert_same( '25.93', usdtf_it_meta( $pg_hand, '_usdtf_usd_regular' ), 'the hand edit becomes the anchor at the restored rate' );
usdtf_it_assert_same( '4000000', usdtf_it_price( $pg_out ), 'the rollback leaves an excluded product alone' );
usdtf_it_pass( 'rollback restores the previous rate through the job path and keeps hand edits' );

// 14. The rate typo guard: confirmation above the threshold, none below it.
usdtf_it_reset_plugin_state();
$pg_rates->save_rate( 270000 );

$pg_typo = $pg_rates->submit_rate( '27000', false );
usdtf_it_assert( ! is_wp_error( $pg_typo ), 'the large rate is accepted for review' );
usdtf_it_assert_same( true, ! empty( $pg_typo['requires_confirmation'] ), 'a -90% rate requires confirmation' );
usdtf_it_assert_same( 270000.0, $pg_rates->get_rate(), 'the active rate does not change before confirmation' );
usdtf_it_assert( null !== $pg_rates->get_pending(), 'the pending rate is stored' );
usdtf_it_assert( (int) $pg_typo['affected'] >= 0, 'an estimate of affected products is returned' );

$pg_typo = $pg_rates->submit_rate( '27000', true );
usdtf_it_assert( ! is_wp_error( $pg_typo ), 'confirming the pending rate succeeds' );
usdtf_it_assert_same( 27000.0, $pg_rates->get_rate(), 'the confirmed rate becomes active' );

$pg_rates->save_rate( 270000 );
$pg_typo = $pg_rates->submit_rate( '280000', false );
usdtf_it_assert_same( false, ! empty( $pg_typo['requires_confirmation'] ), 'a move below the threshold needs no confirmation' );
usdtf_it_assert_same( true, $pg_typo['saved'], 'a move below the threshold is saved at once' );
usdtf_it_assert_same( 280000.0, $pg_rates->get_rate(), 'the small move becomes active' );
usdtf_it_pass( 'the rate typo guard asks for confirmation above the threshold only' );

// ---------------------------------------------------------------------------
// Admin screens, REST payloads and the CSV export.
// ---------------------------------------------------------------------------
usdtf_it_set_currency( 'IRT' );
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

require_once ABSPATH . 'wp-admin/includes/screen.php';

$pa_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$pa_user   = $pa_admins ? (int) $pa_admins[0] : 0;
usdtf_it_assert( $pa_user > 0, 'the test site has an administrator' );
wp_set_current_user( $pa_user );

$pa_rates = usdtf_plugin()->rates();
$pa_rates->save_rate( 270000 );

// Run a renderer and fail on any PHP warning, notice or deprecation raised in
// the plugin's own files: WP_DEBUG_DISPLAY is off, so they never reach the HTML.
$pa_quiet = static function ( callable $callback, $label ) {
	$errors = array();

	set_error_handler(
		static function ( $level, $message, $file, $line ) use ( &$errors ) {
			if ( false !== strpos( (string) $file, 'usd-to-toman' ) ) {
				$errors[] = $message . ' in ' . $file . ':' . $line;
			}

			return false;
		}
	);

	ob_start();

	try {
		call_user_func( $callback );
	} finally {
		$html = (string) ob_get_clean();
		restore_error_handler();
	}

	usdtf_it_assert_same( array(), $errors, $label . ' raises no PHP warning' );

	return $html;
};

// Render one tab of the plugin screen and return its HTML.
$pa_admin  = usdtf_plugin()->admin();
$pa_render = static function ( $tab, array $query = array() ) use ( $pa_admin, $pa_quiet ) {
	$previous_get = $_GET;
	$_GET         = array_merge(
		array(
			'page' => 'usdtf',
			'tab'  => $tab,
		),
		$query
	);

	try {
		return $pa_quiet( array( $pa_admin, 'render_page' ), 'the ' . $tab . ' tab' );
	} finally {
		$_GET = $previous_get;
	}
};

// The state payload names the store currency.
$pa_state = usdtf_it_rest_data( usdtf_it_rest( 'GET', '/state' ) );
usdtf_it_assert( isset( $pa_state['store_currency'] ), 'the state payload carries the store currency' );
usdtf_it_assert_same(
	array(
		'code'      => 'IRT',
		'unit'      => 'toman',
		'label'     => 'Toman',
		'supported' => true,
	),
	$pa_state['store_currency'],
	'an IRT store is a supported Toman store'
);

usdtf_it_set_currency( 'USD' );
$pa_state = usdtf_it_rest_data( usdtf_it_rest( 'GET', '/state' ) );
usdtf_it_assert_same( false, $pa_state['store_currency']['supported'], 'a USD store is not supported' );
usdtf_it_assert_same( '', $pa_state['store_currency']['unit'], 'a USD store has no unit' );
usdtf_it_set_currency( 'IRT' );
usdtf_it_pass( 'the state payload names the store currency' );

// A job item carries the USD price and no Toman source.
$pa_product = usdtf_it_make_priced_product( 'Admin pegged', 5000000 )->get_id();
$pa_job     = usdtf_it_create_job( array( 'scope' => array( 'ids' => array( $pa_product ) ) ) );
usdtf_it_run_job( (int) $pa_job['id'] );

$pa_detail = usdtf_it_rest_data( usdtf_it_rest( 'GET', '/jobs/' . (int) $pa_job['id'] ) );
usdtf_it_assert( isset( $pa_detail['items'][0] ), 'the job detail lists its item' );
$pa_item = $pa_detail['items'][0];

usdtf_it_assert_same( '18.52', $pa_item['usd_regular'], 'the item payload carries the USD regular price' );
usdtf_it_assert( array_key_exists( 'usd_sale', $pa_item ), 'the item payload carries the USD sale price' );
usdtf_it_assert( ! array_key_exists( 'toman_regular', $pa_item ), 'the item payload has no Toman regular price' );
usdtf_it_assert( ! array_key_exists( 'toman_sale', $pa_item ), 'the item payload has no Toman sale price' );
usdtf_it_assert( ! array_key_exists( 'revision', $pa_item ), 'the item payload has no source revision' );
foreach ( array( 'old_regular', 'old_sale', 'new_regular', 'new_sale', 'status' ) as $pa_key ) {
	usdtf_it_assert( array_key_exists( $pa_key, $pa_item ), 'the item payload carries ' . $pa_key );
}
usdtf_it_assert_same( 'generated', $pa_item['status'], 'the first run generates the USD price' );
usdtf_it_assert_same( 1, $pa_detail['counters']['generated'], 'the job payload counts the generated item' );
usdtf_it_assert_same( 0, $pa_detail['counters']['rebased'], 'the job payload counts re-based items' );
usdtf_it_pass( 'a job item payload carries the USD price and no Toman source' );

// The CSV export lists the USD price.
$pa_csv = fopen( 'php://memory', 'w+' );
$pa_admin->write_items_csv( $pa_csv, usdtf_plugin()->jobs()->get( (int) $pa_job['id'] ) );
rewind( $pa_csv );
$pa_lines = explode( "\n", trim( (string) stream_get_contents( $pa_csv ) ) );
fclose( $pa_csv );

usdtf_it_assert_same( 'product_id,parent_id,object_type,status,usd_regular,usd_sale,old_price,new_price,attempts,message', $pa_lines[0], 'the CSV header names the USD columns' );
usdtf_it_assert( 0 === strpos( $pa_lines[1], $pa_product . ',0,product,generated,18.52,,5000000,5000000,' ), 'the CSV row carries the USD and Woo prices' );
usdtf_it_pass( 'the CSV export lists the USD price' );

// Every tab renders cleanly; the jobs detail shows the pegged columns and tabs.
foreach ( array( 'dashboard', 'jobs', 'health', 'settings' ) as $pa_tab ) {
	$pa_html = $pa_render( $pa_tab );
	usdtf_it_assert( false !== strpos( $pa_html, 'usdtf-wrap' ), 'the ' . $pa_tab . ' tab renders' );
}

$pa_html = $pa_render( 'dashboard' );
usdtf_it_assert( false !== strpos( $pa_html, 'Toman per 1 USD' ), 'the dashboard labels the rate with the Toman unit' );
usdtf_it_assert( false === strpos( $pa_html, 'mode B' ), 'the dashboard no longer names a currency mode' );

$pa_html = $pa_render( 'jobs', array( 'job' => (string) (int) $pa_job['id'], 'items_status' => 'generated' ) );
foreach ( array( 'Current Woo price', 'USD price', 'New Woo price', 'Details', 'USD set', 'Re-based', 'Updated' ) as $pa_text ) {
	usdtf_it_assert( false !== strpos( $pa_html, $pa_text ), 'the job detail shows ' . $pa_text );
}
usdtf_it_assert( false !== strpos( $pa_html, '18.52' ), 'the job detail shows the USD price' );
usdtf_it_assert( false === strpos( $pa_html, 'Toman source' ), 'the job detail has no Toman source column' );

$pa_html = $pa_render( 'health' );
usdtf_it_assert( false !== strpos( $pa_html, 'Toman (IRT)' ), 'Diagnostics shows the detected store currency' );
$pa_check_ids = wp_list_pluck( usdtf_plugin()->health()->checks(), 'id' );
usdtf_it_assert( in_array( 'store_currency', $pa_check_ids, true ), 'Diagnostics has a store currency check' );
usdtf_it_assert( ! in_array( 'currency_mode', $pa_check_ids, true ), 'Diagnostics has no currency mode check' );
usdtf_it_pass( 'every tab renders and the job detail shows the pegged columns' );

// The Settings screen shows the store currency read-only.
$pa_html = $pa_render( 'settings' );
usdtf_it_assert( false !== strpos( $pa_html, 'Store currency' ), 'the Settings screen shows the store currency' );
usdtf_it_assert( false !== strpos( $pa_html, 'Toman (IRT)' ), 'the Settings screen names the Toman unit' );
usdtf_it_assert( false !== strpos( $pa_html, 'page=wc-settings' ), 'the Settings screen links to the WooCommerce currency setting' );
foreach ( array( 'currency_mode', 'display_toman', 'persian_digits', 'toman_suffix', '[decimals]', 'auto_manage_new_products' ) as $pa_gone ) {
	usdtf_it_assert( false === strpos( $pa_html, $pa_gone ), 'the Settings screen has no ' . $pa_gone . ' field' );
}

usdtf_it_set_currency( 'IRR' );
$pa_html = $pa_render( 'settings' );
usdtf_it_assert( false !== strpos( $pa_html, 'Rial (IRR)' ), 'the Settings screen names the Rial unit' );
usdtf_it_assert( false !== strpos( $pa_html, 'value="100000"' ), 'the Settings screen offers the Rial increments' );
$pa_html = $pa_render( 'dashboard' );
usdtf_it_assert( false !== strpos( $pa_html, 'Rial per 1 USD' ), 'the dashboard labels the rate with the Rial unit' );
usdtf_it_set_currency( 'IRT' );
usdtf_it_pass( 'the Settings screen shows the store currency read-only and follows the unit' );

// An unsupported store currency is announced and the job buttons are disabled.
set_current_screen( 'woocommerce_page_usdtf' );

ob_start();
$pa_admin->render_notices();
$pa_notices = (string) ob_get_clean();
usdtf_it_assert( false === strpos( $pa_notices, 'Set the store currency to Iranian Toman or Rial' ), 'a Toman store gets no currency notice' );

usdtf_it_set_currency( 'USD' );
ob_start();
$pa_admin->render_notices();
$pa_notices = (string) ob_get_clean();
usdtf_it_assert( false !== strpos( $pa_notices, 'Set the store currency to Iranian Toman or Rial' ), 'a USD store gets the currency notice' );

$pa_html = $pa_render( 'dashboard' );
usdtf_it_assert( 1 === preg_match( '#id="usdtf-start-update"[^>]*disabled#', $pa_html ), 'the update button is disabled in a USD store' );
usdtf_it_assert( 1 === preg_match( '#id="usdtf-start-preview"[^>]*disabled#', $pa_html ), 'the dry run button is disabled in a USD store' );
usdtf_it_set_currency( 'IRT' );
$pa_html = $pa_render( 'dashboard' );
usdtf_it_assert( 0 === preg_match( '#id="usdtf-start-update"[^>]*disabled#', $pa_html ), 'the update button is enabled in a Toman store' );
$GLOBALS['current_screen'] = null;
usdtf_it_pass( 'an unsupported store currency is announced and the job buttons are disabled' );

// The product panel: Managed or Excluded, read-only prices, no reference fields.
$pa_panel = new \USDTF\Admin\Product_Panel( usdtf_plugin()->settings(), usdtf_plugin()->pricing(), usdtf_plugin()->products() );

$pa_box = $pa_quiet(
	function () use ( $pa_panel, $pa_product ) {
		$pa_panel->render_meta_box( get_post( $pa_product ) );
	},
	'the product panel'
);

usdtf_it_assert( false !== strpos( $pa_box, 'name="usdtf_mode"' ), 'the panel offers the pricing mode' );
usdtf_it_assert( false !== strpos( $pa_box, 'usdtf_panel_nonce' ), 'the panel carries its nonce' );
usdtf_it_assert( false === strpos( $pa_box, 'usdtf_source' ), 'the panel has no reference price fields' );
usdtf_it_assert( false === strpos( $pa_box, 'value="native"' ), 'the panel offers no native mode' );
usdtf_it_assert( false !== strpos( $pa_box, 'value="managed"' ) && false !== strpos( $pa_box, 'value="excluded"' ), 'the panel offers Managed and Excluded' );
foreach ( array( 'Woo price', 'USD price', 'Rate used', 'Last sync', '18.52', '5,000,000', '270,000' ) as $pa_text ) {
	usdtf_it_assert( false !== strpos( $pa_box, $pa_text ), 'the panel shows ' . $pa_text );
}

$_POST = array(
	'usdtf_panel_nonce' => wp_create_nonce( \USDTF\Admin\Product_Panel::NONCE ),
	'usdtf_mode'        => 'native',
);
$pa_panel->save_product( $pa_product );
usdtf_it_assert_same( 'managed', usdtf_plugin()->pricing()->get_mode( $pa_product ), 'the panel refuses a mode other than Managed or Excluded' );
usdtf_it_assert_same( '', usdtf_it_meta( $pa_product, '_usdtf_mode' ), 'a refused mode stores nothing' );

$_POST['usdtf_mode'] = 'excluded';
$pa_panel->save_product( $pa_product );
usdtf_it_assert_same( 'excluded', usdtf_plugin()->pricing()->get_mode( $pa_product ), 'the panel accepts Excluded' );

$_POST['usdtf_mode'] = 'managed';
$pa_panel->save_product( $pa_product );
usdtf_it_assert_same( 'managed', usdtf_plugin()->pricing()->get_mode( $pa_product ), 'the panel accepts Managed' );
$_POST = array();
delete_transient( 'usdtf_panel_error_' . $pa_user );

// Bulk actions: Managed, Excluded and Recalculate selected.
$pa_actions = $pa_panel->register_bulk_actions( array() );
usdtf_it_assert( isset( $pa_actions['usdtf_enable'], $pa_actions['usdtf_exclude'], $pa_actions['usdtf_recalculate'] ), 'the three bulk actions are registered' );
usdtf_it_assert( ! isset( $pa_actions['usdtf_native'] ), 'the native bulk action is gone' );

$pa_panel->handle_bulk_actions( 'edit.php?post_type=product', 'usdtf_exclude', array( $pa_product ) );
usdtf_it_assert_same( 'excluded', usdtf_plugin()->pricing()->get_mode( $pa_product ), 'the bulk action excludes a product' );
$pa_panel->handle_bulk_actions( 'edit.php?post_type=product', 'usdtf_enable', array( $pa_product ) );
usdtf_it_assert_same( 'managed', usdtf_plugin()->pricing()->get_mode( $pa_product ), 'the bulk action manages a product' );

$pa_redirect = $pa_panel->handle_bulk_actions( 'edit.php?post_type=product', 'usdtf_recalculate', array( $pa_product ) );
usdtf_it_assert( 1 === preg_match( '#usdtf_recalc=queued%3A(\d+)#', $pa_redirect, $pa_match ), 'Recalculate selected queues a job' );
$pa_recalc = usdtf_plugin()->jobs()->get( (int) $pa_match[1] );
usdtf_it_assert( $pa_recalc && \USDTF\Job::TYPE_RECALCULATE === $pa_recalc->type(), 'the queued job is a recalculation' );
usdtf_it_assert_same( array( $pa_product ), array_map( 'intval', $pa_recalc->scope()['ids'] ), 'the recalculation covers the selected products' );
usdtf_it_run_job( $pa_recalc->id() );

// The list column shows the mode and the USD price.
$pa_column = $pa_quiet(
	function () use ( $pa_panel, $pa_product ) {
		$pa_panel->render_column( \USDTF\Admin\Product_Panel::COLUMN, $pa_product );
	},
	'the product list column'
);
usdtf_it_assert( false !== strpos( $pa_column, 'Managed' ) && false !== strpos( $pa_column, '18.52' ), 'the list column shows the mode and the USD price' );
usdtf_it_pass( 'the product panel offers Managed or Excluded, read-only prices and the bulk actions' );

// ---------------------------------------------------------------------------
// The old Toman helpers are gone and the Persian catalogue knows the new labels.
// ---------------------------------------------------------------------------
foreach ( array( 'from_toman', 'from_usd', 'parse_toman', 'format_toman', 'to_persian_digits' ) as $pg_removed ) {
	usdtf_it_assert( ! method_exists( '\USDTF\Calculator', $pg_removed ), 'the obsolete Calculator::' . $pg_removed . '() is removed' );
}

$pg_mo = USDTF_DIR . 'languages/usd-to-toman-price-sync-for-woocommerce-fa_IR.mo';
usdtf_it_assert( load_textdomain( USDTF_SLUG, $pg_mo ), 'the compiled Persian catalogue loads' );

foreach ( array( 'Toman per 1 USD', 'Rial per 1 USD' ) as $pg_label ) {
	$pg_translated = __( $pg_label, USDTF_SLUG ); // phpcs:ignore WordPress.WP.I18n -- Looking up a catalogue entry in a test.
	usdtf_it_assert( $pg_translated !== $pg_label, 'the Persian catalogue translates "' . $pg_label . '"' );
	usdtf_it_assert( 1 === preg_match( '/\p{Arabic}/u', $pg_translated ), 'the translation of "' . $pg_label . '" is Persian text' );
}

unload_textdomain( USDTF_SLUG );
usdtf_it_pass( 'the obsolete Toman helpers are gone and the new labels are translated' );

wp_set_current_user( 0 );
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

if ( false === $pg_decimals ) {
	delete_option( 'woocommerce_price_num_decimals' );
} else {
	update_option( 'woocommerce_price_num_decimals', $pg_decimals );
}

remove_action( 'woocommerce_update_product', $pg_count_write );
remove_action( 'woocommerce_update_product_variation', $pg_count_write );
usdtf_it_reset_plugin_state();

// Leave nothing behind for the legacy scenarios that follow in a full run.
usdtf_it_set_currency( null );
usdtf_it_delete_products();
