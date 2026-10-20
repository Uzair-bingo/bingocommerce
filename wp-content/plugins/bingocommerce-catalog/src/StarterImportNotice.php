<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 section 8: the Hall dashboard notice that points to Products →
 * Starter Products.
 *
 * Shown on a Hall's dashboard only, to users who can import (Hall Managers
 * and Super Admins), when the Hall's Commerce access is not blocked, and
 * either:
 *   - nothing has been imported yet, new imports are on and a version is
 *     published: "Bingo Innovation starter products are available …"; or
 *   - an import started but is not finished (importing, failed or needing
 *     attention), whether or not new imports are on: "… isn't finished".
 * A finished import never shows it.
 *
 * Dismiss is a small POST form to admin-post.php with its own nonce; it
 * saves a per-user, per-Hall setting (update_user_option). The dashboard
 * only reads saved settings and at most one indexed query; it never creates
 * tables.
 */
final class StarterImportNotice {

	public const DISMISSED_OPTION = 'bc_starter_import_notice_dismissed';
	public const DISMISS_ACTION   = 'bc_starter_import_notice_dismiss';

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ self::class, 'dismiss' ] );
	}

	/** On the Hall dashboard, when one of the two cases applies. */
	public static function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || 'dashboard' !== $screen->id ) {
			return;
		}

		$message = self::message();
		if ( null === $message ) {
			return;
		}
		?>
		<div class="notice notice-info bc-starter-import-notice">
			<p>
				<?php echo esc_html( $message ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=' . StarterImportAdmin::PAGE ) ); ?>"><?php echo esc_html__( 'Open Starter Products', 'bingocommerce-catalog' ); ?></a>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DISMISS_ACTION ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::nonce_action() ) ); ?>">

				<p><button type="submit" class="button-link"><?php echo esc_html__( 'Dismiss this notice', 'bingocommerce-catalog' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * The notice text for the current user on the current Hall, or null when
	 * there is none to show.
	 */
	public static function message(): ?string {
		if ( ! self::may_import() ) {
			return null;
		}
		if ( '1' === (string) get_user_option( self::DISMISSED_OPTION ) ) {
			return null;
		}
		// Blocked or suspended Halls cannot import; without the evaluator the
		// import is unavailable, so there is nothing to point to.
		if ( ! class_exists( '\BingoCommerce\PortalIntegration\HallCommerceAccess' )
			|| \BingoCommerce\PortalIntegration\HallCommerceAccess::is_blocked( (int) get_current_blog_id() ) ) {
			return null;
		}

		if ( StarterImportMigrations::is_hall_current() ) {
			$receipt = StarterImportRepository::receipt();
			if ( is_wp_error( $receipt ) ) {
				return null;
			}
			if ( is_array( $receipt ) ) {
				return StarterImportRepository::RECEIPT_COMPLETE === $receipt['status']
					? null
					: __( 'Your Bingo Innovation starter product import isn\'t finished.', 'bingocommerce-catalog' );
			}
		}

		// Not started. A start marker without a receipt is a repair case the
		// Starter Products page explains; it is not "available".
		if ( '' !== (string) get_option( StarterImportRepository::START_MARKER_OPTION, '' ) ) {
			return null;
		}
		if ( ! StarterImportMigrations::is_network_current() || ! StarterCatalogVersions::imports_allowed() ) {
			return null;
		}
		$version = StarterCatalogVersions::latest_published();
		if ( ! is_array( $version ) ) {
			return null;
		}

		return __( 'Bingo Innovation starter products are available for your store. You can import them once, as draft products.', 'bingocommerce-catalog' );
	}

	/** admin-post: save the dismissal, then return to the dashboard. */
	public static function dismiss(): void {
		$result = self::handle_dismiss( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_dismiss().
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 403 ] );
		}

		wp_safe_redirect( admin_url(), 303 );
		exit;
	}

	/**
	 * Save the current user's dismissal for the current Hall.
	 *
	 * @param array<string,mixed> $posted Unslashed POST fields.
	 * @return true|\WP_Error
	 */
	public static function handle_dismiss( array $posted ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::may_import() ) {
			return new \WP_Error( 'starter_import_notice_denied', __( 'You are not allowed to change this notice.', 'bingocommerce-catalog' ) );
		}
		if ( ! is_string( $posted['_wpnonce'] ?? null ) || ! wp_verify_nonce( $posted['_wpnonce'], self::nonce_action() ) ) {
			return new \WP_Error( 'starter_import_notice_nonce', __( 'Verification failed. Reload the dashboard and try again.', 'bingocommerce-catalog' ) );
		}

		update_user_option( get_current_user_id(), self::DISMISSED_OPTION, '1' );

		return '1' === (string) get_user_option( self::DISMISSED_OPTION )
			? true
			: new \WP_Error( 'starter_import_notice_storage', __( 'The notice could not be dismissed. Please try again.', 'bingocommerce-catalog' ) );
	}

	/** A signed-in Hall Manager or Super Admin on a Hall site. */
	private static function may_import(): bool {
		return is_multisite() && is_user_logged_in()
			&& class_exists( HallContext::class ) && HallContext::is_hall()
			&& ( is_super_admin() || current_user_can( 'bc_manage_products' ) );
	}

	private static function nonce_action(): string {
		return 'bingocommerce_starter_import_notice:hall:' . get_current_blog_id();
	}
}
