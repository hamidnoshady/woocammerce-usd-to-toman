<?php
/**
 * Job and job item persistence.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Stores synchronization jobs and their per product work items.
 */
final class Job_Repository {

	/**
	 * Item status: waiting to be processed.
	 */
	const ITEM_PENDING = 'pending';

	/**
	 * Item status: claimed by a worker.
	 */
	const ITEM_PROCESSING = 'processing';

	/**
	 * Item status: price was written.
	 */
	const ITEM_CHANGED = 'changed';

	/**
	 * Item status: nothing to write.
	 */
	const ITEM_UNCHANGED = 'unchanged';

	/**
	 * Item status: skipped (invalid, unmanaged, missing).
	 */
	const ITEM_SKIPPED = 'skipped';

	/**
	 * Item status: the write failed.
	 */
	const ITEM_FAILED = 'failed';

	/**
	 * Item status: the source changed during the job.
	 */
	const ITEM_CONFLICT = 'conflict';

	/**
	 * Item status: dropped because the job was cancelled.
	 */
	const ITEM_CANCELLED = 'cancelled';

	/**
	 * Columns of the items table, in insert order.
	 *
	 * @var string[]
	 */
	const ITEM_COLUMNS = array(
		'job_id',
		'object_id',
		'parent_id',
		'object_type',
		'expected_revision',
		'source_regular',
		'source_sale',
		'old_regular',
		'old_sale',
		'new_regular',
		'new_sale',
		'toman_regular',
		'toman_sale',
		'status',
		'child_cursor',
		'child_total',
		'parent_synced',
		'attempts',
		'message',
		'retry_after',
		'updated_at',
	);

	/**
	 * Printf formats matching ITEM_COLUMNS.
	 *
	 * @var string[]
	 */
	const ITEM_FORMATS = array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' );

	/**
	 * Create a job row.
	 *
	 * @param array $args Job data.
	 * @return int Job ID, 0 on failure.
	 */
	public function create( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'status'        => Job::STATUS_QUEUED,
				'job_type'      => Job::TYPE_SYNC,
				'scope'         => array(),
				'currency_mode' => Settings::MODE_USD,
				'rate'          => 0,
				'previous_rate' => null,
				'rounding'      => Calculator::ROUND_UP,
				'increment'     => 1,
				'decimals'      => 0,
				'user_id'       => get_current_user_id(),
				'phase'         => Job::PHASE_DISCOVER,
			)
		);

		$now = current_time( 'mysql', true );

		return Database::insert(
			Database::jobs_table(),
			array(
				'status'        => (string) $args['status'],
				'job_type'      => (string) $args['job_type'],
				'scope'         => wp_json_encode( $args['scope'] ),
				'currency_mode' => (string) $args['currency_mode'],
				'rate'          => (float) $args['rate'],
				'previous_rate' => null === $args['previous_rate'] ? null : (float) $args['previous_rate'],
				'rounding'      => (string) $args['rounding'],
				'increment'     => (float) $args['increment'],
				'decimals'      => (int) $args['decimals'],
				'phase'         => (string) $args['phase'],
				'user_id'       => (int) $args['user_id'],
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%f', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Fetch a job.
	 *
	 * @param int $job_id Job ID.
	 * @return Job|null
	 */
	public function get( $job_id ) {
		global $wpdb;

		$table = Database::jobs_table();

		$row = Database::get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` WHERE id = %d",
				(int) $job_id
			)
		);

		return $row ? Job::from_row( $row ) : null;
	}

	/**
	 * Recent jobs.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type int    $limit    Maximum rows. Default 20.
	 *     @type string $status   Optional status filter.
	 *     @type bool   $writable Only jobs that write prices.
	 * }
	 * @return Job[]
	 */
	public function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'limit'    => 20,
				'status'   => '',
				'writable' => false,
			)
		);

		$table  = Database::jobs_table();
		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if ( $args['writable'] ) {
			$where[] = "job_type <> 'preview'";
		}

		$params[]  = max( 1, (int) $args['limit'] );
		$where_sql = implode( ' AND ', $where );

		$rows = Database::get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d",
				$params
			)
		);

		return array_map( array( Job::class, 'from_row' ), $rows );
	}

	/**
	 * The job that currently holds the single-job slot.
	 *
	 * @return Job|null
	 */
	public function active_write_job() {
		global $wpdb;

		$table = Database::jobs_table();

		$row = Database::get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` WHERE status IN ( %s, %s, %s ) AND job_type <> %s ORDER BY id ASC LIMIT 1",
				Job::STATUS_QUEUED,
				Job::STATUS_RUNNING,
				Job::STATUS_PAUSED,
				Job::TYPE_PREVIEW
			)
		);

		return $row ? Job::from_row( $row ) : null;
	}

	/**
	 * The newest finished job.
	 *
	 * @return Job|null
	 */
	public function last_finished_job() {
		global $wpdb;

		$table = Database::jobs_table();

		$row = Database::get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` WHERE status NOT IN ( %s, %s, %s ) ORDER BY id DESC LIMIT 1",
				Job::STATUS_QUEUED,
				Job::STATUS_RUNNING,
				Job::STATUS_PAUSED
			)
		);

		return $row ? Job::from_row( $row ) : null;
	}

	/**
	 * Update a job row.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Column/value pairs.
	 * @return void
	 */
	public function update( $job_id, array $data ) {
		$allowed = array(
			'status',
			'phase',
			'rate',
			'previous_rate',
			'currency_mode',
			'decimals',
			'rounding',
			'increment',
			'total_items',
			'processed',
			'changed',
			'unchanged',
			'skipped',
			'failed',
			'conflicts',
			'attention',
			'discovery_page',
			'current_item',
			'message',
			'started_at',
			'heartbeat_at',
			'finished_at',
			'scope',
			'user_id',
		);

		$format_map = array(
			'total_items'    => '%d',
			'processed'      => '%d',
			'changed'        => '%d',
			'unchanged'      => '%d',
			'skipped'        => '%d',
			'failed'         => '%d',
			'conflicts'      => '%d',
			'attention'      => '%d',
			'discovery_page' => '%d',
			'user_id'        => '%d',
			'decimals'       => '%d',
			'rate'           => '%f',
			'previous_rate'  => '%f',
			'increment'      => '%f',
		);

		$clean         = array();
		$column_format = array();

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			if ( 'scope' === $key ) {
				$value = wp_json_encode( $value );
			}

			$clean[ $key ]   = $value;
			$column_format[] = isset( $format_map[ $key ] ) ? $format_map[ $key ] : '%s';
		}

		if ( ! $clean ) {
			return;
		}

		$clean['updated_at'] = current_time( 'mysql', true );
		$column_format[]     = '%s';

		Database::update( Database::jobs_table(), $clean, array( 'id' => (int) $job_id ), $column_format, array( '%d' ) );
	}

	/**
	 * Refresh the heartbeat and lock timestamp.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Optional status to enforce.
	 * @return void
	 */
	public function heartbeat( $job_id, $status = '' ) {
		$data = array(
			'heartbeat_at' => current_time( 'mysql', true ),
			'updated_at'   => current_time( 'mysql', true ),
		);

		if ( $status ) {
			$data['status'] = $status;
		}

		Database::update( Database::jobs_table(), $data, array( 'id' => (int) $job_id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/**
	 * Increase several counters in one query.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $deltas Counter name => increment.
	 * @return void
	 */
	public function increment( $job_id, array $deltas ) {
		$allowed = array( 'processed', 'changed', 'unchanged', 'skipped', 'failed', 'conflicts', 'total_items', 'variations_processed', 'variations_changed' );
		$sets    = array();

		global $wpdb;

		foreach ( $deltas as $counter => $delta ) {
			$delta = (int) $delta;

			if ( ! in_array( $counter, $allowed, true ) || 0 === $delta ) {
				continue;
			}

			$sign   = $delta > 0 ? '+' : '-';
			$sets[] = "`{$counter}` = `{$counter}` {$sign} " . abs( $delta );
		}

		if ( ! $sets ) {
			return;
		}

		$table = Database::jobs_table();

		Database::query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal, counters are whitelisted.
				"UPDATE `{$table}` SET " . implode( ', ', $sets ) . ' , `updated_at` = %s WHERE `id` = %d',
				current_time( 'mysql', true ),
				(int) $job_id
			)
		);
	}

	/**
	 * Set a single counter to an absolute value.
	 *
	 * @param int    $job_id  Job ID.
	 * @param string $counter Counter name.
	 * @param int    $value   Value.
	 * @return void
	 */
	public function set_counter( $job_id, $counter, $value ) {
		$allowed = array( 'total_items', 'processed', 'changed', 'unchanged', 'skipped', 'failed', 'conflicts', 'discovery_page' );

		if ( ! in_array( $counter, $allowed, true ) ) {
			return;
		}

		global $wpdb;

		$table = Database::jobs_table();

		Database::query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and counter are whitelisted.
				"UPDATE `{$table}` SET `{$counter}` = %d, `updated_at` = %s WHERE `id` = %d",
				(int) $value,
				current_time( 'mysql', true ),
				(int) $job_id
			)
		);
	}

	/**
	 * Number of items of a job that need an administrator's attention.
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public function attention_total( $job_id ) {
		global $wpdb;

		$table = Database::items_table();

		$count = Database::get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT COUNT(*) FROM `{$table}` WHERE job_id = %d AND attention = 1",
				(int) $job_id
			)
		);

		return (int) $count;
	}

	/**
	 * Insert a batch of work items.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $items  Item data.
	 * @return int Inserted rows.
	 */
	public function add_items( $job_id, array $items ) {
		if ( ! $items ) {
			return 0;
		}

		$now  = current_time( 'mysql', true );
		$rows = array();

		foreach ( $items as $item ) {
			$rows[] = array(
				'job_id'            => (int) $job_id,
				'object_id'         => (int) $item['object_id'],
				'parent_id'         => isset( $item['parent_id'] ) ? (int) $item['parent_id'] : 0,
				'object_type'       => isset( $item['object_type'] ) ? (string) $item['object_type'] : 'product',
				'expected_revision' => isset( $item['expected_revision'] ) ? (int) $item['expected_revision'] : 0,
				'source_regular'    => isset( $item['source_regular'] ) ? $item['source_regular'] : null,
				'source_sale'       => isset( $item['source_sale'] ) ? $item['source_sale'] : null,
				'old_regular'       => isset( $item['old_regular'] ) ? $item['old_regular'] : null,
				'old_sale'          => isset( $item['old_sale'] ) ? $item['old_sale'] : null,
				'new_regular'       => isset( $item['new_regular'] ) ? $item['new_regular'] : null,
				'new_sale'          => isset( $item['new_sale'] ) ? $item['new_sale'] : null,
				'toman_regular'     => isset( $item['toman_regular'] ) ? $item['toman_regular'] : null,
				'toman_sale'        => isset( $item['toman_sale'] ) ? $item['toman_sale'] : null,
				'status'            => isset( $item['status'] ) ? (string) $item['status'] : self::ITEM_PENDING,
				'child_cursor'      => isset( $item['child_cursor'] ) ? (int) $item['child_cursor'] : 0,
				'child_total'       => isset( $item['child_total'] ) ? (int) $item['child_total'] : 0,
				'parent_synced'     => isset( $item['parent_synced'] ) ? (int) $item['parent_synced'] : 0,
				'attempts'          => isset( $item['attempts'] ) ? (int) $item['attempts'] : 0,
				'message'           => isset( $item['message'] ) ? $item['message'] : null,
				'retry_after'       => isset( $item['retry_after'] ) ? $item['retry_after'] : null,
				'updated_at'        => $now,
			);
		}

		return Database::bulk_insert( Database::items_table(), self::ITEM_COLUMNS, self::ITEM_FORMATS, $rows );
	}

	/**
	 * Claim the next items for processing.
	 *
	 * Items that were left in "processing" by a dead worker and items whose
	 * retry delay has passed are picked up again, which is what makes the job
	 * resumable after a crash.
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Maximum items.
	 * @return array[]
	 */
	public function next_items( $job_id, $limit ) {
		global $wpdb;

		$table = Database::items_table();
		$now   = current_time( 'mysql', true );

		$rows = Database::get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` WHERE job_id = %d AND ( status = %s OR ( status = %s AND ( retry_after IS NULL OR retry_after <= %s ) ) ) ORDER BY id ASC LIMIT %d",
				(int) $job_id,
				self::ITEM_PENDING,
				self::ITEM_PROCESSING,
				$now,
				max( 1, (int) $limit )
			)
		);

		return $rows;
	}

	/**
	 * Update an item row.
	 *
	 * @param int   $item_id Item ID.
	 * @param array $data    Column/value pairs.
	 * @return void
	 */
	public function update_item( $item_id, array $data ) {
		$formats = array(
			'object_id'         => '%d',
			'parent_id'         => '%d',
			'expected_revision' => '%d',
			'attempts'          => '%d',
			'child_cursor'      => '%d',
			'child_total'       => '%d',
			'parent_synced'     => '%d',
			'attention'         => '%d',
			'job_id'            => '%d',
		);

		$clean = array();

		foreach ( $data as $key => $value ) {
			$clean[ $key ] = $value;
		}

		$clean['updated_at'] = current_time( 'mysql', true );

		$item_formats = array();

		foreach ( $clean as $key => $value ) {
			$item_formats[] = isset( $formats[ $key ] ) ? $formats[ $key ] : '%s';
		}

		Database::update( Database::items_table(), $clean, array( 'id' => (int) $item_id ), $item_formats, array( '%d' ) );
	}

	/**
	 * Move items from one status to another.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $from   Source status.
	 * @param string $to     Target status.
	 * @param int    $limit  Optional maximum number of items.
	 * @return int Affected rows.
	 */
	public function reset_items( $job_id, $from, $to, $limit = 0 ) {
		global $wpdb;

		$table = Database::items_table();
		$limit = (int) $limit;

		if ( $limit > 0 ) {
			$ids = Database::get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
					"SELECT id FROM `{$table}` WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
					(int) $job_id,
					(string) $from,
					$limit
				)
			);

			if ( ! $ids ) {
				return 0;
			}

			$id_list = implode( ',', array_map( 'intval', wp_list_pluck( $ids, 'id' ) ) );

			return Database::query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal, ID list is cast to int.
					"UPDATE `{$table}` SET status = %s, attempts = 0, retry_after = NULL, updated_at = %s WHERE id IN ( {$id_list} )",
					(string) $to,
					current_time( 'mysql', true )
				)
			);
		}

		return Database::query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"UPDATE `{$table}` SET status = %s, attempts = 0, retry_after = NULL, updated_at = %s WHERE job_id = %d AND status = %s",
				(string) $to,
				current_time( 'mysql', true ),
				(int) $job_id,
				(string) $from
			)
		);
	}

	/**
	 * Mark the remaining pending items of a job as cancelled.
	 *
	 * @param int $job_id Job ID.
	 * @return int Affected rows.
	 */
	public function cancel_pending_items( $job_id ) {
		return $this->reset_items( $job_id, self::ITEM_PENDING, self::ITEM_CANCELLED );
	}

	/**
	 * Count items of a job.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public function count_items( $job_id, $status = '' ) {
		global $wpdb;

		$table = Database::items_table();

		if ( $status ) {
			return (int) Database::get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
					"SELECT COUNT(*) FROM `{$table}` WHERE job_id = %d AND status = %s",
					(int) $job_id,
					(string) $status
				)
			);
		}

		return (int) Database::get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT COUNT(*) FROM `{$table}` WHERE job_id = %d",
				(int) $job_id
			)
		);
	}

	/**
	 * Item counts grouped by status.
	 *
	 * @param int $job_id Job ID.
	 * @return array
	 */
	public function item_totals( $job_id ) {
		global $wpdb;

		$table = Database::items_table();

		$rows = Database::get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT status, COUNT(*) AS total FROM `{$table}` WHERE job_id = %d GROUP BY status",
				(int) $job_id
			)
		);

		$totals = array();

		foreach ( $rows as $row ) {
			$totals[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $totals;
	}

	/**
	 * Items of a job for the detail screen and CSV export.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type string $status Item status filter.
	 *     @type int    $limit  Rows per page.
	 *     @type int    $page   1 based page number.
	 * }
	 * @return array[]
	 */
	public function items( $job_id, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status' => '',
				'limit'  => 50,
				'page'   => 1,
			)
		);

		$limit  = max( 1, (int) $args['limit'] );
		$offset = max( 0, ( ( max( 1, (int) $args['page'] ) - 1 ) * $limit ) );

		$params = array( (int) $job_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
		$sql = 'SELECT * FROM `' . Database::items_table() . '` WHERE job_id = %d';

		if ( $args['status'] ) {
			$sql .= ' AND status = %s';

			$params[] = (string) $args['status'];
		}

		$sql .= ' ORDER BY id ASC LIMIT %d OFFSET %d'; // phpcs:ignore Squiz.Strings.DoubleQuoteUsage.NotRequired -- Placeholders are single quoted already.

		$params[] = $limit;
		$params[] = $offset;

		return Database::get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Distinct variable parents touched by a job, used by the finalize phase.
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $status   Item status to look at.
	 * @param int    $limit    Maximum rows.
	 * @param int    $after_id Only consider items with a higher ID.
	 * @return int[] Parent IDs and the highest seen item ID.
	 */
	public function parents_for_finalize( $job_id, $status, $limit, $after_id = 0 ) {
		global $wpdb;

		$table = Database::items_table();

		$rows = Database::get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT id, parent_id FROM `{$table}` WHERE job_id = %d AND status = %s AND parent_id > 0 AND parent_synced = 0 AND id > %d AND object_type = %s ORDER BY id ASC LIMIT %d",
				(int) $job_id,
				(string) $status,
				(int) $after_id,
				'variation',
				max( 1, (int) $limit )
			)
		);

		$parents = array();
		$last_id = $after_id;

		foreach ( $rows as $row ) {
			$parents[] = (int) $row['parent_id'];
			$last_id   = max( $last_id, (int) $row['id'] );
		}

		return array(
			'parents' => array_values( array_unique( $parents ) ),
			'last_id' => $last_id,
		);
	}

	/**
	 * Mark the items of the given parents as "parent recalculated".
	 *
	 * @param int   $job_id    Job ID.
	 * @param int[] $parent_ids Parent IDs.
	 * @return void
	 */
	public function mark_parents_synced( $job_id, array $parent_ids ) {
		global $wpdb;

		$parent_ids = array_values( array_filter( array_map( 'intval', $parent_ids ) ) );

		if ( ! $parent_ids ) {
			return;
		}

		$table   = Database::items_table();
		$id_list = implode( ',', $parent_ids );

		Database::query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal, parent list is cast to int.
				"UPDATE `{$table}` SET parent_synced = 1, updated_at = %s WHERE job_id = %d AND parent_id IN ( {$id_list} )",
				current_time( 'mysql', true ),
				(int) $job_id
			)
		);
	}

	/**
	 * Delete job items and finished jobs older than the retention window.
	 *
	 * @param int $days Retention in days, 0 disables cleanup.
	 * @return int Deleted item rows.
	 */
	public function purge_items_older_than( $days ) {
		global $wpdb;

		$days = (int) $days;

		if ( $days <= 0 ) {
			return 0;
		}

		$table  = Database::items_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = Database::query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"DELETE FROM `{$table}` WHERE updated_at < %s",
				$cutoff
			)
		);

		// Also drop metadata rows of finished jobs so the table stays small.
		$jobs_table = Database::jobs_table();

		Database::query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"UPDATE `{$jobs_table}` SET scope = '{}' WHERE scope IS NOT NULL AND scope <> '{}' AND finished_at IS NOT NULL AND finished_at < %s",
				$cutoff
			)
		);

		return $deleted;
	}

	/**
	 * How many items are still waiting.
	 *
	 * @param int $job_id Job ID.
	 * @return int
	 */
	public function pending_count( $job_id ) {
		$totals = $this->item_totals( $job_id );

		return (int) ( isset( $totals[ self::ITEM_PENDING ] ) ? $totals[ self::ITEM_PENDING ] : 0 )
			+ (int) ( isset( $totals[ self::ITEM_PROCESSING ] ) ? $totals[ self::ITEM_PROCESSING ] : 0 );
	}
}
