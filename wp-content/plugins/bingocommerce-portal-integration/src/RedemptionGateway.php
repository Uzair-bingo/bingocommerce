<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Narrow runtime boundary: this plugin never reads Digital Tickets tables. */
final class RedemptionGateway {

	public const FILTER = 'bingocommerce_redeem_digital_ticket';

	/** Fixed public vocabulary; domain/database exception text is never exposed. */
	private const ERRORS = [
		'invalid_request'           => [ 400, false, 'Invalid redemption request.' ],
		'malformed_qr'              => [ 400, false, 'Scan a valid Bingo ticket.' ],
		'authentication_required'   => [ 401, false, 'Service authentication required.' ],
		'caller_not_authorized'     => [ 403, false, 'This Caller cannot redeem tickets here.' ],
		'wrong_hall'                => [ 403, false, 'This ticket belongs to a different Hall.' ],
		'ticket_not_found'          => [ 404, false, 'Ticket not found.' ],
		'already_redeemed'          => [ 409, false, 'Already redeemed.' ],
		'request_id_conflict'       => [ 409, false, 'Request ID was used for a different request.' ],
		'wrong_session'             => [ 409, false, 'Ticket belongs to a different dated Session.' ],
		'electronic_limit_exceeded' => [ 409, false, 'This ticket exceeds this tablet\'s allowance.' ],
		'ticket_voided'             => [ 410, false, 'This ticket is no longer valid.' ],
		'saved_purchase_invalid'    => [ 422, false, 'Saved ticket details need staff review.' ],
		'mapping_unavailable'       => [ 503, true, 'Ticket identity mapping is unavailable.' ],
		'verification_unavailable'  => [ 503, true, 'Unable to verify now. Retry the original request.' ],
		'write_outcome_unknown'     => [ 503, true, 'Unable to confirm redemption. Retry the original request.' ],
	];

	/** @return array<string,mixed>|\WP_Error */
	public static function redeem( array $command ) {
		try {
			$result = apply_filters( self::FILTER, null, $command );
		} catch ( \Throwable $ignored ) {
			return self::error( 'verification_unavailable' );
		}
		if ( $result instanceof \WP_Error ) {
			return self::error( (string) $result->get_error_code() );
		}
		if ( ! self::valid_result( $result, $command ) ) {
			return self::error( 'verification_unavailable' );
		}
		return $result;
	}

	public static function error( string $code ): \WP_Error {
		if ( ! isset( self::ERRORS[ $code ] ) ) {
			$code = 'verification_unavailable';
		}
		[ $status, $retryable, $message ] = self::ERRORS[ $code ];
		return new \WP_Error( $code, $message, [ 'status' => $status, 'retryable' => $retryable ] );
	}

	public static function response( $result ): \WP_REST_Response {
		if ( $result instanceof \WP_Error ) {
			$error = self::error( (string) $result->get_error_code() );
			$data  = $error->get_error_data();
			return new \WP_REST_Response(
				[ 'api_version' => 1, 'error' => [ 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'retryable' => $data['retryable'] ] ],
				$data['status'],
				[ 'Cache-Control' => 'no-store' ]
			);
		}
		return new \WP_REST_Response( $result, 200, [ 'Cache-Control' => 'no-store' ] );
	}

	/** Fail closed on unexpected fields: never leak a domain/order object. */
	public static function valid_result( $result, array $command ): bool {
		if ( ! self::keys( $result, [ 'api_version', 'request_id', 'player_tablet_id', 'redemption_id', 'recovered', 'state', 'session', 'package', 'digital_faces_by_game_type' ] )
			|| 1 !== $result['api_version'] || 'redeemed' !== $result['state']
			|| ( $command['request_id'] ?? null ) !== $result['request_id']
			|| ( $command['player_tablet_id'] ?? null ) !== $result['player_tablet_id']
			|| ! is_bool( $result['recovered'] ) || ! RedemptionRestController::uuid( $result['redemption_id'] ) ) {
			return false;
		}
		$s = $result['session'];
		$p = $result['package'];
		if ( ! self::keys( $s, [ 'commerce_session_id', 'name', 'date', 'start_time_local', 'timezone_name', 'starts_at_utc' ] )
			|| ! self::positive_integer( $s['commerce_session_id'] ) || ! self::name( $s['name'] ) || ! self::date( $s['date'] )
			|| ! self::keys( $p, [ 'commerce_package_id', 'name' ] ) || ! self::positive_integer( $p['commerce_package_id'] ) || ! self::name( $p['name'] ) ) {
			return false;
		}
		if ( null !== $s['start_time_local'] && ( ! is_string( $s['start_time_local'] ) || 1 !== preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/', $s['start_time_local'] ) ) ) {
			return false;
		}
		if ( null !== $s['timezone_name'] && ( ! is_string( $s['timezone_name'] ) || ! in_array( $s['timezone_name'], \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC ), true ) ) ) {
			return false;
		}
		if ( null !== $s['starts_at_utc'] && ! self::utc( $s['starts_at_utc'] ) ) {
			return false;
		}
		$faces = $result['digital_faces_by_game_type'];
		if ( ! is_array( $faces ) || [] === $faces || array_keys( $faces ) !== range( 0, count( $faces ) - 1 ) ) {
			return false;
		}
		$seen = [];
		foreach ( $faces as $entry ) {
			if ( ! self::keys( $entry, [ 'portal_game_type_id', 'faces' ] ) || ! self::positive_integer( $entry['portal_game_type_id'] )
				|| ! self::positive_integer( $entry['faces'] ) || isset( $seen[ $entry['portal_game_type_id'] ] ) ) {
				return false;
			}
			$seen[ $entry['portal_game_type_id'] ] = true;
		}
		return true;
	}

	private static function keys( $value, array $expected ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$actual = array_keys( $value );
		sort( $actual );
		sort( $expected );
		return $actual === $expected;
	}

	private static function positive_integer( $value ): bool {
		return is_int( $value ) && $value > 0;
	}

	private static function name( $value ): bool {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 4096 && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1f\x7f]/', $value );
	}

	private static function date( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts ) ) {
			return false;
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	private static function utc( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
		return $parsed && $value === $parsed->format( 'Y-m-d\TH:i:s\Z' );
	}
}
