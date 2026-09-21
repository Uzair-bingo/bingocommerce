<?php

/**
 * CLI integration coverage for Phase 3G: the manual hall-scoped Portal
 * synchronization screen (ADR-041).
 *
 * Exercises the REAL registered admin page and the REAL registered
 * `admin_post_bc_portal_sync` handler. Handler invocations run in a subprocess
 * (tests/portal-sync-admin-worker.php) because the production handler ends in
 * exit() or wp_die(); that termination is part of what is under test.
 *
 * The Portal is never contacted, and the operator's Portal configuration never
 * becomes the effective runtime constants — see the bootstrap note below. Every
 * HTTP attempt is short-circuited at `pre_http_request` inside the worker, and
 * the worker defines its own throwaway fixture constants.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_test_suites php tests/portal-sync-admin.php
 *
 * THE DATABASE NAME IS NOW REQUIRED (ADR-043 §16 amendment). This suite creates
 * disposable Multisite SITES, and `bingocommerce-sessions` is network-active, so
 * every wp_insert_site() fires its `wp_initialize_site` handler and provisions
 * the CURRENT Sessions schema into whichever database is booted. Once ADR-043
 * raised that schema to v3, the previous invocation — plain
 * `php tests/portal-sync-admin.php`, which fell through the shared bootstrap to
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

/** The two Portal constants this suite decides, and the only ones it touches. */
const BC_PSA_PORTAL_CONSTANTS = [
	'BINGOCOMMERCE_PORTAL_BASE_URL',
	'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN',
];

/** Disposable fixture configuration. Never an operator value. */
const BC_PSA_FIXTURE_BASE_URL = 'http://portal-3g-fixture.invalid';
const BC_PSA_FIXTURE_TOKEN    = 'phase-3g-fixture-token';

/*
 * Defined BEFORE wp-load.php, so the page can reach its Ready state on
 * disposable configuration.
 *
 * wp-config.php executes and evaluates its own definitions, the Portal ones
 * included; that is not claimed otherwise. What holds is that these earlier
 * definitions remain AUTHORITATIVE, because PHP keeps the first definition of a
 * constant and ignores every later one. The operator's values therefore never
 * become the effective runtime Portal constants, are not used to build an
 * Authorization header or a Portal request, and no disclosure of them was
 * observed in this suite's output, its redirect URLs or its audit context.
 */
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', BC_PSA_FIXTURE_BASE_URL );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', BC_PSA_FIXTURE_TOKEN );

/**
 * Is this diagnostic the duplicate definition of one of OUR two constants?
 *
 * The whole suppression rule, in one testable place, and it takes BOTH halves
 * of the diagnostic. Severity is part of the identity of what we are willing to
 * swallow: PHP raises constant redefinition as E_WARNING, so anything arriving
 * at another severity is a different event even when the text coincides, and a
 * message-only rule would swallow a deliberately crafted E_USER_WARNING. Exact
 * string equality on the complete message, for exactly the two names above.
 */
function bc_psa_is_portal_redefinition( int $errno, string $message ): bool {
	if ( E_WARNING !== $errno ) {
		return false;
	}

	foreach ( BC_PSA_PORTAL_CONSTANTS as $bc_psa_name ) {
		if ( "Constant {$bc_psa_name} already defined" === $message ) {
			return true;
		}
	}

	return false;
}

/*
 * If wp-config.php defines these names too, it will now raise one "already
 * defined" warning each. Those are counted and swallowed; NOTHING else is.
 *
 * HOW MANY there are is deliberately NOT asserted as a fixed number: it depends
 * on whether the local, git-ignored wp-config.php happens to define these
 * constants, and pinning it to two would make this suite pass only on machines
 * configured like one developer's. The portable invariants are asserted
 * instead — only Portal redefinitions are suppressed, everything else is
 * declined, and the runtime constants are whatever this file predefined.
 */
$bc_psa_suppressed = 0;
$bc_psa_declined   = 0;
set_error_handler(
	static function ( int $errno, string $message ) use ( &$bc_psa_suppressed, &$bc_psa_declined ): bool {
		if ( bc_psa_is_portal_redefinition( $errno, $message ) ) {
			++$bc_psa_suppressed;

			return true;
		}

		// False hands the diagnostic back to PHP's own reporting, untouched.
		++$bc_psa_declined;

		return false;
	}
);

try {
	/*
	 * This suite asserts on the Hall Manager role's NATIVE baseline, which
	 * Roles::sync_hall_manager() copies from the LIVE WooCommerce Shop Manager
	 * role. WooCommerce is network-active on the development installation, so in
	 * fixture mode it has to be asked for explicitly — otherwise the baseline is
	 * absent, the versioned role sync correctly refuses to record itself as
	 * complete, and the suite would be measuring the fixture rather than the
	 * code. Ignored entirely outside fixture mode.
	 */
	if ( '' !== (string) getenv( 'BC_TEST_DB' ) && '' === (string) getenv( 'BC_FIXTURE_WOOCOMMERCE' ) ) {
		putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
	}

	require __DIR__ . '/fixtures/wordpress-bootstrap.php';
} finally {
	restore_error_handler();
}

/* The handler is really gone, not merely assumed gone. */
$bc_psa_probe_saw = false;
set_error_handler(
	static function ( int $errno, string $message ) use ( &$bc_psa_probe_saw ): bool {
		$bc_psa_probe_saw = ( 'bc-psa-handler-restoration-probe' === $message );

		return true;
	}
);
@trigger_error( 'bc-psa-handler-restoration-probe', E_USER_WARNING );
restore_error_handler();
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! class_exists( '\BingoCommerce\PortalIntegration\PortalSyncAdmin' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}
if ( false === has_action( 'admin_post_' . \BingoCommerce\PortalIntegration\PortalSyncAdmin::POST_ACTION ) ) {
	\BingoCommerce\PortalIntegration\Plugin::init();
}

// Safety belt: this parent process must never reach a real network. Handler
// scenarios run in the worker, which installs its own fixture boundary.
add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'phase_3g_outbound_http_blocked', 'Outbound HTTP is blocked in the Phase 3G harness.' );
	},
	PHP_INT_MAX
);

use BingoCommerce\Core\Roles;
use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\Migrations;
use BingoCommerce\PortalIntegration\PortalSyncAdmin;
use BingoCommerce\PortalIntegration\PortalSyncState;

final class PortalSyncAdminTest {

	/** Portal Hall IDs used only by this suite. They exist in no Portal. */
	private const HALL_A = 970101;
	private const HALL_B = 970102;
	private const HALL_C = 970103;

	private const PERSISTENT_SITES = [ 2, 3, 4 ];

	/** @var array<int,bool> */
	private array $disposable_sites = [];

	/** @var array<int,array<int,string>> */
	private array $disposable_tables = [];

	/** @var array<int,array<int,string>> */
	private array $deleted_site_tables = [];

	/** @var array<int,array{site_id:int,role:string}> */
	private array $disposable_users = [];

	/** @var array<int,bool> */
	private array $bound_mappings = [];

	/** @var array<int,array{id:int,blog_id:int,action:string}> */
	private array $audit_ids_removed = [];

	/** Highest WooCommerce session id present before this run created any. */
	private int $wc_session_high_water = 0;

	private int $assertions = 0;
	private int $super_admin_id = 0;
	private int $audit_high_water = 0;

	/** @var array<string,string> */
	private array $persistent_snapshot = [];

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->super_admin_id = $this->find_super_admin_id();
		$this->assert_true( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		$this->assert_true( Migrations::is_current(), 'Portal Hall mapping schema is current' );

		$this->persistent_snapshot = $this->snapshot_domain_tables( self::PERSISTENT_SITES );
		$this->audit_high_water    = $this->max_audit_id();

		/*
		 * WOOCOMMERCE'S OWN GUEST SESSIONS ARE RESIDUE TOO. This suite now opts
		 * into WooCommerce in fixture mode, and WC_Session_Handler writes a row
		 * to the network MAIN site's `woocommerce_sessions` table when it
		 * initialises. Nothing here asks for that and nothing else removes it,
		 * so the high-water mark is recorded before the first mutating group and
		 * cleanup() removes exactly the rows created above it.
		 */
		$this->wc_session_high_water = $this->wc_session_max_id();

		try {
			$this->capability_model_group();
			$this->registration_group();
			$this->authorization_group();
			$this->tenant_group();
			$this->result_group();
			$this->audit_group();
			$this->dependency_group();
			$this->render_group();
		} finally {
			$this->cleanup();
		}

		$this->assert_same(
			$this->persistent_snapshot,
			$this->snapshot_domain_tables( self::PERSISTENT_SITES ),
			'Sunrise, Tampa and Orlando domain rows are byte-identical after the suite'
		);
		$this->assert_same( [], $this->disposable_sites, 'every disposable site created by this run was destroyed' );
		$this->assert_same( [], $this->bound_mappings, 'every disposable mapping created by this run was removed' );
		$this->verify_disposable_cleanup();
		$this->verify_no_user_locks();

		echo "PASS: {$this->assertions} Phase 3G Portal sync admin assertions.\n";
	}

	/* ================================================================== *
	 * A. Capability model
	 * ================================================================== */

	private function capability_model_group(): void {
		$this->group( 'A. Capability model' );

		$this->assert_same( 2, Roles::SCHEMA_VERSION, 'role schema version advanced to 2' );
		$this->assert_true(
			in_array( 'bc_sync_portal', Roles::HALL_MANAGER_CAPS, true ),
			'bc_sync_portal is declared for Hall Manager'
		);
		$this->assert_false(
			in_array( 'bc_sync_portal', Roles::GAME_OPERATOR_CAPS, true ),
			'bc_sync_portal is NOT declared for Game Operator'
		);
		$this->assert_same(
			PortalSyncAdmin::CAPABILITY,
			'bc_sync_portal',
			'the admin boundary checks exactly bc_sync_portal'
		);

		// Every pre-existing Hall Manager capability survives the version bump.
		foreach ( [ 'bc_manage_hall', 'bc_manage_hall_staff', 'bc_manage_hall_settings', 'bc_manage_products', 'bc_manage_orders', 'bc_manage_sessions', 'bc_view_orders', 'bc_view_sessions', 'bc_redeem_tickets', 'bc_reactivate_tickets' ] as $cap ) {
			$this->assert_true( in_array( $cap, Roles::HALL_MANAGER_CAPS, true ), "Hall Manager retains {$cap}" );
		}
		$this->assert_same(
			[ 'bc_view_orders', 'bc_view_sessions', 'bc_redeem_tickets', 'bc_reactivate_tickets' ],
			Roles::GAME_OPERATOR_CAPS,
			'Game Operator capability set is unchanged'
		);

		// Existing-site convergence: an already-provisioned hall picks the new
		// capability up through the versioned sync, without disturbing anything else.
		switch_to_blog( 2 );
		$before_operator = $this->role_caps( Roles::GAME_OPERATOR );
		update_option( Roles::SITE_VERSION_OPTION, 1 );
		Roles::maybe_sync_current_site();
		$manager_role = get_role( Roles::HALL_MANAGER );
		$this->assert_true(
			$manager_role instanceof \WP_Role && ! empty( $manager_role->capabilities['bc_sync_portal'] ),
			'existing-site convergence grants Hall Manager bc_sync_portal'
		);
		$this->assert_same(
			$before_operator,
			$this->role_caps( Roles::GAME_OPERATOR ),
			'existing-site convergence leaves Game Operator capabilities untouched'
		);
		$this->assert_same(
			(string) Roles::SCHEMA_VERSION,
			(string) get_option( Roles::SITE_VERSION_OPTION ),
			'existing-site role schema version is recorded as current'
		);
		restore_current_blog();
	}

	/* ================================================================== *
	 * B. Registration
	 * ================================================================== */

	private function registration_group(): void {
		$this->group( 'B. Registration' );

		$this->assert_true(
			false !== has_action( 'admin_post_' . PortalSyncAdmin::POST_ACTION, [ PortalSyncAdmin::class, 'handle_post' ] ),
			'authenticated admin_post_bc_portal_sync handler is registered'
		);
		$this->assert_false(
			(bool) has_action( 'admin_post_nopriv_' . PortalSyncAdmin::POST_ACTION ),
			'NO admin_post_nopriv handler is registered'
		);
		$this->assert_true(
			false !== has_action( 'admin_menu', [ PortalSyncAdmin::class, 'add_menu' ] ),
			'the Portal Sync menu is registered on admin_menu'
		);
		$this->assert_same( 'bingocommerce-portal-sync', PortalSyncAdmin::PAGE_SLUG, 'page slug is stable' );

		// New-site provisioning: a freshly created hall receives the capability.
		$fresh = $this->create_disposable_site( 'ROLE' );
		switch_to_blog( $fresh );
		$role = get_role( Roles::HALL_MANAGER );
		$this->assert_true(
			$role instanceof \WP_Role && ! empty( $role->capabilities['bc_sync_portal'] ),
			'new-site provisioning grants Hall Manager bc_sync_portal'
		);
		$operator = get_role( Roles::GAME_OPERATOR );
		$this->assert_true(
			$operator instanceof \WP_Role && empty( $operator->capabilities['bc_sync_portal'] ),
			'new-site Game Operator does NOT receive bc_sync_portal'
		);
		restore_current_blog();

		// The menu exists in a hall and is absent on the main site.
		$this->assert_true( $this->menu_registers_for( $fresh, $this->user_with_role( $fresh, Roles::HALL_MANAGER ) ), 'the Portal Sync page registers inside a hall site' );
		$this->assert_false( $this->menu_registers_for( 1, $this->super_admin_id ), 'the Portal Sync page does NOT register on the main network site' );
	}

	/* ================================================================== *
	 * C. Authorization
	 * ================================================================== */

	private function authorization_group(): void {
		$this->group( 'C. Authorization' );

		$site    = $this->create_disposable_site( 'AUTH' );
		$manager = $this->user_with_role( $site, Roles::HALL_MANAGER );
		$op      = $this->user_with_role( $site, Roles::GAME_OPERATOR );
		$plain   = $this->user_with_role( $site, 'subscriber' );
		$this->bind( self::HALL_A, $site );

		switch_to_blog( $site );
		$this->assert_true( user_can( $manager, 'bc_sync_portal' ), 'Hall Manager holds bc_sync_portal in their hall' );
		$this->assert_false( user_can( $op, 'bc_sync_portal' ), 'Game Operator does NOT hold bc_sync_portal' );
		$this->assert_false( user_can( $plain, 'bc_sync_portal' ), 'an ordinary subscriber does NOT hold bc_sync_portal' );
		$this->assert_true( user_can( $this->super_admin_id, 'bc_sync_portal' ), 'Network Super Admin passes the capability check' );
		restore_current_blog();

		$body = $this->snapshot_body( self::HALL_A, '2031-01-01T10:00:00+00:00', 2, 2 );

		// Denials: every one must occur before any Portal fetch.
        foreach (
			[
				[ 'Game Operator', $op, [], 0 ],
				[ 'an ordinary subscriber', $plain, [], 0 ],
				[ 'a logged-out request', 0, [], 0 ],
			] as [ $label, $user, $extra, $expected_fetches ]
		) {
			$run = $this->worker( array_merge( [ 'site_id' => $site, 'user_id' => $user, 'configure' => true, 'body' => $body ], $extra ) );
			$this->assert_true( '' !== $run['wpdie'], "{$label} is refused by the handler" );
			$this->assert_same( '', $run['redirect'], "  {$label} receives no synchronization redirect" );
			$this->assert_same( $expected_fetches, $run['fetches'], "  {$label} triggers zero Portal fetches" );
		}

		// GET cannot synchronize.
		$run = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $body, 'method' => 'GET' ] );
		$this->assert_true( str_contains( $run['wpdie'], 'POST' ), 'a GET request is refused before anything else' );
		$this->assert_same( 0, $run['fetches'], '  and triggers zero Portal fetches' );

		// Nonce failures.
		foreach ( [ 'invalid', 'missing' ] as $mode ) {
			$run = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $body, 'nonce' => $mode ] );
			$this->assert_same( '', $run['redirect'], "a {$mode} nonce produces no synchronization redirect" );
			$this->assert_same( 0, $run['fetches'], "  and a {$mode} nonce triggers zero Portal fetches" );
		}

		// Super Admin may use the hall-local page.
		$run = $this->worker( [ 'site_id' => $site, 'user_id' => $this->super_admin_id, 'configure' => true, 'body' => $body ] );
		$this->assert_true( $this->outcome( $run ) === PortalSyncAdmin::OUTCOME_APPLIED, 'Network Super Admin can synchronize from the hall-local page' );

		$this->reset_hall( $site );
	}

	/* ================================================================== *
	 * D. Tenant boundary
	 * ================================================================== */

	private function tenant_group(): void {
		$this->group( 'D. Tenant boundary' );

		$site_a = $this->create_disposable_site( 'TENANT-A' );
		$site_b = $this->create_disposable_site( 'TENANT-B' );
		$this->bind( self::HALL_B, $site_a );
		$this->bind( self::HALL_C, $site_b );
		$manager_a = $this->user_with_role( $site_a, Roles::HALL_MANAGER );

		$before_b = $this->snapshot_domain_tables( [ $site_b ] );

		// Forged identifiers naming the OTHER hall must be ignored entirely.
		$run = $this->worker(
			[
				'site_id'   => $site_a,
				'user_id'   => $manager_a,
				'configure' => true,
				'body'      => $this->snapshot_body( self::HALL_B, '2031-02-01T10:00:00+00:00', 1, 1 ),
				'forged'    => [
					'blog_id'        => (string) $site_b,
					'site_id'        => (string) $site_b,
					'portal_hall_id' => (string) self::HALL_C,
					'mapping_id'     => (string) self::HALL_C,
					'network_id'     => '99',
				],
			]
		);
		$this->assert_same( PortalSyncAdmin::OUTCOME_APPLIED, $this->outcome( $run ), 'forged identifiers do not prevent the legitimate hall from synchronizing' );
		$this->assert_same( $before_b, $this->snapshot_domain_tables( [ $site_b ] ), 'forged identifiers did NOT retarget the other hall' );

		switch_to_blog( $site_a );
		$state_a = PortalSyncState::readUncached();
		restore_current_blog();
		$this->assert_true( is_array( $state_a ) && self::HALL_B === $state_a['portal_hall_id'], 'hall A marker records hall A only' );

		switch_to_blog( $site_b );
		$this->assert_same( null, PortalSyncState::readUncached(), 'hall B has no sync marker after hall A synchronized' );
		restore_current_blog();

		// The reciprocal direction.
		$before_a = $this->snapshot_domain_tables( [ $site_a ] );
		$manager_b = $this->user_with_role( $site_b, Roles::HALL_MANAGER );
		$run = $this->worker(
			[
				'site_id'   => $site_b,
				'user_id'   => $manager_b,
				'configure' => true,
				'body'      => $this->snapshot_body( self::HALL_C, '2031-02-01T11:00:00+00:00', 3, 1 ),
			]
		);
		$this->assert_same( PortalSyncAdmin::OUTCOME_APPLIED, $this->outcome( $run ), 'hall B synchronizes independently' );
		$this->assert_same( $before_a, $this->snapshot_domain_tables( [ $site_a ] ), 'hall B synchronization left hall A byte-identical' );

		// Main site cannot synchronize.
		$run = $this->worker( [ 'site_id' => 1, 'user_id' => $this->super_admin_id, 'configure' => true, 'body' => '' ] );
		$this->assert_true( '' !== $run['wpdie'], 'the main network site cannot synchronize' );
		$this->assert_same( 0, $run['fetches'], '  and triggers zero Portal fetches' );

		// Archived / deleted / spam sites and a removed mapping.
		$flagged = $this->create_disposable_site( 'FLAGGED' );
		$this->bind( 970104, $flagged );
		$manager_f = $this->user_with_role( $flagged, Roles::HALL_MANAGER );
		foreach ( [ 'archived', 'spam', 'deleted' ] as $flag ) {
			$this->set_site_flag( $flagged, $flag, 1 );
			$run = $this->worker( [ 'site_id' => $flagged, 'user_id' => $manager_f, 'configure' => true, 'body' => '' ] );
			$this->assert_same( 'site_ineligible', $this->error_code( $run ), "an {$flag} site is refused with the site_ineligible category" );
			$this->assert_same( 0, $run['fetches'], "  and an {$flag} site triggers zero Portal fetches" );
			$this->set_site_flag( $flagged, $flag, 0 );
		}

		$unmapped = $this->create_disposable_site( 'UNMAPPED' );
		$manager_u = $this->user_with_role( $unmapped, Roles::HALL_MANAGER );
		$run = $this->worker( [ 'site_id' => $unmapped, 'user_id' => $manager_u, 'configure' => true, 'body' => '' ] );
		$this->assert_same( 'not_linked', $this->error_code( $run ), 'an unmapped hall is refused with the not_linked category' );
		$this->assert_same( 0, $run['fetches'], '  and an unmapped hall triggers zero Portal fetches' );

		// A disabled mapping is not an active mapping.
		$repo = new HallMappingRepository();
		$repo->disable( 970104 );
		$run = $this->worker( [ 'site_id' => $flagged, 'user_id' => $manager_f, 'configure' => true, 'body' => '' ] );
		$this->assert_same( 'not_linked', $this->error_code( $run ), 'a disabled mapping refuses synchronization' );
		$this->assert_same( 0, $run['fetches'], '  and a disabled mapping triggers zero Portal fetches' );
		$repo->enable( 970104 );
	}

	/* ================================================================== *
	 * E. Results and notices
	 * ================================================================== */

	private function result_group(): void {
		$this->group( 'E. Results and notices' );

		$site    = $this->create_disposable_site( 'RESULT' );
		$manager = $this->user_with_role( $site, Roles::HALL_MANAGER );
		$this->bind( 970105, $site );

		$body = $this->snapshot_body( 970105, '2031-03-01T10:00:00+00:00', 2, 3 );
		$run  = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $body ] );

		/*
		 * CONFIGURED SCENARIOS RUN ON DISPOSABLE VALUES, and that is asserted
		 * rather than assumed. The worker reports equality against its own
		 * fixture constants, so neither value is emitted here or there; what is
		 * proved is that wp-config.php did not supply what PortalConfig read.
		 */
		$this->assert_same( 2, $run['portal_defined'], 'a configured worker defines both Portal constants' );
		$this->assert_same( 0, $run['portal_empty'], '  neither of them empty' );
		$this->assert_same( 1, $run['portal_matches'], '  and both RETAIN the disposable fixture values' );
		$this->assert_same( 'configured', $run['portal_config'], '  so PortalConfig reports a usable configuration' );
		$this->assert_suppression_is_selective( $run, 'a configured worker' );

		$this->assert_true(
			defined( 'BINGOCOMMERCE_PORTAL_BASE_URL' )
				&& BC_PSA_FIXTURE_BASE_URL === constant( 'BINGOCOMMERCE_PORTAL_BASE_URL' )
				&& defined( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' )
				&& BC_PSA_FIXTURE_TOKEN === constant( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' ),
			"this suite's own runtime constants RETAIN the disposable fixture values"
		);
		$this->assert_parent_suppression_is_selective();

		$this->assert_same( PortalSyncAdmin::OUTCOME_APPLIED, $this->outcome( $run ), 'a first synchronization redirects with the applied outcome' );
		$this->assert_same( 1, $run['fetches'], '  and performed exactly one Portal fetch' );
		$this->assert_same( 303, $run['status'], '  and redirects with HTTP 303, not a default 302' );

		// Identical content, strictly newer stamp: a domain no-op.
		$run = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $this->snapshot_body( 970105, '2031-03-01T11:00:00+00:00', 2, 3 ) ] );
		$this->assert_same( PortalSyncAdmin::OUTCOME_NO_CHANGE, $this->outcome( $run ), 'an unchanged snapshot redirects with the no_change outcome' );
		$this->assert_same( 1, $run['fetches'], '  and performed exactly one Portal fetch' );
		$this->assert_same( 303, $run['status'], '  and redirects with HTTP 303' );

		// The redirect must carry no counts, messages or identifiers.
		$query = $this->redirect_query( $run );
		$this->assert_same( [ 'bc_sync', 'page' ], $this->sorted_keys( $query ), 'a success redirect carries only page and bc_sync' );
		foreach ( [ '970105', (string) $site, 'game_types', 'session_templates', 'Portal', 'portal-3g-fixture' ] as $needle ) {
			$this->assert_false( str_contains( $run['redirect'], $needle ), "the success redirect does not leak '{$needle}'" );
		}

		// Failure categories, each from a real production error code.
		$cases = [
			[ 'credentials rejected (401)', [ 'http' => 'status', 'http_status' => 401 ], 'credentials_rejected' ],
			[ 'credentials rejected (403)', [ 'http' => 'status', 'http_status' => 403 ], 'credentials_rejected' ],
			[ 'hall inaccessible (404)', [ 'http' => 'status', 'http_status' => 404 ], 'hall_inaccessible' ],
			[ 'unexpected status (500)', [ 'http' => 'status', 'http_status' => 500 ], 'portal_unexpected' ],
			[ 'transport failure', [ 'http' => 'wp_error' ], 'portal_unreachable' ],
			[ 'invalid JSON', [ 'body' => 'not json at all' ], 'portal_unexpected' ],
			[ 'invalid contract', [ 'body' => '{"api_version":"v1"}' ], 'portal_unexpected' ],
			// A payload naming another hall is caught by the Phase 3E hall-identity
			// re-check, which is more specific than a generic contract failure.
			[ 'wrong hall in payload', [ 'body' => $this->snapshot_body( 970999, '2031-03-02T10:00:00+00:00', 1, 1 ) ], 'hall_mismatch' ],
			[ 'stale snapshot', [ 'body' => $this->snapshot_body( 970105, '2030-01-01T10:00:00+00:00', 2, 3 ) ], 'snapshot_stale' ],
		];
		foreach ( $cases as [ $label, $extra, $expected ] ) {
			$run = $this->worker( array_merge( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $body ], $extra ) );
			$this->assert_same( PortalSyncAdmin::OUTCOME_ERROR, $this->outcome( $run ), "{$label} redirects with the error outcome" );
			$this->assert_same( $expected, $this->error_code( $run ), "  {$label} maps to the {$expected} category" );
			$this->assert_same( 303, $run['status'], "  {$label} redirects with HTTP 303" );
		}

		/*
		 * MISSING CONFIGURATION.
		 *
		 * This scenario used to be unreachable on any installation whose
		 * wp-config.php defines the Portal constants: the worker omitted its
		 * fixture values, wp-load.php then supplied the operator's real ones,
		 * PortalConfig succeeded, and the run failed somewhere further down
		 * instead. The worker now DEFINES both names itself before wp-load, so
		 * what PortalConfig reads is settled by this suite rather than by the
		 * environment.
		 */
		$before_domain  = $this->snapshot_domain_tables( [ $site ] );
		$before_mapping = $this->mapping_snapshot();
		$before_marker  = $this->sync_marker_for( $site );
		$before_audit   = $this->max_audit_id();

		$run = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => false, 'body' => $body ] );

		/*
		 * The premise, established inside the worker rather than hoped for.
		 *
		 * Both constants are DEFINED and EMPTY. Defining them is what makes the
		 * scenario deterministic: wp-config.php cannot replace a constant that
		 * already exists, so the operator's values never become the runtime
		 * ones. PortalConfig then rejects the empty base URL.
		 *
		 * The code is `portal_base_url_invalid`, not `portal_base_url_missing`:
		 * an empty constant is present, so the `! defined()` branches do not
		 * fire and the `'' === $base_url` check answers first. Both codes are
		 * allowlisted to the same `not_configured` category, which is what the
		 * admin screen reduces to and what this scenario is about.
		 */
		$this->assert_same( 2, $run['portal_defined'], 'the unconfigured worker defines BOTH Portal constants' );
		$this->assert_same( 2, $run['portal_empty'], '  and both of them RETAIN the empty value' );
		$this->assert_same( 0, $run['portal_matches'], '  so neither holds the disposable fixture value' );
		$this->assert_suppression_is_selective( $run, 'the unconfigured worker' );
		$this->assert_same(
			'portal_base_url_invalid',
			$run['portal_config'],
			'  PortalConfig rejects the empty base URL rather than reading a configured one'
		);

		$this->assert_same( 'not_configured', $this->error_code( $run ), 'missing Portal configuration maps to the not_configured category' );
		$this->assert_same( PortalSyncAdmin::OUTCOME_ERROR, $this->outcome( $run ), '  with the error outcome' );
		$this->assert_same( 0, $run['fetches'], '  and attempts zero Portal fetches' );

		// Nothing was written anywhere it matters.
		$this->assert_same( $before_domain, $this->snapshot_domain_tables( [ $site ] ), '  no Game Type or Session-template row changed' );
		$this->assert_same( $before_mapping, $this->mapping_snapshot(), '  the Hall mapping is byte-identical' );
		$this->assert_same( $before_marker, $this->sync_marker_for( $site ), '  no sync marker was written or advanced' );
		$this->assert_same( 0, $this->held_sync_locks(), '  no advisory lock is left held' );

		/*
		 * The audit row IS expected. ADR-025/BR-033 make the failure record
		 * mandatory, and PortalSyncAdmin::finish() writes exactly one for every
		 * refused attempt — so this asserts the recorded outcome, rather than
		 * pretending a privileged action went unrecorded.
		 */
		$audit = $this->audit_rows_since( $before_audit );
		$this->assert_same( 1, count( $audit ), '  exactly one audit row records the refusal' );
		$this->assert_same( PortalSyncAdmin::AUDIT_FAILED, (string) ( $audit[0]['action'] ?? '' ), '    as a failed synchronization' );
		$this->assert_true(
			str_contains( (string) ( $audit[0]['context'] ?? '' ), '"error_category":"not_configured"' ),
			'    carrying the allowlisted category and nothing else'
		);

		$this->assert_false(
			str_contains( $run['raw'], 'already defined' ),
			'  and no redefinition warning escaped into the worker output'
		);
		$this->assert_false(
			str_contains( $run['raw'], 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' ),
			'  the worker output names no Portal credential constant'
		);

		/*
		 * NO CONFIGURATION VALUE ANYWHERE, tested against the values this suite
		 * itself controls.
		 *
		 * The operator's values are evaluated by wp-config.php but are not the
		 * effective runtime constants, so they are not what this code path
		 * carries. What is demonstrated here is that the path discloses no
		 * configuration value at all: the disposable base URL and token travel
		 * exactly where any Portal configuration would, and neither reaches the
		 * worker output, the redirect URL, or the audit context. Anything
		 * following the same route would have to surface in one of those three.
		 */
		$audit_context = (string) ( $audit[0]['context'] ?? '' );
		foreach (
			[
				'the disposable base URL' => BC_PSA_FIXTURE_BASE_URL,
				'the disposable token'    => BC_PSA_FIXTURE_TOKEN,
			] as $what => $value
		) {
			$this->assert_false( str_contains( $run['raw'], $value ), "  {$what} never appears in the worker output" );
			$this->assert_false( str_contains( $run['redirect'], $value ), "  {$what} never appears in the redirect URL" );
			$this->assert_false( str_contains( $audit_context, $value ), "  {$what} never appears in the audit context" );
		}

		// And the refusal happened before any transport: no Portal URL was even
		// built, let alone dispatched.
		$this->assert_false( str_contains( $run['raw'], '/integration/v1/halls/' ), '  no Portal snapshot URL was constructed' );

		// Every emitted category is allowlisted, and the error messages resolve.
		$messages = $this->error_message_keys();
		$this->assert_true( in_array( 'unknown', $messages, true ), 'the notice table defines an unknown fallback' );
		// 53 since Commerce access enforcement added hall_commerce_blocked,
		// which PortalSyncService returns before any lock or Portal request.
		$this->assert_same( 53, $this->declared_category_mappings(), 'the production error-category table contains 53 mappings' );
		foreach ( $this->declared_categories() as $category ) {
			$this->assert_true( in_array( $category, $messages, true ), "category {$category} has a fixed notice" );
		}
	}

	/* ================================================================== *
	 * F. Audit
	 * ================================================================== */

	private function audit_group(): void {
		$this->group( 'F. Audit' );

		$site    = $this->create_disposable_site( 'AUDIT' );
		$manager = $this->user_with_role( $site, Roles::HALL_MANAGER );
		$this->bind( 970106, $site );

		$mark = $this->max_audit_id();
		$run  = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $this->snapshot_body( 970106, '2031-04-01T10:00:00+00:00', 1, 2 ) ] );
		$this->assert_same( PortalSyncAdmin::OUTCOME_APPLIED, $this->outcome( $run ), 'applied synchronization for the audit fixture' );

		$rows = $this->audit_rows_since( $mark );
		$this->assert_same( 1, count( $rows ), 'an applied synchronization writes exactly one audit row' );
		$row = $rows[0];
		$this->assert_same( PortalSyncAdmin::AUDIT_SYNCHRONIZED, $row['action'], '  with action portal.snapshot.synchronized' );
		$this->assert_same( $site, (int) $row['blog_id'], '  attributed to the trusted current site' );
		$this->assert_same( $manager, (int) $row['actor_user_id'], '  attributed to the authenticated actor' );
		$this->assert_same( '970106', (string) $row['target_id'], '  targeting the mapped Portal Hall' );
		$this->assert_true( str_contains( (string) $row['context'], '"outcome":"applied"' ), '  recording outcome applied' );
		foreach ( [ 'game_types_created', 'game_types_renamed', 'session_templates_created', 'session_templates_updated', 'session_templates_marked_not_current' ] as $key ) {
			$this->assert_true( str_contains( (string) $row['new_value'], $key ), "  carrying the {$key} count" );
		}
		$this->assert_no_secrets( (string) $row['new_value'] . (string) $row['context'], 'the applied audit row' );

		// No-op.
		$mark = $this->max_audit_id();
		$run  = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $this->snapshot_body( 970106, '2031-04-01T11:00:00+00:00', 1, 2 ) ] );
		$this->assert_same( PortalSyncAdmin::OUTCOME_NO_CHANGE, $this->outcome( $run ), 'no-op synchronization for the audit fixture' );
		$rows = $this->audit_rows_since( $mark );
		$this->assert_same( 1, count( $rows ), 'a no-op writes exactly one audit row' );
		$this->assert_same( PortalSyncAdmin::AUDIT_SYNCHRONIZED, $rows[0]['action'], '  with action portal.snapshot.synchronized' );
		$this->assert_true( str_contains( (string) $rows[0]['context'], '"outcome":"no_change"' ), '  recording outcome no_change' );

		// Failure.
		$mark = $this->max_audit_id();
		$run  = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'http' => 'status', 'http_status' => 401, 'body' => '' ] );
		$this->assert_same( 'credentials_rejected', $this->error_code( $run ), 'failed synchronization for the audit fixture' );
		$rows = $this->audit_rows_since( $mark );
		$this->assert_same( 1, count( $rows ), 'a failure writes exactly one audit row' );
		$this->assert_same( PortalSyncAdmin::AUDIT_FAILED, $rows[0]['action'], '  with action portal.snapshot.failed' );
		$this->assert_true( str_contains( (string) $rows[0]['context'], '"error_category":"credentials_rejected"' ), '  recording the allowlisted category' );
		$this->assert_same( null, $rows[0]['new_value'], '  and no counts' );
		$this->assert_no_secrets( (string) $rows[0]['context'], 'the failed audit row' );

		// Audit failure must not rewrite the synchronization outcome.
		$mark = $this->max_audit_id();
		$run  = $this->worker(
			[
				'site_id'    => $site,
				'user_id'    => $manager,
				'configure'  => true,
				'audit_fail' => true,
				'body'       => $this->snapshot_body( 970106, '2031-04-01T12:00:00+00:00', 1, 2 ),
			]
		);
		$this->assert_same( PortalSyncAdmin::OUTCOME_NO_CHANGE, $this->outcome( $run ), 'an audit write failure does NOT change the true sync outcome' );
		$query = $this->redirect_query( $run );
		$this->assert_same( '1', (string) ( $query['bc_sync_audit'] ?? '' ), '  and raises only the fixed audit warning flag' );
		$this->assert_same( 0, count( $this->audit_rows_since( $mark ) ), '  with no audit row written' );

		// A THROWN audit failure must behave identically to a returned WP_Error:
		// the exception may not escape, and may not replace a committed result.
		$mark  = $this->max_audit_id();
		$before_rows = $this->snapshot_domain_tables( [ $site ] );
		$run   = $this->worker(
			[
				'site_id'     => $site,
				'user_id'     => $manager,
				'configure'   => true,
				'audit_throw' => true,
				'body'        => $this->snapshot_body( 970106, '2031-04-01T13:00:00+00:00', 1, 2 ),
			]
		);
		$this->assert_same( PortalSyncAdmin::OUTCOME_NO_CHANGE, $this->outcome( $run ), 'a THROWN audit failure does NOT change the true sync outcome' );
		$this->assert_same( 303, $run['status'], '  and still redirects with HTTP 303' );
		$query = $this->redirect_query( $run );
		$this->assert_same( '1', (string) ( $query['bc_sync_audit'] ?? '' ), '  raising only the fixed audit warning flag' );
		$this->assert_same( '', $run['wpdie'], '  the exception did not escape as a fatal or wp_die' );
		$this->assert_same( 0, count( $this->audit_rows_since( $mark ) ), '  with no audit row written' );
		$this->assert_same( 1, $run['fetches'], '  the synchronization ran exactly once and was not repeated' );
		$this->assert_same( $before_rows, $this->snapshot_domain_tables( [ $site ] ), '  and committed domain rows were neither rolled back nor re-applied' );
		foreach ( [ 'SECRET-audit-explosion', 'RuntimeException', 'Stack trace', 'phase3g_audit_failure_fixture_missing', 'errno', 'INSERT INTO', 'bc_audit_log' ] as $needle ) {
			$this->assert_false( str_contains( $run['raw'], $needle ), "  no '{$needle}' leaked from the thrown audit failure" );
		}
	}

	/* ================================================================== *
	 * G. Dependency and concurrency
	 * ================================================================== */

	private function dependency_group(): void {
		$this->group( 'G. Dependency and concurrency' );

		$this->assert_same(
			[],
			\BingoCommerce\PortalIntegration\Plugin::missing_sync_dependencies(),
			'the Catalog and Sessions dependencies are present in this environment'
		);

		// The handler owns no lock of its own; Phase 3E remains the only lock.
		// Comments are stripped first so prose about the design cannot be
		// mistaken for executable code.
		$source = $this->executable_source( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/src/PortalSyncAdmin.php' );
		foreach ( [ 'set_transient', 'get_transient', 'GET_LOCK', 'RELEASE_LOCK', 'wp_cache_add' ] as $forbidden ) {
			$this->assert_false( str_contains( $source, $forbidden ), "PortalSyncAdmin adds no {$forbidden} of its own" );
		}
		$this->assert_same(
			1,
			substr_count( $source, 'PortalSyncService::synchronize(' ),
			'PortalSyncService::synchronize() is called from exactly one place'
		);

		// Real same-hall lock contention returns the fixed retry notice.
		$site    = $this->create_disposable_site( 'LOCK' );
		$manager = $this->user_with_role( $site, Roles::HALL_MANAGER );
		$this->bind( 970107, $site );

		// Held for longer than PortalSyncLock::TIMEOUT_SECONDS (5), so GET_LOCK
		// genuinely times out instead of succeeding once the holder releases.
		$worker = popen(
			escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/portal-snapshot-sync-worker.php' ) . ' ' . (int) $site . ' 9000 2>/dev/null',
			'r'
		);
		$this->assert_true( is_resource( $worker ), 'the Phase 3E lock worker started' );
		$line = fgets( $worker );
		$this->assert_true( is_string( $line ) && str_contains( $line, 'LOCKED' ), '  and holds the same-hall lock' );

		$run = $this->worker( [ 'site_id' => $site, 'user_id' => $manager, 'configure' => true, 'body' => $this->snapshot_body( 970107, '2031-05-01T10:00:00+00:00', 1, 1 ) ] );
		$this->assert_same( 'lock_busy', $this->error_code( $run ), 'same-hall lock contention maps to the lock_busy category' );
		$this->assert_same( 0, $run['fetches'], '  and performs zero Portal fetches while the lock is held' );

		while ( false !== fgets( $worker ) ) {
			continue;
		}
		pclose( $worker );
	}

	/* ================================================================== *
	 * H. Rendered page
	 * ================================================================== */

	private function render_group(): void {
		$this->group( 'H. Rendered page' );

		$site    = $this->create_disposable_site( 'RENDER' );
		$manager = $this->user_with_role( $site, Roles::HALL_MANAGER );
		$this->bind( 970108, $site );

		switch_to_blog( $site );
		$previous = get_current_user_id();
		wp_set_current_user( $manager );

		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG, 'bc_sync' => 'applied' ];
		$html = $this->capture_render();
		$this->assert_true( str_contains( $html, 'Portal data synchronized successfully.' ), 'the applied notice renders' );
		$this->assert_true( str_contains( $html, 'Not linked' ) || str_contains( $html, '970108' ), 'the mapped Portal Hall is shown' );
		$this->assert_true( str_contains( $html, 'role="status"' ), 'success notices are announced accessibly' );

		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG, 'bc_sync' => 'no_change' ];
		$this->assert_true( str_contains( $this->capture_render(), 'already up to date' ), 'the no_change notice renders' );

		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG, 'bc_sync' => 'error', 'bc_sync_code' => 'lock_busy' ];
		$html = $this->capture_render();
		$this->assert_true( str_contains( $html, 'already running for this hall' ), 'a known error category renders its fixed notice' );
		$this->assert_true( str_contains( $html, 'role="alert"' ), 'error notices are announced accessibly' );

		// A forged category cannot select anything but the fallback.
		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG, 'bc_sync' => 'error', 'bc_sync_code' => '<script>alert(1)</script>' ];
		$html = $this->capture_render();
		$this->assert_true( str_contains( $html, 'Synchronization failed. Nothing was saved.' ), 'an unknown category falls back to the fixed notice' );
		$this->assert_false( str_contains( $html, '<script>' ), '  and cannot inject markup' );

		// A forged outcome renders no notice at all.
		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG, 'bc_sync' => 'totally-made-up' ];
		$html = $this->capture_render();
		$this->assert_false( str_contains( $html, 'notice-success' ), 'an unrecognised outcome renders no success notice' );
		$this->assert_false( str_contains( $html, 'notice-error' ), '  and renders no error notice' );

		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG, 'bc_sync' => 'applied', 'bc_sync_audit' => '1' ];
		$this->assert_true( str_contains( $this->capture_render(), 'could not be recorded in the audit log' ), 'the audit warning renders when flagged' );

		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG ];
		$html = $this->capture_render();
		$this->assert_true( str_contains( $html, 'admin-post.php' ), 'the form posts to admin-post.php' );
		$this->assert_true( str_contains( $html, 'name="action" value="bc_portal_sync"' ), '  carrying the fixed action' );
		$this->assert_true( str_contains( $html, 'name="_wpnonce"' ), '  and a nonce field' );
		$this->assert_true( str_contains( $html, '<button type="submit"' ), '  with a real button element' );
		foreach ( [ 'blog_id', 'site_id', 'portal_hall_id', 'mapping_id', 'network_id' ] as $field ) {
			$this->assert_false( str_contains( $html, 'name="' . $field . '"' ), "  and no {$field} field" );
		}
		$this->assert_no_secrets( $html, 'the rendered page' );

		// Readiness with a genuine active mapping.
		$this->assert_true( str_contains( $html, '970108' ), 'a linked hall shows its mapped Portal Hall ID' );
		$this->assert_true( str_contains( $html, 'Ready' ), '  and reports Ready' );
		$this->assert_true( str_contains( $html, 'Last successful synchronization (UTC)' ), 'the marker timestamp is labelled UTC' );

		wp_set_current_user( $previous );
		restore_current_blog();

		// A hall with NO active mapping must say Not linked — and nothing else.
		$unmapped = $this->create_disposable_site( 'READY-UNMAPPED' );
		$um_manager = $this->user_with_role( $unmapped, Roles::HALL_MANAGER );
		switch_to_blog( $unmapped );
		$previous = get_current_user_id();
		wp_set_current_user( $um_manager );
		$_GET = [ 'page' => PortalSyncAdmin::PAGE_SLUG ];
		$html = $this->capture_render();
		$this->assert_true( str_contains( $html, 'Not linked' ), 'a genuinely unmapped hall reports Not linked' );
		$this->assert_false( str_contains( $html, 'Synchronization unavailable' ), '  and NOT Synchronization unavailable' );
		$this->assert_false( str_contains( $html, '<button type="submit"' ), '  with no Synchronize now button' );

		// An unavailable mapping SYSTEM must NOT be reported as Not linked. The
		// schema marker is filtered, not written, so nothing is mutated.
		$drop_schema = static function () {
			return 0;
		};
		add_filter( 'pre_site_option_' . Migrations::NETWORK_VERSION_OPTION, $drop_schema, PHP_INT_MAX );
		$this->assert_false( Migrations::is_current(), 'the mapping schema reads as unavailable under the filter' );
		$html = $this->capture_render();
		remove_filter( 'pre_site_option_' . Migrations::NETWORK_VERSION_OPTION, $drop_schema, PHP_INT_MAX );

		$this->assert_true( str_contains( $html, 'Synchronization unavailable' ), 'an unavailable mapping schema reports Synchronization unavailable' );
		$this->assert_false( str_contains( $html, 'Not linked' ), '  and must NOT claim the hall is Not linked' );
		$this->assert_false( str_contains( $html, '<button type="submit"' ), '  with no Synchronize now button' );
		$this->assert_no_secrets( $html, 'the unavailable-mapping page' );
		foreach ( [ 'bingo_portal_hall_mappings', 'mapping_schema_unavailable', 'mapping_read_failed', 'WP_Error' ] as $needle ) {
			$this->assert_false( str_contains( $html, $needle ), "  and discloses no '{$needle}'" );
		}
		$this->assert_true( Migrations::is_current(), 'the mapping schema reads as current again once the filter is removed' );

		$_GET = [];
		wp_set_current_user( $previous );
		restore_current_blog();
	}

	/* ------------------------------------------------------------------ *
	 * Fixtures and helpers
	 * ------------------------------------------------------------------ */

	/** PHP source with every comment removed, so prose cannot be read as code. */
	private function executable_source( string $path ): string {
		$out = '';
		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
					continue;
				}
				$out .= $token[1];
				continue;
			}
			$out .= $token;
		}

		return $out;
	}

	private function capture_render(): string {
		ob_start();
		PortalSyncAdmin::render();

		return (string) ob_get_clean();
	}

	/** Assert that a blob discloses nothing sensitive. */
	private function assert_no_secrets( string $blob, string $label ): void {
		foreach (
			[
				'phase-3g-fixture-token' => 'a token',
				'Authorization'          => 'an Authorization header',
				'Bearer'                 => 'a bearer credential',
				'portal-3g-fixture'      => 'the endpoint host',
				'/integration/v1/'       => 'the endpoint path',
				'SELECT '                => 'SQL',
				'INSERT '                => 'SQL',
				'bc:ps:'                 => 'a lock name',
				'errno'                  => 'a database errno',
				'api_version'            => 'a snapshot fragment',
			] as $needle => $what
		) {
			$this->assert_false( str_contains( $blob, $needle ), "{$label} discloses no {$what}" );
		}
	}

	/** A validator-shaped Portal snapshot for a hall that exists in no Portal. */
	private function snapshot_body( int $hall_id, string $generated_at, int $game_types, int $sessions ): string {
		$types = [];
		for ( $i = 1; $i <= $game_types; $i++ ) {
			$types[] = [
				'id'         => ( $hall_id * 100 ) + $i,
				'hall_id'    => $hall_id,
				'name'       => 'P3G Type ' . $i,
				'updated_at' => null,
			];
		}
		$templates = [];
		for ( $i = 1; $i <= $sessions; $i++ ) {
			$templates[] = [
				'id'         => ( $hall_id * 100 ) + $i,
				'hall_id'    => $hall_id,
				'name'       => 'P3G Session ' . $i,
				'status'     => 1 === $i % 2 ? 'active' : 'draft',
				'updated_at' => null,
			];
		}

		return (string) wp_json_encode(
			[
				'api_version'  => 'v1',
				'generated_at' => $generated_at,
				'hall'         => [
					'id'         => $hall_id,
					'name'       => 'P3G Disposable Hall',
					'state'      => 'active',
					'updated_at' => null,
				],
				'game_types'   => $types,
				'sessions'     => $templates,
			]
		);
	}

	/**
	 * Run one handler invocation in its own process.
	 *
	 * @param array<string,mixed> $config
	 * @return array{redirect:string,status:int,wpdie:string,fetches:int,raw:string}
	 */
	private function worker( array $config ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/portal-sync-admin-worker.php' )
			. ' ' . escapeshellarg( base64_encode( (string) wp_json_encode( $config ) ) ) . ' 2>&1';

		$raw = (string) shell_exec( $cmd );

		$redirect          = '';
		$wpdie             = '';
		$fetches           = 0;
		$status            = 0;
		$portal_defined    = -1;
		$portal_empty      = -1;
		$portal_matches    = -1;
		$portal_suppressed = -1;
		$portal_declined   = -1;
		$portal_restored   = -1;
		$portal_predicate  = -1;
		$portal_config     = '';
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( str_starts_with( $line, 'REDIRECT=' ) ) {
				$redirect = substr( $line, 9 );
			} elseif ( str_starts_with( $line, 'STATUS=' ) ) {
				$status = (int) substr( $line, 7 );
			} elseif ( str_starts_with( $line, 'WPDIE=' ) ) {
				$wpdie = substr( $line, 6 );
			} elseif ( str_starts_with( $line, 'FETCHES=' ) ) {
				$fetches = max( $fetches, (int) substr( $line, 8 ) );
			} elseif ( str_starts_with( $line, 'PORTAL_DEFINED=' ) ) {
				$portal_defined = (int) substr( $line, 15 );
			} elseif ( str_starts_with( $line, 'PORTAL_EMPTY=' ) ) {
				$portal_empty = (int) substr( $line, 13 );
			} elseif ( str_starts_with( $line, 'PORTAL_MATCHES_FIXTURE=' ) ) {
				$portal_matches = (int) substr( $line, 23 );
			} elseif ( str_starts_with( $line, 'PORTAL_SUPPRESSED=' ) ) {
				$portal_suppressed = (int) substr( $line, 18 );
			} elseif ( str_starts_with( $line, 'PORTAL_DECLINED=' ) ) {
				$portal_declined = (int) substr( $line, 16 );
			} elseif ( str_starts_with( $line, 'PORTAL_HANDLER_RESTORED=' ) ) {
				$portal_restored = (int) substr( $line, 24 );
			} elseif ( str_starts_with( $line, 'PORTAL_PREDICATE_OK=' ) ) {
				$portal_predicate = (int) substr( $line, 20 );
			} elseif ( str_starts_with( $line, 'PORTAL_CONFIG=' ) ) {
				$portal_config = substr( $line, 14 );
			}
		}

		return [
			'redirect'          => $redirect,
			'status'            => $status,
			'wpdie'             => $wpdie,
			'fetches'           => $fetches,
			'portal_defined'    => $portal_defined,
			'portal_empty'      => $portal_empty,
			'portal_matches'    => $portal_matches,
			'portal_suppressed' => $portal_suppressed,
			'portal_declined'   => $portal_declined,
			'portal_restored'   => $portal_restored,
			'portal_predicate'  => $portal_predicate,
			'portal_config'     => $portal_config,
			'raw'               => $raw,
		];
	}

	/** @param array{redirect:string} $run */
	private function outcome( array $run ): string {
		return (string) ( $this->redirect_query( $run )['bc_sync'] ?? '' );
	}

	/** @param array{redirect:string} $run */
	private function error_code( array $run ): string {
		return (string) ( $this->redirect_query( $run )['bc_sync_code'] ?? '' );
	}

	/**
	 * @param array{redirect:string} $run
	 * @return array<string,string>
	 */
	private function redirect_query( array $run ): array {
		if ( '' === $run['redirect'] ) {
			return [];
		}
		$query = (string) wp_parse_url( $run['redirect'], PHP_URL_QUERY );
		$out   = [];
		parse_str( $query, $out );

		return array_map( 'strval', $out );
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<int,string>
	 */
	private function sorted_keys( array $query ): array {
		$keys = array_keys( $query );
		sort( $keys );

		return $keys;
	}

	/**
	 * Categories declared by the ERROR_CATEGORIES table.
	 *
	 * Scoped to that constant only: scraping the whole file would also pick up
	 * unrelated array values such as the readiness mapping_status keys.
	 *
	 * @return array<int,string>
	 */
	private function declared_categories(): array {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/src/PortalSyncAdmin.php' );
		if ( 1 !== preg_match( '/private const ERROR_CATEGORIES = \[(.*?)\n\t\];/s', $source, $block ) ) {
			throw new \RuntimeException( 'The ERROR_CATEGORIES table could not be located.' );
		}
		preg_match_all( "/^\s*'[a-z_]+'\s*=> '([a-z_]+)',/m", $block[1], $matches );

		return array_values( array_unique( $matches[1] ?? [] ) );
	}

	/** Number of production error codes mapped by ERROR_CATEGORIES. */
	private function declared_category_mappings(): int {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/src/PortalSyncAdmin.php' );
		preg_match( '/private const ERROR_CATEGORIES = \[(.*?)\n\t\];/s', $source, $block );

		return preg_match_all( "/^\s*'[a-z_]+'\s*=> '[a-z_]+',/m", $block[1] ?? '' );
	}

	/** @return array<int,string> */
	private function error_message_keys(): array {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/src/PortalSyncAdmin.php' );
		preg_match_all( "/^\s*'([a-z_]+)'\s*=> __\(/m", $source, $matches );

		return array_values( array_unique( $matches[1] ?? [] ) );
	}

	private function menu_registers_for( int $site_id, int $user_id ): bool {
		global $admin_page_hooks, $_registered_pages, $menu, $submenu;

		switch_to_blog( $site_id );
		$previous_user = get_current_user_id();
		wp_set_current_user( $user_id );

		$saved = [ $admin_page_hooks, $_registered_pages, $menu, $submenu ];
		$admin_page_hooks  = [];
		$_registered_pages = [];
		$menu              = [];
		$submenu           = [];

		PortalSyncAdmin::add_menu();
		$registered = isset( $admin_page_hooks[ PortalSyncAdmin::PAGE_SLUG ] );

		[ $admin_page_hooks, $_registered_pages, $menu, $submenu ] = $saved;

		wp_set_current_user( $previous_user );
		restore_current_blog();

		return $registered;
	}

	/** @return array<string,bool> */
	private function role_caps( string $role_slug ): array {
		$role = get_role( $role_slug );

		return $role instanceof \WP_Role ? $role->capabilities : [];
	}

	/**
	 * Create a disposable user and give it a role on a disposable hall.
	 *
	 * Hall Manager and Game Operator are PROTECTED roles: HallStaffWriterGuard
	 * blocks any attempt to write their capability meta directly, including
	 * add_user_to_blog(). The guard is not bypassed here — the fixture goes
	 * through HallStaffService, which the codebase documents as the canonical
	 * and only supported application writer for those roles. Unprotected roles
	 * take the ordinary WordPress path.
	 */
	private function user_with_role( int $site_id, string $role ): int {
		$login = 'p3g' . strtolower( wp_generate_password( 10, false, false ) );
		$email = $login . '@phase3g.invalid';

		// wpmu_create_user() is what HallStaffProvisioningService uses: it strips
		// the empty primary-site capability rows wp_insert_user() would leave
		// behind, which BR-036 eligibility rejects as non-operational.
		$user_id = wpmu_create_user( $login, wp_generate_password( 24, true, true ), $email );
		if ( ! is_int( $user_id ) || $user_id < 1 ) {
			throw new \RuntimeException( 'Disposable user could not be created.' );
		}

		$this->disposable_users[ $user_id ] = [ 'site_id' => $site_id, 'role' => $role ];

		$protected = [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ];

		switch_to_blog( $site_id );
		$previous_user = get_current_user_id();

		if ( in_array( $role, $protected, true ) ) {
			wp_set_current_user( $this->super_admin_id );
			$assigned = \BingoCommerce\Core\HallStaffService::assign_existing_user( $email, $role );
			if ( is_wp_error( $assigned ) ) {
				wp_set_current_user( $previous_user );
				restore_current_blog();

				throw new \RuntimeException( 'Disposable staff assignment failed: ' . $assigned->get_error_code() );
			}
		} else {
			add_user_to_blog( $site_id, $user_id, $role );
		}

		wp_set_current_user( $previous_user );
		restore_current_blog();

		return $user_id;
	}

	private function bind( int $portal_hall_id, int $blog_id ): void {
		$mapping = ( new HallMappingRepository() )->bind( $portal_hall_id, $blog_id );
		if ( is_wp_error( $mapping ) ) {
			throw new \RuntimeException( 'Disposable mapping could not be created: ' . $mapping->get_error_code() );
		}
		$this->bound_mappings[ $portal_hall_id ] = true;
	}

	/** Clear a disposable hall's replica rows and marker between scenarios. */
	private function reset_hall( int $site_id ): void {
		global $wpdb;

		if ( empty( $this->disposable_sites[ $site_id ] ) ) {
			throw new \RuntimeException( 'Refusing to reset a non-disposable hall.' );
		}

		switch_to_blog( $site_id );
		delete_option( PortalSyncState::OPTION_NAME );
		restore_current_blog();

		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( [ 'bingo_game_types', 'bingo_portal_session_templates' ] as $suffix ) {
			$table = $prefix . $suffix;
			if ( 1 === preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . $site_id . '_bingo_(game_types|portal_session_templates)$/', $table ) ) {
				$wpdb->query( "DELETE FROM `{$table}`" );
			}
		}
	}

	private function create_disposable_site( string $label ): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'portal3g' . wp_generate_password( 8, false, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'Portal 3G Disposable Hall ' . $label,
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			throw new \RuntimeException( 'Disposable site could not be created: ' . $site->get_error_message() );
		}

		$site_id = (int) $site;
		if ( $site_id <= 4 ) {
			throw new \RuntimeException( 'Refusing to treat a persistent site as disposable.' );
		}

		$this->disposable_sites[ $site_id ]  = true;
		$this->disposable_tables[ $site_id ] = $this->domain_table_names( $site_id );

		return $site_id;
	}

	private function set_site_flag( int $site_id, string $flag, int $value ): void {
		if ( empty( $this->disposable_sites[ $site_id ] ) || ! in_array( $flag, [ 'deleted', 'archived', 'spam' ], true ) ) {
			throw new \RuntimeException( 'Refusing to mutate a non-disposable site flag.' );
		}

		update_blog_status( $site_id, $flag, $value );
		clean_blog_cache( $site_id );
	}

	/** @return array<int,string> */
	private function domain_table_names( int $site_id ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $site_id );

		return array_map(
			static fn( string $suffix ): string => $prefix . $suffix,
			[ 'bingo_game_types', 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_package_addons', 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates' ]
		);
	}

	/**
	 * @param array<int,int> $site_ids
	 * @return array<string,string>
	 */
	private function snapshot_domain_tables( array $site_ids ): array {
		global $wpdb;

		$snapshot = [];
		foreach ( $site_ids as $site_id ) {
			foreach ( $this->domain_table_names( (int) $site_id ) as $table ) {
				if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
					$snapshot[ $table ] = '(absent)';
					continue;
				}
				$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
				$parts = [];
				foreach ( (array) $rows as $row ) {
					$parts[] = wp_json_encode( $row );
				}
				sort( $parts, SORT_STRING );
				$snapshot[ $table ] = md5( implode( "\n", $parts ) );
			}
		}

		return $snapshot;
	}

	private function max_audit_id(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . \BingoCommerce\Core\Migrations::audit_log_table() );
	}

	/** @return array<int,array<string,mixed>> */
	private function audit_rows_since( int $id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, blog_id, actor_user_id, action, target_type, target_id, new_value, context FROM '
				. \BingoCommerce\Core\Migrations::audit_log_table() . ' WHERE id > %d ORDER BY id',
				$id
			),
			ARRAY_A
		);
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

	/* ------------------------------------------------------------------ *
	 * Cleanup
	 * ------------------------------------------------------------------ */

	private function cleanup(): void {
		global $wpdb;

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		$this->remove_wc_session_residue();

		foreach ( array_keys( $this->bound_mappings ) as $portal_hall_id ) {
			$portal_hall_id = (int) $portal_hall_id;
			if ( $portal_hall_id < 970101 || $portal_hall_id > 970199 ) {
				throw new \RuntimeException( 'Refusing to remove a Portal Hall mapping outside the Phase 3G test range.' );
			}
			$wpdb->delete( Migrations::hall_mappings_table(), [ 'portal_hall_id' => $portal_hall_id ], [ '%d' ] );
			unset( $this->bound_mappings[ $portal_hall_id ] );
		}

		// Protected hall roles must be released through HallStaffService.
		// HallStaffWriterGuard blocks direct capability-meta deletion — including
		// the one wp_delete_site() would attempt — so removing the staff record
		// first is what actually lets the usermeta go.
		foreach ( $this->disposable_users as $user_id => $meta ) {
			$user_id = (int) $user_id;
			$site_id = (int) $meta['site_id'];

			if ( in_array( $meta['role'], [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ], true ) && null !== get_site( $site_id ) ) {
				switch_to_blog( $site_id );
				$previous_user = get_current_user_id();
				wp_set_current_user( $this->super_admin_id );
				\BingoCommerce\Core\HallStaffService::remove_staff( $user_id );
				wp_set_current_user( $previous_user );
				restore_current_blog();
			}

			wpmu_delete_user( $user_id );
			unset( $this->disposable_users[ $user_id ] );
		}

		// Audit rows this run produced for its own disposable halls. BOTH guards
		// must hold: the row must be newer than the opening high-water mark AND
		// its blog_id must be a site this exact run created. A deleted Hall is
		// NOT sufficient grounds on its own, and historical or persistent-Hall
		// rows are never touched.
		$this->audit_ids_removed = [];
		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			if ( $site_id <= 4 ) {
				throw new \RuntimeException( 'Refusing to clean up audit rows outside the disposable range.' );
			}

			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, blog_id, action FROM ' . \BingoCommerce\Core\Migrations::audit_log_table()
					. ' WHERE id > %d AND blog_id = %d ORDER BY id',
					$this->audit_high_water,
					$site_id
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				if ( (int) $row['id'] <= $this->audit_high_water || (int) $row['blog_id'] !== $site_id ) {
					continue; // defensive: never widen the guard
				}
				$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
				$this->audit_ids_removed[] = [ 'id' => (int) $row['id'], 'blog_id' => $site_id, 'action' => (string) $row['action'] ];
			}
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			if ( $site_id <= 4 ) {
				throw new \RuntimeException( 'Refusing to clean up a site ID outside the disposable range.' );
			}

			foreach ( [ 'deleted', 'archived', 'spam' ] as $flag ) {
				update_blog_status( $site_id, $flag, 0 );
			}
			clean_blog_cache( $site_id );

			wp_delete_site( $site_id );

			$allowed = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . $site_id . '_bingo_(game_types|play_products|packages|package_components|package_addons|sessions|session_packages|portal_session_templates)$/';
			foreach ( $this->disposable_tables[ $site_id ] ?? [] as $table ) {
				if ( 1 === preg_match( $allowed, $table ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}

			// wp_delete_site() never touches the Multisite-global registration
			// log, so each disposable hall would otherwise leave a row behind.
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
			$this->assert_same(
				0,
				(int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
						$wpdb->esc_like( $wpdb->get_blog_prefix( (int) $site_id ) ) . '%'
					)
				),
				"disposable site {$site_id} user roles and usermeta were removed"
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

		$this->assert_same( [], $this->disposable_users, 'every disposable user created by this run was destroyed' );

		// No audit row created by this run may survive for any disposable hall.
		foreach ( array_keys( $this->deleted_site_tables ) as $site_id ) {
			$this->assert_same(
				0,
				(int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . \BingoCommerce\Core\Migrations::audit_log_table()
						. ' WHERE id > %d AND blog_id = %d',
						$this->audit_high_water,
						(int) $site_id
					)
				),
				"disposable site {$site_id} audit rows above the opening high-water mark were removed"
			);
		}
		echo 'NOTE: current-run audit rows removed: ' . count( $this->audit_ids_removed )
			. ( $this->audit_ids_removed ? ' -> ' . wp_json_encode( $this->audit_ids_removed ) : '' ) . "\n";
	}

	/**
	 * The error handler suppressed only what it was allowed to, in a worker.
	 *
	 * The COUNT is bounded rather than fixed. Whether wp-config.php redefines
	 * these constants is a property of the developer's ignored configuration
	 * file, so zero, one or two duplicate warnings are all legitimate; what must
	 * hold on every machine is that nothing outside that narrow set was touched.
	 *
	 * @param array<string,mixed> $run
	 */
	private function assert_suppression_is_selective( array $run, string $label ): void {
		$suppressed = (int) $run['portal_suppressed'];

		$this->assert_true(
			$suppressed >= 0 && $suppressed <= 2,
			"{$label}: between 0 and 2 Portal redefinition warnings were suppressed (observed {$suppressed})"
		);
		$this->assert_same(
			1,
			$run['portal_predicate'],
			"  {$label}: the suppression rule matches ONLY those two exact redefinition messages"
		);
		/*
		 * INFORMATIONAL ONLY. A nonnegative counter proves the line parsed, not
		 * that anything was declined — and requiring a POSITIVE count would put
		 * us right back to depending on whether the developer's private
		 * wp-config.php happens to raise other diagnostics. Selectivity is
		 * proved by the errno-and-message controls instead.
		 */
		$this->assert_true(
			(int) $run['portal_declined'] >= 0,
			"  {$label}: the declined-diagnostic counter was reported (observed " . (int) $run['portal_declined'] . ')'
		);
		$this->assert_same(
			1,
			$run['portal_restored'],
			"  {$label}: the handler was removed after bootstrap, proven by an independent probe"
		);
		$this->assert_false(
			str_contains( $run['raw'], 'already defined' ),
			"  {$label}: no redefinition warning escaped into the output"
		);
	}

	/** The same guarantees for this suite's own bootstrap. */
	private function assert_parent_suppression_is_selective(): void {
		$suppressed = (int) ( $GLOBALS['bc_psa_suppressed'] ?? -1 );

		$this->assert_true(
			$suppressed >= 0 && $suppressed <= 2,
			"this suite's bootstrap suppressed between 0 and 2 Portal redefinition warnings (observed {$suppressed})"
		);
		// Informational, for the same reason as above: a nonnegative counter
		// proves only that the counter exists.
		$this->assert_true(
			(int) ( $GLOBALS['bc_psa_declined'] ?? -1 ) >= 0,
			'  and reported its declined-diagnostic counter (observed '
				. (int) ( $GLOBALS['bc_psa_declined'] ?? -1 ) . ')'
		);
		$this->assert_true(
			true === ( $GLOBALS['bc_psa_probe_saw'] ?? false ),
			'  and its handler was removed after bootstrap, proven by an independent probe'
		);

		/*
		 * THE RULE ITSELF — the only thing that actually proves selectivity.
		 * Both halves of the diagnostic are exercised: severity and message.
		 */
		$approved = [
			'the base URL redefinition' => 'Constant BINGOCOMMERCE_PORTAL_BASE_URL already defined',
			'the token redefinition'    => 'Constant BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN already defined',
		];

		// Positive controls: E_WARNING plus each exact approved message.
		foreach ( $approved as $what => $message ) {
			$this->assert_true(
				bc_psa_is_portal_redefinition( E_WARNING, $message ),
				"  the rule matches E_WARNING plus {$what}"
			);
		}

		// Negative controls on SEVERITY: the same exact messages elsewhere.
		foreach (
			[ 'E_USER_WARNING' => E_USER_WARNING, 'E_NOTICE' => E_NOTICE, 'E_DEPRECATED' => E_DEPRECATED ] as $severity => $errno
		) {
			foreach ( $approved as $what => $message ) {
				$this->assert_false(
					bc_psa_is_portal_redefinition( $errno, $message ),
					"  and does NOT match {$what} raised as {$severity}"
				);
			}
		}

		// Negative controls on MESSAGE: right severity, wrong text.
		foreach (
			[
				'another constant'             => 'Constant WP_DEBUG already defined',
				'a database constant'          => 'Constant DB_PASSWORD already defined',
				'a near-miss with a full stop' => 'Constant BINGOCOMMERCE_PORTAL_BASE_URL already defined.',
				'a different diagnostic'       => 'Undefined variable $BINGOCOMMERCE_PORTAL_BASE_URL',
				'a bare constant name'         => 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN',
				'an empty constant name'       => 'Constant  already defined',
				'an empty message'             => '',
				'a deprecation'                => 'strlen(): Passing null to parameter #1 is deprecated',
			] as $what => $message
		) {
			$this->assert_false(
				bc_psa_is_portal_redefinition( E_WARNING, $message ),
				"  and does NOT match {$what}"
			);
		}
	}

	/** The whole Hall mapping table, hashed. @return string */
	private function mapping_snapshot(): string {
		global $wpdb;

		$table = \BingoCommerce\PortalIntegration\Migrations::hall_mappings_table();
		$rows  = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY portal_hall_id", ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			// An unreadable table must never hash the same as an empty one.
			return 'unreadable:' . wp_generate_uuid4();
		}

		return md5( (string) wp_json_encode( $rows ) );
	}

	/** One Hall's sync marker, read uncached. @return string */
	private function sync_marker_for( int $site_id ): string {
		switch_to_blog( $site_id );
		try {
			$marker = PortalSyncState::readUncached();
		} finally {
			restore_current_blog();
		}

		return (string) wp_json_encode( $marker );
	}

	/** User-level MySQL locks currently held anywhere on this server. */
	private function held_sync_locks(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_TYPE = 'USER LEVEL LOCK'"
		);
	}

	private function verify_no_user_locks(): void {
		global $wpdb;

		$held = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_TYPE = 'USER LEVEL LOCK'"
		);
		$this->assert_same( 0, $held, 'no user-level MySQL locks remain after the suite' );
	}

	/* ------------------------------------------------------------------ *
	 * Assertions
	 * ------------------------------------------------------------------ */

	private function group( string $name ): void {
		echo "\n-- {$name}\n";
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	/** The main site's WooCommerce session table, or '' when it does not exist. */
	private function wc_session_table(): string {
		global $wpdb;

		$table = $wpdb->base_prefix . 'woocommerce_sessions';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return '';
		}

		$found = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table ? $table : '';
	}

	private function wc_session_max_id(): int {
		global $wpdb;

		$table = $this->wc_session_table();

		return '' === $table ? 0 : (int) $wpdb->get_var( "SELECT COALESCE(MAX(session_id), 0) FROM `{$table}`" );
	}

	/**
	 * Remove ONLY the WooCommerce guest sessions this run caused.
	 *
	 * Bounded by the high-water mark taken before the first mutating group, so a
	 * row that was already in the fixture is never touched. A second sweep runs
	 * last on `shutdown`, because WC_Session_Handler saves at priority 20 —
	 * after every assertion this harness can make — and would otherwise write a
	 * session back once the first sweep had already passed.
	 */
	private function remove_wc_session_residue(): void {
		global $wpdb;

		$table = $this->wc_session_table();
		if ( '' === $table ) {
			return;
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE session_id > %d", $this->wc_session_high_water ) );

		$this->assert_same(
			$this->wc_session_high_water,
			$this->wc_session_max_id(),
			'the WooCommerce guest sessions this run created were removed'
		);

		$high_water = $this->wc_session_high_water;
		add_action(
			'shutdown',
			static function () use ( $table, $high_water ): void {
				global $wpdb;

				if ( $wpdb instanceof wpdb ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE session_id > %d", $high_water ) );
				}
			},
			PHP_INT_MAX
		);
	}

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

( new PortalSyncAdminTest() )->run();
