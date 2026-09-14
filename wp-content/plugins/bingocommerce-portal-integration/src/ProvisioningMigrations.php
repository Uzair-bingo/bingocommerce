<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the network-wide Commerce-state and provisioning-operations schemas.
 *
 * DELIBERATELY SEPARATE from Migrations::NETWORK_DB_VERSION, which stays at 1.
 * That option gates every Hall-mapping read in HallMappingRepository::find_one();
 * bumping it here would make the shipped mapping and Portal Sync screens report
 * `mapping_schema_unavailable` network-wide for the window between deploying
 * these files and the first migration run.
 *
 * NOT WIRED TO ANY AUTOMATIC PATH. There is no activation hook, no `init`, no
 * `admin_init`, no cron. migrate() is called explicitly and nowhere else, so a
 * running web server cannot apply this schema merely because the files are on
 * disk. Connecting it to a trigger is a later increment's decision.
 *
 * VERSION 2 ADDS THE ADR-042 PROVISIONING OPERATIONS TABLE. A genuine v1 -> v2
 * upgrade must not recreate, rewrite, truncate or alter the commerce-state
 * table or any of its rows, so the state DDL is issued only when the state
 * schema does not already verify. A clean installation creates both.
 *
 * VERSION VALUE ALONE NEVER PROVES READINESS. is_current() answers the version
 * question only; is_ready() is the runtime gate and requires the structure
 * itself to verify.
 */
final class ProvisioningMigrations {

	public const DB_VERSION     = 2;
	public const VERSION_OPTION = 'bc_portal_provisioning_db_version';
	public const STATE_TABLE    = 'bingo_hall_commerce_state';

	/** ADR-042 network-wide provisioning operations table (one row per Hall). */
	public const OPERATIONS_TABLE = 'bingo_portal_provisioning_operations';

	/** Schema version at which the operations table was introduced. */
	public const OPERATIONS_DB_VERSION = 2;

	/**
	 * Per-request positive readiness cache.
	 *
	 * Only a fully verified result is cached, and only for the life of one PHP
	 * request. A failed or unreadable probe invalidates it rather than being
	 * remembered, so a transient storage failure can never leave a later call
	 * believing the schema is ready.
	 */
	private static ?bool $ready_cache = null;

	/** Network-wide table, keyed by the tenant's blog_id (AGENTS rule 6). */
	public static function state_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::STATE_TABLE;
	}

	/** Network-wide table, keyed by Portal Hall identity (ADR-042). */
	public static function operations_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::OPERATIONS_TABLE;
	}

	/**
	 * Is the recorded schema version current?
	 *
	 * Public behaviour is unchanged: a boolean, with an unreadable version
	 * treated as NOT current so callers fail closed. Callers that must tell
	 * "absent" from "unreadable" — the migration itself — use read_version().
	 */
	public static function is_current(): bool {
		$version = self::read_version();

		return $version instanceof \WP_Error ? false : $version >= self::DB_VERSION;
	}

	/**
	 * The ADR-042 runtime readiness gate. Performs NO DDL and NO repair.
	 *
	 * Readiness requires BOTH a readable version at or above the operations
	 * version AND successful structural verification. A recorded version with a
	 * damaged or missing table is NOT ready, which is the whole point: the
	 * version records what a migration once installed, not what the database
	 * currently holds.
	 *
	 * The positive result is cached per request. Every failure path clears the
	 * cache instead of storing `false`, so nothing is remembered from a probe
	 * that could not be completed.
	 */
	public static function is_ready(): bool {
		if ( true === self::$ready_cache ) {
			return true;
		}

		$version = self::read_version();
		if ( $version instanceof \WP_Error || $version < self::OPERATIONS_DB_VERSION ) {
			self::$ready_cache = null;

			return false;
		}

		if ( true !== self::verify_schema() ) {
			self::$ready_cache = null;

			return false;
		}

		self::$ready_cache = true;

		return true;
	}

	/**
	 * Discard the per-request readiness cache.
	 *
	 * Exists so a migration in this same request, and the focused harness, can
	 * prove readiness is re-derived from storage rather than from memory.
	 */
	public static function flush_ready_cache(): void {
		self::$ready_cache = null;
	}

	/**
	 * The recorded schema version, or a fixed error when it cannot be read.
	 *
	 * Read directly rather than through get_site_option(), which swallows a
	 * database error and returns the default — indistinguishable from a version
	 * that is genuinely absent. The migration must not treat "we could not ask"
	 * as "nothing is installed", so the distinction is made here.
	 *
	 * @return int|\WP_Error
	 */
	private static function read_version() {
		global $wpdb;

		// Owns its own suppression because is_current() calls it outside the
		// migration's boundary. Restored on success, on a database error and on
		// a throw alike.
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1",
					get_current_network_id(),
					self::VERSION_OPTION
				)
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			// Never surfaced: the message can quote SQL and server text.
			unset( $throwable );

			return self::error( 'The Commerce state schema version could not be read.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( '' !== $error ) {
			return self::error( 'The Commerce state schema version could not be read.' );
		}

		return null === $value ? 0 : (int) $value;
	}

	/**
	 * Explicit migration entry point. Safe to call repeatedly.
	 *
	 * The version is advanced ONLY after the resulting schema has been verified,
	 * so a partial or incompatible dbDelta result cannot leave the option
	 * claiming a schema that is not actually present.
	 *
	 * @return true|\WP_Error
	 */
	public static function migrate() {
		if ( ! is_multisite() ) {
			return self::error( 'The Commerce state schema requires WordPress Multisite.' );
		}

		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			return self::run_migration();
		} catch ( \Throwable $throwable ) {
			// Never surfaced: migration failures can quote SQL and server text.
			unset( $throwable );

			return self::error( 'The Commerce state schema could not be applied.' );
		} finally {
			// Restored on every path, including a throw.
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/** @return true|\WP_Error */
	private static function run_migration() {
		/*
		 * The version is read ONCE, directly, and an unreadable version stops the
		 * migration before any schema or version write.
		 *
		 * is_current() deliberately collapses "could not read" to false to keep
		 * its boolean contract for callers that only need a yes/no. Using it here
		 * would turn a database error into "nothing is installed yet" and send
		 * dbDelta at a database we could not even question.
		 */
		$version = self::read_version();
		if ( $version instanceof \WP_Error ) {
			return $version;
		}

		if ( $version >= self::DB_VERSION ) {
			$verified = self::verify_schema();

			return $verified instanceof \WP_Error ? $verified : true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * PRESERVATION OF THE v1 TABLE IS STRUCTURAL, NOT INCIDENTAL.
		 *
		 * dbDelta on an already-correct table normally issues nothing, but
		 * "normally" is not a guarantee worth resting row preservation on. The
		 * state DDL is therefore issued ONLY when the state schema does not
		 * already verify, so a genuine v1 -> v2 upgrade provably sends no
		 * statement at that table at all. A clean installation, where
		 * verification fails because the table is absent, still creates it.
		 */
		if ( true !== self::verify_state_schema() ) {
			$state_table = self::state_table();

			try {
				dbDelta(
					"CREATE TABLE {$state_table} (
	blog_id bigint(20) unsigned NOT NULL,
	seq bigint(20) unsigned NOT NULL,
	state varchar(16) NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (blog_id)
) ENGINE=InnoDB {$charset_collate};"
				);
			} catch ( \Throwable $throwable ) {
				// Never surfaced: dbDelta failures can quote SQL and server text.
				unset( $throwable );

				return self::error( 'The Commerce state schema could not be applied.' );
			}
		}

		if ( true !== self::verify_operations_schema() ) {
			$operations_table = self::operations_table();

			try {
				dbDelta( self::operations_ddl( $operations_table, $charset_collate ) );
			} catch ( \Throwable $throwable ) {
				unset( $throwable );

				return self::error( 'The provisioning operations schema could not be applied.' );
			}
		}

		$verified = self::verify_schema();
		if ( $verified instanceof \WP_Error ) {
			return $verified;
		}

		/*
		 * Recording and confirming the version are both database work and both
		 * can throw. A failure here must never leave the caller believing the
		 * schema is registered when it is not.
		 */
		try {
			update_site_option( self::VERSION_OPTION, self::DB_VERSION );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'The Commerce state schema version could not be recorded.' );
		}

		$recorded = self::read_version();
		if ( $recorded instanceof \WP_Error || $recorded < self::DB_VERSION ) {
			return self::error( 'The Commerce state schema version could not be recorded.' );
		}

		return true;
	}

	/**
	 * Structural verification of the whole v2 schema.
	 *
	 * Both tables must verify. Callers that need only one of them use the
	 * single-table methods below; the migration uses those to decide which DDL
	 * to issue, and this one to decide whether the version may advance.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_schema() {
		$state = self::verify_state_schema();
		if ( $state instanceof \WP_Error ) {
			return $state;
		}

		return self::verify_operations_schema();
	}

	/**
	 * Structural verification: existence, columns, key, engine and charset.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_state_schema() {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		try {
			return self::inspect_schema( $wpdb );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'The Commerce state schema could not be inspected.' );
		} finally {
			// Restored on every path, including a throw, so a failed inspection
			// cannot leave error reporting altered for the rest of the request.
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Structural verification of the ADR-042 provisioning operations table.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_operations_schema() {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		try {
			return self::inspect_operations_schema( $wpdb );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'The provisioning operations schema could not be inspected.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/** @return true|\WP_Error */
	private static function inspect_schema( \wpdb $wpdb ) {
		$table = self::state_table();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::error( 'The Commerce state table could not be inspected.' );
		}
		if ( $found !== $table ) {
			return self::error( 'The Commerce state table is missing.' );
		}

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $columns ) || '' !== (string) $wpdb->last_error ) {
			return self::error( 'The Commerce state columns could not be inspected.' );
		}

		$by_name = [];
		foreach ( $columns as $column ) {
			$by_name[ $column['Field'] ] = $column;
		}

		$expected = [
			'blog_id'    => '/^bigint(?:\(20\))? unsigned$/',
			'seq'        => '/^bigint(?:\(20\))? unsigned$/',
			'state'      => '/^varchar\(16\)$/',
			'updated_at' => '/^datetime$/',
		];
		if ( count( $by_name ) !== count( $expected ) ) {
			return self::error( 'The Commerce state table has unexpected columns.' );
		}
		foreach ( $expected as $name => $type ) {
			$actual = $by_name[ $name ] ?? null;
			if ( ! is_array( $actual )
				|| 1 !== preg_match( $type, strtolower( (string) $actual['Type'] ) )
				|| 'NO' !== ( $actual['Null'] ?? null )
				|| null !== ( $actual['Default'] ?? null ) ) {
				return self::error( sprintf( 'The Commerce state %s column is missing or incompatible.', $name ) );
			}
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $indexes ) || '' !== (string) $wpdb->last_error ) {
			return self::error( 'The Commerce state indexes could not be inspected.' );
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

		$expected_indexes = [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'blog_id' ] ] ];
		if ( $actual_indexes !== $expected_indexes ) {
			return self::error( 'The Commerce state primary key is missing or incompatible.' );
		}

		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! is_array( $status )
			|| 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) )
			|| 0 !== strpos( strtolower( (string) ( $status['Collation'] ?? '' ) ), 'utf8mb4_' ) ) {
			return self::error( 'The Commerce state table engine or charset is incompatible.' );
		}

		return true;
	}

	/**
	 * The exact ADR-042 operations schema.
	 *
	 * `ascii_bin` on the identity columns is a correctness requirement, not a
	 * storage preference: UUIDs, fingerprints, DNS slugs and the closed
	 * `desired_state` / `last_error_category` vocabularies must compare
	 * byte-for-byte. Under a case-insensitive collation `A3F` and `a3f` would be
	 * the same UUID, `UNIQUE(site_slug)` would exclude addresses that are
	 * actually distinct, and an uppercase fingerprint would satisfy an exact
	 * comparison it must fail.
	 *
	 * `request_hall_name` deliberately keeps the table's utf8mb4 default: it
	 * holds a 255-Unicode-character display attribute, never an identity.
	 */
	private static function operations_ddl( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
	portal_hall_id bigint(20) unsigned NOT NULL,
	blog_id bigint(20) unsigned NULL,
	site_ownership_id char(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
	site_slug varchar(63) CHARACTER SET ascii COLLATE ascii_bin NULL,
	request_site_slug varchar(63) CHARACTER SET ascii COLLATE ascii_bin NULL,
	request_hall_name varchar(255) NULL,
	portal_actor_id bigint(20) unsigned NOT NULL,
	operation_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	intent_sequence bigint(20) unsigned NOT NULL,
	enforced_sequence bigint(20) unsigned NOT NULL DEFAULT 0,
	desired_state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	request_canonical_version tinyint(3) unsigned NOT NULL,
	request_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	last_error_category varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (portal_hall_id),
	UNIQUE KEY operation_id (operation_id),
	UNIQUE KEY site_ownership_id (site_ownership_id),
	UNIQUE KEY blog_id (blog_id),
	UNIQUE KEY site_slug (site_slug)
) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * Every column type, nullability, default, collation and index is checked.
	 *
	 * Nullability is verified in BOTH directions. A `site_ownership_id` that had
	 * been created NOT NULL would make the pre-ownership lifecycle impossible to
	 * represent, and a `request_fingerprint` that had been created NULL would let
	 * an unfingerprinted row exist — so neither is inferred from the other.
	 *
	 * @return true|\WP_Error
	 */
	private static function inspect_operations_schema( \wpdb $wpdb ) {
		$table = self::operations_table();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::error( 'The provisioning operations table could not be inspected.' );
		}
		if ( $found !== $table ) {
			return self::error( 'The provisioning operations table is missing.' );
		}

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $columns ) || '' !== (string) $wpdb->last_error ) {
			return self::error( 'The provisioning operations columns could not be inspected.' );
		}

		$by_name = [];
		foreach ( $columns as $column ) {
			$by_name[ (string) $column['Field'] ] = $column;
		}

		// [ type pattern, nullable, default, collation prefix or null for the
		// table default ].
		$expected = [
			'portal_hall_id'            => [ '/^bigint(?:\(20\))? unsigned$/', false, null, null ],
			'blog_id'                   => [ '/^bigint(?:\(20\))? unsigned$/', true, null, null ],
			'site_ownership_id'         => [ '/^char\(36\)$/', true, null, 'ascii_bin' ],
			'site_slug'                 => [ '/^varchar\(63\)$/', true, null, 'ascii_bin' ],
			'request_site_slug'         => [ '/^varchar\(63\)$/', true, null, 'ascii_bin' ],
			'request_hall_name'         => [ '/^varchar\(255\)$/', true, null, 'utf8mb4_' ],
			'portal_actor_id'           => [ '/^bigint(?:\(20\))? unsigned$/', false, null, null ],
			'operation_id'              => [ '/^char\(36\)$/', false, null, 'ascii_bin' ],
			'intent_sequence'           => [ '/^bigint(?:\(20\))? unsigned$/', false, null, null ],
			'enforced_sequence'         => [ '/^bigint(?:\(20\))? unsigned$/', false, '0', null ],
			'desired_state'             => [ '/^varchar\(16\)$/', false, null, 'ascii_bin' ],
			'request_canonical_version' => [ '/^tinyint(?:\(\d+\))? unsigned$/', false, null, null ],
			'request_fingerprint'       => [ '/^char\(64\)$/', false, null, 'ascii_bin' ],
			'last_error_category'       => [ '/^varchar\(64\)$/', true, null, 'ascii_bin' ],
			'created_at'                => [ '/^datetime$/', false, null, null ],
			'updated_at'                => [ '/^datetime$/', false, null, null ],
		];

		if ( count( $by_name ) !== count( $expected ) ) {
			return self::error( 'The provisioning operations table has unexpected columns.' );
		}

		foreach ( $expected as $name => [ $type, $nullable, $default, $collation ] ) {
			$actual = $by_name[ $name ] ?? null;
			if ( ! is_array( $actual )
				|| 1 !== preg_match( $type, strtolower( (string) $actual['Type'] ) )
				|| ( $nullable ? 'YES' : 'NO' ) !== ( $actual['Null'] ?? null )
				|| $default !== ( $actual['Default'] ?? null ) ) {
				return self::error( sprintf( 'The provisioning operations %s column is missing or incompatible.', $name ) );
			}

			if ( null !== $collation
				&& 0 !== strpos( strtolower( (string) ( $actual['Collation'] ?? '' ) ), $collation ) ) {
				return self::error( sprintf( 'The provisioning operations %s column collation is incompatible.', $name ) );
			}
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $indexes ) || '' !== (string) $wpdb->last_error ) {
			return self::error( 'The provisioning operations indexes could not be inspected.' );
		}

		$actual_indexes = [];
		foreach ( $indexes as $index ) {
			$key                              = (string) $index['Key_name'];
			$actual_indexes[ $key ]['unique'] = 0 === (int) $index['Non_unique'];
			$actual_indexes[ $key ]['columns'][ (int) $index['Seq_in_index'] ] = (string) $index['Column_name'];
		}
		foreach ( $actual_indexes as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );

		$expected_indexes = [
			'PRIMARY'           => [ 'unique' => true, 'columns' => [ 'portal_hall_id' ] ],
			'operation_id'      => [ 'unique' => true, 'columns' => [ 'operation_id' ] ],
			'site_ownership_id' => [ 'unique' => true, 'columns' => [ 'site_ownership_id' ] ],
			'blog_id'           => [ 'unique' => true, 'columns' => [ 'blog_id' ] ],
			'site_slug'         => [ 'unique' => true, 'columns' => [ 'site_slug' ] ],
		];

		ksort( $actual_indexes );
		ksort( $expected_indexes );
		if ( $actual_indexes !== $expected_indexes ) {
			return self::error( 'The provisioning operations indexes are missing or incompatible.' );
		}

		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! is_array( $status )
			|| 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) )
			|| 0 !== strpos( strtolower( (string) ( $status['Collation'] ?? '' ) ), 'utf8mb4_' ) ) {
			return self::error( 'The provisioning operations table engine or charset is incompatible.' );
		}

		return true;
	}

	private static function error( string $message ): \WP_Error {
		return new \WP_Error(
			'provisioning_migration_verification_failed',
			__( $message, 'bingocommerce-portal-integration' )
		);
	}
}
