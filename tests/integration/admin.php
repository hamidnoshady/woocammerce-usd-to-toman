<?php
/**
 * Admin API, worker endpoint, cron maintenance and uninstall scenarios.
 *
 * Included by run.php, which boots WordPress and WooCommerce and exposes the
 * plugin services the scenarios below reuse ($settings, $rates, $runner,
 * $pricing, $jobs).
 *
 * @package USDTF
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixtures.

// ---------------------------------------------------------------------------
// 22. The REST API the admin screen talks to.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();

$rest_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$rest_user   = $rest_admins ? (int) $rest_admins[0] : 0;
$made_admin  = false;

if ( $rest_user <= 0 ) {
	$rest_user  = (int) wp_insert_user(
		array(
			'user_login' => 'usdtf_rest_' . wp_rand( 1000, 999999 ),
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'administrator',
		)
	);
	$made_admin = true;
}

wp_set_current_user( $rest_user );
$rates->save_rate( 270000 );

$rest_product = usdtf_it_make_simple_product( 'REST product', 5000000 );

// The route must exist: without this assertion a 404 would look like a pass.
$state_response = usdtf_it_rest( 'GET', '/state' );

usdtf_it_assert_same( '', usdtf_it_rest_error_code( $state_response ), 'the state route must be registered' );
usdtf_it_assert_same( 200, $state_response->get_status(), 'the state route must answer with 200' );

$state = usdtf_it_rest_data( $state_response );

usdtf_it_assert( isset( $state['rate'], $state['jobs'], $state['checks'], $state['can'] ), 'the state payload must carry the rate, jobs, checks and permissions' );
usdtf_it_assert( (float) $state['rate'] > 0, 'the state payload must report the stored rate' );
usdtf_it_assert( ! empty( $state['can']['manage'] ), 'an administrator must be allowed to manage pricing' );

// Dry run over REST: reports work but writes nothing.
$preview_response = usdtf_it_rest( 'POST', '/preview' );

usdtf_it_assert_same( '', usdtf_it_rest_error_code( $preview_response ), 'the preview route must work' );
usdtf_it_assert_same( 200, $preview_response->get_status(), 'the preview route must answer with 200' );
usdtf_it_assert_same( '', usdtf_it_price( $rest_product->get_id() ), 'a preview must not write a price' );

// Update over REST: queues a job that the worker finishes.
$update_response = usdtf_it_rest( 'POST', '/update', array( 'scope' => array( 'label' => 'Everything' ) ) );

usdtf_it_assert_same( '', usdtf_it_rest_error_code( $update_response ), 'the update route must work' );
usdtf_it_assert( isset( usdtf_it_rest_data( $update_response )['id'] ), 'the update route must return the created job' );

$rest_job_id = (int) usdtf_it_rest_data( $update_response )['id'];

// A second update while one is active must be refused, and the rejection must
// carry the running job so the screen can show it.
$duplicate_response = usdtf_it_rest( 'POST', '/update' );

usdtf_it_assert_same( 'usdtf_job_running', usdtf_it_rest_error_code( $duplicate_response ), 'a duplicate update must be refused' );
usdtf_it_assert_same( 409, $duplicate_response->get_status(), 'a duplicate update must be reported as a conflict' );
usdtf_it_assert_same( $rest_job_id, (int) usdtf_it_rest_error_data( $duplicate_response )['job']['id'], 'the refusal must carry the running job' );

usdtf_it_run_job( $rest_job_id );
usdtf_it_assert_same( '19', usdtf_it_price( $rest_product->get_id() ), 'the queued update must convert the product' );

// Job listing and detail, which the progress screen polls.
$jobs_response = usdtf_it_rest( 'GET', '/jobs', array( 'limit' => 5 ) );

usdtf_it_assert_same( 200, $jobs_response->get_status(), 'the jobs route must answer with 200' );
usdtf_it_assert( count( usdtf_it_rest_data( $jobs_response ) ) >= 1, 'the jobs route must list the finished job' );

$job_response = usdtf_it_rest(
	'GET',
	'/jobs/' . $rest_job_id,
	array(
		'items_status'      => \USDTF\Job_Repository::ITEM_CHANGED,
		'items_per_page'    => 5,
		'items_page'        => 1,
	)
);

usdtf_it_assert_same( '', usdtf_it_rest_error_code( $job_response ), 'the job detail route must work' );

$job_detail = usdtf_it_rest_data( $job_response );

usdtf_it_assert( isset( $job_detail['items'], $job_detail['totals'] ), 'the job detail must carry its items and totals' );
usdtf_it_assert( count( $job_detail['items'] ) >= 1, 'the job detail must return the filtered items' );

foreach ( $job_detail['items'] as $item ) {
	usdtf_it_assert_same( \USDTF\Job_Repository::ITEM_CHANGED, $item['status'], 'the item filter must be applied' );
	usdtf_it_assert( isset( $item['name'], $item['new_regular'], $item['revision'] ), 'every item must describe the product, the new price and its revision' );
}

$missing_response = usdtf_it_rest( 'GET', '/jobs/99999999' );

usdtf_it_assert_same( 'usdtf_job_not_found', usdtf_it_rest_error_code( $missing_response ), 'an unknown job must return the documented code' );
usdtf_it_assert_same( 404, $missing_response->get_status(), 'an unknown job must be a 404' );

// Pause, resume and cancel through the API.
$pause_product = usdtf_it_make_simple_product( 'REST pause product', 8100000 );
$new_job       = usdtf_it_rest( 'POST', '/update' );

usdtf_it_assert_same( '', usdtf_it_rest_error_code( $new_job ), 'a second update must be accepted once the first finished' );

$pause_job_id = (int) usdtf_it_rest_data( $new_job )['id'];

$paused = usdtf_it_rest( 'POST', '/jobs/' . $pause_job_id . '/pause' );
usdtf_it_assert_same( \USDTF\Job::STATUS_PAUSED, usdtf_it_rest_data( $paused )['job']['status'], 'the pause action must pause the job' );
usdtf_it_assert_same( \USDTF\Job::STATUS_PAUSED, $jobs->get( $pause_job_id )->status(), 'the pause action must reach the database' );

$resumed = usdtf_it_rest( 'POST', '/jobs/' . $pause_job_id . '/resume' );

// Resuming hands the job back to a worker, so it is running again.
usdtf_it_assert_same( \USDTF\Job::STATUS_RUNNING, usdtf_it_rest_data( $resumed )['job']['status'], 'the resume action must hand the job back to the worker' );
usdtf_it_assert( usdtf_it_rest_data( $resumed )['job']['can_cancel'], 'a resumed job must be cancellable again' );

$cancelled = usdtf_it_rest( 'POST', '/jobs/' . $pause_job_id . '/cancel' );
usdtf_it_assert_same( \USDTF\Job::STATUS_CANCELLED, usdtf_it_rest_data( $cancelled )['job']['status'], 'the cancel action must cancel the job' );

// Only the documented actions are routable at all.
$status_before  = $jobs->get( $pause_job_id )->status();
$unknown_action = usdtf_it_rest( 'POST', '/jobs/' . $pause_job_id . '/explode' );

usdtf_it_assert_same( 'rest_no_route', usdtf_it_rest_error_code( $unknown_action ), 'an unknown job action must not be routable' );
usdtf_it_assert_same( $status_before, $jobs->get( $pause_job_id )->status(), 'the refused action must not touch the job' );

$rest_after_cancel = usdtf_it_rest( 'POST', '/jobs/' . $pause_job_id . '/resume' );
usdtf_it_assert_same( 'usdtf_action_failed', usdtf_it_rest_error_code( $rest_after_cancel ), 'a cancelled job must not be resumable' );

// Diagnostics and the scope picker.
$health_response = usdtf_it_rest( 'GET', '/health' );

usdtf_it_assert_same( 200, $health_response->get_status(), 'the health route must answer with 200' );

$health_data = usdtf_it_rest_data( $health_response );

usdtf_it_assert( isset( $health_data['checks'], $health_data['scheduler'], $health_data['lock'] ), 'the health route must report its checks, the scheduler and the lock' );

$search_response = usdtf_it_rest( 'GET', '/products', array( 'search' => 'REST product' ) );

usdtf_it_assert_same( 200, $search_response->get_status(), 'the product search route must answer with 200' );

$search_results = usdtf_it_rest_data( $search_response );

usdtf_it_assert( count( $search_results ) >= 1, 'the product search must find the product' );
usdtf_it_assert_same( \USDTF\Product_Pricing::MODE_MANAGED, $search_results[0]['mode'], 'the search must report the pricing mode' );

// Recalculate a single product and roll the rate back, both over REST.
$recalculate_response = usdtf_it_rest( 'POST', '/recalculate', array( 'ids' => array( $rest_product->get_id() ) ) );

usdtf_it_assert_same( '', usdtf_it_rest_error_code( $recalculate_response ), 'the recalculate route must work' );
usdtf_it_run_job( (int) usdtf_it_rest_data( $recalculate_response )['id'] );

$rates->save_rate( 300000 );

usdtf_it_assert_same( 300000.0, (float) $rates->get_rate(), 'the new rate must be stored' );

$rollback_response = usdtf_it_rest( 'POST', '/rollback' );

usdtf_it_assert_same( '', usdtf_it_rest_error_code( $rollback_response ), 'the rollback route must work' );
usdtf_it_run_job( (int) usdtf_it_rest_data( $rollback_response )['id'] );

usdtf_it_assert_same( 270000.0, (float) $rates->get_rate(), 'the rollback must restore the previous rate' );

// A subscriber must not reach any of it.
$rest_subscriber = wp_insert_user(
	array(
		'user_login' => 'usdtf_rest_sub_' . wp_rand( 1000, 999999 ),
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'subscriber',
	)
);

wp_set_current_user( (int) $rest_subscriber );

$forbidden = usdtf_it_rest( 'GET', '/state' );

usdtf_it_assert_same( 'rest_forbidden', usdtf_it_rest_error_code( $forbidden ), 'a subscriber must be rejected by the capability, not by a missing route' );

$forbidden_write = usdtf_it_rest( 'POST', '/update' );

usdtf_it_assert_same( 'rest_forbidden', usdtf_it_rest_error_code( $forbidden_write ), 'a subscriber must not queue updates' );

require_once ABSPATH . 'wp-admin/includes/user.php';

wp_delete_user( (int) $rest_subscriber );
wp_set_current_user( $rest_user );

usdtf_it_pass( 'the REST API serves the admin screen and refuses other roles' );

if ( $made_admin ) {
	wp_set_current_user( 0 );
}

// ---------------------------------------------------------------------------
// 23. The token protected worker endpoint.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$scheduler = usdtf_plugin()->scheduler();

usdtf_it_assert( ! \USDTF\Scheduler::verify_token( '' ), 'an empty loopback token must be refused' );
usdtf_it_assert( ! \USDTF\Scheduler::verify_token( 'not-the-token' ), 'a wrong loopback token must be refused' );
usdtf_it_assert( \USDTF\Scheduler::verify_token( \USDTF\Scheduler::token() ), 'the stored loopback token must be accepted' );

$loopback_product = usdtf_it_make_simple_product( 'Loopback product', 5400000 );
$loopback_job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$loopback_job_id  = (int) $loopback_job['id'];

$runner->handle_discovery( $loopback_job_id );

$worker_request = static function ( $token, $hook ) use ( $loopback_job_id ) {
	$_POST = array(
		'token' => $token,
		'hook'  => $hook,
		'args'  => wp_json_encode( array( $loopback_job_id ) ),
	);
	$_GET  = array();
};

$run_worker = static function () use ( $scheduler ) {
	try {
		$scheduler->handle_loopback();
	} catch ( \USDTF_IT_Die $die ) {
		return $die;
	}

	return null;
};

$worker_request( 'wrong-token', 'usdtf_run_batch' );
$denied = $run_worker();

usdtf_it_assert( $denied instanceof \USDTF_IT_Die, 'a request with a wrong token must be killed' );
usdtf_it_assert_same( 403, $denied->die_status, 'a wrong token must be answered with 403' );
usdtf_it_assert_same( '', usdtf_it_price( $loopback_product->get_id() ), 'a refused request must not process anything' );

$worker_request( \USDTF\Scheduler::token(), 'usdtf_delete_everything' );
$bad_hook = $run_worker();

usdtf_it_assert( $bad_hook instanceof \USDTF_IT_Die, 'a request for an unknown hook must be killed' );
usdtf_it_assert_same( 400, $bad_hook->die_status, 'an unknown hook must be answered with 400' );

$_POST = array(
	'token' => \USDTF\Scheduler::token(),
	'hook'  => 'usdtf_run_batch',
	'args'  => wp_json_encode( array( 0 ) ),
);
$_GET  = array();

$no_job = $run_worker();

usdtf_it_assert( $no_job instanceof \USDTF_IT_Die, 'a request without a job id must be killed' );
usdtf_it_assert_same( 400, $no_job->die_status, 'a missing job id must be answered with 400' );

$worker_request( \USDTF\Scheduler::token(), 'usdtf_run_batch' );
$accepted = $run_worker();

usdtf_it_assert( $accepted instanceof \USDTF_IT_Die, 'a valid worker request must run the hook' );
usdtf_it_assert_same( 200, $accepted->die_status, 'a valid worker request must answer with 200' );
usdtf_it_assert_same( '20', usdtf_it_price( $loopback_product->get_id() ), 'the worker request must process the batch it was given' );

usdtf_it_run_job( $loopback_job_id );
usdtf_it_assert_same( \USDTF\Job::STATUS_COMPLETED, $jobs->get( $loopback_job_id )->status(), 'the job must finish after the manual worker request' );

$_POST = array();
$_GET  = array();

usdtf_it_pass( 'the worker endpoint requires its token and only runs worker hooks' );

// ---------------------------------------------------------------------------
// 24. Cron maintenance: scheduling, recovery and retention.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

\USDTF\Cron::unschedule();
usdtf_it_assert( false === wp_next_scheduled( \USDTF\Cron::EVENT_TICK ), 'unscheduling must remove the recovery event' );
usdtf_it_assert( false === wp_next_scheduled( \USDTF\Cron::EVENT_DAILY ), 'unscheduling must remove the daily event' );

\USDTF\Cron::schedule();
usdtf_it_assert( false !== wp_next_scheduled( \USDTF\Cron::EVENT_TICK ), 'the recovery event must be scheduled' );
usdtf_it_assert( false !== wp_next_scheduled( \USDTF\Cron::EVENT_DAILY ), 'the daily event must be scheduled' );

// A job whose worker died is picked up by the recovery pass.
$cron_product = usdtf_it_make_simple_product( 'Cron product', 2700000 );
$cron_job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$cron_job_id  = (int) $cron_job['id'];

$runner->handle_discovery( $cron_job_id );

$stale = gmdate( 'Y-m-d H:i:s', time() - ( 3 * HOUR_IN_SECONDS ) );

$wpdb->update( \USDTF\Database::jobs_table(), array( 'heartbeat_at' => $stale ), array( 'id' => $cron_job_id ) );
$wpdb->update( \USDTF\Database::jobs_table(), array( 'updated_at' => $stale ), array( 'id' => $cron_job_id ) );

usdtf_plugin()->cron()->tick();

$recovered_job = $jobs->get( $cron_job_id );

usdtf_it_assert( $recovered_job->is_active(), 'the maintenance pass must keep the job alive' );
usdtf_it_assert( '' !== (string) $recovered_job->data['heartbeat_at'], 'the recovered job must have a fresh heartbeat' );

usdtf_it_run_job( $cron_job_id );
usdtf_it_assert_same( '10', usdtf_it_price( $cron_product->get_id() ), 'the recovered job must still finish its work' );

// The daily pass applies the retention window.
$settings->update( array( 'retention_days' => 30 ) );

$logs_table  = \USDTF\Database::logs_table();
$items_table = \USDTF\Database::items_table();
$log_stamp   = gmdate( 'Y-m-d H:i:s', time() - ( 45 * DAY_IN_SECONDS ) );

$wpdb->insert(
	$logs_table,
	array(
		'level'      => 'info',
		'message'    => 'Ancient log entry.',
		'context'    => '{}',
		'job_id'     => $cron_job_id,
		'created_at' => $log_stamp,
	),
	array( '%s', '%s', '%s', '%d', '%s' )
);
$wpdb->query( $wpdb->prepare( "UPDATE `{$items_table}` SET updated_at = %s WHERE job_id = %d", $log_stamp, $cron_job_id ) );

// The retention assertion below can only mean something when the expired row
// really landed, so the fixture is asserted instead of assumed.
usdtf_it_assert_same(
	1,
	(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$logs_table}` WHERE message = %s", 'Ancient log entry.' ) ),
	'the expired log fixture must be stored before the daily pass runs'
);

usdtf_plugin()->cron()->daily();

usdtf_it_assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$logs_table}` WHERE message = %s", 'Ancient log entry.' ) ), 'the daily pass must remove expired log entries' );
usdtf_it_assert_same( 0, count( $jobs->items( $cron_job_id ) ), 'the daily pass must remove expired job items' );
usdtf_it_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$logs_table}` WHERE message = %s", 'Daily maintenance finished.' ) ) >= 1, 'the daily pass must record what it did' );

usdtf_it_pass( 'cron maintenance recovers jobs and applies the retention window' );

// ---------------------------------------------------------------------------
// 25. Uninstalling never destroys the canonical Toman prices by default.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$uninstall_product = usdtf_it_make_simple_product( 'Uninstall product', 5000000 );
$uninstall_job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );

usdtf_it_run_job( (int) $uninstall_job['id'] );

$jobs_table = \USDTF\Database::jobs_table();
$source_key = \USDTF\Product_Pricing::META_SOURCE_REGULAR;

usdtf_it_assert( \USDTF\Database::table_exists( $jobs_table ), 'the plugin tables must exist before the uninstall' );

// A meta key the plugin does not own, to prove a purge is scoped.
update_post_meta( $uninstall_product->get_id(), 'usdtf_it_foreign_key', 'keep me' );

// The assertions look at this scenario's own product instead of counting the
// meta across the whole site: a store that already uses the plugin keeps its
// own source prices, and that must not be mistaken for this fixture's.
\USDTF\Installer::uninstall( false );

usdtf_it_assert( ! \USDTF\Database::table_exists( $jobs_table ), 'uninstalling must drop the plugin tables' );
usdtf_it_assert_same( '', (string) get_option( \USDTF\Settings::OPTION, '' ), 'uninstalling must remove the settings' );
usdtf_it_assert_same( '5000000', usdtf_it_meta( $uninstall_product->get_id(), $source_key ), 'uninstalling without the opt in must keep the canonical Toman price' );

\USDTF\Installer::create_tables();
\USDTF\Installer::seed_options();

usdtf_it_assert( \USDTF\Database::table_exists( $jobs_table ), 'the tables must be installable again' );

\USDTF\Installer::uninstall( true );

// The purge removes the rows with a direct query, so the cached meta of this
// product is dropped before it is read back.
clean_post_cache( $uninstall_product->get_id() );

usdtf_it_assert_same( '', usdtf_it_meta( $uninstall_product->get_id(), $source_key ), 'the explicit opt in must remove the plugin metadata' );
usdtf_it_assert_same( 'keep me', usdtf_it_meta( $uninstall_product->get_id(), 'usdtf_it_foreign_key' ), 'a purge must not touch meta the plugin does not own' );

// What activating the plugin again does.
\USDTF\Installer::create_tables();
\USDTF\Installer::seed_options();
\USDTF\Cron::schedule();
usdtf_it_reset_plugin_state();

usdtf_it_assert( \USDTF\Database::table_exists( \USDTF\Database::jobs_table() ), 'the suite must leave a working installation behind' );
usdtf_it_assert( false !== wp_next_scheduled( \USDTF\Cron::EVENT_TICK ), 'activating again must restore the maintenance events' );

usdtf_it_pass( 'uninstall keeps the Toman prices unless the store owner opts in' );
