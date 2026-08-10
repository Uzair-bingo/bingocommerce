<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HallContext {

	/**
	 * Return the current WordPress site/blog ID.
	 */
	public static function site_id(): int {
		return get_current_blog_id();
	}

	/**
	 * Determine whether the current request belongs to the
	 * primary network site.
	 */
	public static function is_main_site(): bool {
		return is_main_site();
	}

	/**
	 * Determine whether the current site is a hall site.
	 *
	 * IMPORTANT:
	 * For now this is intentionally conservative.
	 * We will replace this with explicit hall registration
	 * rather than assuming every non-main site is a hall.
	 */
	public static function is_hall(): bool {
		return ! self::is_main_site();
	}
}
