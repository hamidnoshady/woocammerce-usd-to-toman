<?php
/**
 * WP-Cron maintenance events (job recovery and log retention).
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules maintenance tasks that keep synchronization jobs healthy.
 */
final class Cron {

	/**
	 * Five minute recovery event.
	 */
	const EVENT_TICK = 'usdtf_cron_tick';

	/**
	 * Daily retention event.
	 */
	const EVENT_DAILY = 'usdtf_cron_daily';

	/**
	 * Custom schedule name.
	 */
	const SCHEDULE = 'usdtf_five_minutes';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		// The five minute event only runs housekeeping (cleanup and queue checks).
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five minute maintenance event.
		add_action( self::EVENT_TICK, array( $this, 'tick' ) );
		add_action( self::EVENT_DAILY, array( $this, 'daily' ) );
	}

	/**
	 * Register the five minute schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 300,
				'display'  => __( 'Every five minutes (USD/Toman pricing maintenance)', 'usd-to-toman-price-sync-for-woocommerce' ),
			);
		}
		// Faster tick for lease recovery: every minute, still keeps five-minute for daily.
		if ( ! isset( $schedules['usdtf_one_minute'] ) ) {
			$schedules['usdtf_one_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (USD/Toman lease recovery)', 'usd-to-toman-price-sync-for-woocommerce' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule both maintenance events.
	 *
	 * @return void
	 */
	public static function schedule() {
		// Activation and upgrade can call this before Cron::hooks(). Register the
		// custom recurrence here as well, otherwise wp_schedule_event() rejects
		// usdtf_five_minutes and recovery silently remains unscheduled.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Plugin maintenance schedule.

		if ( ! wp_next_scheduled( self::EVENT_TICK ) ) {
			// Prefer one-minute lease recovery if available, fallback to five-minute.
			$sched = 'usdtf_one_minute';
			// Check if the one-minute schedule is actually registered (add_schedule was applied).
			$schedules = apply_filters( 'cron_schedules', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
			if ( ! isset( $schedules[ $sched ] ) ) {
				$sched = self::SCHEDULE;
			}
			wp_schedule_event( time() + 60, $sched, self::EVENT_TICK );
		}

		if ( ! wp_next_scheduled( self::EVENT_DAILY ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::EVENT_DAILY );
		}
	}

	/**
	 * Remove the maintenance events.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::EVENT_TICK );
		wp_clear_scheduled_hook( self::EVENT_DAILY );
	}

	/**
	 * Recovery pass: resume jobs whose worker action disappeared.
	 *
	 * @return void
	 */
	public function tick() {
		$runner = usdtf_plugin()->runner();

		$runner->recover_stale_jobs();
		$runner->resume_orphaned_jobs();

		// Lease expiry: if a worker claimed a step but died after claim
		// (client disconnect after unschedule, no heartbeat), requeue exactly
		// once after TTL (60s). Uses Scheduler lease helpers.
		if ( class_exists( '\\USDTF\\Scheduler' ) && method_exists( '\\USDTF\\Scheduler', 'is_lease_expired' ) ) {
			foreach ( usdtf_plugin()->jobs()->query( array( 'status' => \USDTF\Job::STATUS_RUNNING, 'limit' => 20 ) ) as $job ) {
				foreach ( \USDTF\Scheduler::allowed_worker_hooks() as $hook ) {
					if ( \USDTF\Scheduler::is_lease_expired( $job->id(), $hook, 60 ) ) {
						// Lease expired: clear and requeue the exact hook for this job.
						delete_option( 'usdtf_worker_lease_' . $job->id() . '_' . $hook );
						usdtf_plugin()->jobs()->heartbeat( $job->id() );
						$runner->resume_orphaned_jobs();
						break 2;
					}
				}
			}
			\USDTF\Scheduler::clear_expired_leases();
		}
	}

	/**
	 * Daily retention pass.
	 *
	 * @return void
	 */
	public function daily() {
		$settings = usdtf_plugin()->settings();
		$jobs     = usdtf_plugin()->jobs();
		$logger   = usdtf_plugin()->logger();

		$days = (int) $settings->get( 'retention_days' );

		$logger->purge_older_than( $days );
		$jobs->purge_items_older_than( $days );

		$logger->info( 'Daily maintenance finished.', array( 'retention_days' => $days ) );
	}
}
