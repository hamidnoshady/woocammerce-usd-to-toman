<?php
/**
 * Plugin logger.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Writes structured log entries to the plugin log table.
 */
final class Logger {

	/**
	 * Log level: debug.
	 */
	const DEBUG = 'debug';

	/**
	 * Log level: info.
	 */
	const INFO = 'info';

	/**
	 * Log level: warning.
	 */
	const WARNING = 'warning';

	/**
	 * Log level: error.
	 */
	const ERROR = 'error';

	/**
	 * Write a log entry.
	 *
	 * @param string $level   One of the level constants.
	 * @param string $message Message.
	 * @param array  $context Extra context, stored as JSON.
	 * @param int    $job_id  Related job ID.
	 * @return void
	 */
	public function log( $level, $message, array $context = array(), $job_id = 0 ) {
		$level = in_array( $level, array( self::DEBUG, self::INFO, self::WARNING, self::ERROR ), true ) ? $level : self::INFO;

		if ( self::DEBUG === $level && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		$message = (string) $message;

		if ( ! Database::table_exists( Database::logs_table() ) ) {
			return;
		}

		Database::insert(
			Database::logs_table(),
			array(
				'level'      => $level,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'job_id'     => (int) $job_id,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		if ( self::ERROR === $level ) {
			update_option(
				'usdtf_last_error',
				array(
					'message' => $message,
					'time'    => time(),
					'job_id'  => (int) $job_id,
				),
				false
			);
		}
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param int    $job_id  Job ID.
	 * @return void
	 */
	public function info( $message, array $context = array(), $job_id = 0 ) {
		$this->log( self::INFO, $message, $context, $job_id );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param int    $job_id  Job ID.
	 * @return void
	 */
	public function warning( $message, array $context = array(), $job_id = 0 ) {
		$this->log( self::WARNING, $message, $context, $job_id );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param int    $job_id  Job ID.
	 * @return void
	 */
	public function error( $message, array $context = array(), $job_id = 0 ) {
		$this->log( self::ERROR, $message, $context, $job_id );
	}

	/**
	 * Most recent log entries.
	 *
	 * @param int    $limit  Maximum rows.
	 * @param string $level  Optional level filter.
	 * @param int    $job_id Optional job filter.
	 * @return array[]
	 */
	public function recent( $limit = 20, $level = '', $job_id = 0 ) {
		$table   = Database::logs_table();
		$where   = array( '1=1' );
		$prepare = array();

		if ( $limit <= 0 ) {
			$limit = 20;
		}

		if ( $level ) {
			$where[]   = 'level = %s';
			$prepare[] = $level;
		}

		if ( $job_id ) {
			$where[]   = 'job_id = %d';
			$prepare[] = $job_id;
		}

		$prepare[] = $limit;
		$where_sql = implode( ' AND ', $where );

		global $wpdb;

		return Database::get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d",
				$prepare
			)
		);
	}

	/**
	 * The last recorded error.
	 *
	 * @return array|null
	 */
	public function last_error() {
		global $wpdb;

		$table = Database::logs_table();

		if ( ! Database::table_exists( $table ) ) {
			return null;
		}

		return Database::get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` WHERE level = %s ORDER BY id DESC LIMIT 1",
				self::ERROR
			)
		);
	}

	/**
	 * Delete log entries older than the given number of days.
	 *
	 * @param int $days Retention window, 0 keeps everything.
	 * @return int Deleted rows.
	 */
	public function purge_older_than( $days ) {
		$days = (int) $days;

		if ( $days <= 0 ) {
			return 0;
		}

		$table  = Database::logs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		global $wpdb;

		return Database::query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"DELETE FROM `{$table}` WHERE created_at < %s",
				$cutoff
			)
		);
	}
}
