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

	usdtf_it_guard_store();
}

/**
 * Option listing the products this suite created.
 */
const USDTF_IT_FIXTURE_OPTION = 'usdtf_it_fixture_ids';

/**
 * Option listing the product categories this suite created.
 */
const USDTF_IT_FIXTURE_TERM_OPTION = 'usdtf_it_fixture_terms';

/**
 * Option holding the product modes the store guard switched away from.
 */
const USDTF_IT_GUARD_OPTION = 'usdtf_it_store_guard_modes';

/**
 * Every product and variation on the site, whatever its status.
 *
 * @return int[]
 */
function usdtf_it_all_product_ids() {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash', 'auto-draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		)
	);
}

/**
 * Products this suite created, and therefore may delete.
 *
 * @return int[]
 */
function usdtf_it_fixtures() {
	$ids = get_option( USDTF_IT_FIXTURE_OPTION, array() );

	return is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
}

/**
 * Record a product as a fixture of this suite.
 *
 * @param int|\WC_Product $product Product or product ID.
 * @return void
 */
function usdtf_it_track_product( $product ) {
	$product_id = $product instanceof \WC_Product ? (int) $product->get_id() : (int) $product;

	if ( $product_id <= 0 ) {
		return;
	}

	$ids = usdtf_it_fixtures();

	if ( in_array( $product_id, $ids, true ) ) {
		return;
	}

	$ids[] = $product_id;

	update_option( USDTF_IT_FIXTURE_OPTION, $ids, false );
}

/**
 * Record a product category as a fixture of this suite.
 *
 * @param int $term_id Term ID.
 * @return void
 */
function usdtf_it_track_term( $term_id ) {
	$term_id = (int) $term_id;
	$terms   = get_option( USDTF_IT_FIXTURE_TERM_OPTION, array() );
	$terms   = is_array( $terms ) ? array_map( 'intval', $terms ) : array();

	if ( $term_id <= 0 || in_array( $term_id, $terms, true ) ) {
		return;
	}

	$terms[] = $term_id;

	update_option( USDTF_IT_FIXTURE_TERM_OPTION, $terms, false );
}

/**
 * Identity of a queued action, used to tell the store's own queue from the
 * suite's leftovers.
 *
 * @param string $hook Action hook.
 * @param array  $args Action arguments.
 * @return string
 */
function usdtf_it_action_key( $hook, $args ) {
	$encoded = wp_json_encode( is_array( $args ) ? array_values( $args ) : array() );

	return (string) $hook . '|' . ( false === $encoded ? '' : $encoded );
}

/**
 * Pending worker actions in the plugin's Action Scheduler group.
 *
 * Action Scheduler returns the action objects themselves unless ids are asked
 * for. Reading the accessors is the portable way to do it: the array form is
 * built from the object's public properties, which change between versions
 * (for example there is no scheduled_date_gmt on every one of them).
 *
 * @return array[] Each entry has hook, args and timestamp.
 */
function usdtf_it_pending_actions() {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return array();
	}

	$actions = as_get_scheduled_actions(
		array(
			'group'    => \USDTF\Scheduler::GROUP,
			'status'   => 'pending',
			'per_page' => 500,
		),
		'OBJECT'
	);

	$found = array();

	foreach ( (array) $actions as $action ) {
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) ) {
			continue;
		}

		$timestamp = time();
		$schedule  = method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;

		if ( $schedule && method_exists( $schedule, 'get_date' ) && $schedule->get_date() instanceof \DateTimeInterface ) {
			$timestamp = (int) $schedule->get_date()->getTimestamp();
		}

		$found[] = array(
			'hook'      => (string) $action->get_hook(),
			'args'      => method_exists( $action, 'get_args' ) ? (array) $action->get_args() : array(),
			'timestamp' => $timestamp,
		);
	}

	return $found;
}

/**
 * Take the store's own state out of the suite's reach.
 *
 * The suite drives the plugin through its public services, so a run resets the
 * plugin options, empties its tables and starts catalog wide jobs. Pointing
 * that at a live store must not damage it, so before the first scenario this:
 *
 * - remembers the plugin options, the job, item, rate and log rows, the
 *   products the store already tracks for this plugin and the worker actions
 *   that are currently queued,
 * - holds those products out of the suite's price updates by switching them to
 *   the excluded mode for the duration of the run,
 * - and puts all of it back when the run ends, including after a failed
 *   assertion, because the restore is a shutdown handler. A run that is killed
 *   outright never reaches that handler, so the modes it replaced are written
 *   to an option as well and repaired by the next run that starts.
 *
 * Together with usdtf_it_delete_products(), which only ever removes products
 * this suite created, that is what keeps a run from deleting or repricing the
 * shop's catalog.
 *
 * Set USDTF_IT_SKIP_STORE_GUARD=1 on a dedicated test installation where the
 * suit's own reset is wanted.
 *
 * @return void
 */
/**
 * Put back the product modes of a run that never reached its shutdown handler.
 *
 * The guard holds the store's own products out of every catalog wide job by
 * switching them to the excluded mode. That write is undone when the run ends,
 * but a run killed outright never gets there, so the modes it replaced are also
 * written to an option and repaired by the next run that starts.
 *
 * @return void
 */
function usdtf_it_recover_interrupted_guard() {
	$modes = get_option( USDTF_IT_GUARD_OPTION, null );

	if ( ! is_array( $modes ) || ! $modes ) {
		return;
	}

	$repaired = 0;

	foreach ( $modes as $product_id => $mode ) {
		$product_id = (int) $product_id;

		if ( ! in_array( get_post_type( $product_id ), array( 'product', 'product_variation' ), true ) ) {
			continue;
		}

		update_post_meta( $product_id, \USDTF\Product_Pricing::META_MODE, (string) $mode );

		++$repaired;
	}

	delete_option( USDTF_IT_GUARD_OPTION );

	printf( 'store guard: repaired %d product mode(s) an interrupted run left behind' . PHP_EOL, $repaired );
}

/**
 * Run the store guard.
 *
 * @return void
 */
function usdtf_it_guard_store() {
	global $wpdb;

	if ( getenv( 'USDTF_IT_SKIP_STORE_GUARD' ) ) {
		return;
	}

	if ( isset( $GLOBALS['usdtf_it_store_snapshot'] ) && null !== $GLOBALS['usdtf_it_store_snapshot'] ) {
		return;
	}

	usdtf_it_recover_interrupted_guard();

	$snapshot = array(
		'options' => array(),
		'tables'  => array(),
		'modes'   => array(),
		'metas'   => array(),
		'prices'  => array(),
		'actions' => array(),
	);

	foreach ( array(
		\USDTF\Settings::OPTION,
		\USDTF\Settings::OPTION_RATE,
		\USDTF\Settings::OPTION_PENDING_RATE,
		\USDTF\Settings::OPTION_SYNCED_CURRENCY_MODE,
		\USDTF\Lock::OPTION,
		'usdtf_last_error',
		'usdtf_stale_resumes',
	) as $option ) {
		$snapshot['options'][ $option ] = get_option( $option, null );
	}

	foreach ( array(
		\USDTF\Database::jobs_table(),
		\USDTF\Database::items_table(),
		\USDTF\Database::rates_table(),
		\USDTF\Database::logs_table(),
	) as $table ) {
		$safe = preg_replace( '/[^A-Za-z0-9_]/', '', $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$snapshot['tables'][ $table ] = (array) $wpdb->get_results( 'SELECT * FROM `' . $safe . '`', ARRAY_A );
	}

	// Every piece of plugin data the store already has on its products. The
	// uninstall scenario in this suite deletes these keys site wide, so they are
	// the most valuable thing to put back afterwards.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$meta_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key LIKE %s',
			$wpdb->esc_like( '_usdtf' ) . '%'
		),
		ARRAY_A
	);

	foreach ( (array) $meta_rows as $row ) {
		$snapshot['metas'][] = array(
			'post_id'    => (int) $row['post_id'],
			'meta_key'   => (string) $row['meta_key'],
			'meta_value' => (string) $row['meta_value'],
		);

		if ( \USDTF\Product_Pricing::META_MODE === $row['meta_key'] ) {
			$snapshot['modes'][ (int) $row['post_id'] ] = (string) $row['meta_value'];
		}
	}

	// Products the store already tracks stay in the catalog but out of every
	// catalog wide job this suite starts, so their prices cannot move.
	foreach ( array_keys( $snapshot['modes'] ) as $product_id ) {
		update_post_meta( (int) $product_id, \USDTF\Product_Pricing::META_MODE, \USDTF\Product_Pricing::MODE_EXCLUDED );
	}

	if ( $snapshot['modes'] ) {
		update_option( USDTF_IT_GUARD_OPTION, $snapshot['modes'], false );
	}

	// The price a managed product carries is not plugin meta of its own, yet a
	// catalog wide job can move it, and so can the uninstall scenario by taking
	// the plugin's mode meta away. The prices of every product the plugin
	// already tracks are remembered as well, so the catalog is put back even if
	// a run repriced something.
	foreach ( array_keys( $snapshot['modes'] ) as $product_id ) {
		foreach ( array( '_regular_price', '_sale_price', '_price' ) as $price_key ) {
			$value = get_post_meta( (int) $product_id, $price_key, true );

			if ( '' !== $value ) {
				$snapshot['prices'][ (int) $product_id ][ $price_key ] = (string) $value;
			}
		}
	}

	$snapshot['actions'] = usdtf_it_pending_actions();

	$GLOBALS['usdtf_it_store_snapshot'] = $snapshot;

	register_shutdown_function( 'usdtf_it_restore_store' );

	printf(
		'store guard: %d product(s) on the site, %d tracked by this plugin held out of the run, %d product meta row(s), %d queued worker action(s) and %d plugin row(s) remembered' . PHP_EOL,
		count( usdtf_it_all_product_ids() ),
		count( $snapshot['modes'] ),
		count( $snapshot['metas'] ),
		count( $snapshot['actions'] ),
		array_sum( array_map( 'count', $snapshot['tables'] ) )
	);
}

/**
 * Put the store back exactly as it was found before the run.
 *
 * @return void
 */
function usdtf_it_restore_store() {
	global $wpdb;

	$snapshot = isset( $GLOBALS['usdtf_it_store_snapshot'] ) ? $GLOBALS['usdtf_it_store_snapshot'] : null;

	if ( ! is_array( $snapshot ) ) {
		return;
	}

	$GLOBALS['usdtf_it_store_snapshot'] = null;

	$restored = array(
		'metas'    => 0,
		'prices'   => 0,
		'rows'     => 0,
		'options'  => 0,
		'actions'  => 0,
		'leftover' => 0,
	);

	try {
		// Restoring the meta goes through the plugin's own writers, so the
		// revision guard has to be off: it would bump _usdtf_revision on every
		// source price this puts back and the store would end up with a higher
		// revision than it started with.
		\USDTF\Product_Pricing::without_hooks(
			function () use ( $snapshot, &$restored ) {
				foreach ( $snapshot['metas'] as $row ) {
					update_post_meta( $row['post_id'], $row['meta_key'], $row['meta_value'] );

					++$restored['metas'];
				}

				foreach ( $snapshot['prices'] as $product_id => $prices ) {
					foreach ( $prices as $price_key => $value ) {
						update_post_meta( (int) $product_id, $price_key, (string) $value );

						++$restored['prices'];
					}
				}
			}
		);

		foreach ( $snapshot['tables'] as $table => $rows ) {
			if ( ! \USDTF\Database::table_exists( $table ) ) {
				continue;
			}

			$safe = preg_replace( '/[^A-Za-z0-9_]/', '', $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'DELETE FROM `' . $safe . '`' );

			foreach ( $rows as $row ) {
				$wpdb->insert( $table, $row );

				++$restored['rows'];
			}
		}

		foreach ( $snapshot['options'] as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}

			++$restored['options'];
		}

		// The modes are back where they were, so the note an interrupted run
		// would need is no longer required.
		delete_option( USDTF_IT_GUARD_OPTION );

		// Worker steps the suite queued while it ran are not part of the store's
		// state, so they are dropped before the store's own queue is put back.
		// Leaving them behind would let a cron tick run a step for a job row
		// this restore is about to remove.
		$wanted = array();

		foreach ( $snapshot['actions'] as $action ) {
			$wanted[ usdtf_it_action_key( $action['hook'], $action['args'] ) ] = true;
		}

		foreach ( usdtf_it_pending_actions() as $action ) {
			if ( isset( $wanted[ usdtf_it_action_key( $action['hook'], $action['args'] ) ] ) ) {
				continue;
			}

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $action['hook'], $action['args'], \USDTF\Scheduler::GROUP );
			}

			wp_clear_scheduled_hook( $action['hook'], $action['args'] );

			++$restored['leftover'];
		}

		foreach ( $snapshot['actions'] as $action ) {
			usdtf_plugin()->scheduler()->enqueue( $action['hook'], $action['args'], max( 0, (int) $action['timestamp'] - time() ) );

			++$restored['actions'];
		}

		usdtf_plugin()->settings()->all( true );
		usdtf_plugin()->products()->flush_counts_cache();

		// The restored prices have to leave the product caches too, or the
		// storefront would keep serving what the run wrote.
		foreach ( array_keys( $snapshot['prices'] ) as $product_id ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $product_id );
			}

			clean_post_cache( (int) $product_id );
		}

		// Products the suite created along the way go as well. Its scenarios
		// clean up after themselves, but the last one has nothing following it,
		// so a run would otherwise leave its fixtures in the catalog.
		$fixtures = usdtf_it_fixtures();

		usdtf_it_delete_products();

		$restored['leftover'] += count( $fixtures );
	} catch ( \Throwable $error ) {
		printf( PHP_EOL . 'store guard: restoring the store failed (%s). Check the plugin options, tables and product modes by hand.' . PHP_EOL, $error->getMessage() );

		return;
	}

	printf(
		PHP_EOL . 'store guard: restored %d product meta row(s), %d store price(s), %d plugin row(s), %d option(s) and %d queued worker action(s), and removed %d leftover fixture product(s)/action(s).' . PHP_EOL,
		$restored['metas'],
		$restored['prices'],
		$restored['rows'],
		$restored['options'],
		$restored['actions'],
		$restored['leftover']
	);
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

	usdtf_it_track_product( $product );

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

	usdtf_it_track_product( $product );

	$pricing = usdtf_plugin()->pricing();
	$pricing->set_mode( $product->get_id(), $mode );

	foreach ( $variations as $index => $prices ) {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => (string) ( $index + 1 ) ) );
		$variation->save();

		usdtf_it_track_product( $variation );

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

	// Only the categories this suite created are recorded: a category the store
	// already had is never a candidate for cleanup.
	usdtf_it_track_term( (int) $created['term_id'] );

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
 * Delete the products this suite created.
 *
 * Only ids registered through usdtf_it_track_product() are removed, so the
 * store's own catalog is never a candidate. The registry lives in an option
 * and is only cleared by a run that gets this far, which means a run that was
 * killed halfway can still clean up its leftovers on the next start.
 *
 * @return void
 */
function usdtf_it_delete_products() {
	global $wpdb;

	$ids = usdtf_it_fixtures();

	foreach ( $ids as $product_id ) {
		// Belt and braces: an id that no longer belongs to a product is left
		// alone rather than handed to wp_delete_post().
		if ( ! in_array( get_post_type( $product_id ), array( 'product', 'product_variation' ), true ) ) {
			continue;
		}

		wp_delete_post( $product_id, true );
	}

	// A product that is deleted while a job is repricing it can have plugin
	// meta written back onto its id after the meta rows were removed. Those
	// rows outlive the product and would only be junk in the store's postmeta
	// table, so they are cleared here, for this suite's own ids only.
	foreach ( $ids as $product_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id = %d AND meta_key LIKE %s',
				(int) $product_id,
				$wpdb->esc_like( '_usdtf' ) . '%'
			)
		);
	}

	if ( $ids ) {
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$orphans = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->postmeta . ' WHERE post_id IN ( ' . $placeholders . ' ) AND meta_key LIKE %s',
				array_merge( array_map( 'intval', $ids ), array( $wpdb->esc_like( '_usdtf' ) . '%' ) )
			)
		);

		usdtf_it_assert_same( 0, $orphans, 'deleting this suite\'s products must leave no plugin meta behind' );
	}

	// The registry only exists while it has entries to clean up, so a finished
	// run leaves no extra option behind on the store.
	delete_option( USDTF_IT_FIXTURE_OPTION );

	usdtf_it_delete_fixture_terms();
}

/**
 * Delete the product categories this suite created once nothing uses them.
 *
 * @return void
 */
function usdtf_it_delete_fixture_terms() {
	$terms     = get_option( USDTF_IT_FIXTURE_TERM_OPTION, array() );
	$remaining = array();

	foreach ( is_array( $terms ) ? array_map( 'intval', $terms ) : array() as $term_id ) {
		$term = get_term( $term_id, 'product_cat' );

		if ( ! $term instanceof WP_Term ) {
			continue;
		}

		// A category the store still uses, or one that was already there when
		// the suite started, stays exactly where it is.
		if ( (int) $term->count > 0 ) {
			$remaining[] = $term_id;
			continue;
		}

		wp_delete_term( $term_id, 'product_cat' );
	}

	if ( $remaining ) {
		update_option( USDTF_IT_FIXTURE_TERM_OPTION, $remaining, false );
	} else {
		delete_option( USDTF_IT_FIXTURE_TERM_OPTION );
	}
}
