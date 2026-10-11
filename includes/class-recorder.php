<?php
/**
 * The single place where a product price is written.
 *
 * Everything goes through the WooCommerce CRUD API so lookup tables, caches,
 * sale state, range prices, Woo Blocks and the Store API stay consistent.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one product or variation pegged to its USD anchor.
 */
final class Recorder {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Pricing model.
	 *
	 * @var Product_Pricing
	 */
	private $pricing;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param Product_Pricing $pricing  Pricing model.
	 */
	public function __construct( Settings $settings, Product_Pricing $pricing ) {
		$this->settings = $settings;
		$this->pricing  = $pricing;
	}

	/**
	 * Plan and apply the pegged price of a product.
	 *
	 * The regular and the sale price are planned independently from the Woo
	 * price, the seen price, the USD anchor and the rates. Only a field whose
	 * plan is an update is written to WooCommerce; every other outcome stores
	 * the plugin meta only. Nothing is written when the result is a skip, a
	 * failure or a conflict, or when this is a dry run.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param array       $args {
	 *     Optional. Arguments.
	 *
	 *     @type bool  $dry_run     Do not write anything. Default false.
	 *     @type float $rate        Rate to apply.
	 *     @type array $rounding    Keys rounding, increment and decimals. Defaults to the settings.
	 *     @type int   $job_id      Job that owns this write.
	 *     @type bool  $sync_parent Recalculate the variable parent right away.
	 * }
	 * @return array {
	 *     What happened.
	 *
	 *     @type string $status      One of the Job_Repository::ITEM_ statuses.
	 *     @type string $code        Machine readable reason.
	 *     @type string $message     Human readable explanation.
	 *     @type array  $old         Woo prices before: regular, sale (string or null).
	 *     @type array  $new         Woo prices after: regular, sale (string or null).
	 *     @type array  $usd         USD anchors after: regular, sale (string or null).
	 *     @type bool   $wrote       Whether the WooCommerce product was saved.
	 *     @type int    $parent_id   Variable parent ID, 0 for a product.
	 *     @type string $object_type product or variation.
	 * }
	 */
	public function apply( $product, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'     => false,
				'rate'        => 0.0,
				'rounding'    => array(),
				'job_id'      => 0,
				'sync_parent' => false,
			)
		);

		$rounding = $args['rounding'] ? (array) $args['rounding'] : $this->settings->rounding_args();
		$decimals = isset( $rounding['decimals'] ) ? (int) $rounding['decimals'] : 0;

		$result = array(
			'status'      => Job_Repository::ITEM_SKIPPED,
			'code'        => 'ok',
			'message'     => '',
			'old'         => self::pair( '', '' ),
			'new'         => self::pair( '', '' ),
			'usd'         => self::pair( '', '' ),
			'wrote'       => false,
			'parent_id'   => 0,
			'object_type' => 'product',
		);

		$snapshot = $product instanceof \WC_Product ? $this->pricing->get_snapshot( $product ) : array();

		if ( ! $snapshot ) {
			$result['code']    = 'missing';
			$result['message'] = __( 'The product could not be loaded.', 'usd-to-toman-price-sync-for-woocommerce' );

			return $result;
		}

		$product_id = (int) $snapshot['id'];

		$result['old']         = self::pair( $snapshot['current_regular'], $snapshot['current_sale'] );
		$result['new']         = $result['old'];
		$result['parent_id']   = (int) $snapshot['parent_id'];
		$result['object_type'] = (string) $snapshot['object_type'];

		if ( ! $snapshot['managed'] ) {
			$result['code']    = 'not_managed';
			$result['message'] = __( 'This product is excluded from USD pricing, so it was skipped.', 'usd-to-toman-price-sync-for-woocommerce' );

			return $result;
		}

		if ( ! Store_Currency::is_supported() ) {
			$result['code']    = 'unsupported_currency';
			$result['message'] = __( 'The store currency is not Iranian Toman or Rial, so no price was calculated.', 'usd-to-toman-price-sync-for-woocommerce' );

			return $result;
		}

		$plans = array(
			'regular' => Price_Planner::plan( $snapshot['current_regular'], $snapshot['seen_regular'], $snapshot['usd_regular'], $snapshot['rate'], $args['rate'], $rounding ),
			'sale'    => Price_Planner::plan( $snapshot['current_sale'], $snapshot['seen_sale'], $snapshot['usd_sale'], $snapshot['rate'], $args['rate'], $rounding ),
		);

		foreach ( $plans as $field => $plan ) {
			// A product without a sale price is normal: only the regular price must exist.
			if ( Price_Planner::ACTION_SKIP === $plan['action'] && ( 'regular' === $field || 'no_price' !== $plan['code'] ) ) {
				$result['code']    = $plan['code'];
				$result['message'] = $this->skip_message( $field, $plan['code'] );

				return $result;
			}
		}

		$result['new']    = self::pair( $plans['regular']['price'], $plans['sale']['price'] );
		$result['usd']    = self::pair( $plans['regular']['usd'], $plans['sale']['usd'] );
		$result['status'] = self::status_for( $plans );

		$failure = self::validate( $plans, $result['new'] );

		if ( '' !== $failure ) {
			$result['status']  = Job_Repository::ITEM_FAILED;
			$result['code']    = $failure;
			$result['message'] = 'zero_price' === $failure
				? __( 'The new price would round to zero, so nothing was written. Choose a smaller rounding increment or check the USD price.', 'usd-to-toman-price-sync-for-woocommerce' )
				: __( 'The sale price cannot be higher than the regular price.', 'usd-to-toman-price-sync-for-woocommerce' );

			if ( ! $args['dry_run'] ) {
				$this->pricing->mark_error( $product_id, $result['message'] );
			}

			return $result;
		}

		/**
		 * Fires once per product just before its Woo prices are read again to
		 * detect an edit made while the product was being planned.
		 *
		 * @param int $product_id Product or variation ID.
		 */
		do_action( 'usdtf_before_price_write', $product_id );

		$fresh = wc_get_product( $product_id );

		if ( ! $fresh
			|| ! Calculator::prices_equal( (string) $fresh->get_regular_price( 'edit' ), $snapshot['current_regular'], $decimals )
			|| ! Calculator::prices_equal( (string) $fresh->get_sale_price( 'edit' ), $snapshot['current_sale'], $decimals ) ) {
			$result['status']  = Job_Repository::ITEM_CONFLICT;
			$result['code']    = 'conflict';
			$result['message'] = __( 'The price was edited in WooCommerce while it was being synchronized, so nothing was written. The next update re-bases the USD price on the edited price.', 'usd-to-toman-price-sync-for-woocommerce' );

			if ( ! $args['dry_run'] ) {
				$this->pricing->mark_conflict( $product_id, $result['message'] );
			}

			return $result;
		}

		$result['message'] = $this->status_message( $plans, $snapshot, $decimals, (bool) $args['dry_run'] );

		if ( $args['dry_run'] ) {
			return $result;
		}

		$updates = array();

		foreach ( $plans as $field => $plan ) {
			if ( Price_Planner::ACTION_UPDATE === $plan['action'] ) {
				$updates[ $field ] = $plan['price'];
			}
		}

		if ( $updates ) {
			try {
				$this->write_price( $fresh, $updates );
			} catch ( \Throwable $exception ) {
				$result['status']  = Job_Repository::ITEM_FAILED;
				$result['code']    = 'exception';
				$result['message'] = sprintf(
					/* translators: %s: error message. */
					__( 'WooCommerce could not save the price: %s', 'usd-to-toman-price-sync-for-woocommerce' ),
					$exception->getMessage()
				);

				$this->pricing->mark_error( $product_id, $result['message'] );

				return $result;
			}

			$result['wrote'] = true;
		}

		$this->record( $product_id, $plans, $args );

		if ( $result['wrote'] && $args['sync_parent'] && 'variation' === $snapshot['object_type'] && (int) $snapshot['parent_id'] > 0 ) {
			$this->sync_parent( (int) $snapshot['parent_id'] );
		}

		return $result;
	}

	/**
	 * Item status for a pair of field plans.
	 *
	 * @param array $plans Plans keyed by field.
	 * @return string
	 */
	private static function status_for( array $plans ) {
		$actions = wp_list_pluck( $plans, 'action' );

		if ( in_array( Price_Planner::ACTION_UPDATE, $actions, true ) ) {
			return Job_Repository::ITEM_CHANGED;
		}

		if ( in_array( Price_Planner::ACTION_GENERATE, $actions, true ) ) {
			return Job_Repository::ITEM_GENERATED;
		}

		if ( in_array( Price_Planner::ACTION_REBASE, $actions, true ) || in_array( Price_Planner::ACTION_CLEAR, $actions, true ) ) {
			return Job_Repository::ITEM_REBASED;
		}

		return Job_Repository::ITEM_UNCHANGED;
	}

	/**
	 * Refuse a plan that must not be written.
	 *
	 * @param array $plans Plans keyed by field.
	 * @param array $after Woo prices after the plan: regular, sale.
	 * @return string Failure code, empty when the plan is valid.
	 */
	private static function validate( array $plans, array $after ) {
		foreach ( $plans as $plan ) {
			if ( Price_Planner::ACTION_UPDATE === $plan['action'] && (float) $plan['price'] <= 0 ) {
				return 'zero_price';
			}
		}

		if ( null !== $after['regular'] && null !== $after['sale'] && (float) $after['sale'] > (float) $after['regular'] ) {
			return 'sale_above_regular';
		}

		return '';
	}

	/**
	 * Write the WooCommerce price fields that changed.
	 *
	 * @param \WC_Product $product Product, freshly loaded.
	 * @param array       $updates New price strings keyed by field (regular, sale).
	 * @return void
	 */
	private function write_price( $product, array $updates ) {
		Product_Pricing::without_hooks(
			function () use ( $product, $updates ) {
				if ( isset( $updates['regular'] ) ) {
					$product->set_regular_price( $updates['regular'] );
				}

				if ( isset( $updates['sale'] ) ) {
					$product->set_sale_price( $updates['sale'] );
				}

				$product->save();
			}
		);
	}

	/**
	 * Store the planned anchors, seen prices and rate, and clear old markers.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $plans      Plans keyed by field.
	 * @param array $args       Apply arguments.
	 * @return void
	 */
	private function record( $product_id, array $plans, array $args ) {
		$this->pricing->save_state(
			$product_id,
			array(
				'usd_regular'  => $plans['regular']['usd'],
				'usd_sale'     => $plans['sale']['usd'],
				'seen_regular' => $plans['regular']['seen'],
				'seen_sale'    => $plans['sale']['seen'],
				'rate'         => (float) $args['rate'],
				'synced_at'    => time(),
			)
		);

		$this->pricing->clear_conflict( $product_id );
		$this->pricing->clear_error( $product_id );

		$job_id = (int) $args['job_id'];

		Product_Pricing::without_hooks(
			function () use ( $product_id, $job_id ) {
				if ( $job_id > 0 ) {
					update_post_meta( $product_id, Product_Pricing::META_LAST_JOB, $job_id );
				} else {
					delete_post_meta( $product_id, Product_Pricing::META_LAST_JOB );
				}
			}
		);
	}

	/**
	 * Recalculate the price range of a variable parent.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return void
	 */
	public function sync_parent( $parent_id ) {
		$parent_id = (int) $parent_id;

		if ( $parent_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$parent = wc_get_product( $parent_id );

		if ( ! $parent || ! $parent->is_type( 'variable' ) || ! class_exists( '\WC_Product_Variable' ) ) {
			return;
		}

		Product_Pricing::without_hooks(
			function () use ( $parent_id ) {
				\WC_Product_Variable::sync( $parent_id, true );
			}
		);
	}

	/**
	 * Price pair with empty strings as null.
	 *
	 * @param string $regular Regular price.
	 * @param string $sale    Sale price.
	 * @return array{regular:string|null,sale:string|null}
	 */
	private static function pair( $regular, $sale ) {
		return array(
			'regular' => '' === (string) $regular ? null : (string) $regular,
			'sale'    => '' === (string) $sale ? null : (string) $sale,
		);
	}

	/**
	 * Translated name of a price field.
	 *
	 * @param string $field regular or sale.
	 * @return string
	 */
	private static function field_label( $field ) {
		return 'sale' === $field
			? __( 'Sale price', 'usd-to-toman-price-sync-for-woocommerce' )
			: __( 'Regular price', 'usd-to-toman-price-sync-for-woocommerce' );
	}

	/**
	 * Message for a skipped product.
	 *
	 * @param string $field Field that could not be planned.
	 * @param string $code  Planner skip code.
	 * @return string
	 */
	private function skip_message( $field, $code ) {
		switch ( $code ) {
			case 'no_rate':
				$message = __( 'No exchange rate is saved, so no price could be calculated.', 'usd-to-toman-price-sync-for-woocommerce' );
				break;
			case 'no_price':
				$message = __( 'There is no price to peg to USD.', 'usd-to-toman-price-sync-for-woocommerce' );
				break;
			case 'too_low':
				$message = __( 'The price is worth less than one US cent at this rate, so no USD price can be stored.', 'usd-to-toman-price-sync-for-woocommerce' );
				break;
			case 'invalid_price':
			default:
				$message = __( 'The price is zero, negative or not a number, so it cannot be converted into a USD price.', 'usd-to-toman-price-sync-for-woocommerce' );
				break;
		}

		return sprintf(
			/* translators: 1: price field (regular or sale price), 2: reason. */
			__( '%1$s: %2$s', 'usd-to-toman-price-sync-for-woocommerce' ),
			self::field_label( $field ),
			$message
		);
	}

	/**
	 * Message for a planned product: one sentence per field that moves.
	 *
	 * @param array $plans    Plans keyed by field.
	 * @param array $snapshot Product snapshot.
	 * @param int   $decimals Woo price decimals.
	 * @param bool  $dry_run  Whether this is a dry run.
	 * @return string
	 */
	private function status_message( array $plans, array $snapshot, $decimals, $dry_run ) {
		$parts = array();

		foreach ( $plans as $field => $plan ) {
			$label = self::field_label( $field );

			switch ( $plan['action'] ) {
				case Price_Planner::ACTION_UPDATE:
					$parts[] = sprintf(
						/* translators: 1: price field, 2: old price, 3: new price, 4: USD price. */
						__( '%1$s: %2$s to %3$s (%4$s USD).', 'usd-to-toman-price-sync-for-woocommerce' ),
						$label,
						Calculator::format_price( $snapshot[ 'current_' . $field ], $decimals ),
						Calculator::format_price( $plan['price'], $decimals ),
						Calculator::format_price( $plan['usd'], 2 )
					);
					break;
				case Price_Planner::ACTION_GENERATE:
					$parts[] = sprintf(
						/* translators: 1: price field, 2: USD price. */
						__( '%1$s: USD price set to %2$s.', 'usd-to-toman-price-sync-for-woocommerce' ),
						$label,
						Calculator::format_price( $plan['usd'], 2 )
					);
					break;
				case Price_Planner::ACTION_REBASE:
					$parts[] = sprintf(
						/* translators: 1: price field, 2: USD price. */
						__( '%1$s: edited by hand, USD price re-based to %2$s.', 'usd-to-toman-price-sync-for-woocommerce' ),
						$label,
						Calculator::format_price( $plan['usd'], 2 )
					);
					break;
				case Price_Planner::ACTION_CLEAR:
					$parts[] = sprintf(
						/* translators: %s: price field. */
						__( '%s: removed, USD price cleared.', 'usd-to-toman-price-sync-for-woocommerce' ),
						$label
					);
					break;
			}
		}

		if ( ! $parts ) {
			$parts[] = __( 'The price already matches its USD price at this rate.', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		if ( $dry_run ) {
			array_unshift( $parts, __( 'Dry run, nothing was written.', 'usd-to-toman-price-sync-for-woocommerce' ) );

			if ( $snapshot['date_on_sale_from'] || $snapshot['date_on_sale_to'] ) {
				$parts[] = __( 'This product has a scheduled sale; the schedule itself is kept.', 'usd-to-toman-price-sync-for-woocommerce' );
			}
		}

		return implode( ' ', $parts );
	}
}
