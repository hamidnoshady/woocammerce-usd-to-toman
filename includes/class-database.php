<?php
/**
 * Low level database helpers for the plugin's own tables.
 *
 * Only plugin owned job/log data is stored with these helpers. Product prices
 * are always written through the WooCommerce CRUD API.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around $wpdb for the plugin's custom tables.
 */
final class Database {

	/**
	 * Current schema version.
	 */
	const SCHEMA_VERSION = '5';

	/**
	 * Fully qualified table name.
	 *
	 * @param string $name Table short name without prefix.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'usdtf_' . $name;
	}

	/**
	 * Jobs table name.
	 *
	 * @return string
	 */
	public static function jobs_table() {
		return self::table( 'jobs' );
	}

	/**
	 * Job items table name.
	 *
	 * @return string
	 */
	public static function items_table() {
		return self::table( 'job_items' );
	}

	/**
	 * Rate history table name.
	 *
	 * @return string
	 */
	public static function rates_table() {
		return self::table( 'rate_history' );
	}

	/**
	 * Log table name.
	 *
	 * @return string
	 */
	public static function logs_table() {
		return self::table( 'logs' );
	}

	/**
	 * Insert a row.
	 *
	 * @param string $table Table name.
	 * @param array  $data  Column/value pairs.
	 * @param array  $formats printf formats, matching $data order.
	 * @return int Inserted row ID, 0 on failure.
	 */
	public static function insert( $table, array $data, array $formats ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table, no cache available.
		$result = $wpdb->insert( $table, $data, $formats );

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a row.
	 *
	 * @param string $table         Table name.
	 * @param array  $data          Column/value pairs.
	 * @param array  $where         Where clause pairs.
	 * @param array  $formats       printf formats for $data.
	 * @param array  $where_formats printf formats for $where.
	 * @return int Rows affected.
	 */
	public static function update( $table, array $data, array $where, array $formats, array $where_formats ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table, no cache available.
		$result = $wpdb->update( $table, $data, $where, $formats, $where_formats );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Fetch a single row as an associative array.
	 *
	 * @param string $sql  Prepared SQL.
	 * @return array|null
	 */
	public static function get_row( $sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Caller passes prepared SQL.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch multiple rows.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array[]
	 */
	public static function get_results( $sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Caller passes prepared SQL.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a single scalar.
	 *
	 * @param string $sql Prepared SQL.
	 * @return string|null
	 */
	public static function get_var( $sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Caller passes prepared SQL.
		$value = $wpdb->get_var( $sql );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Run a prepared write query.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int Rows affected.
	 */
	public static function query( $sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Caller passes prepared SQL.
		$result = $wpdb->query( $sql );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Insert many rows of the same shape in one query.
	 *
	 * @param string $table   Table name.
	 * @param array  $columns Column names.
	 * @param array  $formats printf format per column.
	 * @param array  $rows    List of value arrays matching $columns.
	 * @return int Number of inserted rows.
	 */
	public static function bulk_insert( $table, array $columns, array $formats, array $rows ) {
		global $wpdb;

		if ( ! $rows ) {
			return 0;
		}

		$placeholders = array();
		$values       = array();

		foreach ( $rows as $row ) {
			$row_placeholders = array();

			foreach ( $columns as $index => $column ) {
				$row_placeholders[] = $formats[ $index ];
				$values[]           = isset( $row[ $column ] ) ? $row[ $column ] : null;
			}

			$placeholders[] = '(' . implode( ', ', $row_placeholders ) . ')';
		}

		$columns_sql = '`' . implode( '`, `', $columns ) . '`';
		$sql         = "INSERT INTO `{$table}` ({$columns_sql}) VALUES " . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and column list are internal, values are prepared.
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Whether a plugin table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}
}
