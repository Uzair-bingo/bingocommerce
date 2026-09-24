<?php

/**
 * CLI integration coverage for ADR-043's Manager administration screens.
 *
 * Covers SessionScheduleAdmin and SessionTemplateAdmin: the §15 workflow, §13.1
 * handler order and hall isolation, and §16 M105/M106. Every assertion drives
 * the REAL screens and the REAL SessionScheduleService — no production class is
 * stubbed, and no scheduling rule is re-stated here.
 *
 * HOW `wp_die()` AND `exit` ARE OBSERVED. A refusal is the behaviour under
 * test, so it has to be caught rather than avoided: the `wp_die_handler` filter
 * throws AdminDied carrying the message and HTTP status, and the `wp_redirect`
 * filter throws AdminRedirected carrying the location — which also stops
 * handle_generate()'s `exit` from terminating this process. Neither filter
 * changes what the screen does; they observe the point it hands control back to
 * WordPress.
 *
 * ISOLATED DATABASE ONLY. This suite creates disposable Multisite sites, so it
 * must never boot the development installation.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_recon php tests/session-schedule-admin-increment-1.php
 * Set BC_TEST_CHROME to an existing Chrome/Chromium executable to also exercise
 * the rendered date controls with WordPress's real bundled picker, offline.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$bc_target = (string) getenv( 'BC_TEST_DB' );
if ( '' === $bc_target ) {
	fwrite( STDERR, "BC_TEST_DB is not set. This suite creates disposable sites, so it must\n" );
	fwrite( STDERR, "never run against the development database.\n" );
	fwrite( STDERR, "  BC_TEST_DB=bingocommerce_state_recon php tests/session-schedule-admin-increment-1.php\n" );
	exit( 1 );
}
if ( ! in_array( $bc_target, [ 'bingocommerce_state_test', 'bingocommerce_state_test_suites', 'bingocommerce_state_recon' ], true ) ) {
	fwrite( STDERR, "BC_TEST_DB='{$bc_target}' is not an isolated fixture database for this suite.\n" );
	exit( 1 );
}
unset( $bc_target );

$_SERVER['REQUEST_URI']    = '/wp-admin/admin.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( ! defined( 'DB_NAME' ) || ! in_array( DB_NAME, [ 'bingocommerce_state_test', 'bingocommerce_state_test_suites', 'bingocommerce_state_recon' ], true ) ) {
	fwrite( STDERR, "Refusing to run: the active database is not an isolated fixture.\n" );
	exit( 1 );
}
if ( ! class_exists( '\BingoCommerce\Catalog\PackageService' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
}
if ( ! class_exists( '\BingoCommerce\Sessions\SessionScheduleAdmin' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/bingocommerce-sessions.php';
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Catalog\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Catalog\Plugin::init();
}
if ( false === has_action( 'wp_initialize_site', [ \BingoCommerce\Sessions\Migrations::class, 'provision_new_site' ] ) ) {
	\BingoCommerce\Sessions\Plugin::init();
}

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\ScheduleExpansion;
use BingoCommerce\Sessions\SessionAdmin;
use BingoCommerce\Sessions\SessionScheduleAdmin;
use BingoCommerce\Sessions\SessionScheduleResult;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionTemplateAdmin;

/** A captured wp_die(), with the status the screen chose. */
final class AdminDied extends \RuntimeException {

	public int $status;

	public function __construct( string $message, int $status ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

/** A captured redirect, which is also what stops handle_generate()'s exit. */
final class AdminRedirected extends \RuntimeException {}

final class SessionScheduleAdminTest {

	private const PERSISTENT = [ 2, 3, 4 ];
	private const PREFIX     = 'SADM1';
	private const ZONE       = 'America/New_York';

	private const NONCE_MUTATION = 'bingocommerce_session_schedule_mutation';
	private const NONCE_GENERATE = 'bingocommerce_session_schedule_generate';
	private const NONCE_TIMEZONE = 'bingocommerce_session_schedule_timezone_';

	private int $super_admin_id = 0;
	private int $no_cap_user_id = 0;
	private int $hall           = 0;
	private int $other_hall     = 0;

	/** The HTTP status of the most recent captured redirect. */
	private int $last_redirect_status = 0;

	/** @var array<int,bool> */
	private array $disposable_sites = [];
	/** @var array<int,int> */
	private array $created_users = [];
	/** @var array<string,array<string,mixed>> */
	private array $persistent_baseline = [];

	private string $group = '';
	/** @var array<string,array{pass:int,fail:int}> */
	private array $groups = [];
	/** @var array<int,string> */
	private array $notes = [];

	public function run(): void {
		$this->super_admin_id = $this->find_super_admin_id();

		$this->group( 'Setup' );
		$this->ok( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		wp_set_current_user( $this->super_admin_id );

		$this->persistent_baseline = $this->persistent_snapshot();

		add_filter( 'wp_die_handler', [ $this, 'die_handler' ], 1 );
		add_filter( 'wp_redirect', [ $this, 'redirect_handler' ], 1, 2 );
		add_filter( 'query', [ $this, 'observe_query' ], 1 );

		$this->hall       = $this->create_disposable_site();
		$this->other_hall = $this->create_disposable_site();
		$this->ok( $this->hall > 0 && $this->other_hall > 0, 'two disposable halls created' );

		foreach ( [ $this->hall, $this->other_hall ] as $site ) {
			switch_to_blog( $site );
			update_option( 'timezone_string', self::ZONE );
			restore_current_blog();
		}

		$this->no_cap_user_id = $this->create_user_without_capability();
		$this->ok( $this->no_cap_user_id > 0, 'a user WITHOUT bc_manage_sessions exists for the refusal cases' );

		try {
			$this->template_screen_group();
			$this->schedule_screen_group();
			$this->mutation_group();
			$this->past_date_group();
			$this->ui_correction_group();
			$this->manager_ui_group();
			$this->timezone_setup_group();
			$this->generate_handler_group();
			$this->handler_order_group();
			$this->hall_isolation_group();
			$this->boundary_group();
			$this->hidden_navigation_group();
			$this->direct_url_access_group();
			$this->orchestration_group();
		} finally {
			remove_filter( 'wp_die_handler', [ $this, 'die_handler' ], 1 );
			remove_filter( 'wp_redirect', [ $this, 'redirect_handler' ], 1 );
			remove_filter( 'query', [ $this, 'observe_query' ], 1 );
			$this->stop_intercepting();
			$this->query_counters = [];
			$this->cleanup();
		}

		$this->group( 'Preservation' );
		$this->same( $this->persistent_baseline, $this->persistent_snapshot(), 'persistent halls 2/3/4 unchanged by this suite' );

		$this->report();
	}

	/* ================================================================== *
	 * Synced Templates — read-only (§15 item 2)
	 * ================================================================== */

	private function template_screen_group(): void {
		$this->group( 'Sessions from Portal' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$eligible    = $this->plant_template( 'Eligible Bingo', 'active', 1 );
		$draft       = $this->plant_template( 'Draft Bingo', 'draft', 1 );
		$superseded  = $this->plant_template( 'Superseded Bingo', 'active', 0 );

		$html = $this->render_screen( [ SessionTemplateAdmin::class, 'render' ], [ 'page' => SessionTemplateAdmin::PAGE_SLUG ] );

		/* (5) The renamed label, everywhere a Manager reads it. */
		$this->ok( false !== strpos( $html, 'Sessions from Portal' ), 'the page is titled Sessions from Portal, not Synced Templates' );
		$this->ok( false === strpos( $html, 'Synced Templates' ), '  and the old label is gone entirely' );

		$this->ok( false !== strpos( $html, self::PREFIX . ' Eligible Bingo' ), 'the eligible Portal session is listed' );
		$this->ok( false !== strpos( $html, self::PREFIX . ' Draft Bingo' ), 'a DRAFT Portal session is listed too, with its status — §4.1 keeps it visible rather than making it disappear' );
		$this->ok( false !== strpos( $html, self::PREFIX . ' Superseded Bingo' ), 'a superseded revision is listed too' );
		$this->ok( false !== strpos( $html, 'Draft' ), '  the Portal status is shown' );
		$this->ok( false !== strpos( $html, 'Superseded' ), '  and so is the revision state' );

		/*
		 * (3) Exactly ONE "Add to Store" link, for the one eligible Portal
		 * session. The ineligible rows must offer no route to a schedule at
		 * all — not a disabled-looking one, and not a link that the commit
		 * would later refuse — and each states a Manager-readable reason.
		 */
		$this->ok( false !== strpos( $html, 'Add to Store' ), 'the eligible row offers "Add to Store"' );
		$this->same( 1, substr_count( $html, 'action=new' ), 'exactly ONE such link is offered' );
		$this->ok(
			false !== strpos( $html, 'portal_session_id=' . $eligible ),
			'  and it carries the ELIGIBLE Portal session\'s id'
		);
		$this->ok( false === strpos( $html, 'portal_session_id=' . $draft ), '  never the draft one\'s' );
		$this->ok( false === strpos( $html, 'portal_session_id=' . $superseded ), '  nor the superseded revision\'s' );
		$this->ok( false !== strpos( $html, 'Superseded revisions cannot be added to the store.' ), 'the superseded row states why, in Manager terms' );
		$this->ok( false !== strpos( $html, 'Only active Portal sessions can be added to the store.' ), 'the draft row states why, in Manager terms' );

		/* READ-ONLY, structurally: no form, no nonce, no submit anywhere. */
		$this->ok( false === strpos( $html, '<form' ), 'the screen renders NO form — it cannot mutate the replica' );
		$this->ok( false === strpos( $html, '_wpnonce' ), '  and no nonce, because it has nothing to protect' );
		$this->ok( false === strpos( $html, '<button' ), '  and no submit control' );

		/* It also registers no POST handler of any kind. */
		$this->same(
			0,
			substr_count( $this->code_only( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionTemplateAdmin.php' ), 'admin_post_' ),
			'SessionTemplateAdmin registers no admin_post_ handler, by enumeration of its own code'
		);

		restore_current_blog();

		/*
		 * (4) MULTIPLE SCHEDULES FOR ONE PORTAL SESSION. Nothing assumes a
		 * one-to-one relationship: two schedules are created for the SAME
		 * eligible Portal session, and both must be surfaced with their own
		 * "Manage Dates" link, each pointing at its OWN schedule id.
		 *
		 * This runs on its OWN disposable hall, not $this->hall: later
		 * groups (Mutations in particular) assert an EXACT schedule count in
		 * $this->hall, so a write-heavy sub-test that plants real schedules
		 * must not share that hall.
		 */
		$multi_hall = $this->create_disposable_site();
		switch_to_blog( $multi_hall );
		update_option( 'timezone_string', self::ZONE );
		wp_set_current_user( $this->super_admin_id );

		$multi_eligible = $this->plant_template( 'Multi Eligible Bingo', 'active', 1 );
		$service        = new SessionScheduleService();
		$pkg_a          = $this->make_package( 'Multi A' );
		$pkg_b          = $this->make_package( 'Multi B' );

		$first  = $service->create( $multi_eligible, ScheduleExpansion::MODE_DATES, '18:00', [ $this->local_date( '+2 days' ) ], [ $pkg_a ] );
		$second = $service->create( $multi_eligible, ScheduleExpansion::MODE_DATES, '20:00', [ $this->local_date( '+9 days' ) ], [ $pkg_b ] );
		$this->ok( $first instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $first->status, 'multi-schedule fixture: the first schedule for this Portal session commits' );
		$this->ok( $second instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $second->status, '  and the second, DIFFERENT schedule for the SAME Portal session also commits' );

		$first_id  = $first instanceof SessionScheduleResult ? (int) $first->schedule['schedule_id'] : 0;
		$second_id = $second instanceof SessionScheduleResult ? (int) $second->schedule['schedule_id'] : 0;
		$this->ok( $first_id > 0 && $second_id > 0 && $first_id !== $second_id, '  as two genuinely distinct schedule ids' );

		$multi_html = $this->render_screen( [ SessionTemplateAdmin::class, 'render' ], [ 'page' => SessionTemplateAdmin::PAGE_SLUG ] );
		$this->same( 2, substr_count( $multi_html, 'Manage Dates' ), 'BOTH schedules for this Portal session get their own "Manage Dates" link' );
		$this->ok( false !== strpos( $multi_html, 'edit=' . $first_id ), '  one pointing at the first schedule id' );
		$this->ok( false !== strpos( $multi_html, 'edit=' . $second_id ), '  and one at the second' );
		foreach ( [ $first_id => '18:00', $second_id => '20:00' ] as $id => $saved_time ) {
			$this->ok( 1 === preg_match( '/Schedule #' . $id . ' \(saved time: ' . $saved_time . '\).*?<a href="[^"]*edit=' . $id . '"[^>]*>\s*Manage Dates/s', $multi_html ), 'each Manage Dates link is paired with its own schedule ID and saved time' );
			$selected = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $id ] );
			$this->ok( false !== strpos( $selected, '<h2>Schedule #' . $id . '</h2>' ) && false !== strpos( $selected, 'Portal session:</strong> ' . self::PREFIX . ' Multi Eligible Bingo' ) && false !== strpos( $selected, 'Saved time:</strong> ' . $saved_time ), 'the edit page identifies the selected schedule, Portal name and saved time' );
			$this->ok( false === strpos( $selected, 'Every session schedule in this hall' ), 'the edit page does not append the hall-wide table' );
			$this->ok( false !== strpos( $selected, 'Back to all schedules' ), 'the selected schedule has a route back to the full list' );
			preg_match_all( '/<form\b[^>]*>.*?<\/form>/s', $selected, $forms );
			$update_forms = array_values( array_filter( $forms[0], static fn( $form ) => false !== strpos( $form, 'name="action" value="' . SessionScheduleAdmin::POST_ACTION . '"' ) ) );
			$this->same( 1, count( $update_forms ), 'exactly one Update Sessions form is offered for the selected schedule' );
			$update_form = $update_forms[0] ?? '';
			$this->ok( false !== strpos( $update_form, 'method="post"' ) && false !== strpos( $update_form, 'name="schedule_id" value="' . $id . '"' ) && false !== strpos( $update_form, wp_create_nonce( self::NONCE_GENERATE ) ), 'the selected update action retains POST, the existing handler, its target and nonce' );
			$this->ok( false === strpos( $update_form, 'name="bc_action"' ) && false !== strpos( $this->form_for_action( $selected, 'edit' ), 'name="schedule_id" value="' . $id . '"' ), 'Save and Update are separate forms targeting the same schedule' );
		}
		$list = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG ] );
		$this->ok( false !== strpos( $list, 'Every session schedule in this hall' ) && false !== strpos( $list, '<strong>#' . $first_id . '</strong>' ) && false !== strpos( $list, '<strong>#' . $second_id . '</strong>' ), 'the main list still shows both schedules for the same Portal session' );

		$second_before = $service->get( $second_id );
		$saved_args = $this->query_args_of( $this->save( [
			'bc_action' => 'edit', 'schedule_id' => (string) $first_id,
			'mode' => ScheduleExpansion::MODE_DATES, 'start_time' => '19:00',
			'future_dates' => [ $this->us_date( '+2 days' ) ], 'package_ids' => [ (string) $pkg_a ],
		] ) );
		$this->same( 'updated', $saved_args['bc_saved'] ?? '', 'selected schedule Save succeeds' );
		$this->same( (string) $first_id, $saved_args['edit'] ?? '', 'Save redirects to the same selected schedule' );
		$this->same( 303, $this->last_redirect_status, 'Save preserves the POST-to-GET redirect' );
		$this->same( 0, $this->occurrence_count( $first_id ), 'Save does not implicitly run Update Sessions' );
		$saved_page = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $saved_args );
		$this->ok( false !== strpos( $saved_page, 'Saved time:</strong> 19:00' ) && false !== strpos( $saved_page, 'Schedule saved.' ), 'Save landing page shows the committed time and save notice' );
		$refused_args = $this->query_args_of( $this->save( [
			'bc_action' => 'edit', 'schedule_id' => (string) $first_id,
			'mode' => ScheduleExpansion::MODE_DATES, 'start_time' => '21:00',
			'future_dates' => [ 'not a date' ], 'package_ids' => [ (string) $pkg_a ],
		] ) );
		$this->same( (string) $first_id, $refused_args['edit'] ?? '', 'refused Save also retains the selected schedule' );
		$refused_page = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $refused_args );
		$this->ok( false !== strpos( $refused_page, 'Saved time:</strong> 19:00' ) && false !== strpos( $refused_page, 'value="21:00"' ), 'saved context remains distinct from the retained unsaved form time' );
		$updated_args = $this->query_args_of( $this->run_generate( $first_id, true ) );
		$this->same( SessionScheduleResult::STATUS_OK, $updated_args['bc_generated'] ?? '', 'the selected Update Sessions action succeeds' );
		$this->same( (string) $first_id, $updated_args['edit'] ?? '', 'Update redirects to the same selected schedule' );
		$this->same( 303, $this->last_redirect_status, 'Update preserves the POST-to-GET redirect' );
		$updated_page = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $updated_args );
		$this->ok( false !== strpos( $updated_page, '<h2>Schedule #' . $first_id . '</h2>' ) && false !== strpos( $updated_page, 'Bingo Sessions updated.' ), 'Update landing page keeps selected identity and its result together' );
		$this->same( 1, $this->occurrence_count( $first_id ), 'Update generates only the selected schedule' );
		$this->same( 0, $this->occurrence_count( $second_id ), 'the other schedule has no generated occurrence' );
		$this->same( $second_before, $service->get( $second_id ), 'saving and updating the selected schedule leaves the other header unchanged' );
		// "Add to Store" survives having existing schedules — a Manager may
		// legitimately add a THIRD schedule for the same Portal session.
		$this->ok( false !== strpos( $multi_html, 'portal_session_id=' . $multi_eligible ), '  and Add to Store is still offered, so a third schedule remains possible' );

		/* An INERT schedule (failed CAS) needs its definition saved before any
		 * session update; Manage Dates remains the only route from this list. */
		global $wpdb;
		$wpdb->update(
			Migrations::schedules_table(),
			[ 'active_definition_token' => null ],
			[ 'schedule_id' => $second_id ]
		);
		$inert_html = $this->render_screen( [ SessionTemplateAdmin::class, 'render' ], [ 'page' => SessionTemplateAdmin::PAGE_SLUG ] );
		$this->ok( false !== strpos( $inert_html, 'Incomplete — open and save this schedule first.' ), 'an INERT schedule shows a save-first reminder' );
		$this->same( 0, substr_count( $inert_html, 'Update Sessions' ), 'the Portal list does not mislabel an edit link as an update action' );
		$this->ok( false !== strpos( $inert_html, 'edit=' . $second_id ) && false !== strpos( $inert_html, 'Manage Dates' ), 'the existing Manage Dates edit route remains available' );
		$inert_edit = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $second_id ] );
		$this->ok( '' !== $this->form_for_action( $inert_edit, 'edit' ) && false === strpos( $inert_edit, 'name="action" value="' . SessionScheduleAdmin::POST_ACTION . '"' ), 'an incomplete schedule offers Save before it can offer Update' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * Schedules screen — list and forms (§15 items 3-5)
	 * ================================================================== */

	private function schedule_screen_group(): void {
		$this->group( 'Schedules screen' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$template = $this->plant_template( 'Form Bingo', 'active', 1 );
		$package  = $this->make_package( 'Form Bingo' );

		$html = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => (string) $template ]
		);

		$this->ok( false !== strpos( $html, 'Create Sessions' ), 'the create form renders for an eligible template' );
		$this->ok( false === strpos( $html, 'Every session schedule in this hall' ) && false !== strpos( $html, 'Back to all schedules' ), 'the create form has a list link instead of the hall-wide table' );
		$this->ok( false !== strpos( $html, self::ZONE ), 'the form states the captured time zone' );
		$this->ok(
			false !== strpos( $html, 'FIXED for the schedule' ) || false !== strpos( $html, 'archive the schedule and create a replacement' ),
			'  and says it is FIXED for the schedule\'s life (§5.1), with the remedy'
		);
		$this->ok( false !== strpos( $html, 'Minute precision' ), 'the time control states minute precision (§5.4)' );
		$this->ok( false !== strpos( $html, 'Seconds are not accepted' ), '  and that seconds are refused' );
		$this->ok( false !== strpos( $html, 'value="' . ScheduleExpansion::MODE_DATES . '"' ), 'the explicit-dates mode is offered' );
		$this->ok( false !== strpos( $html, 'value="' . ScheduleExpansion::MODE_WEEKLY . '"' ), 'the weekly mode is offered' );
		$this->ok( false !== strpos( $html, 'name="weekdays[]"' ), '  with a weekday mask' );
		$this->ok( false !== strpos( $html, 'name="recurrence_start"' ), '  and weekly bounds' );
		$this->ok( false !== strpos( $html, 'tomorrow or later' ), 'the date control states tomorrow-or-later (§8.1)' );
		$this->ok( false !== strpos( $html, 'name="package_ids[]"' ), 'Package selection is offered' );
		$this->ok( false !== strpos( $html, 'value="' . $package . '"' ), '  listing this hall\'s assignable Package' );
		$this->ok( false !== strpos( $html, '_wpnonce' ), 'the form carries a nonce' );

		/* An INELIGIBLE template offers no create form at all. */
		$draft      = $this->plant_template( 'Form Draft Bingo', 'draft', 1 );
		$draft_html = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => (string) $draft ]
		);
		$this->ok( false !== strpos( $draft_html, 'not ready to sell yet' ), 'a draft template is refused a create form, and told why' );
		$this->ok( false === strpos( $draft_html, 'name="package_ids[]"' ), '  no form is rendered for it at all' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * Create, edit, archive, restore — all through the service
	 * ================================================================== */

	private function mutation_group(): void {
		global $wpdb;

		$this->group( 'Mutations' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$template = $this->plant_template( 'Mutate Bingo', 'active', 1 );
		$package  = $this->make_package( 'Mutate Bingo' );
		$service  = new SessionScheduleService();

		/* --- Create. --- */
		$this->post_to_screen(
			[
				'bc_action'         => 'create',
				'portal_session_id' => (string) $template,
				'mode'              => ScheduleExpansion::MODE_DATES,
				'start_time'        => '18:00',
				'future_dates'      => $this->local_date( '+2 days' ),
				'package_ids'       => [ (string) $package ],
			]
		);

		$schedules = $service->list_for_administration( 'all' );
		$this->ok( is_array( $schedules ) && 1 === count( $schedules ), 'the posted create produced exactly one schedule' );
		$schedule_id = is_array( $schedules ) && [] !== $schedules ? (int) $schedules[0]['schedule_id'] : 0;
		$this->ok( $schedule_id > 0, '  with a schedule id' );
		$this->same( '18:00:00', is_array( $schedules ) && [] !== $schedules ? (string) $schedules[0]['start_time_local'] : '', '  the minute-precision time is stored normalized' );
		$this->same( self::ZONE, is_array( $schedules ) && [] !== $schedules ? (string) $schedules[0]['timezone_name'] : '', '  and the hall zone was captured' );

		/* Seconds are REFUSED — the screen passes them through untouched
		 * rather than helpfully truncating input the ADR says to reject. */
		$refused = $this->post_to_screen(
			[
				'bc_action'         => 'create',
				'portal_session_id' => (string) $template,
				'mode'              => ScheduleExpansion::MODE_DATES,
				'start_time'        => '18:00:30',
				'future_dates'      => $this->local_date( '+2 days' ),
				'package_ids'       => [ (string) $package ],
			]
		);
		$this->ok( false !== strpos( $refused, 'notice-error' ), '18:00:30 is REFUSED, not silently truncated to 18:00 (§5.4)' );
		$after = $service->list_for_administration( 'all' );
		$this->same( 1, is_array( $after ) ? count( $after ) : -1, '  and no second schedule was created' );

		/* --- Edit: change the time. --- */
		$edited_page = $this->post_to_screen(
			[
				'bc_action'   => 'edit',
				'schedule_id' => (string) $schedule_id,
				'mode'        => ScheduleExpansion::MODE_DATES,
				'start_time'  => '19:45',
				'future_dates' => $this->local_date( '+3 days' ),
				'package_ids' => [ (string) $package ],
			]
		);
		$edited = $service->get( $schedule_id );
		$this->same( '19:45:00', is_array( $edited ) ? (string) $edited['start_time_local'] : '', 'the posted edit changed the time through the service' );
		$this->same( self::ZONE, is_array( $edited ) ? (string) $edited['timezone_name'] : '', '  and the timezone is UNCHANGED — it is fixed for the schedule\'s life' );
		$this->ok( false !== strpos( $edited_page, 'value="19:45"' ) && false === strpos( $edited_page, 'value="18:00:30"' ), 'successful Save renders the committed time, not retained input from an earlier refused form' );

		/* --- Archive, then restore. --- */
		$this->post_to_screen( [ 'bc_action' => 'archive', 'schedule_id' => (string) $schedule_id ] );
		$archived = $service->get( $schedule_id );
		$this->same( SessionScheduleService::STATUS_ARCHIVED, is_array( $archived ) ? (string) $archived['status'] : '', 'the posted archive archived the schedule' );

		$this->post_to_screen( [ 'bc_action' => 'restore', 'schedule_id' => (string) $schedule_id ] );
		$restored = $service->get( $schedule_id );
		$this->same( SessionScheduleService::STATUS_ACTIVE, is_array( $restored ) ? (string) $restored['status'] : '', 'the posted restore reactivated it' );

		/* --- The list table reflects lifecycle and offers the right controls. --- */
		$list = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG ] );
		$this->ok( false !== strpos( $list, 'value="archive"' ), 'an ACTIVE schedule offers Archive' );
		$this->ok( false !== strpos( $list, 'value="' . SessionScheduleAdmin::POST_ACTION . '"' ), '  and Generate, posted to admin-post.php' );
		$this->ok( false === strpos( $list, 'value="restore"' ), '  and NOT Restore' );

		$this->post_to_screen( [ 'bc_action' => 'archive', 'schedule_id' => (string) $schedule_id ] );
		$archived_list = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG ] );
		$this->ok( false !== strpos( $archived_list, 'value="restore"' ), 'an ARCHIVED schedule offers Restore' );
		$this->ok( false === strpos( $archived_list, 'value="archive"' ), '  and not Archive' );
		$this->ok( false === strpos( $archived_list, 'value="' . SessionScheduleAdmin::POST_ACTION . '"' ), '  and not Generate' );

		/*
		 * THE AUDIT ACTUALLY RECORDS. These screens are the first caller to
		 * exercise the REAL audit recorder — every harness before them passed a
		 * swallowing stub — and that is how `active_definition_token` was found
		 * to be refused by AuditLog's sensitive-key filter, which splits a key
		 * on non-alphanumerics and rejects any part equal to `token`. Every
		 * schedule mutation was recording an `audit_write_failed` warning and no
		 * audit row. Asserting the ABSENCE of that warning is what keeps §13.1's
		 * claim honest.
		 */
		foreach (
			[
				'created'  => $service->create( $template, ScheduleExpansion::MODE_DATES, '18:00', [ $this->local_date( '+6 days' ) ], [ $package ] ),
				'archived' => null,
			] as $verb => $audited
		) {
			if ( null === $audited ) {
				continue;
			}
			$this->ok( $audited instanceof SessionScheduleResult, "audit fixture: the {$verb} mutation succeeded" );
			$this->same(
				[],
				$audited instanceof SessionScheduleResult ? array_values( array_filter( $audited->warnings, static fn( $w ) => 'audit_write_failed' === ( $w['code'] ?? '' ) ) ) : [ 'NO RESULT' ],
				"the {$verb} mutation records its audit event with NO audit_write_failed warning"
			);
			$audited_id = $audited instanceof SessionScheduleResult ? (int) $audited->schedule['schedule_id'] : 0;

			$archived_audit = $service->archive( $audited_id );
			$this->same(
				[],
				$archived_audit instanceof SessionScheduleResult ? array_values( array_filter( $archived_audit->warnings, static fn( $w ) => 'audit_write_failed' === ( $w['code'] ?? '' ) ) ) : [ 'NO RESULT' ],
				'  and so does the archive that follows it'
			);
		}

		/* No audit payload key may contain a `token` part, by enumeration. */
		$service_code = $this->code_only( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionScheduleService.php' );
		$payload_body = '';
		if ( 1 === preg_match( '/function audit_payload\([^)]*\)\s*:\s*array\s*\{(.*?)\n\t\}/s', $service_code, $m ) ) {
			$payload_body = $m[1];
		}
		$this->ok( '' !== $payload_body, 'audit_payload() was located for enumeration' );
		// Only the KEYS are scanned. `$row['active_definition_token']` is a
		// column read on the right-hand side and is legal; a payload KEY
		// containing `token` is not.
		$this->same(
			0,
			preg_match( '/\'[a-z_]*token[a-z_]*\'\s*=>/', $payload_body ),
			'no audit payload KEY contains a `token` part — AuditLog refuses every one of them'
		);
		$this->same(
			0,
			preg_match( '/\'[a-z_]*session_id[a-z_]*\'\s*=>/', $payload_body ),
			'and none joins into `sessionid` across two adjacent parts, which is the same landmine one position along'
		);

		unset( $wpdb );
		restore_current_blog();
	}

	/* ================================================================== *
	 * §8.3 past-date keep/drop
	 * ================================================================== */

	private function past_date_group(): void {
		global $wpdb;

		$this->group( 'Past dates keep/drop' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$template = $this->plant_template( 'Past Date Bingo', 'active', 1 );
		$package  = $this->make_package( 'Past Date Bingo' );
		$service  = new SessionScheduleService();

		$created = $service->create(
			$template,
			ScheduleExpansion::MODE_DATES,
			'18:00',
			[ $this->local_date( '+2 days' ) ],
			[ $package ]
		);
		$this->ok( $created instanceof SessionScheduleResult, 'past-date fixture: a schedule exists' );
		$schedule_id = $created instanceof SessionScheduleResult ? (int) $created->schedule['schedule_id'] : 0;
		$token       = $created instanceof SessionScheduleResult ? (string) $created->schedule['active_definition_token'] : '';

		/*
		 * A date that has already passed is PLANTED into the committed
		 * definition: the service refuses to ADD one (§8.1), and §8.3 is about
		 * a date that was legitimately committed and has since gone by.
		 */
		$past = $this->local_date( '-4 days' );
		$wpdb->insert(
			Migrations::schedule_dates_table(),
			[
				'schedule_id'      => $schedule_id,
				'definition_token' => $token,
				'schedule_date'    => $past,
				'created_at'       => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		$html = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $schedule_id ]
		);

		$this->ok( false !== strpos( $html, 'Dates already scheduled' ), 'the edit form shows the committed past dates as a keep/drop control (§8.3)' );
		$this->ok(
			1 === preg_match( '/name="past_dates\[\]" value="' . preg_quote( $this->us_date( '-4 days' ), '/' ) . '" checked/', $html ),
			'  the passed date is listed as a KEEPABLE, pre-checked checkbox'
		);
		$this->ok( false !== strpos( $html, 'changes no session that already exists' ), '  and the screen says dropping one changes no session that already exists' );
		$this->ok(
			false === strpos( $html, '<textarea id="bc-dates" name="dates" rows="6" class="large-text" placeholder="2026-01-31">' . $past ),
			'  and the past date is NOT dumped into the future-dates textarea'
		);
		$this->ok( 1 === preg_match( '/name="future_dates\[\]"\s+value="' . preg_quote( $this->us_date( '+2 days' ), '/' ) . '"/', $html ), '  while the FUTURE date is still offered in the approved MM/DD/YYYY format' );

		/*
		 * KEEP it first, because keep and drop are not independent: §8.3 exempts
		 * a date that is in the CURRENT committed definition, so once a past
		 * date has been dropped it is an ordinary historical date again and
		 * re-adding it is refused. Testing drop first and keep second would
		 * therefore be testing "can I re-add a dropped past date", which is a
		 * different question with the opposite answer.
		 */
		$keep = $service->edit(
			$schedule_id,
			ScheduleExpansion::MODE_DATES,
			'18:00',
			[ $past, $this->local_date( '+2 days' ) ],
			[ $package ]
		);
		$this->ok( $keep instanceof SessionScheduleResult, 'a previously committed past date may be KEPT — it is exempt from tomorrow-or-later' );
		$kept_token = (string) $service->get( $schedule_id )['active_definition_token'];
		$kept_dates = $service->definition_dates( $schedule_id, $kept_token );
		$this->ok( is_array( $kept_dates ) && in_array( $past, $kept_dates, true ), '  and it survives into the new definition' );

		/*
		 * THE REAL SCREEN POST, WITH BOTH INPUTS PRESENT AT ONCE.
		 *
		 * This is the case the field names broke. The past dates are checkboxes
		 * and the future dates are a textarea; while BOTH were named `dates`
		 * (as `dates[]` and `dates`) PHP parsed one value for that name and the
		 * textarea's string simply replaced the checkbox array — so every
		 * checked past date was dropped on save, silently, and only a POST
		 * carrying both inputs could show it. Calling the service directly, as
		 * the keep case above does, cannot: it never goes through the form.
		 */
		$future = $this->local_date( '+2 days' );
		$this->post_to_screen(
			[
				'bc_action'    => 'edit',
				'schedule_id'  => (string) $schedule_id,
				'mode'         => ScheduleExpansion::MODE_DATES,
				'start_time'   => '18:00',
				'past_dates'   => [ $this->us_date( '-4 days' ) ],
				'future_dates' => $future,
				'package_ids'  => [ (string) $package ],
			]
		);
		$both_token = (string) $service->get( $schedule_id )['active_definition_token'];
		$both_dates = $service->definition_dates( $schedule_id, $both_token );
		$this->ok( is_array( $both_dates ) && in_array( $past, $both_dates, true ), 'screen POST: a CHECKED past date survives a save that also carries future dates' );
		$this->ok( is_array( $both_dates ) && in_array( $future, $both_dates, true ), '  and the future textarea date survives the same save' );
		$this->same( 2, is_array( $both_dates ) ? count( $both_dates ) : -1, '  both inputs are merged into ONE definition, with nothing else in it' );

		/* The merge deduplicates rather than double-committing. */
		$this->post_to_screen(
			[
				'bc_action'    => 'edit',
				'schedule_id'  => (string) $schedule_id,
				'mode'         => ScheduleExpansion::MODE_DATES,
				'start_time'   => '18:00',
				'past_dates'   => [ $this->us_date( '-4 days' ), $this->us_date( '-4 days' ) ],
				'future_dates' => $future . "\n " . $future . " \n",
				'package_ids'  => [ (string) $package ],
			]
		);
		$dedup_token = (string) $service->get( $schedule_id )['active_definition_token'];
		$dedup_dates = $service->definition_dates( $schedule_id, $dedup_token );
		$this->same( 2, is_array( $dedup_dates ) ? count( $dedup_dates ) : -1, 'screen POST: repeated and whitespace-padded values are trimmed and deduplicated, not committed twice' );

		/* DROP it: the same POST with the checkbox UNCHECKED — which is simply
		 * its absence from past_dates — while the future date stays. */
		$this->post_to_screen(
			[
				'bc_action'    => 'edit',
				'schedule_id'  => (string) $schedule_id,
				'mode'         => ScheduleExpansion::MODE_DATES,
				'start_time'   => '18:00',
				'future_dates' => $future,
				'package_ids'  => [ (string) $package ],
			]
		);
		$after_token = (string) $service->get( $schedule_id )['active_definition_token'];
		$after_dates = $service->definition_dates( $schedule_id, $after_token );
		$this->ok( is_array( $after_dates ) && ! in_array( $past, $after_dates, true ), 'screen POST: an UNCHECKED past date is dropped' );
		$this->ok( is_array( $after_dates ) && in_array( $future, $after_dates, true ), '  while the future date remains' );
		$this->same( 1, is_array( $after_dates ) ? count( $after_dates ) : -1, '  leaving exactly the one date the form still carried' );

		/* The two inputs are genuinely separate names in the rendered form. */
		$form = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $schedule_id ]
		);
		$this->ok( false === strpos( $form, 'name="dates"' ) && false === strpos( $form, "name='dates'" ), 'the form no longer emits a bare `dates` field' );
		$this->ok( false === strpos( $form, 'name="dates[]"' ), '  nor a colliding `dates[]` one' );
		$this->ok( false !== strpos( $form, 'name="future_dates[]"' ), '  it emits future_dates[]' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * UI correction pass — failed-read Save suppression, PRG, template name
	 * ================================================================== */

	private function ui_correction_group(): void {
		global $wpdb;

		$this->group( 'UI corrections' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$template = $this->plant_template( 'Correction Bingo', 'active', 1 );
		$package  = $this->make_package( 'Correction Bingo' );
		$service  = new SessionScheduleService();

		$created     = $service->create( $template, ScheduleExpansion::MODE_DATES, '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$schedule_id = $created instanceof SessionScheduleResult ? (int) $created->schedule['schedule_id'] : 0;
		$this->ok( $schedule_id > 0, 'correction fixture: a schedule exists' );

		/* ---------------------------------------------------------------- *
		 * (2) A FAILED DEFINITION READ OFFERS NO WAY TO SAVE OR UPDATE.
		 *
		 * An edit commits the WHOLE definition, so a form built on a read that
		 * failed would let one Save discard the dates and Packages that could
		 * not be loaded. Divert only the definition SELECT to a nonexistent
		 * table, yielding a genuine database error without changing any schema.
		 * ---------------------------------------------------------------- */
		// ONLY the definition-dates SELECT is diverted; every other statement in
		// the render runs normally, so what is being tested is that one read
		// failing rather than the whole hall being unreachable.
		$this->intercept( '/SELECT schedule_date\s+FROM \S+bingo_session_schedule_dates/' );

		$broken = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $schedule_id ]
		);

		$this->ok( $this->stop_intercepting() > 0, 'failed read: the interceptor actually fired — the scenario is not vacuous' );

		$edit_form = $this->form_for_action( $broken, 'edit' );
		$this->same( '', $edit_form, 'failed read: NO edit form is rendered at all' );
		$this->ok( false === strpos( $broken, 'Save Schedule' ), '  and no Save action is offered' );
		$this->ok( false === strpos( $broken, 'name="action" value="' . SessionScheduleAdmin::POST_ACTION . '"' ), '  and no Update action is offered' );
		$this->ok( false === strpos( $broken, 'name="future_dates"' ), '  no editable date control is rendered' );
		$this->ok( false === strpos( $broken, 'name="package_ids[]"' ), '  and no editable Package control' );
		$this->ok( false !== strpos( $broken, 'could not be read' ), '  the failure is stated' );
		$this->ok( false !== strpos( $broken, 'Opening this page made no changes' ), '  the render does not imply that a previous save was rolled back' );
		$this->ok( false !== strpos( $broken, 'Back to all schedules' ), '  with a safe way back' );
		$this->ok( false === strpos( $broken, 'Every session schedule in this hall' ), 'the failed-read screen does not append the hall-wide table' );

		/* The schedule is still archivable from the list — a display failure
		 * must not take the lifecycle control away. */
		$list = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG ] );
		preg_match( '/<tr>\s*<td>\s*<strong>#' . $schedule_id . '<\/strong>.*?<\/tr>/s', $list, $list_row );
		$this->ok( false !== strpos( $list_row[0] ?? '', 'value="archive"' ) && false !== strpos( $list_row[0] ?? '', 'name="schedule_id" value="' . $schedule_id . '"' ), 'the existing Archive action remains accessible on the main list for this schedule' );

		$this->intercept( '/SELECT package_id\s+FROM \S+bingo_session_schedule_packages/' );
		$broken_packages = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [
			'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $schedule_id,
			'bc_saved' => 'created_partial', 'bc_schedule' => (string) $schedule_id,
		] );
		$this->ok( $this->stop_intercepting() > 0, 'definition Package read failure was injected' );
		$this->same( '', $this->form_for_action( $broken_packages, 'edit' ), 'failed Package definition read also suppresses Save' );
		$this->ok( false === strpos( $broken_packages, 'name="action" value="' . SessionScheduleAdmin::POST_ACTION . '"' ), 'a partial-generation notice cannot bypass failed-read Update suppression' );
		$this->ok( false !== strpos( $broken_packages, 'sessions were not fully created' ) && false !== strpos( $broken_packages, 'could not be read' ), 'both the prior result and current read error stay visible' );
		$this->ok( false !== strpos( $broken_packages, 'reload this page once the read succeeds; Update Sessions is unavailable until then' ), 'the partial-generation notice explains recovery without offering an action on the failed-read screen' );

		/* Nothing was written by rendering the broken state. */
		$still = $service->get( $schedule_id );
		$this->same(
			$created instanceof SessionScheduleResult ? (string) $created->schedule['active_definition_token'] : 'X',
			is_array( $still ) ? (string) $still['active_definition_token'] : 'Y',
			'  the definition token is untouched by the failed render'
		);

		/* ---------------------------------------------------------------- *
		 * A FAILED CREATE IS NEVER REPORTED AS `created`.
		 *
		 * The template is made non-current BETWEEN request-time validation and
		 * the template-guarded CAS, using a barrier on the CAS statement itself
		 * — the §3.2 window the guard exists for. The CAS then affects zero
		 * rows, the provisional header survives INERT (§3.8 step 5), and
		 * create() returns a SessionScheduleResult whose status is `failed`.
		 * It is a result object, not a WP_Error, which is exactly why an
		 * earlier version called it success.
		 * ---------------------------------------------------------------- */
		$cas_template = $this->plant_template( 'Invalidated Bingo', 'active', 1 );
		$before_inert = $this->schedule_ids( $service );

		$fired = false;
		$this->barrier(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $wpdb, $cas_template, &$fired ) {
				$fired = true;
				$wpdb->update(
					Migrations::portal_session_templates_table(),
					[ 'is_current' => 0 ],
					[ 'portal_session_id' => $cas_template ]
				);
			}
		);

		$cas_location = $this->save(
			[
				'bc_action'         => 'create',
				'portal_session_id' => (string) $cas_template,
				'mode'              => ScheduleExpansion::MODE_DATES,
				'start_time'        => '18:00',
				'future_dates'      => $this->local_date( '+5 days' ),
				'package_ids'       => [ (string) $package ],
			]
		);
		$this->clear_barrier();

		$this->ok( $fired, 'CAS race: the barrier fired — the template was invalidated inside the commit window' );

		$cas_args = $this->query_args_of( $cas_location );
		$this->same( 'error', $cas_args['bc_saved'] ?? '', 'CAS race: the redirect reports FAILURE' );
		$this->ok(
			! in_array( $cas_args['bc_saved'] ?? '', [ 'created', 'updated', 'archived', 'restored' ], true ),
			'  and never `created` — a result object is not the same thing as a success'
		);
		$this->same( 'not_eligible', $cas_args['bc_error'] ?? '', '  naming the template as the cause, in this screen\'s own vocabulary' );

		/* EXACTLY ONE inert header exists, and the redirect points AT it. */
		$after_inert = $this->schedule_ids( $service );
		$added       = array_values( array_diff( $after_inert, $before_inert ) );
		$this->same( 1, count( $added ), 'CAS race: exactly ONE schedule header now exists for that attempt' );

		$inert_id = $added[0] ?? 0;
		$this->same( (string) $inert_id, $cas_args['edit'] ?? '', '  and the redirect targets THAT schedule\'s Edit screen' );
		$this->ok( ! isset( $cas_args['action'] ), '  never the create form, which would make another header on retry' );

		$inert_row = $service->get( $inert_id );
		$this->same( '', is_array( $inert_row ) ? (string) $inert_row['active_definition_token'] : 'X', '  the header really is inert — no committed definition' );

		/* RETRY after restoring eligibility commits the SAME schedule_id. */
		$wpdb->update(
			Migrations::portal_session_templates_table(),
			[ 'is_current' => 1 ],
			[ 'portal_session_id' => $cas_template ]
		);

		$retry_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'    => 'edit',
					'schedule_id'  => (string) $inert_id,
					'mode'         => ScheduleExpansion::MODE_DATES,
					'start_time'   => '18:00',
					'future_dates' => $this->local_date( '+5 days' ),
					'package_ids'  => [ (string) $package ],
				]
			)
		);
		$this->same( 'updated', $retry_args['bc_saved'] ?? '', 'CAS race: retrying from that Edit screen SUCCEEDS once the template is eligible again' );

		$recovered = $service->get( $inert_id );
		$this->ok( is_array( $recovered ) && '' !== (string) $recovered['active_definition_token'], '  the SAME schedule_id now has a committed definition' );
		$this->same(
			$after_inert,
			$this->schedule_ids( $service ),
			'  and the retry created NO second header — the inert one was recovered, not replaced'
		);

		/*
		 * THE OTHER non-OK status, separately. `classify_commit_failure()`
		 * returns STATUS_SCHEDULE_STATE_CHANGED — not STATUS_FAILED — when the
		 * template is still perfectly eligible but the header moved underneath
		 * the CAS. Both are result objects and neither is a success, so both
		 * have to be proven: a classification that only special-cased `failed`
		 * would report this one as `created`.
		 */
		$moved_template = $this->plant_template( 'Moved Header Bingo', 'active', 1 );
		$before_moved   = $this->schedule_ids( $service );

		$moved_fired = false;
		$this->barrier(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $wpdb, $moved_template, &$moved_fired ) {
				$moved_fired = true;
				// The header stops being active mid-commit; the template is
				// untouched and stays eligible.
				$wpdb->update(
					Migrations::schedules_table(),
					[ 'status' => SessionScheduleService::STATUS_ARCHIVED ],
					[ 'portal_session_id' => $moved_template ]
				);
			}
		);

		$moved_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $moved_template,
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00',
					'future_dates'      => $this->local_date( '+5 days' ),
					'package_ids'       => [ (string) $package ],
				]
			)
		);
		$this->clear_barrier();

		$this->ok( $moved_fired, 'state-changed: the barrier fired inside the commit window' );
		$this->same( 'error', $moved_args['bc_saved'] ?? '', 'state-changed: STATUS_SCHEDULE_STATE_CHANGED redirects as an ERROR' );
		$this->same( 'state_changed', $moved_args['bc_error'] ?? '', '  named state_changed, distinct from an eligibility refusal' );

		$moved_added = array_values( array_diff( $this->schedule_ids( $service ), $before_moved ) );
		$this->same( 1, count( $moved_added ), '  exactly one header exists for that attempt' );
		$this->same( (string) ( $moved_added[0] ?? 0 ), $moved_args['edit'] ?? '', '  and the redirect targets it, so the retry reuses the same id' );

		/* ---------------------------------------------------------------- *
		 * (3) PRG: a refresh cannot repeat a mutation.
		 * ---------------------------------------------------------------- */
		$before_count = count( (array) $service->list_for_administration( 'all' ) );

		$location = $this->save(
			[
				'bc_action'         => 'create',
				'portal_session_id' => (string) $template,
				'mode'              => ScheduleExpansion::MODE_DATES,
				'start_time'        => '18:00',
				'future_dates'      => $this->local_date( '+4 days' ),
				'package_ids'       => [ (string) $package ],
			]
		);

		$this->ok( '' !== $location, 'create REDIRECTS rather than rendering its own result' );
		$args = $this->query_args_of( $location );
		$this->same( 'created', $args['bc_saved'] ?? '', '  reporting the allowlisted outcome `created`' );
		$this->same( $args['bc_schedule'] ?? 'missing', $args['edit'] ?? '', 'successful Create lands on its newly saved schedule' );
		$this->same( SessionScheduleAdmin::PAGE_SLUG, $args['page'] ?? '', '  back to the schedules screen' );

		$after_create = count( (array) $service->list_for_administration( 'all' ) );
		$this->same( $before_count + 1, $after_create, 'exactly ONE schedule was created' );

		/*
		 * THE REFRESH. The browser re-issues the redirected GET — never the
		 * POST — because the handler answered 303 and the render callback
		 * mutates nothing. Re-rendering it must not create a second schedule.
		 */
		$refreshed = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $args );
		$this->same(
			$after_create,
			count( (array) $service->list_for_administration( 'all' ) ),
			'refreshing the post-create page creates NO duplicate schedule'
		);
		$this->ok( false !== strpos( $refreshed, 'Sessions created' ), '  and the success notice still renders from the redirect' );

		/* Refreshing it a second and third time is equally inert. */
		$this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $args );
		$this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $args );
		$this->same(
			$after_create,
			count( (array) $service->list_for_administration( 'all' ) ),
			'  and repeated refreshes stay inert'
		);

		/* The render callback holds no mutation path at all, by enumeration. */
		$admin_code = $this->code_only( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionScheduleAdmin.php' );
		$this->same(
			1,
			substr_count( $admin_code, 'handle_request(' ) - substr_count( $admin_code, 'function handle_request(' ),
			'handle_request() is called from exactly ONE place — the admin-post save handler, never the render callback'
		);

		/* Archive and restore redirect too, with their own allowlisted words. */
		foreach (
			[
				'archive' => 'archived',
				'restore' => 'restored',
			] as $bc_action => $expected
		) {
			$lifecycle = $this->query_args_of( $this->save( [ 'bc_action' => $bc_action, 'schedule_id' => (string) $schedule_id ] ) );
			$this->same( $expected, $lifecycle['bc_saved'] ?? '', "{$bc_action} redirects reporting `{$expected}`" );
		}

		/* A REFUSED save redirects too, carrying a code and no message. */
		$refused = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $template,
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00:30',
					'future_dates'      => $this->local_date( '+4 days' ),
					'package_ids'       => [ (string) $package ],
				]
			)
		);
		$this->same( 'error', $refused['bc_saved'] ?? '', 'a refused save redirects with bc_saved=error' );
		$this->same( 'invalid_time', $refused['bc_error'] ?? '', '  carrying THIS SCREEN\'S fixed code, not the service\'s `invalid_start_time`' );
		$validation_notice = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $refused );
		$this->ok( false !== strpos( $validation_notice, 'The schedule definition was not saved.' ) && false === strpos( $validation_notice, 'check the schedule and sales state' ), 'definite validation refusal remains explicit' );

		/*
		 * This create was refused at VALIDATION, before any header was
		 * inserted, so there is no inert schedule to point at and the safe
		 * destination is the list. It must NOT be the create form: this screen
		 * cannot prove no header exists, and assuming one does not is how
		 * duplicate inert headers get made.
		 */
		$this->same( '', $refused['action'] ?? '', '  and does NOT return to the create form' );
		$this->same( '', $refused['edit'] ?? '', '  nor claim an inert schedule that was never created' );
		$this->same(
			[],
			array_values( array_diff( array_keys( $refused ), [ 'page', 'bc_saved', 'bc_error', 'bc_field', 'edit', 'bc_schedule' ] ) ),
			'  and NOTHING else travels in the query string — no message, no posted value, no service code'
		);

		/*
		 * handle_save() puts no raw service error code in the query string.
		 * Scoped to that method's own body: the mapping helper legitimately
		 * READS get_error_code() to translate it, and the separate generation
		 * handler is outside this correction's scope.
		 */
		$admin_source = $this->code_only( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionScheduleAdmin.php' );
		$save_body    = '';
		if ( 1 === preg_match( '/function handle_save\(\)\s*:\s*void\s*\{(.*?)\n\t\}/s', $admin_source, $m ) ) {
			$save_body = $m[1];
		}
		$this->ok( '' !== $save_body, 'handle_save() was located for enumeration' );
		$this->same(
			0,
			substr_count( $save_body, 'get_error_code()' ),
			'handle_save() never pipes a service error code into the redirect — every code it emits comes from its own fixed map'
		);
		$this->ok(
			false !== strpos( $save_body, 'ui_error_code(' ),
			'  it routes every refusal through ui_error_code(), which cannot return anything outside SAVE_ERRORS'
		);

		/* An unrecognised outcome is not displayed, and an unknown code falls
		 * back to a message this screen owns rather than being echoed. */
		$spoofed = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'bc_saved' => 'totally_made_up' ]
		);
		$this->ok( false === strpos( $spoofed, 'totally_made_up' ), 'an outcome outside the vocabulary is never echoed onto the page' );

		$unknown = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'bc_saved' => 'error', 'bc_error' => 'zzz_unknown_code' ]
		);
		$this->ok( false === strpos( $unknown, 'zzz_unknown_code' ), 'an unknown error code is never echoed either' );
		$this->ok( false !== strpos( $unknown, 'was not saved' ), '  but the refusal is still reported' );

		/* A removed future date can commit before its captured sales-OFF write
		 * fails. Inject failure in that post-commit UPDATE, not the definition
		 * CAS, and assert the resulting notice does not deny the saved definition. */
		$removed_date  = $this->local_date( '+6 days' );
		$retained_date = $this->local_date( '+7 days' );
		$committed     = $service->create( $template, ScheduleExpansion::MODE_DATES, '18:00', [ $removed_date, $retained_date ], [ $package ] );
		$commit_id     = $committed instanceof SessionScheduleResult ? (int) $committed->schedule['schedule_id'] : 0;
		$this->ok( $commit_id > 0, 'post-commit fixture: two-date schedule exists' );
		$generated = $service->generate( $commit_id );
		$this->same( SessionScheduleResult::STATUS_OK, $generated instanceof SessionScheduleResult ? $generated->status : '', 'post-commit fixture: Bingo Sessions generated' );
		$removed_session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d AND session_date = %s', Migrations::sessions_table(), $commit_id, $removed_date ) );
		$this->ok( $removed_session > 0, 'post-commit fixture: removed-date occurrence exists' );
		$sales_on = ( new \BingoCommerce\Sessions\SessionService() )->setAvailableForSale( $removed_session, true );
		$this->ok( ! is_wp_error( $sales_on ), 'post-commit fixture: removed-date occurrence was offered for sale' );
		$before_commit = $service->get( $commit_id );
		$this->intercept( '/UPDATE \S+bingo_sessions\s+SET available_for_sale = 0/' );
		$commit_args = $this->query_args_of( $this->save( [
			'bc_action'    => 'edit',
			'schedule_id'  => (string) $commit_id,
			'mode'         => ScheduleExpansion::MODE_DATES,
			'start_time'   => '18:00',
			'future_dates' => $retained_date,
			'package_ids'  => [ (string) $package ],
		] ) );
		$this->same( 1, $this->stop_intercepting(), 'post-commit sales-OFF write failed after the definition CAS' );
		$this->same( 'error', $commit_args['bc_saved'] ?? '', 'post-commit error is not reported as a completed save' );
		$this->same( 'write_failed', $commit_args['bc_error'] ?? '', 'post-commit persistence error maps to the fixed UI code' );
		$after_commit = $service->get( $commit_id );
		$this->ok( is_array( $before_commit ) && is_array( $after_commit ) && $before_commit['active_definition_token'] !== $after_commit['active_definition_token'], 'definition actually committed before the error' );
		$this->same( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT available_for_sale FROM %i WHERE session_id = %d', Migrations::sessions_table(), $removed_session ) ), 'injected sales-OFF failure leaves the stored sales flag needing review' );
		$commit_notice = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $commit_args );
		$this->ok( false !== strpos( $commit_notice, 'Reload and check the schedule and sales state before trying again.' ), 'post-commit notice directs a state check before retry' );
		$this->ok( false === strpos( $commit_notice, 'The schedule definition was not saved.' ), 'post-commit notice does not deny the saved definition' );

		/* ---------------------------------------------------------------- *
		 * (4) The Portal template name, read-only, in both places.
		 * ---------------------------------------------------------------- */
		$template_label = self::PREFIX . ' Correction Bingo';

		$list = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG ] );
		$this->ok( false !== strpos( $list, 'Portal session' ), 'the list has a Portal session column' );
		$this->ok( false !== strpos( $list, $template_label ), '  showing the template NAME, not just its id' );

		$edit = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $schedule_id ]
		);
		$this->ok( false !== strpos( $edit, $template_label ), 'the edit form shows the template name' );
		$this->ok( false !== strpos( $edit, 'Read-only' ), '  stated as read-only' );
		$this->ok(
			0 === substr_count( $edit, 'name="portal_session_id"' ),
			'  and it is NOT an editable field — a schedule stays with its template'
		);

		/* The name is read through the gateway, never by direct SQL. */
		$this->ok( false !== strpos( $admin_code, 'PortalTemplateGateway' ), 'the screen reads templates through PortalTemplateGateway' );
		$this->same( 0, substr_count( $admin_code, 'portal_session_templates_table' ), '  and never touches the replica table itself' );
		$this->same( 0, substr_count( $admin_code, '$wpdb' ), '  the screen holds no $wpdb at all' );

		/* A replica read failure degrades to a safe label carrying the id, and
		 * takes nothing away: the schedule is still listed and still
		 * archivable. */
		$this->intercept( '/FROM \S+bingo_portal_session_templates/' );

		$degraded = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG ] );

		$this->ok( $this->stop_intercepting() > 0, 'template fallback: the interceptor actually fired' );

		$this->ok( false !== strpos( $degraded, '#' . $template ), 'an unreadable template degrades to a label carrying the Portal template ID' );
		$this->ok( false !== strpos( $degraded, 'name unavailable' ), '  said plainly, not guessed at' );
		$this->ok( false !== strpos( $degraded, 'value="archive"' ), '  and Archive is NOT blocked by a display failure' );
		$this->ok( false !== strpos( $degraded, '#' . $schedule_id ), '  the schedule itself is still listed' );

		restore_current_blog();
	}

	/**
	 * The `<form>` element carrying a given bc_action, or '' when none does.
	 *
	 * Used to assert the ABSENCE of a submittable edit form, which
	 * "no Save button" alone would not establish.
	 */
	private function form_for_action( string $html, string $bc_action ): string {
		$needle = 'name="bc_action" value="' . $bc_action . '"';
		$at     = strpos( $html, $needle );
		if ( false === $at ) {
			return '';
		}

		$open = strrpos( substr( $html, 0, $at ), '<form' );
		if ( false === $open ) {
			return '';
		}
		$close = strpos( $html, '</form>', $open );

		return false === $close ? substr( $html, $open ) : substr( $html, $open, $close - $open + 7 );
	}

	/* ================================================================== *
	 * Hall Manager UI corrections — timezone gate, conditional mode,
	 * labelled Packages, US dates, weekly range (requirements 9 and 12)
	 * ================================================================== */

	/** Real rendered form + bundled jQuery UI, in a disposable offline browser. */
	private function date_picker_browser_checks( string $form ): void {
		$chrome = (string) getenv( 'BC_TEST_CHROME' );
		if ( '' === $chrome ) {
			$this->notes[] = 'Browser date-control checks not run: set BC_TEST_CHROME to an installed Chrome/Chromium executable.';
			return;
		}
		$this->ok( is_executable( $chrome ), 'configured date-control browser exists' );
		if ( ! is_executable( $chrome ) ) {
			return;
		}

		$checks = <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var results = [];
    function check(value, message) { results.push([!!value, message]); }
    try {
        var $ = jQuery, form = document.querySelector('.bc-schedule-form');
        var list = form.querySelector('.bc-date-list');
        var rows = () => Array.from(list.querySelectorAll('.bc-date-row'));
        var dates = () => rows().map(row => row.querySelector('input'));
        var data = () => new FormData(form);
        var past = form.querySelector('[name="past_dates[]"]');
        var pastMarkup = past.outerHTML;
        var original = dates()[0], originalValue = original.value;
        check(form.querySelectorAll('.hasDatepicker').length === dates().length + 2, 'all specific and weekly fields have a real picker');
        check(Array.from(form.querySelectorAll('.bc-date-input')).every(input => $(input).datepicker('option', 'dateFormat') === 'mm/dd/yy'), 'instance format overrides non-US WordPress defaults');
        check(data().getAll('past_dates[]')[0] === past.value && /^\d{2}\/\d{2}\/\d{4}$/.test(past.value), 'past checkbox still submits separately, in US format');
        check(!data().has('recurrence_start') && !data().has('recurrence_end') && !data().has('weekdays[]'), 'dates mode does not submit weekly fields');
        check(form.querySelector('#bc-recurrence-start').disabled && form.querySelector('#bc-recurrence-start').willValidate === false, 'inactive weekly field is excluded from browser validation');
        form.querySelector('.bc-add-date').click();
        var added = dates()[1];
        check(rows().length === 2 && added.value === '', 'Add another date appends exactly one blank row');
        check(added.id !== original.id && added.parentNode.querySelector('label').htmlFor === added.id, 'new row has a unique accessible label');
        check($(added).hasClass('hasDatepicker') && added.parentNode.querySelectorAll('.ui-datepicker-trigger').length === 1, 'new row has its own picker without cloned widget state');
        function selectDay(input) {
            $(input).datepicker('show');
            var day = document.querySelector('#ui-datepicker-div td[data-handler="selectDay"]');
            var expected = String(Number(day.dataset.month) + 1).padStart(2, '0') + '/' + day.textContent.trim().padStart(2, '0') + '/' + day.dataset.year;
            day.querySelector('a').click();
            check(input.value === expected && /^\d{2}\/\d{2}\/\d{4}$/.test(input.value), 'calendar selection writes exactly MM/DD/YYYY: ' + input.name);
        }
        selectDay(added);
        check(data().getAll('future_dates[]')[1] === added.value, 'calendar date submits unchanged in US format');
        added.parentNode.querySelector('.bc-remove-date').click();
        check(rows().length === 1 && dates()[0] === original && original.value === originalValue, 'Remove date removes only its corresponding row');
        rows()[0].querySelector('.bc-remove-date').click();
        check(rows().length === 1 && dates()[0].value === '', 'removing final row leaves one empty date row');
        check($(dates()[0]).hasClass('hasDatepicker'), 'remaining empty row still has a picker');
        check(past.outerHTML === pastMarkup && past.checked, 'future-row removal leaves past keep/drop control untouched');
        original.value = originalValue;
        original.dispatchEvent(new Event('change', {bubbles:true}));
        check(data().getAll('future_dates[]')[0] === originalValue, 'manual MM/DD/YYYY input submits unchanged');
        original.value = '02/31/2026';
        original.dispatchEvent(new Event('change', {bubbles:true}));
        check(original.value === '02/31/2026', 'picker never silently rolls an impossible manual date forward');
        var weekly = form.querySelector('[name="mode"][value="weekly"]');
        weekly.checked = true;
        weekly.dispatchEvent(new Event('change', {bubbles:true}));
        check(!data().has('future_dates[]') && !data().has('past_dates[]'), 'weekly mode submits neither specific-date group');
        check(original.disabled && !original.willValidate && rows()[0].querySelector('.bc-remove-date').disabled, 'inactive date controls cannot validate or mutate');
        var start = form.querySelector('#bc-recurrence-start'), end = form.querySelector('#bc-recurrence-end');
        check(!start.disabled && !end.disabled, 'weekly controls become enabled');
        selectDay(start);
        selectDay(end);
        check(data().get('recurrence_start') === start.value && data().get('recurrence_end') === end.value, 'both weekly dates submit in MM/DD/YYYY');
        check($.datepicker.parseDate('mm/dd/yy', end.value) >= $.datepicker.parseDate('mm/dd/yy', start.value), 'picker offers an inclusive end bound');
        start.value = '12/31/2098';
        $(end).datepicker('show');
        check($.datepicker.formatDate('mm/dd/yy', $(end).datepicker('option', 'minDate')) === start.value, 'end picker follows a manually changed start across years');
        $(end).datepicker('hide');
        end.value = '12/30/2098';
        start.dispatchEvent(new Event('change', {bubbles:true}));
        check(end.value === '12/30/2098', 'manual end-before-start remains available for authoritative server rejection');
        start.value = '';
        $(end).datepicker('show');
        check($.datepicker.formatDate('mm/dd/yy', $(end).datepicker('option', 'minDate')) === start.dataset.min, 'clearing start restores the hall-local tomorrow hint');
        $(end).datepicker('hide');
        form.querySelector('[name="mode"][value="dates"]').checked = true;
        form.querySelector('[name="mode"][value="dates"]').dispatchEvent(new Event('change', {bubbles:true}));
        check(past.checked && data().has('past_dates[]') && !data().has('recurrence_end'), 'switching back restores past keep state and excludes weekly data');
        check(original.value === '02/31/2026', 'mode switching does not silently clear invalid manual input');
        past.checked = false;
        check(!data().has('past_dates[]'), 'unchecked past date is still dropped by omission');
        form.querySelector('.bc-add-date').click();
        check(rows().length === 2 && dates()[1].value === '', 'Add another date still works after removal and mode switches');
    } catch (error) { check(false, 'browser exception: ' + error.message); }
    var output = document.createElement('pre');
    output.id = 'bc-date-test-results';
    output.textContent = JSON.stringify(results);
    document.body.appendChild(output);
});
JS;

		$directory = sys_get_temp_dir() . '/bc-date-controls-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700 );
		try {
			$html = '<!doctype html><html><head><meta charset="utf-8">';
			foreach ( [ 'jquery/jquery.min.js', 'jquery/ui/core.min.js', 'jquery/ui/datepicker.min.js' ] as $script ) {
				$html .= '<script>' . file_get_contents( ABSPATH . WPINC . '/js/' . $script ) . '</script>';
			}
			// Deliberately non-US defaults: the production instance must override them.
			$html .= '<script>jQuery.datepicker.setDefaults({dateFormat:"dd/mm/yy"});</script></head><body>';
			$html .= $form . '<script>' . $checks . '</script></body></html>';
			file_put_contents( $directory . '/form.html', $html );
			$process = proc_open(
				[ $chrome, '--headless', '--disable-gpu', '--no-first-run', '--disable-background-networking', '--host-resolver-rules=MAP * ~NOTFOUND', '--user-data-dir=' . $directory . '/profile', '--dump-dom', '--timeout=15000', '--virtual-time-budget=1000', 'file://' . $directory . '/form.html' ],
				[ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'file', $directory . '/browser.log', 'w' ] ],
				$pipes
			);
			$this->ok( is_resource( $process ), 'disposable browser started' );
			if ( ! is_resource( $process ) ) {
				return;
			}
			// Read the result, not EOF: Chrome helpers can keep stdout open after
			// --dump-dom has completed. No sleeps or timing-dependent UI assertions.
			stream_set_blocking( $pipes[1], false );
			$output = '';
			$deadline = microtime( true ) + 30;
			do {
				$read = [ $pipes[1] ];
				$write = $except = null;
				if ( stream_select( $read, $write, $except, 1 ) > 0 ) {
					$output .= stream_get_contents( $pipes[1] );
				}
				$complete = 1 === preg_match( '/<pre id="bc-date-test-results">([^<]*)<\/pre>/', $output );
			} while ( ! $complete && ! feof( $pipes[1] ) && microtime( true ) < $deadline );
			proc_terminate( $process );
			fclose( $pipes[1] );
			proc_close( $process );
			$this->ok( $complete, 'browser returns its results within the bounded read timeout' );
			preg_match( '/<pre id="bc-date-test-results">([^<]*)<\/pre>/', $output, $matches );
			$results = json_decode( html_entity_decode( $matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			$this->ok( is_array( $results ) && count( $results ) >= 29, 'real browser returned every date-control assertion' );
			foreach ( is_array( $results ) ? $results : [] as [ $passed, $message ] ) {
				$this->ok( true === $passed, 'browser: ' . $message );
			}
		} finally {
			// Only this test's random temporary profile/HTML; never a user profile.
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $directory );
		}
	}

	private function manager_ui_group(): void {
		global $wpdb;

		$this->group( 'Manager UI' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$template = $this->plant_template( 'Manager UI Bingo', 'active', 1 );
		$package  = $this->make_package( 'Manager UI Bingo' );
		$service  = new SessionScheduleService();

		$create_args = [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => (string) $template ];

		/* ---------------------------------------------------------------- *
		 * (1) No named hall time zone blocks the form entirely.
		 * ---------------------------------------------------------------- */
		$zone_before = get_option( 'timezone_string' );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5 );

		$blocked = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $create_args );

		$this->ok( false !== strpos( $blocked, 'Set Hall Timezone' ), 'no zone: the screen offers inline timezone setup' );
		$this->ok( false === strpos( $blocked, 'options-general.php' ), '  without sending a Manager to Settings → General' );
		$this->same( '', $this->form_for_action( $blocked, 'create' ), '  NO create form is rendered' );
		$this->ok( false === strpos( $blocked, 'Create Sessions</button>' ), '  and no Create Sessions button' );
		$this->ok( false === strpos( $blocked, 'name="package_ids[]"' ), '  no Package control either' );
		$this->ok( false === strpos( $blocked, 'name="future_dates[]"' ), '  and no date control' );

		/* Even a hand-made POST creates nothing: the service refuses before it
		 * inserts, so no inert header is left behind. */
		$ids_before = $this->schedule_ids( $service );
		$zone_args  = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $template,
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00',
					'future_dates'      => [ $this->us_date( '+2 days' ) ],
					'package_ids'       => [ (string) $package ],
				]
			)
		);
		$this->same( 'error', $zone_args['bc_saved'] ?? '', 'no zone: a hand-made POST is refused' );
		$this->same( 'no_timezone', $zone_args['bc_error'] ?? '', '  naming the time zone' );
		$this->same( $ids_before, $this->schedule_ids( $service ), '  and NO inert schedule is created' );

		update_option( 'timezone_string', false === $zone_before ? self::ZONE : $zone_before );

		/* ---------------------------------------------------------------- *
		 * (5) and (7) Labels and the standing instruction.
		 * ---------------------------------------------------------------- */
		$form = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $create_args );

		foreach (
			[
				'Schedule This Session',
				'Specific dates',
				'Repeating weekly',
				'Packages customers can purchase',
				'Create Sessions',
				'Choose when this session will run and which Packages customers can purchase.',
			] as $label
		) {
			$this->ok( false !== strpos( $form, $label ), "the form shows the required label: {$label}" );
		}

		/* ---------------------------------------------------------------- *
		 * (2) Mode is conditional, and only the ACTIVE mode's data submits.
		 * ---------------------------------------------------------------- */
		$this->ok( false !== strpos( $form, 'class="bc-mode-dates"' ), 'the specific-dates fields are their own group' );
		$this->ok( false !== strpos( $form, 'class="bc-mode-weekly"' ), 'the weekly fields are their own group' );
		$this->ok(
			1 === preg_match( '/class="bc-mode-weekly" style="display:none"/', $form ),
			'in specific-dates mode the weekly group is HIDDEN'
		);
		$this->ok(
			1 === preg_match( '/<input[^>]*name="weekdays\[\]"[^>]*disabled/', $form ),
			'  and its inputs are disabled, so a browser will not submit them'
		);
		$this->ok(
			1 !== preg_match( '/<input[^>]*name="future_dates\[\]"[^>]*disabled/', $form ),
			'  while the active group stays enabled'
		);

		/* The SERVER ignores the inactive mode's fields whatever arrives. A
		 * weekly save carrying leftover explicit dates must commit the weekly
		 * definition, not be refused by a control the Manager cannot see. */
		$weekly_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $template,
					'mode'              => ScheduleExpansion::MODE_WEEKLY,
					'start_time'        => '18:00',
					'future_dates'      => [ 'not-a-date-at-all' ],
					'weekdays'          => [ '1', '4' ],
					'recurrence_start'  => $this->us_date( '+2 days' ),
					'recurrence_end'    => $this->us_date( '+20 days' ),
					'package_ids'       => [ (string) $package ],
				]
			)
		);
		$this->same( 'created', $weekly_args['bc_saved'] ?? '', 'weekly save: leftover specific-dates input is IGNORED, not validated' );

		$weekly_id  = (int) ( $weekly_args['bc_schedule'] ?? 0 );
		$weekly_row = $service->get( $weekly_id );
		$this->same( ScheduleExpansion::MODE_WEEKLY, is_array( $weekly_row ) ? (string) $weekly_row['mode'] : '', '  and the weekly definition is what was committed' );
		$this->same( 5, is_array( $weekly_row ) ? (int) $weekly_row['weekday_mask'] : 0, '  with the chosen weekdays' );

		/* The mirror image: a specific-dates save carrying leftover weekly junk.
		 * Its own template avoids a name/date collision whenever +3 days lands
		 * on a weekday emitted by the preceding independent weekly scenario. */
		$dates_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $this->plant_template( 'Manager UI Specific Dates Bingo', 'active', 1 ),
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00',
					'future_dates'      => [ $this->us_date( '+3 days' ) ],
					'weekdays'          => [ '999' ],
					'recurrence_start'  => 'rubbish',
					'recurrence_end'    => 'rubbish',
					'package_ids'       => [ (string) $package ],
				]
			)
		);
		$this->same( 'created', $dates_args['bc_saved'] ?? '', 'specific-dates save: leftover weekly input is IGNORED, not validated' );

		$dates_row = $service->get( (int) ( $dates_args['bc_schedule'] ?? 0 ) );
		$this->same( ScheduleExpansion::MODE_DATES, is_array( $dates_row ) ? (string) $dates_row['mode'] : '', '  and the explicit-dates definition is what was committed' );
		$this->same( 0, is_array( $dates_row ) ? (int) $dates_row['weekday_mask'] : -1, '  with no weekday mask carried over' );

		/* ---------------------------------------------------------------- *
		 * (3) A date-entry control, never a raw textarea, never prefilled past.
		 * ---------------------------------------------------------------- */
		$this->ok( false === strpos( $form, '<textarea' ), 'the raw Dates textarea is gone' );
		/*
		 * A TEXT control, not `type="date"`. A native date input's TYPED,
		 * VISIBLE format follows the browser/OS locale, not this screen's
		 * choice — `value`/`min` being forced ISO says nothing about what the
		 * Manager actually sees or types. A plain text input under this
		 * screen's own MM/DD/YYYY parsing is what makes the display format
		 * actually guaranteed rather than merely claimed.
		 */
		$this->ok( false === strpos( $form, 'type="date"' ), 'no control on this screen uses the browser\'s native date picker' );
		$this->ok( 1 === preg_match( '/<input[^>]*type="text"[^>]*name="future_dates\[\]"/', $form ), '  the date-entry control is a plain text input instead' );
		$this->ok( false !== strpos( $form, '>Remove date</button>' ), 'each future row offers a labelled Remove date button' );
		$this->ok( false !== strpos( $form, '>Add another date</button>' ), 'Add another date remains available' );
		$this->same( 1, substr_count( $form, 'class="bc-date-row"' ), 'an empty create form has one future date row' );

		$original_get = $_GET;
		wp_dequeue_script( 'jquery-ui-datepicker' );
		wp_dequeue_style( 'jquery-ui-style' );
		$_GET = [ 'page' => SessionAdmin::PAGE_SLUG ];
		SessionScheduleAdmin::enqueue_date_picker();
		$this->ok( ! wp_script_is( 'jquery-ui-datepicker', 'enqueued' ), 'picker is not enqueued on other admin screens' );
		$_GET = [ 'page' => SessionScheduleAdmin::PAGE_SLUG ];
		SessionScheduleAdmin::enqueue_date_picker();
		$this->ok( wp_script_is( 'jquery-ui-datepicker', 'enqueued' ), 'schedule screen enqueues WordPress bundled datepicker' );
		$this->ok( wp_style_is( 'jquery-ui-style', 'enqueued' ), 'schedule screen reuses existing local admin picker styling' );
		$this->same( 20, has_action( 'admin_enqueue_scripts', [ SessionScheduleAdmin::class, 'enqueue_date_picker' ] ), 'picker enqueue is registered after admin styles' );
		$_GET = $original_get;

		$tomorrow = ( new DateTimeImmutable( 'now', new DateTimeZone( self::ZONE ) ) )->modify( '+1 day' )->format( 'm/d/Y' );
		$this->ok(
			1 === preg_match( '/name="future_dates\[\]"[^>]*data-min="' . preg_quote( $tomorrow, '/' ) . '"/', $form ),
			'  carrying tomorrow as data-min, in MM/DD/YYYY — the same format it displays'
		);

		/* An edit never prefills the entry control with a date that has passed. */
		$prefill = $service->create( $template, ScheduleExpansion::MODE_DATES, '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$prefill_id = $prefill instanceof SessionScheduleResult ? (int) $prefill->schedule['schedule_id'] : 0;
		$past       = $this->local_date( '-6 days' );
		$wpdb->insert(
			Migrations::schedule_dates_table(),
			[
				'schedule_id'      => $prefill_id,
				'definition_token' => (string) $prefill->schedule['active_definition_token'],
				'schedule_date'    => $past,
				'created_at'       => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		$edit_html = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $prefill_id ]
		);
		$this->ok(
			1 !== preg_match( '/name="future_dates\[\]"[^>]*value="' . preg_quote( $past, '/' ) . '"/', $edit_html ),
			'a past date is NOT prefilled into the date-entry control'
		);
		$this->ok(
			1 === preg_match( '/name="past_dates\[\]"[^>]*value="' . preg_quote( $this->us_date( '-6 days' ), '/' ) . '"/', $edit_html ),
			'  it stays in the keep/drop control instead, where §8.3 puts it'
		);
		$this->date_picker_browser_checks( $edit_html );

		/* Adding a past date is refused, with the error beside the date field. */
		$past_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'    => 'edit',
					'schedule_id'  => (string) $prefill_id,
					'mode'         => ScheduleExpansion::MODE_DATES,
					'start_time'   => '18:00',
					'future_dates' => [ $this->us_date( '-3 days' ) ],
					'package_ids'  => [ (string) $package ],
				]
			)
		);
		$this->same( 'error', $past_args['bc_saved'] ?? '', 'a past date is REFUSED' );
		$this->same( 'date_in_past', $past_args['bc_error'] ?? '', '  named as such' );
		$this->same( 'dates', $past_args['bc_field'] ?? '', '  and attributed to the date field' );

		/* ---------------------------------------------------------------- *
		 * (4) Every Package checkbox carries a visible label.
		 * ---------------------------------------------------------------- */
		$boxes = preg_match_all( '/<input[^>]*name="package_ids\[\]"[^>]*>/', $form, $matches );
		$this->ok( $boxes > 0, 'the form offers Package checkboxes' );

		$unlabelled = 0;
		foreach ( $matches[0] as $box ) {
			$at    = strpos( $form, $box );
			$after = substr( $form, $at + strlen( $box ), 400 );
			// The visible text between this input and the end of its label.
			$text  = trim( wp_strip_all_tags( substr( $after, 0, strpos( $after, '</label>' ) ?: 0 ) ) );
			if ( '' === $text ) {
				++$unlabelled;
			}
		}
		$this->same( 0, $unlabelled, 'EVERY Package checkbox has visible label text — none is a bare box' );
		$this->ok( false !== strpos( $form, '(available)' ), '  and each shows its status' );

		/* A Package whose NAME cannot be read is labelled by id and disabled,
		 * never rendered bare and never silently dropped. */
		/*
		 * A Package's name is the linked WooCommerce PRODUCT's title — there is
		 * no name column on bingo_packages at all — so an unreadable name is
		 * simulated by emptying that title, which is the only thing that could
		 * actually produce this state.
		 */
		$nameless   = $this->make_package( 'Nameless Bingo' );
		$product_id = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT wc_product_id FROM ' . $wpdb->prefix . 'bingo_packages WHERE package_id = %d', $nameless )
		);
		$this->ok( $product_id > 0, 'nameless fixture: the Package has a linked product to blank' );
		$wpdb->update( $wpdb->posts, [ 'post_title' => '' ], [ 'ID' => $product_id ] );
		clean_post_cache( $product_id );

		$with_nameless = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $create_args );
		$this->ok( false !== strpos( $with_nameless, 'name unavailable' ), 'an unreadable Package name is stated plainly' );
		$this->ok( false !== strpos( $with_nameless, '#' . $nameless ), '  with the Package id, so it can still be identified' );
		$this->ok(
			1 === preg_match( '/<input[^>]*value="' . $nameless . '"[^>]*disabled/', $with_nameless ),
			'  and that option is DISABLED rather than selectable'
		);

		/* ---------------------------------------------------------------- *
		 * (10) and (12) US dates in, canonical dates out.
		 * ---------------------------------------------------------------- */
		$us_form = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $create_args );
		$this->ok( false !== strpos( $us_form, 'MM/DD/YYYY' ), 'the form states the MM/DD/YYYY format' );
		$this->ok( false !== strpos( $us_form, '10/12/2026' ), '  with a US-format example' );

		$us_date  = $this->us_date( '+7 days' );
		$iso_date = $this->local_date( '+7 days' );
		$us_args  = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $this->plant_template( 'Manager UI US Date Bingo', 'active', 1 ),
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00',
					'future_dates'      => [ $us_date ],
					'package_ids'       => [ (string) $package ],
				]
			)
		);
		$this->same( 'created', $us_args['bc_saved'] ?? '', "a US-format date ({$us_date}) is accepted" );

		$us_id    = (int) ( $us_args['bc_schedule'] ?? 0 );
		$us_row   = $service->get( $us_id );
		$us_dates = $service->definition_dates( $us_id, (string) $us_row['active_definition_token'] );
		$this->same( [ $iso_date ], is_array( $us_dates ) ? $us_dates : [], "  and stored canonically as {$iso_date}" );

		/* The edit form renders that same date back in US form — MM/DD/YYYY,
		 * not the ISO value it is stored as. */
		$list = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $us_id ] );
		$this->ok( false !== strpos( $list, 'value="' . $us_date . '"' ), 'the date control round-trips the stored value, displayed as MM/DD/YYYY' );
		$this->ok( false === strpos( $list, 'value="' . $iso_date . '"' ), '  never as the raw ISO storage value' );

		/* Ambiguous or impossible dates are REJECTED, never guessed at. */
		foreach (
			[
				'13/01/2026' => 'a month that does not exist',
				'02/31/2026' => 'a day the calendar would silently roll over',
				'2026/10/12' => 'a non-US ordering',
				'10-12-2026' => 'the wrong separator',
				'next friday' => 'free text',
			] as $bad => $why
		) {
			$bad_args = $this->query_args_of(
				$this->save(
					[
						'bc_action'         => 'create',
						'portal_session_id' => (string) $template,
						'mode'              => ScheduleExpansion::MODE_DATES,
						'start_time'        => '18:00',
						'future_dates'      => [ $bad ],
						'package_ids'       => [ (string) $package ],
					]
				)
			);
			$this->same( 'error', $bad_args['bc_saved'] ?? '', "'{$bad}' is rejected — {$why}" );
			$this->same( 'invalid_date', $bad_args['bc_error'] ?? '', '  as an unreadable date' );
			$this->same( 'dates', $bad_args['bc_field'] ?? '', '  beside the date field' );
		}

		/* ---------------------------------------------------------------- *
		 * (11) Weekly range, enforced on the SERVER.
		 *
		 * EACH CASE GETS ITS OWN TEMPLATE AND PACKAGE. All three weekly
		 * schedules below select the same weekday, so reusing one shared
		 * template — and therefore one shared computed display name — across
		 * "equal" and "after" would make the "after" case's Monday ten days
		 * out collide with the "equal" case's own Monday ten days out
		 * (§4.2's foreign_name_date_clash, a genuine preflight blocker doing
		 * exactly its job). That is a fixture collision, not the range rule
		 * this block exists to prove, and giving each case its own template
		 * removes it rather than masking it.
		 * ---------------------------------------------------------------- */
		$range = [
			'before' => [ $this->us_date( '+20 days' ), $this->us_date( '+10 days' ), 'error' ],
			'equal'  => [ $this->us_date( '+10 days' ), $this->us_date( '+10 days' ), 'created' ],
			'after'  => [ $this->us_date( '+10 days' ), $this->us_date( '+24 days' ), 'created' ],
		];
		foreach ( $range as $label => $case ) {
			[ $start, $end, $expected ] = $case;
			$range_template = $this->plant_template( "Range {$label} Bingo", 'active', 1 );
			$range_package  = $this->make_package( "Range {$label} Bingo" );
			$range_args     = $this->query_args_of(
				$this->save(
					[
						'bc_action'         => 'create',
						'portal_session_id' => (string) $range_template,
						'mode'              => ScheduleExpansion::MODE_WEEKLY,
						'start_time'        => '18:00',
						'weekdays'          => [ '1' ],
						'recurrence_start'  => $start,
						'recurrence_end'    => $end,
						'package_ids'       => [ (string) $range_package ],
					]
				)
			);
			$this->same( $expected, $range_args['bc_saved'] ?? '', "weekly range ({$label}): end {$end} against start {$start}" );

			if ( 'error' === $expected ) {
				$this->same( 'end_before_start', $range_args['bc_error'] ?? '', '  named end_before_start' );
				$this->same( 'recurrence_end', $range_args['bc_field'] ?? '', '  and attributed to the End date field' );
			}
		}

		/*
		 * The rule is the SERVER's. The browser only ever narrows the End date
		 * input; nothing in the markup is what refuses the range.
		 */
		$end_before = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[
				'page'       => SessionScheduleAdmin::PAGE_SLUG,
				'action'     => 'new',
				'portal_session_id' => (string) $template,
				'bc_saved'   => 'error',
				'bc_error'   => 'end_before_start',
				'bc_field'   => 'recurrence_end',
			]
		);
		$this->ok(
			false !== strpos( $end_before, 'End date must be the same as or later than the start date.' ),
			'the End date error is the exact wording required'
		);

		/*
		 * And it is rendered BESIDE End date — not ONLY at the top. The
		 * page-level notice legitimately shows it too, so the assertion looks
		 * for an occurrence AFTER the End date control rather than for the
		 * first occurrence anywhere, which would only ever find the notice.
		 */
		$end_at = strpos( $end_before, 'id="bc-recurrence-end"' );
		$this->ok( false !== $end_at, 'the End date control is rendered' );
		$beside = false !== $end_at
			&& false !== strpos( substr( $end_before, $end_at ), 'End date must be the same as or later than the start date.' );
		$this->ok( $beside, '  and the error sits AFTER it, beside the field it is about' );
		$this->same(
			2,
			substr_count( $end_before, 'End date must be the same as or later than the start date.' ),
			'  appearing exactly twice: once in the page notice and once beside the field'
		);

		/* ---------------------------------------------------------------- *
		 * (6) and (11) A rejected form comes back with its values.
		 * ---------------------------------------------------------------- */
		$held_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $template,
					'mode'              => ScheduleExpansion::MODE_WEEKLY,
					'start_time'        => '19:30',
					'weekdays'          => [ '2' ],
					'recurrence_start'  => $this->us_date( '+18 days' ),
					'recurrence_end'    => $this->us_date( '+4 days' ),
					'package_ids'       => [ (string) $package ],
				]
			)
		);
		$this->same( 'error', $held_args['bc_saved'] ?? '', 'preserved values: the save was refused' );

		$returned = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $held_args + $create_args );
		$this->ok( false !== strpos( $returned, 'value="19:30"' ), '  the start time comes back' );
		// Preserved EXACTLY as posted — MM/DD/YYYY, matching what the Manager
		// actually typed, not converted to the ISO storage form.
		$this->ok( false !== strpos( $returned, 'value="' . $this->us_date( '+18 days' ) . '"' ), '  the start date comes back, in the MM/DD/YYYY form it was typed in' );
		$this->ok( false !== strpos( $returned, 'value="' . $this->us_date( '+4 days' ) . '"' ), '  the rejected end date comes back the same way, so it can be corrected' );
		$this->ok( 1 === preg_match( '/value="2"[^>]*checked/', $returned ), '  the chosen weekday is still checked' );
		$this->ok( 1 === preg_match( '/value="weekly"[^>]*checked/', $returned ), '  and the form is still in weekly mode' );

		/* Held input is consumed, so a later reload is not a stale draft. */
		$fresh = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $create_args );
		$this->ok( false === strpos( $fresh, 'value="19:30"' ), '  and a later reload shows a clean form, not the stale draft' );

		/* The generic-only refusal is gone. */
		$admin_code = $this->code_only( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionScheduleAdmin.php' );
		$this->same( 0, substr_count( $admin_code, 'The change was refused.' ), 'the generic-only "The change was refused." message is gone' );

		restore_current_blog();
	}

	private function timezone_setup_group(): void {
		global $wpdb;
		$this->group( 'Hall timezone setup' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );
		$original_zone = get_option( 'timezone_string' );
		// get_option('gmt_offset') is dynamically filtered by WordPress using
		// timezone_string. Compare the stored value to detect an actual write.
		$offset_sql = $wpdb->prepare( 'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s', 'gmt_offset' );
		$original_offset = $wpdb->get_var( $offset_sql );
		$other_zone = get_blog_option( $this->other_hall, 'timezone_string' );
		$template = $this->plant_template( 'Timezone Setup Bingo', 'active', 1 );
		$package = $this->make_package( 'Timezone Setup Bingo' );
		$service = new SessionScheduleService();
		$existing = $service->create( $template, ScheduleExpansion::MODE_DATES, '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$this->ok( $existing instanceof SessionScheduleResult, 'an existing schedule has a stored timezone before setup' );
		$before = $wpdb->get_results( 'SELECT * FROM ' . Migrations::schedules_table() . ' ORDER BY schedule_id', ARRAY_A );

		// A real non-Super-Admin role, planted only for this disposable user/site,
		// following session-increment-1.php's role fixture (not a capability mock).
		$manager = $this->create_user_without_capability();
		$cap_key = $wpdb->prefix . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $manager, 'meta_key' => $cap_key ], [ '%d', '%s' ] );
		$wpdb->insert( $wpdb->usermeta, [ 'user_id' => $manager, 'meta_key' => $cap_key, 'meta_value' => serialize( [ 'bc_hall_manager' => true ] ) ], [ '%d', '%s', '%s' ] );
		clean_user_cache( $manager );
		wp_set_current_user( $manager );
		$this->ok( ! is_super_admin() && current_user_can( 'bc_manage_sessions' ) && ! current_user_can( 'manage_options' ), 'real Hall Manager can manage sessions but cannot use general settings' );

		try {
			update_option( 'timezone_string', '' );
			$get = [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => (string) $template ];
			$html = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $get );
			$this->ok( false !== strpos( $html, 'class="bc-timezone-form"' ), 'missing timezone renders a setup form on Add to Store' );
			$this->ok( false === strpos( $html, 'class="bc-schedule-form"' ), 'no schedule form is exposed before timezone setup' );
			$this->ok( false !== strpos( $html, 'for="bc-hall-timezone"' ), 'timezone selector has a label' );
			$this->ok( false !== strpos( $html, '<option value="America/New_York">America/New_York</option>' ), 'selector offers named IANA America/New_York' );
			$this->ok( false === strpos( $html, 'value="UTC-5"' ), 'selector does not offer raw offsets' );
			$this->ok( false !== strpos( $html, 'Save Timezone and Continue' ) && false !== strpos( $html, 'session dates and sales windows' ), 'form explains the purpose and offers the continuation button' );
			$this->ok( false === strpos( $html, 'options-general.php' ), 'Hall Manager sees no Settings → General link' );
			$this->ok( false !== has_action( 'admin_post_' . SessionScheduleAdmin::POST_ACTION_TIMEZONE, [ SessionScheduleAdmin::class, 'handle_timezone' ] ), 'timezone save uses its registered authenticated handler' );
			$this->same( false, has_action( 'admin_post_nopriv_' . SessionScheduleAdmin::POST_ACTION_TIMEZONE ), 'no anonymous timezone handler is registered' );
			$nonce = wp_create_nonce( self::NONCE_TIMEZONE . $this->hall );
			$this->ok( false !== strpos( $html, 'value="' . $nonce . '"' ), 'rendered form carries the dedicated site-bound nonce' );
			$post = [ 'portal_session_id' => (string) $template, '_wpnonce' => $nonce, 'timezone_string' => 'America/Chicago' ];
			$this->count_matches( 'timezone_headers', '/^\s*(INSERT|UPDATE|DELETE).*bingo_session_schedules\b/is' );

			foreach ( [ '', 'UTC-5', '+05:30', 'EST', 'Not/A_Zone', [ 'America/New_York' ] ] as $invalid ) {
				$result = $this->run_timezone( 'POST', array_replace( $post, [ 'timezone_string' => $invalid ] ) );
				$this->same( 400, $result instanceof AdminDied ? $result->status : 0, 'invalid named-zone input is refused' );
				$this->same( '', get_option( 'timezone_string' ), 'invalid input does not change the hall option' );
			}
			update_option( 'timezone_string', 'UTC-5' );
			$this->ok( false !== strpos( $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $get ), 'class="bc-timezone-form"' ), 'an existing invalid offset also shows timezone setup' );
			update_option( 'timezone_string', '' );

			foreach ( [ '-1', 'not-an-id', '999999999' ] as $invalid_id ) {
				$result = $this->run_timezone( 'POST', array_replace( $post, [ 'portal_session_id' => $invalid_id ] ) );
				$this->same( 403, $result instanceof AdminDied ? $result->status : 0, 'invalid return context cannot change the hall timezone' );
			}
			$result = $this->run_timezone( 'POST', array_replace( $post, [ '_wpnonce' => wp_create_nonce( self::NONCE_MUTATION ) ] ) );
			$this->ok( $result instanceof AdminDied, 'a schedule-save nonce cannot authorize timezone setup' );

			// Same user can administer both halls, but a nonce from hall A cannot
			// authorize a POST in hall B, even if its template id is otherwise valid.
			wp_set_current_user( $this->super_admin_id );
			$hall_nonce = wp_create_nonce( self::NONCE_TIMEZONE . $this->hall );
			switch_to_blog( $this->other_hall );
			try {
				$foreign_template = $this->plant_template( 'Other Hall Timezone Bingo', 'active', 1 );
				$result = $this->run_timezone( 'POST', [ '_wpnonce' => $hall_nonce, 'portal_session_id' => $foreign_template, 'timezone_string' => 'Asia/Tokyo' ] );
				$this->ok( $result instanceof AdminDied, 'replaying a nonce in another authorized hall is refused' );
				$this->same( $other_zone, get_option( 'timezone_string' ), 'wrong-hall POST leaves that hall unchanged' );
			} finally {
				restore_current_blog();
			}
			wp_set_current_user( $manager );
			$result = $this->run_timezone( 'POST', array_replace( $post, [ 'portal_session_id' => $foreign_template ] ) );
			$this->same( 403, $result instanceof AdminDied ? $result->status : 0, 'another hall\'s template cannot be used as return context' );

			// A real failed option UPDATE, not an update_option() mock.
			$this->intercept( '/^UPDATE\s+`?' . preg_quote( $wpdb->options, '/' ) . '`?.*timezone_string/s' );
			$suppressed = $wpdb->suppress_errors( true );
			try {
				$result = $this->run_timezone( 'POST', $post );
			} finally {
				$wpdb->suppress_errors( $suppressed );
				$hits = $this->stop_intercepting();
			}
			$this->same( 1, $hits, 'failed-update fixture intercepted exactly the timezone UPDATE' );
			$this->same( 500, $result instanceof AdminDied ? $result->status : 0, 'failed update is refused, not redirected as success' );
			$this->same( '', get_option( 'timezone_string' ), 'failed update leaves timezone unset' );

			$result = $this->run_timezone( 'POST', $post + [ 'redirect_to' => 'https://example.invalid/escape', 'site_id' => $this->other_hall, 'edit' => 99 ] );
			$this->ok( $result instanceof AdminRedirected, 'valid timezone save redirects' );
			$this->same( 303, $this->last_redirect_status, 'timezone save uses HTTP 303' );
			$this->same( 'America/Chicago', get_option( 'timezone_string' ), 'valid save updates the current hall timezone' );
			$this->same( $original_offset, $wpdb->get_var( $offset_sql ), 'timezone save does not change stored gmt_offset' );
			$this->same( $other_zone, get_blog_option( $this->other_hall, 'timezone_string' ), 'valid save leaves the other hall timezone unchanged' );
			$location = $result instanceof AdminRedirected ? $result->getMessage() : '';
			$this->same( $get, $this->query_args_of( $location ), 'redirect preserves only the fixed Add to Store route and numeric template id' );
			$this->same( admin_url( 'admin.php' ), strtok( $location, '?' ), 'redirect stays in the current hall admin, ignoring supplied URL/site id' );
			$continued = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $this->query_args_of( $location ) );
			$this->ok( false !== strpos( $continued, 'class="bc-schedule-form"' ) && false !== strpos( $continued, 'America/Chicago' ), 'redirected Add to Store renders the normal schedule form with the saved zone' );
			$this->ok( false === strpos( $continued, 'class="bc-timezone-form"' ), 'completed setup form is no longer offered' );
			$result = $this->run_timezone( 'POST', $post );
			$this->ok( $result instanceof AdminRedirected, 'repeating an unchanged valid timezone save safely continues' );
			$this->same( 0, $this->stop_counting( 'timezone_headers' ), 'timezone setup/refusals issue no schedule header writes' );
			$this->same( $before, $wpdb->get_results( 'SELECT * FROM ' . Migrations::schedules_table() . ' ORDER BY schedule_id', ARRAY_A ), 'all existing schedule rows and stored timezones remain byte-identical; no header was created' );
		} finally {
			$this->stop_intercepting();
			$this->stop_counting( 'timezone_headers' );
			wp_set_current_user( $this->super_admin_id );
			update_option( 'timezone_string', $original_zone );
			$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $manager, 'meta_key' => $cap_key ], [ '%d', '%s' ] );
			clean_user_cache( $manager );
			restore_current_blog();
		}
	}

	/** A date in the hall's zone, in the US form the screen displays. */
	private function us_date( string $modifier ): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( self::ZONE ) ) )->modify( $modifier )->format( 'm/d/Y' );
	}

	/* ================================================================== *
	 * Generate through admin-post.php
	 * ================================================================== */

	private function generate_handler_group(): void {
		global $wpdb;

		$this->group( 'Generate handler' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$template = $this->plant_template( 'Generate Bingo', 'active', 1 );
		$package  = $this->make_package( 'Generate Bingo' );
		$service  = new SessionScheduleService();

		$created     = $service->create( $template, ScheduleExpansion::MODE_DATES, '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$schedule_id = $created instanceof SessionScheduleResult ? (int) $created->schedule['schedule_id'] : 0;
		$this->ok( $schedule_id > 0, 'generate fixture: a schedule exists' );
		$edit = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => (string) $schedule_id ] );
		$this->ok( false !== strpos( $edit, 'previously offered for sale keeps its original time and Package assignments' ), 'edit form explains protected time and Packages' );
		$this->ok( false !== strpos( $edit, 'Removing one of its future dates stops purchases for that occurrence but keeps its record' ) && false !== strpos( $edit, 'create a separate schedule' ), 'edit form explains future-date removal and a separate time' );
		$list = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG ] );
		preg_match( '/<tr>\s*<td>\s*<strong>#' . $schedule_id . '<\/strong>.*?<\/tr>/s', $list, $row_match );
		$row_html = $row_match[0] ?? '';
		$this->ok( false !== strpos( $row_html, 'class="bc-schedule-actions" style="display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;"' ), 'each action is a separate spaced control' );
		$this->ok( false !== strpos( $row_html, 'Edit schedule' ) && false === strpos( $row_html, 'Edit dates' ) && false !== strpos( $row_html, 'Update Sessions' ), 'action labels describe the schedule and session update' );
		$this->same( 2, substr_count( $row_html, '<form method="post"' ), 'Archive and Update Sessions retain separate POST forms' );
		$this->same( 2, substr_count( $row_html, 'name="_wpnonce"' ), 'both action forms retain their own nonces' );
		$save_notice = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'bc_saved' => 'updated' ] );
		$this->ok( false !== strpos( $save_notice, 'Schedule saved. Updating Bingo Sessions is a separate step' ) && false !== strpos( $save_notice, 'Removed future dates stop sales' ), 'save notice distinguishes committed schedule from session update and sales stop' );

		$location = $this->run_generate( $schedule_id, true );

		$this->ok( '' !== $location, 'the handler REDIRECTS rather than rendering (PRG)' );
		$this->ok( false !== strpos( $location, 'bc_generated=' . SessionScheduleResult::STATUS_OK ), '  reporting status ok' );
		$this->ok( false !== strpos( urldecode( $location ), 'created:1' ), '  and the counts it actually achieved' );
		$this->ok( false !== strpos( $location, 'page=' . SessionScheduleAdmin::PAGE_SLUG ), '  back to the schedules screen' );

		$occurrences = $this->occurrence_count( $schedule_id );
		$this->same( 1, $occurrences, 'the occurrence really was generated' );

		/* The notice re-renders from the redirect, reading only allowlisted
		 * values back out of the query string. */
		$notice = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[
				'page'         => SessionScheduleAdmin::PAGE_SLUG,
				'bc_generated' => SessionScheduleResult::STATUS_OK,
				'bc_counts'    => 'created:1,completed:1,not_a_real_count:9',
			]
		);
		$this->ok( false !== strpos( $notice, 'Bingo Sessions updated.' ), 'the session update success notice renders after the redirect' );
		$this->ok( false !== strpos( $notice, 'created: 1' ), '  showing a recognised count' );
		$this->ok( false === strpos( $notice, 'not_a_real_count' ), '  and DROPPING a count name outside the §8.6 vocabulary rather than echoing it' );

		/* A failing run is reported as failing, and says what to do next. */
		$failed = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[
				'page'         => SessionScheduleAdmin::PAGE_SLUG,
				'bc_generated' => SessionScheduleResult::STATUS_FAILED,
				'bc_code'      => 'template_renamed',
			]
		);
		$this->ok( false !== strpos( $failed, 'were not fully updated' ), 'a failed run is never described as finished' );
		$this->ok( false !== strpos( $failed, 'renamed' ), '  and names the category in the Manager\'s terms' );
		$general = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], [
			'page' => SessionScheduleAdmin::PAGE_SLUG, 'bc_generated' => SessionScheduleResult::STATUS_FAILED,
			'bc_code' => 'preflight_conflict',
		] );
		$this->ok( false !== strpos( $general, 'Repeating it without a change will not resolve the conflict' ), 'general conflict tells the Manager to resolve it before updating' );
		$this->ok( false === strpos( $general, 'its time and Package assignments are protected' ), 'general conflict does not claim a protected-time cause' );
		$this->ok( false !== strpos( $general, 'Bingo Sessions were not fully updated.' ) && false === strpos( $general, 'reported below' ), 'failed update without counts makes no promise of reported results' );

		$session_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule_id ) );
		$this->ok( $session_id > 0, 'protected-time fixture has a generated Bingo Session' );
		$on = ( new \BingoCommerce\Sessions\SessionService() )->setAvailableForSale( $session_id, true );
		$this->ok( ! is_wp_error( $on ), 'generated Bingo Session was offered for sale' );
		$edited = $service->edit( $schedule_id, ScheduleExpansion::MODE_DATES, '15:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$this->same( SessionScheduleResult::STATUS_OK, $edited instanceof SessionScheduleResult ? $edited->status : '', 'schedule saves a different time before session update' );
		$before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %d', Migrations::sessions_table(), $session_id ), ARRAY_A );
		$protected_args = $this->query_args_of( $this->run_generate( $schedule_id, true ) );
		$this->same( 'protected_time_conflict', $protected_args['bc_code'] ?? '', 'only a confirmed protected-time blocker is named in the redirect' );
		$this->same( (string) $schedule_id, $protected_args['edit'] ?? '', 'a failed Update retains the selected schedule context' );
		$protected_notice = $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $protected_args );
		$this->ok( false !== strpos( $protected_notice, 'It did not move' ) && false !== strpos( $protected_notice, 'create a separate schedule at the new time' ), 'protected-time notice explains why and what to change' );
		$this->same( $before, $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %d', Migrations::sessions_table(), $session_id ), ARRAY_A ), 'protected-time update leaves the Bingo Session unchanged' );
		$wpdb->update( Migrations::sessions_table(), [ 'generation_completed_at' => null ], [ 'session_id' => $session_id ] );
		$other_conflict = $this->query_args_of( $this->run_generate( $schedule_id, true ) );
		$this->same( 'preflight_conflict', $other_conflict['bc_code'] ?? '', 'other confirmed preflight blocker remains a general conflict' );
		$this->ok( false === strpos( $this->render_screen( [ SessionScheduleAdmin::class, 'render' ], $other_conflict ), 'It did not move' ), 'non-time conflict is not mislabeled protected-time' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * §16 M106 — handler order, proven by violating several gates at once
	 * ================================================================== */

	private function handler_order_group(): void {
		$this->group( 'M106 handler order' );

		/*
		 * Each case violates EVERY remaining gate as well as the one under
		 * test, so the gate that fires is the one that comes FIRST. A test that
		 * violated one gate at a time would pass against any order at all.
		 *
		 * BOTH admin-post handlers are driven through the same four cases.
		 * Generation and saving enforce the order independently, and testing
		 * only one of them would leave the other free to drift.
		 */
		foreach (
			[
				'generate' => [ $this, 'catch_generate' ],
				'save'     => [ $this, 'catch_save' ],
				'timezone' => [ $this, 'run_timezone' ],
			] as $handler => $driver
		) {
			// 1. GET, on the main site, signed out, no nonce -> the METHOD gate.
			switch_to_blog( 1 );
			wp_set_current_user( 0 );
			$died = call_user_func( $driver, 'GET', [] );
			$this->same( 405, $died instanceof AdminDied ? $died->status : 0, "M106 {$handler} (1): GET is refused 405 FIRST, before tenancy, capability or nonce" );
			$this->ok( $died instanceof AdminDied && false !== strpos( $died->getMessage(), 'POST request' ), '  naming the method' );
			restore_current_blog();

			// 2. POST, still on the main site, signed out, no nonce -> TENANCY.
			switch_to_blog( 1 );
			wp_set_current_user( 0 );
			$died = call_user_func( $driver, 'POST', [] );
			$this->same( 403, $died instanceof AdminDied ? $died->status : 0, "M106 {$handler} (2): a non-hall POST is refused 403 next" );
			$this->ok( $died instanceof AdminDied && false !== strpos( $died->getMessage(), 'hall site' ), '  naming tenancy, NOT capability — tenancy is proved before authority' );
			restore_current_blog();

			// 3. POST, in the hall, WITHOUT the capability, no nonce -> CAPABILITY.
			switch_to_blog( $this->hall );
			wp_set_current_user( $this->no_cap_user_id );
			$died = call_user_func( $driver, 'POST', [] );
			$this->same( 403, $died instanceof AdminDied ? $died->status : 0, "M106 {$handler} (3): a hall POST without bc_manage_sessions is refused 403" );
			$this->ok( $died instanceof AdminDied && false !== strpos( $died->getMessage(), 'not authorized' ), '  naming authorization' );
			restore_current_blog();

			// 4. POST, in the hall, authorized, BAD nonce -> the NONCE gate, last.
			switch_to_blog( $this->hall );
			wp_set_current_user( $this->super_admin_id );
			$died = call_user_func( $driver, 'POST', [ '_wpnonce' => 'not-a-real-nonce' ] );
			$this->ok( $died instanceof AdminDied, "M106 {$handler} (4): a bad nonce still dies" );
			$this->ok(
				$died instanceof AdminDied
					&& false === strpos( $died->getMessage(), 'POST request' )
					&& false === strpos( $died->getMessage(), 'hall site' )
					&& false === strpos( $died->getMessage(), 'not authorized' ),
				'  and it is the NONCE that refused it, having passed method, tenancy and capability first'
			);
			restore_current_blog();
		}

		/*
		 * A PASSING request redirects with 303 — not 302. 303 is what makes the
		 * follow-up unambiguously a GET for every client, which is the property
		 * the whole PRG arrangement rests on.
		 */
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );
		$status_template = $this->plant_template( 'Status Bingo', 'active', 1 );
		$status_package  = $this->make_package( 'Status Bingo' );

		$this->last_redirect_status = 0;
		$this->save(
			[
				'bc_action'         => 'create',
				'portal_session_id' => (string) $status_template,
				'mode'              => ScheduleExpansion::MODE_DATES,
				'start_time'        => '18:00',
				'future_dates'      => $this->local_date( '+2 days' ),
				'package_ids'       => [ (string) $status_package ],
			]
		);
		$this->same( 303, $this->last_redirect_status, 'M106 save: the redirect status is 303' );

		$status_schedule = (int) ( ( $this->schedule_ids( new SessionScheduleService() ) )[0] ?? 0 );
		$this->last_redirect_status = 0;
		$this->run_generate( $status_schedule, true );
		$this->same( 303, $this->last_redirect_status, 'M106 generate: the redirect status is 303 too' );
		restore_current_blog();

		/* The same order is enforced by the rendered screens, minus the nonce
		 * step they have no need for. */
		switch_to_blog( 1 );
		wp_set_current_user( $this->super_admin_id );
		foreach (
			[
				'SessionScheduleAdmin' => [ SessionScheduleAdmin::class, 'render' ],
				'SessionTemplateAdmin' => [ SessionTemplateAdmin::class, 'render' ],
			] as $label => $callable
		) {
			$died = $this->catch_render( $callable );
			$this->same( 403, $died instanceof AdminDied ? $died->status : 0, "{$label}: refuses to render outside a hall, 403" );
		}
		restore_current_blog();

		switch_to_blog( $this->hall );
		wp_set_current_user( $this->no_cap_user_id );
		foreach (
			[
				'SessionScheduleAdmin' => [ SessionScheduleAdmin::class, 'render' ],
				'SessionTemplateAdmin' => [ SessionTemplateAdmin::class, 'render' ],
			] as $label => $callable
		) {
			$died = $this->catch_render( $callable );
			$this->same( 403, $died instanceof AdminDied ? $died->status : 0, "{$label}: refuses to render without the capability, 403" );
		}
		restore_current_blog();
		wp_set_current_user( $this->super_admin_id );
	}

	/* ================================================================== *
	 * §16 M105 — hall isolation
	 * ================================================================== */

	private function hall_isolation_group(): void {
		$this->group( 'M105 hall isolation' );

		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );
		$template = $this->plant_template( 'Isolation Bingo', 'active', 1 );
		$package  = $this->make_package( 'Isolation Bingo' );
		$created  = ( new SessionScheduleService() )->create( $template, ScheduleExpansion::MODE_DATES, '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$hall_a_schedule = $created instanceof SessionScheduleResult ? (int) $created->schedule['schedule_id'] : 0;
		$this->ok( $hall_a_schedule > 0, 'isolation fixture: hall A owns a schedule' );
		$before = ( new SessionScheduleService() )->get( $hall_a_schedule );
		restore_current_blog();

		/* Hall B, same authorized user, handed hall A's id. */
		switch_to_blog( $this->other_hall );
		wp_set_current_user( $this->super_admin_id );
		$foreign = new SessionScheduleService();

		$this->same( 'schedule_not_found', $this->code( $foreign->get( $hall_a_schedule ) ), 'M105: hall B cannot READ hall A\'s schedule' );
		$this->same( 'schedule_not_found', $this->code( $foreign->edit( $hall_a_schedule, ScheduleExpansion::MODE_DATES, '20:00', [ $this->local_date( '+5 days' ) ], [ $package ] ) ), '  nor EDIT it' );
		$this->same( 'schedule_not_found', $this->code( $foreign->archive( $hall_a_schedule ) ), '  nor ARCHIVE it' );
		$this->same( 'schedule_not_found', $this->code( $foreign->restore( $hall_a_schedule ) ), '  nor RESTORE it' );
		$this->same( 'schedule_not_found', $this->code( $foreign->generate( $hall_a_schedule ) ), '  nor GENERATE from it' );
		$this->same( 'schedule_not_found', $this->code( $foreign->definition_packages( $hall_a_schedule, 'any-token' ) ), '  nor enumerate its Packages' );
		$this->same( 'schedule_not_found', $this->code( $foreign->definition_dates( $hall_a_schedule, 'any-token' ) ), '  nor its dates' );
		$this->same( [], $foreign->list_for_administration( 'all' ), '  and hall B\'s own list is empty, not hall A\'s' );

		/* The Generate HANDLER is isolated too, not just the service. */
		$location = $this->run_generate( $hall_a_schedule, true );
		$this->ok( false !== strpos( $location, 'bc_generated=error' ), 'M105: the Generate handler in hall B refuses hall A\'s schedule' );
		$this->ok( false !== strpos( $location, 'bc_code=schedule_not_found' ), '  naming it not found in THIS hall' );
		restore_current_blog();

		/* Hall A is byte-identical afterwards. */
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );
		$this->same( $before, ( new SessionScheduleService() )->get( $hall_a_schedule ), 'M105: hall A\'s schedule is byte-identical after every cross-hall attempt' );
		$this->same( 0, $this->occurrence_count( $hall_a_schedule ), '  and no occurrence was generated for it' );
		restore_current_blog();
	}

	/* ================================================================== *
	 * §13.1 request integers — request_int(), never absint()
	 * ================================================================== */

	private function boundary_group(): void {
		$this->group( 'M105 request integers' );

		/*
		 * `absint( '-5' )` is 5 — a DIFFERENT, valid-looking id that a lookup
		 * might actually match. request_int() returns -5, which matches nothing.
		 */
		$this->same( -5, SessionAdmin::request_int( '-5' ), 'request_int(\'-5\') is -5, NOT 5 — absint() would have flipped it into a real id' );
		$this->same( 7, SessionAdmin::request_int( ' 7 ' ), 'surrounding whitespace is tolerated' );
		$this->same( 0, SessionAdmin::request_int( 'abc' ), 'a non-numeric value is 0, which matches nothing' );
		$this->same( 0, SessionAdmin::request_int( '9 OR 1=1' ), 'a numeric prefix does NOT leak through' );
		$this->same( 0, SessionAdmin::request_int( null ), 'a missing value is 0' );
		$this->same( 0, SessionAdmin::request_int( [ 5 ] ), 'an array is 0, never coerced' );

		/* Both new screens use it, and neither reaches for absint(). */
		foreach ( [ 'SessionScheduleAdmin.php', 'SessionTemplateAdmin.php' ] as $file ) {
			$code = $this->code_only( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/' . $file );
			$this->same( 0, substr_count( $code, 'absint(' ), "{$file} never calls absint() in executable code" );
			$this->ok( false !== strpos( $code, 'request_int(' ), "  and does read request integers through request_int()" );
		}
	}

	/* ================================================================== *
	 * §6 — Manage Session Dates is absent from normal navigation, but
	 * authorized recovery links still work.
	 * ================================================================== */

	private function hidden_navigation_group(): void {
		$this->group( 'Hidden navigation' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		global $submenu;
		$submenu = [];

		SessionAdmin::add_menu();
		SessionScheduleAdmin::add_menu();
		SessionTemplateAdmin::add_menu();

		$rows        = $submenu[ SessionAdmin::PAGE_SLUG ] ?? [];
		$slugs       = array_column( $rows, 2 );
		$menu_titles = array_column( $rows, 0 );

		$this->ok(
			! in_array( SessionScheduleAdmin::PAGE_SLUG, $slugs, true ),
			'Manage Session Dates is NOT in the Bingo Sessions submenu'
		);
		$this->ok(
			false === array_reduce( $menu_titles, static fn( $found, $t ) => $found || false !== strpos( (string) $t, 'Manage Session Dates' ), false ),
			'  and no menu row is titled Manage Session Dates either'
		);
		$this->ok(
			in_array( SessionTemplateAdmin::PAGE_SLUG, $slugs, true ),
			'Sessions from Portal, by contrast, IS in the normal submenu — only the schedules page is hidden'
		);

		/*
		 * HIDDEN FROM THE MENU IS NOT THE SAME AS DE-AUTHORIZED — but calling
		 * render() directly, as this block does, only proves render() itself
		 * has no gate of its own to bypass. It does NOT prove a real browser
		 * request succeeds: WordPress's own admin.php?page=X gate,
		 * user_can_access_admin_page(), runs BEFORE render() is ever called,
		 * and (as direct_url_access_group() below covers, added after a real
		 * "Sorry, you are not allowed to access this page." was found in
		 * manual testing) it re-resolves this page's parent by searching
		 * $submenu at request time — so removing the entry unconditionally
		 * broke that gate for every visitor, including an authorized Hall
		 * Manager. This block still confirms the menu ABSENCE; reachability
		 * through WordPress's own gate is what direct_url_access_group()
		 * proves.
		 */
		$template = $this->plant_template( 'Hidden Nav Bingo', 'active', 1 );
		$recovery = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			[ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => (string) $template ]
		);
		$this->ok( false !== strpos( $recovery, 'Create Sessions' ), 'the hidden page still renders fully when reached directly' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * Direct URL access through WordPress's REAL admin.php?page=X gate.
	 *
	 * Manual testing found "Sorry, you are not allowed to access this page."
	 * on Add to Store, which linked to admin.php?page=bingocommerce-session-
	 * schedules. The cause: wp-admin/includes/menu.php calls
	 * user_can_access_admin_page() AFTER do_action('admin_menu') has already
	 * run, and that function's get_admin_page_parent() resolves a submenu
	 * page's parent SOLELY by searching $submenu for the entry at request
	 * time — there is no separate, fixed table of "capability required for
	 * page X" consulted instead. Removing the entry unconditionally, in the
	 * same admin_menu callback that added it, meant the one search that
	 * mattered always failed, for every visitor. add_menu()'s fix keeps the
	 * entry present for exactly the request whose own ?page= is this page,
	 * which is the only case that needs it — this exercises WordPress's own
	 * gate function directly, not render() (which has no gate to bypass and
	 * so never caught this).
	 * ================================================================== */

	private function direct_url_access_group(): void {
		global $menu, $submenu, $pagenow, $plugin_page,
			$_wp_menu_nopriv, $_wp_submenu_nopriv, $_registered_pages,
			$_parent_pages, $_wp_real_parent_file;

		$this->group( 'Direct URL access' );
		switch_to_blog( $this->hall );
		wp_set_current_user( $this->super_admin_id );

		$template = $this->plant_template( 'Direct Url Bingo', 'active', 1 );
		$pagenow  = 'admin.php';

		$reset = static function () use ( &$menu, &$submenu, &$_wp_menu_nopriv, &$_wp_submenu_nopriv, &$_registered_pages, &$_parent_pages, &$_wp_real_parent_file ): void {
			$menu                  = [];
			$submenu               = [];
			$_wp_menu_nopriv       = [];
			$_wp_submenu_nopriv    = [];
			$_registered_pages     = [];
			$_parent_pages         = [];
			$_wp_real_parent_file  = [];
		};

		foreach (
			[
				'Add to Store (create)'  => [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => (string) $template ],
				'Manage Dates (edit)'    => [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'edit' => '1' ],
				'Retry redirect landing' => [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'bc_generated' => 'ok' ],
			] as $label => $get
		) {
			$_GET        = $get;
			$plugin_page = $get['page'];
			$reset();

			SessionAdmin::add_menu();
			SessionScheduleAdmin::add_menu();
			SessionTemplateAdmin::add_menu();

			$this->ok(
				user_can_access_admin_page(),
				"an authorized Hall Manager reaches {$label} through WordPress's real admin.php?page=" . SessionScheduleAdmin::PAGE_SLUG . ' gate'
			);
		}

		/* The fix does not weaken the capability gate itself. */
		$_GET        = [ 'page' => SessionScheduleAdmin::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => (string) $template ];
		$plugin_page = SessionScheduleAdmin::PAGE_SLUG;
		$reset();
		wp_set_current_user( $this->no_cap_user_id );
		SessionAdmin::add_menu();
		SessionScheduleAdmin::add_menu();
		SessionTemplateAdmin::add_menu();
		$this->ok( ! user_can_access_admin_page(), 'a user WITHOUT bc_manage_sessions is still refused the same page' );
		wp_set_current_user( $this->super_admin_id );

		/* Still hidden from the rendered menu — and still reachable itself — on a DIFFERENT page. */
		$_GET        = [ 'page' => SessionAdmin::PAGE_SLUG ];
		$plugin_page = SessionAdmin::PAGE_SLUG;
		$reset();
		SessionAdmin::add_menu();
		SessionScheduleAdmin::add_menu();
		SessionTemplateAdmin::add_menu();
		$rows  = $submenu[ SessionAdmin::PAGE_SLUG ] ?? [];
		$slugs = array_column( $rows, 2 );
		$this->ok(
			! in_array( SessionScheduleAdmin::PAGE_SLUG, $slugs, true ),
			'Manage Session Dates is still absent from the rendered menu while visiting a different page'
		);
		$this->ok( user_can_access_admin_page(), '  and that different page remains reachable too' );

		$_GET = [];
		unset( $plugin_page );
		restore_current_blog();
	}

	/* ================================================================== *
	 * §7 — Create orchestration: creation-then-generation, exactly once,
	 * and never generation without a successful creation first.
	 * ================================================================== */

	private function orchestration_group(): void {
		global $wpdb;

		$this->group( 'Create orchestration' );
		/*
		 * Its own disposable hall, not $this->hall: by this point in the
		 * suite that hall carries many leftover schedules from earlier
		 * groups (CAS races, handler-order, boundary cases), and this
		 * group's assertions below rely on EXACT statement and row counts
		 * — e.g. an exact action count — which only
		 * a clean hall can guarantee.
		 */
		$orch_hall = $this->create_disposable_site();
		switch_to_blog( $orch_hall );
		update_option( 'timezone_string', self::ZONE );
		wp_set_current_user( $this->super_admin_id );

		$service = new SessionScheduleService();

		/*
		 * (6) EXACTLY ONE CREATE, EXACTLY ONE GENERATE. The create CAS
		 * (`SET s.mode`) and the occurrence INSERT
		 * (`OccurrenceGenerationWriter::create_occurrence()`'s guarded INSERT)
		 * are each a DISTINCT, recognisable statement shape; counting real
		 * executions of each — not stubbing or wrapping the service — is what
		 * proves "called once" without assuming it from the visible result
		 * alone, and without modifying SessionScheduleService for testability.
		 */
		$template = $this->plant_template( 'Once Bingo', 'active', 1 );
		$package  = $this->make_package( 'Once Bingo' );

		$this->count_matches( 'create-cas', '/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/' );
		$this->count_matches( 'occurrence-insert', '/INSERT INTO \S+bingo_sessions\b/' );

		$once_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $template,
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00',
					'future_dates'      => [ $this->us_date( '+2 days' ) ],
					'package_ids'       => [ (string) $package ],
				]
			)
		);

		$this->same( 1, $this->stop_counting( 'create-cas' ), 'the create CAS statement runs EXACTLY once' );
		$this->same( 1, $this->stop_counting( 'occurrence-insert' ), 'the occurrence INSERT statement runs EXACTLY once — one create, one generate, no more' );
		$this->same( 'created', $once_args['bc_saved'] ?? '', '  and the whole thing succeeds' );

		/*
		 * (7) A FAILED CREATE NEVER REACHES GENERATION. `create()` fails at
		 * request-time validation (a malformed start time), before any row
		 * is written, so the occurrence-insert pattern must never fire at all
		 * — not once, not as a no-op — because create_and_generate() is only
		 * ever called from the STATUS_OK branch.
		 */
		$fail_template = $this->plant_template( 'Never Bingo', 'active', 1 );
		$fail_package  = $this->make_package( 'Never Bingo' );

		$this->count_matches( 'occurrence-insert-2', '/INSERT INTO \S+bingo_sessions\b/' );

		$fail_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $fail_template,
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00:30',
					'future_dates'      => [ $this->us_date( '+2 days' ) ],
					'package_ids'       => [ (string) $fail_package ],
				]
			)
		);

		$this->same( 'error', $fail_args['bc_saved'] ?? '', 'the create is refused' );
		$this->same( 0, $this->stop_counting( 'occurrence-insert-2' ), 'generation NEVER runs for a failed create — zero occurrence-insert statements' );
		$this->same(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Migrations::schedules_table() . ' WHERE portal_session_id = %d',
					$fail_template
				)
			),
			'  and no schedule header of any kind — inert or otherwise — was written'
		);

		/*
		 * (10) GENERATED BINGO SESSIONS REMAIN OFF. The schedule from the
		 * first case above fully succeeded ("created", not "created_partial"),
		 * so its occurrence was both created AND generated — the exact state
		 * a Manager sees immediately after clicking Create Sessions.
		 */
		$once_id  = (int) ( $once_args['bc_schedule'] ?? 0 );
		$once_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT available_for_sale, generation_completed_at FROM ' . Migrations::sessions_table() . ' WHERE schedule_id = %d',
				$once_id
			),
			ARRAY_A
		);
		$this->ok( is_array( $once_row ), 'the newly created-and-generated occurrence exists' );
		$this->same( 0, is_array( $once_row ) ? (int) $once_row['available_for_sale'] : -1, '  and is OFF — Create Sessions never turns anything on' );
		$this->ok( is_array( $once_row ) && null !== $once_row['generation_completed_at'], '  while being fully, completely generated' );

		/*
		 * (8) A GENERATION FAILURE PRESERVES THE SCHEDULE AND OFFERS A SAFE
		 * RETRY. The barrier here targets the OCCURRENCE INSERT, not the
		 * create CAS: invalidating the template at the CAS itself makes
		 * create() FAIL (proven above, and already covered by the existing
		 * "CAS race" case), because that statement's own WHERE EXISTS gates
		 * on the template. Invalidating one step later — between a
		 * successfully committed create() and the occurrence INSERT
		 * generate() issues — lets create() succeed while the chained
		 * generate() finds nothing eligible to write, which is the actual
		 * "schedule saved, sessions not fully created" case this proves.
		 */
		$partial_template = $this->plant_template( 'Preserved Bingo', 'active', 1 );
		$partial_package  = $this->make_package( 'Preserved Bingo' );

		$fired = false;
		$this->barrier(
			'/INSERT INTO \S+bingo_sessions\b/',
			function () use ( $wpdb, $partial_template, &$fired ) {
				$fired = true;
				$wpdb->update(
					Migrations::portal_session_templates_table(),
					[ 'is_current' => 0 ],
					[ 'portal_session_id' => $partial_template ]
				);
			}
		);
		$partial_args = $this->query_args_of(
			$this->save(
				[
					'bc_action'         => 'create',
					'portal_session_id' => (string) $partial_template,
					'mode'              => ScheduleExpansion::MODE_DATES,
					'start_time'        => '18:00',
					'future_dates'      => [ $this->us_date( '+2 days' ) ],
					'package_ids'       => [ (string) $partial_package ],
				]
			)
		);
		$this->clear_barrier();

		$this->ok( $fired, 'preserve-on-failure: the barrier fired between create and the automatic generate' );
		$this->same( 'created_partial', $partial_args['bc_saved'] ?? '', '  create succeeded; the chained generate did not' );

		$partial_id = (int) ( $partial_args['bc_schedule'] ?? 0 );
		$this->ok( $partial_id > 0, '  a real schedule id was returned' );
		$preserved = $service->get( $partial_id );
		$this->ok( is_array( $preserved ), '  and the SCHEDULE ITSELF STILL EXISTS — a generation failure never rolls it back' );

		$partial_notice = $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			$partial_args
		);
		$this->ok( false !== strpos( $partial_notice, 'use Update Sessions for this schedule to finish creating Bingo Sessions' ), 'a readable partial-generation page directs the Manager to the selected schedule action' );
		preg_match( '/<div class="notice notice-warning">(.*?)<\/div>/s', $partial_notice, $partial_warning );
		$this->ok( false === strpos( $partial_warning[1] ?? '', '<form' ), 'the partial-generation notice does not introduce a retry form' );
		// The result leads to the selected editor, whose read-verified action
		// replaces the notice's independent action and the old table below it.
		$this->ok(
			1 === preg_match_all( '/class="button">\s*Update Sessions/', $partial_notice ),
			'  and the selected editor offers exactly one Update Sessions action'
		);
		$this->same( (string) $partial_id, $partial_args['edit'] ?? '', 'partial generation retains its selected schedule context' );
		$this->ok( false !== strpos( $partial_notice, (string) $partial_id ), '  targeting the preserved schedule\'s own id' );

		/* Retry uses the SAME idempotent generation path — no second implementation. */
		$wpdb->update(
			Migrations::portal_session_templates_table(),
			[ 'is_current' => 1 ],
			[ 'portal_session_id' => $partial_template ]
		);
		$retried = $this->run_generate( $partial_id, true );
		$this->ok( '' !== $retried, 'retry succeeds once eligibility is restored, through the ordinary generate handler' );
		$retried_args = $this->query_args_of( $retried );
		$this->same( SessionScheduleResult::STATUS_OK, $retried_args['bc_generated'] ?? '', '  reporting status ok' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * Screen drivers
	 * ================================================================== */

	/** @param callable $callable @param array<string,string> $get */
	private function render_screen( $callable, array $get ): string {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = $get;
		$_POST                     = [];
		$_REQUEST                  = $get;

		ob_start();
		try {
			call_user_func( $callable );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * POST a mutation to the schedules screen with a VALID nonce, and return
	 * the rendered result.
	 *
	 * @param array<string,mixed> $post
	 */
	private function post_to_screen( array $post ): string {
		return $this->render_screen(
			[ SessionScheduleAdmin::class, 'render' ],
			$this->query_args_of( $this->save( $post ) )
		);
	}

	/**
	 * POST a mutation to the real admin-post handler and return the LOCATION it
	 * redirected to. Nothing is rendered: the handler never renders.
	 *
	 * @param array<string,mixed> $post
	 */
	private function save( array $post ): string {
		$post['action']   = SessionScheduleAdmin::POST_ACTION_SAVE;
		$post['_wpnonce'] = wp_create_nonce( self::NONCE_MUTATION );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = [];
		$_POST                     = $post;
		$_REQUEST                  = $post;

		$location = '';
		try {
			ob_start();
			SessionScheduleAdmin::handle_save();
			ob_end_clean();
		} catch ( AdminRedirected $redirect ) {
			ob_end_clean();
			$location = $redirect->getMessage();
		} catch ( AdminDied $died ) {
			ob_end_clean();
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_REQUEST                  = [];

		return $location;
	}

	/**
	 * The query arguments of a redirect location, as the browser's follow-up
	 * GET would carry them.
	 *
	 * @return array<string,string>
	 */
	private function query_args_of( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		$args  = [];
		parse_str( $query, $args );

		return array_map( 'strval', array_filter( $args, 'is_scalar' ) );
	}

	/** Run the generate handler and return the redirect location it chose. */
	private function run_generate( int $schedule_id, bool $valid_nonce ): string {
		$post = [ 'schedule_id' => (string) $schedule_id ];
		if ( $valid_nonce ) {
			$post['_wpnonce'] = wp_create_nonce( self::NONCE_GENERATE );
		}

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $post;
		$_REQUEST                  = $post;
		$_GET                      = [];

		$location = '';
		try {
			SessionScheduleAdmin::handle_generate();
		} catch ( AdminRedirected $redirect ) {
			$location = $redirect->getMessage();
		} catch ( AdminDied $died ) {
			$location = '';
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_REQUEST                  = [];

		return $location;
	}

	/**
	 * Call the generate handler expecting it to DIE, and return the refusal.
	 *
	 * @param array<string,mixed> $post
	 * @return AdminDied|null
	 */
	private function catch_generate( string $method, array $post ): ?AdminDied {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $post;
		$_REQUEST                  = $post;
		$_GET                      = [];

		$caught = null;
		try {
			ob_start();
			SessionScheduleAdmin::handle_generate();
			ob_end_clean();
		} catch ( AdminDied $died ) {
			ob_end_clean();
			$caught = $died;
		} catch ( AdminRedirected $redirect ) {
			ob_end_clean();
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_REQUEST                  = [];

		return $caught;
	}

	/**
	 * Call the SAVE handler expecting it to DIE, and return the refusal.
	 *
	 * @param array<string,mixed> $post
	 * @internal Public visibility is not needed; called through a callable.
	 */
	private function catch_save( string $method, array $post ): ?AdminDied {
		$post['action'] = SessionScheduleAdmin::POST_ACTION_SAVE;

		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $post;
		$_REQUEST                  = $post;
		$_GET                      = [];

		$caught = null;
		try {
			ob_start();
			SessionScheduleAdmin::handle_save();
			ob_end_clean();
		} catch ( AdminDied $died ) {
			ob_end_clean();
			$caught = $died;
		} catch ( AdminRedirected $redirect ) {
			ob_end_clean();
		}

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = [];
		$_REQUEST                  = [];

		return $caught;
	}

	/** Run the real authenticated timezone hook, observing redirects/refusals. */
	private function run_timezone( string $method, array $post ): ?\RuntimeException {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET = [];
		$_POST = $_REQUEST = $post;
		ob_start();
		try {
			do_action( 'admin_post_' . SessionScheduleAdmin::POST_ACTION_TIMEZONE );
		} catch ( AdminDied | AdminRedirected $result ) {
			return $result;
		} finally {
			ob_end_clean();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST = $_REQUEST = [];
		}
		return null;
	}

	/** @param callable $callable @return AdminDied|null */
	private function catch_render( $callable ): ?AdminDied {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = [];
		$_POST                     = [];
		$_REQUEST                  = [];

		$caught = null;
		try {
			ob_start();
			call_user_func( $callable );
			ob_end_clean();
		} catch ( AdminDied $died ) {
			ob_end_clean();
			$caught = $died;
		}

		return $caught;
	}

	/** @internal Public because WordPress needs a callable. */
	public function die_handler(): callable {
		return static function ( $message, $title = '', $args = [] ): void {
			$status = is_array( $args ) ? (int) ( $args['response'] ?? 0 ) : 0;
			throw new AdminDied( is_scalar( $message ) ? (string) $message : '', $status );
		};
	}

	/** @internal Public because WordPress needs a callable. */
	public function redirect_handler( $location, $status = 0 ) {
		$this->last_redirect_status = (int) $status;

		throw new AdminRedirected( (string) $location );
	}

	/* ================================================================== *
	 * Targeted query interception
	 *
	 * Fault injection is a REWRITE OF ONE STATEMENT, never a schema change.
	 * An earlier version renamed a real table away for the duration, which
	 * breaks every reader of that table at once — including WordPress's own —
	 * and leaves the fixture genuinely damaged if the run dies between the two
	 * RENAMEs. This sends only the statement whose pattern matches to a table
	 * guaranteed not to exist, and asserts that it actually fired, so a pattern
	 * that silently stopped matching cannot leave the scenario passing
	 * vacuously against a perfectly healthy read.
	 * ================================================================== */

	private const NOWHERE_TABLE = 'bc_sadm1_no_such_table_ever';

	private string $intercept_pattern = '';
	private int $intercept_hits       = 0;

	private string $barrier_pattern = '';
	/** @var callable|null */
	private $barrier_callback = null;
	private bool $barrier_running = false;

	/**
	 * Run a callback immediately BEFORE the first statement matching $pattern.
	 *
	 * This is how a race is made deterministic rather than timed: the callback
	 * runs inside the window between the service's validation and the statement
	 * that is guarded against exactly that window changing underneath it.
	 */
	private function barrier( string $pattern, callable $callback ): void {
		$this->barrier_pattern  = $pattern;
		$this->barrier_callback = $callback;
		$this->barrier_running  = false;
	}

	private function clear_barrier(): void {
		$this->barrier_pattern  = '';
		$this->barrier_callback = null;
		$this->barrier_running  = false;
	}

	/** @return array<int,int> Every schedule id in this hall, ascending. */
	private function schedule_ids( SessionScheduleService $service ): array {
		$rows = $service->list_for_administration( 'all' );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$ids = array_map( static fn( $row ) => (int) ( $row['schedule_id'] ?? 0 ), $rows );
		sort( $ids );

		return $ids;
	}

	private function intercept( string $pattern ): void {
		$this->intercept_pattern = $pattern;
		$this->intercept_hits    = 0;
	}

	/** @return int How many statements were diverted. */
	private function stop_intercepting(): int {
		$hits                    = $this->intercept_hits;
		$this->intercept_pattern = '';
		$this->intercept_hits    = 0;

		return $hits;
	}

	/**
	 * Named, non-diverting query counters, run alongside $intercept_pattern.
	 *
	 * Proving "the create CAS fires exactly once" or "no generation statement
	 * fires at all" needs a COUNT, not a fault injection — the statement must
	 * still run normally. Several counters can be active at once, each keyed
	 * by a name the caller chooses.
	 *
	 * @var array<string,array{pattern:string,hits:int}>
	 */
	private array $query_counters = [];

	private function count_matches( string $name, string $pattern ): void {
		$this->query_counters[ $name ] = [ 'pattern' => $pattern, 'hits' => 0 ];
	}

	private function stop_counting( string $name ): int {
		$hits = $this->query_counters[ $name ]['hits'] ?? 0;
		unset( $this->query_counters[ $name ] );

		return $hits;
	}

	/** @internal Public because WordPress needs a callable. */
	public function observe_query( string $sql ): string {
		// The barrier fires FIRST and only once, before the matched statement
		// runs. `barrier_running` stops the callback's own queries from
		// re-entering it.
		if ( '' !== $this->barrier_pattern
			&& ! $this->barrier_running
			&& null !== $this->barrier_callback
			&& 1 === preg_match( $this->barrier_pattern, $sql )
		) {
			$this->barrier_running = true;
			try {
				call_user_func( $this->barrier_callback );
			} finally {
				$this->barrier_pattern  = '';
				$this->barrier_callback = null;
				$this->barrier_running  = false;
			}
		}

		foreach ( $this->query_counters as $name => $counter ) {
			if ( 1 === preg_match( $counter['pattern'], $sql ) ) {
				++$this->query_counters[ $name ]['hits'];
			}
		}

		if ( '' === $this->intercept_pattern || 1 !== preg_match( $this->intercept_pattern, $sql ) ) {
			return $sql;
		}

		++$this->intercept_hits;

		return 'SELECT 1 FROM ' . self::NOWHERE_TABLE;
	}

	/* ================================================================== *
	 * Fixtures
	 * ================================================================== */

	private function plant_template( string $name, string $status, int $is_current ): int {
		global $wpdb;

		static $next = 0;
		++$next;
		$portal_session_id = 980000 + $next;
		$now               = current_time( 'mysql', true );

		$wpdb->insert(
			Migrations::portal_session_templates_table(),
			[
				'portal_session_id' => $portal_session_id,
				'name'              => self::PREFIX . ' ' . $name,
				'portal_status'     => $status,
				'is_current'        => $is_current,
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		return $portal_session_id;
	}

	private function make_package( string $label ): int {
		$play = ( new BingoPlayProductService() )->create( $this->make_product( $label . ' pp' ), 'paper', 2, null );
		if ( is_wp_error( $play ) ) {
			$this->ok( false, "fixture Play Product failed for {$label}: " . $play->get_error_code() );

			return 0;
		}

		$package = ( new PackageService() )->create(
			$this->make_product( 'pkg ' . $label ),
			[ [ 'bingo_play_product_id' => (int) $play->play_product['bingo_play_product_id'], 'quantity' => 1 ] ]
		);
		if ( is_wp_error( $package ) ) {
			$this->ok( false, "fixture Package failed for {$label}: " . $package->get_error_code() );

			return 0;
		}

		return (int) $package->package['package_id'];
	}

	private function make_product( string $label ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( self::PREFIX . ' ' . $label . ' ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}

	private function local_date( string $modifier ): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( self::ZONE ) ) )->modify( $modifier )->format( 'Y-m-d' );
	}

	private function occurrence_count( int $schedule_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() . ' WHERE schedule_id = %d', $schedule_id )
		);
	}

	/** @param mixed $result */
	private function code( $result ): string {
		return is_wp_error( $result ) ? (string) $result->get_error_code() : 'NOT_AN_ERROR';
	}

	/** Executable code only, so a docblock can never satisfy a string search. */
	private function code_only( string $file ): string {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	private function create_user_without_capability(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'sadm1_nocap_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'sadm1-nocap-' . wp_generate_password( 6, false ) . '@example.invalid',
				'role'       => '',
			]
		);
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		$this->created_users[] = (int) $user_id;

		return (int) $user_id;
	}

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'sadm1' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'SADM1 Disposable Hall',
				'user_id'    => $this->super_admin_id,
			]
		);
		if ( is_wp_error( $site ) ) {
			$this->ok( false, 'disposable hall could not be created: ' . $site->get_error_message() );

			return 0;
		}

		$this->disposable_sites[ (int) $site ] = true;

		if ( class_exists( 'WC_Install' ) ) {
			switch_to_blog( (int) $site );
			\WC_Install::create_tables();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) {
				if ( class_exists( $schema ) ) {
					( new $schema() )->register_tables( true );
				}
			}
			restore_current_blog();
		}

		return (int) $site;
	}

	private function cleanup(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$site_id = (int) $site_id;
			// Never delete a site this run did not create.
			if ( in_array( $site_id, self::PERSISTENT, true ) || 1 === $site_id ) {
				$this->ok( false, "refusing to delete non-disposable site {$site_id}" );
				continue;
			}
			if ( get_site( $site_id ) instanceof \WP_Site ) {
				wp_delete_site( $site_id );
			}
		}
		$this->disposable_sites = [];

		foreach ( $this->created_users as $user_id ) {
			if ( $user_id > 0 && get_userdata( $user_id ) instanceof \WP_User ) {
				wpmu_delete_user( $user_id );
			}
		}
		$this->created_users = [];
	}

	/** @return array<string,array<string,mixed>> */
	private function persistent_snapshot(): array {
		global $wpdb;

		$snapshot = [];
		foreach ( self::PERSISTENT as $site_id ) {
			if ( ! get_site( $site_id ) instanceof \WP_Site ) {
				continue;
			}
			switch_to_blog( $site_id );

			$row = [];
			foreach (
				[
					'sessions'  => Migrations::sessions_table(),
					'schedules' => Migrations::schedules_table(),
					'dates'     => Migrations::schedule_dates_table(),
					'templates' => Migrations::portal_session_templates_table(),
				] as $label => $table
			) {
				$row[ $label ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
			}
			$snapshot[ 'site_' . $site_id ] = $row;
			restore_current_blog();
		}

		return $snapshot;
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

	/* ================================================================== *
	 * Reporting
	 * ================================================================== */

	private function group( string $name ): void {
		$this->group = $name;
		if ( ! isset( $this->groups[ $name ] ) ) {
			$this->groups[ $name ] = [ 'pass' => 0, 'fail' => 0 ];
		}
	}

	private function ok( bool $condition, string $message ): void {
		if ( $condition ) {
			++$this->groups[ $this->group ]['pass'];

			return;
		}

		++$this->groups[ $this->group ]['fail'];
		fwrite( STDERR, "FAIL [{$this->group}] {$message}\n" );
	}

	/** @param mixed $expected @param mixed $actual */
	private function same( $expected, $actual, string $message ): void {
		$this->ok(
			$expected === $actual,
			$message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
		);
	}

	private function report(): void {
		$pass = 0;
		$fail = 0;

		echo str_repeat( '-', 72 ), "\n";
		foreach ( $this->groups as $name => $counts ) {
			$pass += $counts['pass'];
			$fail += $counts['fail'];
			printf(
				"  %-34s %4d assertions   %s\n",
				$name,
				$counts['pass'] + $counts['fail'],
				0 === $counts['fail'] ? 'PASS' : 'FAIL (' . $counts['fail'] . ')'
			);
		}
		echo str_repeat( '-', 72 ), "\n";
		printf( "  %-34s %4d assertions   %s\n", 'TOTAL', $pass + $fail, 0 === $fail ? 'PASS' : 'FAIL' );

		foreach ( $this->notes as $note ) {
			echo "NOTE: {$note}\n";
		}

		if ( $fail > 0 ) {
			exit( 1 );
		}
	}
}

( new SessionScheduleAdminTest() )->run();
