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
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Five minute maintenance event.
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

		return $schedules;
	}

	/**
	 * Schedule both maintenance events.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::EVENT_TICK ) ) {
			wp_schedule_event( time() + 120, self::SCHEDULE, self::EVENT_TICK );
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
