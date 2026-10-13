<?php
/**
 * Integration scenarios for a bulk update that stops between two batches.
 *
 * Included by run.php, which has booted WordPress and loaded lib.php. Run alone
 * with USDTF_IT_ONLY=stall.
 *
 * The worker loopback is answered locally by isolate.php with an empty body,
 * which the plugin treats as a refused dispatch: this is a host whose loopback
 * request never reaches the worker. Action Scheduler's async runner is off as
 * well, so a queued step stays queued until something runs it.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

usdtf_it_delete_products();
usdtf_it_reset_plugin_state();

usdtf_it_use_toman_store();

$st_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$st_user   = $st_admins ? (int) $st_admins[0] : (int) wp_insert_user(
	array(
		'user_login' => 'usdtf_stall_' . wp_rand( 1000, 999999 ),
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'administrator',
	)
);
wp_set_current_user( $st_user );
usdtf_plugin()->rates()->save_rate( 270000 );

$st_ids = array();

for ( $i = 1; $i <= 25; $i++ ) {
	$st_ids[] = usdtf_it_make_priced_product( 'Stall product ' . $i, 5000000 + ( $i * 1000 ) )->get_id();
}

$st_batch = static function () {
	return 10;
};
add_filter( 'usdtf_batch_size', $st_batch );

$GLOBALS['usdtf_it_stall_writes'] = 0;
$st_count_write                   = static function () {
	++$GLOBALS['usdtf_it_stall_writes'];
};
add_action( 'woocommerce_update_product', $st_count_write );
add_action( 'woocommerce_update_product_variation', $st_count_write );

$st_job    = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_PREVIEW,
		'scope' => array( 'ids' => $st_ids ),
	)
);
$st_job_id = (int) $st_job['id'];

// The first worker steps run (discovery, then one batch of ten items) ...
$runner->handle_discovery( $st_job_id );
$runner->handle_batch( $st_job_id );

$st_job_now = $jobs->get( $st_job_id );
usdtf_it_assert_same( 10, (int) $st_job_now->counters()['processed'], 'the first batch must process ten items' );
usdtf_it_assert( $st_job_now->is_active(), 'the preview must still be running after its first batch' );
usdtf_it_assert( usdtf_plugin()->scheduler()->has_pending( \USDTF\Sync_Runner::HOOK_PROCESS, array( $st_job_id ) ), 'the next batch must be queued' );

// ... and the next batch never starts: its dispatch was refused and nothing
// else runs the queue. The job sits at 10 of 25 with a ticking heartbeat age.
$st_age = static function ( $job_id, $seconds ) {
	global $wpdb;

	$when = gmdate( 'Y-m-d H:i:s', time() - $seconds );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( \USDTF\Database::jobs_table(), array( 'heartbeat_at' => $when ), array( 'id' => (int) $job_id ), array( '%s' ), array( '%d' ) );
};

$st_poll = static function ( $job_id ) {
	$response = usdtf_it_rest( 'GET', '/jobs/' . (int) $job_id . '/status', array( 'id' => (int) $job_id ) );

	return usdtf_it_rest_data( $response );
};

// A poll right after a batch is a healthy job: nothing is run on its behalf.
$st_fresh = $st_poll( $st_job_id );
usdtf_it_assert_same( 10, (int) $st_fresh['counters']['processed'], 'a poll of a job with a fresh heartbeat must not run a step' );

// A poll of a job whose next batch has been waiting too long moves it forward.
$st_age( $st_job_id, 30 );
$st_after = $st_poll( $st_job_id );
usdtf_it_assert( (int) $st_after['counters']['processed'] > 10, 'a poll of an idle running job must run the queued batch itself, processed=' . (int) $st_after['counters']['processed'] );

// Keep polling like the browser does: the job reaches its end without any
// other worker.
for ( $st_i = 0; $st_i < 10 && $jobs->get( $st_job_id )->is_active(); $st_i++ ) {
	$st_age( $st_job_id, 30 );
	$st_poll( $st_job_id );
}

$st_final = $jobs->get( $st_job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $st_final->status(), 'the preview must complete through the polls alone' );
usdtf_it_assert_same( 25, (int) $st_final->counters()['processed'], 'every product must be processed exactly once' );
usdtf_it_assert_same( 0, (int) $GLOBALS['usdtf_it_stall_writes'], 'a dry run must write no product' );
usdtf_it_pass( 'a poll restarts a batch that was never dispatched' );

// A retry that waits for its delay is not an idle worker.
$st_retry = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_PREVIEW,
		'scope' => array( 'ids' => array_slice( $st_ids, 0, 3 ) ),
	)
);
$st_retry_id = (int) $st_retry['id'];
$runner->handle_discovery( $st_retry_id );
$st_items = $jobs->next_items( $st_retry_id, 3 );
$jobs->update_item(
	$st_items[0]['id'],
	array(
		'retry_after' => gmdate( 'Y-m-d H:i:s', time() + 600 ),
		'attempts'    => 1,
	)
);
$jobs->update_item( $st_items[1]['id'], array( 'status' => \USDTF\Job_Repository::ITEM_UNCHANGED ) );
$jobs->update_item( $st_items[2]['id'], array( 'status' => \USDTF\Job_Repository::ITEM_UNCHANGED ) );
$st_age( $st_retry_id, 30 );
$st_waiting = $st_poll( $st_retry_id );
usdtf_it_assert_same( 0, (int) $st_waiting['counters']['processed'] > 0 ? 1 : 0, 'a poll must not run a step that waits for a retry delay' );
usdtf_it_pass( 'a poll leaves a delayed retry alone' );

// A worker that died after claiming its step leaves nothing queued. A live
// worker lease or a fresh heartbeat keeps the poll away; a minute of silence
// without either queues the step again.
$st_lost = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_PREVIEW,
		'scope' => array( 'ids' => $st_ids ),
	)
);
$st_lost_id = (int) $st_lost['id'];
$runner->handle_discovery( $st_lost_id );
$runner->handle_batch( $st_lost_id );
\USDTF\Scheduler::unschedule_all( \USDTF\Sync_Runner::HOOK_PROCESS, array( $st_lost_id ) );
usdtf_it_assert( ! usdtf_plugin()->scheduler()->has_pending( \USDTF\Sync_Runner::HOOK_PROCESS, array( $st_lost_id ) ), 'the lost-worker job must have no queued step' );

$st_age( $st_lost_id, 30 );
usdtf_it_assert_same( 10, (int) $st_poll( $st_lost_id )['counters']['processed'], 'thirty seconds of silence is not a lost worker' );

update_option( 'usdtf_worker_lease_' . $st_lost_id . '_' . \USDTF\Sync_Runner::HOOK_PROCESS, array( 'time' => time() ), false );
$st_age( $st_lost_id, 90 );
usdtf_it_assert_same( 10, (int) $st_poll( $st_lost_id )['counters']['processed'], 'a live worker lease keeps the poll from starting a second worker' );

delete_option( 'usdtf_worker_lease_' . $st_lost_id . '_' . \USDTF\Sync_Runner::HOOK_PROCESS );
$st_age( $st_lost_id, 90 );
usdtf_it_assert( (int) $st_poll( $st_lost_id )['counters']['processed'] > 10, 'a minute without a worker or a queued step must restart the job' );

for ( $st_i = 0; $st_i < 10 && $jobs->get( $st_lost_id )->is_active(); $st_i++ ) {
	$st_age( $st_lost_id, 30 );
	$st_poll( $st_lost_id );
}

usdtf_it_assert_same( 25, (int) $jobs->get( $st_lost_id )->counters()['processed'], 'the job that lost its worker must finish with every product once' );
usdtf_it_pass( 'a poll restarts a job whose worker died' );

// A product that kills the worker every time it is processed. Each step runs
// in its own PHP process (worker.php), and that process ends while it works on
// the poison product, like a fatal error or a host that kills the request.
// The maintenance tick re-queues an orphaned job on its own, and with a test
// web server running it would finish this job behind the scenario's back. It is
// held off for the scenario and scheduled again at its end.
wp_clear_scheduled_hook( \USDTF\Cron::EVENT_TICK );
register_shutdown_function( array( '\\USDTF\\Cron', 'schedule' ) );

$st_crash_ids = array_slice( $st_ids, 0, 12 );
$st_poison    = (int) $st_crash_ids[2];
$st_crash     = usdtf_it_create_job(
	array(
		'type'  => \USDTF\Job::TYPE_PREVIEW,
		'scope' => array( 'ids' => $st_crash_ids ),
	)
);
$st_crash_id  = (int) $st_crash['id'];
$runner->handle_discovery( $st_crash_id );

$st_worker = static function ( $job_id, $crash_on ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/worker.php' ) . ' '
		. escapeshellarg( usdtf_it_wp_dir() ) . ' ' . (int) $job_id . ' ' . (int) $crash_on . ' 2>&1';
	$output  = array();
	$status  = 0;
	exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

	return array(
		'status' => (int) $status,
		'output' => implode( "\n", $output ),
	);
};

$st_first = $st_worker( $st_crash_id, $st_poison );
usdtf_it_assert_same( 255, $st_first['status'], 'the worker must die on the poison product: ' . $st_first['output'] );
wp_cache_flush();
usdtf_it_assert_same( 2, (int) $jobs->get( $st_crash_id )->counters()['processed'], 'the progress must show the items finished before the worker died' );

$st_logged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . \USDTF\Database::logs_table() . '` WHERE job_id = %d AND message LIKE %s', $st_crash_id, '%stopped while processing%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
usdtf_it_assert( $st_logged > 0, 'the plugin log must name the product the worker died on' );

$st_second = $st_worker( $st_crash_id, $st_poison );
usdtf_it_assert_same( 255, $st_second['status'], 'the poison product is tried once more: ' . $st_second['output'] );

// The third worker skips it and finishes the batch.
$st_third = $st_worker( $st_crash_id, $st_poison );
usdtf_it_assert_same( 0, $st_third['status'], 'the third worker must skip the poison product: ' . $st_third['output'] );
wp_cache_flush();
usdtf_it_run_job( $st_crash_id );

$st_crash_job = $jobs->get( $st_crash_id );
usdtf_it_assert( ! $st_crash_job->is_active(), 'the job with a poison product must finish' );
usdtf_it_assert_same( 12, (int) $st_crash_job->counters()['processed'], 'every product must be processed or reported' );
usdtf_it_assert_same( 1, (int) $st_crash_job->counters()['failed'], 'only the poison product fails' );
usdtf_it_assert_same( 0, (int) $GLOBALS['usdtf_it_stall_writes'], 'a dry run with a crashing product must write no product' );
usdtf_it_assert( false === get_option( 'usdtf_in_flight_' . $st_crash_id, false ), 'a finished job must leave no in-flight marker' );
usdtf_it_pass( 'a product that kills the worker is skipped after two attempts and the job finishes' );

\USDTF\Cron::schedule();

remove_filter( 'usdtf_batch_size', $st_batch );
remove_action( 'woocommerce_update_product', $st_count_write );
remove_action( 'woocommerce_update_product_variation', $st_count_write );
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
