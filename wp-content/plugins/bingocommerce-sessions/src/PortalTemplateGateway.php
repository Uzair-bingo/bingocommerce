<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ONLY read path into this hall's Portal Session-template replica
 * (ADR-043 §4.1), mirroring PackageGateway's fail-closed shape.
 *
 * READS ONLY. Nothing here writes, and nothing here may: the replica is owned
 * by the Portal snapshot transport (ADR-040), which is the single writer. A
 * schedule consumes the replica the way it consumes the Catalog — through one
 * narrow gateway that fails closed — so "the Portal plugin is missing" or "the
 * replica table is absent" can never be mistaken for "no templates exist,
 * therefore this is fine".
 *
 * COMMERCE-ELIGIBLE means `is_current = 1 AND portal_status = 'active'`. The two
 * are independent facts and are never merged: `is_current` records replica
 * presence, `portal_status` records the Portal's own attribute, and the
 * transport keeps them independent by design
 * (`…/PortalSnapshotTransactionTransport.php:656-663`). They are shown to the
 * Manager as two columns for the same reason.
 *
 * NOTHING HERE AUTHORIZES A WRITE. Every mutation proves eligibility inside its
 * own guarded statement, against the database, at the instant it commits. A read
 * from this gateway is used to VALIDATE a request and to explain a refusal —
 * never as the proof a write relies on, because between the read and the write
 * the Portal can sync.
 */
final class PortalTemplateGateway {

	/** Upper bound on one administrative page of templates. */
	public const ADMIN_READ_PAGE_SIZE = 200;

	/** The Portal attribute that makes a template commerce-eligible. */
	public const STATUS_ACTIVE = 'active';

	/**
	 * One template by Portal id, or null when this hall has no replica row.
	 *
	 * Null means "this hall does not know about that template", which the UI
	 * reports as *template unavailable* — never as *template deleted*. Portal
	 * deletion is prohibited once a Game Type or Session exists (BR-039,
	 * ADR-035), so absence here is a replication state, not a life event.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function get( int $portal_session_id ) {
		if ( $portal_session_id < 1 ) {
			return $this->error( 'invalid_portal_session_id', 'The Portal Session template identity is missing or invalid.' );
		}

		$ready = $this->assert_replica_available();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		global $wpdb;

		$table = Migrations::portal_session_templates_table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations, not request input.
				"SELECT portal_session_id, name, portal_status, is_current, updated_at, not_current_at
				   FROM {$table}
				  WHERE portal_session_id = %d",
				$portal_session_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return '' === (string) $wpdb->last_error ? null : $this->persistence_error( (string) $wpdb->last_error );
		}

		return $this->map_row( $row );
	}

	/**
	 * One administrative page of templates, newest replica activity first.
	 *
	 * Draft and non-current templates are INCLUDED. §4.1 requires them to remain
	 * visible with their status shown verbatim, so a Manager can see why a
	 * template they remember cannot be scheduled. Filtering them out here would
	 * turn an explainable refusal into a disappearance.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error Keyed by portal_session_id.
	 */
	public function map( int $page = 1 ) {
		$ready = $this->assert_replica_available();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		global $wpdb;

		$table  = Migrations::portal_session_templates_table();
		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::ADMIN_READ_PAGE_SIZE;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Migrations, not request input.
				"SELECT portal_session_id, name, portal_status, is_current, updated_at, not_current_at
				   FROM {$table}
			   ORDER BY is_current DESC, portal_session_id ASC
				  LIMIT %d OFFSET %d",
				self::ADMIN_READ_PAGE_SIZE,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$mapped = [];
		foreach ( $rows as $row ) {
			$template = $this->map_row( $row );
			$mapped[ (int) $template['portal_session_id'] ] = $template;
		}

		return $mapped;
	}

	/**
	 * Whether a replica row is commerce-eligible (§4.1).
	 *
	 * @param array<string,mixed> $template
	 * @return true|\WP_Error
	 */
	public static function assert_commerce_eligible( array $template ) {
		if ( (int) ( $template['portal_session_id'] ?? 0 ) < 1 ) {
			return new \WP_Error(
				'template_not_commerce_eligible',
				__( 'The Portal Session template identity is missing or invalid.', 'bingocommerce-sessions' ),
				[ 'status' => 'template_not_commerce_eligible' ]
			);
		}

		if ( empty( $template['is_current'] ) ) {
			return new \WP_Error(
				'template_not_commerce_eligible',
				__( 'That Portal Session template is no longer current in this hall and cannot be scheduled for sale.', 'bingocommerce-sessions' ),
				[ 'status' => 'template_not_commerce_eligible' ]
			);
		}

		if ( self::STATUS_ACTIVE !== (string) ( $template['portal_status'] ?? '' ) ) {
			return new \WP_Error(
				'template_not_commerce_eligible',
				__( 'That Portal Session template is not active in the Portal and cannot be scheduled for sale.', 'bingocommerce-sessions' ),
				[ 'status' => 'template_not_commerce_eligible' ]
			);
		}

		return true;
	}

	/** @param array<string,mixed> $template */
	public static function is_commerce_eligible( array $template ): bool {
		return true === self::assert_commerce_eligible( $template );
	}

	/**
	 * Fail closed when the replica cannot be read at all.
	 *
	 * A missing schema is NOT an empty template list. Returning [] here would let
	 * a schedule be created against a template nothing could verify.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_replica_available() {
		if ( ! Migrations::is_current() ) {
			return $this->error( 'sessions_schema_unavailable', 'The Session schema is not ready for this hall.' );
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function map_row( array $row ): array {
		$template = [
			'portal_session_id' => (int) $row['portal_session_id'],
			// The Portal's own display string, kept verbatim. Never parsed for a
			// time, a date or any behaviour (BR-050).
			'name'              => (string) $row['name'],
			'portal_status'     => (string) $row['portal_status'],
			'is_current'        => 1 === (int) $row['is_current'],
			'updated_at'        => (string) $row['updated_at'],
			'not_current_at'    => null === $row['not_current_at'] ? null : (string) $row['not_current_at'],
		];

		// Derived for presentation only. Every WRITE re-proves eligibility in SQL.
		$template['commerce_eligible'] = self::is_commerce_eligible( $template );

		return $template;
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-sessions' ), [ 'status' => $code ] );
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'The Portal Session templates could not be read.', 'bingocommerce-sessions' ),
			[ 'db_error' => $db_error ]
		);
	}
}
