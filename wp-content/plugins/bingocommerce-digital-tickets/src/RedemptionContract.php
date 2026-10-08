<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Version-one server handoff. Values are validated, never normalized. */
final class RedemptionContract {
	public const FILTER = 'bingocommerce_redeem_digital_ticket';
	public const UUID = '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/';
	public const QR = '/\ABC1\.([1-9][0-9]{0,18})\.([a-f0-9]{64})\z/';
	public const MAX_RESPONSE_BYTES = 262144;
	public const ERRORS = [
		'invalid_request' => [ 400, 'Invalid redemption request.', false ],
		'malformed_qr' => [ 400, 'Scan a valid Bingo ticket.', false ],
		'authentication_required' => [ 401, 'Service authentication required.', false ],
		'caller_not_authorized' => [ 403, 'This Caller is not authorized.', false ],
		'wrong_hall' => [ 403, 'This ticket cannot be redeemed in this Hall.', false ],
		'ticket_not_found' => [ 404, 'Ticket not found.', false ],
		'already_redeemed' => [ 409, 'Already redeemed.', false ],
		'request_id_conflict' => [ 409, 'Request ID was used for a different request.', false ],
		'wrong_session' => [ 409, 'Ticket belongs to a different dated Session.', false ],
		'electronic_limit_exceeded' => [ 409, 'This ticket exceeds this tablet\'s allowance.', false ],
		'ticket_voided' => [ 410, 'This ticket is no longer valid.', false ],
		'saved_purchase_invalid' => [ 422, 'Saved ticket details need staff review.', false ],
		'mapping_unavailable' => [ 503, 'Required Game Type mapping is unavailable. Retry after it is restored.', true ],
		'verification_unavailable' => [ 503, 'Unable to verify now. Retry the original request.', true ],
		'write_outcome_unknown' => [ 503, 'The result could not be confirmed. Retry the original request.', true ],
	];

	public static function error( string $code ): \WP_Error {
		$code = isset( self::ERRORS[ $code ] ) ? $code : 'verification_unavailable';
		[ $status, $message, $retryable ] = self::ERRORS[ $code ];
		return new \WP_Error( $code, $message, [ 'status' => $status, 'retryable' => $retryable ] );
	}

	public static function opaque( mixed $value, int $bytes ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $bytes
			&& 1 === preg_match( '//u', $value ) && 0 === preg_match( '/\p{Cc}/u', $value );
	}

	public static function validate( array $command ): true|\WP_Error {
		foreach ( [ 'site_id', 'portal_hall_id' ] as $field ) {
			if ( ! is_int( $command[ $field ] ?? null ) || $command[ $field ] < 1 ) { return self::error( 'invalid_request' ); }
		}
		if ( ! is_string( $command['request_id'] ?? null ) || 1 !== preg_match( self::UUID, $command['request_id'] )
			|| ! self::opaque( $command['player_tablet_id'] ?? null, 128 ) || ! self::opaque( $command['caller_id'] ?? null, 191 ) ) { return self::error( 'invalid_request' ); }
		if ( array_key_exists( 'operating_commerce_session_id', $command ) && ( ! is_int( $command['operating_commerce_session_id'] ) || $command['operating_commerce_session_id'] < 1 ) ) { return self::error( 'invalid_request' ); }
		if ( ! is_string( $command['qr'] ?? null ) || 1 !== preg_match( self::QR, $command['qr'], $parts ) ) { return self::error( 'malformed_qr' ); }
		if ( (string) $command['site_id'] !== $parts[1] ) { return self::error( 'wrong_hall' ); }
		return true;
	}

	/** No Caller identity: a second authorized Caller may relay the same tablet. */
	public static function intent( array $command ): string {
		return hash( 'sha256', json_encode( [ 1, $command['site_id'], $command['portal_hall_id'], $command['player_tablet_id'], $command['qr'], $command['operating_commerce_session_id'] ?? null ], JSON_THROW_ON_ERROR ) );
	}

	/** Positive frozen quantities only. An unreadable map is never zero. */
	public static function faces( mixed $faces ): ?array {
		if ( ! is_array( $faces ) || [] === $faces ) { return null; }
		foreach ( $faces as $id => $quantity ) {
			if ( ! is_int( $id ) || $id < 1 || ! is_int( $quantity ) || $quantity < 1 ) { return null; }
		}
		ksort( $faces, SORT_NUMERIC );
		return $faces;
	}

	/** No counters: every accepted contribution remains counted, including voids. */
	public static function within_limit( array $incoming, array $accepted, int $limit ): bool {
		foreach ( $accepted as $faces ) {
			foreach ( $faces as $game => $quantity ) {
				if ( ! isset( $incoming[ $game ] ) ) { continue; }
				if ( $quantity > PHP_INT_MAX - $incoming[ $game ] ) { return false; }
				$incoming[ $game ] += $quantity;
			}
		}
		foreach ( $incoming as $quantity ) { if ( $quantity > $limit ) { return false; } }
		return true;
	}

	/** Fully serialized before consumption; downstream relay has the same bound. */
	public static function response( array $command, array $purchase, array $mapping, string $redemption ): array {
		$faces = [];
		foreach ( $purchase['faces'] as $local => $quantity ) { $faces[] = [ 'portal_game_type_id' => $mapping[ $local ], 'faces' => $quantity ]; }
		usort( $faces, static fn( $a, $b ) => $a['portal_game_type_id'] <=> $b['portal_game_type_id'] );
		return [ 'api_version' => 1, 'request_id' => $command['request_id'], 'player_tablet_id' => $command['player_tablet_id'],
			'redemption_id' => $redemption, 'recovered' => false, 'state' => 'redeemed', 'session' => $purchase['session'],
			'package' => $purchase['package'], 'digital_faces_by_game_type' => $faces ];
	}
}
