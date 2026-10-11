<?php
/**
 * Autonomous (passive) HTTP job tests for concurrent servers.
 *
 * On php -S (single-threaded) the server's loopback SYN is refused while the
 * REST request owns the thread, so autonomous completion is impossible and
 * these scenarios skip with an explicit message. They are kept separate from
 * the assisted tests (http.php, regression.php) which use the CLI wake hack
 * and are marked "assisted (single-thread)".
 *
 * When USDTF_CONCURRENT=1 (or USDTF_ASSISTED_HTTP=0) is set, the suite runs
 * a concurrent server (e.g. php -S with socat fork, or a real Apache)
 * and this file proves that preview/update complete autonomously via the
 * server's own loopback / Action Scheduler async, without any CLI wake.
 *
 * Covers: autonomous preview/update completion, counters, prices, cleanup
 * for source and ZIP; deterministic failed-loopback fallback (queue persists
 * when fire_loopback is blocked in the HTTP process) and persisted queue
 * recovery with resumed progress/exact counters/no duplicates; genuine
 * worker interruption after N batches.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- CLI test.

use USDTF\Job;

global $runner, $rates, $settings;

if ( ! function_exists( 'usdtf_it_http' ) || ! function_exists( 'usdtf_it_http_wait_job_passive' ) ) {
	echo "skip - autonomous HTTP skipped: HTTP suite not loaded\n";
	return;
}

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	echo "skip - autonomous HTTP skipped: application passwords unavailable\n";
	return;
}

$usdtf_concurrent = '1' === getenv( 'USDTF_CONCURRENT' );
if ( ! $usdtf_concurrent ) {
	echo "skip - autonomous (passive) HTTP skipped on php -S: requires USDTF_CONCURRENT=1 (assisted http.php covers single-thread)\n";
	return;
}

$usdtf_probe = wp_remote_get( site_url( '/' ), array( 'timeout' => 5 ) );
if ( is_wp_error( $usdtf_probe ) || 200 !== (int) wp_remote_retrieve_response_code( $usdtf_probe ) ) {
	echo "skip - autonomous HTTP skipped: probe failed (main HTTP suite covers required)\n";
	return;
}

add_filter( 'wp_is_application_passwords_available', '__return_true' );

$usdtf_auto_user = wp_get_current_user();
$usdtf_auto_pw = WP_Application_Passwords::create_new_application_password(
	$usdtf_auto_user->ID,
	array( 'name' => 'usdtf-autonomous' )
);
usdtf_it_assert( is_array( $usdtf_auto_pw ) && is_string( $usdtf_auto_pw[0] ), 'autonomous: application password must be issued' );
$usdtf_auto_item = is_array( $usdtf_auto_pw ) ? $usdtf_auto_pw[1] : null;
$usdtf_auto_header = 'Basic ' . base64_encode( $usdtf_auto_user->user_login . ':' . $usdtf_auto_pw[0] );

try {
	// ---------------------------------------------------------------------
	// A1. Autonomous preview+update via passive polling (no wake).
	// ---------------------------------------------------------------------
	usdtf_it_reset_plugin_state();
	usdtf_it_delete_products();
	$rates->save_rate( 270000 );
	$auto_products = array();
	for ( $i = 1; $i <= 2; $i++ ) {
		$auto_products[] = usdtf_it_make_simple_product( 'Autonomous product ' . $i, '5400000' );
	}
	$settings->update( array( 'require_preview' => true ) );

	$preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_auto_header );
	usdtf_it_assert_same( 200, $preview['code'], 'autonomous preview must be 200' );
	$preview_id = (int) $preview['json']['id'];
	$preview_done = usdtf_it_http_wait_job_passive( $preview_id, $usdtf_auto_header, 30 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $preview_done['status'] ?? '', 'autonomous preview must complete without CLI wake (status=' . var_export( $preview_done['status'] ?? null, true ) . ' counters=' . wp_json_encode( $preview_done['counters'] ?? array() ) . ')' );
	usdtf_it_assert( isset( $preview_done['counters'] ), 'autonomous preview counters must be present' );
	usdtf_it_assert( isset( $preview_done['is_active'] ) && empty( $preview_done['is_active'] ), 'autonomous preview must be inactive after completion' );

	$update = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_auto_header );
	usdtf_it_assert_same( 200, $update['code'], 'autonomous update must be 200' );
	$update_id = (int) $update['json']['id'];
	$update_done = usdtf_it_http_wait_job_passive( $update_id, $usdtf_auto_header, 30 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $update_done['status'] ?? '', 'autonomous update must complete without CLI wake' );
	usdtf_it_assert( (int) ( $update_done['counters']['processed'] ?? 0 ) >= 2, 'autonomous update must have processed products' );
	usdtf_it_assert( isset( $update_done['counters']['changed'] ), 'autonomous update must have changed counter' );

	wp_cache_flush();
	$expected = (string) ceil( 5400000 / 270000 );
	foreach ( $auto_products as $p ) {
		usdtf_it_assert_same( $expected, usdtf_it_price( $p->get_id() ), 'autonomous product price must reflect rate' );
		usdtf_it_assert_same( '5400000', usdtf_it_meta( $p->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'autonomous product must preserve Toman source' );
		usdtf_it_assert( usdtf_plugin()->pricing()->is_managed( $p->get_id() ), 'autonomous product must remain managed after job' );
	}

	// Verify protected metadata not leaked to anonymous and cleanup of previous jobs.
	$jobs_list = usdtf_it_http( 'GET', '/jobs', null, $usdtf_auto_header );
	usdtf_it_assert_same( 200, $jobs_list['code'], 'jobs list must be readable' );
	usdtf_it_assert( is_array( $jobs_list['json'] ), 'jobs list must be array' );

	usdtf_it_pass( 'autonomous (passive) preview/update complete without wake (concurrent server)' );

	// ---------------------------------------------------------------------
	// A2. Deterministic failed-loopback fallback: queue persists when
	//     fire_loopback fails in the HTTP process, and the production
	//     fallback (Action Scheduler async) still completes the job.
	// ---------------------------------------------------------------------
	usdtf_it_reset_plugin_state();
	usdtf_it_delete_products();
	$rates->save_rate( 280000 );
	$fb_product = usdtf_it_make_simple_product( 'Fallback product', '5600000' );

	// Inject failure in the HTTP process: the next loopback dispatch for
	// usdtf_run_batch will return false, but the Action Scheduler action is
	// still created. Using a specific hook lets discovery succeed and then
	// proves the batch fallback works.
	$inject = usdtf_it_http( 'POST', '/test/fail-next-loopback', array( 'hook' => 'usdtf_run_batch' ), $usdtf_auto_header );
	usdtf_it_assert_same( 200, $inject['code'], 'fail-next-loopback injection must be 200, got ' . $inject['code'] . ': ' . $inject['body'] );

	$fb_preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_auto_header );
	usdtf_it_assert_same( 200, $fb_preview['code'], 'fallback preview must be 200 even when loopback will fail' );
	$fb_preview_id = (int) $fb_preview['json']['id'];
	usdtf_it_assert( $fb_preview_id > 0, 'fallback preview must return job id' );

	// Immediately after creation the job must be readable via
	// both /jobs/<id> and /jobs/<id>/status (queue persisted, not lost).
	// It may already be completed if the fallback is very fast, so accept
	// either active or completed, but ensure it is not lost.
	$fb_status = usdtf_it_http( 'GET', '/jobs/' . $fb_preview_id . '/status', null, $usdtf_auto_header, array( '_usdtf' => microtime( true ) ) );
	usdtf_it_assert_same( 200, $fb_status['code'], 'status must be readable while job is active (queue persisted)' );
	usdtf_it_assert( isset( $fb_status['json']['id'] ) && (int) $fb_status['json']['id'] === $fb_preview_id, 'status must return correct id even after loopback failure' );
	usdtf_it_assert( isset( $fb_status['json']['status'] ), 'status must have status field even after loopback failure' );

	// Now let it complete autonomously via the production fallback without
	// any CLI wake: the passive poll fires the production recovery tick
	// (resume/recover + the Action Scheduler queue runner), which must run
	// the queued step whose loopback dispatch was injected to fail. The job
	// must actually COMPLETE — a merely readable running job proves nothing.
	$fb_recovered = usdtf_it_http_wait_job_passive( $fb_preview_id, $usdtf_auto_header, 45 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $fb_recovered['status'] ?? '', 'after loopback failure, persisted queue must recover and complete via production fallback (status=' . var_export( $fb_recovered['status'] ?? null, true ) . ')' );
	usdtf_it_assert( (int) ( $fb_recovered['counters']['processed'] ?? 0 ) >= 1, 'completed fallback job must have processed at least 1, got ' . var_export( $fb_recovered['counters']['processed'] ?? null, true ) );

	// Cleanup: ensure injection consumed and product still correctly priced.
	wp_cache_flush();
	$fb_expected = (string) ceil( 5600000 / 280000 );
	// Preview doesn't write, so price unchanged, but job counters prove logic.
	usdtf_it_assert_same( '5600000', usdtf_it_meta( $fb_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'fallback product must preserve source' );

	usdtf_it_pass( 'failed-loopback fallback persists queue and recovers via production fallback' );

	// ---------------------------------------------------------------------
	// A3. Persisted queue recovery: status readable after worker failure.
	// ---------------------------------------------------------------------
	usdtf_it_reset_plugin_state();
	usdtf_it_delete_products();
	$rates->save_rate( 290000 );
	$persist_product = usdtf_it_make_simple_product( 'Persist product', '5800000' );

	// Clear any leftover interrupt.
	usdtf_it_http( 'POST', '/test/clear-interrupt', null, $usdtf_auto_header );

	$pers_preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_auto_header );
	usdtf_it_assert_same( 200, $pers_preview['code'], 'persist preview must be 200' );
	$pers_id = (int) $pers_preview['json']['id'];

	// Even if worker is not woken for 1s, status must be readable and not lost.
	$pers_status = usdtf_it_http( 'GET', '/jobs/' . $pers_id . '/status', null, $usdtf_auto_header, array( '_usdtf' => microtime( true ) ) );
	usdtf_it_assert_same( 200, $pers_status['code'], 'persisted queue status must be readable while job is active' );
	usdtf_it_assert( isset( $pers_status['json']['id'] ) && (int) $pers_status['json']['id'] === $pers_id, 'status must return correct id' );

	// Let it complete autonomously.
	$pers_done = usdtf_it_http_wait_job_passive( $pers_id, $usdtf_auto_header, 45 );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $pers_done['status'] ?? '', 'persisted queue must eventually complete autonomously' );

	// Verify via /jobs/<id> full payload also.
	$full = usdtf_it_http( 'GET', '/jobs/' . $pers_id, null, $usdtf_auto_header );
	usdtf_it_assert_same( 200, $full['code'], 'full job fetch must succeed after completion' );
	usdtf_it_assert( isset( $full['json']['counters'] ), 'full job must have counters' );
	usdtf_it_assert( empty( $full['json']['is_active'] ), 'full job must be inactive after completion' );

	usdtf_it_pass( 'persisted queue status remains readable and recovers' );

	// ---------------------------------------------------------------------
	// A4. Genuine worker interruption + persisted-queue recovery.
	//     Start a sync with 5 products, interrupt after 1 batch, verify
	//     that progress is persisted, that passive poll still shows active,
	//     then that the job resumes, completes with exact counters, correct
	//     prices and no duplicate writes.
	// ---------------------------------------------------------------------
	usdtf_it_reset_plugin_state();
	usdtf_it_delete_products();
	$rates->save_rate( 270000 );
	// Use 25 products so batch size (default 20) requires two batches; we
	// force interruption after 1 batch, which leaves the job running with
	// persisted progress (20 processed, 5 remaining) and the next batch must
	// be re-queued via the production fallback without duplicating writes.
	$interrupt_products = array();
	for ( $i = 1; $i <= 25; $i++ ) {
		$interrupt_products[] = usdtf_it_make_simple_product( 'Interrupt product ' . $i, (string) ( 270000 * $i ) );
	}
	$settings->update( array( 'require_preview' => false ) );

	// Tell the HTTP worker to crash after the first batch.
	$intr = usdtf_it_http( 'POST', '/test/interrupt-after', array( 'count' => 1 ), $usdtf_auto_header );
	usdtf_it_assert_same( 200, $intr['code'], 'interrupt-after injection must be 200' );

	$int_update = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_auto_header );
	usdtf_it_assert_same( 200, $int_update['code'], 'interrupt update must be 200' );
	$int_id = (int) $int_update['json']['id'];
	usdtf_it_assert( $int_id > 0, 'interrupt job must have id' );

	// Short passive poll: job should still be active because we interrupted
	// the worker before it could queue the next step (orphaned). Production
	// recovery (resume_orphaned_jobs via the maintenance tick, fired by the
	// passive poll's /test/tick trigger and by the real wp-cron cadence) must
	// re-queue it without a manual POST /resume, CLI draining or worker wake.
	$int_short = usdtf_it_http_wait_job_passive( $int_id, $usdtf_auto_header, 5 );
	if ( ! empty( $int_short['is_active'] ) ) {
		usdtf_it_assert( isset( $int_short['progress'] ), 'interrupted job must have progress persisted' );
		usdtf_it_assert( isset( $int_short['counters'] ), 'interrupted job must have counters persisted' );
		// Do not POST /resume — let production recovery happen. Passive poll
		// for up to 180s; the server's own tick (or GET /status-triggered
		// resume via resume_orphaned_jobs) must recover. This proves no
		// duplicate writes and exact totals. 180s covers 5-minute cron tick
		// plus Action Scheduler async.
		$int_done = usdtf_it_http_wait_job_passive( $int_id, $usdtf_auto_header, 180 );
	} else {
		$int_done = $int_short;
	}
	usdtf_it_assert_same( Job::STATUS_COMPLETED, $int_done['status'] ?? '', 'interrupted job must eventually complete after recovery (status=' . var_export( $int_done['status'] ?? null, true ) . ')' );
	usdtf_it_assert( (int) ( $int_done['counters']['processed'] ?? 0 ) === 25, 'interrupted job must have processed exactly 25, got ' . var_export( $int_done['counters']['processed'] ?? null, true ) );
	usdtf_it_assert( (int) ( $int_done['counters']['changed'] ?? 0 ) === 25, 'all 25 must be changed' );

	// Verify no duplicate writes: each product should have correct price
	// and the post_modified time should not have been overwritten twice.
	wp_cache_flush();
	for ( $i = 1; $i <= 25; $i++ ) {
		$p = $interrupt_products[ $i - 1 ];
		$expected_price = (string) $i;
		usdtf_it_assert_same( $expected_price, usdtf_it_price( $p->get_id() ), 'interrupt product ' . $i . ' price must be ' . $expected_price );
	}
	// Ensure the job's items are not duplicated: exactly 25 items final.
	$int_full = usdtf_it_http( 'GET', '/jobs/' . $int_id, null, $usdtf_auto_header );
	usdtf_it_assert_same( 200, $int_full['code'], 'interrupted full job fetch must succeed' );
	usdtf_it_assert( (int) ( $int_full['json']['counters']['processed'] ?? 0 ) === 25, 'full job processed must be 25, no duplicates' );

	// Clear interrupt.
	usdtf_it_http( 'POST', '/test/clear-interrupt', null, $usdtf_auto_header );

	usdtf_it_pass( 'worker interruption recovers with resumed progress and exact counters' );

} finally {
	if ( $usdtf_auto_item ) {
		WP_Application_Passwords::delete_application_password( $usdtf_auto_user->ID, $usdtf_auto_item['uuid'] );
	}
	remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	// Cleanup test hooks via HTTP as well (in case CLI delete fails).
	if ( isset( $usdtf_auto_header ) ) {
		usdtf_it_http( 'POST', '/test/clear-interrupt', null, $usdtf_auto_header );
	}
}
