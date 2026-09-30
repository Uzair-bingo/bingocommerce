<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BR-084: storefront colours and fonts are fixed in v1.
 *
 * WordPress stores a site's editable global styles (fonts, colours, element and
 * block styles, and Font Library activations) in a `wp_global_styles` post and
 * renders them on every page, including over the classic BingoThemev1 theme.
 * Core maps writes to that post to `edit_theme_options`, which Hall Managers
 * hold for native logo and menu editing. On Hall sites this guard therefore
 * limits edit/delete/publish of `wp_global_styles` to Super Admins (Platform
 * Admin). Reading global styles, and every other `edit_theme_options` use —
 * Customizer logo, Menus, page editing — is unchanged. No role is modified.
 *
 * The Appearance → Fonts entry is removed for the same users only because its
 * saves are refused above; the menu change is presentation, the capability
 * mapping is the authorization.
 */
final class StorefrontStyleGuard {

	private const WRITE_CAPS = [ 'edit_post', 'delete_post', 'publish_post' ];

	/** WordPress 7.0.3 registers Fonts as `$submenu['themes.php'][9]` in wp-admin/menu.php. */
	private const FONTS_PARENT = 'themes.php';
	private const FONTS_SLUG   = 'font-library.php';

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 10, 4 );
		// Late, after core and plugins have built the Appearance submenu.
		add_action( 'admin_menu', [ self::class, 'hide_fonts_menu' ], 999 );
	}

	/** Users whose Hall global-styles writes are refused: everyone but Super Admins, on Hall sites. */
	public static function is_restricted( int $user_id ): bool {
		return HallContext::is_hall() && ! is_super_admin( $user_id );
	}

	/**
	 * @param array<int,string> $caps
	 * @param string            $cap
	 * @param int               $user_id
	 * @param array<int,mixed>  $args
	 * @return array<int,string>
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, self::WRITE_CAPS, true ) || empty( $args[0] ) || ! self::is_restricted( (int) $user_id ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( $post instanceof \WP_Post && 'revision' === $post->post_type ) {
			$post = get_post( (int) $post->post_parent );
		}
		if ( ! $post instanceof \WP_Post || 'wp_global_styles' !== $post->post_type ) {
			return $caps;
		}
		return [ 'do_not_allow' ];
	}

	/** Do not offer Appearance → Fonts (or its Themes-screen button) where its saves are refused. */
	public static function hide_fonts_menu(): void {
		if ( self::is_restricted( get_current_user_id() ) ) {
			remove_submenu_page( self::FONTS_PARENT, self::FONTS_SLUG );
		}
	}
}
