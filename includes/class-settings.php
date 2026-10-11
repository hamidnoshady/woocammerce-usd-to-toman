<?php
/**
 * Plugin settings.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Read/write access to plugin settings with validated defaults.
 */
final class Settings {

	/**
	 * Option name holding every plugin setting.
	 */
	const OPTION = 'usdtf_settings';

	/**
	 * Option holding the rate that is currently active.
	 */
	const OPTION_RATE = 'usdtf_rate';

	/**
	 * Option holding a rate that is waiting for explicit confirmation.
	 */
	const OPTION_PENDING_RATE = 'usdtf_pending_rate';

	/**
	 * Option holding the store unit (`toman` or `rial`) the saved rate is in.
	 */
	const OPTION_RATE_UNIT = 'usdtf_rate_unit';

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'rounding'                 => Calculator::ROUND_UP,
			'increment'                => Calculator::default_increment( Store_Currency::unit() ),
			'rate_change_threshold'    => 15.0,
			'batch_size'               => 10,
			'time_budget'              => 20,
			'retry_limit'              => 3,
			'retention_days'           => 30,
			'loopback_fallback'        => true,
			'require_preview'          => true,
			'required_capability'      => Capabilities::DEFAULT_CAPABILITY,
			'credit_author'            => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Allowed batch sizes. Deliberately limited: this plugin must stay safe on
	 * cheap shared hosting, so 500/1000 item batches are not offered.
	 *
	 * @return int[]
	 */
	public static function allowed_batch_sizes() {
		return array( 5, 10, 15, 20, 25 );
	}

	/**
	 * Allowed rounding increments for a store currency unit.
	 *
	 * @param string|null $unit Store currency unit, the current store unit when omitted.
	 * @return float[]
	 */
	public static function allowed_increments( $unit = null ) {
		return Calculator::increments_for_unit( null === $unit ? Store_Currency::unit() : $unit );
	}

	/**
	 * Read every setting merged over the defaults.
	 *
	 * @param bool $force_reload Ignore the request cache.
	 * @return array
	 */
	public function all( $force_reload = false ) {
		if ( ! $force_reload && is_array( $this->cache ) ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache = wp_parse_args( $stored, self::defaults() );

		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value used when the setting is unknown.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $fallback;
	}

	/**
	 * Persist a partial settings update.
	 *
	 * @param array $values Values to merge.
	 * @return array The stored settings.
	 */
	public function update( array $values ) {
		$settings = wp_parse_args( $values, $this->all() );

		$settings = self::sanitize( $settings );

		update_option( self::OPTION, $settings );

		$this->cache = null;

		return $this->all( true );
	}

	/**
	 * Replace all settings with the defaults.
	 *
	 * @return array
	 */
	public function reset() {
		update_option( self::OPTION, self::defaults() );
		$this->cache = null;

		return $this->all( true );
	}

	/**
	 * Sanitize and constrain every setting value.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public static function sanitize( array $settings ) {
		$defaults = self::defaults();

		// This method is the registered sanitize_callback of the option, and
		// WordPress hands that callback only the fields that were actually
		// submitted. Callers may also sanitize a partial update on purpose.
		// Anything not supplied therefore falls back to the stored value, so a
		// partial array can neither raise "undefined array key" notices nor
		// silently reset the rest of the configuration.
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = wp_parse_args( $settings, wp_parse_args( $stored, $defaults ) );

		$clean = array();

		$clean['rounding'] = in_array( $settings['rounding'], Calculator::rounding_modes(), true )
			? $settings['rounding']
			: $defaults['rounding'];

		$increment          = (float) $settings['increment'];
		$clean['increment'] = in_array( $increment, self::allowed_increments(), true ) ? $increment : $defaults['increment'];

		$threshold                      = (float) $settings['rate_change_threshold'];
		$clean['rate_change_threshold'] = ( $threshold >= 0 && $threshold <= 90 ) ? $threshold : $defaults['rate_change_threshold'];

		$batch               = (int) $settings['batch_size'];
		$clean['batch_size'] = in_array( $batch, self::allowed_batch_sizes(), true ) ? $batch : $defaults['batch_size'];

		$budget               = (int) $settings['time_budget'];
		$clean['time_budget'] = ( $budget >= 5 && $budget <= 55 ) ? $budget : $defaults['time_budget'];

		$retries              = (int) $settings['retry_limit'];
		$clean['retry_limit'] = ( $retries >= 0 && $retries <= 10 ) ? $retries : $defaults['retry_limit'];

		$retention               = (int) $settings['retention_days'];
		$clean['retention_days'] = ( $retention >= 0 && $retention <= 3650 ) ? $retention : $defaults['retention_days'];

		foreach ( array( 'loopback_fallback', 'require_preview', 'credit_author', 'delete_data_on_uninstall' ) as $flag ) {
			$clean[ $flag ] = ! empty( $settings[ $flag ] );
		}

		// Only the documented allowlist is storable. Weaker capabilities stay
		// available to developers through the usdtf_required_capability filter.
		$capability                   = isset( $settings['required_capability'] ) ? sanitize_key( (string) $settings['required_capability'] ) : '';
		$clean['required_capability'] = in_array( $capability, Capabilities::allowed(), true ) ? $capability : $defaults['required_capability'];

		return $clean;
	}

	/**
	 * Batch size used by the background worker.
	 *
	 * @return int
	 */
	public function batch_size() {
		$batch = (int) $this->get( 'batch_size' );

		if ( ! in_array( $batch, self::allowed_batch_sizes(), true ) ) {
			$batch = 10;
		}

		/**
		 * Filters the number of products processed per worker batch.
		 *
		 * @param int $batch Batch size.
		 */
		$batch = (int) apply_filters( 'usdtf_batch_size', $batch );

		return max( 1, min( 50, $batch ) );
	}

	/**
	 * Seconds a single worker run may use before handing over to the next run.
	 *
	 * @return float
	 */
	public function time_budget() {
		$configured = (int) $this->get( 'time_budget' );
		$limit      = (int) ini_get( 'max_execution_time' );

		if ( $limit > 0 ) {
			// Never use more than 60% of the PHP execution limit.
			$configured = (int) min( $configured, max( 5, (int) floor( $limit * 0.6 ) ) );
		}

		/**
		 * Filters the per-run time budget in seconds.
		 *
		 * @param int $budget Time budget.
		 */
		return max( 3, (float) apply_filters( 'usdtf_time_budget', $configured ) );
	}

	/**
	 * Rounding options used by the calculator. The increment falls back to the
	 * default of the store unit when the stored one is not allowed for it, and
	 * the decimals are the WooCommerce price decimals.
	 *
	 * @return array{rounding:string,increment:float,decimals:int}
	 */
	public function rounding_args() {
		$increment = (float) $this->get( 'increment' );

		if ( ! in_array( $increment, self::allowed_increments(), true ) ) {
			$increment = Calculator::default_increment( Store_Currency::unit() );
		}

		return array(
			'rounding'  => (string) $this->get( 'rounding' ),
			'increment' => $increment,
			'decimals'  => wc_get_price_decimals(),
		);
	}
}
