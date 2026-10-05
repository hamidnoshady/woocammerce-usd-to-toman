<?php
/**
 * The canonical pricing model for products and variations.
 *
 * Every product keeps three separate concepts:
 *
 * 1. the canonical source price entered in Toman (never overwritten by a sync),
 * 2. the derived USD price calculated from the source and the manual FX rate,
 * 3. the WooCommerce price fields, which hold the value of the configured
 *    transaction currency (USD in mode A, Toman in mode B).
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
	 * Product participates in Toman sourced pricing.
	 */
	const MODE_MANAGED = 'managed';

	/**
	 * Product keeps a native USD price and is never synchronized.
	 */
	const MODE_NATIVE = 'native';

	/**
	 * Product is explicitly excluded from synchronization.
	 */
	const MODE_EXCLUDED = 'excluded';

	/**
	 * Canonical Toman regular price.
	 */
	const META_SOURCE_REGULAR = '_usdtf_source_regular';

	/**
	 * Canonical Toman sale price.
	 */
	const META_SOURCE_SALE = '_usdtf_source_sale';

	/**
	 * Last derived USD regular price.
	 */
	const META_DERIVED_REGULAR = '_usdtf_derived_regular';

	/**
	 * Last derived USD sale price.
	 */
	const META_DERIVED_SALE = '_usdtf_derived_sale';

	/**
	 * Exchange rate used for the last synchronization.
	 */
	const META_RATE = '_usdtf_rate';

	/**
	 * Timestamp of the last successful synchronization.
	 */
	const META_SYNCED_AT = '_usdtf_synced_at';

	/**
	 * Source revision counter used for concurrent edit protection.
	 */
	const META_REVISION = '_usdtf_revision';

	/**
	 * Pricing mode of the product.
	 */
	const META_MODE = '_usdtf_mode';

	/**
	 * Which transaction currency the WooCommerce price fields were written for.
	 */
	const META_MODE_SYNCED = '_usdtf_mode_synced';

	/**
	 * Conflict marker written when a source change was detected mid sync.
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
	 * Informational copy of the value another source plugin stored.
	 */
	const META_EXTERNAL_SOURCE = '_usdtf_external_source';

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
			self::META_SOURCE_REGULAR,
			self::META_SOURCE_SALE,
			self::META_DERIVED_REGULAR,
			self::META_DERIVED_SALE,
			self::META_RATE,
			self::META_SYNCED_AT,
			self::META_REVISION,
			self::META_MODE,
			self::META_MODE_SYNCED,
			self::META_CONFLICT,
			self::META_LAST_ERROR,
			self::META_LAST_JOB,
			self::META_EXTERNAL_SOURCE,
		);
	}

	/**
	 * Supported pricing modes.
	 *
	 * @return string[]
	 */
	public static function modes() {
		return array( self::MODE_MANAGED, self::MODE_NATIVE, self::MODE_EXCLUDED );
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
	 * Register hooks that keep the source revision accurate.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'added_post_meta', array( $this, 'on_meta_written' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_written' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 10, 4 );

		add_action( 'woocommerce_new_product', array( $this, 'on_new_product' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_new_product' ), 20, 1 );

		add_action( 'woocommerce_admin_process_product_object', array( $this, 'on_admin_process_product' ), 20, 1 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'on_admin_process_variation' ), 20, 2 );
	}

	/**
	 * React to meta writes on products and variations.
	 *
	 * @param int    $meta_id   Meta row ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value Value.
	 * @return void
	 */
	public function on_meta_written( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );

		if ( self::$writing ) {
			return;
		}

		if ( ! in_array( $meta_key, array( self::META_SOURCE_REGULAR, self::META_SOURCE_SALE, '_regular_price', '_sale_price' ), true ) ) {
			return;
		}

		if ( ! $this->looks_like_priced_product( $object_id ) ) {
			return;
		}

		if ( ! $this->is_managed( $object_id ) ) {
			return;
		}

		$this->bump_revision( $object_id );
	}

	/**
	 * React to meta deletions on products and variations.
	 *
	 * @param int    $meta_ids   Deleted meta row IDs.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 * @return void
	 */
	public function on_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ) {
		unset( $meta_ids, $meta_value );

		if ( self::$writing ) {
			return;
		}

		if ( ! in_array( $meta_key, array( self::META_SOURCE_REGULAR, self::META_SOURCE_SALE, '_regular_price', '_sale_price' ), true ) ) {
			return;
		}

		if ( $this->looks_like_priced_product( $object_id ) && $this->is_managed( $object_id ) ) {
			$this->bump_revision( $object_id );
		}
	}

	/**
	 * A newly created product starts in the configured default mode.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public function on_new_product( $product_id ) {
		if ( self::$writing ) {
			return;
		}

		$product_id = (int) $product_id;

		if ( $product_id <= 0 || '' !== (string) get_post_meta( $product_id, self::META_MODE, true ) ) {
			return;
		}

		if ( ! $this->settings->get( 'auto_manage_new_products' ) ) {
			$this->set_mode( $product_id, self::MODE_NATIVE );

			return;
		}

		$this->set_mode( $product_id, self::MODE_MANAGED );

		$product = wc_get_product( $product_id );

		if ( $product ) {
			$this->import_current_price_as_source( $product );
		}
	}

	/**
	 * Protect the derived price when an admin edits a managed product.
	 *
	 * In USD mode the WooCommerce price fields are owned by the plugin: if an
	 * admin changes them by hand we restore the derived value and flag it, so a
	 * real USD price can never silently become the Toman source.
	 *
	 * In Toman mode the price fields *are* the canonical Toman price, so an
	 * admin edit is treated as a source change instead.
	 *
	 * @param \WC_Product $product Product being saved.
	 * @return void
	 */
	public function on_admin_process_product( $product ) {
		if ( ! $product instanceof \WC_Product || self::$writing ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! $this->is_managed( $product_id ) ) {
			return;
		}

		if ( $this->settings->is_toman_mode() ) {
			$this->ingest_woocommerce_prices( $product );

			return;
		}

		$derived_regular = get_post_meta( $product_id, self::META_DERIVED_REGULAR, true );
		$derived_sale    = get_post_meta( $product_id, self::META_DERIVED_SALE, true );

		if ( '' === $derived_regular && '' === $derived_sale ) {
			// Nothing derived yet: keep whatever the admin entered as the source.
			$this->ingest_woocommerce_prices( $product );

			return;
		}

		$current_regular = (string) $product->get_regular_price( 'edit' );
		$current_sale    = (string) $product->get_sale_price( 'edit' );

		$decimals = (int) $this->settings->get( 'decimals' );

		$changed = false;

		if ( ! Calculator::prices_equal( $current_regular, $derived_regular, $decimals ) ) {
			$product->set_regular_price( Calculator::to_price_string( '' === $derived_regular ? null : (float) $derived_regular, $decimals ) );
			$changed = true;
		}

		if ( ! Calculator::prices_equal( $current_sale, $derived_sale, $decimals ) ) {
			$product->set_sale_price( Calculator::to_price_string( '' === $derived_sale ? null : (float) $derived_sale, $decimals ) );
			$changed = true;
		}

		if ( $changed ) {
			WC()->session->set( 'usdtf_manual_price_notice', $product_id );
		}
	}

	/**
	 * Variation equivalent of on_admin_process_product().
	 *
	 * @param \WC_Product $variation Variation being saved.
	 * @param int         $index     Variation index.
	 * @return void
	 */
	public function on_admin_process_variation( $variation, $index ) {
		unset( $index );

		$this->on_admin_process_product( $variation );
	}

	/**
	 * Whether a post ID belongs to a product or a variation.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function looks_like_priced_product( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'wp_cache_get' ) ) {
			$type = get_post_type( $post_id );
		} else {
			$type = get_post_type( $post_id );
		}

		return in_array( $type, array( 'product', 'product_variation' ), true );
	}

	/**
	 * Pricing mode of a product.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return string
	 */
	public function get_mode( $product ) {
		$product_id = $this->resolve_id( $product );

		if ( $product_id <= 0 ) {
			return self::MODE_NATIVE;
		}

		$mode = (string) get_post_meta( $product_id, self::META_MODE, true );

		if ( ! in_array( $mode, self::modes(), true ) ) {
			return self::MODE_NATIVE;
		}

		return $mode;
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
	 * Whether a product is managed (Toman sourced and synchronized).
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return bool
	 */
	public function is_managed( $product ) {
		return self::MODE_MANAGED === $this->get_mode( $product );
	}

	/**
	 * Canonical Toman source prices.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return array{regular:float|null,sale:float|null,code:string,regular_raw:string,sale_raw:string}
	 */
	public function get_source( $product ) {
		$product_id = $this->resolve_id( $product );

		$regular_raw = '' === $product_id ? '' : (string) get_post_meta( $product_id, self::META_SOURCE_REGULAR, true );
		$sale_raw    = '' === $product_id ? '' : (string) get_post_meta( $product_id, self::META_SOURCE_SALE, true );

		$regular = Calculator::parse_toman( $regular_raw );
		$sale    = Calculator::parse_toman( $sale_raw );

		return array(
			'regular'     => $regular['value'],
			'sale'        => $sale['value'],
			'code'        => $this->validate_source_pair( $regular, $sale ),
			'regular_raw' => $regular_raw,
			'sale_raw'    => $sale_raw,
		);
	}

	/**
	 * Validate a parsed source pair.
	 *
	 * @param array $regular Parsed regular price.
	 * @param array $sale    Parsed sale price.
	 * @return string One of: ok, empty, zero, negative, out_of_range, sale_above_regular.
	 */
	public function validate_source_pair( array $regular, array $sale ) {
		if ( 'negative' === $regular['code'] || 'negative' === $sale['code'] ) {
			return 'negative';
		}

		if ( 'out_of_range' === $regular['code'] || 'out_of_range' === $sale['code'] ) {
			return 'out_of_range';
		}

		if ( 'zero' === $regular['code'] || 'zero' === $sale['code'] ) {
			return 'zero';
		}

		if ( null === $regular['value'] && null === $sale['value'] ) {
			return 'empty';
		}

		if ( null !== $regular['value'] && null !== $sale['value'] && $sale['value'] > $regular['value'] ) {
			return 'sale_above_regular';
		}

		return 'ok';
	}

	/**
	 * Write canonical Toman source prices.
	 *
	 * @param int|\WC_Product $product    Product or product ID.
	 * @param mixed           $regular    Regular Toman price.
	 * @param mixed           $sale       Sale Toman price, empty to clear.
	 * @param array           $args {
	 *     Optional arguments.
	 *
	 *     @type bool $bump_revision Bump the revision counter. Default true.
	 * }
	 * @return array|\WP_Error Result of the write.
	 */
	public function set_source( $product, $regular, $sale = '', $args = array() ) {
		$product_id = $this->resolve_id( $product );

		if ( $product_id <= 0 ) {
			return new \WP_Error( 'usdtf_invalid_product', __( 'The product could not be found.', 'usd-to-toman-price-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$args = wp_parse_args( $args, array( 'bump_revision' => true ) );

		$parsed_regular = Calculator::parse_toman( $regular );
		$parsed_sale    = Calculator::parse_toman( $sale );

		$code = $this->validate_source_pair( $parsed_regular, $parsed_sale );

		if ( in_array( $code, array( 'negative', 'out_of_range' ), true ) ) {
			return new \WP_Error( 'usdtf_invalid_source', $this->source_error_message( $code ), array( 'status' => 400 ) );
		}

		if ( 'sale_above_regular' === $code ) {
			return new \WP_Error( 'usdtf_invalid_source', $this->source_error_message( $code ), array( 'status' => 400 ) );
		}

		$current = $this->get_source( $product_id );

		$new_regular = null === $parsed_regular['value'] ? '' : Calculator::to_price_string( $parsed_regular['value'], 0 );
		$new_sale    = null === $parsed_sale['value'] ? '' : Calculator::to_price_string( $parsed_sale['value'], 0 );

		$changed = (string) $current['regular_raw'] !== (string) $new_regular || (string) $current['sale_raw'] !== (string) $new_sale;

		if ( ! $changed ) {
			return array(
				'changed'  => false,
				'regular'  => $parsed_regular['value'],
				'sale'     => $parsed_sale['value'],
				'code'     => $code,
				'revision' => $this->get_revision( $product_id ),
			);
		}

		$revision = self::without_hooks(
			function () use ( $product_id, $new_regular, $new_sale, $args ) {
				update_post_meta( $product_id, self::META_SOURCE_REGULAR, $new_regular );
				update_post_meta( $product_id, self::META_SOURCE_SALE, $new_sale );

				return ! empty( $args['bump_revision'] ) ? $this->bump_revision( $product_id ) : $this->get_revision( $product_id );
			}
		);

		return array(
			'changed'  => true,
			'regular'  => $parsed_regular['value'],
			'sale'     => $parsed_sale['value'],
			'code'     => $code,
			'revision' => (int) $revision,
		);
	}

	/**
	 * Human readable message for a validation code.
	 *
	 * @param string $code Validation code.
	 * @return string
	 */
	public function source_error_message( $code ) {
		switch ( $code ) {
			case 'sale_above_regular':
				return __( 'The sale price cannot be higher than the regular price.', 'usd-to-toman-price-sync-for-woocommerce' );
			case 'negative':
				return __( 'Prices cannot be negative.', 'usd-to-toman-price-sync-for-woocommerce' );
			case 'zero':
				return __( 'A price of zero cannot be converted into a USD price.', 'usd-to-toman-price-sync-for-woocommerce' );
			case 'out_of_range':
				return __( 'The price is outside of the supported range.', 'usd-to-toman-price-sync-for-woocommerce' );
			case 'empty':
			default:
				return __( 'No Toman price is stored for this product yet.', 'usd-to-toman-price-sync-for-woocommerce' );
		}
	}

	/**
	 * Current source revision of a product.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return int
	 */
	public function get_revision( $product ) {
		$product_id = $this->resolve_id( $product );

		if ( $product_id <= 0 ) {
			return 0;
		}

		return (int) get_post_meta( $product_id, self::META_REVISION, true );
	}

	/**
	 * Increase the source revision counter.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @param int             $amount  Amount to add.
	 * @return int New revision.
	 */
	public function bump_revision( $product, $amount = 1 ) {
		$product_id = $this->resolve_id( $product );

		if ( $product_id <= 0 ) {
			return 0;
		}

		$revision = (int) get_post_meta( $product_id, self::META_REVISION, true );
		$revision = max( 1, $revision + max( 1, (int) $amount ) );

		self::without_hooks(
			function () use ( $product_id, $revision ) {
				update_post_meta( $product_id, self::META_REVISION, $revision );
			}
		);

		return $revision;
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
		$source     = $this->get_source( $product_id );
		$parent_id  = (int) $object->get_parent_id();

		$snapshot = array(
			'id'                => $product_id,
			'parent_id'         => $parent_id,
			'object_type'       => $parent_id > 0 ? 'variation' : 'product',
			'name'              => $object->get_name(),
			'status'            => $object->get_status(),
			'type'              => $object->get_type(),
			'mode'              => $this->get_mode( $product_id ),
			'managed'           => $this->is_managed( $product_id ),
			'source_regular'    => $source['regular'],
			'source_sale'       => $source['sale'],
			'source_code'       => $source['code'],
			'derived_regular'   => $this->meta_number( $product_id, self::META_DERIVED_REGULAR ),
			'derived_sale'      => $this->meta_number( $product_id, self::META_DERIVED_SALE ),
			'rate'              => $this->meta_number( $product_id, self::META_RATE ),
			'synced_at'         => (int) get_post_meta( $product_id, self::META_SYNCED_AT, true ),
			'revision'          => $this->get_revision( $product_id ),
			'mode_synced'       => (string) get_post_meta( $product_id, self::META_MODE_SYNCED, true ),
			'conflict'          => (string) get_post_meta( $product_id, self::META_CONFLICT, true ),
			'last_error'        => (string) get_post_meta( $product_id, self::META_LAST_ERROR, true ),
			'external_source'   => $this->meta_number( $product_id, self::META_EXTERNAL_SOURCE ),
			'current_regular'   => null === $object->get_regular_price( 'edit' ) || '' === $object->get_regular_price( 'edit' ) ? null : (float) $object->get_regular_price( 'edit' ),
			'current_sale'      => null === $object->get_sale_price( 'edit' ) || '' === $object->get_sale_price( 'edit' ) ? null : (float) $object->get_sale_price( 'edit' ),
			'price'             => '' === $object->get_price( 'edit' ) ? null : (float) $object->get_price( 'edit' ),
			'edit_link'         => get_edit_post_link( $product_id, 'raw' ),
			'is_on_sale'        => $object->is_on_sale(),
			'date_on_sale_from' => $object->get_date_on_sale_from( 'edit' ) ? $object->get_date_on_sale_from( 'edit' )->getTimestamp() : 0,
			'date_on_sale_to'   => $object->get_date_on_sale_to( 'edit' ) ? $object->get_date_on_sale_to( 'edit' )->getTimestamp() : 0,
		);

		return $snapshot;
	}

	/**
	 * Copy the current WooCommerce price fields into the Toman source.
	 *
	 * This is the onboarding path for a catalog whose stored prices were
	 * entered in Toman before this plugin was installed.
	 *
	 * @param int|\WC_Product $product Product or product ID.
	 * @return array|\WP_Error
	 */
	public function import_current_price_as_source( $product ) {
		$object = $product instanceof \WC_Product ? $product : wc_get_product( $product );

		if ( ! $object ) {
			return new \WP_Error( 'usdtf_invalid_product', __( 'The product could not be found.', 'usd-to-toman-price-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$regular = $object->get_regular_price( 'edit' );
		$sale    = $object->get_sale_price( 'edit' );

		if ( '' === $regular ) {
			$regular = $object->get_price( 'edit' );
		}

		$regular = Calculator::normalize_number( $regular );
		$sale    = Calculator::normalize_number( $sale );

		if ( '' === $regular && '' === $sale ) {
			return new \WP_Error( 'usdtf_no_price', __( 'This product has no price to import.', 'usd-to-toman-price-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$source = $this->set_source( $object->get_id(), '' === $regular ? '' : $regular, '' === $sale ? '' : $sale );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		return $source;
	}

	/**
	 * Treat the current WooCommerce price fields as the Toman source (Toman mode).
	 *
	 * @param \WC_Product $product Product being saved.
	 * @return void
	 */
	public function ingest_woocommerce_prices( $product ) {
		$product_id = $product->get_id();

		$regular = Calculator::normalize_number( $product->get_regular_price( 'edit' ) );
		$sale    = Calculator::normalize_number( $product->get_sale_price( 'edit' ) );

		$this->set_source( $product_id, $regular, $sale );
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

	/**
	 * Read a numeric meta value or null.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $key        Meta key.
	 * @return float|null
	 */
	private function meta_number( $product_id, $key ) {
		$value = get_post_meta( $product_id, $key, true );

		if ( '' === $value || null === $value ) {
			return null;
		}

		return (float) $value;
	}
}
