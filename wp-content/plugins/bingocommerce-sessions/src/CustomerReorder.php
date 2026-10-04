<?php

declare(strict_types=1);

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WooCommerce's native "Order again" copies an order's lines straight into the
 * cart, without the Bingo selection each Package and add-on line needs. An
 * order with Bingo purchase facts is therefore never ordered again, in whole or
 * in part, including mixed orders; merchandise-only orders keep the native flow.
 */
final class CustomerReorder {
	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		// Explicitly before WooCommerce's cart loader (WC_Cart_Session::get_cart_from_session, wp_loaded 10).
		add_action( 'wp_loaded', [ self::class, 'guard' ], 5 );
		// Every cart load, including the Store API's own (REST requests have no cart at wp_loaded).
		add_action( 'woocommerce_load_cart_from_session', [ self::class, 'neutralize' ], 1 );
		if ( did_action( 'init' ) ) { self::replace_button(); }
		else { add_action( 'init', [ self::class, 'replace_button' ] ); }
	}

	/** Wrap the native button only where WooCommerce still shows it. */
	public static function replace_button(): void {
		$priority = has_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
		if ( false === $priority ) { return; }
		remove_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button', $priority );
		add_action( 'woocommerce_order_details_after_order_table', [ self::class, 'button' ], $priority );
	}

	public static function button( $order ): void {
		if ( $order instanceof \WC_Order && self::has_bingo_purchase( $order ) ) { return; }
		woocommerce_order_again_button( $order );
	}

	/**
	 * Frontend requests, before WooCommerce's cart loader and without loading the
	 * cart: a Bingo order ends with the cart untouched and an explanation; a
	 * request WooCommerce would refuse, or a malformed one, ends with the cart
	 * untouched and nothing said about any order. Merchandise-only orders and
	 * requests WooCommerce itself ignores (signed out, invalid nonce string) are
	 * left to WooCommerce. REST and admin requests have no cart here.
	 */
	public static function guard(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) { return; }
		$decision = self::decision();
		if ( '' === $decision ) { return; }
		if ( 'bingo' === $decision ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: opening link to the Hall's Session calendar, 2: closing link. The link comes last, so narrow screens stack it under the sentence. */
					esc_html__( 'Bingo Packages can’t be ordered again because each one is for a specific Session. Your cart hasn’t changed. %1$sChoose a Session to buy a Package%2$s', 'bingocommerce-sessions' ),
					'<a href="' . esc_url( home_url( '/' ) . '#' . HomepageSessionCalendar::ANCHOR_CALENDAR ) . '">',
					'</a>'
				),
				'notice'
			);
		}
		self::forget_request();
		wp_safe_redirect( wc_get_cart_url() ); // drops the query string, as WooCommerce's own reorder redirect does
		exit;
	}

	/**
	 * Fires at the top of every WooCommerce cart load, before it reads the
	 * request (WC_Cart_Session::get_cart_from_session): on a Store API request,
	 * or any other load the frontend guard did not end, the same requests lose
	 * order_again so WooCommerce cannot act on them. No redirect or notice here.
	 */
	public static function neutralize(): void {
		if ( '' !== self::decision() ) { self::forget_request(); }
	}

	/**
	 * '' when the request is not WooCommerce's to act on or is a merchandise-only
	 * reorder (both left to WooCommerce); 'bingo' for the customer's own order with
	 * Bingo purchase markers; 'refused' for a request WooCommerce would refuse
	 * (another customer's order, an unknown, non-order or malformed ID) or one with
	 * array parameters (natively a PHP warning, or absint() coercing to order 1).
	 * Mirrors WooCommerce's own conditions; orders come from the current Hall's
	 * own tables only.
	 */
	private static function decision(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is verified here, as WooCommerce does.
		if ( ! isset( $_GET['order_again'], $_GET['_wpnonce'] ) || ! is_user_logged_in() ) { return ''; }
		$id = $_GET['order_again'];
		$nonce = $_GET['_wpnonce'];
		// phpcs:enable
		// An invalid nonce string: WooCommerce ignores the request itself.
		if ( is_string( $nonce ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'woocommerce-order_again' ) ) { return ''; }
		$order = is_string( $nonce ) && is_string( $id ) && ctype_digit( $id ) ? wc_get_order( (int) $id ) : false;
		$statuses = apply_filters( 'woocommerce_valid_order_statuses_for_order_again', [ 'completed' ] );
		if ( ! $order instanceof \WC_Order || ! $order->has_status( $statuses ) || ! current_user_can( 'order_again', $order->get_id() ) ) { return 'refused'; }
		return self::has_bingo_purchase( $order ) ? 'bingo' : '';
	}

	/** The same markers CustomerRefund treats as a Bingo order: order facts or any line's binding or saved labels. */
	private static function has_bingo_purchase( \WC_Order $order ): bool {
		if ( '' !== $order->get_meta( CustomerOrder::META, true ) ) { return true; }
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			foreach ( [ CustomerOrder::META, 'bc_bingo_session', 'bc_package' ] as $key ) {
				if ( '' !== $item->get_meta( $key, true ) ) { return true; }
			}
		}
		return false;
	}

	/**
	 * The request parameter goes before any redirect: exit runs WooCommerce's
	 * shutdown hook, which loads the cart (WC_Cart_Session::maybe_set_cart_cookies
	 * → get_cart) and would otherwise still act on it before the session, and any
	 * notice, is saved.
	 */
	private static function forget_request(): void {
		unset( $_GET['order_again'], $_REQUEST['order_again'] );
	}
}
