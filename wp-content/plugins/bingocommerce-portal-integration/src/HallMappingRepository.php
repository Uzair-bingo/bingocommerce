<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network repository and invariant boundary for Portal Hall/site mappings.
 *
 * ORDINARY MAPPING RULES ARE UNCHANGED. bind(), findActiveByPortalHallId() and
 * findActiveByBlogId() still refuse an archived site exactly as they always
 * have, because for every ordinary caller an archived site is a site that must
 * not be operated on.
 *
 * ONE NARROW PROVISIONING-SCOPED EXCEPTION EXISTS, and only because ADR-042
 * requires it: an automatically provisioned Hall site is created `archived = 1`
 * and MUST stay archived until mapping, synchronization, readiness and
 * operational state have all succeeded. Mapping therefore has to happen while
 * the site is still archived. The exception is NOT a boolean an argument can
 * switch on: bindForProvisioning() and findActiveByBlogIdForProvisioning()
 * demand PROOF — a currently held ProvisioningMutex for this exact Portal Hall,
 * and a `bc_commerce_managed` witness on the target site that exactly equals the
 * operation's permanent `site_ownership_id`. Without both, they behave like the
 * ordinary methods. Nothing else is relaxed: a deleted, spam, wrong-network or
 * main site is still refused, and a conflicting existing mapping is still a
 * conflict that is never silently repaired.
 */
final class HallMappingRepository {

	public const STATUS_ACTIVE = 'active';
	public const STATUS_DISABLED = 'disabled';

	/** @return array<string,mixed>|\WP_Error */
	public function bind( int $portal_hall_id, int $blog_id ) {
		return $this->bind_internal( $portal_hall_id, $blog_id, false );
	}

	/**
	 * Create or confirm this Hall's mapping while its site is still archived.
	 *
	 * The ONLY caller this is written for is the ADR-042 provisioning
	 * orchestrator, on the enable path, between attaching a verified `blog_id`
	 * and clearing `archived`. It relaxes exactly one check — the archived
	 * refusal — and only against proof that cannot be forged from arguments:
	 *
	 *   - the mutex must be the Hall provisioning mutex for THIS
	 *     `portal_hall_id`, and the SERVER must still confirm it is held; and
	 *   - the target site's `bc_commerce_managed` witness must exactly equal the
	 *     permanent `site_ownership_id` this operation owns.
	 *
	 * Everything else is the ordinary bind: same existing-mapping
	 * classification, same `portal_hall_already_mapped` / `blog_already_mapped`
	 * / `mapping_disabled` conflicts, same unique-index authority, same
	 * read-back. It never repairs, re-points or disables an existing mapping.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function bindForProvisioning( ProvisioningMutex $mutex, int $portal_hall_id, int $blog_id, string $site_ownership_id ) {
		$proven = $this->provisioning_proof( $mutex, $portal_hall_id, $blog_id, $site_ownership_id );
		if ( is_wp_error( $proven ) ) {
			return $proven;
		}

		return $this->bind_internal( $portal_hall_id, $blog_id, true );
	}

	/**
	 * The active mapping for one site, tolerating an archived provisioning target.
	 *
	 * Same proof as bindForProvisioning(), and the same single relaxation. Used
	 * by the trusted provisioning synchronization path, which must resolve the
	 * Hall identity of a site that is deliberately still archived.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function findActiveByBlogIdForProvisioning( ProvisioningMutex $mutex, int $portal_hall_id, int $blog_id, string $site_ownership_id ) {
		$proven = $this->provisioning_proof( $mutex, $portal_hall_id, $blog_id, $site_ownership_id );
		if ( is_wp_error( $proven ) ) {
			return $proven;
		}

		return $this->validate_active_mapping( $this->find_one( 'blog_id', $blog_id, self::STATUS_ACTIVE ), true );
	}

	/**
	 * Mutex ownership plus the exact ownership witness, or a fixed refusal.
	 *
	 * FAILS CLOSED IN EVERY DIRECTION. A mutex for another Hall, a mutex the
	 * server no longer confirms, an unreadable witness, an absent witness and a
	 * different witness all land on the same refusal, because none of them
	 * proves this operation owns this site.
	 *
	 * @return true|\WP_Error
	 */
	private function provisioning_proof( ProvisioningMutex $mutex, int $portal_hall_id, int $blog_id, string $site_ownership_id ) {
		if ( $portal_hall_id < 1 || $blog_id < 1 ) {
			return $this->error( 'invalid_portal_hall_id', 'The Portal Hall mapping request is invalid.' );
		}
		if ( ! HallCommerceAccess::is_valid_marker( $site_ownership_id ) ) {
			return $this->error( 'mapping_ownership_unproven', 'Site ownership could not be proven for this Portal Hall mapping.' );
		}
		if ( $mutex->portalHallId() !== $portal_hall_id || ! $mutex->isStillHeld() ) {
			return $this->error( 'mapping_provisioning_not_current', 'The provisioning command is no longer current.' );
		}

		$marker = HallCommerceAccess::read_marker_value( $blog_id );
		if ( HallCommerceAccess::MARKER_VALID !== $marker['status']
			|| null === $marker['value']
			|| ! hash_equals( $site_ownership_id, (string) $marker['value'] ) ) {
			return $this->error( 'mapping_ownership_unproven', 'Site ownership could not be proven for this Portal Hall mapping.' );
		}

		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function bind_internal( int $portal_hall_id, int $blog_id, bool $allow_archived ) {
		$ready = $this->validate_input_and_schema( $portal_hall_id, $blog_id );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = $this->validate_site( $blog_id, $allow_archived );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$by_hall = $this->findByPortalHallId( $portal_hall_id );
		if ( is_wp_error( $by_hall ) ) {
			return $by_hall;
		}
		if ( null !== $by_hall ) {
			return $this->classify_existing_hall_mapping( $by_hall, $blog_id );
		}

		$by_blog = $this->findByBlogId( $blog_id );
		if ( is_wp_error( $by_blog ) ) {
			return $by_blog;
		}
		if ( null !== $by_blog ) {
			return $this->error( 'blog_already_mapped', 'The WordPress site is already bound to another Portal Hall.' );
		}

		global $wpdb;
		$now      = current_time( 'mysql', true );
		$previous = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			Migrations::hall_mappings_table(),
			[
				'portal_hall_id' => $portal_hall_id,
				'blog_id'        => $blog_id,
				'status'         => self::STATUS_ACTIVE,
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
		$wpdb->suppress_errors( $previous );

		if ( false === $inserted ) {
			// Unique indexes are the final authority under concurrent binds. Read
			// the winning state back and return a stable, explicit conflict.
			$by_hall = $this->findByPortalHallId( $portal_hall_id );
			if ( is_wp_error( $by_hall ) ) {
				return $by_hall;
			}
			if ( null !== $by_hall ) {
				return $this->classify_existing_hall_mapping( $by_hall, $blog_id );
			}

			$by_blog = $this->findByBlogId( $blog_id );
			if ( is_wp_error( $by_blog ) ) {
				return $by_blog;
			}
			if ( null !== $by_blog ) {
				return $this->error( 'blog_already_mapped', 'The WordPress site is already bound to another Portal Hall.' );
			}

			return $this->error( 'mapping_persistence_failed', 'The Portal Hall mapping could not be saved.' );
		}

		$mapping = $this->findByPortalHallId( $portal_hall_id );
		if ( is_wp_error( $mapping ) || null === $mapping ) {
			return $this->error( 'mapping_persistence_failed', 'The saved Portal Hall mapping could not be verified.' );
		}

		return $mapping;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function findByPortalHallId( int $portal_hall_id ) {
		if ( $portal_hall_id < 1 ) {
			return $this->error( 'invalid_portal_hall_id', 'The Portal Hall ID is invalid.' );
		}

		return $this->find_one( 'portal_hall_id', $portal_hall_id, null );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function findActiveByPortalHallId( int $portal_hall_id ) {
		if ( $portal_hall_id < 1 ) {
			return $this->error( 'invalid_portal_hall_id', 'The Portal Hall ID is invalid.' );
		}

		return $this->validate_active_mapping( $this->find_one( 'portal_hall_id', $portal_hall_id, self::STATUS_ACTIVE ) );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function findByBlogId( int $blog_id ) {
		if ( $blog_id < 1 ) {
			return $this->error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}

		return $this->find_one( 'blog_id', $blog_id, null );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function findActiveByBlogId( int $blog_id ) {
		if ( $blog_id < 1 ) {
			return $this->error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}

		return $this->validate_active_mapping( $this->find_one( 'blog_id', $blog_id, self::STATUS_ACTIVE ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function disable( int $portal_hall_id ) {
		return $this->change_status( $portal_hall_id, self::STATUS_DISABLED );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function enable( int $portal_hall_id ) {
		$mapping = $this->findByPortalHallId( $portal_hall_id );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		if ( null === $mapping ) {
			return $this->error( 'mapping_not_found', 'The Portal Hall mapping was not found.' );
		}

		$site = $this->validate_site( (int) $mapping['blog_id'] );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		return $this->change_status( $portal_hall_id, self::STATUS_ACTIVE );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function change_status( int $portal_hall_id, string $status ) {
		if ( ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_DISABLED ], true ) ) {
			return $this->error( 'invalid_mapping_status', 'The Portal Hall mapping status is invalid.' );
		}

		$mapping = $this->findByPortalHallId( $portal_hall_id );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}
		if ( null === $mapping ) {
			return $this->error( 'mapping_not_found', 'The Portal Hall mapping was not found.' );
		}
		if ( $status === $mapping['status'] ) {
			return $mapping;
		}

		global $wpdb;
		$updated = $wpdb->update(
			Migrations::hall_mappings_table(),
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'portal_hall_id' => $portal_hall_id,
				'status'         => $mapping['status'],
			],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);
		if ( false === $updated ) {
			return $this->error( 'mapping_persistence_failed', 'The Portal Hall mapping status could not be saved.' );
		}

		$after = $this->findByPortalHallId( $portal_hall_id );
		if ( is_wp_error( $after ) || null === $after || $status !== $after['status'] ) {
			return $this->error( 'mapping_state_changed', 'The Portal Hall mapping changed concurrently and was not overwritten.' );
		}

		return $after;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function find_one( string $column, int $value, ?string $status ) {
		if ( ! in_array( $column, [ 'portal_hall_id', 'blog_id' ], true ) ) {
			return $this->error( 'invalid_mapping_lookup', 'The Portal Hall mapping lookup is invalid.' );
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'mapping_schema_unavailable', 'The Portal Hall mapping schema is unavailable.' );
		}

		global $wpdb;
		$table = Migrations::hall_mappings_table();
		if ( null === $status ) {
			$sql = $wpdb->prepare(
				"SELECT portal_hall_id, blog_id, status, created_at, updated_at FROM {$table} WHERE {$column} = %d",
				$value
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT portal_hall_id, blog_id, status, created_at, updated_at FROM {$table} WHERE {$column} = %d AND status = %s",
				$value,
				$status
			);
		}

		$row = $wpdb->get_row( $sql, ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->error( 'mapping_read_failed', 'The Portal Hall mapping could not be read.' );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->map_row( $row );
	}

	/** @param array<string,mixed>|null|\WP_Error $mapping
	 *  @return array<string,mixed>|null|\WP_Error
	 */
	private function validate_active_mapping( $mapping, bool $allow_archived = false ) {
		if ( is_wp_error( $mapping ) || null === $mapping ) {
			return $mapping;
		}

		$site = $this->validate_site( (int) $mapping['blog_id'], $allow_archived );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		return $mapping;
	}

	/** @param array<string,mixed> $row
	 *  @return array<string,mixed>|\WP_Error
	 */
	private function map_row( array $row ) {
		$portal_hall_id = (int) ( $row['portal_hall_id'] ?? 0 );
		$blog_id        = (int) ( $row['blog_id'] ?? 0 );
		$status         = (string) ( $row['status'] ?? '' );
		if ( $portal_hall_id < 1 || $blog_id < 1 || ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_DISABLED ], true ) ) {
			return $this->error( 'mapping_state_inconsistent', 'The stored Portal Hall mapping is invalid.' );
		}

		return [
			'portal_hall_id' => $portal_hall_id,
			'blog_id'        => $blog_id,
			'status'         => $status,
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
			'updated_at'     => (string) ( $row['updated_at'] ?? '' ),
		];
	}

	/** @param array<string,mixed> $mapping
	 *  @return array<string,mixed>|\WP_Error
	 */
	private function classify_existing_hall_mapping( array $mapping, int $blog_id ) {
		if ( $blog_id !== (int) $mapping['blog_id'] ) {
			return $this->error( 'portal_hall_already_mapped', 'The Portal Hall is already bound to another WordPress site.' );
		}
		if ( self::STATUS_DISABLED === $mapping['status'] ) {
			return $this->error( 'mapping_disabled', 'The Portal Hall mapping is disabled and must be explicitly re-enabled.' );
		}

		return $mapping;
	}

	/** @return true|\WP_Error */
	private function validate_input_and_schema( int $portal_hall_id, int $blog_id ) {
		if ( $portal_hall_id < 1 ) {
			return $this->error( 'invalid_portal_hall_id', 'The Portal Hall ID is invalid.' );
		}
		if ( $blog_id < 1 ) {
			return $this->error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'mapping_schema_unavailable', 'The Portal Hall mapping schema is unavailable.' );
		}

		return true;
	}

	/**
	 * @param bool $allow_archived Set ONLY by a provisioning-scoped caller that
	 *                             has already proven mutex ownership and the
	 *                             exact `bc_commerce_managed` witness. It relaxes
	 *                             the archived refusal and nothing else.
	 * @return \WP_Site|\WP_Error
	 */
	private function validate_site( int $blog_id, bool $allow_archived = false ) {
		if ( ! is_multisite() ) {
			return $this->error( 'multisite_required', 'Portal Hall mappings require WordPress Multisite.' );
		}

		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site ) {
			return $this->error( 'site_not_found', 'The target WordPress site does not exist.' );
		}

		$network_id = get_current_network_id();
		if ( (int) $site->site_id !== $network_id ) {
			return $this->error( 'site_wrong_network', 'The target WordPress site does not belong to the current network.' );
		}
		if ( is_main_site( $blog_id, $network_id ) ) {
			return $this->error( 'main_site_not_allowed', 'The main network site cannot be mapped to a Portal Hall.' );
		}
		if ( '1' === (string) $site->deleted ) {
			return $this->error( 'site_deleted', 'A deleted WordPress site cannot be mapped to a Portal Hall.' );
		}
		if ( ! $allow_archived && '1' === (string) $site->archived ) {
			// An ADR-042 provisioning target is archived ON PURPOSE until its
			// mapping, synchronization, readiness and operational state all
			// succeed, so the one caller that has proven it owns this exact site
			// is permitted past this refusal. Every other caller is not.
			return $this->error( 'site_archived', 'An archived WordPress site cannot be mapped to a Portal Hall.' );
		}
		if ( '1' === (string) $site->spam ) {
			return $this->error( 'site_spam', 'A spam WordPress site cannot be mapped to a Portal Hall.' );
		}

		return $site;
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}
}
