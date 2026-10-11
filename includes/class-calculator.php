<?php
/**
 * Price math, parsing and formatting helpers.
 *
 * The calculator is intentionally free of WordPress and WooCommerce side
 * effects so it can be unit tested in isolation.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Pure price math for the USD/Toman conversion.
 */
final class Calculator {

	/**
	 * Always round up to the next increment (plugin default).
	 */
	const ROUND_UP = 'up';

	/**
	 * Round to the nearest increment.
	 */
	const ROUND_NEAREST = 'nearest';

	/**
	 * Round down to the previous increment.
	 */
	const ROUND_DOWN = 'down';

	/**
	 * Supported rounding modes.
	 *
	 * @return string[]
	 */
	public static function rounding_modes() {
		return array( self::ROUND_UP, self::ROUND_NEAREST, self::ROUND_DOWN );
	}

	/**
	 * Convert Persian and Arabic-Indic digits to ASCII digits.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_digits( $value ) {
		$value = (string) $value;

		$map = array(
			'۰' => '0',
			'۱' => '1',
			'۲' => '2',
			'۳' => '3',
			'۴' => '4',
			'۵' => '5',
			'۶' => '6',
			'۷' => '7',
			'۸' => '8',
			'۹' => '9',
			'٠' => '0',
			'١' => '1',
			'٢' => '2',
			'٣' => '3',
			'٤' => '4',
			'٥' => '5',
			'٦' => '6',
			'٧' => '7',
			'٨' => '8',
			'٩' => '9',
			'٫' => '.',
			'،' => ',',
			'٬' => ',',
		);

		return strtr( $value, $map );
	}

	/**
	 * Normalize a human supplied number into a plain numeric string.
	 *
	 * Thousands separators and currency words are stripped, Persian and Arabic
	 * digits are translated, but no rounding or clamping happens here. This is
	 * what protects the exchange rate field from malformed input.
	 *
	 * @param mixed $raw Raw admin input.
	 * @return string Plain numeric string, or an empty string when nothing numeric is left.
	 */
	public static function normalize_number( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (string) $raw;
		}

		if ( ! is_string( $raw ) ) {
			return '';
		}

		$value = self::normalize_digits( $raw );
		$value = str_replace( array( "\xc2\xa0", "\xe2\x80\x8c", ' ', "\t", "\n", "\r" ), '', $value );

		// Drop currency words/labels that admins sometimes paste in.
		$words = array( 'تومان', 'تومن', 'ریال', 'دلار', 'دالر', 'toman', 'tuman', 'tomens', 'rial', 'rials', 'usd', '$', '﷼', "'" );
		$value = str_ireplace( $words, '', $value );

		// Commas (and Arabic thousands separators) are group separators, never decimals.
		$value = str_replace( ',', '', $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Parse an exchange rate (Toman per 1 USD).
	 *
	 * @param mixed $raw Raw value.
	 * @return array{code:string,value:float|null}
	 */
	public static function parse_rate( $raw ) {
		$normalized = self::normalize_number( $raw );

		if ( '' === $normalized ) {
			return array(
				'code'  => 'empty',
				'value' => null,
			);
		}

		$value = (float) $normalized;

		if ( $value < 0 ) {
			return array(
				'code'  => 'negative',
				'value' => $value,
			);
		}

		if ( 0.0 === $value ) {
			return array(
				'code'  => 'zero',
				'value' => 0.0,
			);
		}

		// Anything outside of 1 .. 100,000,000 Toman per USD is a typo.
		if ( $value < 1 || $value > 100000000 ) {
			return array(
				'code'  => 'out_of_range',
				'value' => $value,
			);
		}

		return array(
			'code'  => 'ok',
			'value' => $value,
		);
	}

	/**
	 * Round a value to the configured mode and increment.
	 *
	 * @param float  $value     Value to round.
	 * @param string $rounding  One of the ROUND_* constants.
	 * @param float  $increment Increment (1, 5, 10, ...).
	 * @return float
	 */
	public static function round_amount( $value, $rounding = self::ROUND_UP, $increment = 1 ) {
		$value     = (float) $value;
		$increment = (float) $increment;

		if ( $increment <= 0 ) {
			$increment = 1.0;
		}

		$steps = $value / $increment;

		switch ( $rounding ) {
			case self::ROUND_NEAREST:
				$rounded = floor( $steps + 0.5 );
				break;
			case self::ROUND_DOWN:
				$rounded = floor( $steps + 1.0e-9 );
				break;
			case self::ROUND_UP:
			default:
				$rounded = ceil( $steps - 1.0e-9 );
				break;
		}

		return (float) ( $rounded * $increment );
	}

	/**
	 * Derive the USD anchor from a store price.
	 *
	 * @param float|int|string $price Store price in the store currency.
	 * @param float|int|string $rate  Store currency units per 1 USD.
	 * @return float|null USD rounded half up to 2 decimals, null when not computable.
	 */
	public static function usd_from_price( $price, $rate ) {
		$price = (float) $price;
		$rate  = (float) $rate;

		if ( $price <= 0 || $rate <= 0 ) {
			return null;
		}

		return (float) round( $price / $rate, 2 );
	}

	/**
	 * Derive a store price from a USD anchor.
	 *
	 * @param float|int|string $usd  USD anchor.
	 * @param float|int|string $rate Store currency units per 1 USD.
	 * @param array            $args {
	 *     Optional. Conversion options.
	 *
	 *     @type string $rounding  Rounding mode. Default ROUND_UP.
	 *     @type float  $increment Rounding increment. Default 1.
	 *     @type int    $decimals  Stored decimal places. Default 0.
	 * }
	 * @return float|null Null when the conversion is not possible.
	 */
	public static function price_from_usd( $usd, $rate, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'rounding'  => self::ROUND_UP,
				'increment' => 1.0,
				'decimals'  => 0,
			)
		);

		$usd  = (float) $usd;
		$rate = (float) $rate;

		if ( $usd <= 0 || $rate <= 0 ) {
			return null;
		}

		$price = self::round_amount( $usd * $rate, $args['rounding'], $args['increment'] );

		return self::trim_to_decimals( $price, (int) $args['decimals'] );
	}

	/**
	 * Rounding increments offered for a store currency unit.
	 *
	 * @param string $unit Store currency unit, 'toman' or 'rial'.
	 * @return float[]
	 */
	public static function increments_for_unit( $unit ) {
		if ( 'rial' === $unit ) {
			return array( 10.0, 1000.0, 10000.0, 100000.0 );
		}

		return array( 1.0, 100.0, 1000.0, 10000.0 );
	}

	/**
	 * Default rounding increment for a store currency unit.
	 *
	 * @param string $unit Store currency unit, 'toman' or 'rial'.
	 * @return float
	 */
	public static function default_increment( $unit ) {
		return 'rial' === $unit ? 10000.0 : 1000.0;
	}

	/**
	 * Format a store price or USD amount for display with locale separators.
	 *
	 * @param float|int|string $amount   Amount.
	 * @param int              $decimals Decimal places.
	 * @return string
	 */
	public static function format_price( $amount, $decimals = 0 ) {
		return number_format_i18n( (float) $amount, max( 0, min( 6, (int) $decimals ) ) );
	}

	/**
	 * Round a value to a fixed number of decimals.
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Decimal places.
	 * @return float
	 */
	public static function trim_to_decimals( $value, $decimals = 0 ) {
		$decimals = max( 0, min( 6, (int) $decimals ) );

		return (float) round( (float) $value, $decimals );
	}

	/**
	 * Format a price for storage as a WooCommerce price string.
	 *
	 * @param float|null $value    Price value.
	 * @param int        $decimals Decimal places.
	 * @return string Empty string when there is no price.
	 */
	public static function to_price_string( $value, $decimals = 0 ) {
		if ( null === $value ) {
			return '';
		}

		$decimals = max( 0, min( 6, (int) $decimals ) );
		$value    = self::trim_to_decimals( $value, $decimals );

		return number_format( $value, $decimals, '.', '' );
	}

	/**
	 * Compare two price strings/numbers using the configured decimal precision.
	 *
	 * @param mixed $a        First value.
	 * @param mixed $b        Second value.
	 * @param int   $decimals Decimal places considered meaningful.
	 * @return bool True when both represent the same price.
	 */
	public static function prices_equal( $a, $b, $decimals = 0 ) {
		$a = ( '' === $a || null === $a ) ? null : (float) $a;
		$b = ( '' === $b || null === $b ) ? null : (float) $b;

		if ( null === $a && null === $b ) {
			return true;
		}

		if ( null === $a || null === $b ) {
			return false;
		}

		return self::to_price_string( $a, $decimals ) === self::to_price_string( $b, $decimals );
	}

	/**
	 * Percentage change between two rates.
	 *
	 * @param float|int|string $old     Old value.
	 * @param float|int|string $updated New value.
	 * @return float|null Signed percentage (e.g. -90.0), null when not computable.
	 */
	public static function percent_change( $old, $updated ) {
		$old     = (float) $old;
		$updated = (float) $updated;

		if ( $old <= 0 ) {
			return null;
		}

		return ( ( $updated - $old ) / $old ) * 100;
	}
}
