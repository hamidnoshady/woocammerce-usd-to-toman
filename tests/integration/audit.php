<?php
/**
 * Integration scenarios for the production audit fixes (issue #4).
 *
 * Covers: live REST route registration, retry timing, cross-slice variation
 * failures, variation mode persistence, the include_variations scope, queue
 * failures, the dry-run-first rule, variation discovery under unmanaged
 * parents, variation pagination and the capability allowlist. The read-only
 * source meta, zero source and ended scheduled sale scenarios of the old
 * Toman-source model are covered by their USD-pegged counterparts in
 * pegged.php.
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
// shipped version markers agree (upgrade / stale files guard).
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();

$usdtf_it_routes = rest_get_server()->get_routes();

foreach ( array( '/state', '/rate', '/preview', '/update', '/rollback', '/recalculate', '/jobs', '/jobs/(?P<id>\\d+)/status', '/health', '/health/loopback', '/products' ) as $usdtf_it_path ) {
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

// The USD price is generated first, so the retried item below writes a new Woo price.
$retry_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $retry_job['id'] );
$rates->save_rate( 300000 );

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
usdtf_it_assert_same( '6000000', usdtf_it_price( $retry_product->get_id() ), 'the retried item must be processed normally' );

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

// Variation #3 carries an invalid price (WooCommerce would not save one, so it
// is planted in the meta): it is reported in the first slice.
$usdtf_it_children = $sliced_product->get_children();
$broken_variation  = (int) $usdtf_it_children[2];

update_post_meta( $broken_variation, '_regular_price', '-5' );
update_post_meta( $broken_variation, '_price', '-5' );

// The working variations get their USD price first, so the sliced job below changes them.
$sliced_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $sliced_job['id'] );
$rates->save_rate( 300000 );

// Ten variations per slice: the job needs three slices for 25 variations.
add_filter(
	'usdtf_variation_slice',
	function () {
		return 10;
	}
);

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
usdtf_it_assert_same( '1', (string) $sliced_item['attention'], 'an invalid variation price in an early slice must flag the item for attention' );

$usdtf_it_child_stats = json_decode( (string) $sliced_item['child_stats'], true );
usdtf_it_assert( is_array( $usdtf_it_child_stats ) && 1 === (int) $usdtf_it_child_stats['skipped'], 'the persisted per-item counters must remember the early slice skip' );
usdtf_it_assert( is_array( $usdtf_it_child_stats ) && 24 === (int) $usdtf_it_child_stats['changed'], 'the working variations must be counted as changed' );
usdtf_it_assert( is_array( $usdtf_it_child_stats ) && 1 === (int) $usdtf_it_child_stats['attention'], 'the attention flag must be sticky across the slices' );

usdtf_it_assert_same( Job::STATUS_COMPLETED_WITH_ERRORS, $sliced_job_row->status(), 'the job must finish with errors when a variation had an invalid price' );

// A variation whose price cannot be written at all fails the item in the slice
// it happens in, no matter how many slices follow.
$usdtf_it_fail_prices = array();

for ( $i = 1; $i <= 12; $i++ ) {
	$usdtf_it_fail_prices[] = array( (string) ( 2000000 + $i * 10000 ) );
}

$failing_product = usdtf_it_make_variable_product( 'Failing variable product', $usdtf_it_fail_prices );
$failing_broken  = (int) $failing_product->get_children()[2];

// Generate the USD prices first: only a new rate makes the job write the variations.
$failing_job = usdtf_it_create_job(
	array(
		'type'  => Job::TYPE_SYNC,
		'scope' => array( 'ids' => array( $failing_product->get_id() ) ),
	)
);
usdtf_it_run_job( (int) $failing_job['id'] );
$rates->save_rate( 330000 );

$failing_hook = function ( $saved_product ) use ( $failing_broken ) {
	$saved_id = $saved_product instanceof \WC_Product ? (int) $saved_product->get_id() : (int) $saved_product;

	if ( $saved_id === $failing_broken ) {
		throw new RuntimeException( 'simulated write failure' );
	}
};

// Products and variations fire different hooks when they are saved.
add_action( 'woocommerce_update_product', $failing_hook );
add_action( 'woocommerce_update_product_variation', $failing_hook );

add_filter(
	'usdtf_variation_slice',
	function () {
		return 5;
	}
);

$failing_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $failing_job['id'] );

remove_all_filters( 'usdtf_variation_slice' );
remove_action( 'woocommerce_update_product', $failing_hook );
remove_action( 'woocommerce_update_product_variation', $failing_hook );

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
$panel_class  = \USDTF\Admin\Product_Panel::class;
$panel_object = new $panel_class( $settings, $pricing, usdtf_plugin()->products() );

$_POST = array(
	'usdtf_panel_nonce' => wp_create_nonce( $panel_class::NONCE ),
	'usdtf_mode'        => Product_Pricing::MODE_MANAGED,
);

// A price posted next to the mode is ignored: variation prices belong to WooCommerce.
$_POST['usdtf_variation'] = array(
	$panel_child_id => array(
		'mode'    => Product_Pricing::MODE_EXCLUDED,
		'regular' => '999',
	),
);

$panel_object->save_variation( $panel_child_id );

usdtf_it_assert_same( Product_Pricing::MODE_EXCLUDED, $pricing->get_mode( $panel_child_id ), 'the variation mode select must be persisted' );
usdtf_it_assert_same( '3000000', usdtf_it_price( $panel_child_id ), 'the variation price must still be the WooCommerce price' );

// A retired or unknown mode is refused and leaves the stored mode alone.
$_POST['usdtf_variation'][ $panel_child_id ]['mode'] = 'native';

$panel_object->save_variation( $panel_child_id );

usdtf_it_assert_same( Product_Pricing::MODE_EXCLUDED, usdtf_it_meta( $panel_child_id, Product_Pricing::META_MODE ), 'a mode other than Managed or Excluded must be refused for a variation' );
delete_transient( 'usdtf_panel_error_' . get_current_user_id() );

$_POST['usdtf_variation'][ $panel_child_id ]['mode'] = Product_Pricing::MODE_MANAGED;

$panel_object->save_variation( $panel_child_id );

usdtf_it_assert_same( Product_Pricing::MODE_MANAGED, $pricing->get_mode( $panel_child_id ), 'switching the variation mode must survive the save' );

// The full product save carries the same fields for every variation.
$_POST['usdtf_variation'][ $panel_child_id ]['mode'] = Product_Pricing::MODE_EXCLUDED;

$panel_object->save_product( $panel_variable->get_id() );

usdtf_it_assert_same( Product_Pricing::MODE_EXCLUDED, $pricing->get_mode( $panel_child_id ), 'the product save must persist the variation mode as well' );

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

// Everything gets its USD price first; the scoped jobs below run at a new rate.
$scope_generate = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $scope_generate['id'] );
$rates->save_rate( 300000 );

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
usdtf_it_assert_same( '2000000', (string) $first_child->get_regular_price( 'edit' ), 'the variations must not be written when the scope excludes them' );
usdtf_it_assert_same( '5556000', usdtf_it_price( $scope_simple->get_id() ), 'the simple product in the same job must still be updated' );

$with_variations_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $with_variations_job['id'] );

$first_child = wc_get_product( (int) $scope_variable->get_children()[0] );
usdtf_it_assert_same( '2223000', (string) $first_child->get_regular_price( 'edit' ), 'a default scope must write the variation prices' );

usdtf_it_pass( 'the include_variations scope flag decides what a variable item does' );

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
	Product_Pricing::MODE_EXCLUDED
);

// The factory applies the parent mode to its variations; the orphan scenario
// needs managed variations under an excluded parent.
foreach ( $orphan_variable->get_children() as $orphan_child_id ) {
	$pricing->set_mode( (int) $orphan_child_id, Product_Pricing::MODE_MANAGED );
}

// The variations get their USD price first; the job below writes a new rate.
$orphan_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $orphan_job['id'] );
$rates->save_rate( 300000 );

$orphan_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $orphan_job['id'] );

$orphan_items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT * FROM ' . Database::items_table() . ' WHERE job_id = %d', (int) $orphan_job['id'] ),
	ARRAY_A
);

usdtf_it_assert_same( 2, count( $orphan_items ), 'the managed variations under the excluded parent must be discovered on their own' );
usdtf_it_assert_same( 'variation', $orphan_items[0]['object_type'], 'the discovered items must be variations' );

$orphan_children = $orphan_variable->get_children();
usdtf_it_assert_same( '3000000', usdtf_it_price( (int) $orphan_children[0] ), 'the managed variation must have been written' );
usdtf_it_assert_same( '6000000', usdtf_it_price( (int) $orphan_children[1] ), 'the managed variation must have been written' );

usdtf_it_assert_same( '', usdtf_it_price( $orphan_variable->get_id() ), 'the excluded parent itself must stay untouched' );
usdtf_it_assert_same( '', usdtf_it_meta( $orphan_variable->get_id(), Product_Pricing::META_USD_REGULAR ), 'the excluded parent must get no USD price' );

usdtf_it_pass( 'managed variations under an unmanaged parent are discovered' );

// ---------------------------------------------------------------------------
// 41. Variation IDs are paginated: a product with more variations than one
// slice is synchronized completely.
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

add_filter(
	'usdtf_variation_slice',
	function () {
		return 5;
	}
);

// The first job generates every USD price, the second one writes a new rate.
$paged_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $paged_job['id'] );

$paged_job_row = usdtf_plugin()->jobs()->get( (int) $paged_job['id'] );
usdtf_it_assert_same( 12, (int) $paged_job_row->data['variations_processed'], 'a product with twelve variations and five per slice must process all of them' );

foreach ( $paged_product->get_children() as $paged_child_id ) {
	usdtf_it_assert( '' !== usdtf_it_meta( $paged_child_id, Product_Pricing::META_USD_REGULAR ), 'every variation of the product must get its USD price' );
}

$rates->save_rate( 300000 );
$paged_job = usdtf_it_create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $paged_job['id'] );

remove_all_filters( 'usdtf_variation_slice' );

$paged_job_row = usdtf_plugin()->jobs()->get( (int) $paged_job['id'] );
usdtf_it_assert_same( 12, (int) $paged_job_row->data['variations_processed'], 'the rate change must process all twelve variations again' );
usdtf_it_assert_same( 12, (int) $paged_job_row->data['variations_changed'], 'every variation of the product must have been written' );

foreach ( $paged_product->get_children() as $paged_index => $paged_child_id ) {
	usdtf_it_assert( $usdtf_it_paged_prices[ $paged_index ][0] !== usdtf_it_price( $paged_child_id ), 'every variation of the product must have been written' );
}

usdtf_it_pass( 'variation IDs are paginated and nothing is cut off' );

// ---------------------------------------------------------------------------
// 43. Only the capability allowlist is storable.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();

usdtf_it_assert( in_array( 'read', \USDTF\Capabilities::allowed(), true ) === false, 'weak capabilities must not be selectable' );

$weak = \USDTF\Settings::sanitize( array( 'required_capability' => 'read' ) );
usdtf_it_assert_same( \USDTF\Capabilities::DEFAULT_CAPABILITY, $weak['required_capability'], 'a weak capability must fall back to the default' );

$strong = \USDTF\Settings::sanitize( array( 'required_capability' => 'manage_options' ) );
usdtf_it_assert_same( 'manage_options', $strong['required_capability'], 'the allowlist capabilities must be storable' );

// sanitize() is the registered sanitize_callback of the option, and options.php
// hands that callback only the fields the screen submitted. A partial update
// must keep the stored values instead of raising notices and resetting
// everything the form did not send.
usdtf_plugin()->settings()->update(
	array(
		'batch_size'        => 25,
		'loopback_fallback' => false,
		'credit_author'     => true,
	)
);

$notices = array();

set_error_handler(
	static function ( $severity, $message ) use ( &$notices ) {
		$notices[] = $message;

		return true;
	},
	E_WARNING | E_NOTICE
);

$partial = \USDTF\Settings::sanitize( array( 'required_capability' => 'manage_options' ) );

restore_error_handler();

usdtf_it_assert_same( array(), $notices, 'a partial update must not raise undefined array key warnings' );
usdtf_it_assert_same( 25, $partial['batch_size'], 'a partial update must keep the stored batch size' );
usdtf_it_assert_same( false, $partial['loopback_fallback'], 'a partial update must keep the stored booleans' );
usdtf_it_assert_same( true, $partial['credit_author'], 'a setting without a form field must survive a partial update' );
usdtf_it_assert_same( 'manage_options', $partial['required_capability'], 'the submitted capability must still be validated' );

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

// The whole option path as a browser drives it: the settings screen posts only
// the fields it rendered, with the hidden 0 of a cleared checkbox standing in
// for the missing box. Everything the submit does not mention must stay put.
usdtf_plugin()->settings()->update(
	array(
		'batch_size'        => 25,
		'loopback_fallback' => true,
		'credit_author'     => true,
		'rounding'          => \USDTF\Calculator::ROUND_NEAREST,
	)
);

usdtf_it_assert(
	false !== has_filter( 'sanitize_option_' . \USDTF\Settings::OPTION ),
	'the settings screen must register the option sanitizer'
);

$rounding_before = get_option( \USDTF\Settings::OPTION )['rounding'];

update_option( \USDTF\Settings::OPTION, array( 'loopback_fallback' => '0' ) );

$saved = get_option( \USDTF\Settings::OPTION );

usdtf_it_assert_same( false, $saved['loopback_fallback'], 'clearing a boolean checkbox must store false' );
usdtf_it_assert_same( 25, $saved['batch_size'], 'a partial submit must keep the untouched numbers' );
usdtf_it_assert_same( true, $saved['credit_author'], 'a partial submit must keep the settings it did not send' );
usdtf_it_assert_same( \USDTF\Calculator::ROUND_NEAREST, $rounding_before, 'the rounding fixture must be stored before the partial submit' );
usdtf_it_assert_same( $rounding_before, $saved['rounding'], 'a partial submit must keep the rounding mode' );

usdtf_plugin()->settings()->reset();

usdtf_it_pass( 'only the capability allowlist is storable' );

// ---------------------------------------------------------------------------
// 45. Maintenance cron can schedule its custom recurrence even on activation.
// ---------------------------------------------------------------------------
\USDTF\Cron::unschedule();
\USDTF\Cron::schedule();

usdtf_it_assert( false !== wp_next_scheduled( \USDTF\Cron::EVENT_TICK ), 'the five-minute recovery cron must be scheduled even when schedule() runs before Cron::hooks()' );
usdtf_it_assert( false !== wp_next_scheduled( \USDTF\Cron::EVENT_DAILY ), 'the daily maintenance cron must be scheduled' );
usdtf_it_pass( 'maintenance cron registers its recurrence before scheduling' );

// ---------------------------------------------------------------------------
// 46. Bulk mode changes cover a variable product and all of its variations.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$bulk_variable = usdtf_it_make_variable_product(
	'Bulk mode variable',
	array(
		array( '1000000' ),
		array( '2000000' ),
		array( '3000000' ),
		array( '4000000' ),
		array( '5000000' ),
		array( '6000000' ),
	)
);

$panel = new \USDTF\Admin\Product_Panel( $settings, $pricing, usdtf_plugin()->products() );
$panel->handle_bulk_actions( admin_url( 'edit.php?post_type=product' ), 'usdtf_exclude', array( $bulk_variable->get_id() ) );

usdtf_it_assert_same( \USDTF\Product_Pricing::MODE_EXCLUDED, $pricing->get_mode( $bulk_variable->get_id() ), 'the variable parent must receive the bulk mode' );

foreach ( usdtf_plugin()->products()->all_variation_ids( $bulk_variable->get_id() ) as $bulk_child_id ) {
	usdtf_it_assert_same( \USDTF\Product_Pricing::MODE_EXCLUDED, $pricing->get_mode( $bulk_child_id ), 'every variation must receive the parent bulk mode too' );
}

usdtf_it_pass( 'bulk mode changes propagate to every variation' );

// ---------------------------------------------------------------------------
// 47. Deleting a managed product takes its plugin meta with it.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();
$rates->save_rate( 270000 );

// The product is synced first, so it carries the plugin's USD price meta: that
// is the state a product is in when a store owner deletes it. Deleting a
// product removes its meta row by row, and anything written during that loop
// outlives the product as junk in wp_postmeta.
$doomed     = usdtf_it_make_simple_product( 'Doomed product', '5400000' );
$doomed_id  = $doomed->get_id();
$doomed_job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );

usdtf_it_run_job( (int) $doomed_job['id'] );

usdtf_it_assert( '' !== usdtf_it_price( $doomed_id ), 'the product must carry a WooCommerce price before it is deleted' );
usdtf_it_assert( '' !== usdtf_it_meta( $doomed_id, Product_Pricing::META_USD_REGULAR ), 'the product must carry its USD price before it is deleted' );

wp_delete_post( $doomed_id, true );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$doomed_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $doomed_id ) );

usdtf_it_assert_same( 0, $doomed_meta, 'deleting a managed product must leave no meta rows behind for its id' );
usdtf_it_pass( 'deleting a managed product leaves no orphaned plugin meta' );

// ---------------------------------------------------------------------------
// 48. Cross-request workers and real HTTP REST (separate files).
// ---------------------------------------------------------------------------
// Assisted (single-thread php -S) via CLI wake; autonomous (passive) requires concurrent server.
require __DIR__ . '/crossrequest.php';
require __DIR__ . '/http.php'; // assisted: usdtf_it_http_wait_job with wake
require __DIR__ . '/http-autonomous.php'; // passive: usdtf_it_http_wait_job_passive, skips on php -S
require __DIR__ . '/regression.php';
