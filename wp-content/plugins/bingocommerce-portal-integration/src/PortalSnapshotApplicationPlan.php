<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An immutable, fully declarative description of one Portal snapshot application.
 *
 * Like the Catalog plans this is data, never behaviour. The transport interprets
 * it with one fixed, reviewed statement sequence, so the complete statement set
 * that can run between BEGIN and COMMIT stays enumerable and the boundary needs
 * no callable.
 *
 * Every field is derived from TRUSTED context only:
 *
 *   - `blog_id`, `portal_hall_id`, `network_id`, and `main_site_id` come from the
 *     HallMappingRepository and the WordPress network, never from the payload;
 *   - `game_types` and `sessions` are the PortalSnapshotValidator's reconstructed
 *     allowlisted arrays, never decoded objects; and
 *   - `now` is computed once, before BEGIN, so no WordPress call is needed inside
 *     the transaction to timestamp a row.
 *
 * Portal names are carried through byte-for-byte (ADR-039). Nothing in this class
 * normalizes, trims, rewrites, or escapes them.
 */
final class PortalSnapshotApplicationPlan {

	/**
	 * @param array<int,array{id:int,hall_id:int,name:string,updated_at:string|null}> $game_types
	 * @param array<int,array{id:int,hall_id:int,name:string,status:string,updated_at:string|null}> $sessions
	 */
	private function __construct(
		public readonly int $blog_id,
		public readonly int $portal_hall_id,
		public readonly int $network_id,
		public readonly int $main_site_id,
		public readonly string $generated_at,
		public readonly array $game_types,
		public readonly array $sessions,
		public readonly string $now,
		/**
		 * Whether the transport may apply this plan to an ARCHIVED site.
		 *
		 * False for every ordinary synchronization, and settable only through
		 * forProvisioning() below, which demands a currently held Hall
		 * provisioning mutex. It relaxes exactly one of the transport's
		 * re-confirmation checks; deleted, spam, wrong-network, main-site and
		 * inactive-mapping all still reject the transaction.
		 */
		public readonly bool $allow_archived_site = false
	) {}

	/**
	 * Build a plan from one validated snapshot and its trusted mapping context.
	 *
	 * The expected Portal Hall ID is re-checked against the snapshot here even
	 * though PortalSnapshotValidator already enforced it. The validator is the
	 * contract boundary; this is the construction boundary, and a plan that could
	 * be built with a Hall ID the caller did not verify would be a tenancy hole
	 * one refactor away.
	 *
	 * @param array<string,mixed> $snapshot Validator output, never a raw payload.
	 * @return self|\WP_Error
	 */
	public static function fromValidatedSnapshot(
		int $blog_id,
		int $portal_hall_id,
		int $network_id,
		int $main_site_id,
		array $snapshot,
		string $now
	) {
		return self::build( $blog_id, $portal_hall_id, $network_id, $main_site_id, $snapshot, $now, false );
	}

	/**
	 * The ADR-042 provisioning variant: the same plan, permitted to apply to a
	 * site that is deliberately still archived.
	 *
	 * A newly provisioned Hall site is created `archived = 1` and MUST remain so
	 * until mapping, synchronization, readiness and operational state have all
	 * succeeded — so the one synchronization that has to run against an archived
	 * site is this one. The permission is NOT a boolean a caller may pass: it is
	 * granted only against a ProvisioningMutex for this exact Portal Hall that
	 * the SERVER still confirms is held. A caller without that mutex cannot
	 * build this plan, so no ordinary path can reach the relaxed check by
	 * accident or by refactor.
	 *
	 * @param array<string,mixed> $snapshot Validator output, never a raw payload.
	 * @return self|\WP_Error
	 */
	public static function forProvisioning(
		ProvisioningMutex $mutex,
		int $blog_id,
		int $portal_hall_id,
		int $network_id,
		int $main_site_id,
		array $snapshot,
		string $now
	) {
		if ( $mutex->portalHallId() !== $portal_hall_id || ! $mutex->isStillHeld() ) {
			return self::error( 'portal_sync_plan_not_current', 'The provisioning command no longer holds this Hall.' );
		}

		return self::build( $blog_id, $portal_hall_id, $network_id, $main_site_id, $snapshot, $now, true );
	}

	/**
	 * @param array<string,mixed> $snapshot
	 * @return self|\WP_Error
	 */
	private static function build(
		int $blog_id,
		int $portal_hall_id,
		int $network_id,
		int $main_site_id,
		array $snapshot,
		string $now,
		bool $allow_archived_site
	) {
		if ( $blog_id < 1 || $portal_hall_id < 1 || $network_id < 1 || $main_site_id < 1 ) {
			return self::error( 'portal_sync_plan_invalid', 'The Portal synchronization plan context is invalid.' );
		}
		if ( $blog_id === $main_site_id ) {
			return self::error( 'portal_sync_plan_invalid', 'The main network site cannot receive a Portal snapshot.' );
		}

		$hall = $snapshot['hall'] ?? null;
		if (
			! is_array( $hall )
			|| ! isset( $hall['id'] )
			|| ! is_int( $hall['id'] )
			|| $hall['id'] !== $portal_hall_id
		) {
			return self::error( 'portal_snapshot_hall_mismatch', 'The Portal snapshot describes a different Portal Hall.' );
		}

		$generated_at = $snapshot['generated_at'] ?? null;
		if ( ! is_string( $generated_at ) || '' === $generated_at ) {
			return self::error( 'portal_sync_plan_invalid', 'The Portal snapshot generation timestamp is unusable.' );
		}

		$game_types = $snapshot['game_types'] ?? null;
		$sessions   = $snapshot['sessions'] ?? null;
		if ( ! is_array( $game_types ) || ! is_array( $sessions ) ) {
			return self::error( 'portal_sync_plan_invalid', 'The Portal snapshot collections are unusable.' );
		}

		// Belt-and-braces re-check of the per-record owning Hall. The validator
		// already rejects a foreign hall_id; repeating it here means no future
		// caller can construct a plan that mixes Halls.
		foreach ( [ $game_types, $sessions ] as $collection ) {
			foreach ( $collection as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['hall_id'] ) || $row['hall_id'] !== $portal_hall_id ) {
					return self::error( 'portal_snapshot_hall_mismatch', 'The Portal snapshot contains a record owned by a different Portal Hall.' );
				}
			}
		}

		if ( 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $now ) ) {
			return self::error( 'portal_sync_plan_invalid', 'The Portal synchronization timestamp is unusable.' );
		}

		return new self(
			$blog_id,
			$portal_hall_id,
			$network_id,
			$main_site_id,
			$generated_at,
			array_values( $game_types ),
			array_values( $sessions ),
			$now,
			$allow_archived_site
		);
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $code ] );
	}
}
