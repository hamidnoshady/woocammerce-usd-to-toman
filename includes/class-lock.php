<?php
/**
 * Locking helpers that keep two synchronizations from racing.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * A single option backed lock with a token owner and a TTL.
 */
final class Lock {

	/**
	 * Option holding the current lock.
	 */
	const OPTION = 'usdtf_job_lock';

	/**
	 * A lock without a heartbeat for this many seconds is considered dead.
	 */
	const TTL = 300;

	/**
	 * Token that owns the lock.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Unique token for this process.
	 */
	public function __construct( $token = '' ) {
		$this->token = $token ? (string) $token : wp_generate_password( 20, false, false );
	}

	/**
	 * This process' token.
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
	 * Whether the lock is currently held by another live owner.
	 *
	 * @return bool
	 */
	public function is_held_by_other() {
		$lock = $this->read();

		if ( empty( $lock['token'] ) || $lock['token'] === $this->token ) {
			return false;
		}

		return (int) $lock['expires'] > time();
	}

	/**
	 * Try to acquire the lock for a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function acquire( $job_id ) {
		$lock = $this->read();

		if ( ! empty( $lock['token'] ) && $lock['token'] !== $this->token ) {
			if ( (int) $lock['expires'] > time() ) {
				return false;
			}

			// The previous owner died without releasing the lock.
			$this->release();
		}

		return $this->write( $job_id );
	}

	/**
	 * Refresh the lock TTL. Returns false when the lock was lost.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function heartbeat( $job_id ) {
		$lock = $this->read();

		if ( ! empty( $lock['token'] ) && $lock['token'] !== $this->token && (int) $lock['expires'] > time() ) {
			return false;
		}

		return $this->write( $job_id );
	}

	/**
	 * Write the lock value.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	private function write( $job_id ) {
		update_option(
			self::OPTION,
			array(
				'job_id'  => (int) $job_id,
				'token'   => $this->token,
				'expires' => time() + self::TTL,
			),
			false
		);

		return true;
	}

	/**
	 * Release the lock when this process owns it.
	 *
	 * @return void
	 */
	public function release() {
		$lock = $this->read();

		if ( ! empty( $lock['token'] ) && $lock['token'] !== $this->token ) {
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
