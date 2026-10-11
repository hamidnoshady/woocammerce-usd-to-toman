<?php
/**
 * Background scheduling abstraction.
 *
 * Prefers WooCommerce Action Scheduler, falls back to WP-Cron and finally to a
 * non blocking loopback request so a synchronization never depends on the
 * admin browser staying open.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Queues the plugin's worker actions.
 */
final class Scheduler {

	/**
	 * Action Scheduler group used by the plugin.
	 */
	const GROUP = 'usdtf';

	/**
	 * Admin-ajax action used by the loopback fallback.
	 */
	const LOOPBACK_ACTION = 'usdtf_worker';

	/**
	 * Option holding the loopback token.
	 */
	const TOKEN_OPTION = 'usdtf_loopback_token';

	/**
	 * Backend: WooCommerce Action Scheduler.
	 */
	const BACKEND_ACTION_SCHEDULER = 'action_scheduler';

	/**
	 * Backend: WP-Cron event + one extra loopback poke.
	 */
	const BACKEND_WP_CRON = 'wp_cron';

	/**
	 * Backend: loopback HTTP request only.
	 */
	const BACKEND_LOOPBACK = 'loopback';

	/**
	 * Backend: nothing is available.
	 */
	const BACKEND_NONE = 'none';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether Action Scheduler can be used.
	 *
	 * @return bool
	 */
	public static function is_action_scheduler_available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Which scheduling backend will be used.
	 *
	 * @return string One of the BACKEND_* constants.
	 */
	public function backend() {
		if ( self::is_action_scheduler_available() ) {
			return self::BACKEND_ACTION_SCHEDULER;
		}

		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			return self::BACKEND_WP_CRON;
		}

		if ( $this->settings->get( 'loopback_fallback' ) ) {
			return self::BACKEND_LOOPBACK;
		}

		return self::BACKEND_NONE;
	}

	/**
	 * Queue a worker action.
	 *
	 * @param string $hook  Action hook.
	 * @param array  $args  Action arguments.
	 * @param int    $delay Seconds to wait before running.
	 * @param bool   $unique Avoid duplicate pending actions for the same hook+args.
	 * @return bool True when the action was queued or already pending.
	 */
	public function enqueue( $hook, array $args = array(), $delay = 0, $unique = true ) {
		$delay = max( 0, (int) $delay );

		/**
		 * Whether queueing is blocked for this action.
		 *
		 * Returning true forces a queueing failure, which the runner reacts to
		 * by pausing the job with a clear message. It exists for tests and for
		 * developers who need to stop the queue on purpose.
		 *
		 * @param bool   $blocked Whether the action must be refused.
		 * @param string $hook    Action hook.
		 * @param array  $args    Action arguments.
		 */
		if ( apply_filters( 'usdtf_scheduler_enqueue_blocked', false, $hook, $args ) ) {
			return false;
		}

		$backend = $this->backend();

		if ( $unique && $this->has_pending( $hook, $args ) ) {
			// A due-now action may be waiting only because the original async
			// dispatch/loopback was dropped by the host. Re-poke the exact queued
			// action instead of waiting for another page load. handle_loopback()
			// claims the Action Scheduler/WP-Cron action before executing it, so
			// multiple pokes cannot run the same queued step twice.
			if ( 0 === $delay && self::BACKEND_NONE !== $backend && $this->settings->get( 'loopback_fallback' ) ) {
				$dispatched = $this->fire_loopback( $hook, $args );
				if ( ! $dispatched && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					$this->dispatch_action_scheduler();
				}
			}

			return true;
		}

		if ( self::BACKEND_ACTION_SCHEDULER === $backend ) {
			if ( function_exists( 'as_has_scheduled_action' ) && $unique && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return true;
			}

			if ( $delay > 0 ) {
				$action_id = as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP, $unique );
			} else {
				$action_id = as_enqueue_async_action( $hook, $args, self::GROUP, $unique );
			}

			// Action Scheduler returns 0 when it could not create the action.
			// A concurrent unique enqueue may have won the race, so accept an
			// already-pending twin before declaring queue failure.
			$queued = (int) $action_id > 0 || $this->has_pending( $hook, $args );

			// Some hosts do not dispatch Action Scheduler's async runner until the
			// next normal WordPress request. That made the live panel sit at 0/0
			// until the administrator refreshed the page. For work that is due now,
			// use the plugin's token-protected loopback as an immediate wake-up.
			// handle_loopback() claims (unschedules) the Action Scheduler action
			// before running it, so the native queue remains a fallback rather than
			// a duplicate execution path. When the built-in server is busy the
			// non-blocking loopback may be refused; a failed loopback falls
			// back to Action Scheduler's own async dispatcher, which will run
			// after the REST response is finished.
			if ( $queued && 0 === $delay ) {
				if ( $this->settings->get( 'loopback_fallback' ) ) {
					$dispatched = $this->fire_loopback( $hook, $args );
					if ( ! $dispatched && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
						$this->dispatch_action_scheduler();
					}
				} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					// Without the loopback, still wake Action Scheduler's own async
					// runner: REST requests do not reliably reach its wp-admin
					// shutdown dispatcher.
					$this->dispatch_action_scheduler();
				}
			}

			return $queued;
		}

		if ( self::BACKEND_WP_CRON === $backend || self::BACKEND_LOOPBACK === $backend ) {
			if ( ! wp_next_scheduled( $hook, $args ) ) {
				$scheduled = wp_schedule_single_event( time() + $delay, $hook, $args, true );

				if ( is_wp_error( $scheduled ) || false === $scheduled ) {
					return false;
				}
			}

			// WP-Cron only runs on requests. For work that is due now, also kick a
			// non-blocking token-protected loopback. Delayed work must never be
			// executed early: the previous implementation slept for at most ten
			// seconds and could violate a 30–300 second retry backoff.
			if ( 0 === $delay && $this->settings->get( 'loopback_fallback' ) ) {
				$dispatched = $this->fire_loopback( $hook, $args );
				if ( ! $dispatched && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					$this->dispatch_action_scheduler();
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Whether an action for the same hook and arguments is pending.
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Action arguments.
	 * @return bool
	 */
	public function has_pending( $hook, array $args = array() ) {
		if ( self::is_action_scheduler_available() && function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
		}

		return (bool) wp_next_scheduled( $hook, $args );
	}

	/**
	 * Remove pending actions for a hook.
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Optional exact arguments.
	 * @return void
	 */
	public static function unschedule_all( $hook, array $args = array() ) {
		if ( self::is_action_scheduler_available() && function_exists( 'as_unschedule_all_actions' ) ) {
			if ( $args ) {
				as_unschedule_all_actions( $hook, $args, self::GROUP );
			} else {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}

		wp_clear_scheduled_hook( $hook, $args );
	}

	/**
	 * Queue health information for the diagnostics screen.
	 *
	 * @return array
	 */
	public function health() {
		$health = array(
			'backend'          => $this->backend(),
			'action_scheduler' => self::is_action_scheduler_available(),
			'pending'          => $this->pending_count(),
			'failed'           => $this->failed_count(),
			'oldest_pending'   => 0,
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cron_late'        => false,
			'next_tick'        => wp_next_scheduled( Cron::EVENT_TICK ),
		);

		if ( self::is_action_scheduler_available() && function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 1,
					'orderby'  => 'scheduled_date',
					'order'    => 'ASC',
				),
				'ids'
			);

			if ( $pending ) {
				$action = \ActionScheduler::store()->fetch_action( reset( $pending ) );

				if ( $action ) {
					$schedule = $action->get_schedule();
					$date     = $schedule ? $schedule->get_date() : null;

					if ( $date instanceof \DateTimeInterface ) {
						$health['oldest_pending'] = max( 0, time() - $date->getTimestamp() );
					}
				}
			}
		}

		if ( ! empty( $health['next_tick'] ) ) {
			$health['cron_late'] = (int) $health['next_tick'] < ( time() - 600 );
		}

		return $health;
	}

	/**
	 * Count pending plugin actions.
	 *
	 * @return int
	 */
	public function pending_count() {
		$count = self::action_scheduler_count( 'pending' );

		if ( null !== $count ) {
			return $count;
		}

		if ( self::is_action_scheduler_available() && function_exists( 'as_get_scheduled_actions' ) ) {
			$ids = as_get_scheduled_actions(
				array(
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 500,
					'orderby'  => 'scheduled_date',
					'order'    => 'ASC',
				),
				'ids'
			);

			return is_array( $ids ) ? count( $ids ) : 0;
		}

		$crons = _get_cron_array();
		$count = 0;

		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $entries ) {
					if ( 0 === strpos( $hook, 'usdtf_' ) ) {
						$count += count( $entries );
					}
				}
			}
		}

		return $count;
	}

	/**
	 * Count failed plugin actions.
	 *
	 * @return int
	 */
	public function failed_count() {
		$count = self::action_scheduler_count( 'failed' );

		if ( null !== $count ) {
			return $count;
		}

		if ( ! self::is_action_scheduler_available() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$ids = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'failed',
				'per_page' => 500,
				'orderby'  => 'scheduled_date',
				'order'    => 'ASC',
			),
			'ids'
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Exact number of plugin actions in a status, or null when the store cannot
	 * count. Listing actions is capped by per_page, so a busy store would be
	 * under-reported.
	 *
	 * @param string $status Action Scheduler status.
	 * @return int|null
	 */
	private static function action_scheduler_count( $status ) {
		if ( ! self::is_action_scheduler_available() || ! class_exists( '\ActionScheduler' ) ) {
			return null;
		}

		$store = \ActionScheduler::store();

		if ( ! $store || ! method_exists( $store, 'query_actions' ) ) {
			return null;
		}

		$count = $store->query_actions(
			array(
				'group'  => self::GROUP,
				'status' => $status,
			),
			'count'
		);

		return is_numeric( $count ) ? (int) $count : null;
	}

	/**
	 * Loopback token, generated on activation.
	 *
	 * @return string
	 */
	public static function token() {
		$token = (string) get_option( self::TOKEN_OPTION, '' );

		if ( '' === $token ) {
			$token = self::ensure_token();
		}

		return $token;
	}

	/**
	 * Make sure a loopback token exists.
	 *
	 * @return string
	 */
	public static function ensure_token() {
		$token = (string) get_option( self::TOKEN_OPTION, '' );

		if ( '' === $token ) {
			$token = wp_generate_password( 43, false, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}

		return $token;
	}

	/**
	 * Validate a loopback token.
	 *
	 * @param string $token Token from the request.
	 * @return bool
	 */
	public static function verify_token( $token ) {
		$expected = (string) get_option( self::TOKEN_OPTION, '' );

		if ( '' === $expected || ! is_string( $token ) || '' === $token ) {
			return false;
		}

		return hash_equals( $expected, $token );
	}

	/**
	 * Wake Action Scheduler after an immediate action is queued from REST.
	 *
	 * Action Scheduler normally attaches its async dispatcher to wp-admin
	 * shutdown. The plugin creates jobs through REST, so invoke the same async
	 * runner explicitly. The action remains persisted in Action Scheduler if
	 * dispatching is unavailable, and WP-Cron can still pick it up later.
	 *
	 * @return void
	 */
	private function dispatch_action_scheduler() {
		if ( ! class_exists( '\\ActionScheduler' ) || ! class_exists( '\\ActionScheduler_AsyncRequest_QueueRunner' ) ) {
			return;
		}

		try {
			$store = \ActionScheduler::store();

			if ( ! $store ) {
				return;
			}

			$runner = new \ActionScheduler_AsyncRequest_QueueRunner( $store );
			$runner->maybe_dispatch();
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Fire a request that runs a worker action, waiting for the step.
	 *
	 * The call is blocking with a short timeout so the worker's response is
	 * actually consumed; see the comment at the request below for why a fire
	 * and forget loopback is not used. The request is verified with normal
	 * WordPress TLS rules: disabling certificate verification would let a
	 * broken loopback silently run over an intercepted connection. Hosts
	 * whose loopback fails keep the WP-Cron twin of the action as their
	 * safety net.
	 *
	 * @param string $hook  Worker hook.
	 * @param array  $args  Arguments.
	 * @return bool Whether WordPress accepted the non-blocking HTTP request.
	 */
	private function fire_loopback( $hook, array $args ) {
		if ( ! in_array( $hook, self::allowed_worker_hooks(), true ) ) {
			return false;
		}

		// Test hook: deterministic failure injection. Only when
		// USDTF_ENABLE_TEST_ROUTES is true, when the option
		// usdtf_test_fail_next_loopback is "1" or equals the hook name,
		// fail this dispatch once (queue remains persisted, fallback must run).
		// Set via POST /usdtf/v1/test/fail-next-loopback. Ignored in production.
		if ( defined( 'USDTF_ENABLE_TEST_ROUTES' ) && USDTF_ENABLE_TEST_ROUTES ) {
			$fail = get_option( 'usdtf_test_fail_next_loopback', '' );
			if ( '' !== $fail ) {
				if ( '1' === (string) $fail || (string) $fail === (string) $hook ) {
					// Consume once.
					delete_option( 'usdtf_test_fail_next_loopback' );
					error_log( sprintf( 'usdtf test: failing loopback for %s (injected)', $hook ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					return false;
				}
			}
		}

		// Blocking request with a real timeout: a non blocking loopback closes
		// its socket almost immediately, so the worker writes its response into
		// a connection that is already gone. On the PHP built-in server that
		// leaves the worker in a state where its NEXT accepted connection never
		// completes, which stalled whole suites. Waiting for the step (bounded
		// by the timeout) keeps the handshake clean; a timeout falls back to
		// Action Scheduler exactly like a refused loopback did.
		$result = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'  => 3,
				'blocking' => true,
				'body'     => array(
					'action' => self::LOOPBACK_ACTION,
					'token'  => self::token(),
					'hook'   => $hook,
					'args'   => wp_json_encode( $args ),
				),
			)
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Worker hooks the loopback endpoint is allowed to trigger.
	 *
	 * @return string[]
	 */
	public static function allowed_worker_hooks() {
		return array(
			Sync_Runner::HOOK_DISCOVER,
			Sync_Runner::HOOK_PROCESS,
			Sync_Runner::HOOK_FINALIZE,
		);
	}

	/**
	 * Handle the loopback worker request.
	 *
	 * Claim has a timestamp lease and heartbeat. If the lease expires,
	 * the step is requeued exactly once by the recovery tick. The worker
	 * request uses ignore_user_abort and time limit so the client
	 * disconnect (0.5s timeout) does not kill it after claim.
	 *
	 * @return void
	 */
	public function handle_loopback() {
		// The caller is the plugin itself, not a browser form, so the request is
		// authenticated with the secret loopback token instead of a nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Secret token verified below.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! self::verify_token( $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Secret token verified above.
		$hook = isset( $_POST['hook'] ) ? sanitize_key( wp_unslash( $_POST['hook'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Secret token verified above, JSON decoded and cast below.
		$raw = isset( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : '';

		if ( ! in_array( $hook, self::allowed_worker_hooks(), true ) ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		$args = json_decode( (string) $raw, true );
		$args = is_array( $args ) ? $args : array();

		$job_id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $job_id <= 0 ) {
			wp_die( '', '', array( 'response' => 400 ) );
		}

		// Ensure the client disconnect does not abort this worker after claim.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions
		}
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- intentional CLI timeout disable for worker lease.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Claim with lease: store timestamp before unscheduling so expiry can be detected.
		$lease_key = 'usdtf_worker_lease_' . $job_id . '_' . $hook;
		$now       = time();
		update_option(
			$lease_key,
			array(
				'time' => $now,
				'pid'  => getmypid(),
			),
			false
		);
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged

		// This request owns the work now: drop the WP-Cron twin of the action
		// so the same step cannot be triggered twice (once here, once by cron).
		// When the loopback never arrives, the cron event survives as the
		// safety net, because only a request that got this far removes it.
		$claimed = false;

		// Action Scheduler is the preferred backend. Claiming means removing the
		// exact queued action before executing it ourselves; if its native runner
		// already claimed it, there is nothing left for this loopback to do.
		if ( self::is_action_scheduler_available() && function_exists( 'as_unschedule_action' ) ) {
			$action_id = as_unschedule_action( $hook, $args, self::GROUP );
			$claimed   = is_numeric( $action_id ) && (int) $action_id > 0;
		}

		// WP-Cron is the fallback backend. Its scheduled event is likewise used
		// as the claim token, so two concurrent loopbacks cannot both execute the
		// worker step.
		if ( ! $claimed ) {
			$timestamp = wp_next_scheduled( $hook, $args );

			if ( false !== $timestamp ) {
				$result  = wp_unschedule_event( $timestamp, $hook, $args, true );
				$claimed = ! is_wp_error( $result ) && false !== $result;
			}
		}

		if ( ! $claimed ) {
			delete_option( $lease_key );
			wp_die( 'already claimed', '', array( 'response' => 200 ) );
		}

		// Heartbeat via job repository as well.
		usdtf_plugin()->jobs()->heartbeat( $job_id );

		// Only the hooks returned by allowed_worker_hooks() can reach this line.
		do_action( $hook, $job_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Whitelisted internal hook.

		delete_option( $lease_key );

		wp_die( 'ok', '', array( 'response' => 200 ) );
	}

	/**
	 * Check if a worker lease has expired (e.g., worker killed after claim).
	 *
	 * @param int    $job_id Job ID.
	 * @param string $hook   Hook.
	 * @param int    $ttl    Seconds before lease considered expired (default 60).
	 * @return bool True if lease exists and is expired.
	 */
	public static function is_lease_expired( $job_id, $hook, $ttl = 60 ) {
		$lease = get_option( 'usdtf_worker_lease_' . $job_id . '_' . $hook, null );
		if ( ! is_array( $lease ) || ! isset( $lease['time'] ) ) {
			return false;
		}
		return ( time() - (int) $lease['time'] ) > $ttl;
	}

	/**
	 * Clear expired leases (called by recovery).
	 *
	 * @return void
	 */
	public static function clear_expired_leases() {
		global $wpdb;
		$like = $wpdb->esc_like( 'usdtf_worker_lease_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( $keys as $key ) {
			$lease = get_option( $key, null );
			if ( is_array( $lease ) && isset( $lease['time'] ) && ( time() - (int) $lease['time'] ) > 300 ) {
				delete_option( $key );
			}
		}
	}
}
