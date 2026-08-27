<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the hall-local Catalog schema and its per-site version state. */
final class Migrations {

	public const DB_VERSION = 2;
	public const VERSION_OPTION = 'bc_catalog_db_version';
	public const NETWORK_SWEEP_OPTION = 'bc_catalog_network_migration_sweep_version';
	public const GAME_TYPES_TABLE = 'bingo_game_types';
	public const PLAY_PRODUCTS_TABLE = 'bingo_play_products';

	/**
	 * ADR-031 reserves this table for the future PackageService. This increment
	 * never creates it; the name exists only so Play Product creation can probe
	 * for a competing authoritative Package claim (ADR-032).
	 */
	public const PACKAGES_TABLE = 'bingo_packages';

	/** @return true|\WP_Error */
	public static function maybe_migrate_current_site() {
		if ( ! HallContext::is_hall() || self::is_current() ) {
			return true;
		}

		$result = self::migrate_current_site();
		if ( is_wp_error( $result ) ) {
			do_action( 'bingocommerce_catalog_migration_failed', $result, get_current_blog_id() );
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
				'catalog_network_migration_incomplete',
				__( 'One or more hall Catalog schemas could not be migrated.', 'bingocommerce-catalog' ),
				[ 'failed_sites' => $failed ]
			);
		}

		update_site_option( self::NETWORK_SWEEP_OPTION, self::DB_VERSION );
		if ( self::persisted_network_sweep_version() < self::DB_VERSION ) {
			return new \WP_Error(
				'catalog_network_sweep_version_not_persisted',
				__( 'The Catalog migration sweep version could not be recorded.', 'bingocommerce-catalog' )
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
				do_action( 'bingocommerce_catalog_migration_failed', $result, (int) $new_site->blog_id );
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
				__( 'Catalog migrations may run only for a hall site.', 'bingocommerce-catalog' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$game_types = self::game_types_table();
		dbDelta(
			"CREATE TABLE {$game_types} (
	game_type_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	code varchar(64) NOT NULL,
	name varchar(160) NOT NULL,
	status varchar(16) NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	archived_at datetime DEFAULT NULL,
	PRIMARY KEY  (game_type_id),
	UNIQUE KEY code_unique (code),
	UNIQUE KEY name_unique (name),
	KEY status_name (status,name)
) ENGINE=InnoDB {$charset_collate};"
		);

		$play_products = self::play_products_table();
		dbDelta(
			"CREATE TABLE {$play_products} (
	bingo_play_product_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	wc_product_id bigint(20) unsigned NOT NULL,
	medium varchar(16) NOT NULL,
	faces_per_unit int(10) unsigned NOT NULL,
	game_type_id bigint(20) unsigned DEFAULT NULL,
	status varchar(16) NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	archived_at datetime DEFAULT NULL,
	PRIMARY KEY  (bingo_play_product_id),
	UNIQUE KEY wc_product_unique (wc_product_id),
	KEY game_type (game_type_id),
	KEY medium_status (medium,status),
	KEY status_wc_product (status,wc_product_id)
) ENGINE=InnoDB {$charset_collate};"
		);

		// The version is the hall's promise that BOTH Catalog schemas are usable,
		// so it is persisted only after every required structure verifies.
		$verified = self::verify_current_site_schema();
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		if ( self::persisted_site_version() < self::DB_VERSION ) {
			return new \WP_Error(
				'catalog_migration_version_not_persisted',
				__( 'The Catalog schema version could not be recorded.', 'bingocommerce-catalog' )
			);
		}

		return true;
	}

	public static function is_current(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) >= self::DB_VERSION;
	}

	public static function game_types_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::GAME_TYPES_TABLE;
	}

	public static function play_products_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::PLAY_PRODUCTS_TABLE;
	}

	public static function packages_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::PACKAGES_TABLE;
	}

	/** @return true|\WP_Error */
	public static function verify_current_site_schema() {
		$game_types = self::verify_game_types_schema();
		if ( is_wp_error( $game_types ) ) {
			return $game_types;
		}

		return self::verify_play_products_schema();
	}

	/** @return true|\WP_Error */
	public static function verify_game_types_schema() {
		global $wpdb;
		$table = self::game_types_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Game Type table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Game Type columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'game_type_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'code'         => [ '/^varchar\(64\)$/', 'NO', null, '' ],
				'name'         => [ '/^varchar\(160\)$/', 'NO', null, '' ],
				'status'       => [ '/^varchar\(16\)$/', 'NO', 'active', '' ],
				'created_at'   => [ '/^datetime$/', 'NO', null, '' ],
				'updated_at'   => [ '/^datetime$/', 'NO', null, '' ],
				'archived_at'  => [ '/^datetime$/', 'YES', null, '' ],
			],
			'Game Type'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'     => [ true, [ 'game_type_id' ] ],
				'code_unique' => [ true, [ 'code' ] ],
				'name_unique' => [ true, [ 'name' ] ],
				'status_name' => [ false, [ 'status', 'name' ] ],
			],
			'Game Type'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		$name_collation = (string) ( $by_name['name']['Collation'] ?? '' );
		if ( ! preg_match( '/^[a-z0-9_]+$/i', $name_collation ) ) {
			return self::verification_error( 'The Game Type name collation is missing or unsafe.' );
		}

		$case_equal = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CONVERT(%s USING utf8mb4) COLLATE {$name_collation} = CONVERT(%s USING utf8mb4) COLLATE {$name_collation}",
				'Regular',
				'regular'
			)
		);
		$accent_equal = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CONVERT(%s USING utf8mb4) COLLATE {$name_collation} = CONVERT(%s USING utf8mb4) COLLATE {$name_collation}",
				'Cafe',
				'Café'
			)
		);
		if ( '1' !== (string) $case_equal || '1' !== (string) $accent_equal || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( 'The Game Type name collation is not case- and accent-insensitive.' );
		}

		return self::assert_engine_and_charset( $table, 'Game Type' );
	}

	/** @return true|\WP_Error */
	public static function verify_play_products_schema() {
		$table = self::play_products_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Bingo Play Product table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Bingo Play Product columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'bingo_play_product_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'wc_product_id'         => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'medium'                => [ '/^varchar\(16\)$/', 'NO', null, '' ],
				'faces_per_unit'        => [ '/^int(?:\(10\))? unsigned$/', 'NO', null, '' ],
				'game_type_id'          => [ '/^bigint(?:\(20\))? unsigned$/', 'YES', null, '' ],
				'status'                => [ '/^varchar\(16\)$/', 'NO', 'active', '' ],
				'created_at'            => [ '/^datetime$/', 'NO', null, '' ],
				'updated_at'            => [ '/^datetime$/', 'NO', null, '' ],
				'archived_at'           => [ '/^datetime$/', 'YES', null, '' ],
			],
			'Bingo Play Product'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'           => [ true, [ 'bingo_play_product_id' ] ],
				'wc_product_unique' => [ true, [ 'wc_product_id' ] ],
				'game_type'         => [ false, [ 'game_type_id' ] ],
				'medium_status'     => [ false, [ 'medium', 'status' ] ],
				'status_wc_product' => [ false, [ 'status', 'wc_product_id' ] ],
			],
			'Bingo Play Product'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Bingo Play Product' );
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
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::VERSION_OPTION
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	private static function persisted_network_sweep_version(): int {
		global $wpdb;

		if ( is_multisite() ) {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s",
					get_current_network_id(),
					self::NETWORK_SWEEP_OPTION
				)
			);
		} else {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					self::NETWORK_SWEEP_OPTION
				)
			);
		}

		return null === $value ? 0 : (int) $value;
	}

	private static function verification_error( string $message ): \WP_Error {
		return new \WP_Error(
			'catalog_migration_verification_failed',
			__( $message, 'bingocommerce-catalog' )
		);
	}
}
