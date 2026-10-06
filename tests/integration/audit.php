<?php
/**
 * Integration scenarios for the production audit fixes (issue #4).
 *
 * Covers: live REST route registration, retry timing, cross-slice variation
 * failures, variation mode persistence, the include_variations scope, the
 * read-only source meta rule, queue failures, the dry-run-first rule,
 * variation discovery under unmanaged parents, variation pagination, the
 * zero source rule, the capability allowlist and scheduled sales.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended, WordPress.WP.GlobalVariablesOverride.Prohibited -- CLI test script with a simulated request.

use USDTF\Database;
use USDTF\Health;
use USDTF\Job;
use USDTF\Job_Repository;
use USDTF\Lock;
use USDTF\Product_Pricing;
use USDTF\Sync_Runner;

global $wpdb, $runner, $pricing, $rates, $settings;

// ---------------------------------------------------------------------------
// 32. Every admin REST route is registered on the live server, and the
//     shipped version markers agree (upgrade / stale files guard).
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();

$usdtf_it_routes = rest_get_server()->get_routes();

foreach ( array( '/state', '/rate', '/preview', '/update', '/rollback', '/recalculate', '/jobs', '/health', '/health/loopback', '/products' ) as $usdtf_it_path ) {
	usdtf_it_assert( isset( $usdtf_it_routes[ '/usdtf/v1' . $usdtf_it_path ] ), 'the live REST server must register /usdtf/v1' . $usdtf_it_path . ' — a missing route is what turns every admin action into rest_no_route' );
}

usdtf_it_assert_same( array(), usdtf_plugin()->health()->missing_rest_routes(), 'the diagnostics must not report missing admin routes' );

$usdtf_it_health_ids = wp_list_pluck( usdtf_plugin()->health()->checks(), 'id' );
usdtf_it_assert( in_array( 'rest_routes', $usdtf_it_health_ids, true ), 'the diagnostics must include the REST route check' );

$usdtf_it_readme_stable = (string) file_get_contents( USDTF_DIR . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI test.
usdtf_it_assert( (bool) preg_match( '/^Stable tag:\s*(' . preg_quote( USDTF_VERSION, '/' ) . ')$/m', $usdtf_it_readme_stable ), 'the readme stable tag must match the plugin version ' . USDTF_VERSION );

$usdtf_it_uninstall_source = (string) file_get_contents( USDTF_DIR . '/uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI test.
usdtf_it_assert( (bool) preg_match( "/define\(\\s*'USDTF_VERSION',\\s*'" . preg_quote( USDTF_VERSION, '/' ) . "'/", $usdtf_it_uninstall_source ), 'the uninstall fallback version must match the plugin version' );

usdtf_it_pass( 'every admin REST route is registered and the version markers agree' );

// ---------------------------------------------------------------------------
// 33. The retry delay is respected for pending items too.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );
$retry_product = usdtf_it_make_simple_product( 'Retry product', '5400000' );

$retry_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
$retry_id  = (int) $retry_job['id'];

// Run the discovery step only: the job stays running with the item queued,
// the way it is while the queue works through the batch.
$runner->handle_discovery( $retry_id );

$retry_item = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT id FROM ' . Database::items_table() . ' WHERE job_id = %d AND object_id = %d', $retry_id, $retry_product->get_id() ),
	ARRAY_A
);

usdtf_it_assert( $retry_item, 'the product must be queued as an item' );

// A failed attempt schedules a retry in the future: the item goes back to
// pending with a retry_after timestamp.
$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	Database::items_table(),
	array(
		'status'      => Job_Repository::ITEM_PENDING,
		'retry_after' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
	),
	array( 'id' => (int) $retry_item['id'] )
);

usdtf_it_assert_same( array(), usdtf_plugin()->jobs()->next_items( $retry_id, 20 ), 'a pending item whose retry delay has not passed must not be picked up' );
usdtf_it_assert( usdtf_plugin()->jobs()->next_retry_delay( $retry_id ) > 0, 'the repository must report how long the earliest retry takes' );
usdtf_it_assert( usdtf_plugin()->jobs()->next_retry_delay( $retry_id ) <= 60, 'the reported retry delay must be bounded by the backoff' );

$runner->handle_batch( $retry_id );

$untouched = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT status FROM ' . Database::items_table() . ' WHERE id = %d', (int) $retry_item['id'] ),
	ARRAY_A
);
usdtf_it_assert_same( Job_Repository::ITEM_PENDING, $untouched['status'], 'a batch run before the retry delay must leave the item pending' );

// Once the delay is over the item is due again.
$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	Database::items_table(),
	array( 'retry_after' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ),
	array( 'id' => (int) $retry_item['id'] )
);

usdtf_it_assert_same( 0, usdtf_plugin()->jobs()->next_retry_delay( $retry_id ), 'no retry delay may be reported once it has passed' );
usdtf_it_assert( count( usdtf_plugin()->jobs()->next_items( $retry_id, 20 ) ) === 1, 'a pending item must become due once its retry delay has passed' );

usdtf_it_run_job( $retry_id );
usdtf_it_assert_same( Job::STATUS_COMPLETED, usdtf_plugin()->jobs()->get( $retry_id )->status(), 'the job must finish once the retry is due' );
usdtf_it_assert_same( '20', usdtf_it_price( $retry_product->get_id() ), 'the retried item must be processed normally' );

usdtf_it_pass( 'the retry delay is respected for pending items' );

// ---------------------------------------------------------------------------
// 34. A variation error in an early slice survives every later slice.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );

$usdtf_it_variation_prices = array();

for ( $i = 1; $i <= 25; $i++ ) {
	$usdtf_it_variation_prices[] = array( (string) ( 1000000 + $i * 10000 ) );
}

$sliced_product = usdtf_it_make_variable_product( 'Sliced variable product', $usdtf_it_variation_prices );

// Variation #3 carries an invalid source: it is reported in the first slice.
$usdtf_it_children = $sliced_product->get_children();
$broken_variation  = (int) $usdtf_it_children[2];

update_post_meta( $broken_variation, Product_Pricing::META_SOURCE_REGULAR, '-5' );

// Ten variations per slice: the job needs three slices for 25 variations.
add_filter( 'usdtf_variation_slice', function () {
	return 10;
} );

$sliced_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $sliced_job['id'] );

remove_all_filters( 'usdtf_variation_slice' );

$sliced_job_row = usdtf_plugin()->jobs()->get( (int) $sliced_job['id'] );
usdtf_it_assert_same( 25, (int) $sliced_job_row->data['variations_processed'], 'all 25 variations must be processed across the slices' );

$sliced_item = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT * FROM ' . Database::items_table() . ' WHERE job_id = %d AND object_id = %d', (int) $sliced_job['id'], $sliced_product->get_id() ),
	ARRAY_A
);

usdtf_it_assert_same( '25', (string) $sliced_item['child_total'], 'the item must know all of its variations' );
usdtf_it_assert( false !== strpos( (string) $sliced_item['message'], 'skipped: 1' ), 'the cumulative counters must keep the early slice problem visible: ' . (string) $sliced_item['message'] );
usdtf_it_assert_same( '1', (string) $sliced_item['attention'], 'an invalid variation source in an early slice must flag the item for attention' );

$usdtf_it_child_stats = json_decode( (string) $sliced_item['child_stats'], true );
usdtf_it_assert( is_array( $usdtf_it_child_stats ) && 1 === (int) $usdtf_it_child_stats['skipped'], 'the persisted per-item counters must remember the early slice skip' );
usdtf_it_assert( is_array( $usdtf_it_child_stats ) && 24 === (int) $usdtf_it_child_stats['changed'], 'the working variations must be counted as changed' );
usdtf_it_assert( is_array( $usdtf_it_child_stats ) && 1 === (int) $usdtf_it_child_stats['attention'], 'the attention flag must be sticky across the slices' );

usdtf_it_assert_same( Job::STATUS_COMPLETED_WITH_ERRORS, $sliced_job_row->status(), 'the job must finish with errors when a variation had an invalid source' );

// A variation whose price cannot be written at all fails the item in the slice
// it happens in, no matter how many slices follow.
$usdtf_it_fail_prices = array();

for ( $i = 1; $i <= 12; $i++ ) {
	$usdtf_it_fail_prices[] = array( (string) ( 2000000 + $i * 10000 ) );
}

$failing_product = usdtf_it_make_variable_product( 'Failing variable product', $usdtf_it_fail_prices );
$failing_broken  = (int) $failing_product->get_children()[2];

$failing_hook = function ( $saved_product ) use ( $failing_broken ) {
	if ( (int) $saved_product->get_id() === $failing_broken ) {
		throw new RuntimeException( 'simulated write failure' );
	}
};

add_action( 'woocommerce_update_product', $failing_hook );

add_filter( 'usdtf_variation_slice', function () {
	return 5;
} );

$failing_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $failing_job['id'] );

remove_all_filters( 'usdtf_variation_slice' );
remove_action( 'woocommerce_update_product', $failing_hook );

$failing_item = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT * FROM ' . Database::items_table() . ' WHERE job_id = %d AND object_id = %d', (int) $failing_job['id'], $failing_product->get_id() ),
	ARRAY_A
);

usdtf_it_assert_same( Job_Repository::ITEM_FAILED, $failing_item['status'], 'a variable item whose variation failed in the first slice must finish as failed' );
usdtf_it_assert( false !== strpos( (string) $failing_item['message'], 'failed: 1' ), 'the failure of the early slice must survive the later slices: ' . (string) $failing_item['message'] );

$usdtf_it_fail_stats = json_decode( (string) $failing_item['child_stats'], true );
usdtf_it_assert( is_array( $usdtf_it_fail_stats ) && 1 === (int) $usdtf_it_fail_stats['failed'], 'the persisted counters must remember the early slice failure' );

usdtf_it_assert_same( Job::STATUS_COMPLETED_WITH_ERRORS, usdtf_plugin()->jobs()->get( (int) $failing_job['id'] )->status(), 'the job must finish with errors when a variation failed' );

usdtf_it_pass( 'a variation error in an early slice survives every later slice' );

// ---------------------------------------------------------------------------
// 35. The variation mode select of the product panel is persisted.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$panel_variable = usdtf_it_make_variable_product( 'Panel mode variable', array( array( '3000000' ), array( '4000000' ) ) );
$panel_child_id = (int) $panel_variable->get_children()[0];

// The panel instance is created by the admin screen; build one the same way.
$panel_class   = \USDTF\Admin\Product_Panel::class;
$panel_object  = new $panel_class( $settings, $pricing, usdtf_plugin()->products() );

$_POST = array(
	'usdtf_panel_nonce' => wp_create_nonce( $panel_class::NONCE ),
	'usdtf_mode'        => Product_Pricing::MODE_MANAGED,
);

$_POST['usdtf_variation_source'] = array(
	$panel_child_id => array(
		'mode'    => Product_Pricing::MODE_NATIVE,
		'regular' => '3000000',
	),
);

$panel_object->save_variation( $panel_child_id );

usdtf_it_assert_same( Product_Pricing::MODE_NATIVE, $pricing->get_mode( $panel_child_id ), 'the variation mode select must be persisted' );
usdtf_it_assert_same( '3000000', usdtf_it_meta( $panel_child_id, Product_Pricing::META_SOURCE_REGULAR ), 'the variation source must still be persisted' );

$_POST['usdtf_variation_source'][ $panel_child_id ]['mode'] = Product_Pricing::MODE_EXCLUDED;

$panel_object->save_variation( $panel_child_id );

usdtf_it_assert_same( Product_Pricing::MODE_EXCLUDED, $pricing->get_mode( $panel_child_id ), 'switching the variation mode must survive the save' );

// The full product save carries the same fields for every variation.
$_POST['usdtf_variation_source'][ $panel_child_id ]['mode'] = Product_Pricing::MODE_MANAGED;

$panel_object->save_product( $panel_variable->get_id() );

usdtf_it_assert_same( Product_Pricing::MODE_MANAGED, $pricing->get_mode( $panel_child_id ), 'the product save must persist the variation mode as well' );

unset( $_POST );

usdtf_it_pass( 'the variation mode select of the product panel is persisted' );

// ---------------------------------------------------------------------------
// 36. The include_variations scope flag decides what a variable item does.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );

$scope_variable = usdtf_it_make_variable_product( 'Scope variable', array( array( '2000000' ), array( '4000000' ) ) );
$scope_simple   = usdtf_it_make_simple_product( 'Scope simple', '5000000' );

$no_variations_job = usdtf_it_create_job(
	array(
		'type'  => Job::TYPE_SYNC,
		'scope' => array( 'include_variations' => false ),
	)
);
usdtf_it_run_job( (int) $no_variations_job['id'] );

$no_variations_item = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT * FROM ' . Database::items_table() . ' WHERE job_id = %d AND object_id = %d', (int) $no_variations_job['id'], $scope_variable->get_id() ),
	ARRAY_A
);

usdtf_it_assert_same( Job_Repository::ITEM_SKIPPED, $no_variations_item['status'], 'a variable product must be skipped when variations are excluded from the scope' );
usdtf_it_assert( false !== strpos( (string) $no_variations_item['message'], 'excluded' ), 'the skip must explain itself: ' . (string) $no_variations_item['message'] );

$first_child = wc_get_product( (int) $scope_variable->get_children()[0] );
usdtf_it_assert_same( '', (string) $first_child->get_regular_price( 'edit' ), 'the variations must not be written when the scope excludes them' );
usdtf_it_assert_same( '19', usdtf_it_price( $scope_simple->get_id() ), 'the simple product in the same job must still be updated' );

$with_variations_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $with_variations_job['id'] );

$first_child = wc_get_product( (int) $scope_variable->get_children()[0] );
usdtf_it_assert( '' !== (string) $first_child->get_regular_price( 'edit' ), 'a default scope must write the variation prices' );

usdtf_it_pass( 'the include_variations scope flag decides what a variable item does' );

// ---------------------------------------------------------------------------
// 37. The worker never rewrites the canonical Toman source meta.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );

$race_product = usdtf_it_make_simple_product( 'Race product', '5000000' );
$race_id      = $race_product->get_id();

// An edit that lands while the worker writes the product, after the revision
// check but before the meta refresh: exactly the window the audit describes.
$race_edit = null;

$race_edit = function ( $saved_product ) use ( $race_id, $pricing, &$race_edit ) {
	if ( (int) $saved_product->get_id() !== $race_id ) {
		return;
	}

	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;
	remove_action( 'woocommerce_update_product', $race_edit );
	$pricing->set_source( $race_id, '9999999' );
};

add_action( 'woocommerce_update_product', $race_edit );

$race_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $race_job['id'] );

remove_action( 'woocommerce_update_product', $race_edit );

usdtf_it_assert_same( '9999999', usdtf_it_meta( $race_id, Product_Pricing::META_SOURCE_REGULAR ), 'a concurrent edit of the canonical Toman source must survive the worker — the worker may never rewrite the source meta' );

$race_item = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT status FROM ' . Database::items_table() . ' WHERE job_id = %d AND object_id = %d', (int) $race_job['id'], $race_id ),
	ARRAY_A
);
usdtf_it_assert( in_array( $race_item['status'], array( Job_Repository::ITEM_CHANGED, Job_Repository::ITEM_CONFLICT ), true ), 'the raced item must finish as changed or as a conflict, never as a silent overwrite' );

// The next run adopts the new source without losing it.
$adopt_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $adopt_job['id'] );

usdtf_it_assert_same( '9999999', usdtf_it_meta( $race_id, Product_Pricing::META_SOURCE_REGULAR ), 'later jobs must also keep the concurrent source' );
usdtf_it_assert_same( '37', usdtf_it_price( $race_id ), 'the next job derives the USD price from the surviving source (9999999 / 270000 = 37)' );

usdtf_it_pass( 'the worker never rewrites the canonical Toman source meta' );

// ---------------------------------------------------------------------------
// 38. A queueing failure pauses the job and frees the lock.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );
usdtf_it_make_simple_product( 'Queue product', '5000000' );

$block_queue = function () {
	return true;
};

add_filter( 'usdtf_scheduler_enqueue_blocked', $block_queue );

$blocked = usdtf_it_rest( 'POST', '/update' );

usdtf_it_assert_same( 'usdtf_queue_failed', usdtf_it_rest_error_code( $blocked ), 'an update whose worker step could not be queued must return an actionable error' );

remove_filter( 'usdtf_scheduler_enqueue_blocked', $block_queue );

$blocked_job = usdtf_plugin()->jobs()->active_write_job();
usdtf_it_assert( $blocked_job, 'the blocked job must still exist' );
usdtf_it_assert_same( Job::STATUS_PAUSED, $blocked_job->status(), 'the blocked job must be paused with a clear message' );
usdtf_it_assert( '' !== (string) $blocked_job->data['message'], 'the paused job must explain what happened' );
usdtf_it_assert( false === get_option( Lock::OPTION ), 'the lock must be released when the queue fails' );

$resumed = usdtf_it_rest( 'POST', '/jobs/' . $blocked_job->id() . '/resume' );
usdtf_it_assert_same( '', usdtf_it_rest_error_code( $resumed ), 'the job must be resumable once the queue works again' );

usdtf_it_run_job( $blocked_job->id() );
$blocked_job = usdtf_plugin()->jobs()->get( $blocked_job->id() );
usdtf_it_assert( ! $blocked_job->is_active(), 'the resumed job must finish' );

usdtf_it_pass( 'a queueing failure pauses the job and frees the lock' );

// ---------------------------------------------------------------------------
// 39. Dry run first: an update needs a matching completed dry run.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$settings->update( array( 'require_preview' => true ) );
$rates->save_rate( 270000 );
usdtf_it_make_simple_product( 'Preview gate product', '5000000' );

$state_with_gate = usdtf_plugin()->runner()->state();
usdtf_it_assert( ! empty( $state_with_gate['preview_required'] ), 'the state must expose that a dry run is required' );
usdtf_it_assert( empty( $state_with_gate['preview_ok'] ), 'without a dry run nothing matches yet' );

$rejected_update = usdtf_it_rest( 'POST', '/update' );
usdtf_it_assert_same( 'usdtf_preview_required', usdtf_it_rest_error_code( $rejected_update ), 'an update without a dry run must be refused' );

$rejected_data = usdtf_it_rest_error_data( $rejected_update );
usdtf_it_assert( ! empty( $rejected_data['fingerprint'] ), 'the rejection must carry the expected fingerprint' );

$preview_result = usdtf_it_rest( 'POST', '/preview' );
usdtf_it_assert_same( '', usdtf_it_rest_error_code( $preview_result ), 'the dry run itself must start' );
usdtf_it_run_job( (int) usdtf_it_rest_data( $preview_result )['id'] );

$state_after_preview = usdtf_plugin()->runner()->state();
usdtf_it_assert( ! empty( $state_after_preview['preview_ok'] ), 'a completed dry run with the same settings must satisfy the gate' );

$allowed_update = usdtf_it_rest( 'POST', '/update' );
usdtf_it_assert_same( '', usdtf_it_rest_error_code( $allowed_update ), 'an update after a matching dry run must start' );
usdtf_it_run_job( (int) usdtf_it_rest_data( $allowed_update )['id'] );

// A different rate invalidates the dry run again.
$rates->save_rate( 300000 );

usdtf_it_assert_same( 'usdtf_preview_required', usdtf_it_rest_error_code( usdtf_it_rest( 'POST', '/update' ) ), 'a rate change must invalidate the completed dry run' );

// A preview presented explicitly must match as well.
$second_preview = usdtf_it_rest( 'POST', '/preview', array( 'rate' => '300000' ) );
usdtf_it_run_job( (int) usdtf_it_rest_data( $second_preview )['id'] );

$wrong_preview_id = (int) usdtf_it_rest_data( $preview_result )['id'];
usdtf_it_assert_same( 'usdtf_preview_required', usdtf_it_rest_error_code( usdtf_it_rest( 'POST', '/update', array( 'preview' => $wrong_preview_id ) ) ), 'referencing a dry run made with another rate must be refused' );

$right_preview_id = (int) usdtf_it_rest_data( $second_preview )['id'];
$matched_update   = usdtf_it_rest( 'POST', '/update', array( 'preview' => $right_preview_id ) );
usdtf_it_assert_same( '', usdtf_it_rest_error_code( $matched_update ), 'referencing the matching dry run must be accepted' );
usdtf_it_run_job( (int) usdtf_it_rest_data( $matched_update )['id'] );

// A different scope does not match the full-catalog dry run either.
usdtf_it_assert_same(
	'usdtf_preview_required',
	usdtf_it_rest_error_code( usdtf_it_rest( 'POST', '/update', array( 'scope' => array( 'only_outdated' => true ) ) ) ),
	'an update with a different scope must be refused until that scope is previewed'
);

usdtf_it_assert_same(
	'usdtf_preview_not_found',
	usdtf_it_rest_error_code( usdtf_it_rest( 'POST', '/update', array( 'preview' => 999999 ) ) ),
	'referencing a dry run that does not exist must be refused'
);

// The enforcement is a setting: without it the update starts directly.
$settings->update( array( 'require_preview' => false ) );

usdtf_it_assert_same( '', usdtf_it_rest_error_code( usdtf_it_rest( 'POST', '/update' ) ), 'the dry run rule is a setting and can be turned off' );
usdtf_it_run_job( (int) usdtf_plugin()->jobs()->active_write_job()->id() );

usdtf_it_pass( 'an update only starts after a matching completed dry run' );

// ---------------------------------------------------------------------------
// 40. Managed variations under an unmanaged parent are discovered.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );

$orphan_variable = usdtf_it_make_variable_product(
	'Unmanaged parent',
	array( array( '2700000' ), array( '5400000' ) ),
	Product_Pricing::MODE_NATIVE
);

// The factory applies the parent mode to its variations; the orphan scenario
// needs managed variations under a native parent.
foreach ( $orphan_variable->get_children() as $orphan_child_id ) {
	$pricing->set_mode( (int) $orphan_child_id, Product_Pricing::MODE_MANAGED );
}

$orphan_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $orphan_job['id'] );

$orphan_items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT * FROM ' . Database::items_table() . ' WHERE job_id = %d', (int) $orphan_job['id'] ),
	ARRAY_A
);

usdtf_it_assert_same( 2, count( $orphan_items ), 'the managed variations under the native parent must be discovered on their own' );
usdtf_it_assert_same( 'variation', $orphan_items[0]['object_type'], 'the discovered items must be variations' );

foreach ( $orphan_variable->get_children() as $orphan_child_id ) {
	usdtf_it_assert( '' !== usdtf_it_price( $orphan_child_id ), 'the managed variation must have been written' );
}

usdtf_it_assert_same( '', usdtf_it_price( $orphan_variable->get_id() ), 'the native parent itself must stay untouched' );

usdtf_it_pass( 'managed variations under an unmanaged parent are discovered' );

// ---------------------------------------------------------------------------
// 41. Variation IDs are paginated: a product with more variations than one
//     slice is synchronized completely.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );

$usdtf_it_paged_prices = array();

for ( $i = 1; $i <= 12; $i++ ) {
	$usdtf_it_paged_prices[] = array( (string) ( 1000000 + $i * 10000 ) );
}

$paged_product = usdtf_it_make_variable_product( 'Paged variable product', $usdtf_it_paged_prices );

usdtf_it_assert_same( 12, usdtf_plugin()->products()->count_variations( $paged_product->get_id() ), 'count_variations must count every variation' );

$first_page  = usdtf_plugin()->products()->variation_ids( $paged_product->get_id(), 0, 5 );
$second_page = usdtf_plugin()->products()->variation_ids( $paged_product->get_id(), 5, 5 );
$third_page  = usdtf_plugin()->products()->variation_ids( $paged_product->get_id(), 10, 5 );

usdtf_it_assert_same( 5, count( $first_page ), 'the first variation page must hold five IDs' );
usdtf_it_assert_same( 5, count( $second_page ), 'the second variation page must hold five IDs' );
usdtf_it_assert_same( 2, count( $third_page ), 'the last variation page must hold the rest' );

usdtf_it_assert_same( array(), array_intersect( $first_page, $second_page ), 'variation pages must not overlap' );

add_filter( 'usdtf_variation_slice', function () {
	return 5;
} );

$paged_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $paged_job['id'] );

remove_all_filters( 'usdtf_variation_slice' );

$paged_job_row = usdtf_plugin()->jobs()->get( (int) $paged_job['id'] );
usdtf_it_assert_same( 12, (int) $paged_job_row->data['variations_processed'], 'a product with twelve variations and five per slice must process all of them' );

foreach ( $paged_product->get_children() as $paged_child_id ) {
	usdtf_it_assert( '' !== usdtf_it_price( $paged_child_id ), 'every variation of the product must have been written' );
}

usdtf_it_pass( 'variation IDs are paginated and nothing is cut off' );

// ---------------------------------------------------------------------------
// 42. A zero source price is refused at write time.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$zero_product = usdtf_it_make_simple_product( 'Zero source product', '1000000' );
$zero_id      = $zero_product->get_id();

usdtf_it_assert( is_wp_error( $pricing->set_source( $zero_id, '0' ) ), 'a zero regular source must be refused' );
usdtf_it_assert( is_wp_error( $pricing->set_source( $zero_id, '1000000', '0' ) ), 'a zero sale source must be refused' );
usdtf_it_assert_same( '1000000', usdtf_it_meta( $zero_id, Product_Pricing::META_SOURCE_REGULAR ), 'a refused write must not change the stored source' );

$zero_import = new WC_Product_Simple();
$zero_import->set_regular_price( '0' );
$zero_import->save();

$pricing->set_mode( $zero_import->get_id(), Product_Pricing::MODE_MANAGED );
usdtf_it_assert( is_wp_error( $pricing->import_current_price_as_source( $zero_import->get_id() ) ), 'importing a zero price must be refused too' );

usdtf_it_pass( 'a zero source price is refused at write time' );

// ---------------------------------------------------------------------------
// 43. Only the capability allowlist is storable.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();

usdtf_it_assert( in_array( 'read', \USDTF\Capabilities::allowed(), true ) === false, 'weak capabilities must not be selectable' );

$weak = \USDTF\Settings::sanitize( array( 'required_capability' => 'read' ) );
usdtf_it_assert_same( \USDTF\Capabilities::DEFAULT_CAPABILITY, $weak['required_capability'], 'a weak capability must fall back to the default' );

$strong = \USDTF\Settings::sanitize( array( 'required_capability' => 'manage_options' ) );
usdtf_it_assert_same( 'manage_options', $strong['required_capability'], 'the allowlist capabilities must be storable' );

// Even a value smuggled into the option directly is not honoured.
update_option( \USDTF\Settings::OPTION, array_merge( \USDTF\Settings::defaults(), array( 'required_capability' => 'read' ) ) );
usdtf_it_assert_same( \USDTF\Capabilities::DEFAULT_CAPABILITY, \USDTF\Capabilities::required(), 'a weak capability in the option must not be honoured' );
usdtf_plugin()->settings()->reset();

// Saving the settings screen is guarded by the plugin capability, not by manage_options.
$usdtf_admin_screen = new \USDTF\Admin\Admin(
	usdtf_plugin()->settings(),
	usdtf_plugin()->runner(),
	usdtf_plugin()->rates(),
	usdtf_plugin()->jobs(),
	usdtf_plugin()->products(),
	usdtf_plugin()->pricing(),
	usdtf_plugin()->health(),
	usdtf_plugin()->logger(),
	usdtf_plugin()->scheduler()
);

$usdtf_admin_screen->register_settings();

usdtf_it_assert_same(
	\USDTF\Capabilities::required(),
	apply_filters( 'option_page_capability_' . \USDTF\Admin\Admin::OPTION_GROUP, 'manage_options' ),
	'options.php must demand the plugin capability, aligned with the settings screen'
);

usdtf_it_pass( 'only the capability allowlist is storable' );

// ---------------------------------------------------------------------------
// 44. A scheduled sale that ended must not feed the Toman range.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );
$settings->update(
	array(
		'display_toman'  => true,
		'persian_digits' => false,
	)
);

$expired_sale_product = usdtf_it_make_variable_product( 'Expired sale variable', array( array( '2000000', '1500000' ), array( '4000000' ) ) );

$expired_child = wc_get_product( (int) $expired_sale_product->get_children()[0] );
$expired_child->set_date_on_sale_from( time() - YEAR_IN_SECONDS );
$expired_child->set_date_on_sale_to( time() - DAY_IN_SECONDS );
$expired_child->save();

$active_child = wc_get_product( (int) $expired_sale_product->get_children()[1] );

$expired_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $expired_job['id'] );

usdtf_it_assert( ! $expired_child->is_on_sale(), 'the expired sale variation must not be on sale' );

$usdtf_it_usd_html = wc_price( 10 );
$expired_range     = apply_filters( 'woocommerce_get_price_html', $usdtf_it_usd_html, wc_get_product( $expired_sale_product->get_id() ) );

usdtf_it_assert( false === strpos( $expired_range, '1,500,000' ), 'an expired scheduled sale must not feed the Toman range' );
usdtf_it_assert( false !== strpos( $expired_range, '2,000,000' ), 'the regular Toman price must be used once the sale ended' );

// While the sale is active the sale price is the reference.
$active_sale_product = usdtf_it_make_variable_product( 'Active sale variable', array( array( '2000000', '1500000' ), array( '4000000' ) ) );

$active_sale_child = wc_get_product( (int) $active_sale_product->get_children()[0] );
$active_sale_child->set_date_on_sale_from( time() - HOUR_IN_SECONDS );
$active_sale_child->set_date_on_sale_to( time() + DAY_IN_SECONDS );
$active_sale_child->save();

$active_sale_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $active_sale_job['id'] );

usdtf_it_assert( $active_sale_child->is_on_sale(), 'the active sale variation must be on sale' );

$active_range = apply_filters( 'woocommerce_get_price_html', $usdtf_it_usd_html, wc_get_product( $active_sale_product->get_id() ) );

usdtf_it_assert( false !== strpos( $active_range, '1,500,000' ), 'an active sale must feed the Toman range' );

usdtf_it_pass( 'a scheduled sale that ended must not feed the Toman range' );

// ---------------------------------------------------------------------------
// 45. Cross-request workers and real HTTP REST (separate files).
// ---------------------------------------------------------------------------
require __DIR__ . '/crossrequest.php';
require __DIR__ . '/http.php';
