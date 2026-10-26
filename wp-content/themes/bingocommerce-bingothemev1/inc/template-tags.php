<?php
/**
 * Rendering helpers for the fixed storefront. Every value is escaped at
 * output; Store Details arrive as plain strings from the core plugin's read-only
 * `bingocommerce_store_details` filter and are never trusted as markup.
 */

declare(strict_types=1);

namespace BingoCommerce\BingoThemeV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Template {

	private const DETAIL_KEYS = [
		'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
		'phone', 'phone_href', 'email', 'facebook_url', 'instagram_url', 'youtube_url', 'tiktok_url',
	];

	/**
	 * Store Details for the current Hall, every key present as a string. Blank
	 * when the core plugin is unavailable, so the theme never fails a page.
	 *
	 * Deliberately not cached here: a request may switch Halls, and the core
	 * filter already reads an autoloaded, per-site option.
	 *
	 * @return array<string,string>
	 */
	public static function details(): array {
		$provided = apply_filters( 'bingocommerce_store_details', [] );
		$provided = is_array( $provided ) ? $provided : [];
		$details  = [];
		foreach ( self::DETAIL_KEYS as $key ) {
			$details[ $key ] = is_string( $provided[ $key ] ?? null ) ? $provided[ $key ] : '';
		}
		return $details;
	}

	/** @return array<int,string> Non-empty address lines in display order. */
	public static function address_lines(): array {
		$d        = self::details();
		$locality = trim( implode( ' ', array_filter( [ trim( implode( ', ', array_filter( [ $d['city'], $d['state'] ] ) ) ), $d['postal_code'] ] ) ) );
		return array_values( array_filter( [ $d['address_line_1'], $d['address_line_2'], $locality, $d['country'] ] ) );
	}

	/** Unescaped embed URL from the current Hall's public address; no separate map setting. */
	public static function map_url(): string {
		$details = self::details();
		if ( '' === trim( $details['address_line_1'] ) ) {
			return '';
		}
		$address = [];
		foreach ( [ 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ] as $key ) {
			$value = trim( $details[ $key ] );
			if ( '' !== $value ) {
				$address[] = $value;
			}
		}
		return 'https://www.google.com/maps?q=' . rawurlencode( implode( ', ', $address ) ) . '&output=embed';
	}

	public static function has_contact(): bool {
		$d = self::details();
		return [] !== self::address_lines() || '' !== $d['phone'] || '' !== $d['email'];
	}

	/** Native custom logo, or the site name when no logo is set. */
	public static function logo( string $context = 'header' ): void {
		$class = 'footer' === $context ? 'logo logo--footer' : 'logo';
		if ( has_custom_logo() ) {
			echo '<div class="' . esc_attr( $class ) . '">' . get_custom_logo() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped markup.
			return;
		}
		printf(
			'<div class="%1$s logo--text"><a href="%2$s" rel="home"%3$s>%4$s</a></div>',
			esc_attr( $class ),
			esc_url( home_url( '/' ) ),
			is_front_page() ? ' aria-current="page"' : '',
			esc_html( get_bloginfo( 'name' ) )
		);
	}

	/** Primary menu; without an assigned menu, the same automatic page list the Halls show today. */
	public static function primary_menu(): void {
		wp_nav_menu(
			[
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'main-nav__list',
				'menu_id'        => 'primary-menu',
				'depth'          => 2,
				'fallback_cb'    => [ self::class, 'page_list_fallback' ],
			]
		);
	}

	public static function page_list_fallback(): void {
		$pages = wp_list_pages( [ 'title_li' => '', 'echo' => false, 'depth' => 2, 'sort_column' => 'menu_order,post_title' ] );
		if ( '' !== trim( (string) $pages ) ) {
			echo '<ul id="primary-menu" class="main-nav__list">' . $pages . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped markup.
		}
	}

	public static function footer_menu(): void {
		if ( ! has_nav_menu( 'footer' ) ) {
			return;
		}
		echo '<nav class="site-footer__nav" aria-label="' . esc_attr__( 'Footer', Theme::TEXT_DOMAIN ) . '">';
		wp_nav_menu(
			[
				'theme_location' => 'footer',
				'container'      => false,
				'menu_class'     => 'legal-links',
				'depth'          => 1,
				'fallback_cb'    => false,
			]
		);
		echo '</nav>';
	}

	public static function account_link(): void {
		$url   = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		$label = is_user_logged_in() ? __( 'My account', Theme::TEXT_DOMAIN ) : __( 'Sign in or create an account', Theme::TEXT_DOMAIN );
		printf(
			'<a href="%1$s" class="icon-btn" aria-label="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( $label ),
			self::icon( 'account' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		);
	}

	/** Header cart link; also returned as the WooCommerce cart fragment. */
	public static function cart_link(): string {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_cart_url' ) ) {
			return '';
		}
		$count = WC()->cart instanceof \WC_Cart ? (int) WC()->cart->get_cart_contents_count() : 0;
		/* translators: %d: number of items in the cart. */
		$one = __( 'Cart, %d item', Theme::TEXT_DOMAIN );
		/* translators: %d: number of items in the cart. */
		$many = __( 'Cart, %d items', Theme::TEXT_DOMAIN );
		return sprintf(
			'<a href="%1$s" class="icon-btn icon-btn--cart bc-cart-link" aria-label="%2$s" data-label-one="%3$s" data-label-many="%4$s">%5$s<span class="cart-count" aria-hidden="true">%6$s</span></a>',
			esc_url( wc_get_cart_url() ),
			esc_attr( sprintf( 1 === $count ? $one : $many, $count ) ),
			esc_attr( $one ),
			esc_attr( $many ),
			self::icon( 'cart' ),
			esc_html( (string) $count )
		);
	}

	/** Header phone button from Store Details; omitted when no valid phone is saved. */
	public static function support_line(): void {
		$d = self::details();
		if ( '' === $d['phone'] || '' === $d['phone_href'] ) {
			return;
		}
		printf(
			'<a href="%1$s" class="support-line" aria-label="%2$s">%3$s<span class="support-line__text">%4$s</span></a>',
			esc_url( $d['phone_href'], [ 'tel' ] ),
			/* translators: %s: Hall phone number. */
			esc_attr( sprintf( __( 'Call %s', Theme::TEXT_DOMAIN ), $d['phone'] ) ),
			self::icon( 'phone' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			esc_html( $d['phone'] )
		);
	}

	/**
	 * Address on its own line, then phone and email together on the next line;
	 * nothing when none is saved.
	 */
	public static function contact( string $class = 'visit-contact' ): void {
		$d       = self::details();
		$address = implode( ', ', self::address_lines() );
		$phone   = '' !== $d['phone'] && '' !== $d['phone_href'];
		$email   = '' !== $d['email'];
		if ( '' === $address && ! $phone && ! $email ) {
			return;
		}
		echo '<div class="' . esc_attr( $class ) . '">';
		if ( '' !== $address ) {
			printf( '<address class="visit-contact__row visit-contact__address"><span class="visit-contact__item">%1$s<span>%2$s</span></span></address>', self::icon( 'pin' ), esc_html( $address ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG; text escaped.
		}
		if ( $phone || $email ) {
			echo '<p class="visit-contact__row visit-contact__reach">';
			if ( $phone ) {
				printf( '<a class="visit-contact__item" href="%1$s">%2$s<span>%3$s</span></a>', esc_url( $d['phone_href'], [ 'tel' ] ), self::icon( 'phone' ), esc_html( $d['phone'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( $email ) {
				printf( '<a class="visit-contact__item" href="%1$s">%2$s<span>%3$s</span></a>', esc_url( 'mailto:' . $d['email'], [ 'mailto' ] ), self::icon( 'mail' ), esc_html( $d['email'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/** Only networks with a saved link are shown; nothing at all when none is saved. */
	public static function social_links(): void {
		$d        = self::details();
		$networks = [
			'facebook_url'  => [ 'Facebook', 'facebook' ],
			'instagram_url' => [ 'Instagram', 'instagram' ],
			'youtube_url'   => [ 'YouTube', 'youtube' ],
			'tiktok_url'    => [ 'TikTok', 'tiktok' ],
		];
		$items = '';
		foreach ( $networks as $key => [ $label, $icon ] ) {
			if ( '' === $d[ $key ] ) {
				continue;
			}
			$items .= sprintf(
				'<li><a href="%1$s" class="social-icon" aria-label="%2$s">%3$s</a></li>',
				esc_url( $d[ $key ] ),
				esc_attr( $label ),
				self::icon( $icon )
			);
		}
		if ( '' !== $items ) {
			echo '<ul class="social-row" aria-label="' . esc_attr__( 'Social media', Theme::TEXT_DOMAIN ) . '">' . $items . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per item.
		}
	}

	/** Static, decorative inline SVG. */
	public static function icon( string $name ): string {
		$paths = [
			'phone'     => '<path d="M4 3h3l1.5 4-2 1.5a11 11 0 005.5 5.5L13.5 12l4 1.5v3a1.5 1.5 0 01-1.6 1.5C9.5 17.5 2.5 10.5 2.5 4.6A1.5 1.5 0 014 3z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			'account'   => '<circle cx="10" cy="6.5" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M2.5 17.5c1.2-3.6 4.2-5.5 7.5-5.5s6.3 1.9 7.5 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
			'cart'      => '<path d="M1 1h2l1.6 10.6a2 2 0 002 1.7h9a2 2 0 002-1.8L19 5.5H4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="18" r="1.3" fill="currentColor"/><circle cx="16" cy="18" r="1.3" fill="currentColor"/>',
			'pin'       => '<path d="M10 18s6-5.2 6-10A6 6 0 004 8c0 4.8 6 10 6 10z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="10" cy="8" r="2.2" stroke="currentColor" stroke-width="1.6"/>',
			'calendar'  => '<rect x="2.75" y="4" width="14.5" height="13.25" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M2.75 8.5h14.5M6.75 2.5v3M13.25 2.5v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
			'search'    => '<circle cx="8.5" cy="8.5" r="5.75" stroke="currentColor" stroke-width="1.6"/><path d="M12.75 12.75l4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
			'image'     => '<rect x="2.5" y="3.5" width="15" height="13" rx="2.5" stroke="currentColor" stroke-width="1.6"/><circle cx="7.25" cy="8" r="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 14.5l4-4 3.25 3.25L12.5 11.5l4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			'mail'      => '<rect x="2" y="4" width="16" height="12" rx="2.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 5.5l7 5.5 7-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			// Trust row icons, as drawn in the reference homepage.
			'lock'      => '<rect x="4" y="9" width="12" height="8" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M6.5 9V6.5a3.5 3.5 0 017 0V9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
			'star'      => '<path d="M10 2.5l2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5-3.6-3.5 5-.7L10 2.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
			'refresh'   => '<path d="M4 9a6 6 0 0110-4.5M16 4v3.5H12.5M16 11a6 6 0 01-10 4.5M4 16v-3.5h3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			'shield'    => '<path d="M10 2.5l6 2.2v4.8c0 4-2.6 6.7-6 7.8-3.4-1.1-6-3.8-6-7.8V4.7l6-2.2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M7.3 10l1.9 1.9L12.7 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
			'facebook'  => '<circle cx="12" cy="12" r="9.5" stroke="currentColor" stroke-width="1.8"/><path d="M13.8 8.6h1.6V6.2h-1.9c-1.7 0-2.7 1-2.7 2.7v1.4H9.2v2.4h1.6V18h2.4v-5.3h1.8l.3-2.4h-2.1V9.2c0-.4.2-.6.6-.6z" fill="currentColor"/>',
			'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.8"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor"/>',
			'youtube'   => '<rect x="2.5" y="5.5" width="19" height="13" rx="4" stroke="currentColor" stroke-width="1.8"/><path d="M10.2 9.3v5.4l4.6-2.7-4.6-2.7z" fill="currentColor"/>',
			'tiktok'    => '<path d="M14 3.5v10.6a3.4 3.4 0 11-3.4-3.4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 3.5c.4 2.6 2.1 4.2 4.7 4.4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>',
		];
		$view = in_array( $name, [ 'facebook', 'instagram', 'youtube', 'tiktok' ], true ) ? '0 0 24 24' : '0 0 20 20';
		$size = in_array( $name, [ 'facebook', 'instagram', 'youtube', 'tiktok' ], true ) ? 17 : 20;
		return sprintf( '<svg width="%1$d" height="%1$d" viewBox="%2$s" fill="none" aria-hidden="true" focusable="false">%3$s</svg>', $size, $view, $paths[ $name ] ?? '' );
	}
}
