<?php
/**
 * Exchange rate validation and typo protection.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Guards the manual USD/Toman rate against typos and malformed input.
 */
final class Rate_Guard {

	/**
	 * Rate is missing.
	 */
	const CODE_EMPTY = 'empty';

	/**
	 * Rate is not a number.
	 */
	const CODE_NON_NUMERIC = 'non_numeric';

	/**
	 * Rate is zero.
	 */
	const CODE_ZERO = 'zero';

	/**
	 * Rate is negative.
	 */
	const CODE_NEGATIVE = 'negative';

	/**
	 * Rate is outside of a realistic range.
	 */
	const CODE_OUT_OF_RANGE = 'out_of_range';

	/**
	 * Rate equals the currently stored rate.
	 */
	const CODE_UNCHANGED = 'unchanged';

	/**
	 * Rate moved more than the configured threshold.
	 */
	const CODE_LARGE_CHANGE = 'large_change';

	/**
	 * Rate is acceptable.
	 */
	const CODE_OK = 'ok';

	/**
	 * Assess a candidate rate against the currently stored rate.
	 *
	 * @param mixed $raw       Raw admin input.
	 * @param float $current   Currently stored rate, 0 when none.
	 * @param float $threshold Percentage threshold that requires confirmation.
	 * @return array {
	 *     Assessment result.
	 *
	 *     @type bool       $ok                    True when the rate can be stored without confirmation.
	 *     @type bool       $valid                 True when the rate is syntactically and logically valid.
	 *     @type bool       $requires_confirmation True when the admin must confirm a large move.
	 *     @type string     $code                  Machine readable result code.
	 *     @type float|null $rate                  Parsed rate.
	 *     @type float|null $change_percent        Signed percentage change.
	 *     @type string     $message               Human readable explanation.
	 * }
	 */
	public static function assess( $raw, $current = 0, $threshold = 15 ) {
		$parsed   = Calculator::parse_rate( $raw );
		$current  = (float) $current;
		$threshold = (float) $threshold;

		if ( in_array( $parsed['code'], array( 'empty', 'zero', 'negative', 'out_of_range' ), true ) ) {
			$code_map = array(
				'empty'        => self::CODE_EMPTY,
				'zero'         => self::CODE_ZERO,
				'negative'     => self::CODE_NEGATIVE,
				'out_of_range' => self::CODE_OUT_OF_RANGE,
			);

			return array(
				'ok'                    => false,
				'valid'                 => false,
				'requires_confirmation' => false,
				'code'                  => $code_map[ $parsed['code'] ],
				'rate'                  => null,
				'change_percent'        => null,
				'message'               => self::message_for( $code_map[ $parsed['code'] ] ),
			);
		}

		$rate   = (float) $parsed['value'];
		$change = $current > 0 ? Calculator::percent_change( $current, $rate ) : null;

		if ( null !== $change && abs( $change ) < 0.0000001 ) {
			return array(
				'ok'                    => false,
				'valid'                 => true,
				'requires_confirmation' => false,
				'code'                  => self::CODE_UNCHANGED,
				'rate'                  => $rate,
				'change_percent'        => 0.0,
				'message'               => self::message_for( self::CODE_UNCHANGED ),
			);
		}

		if ( null !== $change && $threshold > 0 && abs( $change ) > $threshold ) {
			return array(
				'ok'                    => false,
				'valid'                 => true,
				'requires_confirmation' => true,
				'code'                  => self::CODE_LARGE_CHANGE,
				'rate'                  => $rate,
				'change_percent'        => $change,
				'message'               => self::message_for( self::CODE_LARGE_CHANGE ),
			);
		}

		return array(
			'ok'                    => true,
			'valid'                 => true,
			'requires_confirmation' => false,
			'code'                  => self::CODE_OK,
			'rate'                  => $rate,
			'change_percent'        => $change,
			'message'               => self::message_for( self::CODE_OK ),
		);
	}

	/**
	 * Human readable message for a result code.
	 *
	 * @param string $code Result code.
	 * @return string
	 */
	public static function message_for( $code ) {
		switch ( $code ) {
			case self::CODE_EMPTY:
				return __( 'Enter the exchange rate: how many Toman one US dollar costs.', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::CODE_NON_NUMERIC:
			case self::CODE_ZERO:
			case self::CODE_NEGATIVE:
			case self::CODE_OUT_OF_RANGE:
				return __( 'The exchange rate must be a positive number such as 270000.', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::CODE_UNCHANGED:
				return __( 'This is the rate that is already stored, so nothing was changed.', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::CODE_LARGE_CHANGE:
				return __( 'This rate is a very large move. Confirm it explicitly before it is stored.', 'usd-to-toman-price-sync-for-woocommerce' );
			case self::CODE_OK:
			default:
				return __( 'The rate is valid.', 'usd-to-toman-price-sync-for-woocommerce' );
		}
	}

	/**
	 * The confirmation phrase the admin must type for a large rate move.
	 *
	 * @return string
	 */
	public static function confirmation_phrase() {
		return 'UPDATE';
	}

	/**
	 * Check whether a confirmation phrase matches.
	 *
	 * @param string $typed Value typed by the admin.
	 * @return bool
	 */
	public static function confirm_phrase_matches( $typed ) {
		return self::confirmation_phrase() === strtoupper( trim( (string) $typed ) );
	}
}
