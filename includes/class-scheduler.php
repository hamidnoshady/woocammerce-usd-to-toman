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

		if ( $unique && $this->has_pending( $hook, $args ) ) {
			return true;
		}

		$backend = $this->backend();

		if ( self::BACKEND_ACTION_SCHEDULER === $backend ) {
			if ( function_exists( 'as_has_scheduled_action' ) && $unique && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return true;
			}

			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP, $unique );
			} else {
				as_enqueue_async_action( $hook, $args, self::GROUP, $unique );
			}

			return true;
		}

		if ( self::BACKEND_WP_CRON === $backend || self::BACKEND_LOOPBACK === $backend ) {
			if ( ! wp_next_scheduled( $hook, $args ) ) {
				wp_schedule_single_event( time() + $delay, $hook, $args );
			}

			// WP-Cron only runs on page loads. Kick a non blocking request as well.
			if ( $this->settings->get( 'loopback_fallback' ) ) {
				$this->fire_loopback( $hook, $args, $delay );
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
	 * Fire a non blocking request that runs a worker action.
	 *
	 * @param string $hook  Worker hook.
	 * @param array  $args  Arguments.
	 * @param int    $delay Delay in seconds.
	 * @return void
	 */
	private function fire_loopback( $hook, array $args, $delay = 0 ) {
		if ( ! in_array( $hook, self::allowed_worker_hooks(), true ) ) {
			return;
		}

		$url = add_query_arg( 'usdtf_delay', max( 0, (int) $delay ), admin_url( 'admin-ajax.php' ) );

		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.5,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => self::LOOPBACK_ACTION,
					'token'  => self::token(),
					'hook'   => $hook,
					'args'   => wp_json_encode( $args ),
				),
			)
		);
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

		$delay = isset( $_GET['usdtf_delay'] ) ? (int) $_GET['usdtf_delay'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token verified above.

		if ( $delay > 0 ) {
			sleep( min( 10, $delay ) );
		}

		// Only the hooks returned by allowed_worker_hooks() can reach this line.
		do_action( $hook, $job_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Whitelisted internal hook.

		wp_die( 'ok', '', array( 'response' => 200 ) );
	}
}
