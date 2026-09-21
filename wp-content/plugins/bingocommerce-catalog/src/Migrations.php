<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the hall-local Catalog schema and its per-site version state. */
final class Migrations {

	public const DB_VERSION = 5;
	public const VERSION_OPTION = 'bc_catalog_db_version';
	public const NETWORK_SWEEP_OPTION = 'bc_catalog_network_migration_sweep_version';
	public const GAME_TYPES_TABLE = 'bingo_game_types';
	public const PLAY_PRODUCTS_TABLE = 'bingo_play_products';
	private const NAME_KEY_MISMATCH = '(portal_game_type_id IS NULL AND NOT (CAST(local_name_key AS BINARY) <=> CAST(name AS BINARY)))'
		. ' OR (portal_game_type_id IS NOT NULL AND local_name_key IS NOT NULL)';

	/**
	 * ADR-031 Package identity, created by Catalog v3. Play Product creation
	 * probes it for a competing authoritative Package claim (ADR-032), and the
	 * ADR-033 composition boundary locks its rows.
	 */
	public const PACKAGES_TABLE = 'bingo_packages';

	/** ADR-031 Package base composition, created by Catalog v3. */
	public const PACKAGE_COMPONENTS_TABLE = 'bingo_package_components';

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
		if ( $game_types !== $wpdb->get_blog_prefix( get_current_blog_id() ) . 'bingo_game_types' || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $game_types ) ) {
			return self::verification_error( 'The Game Type migration table does not match the current hall.' );
		}
		$local_name_collation = '';
		if ( self::table_exists( $game_types ) ) {
			$columns = self::columns_by_name( $game_types );
			$collation = (string) ( $columns['name']['Collation'] ?? '' );
			if ( 1 !== preg_match( '/^utf8mb4_[a-z0-9_]+$/iD', $collation ) ) {
				return self::verification_error( 'The existing Game Type name collation is incompatible.' );
			}
			// Preserve the column's collation even when it differs from the table default.
			$local_name_collation = " CHARACTER SET utf8mb4 COLLATE {$collation}";
		}
		dbDelta(
			"CREATE TABLE {$game_types} (
	game_type_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	portal_game_type_id bigint(20) unsigned DEFAULT NULL,
	code varchar(64) NOT NULL,
	name varchar(160) NOT NULL,
	local_name_key varchar(160){$local_name_collation} DEFAULT NULL,
	status varchar(16) NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	archived_at datetime DEFAULT NULL,
	PRIMARY KEY  (game_type_id),
	UNIQUE KEY portal_game_type_unique (portal_game_type_id),
	UNIQUE KEY code_unique (code),
	UNIQUE KEY local_name_unique (local_name_key),
	KEY name_id (name,game_type_id),
	KEY status_name (status,name)
) ENGINE=InnoDB {$charset_collate};"
		);

		$names = self::migrate_game_type_names();
		if ( is_wp_error( $names ) ) {
			return $names;
		}

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

		$packages = self::packages_table();
		dbDelta(
			"CREATE TABLE {$packages} (
	package_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	wc_product_id bigint(20) unsigned NOT NULL,
	package_type varchar(16) NOT NULL,
	status varchar(16) NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	archived_at datetime DEFAULT NULL,
	PRIMARY KEY  (package_id),
	UNIQUE KEY wc_product_unique (wc_product_id),
	KEY status_package_type (status,package_type)
) ENGINE=InnoDB {$charset_collate};"
		);

		$package_components = self::package_components_table();
		dbDelta(
			"CREATE TABLE {$package_components} (
	package_component_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	package_id bigint(20) unsigned NOT NULL,
	bingo_play_product_id bigint(20) unsigned NOT NULL,
	quantity int(10) unsigned NOT NULL,
	sort_order int(10) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (package_component_id),
	UNIQUE KEY package_play_product_unique (package_id,bingo_play_product_id),
	KEY package_sort (package_id,sort_order),
	KEY play_product (bingo_play_product_id)
) ENGINE=InnoDB {$charset_collate};"
		);

		// The version is the hall's promise that EVERY Catalog schema through v5
		// is usable, so it is persisted only after every required structure
		// verifies.
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

	public static function package_components_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::PACKAGE_COMPONENTS_TABLE;
	}

	/** @return true|\WP_Error */
	public static function verify_current_site_schema() {
		$game_types = self::verify_game_types_schema();
		if ( is_wp_error( $game_types ) ) {
			return $game_types;
		}

		$play_products = self::verify_play_products_schema();
		if ( is_wp_error( $play_products ) ) {
			return $play_products;
		}

		$packages = self::verify_packages_schema();
		if ( is_wp_error( $packages ) ) {
			return $packages;
		}

		return self::verify_package_components_schema();
	}

	/** @return true|\WP_Error */
	public static function verify_game_types_schema() {
		$structure = self::verify_game_types_structure();
		if ( is_wp_error( $structure ) ) {
			return $structure;
		}
		$legacy_index = self::legacy_game_type_name_index();
		if ( is_wp_error( $legacy_index ) ) {
			return $legacy_index;
		}
		if ( [] !== $legacy_index ) {
			return self::verification_error( 'The obsolete Game Type name_unique index remains.' );
		}

		return self::verify_local_name_keys();
	}

	/** Verify replacement structure before touching data or the old index. @return true|\WP_Error */
	private static function verify_game_types_structure() {
		$structure = self::verify_game_types_structure_only();
		if ( is_wp_error( $structure ) ) {
			return $structure;
		}

		return self::assert_engine_and_charset( self::game_types_table(), 'Game Type' );
	}

	/**
	 * Columns, indexes and name-collation behaviour ONLY — no storage-engine or
	 * charset assertion.
	 *
	 * Split out for ADR-043 §11.3's Portal-sync readiness gate, which runs BEFORE
	 * the snapshot fetch. `PortalSnapshotTransactionTransport` already asserts
	 * InnoDB for this exact table through `REQUIRED_INNODB_TABLES`
	 * (`…:101-107`), after the fetch and before `BEGIN`, reporting
	 * `portal_sync_storage_engine_unsupported`. The readiness gate must not
	 * pre-empt that: doing so would move the engine check ahead of the fetch and
	 * replace a specific error code with a vaguer one.
	 *
	 * Everything that makes the replica STRUCTURALLY usable is still proved here,
	 * including the case- and accent-insensitive name collation the transport's
	 * conflict handling depends on. The full verifier still asserts the engine,
	 * because migration does need to prove it.
	 *
	 * @return true|\WP_Error
	 */
	private static function verify_game_types_structure_only() {
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
				'game_type_id'        => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'portal_game_type_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'YES', null, '' ],
				'code'                => [ '/^varchar\(64\)$/', 'NO', null, '' ],
				'name'                => [ '/^varchar\(160\)$/', 'NO', null, '' ],
				'local_name_key'      => [ '/^varchar\(160\)$/', 'YES', null, '' ],
				'status'              => [ '/^varchar\(16\)$/', 'NO', 'active', '' ],
				'created_at'          => [ '/^datetime$/', 'NO', null, '' ],
				'updated_at'          => [ '/^datetime$/', 'NO', null, '' ],
				'archived_at'         => [ '/^datetime$/', 'YES', null, '' ],
			],
			'Game Type'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'                 => [ true, [ 'game_type_id' ] ],
				'portal_game_type_unique' => [ true, [ 'portal_game_type_id' ] ],
				'code_unique'             => [ true, [ 'code' ] ],
				'local_name_unique'       => [ true, [ 'local_name_key' ] ],
				'name_id'                 => [ false, [ 'name', 'game_type_id' ] ],
				'status_name'             => [ false, [ 'status', 'name' ] ],
			],
			'Game Type'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		$name_collation = (string) ( $by_name['name']['Collation'] ?? '' );
		if ( 1 !== preg_match( '/^utf8mb4_[a-z0-9_]+$/iD', $name_collation ) || $name_collation !== ( $by_name['local_name_key']['Collation'] ?? null ) ) {
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

		return true;
	}

	/**
	 * The Game Type replica's STRUCTURAL readiness, for ADR-043 §11.3.
	 *
	 * Everything `verify_game_types_schema()` proves except the storage engine:
	 * structure, the obsolete `name_unique` index being gone, and every stored
	 * `local_name_key` matching its row identity — all three are properties the
	 * snapshot transport's conflict handling relies on.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_game_types_structure_for_sync() {
		$structure = self::verify_game_types_structure_only();
		if ( is_wp_error( $structure ) ) {
			return $structure;
		}

		$legacy_index = self::legacy_game_type_name_index();
		if ( is_wp_error( $legacy_index ) ) {
			return $legacy_index;
		}
		if ( [] !== $legacy_index ) {
			return self::verification_error( 'The obsolete Game Type name_unique index remains.' );
		}

		return self::verify_local_name_keys();
	}

	/** ADR-039's sole allowlisted structural exception. @return true|\WP_Error */
	private static function migrate_game_type_names() {
		global $wpdb;
		$structure = self::verify_game_types_structure();
		if ( is_wp_error( $structure ) ) {
			return $structure;
		}
		$legacy_index = self::legacy_game_type_name_index();
		if ( is_wp_error( $legacy_index ) ) {
			return $legacy_index;
		}
		$table = self::game_types_table();
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET local_name_key = CASE WHEN portal_game_type_id IS NULL THEN name ELSE NULL END WHERE ' . self::NAME_KEY_MISMATCH,
			$table
		) );
		if ( false === $updated ) {
			return self::verification_error( 'The Game Type local-name backfill failed.' );
		}
		$keys = self::verify_local_name_keys();
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		// Recheck the exact target immediately before the one permitted DROP.
		$legacy_index = self::legacy_game_type_name_index();
		if ( is_wp_error( $legacy_index ) ) {
			return $legacy_index;
		}
		if ( $table !== self::game_types_table() ) {
			return self::verification_error( 'The Game Type migration hall changed.' );
		}
		if ( [] !== $legacy_index && false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX `name_unique`', $table ) ) ) {
			return self::verification_error( 'The obsolete Game Type name index could not be removed.' );
		}

		return self::verify_game_types_schema();
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private static function legacy_game_type_name_index() {
		global $wpdb;
		$table = self::game_types_table();
		if ( ! HallContext::is_hall() || $table !== $wpdb->get_blog_prefix( get_current_blog_id() ) . 'bingo_game_types' || ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Game Type index target is not the current hall table.' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'name_unique' ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( 'The Game Type name index could not be inspected.' );
		}
		if ( [] === $rows ) {
			return [];
		}
		$index = $rows[0];
		if (
			1 !== count( $rows ) || $table !== $index['Table']
			|| 'name_unique' !== $index['Key_name'] || 0 !== (int) $index['Non_unique']
			|| 1 !== (int) $index['Seq_in_index'] || 'name' !== $index['Column_name']
			|| null !== $index['Sub_part'] || 'BTREE' !== $index['Index_type']
			|| 'A' !== $index['Collation'] || null !== ( $index['Expression'] ?? null )
		) {
			return self::verification_error( 'The name_unique index is not the exact allowlisted legacy index.' );
		}

		return $rows;
	}

	/** @return true|\WP_Error */
	private static function verify_local_name_keys() {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . self::NAME_KEY_MISMATCH, self::game_types_table() ) );
		if ( '0' !== (string) $count || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( 'The Game Type local-name keys do not match their row identities.' );
		}

		return true;
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

	/** @return true|\WP_Error */
	public static function verify_packages_schema() {
		$table = self::packages_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Bingo Package table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Bingo Package columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'package_id'    => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'wc_product_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'package_type'  => [ '/^varchar\(16\)$/', 'NO', null, '' ],
				'status'        => [ '/^varchar\(16\)$/', 'NO', 'active', '' ],
				'created_at'    => [ '/^datetime$/', 'NO', null, '' ],
				'updated_at'    => [ '/^datetime$/', 'NO', null, '' ],
				'archived_at'   => [ '/^datetime$/', 'YES', null, '' ],
			],
			'Bingo Package'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		// assert_indexes() compares the EXACT ordered column list, so a composite
		// UNIQUE such as (wc_product_id,status) cannot satisfy the single-column
		// wc_product_unique requirement ADR-031 depends on for one-Package-per-
		// product. That is deliberate: a composite key would only constrain the
		// combination and would not prove the invariant.
		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'             => [ true, [ 'package_id' ] ],
				'wc_product_unique'   => [ true, [ 'wc_product_id' ] ],
				'status_package_type' => [ false, [ 'status', 'package_type' ] ],
			],
			'Bingo Package'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Bingo Package' );
	}

	/** @return true|\WP_Error */
	public static function verify_package_components_schema() {
		$table = self::package_components_table();

		if ( ! self::table_exists( $table ) ) {
			return self::verification_error( 'The Bingo Package component table is missing.' );
		}

		$by_name = self::columns_by_name( $table );
		if ( ! is_array( $by_name ) ) {
			return self::verification_error( 'The Bingo Package component columns could not be inspected.' );
		}

		$columns = self::assert_columns(
			$by_name,
			[
				'package_component_id'  => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment' ],
				'package_id'            => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'bingo_play_product_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
				'quantity'              => [ '/^int(?:\(10\))? unsigned$/', 'NO', null, '' ],
				'sort_order'            => [ '/^int(?:\(10\))? unsigned$/', 'NO', '0', '' ],
				'created_at'            => [ '/^datetime$/', 'NO', null, '' ],
				'updated_at'            => [ '/^datetime$/', 'NO', null, '' ],
			],
			'Bingo Package component'
		);
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$indexes = self::assert_indexes(
			$table,
			[
				'PRIMARY'                     => [ true, [ 'package_component_id' ] ],
				'package_play_product_unique' => [ true, [ 'package_id', 'bingo_play_product_id' ] ],
				'package_sort'                => [ false, [ 'package_id', 'sort_order' ] ],
				// ADR-034's Package-reference check is index-backed through this key.
				'play_product'                => [ false, [ 'bingo_play_product_id' ] ],
			],
			'Bingo Package component'
		);
		if ( is_wp_error( $indexes ) ) {
			return $indexes;
		}

		return self::assert_engine_and_charset( $table, 'Bingo Package component' );
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
			$actual_indexes[ $key ]['full_length'] = ( $actual_indexes[ $key ]['full_length'] ?? true ) && null === $index['Sub_part'];
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
				|| ! $actual_indexes[ $name ]['full_length']
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
