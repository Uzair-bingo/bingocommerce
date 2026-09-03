<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the Portal integration plugin's network-wide schema. */
final class Migrations {

	public const NETWORK_DB_VERSION = 1;
	public const NETWORK_VERSION_OPTION = 'bc_portal_integration_network_db_version';
	public const HALL_MAPPINGS_TABLE = 'bingo_portal_hall_mappings';

	/** @return true|\WP_Error */
	public static function maybe_migrate_network() {
		if ( self::is_current() ) {
			return true;
		}

		$result = self::migrate_network();
		if ( is_wp_error( $result ) ) {
			do_action( 'bingocommerce_portal_integration_migration_failed', $result );
		}

		return $result;
	}

	/** @return true|\WP_Error */
	public static function migrate_network() {
		if ( ! is_multisite() ) {
			return self::verification_error( 'Portal integration requires WordPress Multisite.' );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$table           = self::hall_mappings_table();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
	portal_hall_id bigint(20) unsigned NOT NULL,
	blog_id bigint(20) unsigned NOT NULL,
	status varchar(16) NOT NULL DEFAULT 'active',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (portal_hall_id),
	UNIQUE KEY blog_id_unique (blog_id)
) ENGINE=InnoDB {$charset_collate};"
		);

		$verified = self::verify_schema();
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		update_site_option( self::NETWORK_VERSION_OPTION, self::NETWORK_DB_VERSION );
		if ( self::persisted_network_version() < self::NETWORK_DB_VERSION ) {
			return new \WP_Error(
				'portal_integration_migration_version_not_persisted',
				__( 'The Portal integration network schema version could not be recorded.', 'bingocommerce-portal-integration' )
			);
		}

		return true;
	}

	public static function is_current(): bool {
		return (int) get_site_option( self::NETWORK_VERSION_OPTION, 0 ) >= self::NETWORK_DB_VERSION;
	}

	public static function hall_mappings_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . self::HALL_MAPPINGS_TABLE;
	}

	/** @return true|\WP_Error */
	public static function verify_schema() {
		global $wpdb;

		$table = self::hall_mappings_table();
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
		if ( $found !== $table ) {
			return self::verification_error( 'The Portal Hall mapping table is missing.' );
		}

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $columns ) || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( 'The Portal Hall mapping columns could not be inspected.' );
		}

		$by_name = [];
		foreach ( $columns as $column ) {
			$by_name[ $column['Field'] ] = $column;
		}

		$expected_columns = [
			'portal_hall_id' => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
			'blog_id'        => [ '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '' ],
			'status'         => [ '/^varchar\(16\)$/', 'NO', 'active', '' ],
			'created_at'     => [ '/^datetime$/', 'NO', null, '' ],
			'updated_at'     => [ '/^datetime$/', 'NO', null, '' ],
		];
		if ( count( $by_name ) !== count( $expected_columns ) ) {
			return self::verification_error( 'The Portal Hall mapping table has unexpected columns.' );
		}

		foreach ( $expected_columns as $name => [ $type, $nullable, $default, $extra ] ) {
			$actual = $by_name[ $name ] ?? null;
			if (
				! is_array( $actual )
				|| 1 !== preg_match( $type, strtolower( (string) $actual['Type'] ) )
				|| $nullable !== ( $actual['Null'] ?? null )
				|| $default !== ( $actual['Default'] ?? null )
				|| $extra !== strtolower( (string) ( $actual['Extra'] ?? '' ) )
			) {
				return self::verification_error( sprintf( 'The Portal Hall mapping %s column is missing or incompatible.', $name ) );
			}
		}

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		if ( ! is_array( $indexes ) || '' !== (string) $wpdb->last_error ) {
			return self::verification_error( 'The Portal Hall mapping indexes could not be inspected.' );
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

		$expected_indexes = [
			'PRIMARY'        => [ 'unique' => true, 'columns' => [ 'portal_hall_id' ] ],
			'blog_id_unique' => [ 'unique' => true, 'columns' => [ 'blog_id' ] ],
		];
		if ( count( $actual_indexes ) !== count( $expected_indexes ) ) {
			return self::verification_error( 'The Portal Hall mapping table has unexpected indexes.' );
		}
		foreach ( $expected_indexes as $name => $expected ) {
			if ( ! isset( $actual_indexes[ $name ] ) || $expected !== $actual_indexes[ $name ] ) {
				return self::verification_error( sprintf( 'The Portal Hall mapping %s index is missing or incompatible.', $name ) );
			}
		}

		$status = $wpdb->get_row(
			$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ),
			ARRAY_A
		);
		if (
			! is_array( $status )
			|| 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) )
			|| 0 !== strpos( strtolower( (string) ( $status['Collation'] ?? '' ) ), 'utf8mb4_' )
		) {
			return self::verification_error( 'The Portal Hall mapping table engine or charset is incompatible.' );
		}

		return true;
	}

	private static function persisted_network_version(): int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s",
				get_current_network_id(),
				self::NETWORK_VERSION_OPTION
			)
		);

		return null === $value ? 0 : (int) $value;
	}

	private static function verification_error( string $message ): \WP_Error {
		return new \WP_Error(
			'portal_integration_migration_verification_failed',
			__( $message, 'bingocommerce-portal-integration' )
		);
	}
}
