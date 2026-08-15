<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns core's network-wide schema definitions and their versioned, idempotent
 * application via dbDelta().
 *
 * Deliberately small: this is a schema/versioning service, not an ORM, not a
 * generic migration framework, and not a replacement for WordPress/WooCommerce
 * schema management. Feature plugins own their own tables; core owns only the
 * shared platform tables defined in network_schema().
 *
 * Scope note: only NETWORK-WIDE tables are handled here, so the schema version
 * lives in a network option (ADR-022/ADR-023). A per-site migration runner is
 * intentionally not built — nothing needs one yet, and adding one later is
 * additive rather than a redesign.
 *
 * Transaction note: MySQL commits implicitly on DDL, so dbDelta() must never
 * run inside a caller-managed transaction. That is why migrations execute on
 * admin_init, structurally separate from any runtime mutation path.
 */
final class Migrations {

	/**
	 * Bump when network_schema() changes so existing networks re-run dbDelta().
	 */
	const NETWORK_DB_VERSION = 2;

	/**
	 * Network option holding the schema version currently applied network-wide.
	 * Network-scoped on purpose: the tables below are shared across all halls,
	 * so a per-site option would be both wrong and redundantly duplicated.
	 */
	const NETWORK_VERSION_OPTION = 'bc_core_network_db_version';

	/**
	 * Unprefixed table names. Per ADR-018 the configured WordPress prefix
	 * already supplies the `bc_` namespace, so no sub-prefix is added here.
	 */
	const AUDIT_LOG_TABLE = 'audit_log';

	/**
	 * admin_init entry point.
	 *
	 * Cheap on every request once the network schema is current: a single
	 * cached site-option read, no dbDelta(), and deliberately no get_sites()
	 * loop — these tables are network-wide, so enumerating halls would do
	 * nothing but burn queries.
	 *
	 * @return true|\WP_Error True when the schema is current or was applied
	 *                        successfully, \WP_Error when migration failed.
	 */
	public static function maybe_migrate_network() {
		if ( (int) get_site_option( self::NETWORK_VERSION_OPTION, 0 ) >= self::NETWORK_DB_VERSION ) {
			return true;
		}

		$result = self::migrate_network();

		if ( is_wp_error( $result ) ) {
			/**
			 * Fires when a core network migration could not be applied.
			 *
			 * The schema version is intentionally left stale so the next admin
			 * request retries. Consumers may use this to surface the failure.
			 *
			 * @param \WP_Error $result Migration failure details.
			 */
			do_action( 'bingocommerce_core_migration_failed', $result );
		}

		return $result;
	}

	/**
	 * Apply every network schema definition, then verify the result before
	 * recording the version.
	 *
	 * dbDelta() reports its intentions rather than its errors, so success is
	 * confirmed by checking that each table actually exists. The version is
	 * never bumped optimistically: a failed run leaves it stale so the work is
	 * retried instead of being silently skipped forever.
	 *
	 * @return true|\WP_Error
	 */
	private static function migrate_network() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$missing = [];

		foreach ( self::network_schema() as $table => $sql ) {
			dbDelta( $sql );

			if ( ! self::table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		if ( empty( $missing ) ) {
			$verified = self::verify_audit_schema_v2();

			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'migration_failed',
				__( 'BingoCommerce core network schema could not be applied.', 'bingocommerce-core' ),
				[ 'missing_tables' => $missing ]
			);
		}

		update_site_option( self::NETWORK_VERSION_OPTION, self::NETWORK_DB_VERSION );

		/*
		 * Confirm the version actually landed in storage rather than trusting
		 * the return value. update_site_option() reports false both when the
		 * write fails AND when the stored value already matches — the second
		 * case happens routinely when a concurrent admin request persisted the
		 * version first, and is a success, not a failure.
		 *
		 * The check reads storage directly because that concurrent request
		 * updated the row without invalidating this process's option cache, so
		 * a cached read here could still report the pre-migration value.
		 */
		$persisted = self::persisted_network_version();

		if ( $persisted < self::NETWORK_DB_VERSION ) {
			return new \WP_Error(
				'migration_version_not_persisted',
				__( 'The BingoCommerce core network schema version could not be recorded.', 'bingocommerce-core' ),
				[
					'expected'  => self::NETWORK_DB_VERSION,
					'persisted' => $persisted,
				]
			);
		}

		return true;
	}

	/**
	 * Read the stored network schema version straight from the database,
	 * bypassing the option cache.
	 *
	 * Only ever called on the migration path, so the extra query costs nothing
	 * once the network is current.
	 */
	private static function persisted_network_version(): int {
		global $wpdb;

		if ( is_multisite() ) {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s",
					get_current_network_id(),
					self::NETWORK_VERSION_OPTION
				)
			);
		} else {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					self::NETWORK_VERSION_OPTION
				)
			);
		}

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Fully qualified name of the network-wide audit table.
	 *
	 * Network-wide per ADR-022/ADR-023: Platform Admin needs efficient
	 * cross-hall auditing, so tenant attribution is an indexed blog_id column
	 * rather than one table per hall.
	 */
	public static function audit_log_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::AUDIT_LOG_TABLE;
	}

	/**
	 * Whether a table physically exists. Used to verify migrations and to
	 * classify audit insert failures — never on a success path.
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return $found === $table;
	}

	/**
	 * Verify the additive v2 witness column and unique index exactly.
	 *
	 * dbDelta() describes intended changes but does not guarantee they landed.
	 * The network version therefore remains stale unless both artifacts exist
	 * with the contract required by ADR-025.
	 *
	 * @return true|\WP_Error
	 */
	private static function verify_audit_schema_v2() {
		global $wpdb;

		$table  = self::audit_log_table();
		$column = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'operation_id' ),
			ARRAY_A
		);

		if (
			! is_array( $column )
			|| 'char(36)' !== strtolower( (string) ( $column['Type'] ?? '' ) )
			|| 'YES' !== ( $column['Null'] ?? '' )
		) {
			return new \WP_Error(
				'migration_verification_failed',
				__( 'The audit operation_id column is missing or incompatible.', 'bingocommerce-core' )
			);
		}

		$indexes = $wpdb->get_results(
			$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", 'operation_id_unique' ),
			ARRAY_A
		);

		if (
			1 !== count( $indexes )
			|| 0 !== (int) $indexes[0]['Non_unique']
			|| 'operation_id' !== ( $indexes[0]['Column_name'] ?? '' )
			|| 1 !== (int) $indexes[0]['Seq_in_index']
		) {
			return new \WP_Error(
				'migration_verification_failed',
				__( 'The audit operation_id unique index is missing or incompatible.', 'bingocommerce-core' )
			);
		}

		return true;
	}

	/**
	 * Core-owned network table definitions, keyed by fully qualified table name.
	 *
	 * Adding a future core network table means adding one entry here and
	 * bumping NETWORK_DB_VERSION — no structural change to this class.
	 *
	 * dbDelta() is strict about formatting: one field per line, two spaces in
	 * `PRIMARY KEY  (id)`, `KEY` rather than `INDEX`, and every index explicitly
	 * named. Getting this wrong makes dbDelta re-issue ALTER statements forever.
	 *
	 * @return array<string,string>
	 */
	private static function network_schema(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$audit_log       = self::audit_log_table();

		/*
		 * Indexes map 1:1 to the audit access patterns we actually expect:
		 * per-hall history, per-actor history, per-event-type history, and
		 * per-target history. Each leads with its selective column and closes
		 * with created_at so time-ordered reads stay index-covered. Payload
		 * columns are never indexed.
		 */
		$schema = [
		$audit_log => "CREATE TABLE {$audit_log} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	operation_id char(36) DEFAULT NULL,
	blog_id bigint(20) unsigned DEFAULT NULL,
	actor_user_id bigint(20) unsigned DEFAULT NULL,
	action varchar(100) NOT NULL,
	target_type varchar(50) DEFAULT NULL,
	target_id varchar(191) DEFAULT NULL,
	previous_value longtext,
	new_value longtext,
	context longtext,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY operation_id_unique (operation_id),
	KEY blog_created (blog_id,created_at),
	KEY actor_created (actor_user_id,created_at),
	KEY action_created (action,created_at),
	KEY target_created (target_type,target_id,created_at)
) ENGINE=InnoDB {$charset_collate};",
		];

		return $schema;
	}
}
