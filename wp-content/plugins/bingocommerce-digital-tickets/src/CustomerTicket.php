<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

use Automattic\WooCommerce\Internal\Utilities\Users;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Render only inside Woo's customer order details; no public QR/image endpoint. */
final class CustomerTicket {
	/**
	 * The waiting area is for online card payments only. Stripe Gateway 11.0.0
	 * records card payments (and Link/wallet payments made through its card
	 * element) under its main gateway ID; its other methods use their own
	 * stripe_<type> IDs, and Woo's offline gateways (bacs, cheque, cod) never match.
	 */
	private const ONLINE_CARD_GATEWAYS = [ 'stripe' ];

	/** The waiting area's script and stylesheet version. */
	private const VERSION = '1.3.2';

	/** The thank-you line's read for each Hall:order, reused once by render() so both show the same state. */
	private static array $noticed = [];

	/** Native paid-order emails precede QR issuance. Link to the guarded view, not a QR. */
	public static function email_order_link( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin || ! $email instanceof \WC_Email || ! $email->is_customer_email()
			|| ! in_array( $email->id, [ 'customer_processing_order', 'customer_completed_order' ], true )
			|| ! $order instanceof \WC_Order || ! Migrations::is_hall() ) { return; }
		$current = wc_get_order( $order->get_id() );
		if ( ! $current instanceof \WC_Order || ! hash_equals( $current->get_order_key(), $order->get_order_key() )
			|| ! self::paid( $current ) || ! self::digital_order( $current ) ) { return; }
		// The normal Woo URL/key reveals no ticket reference. Its destination must
		// authorize the viewer and read current entitlement/terminal state afresh.
		$url = $current->get_checkout_order_received_url();
		$label = __( 'View your Bingo order', 'bingocommerce-digital-tickets' );
		if ( $plain_text ) { echo "\n" . esc_html( $label ) . ': ' . esc_url_raw( $url ) . "\n"; }
		else { echo self::email_button( $url, $label ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * An email-safe button in the Hall's WooCommerce email colour: a link styled
	 * inline, inside a table cell so clients such as Outlook still show the colour.
	 * The cells set padding:0 because WooCommerce's email styles pad table cells.
	 */
	private static function email_button( string $url, string $label ): string {
		$base = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color' ) );
		$base = $base ? $base : '#720eec';
		// The same contrasting text colour WooCommerce's email styles use on the base colour.
		$text = wc_light_or_dark( $base, '#202020', '#ffffff' );
		$button = 'display:inline-block;box-sizing:border-box;min-width:220px;max-width:100%;padding:14px 28px;border-radius:6px;'
			. 'background-color:' . $base . ';color:' . $text . ';font-size:16px;font-weight:bold;line-height:20px;text-align:center;text-decoration:none;';
		return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:24px 0;"><tr><td align="center" style="padding:0;">'
			. '<table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="' . esc_attr( $base ) . '" style="padding:0;border-radius:6px;background-color:' . esc_attr( $base ) . ';">'
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="' . esc_attr( $button ) . '">' . esc_html( $label ) . '</a>'
			. '</td></tr></table></td></tr></table>';
	}

	public static function render( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Migrations::is_hall() ) { return; }
		// Reload in this Hall; never accept a foreign-Hall object as authorization.
		$current = wc_get_order( $order->get_id() );
		if ( ! $current instanceof \WC_Order || ! hash_equals( $current->get_order_key(), $order->get_order_key() ) || ! self::can_view( $current ) ) { return; }
		$key = get_current_blog_id() . ':' . $current->get_id();
		if ( array_key_exists( $key, self::$noticed ) ) { $status = self::$noticed[ $key ]; unset( self::$noticed[ $key ] ); }
		else { $status = self::current( $current ); }
		if ( null === $status ) { return; }
		if ( 'ready' !== $status['state'] ) { self::waiting( $current, $status['state'] ); return; }
		self::assets();
		echo '<section class="bc-digital-ticket" data-bc-ticket-scope="' . esc_attr( get_current_blog_id() . ':' . $current->get_id() ) . '"><h2>' . esc_html__( 'Digital Bingo ticket', 'bingocommerce-digital-tickets' ) . '</h2>';
		echo '<p>' . esc_html__( 'This QR represents the digital part of your Bingo Package only. Keep it private.', 'bingocommerce-digital-tickets' ) . '</p>';
		echo '<canvas width="296" height="296" data-bc-ticket="' . esc_attr( $status['reference'] ) . '" role="img" aria-label="' . esc_attr__( 'Digital Bingo ticket QR', 'bingocommerce-digital-tickets' ) . '"></canvas>';
		$validity = self::validity( $current );
		if ( null !== $validity ) { echo '<p data-bc-ticket-validity><strong>' . esc_html( $validity[0] ) . '</strong> ' . esc_html( $validity[1] ) . '</p>'; }
		echo '<noscript>' . esc_html__( 'Enable JavaScript to display your QR.', 'bingocommerce-digital-tickets' ) . '</noscript></section>';
	}

	/**
	 * Order Received's "Thank you. Your order has been received." line on a page
	 * with a ticket waiting area. Both lines are supplied so the script can switch
	 * between them as the state changes, and only the one for the current state is
	 * visible: "confirming your payment" while payment is pending ('payment');
	 * WooCommerce's own (filtered, translated) line otherwise ('ticket', or
	 * 'unavailable', from which nothing about payment is inferred). Pages without
	 * a waiting area keep WooCommerce's line untouched. Same authorization and
	 * read as render().
	 */
	public static function received_notice( $message, $order ) {
		if ( ! $order instanceof \WC_Order || ! Migrations::is_hall() ) { return $message; }
		$current = wc_get_order( $order->get_id() );
		if ( ! $current instanceof \WC_Order || ! hash_equals( $current->get_order_key(), $order->get_order_key() ) || ! self::can_view( $current ) ) { return $message; }
		$scope = get_current_blog_id() . ':' . $current->get_id();
		$status = self::$noticed[ $scope ] = self::current( $current );
		$state = $status['state'] ?? null;
		if ( ! in_array( $state, [ 'payment', 'ticket', 'unavailable' ], true ) ) { return $message; }
		$confirming = 'payment' === $state;
		return '<span data-bc-ticket-notice="payment" data-bc-ticket-for="' . esc_attr( $scope ) . '"' . ( $confirming ? '' : ' hidden' ) . '>' . esc_html__( 'Thank you. We’re confirming your payment. This usually takes a few seconds.', 'bingocommerce-digital-tickets' ) . '</span>'
			. '<span data-bc-ticket-notice="received" data-bc-ticket-for="' . esc_attr( $scope ) . '"' . ( $confirming ? ' hidden' : '' ) . '>' . $message . '</span>';
	}

	/**
	 * The ticket area's state for a viewer already authorized for this order:
	 * 'ready' with the reference of a ticket that may be displayed now, a waiting
	 * state, or null (nothing to show). Read-only; the order page and the
	 * TicketStatus route both use it.
	 */
	public static function current( \WC_Order $order ): ?array {
		global $wpdb;
		$reference = DigitalTicket::reference_for_order( $order->get_id() );
		if ( null !== $reference ) { return [ 'state' => 'ready', 'reference' => $reference ]; }
		// reference_for_order() also returns null when its single read fails; that read's error is still here.
		$state = self::waiting_state( $order, '' !== $wpdb->last_error );
		return null === $state ? null : [ 'state' => $state ];
	}

	/**
	 * A ticket area for an eligible online card order whose ticket is not shown:
	 * 'payment' (confirmation pending), 'ticket' (paid, no ticket yet) or
	 * 'unavailable' (a read failed). Null shows nothing, as before.
	 */
	private static function waiting_state( \WC_Order $order, bool $unreadable ): ?string {
		if ( ! in_array( $order->get_payment_method( 'edit' ), self::ONLINE_CARD_GATEWAYS, true ) || ! self::digital_order( $order ) ) { return null; }
		if ( self::paid( $order ) ) { $state = 'ticket'; }
		elseif ( $order->has_status( [ 'pending', 'on-hold' ] ) ) { $state = 'payment'; }
		else { return null; } // Failed, cancelled, refunded, draft and any other status.
		if ( $unreadable ) { return 'unavailable'; }
		$blocked = EntitlementVoiding::display_blocked( $order->get_id() );
		if ( null === $blocked ) { return 'unavailable'; }
		return $blocked ? null : $state;
	}

	private static function waiting( \WC_Order $order, string $state ): void {
		$messages = [
			'payment'     => __( 'Payment hasn’t been confirmed. If you have already submitted payment, please don’t pay again while confirmation is pending.', 'bingocommerce-digital-tickets' ),
			'ticket'      => __( 'Your payment is confirmed, but your Digital Bingo ticket isn’t available yet.', 'bingocommerce-digital-tickets' ),
			'unavailable' => __( 'Your Digital Bingo ticket can’t be checked right now. If you have already submitted payment, please don’t pay again.', 'bingocommerce-digital-tickets' ),
		];
		self::assets();
		$validity = self::validity( $order );
		// The waiting area's small loading sign; it takes the theme's text colour.
		wp_enqueue_style( 'bc-customer-ticket', plugins_url( 'assets/customer-ticket.css', dirname( __DIR__ ) . '/bingocommerce-digital-tickets.php' ), [], self::VERSION );
		wp_localize_script( 'bc-customer-ticket', 'bcCustomerTicket', [
			'checking'         => __( 'This page will check again automatically for a short time.', 'bingocommerce-digital-tickets' ),
			'checkAgain'       => __( 'Check again', 'bingocommerce-digital-tickets' ),
			'checkingNow'      => __( 'Checking…', 'bingocommerce-digital-tickets' ),
			'exhausted'        => __( 'Your ticket still isn’t shown. Use Check again or reload this page later. If it doesn’t appear, contact the Hall.', 'bingocommerce-digital-tickets' ),
			'network'          => __( 'We couldn’t check for your ticket just now. Use Check again or reload this page later.', 'bingocommerce-digital-tickets' ),
			'unexpected'       => __( 'We couldn’t check for your ticket automatically. Use Check again, or reload this page to see the latest details.', 'bingocommerce-digital-tickets' ),
			'storage'          => __( 'Automatic checking isn’t available in this browser. Use Check again or reload this page later.', 'bingocommerce-digital-tickets' ),
			'still'            => __( 'Your ticket still isn’t available. Use Check again later or reload this page.', 'bingocommerce-digital-tickets' ),
			'manual'           => __( 'Use Check again or reload this page later.', 'bingocommerce-digital-tickets' ),
			'reload'           => __( 'Reload this page later to check for your ticket.', 'bingocommerce-digital-tickets' ),
			'denied'           => __( 'This page can no longer check for your ticket. Reload this page to check again.', 'bingocommerce-digital-tickets' ),
			'closed'           => __( 'A Digital Bingo ticket can’t be shown for this order. Reload this page to see the latest order details.', 'bingocommerce-digital-tickets' ),
			'ready'            => __( 'Your Digital Bingo ticket is now shown.', 'bingocommerce-digital-tickets' ),
			'statePayment'     => $messages['payment'],
			'stateTicket'      => $messages['ticket'],
			'stateUnavailable' => $messages['unavailable'],
			'heading'          => __( 'Digital Bingo ticket', 'bingocommerce-digital-tickets' ),
			'intro'            => __( 'This QR represents the digital part of your Bingo Package only. Keep it private.', 'bingocommerce-digital-tickets' ),
			'label'            => __( 'Digital Bingo ticket QR', 'bingocommerce-digital-tickets' ),
			// Shown under a QR that appears on this page later; empty when the order's saved facts can't be read.
			'validLead'        => $validity[0] ?? '',
			'validDetail'      => $validity[1] ?? '',
		] );
		$attributes = ' data-bc-ticket-scope="' . esc_attr( get_current_blog_id() . ':' . $order->get_id() ) . '" data-bc-ticket-wait="' . esc_attr( $state ) . '"';
		$endpoint = TicketStatus::url();
		if ( '' !== $endpoint ) {
			// This viewer already passed can_view(): carry exactly that access into the background checks.
			$attributes .= ' data-bc-ticket-status="' . esc_url( $endpoint ) . '" data-bc-ticket-credential="' . esc_attr( TicketStatus::credential( $order ) ) . '"';
			if ( get_current_user_id() > 0 ) { $attributes .= ' data-bc-ticket-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"'; }
		}
		echo '<section class="bc-digital-ticket"' . $attributes . '>';
		echo '<h2>' . esc_html__( 'Digital Bingo ticket', 'bingocommerce-digital-tickets' ) . '</h2>';
		echo '<p data-bc-ticket-message>' . esc_html( $messages[ $state ] ) . '</p>';
		echo '<noscript><p>' . esc_html__( 'Reload this page later to check for your Digital Bingo ticket.', 'bingocommerce-digital-tickets' ) . '</p></noscript></section>';
	}

	/**
	 * What the QR is for, in plain words, from the order's saved purchase facts
	 * (never live Session data): one Package, with its add-ons when any were
	 * bought, for one dated Bingo Session. Informational only; nothing here
	 * enforces it. Null when the facts can't be read.
	 */
	private static function validity( \WC_Order $order ): ?array {
		$facts = $order->get_meta( 'bc_purchase', true );
		if ( ! EntitlementSnapshot::valid_facts( $order, $facts ) || 'paper' === $facts['package_type']
			|| ! is_string( $facts['package_name'] ?? null ) || '' === trim( $facts['package_name'] )
			|| ! is_string( $facts['session_name'] ?? null ) || '' === trim( $facts['session_name'] ) ) { return null; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $facts['session_date'] )->format( 'm/d/Y' );
		$name = trim( $facts['session_name'] ); $time = null;
		if ( is_string( $facts['start_time_local'] ?? null ) && preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]/', $facts['start_time_local'], $match ) ) {
			$time = $match[0];
			// A generated Session's name already ends with " — HH:MM"; show the time once.
			if ( str_ends_with( $name, ' — ' . $time ) ) { $name = substr( $name, 0, -strlen( ' — ' . $time ) ); }
		}
		/* translators: 1: Session name, 2: date (MM/DD/YYYY), 3: start time (HH:MM). */
		$session = null === $time ? sprintf( __( '“%1$s” on %2$s', 'bingocommerce-digital-tickets' ), $name, $date ) : sprintf( __( '“%1$s” on %2$s at %3$s', 'bingocommerce-digital-tickets' ), $name, $date, $time );
		$detail = [] === $facts['addons']
			/* translators: 1: Package name, 2: the Bingo Session. */
			? sprintf( __( 'This QR covers only the digital part of your purchase: one Package (%1$s) for the Bingo Session %2$s.', 'bingocommerce-digital-tickets' ), $facts['package_name'], $session )
			/* translators: 1: Package name, 2: the Bingo Session. */
			: sprintf( __( 'This QR covers only the digital part of your purchase: one Package (%1$s) and its add-ons for the Bingo Session %2$s.', 'bingocommerce-digital-tickets' ), $facts['package_name'], $session );
		return [ __( 'Valid for one Session only.', 'bingocommerce-digital-tickets' ), $detail . ' ' . __( 'It can’t be used for any other Session, date or Package.', 'bingocommerce-digital-tickets' ) ];
	}

	/** Saved Digital/Hybrid purchase from Woo checkout; the email link and ticket area share it. */
	private static function digital_order( \WC_Order $order ): bool {
		if ( ! in_array( $order->get_created_via( 'edit' ), [ 'checkout', 'store-api' ], true )
			|| (float) $order->get_total( 'edit' ) <= 0 || '' === $order->get_payment_method( 'edit' ) ) { return false; }
		$facts = $order->get_meta( 'bc_purchase', true );
		if ( ! EntitlementSnapshot::valid_facts( $order, $facts ) || 'paper' === $facts['package_type'] ) { return false; }
		$faces = $facts['digital_faces_by_game_type'] ?? null;
		if ( ! is_array( $faces ) || [] === $faces ) { return false; }
		foreach ( $faces as $game_type => $total ) {
			if ( ! is_int( $game_type ) || $game_type < 1 || ! is_int( $total ) || $total < 1 ) { return false; }
		}
		return true;
	}

	private static function paid( \WC_Order $order ): bool {
		return $order->is_paid() && (bool) $order->get_date_paid( 'edit' );
	}

	private static function assets(): void {
		// Installed WooCommerce 11.0.1's MIT-licensed encoder; no remote service.
		wp_enqueue_script( 'bc-qr-encoder', WC()->plugin_url() . '/assets/js/jquery-qrcode/jquery.qrcode.min.js', [ 'jquery' ], WC_VERSION, true );
		wp_enqueue_script( 'bc-customer-ticket', plugins_url( 'assets/customer-ticket.js', dirname( __DIR__ ) . '/bingocommerce-digital-tickets.php' ), [ 'bc-qr-encoder' ], self::VERSION, true );
	}

	private static function can_view( \WC_Order $order ): bool {
		if ( get_current_user_id() > 0 && current_user_can( 'view_order', $order->get_id() ) ) { return true; }
		// A guest's user ID of zero must never satisfy the owner capability check.
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( $order->get_customer_id() || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || ! class_exists( Users::class ) ) { return false; }
		$email = null;
		if ( isset( $_POST['check_submission'], $_POST['email'] ) && is_string( $_POST['check_submission'] ) && is_string( $_POST['email'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['check_submission'] ) ), 'wc_verify_email' ) ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ) );
		} elseif ( isset( $_POST['_wpnonce'], $_POST['email'] ) && is_string( $_POST['_wpnonce'] ) && is_string( $_POST['email'] ) ) {
			// Order Confirmation blocks use _wpnonce, not the classic form's
			// check_submission field. Match the block's two verification actions.
			$nonce = sanitize_key( wp_unslash( $_POST['_wpnonce'] ) );
			if ( wp_verify_nonce( $nonce, 'wc_verify_email' ) || wp_verify_nonce( $nonce, 'wc_create_account' ) ) {
				$email = sanitize_email( wp_unslash( $_POST['email'] ) );
			}
		}
		return ! Users::should_user_verify_order_email( $order->get_id(), $email, 'order-received' );
	}
}
