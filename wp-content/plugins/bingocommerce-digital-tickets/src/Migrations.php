<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Plugin-owned schema. No payment callback performs DDL or creates defaults. */
final class Migrations {
	public const DB_VERSION = 2;
	public const VERSION_OPTION = 'bc_digital_tickets_db_version';
	public const SWEEP_OPTION = 'bc_digital_tickets_network_migration_sweep_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bingo_entitlements';
	}

	public static function voids_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bingo_entitlement_voids';
	}

	public static function is_hall(): bool {
		return class_exists( HallContext::class ) && HallContext::is_hall();
	}

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) { self::maybe_migrate_network(); }
		else { self::maybe_migrate_current_site(); }
	}

	public static function maybe_migrate_current_site(): void {
		if ( self::is_hall() && (int) get_option( self::VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			self::report( self::migrate_current_site() );
		}
	}

	public static function maybe_migrate_network(): void {
		if ( ! is_multisite() || ! is_network_admin() || ! is_super_admin( get_current_user_id() )
			|| (int) get_site_option( self::SWEEP_OPTION, 0 ) >= self::DB_VERSION || ! class_exists( HallContext::class ) ) { return; }
		$complete = true;
		foreach ( get_sites( [ 'number' => 0 ] ) as $site ) {
			switch_to_blog( (int) $site->blog_id );
			try {
				if ( self::is_hall() && (int) get_option( self::VERSION_OPTION, 0 ) < self::DB_VERSION ) {
					$result = self::migrate_current_site();
					self::report( $result );
					$complete = $complete && ! is_wp_error( $result );
				}
			} finally { restore_current_blog(); }
		}
		if ( $complete ) { update_site_option( self::SWEEP_OPTION, self::DB_VERSION ); }
	}

	public static function provision_new_site( \WP_Site $site ): void {
		switch_to_blog( (int) $site->blog_id );
		try { self::maybe_migrate_current_site(); }
		finally { restore_current_blog(); }
	}

	public static function migrate_current_site(): true|\WP_Error {
		if ( ! self::is_hall() ) { return self::error(); }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table(); $collation = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
	entitlement_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	order_id bigint(20) unsigned NOT NULL,
	session_id bigint(20) unsigned NOT NULL,
	package_id bigint(20) unsigned NOT NULL,
	selection_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	snapshot_version smallint(5) unsigned NOT NULL,
	digital_faces_json longtext NOT NULL,
	status varchar(16) NOT NULL,
	confirmed_at datetime NOT NULL,
	PRIMARY KEY  (entitlement_id),
	UNIQUE KEY order_unique (order_id),
	KEY session_status (session_id,status)
) ENGINE=InnoDB {$collation};" );
		$voids = self::voids_table();
		dbDelta( "CREATE TABLE {$voids} (
	order_id bigint(20) unsigned NOT NULL,
	session_id bigint(20) unsigned NOT NULL,
	package_id bigint(20) unsigned NOT NULL,
	selection_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	cause varchar(16) NOT NULL,
	refund_id bigint(20) unsigned NOT NULL,
	voided_at datetime NOT NULL,
	PRIMARY KEY  (order_id),
	KEY session_id (session_id)
) ENGINE=InnoDB {$collation};" );
		$result = self::verify_current_site_schema();
		if ( is_wp_error( $result ) ) { return $result; }
		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		$version = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::VERSION_OPTION ) );
		return '' === $wpdb->last_error && (int) $version === self::DB_VERSION ? true : self::error();
	}

	/** Check the unique arbiter, complete row structure and storage engine before use. */
	public static function verify_current_site_schema(): true|\WP_Error {
		if ( ! self::is_hall() ) { return self::error(); }
		$bigint = '/^bigint(?:\(20\))? unsigned$/';
		$result = self::verify_table( self::table(), [
			'entitlement_id' => $bigint, 'order_id' => $bigint, 'session_id' => $bigint, 'package_id' => $bigint,
			'selection_id' => '/^char\(36\)$/', 'snapshot_version' => '/^smallint(?:\(5\))? unsigned$/',
			'digital_faces_json' => '/^longtext$/', 'status' => '/^varchar\(16\)$/', 'confirmed_at' => '/^datetime$/',
		], [ 'PRIMARY' => [ [ 'entitlement_id', 0, null ] ], 'order_unique' => [ [ 'order_id', 0, null ] ], 'session_status' => [ [ 'session_id', 1, null ], [ 'status', 1, null ] ] ] );
		if ( is_wp_error( $result ) ) { return $result; }
		return self::verify_table( self::voids_table(), [
			'order_id' => $bigint, 'session_id' => $bigint, 'package_id' => $bigint, 'selection_id' => '/^char\(36\)$/',
			'cause' => '/^varchar\(16\)$/', 'refund_id' => $bigint, 'voided_at' => '/^datetime$/',
		], [ 'PRIMARY' => [ [ 'order_id', 0, null ] ], 'session_id' => [ [ 'session_id', 1, null ] ] ] );
	}

	private static function verify_table( string $table, array $patterns, array $required_indexes ): true|\WP_Error {
		global $wpdb;
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $status ) || 'InnoDB' !== $status['Engine'] || ! str_starts_with( $status['Collation'], 'utf8mb4_' ) ) { return self::error(); }
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $columns ) ) { return self::error(); }
		$columns = array_column( $columns, null, 'Field' );
		foreach ( $patterns as $name => $pattern ) {
			$column = $columns[ $name ] ?? [];
			if ( ! preg_match( $pattern, $column['Type'] ?? '' ) || 'NO' !== ( $column['Null'] ?? null )
				|| ( 'entitlement_id' === $name ? 'auto_increment' : '' ) !== ( $column['Extra'] ?? null ) ) { return self::error(); }
		}
		if ( 'ascii_bin' !== $columns['selection_id']['Collation'] ) { return self::error(); }
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $indexes ) ) { return self::error(); }
		$actual = [];
		foreach ( $indexes as $index ) {
			$actual[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = [ $index['Column_name'], (int) $index['Non_unique'], $index['Sub_part'] ];
		}
		foreach ( $required_indexes as $key => $expected ) {
			$found = $actual[ $key ] ?? []; ksort( $found );
			if ( array_values( $found ) !== $expected ) { return self::error(); }
		}
		return true;
	}

	private static function error(): \WP_Error {
		return new \WP_Error( 'bc_entitlement_schema_unavailable', __( 'The Hall digital entitlement schema is unavailable or incompatible.', 'bingocommerce-digital-tickets' ) );
	}

	private static function report( true|\WP_Error $result ): void {
		if ( is_wp_error( $result ) ) { do_action( 'bingocommerce_digital_tickets_migration_failed', $result, get_current_blog_id() ); }
	}
}
