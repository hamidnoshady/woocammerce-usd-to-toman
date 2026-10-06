<?php
/**
 * Synchronization job model.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-ish value object for a row of the jobs table.
 */
final class Job {

	/**
	 * Job created, worker not started yet.
	 */
	const STATUS_QUEUED = 'queued';

	/**
	 * Worker is processing the job.
	 */
	const STATUS_RUNNING = 'running';

	/**
	 * Job was paused by an admin and can be resumed.
	 */
	const STATUS_PAUSED = 'paused';

	/**
	 * Job finished without problems.
	 */
	const STATUS_COMPLETED = 'completed';

	/**
	 * Job finished but some products failed or conflicted.
	 */
	const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

	/**
	 * Job was cancelled by an admin.
	 */
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Job stopped because of a fatal error.
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * Phase: discovering the products in scope.
	 */
	const PHASE_DISCOVER = 'discover';

	/**
	 * Phase: processing queued items.
	 */
	const PHASE_PROCESS = 'process';

	/**
	 * Phase: synchronizing variable parents.
	 */
	const PHASE_FINALIZE = 'finalize';

	/**
	 * Phase: nothing left to do.
	 */
	const PHASE_DONE = 'done';

	/**
	 * Job type: write prices.
	 */
	const TYPE_SYNC = 'sync';

	/**
	 * Job type: dry run, no writes.
	 */
	const TYPE_PREVIEW = 'preview';

	/**
	 * Job type: recalculate after restoring a previous rate.
	 */
	const TYPE_ROLLBACK = 'rollback';

	/**
	 * Job type: recalculate a handful of selected products.
	 */
	const TYPE_RECALCULATE = 'recalculate';

	/**
	 * Job fields and their types.
	 *
	 * @var array
	 */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Raw row.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Build from a database row.
	 *
	 * @param array $row Row.
	 * @return self
	 */
	public static function from_row( array $row ) {
		return new self( $row );
	}

	/**
	 * Job ID.
	 *
	 * @return int
	 */
	public function id() {
		return (int) $this->data['id'];
	}

	/**
	 * Magic getter for any column.
	 *
	 * @param string $key Column name.
	 * @return mixed
	 */
	public function __get( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}

	/**
	 * Job status.
	 *
	 * @return string
	 */
	public function status() {
		return (string) $this->data['status'];
	}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function type() {
		return (string) $this->data['job_type'];
	}

	/**
	 * Current phase.
	 *
	 * @return string
	 */
	public function phase() {
		return (string) $this->data['phase'];
	}

	/**
	 * Exchange rate used by this job.
	 *
	 * @return float
	 */
	public function rate() {
		return (float) $this->data['rate'];
	}

	/**
	 * Declared scope.
	 *
	 * @return array
	 */
	public function scope() {
		$scope = isset( $this->data['scope'] ) ? json_decode( (string) $this->data['scope'], true ) : null;

		return is_array( $scope ) ? $scope : array();
	}

	/**
	 * Whether this job writes prices.
	 *
	 * @return bool
	 */
	public function is_write_job() {
		return self::TYPE_PREVIEW !== $this->type();
	}

	/**
	 * Whether the job is a real price update.
	 *
	 * @return bool
	 */
	public function is_sync_job() {
		return in_array( $this->type(), array( self::TYPE_SYNC, self::TYPE_ROLLBACK ), true );
	}

	/**
	 * Whether the job still occupies the single-job slot.
	 *
	 * @return bool
	 */
	public function is_active() {
		return in_array( $this->status(), array( self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_PAUSED ), true );
	}

	/**
	 * Whether the worker may still make progress.
	 *
	 * @return bool
	 */
	public function is_running() {
		return self::STATUS_RUNNING === $this->status();
	}

	/**
	 * Whether the job reached a terminal state.
	 *
	 * @return bool
	 */
	public function is_finished() {
		return ! $this->is_active();
	}

	/**
	 * Progress in percent.
	 *
	 * @return float
	 */
	public function progress_percent() {
		$total = max( 0, (int) $this->data['total_items'] );

		if ( 0 === $total ) {
			return self::STATUS_RUNNING === $this->status() ? 0.0 : 100.0;
		}

		return min( 100.0, round( ( (int) $this->data['processed'] / $total ) * 100, 1 ) );
	}

	/**
	 * Age of the last heartbeat in seconds.
	 *
	 * @return int
	 */
	public function heartbeat_age() {
		$heartbeat = isset( $this->data['heartbeat_at'] ) ? (string) $this->data['heartbeat_at'] : '';

		if ( '' === $heartbeat || null === $heartbeat ) {
			$started = isset( $this->data['started_at'] ) ? (string) $this->data['started_at'] : '';

			if ( '' === $started ) {
				return 0;
			}

			$heartbeat = $started;
		}

		$timestamp = strtotime( $heartbeat . ' UTC' );

		return $timestamp ? max( 0, time() - $timestamp ) : 0;
	}

	/**
	 * Whether the job looks dead (no heartbeat for a while).
	 *
	 * @return bool
	 */
	public function is_stale() {
		return self::STATUS_RUNNING === $this->status() && $this->heartbeat_age() > Lock::TTL;
	}

	/**
	 * Counters for the progress UI.
	 *
	 * @return array
	 */
	public function counters() {
		return array(
			'total'                => (int) $this->data['total_items'],
			'processed'            => (int) $this->data['processed'],
			'changed'              => (int) $this->data['changed'],
			'unchanged'            => (int) $this->data['unchanged'],
			'skipped'              => (int) $this->data['skipped'],
			'failed'               => (int) $this->data['failed'],
			'conflicts'            => (int) $this->data['conflicts'],
			'attention'            => (int) $this->data['attention'],
			'variations_processed' => (int) $this->data['variations_processed'],
			'variations_changed'   => (int) $this->data['variations_changed'],
		);
	}

	/**
	 * Plain array representation for REST responses.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'            => $this->id(),
			'status'        => $this->status(),
			'type'          => $this->type(),
			'phase'         => $this->phase(),
			'rate'          => $this->rate(),
			'previous_rate' => isset( $this->data['previous_rate'] ) && '' !== $this->data['previous_rate'] ? (float) $this->data['previous_rate'] : null,
			'currency_mode' => (string) $this->data['currency_mode'],
			'rounding'      => (string) $this->data['rounding'],
			'increment'     => (float) $this->data['increment'],
			'decimals'      => (int) $this->data['decimals'],
			'counters'      => $this->counters(),
			'progress'      => $this->progress_percent(),
			'scope'         => $this->scope(),
			'user_id'       => (int) $this->data['user_id'],
			'user'          => self::user_display_name( (int) $this->data['user_id'] ),
			'message'       => isset( $this->data['message'] ) ? (string) $this->data['message'] : '',
			'current_item'  => isset( $this->data['current_item'] ) ? (string) $this->data['current_item'] : '',
			'started_at'    => isset( $this->data['started_at'] ) ? (string) $this->data['started_at'] : null,
			'finished_at'   => isset( $this->data['finished_at'] ) ? (string) $this->data['finished_at'] : null,
			'heartbeat_age' => $this->heartbeat_age(),
			'is_active'     => $this->is_active(),
			'is_stale'      => $this->is_stale(),
			'can_pause'     => self::STATUS_RUNNING === $this->status(),
			'can_resume'    => in_array( $this->status(), array( self::STATUS_PAUSED, self::STATUS_FAILED ), true ) && $this->is_write_job(),
			'can_cancel'    => $this->is_active(),
			'created_at'    => isset( $this->data['created_at'] ) ? (string) $this->data['created_at'] : null,
		);
	}

	/**
	 * Display name of the user that started the job.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_display_name( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return __( 'System', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return sprintf(
				/* translators: %d: user ID. */
				__( 'User #%d', 'usd-to-toman-price-sync-for-woocommerce' ),
				$user_id
			);
		}

		return $user->display_name;
	}

	/**
	 * Human readable status label.
	 *
	 * @return string
	 */
	public function status_label() {
		switch ( $this->status() ) {
			case self::STATUS_QUEUED:
				return __( 'Queued', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::STATUS_RUNNING:
				return __( 'Running', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::STATUS_PAUSED:
				return __( 'Paused', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::STATUS_COMPLETED:
				return __( 'Completed', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::STATUS_COMPLETED_WITH_ERRORS:
				return __( 'Completed with errors', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::STATUS_CANCELLED:
				return __( 'Cancelled', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::STATUS_FAILED:
				return __( 'Failed', 'usd-to-toman-price-sync-for-woocommerce' );
			default:
				return (string) $this->status();
		}
	}

	/**
	 * Human readable type label.
	 *
	 * @return string
	 */
	public function type_label() {
		switch ( $this->type() ) {
			case self::TYPE_PREVIEW:
				return __( 'Preview / dry run', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::TYPE_ROLLBACK:
				return __( 'Rollback', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::TYPE_RECALCULATE:
				return __( 'Recalculate selected', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::TYPE_SYNC:
			default:
				return __( 'Price update', 'usd-to-toman-price-sync-for-woocommerce' );
		}
	}

	/**
	 * Finish status for a job based on its counters.
	 *
	 * @return string
	 */
	public function completed_status() {
		if ( ! $this->is_write_job() ) {
			return self::STATUS_COMPLETED;
		}

		// Failures, conflicts and skipped products that need the attention of an
		// administrator (invalid or conflicting price data) are reported.
		if ( (int) $this->data['failed'] > 0 || (int) $this->data['conflicts'] > 0 || (int) $this->data['attention'] > 0 ) {
			return self::STATUS_COMPLETED_WITH_ERRORS;
		}

		return self::STATUS_COMPLETED;
	}
}
