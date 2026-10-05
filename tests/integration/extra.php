<?php
/**
 * Storefront, rounding, retention, status and capability scenarios.
 *
 * Included by run.php, which boots WordPress and WooCommerce and exposes the
 * plugin services the scenarios below reuse ($settings, $rates, $runner,
 * $pricing, $jobs).
 *
 * @package USDTF
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixtures.

// ---------------------------------------------------------------------------
// 15. Storefront display: Toman for managed products, untouched USD otherwise.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );
$settings->update(
	array(
		'display_toman'    => true,
		'persian_digits'   => false,
		'display_toman_cart' => true,
	)
);

$display_product = usdtf_it_make_simple_product( 'Displayed simple product', 5000000 );
$display_job     = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $display_job['id'] );

// This is what WooCommerce hands to the shop loop, the single product page, the
// related/upsell loops and the Store API `price_html` field.
$usd_html = '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">$</span>19</bdi></span>';
$shown    = apply_filters( 'woocommerce_get_price_html', $usd_html, $display_product );

usdtf_it_assert( false !== strpos( $shown, '5,000,000 تومان' ), 'the storefront price must show the stored Toman value' );
usdtf_it_assert( false === strpos( $shown, 'woocommerce-Price-currencySymbol' ), 'the derived USD price must not leak into the storefront price' );

$variable_display = usdtf_it_make_variable_product( 'Displayed variable product', array( array( 2000000 ), array( 5000000 ) ) );
$variable_job     = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $variable_job['id'] );

$range = apply_filters( 'woocommerce_get_price_html', $usd_html, $variable_display );

usdtf_it_assert( false !== strpos( $range, '2,000,000 تومان' ), 'a variable product must show its lowest Toman variation' );
usdtf_it_assert( false !== strpos( $range, '5,000,000 تومان' ), 'a variable product must show its highest Toman variation' );
usdtf_it_assert( false !== strpos( $range, 'usdtf-price-range' ), 'a variable product must render a Toman range' );

$cart_price = apply_filters( 'woocommerce_cart_item_price', $usd_html, array( 'data' => $display_product ), 'usdtf-cart-key' );
usdtf_it_assert( false !== strpos( $cart_price, '5,000,000 تومان' ), 'the mini cart and cart must show the Toman value next to the USD price' );

$native_product = usdtf_it_make_simple_product( 'Native USD product', '', '', \USDTF\Product_Pricing::MODE_NATIVE );
$native_product = wc_get_product( $native_product->get_id() );
$native_product->set_regular_price( '42' );
$native_product->save();

usdtf_it_assert_same(
	$usd_html,
	apply_filters( 'woocommerce_get_price_html', $usd_html, $native_product ),
	'a native USD product must keep its USD price on the storefront'
);
usdtf_it_pass( 'the storefront shows Toman for managed products only' );

// ---------------------------------------------------------------------------
// 16. Rounding modes, increments and the guards around the settings.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

// 1,000,000 / 270,000 = 3.7037, so the three modes are easy to tell apart.
$rounding_product = usdtf_it_make_simple_product( 'Rounding mode product', 1000000 );

$settings->update(
	array(
		'rounding'  => \USDTF\Calculator::ROUND_NEAREST,
		'increment' => 1,
	)
);
$rounding_job = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $rounding_job['id'] );
usdtf_it_assert_same( '4', usdtf_it_price( $rounding_product->get_id() ), 'the nearest mode must round 3.70 up to 4' );

$settings->update( array( 'rounding' => \USDTF\Calculator::ROUND_DOWN ) );
$rounding_job = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $rounding_job['id'] );
usdtf_it_assert_same( '3', usdtf_it_price( $rounding_product->get_id() ), 'the downward mode must round 3.70 down to 3' );

$settings->update(
	array(
		'rounding'  => \USDTF\Calculator::ROUND_UP,
		'increment' => 5,
	)
);
$rounding_job = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $rounding_job['id'] );
usdtf_it_assert_same( '5', usdtf_it_price( $rounding_product->get_id() ), 'a $5 increment must round 3.70 up to 5' );
usdtf_it_assert_same( '1000000', usdtf_it_meta( $rounding_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'every mode must keep converting from the same Toman source' );

// Dangerous values must not be storable, even if they arrive from a request.
$settings->update(
	array(
		'batch_size' => 500,
		'increment'  => 500,
		'rounding'   => 'sideways',
	)
);
usdtf_it_assert_same( 10, $settings->batch_size(), 'an unsafe batch size must fall back to the documented default' );
usdtf_it_assert_same( 1.0, (float) $settings->get( 'increment' ), 'an unsupported increment must fall back to $1' );
usdtf_it_assert_same( \USDTF\Calculator::ROUND_UP, (string) $settings->get( 'rounding' ), 'an unknown rounding mode must fall back to upward' );
usdtf_it_assert_same( array( 5, 10, 15, 20, 25 ), array_map( 'intval', \USDTF\Settings::allowed_batch_sizes() ), 'only the documented batch sizes must be offered' );
usdtf_it_pass( 'rounding modes, increments and settings guards' );

// ---------------------------------------------------------------------------
// 17. The retention window trims old job items and logs.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$retention_product = usdtf_it_make_simple_product( 'Retention product', 2700000 );
$retention_job     = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $retention_job['id'] );
$retention_job_id = (int) $retention_job['id'];

$items_table = \USDTF\Database::items_table();
$logs_table  = \USDTF\Database::logs_table();
$old_stamp   = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );

$wpdb->query( $wpdb->prepare( "UPDATE `{$items_table}` SET updated_at = %s WHERE job_id = %d", $old_stamp, $retention_job_id ) );
$wpdb->query( $wpdb->prepare( "UPDATE `{$logs_table}` SET created_at = %s", $old_stamp ) );

usdtf_it_assert_same( 0, $jobs->purge_items_older_than( 0 ), 'a retention of zero days must keep everything' );
usdtf_it_assert_same( 0, (int) usdtf_plugin()->logger()->purge_older_than( 0 ), 'log cleanup must also be disabled at zero days' );

usdtf_it_assert( $jobs->purge_items_older_than( 30 ) >= 1, 'job items older than the window must be deleted' );
usdtf_it_assert_same( 0, count( $jobs->items( $retention_job_id ) ), 'nothing may remain from the expired job' );
usdtf_it_assert( usdtf_plugin()->logger()->purge_older_than( 30 ) >= 1, 'expired log rows must be deleted' );
usdtf_it_assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$logs_table}`" ), 'the log table must be empty after the cleanup' );

wp_delete_post( $retention_product->get_id(), true );

$fresh_product = usdtf_it_make_simple_product( 'Fresh retention product', 5400000 );
$fresh_job     = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $fresh_job['id'] );

usdtf_plugin()->logger()->info( 'Fresh log entry.' );

$jobs->purge_items_older_than( 30 );
usdtf_plugin()->logger()->purge_older_than( 30 );

usdtf_it_assert_same( 1, count( $jobs->items( (int) $fresh_job['id'] ) ), 'recent job items must survive the cleanup' );
usdtf_it_assert(
	1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$logs_table}` WHERE message = %s", 'Fresh log entry.' ) ),
	'the recent fixture entry must survive the cleanup'
);
usdtf_it_pass( 'the retention window trims old job items and logs only' );

// ---------------------------------------------------------------------------
// 18. Product statuses: drafts and private products sync, trash is ignored.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$published = usdtf_it_make_simple_product( 'Status published', 2700000 );
$draft     = usdtf_it_make_simple_product( 'Status draft', 5400000 );
$private   = usdtf_it_make_simple_product( 'Status private', 8100000 );
$trashed   = usdtf_it_make_simple_product( 'Status trashed', 13500000 );

foreach ( array( $draft, $private ) as $index => $status_product ) {
	$status_edit = wc_get_product( $status_product->get_id() );
	$status_edit->set_status( 0 === $index ? 'draft' : 'private' );
	$status_edit->save();
}

$trashed_edit = wc_get_product( $trashed->get_id() );
$trashed_edit->set_regular_price( '77' );
$trashed_edit->save();
wp_trash_post( $trashed->get_id() );

$status_job = $runner->create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $status_job['id'] );

usdtf_it_assert_same( '10', usdtf_it_price( $published->get_id() ), 'a published product must be synchronized' );
usdtf_it_assert_same( '20', usdtf_it_price( $draft->get_id() ), 'a draft product must be synchronized' );
usdtf_it_assert_same( '30', usdtf_it_price( $private->get_id() ), 'a private product must be synchronized' );
usdtf_it_assert_same( '77', usdtf_it_price( $trashed->get_id() ), 'a trashed product must keep its price' );

$statuses = usdtf_it_item_statuses( (int) $status_job['id'] );
usdtf_it_assert( ! isset( $statuses[ $trashed->get_id() ] ), 'a trashed product must not even be queued' );
usdtf_it_pass( 'drafts and private products sync, trash is ignored' );

// ---------------------------------------------------------------------------
// 19. Only users with the required capability may touch the REST API.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();

$administrators = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$administrator  = $administrators ? (int) $administrators[0] : 0;

$subscriber = wp_insert_user(
	array(
		'user_login' => 'usdtf_subscriber_' . wp_rand( 1000, 999999 ),
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'subscriber',
	)
);

usdtf_it_assert( ! is_wp_error( $subscriber ), 'the subscriber fixture must be created' );

$subscriber_request = new \WP_REST_Request( 'POST', '/usdtf/v1/rate' );
$subscriber_request->set_param( 'rate', '270000' );

wp_set_current_user( (int) $subscriber );

$subscriber_response = rest_do_request( $subscriber_request );

usdtf_it_assert( ! \USDTF\Capabilities::current_user_can(), 'a subscriber must not hold the pricing capability' );
usdtf_it_assert(
	$subscriber_response->is_error() || $subscriber_response->get_status() >= 400,
	'a REST mutation must be rejected for a subscriber'
);

if ( $administrator > 0 ) {
	wp_set_current_user( $administrator );
	usdtf_it_assert( \USDTF\Capabilities::current_user_can(), 'an administrator must hold the pricing capability' );
	usdtf_it_assert( \USDTF\Capabilities::user_can( $administrator ), 'the capability check must accept the administrator id' );
}

wp_set_current_user( 0 );

require_once ABSPATH . 'wp-admin/includes/user.php';

wp_delete_user( (int) $subscriber );
usdtf_it_pass( 'the REST API requires the pricing capability' );
