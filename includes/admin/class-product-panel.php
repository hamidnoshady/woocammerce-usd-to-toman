<?php
/**
 * Product editing integration: meta box, bulk actions and list column.
 *
 * @package USDTF
 */

namespace USDTF\Admin;

use USDTF\Capabilities;
use USDTF\Job;
use USDTF\Product_Pricing;
use USDTF\Product_Repository;
use USDTF\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the USD pricing panel to products and variations.
 */
final class Product_Panel {

	/**
	 * Nonce action for the panel form.
	 */
	const NONCE = 'usdtf_product_panel';

	/**
	 * Key of the product list column.
	 */
	const COLUMN = 'usdtf_pricing';

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

		$pricing    = $this->pricing;
		$settings   = $this->settings;
		$snapshot   = $pricing->get_snapshot( $product );
		$variations = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $this->products->all_variation_ids( $product->get_id() ) as $variation_id ) {
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

		if ( '' !== $mode ) {
			$this->save_mode( $post_id, $mode );
		}

		$variation_values = isset( $_POST['usdtf_variation'] ) && is_array( $_POST['usdtf_variation'] )
			? wp_unslash( $_POST['usdtf_variation'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed below.
			: array();

		foreach ( $variation_values as $variation_id => $values ) {
			$variation_id = (int) $variation_id;

			if ( $variation_id <= 0 || ! current_user_can( 'edit_product', $variation_id ) ) {
				continue;
			}

			if ( ! is_array( $values ) ) {
				continue;
			}

			$this->save_variation_values( $variation_id, $values );
		}
	}

	/**
	 * Persist a variation panel value.
	 *
	 * Variations are edited through the WooCommerce variations table, so only the
	 * plugin's own hidden field is read here. The mode select of a variation is
	 * part of that field set and is persisted like the parent mode.
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

		$submitted = isset( $_POST['usdtf_variation'] ) && is_array( $_POST['usdtf_variation'] )
			? wp_unslash( $_POST['usdtf_variation'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed below.
			: array();

		if ( ! isset( $submitted[ $variation_id ] ) || ! is_array( $submitted[ $variation_id ] ) ) {
			return;
		}

		$this->save_variation_values( $variation_id, $submitted[ $variation_id ] );
	}

	/**
	 * Save the mode submitted for one variation.
	 *
	 * @param int   $variation_id Variation ID.
	 * @param array $values       Submitted values for the variation.
	 * @return void
	 */
	private function save_variation_values( $variation_id, array $values ) {
		$variation_id = (int) $variation_id;

		if ( $variation_id <= 0 ) {
			return;
		}

		// The mode select is a real field of the panel, so switching a single
		// variation to excluded has to survive the save.
		$mode = isset( $values['mode'] ) ? sanitize_key( (string) $values['mode'] ) : '';

		if ( '' !== $mode ) {
			$this->save_mode( $variation_id, $mode );
		}
	}

	/**
	 * Store a submitted pricing mode: only Managed and Excluded are accepted.
	 *
	 * Anything else is refused, leaves the stored mode alone and tells the editor.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $mode       Submitted mode.
	 * @return bool Whether the mode was stored.
	 */
	private function save_mode( $product_id, $mode ) {
		if ( ! in_array( $mode, Product_Pricing::modes(), true ) ) {
			set_transient(
				'usdtf_panel_error_' . get_current_user_id(),
				__( 'The pricing mode was not changed: choose Managed or Excluded.', 'usd-to-toman-price-sync-for-woocommerce' ),
				60
			);

			return false;
		}

		$this->pricing->set_mode( $product_id, $mode );
		$this->products->flush_counts_cache();

		return true;
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

		$actions['usdtf_enable']      = __( 'USD/Toman: set Managed', 'usd-to-toman-price-sync-for-woocommerce' );
		$actions['usdtf_exclude']     = __( 'USD/Toman: set Excluded', 'usd-to-toman-price-sync-for-woocommerce' );
		$actions['usdtf_recalculate'] = __( 'USD/Toman: Recalculate selected', 'usd-to-toman-price-sync-for-woocommerce' );

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

		if ( 'usdtf_recalculate' === $action ) {
			return $this->bulk_recalculate( $redirect_to, $post_ids );
		}

		$map = array(
			'usdtf_enable'  => Product_Pricing::MODE_MANAGED,
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

			$product = wc_get_product( $post_id );

			if ( ! $product ) {
				continue;
			}

			$mode = $map[ $action ];

			$this->pricing->set_mode( $post_id, $mode );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $this->products->all_variation_ids( $post_id ) as $variation_id ) {
					$this->pricing->set_mode( $variation_id, $mode );
				}
			}

			++$count;
		}

		$this->products->flush_counts_cache();

		return add_query_arg( 'usdtf_bulk_done', $count, $redirect_to );
	}

	/**
	 * Queue a recalculation job for the selected products.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param int[]  $post_ids    Selected product IDs.
	 * @return string
	 */
	private function bulk_recalculate( $redirect_to, $post_ids ) {
		$ids = array();

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( $post_id > 0 && current_user_can( 'edit_product', $post_id ) ) {
				$ids[] = $post_id;
			}
		}

		$result = usdtf_plugin()->runner()->recalculate( $ids );

		if ( is_wp_error( $result ) ) {
			$value = 'error:' . $result->get_error_code();
		} else {
			$value = 'queued:' . ( isset( $result['id'] ) ? (int) $result['id'] : 0 );
		}

		return add_query_arg( 'usdtf_recalc', rawurlencode( $value ), $redirect_to );
	}

	/**
	 * Add the USD price column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! Capabilities::current_user_can() ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = __( 'USD price', 'usd-to-toman-price-sync-for-woocommerce' );

		return $columns;
	}

	/**
	 * Render the USD price column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return;
		}

		$snapshot = $this->pricing->get_snapshot( $product );

		$mode_labels = self::mode_labels();

		printf(
			'<span class="usdtf-mode usdtf-mode--%1$s">%2$s</span>',
			esc_attr( $snapshot['mode'] ),
			esc_html( isset( $mode_labels[ $snapshot['mode'] ] ) ? $mode_labels[ $snapshot['mode'] ] : $snapshot['mode'] )
		);

		if ( '' !== $snapshot['usd_regular'] ) {
			printf(
				'<br /><span class="usdtf-usd-value">%s</span>',
				esc_html( Admin::format_price_pair( $snapshot['usd_regular'], $snapshot['usd_sale'], 2, '$' ) )
			);
		} elseif ( $snapshot['managed'] ) {
			echo '<br /><span class="usdtf-flag">' . esc_html__( 'No USD price yet', 'usd-to-toman-price-sync-for-woocommerce' ) . '</span>';
		}

		if ( $snapshot['conflict'] ) {
			printf(
				'<br /><span class="usdtf-flag usdtf-flag--conflict">%s</span>',
				esc_html__( 'Conflict – recalculate', 'usd-to-toman-price-sync-for-woocommerce' )
			);
		}
	}

	/**
	 * Labels of the pricing modes.
	 *
	 * @return array<string,string> Mode => label.
	 */
	public static function mode_labels() {
		return array(
			Product_Pricing::MODE_MANAGED  => __( 'Managed', 'usd-to-toman-price-sync-for-woocommerce' ),
			Product_Pricing::MODE_EXCLUDED => __( 'Excluded', 'usd-to-toman-price-sync-for-woocommerce' ),
		);
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

		foreach ( self::mode_labels() as $value => $label ) {
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

		// A product without a stored mode is managed; a stored "native" is excluded.
		if ( Product_Pricing::MODE_MANAGED === $filter ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => Product_Pricing::META_MODE,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Product_Pricing::META_MODE,
					'value'   => array( Product_Pricing::MODE_EXCLUDED, 'native' ),
					'compare' => 'NOT IN',
				),
			);
		} elseif ( Product_Pricing::MODE_EXCLUDED === $filter ) {
			$meta_query[] = array(
				'key'     => Product_Pricing::META_MODE,
				'value'   => array( Product_Pricing::MODE_EXCLUDED, 'native' ),
				'compare' => 'IN',
			);
		} else {
			return;
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
