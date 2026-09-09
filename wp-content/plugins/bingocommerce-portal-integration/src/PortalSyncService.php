<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3E orchestration: mapping -> fetch -> validate -> atomic application.
 *
 * The order below is the security boundary, not a convenience:
 *
 *   1. the per-Hall advisory lock is taken FIRST, so a second synchronization of
 *      the same hall never even issues a Portal request;
 *   2. the Portal Hall ID comes ONLY from the active HallMappingRepository row
 *      for the target site — never from a request, a payload, a hostname, or a
 *      name;
 *   3. the snapshot URL is built from that mapped ID by PortalHttpClient;
 *   4. PortalSnapshotValidator re-checks the Hall identity of the envelope AND of
 *      every child record against the same mapped ID;
 *   5. only the validator's reconstructed array reaches the plan; and
 *   6. the transport re-confirms the mapping under `FOR UPDATE` before it writes,
 *      because a mapping can be disabled or a site archived during a Portal
 *      round-trip.
 *
 * HTTP finishes before the transaction opens. That is structural: the transport
 * accepts no callable and owns its complete statement set, so no network call is
 * reachable from inside the boundary.
 *
 * This service registers no admin screen, no REST route, no cron event, and no
 * hook. Phase 3E is invoked directly — from the CLI harnesses today, and from
 * whatever trigger surface a later, separately reviewed increment adds.
 */
final class PortalSyncService {

	/** @var callable(int):(string|\WP_Error) */
	private $fetcher;

	private PortalSnapshotValidator $validator;

	/**
	 * @param callable(int):(string|\WP_Error)|null $fetcher Snapshot transport. The
	 *        default is the real PortalHttpClient; harnesses inject fixture bodies
	 *        so application behaviour can be proven without a live Portal.
	 */
	public function __construct( ?callable $fetcher = null, ?PortalSnapshotValidator $validator = null ) {
		$this->fetcher = $fetcher ?? static function ( int $portal_hall_id ) {
			return ( new PortalHttpClient() )->fetchHallSnapshot( $portal_hall_id );
		};
		$this->validator = $validator ?? new PortalSnapshotValidator();
	}

	/** Convenience entry point for CLI and operator invocation. @return PortalSyncResult|\WP_Error */
	public static function synchronize( int $blog_id ) {
		return ( new self() )->synchronizeHall( $blog_id );
	}

	/**
	 * Synchronize one mapped hall from the Portal.
	 *
	 * @return PortalSyncResult|\WP_Error
	 */
	public function synchronizeHall( int $blog_id ) {
		if ( ! is_multisite() ) {
			return self::error( 'multisite_required', 'Portal synchronization requires WordPress Multisite.' );
		}
		if ( $blog_id < 1 ) {
			return self::error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}
		if ( ! Migrations::is_current() ) {
			return self::error( 'mapping_schema_unavailable', 'The Portal Hall mapping schema is unavailable.' );
		}

		// Rank 0. Held across mapping resolution, fetch, validation, and
		// application, and released in finally.
		$lock = PortalSyncLock::acquire( $blog_id );
		if ( $lock instanceof \WP_Error ) {
			return $lock;
		}

		try {
			return $this->run( $blog_id, $lock );
		} finally {
			$lock->release();
		}
	}

	/** @return PortalSyncResult|\WP_Error */
	private function run( int $blog_id, PortalSyncLock $lock ) {
		$mapping = ( new HallMappingRepository() )->findActiveByBlogId( $blog_id );
		if ( $mapping instanceof \WP_Error ) {
			return $mapping;
		}
		if ( null === $mapping ) {
			return self::error( 'mapping_not_found', 'No active Portal Hall mapping exists for this site.' );
		}

		$portal_hall_id = (int) $mapping['portal_hall_id'];
		$network_id     = get_current_network_id();
		$main_site_id   = (int) get_main_site_id( $network_id );

		switch_to_blog( $blog_id );

		try {
			$schema = $this->assert_domain_schemas();
			if ( $schema instanceof \WP_Error ) {
				return $schema;
			}

			// --- Portal round-trip, entirely outside the transaction --------------
			$body = call_user_func( $this->fetcher, $portal_hall_id );
			if ( $body instanceof \WP_Error ) {
				return $body;
			}
			if ( ! is_string( $body ) ) {
				return self::error( 'portal_snapshot_unavailable', 'The Portal snapshot response was unusable.' );
			}

			$snapshot = $this->validator->validate( $body, $portal_hall_id );
			if ( $snapshot instanceof \WP_Error ) {
				return $snapshot;
			}
			unset( $body );

			$plan = PortalSnapshotApplicationPlan::fromValidatedSnapshot(
				$blog_id,
				$portal_hall_id,
				(int) $network_id,
				$main_site_id,
				$snapshot,
				current_time( 'mysql', true )
			);
			if ( $plan instanceof \WP_Error ) {
				return $plan;
			}

			// --- Atomic application ----------------------------------------------
			$result = PortalSnapshotTransactionTransport::apply( $plan, $lock );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}

			// Only after a CONFIRMED commit. The marker row may have been created
			// inside the transaction, and a prior get_option() miss would otherwise
			// keep it invisible behind the negative 'notoptions' cache entry.
			PortalSyncState::invalidateCache();

			return $result;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Both replica schemas must be ready for THIS hall before anything is fetched.
	 *
	 * Fail-closed: an unavailable schema is never treated as "this hall has no
	 * Game Types", which under ADR-038 would be the difference between a rejected
	 * sync and a silent integrity decision.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_domain_schemas() {
		if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) || ! class_exists( '\BingoCommerce\Sessions\Migrations' ) ) {
			return self::error( 'portal_sync_domain_unavailable', 'The Catalog and Session plugins are required for Portal synchronization.' );
		}
		if ( ! \BingoCommerce\Catalog\Migrations::is_current() ) {
			return self::error( 'portal_sync_schema_unavailable', 'The Catalog schema is not ready for this hall.' );
		}
		if ( ! \BingoCommerce\Sessions\Migrations::is_current() ) {
			return self::error( 'portal_sync_schema_unavailable', 'The Session replica schema is not ready for this hall.' );
		}

		return true;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ), [ 'status' => $code ] );
	}
}
