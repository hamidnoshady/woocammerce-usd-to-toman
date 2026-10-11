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

// Leave nothing behind for the legacy scenarios that follow in a full run.
usdtf_it_set_currency( null );
usdtf_it_delete_products();
