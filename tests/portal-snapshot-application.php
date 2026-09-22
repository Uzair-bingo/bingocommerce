<?php

/**
 * CLI integration coverage for Phase 3E Portal snapshot application (ADR-040).
 *
 * Every scenario runs against DISPOSABLE halls created and destroyed by this
 * run, bound to Portal Hall IDs 990001-990006 that exist in no Portal
 * environment.
 *
 * Sunrise, Tampa, and Orlando ARE read: the suite selects their rows and schemas
 * before and after the run to hash them into before/after snapshots. That read is
 * their only role. No persistent hall supplies fixture input, no scenario
 * synchronizes or otherwise exercises one, and none is modified — the closing
 * assertions prove their rows, schemas, and WooCommerce state are byte-identical
 * to the opening snapshot.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_test_suites php tests/portal-snapshot-application.php
 *
 * THE DATABASE NAME IS NOW REQUIRED (ADR-043 §16 amendment). This suite creates
 * disposable Multisite SITES, and `bingocommerce-sessions` is network-active, so
 * every wp_insert_site() fires its `wp_initialize_site` handler and provisions
 * the CURRENT Sessions schema into whichever database is booted. Once ADR-043
 * raised that schema to v3, the previous invocation — plain
 * `php tests/portal-snapshot-application.php`, which fell through the shared bootstrap to
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

if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( ! class_exists( '\BingoCommerce\Sessions\Migrations' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/bingocommerce-sessions.php';
}
if ( ! class_exists( '\BingoCommerce\PortalIntegration\PortalSyncService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Sessions\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Sessions\Plugin::init();
}

use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\Migrations as PortalMigrations;
use BingoCommerce\PortalIntegration\PortalSnapshotTransactionTransport;
use BingoCommerce\PortalIntegration\PortalSyncResult;
use BingoCommerce\PortalIntegration\PortalSyncService;
use BingoCommerce\PortalIntegration\PortalSyncState;

final class PortalSnapshotApplicationTest {

	private const PERSISTENT_HALLS = [ 2, 3, 4 ];

	private const HALL_A = 990001;
	private const HALL_B = 990002;
	private const HALL_C = 990003;

	/** Dedicated to the release-failure scenarios, each of which fails its hall closed. */
	private const HALL_D = 990004;
	private const HALL_E = 990005;
	private const HALL_F = 990006;

	/**
	 * ADR-043 §16 test 100 runs on its OWN hall.
	 *
	 * The scenario imports a Game Type and a Session template and then has no way
	 * to take them back: a later snapshot that OMITTED an already-imported Game
	 * Type would be refused with `portal_game_type_missing`, which is correct
	 * transport behaviour and not something a test should work around. A private
	 * hall is destroyed in cleanup, so nothing needs undoing.
	 */
	private const HALL_G = 990007;

	/** @var array<int,bool> */
	private array $disposable_sites = [];

	/** @var array<int,array<int,string>> */
	private array $deleted_site_tables = [];

	/** @var array<int,bool> */
	private array $bound_mappings = [];

	/** @var array<string,string> */
	private array $persistent_domain_rows = [];

	/** @var array<string,string> */
	private array $persistent_catalog_schema = [];

	/** @var array<string,string> */
	private array $persistent_woo_rows = [];

	/** @var array<string,mixed> */
	private array $mapping_snapshot = [];

	private int $assertions    = 0;
	private int $super_admin_id = 0;
	private int $fetch_count   = 0;

	/** @var array<int,string> statements observed while `observing` is true */
	private array $observed_sql = [];
	private bool $observing     = false;

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->assert_true( PortalMigrations::is_current(), 'Portal Hall mapping schema is current' );
		$this->super_admin_id = $this->find_super_admin_id();
		$this->assert_true( $this->super_admin_id > 0, 'native Super Admin fixture exists' );

		$this->persistent_domain_rows    = $this->snapshot_rows( self::PERSISTENT_HALLS, $this->domain_table_suffixes() );
		$this->persistent_catalog_schema = $this->snapshot_schema( self::PERSISTENT_HALLS, $this->catalog_table_suffixes() );
		$this->persistent_woo_rows       = $this->snapshot_rows( self::PERSISTENT_HALLS, $this->woo_table_suffixes() );
		$this->mapping_snapshot          = $this->snapshot_mapping();

		try {
			$a = $this->provision_hall( 'A', self::HALL_A );
			$b = $this->provision_hall( 'B', self::HALL_B );
			$c = $this->provision_hall( 'C', self::HALL_C );

			$this->failures_leave_no_marker( $a );
			$this->first_synchronization( $a );
			$this->repeated_synchronization( $a );
			$this->portal_rename( $a );
			$this->session_absence_and_reappearance( $a );
			$this->ordering( $a );
			$this->wrong_hall_rejection( $a );
			$this->code_collision( $a );
			$this->missing_game_type_rollback( $c );
			$this->code_exhaustion_rollback( $c );
			$this->database_failure_rollback( $c );
			$this->mapping_changed_during_synchronization( $c );
			$this->no_is_wp_error_callbacks_mid_transaction( $c );
			$this->malformed_sync_state_marker( $c );
			$this->wrong_hall_sync_state_marker( $c );
			$this->innodb_requirement( $c );
			$this->sessions_v2_synchronization( $this->provision_hall( 'G', self::HALL_G ) );
			$this->tenant_isolation( $a, $b );
			// After tenant_isolation, so the different-hall attempt nested inside the
			// outer fetch is a genuine idempotent no-op rather than a first import.
			$this->nested_same_hall_synchronization( $a, $b );
			$this->concurrent_same_hall_synchronization( $a, $b );
			// Last on hall A: its snapshot is the newest in the chain, so it cannot
			// make an earlier scenario's fixture stale.
			$this->reentrancy_during_get_lock_query( $a );
			$this->commerce_untouched( $a );
			// Last, and on its own hall: this scenario deliberately leaves hall D
			// refused for the rest of the request.
			$this->unconfirmed_release_keeps_reservation( $this->provision_hall( 'D', self::HALL_D ), $c );
			$this->release_failure_keeps_reservation( $this->provision_hall( 'E', self::HALL_E ), self::HALL_E, 'connection_id_unavailable', $c );
			$this->release_failure_keeps_reservation( $this->provision_hall( 'F', self::HALL_F ), self::HALL_F, 'release_throws', $c );
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
		$this->assert_same( $this->mapping_snapshot, $this->snapshot_mapping(), 'Portal Hall mapping rows are byte-identical after the suite' );
		$this->assert_same( [], $this->disposable_sites, 'every disposable hall created by this run was destroyed' );
		$this->verify_disposable_cleanup();

		echo "PASS: {$this->assertions} Phase 3E Portal snapshot application assertions.\n";
	}

	/* ------------------------------------------------------------------ *
	 * Scenarios
	 * ------------------------------------------------------------------ */

	/** Nothing before a committed application may leave a sync-state row behind. */
	private function failures_leave_no_marker( int $site_id ): void {
		$this->assert_same( [], $this->marker_row( $site_id ), 'a freshly provisioned hall has no sync-state marker' );

		$transport_error = $this->sync( $site_id, new \WP_Error( 'portal_http_error', 'upstream' ) );
		$this->assert_true( is_wp_error( $transport_error ), 'an HTTP failure fails the synchronization' );
		$this->assert_same( 'portal_http_error', $transport_error->get_error_code(), 'the transport error code reaches the caller unchanged' );
		$this->assert_same( [], $this->marker_row( $site_id ), 'an HTTP failure writes no sync-state row' );
		$this->assert_same( [], $this->game_type_rows( $site_id ), 'an HTTP failure writes no Game Type row' );

		$invalid = $this->sync( $site_id, '{"api_version":"v2"' );
		$this->assert_same( 'portal_snapshot_invalid_json', $invalid->get_error_code(), 'an unparseable body is rejected as invalid JSON' );
		$this->assert_same( [], $this->marker_row( $site_id ), 'a JSON failure writes no sync-state row' );

		$unsupported = $this->sync( $site_id, $this->body( self::HALL_A, '2026-09-08T10:00:00-04:00', [], [], 'v2' ) );
		$this->assert_same( 'portal_snapshot_unsupported_version', $unsupported->get_error_code(), 'an unsupported api_version is rejected' );
		$this->assert_same( [], $this->marker_row( $site_id ), 'a validation failure writes no sync-state row' );

		$extra = $this->sync( $site_id, '{"api_version":"v1","generated_at":"2026-09-08T10:00:00-04:00","hall":{"id":990001,"name":"H","state":"active","updated_at":null},"game_types":[],"sessions":[],"extra":1}' );
		$this->assert_same( 'portal_snapshot_invalid_contract', $extra->get_error_code(), 'an unknown envelope member is rejected' );
		$this->assert_same( [], $this->marker_row( $site_id ), 'a contract failure writes no sync-state row' );
	}

	private function first_synchronization( int $site_id ): void {
		// Two DIFFERENT Portal identities carrying the SAME name, which ADR-039
		// requires to stay separate rows.
		$body = $this->body(
			self::HALL_A,
			'2026-09-08T10:00:00-04:00',
			[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball' ] ],
			[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
		);

		$result = $this->sync( $site_id, $body );
		$this->assert_true( $result instanceof PortalSyncResult, 'the first synchronization succeeds' );
		$this->assert_same( 1, $this->fetch_count, 'the first synchronization performs exactly one Portal fetch' );
		$this->assert_same( PortalSyncResult::APPLIED, $result->status, 'the result reports an applied snapshot' );
		$this->assert_same(
			[
				'game_types_created'                   => 3,
				'game_types_renamed'                   => 0,
				'session_templates_created'            => 2,
				'session_templates_updated'            => 0,
				'session_templates_marked_not_current' => 0,
			],
			$result->counts(),
			'the first synchronization reports exactly the rows it created'
		);

		$game_types = $this->game_type_rows( $site_id );
		$this->assert_same( 3, count( $game_types ), 'three Game Type rows exist after the first synchronization' );
		$this->assert_same( [ 4001, 4002, 4003 ], array_map( 'intval', array_column( $game_types, 'portal_game_type_id' ) ), 'every Portal Game Type identity is linked' );
		$this->assert_same(
			[ 'portal-gt-4001', 'portal-gt-4002', 'portal-gt-4003' ],
			array_column( $game_types, 'code' ),
			'imported codes use the approved portal-gt-<id> family'
		);
		$this->assert_same( [ 'Regular', 'Regular', 'Speedball' ], array_column( $game_types, 'name' ), 'duplicate Portal names remain separate rows' );
		$this->assert_same( [ null, null, null ], array_column( $game_types, 'local_name_key' ), 'Portal-linked rows store a NULL local name key' );
		$this->assert_same( [ 'active', 'active', 'active' ], array_column( $game_types, 'status' ), 'imported Game Types start active' );
		$this->assert_same( [ null, null, null ], array_column( $game_types, 'archived_at' ), 'imported Game Types are not archived' );

		$templates = $this->template_rows( $site_id );
		$this->assert_same( [ 7001, 7002 ], array_map( 'intval', array_column( $templates, 'portal_session_id' ) ), 'both Portal Session templates are replicated' );
		$this->assert_same( [ 'active', 'draft' ], array_column( $templates, 'portal_status' ), 'Portal status is stored as the source attribute' );
		$this->assert_same( [ '1', '1' ], array_column( $templates, 'is_current' ), 'a present draft template is still current' );
		$this->assert_same( [ null, null ], array_column( $templates, 'not_current_at' ), 'present templates carry no not_current_at' );

		$marker = $this->marker_row( $site_id );
		$this->assert_same( 'off', (string) ( $marker['autoload'] ?? '' ), 'the sync-state marker is stored non-autoloaded' );
		$decoded = PortalSyncState::decode( (string) ( $marker['option_value'] ?? '' ) );
		$this->assert_same( '2026-09-08T10:00:00-04:00', $decoded['generated_at'] ?? '', 'the marker stores generated_at verbatim' );
		$this->assert_same( self::HALL_A, $decoded['portal_hall_id'] ?? 0, 'the marker records the trusted Portal Hall ID' );
	}

	private function repeated_synchronization( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$before_templates  = $this->template_rows( $site_id );

		$body = $this->body(
			self::HALL_A,
			'2026-09-08T10:00:00-04:00',
			[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball' ] ],
			[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
		);

		$result = $this->sync( $site_id, $body );
		$this->assert_true( $result instanceof PortalSyncResult, 'an identical second synchronization succeeds' );
		$this->assert_true( $result->isNoOp(), 'an identical second synchronization reports no row changes' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'an identical snapshot leaves every Game Type row byte-identical' );
		$this->assert_same( $before_templates, $this->template_rows( $site_id ), 'an identical snapshot leaves every Session template row byte-identical' );
	}

	private function portal_rename( int $site_id ): void {
		$before = $this->game_type_row_by_portal_id( $site_id, 4003 );

		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T11:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_true( $result instanceof PortalSyncResult, 'a Portal rename synchronization succeeds' );
		$this->assert_same( 1, $result->game_types_renamed, 'exactly one Game Type name is updated' );
		$this->assert_same( 0, $result->game_types_created, 'a rename creates no Game Type row' );

		$after = $this->game_type_row_by_portal_id( $site_id, 4003 );
		$this->assert_same( 'Speedball Deluxe', (string) $after['name'], 'the Portal rename updates the source name' );
		$this->assert_same( $before['game_type_id'], $after['game_type_id'], 'a Portal rename preserves the local surrogate ID' );
		$this->assert_same( $before['code'], $after['code'], 'a Portal rename preserves the immutable imported code' );
		$this->assert_same( $before['created_at'], $after['created_at'], 'a Portal rename preserves created_at' );
		$this->assert_same( $before['status'], $after['status'], 'a Portal rename preserves local status' );
		$this->assert_same( null, $after['local_name_key'], 'a renamed Portal-linked row keeps a NULL local name key' );

		// A local archive must survive the next pull: status is local, the name is
		// Portal's. This is the transitional split ADR-035 leaves in place until the
		// deferred local writer-restriction decision.
		$this->update_game_type_status( $site_id, (int) $after['game_type_id'], 'archived' );
		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T11:05:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_true( $result instanceof PortalSyncResult, 'synchronization succeeds against a locally archived linked row' );
		$archived = $this->game_type_row_by_portal_id( $site_id, 4003 );
		$this->assert_same( 'archived', (string) $archived['status'], 'local archived status is preserved across synchronization' );

		// A local rename of a linked row is NOT blocked in this increment. Portal
		// remains authoritative and restores the Portal-owned name on the next pull.
		$this->update_game_type_name( $site_id, (int) $archived['game_type_id'], 'Locally Renamed' );
		$this->assert_same( 'Locally Renamed', (string) $this->game_type_row_by_portal_id( $site_id, 4003 )['name'], 'a local rename of a linked row is permitted in this increment' );
		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T11:10:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_same( 1, $result->game_types_renamed, 'the next pull rewrites the locally renamed row' );
		$restored = $this->game_type_row_by_portal_id( $site_id, 4003 );
		$this->assert_same( 'Speedball Deluxe', (string) $restored['name'], 'Portal synchronization restores the Portal-owned name' );
		$this->assert_same( 'archived', (string) $restored['status'], 'restoring the Portal name still preserves local archived status' );

		$this->update_game_type_status( $site_id, (int) $restored['game_type_id'], 'active' );
	}

	private function session_absence_and_reappearance( int $site_id ): void {
		$before = $this->template_row_by_portal_id( $site_id, 7002 );

		// 7002 disappears.
		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T12:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ] ]
			)
		);
		$this->assert_same( 1, $result->session_templates_marked_not_current, 'a missing template is marked not current' );
		$absent = $this->template_row_by_portal_id( $site_id, 7002 );
		$this->assert_true( [] !== $absent, 'a missing template row is never deleted' );
		$this->assert_same( '0', (string) $absent['is_current'], 'a missing template becomes not current' );
		$this->assert_true( null !== $absent['not_current_at'], 'a missing template records when it went missing' );
		$this->assert_same( $before['created_at'], $absent['created_at'], 'created_at survives the absence transition' );
		$this->assert_same( $before['name'], $absent['name'], 'the last known Portal name is preserved while absent' );
		$this->assert_same( $before['portal_status'], $absent['portal_status'], 'the last known Portal status is preserved while absent' );
		$first_transition = (string) $absent['not_current_at'];

		// Repeated absence must not re-stamp the timestamp or touch the row.
		sleep( 1 );
		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T12:30:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ] ]
			)
		);
		$this->assert_same( 0, $result->session_templates_marked_not_current, 'a template that is already not current is not rewritten' );
		$this->assert_same( $absent, $this->template_row_by_portal_id( $site_id, 7002 ), 'a repeated absence leaves the row byte-identical' );
		$this->assert_same( $first_transition, (string) $this->template_row_by_portal_id( $site_id, 7002 )['not_current_at'], 'repeated absence never replaces not_current_at' );

		// 7002 reappears.
		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T13:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_same( 1, $result->session_templates_updated, 'a reappearing template is updated' );
		$restored = $this->template_row_by_portal_id( $site_id, 7002 );
		$this->assert_same( '1', (string) $restored['is_current'], 'a reappearing template becomes current again' );
		$this->assert_same( null, $restored['not_current_at'], 'a reappearing template clears not_current_at' );
		$this->assert_same( $before['created_at'], $restored['created_at'], 'created_at survives the reappearance' );
		$this->assert_same( $before['session_template_id'], $restored['session_template_id'], 'the local replica surrogate ID is preserved across absence and reappearance' );

		// A LATER absence must start a NEW transition rather than reuse the first.
		sleep( 1 );
		$this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T14:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ] ]
			)
		);
		$second = $this->template_row_by_portal_id( $site_id, 7002 );
		$this->assert_true( null !== $second['not_current_at'], 'a later absence stamps not_current_at again' );
		$this->assert_true( (string) $second['not_current_at'] !== $first_transition, 'a later absence starts a new not_current_at transition' );

		// Return the hall to a fully current state for the scenarios that follow.
		$this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T15:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_same( null, $this->template_row_by_portal_id( $site_id, 7002 )['not_current_at'], 'the hall returns to a fully current template state' );
	}

	private function ordering( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$before_templates  = $this->template_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );


		$stale = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T09:00:00-04:00',
				[ [ 4001, 'Stale Name' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_true( is_wp_error( $stale ), 'a strictly older snapshot is rejected' );
		$this->assert_same( 'portal_snapshot_stale', $stale->get_error_code(), 'the stale rejection uses its own error code' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'a stale snapshot writes no Game Type row' );
		$this->assert_same( $before_templates, $this->template_rows( $site_id ), 'a stale snapshot writes no Session template row' );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'a stale snapshot leaves the marker untouched' );

		// Same INSTANT, different offset: ordering must compare instants, not text.
		$equivalent = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T19:00:00+00:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_true( $equivalent instanceof PortalSyncResult, 'an equal instant expressed in a different offset applies' );
		$this->assert_true( $equivalent->isNoOp(), 'the equal-instant snapshot changes no row' );

		$equal = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T15:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ], [ 4004, 'Bonanza' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_true( $equal instanceof PortalSyncResult, 'an equal generated_at applies rather than being rejected' );
		$this->assert_same( 1, $equal->game_types_created, 'a real change carried by an equal generated_at is applied' );
		$this->assert_same( 'portal-gt-4004', (string) $this->game_type_row_by_portal_id( $site_id, 4004 )['code'], 'the equal-timestamp import allocates its own code' );
	}

	private function wrong_hall_rejection( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$foreign_envelope = $this->sync( $site_id, $this->body( self::HALL_B, '2026-09-08T16:00:00-04:00', [ [ 4001, 'Regular' ] ], [] ) );
		$this->assert_same( 'portal_snapshot_hall_mismatch', $foreign_envelope->get_error_code(), 'a snapshot describing another Portal Hall is rejected' );

		// Correct envelope, foreign child record.
		$mixed = json_encode(
			[
				'api_version'  => 'v1',
				'generated_at' => '2026-09-08T16:00:00-04:00',
				'hall'         => [ 'id' => self::HALL_A, 'name' => 'Test Portal Hall', 'state' => 'active', 'updated_at' => null ],
				'game_types'   => [ [ 'id' => 4001, 'hall_id' => self::HALL_B, 'name' => 'Regular', 'updated_at' => null ] ],
				'sessions'     => [],
			]
		);
		$foreign_child = $this->sync( $site_id, (string) $mixed );
		$this->assert_same( 'portal_snapshot_hall_mismatch', $foreign_child->get_error_code(), 'a record owned by another Portal Hall is rejected' );

		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'wrong-Hall data writes no Game Type row' );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'wrong-Hall data leaves the marker untouched' );
	}

	private function code_collision( int $site_id ): void {
		// A LOCAL row already occupying the first candidate for Portal ID 4100.
		$local_id = $this->insert_local_game_type( $site_id, 'portal-gt-4100', 'Locally Named Collision' );
		$before   = $this->game_type_row_by_id( $site_id, $local_id );

		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_A,
				'2026-09-08T17:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ], [ 4004, 'Bonanza' ], [ 4100, 'Collision Import' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_true( $result instanceof PortalSyncResult, 'an import whose first code candidate is taken still succeeds' );
		$imported = $this->game_type_row_by_portal_id( $site_id, 4100 );
		$this->assert_same( 'portal-gt-4100-2', (string) $imported['code'], 'a code collision advances to the next approved candidate' );
		$this->assert_same( 'Collision Import', (string) $imported['name'], 'the imported row keeps its Portal name' );
		$this->assert_same( $before, $this->game_type_row_by_id( $site_id, $local_id ), 'the colliding local row is never adopted, renamed, or overwritten' );
		$this->assert_same( null, $this->game_type_row_by_id( $site_id, $local_id )['portal_game_type_id'], 'a matching code never links a local row to a Portal identity' );
	}

	private function missing_game_type_rollback( int $site_id ): void {
		$first = $this->sync(
			$site_id,
			$this->body(
				self::HALL_C,
				'2026-09-08T10:00:00-04:00',
				[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ] ],
				[ [ 8001, 'Hall C Session', 'active' ] ]
			)
		);
		$this->assert_true( $first instanceof PortalSyncResult, 'hall C first synchronization succeeds' );

		$before_game_types = $this->game_type_rows( $site_id );
		$before_templates  = $this->template_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$missing = $this->sync(
			$site_id,
			$this->body(
				self::HALL_C,
				'2026-09-08T11:00:00-04:00',
				[ [ 5001, 'Regular' ], [ 5003, 'Newly Added' ] ],
				[ [ 8001, 'Hall C Session', 'active' ], [ 8002, 'Second Session', 'active' ] ]
			)
		);
		$this->assert_true( is_wp_error( $missing ), 'a snapshot omitting a linked Game Type is rejected' );
		$this->assert_same( 'portal_game_type_missing', $missing->get_error_code(), 'the omission is reported as a Game Type integrity violation' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'the omission performs zero Game Type writes' );
		$this->assert_same( $before_templates, $this->template_rows( $site_id ), 'the omission performs zero Session template writes' );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'the omission leaves the marker untouched' );
		$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5003 ), 'the new Game Type in a rejected snapshot is never created' );
		$this->assert_same( [], $this->template_row_by_portal_id( $site_id, 8002 ), 'the new Session template in a rejected snapshot is never created' );

		$empty = $this->sync( $site_id, $this->body( self::HALL_C, '2026-09-08T11:30:00-04:00', [], [] ) );
		$this->assert_same( 'portal_game_type_missing', $empty->get_error_code(), 'an authoritative empty collection is rejected when linked rows exist' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'an empty collection performs zero Game Type writes' );

		$recovered = $this->sync(
			$site_id,
			$this->body(
				self::HALL_C,
				'2026-09-08T12:00:00-04:00',
				[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ] ],
				[ [ 8001, 'Hall C Session', 'active' ] ]
			)
		);
		$this->assert_true( $recovered instanceof PortalSyncResult, 'a later complete snapshot is processed normally after a rejection' );
		$this->assert_same( 'portal-gt-5003', (string) $this->game_type_row_by_portal_id( $site_id, 5003 )['code'], 'a rejected snapshot reserved no code' );
	}

	private function code_exhaustion_rollback( int $site_id ): void {
		// Occupy every approved candidate for Portal ID 5900.
		$occupied = [];
		for ( $candidate = 1; $candidate <= 32; ++$candidate ) {
			$code = 1 === $candidate ? 'portal-gt-5900' : 'portal-gt-5900-' . $candidate;
			$occupied[] = $this->insert_local_game_type( $site_id, $code, 'Exhaustion Fixture ' . $candidate );
		}

		$before_game_types = $this->game_type_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		// 5800 is inserted FIRST and must not survive the rejection of 5900.
		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_C,
				'2026-09-08T13:00:00-04:00',
				[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5800, 'Applies First' ], [ 5900, 'Exhausts Codes' ] ],
				[ [ 8001, 'Hall C Session', 'active' ] ]
			)
		);
		$this->assert_true( is_wp_error( $result ), 'exhausting every code candidate rejects the application' );
		$this->assert_same( 'portal_game_type_code_exhausted', $result->get_error_code(), 'code exhaustion reports its own error code' );
		$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5900 ), 'the exhausted Game Type is not created' );
		$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5800 ), 'a Game Type inserted earlier in the same transaction is rolled back' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'code exhaustion leaves every Game Type row byte-identical' );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'code exhaustion leaves the marker untouched' );

		foreach ( $occupied as $game_type_id ) {
			$this->delete_game_type( $site_id, $game_type_id );
		}
	}

	private function database_failure_rollback( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$before_templates  = $this->template_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		// A real, deterministic mid-transaction database failure: a unique index on
		// the replica name column that the second inserted template violates. The
		// Game Type writes have already happened by then.
		$this->probe_index( $site_id, 'add' );

		try {
			$result = $this->sync(
				$site_id,
				$this->body(
					self::HALL_C,
					'2026-09-08T14:00:00-04:00',
					[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5700, 'Applies Before Failure' ] ],
					[
						[ 8001, 'Hall C Session', 'active' ],
						[ 8801, 'Duplicate Probe Name', 'active' ],
						[ 8802, 'Duplicate Probe Name', 'active' ],
					]
				)
			);

			$this->assert_true( is_wp_error( $result ), 'a mid-transaction database failure fails the synchronization' );
			$this->assert_same( 'portal_sync_persistence_failed', $result->get_error_code(), 'the database failure is reported as a persistence failure' );
			$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5700 ), 'a Game Type written before the failure is rolled back' );
			$this->assert_same( [], $this->template_row_by_portal_id( $site_id, 8801 ), 'a Session template written before the failure is rolled back' );
			$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'a database failure leaves every Game Type row byte-identical' );
			$this->assert_same( $before_templates, $this->template_rows( $site_id ), 'a database failure leaves every Session template row byte-identical' );
			$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'a database failure leaves the marker untouched' );
		} finally {
			$this->probe_index( $site_id, 'drop' );
		}

		$recovered = $this->sync(
			$site_id,
			$this->body(
				self::HALL_C,
				'2026-09-08T14:30:00-04:00',
				[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5700, 'Applies Before Failure' ] ],
				[ [ 8001, 'Hall C Session', 'active' ] ]
			)
		);
		$this->assert_true( $recovered instanceof PortalSyncResult, 'the session is still usable after a rolled-back transaction' );
		$this->assert_same( 'portal-gt-5700', (string) $this->game_type_row_by_portal_id( $site_id, 5700 )['code'], 'a rolled-back application reserved no code' );
	}

	private function mapping_changed_during_synchronization( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$body = $this->body(
			self::HALL_C,
			'2026-09-08T15:00:00-04:00',
			[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5700, 'Applies Before Failure' ], [ 5600, 'Should Not Land' ] ],
			[ [ 8001, 'Hall C Session', 'active' ] ]
		);

		// The mapping is disabled AFTER resolution and DURING the Portal round-trip,
		// exactly as a Platform Admin could do while a pull is in flight.
		$disabled = $this->sync(
			$site_id,
			function ( int $portal_hall_id ) use ( $body ) {
				$changed = ( new HallMappingRepository() )->disable( self::HALL_C );
				$this->assert_false( is_wp_error( $changed ), 'the mapping can be disabled mid-synchronization' );

				return $body;
			}
		);

		$this->assert_true( is_wp_error( $disabled ), 'a mapping disabled during synchronization fails the application' );
		$this->assert_same( 'portal_sync_mapping_changed', $disabled->get_error_code(), 'the in-transaction mapping re-check rejects the application' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'a changed mapping performs zero Game Type writes' );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'a changed mapping leaves the marker untouched' );
		$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5600 ), 'no Game Type survives a changed mapping' );

		$this->assert_false( is_wp_error( ( new HallMappingRepository() )->enable( self::HALL_C ) ), 'the mapping can be re-enabled' );

		// A disabled mapping is also refused before the fetch.
		$this->assert_false( is_wp_error( ( new HallMappingRepository() )->disable( self::HALL_C ) ), 'the mapping can be disabled again' );
		$refused = $this->sync( $site_id, $body );
		$this->assert_true( is_wp_error( $refused ), 'a disabled mapping refuses synchronization outright' );
		$this->assert_same( 0, $this->fetch_count, 'a disabled mapping performs no Portal fetch' );
		$this->assert_false( is_wp_error( ( new HallMappingRepository() )->enable( self::HALL_C ) ), 'the mapping is restored for cleanup' );
	}

	/**
	 * A mid-transaction error must fire ZERO `is_wp_error_instance` callbacks.
	 *
	 * is_wp_error() dispatches that action for every WP_Error it inspects, so a
	 * single use inside the boundary would run arbitrary third-party callbacks on
	 * the captured session while the transaction is open — the same hazard as the
	 * `query` and `gettext` filters. The transport and the service therefore test
	 * with `instanceof \WP_Error` throughout, and this observer proves it at
	 * runtime rather than by inspection.
	 */
	private function no_is_wp_error_callbacks_mid_transaction( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$observed  = 0;
		$observer  = static function () use ( &$observed ): void {
			++$observed;
		};

		$this->probe_index( $site_id, 'add' );
		add_action( 'is_wp_error_instance', $observer );

		try {
			$result = $this->sync(
				$site_id,
				$this->body(
					self::HALL_C,
					'2026-09-08T15:10:00-04:00',
					[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5700, 'Applies Before Failure' ], [ 5500, 'Observer Probe' ] ],
					[
						[ 8001, 'Hall C Session', 'active' ],
						[ 8901, 'Observer Duplicate Name', 'active' ],
						[ 8902, 'Observer Duplicate Name', 'active' ],
					]
				)
			);
		} finally {
			// Removed BEFORE the returned error is inspected: assert_* and
			// is_wp_error() in this harness would otherwise fire the observer
			// themselves and destroy the measurement.
			remove_action( 'is_wp_error_instance', $observer );
			$this->probe_index( $site_id, 'drop' );
		}

		$this->assert_same( 0, $observed, 'a mid-transaction failure fires zero is_wp_error_instance callbacks' );
		$this->assert_true( $result instanceof \WP_Error, 'the observed synchronization did fail mid-transaction' );
		$this->assert_same( 'portal_sync_persistence_failed', $result->get_error_code(), 'the observed failure is the forced database failure' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'the observed failure rolled back every Game Type write' );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'the observed failure left the marker untouched' );

		// The same forced failure must expose neither the Portal name nor raw SQL.
		$exposed = strtolower( $result->get_error_message() . ' ' . var_export( $result->get_error_data(), true ) );
		$this->assert_true( false === strpos( $exposed, 'observer duplicate name' ), 'the failure exposes no Portal Session name' );
		$this->assert_true( false === strpos( $exposed, 'duplicate entry' ), 'the failure exposes no raw MySQL duplicate-entry text' );
		$this->assert_true( false === strpos( $exposed, 'bc_rollback_probe' ), 'the failure exposes no non-allowlisted constraint name' );
		$this->assert_true( false === strpos( $exposed, 'insert into' ), 'the failure exposes no SQL statement text' );
		$this->assert_true( false === strpos( $exposed, $this->site_prefix( $site_id ) ), 'the failure exposes no schema-qualified table name' );

		$data = $result->get_error_data();
		$this->assert_true( is_array( $data ), 'the failure carries structured data' );
		$this->assert_same( [ 'db_errno', 'status' ], array_values( array_diff( array_keys( $data ), [ 'constraint' ] ) ), 'the failure data carries only allowlisted keys' );
		$this->assert_true( is_int( $data['db_errno'] ?? null ), 'the failure reports a numeric MySQL errno only' );
	}

	/**
	 * A nested synchronization of the SAME hall must be refused before GET_LOCK.
	 *
	 * MySQL advisory locks are re-entrant within one session, so without the
	 * request-local guard the inner attempt would take the same lock, fetch, and
	 * write while the outer one was mid-flight.
	 */
	private function nested_same_hall_synchronization( int $site_a, int $site_b ): void {
		$before_a = $this->game_type_rows( $site_a );
		$before_b = $this->game_type_rows( $site_b );

		$inner_fetches = 0;
		$inner_result  = null;
		$other_result  = null;

		$outer_body = $this->body(
			self::HALL_A,
			'2026-09-08T19:00:00-04:00',
			[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ], [ 4004, 'Bonanza' ], [ 4100, 'Collision Import' ], [ 4200, 'Outer Only' ] ],
			[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
		);

		$outer = $this->sync(
			$site_a,
			function ( int $portal_hall_id ) use ( $site_a, $site_b, $outer_body, &$inner_fetches, &$inner_result, &$other_result ) {
				// Re-entrant attempt at the SAME hall, from inside the outer fetch.
				$inner = new PortalSyncService(
					static function ( int $id ) use ( &$inner_fetches ) {
						++$inner_fetches;

						return '{"never":"reached"}';
					}
				);
				$inner_result = $inner->synchronizeHall( $site_a );

				// A DIFFERENT hall must remain completely unaffected.
				$other = new PortalSyncService(
					// Deliberately the SAME instant tenant_isolation already applied, so
					// this proves independence without disturbing the ordering the
					// concurrency scenario depends on afterwards.
					fn( int $id ) => $this->body(
						self::HALL_B,
						'2026-09-08T10:00:00-04:00',
						[ [ 4001, 'Regular' ], [ 4002, 'Regular' ] ],
						[ [ 7001, 'Evening Session', 'active' ] ]
					)
				);
				$other_result = $other->synchronizeHall( $site_b );

				return $outer_body;
			}
		);

		$this->assert_true( $inner_result instanceof \WP_Error, 'a nested same-hall synchronization is refused' );
		$this->assert_same( 'portal_sync_lock_reentrant', $inner_result->get_error_code(), 'the nested attempt is refused by the request-local reentrancy guard' );
		$this->assert_same( 0, $inner_fetches, 'the nested attempt performs zero Portal fetches' );

		$this->assert_true( $outer instanceof PortalSyncResult, 'the outer synchronization remains valid' );
		$this->assert_same( 1, $outer->game_types_created, 'the outer synchronization applied its own change' );
		$this->assert_same( 'portal-gt-4200', (string) $this->game_type_row_by_portal_id( $site_a, 4200 )['code'], 'the outer synchronization committed normally' );
		$this->assert_same( count( $before_a ) + 1, count( $this->game_type_rows( $site_a ) ), 'the nested attempt wrote no Game Type row of its own' );

		$this->assert_true( $other_result instanceof PortalSyncResult, 'a different hall synchronizes normally from inside the outer fetch' );
		$this->assert_same( $before_b, $this->game_type_rows( $site_b ), 'the different-hall synchronization was an idempotent no-op' );

		// The guard must be cleared on release, so the hall is usable again.
		$again = $this->sync( $site_a, $outer_body );
		$this->assert_true( $again instanceof PortalSyncResult, 'the reentrancy guard is cleared once the outer lock is released' );
		$this->assert_true( $again->isNoOp(), 'the follow-up synchronization is an idempotent no-op' );
	}

	/**
	 * The re-entrancy window IS the acquisition query itself.
	 *
	 * wpdb::query() fires the `query` filter on every statement, so a third-party
	 * callback can run DURING the outer GET_LOCK. If the guard were reserved only
	 * after that query returned, the callback would find it empty and MySQL —
	 * whose advisory locks are re-entrant per session — would hand the nested
	 * attempt the same lock and report success.
	 *
	 * This drives exactly that: a temporary `query` filter fires once, on the
	 * outer GET_LOCK statement, and attempts one nested same-Hall
	 * synchronization from inside it.
	 */
	private function reentrancy_during_get_lock_query( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$before_templates  = $this->template_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$fired          = false;
		$nested_result  = null;
		$nested_fetches = 0;
		$saw_get_lock   = false;

		$filter = function ( $sql ) use ( &$fired, &$nested_result, &$nested_fetches, &$saw_get_lock, $site_id ) {
			// Fire once, on the outer acquisition statement only. Without the latch
			// the nested attempt's own statements would recurse.
			if ( ! $fired && is_string( $sql ) && false !== stripos( $sql, 'GET_LOCK' ) && false === stripos( $sql, 'RELEASE_LOCK' ) ) {
				$fired        = true;
				$saw_get_lock = true;

				$inner = new PortalSyncService(
					static function ( int $portal_hall_id ) use ( &$nested_fetches ) {
						++$nested_fetches;

						return '{"never":"reached"}';
					}
				);
				$nested_result = $inner->synchronizeHall( $site_id );
			}

			return $sql;
		};

		$body = $this->body(
			self::HALL_A,
			'2026-09-08T21:00:00-04:00',
			[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ], [ 4004, 'Bonanza' ], [ 4100, 'Collision Import' ], [ 4200, 'Outer Only' ], [ 4300, 'Filtered Window' ] ],
			[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
		);

		add_filter( 'query', $filter );
		try {
			$outer = $this->sync( $site_id, $body );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assert_true( $saw_get_lock, 'the temporary query filter observed the outer GET_LOCK statement' );
		$this->assert_false( has_filter( 'query', $filter ), 'the temporary query filter is removed in finally' );

		$this->assert_true( $nested_result instanceof \WP_Error, 'a nested attempt made during the outer GET_LOCK query is refused' );
		$this->assert_same( 'portal_sync_lock_reentrant', $nested_result->get_error_code(), 'the guard is reserved BEFORE the acquisition query runs' );
		$this->assert_same( 0, $nested_fetches, 'the nested attempt performs zero Portal fetches' );

		$this->assert_true( $outer instanceof PortalSyncResult, 'the outer synchronization completes normally' );
		$this->assert_same( 1, $outer->game_types_created, 'the outer synchronization applied exactly its own change' );
		$this->assert_same( 'portal-gt-4300', (string) $this->game_type_row_by_portal_id( $site_id, 4300 )['code'], 'the outer synchronization committed its import' );
		$this->assert_same( count( $before_game_types ) + 1, count( $this->game_type_rows( $site_id ) ), 'the nested attempt wrote no Game Type row' );
		$this->assert_same( $before_templates, $this->template_rows( $site_id ), 'the nested attempt wrote no Session template row' );
		$this->assert_true( $before_marker !== $this->marker_row( $site_id ), 'only the outer synchronization advanced the marker' );

		// The guard survives neither acquisition nor release, so the hall is usable.
		$again = $this->sync( $site_id, $body );
		$this->assert_true( $again instanceof PortalSyncResult, 'the hall synchronizes again after the filtered window' );
		$this->assert_true( $again->isNoOp(), 'the follow-up synchronization is an idempotent no-op' );
	}

	/** An existing but unreadable marker must never be treated as "no previous state". */
	private function malformed_sync_state_marker( int $site_id ): void {
		$valid = $this->marker_row( $site_id );
		$this->assert_true( [] !== $valid, 'the hall has a committed marker to corrupt' );

		$before_game_types = $this->game_type_rows( $site_id );
		$body              = $this->body(
			self::HALL_C,
			'2026-09-08T16:00:00-04:00',
			[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5700, 'Applies Before Failure' ], [ 5400, 'Must Not Land' ] ],
			[ [ 8001, 'Hall C Session', 'active' ] ]
		);

		foreach ( [ 'not json at all', '{"portal_hall_id":990003}', '{"portal_hall_id":990003,"generated_at":"nonsense","applied_at":"x"}', '[]' ] as $corrupt ) {
			$this->write_marker_value( $site_id, $corrupt );

			$result = $this->sync( $site_id, $body );
			$this->assert_true( $result instanceof \WP_Error, 'a malformed marker fails the synchronization' );
			$this->assert_same( 'portal_sync_state_invalid', $result->get_error_code(), 'a malformed marker reports a stable sync-state error' );
			$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'a malformed marker performs zero Game Type writes' );
			$this->assert_same( $corrupt, (string) $this->marker_row( $site_id )['option_value'], 'a malformed marker is left exactly as found' );
			$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5400 ), 'no Game Type lands behind a malformed marker' );
		}

		// An ABSENT row remains the only "no previous state" case.
		$this->delete_marker( $site_id );
		$this->assert_same( [], $this->marker_row( $site_id ), 'the marker row can be removed' );
		$recovered = $this->sync( $site_id, $body );
		$this->assert_true( $recovered instanceof PortalSyncResult, 'an absent marker is the only no-previous-state case and applies' );
		$this->assert_same( 1, $recovered->game_types_created, 'the recovered synchronization applied its change' );
	}

	/** A marker naming a different Portal Hall is a tenancy disagreement, never writable. */
	private function wrong_hall_sync_state_marker( int $site_id ): void {
		$before_game_types = $this->game_type_rows( $site_id );
		$valid             = (string) $this->marker_row( $site_id )['option_value'];

		$this->write_marker_value(
			$site_id,
			(string) json_encode(
				[ 'portal_hall_id' => self::HALL_B, 'generated_at' => '2026-09-08T10:00:00-04:00', 'applied_at' => '2026-09-08 14:00:00' ]
			)
		);

		$result = $this->sync(
			$site_id,
			$this->body(
				self::HALL_C,
				'2026-09-08T17:00:00-04:00',
				[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5700, 'Applies Before Failure' ], [ 5400, 'Must Not Land' ], [ 5300, 'Also Must Not Land' ] ],
				[ [ 8001, 'Hall C Session', 'active' ] ]
			)
		);
		$this->assert_true( $result instanceof \WP_Error, 'a marker naming another Portal Hall fails the synchronization' );
		$this->assert_same( 'portal_sync_state_invalid', $result->get_error_code(), 'a wrong-Hall marker reports a stable sync-state error' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'a wrong-Hall marker performs zero Game Type writes' );
		$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5300 ), 'no Game Type lands behind a wrong-Hall marker' );

		$this->write_marker_value( $site_id, $valid );
		$this->assert_same( $valid, (string) $this->marker_row( $site_id )['option_value'], 'the valid marker is restored' );
	}

	/**
	 * ADR-043 §16 test 100 — a hall at Sessions v2 still synchronizes.
	 *
	 * THROUGH THE REAL PUBLIC ENTRY POINT. Calling
	 * `PortalSyncService::assert_snapshot_schemas()` directly proves only that
	 * the gate returns true; it proves nothing about whether synchronization
	 * actually completes, whether the snapshot lands, or whether something else
	 * on the path independently refuses a hall at v2. This drives
	 * `synchronizeHall()` — the same method production calls — with the suite's
	 * existing injected fetcher, and asserts on the replica rows and the
	 * sync-state marker afterwards.
	 *
	 * WHY IT MATTERS. ADR-043 raises `Sessions\Migrations::DB_VERSION` to 3 for
	 * three schedule tables and seven `bingo_sessions` columns, none of which a
	 * snapshot touches. The previous gate compared whole-plugin versions, so the
	 * moment that constant changed, every hall still recorded at 2 would have
	 * stopped receiving Portal updates until it migrated. §11.3 replaces that
	 * with structural verification of exactly what the transport writes into.
	 *
	 * The version marker is lowered to 2 for the duration and restored in the
	 * `finally`, together with the replica state, so no later scenario inherits
	 * either. The REQUIRED structures are left entirely intact — this scenario is
	 * about the version marker alone, and a damaged-replica case would prove
	 * something different.
	 */
	private function sessions_v2_synchronization( int $site_id ): void {
		$version_option = \BingoCommerce\Sessions\Migrations::VERSION_OPTION;

		switch_to_blog( $site_id );
		$recorded_version = get_option( $version_option );
		restore_current_blog();

		$before_game_types = $this->game_type_rows( $site_id );
		$before_templates  = $this->template_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$existing_game_types = [];
		foreach ( $before_game_types as $row ) {
			if ( null !== $row['portal_game_type_id'] ) {
				$existing_game_types[] = [ (int) $row['portal_game_type_id'], (string) $row['name'] ];
			}
		}
		$existing_templates = [];
		foreach ( $before_templates as $row ) {
			$existing_templates[] = [ (int) $row['portal_session_id'], (string) $row['name'], (string) $row['portal_status'] ];
		}

		switch_to_blog( $site_id );
		update_option( $version_option, 2 );
		$at_v2          = (int) get_option( $version_option, 0 );
		$is_current     = \BingoCommerce\Sessions\Migrations::is_current();
		$replica_intact = \BingoCommerce\Sessions\Migrations::verify_portal_session_templates_structure();
		$catalog_intact = \BingoCommerce\Catalog\Migrations::verify_game_types_structure_for_sync();
		restore_current_blog();

		try {
			$this->assert_same( 2, $at_v2, 'the hall is recorded at Sessions version 2' );
			$this->assert_false( $is_current, '  so the whole-plugin version gate is false for this hall' );
			$this->assert_true( true === $replica_intact, '  while the Session-template replica structure is intact' );
			$this->assert_true( true === $catalog_intact, '  and so is the Game Type replica structure' );

			/*
			 * Every statement the synchronization emits is observed, so "no
			 * migration or DDL ran" is proved from what actually reached the
			 * database rather than inferred from the absence of a symptom.
			 */
			$this->observed_sql = [];
			$this->observing    = true;
			add_filter( 'query', [ $this, 'observe_sql' ], 1 );

			/*
			 * The snapshot carries every Game Type this hall has ALREADY imported,
			 * plus one new one. A snapshot that omitted an imported Game Type
			 * would be refused with `portal_game_type_missing` — correctly, and
			 * for a reason that has nothing to do with the schema version this
			 * scenario is about. The existing set is therefore read from the
			 * replica rather than hardcoded, so earlier scenarios on this hall
			 * cannot make this one fail for the wrong reason.
			 */
			$result = $this->sync(
				$site_id,
				$this->body(
					self::HALL_G,
					'2026-09-09T19:15:00-04:00',
					array_merge( $existing_game_types, [ [ 5910, 'Added At V2' ] ] ),
					array_merge( $existing_templates, [ [ 8910, 'Session Added At V2', 'active' ] ] )
				)
			);

			remove_filter( 'query', [ $this, 'observe_sql' ], 1 );
			$this->observing = false;

			/* --- synchronization succeeds --- */
			$this->assert_false( is_wp_error( $result ), 'a hall at Sessions v2 SYNCHRONIZES successfully through synchronizeHall()' );
			$this->assert_same( 1, $this->fetch_count, '  performing exactly one Portal fetch' );

			/* --- the snapshot is applied to the expected replica state --- */
			$game_type = $this->game_type_row_by_portal_id( $site_id, 5910 );
			$this->assert_same( 'Added At V2', (string) ( $game_type['name'] ?? '' ), 'the new Game Type landed in the replica at v2' );
			$this->assert_same( 'active', (string) ( $game_type['status'] ?? '' ), '  with an active status' );

			$templates = [];
			foreach ( $this->template_rows( $site_id ) as $row ) {
				$templates[ (int) $row['portal_session_id'] ] = $row;
			}
			$this->assert_same( 'Session Added At V2', (string) ( $templates[8910]['name'] ?? '' ), 'the new Session template landed in the replica at v2' );
			$this->assert_same( 1, (int) ( $templates[8910]['is_current'] ?? 0 ), '  and is current' );
			$this->assert_same( 'active', (string) ( $templates[8910]['portal_status'] ?? '' ), '  carrying its Portal status verbatim' );

			$this->assert_true(
				count( $this->game_type_rows( $site_id ) ) > count( $before_game_types ),
				'the Game Type replica grew, so the snapshot really was applied'
			);

			/* --- the sync-state marker advanced, so the run was recorded --- */
			$marker = $this->marker_row( $site_id );
			$this->assert_true( [] !== $marker, 'the sync-state marker exists after the v2 synchronization' );
			$this->assert_true(
				( $before_marker['option_value'] ?? null ) !== ( $marker['option_value'] ?? null ),
				'  and advanced, so the run completed rather than short-circuiting'
			);

			/* --- the Sessions version marker is untouched --- */
			switch_to_blog( $site_id );
			$after_version = (int) get_option( $version_option, 0 );
			restore_current_blog();
			$this->assert_same( 2, $after_version, 'the Sessions version marker REMAINS 2 — synchronization migrated nothing' );

			/* --- no migration and no DDL ran --- */
			$structural = [];
			foreach ( $this->observed_sql as $sql ) {
				$head = strtoupper( ltrim( $sql ) );
				foreach ( [ 'CREATE TABLE', 'CREATE INDEX', 'ALTER TABLE', 'DROP TABLE', 'DROP INDEX', 'RENAME TABLE', 'TRUNCATE' ] as $verb ) {
					if ( 0 === strpos( $head, $verb ) ) {
						$structural[] = substr( trim( $sql ), 0, 70 );
					}
				}
			}
			$this->assert_same( [], $structural, 'the synchronization issued NO DDL of any kind' );

			$version_writes = [];
			foreach ( $this->observed_sql as $sql ) {
				if ( false !== strpos( $sql, $version_option ) && 1 === preg_match( '/^\s*(INSERT|UPDATE|REPLACE|DELETE)/i', $sql ) ) {
					$version_writes[] = substr( trim( $sql ), 0, 70 );
				}
			}
			$this->assert_same( [], $version_writes, '  and wrote to no Sessions version marker' );

			printf(
				"NOTE: ADR-043 test 100 observed %d statements during the v2 synchronization, none structural.\n",
				count( $this->observed_sql )
			);
		} finally {
			remove_filter( 'query', [ $this, 'observe_sql' ], 1 );
			$this->observing = false;

			/*
			 * Restore the version marker AND the replica state this scenario
			 * changed, so nothing downstream inherits either. The rows added here
			 * are removed by the snapshot that follows in the normal course of the
			 * suite, but the marker is this scenario's own and must be put back
			 * explicitly.
			 */
			switch_to_blog( $site_id );
			if ( false === $recorded_version ) {
				delete_option( $version_option );
			} else {
				update_option( $version_option, $recorded_version );
			}
			restore_current_blog();
		}

		switch_to_blog( $site_id );
		$restored = get_option( $version_option );
		restore_current_blog();
		$this->assert_same( $recorded_version, $restored, 'the original Sessions version marker is restored' );

		/*
		 * NO REPLICA RESTORATION IS NEEDED OR POSSIBLE.
		 *
		 * This scenario owns its hall, which cleanup() destroys with every table
		 * on it. That matters beyond tidiness: the rows imported above CANNOT be
		 * withdrawn through the public path, because a snapshot that omitted an
		 * already-imported Game Type is refused with `portal_game_type_missing` —
		 * correct transport behaviour, and not something a test should route
		 * around by deleting rows by hand. The version marker is this scenario's
		 * own change and is restored explicitly above.
		 */
	}

	/** @internal Public only because WordPress needs a callable. */
	public function observe_sql( string $sql ): string {
		if ( $this->observing ) {
			$this->observed_sql[] = $sql;
		}

		return $sql;
	}

	/** Every participating table must be InnoDB before BEGIN, or nothing runs. */
	private function innodb_requirement( int $site_id ): void {
		global $wpdb;

		$before_game_types = $this->game_type_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$body = $this->body(
			self::HALL_C,
			'2026-09-08T18:00:00-04:00',
			[ [ 5001, 'Regular' ], [ 5002, 'Speedball' ], [ 5003, 'Newly Added' ], [ 5700, 'Applies Before Failure' ], [ 5400, 'Must Not Land' ], [ 5200, 'Engine Probe' ] ],
			[ [ 8001, 'Hall C Session', 'active' ] ]
		);

		switch_to_blog( $site_id );
		$templates = \BingoCommerce\Sessions\Migrations::portal_session_templates_table();
		restore_current_blog();

		$this->disposable_engine( $site_id, $templates, 'MyISAM' );
		try {
			$this->assert_same( 'MyISAM', $this->table_engine( $templates ), 'the replica table is temporarily non-transactional' );

			$result = $this->sync( $site_id, $body );
			$this->assert_true( $result instanceof \WP_Error, 'a non-InnoDB participating table fails the synchronization' );
			$this->assert_same( 'portal_sync_storage_engine_unsupported', $result->get_error_code(), 'the storage-engine gate reports its own error code' );
			$this->assert_same( 1, $this->fetch_count, 'the storage-engine gate runs after the fetch and before BEGIN' );
			$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'a non-InnoDB table performs zero Game Type writes' );
			$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'a non-InnoDB table leaves the marker untouched' );
			$this->assert_same( [], $this->game_type_row_by_portal_id( $site_id, 5200 ), 'no Game Type lands behind the storage-engine gate' );
		} finally {
			$this->disposable_engine( $site_id, $templates, 'InnoDB' );
		}

		$this->assert_same( 'InnoDB', $this->table_engine( $templates ), 'the replica table is restored to InnoDB' );
		$recovered = $this->sync( $site_id, $body );
		$this->assert_true( $recovered instanceof PortalSyncResult, 'synchronization resumes once every table is InnoDB again' );
	}

	private function tenant_isolation( int $site_a, int $site_b ): void {
		$before_a_game_types = $this->game_type_rows( $site_a );
		$before_a_templates  = $this->template_rows( $site_a );
		$before_a_marker     = $this->marker_row( $site_a );

		// Deliberately OVERLAPPING Portal identities and IDENTICAL names.
		$result = $this->sync(
			$site_b,
			$this->body(
				self::HALL_B,
				'2026-09-08T10:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ] ],
				[ [ 7001, 'Evening Session', 'active' ] ]
			)
		);
		$this->assert_true( $result instanceof PortalSyncResult, 'the second hall synchronizes independently' );
		$this->assert_same( 2, $result->game_types_created, 'the second hall imports its own Game Type rows' );

		$this->assert_same( $before_a_game_types, $this->game_type_rows( $site_a ), 'the first hall Game Type rows are untouched by the second hall' );
		$this->assert_same( $before_a_templates, $this->template_rows( $site_a ), 'the first hall Session template rows are untouched by the second hall' );
		$this->assert_same( $before_a_marker, $this->marker_row( $site_a ), 'the first hall marker is untouched by the second hall' );

		$b_game_types = $this->game_type_rows( $site_b );
		$this->assert_same( [ 4001, 4002 ], array_map( 'intval', array_column( $b_game_types, 'portal_game_type_id' ) ), 'the second hall holds only its own Portal identities' );
		$this->assert_same( 2, count( $b_game_types ), 'the second hall sees none of the first hall rows' );

		$b_marker = PortalSyncState::decode( (string) ( $this->marker_row( $site_b )['option_value'] ?? '' ) );
		$this->assert_same( self::HALL_B, $b_marker['portal_hall_id'] ?? 0, 'each hall marker records its own Portal Hall ID' );
	}

	private function concurrent_same_hall_synchronization( int $site_a, int $site_b ): void {
		$before_a = $this->game_type_rows( $site_a );

		$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$worker      = proc_open(
			sprintf( '%s %s %d %d', escapeshellarg( PHP_BINARY ), escapeshellarg( __DIR__ . '/portal-snapshot-sync-worker.php' ), $site_a, 9000 ),
			$descriptors,
			$pipes
		);
		if ( ! is_resource( $worker ) ) {
			$this->assert_true( false, 'the concurrency worker could not be started' );

			return;
		}

		try {
			$handshake = '';
			$deadline  = microtime( true ) + 30.0;
			while ( false === strpos( $handshake, 'LOCKED' ) && microtime( true ) < $deadline ) {
				$chunk = fgets( $pipes[1] );
				if ( false === $chunk ) {
					break;
				}
				$handshake .= $chunk;
			}
			$this->assert_true( false !== strpos( $handshake, 'LOCKED' ), 'the worker holds the hall A synchronization lock' );

			$blocked = $this->sync(
				$site_a,
				$this->body( self::HALL_A, '2026-09-08T18:00:00-04:00', [ [ 4001, 'Regular' ] ], [] )
			);
			$this->assert_true( is_wp_error( $blocked ), 'a second synchronization of the same hall is refused while one is running' );
			$this->assert_same( 'portal_sync_lock_timeout', $blocked->get_error_code(), 'the blocked attempt reports a lock timeout' );
			$this->assert_same( 0, $this->fetch_count, 'a blocked attempt performs NO Portal fetch, so the lock precedes the request' );
			$this->assert_same( $before_a, $this->game_type_rows( $site_a ), 'a blocked attempt writes nothing' );

			// A different hall must not be serialized behind hall A's lock.
			$other = $this->sync(
				$site_b,
				$this->body(
					self::HALL_B,
					'2026-09-08T11:00:00-04:00',
					[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4005, 'Second Hall Only' ] ],
					[ [ 7001, 'Evening Session', 'active' ] ]
				)
			);
			$this->assert_true( $other instanceof PortalSyncResult, 'a different hall synchronizes while hall A is locked' );
			$this->assert_same( 1, $other->game_types_created, 'the unrelated hall applies its own change' );
		} finally {
			foreach ( $pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			proc_close( $worker );
		}

		// The lock is reusable once the worker exits. The snapshot must stay newer
		// than the nested scenario's committed marker and must remain complete.
		$after = $this->sync(
			$site_a,
			$this->body(
				self::HALL_A,
				'2026-09-08T20:00:00-04:00',
				[ [ 4001, 'Regular' ], [ 4002, 'Regular' ], [ 4003, 'Speedball Deluxe' ], [ 4004, 'Bonanza' ], [ 4100, 'Collision Import' ], [ 4200, 'Outer Only' ] ],
				[ [ 7001, 'Evening Session', 'active' ], [ 7002, 'Matinee', 'draft' ] ]
			)
		);
		$this->assert_true( $after instanceof PortalSyncResult, 'the hall synchronizes normally after the lock is released' );
	}

	/**
	 * An UNCONFIRMED RELEASE_LOCK must NOT give the reservation back.
	 *
	 * RELEASE_LOCK returns 0 when this thread does not own the lock and NULL when
	 * the name does not exist, and a failed query returns nothing. In all of those
	 * cases MySQL may still hold the lock on this session, and its advisory locks
	 * are re-entrant per session — so clearing the guard would let a later
	 * same-Hall synchronization call GET_LOCK and be handed the lock again.
	 *
	 * The failure is forced with a temporary `query` filter that rewrites the
	 * statement to `RELEASE_LOCK(...) - 1`. That still performs the real release,
	 * so no lock can outlive this scenario, while the caller observes exactly the
	 * unconfirmed `0` it must fail closed on. The scenario runs last, on its own
	 * hall, because failing closed is the point: hall D stays refused afterwards.
	 */
	private function unconfirmed_release_keeps_reservation( int $site_id, int $other_site_id ): void {
		global $wpdb;

		$body = $this->body(
			self::HALL_D,
			'2026-09-08T10:00:00-04:00',
			[ [ 6001, 'Hall D Regular' ] ],
			[ [ 9001, 'Hall D Session', 'active' ] ]
		);

		$fired         = false;
		$captured_name = '';

		$filter = function ( $sql ) use ( &$fired, &$captured_name ) {
			if ( ! $fired && is_string( $sql ) && 1 === preg_match( "/RELEASE_LOCK\('([^']+)'\)/", $sql, $m ) ) {
				$fired         = true;
				$captured_name = $m[1];

				// Really releases; reports an unconfirmed 0.
				return $sql . ' - 1';
			}

			return $sql;
		};

		add_filter( 'query', $filter );
		try {
			$first = $this->sync( $site_id, $body );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assert_false( has_filter( 'query', $filter ), 'the release-failure filter is removed in finally' );
		$this->assert_true( $fired, 'the release-failure filter rewrote the RELEASE_LOCK statement' );
		$this->assert_true( $first instanceof PortalSyncResult, 'an unconfirmed release does not fail the synchronization itself' );
		$this->assert_same( 1, $first->game_types_created, 'the synchronization committed normally before release' );

		// The lock really was released, so nothing can outlive this process.
		$this->assert_same(
			null,
			$wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $captured_name ) ),
			'the advisory lock is genuinely free, so the test leaks no lock'
		);

		// ...but the reservation was NOT given back, so the hall fails closed.
		$before_game_types = $this->game_type_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$second = $this->sync( $site_id, $body );
		$this->assert_true( $second instanceof \WP_Error, 'a later same-Hall synchronization is refused after an unconfirmed release' );
		$this->assert_same( 'portal_sync_lock_reentrant', $second->get_error_code(), 'the retained reservation refuses it rather than re-acquiring the lock' );
		$this->assert_same( 0, $this->fetch_count, 'the refused attempt performs zero Portal fetches' );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), 'the refused attempt writes no Game Type row' );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), 'the refused attempt leaves the marker untouched' );

		// Different-Hall independence is unaffected by another hall's retained
		// reservation. The payload is rebuilt from hall C's live rows so this stays
		// complete under ADR-038 no matter what ran before it.
		$other_game_types = [];
		foreach ( $this->game_type_rows( $other_site_id ) as $row ) {
			if ( null !== $row['portal_game_type_id'] ) {
				$other_game_types[] = [ (int) $row['portal_game_type_id'], (string) $row['name'] ];
			}
		}

		$other = $this->sync(
			$other_site_id,
			$this->body( self::HALL_C, '2026-09-08T23:00:00-04:00', $other_game_types, [ [ 8001, 'Hall C Session', 'active' ] ] )
		);
		$this->assert_true( $other instanceof PortalSyncResult, 'a different hall still synchronizes while hall D stays refused' );
		$this->assert_true( $other->isNoOp(), 'the different-hall synchronization is an idempotent no-op' );
	}

	/**
	 * Two release paths whose outcome is genuinely UNKNOWN, not merely negative.
	 *
	 *   connection_id_unavailable — `SELECT CONNECTION_ID()` yields NULL. Casting
	 *     that before validating gives 0, which compares unequal to the acquiring
	 *     id and reads as "the connection changed" — clearing the reservation on
	 *     exactly the failure where the lock is most likely still held.
	 *
	 *   release_throws — the RELEASE_LOCK query throws. Uncaught, it escapes
	 *     release(), which runs from the caller's `finally`, and replaces an
	 *     already-committed synchronization result with a thrown error.
	 *
	 * Both must keep the reservation and leave the hall refused. Neither may leak
	 * a lock: the real lock is never released on these paths, so the scenario
	 * releases it by hand once the filter is gone and proves it free.
	 */
	private function release_failure_keeps_reservation( int $site_id, int $portal_hall_id, string $mode, int $other_site_id ): void {
		global $wpdb;

		$body = $this->body(
			$portal_hall_id,
			'2026-09-08T10:00:00-04:00',
			[ [ 6101, 'Release Failure Regular' ] ],
			[ [ 9101, 'Release Failure Session', 'active' ] ]
		);

		$lock_name = '';
		$sabotaged = false;

		$filter = function ( $sql ) use ( &$lock_name, &$sabotaged, $mode ) {
			if ( ! is_string( $sql ) ) {
				return $sql;
			}

			// The acquisition statement carries the lock name; capture it so the
			// scenario can release the lock itself afterwards.
			if ( '' === $lock_name && 1 === preg_match( "/GET_LOCK\('([^']+)'/", $sql, $m ) ) {
				$lock_name = $m[1];
			}

			if ( $sabotaged ) {
				return $sql;
			}

			// release() is the only caller that issues a bare CONNECTION_ID().
			if ( 'connection_id_unavailable' === $mode && 'SELECT CONNECTION_ID()' === trim( $sql ) ) {
				$sabotaged = true;

				return 'SELECT NULL';
			}

			if ( 'release_throws' === $mode && false !== stripos( $sql, 'RELEASE_LOCK' ) ) {
				$sabotaged = true;

				throw new \RuntimeException( 'Forced release failure: SELECT RELEASE_LOCK exploded.' );
			}

			return $sql;
		};

		add_filter( 'query', $filter );
		try {
			$first = $this->sync( $site_id, $body );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assert_false( has_filter( 'query', $filter ), "{$mode}: the sabotage filter is removed in finally" );
		$this->assert_true( $sabotaged, "{$mode}: the sabotage filter fired on the release path" );
		$this->assert_true( $lock_name !== '', "{$mode}: the scenario captured the advisory lock name" );

		// The completed synchronization result must survive the release failure.
		$this->assert_true( $first instanceof PortalSyncResult, "{$mode}: a release failure does not replace the synchronization result" );
		$this->assert_same( 1, $first->game_types_created, "{$mode}: the synchronization committed normally before release" );
		$this->assert_same( 1, $first->session_templates_created, "{$mode}: the Session template committed normally before release" );

		// The reservation was NOT given back, so the hall fails closed.
		$before_game_types = $this->game_type_rows( $site_id );
		$before_marker     = $this->marker_row( $site_id );

		$second = $this->sync( $site_id, $body );
		$this->assert_true( $second instanceof \WP_Error, "{$mode}: a later same-Hall synchronization is refused" );
		$this->assert_same( 'portal_sync_lock_reentrant', $second->get_error_code(), "{$mode}: the retained reservation refuses it" );
		$this->assert_same( 0, $this->fetch_count, "{$mode}: the refused attempt performs zero Portal fetches" );
		$this->assert_same( $before_game_types, $this->game_type_rows( $site_id ), "{$mode}: the refused attempt writes no Game Type row" );
		$this->assert_same( $before_marker, $this->marker_row( $site_id ), "{$mode}: the refused attempt leaves the marker untouched" );

		// A different hall is unaffected by this hall's retained reservation.
		$other_game_types = [];
		foreach ( $this->game_type_rows( $other_site_id ) as $row ) {
			if ( null !== $row['portal_game_type_id'] ) {
				$other_game_types[] = [ (int) $row['portal_game_type_id'], (string) $row['name'] ];
			}
		}
		$other = $this->sync(
			$other_site_id,
			$this->body( self::HALL_C, '2026-09-09T0' . ( 'release_throws' === $mode ? '1' : '0' ) . ':00:00-04:00', $other_game_types, [ [ 8001, 'Hall C Session', 'active' ] ] )
		);
		$this->assert_true( $other instanceof PortalSyncResult, "{$mode}: a different hall still synchronizes" );

		// These paths never released the lock. Do it here, with the filter gone, so
		// nothing can survive this process.
		$this->assert_same(
			(string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ),
			(string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name ) ),
			"{$mode}: the advisory lock is still held, as the failure implies"
		);
		$this->assert_same( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ), "{$mode}: the scenario releases the lock itself" );
		$this->assert_same( null, $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name ) ), "{$mode}: no advisory lock survives the scenario" );
	}

	/** Phase 3E must not touch Commerce Sessions, Packages, products, or scheduling. */
	private function commerce_untouched( int $site_id ): void {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$sessions  = \BingoCommerce\Sessions\Migrations::sessions_table();
			$relations = \BingoCommerce\Sessions\Migrations::session_packages_table();
			$this->assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sessions}`" ), 'Phase 3E created no dated Commerce Session' );
			$this->assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$relations}`" ), 'Phase 3E created no Session/Package assignment' );

			// Catalog v6 provisions the add-on table too. Snapshot application
			// must leave every commerce table empty on this new disposable Hall.
			foreach ( [ 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_package_addons' ] as $suffix ) {
				$table = $wpdb->prefix . $suffix;
				$this->assert_true( $this->table_exists( $table ), "Catalog table {$suffix} exists" );
				$this->assert_same( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), "Phase 3E created no row in {$suffix}" );
			}

			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = %s", 'product' ) ),
				'Phase 3E created no WooCommerce product'
			);
		} finally {
			restore_current_blog();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Synchronization helpers
	 * ------------------------------------------------------------------ */

	/**
	 * @param string|\WP_Error|callable $body Fixture body, transport error, or a
	 *        callable that may mutate state before returning one of those.
	 * @return PortalSyncResult|\WP_Error
	 */
	private function sync( int $site_id, $body ) {
		$this->fetch_count = 0;

		$service = new PortalSyncService(
			function ( int $portal_hall_id ) use ( $body ) {
				++$this->fetch_count;

				return is_callable( $body ) ? $body( $portal_hall_id ) : $body;
			}
		);

		return $service->synchronizeHall( $site_id );
	}

	/**
	 * Build one exact Portal v1 snapshot body.
	 *
	 * @param array<int,array{0:int,1:string}> $game_types
	 * @param array<int,array{0:int,1:string,2:string}> $sessions
	 */
	private function body( int $hall_id, string $generated_at, array $game_types, array $sessions, string $api_version = 'v1' ): string {
		$encoded_game_types = [];
		foreach ( $game_types as [ $id, $name ] ) {
			$encoded_game_types[] = [ 'id' => $id, 'hall_id' => $hall_id, 'name' => $name, 'updated_at' => null ];
		}

		$encoded_sessions = [];
		foreach ( $sessions as [ $id, $name, $status ] ) {
			$encoded_sessions[] = [ 'id' => $id, 'hall_id' => $hall_id, 'name' => $name, 'status' => $status, 'updated_at' => null ];
		}

		return (string) json_encode(
			[
				'api_version'  => $api_version,
				'generated_at' => $generated_at,
				'hall'         => [ 'id' => $hall_id, 'name' => 'Test Portal Hall ' . $hall_id, 'state' => 'active', 'updated_at' => null ],
				'game_types'   => $encoded_game_types,
				'sessions'     => $encoded_sessions,
			]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Fixture and state helpers
	 * ------------------------------------------------------------------ */

	private function provision_hall( string $label, int $portal_hall_id ): int {
		$site_id = $this->create_disposable_site( $label );
		$this->assert_true( $site_id > 4, "hall {$label} is a current-run disposable hall" );

		switch_to_blog( $site_id );
		try {
			$this->assert_true( \BingoCommerce\Catalog\Migrations::is_current(), "hall {$label} has a current Catalog schema" );
			$this->assert_true( \BingoCommerce\Sessions\Migrations::is_current(), "hall {$label} has a current Session schema" );
		} finally {
			restore_current_blog();
		}

		$mapping = ( new HallMappingRepository() )->bind( $portal_hall_id, $site_id );
		$this->assert_false( is_wp_error( $mapping ), "test Portal Hall {$portal_hall_id} binds to hall {$label}" );
		$this->bound_mappings[ $portal_hall_id ] = true;

		return $site_id;
	}

	/** @return array<int,array<string,mixed>> */
	private function game_type_rows( int $site_id ): array {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$table = \BingoCommerce\Catalog\Migrations::game_types_table();

			return (array) $wpdb->get_results(
				"SELECT game_type_id, portal_game_type_id, code, name, local_name_key, status, created_at, updated_at, archived_at FROM `{$table}` ORDER BY game_type_id",
				ARRAY_A
			);
		} finally {
			restore_current_blog();
		}
	}

	/** @return array<int,array<string,mixed>> */
	private function template_rows( int $site_id ): array {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$table = \BingoCommerce\Sessions\Migrations::portal_session_templates_table();

			return (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY portal_session_id", ARRAY_A );
		} finally {
			restore_current_blog();
		}
	}

	/** @return array<string,mixed> */
	private function game_type_row_by_portal_id( int $site_id, int $portal_game_type_id ): array {
		foreach ( $this->game_type_rows( $site_id ) as $row ) {
			if ( (int) $row['portal_game_type_id'] === $portal_game_type_id ) {
				return $row;
			}
		}

		return [];
	}

	/** @return array<string,mixed> */
	private function game_type_row_by_id( int $site_id, int $game_type_id ): array {
		foreach ( $this->game_type_rows( $site_id ) as $row ) {
			if ( (int) $row['game_type_id'] === $game_type_id ) {
				return $row;
			}
		}

		return [];
	}

	/** @return array<string,mixed> */
	private function template_row_by_portal_id( int $site_id, int $portal_session_id ): array {
		foreach ( $this->template_rows( $site_id ) as $row ) {
			if ( (int) $row['portal_session_id'] === $portal_session_id ) {
				return $row;
			}
		}

		return [];
	}

	/** @return array<string,mixed> */
	private function marker_row( int $site_id ): array {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name = %s",
					PortalSyncState::OPTION_NAME
				),
				ARRAY_A
			);

			return is_array( $row ) ? $row : [];
		} finally {
			restore_current_blog();
		}
	}

	private function insert_local_game_type( int $site_id, string $code, string $name ): int {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$now      = current_time( 'mysql', true );
			$inserted = $wpdb->insert(
				\BingoCommerce\Catalog\Migrations::game_types_table(),
				[
					'portal_game_type_id' => null,
					'code'                => $code,
					'name'                => $name,
					'local_name_key'      => $name,
					'status'              => 'active',
					'created_at'          => $now,
					'updated_at'          => $now,
					'archived_at'         => null,
				],
				[ null, '%s', '%s', '%s', '%s', '%s', '%s', null ]
			);
			$this->assert_same( 1, $inserted, "local Game Type fixture {$code} is inserted" );

			return (int) $wpdb->insert_id;
		} finally {
			restore_current_blog();
		}
	}

	private function update_game_type_status( int $site_id, int $game_type_id, string $status ): void {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$wpdb->update(
				\BingoCommerce\Catalog\Migrations::game_types_table(),
				[ 'status' => $status, 'archived_at' => 'archived' === $status ? current_time( 'mysql', true ) : null ],
				[ 'game_type_id' => $game_type_id ],
				[ '%s', 'archived' === $status ? '%s' : null ],
				[ '%d' ]
			);
		} finally {
			restore_current_blog();
		}
	}

	private function update_game_type_name( int $site_id, int $game_type_id, string $name ): void {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$wpdb->update(
				\BingoCommerce\Catalog\Migrations::game_types_table(),
				[ 'name' => $name ],
				[ 'game_type_id' => $game_type_id ],
				[ '%s' ],
				[ '%d' ]
			);
		} finally {
			restore_current_blog();
		}
	}

	private function delete_game_type( int $site_id, int $game_type_id ): void {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$wpdb->delete( \BingoCommerce\Catalog\Migrations::game_types_table(), [ 'game_type_id' => $game_type_id ], [ '%d' ] );
		} finally {
			restore_current_blog();
		}
	}

	private function site_prefix( int $site_id ): string {
		global $wpdb;

		return $wpdb->get_blog_prefix( $site_id );
	}

	private function write_marker_value( int $site_id, string $value ): void {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s",
					$value,
					PortalSyncState::OPTION_NAME
				)
			);
			PortalSyncState::invalidateCache();
		} finally {
			restore_current_blog();
		}
	}

	private function delete_marker( int $site_id ): void {
		global $wpdb;

		switch_to_blog( $site_id );
		try {
			$wpdb->delete( $wpdb->options, [ 'option_name' => PortalSyncState::OPTION_NAME ], [ '%s' ] );
			PortalSyncState::invalidateCache();
		} finally {
			restore_current_blog();
		}
	}

	private function table_engine( string $table ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
	}

	private function disposable_engine( int $site_id, string $table, string $engine ): void {
		global $wpdb;

		if ( $site_id <= 4 || empty( $this->disposable_sites[ $site_id ] ) ) {
			throw new \RuntimeException( 'Refusing a storage-engine change outside a current-run disposable hall.' );
		}
		if ( ! in_array( $engine, [ 'InnoDB', 'MyISAM' ], true ) ) {
			throw new \RuntimeException( 'Unsupported test storage engine.' );
		}
		if ( $this->site_prefix( $site_id ) . 'bingo_portal_session_templates' !== $table ) {
			throw new \RuntimeException( 'Refusing a storage-engine change outside the disposable replica table.' );
		}

		if ( false === $wpdb->query( "ALTER TABLE `{$table}` ENGINE = {$engine}" ) ) {
			throw new \RuntimeException( 'Disposable storage-engine change failed.' );
		}
	}

	private function probe_index( int $site_id, string $action ): void {
		global $wpdb;

		if ( $site_id <= 4 || empty( $this->disposable_sites[ $site_id ] ) ) {
			throw new \RuntimeException( 'Refusing structural SQL outside a current-run disposable hall.' );
		}

		switch_to_blog( $site_id );
		try {
			$table = \BingoCommerce\Sessions\Migrations::portal_session_templates_table();
			$sql   = 'add' === $action
				? "ALTER TABLE `{$table}` ADD UNIQUE KEY `bc_rollback_probe` (`name`(64))"
				: "ALTER TABLE `{$table}` DROP INDEX `bc_rollback_probe`";
			if ( false === $wpdb->query( $sql ) ) {
				throw new \RuntimeException( 'Disposable rollback-probe index change failed.' );
			}
		} finally {
			restore_current_blog();
		}
	}

	private function create_disposable_site( string $label ): int {
		add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'portal3e' . wp_generate_password( 8, false, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'Portal 3E Disposable Hall ' . $label,
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

	/* ------------------------------------------------------------------ *
	 * Isolation snapshots and cleanup
	 * ------------------------------------------------------------------ */

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * @param array<int,int> $site_ids
	 * @param array<int,string> $suffixes
	 * @return array<string,string>
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
				$rows = (array) $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
				usort( $rows, static fn( array $left, array $right ): int => strcmp( serialize( $left ), serialize( $right ) ) );
				$snapshot[ $table ] = hash( 'sha256', serialize( $rows ) );
			}
		}
		ksort( $snapshot );

		return $snapshot;
	}

	/**
	 * @param array<int,int> $site_ids
	 * @param array<int,string> $suffixes
	 * @return array<string,string>
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
				$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
				$snapshot[ $table ] = is_array( $row ) ? (string) ( $row[1] ?? '' ) : '';
			}
		}
		ksort( $snapshot );

		return $snapshot;
	}

	/** @return array<int,string> */
	private function domain_table_suffixes(): array {
		return array_merge(
			$this->catalog_table_suffixes(),
			[ 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates' ]
		);
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

		$table = PortalMigrations::hall_mappings_table();
		if ( ! $this->table_exists( $table ) ) {
			return [];
		}

		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

		return [
			'ddl'  => is_array( $row ) ? (string) ( $row[1] ?? '' ) : '',
			'rows' => (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY portal_hall_id", ARRAY_A ),
		];
	}

	private function cleanup(): void {
		global $wpdb;

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array_keys( $this->bound_mappings ) as $portal_hall_id ) {
			$portal_hall_id = (int) $portal_hall_id;
			// The range must cover every HALL_* constant above. It is deliberately
			// a hard refusal rather than a filter: a mapping this suite did not
			// create must never be removed, and adding a hall without widening
			// this range should fail loudly — as it did when HALL_G was added.
			if ( $portal_hall_id < 990001 || $portal_hall_id > 990007 ) {
				throw new \RuntimeException( 'Refusing to remove a Portal Hall mapping outside the test range.' );
			}
			$wpdb->delete( PortalMigrations::hall_mappings_table(), [ 'portal_hall_id' => $portal_hall_id ], [ '%d' ] );
			unset( $this->bound_mappings[ $portal_hall_id ] );
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			if ( $site_id <= 4 ) {
				throw new \RuntimeException( 'Refusing to clean up a site ID outside the disposable range.' );
			}
			$prefix  = $wpdb->get_blog_prefix( $site_id );
			$tables  = array_map( static fn( string $suffix ): string => $prefix . $suffix, $this->domain_table_suffixes() );

			wp_delete_site( $site_id );
			$allowed = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . $site_id . '_bingo_(game_types|play_products|packages|package_components|package_addons|sessions|session_packages|portal_session_templates)$/';
			foreach ( $tables as $table ) {
				if ( 1 === preg_match( $allowed, $table ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}

			// wp_delete_site() removes the site row, its blogmeta and its per-site
			// tables, but the registration log is a Multisite-global table that no
			// core deletion path touches, so every disposable hall would otherwise
			// leave a permanent row behind. Scoped to this one blog_id, which the
			// guard above proves is a disposable site created by this run.
			$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $site_id ], [ '%d' ] );

			$this->deleted_site_tables[ $site_id ] = $tables;
			unset( $this->disposable_sites[ $site_id ] );
		}
	}

	private function verify_disposable_cleanup(): void {
		global $wpdb;

		foreach ( $this->deleted_site_tables as $site_id => $tables ) {
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $site_id ) ),
				"disposable hall {$site_id} record was removed"
			);
			foreach ( $tables as $table ) {
				$this->assert_same(
					0,
					(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', DB_NAME, $table ) ),
					"disposable hall {$site_id} table {$table} was removed"
				);
			}
			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->registration_log} WHERE blog_id = %d", $site_id ) ),
				"disposable hall {$site_id} registration log rows were removed"
			);
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

	/**
	 * @param mixed $expected
	 * @param mixed $actual
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

( new PortalSnapshotApplicationTest() )->run();
