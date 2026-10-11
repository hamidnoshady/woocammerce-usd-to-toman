<?php
/**
 * Integration suite: real WordPress + real WooCommerce.
 *
 * Usage: USDTF_WP_PATH=/path/to/wordpress php tests/integration/run.php
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- CLI test script.

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT']    = '80';

$usdtf_wp_path = getenv( 'USDTF_WP_PATH' );

if ( ! $usdtf_wp_path && ! empty( $argv[1] ) ) {
	$usdtf_wp_path = $argv[1];
}

if ( ! $usdtf_wp_path ) {
	fwrite( STDERR, "Set USDTF_WP_PATH or pass the WordPress directory as the first argument.\n" );
	exit( 1 );
}

require_once rtrim( $usdtf_wp_path, '/' ) . '/wp-load.php';

require_once __DIR__ . '/lib.php';

usdtf_it_boot();

// Any wp_die() from now on fails the scenario that caused it (see lib.php).
usdtf_it_catch_wp_die();

// Most scenarios drive worker phases directly in this CLI process; keep the
// background queue from running the same jobs behind their back.
require_once __DIR__ . '/isolate.php';

/**
 * Read a product meta value.
 *
 * @param int    $product_id Product ID.
 * @param string $key        Meta key.
 * @return string
 */
function usdtf_it_meta( $product_id, $key ) {
	return (string) get_post_meta( $product_id, $key, true );
}

/**
 * Current WooCommerce price of a product.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function usdtf_it_price( $product_id ) {
	$product = wc_get_product( $product_id );

	return $product ? (string) $product->get_regular_price( 'edit' ) : '';
}

/**
 * Current WooCommerce sale price.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function usdtf_it_sale( $product_id ) {
	$product = wc_get_product( $product_id );

	return $product ? (string) $product->get_sale_price( 'edit' ) : '';
}

/**
 * Item statuses of a job.
 *
 * @param int $job_id Job ID.
 * @return array
 */
function usdtf_it_item_statuses( $job_id ) {
	$statuses = array();

	foreach ( usdtf_plugin()->jobs()->items( $job_id, array( 'limit' => 200 ) ) as $item ) {
		$statuses[ (int) $item['object_id'] ] = (string) $item['status'];
	}

	return $statuses;
}

echo "== USD to Toman integration suite ==\n";

usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$settings = usdtf_plugin()->settings();
$rates    = usdtf_plugin()->rates();
$runner   = usdtf_plugin()->runner();
$pricing  = usdtf_plugin()->pricing();
$jobs     = usdtf_plugin()->jobs();

// ---------------------------------------------------------------------------
// The USD-pegged model. USDTF_IT_ONLY=pegged runs just these scenarios. The
// store guard registered its shutdown handler in usdtf_it_boot(), so the early
// exit still restores the store.
// ---------------------------------------------------------------------------
require __DIR__ . '/pegged.php';

if ( 'pegged' === getenv( 'USDTF_IT_ONLY' ) ) {
	echo "\nAll pegged integration scenarios passed.\n";
	exit( 0 );
}

// ---------------------------------------------------------------------------
// The legacy scenarios, ported to the USD-pegged model. They run on a Toman
// store priced without decimals; the worker scripts and the test web server
// read the same WooCommerce options from the database.
// ---------------------------------------------------------------------------
usdtf_it_use_toman_store();

// ---------------------------------------------------------------------------
// 1. The rounding examples from the issue.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$laptop_a = usdtf_it_make_simple_product( 'Laptop A', 5000000 );
$laptop_b = usdtf_it_make_simple_product( 'Laptop B', 5400000 );
$laptop_c = usdtf_it_make_simple_product( 'Laptop C', 5410000 );

// The first update only generates the USD prices from the Woo prices.
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_assert( ! is_wp_error( $job ), 'the update job should be created' );
usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '18.52', usdtf_it_meta( $laptop_a->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), '5,000,000 / 270,000 must give 18.52 USD' );
usdtf_it_assert_same( '20.00', usdtf_it_meta( $laptop_b->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), '5,400,000 / 270,000 must give 20.00 USD' );
usdtf_it_assert_same( '20.04', usdtf_it_meta( $laptop_c->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), '5,410,000 / 270,000 must give 20.04 USD' );
usdtf_it_assert_same( '5000000', usdtf_it_price( $laptop_a->get_id() ), 'generating the USD price must leave the Woo price alone' );
usdtf_it_assert_same( 3, (int) $jobs->get( (int) $job['id'] )->data['generated'], 'the first update must report three generated USD prices' );

// A new rate rewrites the Woo prices from the USD prices, rounded up to 1,000 Toman.
$rates->save_rate( 297000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '5501000', usdtf_it_price( $laptop_a->get_id() ), '18.52 USD x 297,000 must round up to 5,501,000' );
usdtf_it_assert_same( '5940000', usdtf_it_price( $laptop_b->get_id() ), '20.00 USD x 297,000 must stay 5,940,000' );
usdtf_it_assert_same( '5952000', usdtf_it_price( $laptop_c->get_id() ), '20.04 USD x 297,000 must round up to 5,952,000' );
usdtf_it_assert_same( '18.52', usdtf_it_meta( $laptop_a->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), 'the USD price must be preserved' );
usdtf_it_assert_same( '297000', usdtf_it_meta( $laptop_a->get_id(), \USDTF\Product_Pricing::META_RATE ), 'the rate used must be stored per product' );
usdtf_it_pass( 'rounding example from the issue (5,000,000 Toman → $19)' );

$finished = $jobs->get( (int) $job['id'] );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $finished->status(), 'the job must complete' );
usdtf_it_assert_same( 3, (int) $finished->data['changed'], 'three products must be reported as changed' );

// The progress screen reads the product the worker was busy with.
usdtf_it_assert( '' !== (string) $finished->data['current_item'], 'the job must remember the last product it processed' );
usdtf_it_assert(
	false !== strpos( (string) $finished->data['current_item'], 'Laptop C' ),
	'the remembered product must be one of the processed products'
);

// The item list is what the job detail screen and the CSV export use.
$changed_items = $jobs->items( (int) $job['id'], array( 'status' => \USDTF\Job_Repository::ITEM_CHANGED, 'limit' => 2, 'page' => 1 ) );
usdtf_it_assert_same( 2, count( $changed_items ), 'the status filter and the page size must be applied' );

foreach ( $changed_items as $changed_item ) {
	usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_CHANGED, (string) $changed_item['status'], 'only the requested status may be returned' );
}

$second_page = $jobs->items( (int) $job['id'], array( 'status' => \USDTF\Job_Repository::ITEM_CHANGED, 'limit' => 2, 'page' => 2 ) );
usdtf_it_assert_same( 1, count( $second_page ), 'the second page must hold the remaining row' );
usdtf_it_assert(
	(int) $second_page[0]['id'] !== (int) $changed_items[0]['id'],
	'paging must not repeat the first page'
);

// Unknown settings fall back to the value the caller asked for.
usdtf_it_assert_same( 'fallback', $settings->get( 'usdtf_unknown_setting', 'fallback' ), 'an unknown setting must return the fallback' );
usdtf_it_pass( 'job state exposes the live item and the item query filters and pages' );

// ---------------------------------------------------------------------------
// 2. A dry run never writes.
// ---------------------------------------------------------------------------
$rates->save_rate( 300000 );

$preview = $runner->preview( array() );
usdtf_it_assert( ! is_wp_error( $preview ), 'the dry run job should be created' );
usdtf_it_run_job( (int) $preview['id'] );

usdtf_it_assert_same( '5501000', usdtf_it_price( $laptop_a->get_id() ), 'a dry run must not change a price' );
usdtf_it_assert_same( '297000', usdtf_it_meta( $laptop_a->get_id(), \USDTF\Product_Pricing::META_RATE ), 'a dry run must not store the new rate' );

$preview_job = $jobs->get( (int) $preview['id'] );
usdtf_it_assert_same( 3, (int) $preview_job->data['changed'], 'the dry run must report the three products that would change' );
usdtf_it_pass( 'dry run reports changes without writing them' );

// The real update applies the new rate and recalculates from the USD price.
$update = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $update['id'] );
usdtf_it_assert_same( '5556000', usdtf_it_price( $laptop_a->get_id() ), '18.52 USD x 300,000 must be 5,556,000' );

// ---------------------------------------------------------------------------
// 3. No unnecessary writes: the same rate again changes nothing.
// ---------------------------------------------------------------------------
$before = get_post_modified_time( 'Y-m-d H:i:s', true, $laptop_a->get_id(), true );

$second = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $second['id'] );

$second_job = $jobs->get( (int) $second['id'] );
usdtf_it_assert_same( 0, (int) $second_job->data['changed'], 'a second identical update must not change any price' );
usdtf_it_assert_same( 3, (int) $second_job->data['unchanged'], 'the products must be reported as unchanged' );
usdtf_it_assert_same( $before, get_post_modified_time( 'Y-m-d H:i:s', true, $laptop_a->get_id(), true ), 'an unchanged product must not be saved again' );
usdtf_it_pass( 'unchanged prices are not rewritten' );

// A tiny rate change that does not move any rounded price: at 299,990 the USD
// prices 18.52, 20.00 and 20.04 still round up to 5,556,000, 6,000,000 and 6,012,000.
$GLOBALS['usdtf_it_legacy_writes'] = 0;
$usdtf_it_count_writes             = function () {
	++$GLOBALS['usdtf_it_legacy_writes'];
};
add_action( 'woocommerce_update_product', $usdtf_it_count_writes );

$rates->save_rate( 299990 );
$third = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $third['id'] );
$third_job = $jobs->get( (int) $third['id'] );
usdtf_it_assert_same( 0, (int) $third_job->data['changed'], 'a rate change that keeps every rounded price must not write' );
usdtf_it_assert_same( 0, $GLOBALS['usdtf_it_legacy_writes'], 'a rate change that keeps every rounded price must not save a product' );
usdtf_it_assert_same( '6012000', usdtf_it_price( $laptop_c->get_id() ), 'the rounded price must stay where it was' );
usdtf_it_assert_same( '299990', usdtf_it_meta( $laptop_c->get_id(), \USDTF\Product_Pricing::META_RATE ), 'the product must still record the rate it was checked against' );
remove_action( 'woocommerce_update_product', $usdtf_it_count_writes );

$rates->save_rate( 270000 );
$fourth = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $fourth['id'] );
usdtf_it_pass( 'rate changes that do not move a price cause zero writes' );

// ---------------------------------------------------------------------------
// 4. Sale prices, scheduled sales and variable products.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$sale_product = usdtf_it_make_simple_product( 'Mouse', 900000, 400000 );
$job          = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '3.33', usdtf_it_meta( $sale_product->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), '900,000 / 270,000 must give a 3.33 USD regular price' );
usdtf_it_assert_same( '1.48', usdtf_it_meta( $sale_product->get_id(), \USDTF\Product_Pricing::META_USD_SALE ), '400,000 / 270,000 must give a 1.48 USD sale price' );

$rates->save_rate( 297000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '990000', usdtf_it_price( $sale_product->get_id() ), '3.33 USD x 297,000 must round up to 990,000' );
usdtf_it_assert_same( '440000', usdtf_it_sale( $sale_product->get_id() ), '1.48 USD x 297,000 must round up to 440,000' );
usdtf_it_assert_same( '3.33', usdtf_it_meta( $sale_product->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), 'the USD regular price must be preserved' );
usdtf_it_assert_same( '1.48', usdtf_it_meta( $sale_product->get_id(), \USDTF\Product_Pricing::META_USD_SALE ), 'the USD sale price must be preserved' );

// A scheduled sale keeps its dates: only the price fields are written.
$scheduled         = usdtf_it_make_simple_product( 'Scheduled sale', 2000000, 1000000 );
$scheduled_product = wc_get_product( $scheduled->get_id() );
$scheduled_product->set_date_on_sale_from( gmdate( 'Y-m-d', time() + 86400 ) );
$scheduled_product->set_date_on_sale_to( gmdate( 'Y-m-d', time() + 172800 ) );
$scheduled_product->save();

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$rates->save_rate( 300000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$scheduled_product = wc_get_product( $scheduled->get_id() );
usdtf_it_assert_same( '2019000', (string) $scheduled_product->get_regular_price( 'edit' ), '2,000,000 / 297,000 = 6.73 USD x 300,000 must be the regular price' );
usdtf_it_assert_same( '1011000', (string) $scheduled_product->get_sale_price( 'edit' ), '1,000,000 / 297,000 = 3.37 USD x 300,000 must be the sale price' );
usdtf_it_assert_same(
	gmdate( 'Y-m-d', time() + 86400 ),
	$scheduled_product->get_date_on_sale_from( 'edit' ) ? $scheduled_product->get_date_on_sale_from( 'edit' )->date( 'Y-m-d' ) : '',
	'a scheduled sale keeps its start date'
);
usdtf_it_assert_same(
	gmdate( 'Y-m-d', time() + 172800 ),
	$scheduled_product->get_date_on_sale_to( 'edit' ) ? $scheduled_product->get_date_on_sale_to( 'edit' )->date( 'Y-m-d' ) : '',
	'a scheduled sale keeps its end date'
);
usdtf_it_pass( 'scheduled sale dates survive a synchronization' );

// Removing the sale price in WooCommerce clears the USD sale price, and a later
// rate change never brings the sale back.
$sale_ended = wc_get_product( $sale_product->get_id() );
$sale_ended->set_sale_price( '' );
$sale_ended->save();

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '', usdtf_it_meta( $sale_product->get_id(), \USDTF\Product_Pricing::META_USD_SALE ), 'removing the sale price must clear the USD sale price' );

$rates->save_rate( 270000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '', usdtf_it_sale( $sale_product->get_id() ), 'a cleared sale price must stay cleared in WooCommerce' );
usdtf_it_assert_same( '900000', usdtf_it_price( $sale_product->get_id() ), 'the regular price must still follow its USD price' );
usdtf_it_pass( 'sale prices are derived and cleared from the source' );

// A variable product with 25 variations (more than one slice of 20).
$variations = array();

for ( $i = 1; $i <= 25; $i++ ) {
	$variations[] = array( 1000000 * $i, '' );
}

$variable = usdtf_it_make_variable_product( 'Variable Laptop', $variations );

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_assert( ! is_wp_error( $job ), 'the variable product update should be created' );
usdtf_it_run_job( (int) $job['id'] );

$parent = wc_get_product( $variable->get_id() );
usdtf_it_assert_same( '3.70', usdtf_it_meta( $parent->get_children()[0], \USDTF\Product_Pricing::META_USD_REGULAR ), 'the first variation must get its USD price' );
usdtf_it_assert_same( '92.59', usdtf_it_meta( $parent->get_children()[24], \USDTF\Product_Pricing::META_USD_REGULAR ), 'the twenty-fifth variation must get its USD price' );

$rates->save_rate( 300000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$variable_job = $jobs->get( (int) $job['id'] );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $variable_job->status(), 'the variable product job must complete' );
usdtf_it_assert( (int) $variable_job->data['variations_processed'] >= 25, 'every variation must be processed' );

$parent = wc_get_product( $variable->get_id() );
usdtf_it_assert( $parent->is_type( 'variable' ), 'the parent must stay a variable product' );

$first_variation = wc_get_product( $parent->get_children()[0] );
usdtf_it_assert_same( '1110000', (string) $first_variation->get_regular_price( 'edit' ), 'the first variation must be converted' );

$twenty_fifth = wc_get_product( $parent->get_children()[24] );
usdtf_it_assert_same( '27777000', (string) $twenty_fifth->get_regular_price( 'edit' ), '92.59 USD x 300,000 must be 27,777,000' );

$parent_range = array_map( 'floatval', get_post_meta( $variable->get_id(), '_price', false ) );
usdtf_it_assert_same( array( 1110000.0, 27777000.0 ), array( min( $parent_range ), max( $parent_range ) ), 'the parent price range must be recalculated' );

usdtf_it_pass( 'variable products are processed in slices and the parent range is recalculated' );

// ---------------------------------------------------------------------------
// 5. Conflict protection: an edit during the job must win.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$conflict_product = usdtf_it_make_simple_product( 'Conflict Product', 5000000 );

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$rates->save_rate( 300000 );
$job    = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$job_id = (int) $job['id'];

// Discovery queues the item.
$runner->handle_discovery( $job_id );

// An admin edits the Woo price while the worker is busy with the product.
$usdtf_it_edit_mid_job = function ( $product_id ) use ( $conflict_product ) {
	if ( (int) $conflict_product->get_id() === (int) $product_id ) {
		$edited = wc_get_product( $product_id );
		$edited->set_regular_price( '6000000' );
		$edited->save();
	}
};
add_action( 'usdtf_before_price_write', $usdtf_it_edit_mid_job );

// The worker now processes the item.
$runner->handle_batch( $job_id );
remove_action( 'usdtf_before_price_write', $usdtf_it_edit_mid_job );

$statuses = usdtf_it_item_statuses( $job_id );
usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_CONFLICT, $statuses[ $conflict_product->get_id() ], 'an edited product must be reported as a conflict' );
usdtf_it_assert_same( '6000000', usdtf_it_price( $conflict_product->get_id() ), 'a conflicted product must not be overwritten' );
usdtf_it_assert( '' !== usdtf_it_meta( $conflict_product->get_id(), \USDTF\Product_Pricing::META_CONFLICT ), 'the conflict marker must be stored on the product' );

usdtf_it_run_job( $job_id );
$conflict_job = $jobs->get( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED_WITH_ERRORS, $conflict_job->status(), 'a job with conflicts must report completed_with_errors' );

// Reviewing the conflicts and recalculating them resolves it: the edit becomes the new USD price.
$runner->recalculate_conflicts( $job_id );
usdtf_it_run_job( $job_id );
usdtf_it_assert_same( '6000000', usdtf_it_price( $conflict_product->get_id() ), 'the edited price must survive the recalculation of the conflict' );
usdtf_it_assert_same( '20.00', usdtf_it_meta( $conflict_product->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), '6,000,000 / 300,000 must be the USD price after the conflict is recalculated' );
usdtf_it_assert_same( '', usdtf_it_meta( $conflict_product->get_id(), \USDTF\Product_Pricing::META_CONFLICT ), 'the conflict marker must be cleared after recalculation' );
usdtf_it_pass( 'concurrent product edits are protected and can be recalculated' );

// ---------------------------------------------------------------------------
// 6. Invalid and excluded products are skipped, never fatal.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$valid = usdtf_it_make_simple_product( 'Valid product', 2700000 );

// A stored pair where the sale price is above the regular price (for example
// after a bad import; WooCommerce itself would drop it on save): the job must
// report it and write nothing, never crash.
$invalid = usdtf_it_make_simple_product( 'Invalid sale', 1000000 );
update_post_meta( $invalid->get_id(), '_sale_price', '2000000' );
$zero = usdtf_it_make_simple_product( 'Zero price', '' );
// A product saved with the retired native mode is excluded.
$native   = usdtf_it_make_simple_product( 'Native USD product', 49, '', 'native' );
$excluded = usdtf_it_make_simple_product( 'Excluded product', 3000000, '', \USDTF\Product_Pricing::MODE_EXCLUDED );

$trashed = usdtf_it_make_simple_product( 'Trashed product', 4000000 );
wp_trash_post( $trashed->get_id() );

// The first update generates the USD prices, the second one moves every price it may.
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
$rates->save_rate( 300000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$statuses = usdtf_it_item_statuses( (int) $job['id'] );
usdtf_it_assert_same( '3000000', usdtf_it_price( $valid->get_id() ), 'a valid product next to them must still be updated' );
usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_FAILED, $statuses[ $invalid->get_id() ], 'a sale price above the regular price must be reported as failed' );
usdtf_it_assert_same( '1000000', usdtf_it_price( $invalid->get_id() ), 'the invalid product must keep its price' );
usdtf_it_assert_same( '', usdtf_it_meta( $invalid->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), 'the invalid product must get no USD price' );
usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_SKIPPED, $statuses[ $zero->get_id() ], 'a product without any price must be skipped' );
usdtf_it_assert_same( '', usdtf_it_price( $zero->get_id() ), 'a product without any price must keep no price' );
usdtf_it_assert_same( '49', usdtf_it_price( $native->get_id() ), 'a product saved with the native mode must never be touched' );
usdtf_it_assert_same( '3000000', usdtf_it_price( $excluded->get_id() ), 'an excluded product must never be touched' );
usdtf_it_assert_same( '', usdtf_it_meta( $excluded->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), 'an excluded product must get no USD price' );
usdtf_it_assert_same( '4000000', usdtf_it_price( $trashed->get_id() ), 'trashed products must be ignored' );
usdtf_it_assert( ! isset( $statuses[ $trashed->get_id() ] ), 'a trashed product must not be queued' );

$job_data = $jobs->get( (int) $job['id'] );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED_WITH_ERRORS, $job_data->status(), 'skipped products are reported without failing the job' );
usdtf_it_pass( 'invalid, native and excluded products are skipped safely' );

// ---------------------------------------------------------------------------
// 7. Selective scopes.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$category_a = usdtf_it_term( 'Category A' );
$category_b = usdtf_it_term( 'Category B' );

$in_category_a = usdtf_it_make_simple_product( 'Category A product', 2700000 );
wp_set_object_terms( $in_category_a->get_id(), array( (int) $category_a ), 'product_cat' );

$in_category_b = usdtf_it_make_simple_product( 'Category B product', 2700000 );
wp_set_object_terms( $in_category_b->get_id(), array( (int) $category_b ), 'product_cat' );

// Both products get their USD price first; the scoped jobs then run at a new rate.
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
$rates->save_rate( 300000 );

$job = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_SYNC,
		'scope' => array(
			'category' => array( (int) $category_a ),
			'label'    => 'Category A',
		),
	)
);
usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '3000000', usdtf_it_price( $in_category_a->get_id() ), 'the product in scope must be updated' );
usdtf_it_assert_same( '2700000', usdtf_it_price( $in_category_b->get_id() ), 'the product outside the scope must stay untouched' );

$job = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_SYNC,
		'scope' => array(
			'ids'   => array( $in_category_b->get_id() ),
			'label' => 'Selected',
		),
	)
);
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '3000000', usdtf_it_price( $in_category_b->get_id() ), 'a selected product must be updated' );

$job = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_SYNC,
		'scope' => array(
			'only_outdated' => true,
			'label'         => 'Outdated only',
		),
	)
);
usdtf_it_run_job( (int) $job['id'] );
$outdated_job = $jobs->get( (int) $job['id'] );
usdtf_it_assert_same( 0, (int) $outdated_job->data['changed'], 'the outdated scope must find nothing after a full update' );
usdtf_it_pass( 'category, selection and outdated scopes work' );

// ---------------------------------------------------------------------------
// 8. One job at a time, pause/resume/cancel.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

for ( $i = 1; $i <= 6; $i++ ) {
	usdtf_it_make_simple_product( 'Job product ' . $i, 270000 * $i );
}

$job    = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$job_id = (int) $job['id'];

$second = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_assert( is_wp_error( $second ), 'a second write job must be rejected while one is queued' );
usdtf_it_assert_same( 'usdtf_job_running', $second->get_error_code(), 'the rejection must use the documented error code' );

// The rejection carries the running job so the screen can show its rate and
// progress with the pause and cancel controls instead of a dead end.
$rejected = $second->get_error_data();
usdtf_it_assert( is_array( $rejected ) && ! empty( $rejected['job'] ), 'the rejection must include the running job' );
usdtf_it_assert_same( $job_id, (int) $rejected['job']['id'], 'the included job must be the running one' );
usdtf_it_assert_same( 270000.0, (float) $rejected['job']['rate'], 'the included job must expose the rate it uses' );
usdtf_it_assert( isset( $rejected['job']['progress'] ), 'the included job must expose its progress' );

$preview_ok = $runner->preview( array() );
usdtf_it_assert( ! is_wp_error( $preview_ok ), 'a dry run must still be allowed' );
usdtf_it_run_job( (int) $preview_ok['id'] );

$runner->handle_discovery( $job_id );
$runner->pause( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_PAUSED, $jobs->get( $job_id )->status(), 'a job must be pausable' );

$runner->handle_batch( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_PAUSED, $jobs->get( $job_id )->status(), 'a paused job must not make progress' );

$runner->resume( $job_id );
usdtf_it_run_job( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $jobs->get( $job_id )->status(), 'a resumed job must finish' );

$job    = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$job_id = (int) $job['id'];
$runner->handle_discovery( $job_id );
$runner->cancel( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_CANCELLED, $jobs->get( $job_id )->status(), 'a job must be cancellable' );
usdtf_it_pass( 'single job lock, pause, resume and cancel' );

// ---------------------------------------------------------------------------
// 9. Rate typo protection.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$result = $rates->submit_rate( '27000', false );
usdtf_it_assert( ! is_wp_error( $result ), 'the large rate must be accepted for review' );
usdtf_it_assert( ! empty( $result['requires_confirmation'] ), 'a -90% rate must require confirmation' );
usdtf_it_assert_same( 270000.0, $rates->get_rate(), 'the active rate must not change before confirmation' );
usdtf_it_assert( null !== $rates->get_pending(), 'the pending rate must be stored' );
usdtf_it_assert( (int) $result['affected'] >= 0, 'an estimate of affected products must be returned' );

$result = $rates->submit_rate( '27000', true );
usdtf_it_assert( ! is_wp_error( $result ), 'confirming the pending rate must succeed' );
usdtf_it_assert_same( 27000.0, $rates->get_rate(), 'the confirmed rate must become active' );

$invalid = $rates->submit_rate( '0', false );
usdtf_it_assert( is_wp_error( $invalid ), 'a zero rate must be rejected' );

$invalid = $rates->submit_rate( 'abc', false );
usdtf_it_assert( is_wp_error( $invalid ), 'a non numeric rate must be rejected' );

$invalid = $rates->submit_rate( '-5000', false );
usdtf_it_assert( is_wp_error( $invalid ), 'a negative rate must be rejected' );

$unchanged = $rates->submit_rate( '27000', false );
usdtf_it_assert_same( false, (bool) $unchanged['saved'], 'resubmitting the same rate must not create a history entry' );
usdtf_it_pass( 'rate typo protection and confirmation flow' );

// ---------------------------------------------------------------------------
// 10. Rollback.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$product = usdtf_it_make_simple_product( 'Rollback product', 5400000 );

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '20.00', usdtf_it_meta( $product->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), 'the first rate must produce 20.00 USD' );
usdtf_it_assert_same( '5400000', usdtf_it_price( $product->get_id() ), 'the first rate must keep the Woo price' );

$rates->save_rate( 400000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '8000000', usdtf_it_price( $product->get_id() ), '20.00 USD x 400,000 must be 8,000,000' );

$rollback = $runner->rollback();
usdtf_it_assert( ! is_wp_error( $rollback ), 'the rollback job must be created' );
usdtf_it_run_job( (int) $rollback['id'] );

usdtf_it_assert_same( 270000.0, $rates->get_rate(), 'the rollback must restore the previous rate' );
usdtf_it_assert_same( '5400000', usdtf_it_price( $product->get_id() ), 'the rollback must recalculate the price from the USD price' );
usdtf_it_assert_same( '20.00', usdtf_it_meta( $product->get_id(), \USDTF\Product_Pricing::META_USD_REGULAR ), 'the rollback must keep the USD price' );
usdtf_it_pass( 'rollback restores the previous rate and recalculates from source' );

// ---------------------------------------------------------------------------
// 12. Crash recovery.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$recovery_products = array();

for ( $i = 1; $i <= 4; $i++ ) {
	$recovery_products[] = usdtf_it_make_simple_product( 'Recovery product ' . $i, 270000 * $i );
}

// The USD prices 1.00 to 4.00 are generated first; the interrupted job then writes a new rate.
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
$rates->save_rate( 300000 );

$job    = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$job_id = (int) $job['id'];
$runner->handle_discovery( $job_id );
$runner->handle_batch( $job_id );

// Simulate a worker that died in the middle of the job.
global $wpdb;
$wpdb->update( \USDTF\Database::jobs_table(), array( 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 900 ) ), array( 'id' => $job_id ) );
$wpdb->update( \USDTF\Database::items_table(), array( 'status' => \USDTF\Job_Repository::ITEM_PROCESSING ), array( 'job_id' => $job_id ) );

$recovered = $runner->recover_stale_jobs();
usdtf_it_assert( $recovered >= 1, 'a job with a dead worker must be recovered' );

$recovered_job = $jobs->get( $job_id );
usdtf_it_assert( $recovered_job->is_active(), 'the recovered job must be active again' );

$runner->resume( $job_id );
$runner->handle_batch( $job_id );
usdtf_it_assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \USDTF\Database::items_table() . ' WHERE job_id = %d AND status = %s', $job_id, \USDTF\Job_Repository::ITEM_PROCESSING ) ), 'items left in processing must be picked up again' );

usdtf_it_run_job( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $jobs->get( $job_id )->status(), 'the recovered job must finish without restarting the catalog' );

$finished_items = 0;

foreach ( array( \USDTF\Job_Repository::ITEM_CHANGED, \USDTF\Job_Repository::ITEM_GENERATED, \USDTF\Job_Repository::ITEM_REBASED, \USDTF\Job_Repository::ITEM_UNCHANGED, \USDTF\Job_Repository::ITEM_SKIPPED, \USDTF\Job_Repository::ITEM_FAILED, \USDTF\Job_Repository::ITEM_CONFLICT ) as $item_status ) {
	$totals          = $jobs->item_totals( $job_id );
	$finished_items += isset( $totals[ $item_status ] ) ? (int) $totals[ $item_status ] : 0;
}

usdtf_it_assert_same( 4, $finished_items, 'every product must end in a final state after the recovery' );
usdtf_it_assert_same( 4, (int) $jobs->get( $job_id )->data['processed'], 'the job must not restart from zero' );

// The retried items were already written by the first batch, so they must be
// recognised as unchanged instead of being written a second time.
foreach ( $recovery_products as $index => $recovery_product ) {
	usdtf_it_assert_same( (string) ( 300000 * ( $index + 1 ) ), usdtf_it_price( $recovery_product->get_id() ), 'the recovered product price must be correct' );
}
usdtf_it_pass( 'interrupted jobs resume from their saved progress' );

// ---------------------------------------------------------------------------
// 13. Products deleted while the job runs.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$product = usdtf_it_make_simple_product( 'Disappearing product', 2700000 );
$job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$job_id  = (int) $job['id'];
$runner->handle_discovery( $job_id );
wp_delete_post( $product->get_id(), true );
$runner->handle_batch( $job_id );

$statuses = usdtf_it_item_statuses( $job_id );
usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_SKIPPED, $statuses[ $product->get_id() ], 'a deleted product must be skipped' );

// The finalize step runs in its own queued request, exactly like the batches.
usdtf_it_run_job( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $jobs->get( $job_id )->status(), 'the job must finish normally' );
usdtf_it_pass( 'products deleted mid-job are skipped' );

require __DIR__ . '/extra.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/audit.php';

echo "\nAll integration scenarios passed.\n";
