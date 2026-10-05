<?php
/**
 * Product discovery and counting.
 *
 * Discovery only ever reads IDs with WP_Query; every price read or write goes
 * through wc_get_product() and the WooCommerce CRUD API.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the products a synchronization job has to look at.
 */
final class Product_Repository {

	/**
	 * Post statuses that are considered "not ignored".
	 *
	 * Trash and auto-draft are deliberately absent.
	 */
	const STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	/**
	 * Number of parent products inspected per discovery run.
	 */
	const DISCOVERY_PAGE_SIZE = 100;

	/**
	 * Hard cap for the variations of a single parent product.
	 */
	const VARIATION_LIMIT = 2000;

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
	 * Default scope of an update.
	 *
	 * @return array
	 */
	public static function default_scope() {
		return array(
			'mode'                => Product_Pricing::MODE_MANAGED,
			'ids'                 => array(),
			'exclude_ids'         => array(),
			'category'            => array(),
			'product_type'        => array(),
			'statuses'            => self::STATUSES,
			'only_outdated'       => false,
			'changed_since'       => 0,
			'include_variations'  => true,
			'label'               => '',
		);
	}

	/**
	 * Sanitize a scope array coming from a REST request or the UI.
	 *
	 * @param array $scope Raw scope.
	 * @return array
	 */
	public function normalize_scope( $scope ) {
		$scope = is_array( $scope ) ? $scope : array();
		$scope = wp_parse_args( $scope, self::default_scope() );

		$clean = self::default_scope();

		$clean['mode'] = in_array( $scope['mode'], array( Product_Pricing::MODE_MANAGED, Product_Pricing::MODE_NATIVE, Product_Pricing::MODE_EXCLUDED, 'all' ), true )
			? $scope['mode']
			: Product_Pricing::MODE_MANAGED;

		foreach ( array( 'ids', 'exclude_ids', 'category', 'statuses' ) as $key ) {
			$values = is_array( $scope[ $key ] ) ? $scope[ $key ] : array( $scope[ $key ] );
			$values = array_filter( array_map( 'intval', $values ) );
			$clean[ $key ] = array_values( array_unique( $values ) );
		}

		if ( $clean['statuses'] ) {
			$clean['statuses'] = array_values( array_intersect( $clean['statuses'], self::STATUSES ) );
		}

		if ( ! $clean['statuses'] ) {
			$clean['statuses'] = self::STATUSES;
		}

		$types = is_array( $scope['product_type'] ) ? $scope['product_type'] : array( $scope['product_type'] );
		$clean['product_type'] = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );

		$clean['only_outdated']      = ! empty( $scope['only_outdated'] );
		$clean['changed_since']      = max( 0, (int) $scope['changed_since'] );
		$clean['include_variations'] = ! empty( $scope['include_variations'] );
		$clean['label']              = isset( $scope['label'] ) ? sanitize_text_field( (string) $scope['label'] ) : '';

		return $clean;
	}

	/**
	 * Human readable label for a scope.
	 *
	 * @param array $scope Scope.
	 * @return string
	 */
	public function scope_label( array $scope ) {
		$scope = $this->normalize_scope( $scope );

		if ( '' !== $scope['label'] ) {
			return $scope['label'];
		}

		if ( $scope['ids'] ) {
			return sprintf(
				/* translators: %d: number of selected products. */
				_n( '%d selected product', '%d selected products', count( $scope['ids'] ), 'usd-to-toman-price-sync-for-woocommerce' ),
				count( $scope['ids'] )
			);
		}

		if ( $scope['only_outdated'] ) {
			return __( 'Products whose price may be outdated', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		if ( $scope['category'] ) {
			return __( 'Selected categories', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		if ( $scope['product_type'] ) {
			return __( 'Selected product types', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		return __( 'Every managed product', 'usd-to-toman-price-sync-for-woocommerce' );
	}

	/**
	 * Build WP_Query arguments for a discovery query.
	 *
	 * @param array  $scope       Scope.
	 * @param int    $page        1 based page.
	 * @param int    $per_page    Items per page.
	 * @param string $object_type product|variation.
	 * @param int    $parent_id   Parent ID for variation queries.
	 * @param float  $rate        Rate used for the "only outdated" filter.
	 * @return array
	 */
	public function query_args( array $scope, $page, $per_page, $object_type = 'product', $parent_id = 0, $rate = 0 ) {
		$scope = $this->normalize_scope( $scope );

		$args = array(
			'post_type'              => 'variation' === $object_type ? 'product_variation' : 'product',
			'post_status'            => $scope['statuses'],
			'fields'                 => 'ids',
			'posts_per_page'         => max( 1, (int) $per_page ),
			'paged'                  => max( 1, (int) $page ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'suppress_filters'       => false,
		);

		if ( $parent_id > 0 ) {
			$args['post_parent'] = (int) $parent_id;
		}

		if ( $scope['ids'] ) {
			$args['post__in'] = $scope['ids'];
		}

		if ( $scope['exclude_ids'] ) {
			$args['post__not_in'] = $scope['exclude_ids'];
		}

		if ( $scope['changed_since'] > 0 ) {
			$args['date_query'] = array(
				array(
					'column'    => 'post_modified_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', $scope['changed_since'] ),
					'inclusive' => true,
				),
			);
		}

		$meta_query = array();

		if ( 'all' !== $scope['mode'] ) {
			$meta_query[] = array(
				'key'     => Product_Pricing::META_MODE,
				'value'   => $scope['mode'],
				'compare' => '=',
			);
		}

		if ( 'product' === $object_type ) {
			if ( $scope['product_type'] ) {
				$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => $scope['product_type'],
						'operator' => 'IN',
					),
				);
			}

			if ( $scope['category'] ) {
				if ( ! isset( $args['tax_query'] ) ) {
					$args['tax_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				}

				$args['tax_query'][] = array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $scope['category'],
					'operator'         => 'IN',
					'include_children' => true,
				);
			}
		}

		if ( $scope['only_outdated'] && $rate > 0 ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => Product_Pricing::META_RATE,
					'value'   => (string) $rate,
					'compare' => '!=',
				),
				array(
					'key'     => Product_Pricing::META_RATE,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		if ( $meta_query ) {
			$meta_query['relation'] = 'AND';
			$args['meta_query']     = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		/**
		 * Filters the discovery query arguments.
		 *
		 * @param array  $args        Query arguments.
		 * @param array  $scope       Normalized scope.
		 * @param string $object_type product|variation.
		 */
		return apply_filters( 'usdtf_discovery_query_args', $args, $scope, $object_type );
	}

	/**
	 * Get product or variation IDs for one discovery page.
	 *
	 * @param array  $scope       Scope.
	 * @param int    $page        Page number.
	 * @param int    $per_page    Items per page.
	 * @param string $object_type product|variation.
	 * @param int    $parent_id   Parent ID for variations.
	 * @param float  $rate        Rate used for "only outdated".
	 * @return int[]
	 */
	public function get_ids( array $scope, $page, $per_page, $object_type = 'product', $parent_id = 0, $rate = 0 ) {
		$args  = $this->query_args( $scope, $page, $per_page, $object_type, $parent_id, $rate );
		$query = new \WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Count products matching a scope.
	 *
	 * @param array  $scope       Scope.
	 * @param string $object_type product|variation.
	 * @param int    $parent_id   Parent ID for variations.
	 * @param float  $rate        Rate for "only outdated".
	 * @return int
	 */
	public function count( array $scope, $object_type = 'product', $parent_id = 0, $rate = 0 ) {
		$args                        = $this->query_args( $scope, 1, 1, $object_type, $parent_id, $rate );
		$args['posts_per_page']      = 1;
		$args['fields']              = 'ids';
		$args['no_found_rows']       = false;

		$query = new \WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Variation IDs of a variable product.
	 *
	 * @param int $parent_id Parent product ID.
	 * @return int[]
	 */
	public function variation_ids( $parent_id ) {
		$ids = $this->get_ids(
			array(
				'mode'    => 'all',
				'ids'     => array(),
				'label'   => '',
			),
			1,
			self::VARIATION_LIMIT,
			'variation',
			(int) $parent_id
		);

		/**
		 * Filters the maximum number of variations handled for one parent.
		 *
		 * @param int $limit     Variation limit.
		 * @param int $parent_id Parent product ID.
		 */
		$limit = (int) apply_filters( 'usdtf_variation_limit', self::VARIATION_LIMIT, (int) $parent_id );

		if ( $limit > 0 && count( $ids ) > $limit ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		return $ids;
	}

	/**
	 * Counts of products per pricing mode.
	 *
	 * @return array
	 */
	public function mode_counts() {
		$cached = get_transient( 'usdtf_mode_counts' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = array(
			'managed'   => 0,
			'native'    => 0,
			'excluded'  => 0,
			'unset'     => 0,
			'products'  => 0,
			'variations' => 0,
		);

		foreach ( Product_Pricing::modes() as $mode ) {
			$counts[ $mode ] = $this->count(
				array(
					'mode'  => $mode,
					'label' => '',
				),
				'product'
			);
		}

		$counts['products'] = $this->count(
			array(
				'mode'  => 'all',
				'label' => '',
			),
			'product'
		);

		$counts['variations'] = $this->count(
			array(
				'mode'  => 'all',
				'label' => '',
			),
			'variation'
		);

		$unset = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => self::STATUSES,
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Product_Pricing::META_MODE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$counts['unset'] = (int) $unset->found_posts;

		$counts['managed_variations'] = $this->count(
			array(
				'mode'  => Product_Pricing::MODE_MANAGED,
				'label' => '',
			),
			'variation'
		);

		set_transient( 'usdtf_mode_counts', $counts, 60 );

		return $counts;
	}

	/**
	 * Number of managed products whose stored rate is not the current rate.
	 *
	 * This is a cheap upper bound of "products needing update": prices whose
	 * rounded USD value is unchanged are not rewritten, so the exact number is
	 * produced by a dry run.
	 *
	 * @param float $rate Current rate.
	 * @return int
	 */
	public function candidate_count( $rate ) {
		if ( $rate <= 0 ) {
			return $this->count(
				array(
					'mode'  => Product_Pricing::MODE_MANAGED,
					'label' => '',
				),
				'product'
			);
		}

		return $this->count(
			array(
				'mode'          => Product_Pricing::MODE_MANAGED,
				'only_outdated' => true,
				'label'         => '',
			),
			'product',
			0,
			$rate
		);
	}

	/**
	 * Dashboard summary.
	 *
	 * @param float $rate Current rate.
	 * @return array
	 */
	public function summary( $rate ) {
		$modes = $this->mode_counts();

		$summary = array(
			'products'            => (int) $modes['products'],
			'variations'          => (int) $modes['variations'],
			'managed'             => (int) $modes['managed'],
			'managed_variations'  => (int) $modes['managed_variations'],
			'native'              => (int) $modes['native'],
			'excluded'            => (int) $modes['excluded'],
			'unset'               => (int) $modes['unset'],
			'candidates'          => $this->candidate_count( $rate ),
		);

		return $summary;
	}

	/**
	 * Invalidate the cached counts.
	 *
	 * @return void
	 */
	public function flush_counts_cache() {
		delete_transient( 'usdtf_mode_counts' );
	}
}
