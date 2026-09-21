<?php

namespace BingoCommerce\Sessions;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the hall-local Session schema and its per-site version state.
 *
 * This plugin owns `bingo_sessions`, `bingo_session_packages`, and the local
 * Session-domain replica table `bingo_portal_session_templates`. Catalog and
 * the Portal integration plugin do not own Session-domain persistence.
 */
final class Migrations {

	public const DB_VERSION = 3;
	public const VERSION_OPTION = 'bc_sessions_db_version';
	public const NETWORK_SWEEP_OPTION = 'bc_sessions_network_migration_sweep_version';
	public const SESSIONS_TABLE = 'bingo_sessions';
	public const SESSION_PACKAGES_TABLE = 'bingo_session_packages';
	public const PORTAL_SESSION_TEMPLATES_TABLE = 'bingo_portal_session_templates';
	public const SCHEDULES_TABLE = 'bingo_session_schedules';
	public const SCHEDULE_DATES_TABLE = 'bingo_session_schedule_dates';
	public const SCHEDULE_PACKAGES_TABLE = 'bingo_session_schedule_packages';

	/** @return true|\WP_Error */
	public static function maybe_migrate_current_site() {
		if ( ! HallContext::is_hall() || self::is_current() ) {
			return true;
		}

		$result = self::migrate_current_site();
		if ( is_wp_error( $result ) ) {
			do_action( 'bingocommerce_sessions_migration_failed', $result, get_current_blog_id() );
		}

		return $result;
	}

	/**
	 * One network catch-up pass for existing halls. Each hall retains its own
	 * authoritative schema version; the network option only avoids repeating a
	 * completed enumeration on every admin request.
	 *
	 * @return true|\WP_Error
	 */
	public static function maybe_migrate_network() {
		if ( ! is_multisite() ) {
			return self::maybe_migrate_current_site();
		}

		$actor_id = get_current_user_id();
		if ( ! is_network_admin() || $actor_id < 1 || ! is_super_admin( $actor_id ) ) {
			return true;
		}

		if ( self::persisted_network_sweep_version() >= self::DB_VERSION ) {
			return true;
		}

		$failed = [];
		foreach ( get_sites( [ 'number' => 0 ] ) as $site ) {
			$site_id = (int) $site->blog_id;
			switch_to_blog( $site_id );
			try {
				if ( HallContext::is_hall() ) {
					$result = self::maybe_migrate_current_site();
					if ( is_wp_error( $result ) ) {
						$failed[ $site_id ] = $result->get_error_code();
					}
				}
			} finally {
				restore_current_blog();
			}
		}

		if ( [] !== $failed ) {
			return new \WP_Error(
				'sessions_network_migration_incomplete',
				__( 'One or more hall Session schemas could not be migrated.', 'bingocommerce-sessions' ),
				[ 'failed_sites' => $failed ]
			);
		}

		update_site_option( self::NETWORK_SWEEP_OPTION, self::DB_VERSION );
		if ( self::persisted_network_sweep_version() < self::DB_VERSION ) {
			return new \WP_Error(
				'sessions_network_sweep_version_not_persisted',
				__( 'The Session migration sweep version could not be recorded.', 'bingocommerce-sessions' )
			);
		}

		return true;
	}

	/** @return true|\WP_Error */
	public static function provision_new_site( \WP_Site $new_site ) {
		if ( ! is_multisite() ) {
			return true;
		}

		switch_to_blog( (int) $new_site->blog_id );
		try {
			if ( ! HallContext::is_hall() ) {
				return true;
			}

			$result = self::migrate_current_site();
			if ( is_wp_error( $result ) ) {
				do_action( 'bingocommerce_sessions_migration_failed', $result, (int) $new_site->blog_id );
			}

			return $result;
		} finally {
			restore_current_blog();
		}
	}

	/** @return true|\WP_Error */
	public static function migrate_current_site() {
		if ( ! HallContext::is_hall() ) {
			return new \WP_Error(
				'invalid_hall_context',
				__( 'Session migrations may run only for a hall site.', 'bingocommerce-sessions' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sessions = self::sessions_table();
		dbDelta(
			"CREATE TABLE {$sessions} (
	session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	session_date date NOT NULL,
	display_name varchar(191) NOT NULL,
	available_for_sale tinyint(1) unsigned NOT NULL DEFAULT 0,
	status varchar(16) NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	archived_at datetime DEFAULT NULL,
	schedule_id bigint(20) unsigned DEFAULT NULL,
	start_time_local time DEFAULT NULL,
	timezone_name varchar(64) COLLATE utf8mb4_bin DEFAULT NULL,
	starts_at_utc datetime DEFAULT NULL,
	sale_locked_at datetime DEFAULT NULL,
	generation_completed_at datetime DEFAULT NULL,
	availability_operation_token char(36) COLLATE utf8mb4_bin DEFAULT NULL,
	PRIMARY KEY  (session_id),
	UNIQUE KEY session_date_display_name (session_date,display_name),
	UNIQUE KEY schedule_occurrence_unique (schedule_id,session_date),
	KEY status_sale_date (status,available_for_sale,session_date),
	KEY session_date (session_date),
	KEY starts_at_utc_idx (starts_at_utc)
) ENGINE=InnoDB {$charset_collate};"
		);

		$session_packages = self::session_packages_table();
		dbDelta(
			"CREATE TABLE {$session_packages} (
	session_package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	session_id bigint(20) unsigned NOT NULL,
	package_id bigint(20) unsigned NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (session_package_id),
	UNIQUE KEY session_package_unique (session_id,package_id),
	KEY package_session (package_id,session_id)
) ENGINE=InnoDB {$charset_collate};"
		);

		$portal_session_templates = self::portal_session_templates_table();
		// portal_status preserves the Portal source attribute. is_current records
		// replica presence/currentness only. Neither is Commerce selection.
		dbDelta(
			"CREATE TABLE {$portal_session_templates} (
	session_template_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	portal_session_id bigint(20) unsigned NOT NULL,
	name text NOT NULL,
	portal_status varchar(32) NOT NULL,
	is_current tinyint(1) unsigned NOT NULL DEFAULT 1,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	not_current_at datetime DEFAULT NULL,
	PRIMARY KEY  (session_template_id),
	UNIQUE KEY portal_session_unique (portal_session_id)
) ENGINE=InnoDB {$charset_collate};"
		);

		/*
		 * ADR-043 §2.4 identity columns use utf8mb4_bin, NOT `CHARACTER SET
		 * ascii COLLATE ascii_bin`.
		 *
		 * The ADR's literal DDL names ascii_bin, and that is correct for the
		 * three NEW tables, whose every collated column is ascii. It is WRONG
		 * here, and provably so: wpdb::get_table_charset() derives one charset
		 * for the whole table from its columns, and on a mix it falls through to
		 * "Two mixed character sets. ascii." (wp-includes/class-wpdb.php:3284-3286).
		 * Adding ascii columns beside the utf8mb4 `display_name` therefore makes
		 * wpdb treat bingo_sessions as an ascii table and REJECT every non-ASCII
		 * display name — "Could not perform query because it contains invalid
		 * data." — which would break existing halls, whose Session names already
		 * contain en dashes.
		 *
		 * utf8mb4_bin keeps the property the ADR actually requires — exact,
		 * case- and accent-sensitive comparison, so `A3F` and `a3f` are never
		 * the same token — while leaving the table single-charset. Verified
		 * against the installed MySQL 9.5.0.
		 */

		// ADR-043 §2.1. `active_definition_token` NULL means the schedule has no
		// readable definition: inert, generating nothing. ascii_bin on every
		// identity column so `A3F` and `a3f` can never be the same token.
		$schedules = self::schedules_table();
		dbDelta(
			"CREATE TABLE {$schedules} (
	schedule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	portal_session_id bigint(20) unsigned NOT NULL,
	timezone_name varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	start_time_local time NOT NULL,
	mode varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	weekday_mask tinyint(3) unsigned NOT NULL DEFAULT 0,
	recurrence_start date DEFAULT NULL,
	recurrence_end date DEFAULT NULL,
	active_definition_token char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
	status varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	archived_at datetime DEFAULT NULL,
	PRIMARY KEY  (schedule_id),
	KEY portal_session_status (portal_session_id,status,schedule_id)
) ENGINE=InnoDB {$charset_collate};"
		);

		// ADR-043 §2.2. Explicitly selected dates for `mode = dates` only; a
		// weekly schedule stores zero rows here.
		$schedule_dates = self::schedule_dates_table();
		dbDelta(
			"CREATE TABLE {$schedule_dates} (
	schedule_date_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	schedule_id bigint(20) unsigned NOT NULL,
	definition_token char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	schedule_date date NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (schedule_date_id),
	UNIQUE KEY schedule_token_date_unique (schedule_id,definition_token,schedule_date)
) ENGINE=InnoDB {$charset_collate};"
		);

		// ADR-043 §2.3. Written onto an occurrence ONLY by the internal
		// generation writer, and only while that occurrence is incomplete.
		$schedule_packages = self::schedule_packages_table();
		dbDelta(
			"CREATE TABLE {$schedule_packages} (
	schedule_package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	schedule_id bigint(20) unsigned NOT NULL,
	definition_token char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	package_id bigint(20) unsigned NOT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (schedule_package_id),
	UNIQUE KEY schedule_token_package_unique (schedule_id,definition_token,package_id),
	KEY package_schedule (package_id,schedule_id)
) ENGINE=InnoDB {$charset_collate};"
		);

		// The version is the hall's promise that ALL THREE Session schemas are
		// usable, so it is persisted only after every required structure verifies.
		$verified = self::verify_current_site_schema();
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		if ( self::persisted_site_version() < self::DB_VERSION ) {
			return new \WP_Error(
				'sessions_migration_version_not_persisted',
				__( 'The Session schema version could not be recorded.', 'bingocommerce-sessions' )
			);
		}

		return true;
	}

	public static function is_current(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) >= self::DB_VERSION;
	}

	public static function sessions_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::SESSIONS_TABLE;
	}

	public static function session_packages_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::SESSION_PACKAGES_TABLE;
	}

	public static function portal_session_templates_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::PORTAL_SESSION_TEMPLATES_TABLE;
	}

	public static function schedules_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::SCHEDULES_TABLE;
	}

	public static function schedule_dates_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::SCHEDULE_DATES_TABLE;
	}

	public static function schedule_packages_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::SCHEDULE_PACKAGES_TABLE;
	}

	/** @return true|\WP_Error */
	public static function verify_current_site_schema() {
		$sessions = self::verify_sessions_schema();
		if ( is_wp_error( $sessions ) ) {
			return $sessions;
		}

		$session_packages = self::verify_session_packages_schema();
		if ( is_wp_error( $session_packages ) ) {
			return $session_packages;
		}

		$templates = self::verify_portal_session_templates_schema();
		if ( is_wp_error( $templates ) ) {
			return $templates;
		}

		$schedules = self::verify_session_schedules_schema();
		if ( is_wp_error( $schedules ) ) {
			return $schedules;
		}

		$schedule_dates = self::verify_schedule_dates_schema();
		if ( is_wp_error( $schedule_dates ) ) {
			return $schedule_dates;
		}

		$schedule_packages = self::verify_schedule_packages_schema();
		if ( is_wp_error( $schedule_packages ) ) {
			return $schedule_packages;
		}

		// ADR-043 §2.5. Structure is not enough: two indexed data probes must
		// also return zero before the hall's version may advance.
		return self::verify_structured_time_integrity();
	}

	/**
	 * ADR-043 §2.1 Commerce schedule.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_session_schedules_schema() {
		$table = self::schedules_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Commerce schedule table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Commerce schedule columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'schedule_id'             => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'portal_session_id'       => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'timezone_name'           => [ '/^varchar\(64\)$/', 'NO', null, '', 'ascii_bin' ],
				'start_time_local'        => [ '/^time$/', 'NO', null, '' ],
				'mode'                    => [ '/^varchar\(16\)$/', 'NO', null, '', 'ascii_bin' ],
				'weekday_mask'            => [ '/^tinyint(?:\(3\))? unsigned$/', 'NO', '0', '' ],
				'recurrence_start'        => [ '/^date$/', 'YES', null, '' ],
				'recurrence_end'          => [ '/^date$/', 'YES', null, '' ],
				'active_definition_token' => [ '/^char\(36\)$/', 'YES', null, '', 'ascii_bin' ],
				'status'                  => [ '/^varchar\(16\)$/', 'NO', 'active', '', 'ascii_bin' ],
				'created_at'              => [ '/^datetime$/', 'NO', null, '' ],
				'updated_at'              => [ '/^datetime$/', 'NO', null, '' ],
				'archived_at'             => [ '/^datetime$/', 'YES', null, '' ],
			],
			'Commerce schedule'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'               => [ true, [ 'schedule_id' ] ],
				'portal_session_status' => [ false, [ 'portal_session_id', 'status', 'schedule_id' ] ],
			],
			'Commerce schedule'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Commerce schedule' );
	}

	/**
	 * ADR-043 §2.2 explicit schedule dates.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_schedule_dates_schema() {
		$table = self::schedule_dates_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Commerce schedule date table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Commerce schedule date columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'schedule_date_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'schedule_id'      => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'definition_token' => [ '/^char\(36\)$/', 'NO', null, '', 'ascii_bin' ],
				'schedule_date'    => [ '/^date$/', 'NO', null, '' ],
				'created_at'       => [ '/^datetime$/', 'NO', null, '' ],
			],
			'Commerce schedule date'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'                    => [ true, [ 'schedule_date_id' ] ],
				'schedule_token_date_unique' => [ true, [ 'schedule_id', 'definition_token', 'schedule_date' ] ],
			],
			'Commerce schedule date'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Commerce schedule date' );
	}

	/**
	 * ADR-043 §2.3 schedule Package defaults.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_schedule_packages_schema() {
		$table = self::schedule_packages_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Commerce schedule Package table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Commerce schedule Package columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'schedule_package_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'schedule_id'         => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'definition_token'    => [ '/^char\(36\)$/', 'NO', null, '', 'ascii_bin' ],
				'package_id'          => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'created_at'          => [ '/^datetime$/', 'NO', null, '' ],
			],
			'Commerce schedule Package'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'                       => [ true, [ 'schedule_package_id' ] ],
				'schedule_token_package_unique' => [ true, [ 'schedule_id', 'definition_token', 'package_id' ] ],
				'package_schedule'              => [ false, [ 'package_id', 'schedule_id' ] ],
			],
			'Commerce schedule Package'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Commerce schedule Package' );
	}

	/**
	 * ADR-043 §2.5 structured-time integrity, as two indexed counts.
	 *
	 * A GENERATED occurrence must carry complete, minute-precise structured
	 * time. A missing value fails exactly as a non-zero second does, because a
	 * name rendered from a NULL time is as wrong as one that silently drops 30
	 * seconds. Legacy rows are exempt by the `schedule_id IS NOT NULL` predicate
	 * and are never inspected.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_structured_time_integrity() {
		global $wpdb;

		$schedules = self::schedules_table();
		$imprecise = $wpdb->get_var( "SELECT COUNT(*) FROM `{$schedules}` WHERE SECOND(start_time_local) <> 0" );
		if ( null === $imprecise || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( 'The Commerce schedule structured time could not be inspected.' );
		}
		if ( 0 !== (int) $imprecise ) {
			return self::verification_error( 'A Commerce schedule stores a structured time that is not minute-precise.' );
		}

		$sessions   = self::sessions_table();
		$incomplete = $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$sessions}`"
			. ' WHERE schedule_id IS NOT NULL'
			. ' AND ('
			. '   start_time_local IS NULL'
			. '   OR SECOND(start_time_local) <> 0'
			. '   OR timezone_name IS NULL'
			. '   OR starts_at_utc IS NULL'
			. ' )'
		);
		if ( null === $incomplete || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( 'The generated occurrence structured time could not be inspected.' );
		}
		if ( 0 !== (int) $incomplete ) {
			return self::verification_error( 'A generated occurrence is missing complete, minute-precise structured time.' );
		}

		return true;
	}

	/** @return true|\WP_Error */
	public static function verify_sessions_schema() {
		$table = self::sessions_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Session table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Session columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'session_id'         => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'session_date'       => [ '/^date$/', 'NO', null, '' ],
				'display_name'       => [ '/^varchar\(191\)$/', 'NO', null, '' ],
				'available_for_sale' => [ '/^tinyint(?:\(1\))? unsigned$/', 'NO', '0', '' ],
				'status'             => [ '/^varchar\(16\)$/', 'NO', 'active', '' ],
				'created_at'         => [ '/^datetime$/', 'NO', null, '' ],
				'updated_at'         => [ '/^datetime$/', 'NO', null, '' ],
				'archived_at'        => [ '/^datetime$/', 'YES', null, '' ],
				// ADR-043 §2.4. All seven are NULLABLE: a legacy or manual
				// occurrence carries NULL in every one and keeps v2 behaviour.
				'schedule_id'                  => [ '/^bigint(?:\(20\))? unsigned$/', 'YES', null, '' ],
				'start_time_local'             => [ '/^time$/', 'YES', null, '' ],
				'timezone_name'                => [ '/^varchar\(64\)$/', 'YES', null, '', 'utf8mb4_bin' ],
				'starts_at_utc'                => [ '/^datetime$/', 'YES', null, '' ],
				'sale_locked_at'               => [ '/^datetime$/', 'YES', null, '' ],
				'generation_completed_at'      => [ '/^datetime$/', 'YES', null, '' ],
				'availability_operation_token' => [ '/^char\(36\)$/', 'YES', null, '', 'utf8mb4_bin' ],
			],
			'Session'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		// assert_indexes() compares the EXACT ordered column list, so the frozen
		// composite shapes cannot be satisfied by a differently ordered index.
		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'                  => [ true, [ 'session_id' ] ],
				'session_date_display_name' => [ true, [ 'session_date', 'display_name' ] ],
				// ADR-043 §2.4. MySQL treats NULLs as distinct, so unlimited legacy
				// rows with schedule_id IS NULL remain legal under this unique key.
				'schedule_occurrence_unique' => [ true, [ 'schedule_id', 'session_date' ] ],
				'status_sale_date'         => [ false, [ 'status', 'available_for_sale', 'session_date' ] ],
				'session_date'             => [ false, [ 'session_date' ] ],
				'starts_at_utc_idx'        => [ false, [ 'starts_at_utc' ] ],
			],
			'Session'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Session' );
	}

	/** @return true|\WP_Error */
	public static function verify_session_packages_schema() {
		$table = self::session_packages_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Session Package assignment table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Session Package assignment columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'session_package_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'session_id'         => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'package_id'         => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'created_at'         => [ '/^datetime$/', 'NO', null, '' ],
			],
			'Session Package assignment'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'                 => [ true, [ 'session_package_id' ] ],
				// The authoritative duplicate-relationship constraint. The writer
				// relies on this rather than a check-then-insert.
				'session_package_unique'  => [ true, [ 'session_id', 'package_id' ] ],
				'package_session'         => [ false, [ 'package_id', 'session_id' ] ],
			],
			'Session Package assignment'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Session Package assignment' );
	}

	/** @return true|\WP_Error */
	public static function verify_portal_session_templates_schema() {
		$structure = self::verify_portal_session_templates_structure();
		if ( is_wp_error( $structure ) ) {
			return $structure;
		}

		return self::assert_engine_and_charset( self::portal_session_templates_table(), 'Portal Session Template replica' );
	}

	/**
	 * Columns and indexes ONLY — no storage-engine or charset assertion.
	 *
	 * Split out for ADR-043 §11.3's Portal-sync readiness gate, which runs BEFORE
	 * the snapshot fetch and must not take over a check another component already
	 * owns. `PortalSnapshotTransactionTransport` asserts InnoDB for exactly this
	 * table through `REQUIRED_INNODB_TABLES` (`…:101-107`), deliberately AFTER the
	 * fetch and before `BEGIN`, and reports it with its own
	 * `portal_sync_storage_engine_unsupported` code. A readiness gate that also
	 * rejected a non-InnoDB replica would pre-empt that gate, change when the
	 * fetch happens relative to the engine check, and replace a specific error
	 * code with a vaguer one — so the readiness gate asks this narrower question
	 * and leaves the engine to its owner.
	 *
	 * The full verifier above still asserts both, because migration DOES need to
	 * prove the engine.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_portal_session_templates_structure() {
		$table = self::portal_session_templates_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Portal Session Template replica table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Portal Session Template replica columns could not be inspected.' );
		}

		$expected_columns = [
			'session_template_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
			'portal_session_id'   => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
			'name'                => [ '/^text$/', 'NO', null, '' ],
			'portal_status'       => [ '/^varchar\(32\)$/', 'NO', null, '' ],
			'is_current'          => [ '/^tinyint(?:\(1\))? unsigned$/', 'NO', '1', '' ],
			'created_at'          => [ '/^datetime$/', 'NO', null, '' ],
			'updated_at'          => [ '/^datetime$/', 'NO', null, '' ],
			'not_current_at'      => [ '/^datetime$/', 'YES', null, '' ],
		];
		if ( array_keys( $expected_columns ) !== array_keys( $by_name ) ) {
			return self::verification_error( 'The Portal Session Template replica columns are missing or incompatible.' );
		}

		$columns = self::assert_columns( $by_name, $expected_columns, 'Portal Session Template replica' );
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		return self::assert_indexes(
			$table,
			[
				'PRIMARY'               => [ true, [ 'session_template_id' ] ],
				'portal_session_unique' => [ true, [ 'portal_session_id' ] ],
			],
			'Portal Session Template replica'
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return $found === $table;
	}

	/** @return array<string,array<string,mixed>>|null */
	private static function columns_by_name( string $table ): ?array {
		global $wpdb;

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $columns ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$by_name = [];
		foreach ( $columns as $column ) {
			$by_name[ $column['Field'] ] = $column;
		}

		return $by_name;
	}

	/**
	 * @param array<string,array<string,mixed>> $by_name
	 * @param array<string,array{0:string,1:string,2:string|null,3:string}> $expected
	 * @return true|\WP_Error
	 */
	private static function assert_columns( array $by_name, array $expected, string $label ) {
		foreach ( $expected as $name => $rule ) {
			[ $type, $nullable, $default, $extra ] = $rule;

			// ADR-043 §2.1/§2.4: an identity column must collate ascii_bin, so
			// `A3F` and `a3f` can never be the same token. An absent entry means
			// the table default is correct and no collation is asserted.
			$collation = $rule[4] ?? null;

			$actual = $by_name[ $name ] ?? null;
			if (
				! is_array( $actual )
				|| 1 !== preg_match( $type, strtolower( (string) $actual['Type'] ) )
				|| $nullable !== ( $actual['Null'] ?? null )
				|| $default !== ( $actual['Default'] ?? null )
				|| $extra !== strtolower( (string) ( $actual['Extra'] ?? '' ) )
			) {
				return self::verification_error( sprintf( 'The %s %s column is missing or incompatible.', $label, $name ) );
			}

			if (
				null !== $collation
				&& 0 !== strpos( strtolower( (string) ( $actual['Collation'] ?? '' ) ), $collation )
			) {
				return self::verification_error( sprintf( 'The %s %s column collation is incompatible.', $label, $name ) );
			}
		}

		return true;
	}

	/**
	 * @param array<string,array{0:bool,1:array<int,string>}> $required
	 * @return true|\WP_Error
	 */
	private static function assert_indexes( string $table, array $required, string $label ) {
		global $wpdb;

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $indexes ) || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( sprintf( 'The %s indexes could not be inspected.', $label ) );
		}

		$actual_indexes = [];
		foreach ( $indexes as $index ) {
			$key = (string) $index['Key_name'];
			$actual_indexes[ $key ]['unique'] = 0 === (int) $index['Non_unique'];
			$actual_indexes[ $key ]['columns'][ (int) $index['Seq_in_index'] ] = (string) $index['Column_name'];
		}
		foreach ( $actual_indexes as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );

		foreach ( $required as $name => [ $unique, $index_columns ] ) {
			if (
				! isset( $actual_indexes[ $name ] )
				|| $unique !== $actual_indexes[ $name ]['unique']
				|| $index_columns !== $actual_indexes[ $name ]['columns']
			) {
				return self::verification_error( sprintf( 'The %s %s index is missing or incompatible.', $label, $name ) );
			}
		}

		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_engine_and_charset( string $table, string $label ) {
		global $wpdb;

		$status = $wpdb->get_row(
			$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ),
			ARRAY_A
		);
		if (
			! is_array( $status )
			|| 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) )
			|| 0 !== strpos( strtolower( (string) ( $status['Collation'] ?? '' ) ), 'utf8mb4_' )
		) {
			return self::verification_error( sprintf( 'The %s table engine or utf8mb4 collation is incompatible.', $label ) );
		}

		return true;
	}

	private static function persisted_site_version(): int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", self::VERSION_OPTION )
		);

		return null === $value ? 0 : (int) $value;
	}

	private static function persisted_network_sweep_version(): int {
		return (int) get_site_option( self::NETWORK_SWEEP_OPTION, 0 );
	}

	private static function verification_error( string $message ): \WP_Error {
		return new \WP_Error(
			'sessions_migration_verification_failed',
			__( $message, 'bingocommerce-sessions' )
		);
	}
}
