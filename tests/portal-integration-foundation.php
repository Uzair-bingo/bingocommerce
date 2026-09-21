<?php

/**
 * CLI integration coverage for the Phase 3B Portal integration foundation.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_test_suites php tests/portal-integration-foundation.php
 *
 * THE DATABASE NAME IS NOW REQUIRED (ADR-043 §16 amendment). This suite creates
 * disposable Multisite SITES, and `bingocommerce-sessions` is network-active, so
 * every wp_insert_site() fires its `wp_initialize_site` handler and provisions
 * the CURRENT Sessions schema into whichever database is booted. Once ADR-043
 * raised that schema to v3, the previous invocation — plain
 * `php tests/portal-integration-foundation.php`, which fell through the shared bootstrap to
 * wp-load.php — would have written v3 structure into the development
 * installation. tests/fixtures/wordpress-bootstrap.php no longer has that
 * fallback and refuses to boot without `BC_TEST_DB`.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( ! class_exists( '\BingoCommerce\PortalIntegration\HallMappingRepository' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}
if ( false === has_action( 'admin_init', [ \BingoCommerce\PortalIntegration\Migrations::class, 'maybe_migrate_network' ] ) ) {
	\BingoCommerce\PortalIntegration\Plugin::init();
}

use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\Migrations;
use BingoCommerce\PortalIntegration\PortalConfig;

final class PortalIntegrationFoundationTest {

	/** @var array<int,bool> */
	private array $disposable_sites = [];

	/** @var array<int,array<int,string>> */
	private array $disposable_tables = [];

	/** @var array<int,array<int,string>> */
	private array $deleted_site_tables = [];

	/** @var array<int,int> */
	private array $portal_hall_ids = [];

	/** @var array<string,string> */
	private array $persistent_domain_snapshot = [];

	private int $assertions = 0;
	private int $super_admin_id = 0;

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->super_admin_id = $this->find_super_admin_id();
		$this->assert_true( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		$this->persistent_domain_snapshot = $this->snapshot_domain_tables( $this->current_site_ids() );

		try {
			$this->migration_schema();
			$this->configuration();
			$this->mapping_behavior();
		} finally {
			$this->cleanup();
		}

		$this->assert_same(
			$this->persistent_domain_snapshot,
			$this->snapshot_domain_tables( $this->current_site_ids() ),
			'existing per-site Catalog and Session rows are byte-identical after the suite'
		);
		$this->assert_same( [], $this->disposable_sites, 'every disposable site created by this run was destroyed' );
		$this->verify_disposable_cleanup();

		echo "PASS: {$this->assertions} Portal integration foundation assertions.\n";
	}

	private function migration_schema(): void {
		global $wpdb;
		$plugin_file = dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
		$headers     = get_file_data( $plugin_file, [ 'network' => 'Network', 'version' => 'Version' ] );

		$this->assert_same( 'true', strtolower( (string) $headers['network'] ), 'plugin declares network-only activation' );
		$this->assert_same( '0.1.0', (string) $headers['version'], 'plugin foundation version is explicit' );
		$this->assert_true(
			false !== has_action( 'admin_init', [ Migrations::class, 'maybe_migrate_network' ] ),
			'network migration hook is registered'
		);
		$this->assert_true( true === Migrations::migrate_network(), 'network mapping migration succeeds' );
		$this->assert_true( true === Migrations::verify_schema(), 'network mapping schema verifies exactly' );
		$this->assert_true( Migrations::is_current(), 'network mapping schema version is current' );
		$this->assert_true( true === Migrations::migrate_network(), 'network mapping migration rerun is idempotent' );
		$this->assert_same(
			$wpdb->base_prefix . 'bingo_portal_hall_mappings',
			Migrations::hall_mappings_table(),
			'mapping table uses the approved network-wide name'
		);

		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM `' . Migrations::hall_mappings_table() . '`' );
		$this->assert_same(
			[ 'portal_hall_id', 'blog_id', 'status', 'created_at', 'updated_at' ],
			$columns,
			'mapping table has only the approved logical fields'
		);

		$indexes = $wpdb->get_results( 'SHOW INDEX FROM `' . Migrations::hall_mappings_table() . '`', ARRAY_A );
		$shape   = [];
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
				'PRIMARY'        => [ 'unique' => true, 'columns' => [ 'portal_hall_id' ] ],
				'blog_id_unique' => [ 'unique' => true, 'columns' => [ 'blog_id' ] ],
			],
			$shape,
			'Portal Hall ID and WordPress site ID are independently unique'
		);
	}

	private function configuration(): void {
		if ( ! defined( 'BINGOCOMMERCE_PORTAL_BASE_URL' ) ) {
			$missing = PortalConfig::fromConstants();
			$this->assert_error( 'portal_base_url_missing', $missing, 'missing Portal base URL fails closed' );
			define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'http://portal.bingocommerce.test/root/' );
		}

		if ( ! defined( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' ) ) {
			$missing = PortalConfig::fromConstants();
			$this->assert_error( 'portal_integration_token_missing', $missing, 'missing Portal token fails closed' );
			define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'phase-3b-disposable-token' );
		}

		$config = PortalConfig::fromConstants();
		$this->assert_true( $config instanceof PortalConfig, 'valid server constants produce Portal configuration' );
		if ( ! $config instanceof PortalConfig ) {
			return;
		}

		$this->assert_true( '' !== $config->baseUrl(), 'Portal base URL is available only through the server abstraction' );
		$this->assert_true( '' !== $config->integrationToken(), 'Portal token is available only through the server abstraction' );
		$debug = print_r( $config, true );
		$this->assert_true( false === strpos( $debug, $config->integrationToken() ), 'generic object debugging redacts the Portal token' );
		$this->assert_true( false !== strpos( $debug, '[REDACTED]' ), 'generic object debugging marks the token as redacted' );
		$this->assert_true( false === strpos( serialize( $config ), $config->integrationToken() ), 'serialized configuration state does not contain the Portal token' );
		$this->assert_true( false === strpos( var_export( $config, true ), $config->integrationToken() ), 'exported configuration state does not contain the Portal token' );
	}

	private function mapping_behavior(): void {
		global $wpdb;

		$site_a = $this->create_disposable_site( 'A' );
		$site_b = $this->create_disposable_site( 'B' );
		$this->assert_true( $site_a > 0 && $site_b > 0 && $site_a !== $site_b, 'two distinct disposable WordPress sites exist' );

		$disposable_snapshot = $this->snapshot_domain_tables( [ $site_a, $site_b ] );
		$repository          = new HallMappingRepository();
		$hall_a              = $this->new_portal_hall_id();
		$hall_b              = $this->new_portal_hall_id();
		$hall_c              = $this->new_portal_hall_id();
		$main_site           = get_main_site_id( get_current_network_id() );

		$this->assert_error(
			'main_site_not_allowed',
			$repository->bind( $this->new_portal_hall_id(), $main_site ),
			'mapping to the main network site is rejected'
		);

		$nonexistent = (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM {$wpdb->blogs}" ) + 1000000;
		$this->assert_true( null === get_site( $nonexistent ), 'nonexistent-site fixture is absent' );
		$this->assert_error(
			'site_not_found',
			$repository->bind( $this->new_portal_hall_id(), $nonexistent ),
			'mapping to a nonexistent site is rejected'
		);

		$this->set_site_flag( $site_b, 'deleted', 1 );
		$this->assert_error( 'site_deleted', $repository->bind( $this->new_portal_hall_id(), $site_b ), 'mapping to a deleted site is rejected' );
		$this->set_site_flag( $site_b, 'deleted', 0 );

		$this->set_site_flag( $site_b, 'archived', 1 );
		$this->assert_error( 'site_archived', $repository->bind( $this->new_portal_hall_id(), $site_b ), 'mapping to an archived site is rejected' );
		$this->set_site_flag( $site_b, 'archived', 0 );

		$this->set_site_flag( $site_b, 'spam', 1 );
		$this->assert_error( 'site_spam', $repository->bind( $this->new_portal_hall_id(), $site_b ), 'mapping to a spam site is rejected' );
		$this->set_site_flag( $site_b, 'spam', 0 );

		$mapping_a = $repository->bind( $hall_a, $site_a );
		$this->assert_mapping( $hall_a, $site_a, HallMappingRepository::STATUS_ACTIVE, $mapping_a, 'Portal Hall A maps to Site A' );
		$mapping_b = $repository->bind( $hall_b, $site_b );
		$this->assert_mapping( $hall_b, $site_b, HallMappingRepository::STATUS_ACTIVE, $mapping_b, 'Portal Hall B maps to Site B' );

		$this->assert_mapping( $hall_a, $site_a, HallMappingRepository::STATUS_ACTIVE, $repository->findByPortalHallId( $hall_a ), 'lookup by Portal Hall returns Site A' );
		$this->assert_mapping( $hall_a, $site_a, HallMappingRepository::STATUS_ACTIVE, $repository->findByBlogId( $site_a ), 'lookup by Site A returns Portal Hall A' );
		$this->assert_error( 'portal_hall_already_mapped', $repository->bind( $hall_a, $site_b ), 'duplicate Portal Hall mapping is rejected' );
		$this->assert_error( 'blog_already_mapped', $repository->bind( $hall_c, $site_a ), 'duplicate WordPress site mapping is rejected' );
		$this->set_site_flag( $site_b, 'spam', 1 );
		$this->assert_error( 'site_spam', $repository->findActiveByPortalHallId( $hall_b ), 'an active row for an ineligible site fails closed at lookup' );
		$this->set_site_flag( $site_b, 'spam', 0 );

		$disabled = $repository->disable( $hall_a );
		$this->assert_mapping( $hall_a, $site_a, HallMappingRepository::STATUS_DISABLED, $disabled, 'mapping A can be disabled' );
		$this->assert_same( null, $repository->findActiveByPortalHallId( $hall_a ), 'disabled mapping is not active by Portal Hall lookup' );
		$this->assert_same( null, $repository->findActiveByBlogId( $site_a ), 'disabled mapping is not active by site lookup' );
		$this->assert_mapping( $hall_b, $site_b, HallMappingRepository::STATUS_ACTIVE, $repository->findActiveByPortalHallId( $hall_b ), 'disabling mapping A does not affect mapping B' );
		$this->assert_error( 'mapping_disabled', $repository->bind( $hall_a, $site_a ), 'ordinary bind does not silently re-enable a disabled mapping' );

		$enabled = $repository->enable( $hall_a );
		$this->assert_mapping( $hall_a, $site_a, HallMappingRepository::STATUS_ACTIVE, $enabled, 'disabled mapping can be explicitly re-enabled' );

		$this->assert_same(
			$disposable_snapshot,
			$this->snapshot_domain_tables( [ $site_a, $site_b ] ),
			'mapping operations do not modify disposable-site Catalog or Session data'
		);
		$this->assert_same(
			$this->persistent_domain_snapshot,
			$this->snapshot_domain_tables( array_diff( $this->current_site_ids(), [ $site_a, $site_b ] ) ),
			'mapping operations do not modify existing-site Catalog or Session data'
		);
	}

	private function create_disposable_site( string $label ): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'portal3b' . wp_generate_password( 8, false, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'Portal 3B Disposable Hall ' . $label,
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			throw new \RuntimeException( 'Disposable site could not be created.' );
		}

		$site_id = (int) $site;
		$this->disposable_sites[ $site_id ] = true;
		$this->disposable_tables[ $site_id ] = $this->domain_table_names( $site_id );

		return $site_id;
	}

	private function set_site_flag( int $site_id, string $flag, int $value ): void {
		if ( empty( $this->disposable_sites[ $site_id ] ) || ! in_array( $flag, [ 'deleted', 'archived', 'spam' ], true ) ) {
			throw new \RuntimeException( 'Refusing to mutate a non-disposable site flag.' );
		}

		$result = update_blog_status( $site_id, $flag, $value );
		clean_blog_cache( $site_id );
		$this->assert_true( false !== $result, "disposable site {$flag} flag can be set to {$value}" );
	}

	private function new_portal_hall_id(): int {
		global $wpdb;

		do {
			$id = random_int( 1000000000, 2000000000 );
		} while (
			in_array( $id, $this->portal_hall_ids, true )
			|| null !== $wpdb->get_var( $wpdb->prepare( 'SELECT portal_hall_id FROM ' . Migrations::hall_mappings_table() . ' WHERE portal_hall_id = %d', $id ) )
		);

		$this->portal_hall_ids[] = $id;

		return $id;
	}

	/** @return array<int,int> */
	private function current_site_ids(): array {
		return array_map(
			static fn( \WP_Site $site ): int => (int) $site->blog_id,
			get_sites( [ 'number' => 0 ] )
		);
	}

	/** @return array<int,string> */
	private function domain_table_names( int $site_id ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $site_id );

		return array_map(
			static fn( string $suffix ): string => $prefix . $suffix,
			[
				'bingo_game_types',
				'bingo_play_products',
				'bingo_packages',
				'bingo_package_components',
				'bingo_package_addons',
				'bingo_sessions',
				'bingo_session_packages',
				'bingo_portal_session_templates',
			]
		);
	}

	/** @param array<int,int> $site_ids
	 *  @return array<string,string>
	 */
	private function snapshot_domain_tables( array $site_ids ): array {
		global $wpdb;

		$snapshot = [];
		foreach ( $site_ids as $site_id ) {
			foreach ( $this->domain_table_names( (int) $site_id ) as $table ) {
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
				if ( $exists !== $table ) {
					continue;
				}

				$primary_key = $this->domain_primary_key( $table );
				$rows        = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY `{$primary_key}`", ARRAY_A );
				$snapshot[ $table ] = hash( 'sha256', serialize( $rows ) );
			}
		}
		ksort( $snapshot );

		return $snapshot;
	}

	private function domain_primary_key( string $table ): string {
		$keys = [
			'bingo_game_types'          => 'game_type_id',
			'bingo_play_products'       => 'bingo_play_product_id',
			'bingo_packages'            => 'package_id',
			'bingo_package_components' => 'package_component_id',
			'bingo_package_addons'      => 'package_addon_id',
			'bingo_sessions'            => 'session_id',
			'bingo_session_packages'    => 'session_package_id',
			'bingo_portal_session_templates' => 'session_template_id',
		];

		foreach ( $keys as $suffix => $primary_key ) {
			if ( str_ends_with( $table, $suffix ) ) {
				return $primary_key;
			}
		}

		throw new \RuntimeException( 'Refusing to snapshot an unknown domain table.' );
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

	private function cleanup(): void {
		global $wpdb;

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			if ( empty( $this->disposable_sites[ $site_id ] ) ) {
				continue;
			}

			foreach ( [ 'deleted', 'archived', 'spam' ] as $flag ) {
				update_blog_status( $site_id, $flag, 0 );
			}
			$wpdb->delete( Migrations::hall_mappings_table(), [ 'blog_id' => $site_id ], [ '%d' ] );
			wp_delete_site( $site_id );

			$allowed = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . $site_id . '_bingo_(game_types|play_products|packages|package_components|package_addons|sessions|session_packages|portal_session_templates)$/';
			foreach ( $this->disposable_tables[ $site_id ] ?? [] as $table ) {
				if ( 1 === preg_match( $allowed, $table ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}

			/*
			 * wp_delete_site() removes the site row, its blogmeta and its
			 * per-site tables, but `registration_log` is a Multisite-GLOBAL table
			 * that no core deletion path touches — so every disposable hall
			 * would otherwise leave a permanent row behind and this suite could
			 * not prove zero residue. Scoped to this one blog_id, which the
			 * guard above proves is a disposable site created by this run.
			 */
			$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $site_id ], [ '%d' ] );

			$this->deleted_site_tables[ $site_id ] = $this->disposable_tables[ $site_id ] ?? [];
			unset( $this->disposable_sites[ $site_id ], $this->disposable_tables[ $site_id ] );
		}
	}

	private function verify_disposable_cleanup(): void {
		global $wpdb;

		foreach ( $this->deleted_site_tables as $site_id => $tables ) {
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $site_id ) ),
				"disposable site {$site_id} record was removed"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogmeta} WHERE blog_id = %d", $site_id ) ),
				"disposable site {$site_id} metadata was removed"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::hall_mappings_table() . ' WHERE blog_id = %d', $site_id ) ),
				"disposable site {$site_id} mapping was removed"
			);
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->registration_log} WHERE blog_id = %d", $site_id ) ),
				"disposable site {$site_id} registration log rows were removed"
			);

			foreach ( $tables as $table ) {
				$this->assert_same(
					0,
					(int) $wpdb->get_var(
						$wpdb->prepare(
							'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
							DB_NAME,
							$table
						)
					),
					"disposable site {$site_id} table {$table} was removed"
				);
			}
		}
	}

	/** @param mixed $actual */
	private function assert_error( string $code, $actual, string $label ): void {
		$this->assert_true( is_wp_error( $actual ) && $code === $actual->get_error_code(), $label );
	}

	/** @param mixed $actual */
	private function assert_mapping( int $portal_hall_id, int $blog_id, string $status, $actual, string $label ): void {
		$this->assert_true(
			is_array( $actual )
			&& $portal_hall_id === ( $actual['portal_hall_id'] ?? null )
			&& $blog_id === ( $actual['blog_id'] ?? null )
			&& $status === ( $actual['status'] ?? null ),
			$label
		);
	}

	/** @param mixed $expected
	 *  @param mixed $actual
	 */
	private function assert_same( $expected, $actual, string $label ): void {
		$this->assert_true( $expected === $actual, $label );
	}

	private function assert_true( bool $condition, string $label ): void {
		++$this->assertions;
		if ( ! $condition ) {
			throw new \RuntimeException( "Assertion {$this->assertions} failed: {$label}" );
		}
	}
}

try {
	( new PortalIntegrationFoundationTest() )->run();
} catch ( \Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
