<?php
/**
 * WooCommerce presentation integration. No template overrides: WooCommerce's
 * own shop, product, cart, checkout, account and order templates render
 * unchanged, including every hook other plugins attach to (such as the digital
 * ticket view after the order table). Only the outer content wrapper is swapped
 * for the theme's container and the unused shop sidebar is removed.
 *
 * Declared support intentionally omits product-grid and image-size defaults:
 * WooCommerce would otherwise rewrite the Hall's catalog settings (and flush the
 * whole object cache) when the theme is activated.
 */

declare(strict_types=1);

namespace BingoCommerce\BingoThemeV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce {

	public static function boot(): void {
		add_action( 'after_setup_theme', [ self::class, 'support' ] );
		add_action( 'wp', [ self::class, 'wrappers' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'assets' ], 20 );
		add_filter( 'woocommerce_add_to_cart_fragments', [ self::class, 'cart_fragment' ] );
	}

	public static function support(): void {
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	}

	/** Replace WooCommerce's default `#primary > main` wrapper; the header already opens `<main>`. */
	public static function wrappers(): void {
		if ( ! function_exists( 'woocommerce_output_content_wrapper' ) ) {
			return;
		}
		remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
		remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
		remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
		add_action( 'woocommerce_before_main_content', [ self::class, 'open' ], 5 );
		add_action( 'woocommerce_after_main_content', [ self::class, 'close' ], 50 );
	}

	public static function open(): void {
		echo '<div class="container shop-shell">';
	}

	public static function close(): void {
		echo '</div>';
	}

	/**
	 * WooCommerce 7.8+ no longer loads cart fragments on every page; the header
	 * count relies on them after classic AJAX add-to-cart and for stale counts.
	 */
	public static function assets(): void {
		if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}
	}

	/**
	 * @param array<string,string> $fragments
	 * @return array<string,string>
	 */
	public static function cart_fragment( $fragments ): array {
		$fragments = is_array( $fragments ) ? $fragments : [];
		$link      = Template::cart_link();
		if ( '' !== $link ) {
			$fragments['a.bc-cart-link'] = $link;
		}
		return $fragments;
	}
}
