<?php
/**
 * Integration scenario: the worker phases run in separate PHP processes.
 *
 * Action Scheduler never runs two worker steps in the same request. This
 * scenario spawns one PHP process per step (see worker.php), the way the queue
 * does, and proves that the job lock, the heartbeat and the progress all
 * survive the request boundary.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

use USDTF\Job;
use USDTF\Job_Repository;
use USDTF\Lock;
use USDTF\Product_Pricing;

global $wpdb, $runner, $rates;

/**
 * Run one worker step in its own PHP process.
 *
 * @param int $job_id Job ID.
 * @return array Exit code and output of the process.
 */
function usdtf_it_worker_step( $job_id ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/worker.php' ) . ' ' .
		escapeshellarg( usdtf_it_wp_dir() ) . ' ' . (int) $job_id . ' 2>&1';

	$output = array();
	$code   = 0;

	exec( $command, $output, $code );

	return array(
		'code'   => (int) $code,
		'output' => implode( "\n", $output ),
	);
}

// ---------------------------------------------------------------------------
// 46. The job survives when every worker step runs in its own PHP request.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );

usdtf_it_make_simple_product( 'Cross request one', '5000000' );
usdtf_it_make_simple_product( 'Cross request two', '8100000' );
usdtf_it_make_variable_product( 'Cross request variable', array( array( '2000000' ), array( '4000000' ) ) );

$cross_job = $runner->create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_assert( ! is_wp_error( $cross_job ) && ! empty( $cross_job['id'] ), 'the cross request job must be created' );

$cross_id = (int) $cross_job['id'];

// The parent process acquired the lease when it started the job. From here on
// every step runs in a fresh PHP process, exactly like the queue does.
$steps = 0;
$failed_process = '';

while ( $steps < 100 ) {
	$job_row = usdtf_plugin()->jobs()->get( $cross_id );

	if ( ! $job_row ) {
		usdtf_it_assert( false, 'the cross request job disappeared' );
		break;
	}

	if ( ! $job_row->is_active() ) {
		break;
	}

	// While the job runs, the lease must stay owned by this job no matter
	// which process heartbeats it.
	$lease = get_option( Lock::OPTION );

	usdtf_it_assert( is_array( $lease ) && (int) $lease['job_id'] === $cross_id, 'the lease must stay owned by the running job between processes' );

	$result = usdtf_it_worker_step( $cross_id );

	if ( 0 !== $result['code'] ) {
		$failed_process = $result['output'];
		break;
	}

	++$steps;
}

usdtf_it_assert( '' === $failed_process, 'a worker process must not fail: ' . $failed_process );
usdtf_it_assert( $steps >= 3, 'the job must have needed several worker processes (steps: ' . $steps . ')' );

$finished = usdtf_plugin()->jobs()->get( $cross_id );
usdtf_it_assert_same( Job::STATUS_COMPLETED, $finished->status(), 'a job whose steps run in separate PHP processes must complete — got ' . $finished->status() . ' after ' . $steps . ' steps' );
usdtf_it_assert_same( 3, (int) $finished->data['changed'], 'every queued item must have been changed across the processes' );
usdtf_it_assert( false === get_option( Lock::OPTION ), 'the lease must be released once the job finishes' );

// The prices were really written: both simple products carry their derived
// USD price, and the variable product did it through its variations.
$cross_items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( 'SELECT object_id, status FROM ' . \USDTF\Database::items_table() . ' WHERE job_id = %d', $cross_id ),
	ARRAY_A
);

usdtf_it_assert_same( 3, count( $cross_items ), 'the cross request job must have queued the three products' );
usdtf_it_assert( ! in_array( Job_Repository::ITEM_FAILED, wp_list_pluck( $cross_items, 'status' ), true ), 'no item may fail across the processes' );

foreach ( $cross_items as $cross_item ) {
	$cross_item_product = wc_get_product( (int) $cross_item['object_id'] );

	if ( $cross_item_product && $cross_item_product->is_type( 'variable' ) ) {
		foreach ( $cross_item_product->get_children() as $cross_child_id ) {
			usdtf_it_assert( '' !== usdtf_it_price( $cross_child_id ), 'the variation ' . $cross_child_id . ' must have a price after the cross request job' );
		}

		continue;
	}

	usdtf_it_assert( '' !== usdtf_it_price( (int) $cross_item['object_id'] ), 'the product ' . $cross_item['object_id'] . ' must have a price after the cross request job' );
}

// A paused job can be resumed from a different process as well.
$resume_product = usdtf_it_make_simple_product( 'Cross request resumed', '2700000' );
$resume_job     = $runner->create_job( array( 'type' => Job::TYPE_SYNC ) );
usdtf_it_assert( ! is_wp_error( $resume_job ) && ! empty( $resume_job['id'] ), 'the resume job must be created' );

usdtf_it_assert_same( true, $runner->pause( (int) $resume_job['id'] ), 'the job must pause' );
usdtf_it_assert( false === get_option( Lock::OPTION ), 'pausing must release the lease' );

usdtf_it_assert_same( true, $runner->resume( (int) $resume_job['id'] ), 'the job must resume' );

$steps = 0;

while ( $steps < 100 ) {
	$job_row = usdtf_plugin()->jobs()->get( (int) $resume_job['id'] );

	if ( ! $job_row || ! $job_row->is_active() ) {
		break;
	}

	$result = usdtf_it_worker_step( (int) $resume_job['id'] );

	if ( 0 !== $result['code'] ) {
		usdtf_it_assert( false, 'a resumed worker process must not fail: ' . $result['output'] );
		break;
	}

	++$steps;
}

$finished_resume = usdtf_plugin()->jobs()->get( (int) $resume_job['id'] );
usdtf_it_assert_same( Job::STATUS_COMPLETED, $finished_resume->status(), 'a job resumed in one process must finish through other processes' );
usdtf_it_assert_same( '10', usdtf_it_price( $resume_product->get_id() ), 'the resumed job must write its price' );

usdtf_it_pass( 'the worker phases run across independent PHP requests' );
