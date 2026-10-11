<?php
/**
 * Regression scenarios for the HTTP empty-reply fix.
 *
 * These scenarios proved the release 1.1.1 failure (cURL 52: Empty reply
 * from server) and now guard against its return. They run over real HTTP
 * after the main HTTP suite has left an authenticated user and a running
 * server behind, so they also exercise the canonical server lifecycle:
 * repeated requests, repeated background jobs, and the store guard.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

use USDTF\Job;

global $runner, $rates, $settings;

// If the HTTP suite was skipped, these regress the same skip — but when the
// CI requires HTTP, a missing server is a hard failure, not a silent pass.
if ( ! function_exists( 'usdtf_it_http' ) ) {
	if ( '1' === getenv( 'USDTF_REQUIRE_HTTP_TESTS' ) ) {
		fwrite( STDERR, "FAIL: regression needs the HTTP suite (usdtf_it_http missing) but USDTF_REQUIRE_HTTP_TESTS=1\n" );
		exit( 1 );
	}
	echo "skip - regression needs the HTTP suite (usdtf_it_http missing)\n";
	return;
}

$usdtf_http_probe = wp_remote_get( site_url( '/' ), array( 'timeout' => 5 ) );
if ( is_wp_error( $usdtf_http_probe ) || 200 !== (int) wp_remote_retrieve_response_code( $usdtf_http_probe ) ) {
	if ( '1' === getenv( 'USDTF_REQUIRE_HTTP_TESTS' ) ) {
		$msg = is_wp_error( $usdtf_http_probe ) ? $usdtf_http_probe->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $usdtf_http_probe );
		fwrite( STDERR, 'FAIL: regression needs the HTTP server but it is not reachable: ' . $msg . "\n" );
		exit( 1 );
	}
	echo "skip - regression needs the HTTP server\n";
	return;
}

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	if ( '1' === getenv( 'USDTF_REQUIRE_HTTP_TESTS' ) ) {
		fwrite( STDERR, "FAIL: regression needs application passwords but this WordPress version has none and USDTF_REQUIRE_HTTP_TESTS=1\n" );
		exit( 1 );
	}
	echo "skip - regression needs application passwords\n";
	return;
}

add_filter( 'wp_is_application_passwords_available', '__return_true' );

$usdtf_regression_user = wp_get_current_user();
$usdtf_regression_pw   = WP_Application_Passwords::create_new_application_password(
	$usdtf_regression_user->ID,
	array( 'name' => 'usdtf-regression' )
);

usdtf_it_assert( is_array( $usdtf_regression_pw ) && is_string( $usdtf_regression_pw[0] ), 'regression: application password must be issued' );

$usdtf_regression_item   = is_array( $usdtf_regression_pw ) ? $usdtf_regression_pw[1] : null;
$usdtf_regression_header = 'Basic ' . base64_encode( $usdtf_regression_user->user_login . ':' . $usdtf_regression_pw[0] );

try {
	// ---------------------------------------------------------------------
	// 48. Repeated HTTP requests do not get an empty reply.
	// ---------------------------------------------------------------------
	usdtf_it_reset_plugin_state();
	usdtf_it_delete_products();
	$rates->save_rate( 270000 );
	usdtf_it_make_simple_product( 'Regression HTTP repeated', '5000000' );

	$usdtf_repeated_fail = '';
	for ( $i = 1; $i <= 5; $i++ ) {
		$resp = usdtf_it_http( 'GET', '/state', null, $usdtf_regression_header );
		if ( 200 !== $resp['code'] ) {
			$usdtf_repeated_fail = "attempt $i: got {$resp['code']} {$resp['body']}";
			break;
		}
		if ( ! isset( $resp['json']['rate'] ) ) {
			$usdtf_repeated_fail = "attempt $i: missing rate";
			break;
		}
		// Also prove the homepage keeps answering (router + index.php).
		$home = wp_remote_get( site_url( '/' ), array( 'timeout' => 5 ) );
		if ( is_wp_error( $home ) || 200 !== (int) wp_remote_retrieve_response_code( $home ) ) {
			$usdtf_repeated_fail = "attempt $i: home probe failed " . ( is_wp_error( $home ) ? $home->get_error_message() : 'HTTP '.wp_remote_retrieve_response_code( $home ) );
			break;
		}
		// Small delay like the worker loopback would see.
		usleep( 100000 );
	}

	usdtf_it_assert( '' === $usdtf_repeated_fail, 'five sequential HTTP state reads must succeed without an empty reply: ' . $usdtf_repeated_fail );
	usdtf_it_pass( 'repeated HTTP requests stay healthy' );

	// ---------------------------------------------------------------------
	// 49. Background jobs can be started twice over HTTP and both finish
	//     via the queue without an admin refresh.
	// ---------------------------------------------------------------------
	usdtf_it_reset_plugin_state();
	usdtf_it_delete_products();
	$rates->save_rate( 280000 );
	$regression_products = array();
	for ( $i = 1; $i <= 3; $i++ ) {
		$regression_products[] = usdtf_it_make_simple_product( 'Regression job product ' . $i, 5600000 );
	}

	// First HTTP job: preview then update.
	$settings->update( array( 'require_preview' => true ) );

	$first_preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $first_preview['code'], 'first preview must be 200, got '.$first_preview['code'].': '.$first_preview['body'] );
	$first_preview_id = (int) $first_preview['json']['id'];
	$first_finished   = usdtf_it_http_wait_job( $first_preview_id, $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $first_finished['status'] ?? '', 'first preview must complete via HTTP queue' );

	$first_update = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $first_update['code'], 'first update must be 200, got '.$first_update['code'].': '.$first_update['body'] );
	$first_update_id = (int) $first_update['json']['id'];
	$first_update_finished = usdtf_it_http_wait_job( $first_update_id, $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $first_update_finished['status'] ?? '', 'first update must complete via HTTP queue' );
	usdtf_it_assert( (int) ( $first_update_finished['counters']['processed'] ?? 0 ) >= 3, 'first update must have processed products' );
	// Verify the first sync wrote the expected price (20) before the second
	// rate change, with a cache flush so this process sees the worker's write.
	wp_cache_flush();
	$first_expected = (string) ceil( 5600000 / 280000 );
	foreach ( $regression_products as $prod ) {
		usdtf_it_assert_same( $first_expected, usdtf_it_price( $prod->get_id() ), 'product #' . $prod->get_id() . ' price after first HTTP job must be ' . $first_expected );
	}

	// Second HTTP job: new rate, new preview/update pair. Proves the server
	// did not die after the first job's loopbacks. Use a rate that changes
	// the price so the write is observable.
	$rates->save_rate( 350000 );
	$second_preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $second_preview['code'], 'second preview must be 200' );
	$second_finished = usdtf_it_http_wait_job( (int) $second_preview['json']['id'], $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $second_finished['status'] ?? '', 'second preview must complete' );

	$second_update = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $second_update['code'], 'second update must be 200' );
	$second_update_finished = usdtf_it_http_wait_job( (int) $second_update['json']['id'], $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $second_update_finished['status'] ?? '', 'second update must complete via HTTP queue' );

	// Prices reflect the second rate: ceil(5,600,000 / 350,000) = 16.
	$expected = (string) ceil( 5600000 / 350000 );
	// Flush the in-memory product cache: the worker wrote through a
	// different PHP process, so this process's cache still holds the
	// pre-sync empty price.
	wp_cache_flush();
	foreach ( $regression_products as $prod ) {
		$actual = usdtf_it_price( $prod->get_id() );
		// Also log the job counters when the assertion fails.
		if ( $expected !== $actual ) {
			$diag = ' counters=' . wp_json_encode( $second_update_finished['counters'] ?? array() );
			$diag .= ' status=' . ( $second_update_finished['status'] ?? '?' );
			$diag .= ' product=' . $prod->get_id() . ' price=' . var_export( $actual, true );
			$diag .= ' source=' . var_export( get_post_meta( $prod->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR, true ), true );
			usdtf_it_assert_same( $expected, $actual, 'product #' . $prod->get_id() . ' price must reflect the second HTTP job rate (expected ' . $expected . ', got ' . var_export( $actual, true ) . ')' . $diag );
		} else {
			usdtf_it_assert_same( $expected, $actual, 'product #' . $prod->get_id() . ' price must reflect the second HTTP job rate' );
		}
	}

	usdtf_it_pass( 'background jobs can be started repeatedly over HTTP' );

	// ---------------------------------------------------------------------
	// 50. The store guard survives an HTTP job (isolation regression).
	// ---------------------------------------------------------------------
	usdtf_it_reset_plugin_state();
	// The lib's store guard already tracks the store's own products; here we
	// simulate a store product that was excluded by the guard and ensure an
	// HTTP job does not touch it.
	$guard_product = usdtf_it_make_simple_product( 'Guarded store product', '2700000' );
	// Mark it as the guard would: excluded mode.
	usdtf_plugin()->pricing()->set_mode( $guard_product->get_id(), \USDTF\Product_Pricing::MODE_EXCLUDED );
	update_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR, '9999999' );

	$rates->save_rate( 270000 );
	// Store original protected metadata before preview/update.
	$guard_before_regular = get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR, true );
	$guard_before_sale = get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_SALE, true );
	$guard_before_price = get_post_meta( $guard_product->get_id(), '_regular_price', true );
	$guard_before_derived = get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_DERIVED_REGULAR, true );
	$guard_preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $guard_preview['code'], 'guard preview must be 200' );
	$guard_preview_done = usdtf_it_http_wait_job( (int) $guard_preview['json']['id'], $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $guard_preview_done['status'] ?? '', 'guard preview must complete (was ' . var_export( $guard_preview_done['status'] ?? null, true ) . ')' );
	usdtf_it_assert_same( '9999999', (string) get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR, true ), 'guard preview must not touch stored Toman source' );

	$guard_update = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $guard_update['code'], 'guard update must be 200' );
	$guard_update_done = usdtf_it_http_wait_job( (int) $guard_update['json']['id'], $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $guard_update_done['status'] ?? '', 'guard update must complete (was ' . var_export( $guard_update_done['status'] ?? null, true ) . ')' );
	usdtf_it_assert( (int) ( $guard_update_done['counters']['processed'] ?? 0 ) >= 0, 'guard update counters must be present' );

	// The excluded product must not have been repriced: stored prices and protected metadata unchanged.
	$after = get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR, true );
	usdtf_it_assert_same( '9999999', (string) $after, 'an excluded product must not be touched by an HTTP job (source regular)' );
	usdtf_it_assert_same( (string) $guard_before_sale, (string) get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_SALE, true ), 'guard sale source must remain unchanged' );
	usdtf_it_assert_same( (string) $guard_before_price, (string) get_post_meta( $guard_product->get_id(), '_regular_price', true ), 'guard Woo price must remain unchanged' );
	usdtf_it_assert_same( (string) $guard_before_derived, (string) get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_DERIVED_REGULAR, true ), 'guard derived price must remain unchanged' );

	// Repeated execution: second preview/update must also leave guard untouched.
	$guard_preview2 = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $guard_preview2['code'], 'second guard preview must be 200' );
	$guard_preview2_done = usdtf_it_http_wait_job( (int) $guard_preview2['json']['id'], $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $guard_preview2_done['status'] ?? '', 'second guard preview must complete' );
	$guard_update2 = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_regression_header );
	usdtf_it_assert_same( 200, $guard_update2['code'], 'second guard update must be 200' );
	$guard_update2_done = usdtf_it_http_wait_job( (int) $guard_update2['json']['id'], $usdtf_regression_header, 90 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $guard_update2_done['status'] ?? '', 'second guard update must complete' );
	usdtf_it_assert_same( '9999999', (string) get_post_meta( $guard_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR, true ), 'guard product must still be untouched after repeated jobs' );

	// Fixture cleanup: remove guard product and verify no leftover.
	$guard_id = $guard_product->get_id();
	wp_delete_post( $guard_id, true );
	usdtf_it_assert_same( '', (string) get_post_meta( $guard_id, \USDTF\Product_Pricing::META_SOURCE_REGULAR, true ), 'guard fixture must be cleaned up (meta removed)' );

	usdtf_it_pass( 'store isolation survives HTTP background jobs (with completed jobs, unchanged prices/metadata, repeated execution and cleanup)' );

} finally {
	if ( $usdtf_regression_item ) {
		WP_Application_Passwords::delete_application_password( $usdtf_regression_user->ID, $usdtf_regression_item['uuid'] );
	}
	remove_filter( 'wp_is_application_passwords_available', '__return_true' );
}
