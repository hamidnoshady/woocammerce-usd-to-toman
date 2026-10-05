<?php
/**
 * Product editing integration: meta box, bulk actions and list column.
 *
 * @package USDTF
 */

namespace USDTF\Admin;

use USDTF\Calculator;
use USDTF\Capabilities;
use USDTF\Job;
use USDTF\Product_Pricing;
use USDTF\Product_Repository;
use USDTF\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Toman pricing panel to products and variations.
 */
final class Product_Panel {

	/**
	 * Nonce action for the panel form.
	 */
	const NONCE = 'usdtf_product_panel';

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
	 * Product discovery.
	 *
	 * @var Product_Repository
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings Settings.
	 * @param Product_Pricing    $pricing  Pricing model.
	 * @param Product_Repository $products Product discovery.
	 */
	public function __construct( Settings $settings, Product_Pricing $pricing, Product_Repository $products ) {
		$this->settings = $settings;
		$this->pricing  = $pricing;
		$this->products = $products;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_product' ), 30, 1 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 30, 1 );

		add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk_actions' ), 10, 3 );

		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_action( 'restrict_manage_posts', array( $this, 'render_list_filter' ) );
		add_action( 'parse_query', array( $this, 'apply_list_filter' ) );

		add_action( 'admin_post_usdtf_recalculate_product', array( $this, 'handle_recalculate' ) );
	}

	/**
	 * Register the meta box on product edit screens.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		if ( ! Capabilities::current_user_can() ) {
			return;
		}

		add_meta_box(
			'usdtf-product-pricing',
			__( 'USD / Toman pricing', 'usd-to-toman-price-sync-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			return;
		}

		$pricing  = $this->pricing;
		$settings = $this->settings;
		$snapshot = $pricing->get_snapshot( $product );
		$variations = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $this->products->variation_ids( $product->get_id() ) as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( $variation ) {
					$variations[] = $pricing->get_snapshot( $variation );
				}
			}
		}

		$recalculate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=usdtf_recalculate_product&product_id=' . (int) $post->ID ),
			'usdtf_recalculate_' . (int) $post->ID
		);

		include USDTF_DIR . 'includes/admin/views/product-panel.php';
	}

	/**
	 * Persist the panel values when a product is saved.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public function save_product( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! isset( $_POST['usdtf_panel_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['usdtf_panel_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can() || ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$mode = isset( $_POST['usdtf_mode'] ) ? sanitize_key( wp_unslash( $_POST['usdtf_mode'] ) ) : '';

		if ( in_array( $mode, Product_Pricing::modes(), true ) ) {
			$this->pricing->set_mode( $post_id, $mode );
		}

		if ( Product_Pricing::MODE_MANAGED !== $this->pricing->get_mode( $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are parsed by Calculator::parse_toman().
		$regular = isset( $_POST['usdtf_source_regular'] ) ? wp_unslash( $_POST['usdtf_source_regular'] ) : '';
		$sale    = isset( $_POST['usdtf_source_sale'] ) ? wp_unslash( $_POST['usdtf_source_sale'] ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = $this->pricing->set_source( $post_id, $regular, $sale );

		if ( is_wp_error( $result ) ) {
			set_transient( 'usdtf_panel_error_' . get_current_user_id(), $result->get_error_message(), 60);

			return;
		}

		$variation_sources = isset( $_POST['usdtf_variation_source'] ) && is_array( $_POST['usdtf_variation_source'] )
			? wp_unslash( $_POST['usdtf_variation_source'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed below.
			: array();

		foreach ( $variation_sources as $variation_id => $values ) {
			$variation_id = (int) $variation_id;

			if ( $variation_id <= 0 || ! current_user_can( 'edit_product', $variation_id ) ) {
				continue;
			}

			if ( ! is_array( $values ) ) {
				continue;
			}

			$this->pricing->set_source(
				$variation_id,
				isset( $values['regular'] ) ? $values['regular'] : '',
				isset( $values['sale'] ) ? $values['sale'] : ''
			);
		}
	}

	/**
	 * Persist a variation panel value.
	 *
	 * Variations are edited through the WooCommerce variations table, so only the
	 * plugin's own hidden field is read here.
	 *
	 * @param int $variation_id Variation ID.
	 * @return void
	 */
	public function save_variation( $variation_id ) {
		$variation_id = (int) $variation_id;

		if ( ! Capabilities::current_user_can() ) {
			return;
		}

		if ( ! isset( $_POST['usdtf_panel_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['usdtf_panel_nonce'] ) ), self::NONCE ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified here.
			return;
		}

		$sources = isset( $_POST['usdtf_variation_source'] ) && is_array( $_POST['usdtf_variation_source'] )
			? wp_unslash( $_POST['usdtf_variation_source'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed below.
			: array();

		if ( ! isset( $sources[ $variation_id ] ) || ! is_array( $sources[ $variation_id ] ) ) {
			return;
		}

		$values = $sources[ $variation_id ];

		$this->pricing->set_source(
			$variation_id,
			isset( $values['regular'] ) ? $values['regular'] : '',
			isset( $values['sale'] ) ? $values['sale'] : ''
		);
	}

	/**
	 * Register the bulk actions.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		if ( ! Capabilities::current_user_can() ) {
			return $actions;
		}

		$actions['usdtf_enable'] = __( 'USD/Toman: enable Toman pricing (import current price)', 'usd-to-toman-price-sync-for-woocommerce' );
		$actions['usdtf_native'] = __( 'USD/Toman: mark as native USD price', 'usd-to-toman-price-sync-for-woocommerce' );
		$actions['usdtf_exclude'] = __( 'USD/Toman: exclude from synchronization', 'usd-to-toman-price-sync-for-woocommerce' );

		return $actions;
	}

	/**
	 * Handle the bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param int[]  $post_ids    Selected product IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( ! Capabilities::current_user_can() ) {
			return $redirect_to;
		}

		$map = array(
			'usdtf_enable'  => Product_Pricing::MODE_MANAGED,
			'usdtf_native'  => Product_Pricing::MODE_NATIVE,
			'usdtf_exclude' => Product_Pricing::MODE_EXCLUDED,
		);

		if ( ! isset( $map[ $action ] ) ) {
			return $redirect_to;
		}

		$count = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( ! current_user_can( 'edit_product', $post_id ) ) {
				continue;
			}

			if ( Product_Pricing::MODE_MANAGED !== $map[ $action ] ) {
				$this->pricing->set_mode( $post_id, $map[ $action ] );
				++$count;

				continue;
			}

			$product = wc_get_product( $post_id );

			if ( ! $product ) {
				continue;
			}

			$this->pricing->set_mode( $post_id, Product_Pricing::MODE_MANAGED );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $this->products->variation_ids( $post_id ) as $variation_id ) {
					$variation = wc_get_product( $variation_id );

					if ( ! $variation ) {
						continue;
					}

					$this->pricing->set_mode( $variation_id, Product_Pricing::MODE_MANAGED );
					$this->pricing->import_current_price_as_source( $variation );
				}
			} else {
				$this->pricing->import_current_price_as_source( $product );
			}

			++$count;
		}

		$this->products->flush_counts_cache();

		return add_query_arg( 'usdtf_bulk_done', $count, $redirect_to );
	}

	/**
	 * Add the Toman price column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! Capabilities::current_user_can() ) {
			return $columns;
		}

		$columns['usdtf_toman'] = __( 'Toman price', 'usd-to-toman-price-sync-for-woocommerce' );

		return $columns;
	}

	/**
	 * Render the Toman price column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( 'usdtf_toman' !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		$snapshot = $this->pricing->get_snapshot( $product );

		$mode_labels = array(
			Product_Pricing::MODE_MANAGED  => __( 'Managed', 'usd-to-toman-price-sync-for-woocommerce' ),
			Product_Pricing::MODE_NATIVE   => __( 'Native USD', 'usd-to-toman-price-sync-for-woocommerce' ),
			Product_Pricing::MODE_EXCLUDED => __( 'Excluded', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		printf(
			'<span class="usdtf-mode usdtf-mode--%1$s">%2$s</span>',
			esc_attr( $snapshot['mode'] ),
			esc_html( isset( $mode_labels[ $snapshot['mode'] ] ) ? $mode_labels[ $snapshot['mode'] ] : $snapshot['mode'] )
		);

		if ( null !== $snapshot['source_regular'] ) {
			printf(
				'<br /><span class="usdtf-toman-value">%s</span>',
				esc_html( Calculator::format_toman( $snapshot['source_regular'], array( 'persian_digits' => (bool) $this->settings->get( 'persian_digits' ), 'with_suffix' => true, 'suffix' => (string) $this->settings->get( 'toman_suffix' ) ) ) )
			);
		}

		if ( null !== $snapshot['source_sale'] ) {
			printf(
				'<br /><span class="usdtf-toman-sale">%s</span>',
				esc_html( Calculator::format_toman( $snapshot['source_sale'], array( 'persian_digits' => (bool) $this->settings->get( 'persian_digits' ), 'with_suffix' => true, 'suffix' => (string) $this->settings->get( 'toman_suffix' ) ) ) )
			);
		}

		if ( $snapshot['conflict'] ) {
			printf(
				'<br /><span class="usdtf-flag usdtf-flag--conflict">%s</span>',
				esc_html__( 'Conflict – recalculate', 'usd-to-toman-price-sync-for-woocommerce' )
			);
		}

		if ( 0 === (int) $snapshot['revision'] && null === $snapshot['source_regular'] ) {
			echo '<br /><span class="usdtf-flag">' . esc_html__( 'No Toman price yet', 'usd-to-toman-price-sync-for-woocommerce' ) . '</span>';
		}
	}

	/**
	 * Render the "pricing mode" filter on the product list.
	 *
	 * @return void
	 */
	public function render_list_filter() {
		global $typenow;

		if ( 'product' !== $typenow || ! Capabilities::current_user_can() ) {
			return;
		}

		$current = isset( $_GET['usdtf_mode_filter'] ) ? sanitize_key( wp_unslash( $_GET['usdtf_mode_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only filter.

		printf( '<select name="usdtf_mode_filter"><option value="">%s</option>', esc_html__( 'All pricing modes', 'usd-to-toman-price-sync-for-woocommerce' ) );

		$labels = array(
			Product_Pricing::MODE_MANAGED  => __( 'Toman managed', 'usd-to-toman-price-sync-for-woocommerce' ),
			Product_Pricing::MODE_NATIVE   => __( 'Native USD', 'usd-to-toman-price-sync-for-woocommerce' ),
			Product_Pricing::MODE_EXCLUDED => __( 'Excluded', 'usd-to-toman-price-sync-for-woocommerce' ),
			'unset'                        => __( 'No mode set', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		foreach ( $labels as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply the pricing mode filter.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function apply_list_filter( $query ) {
		global $pagenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}

		$filter = isset( $_GET['usdtf_mode_filter'] ) ? sanitize_key( wp_unslash( $_GET['usdtf_mode_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only filter.

		if ( '' === $filter ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();

		if ( 'unset' === $filter ) {
			$meta_query[] = array(
				'key'     => Product_Pricing::META_MODE,
				'compare' => 'NOT EXISTS',
			);
		} else {
			$meta_query[] = array(
				'key'   => Product_Pricing::META_MODE,
				'value' => $filter,
			);
		}

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Handle the "recalculate this product" action.
	 *
	 * @return void
	 */
	public function handle_recalculate() {
		$product_id = isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0;

		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to recalculate prices.', 'usd-to-toman-price-sync-for-woocommerce' ), 403 );
		}

		check_admin_referer( 'usdtf_recalculate_' . $product_id );

		$result = usdtf_plugin()->runner()->recalculate( array( $product_id ) );

		$key = 'usdtf_recalc';

		if ( is_wp_error( $result ) ) {
			$value = 'error:' . $result->get_error_code();
		} else {
			$value = 'queued:' . ( isset( $result['id'] ) ? (int) $result['id'] : 0 );
		}

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=product' );

		wp_safe_redirect( add_query_arg( $key, rawurlencode( $value ), $redirect ) );
		exit;
	}
}
