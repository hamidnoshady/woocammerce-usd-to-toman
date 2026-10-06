<?php
/**
 * One worker step in its own PHP process.
 *
 * Usage: php tests/integration/worker.php /path/to/wordpress <job_id>
 *
 * Action Scheduler runs every worker step in a separate PHP request. This
 * script is the honest version of that for the integration suite: each call
 * boots WordPress from scratch, loads the job and runs exactly one phase, so
 * the job lock has to survive the request boundary for the job to finish.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

$usdtf_wp_path = isset( $argv[1] ) ? (string) $argv[1] : '';
$usdtf_job_id  = isset( $argv[2] ) ? (int) $argv[2] : 0;

if ( '' === $usdtf_wp_path || $usdtf_job_id <= 0 ) {
	fwrite( STDERR, "usage: php worker.php /path/to/wordpress <job_id>\n" );
	exit( 2 );
}

$usdtf_wp_path = rtrim( $usdtf_wp_path, '/' );

if ( ! file_exists( $usdtf_wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress was not found at {$usdtf_wp_path}.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['SERVER_PORT']    = '80';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once $usdtf_wp_path . '/wp-load.php';
require_once __DIR__ . '/isolate.php';

if ( ! function_exists( 'usdtf_plugin' ) ) {
	fwrite( STDERR, "The plugin under test is not active.\n" );
	exit( 2 );
}

$usdtf_job = usdtf_plugin()->jobs()->get( $usdtf_job_id );

if ( ! $usdtf_job ) {
	echo "missing\n";
	exit( 0 );
}

if ( ! $usdtf_job->is_active() ) {
	echo "inactive\n";
	exit( 0 );
}

$usdtf_runner = usdtf_plugin()->runner();
$usdtf_phase  = $usdtf_job->phase();

switch ( $usdtf_phase ) {
	case USDTF\Job::PHASE_DISCOVER:
	case USDTF\Job::PHASE_DISCOVER_VARIATIONS:
		$usdtf_runner->handle_discovery( $usdtf_job_id );
		break;
	case USDTF\Job::PHASE_FINALIZE:
		$usdtf_runner->handle_finalize( $usdtf_job_id );
		break;
	default:
		$usdtf_runner->handle_batch( $usdtf_job_id );
		break;
}

$usdtf_job = usdtf_plugin()->jobs()->get( $usdtf_job_id );

echo 'step ' . $usdtf_phase . ' -> ' . ( $usdtf_job ? $usdtf_job->status() . '/' . $usdtf_job->phase() : 'missing' ) . "\n";
