<?php
/**
 * Shared helpers for the WordPress + WooCommerce integration suite.
 *
 * These tests boot a real WordPress installation with WooCommerce active and
 * drive the plugin's public services, so the price writes go through the real
 * WooCommerce CRUD API.
 *
 * @package USDTF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Root directory of the plugin under test.
 *
 * @return string
 */
function usdtf_it_plugin_dir() {
	return dirname( dirname( __DIR__ ) );
}

/**
 * WordPress installation directory.
 *
 * @return string
 */
function usdtf_it_wp_dir() {
	$path = getenv( 'USDTF_WP_PATH' );

	if ( ! $path && ! empty( $GLOBALS['argv'][1] ) ) {
		$path = $GLOBALS['argv'][1];
	}

	if ( ! $path ) {
		fwrite( STDERR, "Set USDTF_WP_PATH or pass the WordPress directory as the first argument.\n" );
		exit( 1 );
	}

	return rtrim( $path, '/' );
}

/**
 * Make the plugin available in the WordPress plugins directory.
 *
 * @return void
 */
function usdtf_it_link_plugin() {
	$target = usdtf_it_wp_dir() . '/wp-content/plugins/usd-to-toman-price-sync-for-woocommerce';

	if ( is_link( $target ) || is_dir( $target ) ) {
		return;
	}

	$source = usdtf_it_plugin_dir();

	if ( ! @symlink( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback below.
		// Copy without the development files.
		mkdir( $target, 0777, true );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$relative = substr( $file->getPathname(), strlen( $source ) + 1 );

			if ( 0 === strpos( $relative, '.git/' ) || 0 === strpos( $relative, 'tests/' ) || 0 === strpos( $relative, '.github/' ) ) {
				continue;
			}

			$destination = $target . '/' . $relative;

			if ( $file->isDir() ) {
				mkdir( $destination, 0777, true );
			} else {
				copy( $file->getPathname(), $destination );
			}
		}
	}
}

/**
 * Boot WordPress for the test run.
 *
 * @return void
 */
function usdtf_it_boot() {
	$wp_dir = usdtf_it_wp_dir();

	if ( ! file_exists( $wp_dir . '/wp-load.php' ) ) {
		fwrite( STDERR, "WordPress was not found at {$wp_dir}.\n" );
		exit( 1 );
	}

	$_SERVER['HTTP_HOST']      = 'localhost';
	$_SERVER['SERVER_NAME']    = 'localhost';
	$_SERVER['REQUEST_URI']    = '/';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['SERVER_PORT']    = '80';

	require_once $wp_dir . '/wp-load.php';

	if ( ! function_exists( 'wc_get_product' ) ) {
		fwrite( STDERR, "WooCommerce is not active in the test installation.\n" );
		exit( 1 );
	}

	if ( ! function_exists( 'usdtf_plugin' ) ) {
		fwrite( STDERR, "The plugin under test is not active.\n" );
		exit( 1 );
	}
}

/**
 * Assertion helper.
 *
 * @param bool   $condition Condition.
 * @param string $message   Message shown on failure.
 * @return void
 */
function usdtf_it_assert( $condition, $message ) {
	static $count = 0;

	++$count;

	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	if ( function_exists( 'debug_print_backtrace' ) ) {
		ob_start();
		debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 );
		fwrite( STDERR, ob_get_clean() );
	}

	exit( 1 );
}

/**
 * Assert two values are the same.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Message.
 * @return void
 */
function usdtf_it_assert_same( $expected, $actual, $message ) {
	if ( $expected === $actual ) {
		return;
	}

	usdtf_it_assert(
		false,
		str_replace(
			array( "\r", "\n" ),
			' ',
			sprintf(
				'%s (expected %s, got %s)',
				$message,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		)
	);
}

/**
 * Print a passing test header.
 *
 * @param string $message Message.
 * @return void
 */
function usdtf_it_pass( $message ) {
	echo "ok - {$message}\n";
}

require_once __DIR__ . '/die.php';

/**
 * Route wp_die() through an exception so a scenario can assert on it.
 *
 * @return void
 */
function usdtf_it_catch_wp_die() {
	add_filter( 'wp_die_handler', 'usdtf_it_die_callback' );
	add_filter( 'wp_die_ajax_handler', 'usdtf_it_die_callback' );
	add_filter( 'wp_die_json_handler', 'usdtf_it_die_callback' );
	add_filter( 'wp_die_xmlrpc_handler', 'usdtf_it_die_callback' );
}

/**
 * Handler name used by the wp_die() filters.
 *
 * @return string
 */
function usdtf_it_die_callback() {
	return 'usdtf_it_die';
}

/**
 * Throw instead of ending the process.
 *
 * @param string $message Message.
 * @param string $title   Title.
 * @param array  $args    wp_die() arguments.
 * @return void
 * @throws USDTF_IT_Die Always, so the caller can assert on the request that was killed.
 */
function usdtf_it_die( $message, $title = '', $args = array() ) {
	throw new USDTF_IT_Die( $message, $title, $args );
}

/**
 * Perform a REST request in this process.
 *
 * @param string $method HTTP method.
 * @param string $route  Route, without the namespace.
 * @param array  $params Request parameters.
 * @return \WP_REST_Response|\WP_Error
 */
function usdtf_it_rest( $method, $route, array $params = array() ) {
	do_action( 'rest_api_init' );

	$request = new \WP_REST_Request( $method, '/usdtf/v1' . $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_do_request( $request );
}

/**
 * Error code of a REST response, empty when it is not an error.
 *
 * WordPress wraps a WP_Error in a WP_REST_Response, so both shapes have to be
 * handled.
 *
 * @param mixed $response Response.
 * @return string
 */
function usdtf_it_rest_error_code( $response ) {
	if ( is_wp_error( $response ) ) {
		return (string) $response->get_error_code();
	}

	if ( $response instanceof \WP_REST_Response && $response->is_error() ) {
		$error = $response->as_error();

		return is_wp_error( $error ) ? (string) $error->get_error_code() : '';
	}

	return '';
}

/**
 * Error payload of a REST response, empty array when it is not an error.
 *
 * @param mixed $response Response.
 * @return array
 */
function usdtf_it_rest_error_data( $response ) {
	if ( is_wp_error( $response ) ) {
		return (array) $response->get_error_data();
	}

	if ( $response instanceof \WP_REST_Response && $response->is_error() ) {
		$error = $response->as_error();
		$data  = is_wp_error( $error ) ? $error->get_error_data() : array();

		return is_array( $data ) ? $data : array();
	}

	return array();
}

/**
 * Data of a REST response.
 *
 * @param mixed $response Response.
 * @return array
 */
function usdtf_it_rest_data( $response ) {
	if ( ! $response instanceof \WP_REST_Response ) {
		return array();
	}

	$data = $response->get_data();

	return is_array( $data ) ? $data : array();
}

/**
 * Create a simple product with Toman source prices.
 *
 * @param string $name    Product name.
 * @param mixed  $regular Toman regular price.
 * @param mixed  $sale    Toman sale price.
 * @param string $mode    Pricing mode.
 * @return \WC_Product
 */
function usdtf_it_make_simple_product( $name, $regular, $sale = '', $mode = 'managed' ) {
	$product = new \WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	// A managed product carries no WooCommerce price until the first sync: the
	// canonical value lives in the Toman source meta only.
	$product->save();

	$pricing = usdtf_plugin()->pricing();
	$pricing->set_mode( $product->get_id(), $mode );
	$pricing->set_source( $product->get_id(), $regular, $sale );

	return wc_get_product( $product->get_id() );
}

/**
 * Create a variable product with the given variations.
 *
 * @param string $name       Product name.
 * @param array  $variations List of [ regular, sale ] Toman pairs.
 * @param string $mode       Pricing mode.
 * @return \WC_Product_Variable
 */
function usdtf_it_make_variable_product( $name, array $variations, $mode = 'managed' ) {
	$product = new \WC_Product_Variable();
	$product->set_name( $name );
	$product->set_status( 'publish' );

	$attribute = new \WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( array_map( 'strval', range( 1, count( $variations ) ) ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$product->set_attributes( array( $attribute ) );

	$product->save();

	$pricing = usdtf_plugin()->pricing();
	$pricing->set_mode( $product->get_id(), $mode );

	foreach ( $variations as $index => $prices ) {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => (string) ( $index + 1 ) ) );
		$variation->save();

		$pricing->set_mode( $variation->get_id(), $mode );
		$pricing->set_source( $variation->get_id(), $prices[0], isset( $prices[1] ) ? $prices[1] : '' );
	}

	return wc_get_product( $product->get_id() );
}

/**
 * Get or create a product category term.
 *
 * @param string $name Category name.
 * @return int Term ID, 0 on failure.
 */
function usdtf_it_term( $name ) {
	$term = get_term_by( 'name', $name, 'product_cat' );

	if ( $term instanceof WP_Term ) {
		return (int) $term->term_id;
	}

	$created = wp_insert_term( $name, 'product_cat' );

	if ( is_wp_error( $created ) ) {
		$term = get_term_by( 'name', $name, 'product_cat' );

		return $term instanceof WP_Term ? (int) $term->term_id : 0;
	}

	return (int) $created['term_id'];
}

/**
 * Run a job to completion by firing the worker actions the way the queue does.
 *
 * Closing the browser cannot cancel a job because the queue owns the worker. In
 * this test the queue is replaced by a loop that calls the exact same hooks.
 *
 * @param int $job_id         Job ID.
 * @param int $max_iterations Safety limit.
 * @return int Number of worker steps performed.
 */
/**
 * Create a job and stop with a readable failure when it is refused.
 *
 * @param array $args Job arguments.
 * @return array Job payload.
 */
function usdtf_it_create_job( array $args = array() ) {
	global $runner;

	$job = $runner->create_job( $args );

	usdtf_it_assert(
		! is_wp_error( $job ) && isset( $job['id'] ),
		'the job must be created'
			. ( is_wp_error( $job ) ? ' (' . $job->get_error_code() . ': ' . $job->get_error_message() . ')' : '' )
	);

	return $job;
}

/**
 * Run a job to its end by stepping through every phase in this process.
 *
 * @param int $job_id        Job ID.
 * @param int $max_iterations Safety valve against a job that never finishes.
 * @return int Number of worker steps the job needed.
 */
function usdtf_it_run_job( $job_id, $max_iterations = 500 ) {
	$runner = usdtf_plugin()->runner();
	$steps  = 0;

	for ( $i = 0; $i < $max_iterations; $i++ ) {
		$job = usdtf_plugin()->jobs()->get( $job_id );

		if ( ! $job || ! $job->is_active() ) {
			break;
		}

		switch ( $job->phase() ) {
			case \USDTF\Job::PHASE_DISCOVER:
			case \USDTF\Job::PHASE_DISCOVER_VARIATIONS:
				$runner->handle_discovery( $job_id );
				break;
			case \USDTF\Job::PHASE_FINALIZE:
				$runner->handle_finalize( $job_id );
				break;
			default:
				$runner->handle_batch( $job_id );
				break;
		}

		++$steps;
	}

	return $steps;
}

/**
 * Reset plugin state between scenario groups.
 *
 * @return void
 */
function usdtf_it_reset_plugin_state() {
	global $wpdb;

	$options = array(
		\USDTF\Settings::OPTION,
		\USDTF\Settings::OPTION_RATE,
		\USDTF\Settings::OPTION_PENDING_RATE,
		\USDTF\Settings::OPTION_SYNCED_CURRENCY_MODE,
		'usdtf_last_error',
		'usdtf_stale_resumes',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Use the shared instance so nothing keeps a cached copy of the old values.
	usdtf_plugin()->settings()->reset();

	// A fresh install defaults to Toman transactions, which has its own
	// scenario. The other scenarios describe the USD transaction mode, so it is
	// pinned here instead of being inherited from the default.
	usdtf_plugin()->settings()->update( array( 'currency_mode' => \USDTF\Settings::MODE_USD ) );
	usdtf_plugin()->settings()->set_synced_currency_mode( \USDTF\Settings::MODE_USD );

	// The shipped default requires a dry run before every update; the mechanics
	// scenarios create jobs directly, so they opt out. The enforcement itself
	// has its own dedicated scenario.
	usdtf_plugin()->settings()->update( array( 'require_preview' => false ) );

	foreach ( array( \USDTF\Database::jobs_table(), \USDTF\Database::items_table(), \USDTF\Database::rates_table(), \USDTF\Database::logs_table() ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM `{$table}`" );
	}

	delete_option( \USDTF\Lock::OPTION );

	// Worker actions queued by earlier scenarios. Job ids are reused after the
	// jobs table is emptied, and a leftover action for the same hook and
	// arguments would make the queue think the new job is already scheduled.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), \USDTF\Scheduler::GROUP );
	}

	foreach ( array( \USDTF\Sync_Runner::HOOK_DISCOVER, \USDTF\Sync_Runner::HOOK_PROCESS, \USDTF\Sync_Runner::HOOK_FINALIZE ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	usdtf_plugin()->products()->flush_counts_cache();
}

/**
 * Delete every product created by the suite.
 *
 * @return void
 */
function usdtf_it_delete_products() {
	$ids = get_posts(
		array(
			'post_type'      => array( 'product', 'product_variation' ),
			// Trash is not included in "any", and the status scenario leaves
			// trashed products behind.
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash', 'auto-draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}
