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
// 1. The rounding examples from the issue.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$laptop_a = usdtf_it_make_simple_product( 'Laptop A', 5000000 );
$laptop_b = usdtf_it_make_simple_product( 'Laptop B', 5400000 );
$laptop_c = usdtf_it_make_simple_product( 'Laptop C', 5410000 );

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_assert( ! is_wp_error( $job ), 'the update job should be created' );
usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '19', usdtf_it_price( $laptop_a->get_id() ), '5,000,000 / 270,000 must round up to 19 USD' );
usdtf_it_assert_same( '20', usdtf_it_price( $laptop_b->get_id() ), '5,400,000 / 270,000 must stay 20 USD' );
usdtf_it_assert_same( '21', usdtf_it_price( $laptop_c->get_id() ), '5,410,000 / 270,000 must round up to 21 USD' );
usdtf_it_assert_same( '5000000', usdtf_it_meta( $laptop_a->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'the canonical Toman price must be preserved' );
usdtf_it_assert_same( '270000', usdtf_it_meta( $laptop_a->get_id(), \USDTF\Product_Pricing::META_RATE ), 'the rate used must be stored per product' );
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

usdtf_it_assert_same( '19', usdtf_it_price( $laptop_a->get_id() ), 'a dry run must not change a price' );

$preview_job = $jobs->get( (int) $preview['id'] );
usdtf_it_assert_same( 3, (int) $preview_job->data['changed'], 'the dry run must report the three products that would change' );
usdtf_it_pass( 'dry run reports changes without writing them' );

// The real update applies the new rate and recalculates from the Toman source.
$update = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $update['id'] );
usdtf_it_assert_same( '17', usdtf_it_price( $laptop_a->get_id() ), 'ceil(5,000,000 / 300,000) must be 17 USD' );

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

// A tiny rate change that does not move any rounded price:
// 300,300 keeps 5,000,000 → $17, 5,400,000 → $18 and 5,410,000 → $19.
$rates->save_rate( 300300 );
$third = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $third['id'] );
$third_job = $jobs->get( (int) $third['id'] );
usdtf_it_assert_same( 0, (int) $third_job->data['changed'], 'a rate change that keeps every rounded price must not write' );

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

usdtf_it_assert_same( '4', usdtf_it_price( $sale_product->get_id() ), '900,000 / 270,000 must round up to 4 USD' );
usdtf_it_assert_same( '2', usdtf_it_sale( $sale_product->get_id() ), '400,000 / 270,000 must round up to 2 USD' );
usdtf_it_assert_same( '900000', usdtf_it_meta( $sale_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'the Toman regular price must be preserved' );
usdtf_it_assert_same( '400000', usdtf_it_meta( $sale_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_SALE ), 'the Toman sale price must be preserved' );

// A scheduled sale keeps its dates: only the price fields are written.
$scheduled         = usdtf_it_make_simple_product( 'Scheduled sale', 2000000, 1000000 );
$scheduled_product = wc_get_product( $scheduled->get_id() );
$scheduled_product->set_date_on_sale_from( gmdate( 'Y-m-d', time() + 86400 ) );
$scheduled_product->set_date_on_sale_to( gmdate( 'Y-m-d', time() + 172800 ) );
$scheduled_product->save();

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$scheduled_product = wc_get_product( $scheduled->get_id() );
usdtf_it_assert_same( '8', (string) $scheduled_product->get_regular_price( 'edit' ), 'ceil(2,000,000 / 270,000) must be the regular price' );
usdtf_it_assert_same( '4', (string) $scheduled_product->get_sale_price( 'edit' ), 'ceil(1,000,000 / 270,000) must be the sale price' );
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

// Clearing the sale price in the source clears it in WooCommerce.
$pricing->set_source( $sale_product->get_id(), 900000, '' );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '', usdtf_it_sale( $sale_product->get_id() ), 'clearing the source sale price must clear the WooCommerce sale price' );
usdtf_it_pass( 'sale prices are derived and cleared from the source' );

// A variable product with 25 variations (more than one slice of 20).
$variations = array();

for ( $i = 1; $i <= 25; $i++ ) {
	$variations[] = array( 1000000 * $i, '' );
}

$variable = usdtf_it_make_variable_product( 'Variable Laptop', $variations );

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_assert( ! is_wp_error( $job ), 'the variable product update should be created' );
$steps = usdtf_it_run_job( (int) $job['id'] );

$variable_job = $jobs->get( (int) $job['id'] );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $variable_job->status(), 'the variable product job must complete' );
usdtf_it_assert( (int) $variable_job->data['variations_processed'] >= 25, 'every variation must be processed' );

$parent = wc_get_product( $variable->get_id() );
usdtf_it_assert( $parent->is_type( 'variable' ), 'the parent must stay a variable product' );

$first_variation = wc_get_product( $parent->get_children()[0] );
usdtf_it_assert_same( '4', (string) $first_variation->get_regular_price( 'edit' ), 'the first variation must be converted' );

$twenty_fifth = wc_get_product( $parent->get_children()[24] );
usdtf_it_assert_same( '93', (string) $twenty_fifth->get_regular_price( 'edit' ), 'ceil(25,000,000 / 270,000) must be 93 USD' );

usdtf_it_pass( 'variable products are processed in slices and the parent range is recalculated' );

// ---------------------------------------------------------------------------
// 5. Conflict protection: an edit during the job must win.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$conflict_product = usdtf_it_make_simple_product( 'Conflict Product', 5000000 );

$job    = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$job_id = (int) $job['id'];

// Discovery queues the item with the current revision.
$runner->handle_discovery( $job_id );

// An admin edits the Toman price while the queue is busy.
$pricing->set_source( $conflict_product->get_id(), 6000000 );

// The worker now processes the item.
$runner->handle_batch( $job_id );

$statuses = usdtf_it_item_statuses( $job_id );
usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_CONFLICT, $statuses[ $conflict_product->get_id() ], 'an edited product must be reported as a conflict' );
usdtf_it_assert_same( '', usdtf_it_price( $conflict_product->get_id() ), 'a conflicted product must not be overwritten' );
usdtf_it_assert( '' !== usdtf_it_meta( $conflict_product->get_id(), \USDTF\Product_Pricing::META_CONFLICT ), 'the conflict marker must be stored on the product' );

usdtf_it_run_job( $job_id );
$conflict_job = $jobs->get( $job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED_WITH_ERRORS, $conflict_job->status(), 'a job with conflicts must report completed_with_errors' );

// Reviewing the conflicts and recalculating them resolves it.
$runner->recalculate_conflicts( $job_id );
usdtf_it_run_job( $job_id );
usdtf_it_assert_same( '23', usdtf_it_price( $conflict_product->get_id() ), 'ceil(6,000,000 / 270,000) must be 23 USD after the conflict is recalculated' );
usdtf_it_assert_same( '', usdtf_it_meta( $conflict_product->get_id(), \USDTF\Product_Pricing::META_CONFLICT ), 'the conflict marker must be cleared after recalculation' );
usdtf_it_pass( 'concurrent product edits are protected and can be recalculated' );

// ---------------------------------------------------------------------------
// 6. Invalid and excluded products are skipped, never fatal.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$invalid = usdtf_it_make_simple_product( 'Invalid sale', 1000000 );

// A stored source pair where the sale price is above the regular price (for
// example after a bad import): the job must skip and report it, never crash.
update_post_meta( $invalid->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR, '1000000' );
update_post_meta( $invalid->get_id(), \USDTF\Product_Pricing::META_SOURCE_SALE, '2000000' );
$zero   = usdtf_it_make_simple_product( 'Zero price', '' );
$native = usdtf_it_make_simple_product( 'Native USD product', '', '', \USDTF\Product_Pricing::MODE_NATIVE );
$native->set_regular_price( '49' );
$native->save();
$excluded = usdtf_it_make_simple_product( 'Excluded product', 3000000, '', \USDTF\Product_Pricing::MODE_EXCLUDED );

$trashed = usdtf_it_make_simple_product( 'Trashed product', 4000000 );
wp_trash_post( $trashed->get_id() );

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$statuses = usdtf_it_item_statuses( (int) $job['id'] );
usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_SKIPPED, $statuses[ $invalid->get_id() ], 'a sale price above the regular price must be skipped' );
usdtf_it_assert_same( '', usdtf_it_price( $invalid->get_id() ), 'the invalid product must keep its price' );
usdtf_it_assert_same( '', usdtf_it_price( $zero->get_id() ), 'a product without any price must be skipped' );
usdtf_it_assert_same( '49', usdtf_it_price( $native->get_id() ), 'a native USD product must never be touched' );
usdtf_it_assert_same( '', usdtf_it_price( $excluded->get_id() ), 'an excluded product must never be touched' );
usdtf_it_assert_same( '', usdtf_it_price( $trashed->get_id() ), 'trashed products must be ignored' );

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

usdtf_it_assert_same( '10', usdtf_it_price( $in_category_a->get_id() ), 'the product in scope must be updated' );
usdtf_it_assert_same( '', usdtf_it_price( $in_category_b->get_id() ), 'the product outside the scope must stay untouched' );

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
usdtf_it_assert_same( '10', usdtf_it_price( $in_category_b->get_id() ), 'a selected product must be updated' );

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
usdtf_it_assert_same( '20', usdtf_it_price( $product->get_id() ), 'the first rate must produce 20 USD' );

$rates->save_rate( 400000 );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '14', usdtf_it_price( $product->get_id() ), 'ceil(5,400,000 / 400,000) must be 14 USD' );

$rollback = $runner->rollback();
usdtf_it_assert( ! is_wp_error( $rollback ), 'the rollback job must be created' );
usdtf_it_run_job( (int) $rollback['id'] );

usdtf_it_assert_same( 270000.0, $rates->get_rate(), 'the rollback must restore the previous rate' );
usdtf_it_assert_same( '20', usdtf_it_price( $product->get_id() ), 'the rollback must recalculate the price from the Toman source' );
usdtf_it_pass( 'rollback restores the previous rate and recalculates from source' );

// ---------------------------------------------------------------------------
// 11. Toman transaction mode (mode B) and the storefront display (mode A).
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$product = usdtf_it_make_simple_product( 'Display product', 5000000, 2500000 );

// Mode A first: WooCommerce stores USD, the storefront shows Toman.
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

$html = wc_get_product( $product->get_id() )->get_price_html();
usdtf_it_assert( false !== strpos( $html, 'تومان' ), 'mode A price HTML must show the Toman label' );
usdtf_it_assert( false !== strpos( $html, '۲' ), 'mode A price HTML must use Persian digits' );
usdtf_it_assert( false === strpos( $html, '$' ), 'mode A price HTML must not show the USD price' );

// The Store API price_html is built from the same filter.
usdtf_it_assert_same(
	$html,
	wc_get_product( $product->get_id() )->get_price_html(),
	'the Store API price HTML must use the same filtered value'
);

// Switch to Toman mode: the price fields must hold Toman after a full update.
$settings->update(
	array(
		'currency_mode' => \USDTF\Settings::MODE_TOMAN,
	)
);

usdtf_it_assert( $settings->currency_mode_is_stale(), 'a mode switch must mark the catalog as needing a full update' );

$job = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_SYNC,
		'scope' => array(
			'ids' => array( $product->get_id() ),
		),
	)
);
usdtf_it_assert( ! is_wp_error( $job ), 'the update after the mode switch must be created' );

// The scope is forced to the whole catalog because the mode changed.
$scope = $jobs->get( (int) $job['id'] )->scope();
usdtf_it_assert( empty( $scope['ids'] ), 'a currency mode change must force a full update' );

usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '5000000', usdtf_it_price( $product->get_id() ), 'in Toman mode the price field must hold the canonical Toman value' );
usdtf_it_assert_same( '2500000', usdtf_it_sale( $product->get_id() ), 'in Toman mode the sale field must hold the canonical Toman value' );
usdtf_it_assert_same( '19', usdtf_it_meta( $product->get_id(), \USDTF\Product_Pricing::META_DERIVED_REGULAR ), 'the derived USD price must be kept as a reference' );
usdtf_it_assert_same( 'IRT', get_woocommerce_currency(), 'the transaction currency must be Toman' );
usdtf_it_assert_same( 'تومان', get_woocommerce_currency_symbol(), 'the currency symbol must be the Toman label' );
usdtf_it_assert_same( 0, wc_get_price_decimals(), 'Toman prices must not use decimals' );
usdtf_it_assert_same( '5000000', wc_get_product( $product->get_id() )->get_regular_price( 'edit' ), 'the stored regular price must be the canonical Toman value' );
usdtf_it_assert_same( '2500000', wc_get_product( $product->get_id() )->get_price(), 'the charged price must be the canonical Toman sale price, never a converted value' );
usdtf_it_assert( ! $settings->currency_mode_is_stale(), 'the catalog must be marked as synchronized after the full update' );
usdtf_it_pass( 'Toman transaction mode stores and charges canonical Toman prices' );

// Back to USD mode for the remaining scenarios.
$settings->update( array( 'currency_mode' => \USDTF\Settings::MODE_USD ) );
$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );
usdtf_it_assert_same( '19', usdtf_it_price( $product->get_id() ), 'switching back must restore derived USD prices' );
usdtf_it_pass( 'switching the transaction currency back rewrites the price fields' );

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

foreach ( array( \USDTF\Job_Repository::ITEM_CHANGED, \USDTF\Job_Repository::ITEM_UNCHANGED, \USDTF\Job_Repository::ITEM_SKIPPED, \USDTF\Job_Repository::ITEM_FAILED, \USDTF\Job_Repository::ITEM_CONFLICT ) as $item_status ) {
	$totals          = $jobs->item_totals( $job_id );
	$finished_items += isset( $totals[ $item_status ] ) ? (int) $totals[ $item_status ] : 0;
}

usdtf_it_assert_same( 4, $finished_items, 'every product must end in a final state after the recovery' );
usdtf_it_assert_same( 4, (int) $jobs->get( $job_id )->data['processed'], 'the job must not restart from zero' );

// The retried items were already written by the first batch, so they must be
// recognised as unchanged instead of being written a second time.
foreach ( $recovery_products as $index => $recovery_product ) {
	usdtf_it_assert_same( (string) ( $index + 1 ), usdtf_it_price( $recovery_product->get_id() ), 'the recovered product price must be correct' );
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

// ---------------------------------------------------------------------------
// 14. Fixture compatibility: another plugin already stored a Toman source.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$fixture = usdtf_it_make_simple_product( 'Fixture product', '' );
update_post_meta( $fixture->get_id(), \USDTF\Product_Pricing::META_EXTERNAL_SOURCE, '5000000' );
$product = wc_get_product( $fixture->get_id() );
$product->set_regular_price( '5000000' );
$product->save();

$pricing->set_mode( $fixture->get_id(), \USDTF\Product_Pricing::MODE_MANAGED );
$imported = $pricing->import_current_price_as_source( $fixture->get_id() );
usdtf_it_assert( ! is_wp_error( $imported ), 'importing the existing price must work' );

$job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $job['id'] );

usdtf_it_assert_same( '19', usdtf_it_price( $fixture->get_id() ), 'the imported Toman price must convert to 19 USD' );
usdtf_it_assert_same( '5000000', usdtf_it_meta( $fixture->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'the imported value must be kept as the source' );
$external = usdtf_it_meta( $fixture->get_id(), \USDTF\Product_Pricing::META_EXTERNAL_SOURCE );
usdtf_it_assert_same( '5000000', $external, 'a foreign source value must stay untouched' );
usdtf_it_pass( 'an existing Toman catalog can be onboarded without data loss' );

require __DIR__ . '/extra.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/audit.php';

echo "\nAll integration scenarios passed.\n";
