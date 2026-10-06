<?php
/**
 * Locking helpers that keep two synchronizations from racing.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * A single option backed lease with a TTL, owned by a job ID.
 *
 * Ownership is the job ID, not a per-request token: the admin request that
 * starts a job and the Action Scheduler request that runs the next worker
 * step are different PHP processes, so anything generated per request cannot
 * survive the request boundary. Every method is safe to call from any process
 * that knows the job ID, and the compare-and-swap writes make takeover races
 * detectable.
 */
final class Lock {

	/**
	 * Option holding the current lock.
	 */
	const OPTION = 'usdtf_job_lock';

	/**
	 * A lease without a heartbeat for this many seconds is considered dead.
	 */
	const TTL = 300;

	/**
	 * Lease token of this request, kept for diagnostics only.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Deprecated. The lease is owned by the job ID.
	 */
	public function __construct( $token = '' ) {
		$this->token = $token ? (string) $token : wp_generate_password( 20, false, false );
	}

	/**
	 * This request' lease token.
	 *
	 * @return string
	 */
	public function token() {
		return $this->token;
	}

	/**
	 * Read the raw lock value.
	 *
	 * @return array
	 */
	public function read() {
		$lock = get_option( self::OPTION, array() );

		if ( ! is_array( $lock ) ) {
			$lock = array();
		}

		return wp_parse_args(
			$lock,
			array(
				'job_id'  => 0,
				'token'   => '',
				'expires' => 0,
			)
		);
	}

	/**
	 * Whether a live lease exists at all.
	 *
	 * Used by maintenance passes that must not disturb a healthy job, no
	 * matter which job owns the lease.
	 *
	 * @return bool
	 */
	public function is_held_by_other() {
		$lock = $this->read();

		if ( empty( $lock['token'] ) ) {
			return false;
		}

		return (int) $lock['expires'] > time();
	}

	/**
	 * Whether the given job currently owns the lease, expired or not.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function owned_by( $job_id ) {
		$lock = $this->read();

		return (int) $job_id > 0 && (int) $lock['job_id'] === (int) $job_id;
	}

	/**
	 * Try to acquire the lease for a job.
	 *
	 * The same job may re-acquire its own lease (for example after a worker
	 * died and the maintenance pass resumes it). Another live job always
	 * refuses. A dead lease is taken over atomically.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function acquire( $job_id ) {
		$job_id = (int) $job_id;

		if ( $job_id <= 0 ) {
			return false;
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$lock  = $this->read();
			$alive = (int) $lock['expires'] > time();

			if ( $alive && (int) $lock['job_id'] !== $job_id ) {
				// Another live job owns the lease.
				return false;
			}

			if ( $alive && (int) $lock['job_id'] === $job_id ) {
				// Already ours from an earlier request: renew it.
				return $this->write( $job_id, $lock );
			}

			if ( empty( $lock['token'] ) && empty( $lock['job_id'] ) ) {
				// Free lease: the atomic INSERT wins against concurrent takers.
				if ( add_option( self::OPTION, $this->lock_value( $job_id ), '', false ) ) {
					return true;
				}

				continue;
			}

			// Dead lease (or one left behind by this job): take it over with a
			// compare-and-swap so a concurrent writer cannot be overwritten.
			if ( $this->write( $job_id, $lock ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Refresh the lease TTL. Returns false when the lease was lost.
	 *
	 * Any process that knows the job ID may refresh the lease, which is what
	 * makes the worker safe across request boundaries.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function heartbeat( $job_id ) {
		$job_id = (int) $job_id;
		$lock   = $this->read();

		if ( $job_id <= 0 || (int) $lock['job_id'] !== $job_id ) {
			return false;
		}

		if ( (int) $lock['expires'] <= time() ) {
			// The lease expired: another job may have taken it over already,
			// so the refresh has to win atomically or give up.
			return $this->write( $job_id, $lock );
		}

		return $this->write( $job_id, $lock );
	}

	/**
	 * Write the lease value, compare-and-swap when a previous value exists.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $old    Previously read lease value, for the swap guard.
	 * @return bool
	 */
	private function write( $job_id, array $old = array() ) {
		$value = $this->lock_value( $job_id );

		if ( ! $old ) {
			update_option( self::OPTION, $value, false );

			return true;
		}

		$swapped = $this->compare_and_swap( $old, $value );

		if ( $swapped ) {
			return true;
		}

		// The stored value changed since it was read. If the lease still
		// belongs to the same job the race was harmless (a heartbeat of the
		// same job); otherwise the lease is gone.
		$fresh = $this->read();

		return (int) $fresh['job_id'] === (int) $job_id;
	}

	/**
	 * The value stored for a job lease.
	 *
	 * @param int $job_id Job ID.
	 * @return array
	 */
	private function lock_value( $job_id ) {
		return array(
			'job_id'  => (int) $job_id,
			'token'   => $this->token,
			'expires' => time() + self::TTL,
		);
	}

	/**
	 * Replace the lease only when it still holds the expected value.
	 *
	 * update_option() has no compare-and-swap, so the swap is done on the
	 * options table with the serialized previous value in the WHERE clause.
	 * A lost race changes zero rows, which is exactly what the caller needs
	 * to know. The object cache entry is dropped afterwards so the next read
	 * in this request sees the winner's value.
	 *
	 * @param array $old Expected current value.
	 * @param array $new New value.
	 * @return bool True when this call replaced the value.
	 */
	private function compare_and_swap( array $old, array $new ) {
		global $wpdb;

		$old_serialized = maybe_serialize( $old );
		$new_serialized = maybe_serialize( $new );

		$rows = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Option name is a class constant.
				"UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_serialized,
				self::OPTION,
				$old_serialized
			)
		);

		if ( 1 !== (int) $rows ) {
			return false;
		}

		wp_cache_delete( self::OPTION, 'options' );

		return true;
	}

	/**
	 * Release the lease when the given job owns it.
	 *
	 * @param int|null $job_id Job ID. Null releases whatever lease exists.
	 * @return void
	 */
	public function release( $job_id = null ) {
		$lock = $this->read();

		if ( null !== $job_id && (int) $lock['job_id'] !== (int) $job_id ) {
			// The lease belongs to a different job: not ours to release.
			return;
		}

		delete_option( self::OPTION );
	}

	/**
	 * Inspect the lock for the diagnostics screen.
	 *
	 * @return array
	 */
	public function status() {
		$lock   = $this->read();
		$active = (int) $lock['expires'] > time();

		return array(
			'job_id'  => (int) $lock['job_id'],
			'active'  => $active,
			'expires' => (int) $lock['expires'],
			'age'     => $active ? max( 0, time() - ( (int) $lock['expires'] - self::TTL ) ) : 0,
		);
	}
}
