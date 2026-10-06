<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only ticket status for the customer's waiting area (ADR-046).
 *
 * AGENTS.md rule 5 exception, approved for this route only. Every request needs
 * the short-lived credential CustomerTicket issues only after the order page's
 * own access checks (owner capability, or order key and, where WooCommerce
 * requires it, email verification), bound to this Hall, the order, its owner and
 * its key. A customer-owned order also still requires the signed-in owner's
 * current_user_can( 'view_order' ); for a guest-owned order the credential is
 * the authorization. Never writes, issues, repairs or redeems anything; a
 * reference is returned only for a ticket the order page would display now.
 */
final class TicketStatus {
	public const NAMESPACE = 'bingocommerce-digital-tickets/v1';
	public const ROUTE = '/ticket-status';
	public const TTL = 1800;
	private const FORMAT = '/\A([1-9][0-9]{0,19})\.([1-9][0-9]{9,10})\.([a-f0-9]{64})\z/';

	public static function register(): void {
		register_rest_route( self::NAMESPACE, self::ROUTE, [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'respond' ],
			'permission_callback' => [ self::class, 'permit' ],
			'args'                => [
				'credential' => [
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => [ self::class, 'valid' ],
					'sanitize_callback' => [ self::class, 'sanitize' ],
				],
			],
		] );
	}

	/** The route's URL when it is this Hall's own origin; '' otherwise (no checks are offered). */
	public static function url(): string {
		$url = rest_url( self::NAMESPACE . self::ROUTE );
		$parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) );
		foreach ( [ 'scheme', 'host', 'port' ] as $part ) {
			if ( ! is_array( $parts ) || ! is_array( $home ) || strtolower( (string) ( $parts[ $part ] ?? '' ) ) !== strtolower( (string) ( $home[ $part ] ?? '' ) ) ) { return ''; }
		}
		return in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) ? $url : '';
	}

	/** Issue only after the order page has authorized the viewer. */
	public static function credential( \WC_Order $order, ?int $expires = null ): string {
		$expires = $expires ?? time() + self::TTL;
		return $order->get_id() . '.' . $expires . '.' . self::sign( $order, $expires );
	}

	public static function valid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::FORMAT, $value );
	}

	public static function sanitize( $value ): string {
		return self::valid( $value ) ? $value : '';
	}

	public static function permit( \WP_REST_Request $request ): bool|\WP_Error {
		return null !== self::order( self::supplied( $request ) ) ? true : self::denied();
	}

	public static function respond( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = self::order( self::supplied( $request ) );
		if ( null === $order ) { return self::denied(); }
		// The same read the order page makes: existing ticket eligibility, active entitlement and void checks.
		$current = CustomerTicket::current( $order );
		$body = [ 'scope' => get_current_blog_id() . ':' . $order->get_id(), 'state' => $current['state'] ?? 'closed' ];
		if ( 'ready' === $body['state'] ) { $body['reference'] = $current['reference']; }
		return new \WP_REST_Response( $body, 200 );
	}

	/** No cache may keep any answer of this route, success or refusal. */
	public static function no_store( $response, $server, $request ) {
		if ( $response instanceof \WP_HTTP_Response && $request instanceof \WP_REST_Request && '/' . self::NAMESPACE . self::ROUTE === $request->get_route() ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		}
		return $response;
	}

	/** The credential from the JSON body only; one supplied in the URL, where logs keep it, is refused. */
	private static function supplied( \WP_REST_Request $request ): string {
		if ( array_key_exists( 'credential', $request->get_query_params() ) ) { return ''; }
		$json = $request->get_json_params();
		return is_array( $json ) ? self::sanitize( $json['credential'] ?? null ) : '';
	}

	/** The current Hall's order this credential authorizes, or null. */
	private static function order( string $credential ): ?\WC_Order {
		if ( ! Migrations::is_hall() || ! function_exists( 'wc_get_order' ) || 1 !== preg_match( self::FORMAT, $credential, $parts ) ) { return null; }
		$expires = (int) $parts[2];
		if ( $expires < time() || $expires > time() + self::TTL ) { return null; }
		$order = wc_get_order( (int) $parts[1] );
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || ! hash_equals( self::sign( $order, $expires ), $parts[3] ) ) { return null; }
		// A customer-owned order keeps the signed-in ownership check; a guest's user ID of zero never satisfies it.
		if ( $order->get_customer_id() > 0 ) {
			return get_current_user_id() > 0 && current_user_can( 'view_order', $order->get_id() ) ? $order : null;
		}
		return $order;
	}

	private static function sign( \WC_Order $order, int $expires ): string {
		// The order key is bound in, never sent: the credential carries only the order ID and expiry.
		$message = implode( '|', [ 'bc-ticket-status-v1', get_current_blog_id(), $order->get_id(), $order->get_customer_id(), $expires, hash( 'sha256', $order->get_order_key() ) ] );
		return hash_hmac( 'sha256', $message, wp_salt( 'nonce' ) );
	}

	private static function denied(): \WP_Error {
		return new \WP_Error( 'bc_ticket_status_unavailable', __( 'Ticket status isn’t available.', 'bingocommerce-digital-tickets' ), [ 'status' => 403 ] );
	}
}
