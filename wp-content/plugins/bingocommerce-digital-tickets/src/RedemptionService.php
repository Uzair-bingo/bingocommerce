<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\RedemptionGameTypes;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Domain entry reached only through the authenticated Portal Integration gateway. */
final class RedemptionService {
	public static function receive( mixed $previous, array $command ): array|\WP_Error {
		if ( null !== $previous ) { return RedemptionContract::error( 'verification_unavailable' ); }
		return self::redeem( $command );
	}

	public static function redeem( array $command ): array|\WP_Error {
		if ( class_exists( LifecycleTransport::class, false ) && LifecycleTransport::refuse_reentry() ) { return self::error( 'verification_unavailable' ); }
		$valid = RedemptionContract::validate( $command );
		if ( $valid instanceof \WP_Error ) { return $valid; }
		if ( get_current_blog_id() !== $command['site_id']
			|| ! class_exists( LifecycleTransport::class, false ) || ! class_exists( RedemptionGameTypes::class, false ) || ! class_exists( ElectronicLimit::class, false ) ) {
			return RedemptionContract::error( 'verification_unavailable' );
		}
		$guard = null; $result = 'verification_unavailable';
		try {
			// Capture the authorized Hall/connection BEFORE schema, Woo or Catalog
			// can dispatch callbacks. Never adopt a replacement context afterward.
			$guard = LifecycleTransport::capture( true );
			if ( Migrations::verify_current_site_schema() instanceof \WP_Error || Migrations::verify_redemption_schema() instanceof \WP_Error ) {
				throw new LifecycleFailure();
			}
			$guard->prove( false );
			$intent = RedemptionContract::intent( $command );
			$token = substr( $command['qr'], strrpos( $command['qr'], '.' ) + 1 );
			$prepared = self::prepare( $guard, $token );
			$guard->prove( false );
			// UUID generation and all Woo/mapping callbacks precede acquisition.
			$redemption = wp_generate_uuid4();
			$guard->acquire();
			// A fixed callback-capable phase. It cannot finalize a lifecycle
			// decision; proof and every lifecycle read follow it natively.
			$limit = ElectronicLimit::effective_limit();
			$guard->prove();
			$result = self::locked( $guard, $command, $intent, $token, $prepared, $limit, $redemption );
		} catch ( \Throwable $error ) {
			$result = $error instanceof LifecycleFailure ? $error->getMessage() : 'verification_unavailable';
		} finally {
			if ( null !== $guard ) { $guard->close(); }
		}
		// WP_Error and diagnostics can dispatch hooks. They never run under the
		// lifecycle mutex, nor change an acknowledged acceptance's outcome.
		$diagnostics_safe = LifecycleTransport::diagnostics_safe();
		if ( $diagnostics_safe && is_string( $result ) && in_array( $result, [ 'verification_unavailable', 'mapping_unavailable', 'write_outcome_unknown' ], true ) ) {
			try {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( $result, [ 'source' => 'bingocommerce-digital-tickets', 'site_id' => $command['site_id'], 'request_id' => $command['request_id'] ] );
				}
			} catch ( \Throwable $ignored ) { /* Diagnostics cannot change durable outcomes. */ }
		}
		if ( is_array( $result ) ) { return $result; }
		return self::error( $result );
	}

	private static function error( string $result ): \WP_Error {
		if ( LifecycleTransport::diagnostics_safe() ) { return RedemptionContract::error( $result ); }
		// WP_Error::add() fires wp_error_added. An unproved lock release must
		// not dispatch domain diagnostics/translation callbacks while returning
		// the same safe error. Its empty constructor has no hook side effects.
		$code = isset( RedemptionContract::ERRORS[ $result ] ) ? $result : 'verification_unavailable';
		[ $status, $message, $retryable ] = RedemptionContract::ERRORS[ $code ];
		$error = new \WP_Error();
		$error->errors = [ $code => [ $message ] ];
		$error->error_data = [ $code => [ 'status' => $status, 'retryable' => $retryable ] ];
		return $error;
	}

	/** Preliminary location and frozen data only; no lifecycle decision or write. */
	private static function prepare( LifecycleTransport $guard, string $token ): array {
		$row = $guard->locate( $token );
		if ( null === $row ) { return [ 'row' => null, 'purchase' => null, 'mapping' => null ]; }
		$purchase = RedemptionPurchase::read( $row );
		$guard->prove( false );
		$mapping = $purchase instanceof \WP_Error ? null : RedemptionGameTypes::map( array_keys( $purchase['faces'] ) );
		return [ 'row' => $row, 'purchase' => $purchase, 'mapping' => $mapping ];
	}

	/** Native, callback-free marker → entitlement/ticket → request/ledger order. */
	private static function locked( LifecycleTransport $guard, array $command, string $intent, string $token, array $prepared, mixed $limit, string $redemption ): array|string {
		$before = $prepared['row'];
		$marker = null === $before ? null : $guard->marker( (int) $before['order_id'] );
		$row = $guard->ticket( $token );
		$request = $guard->request( $command['request_id'] );
		if ( null !== $request && ( ! hash_equals( $request['intent_hash'], $intent ) || $request['player_tablet_id'] !== $command['player_tablet_id'] ) ) { return 'request_id_conflict'; }
		if ( null === $row ) { return 'ticket_not_found'; }
		// A changed preliminary locator is unavailable, never an unchecked order.
		if ( null === $before || $row !== $before ) { return null !== $marker || 'active' !== $row['status'] ? 'ticket_voided' : 'verification_unavailable'; }
		if ( null !== $marker || 'active' !== $row['status'] ) { return 'ticket_voided'; }
		if ( null !== $request && 'accepted' === $request['state'] ) {
			return self::recover( $request, $row, $command );
		}
		if ( null !== $request && 'pending' !== $request['state'] ) { return 'verification_unavailable'; }
		if ( null === $request ) {
			$guard->claim( $command, $intent, $redemption );
			$request = $guard->request( $command['request_id'] );
			if ( null === $request || ! hash_equals( $request['intent_hash'], $intent ) || 'pending' !== $request['state'] ) { return 'write_outcome_unknown'; }
		}
		if ( null !== $guard->accepted( (int) $row['entitlement_id'] ) ) { return 'already_redeemed'; }
		if ( isset( $command['operating_commerce_session_id'] ) && (string) $command['operating_commerce_session_id'] !== (string) $row['session_id'] ) { return 'wrong_session'; }
		$purchase = $prepared['purchase']; $mapping = $prepared['mapping'];
		if ( $purchase instanceof \WP_Error ) { return $purchase->get_error_code(); }
		if ( ! is_array( $purchase ) ) { return 'saved_purchase_invalid'; }
		if ( $mapping instanceof \WP_Error || ! is_array( $mapping ) ) { return 'mapping_unavailable'; }
		if ( ! is_int( $limit ) || $limit < 1 ) { return 'verification_unavailable'; }
		$accepted = [];
		foreach ( $guard->usage( (int) $row['session_id'], $command['player_tablet_id'] ) as $contribution ) {
			$faces = RedemptionContract::faces( json_decode( $contribution['contributions_json'], true, 512, JSON_THROW_ON_ERROR ) );
			if ( null === $faces ) { return 'verification_unavailable'; }
			$accepted[] = $faces;
		}
		if ( ! RedemptionContract::within_limit( $purchase['faces'], $accepted, $limit ) ) { return 'electronic_limit_exceeded'; }
		$response = RedemptionContract::response( $command, $purchase, $mapping, $request['redemption_id'] );
		$json = json_encode( $response, JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > RedemptionContract::MAX_RESPONSE_BYTES ) { return 'saved_purchase_invalid'; }
		$faces = json_encode( $purchase['faces'], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR );
		$guard->accept( $command['request_id'], $intent, (int) $row['entitlement_id'], (int) $row['session_id'], $faces, $json );
		return $response;
	}

	private static function recover( array $request, array $entitlement, array $command ): array|string {
		if ( (string) $request['accepted_entitlement_id'] !== (string) $entitlement['entitlement_id'] || (string) $request['session_id'] !== (string) $entitlement['session_id'] ) { return 'verification_unavailable'; }
		$response = json_decode( $request['response_json'], true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $response ) || strlen( $request['response_json'] ) > RedemptionContract::MAX_RESPONSE_BYTES
			|| 1 !== ( $response['api_version'] ?? null ) || 'redeemed' !== ( $response['state'] ?? null ) || false !== ( $response['recovered'] ?? null )
			|| $command['request_id'] !== ( $response['request_id'] ?? null ) || $command['player_tablet_id'] !== ( $response['player_tablet_id'] ?? null )
			|| $request['redemption_id'] !== ( $response['redemption_id'] ?? null )
			|| (int) $entitlement['session_id'] !== ( $response['session']['commerce_session_id'] ?? null )
			|| (int) $entitlement['package_id'] !== ( $response['package']['commerce_package_id'] ?? null ) ) { return 'verification_unavailable'; }
		$response['recovered'] = true;
		return $response;
	}
}
