<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-048 network schema. Explicit migration only, like ProvisioningMigrations:
 * no activation/admin_init/cron hook, and no change to the Hall mapping version.
 */
final class SsoMigrations {

	public const DB_VERSION = 1;
	public const VERSION_OPTION = 'bc_portal_sso_db_version';
	public const LINKS_TABLE = 'bingo_portal_sso_links';
	public const ATTEMPTS_TABLE = 'bingo_portal_sso_attempts';

	public static function links_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::LINKS_TABLE;
	}

	public static function attempts_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::ATTEMPTS_TABLE;
	}

	/** @return true|\WP_Error */
	public static function migrate() {
		if ( ! is_multisite() ) {
			return self::error( 'Portal SSO requires WordPress Multisite.' );
		}
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$version = self::read_version();
			if ( is_wp_error( $version ) ) {
				return $version;
			}
			if ( $version >= self::DB_VERSION ) {
				return self::verify_schema();
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			// Only server-owned schema/charset syntax is composed. Identifiers use %i.
			dbDelta( $wpdb->prepare(
				"CREATE TABLE %i (
	portal_installation_id varchar(20) NOT NULL,
	portal_user_id bigint(20) unsigned NOT NULL,
	portal_user_created_at varchar(32) NOT NULL,
	planned_username varchar(60) NOT NULL,
	creation_blog_id bigint(20) unsigned NOT NULL,
	creation_operation_id char(36) NOT NULL,
	creation_sso_reference varchar(64) NOT NULL,
	wp_user_id bigint(20) unsigned DEFAULT NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY  (portal_installation_id,portal_user_id),
	UNIQUE KEY planned_username_unique (planned_username),
	UNIQUE KEY wp_user_id_unique (wp_user_id),
	KEY creation_blog_id (creation_blog_id)
) ENGINE=InnoDB {$charset_collate};",
				self::links_table()
			) );
			dbDelta( $wpdb->prepare(
				"CREATE TABLE %i (
	attempt_reference_hash char(64) NOT NULL,
	browser_secret_fingerprint char(64) NOT NULL,
	secret_proof varchar(128) NOT NULL,
	ip_fingerprint char(64) NOT NULL,
	blog_id bigint(20) unsigned NOT NULL,
	sso_reference varchar(64) NOT NULL,
	created_at datetime NOT NULL,
	expires_at datetime NOT NULL,
	used_at datetime DEFAULT NULL,
	PRIMARY KEY  (attempt_reference_hash),
	KEY blog_id (blog_id),
	KEY expires_at (expires_at),
	KEY ip_created (ip_fingerprint,created_at)
) ENGINE=InnoDB {$charset_collate};",
				self::attempts_table()
			) );

			$verified = self::verify_schema();
			if ( is_wp_error( $verified ) ) {
				return $verified;
			}
			update_site_option( self::VERSION_OPTION, self::DB_VERSION );
			$persisted = self::read_version();
			return self::DB_VERSION === $persisted ? true : self::error( 'The Portal SSO schema version could not be recorded.' );
		} catch ( \Throwable $error ) {
			// Never expose SQL, database text or a stack trace in a migration error.
			return self::error( 'The Portal SSO schema could not be applied.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/** Version alone is not proof of a usable schema. No automatic repair. */
	public static function is_ready(): bool {
		$version = self::read_version();
		return ! is_wp_error( $version ) && $version >= self::DB_VERSION && true === self::verify_schema();
	}

	/** @return int|\WP_Error */
	private static function read_version() {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			$value = $wpdb->get_var( $wpdb->prepare(
				'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s LIMIT 1',
				$wpdb->sitemeta, get_current_network_id(), self::VERSION_OPTION
			) );
			return '' === $wpdb->last_error ? (int) $value : self::error( 'The Portal SSO schema version could not be read.' );
		} catch ( \Throwable $error ) {
			return self::error( 'The Portal SSO schema version could not be read.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/** @return true|\WP_Error */
	public static function verify_schema() {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		try {
			foreach ( self::schemas() as $table => $schema ) {
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
				if ( ! is_array( $status ) || '' !== $wpdb->last_error || 'innodb' !== strtolower( $status['Engine'] ) || 0 !== strpos( $status['Collation'], 'utf8mb4_' ) ) {
					return self::error( 'A Portal SSO table is missing or has an incompatible engine or charset.' );
				}
				$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
				if ( ! is_array( $columns ) || '' !== $wpdb->last_error || count( $columns ) !== count( $schema['columns'] ) ) {
					return self::error( 'The Portal SSO columns could not be verified.' );
				}
				foreach ( $columns as $column ) {
					$expected = $schema['columns'][ $column['Field'] ] ?? null;
					$type = preg_replace( '/bigint\(20\)/', 'bigint', strtolower( $column['Type'] ) );
					if ( ! $expected || $expected !== [ $type, $column['Null'] ] || null !== $column['Default'] || '' !== $column['Extra'] ) {
						return self::error( 'A Portal SSO column is incompatible.' );
					}
				}
				$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
				if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
					return self::error( 'The Portal SSO indexes could not be read.' );
				}
				$indexes = [];
				foreach ( $rows as $row ) {
					if ( null !== $row['Sub_part'] ) {
						return self::error( 'A Portal SSO index has an incompatible prefix.' );
					}
					$indexes[ $row['Key_name'] ]['unique'] = 0 === (int) $row['Non_unique'];
					$indexes[ $row['Key_name'] ]['columns'][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
				}
				foreach ( $indexes as &$index ) {
					ksort( $index['columns'] );
					$index['columns'] = array_values( $index['columns'] );
				}
				unset( $index );
				ksort( $indexes );
				ksort( $schema['indexes'] );
				if ( $indexes !== $schema['indexes'] ) {
					return self::error( 'A Portal SSO index is missing or incompatible.' );
				}
			}
			return true;
		} catch ( \Throwable $error ) {
			return self::error( 'The Portal SSO schema could not be verified.' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/** @return array<string,array<string,array>> */
	private static function schemas(): array {
		return [
			self::links_table() => [
				'columns' => [
					'portal_installation_id' => [ 'varchar(20)', 'NO' ],
					'portal_user_id' => [ 'bigint unsigned', 'NO' ],
					'portal_user_created_at' => [ 'varchar(32)', 'NO' ],
					'planned_username' => [ 'varchar(60)', 'NO' ],
					'creation_blog_id' => [ 'bigint unsigned', 'NO' ],
					'creation_operation_id' => [ 'char(36)', 'NO' ],
					'creation_sso_reference' => [ 'varchar(64)', 'NO' ],
					'wp_user_id' => [ 'bigint unsigned', 'YES' ],
					'created_at' => [ 'datetime', 'NO' ],
				],
				'indexes' => [
					'PRIMARY' => [ 'unique' => true, 'columns' => [ 'portal_installation_id', 'portal_user_id' ] ],
					'planned_username_unique' => [ 'unique' => true, 'columns' => [ 'planned_username' ] ],
					'wp_user_id_unique' => [ 'unique' => true, 'columns' => [ 'wp_user_id' ] ],
					'creation_blog_id' => [ 'unique' => false, 'columns' => [ 'creation_blog_id' ] ],
				],
			],
			self::attempts_table() => [
				'columns' => [
					'attempt_reference_hash' => [ 'char(64)', 'NO' ],
					'browser_secret_fingerprint' => [ 'char(64)', 'NO' ],
					'secret_proof' => [ 'varchar(128)', 'NO' ],
					'ip_fingerprint' => [ 'char(64)', 'NO' ],
					'blog_id' => [ 'bigint unsigned', 'NO' ],
					'sso_reference' => [ 'varchar(64)', 'NO' ],
					'created_at' => [ 'datetime', 'NO' ],
					'expires_at' => [ 'datetime', 'NO' ],
					'used_at' => [ 'datetime', 'YES' ],
				],
				'indexes' => [
					'PRIMARY' => [ 'unique' => true, 'columns' => [ 'attempt_reference_hash' ] ],
					'blog_id' => [ 'unique' => false, 'columns' => [ 'blog_id' ] ],
					'expires_at' => [ 'unique' => false, 'columns' => [ 'expires_at' ] ],
					'ip_created' => [ 'unique' => false, 'columns' => [ 'ip_fingerprint', 'created_at' ] ],
				],
			],
		];
	}

	private static function error( string $message ): \WP_Error {
		return new \WP_Error( 'portal_sso_schema_unavailable', __( $message, 'bingocommerce-portal-integration' ) );
	}
}
