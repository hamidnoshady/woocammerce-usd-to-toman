<?php
/**
 * Price planner.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what to do with one price field (regular or sale) of one product.
 *
 * The planner is pure: it reads nothing and writes nothing. It takes the
 * WooCommerce price now (current), the price this plugin last wrote or accepted
 * (seen), the stored USD anchor and the rates, and returns the action together
 * with the USD anchor, price and seen value to store afterwards. Empty strings
 * mean "none".
 */
final class Price_Planner {

	/**
	 * The field has a plain price: derive the USD anchor from it.
	 */
	const ACTION_GENERATE = 'generate';

	/**
	 * The price was edited by hand: derive a new USD anchor from it.
	 */
	const ACTION_REBASE = 'rebase';

	/**
	 * The rate moved: derive a new price from the USD anchor.
	 */
	const ACTION_UPDATE = 'update';

	/**
	 * The price was removed: forget the USD anchor.
	 */
	const ACTION_CLEAR = 'clear';

	/**
	 * Nothing moved: keep price and anchor.
	 */
	const ACTION_UNCHANGED = 'unchanged';

	/**
	 * The field cannot be planned; the code says why and nothing is stored.
	 */
	const ACTION_SKIP = 'skip';

	/**
	 * Smallest USD anchor that can be stored.
	 */
	const MIN_USD = 0.01;

	/**
	 * Plan one price field.
	 *
	 * @param string $current     Woo price now, '' when none.
	 * @param string $seen        Price the plugin last wrote or accepted, '' when none.
	 * @param string $usd         Stored USD anchor, '' when none.
	 * @param float  $stored_rate Rate the stored price was derived with.
	 * @param float  $rate        Rate to apply now.
	 * @param array  $rounding    Keys rounding, increment and decimals (the Woo price decimals).
	 * @return array {
	 *     The decision.
	 *
	 *     @type string $action One of the ACTION_ constants.
	 *     @type string $code   Reason for a skip (no_rate, no_price, invalid_price, too_low), otherwise ''.
	 *     @type string $usd    USD anchor to store, 2 decimals, '' when none.
	 *     @type string $price  Woo price after the action; equal to $current unless updated.
	 *     @type string $seen   Seen price to store; equal to $price for every action but skip.
	 * }
	 */
	public static function plan( $current, $seen, $usd, $stored_rate, $rate, array $rounding ) {
		$current  = (string) $current;
		$seen     = (string) $seen;
		$usd      = (string) $usd;
		$rate     = (float) $rate;
		$decimals = isset( $rounding['decimals'] ) ? (int) $rounding['decimals'] : 0;

		if ( $rate <= 0 ) {
			return self::skip( 'no_rate', $current, $seen, $usd );
		}

		if ( '' === $current ) {
			if ( '' === $usd && '' === $seen ) {
				return self::skip( 'no_price', $current, $seen, $usd );
			}

			return self::result( self::ACTION_CLEAR, '', '', '' );
		}

		if ( ! is_numeric( $current ) || ! is_finite( (float) $current ) || (float) $current <= 0 ) {
			return self::skip( 'invalid_price', $current, $seen, $usd );
		}

		$price = Calculator::to_price_string( (float) $current, $decimals );

		if ( '' === $usd ) {
			return self::anchor( self::ACTION_GENERATE, $current, $price, $rate, $seen, $usd );
		}

		if ( ! Calculator::prices_equal( $current, $seen, $decimals ) ) {
			return self::anchor( self::ACTION_REBASE, $current, $price, $rate, $seen, $usd );
		}

		$usd_string = self::usd_string( (float) $usd );

		if ( self::rates_equal( $stored_rate, $rate ) ) {
			return self::result( self::ACTION_UNCHANGED, $usd_string, $price, $price );
		}

		$new_price = Calculator::price_from_usd( (float) $usd, $rate, $rounding );

		if ( null === $new_price ) {
			// A stored anchor below one cent cannot be priced; nothing is written.
			return self::skip( 'too_low', $current, $seen, $usd );
		}

		$new_price = Calculator::to_price_string( $new_price, $decimals );

		// A new rate that rounds to the price the product already has writes
		// nothing: the product is unchanged and only records the rate.
		if ( Calculator::prices_equal( $new_price, $current, $decimals ) ) {
			return self::result( self::ACTION_UNCHANGED, $usd_string, $price, $price );
		}

		return self::result( self::ACTION_UPDATE, $usd_string, $new_price, $new_price );
	}

	/**
	 * Plan a generate or rebase: the new USD anchor comes from the current price.
	 *
	 * @param string $action  ACTION_GENERATE or ACTION_REBASE.
	 * @param string $current Woo price now.
	 * @param string $price   Woo price now as a price string.
	 * @param float  $rate    Rate to apply.
	 * @param string $seen    Seen price, echoed on a skip.
	 * @param string $usd     Stored USD anchor, echoed on a skip.
	 * @return array
	 */
	private static function anchor( $action, $current, $price, $rate, $seen, $usd ) {
		$new_usd = Calculator::usd_from_price( (float) $current, $rate );

		if ( null === $new_usd || $new_usd < self::MIN_USD ) {
			return self::skip( 'too_low', $current, $seen, $usd );
		}

		return self::result( $action, self::usd_string( $new_usd ), $price, $price );
	}

	/**
	 * Whether two rates are the same at 6 decimals.
	 *
	 * @param float $stored_rate Stored rate.
	 * @param float $rate        Rate now.
	 * @return bool
	 */
	private static function rates_equal( $stored_rate, $rate ) {
		return number_format( (float) $stored_rate, 6, '.', '' ) === number_format( (float) $rate, 6, '.', '' );
	}

	/**
	 * Format a USD anchor with exactly 2 decimals.
	 *
	 * @param float $usd USD amount.
	 * @return string
	 */
	private static function usd_string( $usd ) {
		return number_format( round( $usd, 2 ), 2, '.', '' );
	}

	/**
	 * Build a decision.
	 *
	 * @param string $action Action.
	 * @param string $usd    USD anchor to store.
	 * @param string $price  Price after the action.
	 * @param string $seen   Seen price to store.
	 * @return array
	 */
	private static function result( $action, $usd, $price, $seen ) {
		return array(
			'action' => $action,
			'code'   => '',
			'usd'    => $usd,
			'price'  => $price,
			'seen'   => $seen,
		);
	}

	/**
	 * Build a skip: the inputs are echoed so a caller that stores them changes nothing.
	 *
	 * @param string $code    Reason.
	 * @param string $current Woo price now.
	 * @param string $seen    Seen price.
	 * @param string $usd     Stored USD anchor.
	 * @return array
	 */
	private static function skip( $code, $current, $seen, $usd ) {
		return array(
			'action' => self::ACTION_SKIP,
			'code'   => $code,
			'usd'    => $usd,
			'price'  => $current,
			'seen'   => $seen,
		);
	}
}
