<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Network repository and invariant boundary for Portal Hall/site mappings. */
final class HallMappingRepository {

	public const STATUS_ACTIVE = 'active';
	public const STATUS_DISABLED = 'disabled';

	/** @return array<string,mixed>|\WP_Error */
	public function bind( int $portal_hall_id, int $blog_id ) {
		$ready = $this->validate_input_and_schema( $portal_hall_id, $blog_id );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$site = $this->validate_site( $blog_id );
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
	private function validate_active_mapping( $mapping ) {
		if ( is_wp_error( $mapping ) || null === $mapping ) {
			return $mapping;
		}

		$site = $this->validate_site( (int) $mapping['blog_id'] );
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

	/** @return \WP_Site|\WP_Error */
	private function validate_site( int $blog_id ) {
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
		if ( '1' === (string) $site->archived ) {
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
