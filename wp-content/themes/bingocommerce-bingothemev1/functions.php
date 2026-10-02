<?php
/**
 * BingoThemev1 — the shared BingoCommerce Hall storefront (ADR-045 / BR-084).
 *
 * Presentation only. The theme renders native WordPress and WooCommerce output
 * inside a fixed header and footer; it makes no purchase decision, reads no
 * Catalog or Session table and writes nothing.
 *
 * Deliberately NOT declared, so Hall Managers cannot restructure the layout or
 * change the fixed v1 colours and fonts: theme.json, block-template-parts,
 * editor-styles, custom-header, custom-background and widget areas.
 */

declare(strict_types=1);

namespace BingoCommerce\BingoThemeV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/inc/template-tags.php';
require_once __DIR__ . '/inc/session-calendar.php';
require_once __DIR__ . '/inc/woocommerce.php';

final class Theme {

	public const TEXT_DOMAIN = 'bingocommerce-bingothemev1';

	public static function boot(): void {
		add_action( 'after_setup_theme', [ self::class, 'setup' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'wp_head', [ self::class, 'preload_fonts' ], 1 );
		WooCommerce::boot();
	}

	public static function setup(): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ] );
		add_theme_support(
			'custom-logo',
			[
				'height'               => 80,
				'width'                => 280,
				'flex-height'          => true,
				'flex-width'           => true,
				'unlink-homepage-logo' => false,
			]
		);
		register_nav_menus(
			[
				'primary' => __( 'Primary menu (header)', self::TEXT_DOMAIN ),
				'footer'  => __( 'Footer links, bottom right (e.g. Privacy Policy, Terms of Service)', self::TEXT_DOMAIN ),
			]
		);
	}

	public static function assets(): void {
		wp_enqueue_style( 'bingothemev1', get_theme_file_uri( 'assets/css/main.css' ), [], self::asset_version( 'assets/css/main.css' ) );
		if ( is_front_page() ) {
			wp_enqueue_script( 'bingothemev1-homepage-slider', get_theme_file_uri( 'assets/js/homepage-slider.js' ), [], self::asset_version( 'assets/js/homepage-slider.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
			// Optional: every calendar control is a real link without it.
			wp_enqueue_script( 'bingothemev1-homepage-calendar', get_theme_file_uri( 'assets/js/homepage-calendar.js' ), [], self::asset_version( 'assets/js/homepage-calendar.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
			// Optional purchase step: without it the Package cards stay informational.
			wp_enqueue_script( 'bingothemev1-homepage-purchase', get_theme_file_uri( 'assets/js/homepage-purchase.js' ), [], self::asset_version( 'assets/js/homepage-purchase.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
			wp_localize_script( 'bingothemev1-homepage-purchase', 'bingothemev1Purchase', SessionCalendar::purchase_strings() );
		}
		wp_enqueue_script(
			'bingothemev1',
			get_theme_file_uri( 'assets/js/theme.js' ),
			[],
			self::asset_version( 'assets/js/theme.js' ),
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);
	}

	/** Theme version plus file time, so a theme update never serves a stale cached asset. */
	private static function asset_version( string $file ): string {
		$mtime = @filemtime( get_theme_file_path( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return (string) wp_get_theme()->get( 'Version' ) . ( $mtime ? '.' . $mtime : '' );
	}

	/** The two Latin subsets are small and used above the fold on every page. */
	public static function preload_fonts(): void {
		foreach ( [ 'assets/fonts/inter/inter-latin-wght-normal.woff2', 'assets/fonts/baloo-2/baloo-2-latin-wght-normal.woff2' ] as $font ) {
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( get_theme_file_uri( $font ) ) );
		}
	}
}

Theme::boot();
