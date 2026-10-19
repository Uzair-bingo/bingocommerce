<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 starter catalog and starter import storage.
 *
 * DELIBERATELY SEPARATE FROM Migrations (Catalog schema v6). This class has its
 * own version options and its own verification, and it registers no hook. It
 * runs only when a caller asks for it: the Starter Catalog admin page for the
 * network tables, and the Hall's Starter Products page and import requests for
 * the Hall tables. It never runs during site creation, Enable or readiness, so
 * a failure here can affect importing only (ADR-049 section 14, the narrow
 * exception recorded in plugin-architecture.md section 3).
 *
 * NO SILENT RESET. Once a version option says the schema is current, a missing
 * or malformed table is reported for repair and is never recreated, because
 * recreating it would quietly erase a Hall's import history.
 *
 * Network tables hold platform data (published versions), not Hall data, so
 * they carry no blog_id. Hall tables use the current Hall's own prefix and
 * carry no redundant blog_id (AGENTS.md rule 6).
 */
final class StarterImportMigrations {

	public const NETWORK_DB_VERSION     = 1;
	public const NETWORK_VERSION_OPTION = 'bc_starter_catalog_network_db_version';
	public const HALL_DB_VERSION        = 1;
	public const HALL_VERSION_OPTION    = 'bc_starter_import_db_version';

	public const VERSIONS_TABLE         = 'bingo_starter_catalog_versions';
	public const VERSION_PRODUCTS_TABLE = 'bingo_starter_catalog_version_products';
	public const VERSION_IMAGES_TABLE   = 'bingo_starter_catalog_version_images';
	public const IMPORT_TABLE           = 'bingo_starter_import';
	public const IMPORT_PRODUCTS_TABLE  = 'bingo_starter_import_products';
	public const IMPORT_IMAGES_TABLE    = 'bingo_starter_import_images';

	/**
	 * Columns each table must have, and its unique keys with their exact
	 * column order. Verification checks names and uniqueness, the parts the
	 * importer's duplicate protection depends on.
	 */
	private const NETWORK_SHAPE = [
		self::VERSIONS_TABLE         => [
			'columns' => [ 'version_id', 'status', 'product_count', 'image_count', 'currency', 'prices_include_tax', 'terms', 'created_by', 'published_by', 'created_at', 'updated_at', 'published_at' ],
			'unique'  => [ 'PRIMARY' => [ 'version_id' ] ],
		],
		self::VERSION_PRODUCTS_TABLE => [
			'columns' => [ 'version_product_id', 'version_id', 'source_product_id', 'parent_source_id', 'product_type', 'position', 'capture_status', 'source_modified_gmt', 'data', 'created_at', 'updated_at' ],
			'unique'  => [
				'PRIMARY'        => [ 'version_product_id' ],
				'version_source' => [ 'version_id', 'source_product_id' ],
			],
		],
		self::VERSION_IMAGES_TABLE   => [
			'columns' => [ 'version_image_id', 'version_id', 'source_attachment_id', 'capture_status', 'file_path', 'checksum', 'mime_type', 'width', 'height', 'file_size', 'alt_text', 'created_at', 'updated_at' ],
			'unique'  => [
				'PRIMARY'            => [ 'version_image_id' ],
				'version_attachment' => [ 'version_id', 'source_attachment_id' ],
			],
		],
	];

	private const HALL_SHAPE = [
		self::IMPORT_TABLE          => [
			'columns' => [ 'import_id', 'singleton', 'import_uuid', 'version_id', 'status', 'total_products', 'imported_products', 'skipped_products', 'started_by', 'last_error_code', 'last_error_item_id', 'started_at', 'updated_at', 'completed_at' ],
			'unique'  => [
				'PRIMARY'            => [ 'import_id' ],
				'singleton_unique'   => [ 'singleton' ],
				'import_uuid_unique' => [ 'import_uuid' ],
			],
		],
		self::IMPORT_PRODUCTS_TABLE => [
			'columns' => [ 'item_id', 'import_id', 'version_product_id', 'parent_item_id', 'product_type', 'position', 'status', 'recovery_slug', 'wc_product_id', 'decisions', 'note', 'claimed_at', 'updated_at', 'completed_at' ],
			'unique'  => [
				'PRIMARY'                => [ 'item_id' ],
				'import_version_product' => [ 'import_id', 'version_product_id' ],
				'recovery_slug_unique'   => [ 'recovery_slug' ],
			],
		],
		self::IMPORT_IMAGES_TABLE   => [
			'columns' => [ 'import_image_id', 'import_id', 'version_image_id', 'status', 'planned_path', 'recovery_slug', 'attachment_id', 'note', 'updated_at', 'completed_at' ],
			'unique'  => [
				'PRIMARY'              => [ 'import_image_id' ],
				'import_version_image' => [ 'import_id', 'version_image_id' ],
				'recovery_slug_unique' => [ 'recovery_slug' ],
			],
		],
	];

	/* ------------------------------------------------------------------ *
	 * Table names
	 * ------------------------------------------------------------------ */

	public static function versions_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::VERSIONS_TABLE;
	}

	public static function version_products_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::VERSION_PRODUCTS_TABLE;
	}

	public static function version_images_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::VERSION_IMAGES_TABLE;
	}

	public static function import_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::IMPORT_TABLE;
	}

	public static function import_products_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::IMPORT_PRODUCTS_TABLE;
	}

	public static function import_images_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::IMPORT_IMAGES_TABLE;
	}

	/* ------------------------------------------------------------------ *
	 * Version state
	 * ------------------------------------------------------------------ */

	public static function is_network_current(): bool {
		return is_multisite()
			&& (int) get_site_option( self::NETWORK_VERSION_OPTION, 0 ) >= self::NETWORK_DB_VERSION;
	}

	public static function is_hall_current(): bool {
		return HallContext::is_hall()
			&& (int) get_option( self::HALL_VERSION_OPTION, 0 ) >= self::HALL_DB_VERSION;
	}

	/* ------------------------------------------------------------------ *
	 * Network tables (Starter Catalog admin page)
	 * ------------------------------------------------------------------ */

	/**
	 * Create or upgrade the network tables, or verify them when current.
	 *
	 * The caller authorizes; this method performs no capability check.
	 *
	 * @return true|\WP_Error
	 */
	public static function ensure_network() {
		if ( ! is_multisite() ) {
			return self::error( 'starter_import_invalid_context', 'Starter products require WordPress Multisite.' );
		}

		if ( self::is_network_current() ) {
			return self::verified_or_repair( self::verify_network_schema() );
		}

		$created = self::create_tables( self::network_sql() );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$verified = self::verify_network_schema();
		if ( is_wp_error( $verified ) ) {
			return self::error( 'starter_import_schema_failed', 'The starter catalog storage could not be prepared.' );
		}

		update_site_option( self::NETWORK_VERSION_OPTION, self::NETWORK_DB_VERSION );
		if ( ! self::is_network_current() ) {
			return self::error( 'starter_import_schema_failed', 'The starter catalog storage version could not be saved.' );
		}

		return true;
	}

	/** @return true|\WP_Error */
	public static function verify_network_schema() {
		global $wpdb;

		return self::verify_shape( self::NETWORK_SHAPE, (string) $wpdb->base_prefix );
	}

	/* ------------------------------------------------------------------ *
	 * Hall tables (Starter Products page and import requests)
	 * ------------------------------------------------------------------ */

	/**
	 * Create or upgrade the current Hall's tables, or verify them when current.
	 *
	 * The caller authorizes; this method performs no capability check. It
	 * refuses the main site and any request whose table prefix is not the
	 * current Hall's own prefix.
	 *
	 * @return true|\WP_Error
	 */
	public static function ensure_current_hall() {
		global $wpdb;

		if ( ! HallContext::is_hall() ) {
			return self::error( 'starter_import_invalid_context', 'Starter products can be imported only on a Hall site.' );
		}
		if ( $wpdb->prefix !== $wpdb->get_blog_prefix( get_current_blog_id() ) ) {
			return self::error( 'starter_import_invalid_context', 'The Hall database context could not be confirmed.' );
		}

		if ( (int) get_option( self::HALL_VERSION_OPTION, 0 ) >= self::HALL_DB_VERSION ) {
			return self::verified_or_repair( self::verify_hall_schema() );
		}

		$created = self::create_tables( self::hall_sql() );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$verified = self::verify_hall_schema();
		if ( is_wp_error( $verified ) ) {
			return self::error( 'starter_import_schema_failed', 'The starter import storage could not be prepared.' );
		}

		update_option( self::HALL_VERSION_OPTION, self::HALL_DB_VERSION, false );
		if ( ! self::is_hall_current() ) {
			return self::error( 'starter_import_schema_failed', 'The starter import storage version could not be saved.' );
		}

		return true;
	}

	/** @return true|\WP_Error */
	public static function verify_hall_schema() {
		global $wpdb;

		if ( ! HallContext::is_hall() ) {
			return self::error( 'starter_import_invalid_context', 'Starter products can be imported only on a Hall site.' );
		}

		return self::verify_shape( self::HALL_SHAPE, (string) $wpdb->prefix );
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/** @return array<string,string> Table name => CREATE TABLE. */
	private static function network_sql(): array {
		global $wpdb;

		$charset_collate  = $wpdb->get_charset_collate();
		$versions         = self::versions_table();
		$version_products = self::version_products_table();
		$version_images   = self::version_images_table();

		return [
			$versions         => "CREATE TABLE {$versions} (
	version_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	status varchar(16) NOT NULL DEFAULT 'building',
	product_count int(10) unsigned NOT NULL DEFAULT 0,
	image_count int(10) unsigned NOT NULL DEFAULT 0,
	currency varchar(8) NOT NULL DEFAULT '',
	prices_include_tax varchar(3) NOT NULL DEFAULT '',
	terms longtext DEFAULT NULL,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	published_by bigint(20) unsigned DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	published_at datetime DEFAULT NULL,
	PRIMARY KEY  (version_id),
	KEY status_version (status,version_id)
) {$charset_collate};",
			$version_products => "CREATE TABLE {$version_products} (
	version_product_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	version_id bigint(20) unsigned NOT NULL,
	source_product_id bigint(20) unsigned NOT NULL,
	parent_source_id bigint(20) unsigned DEFAULT NULL,
	product_type varchar(16) NOT NULL,
	position int(10) unsigned NOT NULL DEFAULT 0,
	capture_status varchar(16) NOT NULL DEFAULT 'pending',
	source_modified_gmt datetime DEFAULT NULL,
	data longtext DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (version_product_id),
	UNIQUE KEY version_source (version_id,source_product_id),
	KEY version_position (version_id,position),
	KEY version_parent (version_id,parent_source_id)
) {$charset_collate};",
			$version_images   => "CREATE TABLE {$version_images} (
	version_image_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	version_id bigint(20) unsigned NOT NULL,
	source_attachment_id bigint(20) unsigned NOT NULL,
	capture_status varchar(16) NOT NULL DEFAULT 'pending',
	file_path varchar(255) NOT NULL DEFAULT '',
	checksum char(64) NOT NULL DEFAULT '',
	mime_type varchar(100) NOT NULL DEFAULT '',
	width int(10) unsigned NOT NULL DEFAULT 0,
	height int(10) unsigned NOT NULL DEFAULT 0,
	file_size bigint(20) unsigned NOT NULL DEFAULT 0,
	alt_text text DEFAULT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (version_image_id),
	UNIQUE KEY version_attachment (version_id,source_attachment_id)
) {$charset_collate};",
		];
	}

	/** @return array<string,string> Table name => CREATE TABLE. */
	private static function hall_sql(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$import          = self::import_table();
		$import_products = self::import_products_table();
		$import_images   = self::import_images_table();

		return [
			$import          => "CREATE TABLE {$import} (
	import_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	singleton tinyint(3) unsigned NOT NULL DEFAULT 1,
	import_uuid char(36) NOT NULL,
	version_id bigint(20) unsigned NOT NULL,
	status varchar(24) NOT NULL DEFAULT 'importing',
	total_products int(10) unsigned NOT NULL DEFAULT 0,
	imported_products int(10) unsigned NOT NULL DEFAULT 0,
	skipped_products int(10) unsigned NOT NULL DEFAULT 0,
	started_by bigint(20) unsigned NOT NULL DEFAULT 0,
	last_error_code varchar(64) DEFAULT NULL,
	last_error_item_id bigint(20) unsigned DEFAULT NULL,
	started_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	completed_at datetime DEFAULT NULL,
	PRIMARY KEY  (import_id),
	UNIQUE KEY singleton_unique (singleton),
	UNIQUE KEY import_uuid_unique (import_uuid)
) {$charset_collate};",
			$import_products => "CREATE TABLE {$import_products} (
	item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	import_id bigint(20) unsigned NOT NULL,
	version_product_id bigint(20) unsigned NOT NULL,
	parent_item_id bigint(20) unsigned DEFAULT NULL,
	product_type varchar(16) NOT NULL,
	position int(10) unsigned NOT NULL DEFAULT 0,
	status varchar(24) NOT NULL DEFAULT 'pending',
	recovery_slug varchar(64) DEFAULT NULL,
	wc_product_id bigint(20) unsigned DEFAULT NULL,
	decisions longtext DEFAULT NULL,
	note text DEFAULT NULL,
	claimed_at datetime DEFAULT NULL,
	updated_at datetime NOT NULL,
	completed_at datetime DEFAULT NULL,
	PRIMARY KEY  (item_id),
	UNIQUE KEY import_version_product (import_id,version_product_id),
	UNIQUE KEY recovery_slug_unique (recovery_slug),
	KEY import_status_position (import_id,status,position),
	KEY parent_item (parent_item_id),
	KEY wc_product (wc_product_id)
) {$charset_collate};",
			$import_images   => "CREATE TABLE {$import_images} (
	import_image_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	import_id bigint(20) unsigned NOT NULL,
	version_image_id bigint(20) unsigned NOT NULL,
	status varchar(24) NOT NULL DEFAULT 'pending',
	planned_path varchar(255) DEFAULT NULL,
	recovery_slug varchar(64) DEFAULT NULL,
	attachment_id bigint(20) unsigned DEFAULT NULL,
	note text DEFAULT NULL,
	updated_at datetime NOT NULL,
	completed_at datetime DEFAULT NULL,
	PRIMARY KEY  (import_image_id),
	UNIQUE KEY import_version_image (import_id,version_image_id),
	UNIQUE KEY recovery_slug_unique (recovery_slug),
	KEY attachment (attachment_id)
) {$charset_collate};",
		];
	}

	/**
	 * Run dbDelta() for each table. Success is decided afterwards by
	 * verification, not by dbDelta()'s own output, so a concurrent request
	 * that created the same table first is not reported as a failure.
	 *
	 * @param array<string,string> $statements Table name => CREATE TABLE.
	 * @return true|\WP_Error
	 */
	private static function create_tables( array $statements ) {
		foreach ( array_keys( $statements ) as $table ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
				return self::error( 'starter_import_schema_failed', 'The starter import table name is not valid.' );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		try {
			foreach ( $statements as $sql ) {
				dbDelta( $sql );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'starter_import_schema_failed', 'The starter import storage could not be prepared.' );
		}

		return true;
	}

	/**
	 * @param array<string,array{columns:array<int,string>,unique:array<string,array<int,string>>}> $shape
	 * @return true|\WP_Error
	 */
	private static function verify_shape( array $shape, string $prefix ) {
		global $wpdb;

		foreach ( $shape as $suffix => $expected ) {
			$table = $prefix . $suffix;
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
				return self::invalid();
			}

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( '' !== (string) $wpdb->last_error || $table !== $exists ) {
				return self::invalid();
			}

			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $columns ) ) {
				return self::invalid();
			}
			if ( [] !== array_diff( $expected['columns'], $columns ) ) {
				return self::invalid();
			}

			$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
				return self::invalid();
			}

			$unique = [];
			foreach ( $rows as $row ) {
				if ( '0' !== (string) ( $row['Non_unique'] ?? '' ) ) {
					continue;
				}
				$unique[ (string) $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
			foreach ( $expected['unique'] as $key => $key_columns ) {
				if ( ! isset( $unique[ $key ] ) ) {
					return self::invalid();
				}
				ksort( $unique[ $key ] );
				if ( array_values( $unique[ $key ] ) !== $key_columns ) {
					return self::invalid();
				}
			}
		}

		return true;
	}

	/**
	 * A current schema that fails verification is a repair case, never a
	 * reason to recreate tables.
	 *
	 * @param true|\WP_Error $verified
	 * @return true|\WP_Error
	 */
	private static function verified_or_repair( $verified ) {
		if ( is_wp_error( $verified ) ) {
			return self::error( 'starter_import_schema_repair_needed', 'The starter import storage needs repair. Please contact support.' );
		}

		return true;
	}

	private static function invalid(): \WP_Error {
		return self::error( 'starter_import_schema_invalid', 'The starter import storage does not match the expected structure.' );
	}

	/** Fixed messages only: no SQL, table names or paths. */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-catalog' ) );
	}
}
