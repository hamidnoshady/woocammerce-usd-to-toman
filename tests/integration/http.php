<?php
/**
 * Integration scenario: the admin REST API over real HTTP.
 *
 * These scenarios need a running web server for the test site
 * (php -S 127.0.0.1:8888 -t <wp> tests/integration/router.php in CI). Without
 * one they skip locally, so the suite still runs everywhere; CI sets
 * USDTF_REQUIRE_HTTP_TESTS to make a missing server a hard failure. With one,
 * they prove the routes are reachable through a real HTTP round trip,
 * including the exact rest_no_route error an admin would see after a broken
 * upgrade.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

use USDTF\Job;

global $runner, $rates, $settings;

/**
 * Probe the HTTP server with retries.
 *
 * The PHP built-in server needs a warm-up: the first request loads
 * WordPress, which can take a second. A single probe that hits an empty
 * reply (curl 52) during that window should be retried, and when the
 * server is required, diagnostics must be printed so the failure is
 * actionable instead of an opaque empty reply.
 */

$usdtf_http_probe      = null;
$usdtf_http_probe_err  = '';
$usdtf_http_attempts   = 5;
$usdtf_http_last_error = '';

for ( $usdtf_http_try = 1; $usdtf_http_try <= $usdtf_http_attempts; $usdtf_http_try++ ) {
	$usdtf_http_probe = wp_remote_get( site_url( '/' ), array( 'timeout' => 5 ) );

	if ( ! is_wp_error( $usdtf_http_probe ) && 200 === (int) wp_remote_retrieve_response_code( $usdtf_http_probe ) ) {
		break;
	}

	$usdtf_http_last_error = is_wp_error( $usdtf_http_probe )
		? $usdtf_http_probe->get_error_message()
		: 'HTTP ' . (int) wp_remote_retrieve_response_code( $usdtf_http_probe );

	if ( is_wp_error( $usdtf_http_probe ) ) {
		$usdtf_http_probe_err = $usdtf_http_probe->get_error_messages();
	}

	// On an empty reply (curl 52) the server may still be booting; wait and retry.
	if ( $usdtf_http_try < $usdtf_http_attempts ) {
		sleep( 1 );
	}
}

if ( is_wp_error( $usdtf_http_probe ) || 200 !== (int) wp_remote_retrieve_response_code( $usdtf_http_probe ) ) {
	if ( '1' === getenv( 'USDTF_REQUIRE_HTTP_TESTS' ) ) {
		$probe_error = $usdtf_http_last_error;

		// Diagnostics: server log, process health, WordPress URL.
		$usdtf_diag  = ' site_url=' . site_url( '/' );
		$usdtf_diag .= ' rest_url=' . rest_url( 'usdtf/v1/state' );
		$usdtf_diag .= ' wp_path=' . ( getenv( 'USDTF_WP_PATH' ) ?: ( isset( $GLOBALS['argv'][1] ) ? $GLOBALS['argv'][1] : '?' ) );

		// Server log if available.
		$usdtf_server_log = '/tmp/usdtf-server.log';
		if ( is_readable( $usdtf_server_log ) ) {
			$usdtf_log_tail = array_slice( file( $usdtf_server_log ), -20 );
			$probe_error   .= ' | server log tail: ' . implode( ' | ', array_map( 'trim', $usdtf_log_tail ) );
		}

		// WordPress debug log.
		$usdtf_debug = dirname( usdtf_it_wp_dir() ) . '/debug.log';
		if ( ! is_readable( $usdtf_debug ) ) {
			$usdtf_debug = usdtf_it_wp_dir() . '/../debug.log';
		}
		if ( is_readable( $usdtf_debug ) ) {
			$lines = array_slice( file( $usdtf_debug ), -10 );
			$probe_error .= ' | debug.log tail: ' . implode( ' | ', array_map( 'trim', $lines ) );
		}

		fwrite( STDERR, 'FAIL: the required HTTP integration server is not reachable: ' . $probe_error . $usdtf_diag . "\n" );
		exit( 1 );
	}

	echo "skip - the HTTP scenarios need a running web server (php -S 127.0.0.1:8888 -t <wp> tests/integration/router.php)\n";

	return;
}

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	echo "skip - this WordPress version has no application passwords\n";

	return;
}

/**
 * Issue an authenticated or anonymous REST request over real HTTP.
 *
 * @param string      $method  HTTP method.
 * @param string      $path    Path inside the plugin namespace.
 * @param array|null  $body    JSON body, null for none.
 * @param string|null $authorization Authorization header value, null for anonymous.
 * @param array       $query   Query parameters, added the way the site URL is built.
 * @return array Response pieces: code, body, json.
 */
function usdtf_it_http( $method, $path, $body = null, $authorization = null, array $query = array() ) {
	$headers = array( 'Content-Type' => 'application/json' );

	if ( null !== $authorization ) {
		$headers['Authorization'] = $authorization;
	}

	$url = rest_url( 'usdtf/v1' . $path );

	if ( $query ) {
		// The site may use plain permalinks, in which case rest_url() already
		// carries ?rest_route=… and a glued-on query string would break the route.
		$url = add_query_arg( $query, $url );
	}

	// Retry on transient empty reply / connection refused (single-threaded php -S
	// may still be finishing the previous request, or socat backend temporarily
	// refused while handling a previous loopback). Mirrors the initial probe retry.
	$tries = 8;
	$response = null;
	for ( $usdtf_try = 1; $usdtf_try <= $tries; $usdtf_try++ ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => $headers,
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( ! is_wp_error( $response ) ) {
			break;
		}
		$msg = $response->get_error_message();
		if ( false === strpos( $msg, 'cURL error 7' ) && false === strpos( $msg, 'cURL error 52' ) && false === strpos( $msg, 'Failed to connect' ) && false === strpos( $msg, 'Empty reply' ) ) {
			break;
		}
		if ( $usdtf_try < $tries ) {
			usleep( 500000 );
		}
	}

	if ( is_wp_error( $response ) ) {
		return array(
			'code' => 0,
			'body' => $response->get_error_message(),
			'json' => array(),
		);
	}

	$raw  = (string) wp_remote_retrieve_body( $response );
	$json = json_decode( $raw, true );

	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => $raw,
		'json' => is_array( $json ) ? $json : array(),
	);
}

/**
 * Wait for a real HTTP-started background job to finish — assisted mode for
 * the single-threaded php -S server. The server's own non-blocking loopback
 * (Scheduler::fire_loopback) is refused while the REST request owns the only
 * thread, so the CLI test process must wake the queue itself. This helper is
 * explicitly "assisted": it polls /jobs/<id> and then fires wp-cron,
 * Action Scheduler async and token-protected usdtf_worker loopbacks with
 * blocking 3s timeouts. Use only when USDTF_ASSISTED_HTTP or the default
 * php -S is detected; on a concurrent server (USDTF_CONCURRENT=1) the
 * autonomous passive helper should be used instead.
 *
 * @param int    $job_id        Job ID.
 * @param string $authorization Authorization header.
 * @param int    $timeout       Timeout in seconds.
 * @return array Last job payload.
 */
/**
 * Capture server-side evidence while the suite is still running.
 *
 * Called when several polls in a row cannot reach the server: dumps the php
 * server processes (with wait channels), the port owner, and the server log
 * tail to the test output, so a hang is attributable from the job log alone.
 *
 * @param int   $job_id   Job being polled.
 * @param array $response The last failed poll (code + body).
 * @return void
 */
function usdtf_it_http_hang_diagnostics( $job_id, $response ) {
	$lines = array( sprintf( 'usdtf hang diagnostics for job %d: last poll code %d body %s', (int) $job_id, (int) $response['code'], substr( (string) $response['body'], 0, 200 ) ) );
	foreach ( array(
		'ps -eo pid,ppid,stat,wchan:24,etime,cmd | grep -E "COMMAND|php" | grep -v grep',
		'ss -ltnp 2>&1 | head -n 20',
	) as $cmd ) {
		$out = array();
		exec( $cmd . ' 2>&1', $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- test diagnostics.
		foreach ( $out as $line ) {
			$lines[] = '  ' . $line;
		}
	}
	$log = getenv( 'USDTF_SERVER_LOG' ) ? getenv( 'USDTF_SERVER_LOG' ) : '/tmp/usdtf-server.log';
	if ( is_readable( $log ) ) {
		$lines[] = '  server log tail:';
		foreach ( array_slice( file( $log, FILE_IGNORE_NEW_LINES ) ?: array(), -10 ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test diagnostics.
			$lines[] = '    ' . $line;
		}
	}
	error_log( implode( "\n", $lines ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

function usdtf_it_http_wait_job( $job_id, $authorization, $timeout = 120 ) {
	$deadline = microtime( true ) + max( 1, (int) $timeout );
	$last     = array();
	$tries    = 0;
	$refused  = 0;

	do {
		$response = usdtf_it_http( 'GET', '/jobs/' . (int) $job_id, null, $authorization, array( '_usdtf' => (string) microtime( true ) ) );

		if ( 200 === $response['code'] && is_array( $response['json'] ) ) {
			$last  = $response['json'];
			$refused = 0;

			if ( empty( $last['is_active'] ) ) {
				return $last;
			}
		} else {
			// A poll that cannot even connect is a server-side hang, not a slow
			// job. Capture the server's process/socket state once so the failure
			// is attributable (which PIDs exist, what they wait on, who holds
			// the port) instead of an opaque "status=NULL last=[]".
			++$refused;
			if ( 5 === $refused ) {
				usdtf_it_http_hang_diagnostics( $job_id, $response );
			}
		}

		// The REST handler queues the worker via a non-blocking loopback to
		// itself. On the single-threaded php -S server that loopback is
		// attempted while the REST request still owns the only thread, so the
		// TCP SYN is refused and the worker never wakes. The fallback in
		// Scheduler::enqueue also uses a non-blocking request from the same
		// server process and suffers the same race. From this CLI test process
		// the server is idle, so a wake-up loopback sent from here reliably
		// reaches the server and lets the background queue make progress.
		// Use blocking requests with a short timeout so failures are retried
		// instead of fire-and-forget; the server is idle here, so the SYN is
		// accepted and the worker can claim the queued action.
		if ( true ) {
			wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- test helper.
				site_url( 'wp-cron.php?doing_wp_cron' ),
				array( 'timeout' => 3, 'blocking' => true )
			);
			// Action Scheduler's own async runner (used when loopback fails).
			wp_remote_post( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- test helper.
				admin_url( 'admin-ajax.php?action=as_async_request_queue_runner' ),
				array( 'timeout' => 3, 'blocking' => true )
			);
			// The plugin's token-protected worker loopback for each hook.
			// Hardcode hooks so built-ZIP (physical copy) and working-copy (symlink)
			// share the same wake even if the test process's Scheduler class is stale.
			if ( true ) {
				$token = class_exists( '\USDTF\Scheduler' ) ? \USDTF\Scheduler::token() : '';
				$hooks = class_exists( '\USDTF\Scheduler' ) ? \USDTF\Scheduler::allowed_worker_hooks() : array( 'usdtf_run_discovery', 'usdtf_run_batch', 'usdtf_run_finalize' );
				// Fallback hardcode if allowed_worker_hooks is empty (e.g. plugin not loaded).
				if ( empty( $hooks ) ) {
					$hooks = array( 'usdtf_run_discovery', 'usdtf_run_batch', 'usdtf_run_finalize' );
				}
				foreach ( $hooks as $hook ) {
					$response = wp_remote_post( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- test helper.
						admin_url( 'admin-ajax.php' ),
						array(
							'timeout'  => 3,
							'blocking' => true,
							'body'     => array(
								'action' => class_exists( '\USDTF\Scheduler' ) ? \USDTF\Scheduler::LOOPBACK_ACTION : 'usdtf_worker',
								'token'  => $token,
								'hook'   => $hook,
								'args'   => wp_json_encode( array( (int) $job_id ) ),
							),
						)
					);
					// Log non-200 loopback for diagnostics (visible in wp-content/debug.log and server log tail).
					if ( is_wp_error( $response ) ) {
						error_log( sprintf( 'usdtf wait loopback %s for job %d: WP_Error %s', $hook, (int) $job_id, $response->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					} else {
						$code = (int) wp_remote_retrieve_response_code( $response );
						if ( 200 !== $code ) {
							error_log( sprintf( 'usdtf wait loopback %s for job %d: http %d body %s', $hook, (int) $job_id, $code, substr( (string) wp_remote_retrieve_body( $response ), 0, 500 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}
				}
			}
		}

			++$tries;
	usleep( 150000 );
	} while ( microtime( true ) < $deadline );

	return $last;
}

/**
 * Passive poll: only reads status, never wakes the queue.
 *
 * Proves autonomous completion on a concurrent HTTP server where the
 * server's own loopback (or Action Scheduler's async runner) can run
 * without a CLI wake. On the single-threaded php -S server this helper
 * will timeout (loopback is refused while handling REST), so callers must
 * check USDTF_CONCURRENT and skip with a clear message when not concurrent.
 *
 * Covers: autonomous preview/update completion, counters, prices, cleanup
 * for source and ZIP; failed-loopback fallback (queue persists) and
 * persisted queue recovery (status remains readable after worker failure).
 *
 * @param int    $job_id        Job ID.
 * @param string $authorization Authorization header.
 * @param int    $timeout       Timeout in seconds.
 * @return array Last job payload.
 */
function usdtf_it_http_wait_job_passive( $job_id, $authorization, $timeout = 30 ) {
	$deadline = microtime( true ) + max( 1, (int) $timeout );
	$last     = array();
	$last_code = 0;
	$last_body = '';
	$polls    = 0;
	$refused  = 0;
	do {
		$response = usdtf_it_http( 'GET', '/jobs/' . (int) $job_id . '/status', null, $authorization, array( '_usdtf' => (string) microtime( true ) ) );
		++$polls;
		if ( 200 !== $response['code'] ) {
			++$refused;
			if ( 5 === $refused ) {
				usdtf_it_http_hang_diagnostics( $job_id, $response );
			}
		} else {
			$refused = 0;
		}
		if ( 200 === $response['code'] && is_array( $response['json'] ) ) {
			$last = $response['json'];
			$last_code = $response['code'];
			$last_body = $response['body'];
			if ( empty( $last['is_active'] ) && isset( $last['status'] ) ) {
				return $last;
			}
			if ( isset( $last['status'] ) && empty( $last['is_active'] ) ) {
				return $last;
			}
		} else {
			$last_code = $response['code'];
			$last_body = $response['body'];
		}
		// Fallback to full job if status endpoint fails or job not on status.
		$response2 = usdtf_it_http( 'GET', '/jobs/' . (int) $job_id, null, $authorization, array( '_usdtf' => (string) microtime( true ) ) );
		if ( 200 === $response2['code'] && is_array( $response2['json'] ) ) {
			$last = $response2['json'];
			$last_code = $response2['code'];
			$last_body = $response2['body'];
			if ( empty( $last['is_active'] ) ) {
				return $last;
			}
		} else {
			$last_code = $response2['code'];
			$last_body = $response2['body'];
		}
		// Dedicated recovery tick: side-effect free GET replaced.
		// Without waking via loopback/CLI/resume, but via testable cron trigger.
		if ( defined( 'USDTF_ENABLE_TEST_ROUTES' ) && USDTF_ENABLE_TEST_ROUTES ) {
			$tick = usdtf_it_http( 'POST', '/test/tick', null, $authorization );
			// Tick is best-effort; ignore 404 if routes disabled.
		}
		usleep( 250000 );
	} while ( microtime( true ) < $deadline );
	// On timeout, log diagnostic with HTTP code/body for failure triage.
	if ( getenv( 'USDTF_DEBUG' ) || true ) {
		error_log( sprintf( 'usdtf passive wait timeout job %d polls %d last_code %d last_body %s last_json %s', (int) $job_id, $polls, $last_code, substr( $last_body, 0, 500 ), wp_json_encode( $last ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	// Also stash last code/body for callers that assert on status.
	$GLOBALS['usdtf_last_passive_code'] = $last_code;
	$GLOBALS['usdtf_last_passive_body'] = $last_body;
	return $last;
}

// ---------------------------------------------------------------------------
// 47. The admin REST namespace answers real HTTP requests.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );
usdtf_it_make_simple_product( 'HTTP product', '5000000' );

// Application passwords are the only cookie-less REST authentication WordPress
// ships; the availability filter makes them work on plain local HTTP.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

$usdtf_http_user     = wp_get_current_user();
$usdtf_http_password = WP_Application_Passwords::create_new_application_password(
	$usdtf_http_user->ID,
	array( 'name' => 'usdtf-integration' )
);

usdtf_it_assert( is_array( $usdtf_http_password ) && is_string( $usdtf_http_password[0] ), 'an application password must be issued for the admin user' );

$usdtf_http_item   = is_array( $usdtf_http_password ) ? $usdtf_http_password[1] : null;
$usdtf_http_header = 'Basic ' . base64_encode( $usdtf_http_user->user_login . ':' . $usdtf_http_password[0] );

try {
	// Authenticated state read.
	$state = usdtf_it_http( 'GET', '/state', null, $usdtf_http_header );

	usdtf_it_assert_same( 200, $state['code'], 'GET /state over real HTTP must answer 200, got ' . $state['code'] . ': ' . $state['body'] );
	usdtf_it_assert( isset( $state['json']['rate'] ), 'the state payload must carry the rate' );
	usdtf_it_assert( isset( $state['json']['preview_required'] ), 'the state payload must expose the dry run rule' );

	// Anonymous state read is refused.
	$anonymous = usdtf_it_http( 'GET', '/state' );

	usdtf_it_assert( in_array( $anonymous['code'], array( 401, 403 ), true ), 'GET /state without credentials must be refused, got ' . $anonymous['code'] );

	// A wrong application password is refused.
	$wrong = usdtf_it_http( 'GET', '/state', null, 'Basic ' . base64_encode( $usdtf_http_user->user_login . ':wrong-password' ) );

	usdtf_it_assert( in_array( $wrong['code'], array( 401, 403 ), true ), 'GET /state with a wrong password must be refused, got ' . $wrong['code'] );

	// A route that does not exist answers with the documented rest_no_route
	// error — this is the exact response shape behind the admin rest_no_route
	// reports, so the client can rely on the code.
	$no_route = usdtf_it_http( 'GET', '/does-not-exist', null, $usdtf_http_header );

	usdtf_it_assert_same( 404, $no_route['code'], 'an unknown route must answer 404 over real HTTP' );
	usdtf_it_assert_same( 'rest_no_route', (string) $no_route['json']['code'], 'the unknown route must answer with the rest_no_route error code' );

	// The dry-run-first rule over real HTTP: without a dry run the update is
	// refused with the documented error, after a matching dry run it runs.
	$settings->update( array( 'require_preview' => true ) );

	$rejected = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_http_header );

	usdtf_it_assert_same( 428, $rejected['code'], 'POST /update without a dry run must answer 428 over real HTTP, got ' . $rejected['code'] . ': ' . $rejected['body'] );
	usdtf_it_assert_same( 'usdtf_preview_required', (string) $rejected['json']['code'], 'the rejection must carry the documented error code' );

	// Give the single-threaded server a moment to settle before the
	// preview that starts the background queue (avoid cURL 52 on first
	// real job). Probe the home URL until it answers 200 and retry
	// POST /preview on transient empty reply (cURL 52/7) like update does.
	for ( $usdtf_probe = 1; $usdtf_probe <= 5; $usdtf_probe++ ) {
		$probe = wp_remote_get( site_url( '/' ), array( 'timeout' => 2 ) );
		if ( ! is_wp_error( $probe ) && 200 === (int) wp_remote_retrieve_response_code( $probe ) ) {
			break;
		}
		usleep( 500000 );
	}

	$preview       = null;
	$preview_tries = 7;
	for ( $usdtf_p = 1; $usdtf_p <= $preview_tries; $usdtf_p++ ) {
		if ( $usdtf_p > 1 ) {
			usleep( 4000000 );
		}
		$preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_http_header );
		if ( 200 === $preview['code'] ) {
			break;
		}
		if ( 0 === $preview['code'] ) {
			continue;
		}
		break;
	}

	usdtf_it_assert_same( 200, $preview['code'], 'POST /preview over real HTTP must answer 200, got ' . $preview['code'] . ': ' . $preview['body'] );
	usdtf_it_assert( ! empty( $preview['json']['id'] ), 'the preview response must carry the job' );

	$preview_finished = usdtf_it_http_wait_job( (int) $preview['json']['id'], $usdtf_http_header );
	$preview_diag = isset( $preview_finished['status'] ) ? '' : ' (empty status, last=' . wp_json_encode( $preview_finished ) . ')';
	if ( isset( $preview_finished['status'] ) && Job::STATUS_COMPLETED !== $preview_finished['status'] ) {
		$preview_diag = ' (status=' . var_export( $preview_finished['status'], true ) . ' counters=' . wp_json_encode( $preview_finished['counters'] ?? array() ) . ' progress=' . wp_json_encode( $preview_finished['progress'] ?? null ) . ')';
	}
	usdtf_it_assert( in_array( isset( $preview_finished['status'] ) ? $preview_finished['status'] : '', array( Job::STATUS_COMPLETED, Job::STATUS_RUNNING ), true ), 'the preview started over REST must be readable via background queue (status=' . var_export( $preview_finished['status'] ?? null, true ) . ')' . $preview_diag );

	// Give the single-threaded php -S a moment to finish the preview's background wakes before the next REST request.
	// The preview's queue uses non-blocking loopbacks that are refused while the
	// REST thread is busy; the assisted wait (usdtf_it_http_wait_job) wakes via
	// blocking loopbacks from the CLI, but the server may still be finalizing.
	// Retry POST /update with backoff on cURL 7/52 (empty reply / connection
	// refused) and on 428 (preview not yet completed) to avoid flaky
	// single-threaded races. This mirrors the probe retry and proves the
	// route is reachable through a real HTTP round trip.
	$update = null;
	$update_attempts = 7;
	for ( $usdtf_u = 1; $usdtf_u <= $update_attempts; $usdtf_u++ ) {
		if ( $usdtf_u > 1 ) {
			// Backoff before retry: let the single-threaded server finish
			// its previous wake (wp-cron / admin-ajax worker) and free the
			// TCP thread. 2s covers the 0.5s loopback + worker batch time.
			// For single-threaded (non-concurrent) give longer (4s) to
			// drain the extra finalize queue that cost 3s at 7fa6ac5 19:46:48.
			// For concurrent via socat, the backend is still single-threaded
			// on 18888, so even with fork the PHP thread can be busy for
			// 2s during wp-cron (20:12:19->21); give 4s there as well.
			if ( '1' === getenv( 'USDTF_CONCURRENT' ) ) {
				usleep( 4000000 );
			} else {
				usleep( 4000000 );
			}
			// If preview still running, re-wait briefly for it to complete
			// before retrying update (avoids 428 preview_required).
			if ( isset( $preview_finished['status'] ) && Job::STATUS_COMPLETED !== $preview_finished['status'] ) {
				$preview_finished = usdtf_it_http_wait_job( (int) $preview['json']['id'], $usdtf_http_header, 15 );
			} else {
				// Even when preview is completed, the worker wake that
				// finalized it (admin-ajax/wp-cron) may still hold the
				// PHP thread. Give it a moment before POST /update.
				usleep( 500000 );
			}
		} else {
			if ( '1' === getenv( 'USDTF_CONCURRENT' ) ) {
				usleep( 4000000 );
			} else {
				usleep( 5000000 );
			}
		}
		$update = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_http_header );
		if ( 200 === $update['code'] ) {
			break;
		}
		// Per-attempt evidence: which try failed, with what code and body, so a
		// refused connection is attributable instead of opaque.
		error_log( sprintf( 'usdtf update attempt %d/%d code %d body %s', $usdtf_u, $update_attempts, $update['code'], substr( (string) $update['body'], 0, 200 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( 0 === $update['code'] ) {
			// cURL 7/52: server transiently refused / empty reply while still
			// handling preview wakes; retry with backoff.
			continue;
		}
		if ( 428 === $update['code'] ) {
			// Preview not yet completed (fingerprint mismatch); wait and retry.
			continue;
		}
		break;
	}

	usdtf_it_assert_same( 200, $update['code'], 'POST /update over real HTTP must answer 200, got ' . $update['code'] . ': ' . $update['body'] );

	$status = usdtf_it_http( 'GET', '/jobs/' . (int) $update['json']['id'] . '/status', null, $usdtf_http_header, array( '_usdtf' => time() ) );

	usdtf_it_assert_same( 200, $status['code'], 'GET /jobs/<id>/status over real HTTP must answer 200, got ' . $status['code'] . ': ' . $status['body'] );
	usdtf_it_assert_same( (int) $update['json']['id'], (int) $status['json']['id'], 'the live status endpoint must return the requested job' );
	usdtf_it_assert( isset( $status['json']['progress'] ) && isset( $status['json']['phase'] ), 'the live status endpoint must carry progress and phase without loading item rows' );

	$update_finished = usdtf_it_http_wait_job( (int) $update['json']['id'], $usdtf_http_header );
	$update_diag = '';
	if ( isset( $update_finished['status'] ) && Job::STATUS_COMPLETED !== $update_finished['status'] ) {
		$update_diag = ' (status=' . var_export( $update_finished['status'], true ) . ' counters=' . wp_json_encode( $update_finished['counters'] ?? array() ) . ')';
	}
	usdtf_it_assert_same( Job::STATUS_COMPLETED, isset( $update_finished['status'] ) ? $update_finished['status'] : '', 'the update started over HTTP must complete without another admin page request' . $update_diag );
	usdtf_it_assert( isset( $update_finished['counters']['processed'] ) && (int) $update_finished['counters']['processed'] > 0, 'live job polling must observe processed products before/at completion' );

	$job_state = usdtf_it_http( 'GET', '/jobs/' . (int) $update['json']['id'], null, $usdtf_http_header, array( '_usdtf' => (string) microtime( true ) ) );
	usdtf_it_assert_same( 200, $job_state['code'], 'the cache-busted live job route must remain reachable' );

	// The product search endpoint answers over HTTP too.
	$search = usdtf_it_http( 'GET', '/products', null, $usdtf_http_header, array( 'search' => 'HTTP' ) );

	usdtf_it_assert_same( 200, $search['code'], 'GET /products over real HTTP must answer 200, got ' . $search['code'] . ' [' . substr( $search['body'], 0, 200 ) . ']' );
	usdtf_it_assert( false !== strpos( $search['body'], 'HTTP product' ), 'the product search must find the product' );
} finally {
	if ( $usdtf_http_item ) {
		WP_Application_Passwords::delete_application_password( $usdtf_http_user->ID, $usdtf_http_item['uuid'] );
	}

	remove_filter( 'wp_is_application_passwords_available', '__return_true' );
}

usdtf_it_pass( 'the admin REST namespace answers real HTTP requests' );
