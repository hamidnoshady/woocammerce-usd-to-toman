<?php
/**
 * The pricing model for products and variations.
 *
 * The store price (Toman or Rial, whatever WooCommerce is set to) is what the
 * shopper pays. Every managed product also keeps:
 *
 * 1. a USD anchor, the dollar price the store price is pegged to,
 * 2. the store price the plugin last saw or wrote (the "seen" price), which
 *    tells a hand edit apart from a price the plugin produced,
 * 3. the exchange rate of the last synchronization.
 *
 * A product with no plugin meta is managed.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's product meta.
 */
final class Product_Pricing {

	/**
	 * Product is kept pegged to its USD anchor. A product without a stored mode is managed too.
	 */
	const MODE_MANAGED = 'managed';

	/**
	 * Product is left alone by every synchronization.
	 */
	const MODE_EXCLUDED = 'excluded';

	/**
	 * USD anchor of the regular price.
	 */
	const META_USD_REGULAR = '_usdtf_usd_regular';

	/**
	 * USD anchor of the sale price.
	 */
	const META_USD_SALE = '_usdtf_usd_sale';

	/**
	 * Regular store price the plugin last saw or wrote.
	 */
	const META_SEEN_REGULAR = '_usdtf_seen_regular';

	/**
	 * Sale store price the plugin last saw or wrote.
	 */
	const META_SEEN_SALE = '_usdtf_seen_sale';

	/**
	 * Exchange rate used for the last synchronization.
	 */
	const META_RATE = '_usdtf_rate';

	/**
	 * Pricing mode of the product.
	 */
	const META_MODE = '_usdtf_mode';

	/**
	 * Timestamp of the last successful synchronization.
	 */
	const META_SYNCED_AT = '_usdtf_synced_at';

	/**
	 * Conflict marker written when a price changed mid sync.
	 */
	const META_CONFLICT = '_usdtf_conflict';

	/**
	 * Last error recorded for the product.
	 */
	const META_LAST_ERROR = '_usdtf_last_error';

	/**
	 * Last job that touched the product.
	 */
	const META_LAST_JOB = '_usdtf_last_job';

	/**
	 * Meta of the 1.1.x model that the upgrade keeps, unread: the Toman
	 * source prices and the derived prices. For a store in the removed
	 * USD-store mode they are the only copy of its Toman prices. Only the
	 * opt-in data purge on uninstall removes them.
	 *
	 * @var string[]
	 */
	const LEGACY_META_KEYS = array( '_usdtf_source_regular', '_usdtf_source_sale', '_usdtf_derived_regular', '_usdtf_derived_sale' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * True while the plugin itself is writing product meta.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Posts that are being deleted in this request, by post ID.
	 *
	 * @var array<int, bool>
	 */
	private static $deleting = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Every meta key owned by the plugin.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			self::META_USD_REGULAR,
			self::META_USD_SALE,
			self::META_SEEN_REGULAR,
			self::META_SEEN_SALE,
			self::META_RATE,
			self::META_MODE,
			self::META_SYNCED_AT,
			self::META_CONFLICT,
			self::META_LAST_ERROR,
			self::META_LAST_JOB,
		);
	}

	/**
	 * Supported pricing modes.
	 *
	 * @return string[]
	 */
	public static function modes() {
		return array( self::MODE_MANAGED, self::MODE_EXCLUDED );
	}

	/**
	 * Whether the plugin is currently writing, so hooks must stay quiet.
	 *
	 * @return bool
	 */
	public static function is_writing() {
		return self::$writing;
	}

	/**
	 * Run a callback with plugin writes silenced.
	 *
	 * @param callable $callback Callback.
	 * @return mixed Callback result.
	 */
	public static function without_hooks( $callback ) {
		self::$writing = true;

		try {
			return call_user_func( $callback );
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * Register the product hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ), 10, 2 );

		add_action( 'woocommerce_new_product', array( $this, 'on_new_product' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_new_product' ), 20, 1 );
	}

	/**
	 * Remember that a post is on its way out.
	 *
	 * WordPress collects the meta rows of a deleted post first and removes them
	 * one by one afterwards, so a write that lands in the middle of that loop
	 * adds a row the list does not know about and the row outlives the post. The
	 * writers below use this to stay quiet for a post that is being deleted.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 * @return void
	 */
	public function on_before_delete( $post_id, $post = null ) {
		unset( $post );

		self::$deleting[ (int) $post_id ] = true;
	}

	/**
	 * Whether a post is being deleted in this request.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_being_deleted( $post_id ) {
		return isset( self::$deleting[ (int) $post_id ] );
	}

	/**
	 * A newly created product stores nothing: a product without a mode is managed.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public function on_new_product( $product_id ) {
		unset( $product_id );
	}

	/**
	 * Pricing mode of a product: excluded when stored as excluded (or the retired native), managed otherwise.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return string
	 */
	public function get_mode( $product ) {
		$product_id = $this->resolve_id( $product );

		if ( $product_id <= 0 ) {
			return self::MODE_EXCLUDED;
		}

		$mode = (string) get_post_meta( $product_id, self::META_MODE, true );

		// The retired "native" mode keeps its meaning: the plugin leaves the product alone.
		if ( self::MODE_EXCLUDED === $mode || 'native' === $mode ) {
			return self::MODE_EXCLUDED;
		}

		return self::MODE_MANAGED;
	}

	/**
	 * Store the pricing mode of a product.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @param string          $mode    Pricing mode.
	 * @return bool
	 */
	public function set_mode( $product, $mode ) {
		$product_id = $this->resolve_id( $product );

		if ( $product_id <= 0 || ! in_array( $mode, self::modes(), true ) ) {
			return false;
		}

		self::without_hooks(
			function () use ( $product_id, $mode ) {
				update_post_meta( $product_id, self::META_MODE, $mode );
			}
		);

		return true;
	}

	/**
	 * Whether a product is managed (kept pegged to its USD anchor).
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return bool
	 */
	public function is_managed( $product ) {
		return self::MODE_MANAGED === $this->get_mode( $product );
	}

	/**
	 * Full pricing snapshot used by the admin UI and the worker.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return array
	 */
	public function get_snapshot( $product ) {
		$object = $product instanceof \WC_Product ? $product : wc_get_product( $product );

		if ( ! $object ) {
			return array();
		}

		$product_id = $object->get_id();
		$parent_id  = (int) $object->get_parent_id();

		return array(
			'id'                => $product_id,
			'parent_id'         => $parent_id,
			'object_type'       => $parent_id > 0 ? 'variation' : 'product',
			'name'              => $object->get_name(),
			'status'            => $object->get_status(),
			'type'              => $object->get_type(),
			'mode'              => $this->get_mode( $product_id ),
			'managed'           => $this->is_managed( $product_id ),
			'current_regular'   => (string) $object->get_regular_price( 'edit' ),
			'current_sale'      => (string) $object->get_sale_price( 'edit' ),
			'seen_regular'      => (string) get_post_meta( $product_id, self::META_SEEN_REGULAR, true ),
			'seen_sale'         => (string) get_post_meta( $product_id, self::META_SEEN_SALE, true ),
			'usd_regular'       => (string) get_post_meta( $product_id, self::META_USD_REGULAR, true ),
			'usd_sale'          => (string) get_post_meta( $product_id, self::META_USD_SALE, true ),
			'rate'              => (float) get_post_meta( $product_id, self::META_RATE, true ),
			'synced_at'         => (int) get_post_meta( $product_id, self::META_SYNCED_AT, true ),
			'conflict'          => (string) get_post_meta( $product_id, self::META_CONFLICT, true ),
			'last_error'        => (string) get_post_meta( $product_id, self::META_LAST_ERROR, true ),
			'edit_link'         => get_edit_post_link( $product_id, 'raw' ),
			'is_on_sale'        => $object->is_on_sale(),
			'date_on_sale_from' => $object->get_date_on_sale_from( 'edit' ) ? $object->get_date_on_sale_from( 'edit' )->getTimestamp() : 0,
			'date_on_sale_to'   => $object->get_date_on_sale_to( 'edit' ) ? $object->get_date_on_sale_to( 'edit' )->getTimestamp() : 0,
		);
	}

	/**
	 * Store the USD anchor, the seen price, the rate and the sync time of a product.
	 *
	 * Only the keys present in $state are written. An empty price removes its
	 * row, so "no price" and "never stored" read the same. The WooCommerce price
	 * fields are not touched.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $state {
	 *     State to store.
	 *
	 *     @type string $usd_regular  USD regular anchor.
	 *     @type string $usd_sale     USD sale anchor.
	 *     @type string $seen_regular Regular store price last seen or written.
	 *     @type string $seen_sale    Sale store price last seen or written.
	 *     @type float  $rate         Rate of the synchronization.
	 *     @type int    $synced_at    Timestamp of the synchronization.
	 * }
	 * @return void
	 */
	public function save_state( $product_id, array $state ) {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 || $this->is_being_deleted( $product_id ) ) {
			return;
		}

		$prices = array(
			'usd_regular'  => self::META_USD_REGULAR,
			'usd_sale'     => self::META_USD_SALE,
			'seen_regular' => self::META_SEEN_REGULAR,
			'seen_sale'    => self::META_SEEN_SALE,
		);

		self::without_hooks(
			function () use ( $product_id, $state, $prices ) {
				foreach ( $prices as $field => $meta_key ) {
					if ( ! array_key_exists( $field, $state ) ) {
						continue;
					}

					if ( '' === (string) $state[ $field ] ) {
						delete_post_meta( $product_id, $meta_key );
					} else {
						update_post_meta( $product_id, $meta_key, (string) $state[ $field ] );
					}
				}

				if ( array_key_exists( 'rate', $state ) ) {
					update_post_meta( $product_id, self::META_RATE, (string) (float) $state['rate'] );
				}

				if ( array_key_exists( 'synced_at', $state ) ) {
					update_post_meta( $product_id, self::META_SYNCED_AT, (int) $state['synced_at'] );
				}
			}
		);
	}

	/**
	 * Record a conflict marker.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $message    Message.
	 * @return void
	 */
	public function mark_conflict( $product_id, $message ) {
		self::without_hooks(
			function () use ( $product_id, $message ) {
				update_post_meta( $product_id, self::META_CONFLICT, (string) $message );
			}
		);
	}

	/**
	 * Clear the conflict marker.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function clear_conflict( $product_id ) {
		self::without_hooks(
			function () use ( $product_id ) {
				delete_post_meta( $product_id, self::META_CONFLICT );
			}
		);
	}

	/**
	 * Record the last error for a product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $message    Message.
	 * @return void
	 */
	public function mark_error( $product_id, $message ) {
		self::without_hooks(
			function () use ( $product_id, $message ) {
				update_post_meta( $product_id, self::META_LAST_ERROR, (string) $message );
			}
		);
	}

	/**
	 * Clear the last error for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function clear_error( $product_id ) {
		self::without_hooks(
			function () use ( $product_id ) {
				delete_post_meta( $product_id, self::META_LAST_ERROR );
			}
		);
	}

	/**
	 * Set the USD price of one price field by hand.
	 *
	 * The store price as it is now is accepted as the seen price, so the next
	 * update does not re-base the new USD price away, and the stored rate is
	 * forgotten, so the next update rewrites the store price from the new USD
	 * price at its rate. An empty value removes the USD price; the next update
	 * then records it again from the store price.
	 *
	 * @param int|\WC_Product $product Product or variation.
	 * @param string          $field   regular or sale.
	 * @param mixed           $raw     USD price as typed.
	 * @return bool|\WP_Error True when the USD price changed, false when it did not.
	 */
	public function set_usd_price( $product, $field, $raw ) {
		$object = $product instanceof \WC_Product ? $product : wc_get_product( $product );

		if ( ! $object || ! in_array( $field, array( 'regular', 'sale' ), true ) ) {
			return false;
		}

		$product_id = (int) $object->get_id();
		$usd_key    = 'sale' === $field ? self::META_USD_SALE : self::META_USD_REGULAR;
		$seen_key   = 'sale' === $field ? self::META_SEEN_SALE : self::META_SEEN_REGULAR;
		$stored     = (string) get_post_meta( $product_id, $usd_key, true );
		$value      = Calculator::normalize_number( $raw );

		if ( '' === $value ) {
			if ( '' === trim( (string) $raw ) ) {
				if ( '' === $stored ) {
					return false;
				}

				self::without_hooks(
					function () use ( $product_id, $usd_key ) {
						delete_post_meta( $product_id, $usd_key );
					}
				);

				return true;
			}

			return new \WP_Error( 'usdtf_invalid_usd', __( 'The USD price must be a number such as 44.44.', 'usd-to-toman-price-sync-for-woocommerce' ) );
		}

		$usd = round( (float) $value, 2 );

		if ( ! is_finite( $usd ) || $usd < Price_Planner::MIN_USD ) {
			return new \WP_Error( 'usdtf_invalid_usd', __( 'The USD price must be at least 0.01.', 'usd-to-toman-price-sync-for-woocommerce' ) );
		}

		$usd = number_format( $usd, 2, '.', '' );

		if ( '' !== $stored && number_format( (float) $stored, 2, '.', '' ) === $usd ) {
			return false;
		}

		$current = 'sale' === $field ? (string) $object->get_sale_price( 'edit' ) : (string) $object->get_regular_price( 'edit' );

		if ( '' === $current ) {
			return new \WP_Error(
				'usdtf_usd_without_price',
				'sale' === $field
					? __( 'Enter a sale price in WooCommerce before setting its USD price.', 'usd-to-toman-price-sync-for-woocommerce' )
					: __( 'Enter a regular price in WooCommerce before setting its USD price.', 'usd-to-toman-price-sync-for-woocommerce' )
			);
		}

		self::without_hooks(
			function () use ( $product_id, $usd_key, $seen_key, $usd, $current ) {
				update_post_meta( $product_id, $usd_key, $usd );
				update_post_meta( $product_id, $seen_key, $current );
				delete_post_meta( $product_id, self::META_RATE );
			}
		);

		return true;
	}

	/**
	 * Resolve a product like value to an ID.
	 *
	 * @param mixed $product Product, variation or ID.
	 * @return int
	 */
	public function resolve_id( $product ) {
		if ( $product instanceof \WC_Product ) {
			return (int) $product->get_id();
		}

		if ( is_object( $product ) && isset( $product->ID ) ) {
			return (int) $product->ID;
		}

		return (int) $product;
	}
}
