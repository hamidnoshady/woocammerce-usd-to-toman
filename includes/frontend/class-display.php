<?php
/**
 * Storefront display of the canonical Toman price.
 *
 * @package USDTF
 */

namespace USDTF\Frontend;

use USDTF\Calculator;
use USDTF\Product_Pricing;
use USDTF\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the displayed price with the stored Toman source price.
 *
 * The value shown is always the canonical Toman source; it is never rebuilt
 * from the rounded USD price. Totals (cart, checkout, shipping, taxes) keep
 * using the transaction currency, which is a deliberate choice: a total that
 * does not match what the gateway charges is worse than a mixed display.
 */
final class Display {

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		if ( ! $this->settings->get( 'display_toman' ) ) {
			return;
		}

		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'filter_cart_item_price' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'filter_cart_item_subtotal' ), 20, 3 );
	}

	/**
	 * Filter the formatted price HTML.
	 *
	 * This one filter covers the classic templates (shop, category, single
	 * product, related, upsell, cross-sells) and the Store API / Woo Blocks
	 * product price, because the Store API builds its `price_html` field from
	 * `WC_Product::get_price_html()`.
	 *
	 * @param string      $html    Price HTML.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function filter_price_html( $html, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $html;
		}

		if ( $this->settings->is_toman_mode() ) {
			// The price fields already hold Toman, so only the formatting differs.
			return $html;
		}

		$toman = $this->toman_price_html( $product );

		return '' === $toman ? $html : $toman;
	}

	/**
	 * Cart line item price.
	 *
	 * @param string $html         Price HTML.
	 * @param array  $cart_item    Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_cart_item_price( $html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		if ( ! $this->settings->get( 'display_toman_cart' ) || empty( $cart_item['data'] ) ) {
			return $html;
		}

		return $this->maybe_wrap( $html, $cart_item['data'] );
	}

	/**
	 * Cart line item subtotal (quantity aware).
	 *
	 * @param string $html         Subtotal HTML.
	 * @param array  $cart_item    Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function filter_cart_item_subtotal( $html, $cart_item, $cart_item_key ) {
		unset( $cart_item_key );

		if ( ! $this->settings->get( 'display_toman_cart' ) || empty( $cart_item['data'] ) ) {
			return $html;
		}

		return $this->maybe_wrap( $html, $cart_item['data'], isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1 );
	}

	/**
	 * Append the Toman reference to a cart price.
	 *
	 * @param string      $html     Original HTML.
	 * @param \WC_Product $product  Product.
	 * @param int         $quantity Quantity.
	 * @return string
	 */
	private function maybe_wrap( $html, $product, $quantity = 1 ) {
		if ( ! $this->pricing->is_managed( $product->get_id() ) ) {
			return $html;
		}

		$toman = $this->toman_price_html( $product, $quantity );

		if ( '' === $toman ) {
			return $html;
		}

		return sprintf(
			'<span class="usdtf-cart-price">%1$s <span class="usdtf-cart-price__toman">(%2$s)</span></span>',
			$html,
			$toman
		);
	}

	/**
	 * Build the Toman price HTML of a product.
	 *
	 * @param \WC_Product $product  Product.
	 * @param int         $quantity Quantity, 1 for a single price.
	 * @return string Empty string when the product should not be shown in Toman.
	 */
	public function toman_price_html( $product, $quantity = 1 ) {
		$product_id = $product->get_id();

		if ( ! $this->pricing->is_managed( $product_id ) ) {
			return '';
		}

		if ( $product->is_type( 'variable' ) ) {
			return $this->variable_price_html( $product );
		}

		$source = $this->pricing->get_source( $product_id );

		if ( 'ok' !== $source['code'] && 'empty' !== $source['code'] ) {
			return '';
		}

		if ( null === $source['regular'] && null === $source['sale'] ) {
			return '';
		}

		$quantity   = max( 1, (int) $quantity );
		$is_on_sale = null !== $source['sale'] && $product->is_on_sale();

		if ( $is_on_sale ) {
			$regular = $this->money( $this->display_price( $product, (float) $source['regular'] ) * $quantity, true, true );
			$sale    = $this->money( $this->display_price( $product, (float) $source['sale'] ) * $quantity, false, true );

			return sprintf(
				'<del aria-hidden="true">%1$s</del> <ins>%2$s</ins>',
				$regular,
				$sale
			);
		}

		$value = null !== $source['regular'] ? $source['regular'] : $source['sale'];

		return $this->money( $this->display_price( $product, (float) $value ) * $quantity );
	}

	/**
	 * Toman price range of a variable product, taken from its variations.
	 *
	 * The sale source is only used while WooCommerce considers the variation
	 * on sale (its sale dates), so a scheduled sale that ended does not keep a
	 * misleading sale price in the range.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return string
	 */
	private function variable_price_html( $product ) {
		$children = $product->get_children();

		if ( ! $children ) {
			return '';
		}

		$min = null;
		$max = null;

		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child ) {
				continue;
			}

			$source = $this->pricing->get_source( $child_id );

			$value = null;

			if ( null !== $source['sale'] && $child->is_on_sale() ) {
				$value = $this->display_price( $child, (float) $source['sale'] );
			} elseif ( null !== $source['regular'] ) {
				$value = $this->display_price( $child, (float) $source['regular'] );
			}

			if ( null === $value ) {
				continue;
			}

			$min = null === $min ? $value : min( $min, $value );
			$max = null === $max ? $value : max( $max, $value );
		}

		if ( null === $min ) {
			return '';
		}

		if ( $min === $max ) {
			return $this->money( $min );
		}

		return sprintf(
			'<span class="usdtf-price-range">%1$s – %2$s</span>',
			$this->money( $min ),
			$this->money( $max )
		);
	}

	/**
	 * Apply the store's tax display rules to a Toman amount.
	 *
	 * The Toman reference is a displayed price, so it follows the same
	 * including/excluding tax treatment WooCommerce applies to every other
	 * displayed price. With no tax rates configured (the common case for a
	 * single currency Toman store) the value passes through unchanged.
	 *
	 * @param \WC_Product $product Product the price belongs to.
	 * @param float       $toman   Toman amount.
	 * @return float
	 */
	private function display_price( $product, $toman ) {
		if ( function_exists( 'wc_get_price_to_display' ) ) {
			return (float) wc_get_price_to_display( $product, array( 'price' => (float) $toman ) );
		}

		return (float) $toman;
	}

	/**
	 * Format a Toman amount with the configured presentation.
	 *
	 * @param float $amount      Amount.
	 * @param bool  $strike      Wrap in a strike-through span.
	 * @param bool  $screen_reader Add a screen reader label.
	 * @return string
	 */
	private function money( $amount, $strike = false, $screen_reader = false ) {
		$text = Calculator::format_toman(
			$amount,
			array(
				'persian_digits' => (bool) $this->settings->get( 'persian_digits' ),
				'with_suffix'    => true,
				'suffix'         => (string) $this->settings->get( 'toman_suffix' ),
			)
		);

		$html = sprintf(
			'<span class="usdtf-price">%s</span>',
			esc_html( $text )
		);

		if ( $screen_reader ) {
			return $html;
		}

		if ( $strike ) {
			return '<span class="usdtf-price usdtf-price--regular">' . esc_html( $text ) . '</span>';
		}

		return $html;
	}
}
