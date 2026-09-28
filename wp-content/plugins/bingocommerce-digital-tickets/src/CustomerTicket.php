<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

use Automattic\WooCommerce\Internal\Utilities\Users;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Render only inside Woo's customer order details; no public QR/image endpoint. */
final class CustomerTicket {
	public static function render( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Migrations::is_hall() ) { return; }
		// Reload in this Hall; never accept a foreign-Hall object as authorization.
		$current = wc_get_order( $order->get_id() );
		if ( ! $current instanceof \WC_Order || ! hash_equals( $current->get_order_key(), $order->get_order_key() ) || ! self::can_view( $current ) ) { return; }
		$reference = DigitalTicket::reference_for_order( $current->get_id() );
		if ( null === $reference ) { return; }
		// Installed WooCommerce 11.0.1's MIT-licensed encoder; no remote service.
		wp_enqueue_script( 'bc-qr-encoder', WC()->plugin_url() . '/assets/js/jquery-qrcode/jquery.qrcode.min.js', [ 'jquery' ], WC_VERSION, true );
		wp_enqueue_script( 'bc-customer-ticket', plugins_url( 'assets/customer-ticket.js', dirname( __DIR__ ) . '/bingocommerce-digital-tickets.php' ), [ 'bc-qr-encoder' ], '1.0.0', true );
		echo '<section class="bc-digital-ticket"><h2>' . esc_html__( 'Digital Bingo ticket', 'bingocommerce-digital-tickets' ) . '</h2>';
		echo '<p>' . esc_html__( 'This QR represents the digital part of your Bingo Package only. Keep it private.', 'bingocommerce-digital-tickets' ) . '</p>';
		echo '<canvas width="296" height="296" data-bc-ticket="' . esc_attr( $reference ) . '" role="img" aria-label="' . esc_attr__( 'Digital Bingo ticket QR', 'bingocommerce-digital-tickets' ) . '"></canvas>';
		echo '<noscript>' . esc_html__( 'Enable JavaScript to display your QR.', 'bingocommerce-digital-tickets' ) . '</noscript></section>';
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
