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

	public const DB_VERSION = 2;
	public const VERSION_OPTION = 'bc_sessions_db_version';
	public const NETWORK_SWEEP_OPTION = 'bc_sessions_network_migration_sweep_version';
	public const SESSIONS_TABLE = 'bingo_sessions';
	public const SESSION_PACKAGES_TABLE = 'bingo_session_packages';
	public const PORTAL_SESSION_TEMPLATES_TABLE = 'bingo_portal_session_templates';

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
	PRIMARY KEY  (session_id),
	UNIQUE KEY session_date_display_name (session_date,display_name),
	KEY status_sale_date (status,available_for_sale,session_date),
	KEY session_date (session_date)
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

		return self::verify_portal_session_templates_schema();
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
				'status_sale_date'         => [ false, [ 'status', 'available_for_sale', 'session_date' ] ],
				'session_date'             => [ false, [ 'session_date' ] ],
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

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'               => [ true, [ 'session_template_id' ] ],
				'portal_session_unique' => [ true, [ 'portal_session_id' ] ],
			],
			'Portal Session Template replica'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Portal Session Template replica' );
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
		foreach ( $expected as $name => [ $type, $nullable, $default, $extra ] ) {
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
