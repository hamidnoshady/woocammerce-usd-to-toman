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
 * Applies a calculated price to a product or variation.
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
	 * Apply a price to a product.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param array       $source  Canonical Toman prices: regular, sale.
	 * @param array       $target  Derived USD prices: regular, sale.
	 * @param array       $args {
	 *     Optional. Arguments.
	 *
	 *     @type bool       $dry_run           Do not write anything. Default false.
	 *     @type float      $rate              Rate used for the conversion.
	 *     @type string     $currency_mode     Currency mode the price fields hold.
	 *     @type int        $job_id            Job that owns this write.
	 *     @type int|null   $expected_revision Source revision captured during discovery.
	 *     @type bool       $sync_parent       Recalculate the variable parent right away.
	 *     @type bool       $refresh_rate_meta Update the rate marker when nothing else changes.
	 * }
	 * @return array Result describing what happened.
	 */
	public function apply( $product, array $source, array $target, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'           => false,
				'rate'              => 0.0,
				'currency_mode'     => (string) $this->settings->get( 'currency_mode' ),
				'job_id'            => 0,
				'expected_revision' => null,
				'sync_parent'       => false,
				'refresh_rate_meta' => true,
			)
		);

		$result = array(
			'status'      => Job_Repository::ITEM_SKIPPED,
			'code'        => 'ok',
			'message'     => '',
			'old'         => array(
				'regular' => null,
				'sale'    => null,
			),
			'new'         => array(
				'regular' => null,
				'sale'    => null,
			),
			'source'      => $source,
			'currency'    => $args['currency_mode'],
			'wrote'       => false,
			'parent_id'   => 0,
			'object_type' => 'product',
		);

		if ( ! $product instanceof \WC_Product ) {
			$result['code']    = 'missing';
			$result['message'] = __( 'The product could not be loaded.', 'usd-to-toman-price-sync-for-woocommerce' );

			return $result;
		}

		$snapshot = $this->pricing->get_snapshot( $product );

		if ( ! $snapshot ) {
			$result['code']    = 'missing';
			$result['message'] = __( 'The product could not be loaded.', 'usd-to-toman-price-sync-for-woocommerce' );

			return $result;
		}

		$product_id = (int) $snapshot['id'];
		$decimals   = (int) $this->settings->get( 'decimals' );

		$result['old']         = array(
			'regular' => $snapshot['current_regular'],
			'sale'    => $snapshot['current_sale'],
		);
		$result['parent_id']   = (int) $snapshot['parent_id'];
		$result['object_type'] = (string) $snapshot['object_type'];

		if ( ! $snapshot['managed'] ) {
			$result['code']    = 'not_managed';
			$result['message'] = __( 'This product is not managed by USD/Toman pricing, so it was skipped.', 'usd-to-toman-price-sync-for-woocommerce' );

			return $result;
		}

		if ( null !== $args['expected_revision'] && (int) $args['expected_revision'] !== (int) $snapshot['revision'] ) {
			$result['status']  = Job_Repository::ITEM_CONFLICT;
			$result['code']    = 'conflict';
			$result['message'] = sprintf(
				/* translators: 1: expected revision, 2: current revision. */
				__( 'The Toman price was edited during the synchronization (expected revision %1$d, found %2$d). The product was skipped and needs a recalculation.', 'usd-to-toman-price-sync-for-woocommerce' ),
				(int) $args['expected_revision'],
				(int) $snapshot['revision']
			);

			if ( ! $args['dry_run'] ) {
				$this->pricing->mark_conflict( $product_id, $result['message'] );
			}

			return $result;
		}

		if ( in_array( $snapshot['source_code'], array( 'empty', 'zero', 'negative', 'out_of_range', 'sale_above_regular' ), true ) ) {
			$result['code']    = 'empty' === $snapshot['source_code'] ? 'missing_source' : 'invalid_source';
			$result['message'] = $this->pricing->source_error_message( $snapshot['source_code'] );

			if ( ! $args['dry_run'] && 'empty' !== $snapshot['source_code'] ) {
				$this->pricing->mark_error( $product_id, $result['message'] );
			}

			return $result;
		}

		$store_regular = Settings::MODE_TOMAN === $args['currency_mode'] ? $source['regular'] : $target['regular'];
		$store_sale    = Settings::MODE_TOMAN === $args['currency_mode'] ? $source['sale'] : $target['sale'];

		if ( null === $store_regular && null === $store_sale ) {
			$result['code']    = 'empty_target';
			$result['message'] = __( 'No price could be calculated for this product.', 'usd-to-toman-price-sync-for-woocommerce' );

			return $result;
		}

		if ( null !== $store_sale && null !== $store_regular && $store_sale > $store_regular ) {
			$result['code']    = 'sale_above_regular';
			$result['message'] = $this->pricing->source_error_message( 'sale_above_regular' );

			if ( ! $args['dry_run'] ) {
				$this->pricing->mark_error( $product_id, $result['message'] );
			}

			return $result;
		}

		$result['new'] = array(
			'regular' => $store_regular,
			'sale'    => $store_sale,
		);

		$regular_string = Calculator::to_price_string( $store_regular, $decimals );
		$sale_string    = Calculator::to_price_string( $store_sale, $decimals );

		$price_changed  = ! Calculator::prices_equal( $snapshot['current_regular'], $store_regular, $decimals )
			|| ! Calculator::prices_equal( $snapshot['current_sale'], $store_sale, $decimals );
		$source_changed = ! Calculator::prices_equal( $snapshot['source_regular'], $source['regular'], 0 )
			|| ! Calculator::prices_equal( $snapshot['source_sale'], $source['sale'], 0 );
		$mode_changed   = (string) $snapshot['mode_synced'] !== (string) $args['currency_mode'];

		if ( ! $price_changed && ! $source_changed && ! $mode_changed ) {
			$result['status']  = Job_Repository::ITEM_UNCHANGED;
			$result['code']    = 'no_change';
			$result['message'] = __( 'The stored price is already correct.', 'usd-to-toman-price-sync-for-woocommerce' );

			if ( ! $args['dry_run'] && $args['refresh_rate_meta'] ) {
				$this->refresh_meta( $snapshot, $target, $args, false );
			}

			return $result;
		}

		if ( $args['dry_run'] ) {
			$result['status']  = Job_Repository::ITEM_CHANGED;
			$result['code']    = 'would_change';
			$result['message'] = $this->preview_message( $snapshot, $store_regular, $store_sale, $source, $args );

			return $result;
		}

		try {
			$this->write_price( $product, $regular_string, $sale_string );
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

		$this->refresh_meta( $snapshot, $target, $args, true );

		if ( $args['sync_parent'] && 'variation' === $snapshot['object_type'] && (int) $snapshot['parent_id'] > 0 ) {
			$this->sync_parent( (int) $snapshot['parent_id'] );
		}

		$result['status']  = Job_Repository::ITEM_CHANGED;
		$result['code']    = 'updated';
		$result['wrote']   = true;
		$result['message'] = $this->changed_message( $store_regular, $store_sale, $args );

		return $result;
	}

	/**
	 * Write the WooCommerce price fields.
	 *
	 * @param \WC_Product $product        Product.
	 * @param string      $regular_string Regular price.
	 * @param string      $sale_string    Sale price.
	 * @return void
	 */
	private function write_price( $product, $regular_string, $sale_string ) {
		Product_Pricing::without_hooks(
			function () use ( $product, $regular_string, $sale_string ) {
				$current_regular = (string) $product->get_regular_price( 'edit' );
				$current_sale    = (string) $product->get_sale_price( 'edit' );

				if ( $current_regular !== $regular_string ) {
					$product->set_regular_price( $regular_string );
				}

				if ( $current_sale !== $sale_string ) {
					$product->set_sale_price( $sale_string );
				}

				$product->save();
			}
		);
	}

	/**
	 * Refresh the plugin meta for a product without touching WooCommerce.
	 *
	 * A meta only refresh keeps the "may need update" counter accurate without
	 * saving the product, which is what the "no unnecessary writes" rule is
	 * about: no lookup table writes, no cache churn, no other plugin hooks.
	 *
	 * The canonical Toman source meta is deliberately NOT written here. It is
	 * read only during a synchronization: rewriting it from the worker's
	 * snapshot would overwrite an edit that landed between the revision check
	 * and this refresh. Only derived, rate and sync markers are updated.
	 *
	 * @param array $snapshot Product snapshot.
	 * @param array $target   Derived prices.
	 * @param array $args     Apply arguments.
	 * @param bool  $prices_written Whether the product itself was saved.
	 * @return void
	 */
	private function refresh_meta( array $snapshot, array $target, array $args, $prices_written ) {
		$product_id = (int) $snapshot['id'];
		$decimals   = (int) $this->settings->get( 'decimals' );

		Product_Pricing::without_hooks(
			function () use ( $product_id, $target, $snapshot, $args, $decimals, $prices_written ) {
				update_post_meta( $product_id, Product_Pricing::META_DERIVED_REGULAR, Calculator::to_price_string( $target['regular'], $decimals ) );
				update_post_meta( $product_id, Product_Pricing::META_DERIVED_SALE, Calculator::to_price_string( $target['sale'], $decimals ) );
				update_post_meta( $product_id, Product_Pricing::META_RATE, (string) (float) $args['rate'] );
				update_post_meta( $product_id, Product_Pricing::META_MODE_SYNCED, (string) $args['currency_mode'] );
				update_post_meta( $product_id, Product_Pricing::META_SYNCED_AT, time() );
				delete_post_meta( $product_id, Product_Pricing::META_CONFLICT );
				delete_post_meta( $product_id, Product_Pricing::META_LAST_ERROR );
				delete_post_meta( $product_id, Product_Pricing::META_LAST_JOB );

				if ( $args['job_id'] ) {
					update_post_meta( $product_id, Product_Pricing::META_LAST_JOB, (int) $args['job_id'] );
				}

				if ( ! $prices_written ) {
					/**
					 * Fires after the plugin refreshed its meta for an unchanged product.
					 *
					 * @param int   $product_id Product ID.
					 * @param array $args       Worker arguments.
					 */
					do_action( 'usdtf_meta_refreshed', $product_id, $args );
				}

				unset( $snapshot );
			}
		);

		wp_cache_delete( $product_id, 'post_meta' );
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
	 * Message for a written price.
	 *
	 * @param float|null $regular Regular price.
	 * @param float|null $sale    Sale price.
	 * @param array      $args    Writer arguments.
	 * @return string
	 */
	private function changed_message( $regular, $sale, array $args ) {
		$rounding = $this->settings->rounding_args();

		if ( Settings::MODE_TOMAN === $args['currency_mode'] ) {
			return __( 'The canonical Toman price was written to the price fields (Toman transaction mode).', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		$message = sprintf(
			/* translators: 1: regular USD price, 2: rounding mode. */
			__( 'Price updated to %1$s USD (%2$s rounding).', 'usd-to-toman-price-sync-for-woocommerce' ),
			Calculator::to_price_string( $regular, (int) $rounding['decimals'] ),
			$rounding['rounding']
		);

		if ( null !== $sale ) {
			$message .= ' ' . sprintf(
				/* translators: %s: sale USD price. */
				__( 'Sale price: %s USD.', 'usd-to-toman-price-sync-for-woocommerce' ),
				Calculator::to_price_string( $sale, (int) $rounding['decimals'] )
			);
		}

		return $message;
	}

	/**
	 * Message for a preview row.
	 *
	 * @param array      $snapshot Product snapshot.
	 * @param float|null $regular  New regular price.
	 * @param float|null $sale     New sale price.
	 * @param array      $source   Source prices.
	 * @param array      $args     Writer arguments.
	 * @return string
	 */
	private function preview_message( array $snapshot, $regular, $sale, array $source, array $args ) {
		$decimals = (int) $this->settings->get( 'decimals' );

		$message = sprintf(
			/* translators: 1: new regular price, 2: canonical Toman price. */
			__( 'Would become %1$s (from %2$s Toman).', 'usd-to-toman-price-sync-for-woocommerce' ),
			Calculator::to_price_string( $regular, $decimals ) . ( Settings::MODE_TOMAN === $args['currency_mode'] ? ' Toman' : ' USD' ),
			Calculator::to_price_string( $source['regular'], 0 )
		);

		if ( null !== $sale && null === $snapshot['current_sale'] ) {
			$message .= ' ' . __( 'A sale price would be added.', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		if ( null === $sale && null !== $snapshot['current_sale'] ) {
			$message .= ' ' . __( 'The sale price would be cleared.', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		if ( $snapshot['date_on_sale_from'] || $snapshot['date_on_sale_to'] ) {
			$message .= ' ' . __( 'This product has a scheduled sale; the schedule itself is kept.', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		return $message;
	}
}
