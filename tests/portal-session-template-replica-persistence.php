<?php

/**
 * CLI integration coverage for Phase 3C2 Portal Session Template persistence.
 *
 * Run from the repository root:
 * php tests/portal-session-template-replica-persistence.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( ! class_exists( '\BingoCommerce\Sessions\Migrations' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/bingocommerce-sessions.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Sessions\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Sessions\Plugin::init();
}

use BingoCommerce\Sessions\Migrations;

final class PortalSessionTemplateReplicaPersistenceTest {

	private const PERSISTENT_HALLS = [ 2, 3, 4 ];

	/** @var array<int,bool> */
	private array $disposable_sites = [];

	/** @var array<int,array<int,string>> */
	private array $deleted_site_tables = [];

	/** @var array<string,string> */
	private array $persistent_domain_rows = [];

	/** @var array<string,string> */
	private array $persistent_catalog_schema = [];

	/** @var array<string,string> */
	private array $persistent_woo_rows = [];

	/** @var array<string,mixed> */
	private array $mapping_snapshot = [];

	private int $assertions = 0;
	private int $super_admin_id = 0;

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->assert_same( 2, Migrations::DB_VERSION, 'Sessions schema advances exactly one version from v1 to v2' );
		$this->super_admin_id = $this->find_super_admin_id();
		$this->assert_true( $this->super_admin_id > 0, 'native Super Admin fixture exists' );

		$this->persistent_domain_rows   = $this->snapshot_rows( self::PERSISTENT_HALLS, $this->domain_table_suffixes() );
		$this->persistent_catalog_schema = $this->snapshot_schema( self::PERSISTENT_HALLS, $this->catalog_table_suffixes() );
		$this->persistent_woo_rows      = $this->snapshot_rows( self::PERSISTENT_HALLS, $this->woo_table_suffixes() );
		$this->mapping_snapshot         = $this->snapshot_mapping();

		try {
			$this->upgrade_existing_v1_hall();
			$this->new_site_provisioning();
		} finally {
			$this->cleanup();
		}

		$this->assert_same(
			$this->persistent_domain_rows,
			$this->snapshot_rows( self::PERSISTENT_HALLS, $this->domain_table_suffixes() ),
			'Sunrise, Tampa, and Orlando domain rows are byte-identical after the suite'
		);
		$this->assert_same(
			$this->persistent_catalog_schema,
			$this->snapshot_schema( self::PERSISTENT_HALLS, $this->catalog_table_suffixes() ),
			'Sunrise, Tampa, and Orlando Catalog schemas are byte-identical after the suite'
		);
		$this->assert_same(
			$this->persistent_woo_rows,
			$this->snapshot_rows( self::PERSISTENT_HALLS, $this->woo_table_suffixes() ),
			'Sunrise, Tampa, and Orlando WooCommerce product/order rows are byte-identical after the suite'
		);
		$this->assert_same( $this->mapping_snapshot, $this->snapshot_mapping(), 'Portal Hall mapping schema and rows are byte-identical after the suite' );
		$this->assert_same( [], $this->disposable_sites, 'every disposable hall created by this run was destroyed' );
		$this->verify_disposable_cleanup();

		echo "PASS: {$this->assertions} Phase 3C2 Portal Session Template persistence assertions.\n";
	}

	private function upgrade_existing_v1_hall(): void {
		global $wpdb;

		$site_id = $this->create_disposable_site( 'Upgrade' );
		$this->assert_true( $site_id > 4, 'upgrade fixture is a current-run disposable hall' );
		if ( $site_id < 1 ) {
			return;
		}

		switch_to_blog( $site_id );
		try {
			$templates = Migrations::portal_session_templates_table();
			$this->disposable_ddl( $site_id, "DROP TABLE IF EXISTS `{$templates}`" );
			update_option( Migrations::VERSION_OPTION, 1, false );
			$this->assert_same( 1, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'fixture represents the prior Sessions v1 state' );
			$this->assert_false( $this->table_exists( $templates ), 'prior v1 fixture has no Portal Session Template table' );

			$now      = current_time( 'mysql', true );
			$sessions = Migrations::sessions_table();
			$relations = Migrations::session_packages_table();
			$inserted = $wpdb->insert(
				$sessions,
				[
					'session_date'       => '2032-05-06',
					'display_name'       => 'Existing Dated Commerce Occurrence',
					'available_for_sale' => 1,
					'status'             => 'active',
					'created_at'         => $now,
					'updated_at'         => $now,
					'archived_at'        => null,
				],
				[ '%s', '%s', '%d', '%s', '%s', '%s', null ]
			);
			$this->assert_same( 1, $inserted, 'existing dated Commerce Session fixture is inserted' );
			$session_id = (int) $wpdb->insert_id;

			$inserted = $wpdb->insert(
				$relations,
				[
					'session_id' => $session_id,
					'package_id' => 987654321,
					'created_at' => $now,
				],
				[ '%d', '%d', '%s' ]
			);
			$this->assert_same( 1, $inserted, 'existing Session/Package assignment fixture is inserted' );
			$relation_id = (int) $wpdb->insert_id;

			$session_before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$sessions}` WHERE session_id = %d", $session_id ), ARRAY_A );
			$relation_before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$relations}` WHERE session_package_id = %d", $relation_id ), ARRAY_A );
			$catalog_rows_before = $this->snapshot_rows( [ $site_id ], $this->catalog_table_suffixes() );
			$catalog_schema_before = $this->snapshot_schema( [ $site_id ], $this->catalog_table_suffixes() );
			$woo_before = $this->snapshot_rows( [ $site_id ], $this->woo_table_suffixes() );

			$this->assert_true( true === Migrations::migrate_current_site(), 'existing disposable hall upgrades from Sessions v1 to v2' );
			$this->assert_same( 2, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'v2 is recorded only after all three Session tables verify' );
			$this->assert_true( true === Migrations::verify_current_site_schema(), 'complete Sessions v2 schema verifies' );
			$this->assert_same( $wpdb->prefix . 'bingo_portal_session_templates', $templates, 'replica table uses the active Hall site prefix' );
			$this->assert_false( str_contains( substr( $templates, strlen( $wpdb->prefix ) ), 'blog_id' ), 'replica table name does not encode a blog_id column' );
			$this->assert_exact_schema( $templates, 'upgraded hall' );

			$session_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$sessions}` WHERE session_id = %d", $session_id ), ARRAY_A );
			$relation_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$relations}` WHERE session_package_id = %d", $relation_id ), ARRAY_A );
			$this->assert_same( $session_before, $session_after, 'every existing dated Commerce Session field survives byte-identically' );
			$this->assert_same( $relation_before, $relation_after, 'existing Session/Package assignment survives byte-identically' );
			$this->assert_same( $catalog_rows_before, $this->snapshot_rows( [ $site_id ], $this->catalog_table_suffixes() ), 'Catalog rows are unchanged by Sessions v2 migration' );
			$this->assert_same( $catalog_schema_before, $this->snapshot_schema( [ $site_id ], $this->catalog_table_suffixes() ), 'Catalog schemas are unchanged by Sessions v2 migration' );
			$this->assert_same( $woo_before, $this->snapshot_rows( [ $site_id ], $this->woo_table_suffixes() ), 'WooCommerce product/order rows are unchanged by Sessions v2 migration' );

			$this->exercise_replica_capabilities( $templates, $now );

			$rows_before_rerun = $this->table_rows( $templates, 'session_template_id' );
			$ddl_before_rerun  = $this->table_ddl( $templates );
			$this->assert_true( true === Migrations::migrate_current_site(), 'Sessions v2 migration rerun is idempotent' );
			$this->assert_true( true === Migrations::verify_current_site_schema(), 'complete schema still verifies after migration rerun' );
			$this->assert_same( $rows_before_rerun, $this->table_rows( $templates, 'session_template_id' ), 'replica rows are byte-identical after migration rerun' );
			$this->assert_same( $ddl_before_rerun, $this->table_ddl( $templates ), 'replica table schema is byte-identical after migration rerun' );
			$this->assert_same( $session_before, $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$sessions}` WHERE session_id = %d", $session_id ), ARRAY_A ), 'dated Commerce Session remains byte-identical after rerun' );
			$this->assert_same( $relation_before, $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$relations}` WHERE session_package_id = %d", $relation_id ), ARRAY_A ), 'Session/Package assignment remains byte-identical after rerun' );
		} finally {
			restore_current_blog();
		}
	}

	private function exercise_replica_capabilities( string $table, string $now ): void {
		global $wpdb;

		$fixtures = [
			[ 10, 'Morning Session', 'active' ],
			[ 11, 'Evening Session', 'draft' ],
			[ 12, 'Weekend Session', 'active' ],
		];
		foreach ( $fixtures as [ $portal_id, $name, $status ] ) {
			$this->assert_same(
				1,
				$wpdb->insert(
					$table,
					[
						'portal_session_id' => $portal_id,
						'name'              => $name,
						'portal_status'     => $status,
						'is_current'        => 1,
						'created_at'        => $now,
						'updated_at'        => $now,
						'not_current_at'    => null,
					],
					[ '%d', '%s', '%s', '%d', '%s', '%s', null ]
				),
				"Portal Session Template {$portal_id} can be represented"
			);
		}

		$replicas = $wpdb->get_results( "SELECT session_template_id, portal_session_id, name, portal_status, is_current, not_current_at FROM `{$table}` ORDER BY portal_session_id", ARRAY_A );
		$local_ids = array_map( 'intval', array_column( $replicas, 'session_template_id' ) );
		$this->assert_same( 3, count( $replicas ), 'different Portal identities produce separate replica rows' );
		$this->assert_true( min( $local_ids ) > 0, 'every replica has a valid locally generated surrogate identity' );
		$this->assert_same( 3, count( array_unique( $local_ids ) ), 'locally generated surrogate identities are distinct' );
		$this->assert_same( [ 10, 11, 12 ], array_map( 'intval', array_column( $replicas, 'portal_session_id' ) ), 'all Portal-style template identities coexist' );
		$this->assert_same( [ 'Morning Session', 'Evening Session', 'Weekend Session' ], array_column( $replicas, 'name' ), 'all Portal-style template names are retained as attributes' );
		$this->assert_same( [ 'active', 'draft', 'active' ], array_column( $replicas, 'portal_status' ), 'Portal operational status is retained independently' );
		$coincident_id = max( $local_ids ) + 1;

		$this->assert_same(
			1,
			$wpdb->insert(
				$table,
				[
					'portal_session_id' => $coincident_id,
					'name'              => 'Morning Session',
					'portal_status'     => 'draft',
					'is_current'        => 1,
					'created_at'        => $now,
					'updated_at'        => $now,
				],
				[ '%d', '%s', '%s', '%d', '%s', '%s' ]
			),
			'duplicate mutable names are allowed when Portal identities differ'
		);
		$coincident_local_id = (int) $wpdb->insert_id;
		$coincident_row      = $wpdb->get_row( $wpdb->prepare( "SELECT session_template_id, portal_session_id FROM `{$table}` WHERE session_template_id = %d", $coincident_local_id ), ARRAY_A );
		$this->assert_same( $coincident_id, (int) ( $coincident_row['session_template_id'] ?? 0 ), 'the next surrogate identity is generated locally' );
		$this->assert_same( $coincident_id, (int) ( $coincident_row['portal_session_id'] ?? 0 ), 'the explicitly supplied Portal identity is preserved exactly' );
		$this->assert_same( (int) ( $coincident_row['session_template_id'] ?? 0 ), (int) ( $coincident_row['portal_session_id'] ?? 0 ), 'coincidental numeric equality is allowed and does not merge the two identity columns' );
		$this->assert_same( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE name = %s", 'Morning Session' ) ), 'name has no uniqueness constraint' );
		$this->assert_same( $coincident_id, (int) $wpdb->get_var( $wpdb->prepare( "SELECT portal_session_id FROM `{$table}` WHERE session_template_id = %d", $coincident_local_id ) ), 'Portal identity is stored explicitly and is not inferred from name' );

		$previous = $wpdb->suppress_errors( true );
		$duplicate = $wpdb->insert(
			$table,
			[
				'portal_session_id' => 10,
				'name'              => 'Different Name Cannot Rebind Identity',
				'portal_status'     => 'active',
				'is_current'        => 1,
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s' ]
		);
		$db_error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous );
		$this->assert_same( false, $duplicate, 'duplicate portal_session_id is rejected by the database' );
		$this->assert_true( false !== stripos( $db_error, 'portal_session_unique' ), 'duplicate failure identifies the Portal identity unique index' );
		$this->assert_same( 4, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), 'rejected duplicate creates no replica row' );

		$not_current_at = '2032-05-07 01:02:03';
		$this->assert_same(
			1,
			$wpdb->update(
				$table,
				[ 'is_current' => 0, 'not_current_at' => $not_current_at ],
				[ 'portal_session_id' => 11 ],
				[ '%d', '%s' ],
				[ '%d' ]
			),
			'a replica can be marked not current without deletion'
		);
		$not_current = $wpdb->get_row( "SELECT portal_status, is_current, not_current_at FROM `{$table}` WHERE portal_session_id = 11", ARRAY_A );
		$this->assert_same( [ 'portal_status' => 'draft', 'is_current' => '0', 'not_current_at' => $not_current_at ], $not_current, 'replica currentness is independent from Portal operational status' );
		$this->assert_same( 4, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), 'marking a replica not current preserves every replica row' );
		$this->assert_same( null, $wpdb->get_var( "SELECT not_current_at FROM `{$table}` WHERE portal_session_id = 10" ), 'not_current_at remains null for a current replica' );
	}

	private function new_site_provisioning(): void {
		global $wpdb;

		$site_id = $this->create_disposable_site( 'Provisioning' );
		$this->assert_true( $site_id > 4, 'new-site fixture is a current-run disposable hall' );
		if ( $site_id < 1 ) {
			return;
		}

		switch_to_blog( $site_id );
		try {
			$this->assert_same( 2, (int) get_option( Migrations::VERSION_OPTION, 0 ), 'normal new-site provisioning records Sessions v2' );
			$this->assert_true( true === Migrations::verify_current_site_schema(), 'normal new-site provisioning creates all three Session tables' );
			$this->assert_same( $wpdb->get_blog_prefix( $site_id ) . 'bingo_portal_session_templates', Migrations::portal_session_templates_table(), 'new Hall replica table uses only its per-site prefix' );
			$this->assert_exact_schema( Migrations::portal_session_templates_table(), 'newly provisioned hall' );
			$this->assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Migrations::portal_session_templates_table() . '`' ), 'new Hall replica table starts empty' );
		} finally {
			restore_current_blog();
		}
	}

	private function assert_exact_schema( string $table, string $label ): void {
		global $wpdb;

		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A );
		$this->assert_same(
			[ 'session_template_id', 'portal_session_id', 'name', 'portal_status', 'is_current', 'created_at', 'updated_at', 'not_current_at' ],
			array_column( $columns, 'Field' ),
			"{$label}: replica table has exactly the approved fields"
		);
		$by_name = array_column( $columns, null, 'Field' );
		$this->assert_column( $by_name, 'session_template_id', '/^bigint(?:\(20\))? unsigned$/', 'NO', null, 'auto_increment', $label );
		$this->assert_column( $by_name, 'portal_session_id', '/^bigint(?:\(20\))? unsigned$/', 'NO', null, '', $label );
		$this->assert_column( $by_name, 'name', '/^text$/', 'NO', null, '', $label );
		$this->assert_column( $by_name, 'portal_status', '/^varchar\(32\)$/', 'NO', null, '', $label );
		$this->assert_column( $by_name, 'is_current', '/^tinyint(?:\(1\))? unsigned$/', 'NO', '1', '', $label );
		$this->assert_column( $by_name, 'created_at', '/^datetime$/', 'NO', null, '', $label );
		$this->assert_column( $by_name, 'updated_at', '/^datetime$/', 'NO', null, '', $label );
		$this->assert_column( $by_name, 'not_current_at', '/^datetime$/', 'YES', null, '', $label );

		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		$shape = [];
		foreach ( $indexes as $index ) {
			$key = (string) $index['Key_name'];
			$shape[ $key ]['unique'] = 0 === (int) $index['Non_unique'];
			$shape[ $key ]['columns'][ (int) $index['Seq_in_index'] ] = (string) $index['Column_name'];
		}
		foreach ( $shape as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );
		$this->assert_same(
			[
				'PRIMARY'               => [ 'unique' => true, 'columns' => [ 'session_template_id' ] ],
				'portal_session_unique' => [ 'unique' => true, 'columns' => [ 'portal_session_id' ] ],
			],
			$shape,
			"{$label}: replica table has only the primary and Portal identity unique indexes"
		);
		$this->assert_true( true === Migrations::verify_portal_session_templates_schema(), "{$label}: production replica schema verification passes" );

		$forbidden = [
			'blog_id', 'portal_hall_id', 'session_date', 'weekday', 'weekdays',
			'recurrence', 'recurring', 'start_time', 'structured_time',
			'available_for_sale', 'package_id', 'portal_days', 'days', 'game_id',
			'games', 'caller_id', 'device_group_id', 'xml_game_group_id',
			'selected_for_commerce', 'available_for_commerce', 'sell_online',
			'enabled_for_commerce', 'portal_updated_at',
		];
		$this->assert_same( [], array_values( array_intersect( $forbidden, array_column( $columns, 'Field' ) ) ), "{$label}: no Commerce-selection, schedule, Package, Portal operational-detail, tenant, or unfrozen timestamp fields exist" );
	}

	/** @param array<string,array<string,mixed>> $by_name */
	private function assert_column( array $by_name, string $name, string $type, string $nullable, ?string $default, string $extra, string $label ): void {
		$actual = $by_name[ $name ] ?? null;
		$this->assert_true(
			is_array( $actual )
			&& 1 === preg_match( $type, strtolower( (string) $actual['Type'] ) )
			&& $nullable === ( $actual['Null'] ?? null )
			&& $default === ( $actual['Default'] ?? null )
			&& $extra === strtolower( (string) ( $actual['Extra'] ?? '' ) ),
			"{$label}: {$name} has the approved type, nullability, default, and extra"
		);
	}

	private function create_disposable_site( string $label ): int {
		add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'portal3c2' . wp_generate_password( 8, false, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'Portal 3C2 Disposable Hall ' . $label,
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			$this->assert_true( false, 'disposable hall could not be created: ' . $site->get_error_message() );

			return 0;
		}

		$site_id = (int) $site;
		$this->disposable_sites[ $site_id ] = true;

		return $site_id;
	}

	private function disposable_ddl( int $site_id, string $sql ): void {
		global $wpdb;

		if ( $site_id <= 4 || empty( $this->disposable_sites[ $site_id ] ) || get_current_blog_id() !== $site_id ) {
			throw new \RuntimeException( 'Refusing structural SQL outside a current-run disposable hall.' );
		}

		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Disposable schema mutation failed.' );
		}
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** @param array<int,int> $site_ids
	 *  @param array<int,string> $suffixes
	 *  @return array<string,string>
	 */
	private function snapshot_rows( array $site_ids, array $suffixes ): array {
		global $wpdb;

		$snapshot = [];
		foreach ( $site_ids as $site_id ) {
			$prefix = $wpdb->get_blog_prefix( (int) $site_id );
			foreach ( $suffixes as $suffix ) {
				$table = $prefix . $suffix;
				if ( ! $this->table_exists( $table ) ) {
					continue;
				}
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
				usort( $rows, static fn( array $left, array $right ): int => strcmp( serialize( $left ), serialize( $right ) ) );
				$snapshot[ $table ] = hash( 'sha256', serialize( $rows ) );
			}
		}
		ksort( $snapshot );

		return $snapshot;
	}

	/** @param array<int,int> $site_ids
	 *  @param array<int,string> $suffixes
	 *  @return array<string,string>
	 */
	private function snapshot_schema( array $site_ids, array $suffixes ): array {
		global $wpdb;

		$snapshot = [];
		foreach ( $site_ids as $site_id ) {
			$prefix = $wpdb->get_blog_prefix( (int) $site_id );
			foreach ( $suffixes as $suffix ) {
				$table = $prefix . $suffix;
				if ( ! $this->table_exists( $table ) ) {
					continue;
				}
				$snapshot[ $table ] = $this->table_ddl( $table );
			}
		}
		ksort( $snapshot );

		return $snapshot;
	}

	/** @return array<int,array<string,mixed>> */
	private function table_rows( string $table, string $primary_key ): array {
		global $wpdb;

		return (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `{$primary_key}`", ARRAY_A );
	}

	private function table_ddl( string $table ): string {
		global $wpdb;

		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

		return is_array( $row ) ? (string) ( $row[1] ?? '' ) : '';
	}

	/** @return array<int,string> */
	private function domain_table_suffixes(): array {
		return array_merge( $this->catalog_table_suffixes(), [ 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates' ] );
	}

	/** @return array<int,string> */
	private function catalog_table_suffixes(): array {
		return [
			'bingo_game_types',
			'bingo_play_products',
			'bingo_packages',
			'bingo_package_components',
			'bingo_package_addons',
		];
	}

	/** @return array<int,string> */
	private function woo_table_suffixes(): array {
		return [
			'posts',
			'postmeta',
			'woocommerce_order_items',
			'woocommerce_order_itemmeta',
			'wc_orders',
			'wc_order_addresses',
			'wc_order_operational_data',
		];
	}

	/** @return array<string,mixed> */
	private function snapshot_mapping(): array {
		global $wpdb;

		$table = $wpdb->base_prefix . 'bingo_portal_hall_mappings';
		if ( ! $this->table_exists( $table ) ) {
			return [];
		}

		return [
			'ddl'  => $this->table_ddl( $table ),
			'rows' => (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY portal_hall_id", ARRAY_A ),
		];
	}

	private function cleanup(): void {
		global $wpdb;

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			$prefix  = $wpdb->get_blog_prefix( $site_id );
			$tables  = array_map( static fn( string $suffix ): string => $prefix . $suffix, $this->domain_table_suffixes() );

			wp_delete_site( $site_id );
			$allowed = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . $site_id . '_bingo_(game_types|play_products|packages|package_components|package_addons|sessions|session_packages|portal_session_templates)$/';
			foreach ( $tables as $table ) {
				if ( 1 === preg_match( $allowed, $table ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}

			$this->deleted_site_tables[ $site_id ] = $tables;
			unset( $this->disposable_sites[ $site_id ] );
		}
	}

	private function verify_disposable_cleanup(): void {
		global $wpdb;

		foreach ( $this->deleted_site_tables as $site_id => $tables ) {
			$this->assert_same( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $site_id ) ), "disposable hall {$site_id} record was removed" );
			foreach ( $tables as $table ) {
				$this->assert_same(
					0,
					(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ) ),
					"disposable hall {$site_id} table {$table} was removed"
				);
			}
		}
	}

	private function find_super_admin_id(): int {
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof \WP_User ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/** @param mixed $expected
	 *  @param mixed $actual
	 */
	private function assert_same( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected !== $actual ) {
			throw new \RuntimeException( "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
		}

		echo "PASS: {$message}\n";
	}

	private function assert_true( bool $condition, string $message ): void {
		$this->assert_same( true, $condition, $message );
	}

	private function assert_false( bool $condition, string $message ): void {
		$this->assert_same( false, $condition, $message );
	}
}

( new PortalSessionTemplateReplicaPersistenceTest() )->run();
