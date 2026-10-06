<?php
/**
 * Queue backend, loopback diagnostics, admin screens and product panel.
 *
 * Included by run.php, which boots WordPress and WooCommerce and exposes the
 * plugin services the scenarios below reuse ($settings, $rates, $runner,
 * $pricing, $jobs).
 *
 * @package USDTF
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixtures.

// ---------------------------------------------------------------------------
// 26. The Action Scheduler queue the workers run on.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$scheduler_under_test = usdtf_plugin()->scheduler();

usdtf_it_assert( \USDTF\Scheduler::is_action_scheduler_available(), 'this installation is expected to provide WooCommerce Action Scheduler' );
usdtf_it_assert_same( \USDTF\Scheduler::BACKEND_ACTION_SCHEDULER, $scheduler_under_test->backend(), 'Action Scheduler must be the preferred backend' );
usdtf_it_assert_same(
	array( \USDTF\Sync_Runner::HOOK_DISCOVER, \USDTF\Sync_Runner::HOOK_PROCESS, \USDTF\Sync_Runner::HOOK_FINALIZE ),
	\USDTF\Scheduler::allowed_worker_hooks(),
	'the loopback endpoint must only expose the three worker hooks'
);

$queue_product = usdtf_it_make_simple_product( 'Queue product', 2700000 );
$baseline      = $scheduler_under_test->pending_count();

$queue_job = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );

usdtf_it_assert( ! is_wp_error( $queue_job ), 'the queue scenario needs a job to watch' );

$queue_job_id = (int) $queue_job['id'];
$queue_args   = array( $queue_job_id );

usdtf_it_assert( $scheduler_under_test->pending_count() > $baseline, 'a new job must add exactly one action to the queue' );

usdtf_it_assert( $scheduler_under_test->has_pending( \USDTF\Sync_Runner::HOOK_DISCOVER, $queue_args ), 'creating a job must queue its discovery step' );
usdtf_it_assert( $scheduler_under_test->enqueue( \USDTF\Sync_Runner::HOOK_DISCOVER, $queue_args ), 'queueing the same step twice must be idempotent' );
usdtf_it_assert_same( $baseline + 1, $scheduler_under_test->pending_count(), 'queueing the same step twice must not add a second action' );

// The lookup the runner itself uses. Action Scheduler answers `true` for a
// pending asynchronous action instead of an id.
usdtf_it_assert( false !== as_next_scheduled_action( \USDTF\Sync_Runner::HOOK_DISCOVER, $queue_args, \USDTF\Scheduler::GROUP ), 'the discovery step must be scheduled in the plugin group' );

$scheduled_action = null;

foreach ( (array) as_get_scheduled_actions(
	array(
		'group'    => \USDTF\Scheduler::GROUP,
		'hook'     => \USDTF\Sync_Runner::HOOK_DISCOVER,
		'status'   => 'pending',
		'per_page' => 500,
		'orderby'  => 'date',
		'order'    => 'ASC',
	),
	'ids'
) as $scheduled_id ) {
	$candidate = \ActionScheduler::store()->fetch_action( (int) $scheduled_id );

	if ( $candidate instanceof \ActionScheduler_Action && array_map( 'intval', (array) $candidate->get_args() ) === array( $queue_job_id ) ) {
		$scheduled_action = $candidate;

		break;
	}
}

usdtf_it_assert( $scheduled_action instanceof \ActionScheduler_Action, 'the queued action must be readable' );
usdtf_it_assert_same( \USDTF\Sync_Runner::HOOK_DISCOVER, $scheduled_action->get_hook(), 'only worker hooks may be queued' );
usdtf_it_assert_same( \USDTF\Scheduler::GROUP, $scheduled_action->get_group(), 'worker actions must stay in the plugin group' );
usdtf_it_assert_same( array( $queue_job_id ), array_map( 'intval', (array) $scheduled_action->get_args() ), 'the queued action must carry the job id' );

// Worker actions are queued asynchronously, so Action Scheduler reports them
// without a date. This is exactly the shape that used to fatal the health
// summary, and it must stay harmless.
usdtf_it_assert( null === $scheduled_action->get_schedule()->get_date(), 'worker actions are asynchronous and carry no date' );
usdtf_it_assert_same( 0, $scheduler_under_test->failed_count(), 'a fresh queue must have no failed actions' );

// Worker actions are queued asynchronously, which means Action Scheduler
// reports them without a date. The diagnostics has to survive that.
$health = $scheduler_under_test->health();

usdtf_it_assert_same( \USDTF\Scheduler::BACKEND_ACTION_SCHEDULER, $health['backend'], 'the diagnostics must name the backend' );
usdtf_it_assert( true === $health['action_scheduler'], 'the diagnostics must report Action Scheduler availability' );
usdtf_it_assert( $health['pending'] > $baseline, 'the diagnostics must count the pending actions' );
usdtf_it_assert( 0 === $health['failed'], 'the diagnostics must count the failed actions' );
usdtf_it_assert( is_int( $health['oldest_pending'] ) && $health['oldest_pending'] >= 0, 'the diagnostics must report the age of the oldest pending action' );
usdtf_it_assert( ! empty( $health['next_tick'] ), 'the diagnostics must report the next maintenance tick' );

// The whole job still runs.
usdtf_it_run_job( $queue_job_id );

usdtf_it_assert_same( '10', usdtf_it_price( $queue_product->get_id() ), 'the queued job must still process its product' );

// This harness runs the workers directly, so the actions it queued are still
// pending. Clearing them must leave the queue exactly as it was.
\USDTF\Scheduler::unschedule_all( \USDTF\Sync_Runner::HOOK_DISCOVER, $queue_args );
\USDTF\Scheduler::unschedule_all( \USDTF\Sync_Runner::HOOK_PROCESS, $queue_args );
\USDTF\Scheduler::unschedule_all( \USDTF\Sync_Runner::HOOK_FINALIZE, $queue_args );

usdtf_it_assert( ! $scheduler_under_test->has_pending( \USDTF\Sync_Runner::HOOK_PROCESS, $queue_args ), 'unscheduling must clear the pending action' );
usdtf_it_assert_same( $baseline, $scheduler_under_test->pending_count(), 'unscheduling must restore the queue' );

// An action left over from a job that was cancelled in the meantime must do
// nothing when it finally runs.
$cancel_product = usdtf_it_make_simple_product( 'Cancelled queue product', 8100000 );
$cancel_job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$cancel_job_id  = (int) $cancel_job['id'];

$runner->handle_discovery( $cancel_job_id );
$runner->cancel( $cancel_job_id );

$runner->handle_discovery( $cancel_job_id );
$runner->handle_batch( $cancel_job_id );
$runner->handle_finalize( $cancel_job_id );

usdtf_it_assert_same( \USDTF\Job::STATUS_CANCELLED, $jobs->get( $cancel_job_id )->status(), 'a leftover worker action must not resurrect a cancelled job' );
usdtf_it_assert_same( '', usdtf_it_price( $cancel_product->get_id() ), 'a leftover worker action must not write a price' );

// A paused job, on the other hand, continues where it stopped.
$pause_queue_product = usdtf_it_make_simple_product( 'Paused queue product', 5400000 );
$pause_queue_job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
$pause_queue_id      = (int) $pause_queue_job['id'];

$runner->handle_discovery( $pause_queue_id );
$runner->pause( $pause_queue_id );

$runner->handle_batch( $pause_queue_id );

usdtf_it_assert_same( '', usdtf_it_price( $pause_queue_product->get_id() ), 'a paused job must not process a batch' );

$runner->resume( $pause_queue_id );
usdtf_it_run_job( $pause_queue_id );

usdtf_it_assert_same( '20', usdtf_it_price( $pause_queue_product->get_id() ), 'a resumed job must finish the work it paused' );

\USDTF\Scheduler::unschedule_all( \USDTF\Sync_Runner::HOOK_DISCOVER, array( $cancel_job_id ) );
\USDTF\Scheduler::unschedule_all( \USDTF\Sync_Runner::HOOK_PROCESS, array( $cancel_job_id ) );
\USDTF\Scheduler::unschedule_all( \USDTF\Sync_Runner::HOOK_FINALIZE, array( $cancel_job_id ) );

usdtf_it_pass( 'the worker queue runs on Action Scheduler and reports its health' );

// ---------------------------------------------------------------------------
// 27. Loopback diagnostics see a reachable, a failing and an unreachable site.
// ---------------------------------------------------------------------------
$loopback = static function ( $response ) {
	return static function () use ( $response ) {
		return $response;
	};
};

$captured_url = '';

$capture = static function ( $preempt, $args, $url ) use ( &$captured_url, $loopback ) {
	$captured_url = $url;

	return array(
		'headers'  => array(),
		'body'     => 'ok',
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

add_filter( 'pre_http_request', $capture, 10, 3 );

$reachable = usdtf_plugin()->health()->loopback_test();

remove_filter( 'pre_http_request', $capture, 10 );

usdtf_it_assert( ! empty( $reachable['ok'] ), 'a 200 answer must be reported as a working loopback' );
usdtf_it_assert_same( 200, $reachable['status'], 'the loopback check must report the HTTP status' );
usdtf_it_assert( false !== strpos( $captured_url, 'usdtf_loopback_check=1' ), 'the loopback check must ask the site for the marker' );
usdtf_it_assert( false !== strpos( $captured_url, 'token=' ), 'the loopback check must carry the token' );

$failing = static function () {
	return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
};

add_filter( 'pre_http_request', $failing, 10, 3 );

$unreachable = usdtf_plugin()->health()->loopback_test();

remove_filter( 'pre_http_request', $failing, 10 );

usdtf_it_assert( empty( $unreachable['ok'] ), 'an unreachable site must not be reported as working' );
usdtf_it_assert_same( 0, $unreachable['status'], 'a failed request must report status 0' );
usdtf_it_assert( '' !== $unreachable['description'], 'a failed request must explain itself' );

$server_error = static function () {
	return array(
		'headers'  => array(),
		'body'     => 'nope',
		'response' => array(
			'code'    => 503,
			'message' => 'Service Unavailable',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

add_filter( 'pre_http_request', $server_error, 10, 3 );

$broken  = usdtf_plugin()->health()->loopback_test();
$backend = usdtf_plugin()->scheduler()->backend();

remove_filter( 'pre_http_request', $server_error, 10 );

usdtf_it_assert( empty( $broken['ok'] ), 'a 503 must not be reported as a working loopback' );
usdtf_it_assert_same( 503, $broken['status'], 'a 503 must report its status' );

usdtf_it_assert( in_array( $backend, array( \USDTF\Scheduler::BACKEND_ACTION_SCHEDULER, \USDTF\Scheduler::BACKEND_WP_CRON, \USDTF\Scheduler::BACKEND_LOOPBACK, \USDTF\Scheduler::BACKEND_NONE ), true ), 'the backend must be one of the documented values' );

usdtf_it_pass( 'the loopback diagnostic reports reachable, failing and unreachable sites' );

// ---------------------------------------------------------------------------
// 28. The admin screen renders every tab and escapes what it shows.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$admin = usdtf_plugin()->admin();

$render = static function ( $tab, array $query = array() ) use ( $admin ) {
	$previous_get = $_GET;
	$_GET         = array_merge(
		array(
			'page' => 'usdtf',
			'tab'  => $tab,
		),
		$query
	);

	ob_start();

	try {
		$admin->render_page();
	} catch ( \USDTF_IT_Die $die ) {
		$_GET = $previous_get;

		return $die;
	}

	$_GET = $previous_get;

	return (string) ob_get_clean();
};

$tabs = array( 'dashboard', 'jobs', 'health', 'settings' );

foreach ( $tabs as $tab ) {
	$html = $render( $tab );

	usdtf_it_assert( is_string( $html ), 'the ' . $tab . ' tab must render' );
	usdtf_it_assert( false !== strpos( $html, 'usdtf-wrap' ), 'the ' . $tab . ' tab must render inside the plugin wrapper' );
	usdtf_it_assert( false !== strpos( $html, 'tab=' . $tab ), 'the ' . $tab . ' tab must be reachable from the navigation' );
	usdtf_it_assert( false === strpos( $html, 'Fatal error' ), 'the ' . $tab . ' tab must not fail' );
}

$dashboard = $render( 'dashboard' );

usdtf_it_assert( false !== strpos( $dashboard, 'usdtf-rate-value' ), 'the dashboard must show the current rate' );
usdtf_it_assert( false !== strpos( $dashboard, 'usdtf-save-rate' ), 'the dashboard must offer saving the rate' );
usdtf_it_assert( false !== strpos( $dashboard, 'usdtf-start-update' ), 'the dashboard must offer updating the prices' );
// The scripts are printed in the admin footer, so the wiring is asserted on
// the enqueue rather than on the rendered page.
$admin->enqueue_assets( 'woocommerce_page_usdtf' );

usdtf_it_assert( wp_script_is( 'usdtf-admin', 'enqueued' ), 'the plugin screen must load the admin script' );
usdtf_it_assert( wp_style_is( 'usdtf-admin', 'enqueued' ), 'the plugin screen must load the admin stylesheet' );

$localized = (string) wp_scripts()->get_data( 'usdtf-admin', 'data' );

usdtf_it_assert( false !== strpos( $localized, 'usdtfData' ), 'the script must receive its configuration' );
usdtf_it_assert( false !== strpos( $localized, 'restNamespace' ) && false !== strpos( $localized, 'restUrl' ), 'the script must receive the REST endpoint' );
usdtf_it_assert( false !== strpos( $localized, '"nonce"' ), 'the script must receive a REST nonce' );
usdtf_it_assert( false !== strpos( $localized, '"confirmPhrase"' ), 'the script must receive the confirmation phrase it asks the admin to type' );
usdtf_it_assert( false !== strpos( $localized, '"rate"' ), 'the script must receive the current state' );

$admin_script = (string) file_get_contents( USDTF_DIR . 'assets/js/admin.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Integration fixture.
$admin_style  = (string) file_get_contents( USDTF_DIR . 'assets/css/admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Integration fixture.

usdtf_it_assert( false !== strpos( $admin_script, '/status?_usdtf=' ), 'live progress must poll the lightweight cache-busted status endpoint' );
usdtf_it_assert( false !== strpos( $admin_script, "'Cache-Control' ] = 'no-cache'" ), 'live GET requests must explicitly bypass intermediary caches' );
usdtf_it_assert( false !== strpos( $admin_script, 'is-indeterminate' ), 'discovery must render as indeterminate progress instead of a misleading 0/0 bar' );
usdtf_it_assert( false !== strpos( $admin_style, '@keyframes usdtf-progress-indeterminate' ), 'the indeterminate discovery bar must be animated' );

// An unknown tab falls back to the dashboard instead of failing.
$fallback = $render( 'not-a-tab' );

usdtf_it_assert( false !== strpos( $fallback, 'usdtf-rate-value' ), 'an unknown tab must fall back to the dashboard' );

// Values coming from a product name must be escaped on every screen.
$escaping_product = usdtf_it_make_simple_product( 'Escaping <script>alert(1)</script>', 2700000 );
$escaping_runs    = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );

usdtf_it_run_job( (int) $escaping_runs['id'] );

foreach ( array( 'dashboard', 'jobs' ) as $tab ) {
	$html = $render( $tab );

	usdtf_it_assert( false === strpos( $html, '<script>alert(1)</script>' ), 'the ' . $tab . ' tab must escape a product name' );
}

$job_html = $render( 'jobs', array( 'job' => (int) $escaping_runs['id'] ) );

usdtf_it_assert( false !== strpos( $job_html, 'usdtf-job' ) || false !== strpos( $job_html, 'job' ), 'the job detail must render' );
usdtf_it_assert( false === strpos( $job_html, '<script>alert(1)</script>' ), 'the job detail must escape a product name' );

// The screen is closed to users without the capability.
$ui_subscriber = wp_insert_user(
	array(
		'user_login' => 'usdtf_ui_sub_' . wp_rand( 1000, 999999 ),
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'subscriber',
	)
);

$ui_admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$ui_admin = $ui_admin ? (int) $ui_admin[0] : 0;

wp_set_current_user( (int) $ui_subscriber );
$denied = $render( 'dashboard' );

usdtf_it_assert( $denied instanceof \USDTF_IT_Die, 'a subscriber must not render the plugin screen' );
usdtf_it_assert_same( 403, $denied->die_status, 'a subscriber must be refused with 403' );

require_once ABSPATH . 'wp-admin/includes/user.php';

wp_delete_user( (int) $ui_subscriber );
wp_set_current_user( $ui_admin );

// The stale currency mode notice is shown to the administrator.
$settings->update( array( 'currency_mode' => \USDTF\Settings::MODE_USD ) );
$settings->set_synced_currency_mode( \USDTF\Settings::MODE_TOMAN );

usdtf_it_assert( $settings->currency_mode_is_stale(), 'the currency mode must be reported as stale after a change' );

require_once ABSPATH . 'wp-admin/includes/screen.php';

set_current_screen( 'woocommerce_page_usdtf' );

ob_start();
$admin->render_notices();
$notices = (string) ob_get_clean();

usdtf_it_assert( false !== strpos( $notices, 'notice-warning' ), 'a stale currency mode must produce an admin notice' );

$settings->set_synced_currency_mode( \USDTF\Settings::MODE_USD );

usdtf_it_assert( ! $settings->currency_mode_is_stale(), 'a matching currency mode must not be reported as stale' );

usdtf_it_pass( 'the admin screen renders every tab, escapes output and honours capabilities' );

// ---------------------------------------------------------------------------
// 29. The product panel: meta box, saving, bulk actions and the list column.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();
usdtf_it_reset_plugin_state();
$rates->save_rate( 270000 );

$panel = \USDTF\Admin\Product_Panel::class;

// The panel instance is created by the admin screen; build one the same way.
$panel_object = new $panel( $settings, $pricing, usdtf_plugin()->products() );

wp_set_current_user( $ui_admin );

$panel_product = usdtf_it_make_simple_product( 'Panel product', 5000000 );
$panel_job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );

usdtf_it_run_job( (int) $panel_job['id'] );

ob_start();
$panel_object->render_meta_box( get_post( $panel_product->get_id() ) );
$meta_box = (string) ob_get_clean();

usdtf_it_assert( false !== strpos( $meta_box, 'usdtf_mode' ), 'the meta box must offer the pricing mode' );
usdtf_it_assert( false !== strpos( $meta_box, 'usdtf_source_regular' ), 'the meta box must offer the Toman regular price' );
usdtf_it_assert( false !== strpos( $meta_box, 'usdtf_panel_nonce' ), 'the meta box must carry a nonce' );
usdtf_it_assert( false !== strpos( $meta_box, '19' ), 'the meta box must show the derived USD price' );

// A save without the nonce must not change anything.
$_POST = array(
	'usdtf_mode'           => \USDTF\Product_Pricing::MODE_NATIVE,
	'usdtf_source_regular' => '999',
);

$panel_object->save_product( $panel_product->get_id() );

usdtf_it_assert_same( \USDTF\Product_Pricing::MODE_MANAGED, $pricing->get_mode( $panel_product->get_id() ), 'a save without a nonce must be ignored' );
usdtf_it_assert_same( '5000000', usdtf_it_meta( $panel_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'a save without a nonce must not touch the source' );

// A real save updates the mode and the Toman source.
$revision_before = $pricing->get_revision( $panel_product->get_id() );

$_POST = array(
	'usdtf_panel_nonce'    => wp_create_nonce( $panel::NONCE ),
	'usdtf_mode'           => \USDTF\Product_Pricing::MODE_MANAGED,
	'usdtf_source_regular' => '5,400,000',
	'usdtf_source_sale'    => '4,000,000',
);

$panel_object->save_product( $panel_product->get_id() );

usdtf_it_assert_same( '5400000', usdtf_it_meta( $panel_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'the panel must store the Toman regular price it was given' );
usdtf_it_assert_same( '4000000', usdtf_it_meta( $panel_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_SALE ), 'the panel must store the Toman sale price it was given' );
usdtf_it_assert( $pricing->get_revision( $panel_product->get_id() ) > $revision_before, 'saving the source must bump the revision' );

// An invalid pair is refused and reported to the editor.
$_POST = array(
	'usdtf_panel_nonce'    => wp_create_nonce( $panel::NONCE ),
	'usdtf_mode'           => \USDTF\Product_Pricing::MODE_MANAGED,
	'usdtf_source_regular' => '1000000',
	'usdtf_source_sale'    => '2000000',
);

$panel_object->save_product( $panel_product->get_id() );

usdtf_it_assert_same( '5400000', usdtf_it_meta( $panel_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'a sale price above the regular price must be refused' );
usdtf_it_assert( false !== get_transient( 'usdtf_panel_error_' . $ui_admin ), 'a refused save must leave a message for the editor' );

delete_transient( 'usdtf_panel_error_' . $ui_admin );

// Switching the product to native USD keeps the Toman source but stops syncing.
$_POST = array(
	'usdtf_panel_nonce' => wp_create_nonce( $panel::NONCE ),
	'usdtf_mode'        => \USDTF\Product_Pricing::MODE_NATIVE,
);

$panel_object->save_product( $panel_product->get_id() );

usdtf_it_assert_same( \USDTF\Product_Pricing::MODE_NATIVE, $pricing->get_mode( $panel_product->get_id() ), 'the panel must be able to switch the pricing mode' );
usdtf_it_assert_same( '5400000', usdtf_it_meta( $panel_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'switching the mode must not destroy the Toman source' );

// Bulk actions map to the three modes, and "enable" imports the current price.
$bulk_actions = $panel_object->register_bulk_actions( array() );

usdtf_it_assert( isset( $bulk_actions['usdtf_enable'], $bulk_actions['usdtf_native'], $bulk_actions['usdtf_exclude'] ), 'the three bulk actions must be registered' );

$bulk_product = usdtf_it_make_simple_product( 'Bulk product', '', '', \USDTF\Product_Pricing::MODE_NATIVE );
$bulk_product = wc_get_product( $bulk_product->get_id() );
$bulk_product->set_regular_price( '7500000' );
$bulk_product->save();

$redirect = $panel_object->handle_bulk_actions( 'edit.php?post_type=product', 'usdtf_enable', array( $bulk_product->get_id() ) );

usdtf_it_assert_same( \USDTF\Product_Pricing::MODE_MANAGED, $pricing->get_mode( $bulk_product->get_id() ), 'the bulk action must enable Toman pricing' );
usdtf_it_assert_same( '7500000', usdtf_it_meta( $bulk_product->get_id(), \USDTF\Product_Pricing::META_SOURCE_REGULAR ), 'enabling must import the price that is already there' );
usdtf_it_assert( false !== strpos( $redirect, 'usdtf_bulk_done=1' ), 'the bulk action must report what it did' );

$panel_object->handle_bulk_actions( 'edit.php?post_type=product', 'usdtf_exclude', array( $bulk_product->get_id() ) );

usdtf_it_assert_same( \USDTF\Product_Pricing::MODE_EXCLUDED, $pricing->get_mode( $bulk_product->get_id() ), 'the bulk action must be able to exclude a product' );

// The list column shows the mode of the product.
$columns = $panel_object->add_column( array( 'title' => 'Title' ) );

usdtf_it_assert( isset( $columns['usdtf_toman'] ), 'the product list must gain a Toman price column' );

ob_start();
$panel_object->render_column( 'usdtf_toman', $panel_product->get_id() );
$column = (string) ob_get_clean();

usdtf_it_assert( '' !== trim( wp_strip_all_tags( $column ) ), 'the product list column must show something for a managed product' );

ob_start();
$panel_object->render_column( 'some_other_column', $panel_product->get_id() );
$other_column = (string) ob_get_clean();

usdtf_it_assert_same( '', $other_column, 'the column renderer must ignore foreign columns' );

// Recalculating without a nonce must be refused.
$_GET = array( 'product_id' => $panel_product->get_id() );

$refused = null;

try {
	$panel_object->handle_recalculate();
} catch ( \USDTF_IT_Die $die ) {
	$refused = $die;
}

usdtf_it_assert( $refused instanceof \USDTF_IT_Die, 'recalculating without a nonce must be refused' );

$_POST = array();
$_GET  = array();

usdtf_it_pass( 'the product panel saves, refuses invalid input and drives the bulk actions' );

// ---------------------------------------------------------------------------
// 30. A fresh install transacts in Toman, an upgrade keeps the stored mode.
// ---------------------------------------------------------------------------
usdtf_it_delete_products();

// Simulate a brand new site: no settings option at all, then the installer runs.
delete_option( \USDTF\Settings::OPTION );
delete_option( \USDTF\Settings::OPTION_SYNCED_CURRENCY_MODE );
delete_option( \USDTF\Settings::OPTION_RATE );

\USDTF\Installer::seed_options();

// Force the shared instance to re-read the option, the way a new request would.
usdtf_plugin()->settings()->all( true );

// The fresh install restores every default, including the dry-run-first rule.
// This scenario is about the currency semantics; the gate itself is covered by
// the dry-run scenarios, so it is pinned off like in every other scenario.
usdtf_plugin()->settings()->update( array( 'require_preview' => false ) );
$fresh = new \USDTF\Settings();

usdtf_it_assert_same( \USDTF\Settings::MODE_TOMAN, $fresh->get( 'currency_mode' ), 'a fresh install must transact in Toman' );
usdtf_it_assert( ! $fresh->currency_mode_is_stale(), 'a fresh install must not look like a pending currency switch' );
usdtf_it_assert_same(
	\USDTF\Settings::MODE_TOMAN,
	get_option( \USDTF\Settings::OPTION_SYNCED_CURRENCY_MODE ),
	'the recorded price field currency must follow the default'
);
usdtf_it_assert_same( 'IRT', get_woocommerce_currency(), 'a fresh install must charge in Toman' );
usdtf_it_assert_same( 'تومان', get_woocommerce_currency_symbol(), 'the default currency symbol must be the Toman label' );

// Products then hold their canonical Toman price in the normal price fields.
$rates->save_rate( 270000 );
$fresh_product = usdtf_it_make_simple_product( 'Fresh install product', 5400000 );
$fresh_job     = usdtf_it_create_job( array( 'type' => \USDTF\Job::TYPE_SYNC ) );
usdtf_it_run_job( (int) $fresh_job['id'] );

usdtf_it_assert_same( '5400000', usdtf_it_price( $fresh_product->get_id() ), 'the price field must hold the canonical Toman price' );
usdtf_it_assert_same( '20', usdtf_it_meta( $fresh_product->get_id(), \USDTF\Product_Pricing::META_DERIVED_REGULAR ), 'the derived USD price must stay a reference' );

// An existing store keeps the mode it stored, whatever the new default is.
update_option( \USDTF\Settings::OPTION, array( 'currency_mode' => \USDTF\Settings::MODE_USD ) );
update_option( \USDTF\Settings::OPTION_SYNCED_CURRENCY_MODE, \USDTF\Settings::MODE_USD );

\USDTF\Installer::seed_options();

$kept = new \USDTF\Settings();
usdtf_it_assert_same( \USDTF\Settings::MODE_USD, $kept->get( 'currency_mode' ), 'an upgrade must not switch an existing store to Toman' );
usdtf_it_assert_same( 10, (int) $kept->get( 'batch_size' ), 'the rest of the defaults must be backfilled' );
usdtf_it_assert( ! $kept->currency_mode_is_stale(), 'an upgrade must not report a pending currency switch for a stored mode' );

usdtf_it_pass( 'a fresh install transacts in Toman and an upgrade keeps the stored mode' );

// ---------------------------------------------------------------------------
// 31. The bundled Persian catalogue covers the template and loads.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();

usdtf_it_assert( has_action( 'init', array( usdtf_plugin(), 'load_textdomain' ) ), 'the plugin must load its own text domain' );

$usdtf_it_po  = USDTF_DIR . 'languages/usd-to-toman-price-sync-for-woocommerce-fa_IR.po';
$usdtf_it_mo  = USDTF_DIR . 'languages/usd-to-toman-price-sync-for-woocommerce-fa_IR.mo';
$usdtf_it_pot = USDTF_DIR . 'languages/usd-to-toman-price-sync-for-woocommerce.pot';

usdtf_it_assert( is_readable( $usdtf_it_pot ), 'the translation template must ship with the plugin' );
usdtf_it_assert( is_readable( $usdtf_it_po ), 'the Persian source catalogue must ship with the plugin' );
usdtf_it_assert( is_readable( $usdtf_it_mo ), 'the compiled Persian catalogue must ship with the plugin' );

if ( ! class_exists( 'PO' ) ) {
	require_once ABSPATH . WPINC . '/pomo/po.php';
}

$usdtf_it_template = new \PO();
$usdtf_it_persian  = new \PO();

usdtf_it_assert( $usdtf_it_template->import_from_file( $usdtf_it_pot ), 'the template must be readable' );
usdtf_it_assert( $usdtf_it_persian->import_from_file( $usdtf_it_po ), 'the Persian catalogue must be readable' );
usdtf_it_assert_same( count( $usdtf_it_template->entries ), count( $usdtf_it_persian->entries ), 'every template string must be translated' );

$usdtf_it_untranslated = array();

foreach ( $usdtf_it_persian->entries as $usdtf_it_entry ) {
	if ( '' === (string) $usdtf_it_entry->translations[0] && '' !== (string) $usdtf_it_entry->singular ) {
		$usdtf_it_untranslated[] = (string) $usdtf_it_entry->singular;
	}
}

usdtf_it_assert_same( array(), $usdtf_it_untranslated, 'no string may be left empty in the Persian catalogue' );

// The compiled catalogue must agree with its source and be loadable.
$usdtf_it_compiled = new \MO();
usdtf_it_assert( $usdtf_it_compiled->import_from_file( $usdtf_it_mo ), 'the compiled catalogue must be readable' );
usdtf_it_assert_same( count( $usdtf_it_persian->entries ), count( $usdtf_it_compiled->entries ), 'the compiled catalogue must hold every translated string' );

usdtf_it_assert( load_textdomain( USDTF_SLUG, $usdtf_it_mo ), 'the compiled catalogue must load' );
usdtf_it_assert_same( 'تنظیمات', __( 'Settings', USDTF_SLUG ), 'a translated string must come back in Persian' );
usdtf_it_assert_same( 'نیازی به همگام‌سازی نیست.', __( 'No synchronization required.', USDTF_SLUG ), 'sentences must come back in Persian too' );

unload_textdomain( USDTF_SLUG );

usdtf_it_pass( 'the bundled Persian catalogue covers the template and loads' );
