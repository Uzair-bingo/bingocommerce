<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the network-wide Commerce-state schema.
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
 */
final class ProvisioningMigrations {

	public const DB_VERSION     = 1;
	public const VERSION_OPTION = 'bc_portal_provisioning_db_version';
	public const STATE_TABLE    = 'bingo_hall_commerce_state';

	/** Network-wide table, keyed by the tenant's blog_id (AGENTS rule 6). */
	public static function state_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::STATE_TABLE;
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
		$table           = self::state_table();
		$charset_collate = $wpdb->get_charset_collate();

		try {
			dbDelta(
				"CREATE TABLE {$table} (
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
	 * Structural verification: existence, columns, key, engine and charset.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_schema() {
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

	private static function error( string $message ): \WP_Error {
		return new \WP_Error(
			'provisioning_migration_verification_failed',
			__( $message, 'bingocommerce-portal-integration' )
		);
	}
}
