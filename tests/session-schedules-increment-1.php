<?php

/**
 * CLI integration coverage for ADR-043 Increment 2 — the definition commit.
 *
 * Covers §16 group A (SQL correctness, 1-7), group B (definition commit,
 * template guard and dependency timing, 8-19) and group C (superseded-definition
 * cleanup, 20-23), to the extent those items are reachable before the
 * reconciliation and availability increments exist. Every item that is NOT
 * reachable yet is reported by name as DEFERRED and is never counted as a pass.
 *
 * ISOLATED DATABASE ONLY (ADR-043 §16 amendment).
 *
 * This suite creates a disposable Multisite SITE and applies Sessions
 * migrations, so it must never boot the development installation. It boots
 * tests/fixtures/wordpress-bootstrap.php with BC_TEST_DB instead, which is
 * allowlisted, sentinel-owned, and refuses the development database by name.
 *
 * HARNESS SAFETY, following tests/session-increment-1.php:
 *  - every structural and destructive scenario runs on a hall this run created,
 *    behind assert_disposable_hall();
 *  - persistent halls (2, 3, 4) are READ ONLY here. This suite creates no
 *    schedule, no Session and no template row on them; their baselines are
 *    recorded and re-asserted at the end;
 *  - rows are owned by exact id in an in-memory registry, never by a name, a
 *    prefix, a date or an id range;
 *  - the disposable hall is destroyed only when this process recorded its id.
 *
 * CONCURRENCY IS DETERMINISTIC, NEVER TIMED. Every race below is produced by an
 * interceptor on WordPress's own `query` filter, which fires immediately before
 * the statement under test is issued. The competing write therefore happens at
 * an exactly known point, with no sleep and no winner decided by timing — the
 * `query`-filter form of the barrier §16 requires.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_test_suites php tests/session-schedules-increment-1.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

/*
 * Fail closed BEFORE WordPress boots.
 *
 * `bingocommerce_state_test_scratch` is excluded deliberately: the ownership
 * CONTROLS in tests/hall-commerce-state.php require that name to be ABSENT when
 * they run, so no other suite may leave a database there.
 */
$bc_target = (string) getenv( 'BC_TEST_DB' );
if ( '' === $bc_target ) {
	fwrite( STDERR, "BC_TEST_DB is not set. This suite applies Sessions migrations and creates a\n" );
	fwrite( STDERR, "disposable site, so it must never run against the development database.\n" );
	fwrite( STDERR, "  BC_TEST_DB=bingocommerce_state_test_suites php tests/session-schedules-increment-1.php\n" );
	exit( 1 );
}
if ( ! in_array( $bc_target, [ 'bingocommerce_state_test', 'bingocommerce_state_test_suites', 'bingocommerce_state_recon' ], true ) ) {
	fwrite( STDERR, "BC_TEST_DB='{$bc_target}' is not an isolated fixture database for this suite.\n" );
	exit( 1 );
}
unset( $bc_target );

$_SERVER['REQUEST_URI']    = '/';
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
if ( ! class_exists( '\BingoCommerce\Sessions\SessionScheduleService' ) ) {
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
use BingoCommerce\Sessions\PortalTemplateGateway;
use BingoCommerce\Sessions\ScheduleExpansion;
use BingoCommerce\Sessions\ScheduleTimezone;
use BingoCommerce\Sessions\SessionScheduleResult;
use BingoCommerce\Sessions\SessionScheduleService;

final class SessionSchedulesIncrementOneTest {

	private const PERSISTENT = [ 2, 3, 4 ];
	private const PREFIX     = 'SCHED1';
	private const ZONE       = 'America/New_York';

	private int $super_admin_id = 0;

	/** @var array<int,bool> */
	private array $disposable_sites = [];

	/** @var array<int,int> exact schedule ids this run created */
	private array $created_schedules = [];
	/** @var array<int,int> exact template replica ids this run planted */
	private array $created_templates = [];
	/** @var array<int,int> exact user ids this run created */
	private array $created_users = [];

	/** @var array<string,array<string,mixed>> */
	private array $persistent_baseline = [];

	/** @var array<int,string> every statement the `query` filter observed while capturing */
	private array $captured_sql = [];
	private bool $capturing = false;

	private string $group = '';
	/** @var array<string,array{pass:int,fail:int}> */
	private array $groups = [];
	/** @var array<int,string> */
	private array $notes = [];
	/** @var array<int,string> */
	private array $deferred = [];

	public function run(): void {
		$this->super_admin_id = $this->find_super_admin_id();

		$this->group( 'Setup' );
		$this->ok( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		wp_set_current_user( $this->super_admin_id );

		$this->record_persistent_baseline();

		$hall = $this->create_disposable_site();
		$this->ok( $hall > 0, 'disposable hall created for schedule scenarios' );
		$this->ok( ! in_array( $hall, array_merge( [ 1 ], self::PERSISTENT ), true ), '  and it is not a persistent hall' );

		// Every schedule statement writes a TIME and a named zone, so the hall
		// needs one. This is the hall's own WordPress setting, set on a site this
		// run created and destroys.
		switch_to_blog( $hall );
		update_option( 'timezone_string', self::ZONE );
		restore_current_blog();

		try {
			$this->schema_group( $hall );
			$this->sql_correctness_group( $hall );
			$this->commit_group( $hall );
			$this->template_guard_group( $hall );
			$this->token_cleanup_group( $hall );
			$this->inert_resave_group( $hall );
			$this->read_failure_group( $hall );
			$this->lifecycle_group( $hall );
			$this->authorization_group( $hall );

			/*
			 * On its OWN disposable hall. This group damages replica structure
			 * deliberately, and one of its cases — dropping the Game Type
			 * `local_name_key` — destroys data that DDL alone cannot restore. Run
			 * on the shared hall it would leave every later group measuring a
			 * broken schema, so the damage is confined to a site that exists only
			 * for it.
			 */
			$readiness_hall = $this->create_disposable_site();
			$this->ok( $readiness_hall > 0, 'second disposable hall created for the readiness group' );
			$this->ok( $readiness_hall !== $hall, '  and it is a different hall from the schedule scenarios' );
			$this->sync_readiness_group( $readiness_hall );
		} finally {
			$this->cleanup();
		}

		$this->group( 'Preservation' );
		$this->same( $this->persistent_baseline, $this->persistent_snapshot(), 'persistent halls 2/3/4 unchanged by this suite' );

		/*
		 * Reported by name, never counted as a pass. Each of these asserts on a
		 * component this increment does not build, so a green run here is not a
		 * claim that they hold.
		 */
		$this->note( '§16 A7 (reconciliation and availability statements): PASSED in Increment 4 — tests/session-reconciliation-increment-1.php sql_correctness_group(). The definition-commit statements were already EXPLAIN-validated above in this harness.' );
		$this->note( '§16 B18 (inert schedule excluded from isSellable): PASSED in Increment 3 — tests/session-availability-increment-1.php:1168.' );
		$this->defer( '§16 B19 (dependency invalidation consequences)', 'a composite scenario across the phase-2 gate, guarded ON and isSellable; the underlying pieces now exist (increments 3-4) but no single test yet chains request-time validation -> post-validation Package invalidation -> commit success -> all three fail-closed consequences together — recorded as a gap, not an admin-UI blocker' );
		$this->note( '§16 C22 (old-token reconciliation worker during cleanup): PASSED in Increment 4 — tests/session-reconciliation-increment-1.php create_group().' );
		$this->note( '§16 D24-D31 (template rename proof): PASSED in Increment 4 — tests/session-reconciliation-increment-1.php preflight_blocker_group().' );

		$this->report();
	}

	/* ================================================================== *
	 * Schema — the v3 tables exist and hold what §2 says they hold
	 * ================================================================== */

	private function schema_group( int $hall ): void {
		global $wpdb;

		$this->group( 'Schema' );
		switch_to_blog( $hall );

		$this->ok( Migrations::is_current(), 'the disposable hall is at the current Sessions schema' );
		$this->same( 3, Migrations::DB_VERSION, 'DB_VERSION is 3' );

		foreach (
			[
				'bingo_session_schedules'         => Migrations::schedules_table(),
				'bingo_session_schedule_dates'    => Migrations::schedule_dates_table(),
				'bingo_session_schedule_packages' => Migrations::schedule_packages_table(),
			] as $label => $table
		) {
			$this->same(
				$table,
				(string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ),
				"{$label} exists in the disposable hall"
			);
		}

		// §2.1: the token column must compare byte-exactly, or `A3F` and `a3f`
		// would be the same definition.
		$collation = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT collation_name FROM information_schema.columns
				  WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				Migrations::schedules_table(),
				'active_definition_token'
			)
		);
		$this->same( 'ascii_bin', $collation, 'active_definition_token is ascii_bin (exact comparison)' );

		// §2.4 collation correction: the two identity columns ON bingo_sessions
		// are utf8mb4_bin, so wpdb does not classify the whole table as ascii and
		// start rejecting non-ASCII display names.
		foreach ( [ 'timezone_name', 'availability_operation_token' ] as $column ) {
			$this->same(
				'utf8mb4_bin',
				(string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT collation_name FROM information_schema.columns
						  WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
						Migrations::sessions_table(),
						$column
					)
				),
				"bingo_sessions.{$column} is utf8mb4_bin, not ascii"
			);
		}

		/*
		 * The condition `wpdb::get_table_charset()` actually applies: it derives
		 * ONE charset for a whole table from its columns and, on a mix, falls
		 * through to "Two mixed character sets. ascii."
		 * (`wp-includes/class-wpdb.php:3284-3286`). One ascii column beside the
		 * utf8mb4 display_name is therefore enough to make wpdb reject every
		 * non-ASCII Session name. The method is protected, so the condition is
		 * asserted directly rather than through it.
		 */
		$mixed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				  WHERE table_schema = DATABASE() AND table_name = %s
				    AND character_set_name IS NOT NULL AND character_set_name <> 'utf8mb4'",
				Migrations::sessions_table()
			)
		);
		$this->same( 0, $mixed, 'bingo_sessions has no non-utf8mb4 column, so wpdb cannot classify it as ascii' );

		/*
		 * The live reproduction of the defect this correction exists for. An
		 * existing development hall really does store "Wednesday Evening – 6:00
		 * PM", and under the Revision 6 literal DDL that row could no longer be
		 * written. $wpdb->insert() is the exact path: it calls process_fields(),
		 * which calls get_table_charset().
		 */
		$en_dash = 'SCHED1 Evening – 6:00 PM ' . wp_generate_password( 5, false );
		$written = $wpdb->insert(
			Migrations::sessions_table(),
			[
				'session_date'       => $this->local_date( '+2 days' ),
				'display_name'       => $en_dash,
				'available_for_sale' => 0,
				'status'             => 'active',
				'created_at'         => current_time( 'mysql', true ),
				'updated_at'         => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
		$this->same( 1, (int) $written, 'a Session display name containing an EN DASH still persists through $wpdb' );
		$this->same(
			$en_dash,
			(string) $wpdb->get_var(
				$wpdb->prepare( 'SELECT display_name FROM ' . Migrations::sessions_table() . ' WHERE display_name = %s', $en_dash )
			),
			'  and reads back byte-identical'
		);

		restore_current_blog();
	}

	/* ================================================================== *
	 * A. SQL correctness (§16 A, items 1-7)
	 * ================================================================== */

	private function sql_correctness_group( int $hall ): void {
		$this->group( 'A. SQL correctness' );
		switch_to_blog( $hall );

		$template = $this->plant_template( $hall, 'Evening Bingo' );
		$package  = $this->make_package( $hall, 'sqlpkg' );
		$service  = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$tomorrow = $this->local_date( '+2 days' );

		/* --- item 6: the provisional INSERT satisfies every NOT NULL column,
		 * executed against the real table under the server's real sql_mode. */
		$this->capture_start();
		$dates_result = $service->create( $template, 'dates', '18:00', [ $tomorrow ], [ $package ] );
		$this->capture_stop();

		$this->ok( $dates_result instanceof SessionScheduleResult, 'item 6: the provisional INSERT executes against the real table' );
		if ( $dates_result instanceof SessionScheduleResult ) {
			$this->same( SessionScheduleResult::STATUS_OK, $dates_result->status, '  and the dates definition commits' );
			$this->track_schedule( (int) $dates_result->schedule['schedule_id'] );
		} else {
			$this->ok( false, '  create failed: ' . $this->describe( $dates_result ) );
		}

		$insert = $this->one_captured( '/INSERT INTO \S+bingo_session_schedules/' );
		$create = $this->one_captured( '/UPDATE \S+bingo_session_schedules s\s+SET s\.mode\s+= \'dates\'/' );

		/* --- item 1 --- */
		$this->ok( null !== $create, 'item 1: a dates create-commit statement was emitted' );
		if ( null !== $create ) {
			$this->ok(
				false !== strpos( $create, 'active_definition_token IS NULL' ),
				'item 1: create-commit fences on `active_definition_token IS NULL`'
			);
			$this->ok(
				false === strpos( $create, 'active_definition_token = ' ) || $this->only_in_set_clause( $create ),
				'item 1: and never binds a placeholder for it in the WHERE clause'
			);
		}

		/* --- item 3 --- */
		$this->ok(
			null !== $create && false !== strpos( $create, 'recurrence_start        = NULL' ),
			'item 3: dates create-commit sets literal SQL NULL for recurrence_start'
		);
		$this->ok(
			null !== $create && false !== strpos( $create, 'recurrence_end          = NULL' ),
			'item 3: dates create-commit sets literal SQL NULL for recurrence_end'
		);
		$this->ok(
			null !== $insert && false !== strpos( $insert, "'dates', 0, NULL, NULL, NULL, 'active'" ),
			'item 3: the dates provisional INSERT writes literal NULLs, not empty strings'
		);

		/* --- item 5: no statement passed PHP null to %s. A quoted empty string
		 * where NULL was meant is the exact failure §3.3 exists to prevent, and
		 * it is visible in the emitted SQL as `''` in a nullable position. */
		$this->ok(
			null !== $insert && false === strpos( $insert, ", '', " ),
			'item 5: the provisional INSERT contains no quoted empty string standing in for NULL'
		);

		/* --- items 2 and 4: the weekly and edit statements. --- */
		$weekly_template = $this->plant_template( $hall, 'Weekly Bingo' );
		$this->capture_start();
		$weekly = $service->create(
			$weekly_template,
			'weekly',
			'19:30',
			[],
			[ $package ],
			$this->local_date( '+3 days' ),
			$this->local_date( '+40 days' ),
			( 1 << 0 ) | ( 1 << 4 )
		);
		$this->capture_stop();

		$this->ok( $weekly instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $weekly->status, 'a weekly definition commits' );
		$weekly_id = $weekly instanceof SessionScheduleResult ? (int) $weekly->schedule['schedule_id'] : 0;
		if ( $weekly_id > 0 ) {
			$this->track_schedule( $weekly_id );
		}

		$weekly_sql = $this->one_captured( '/UPDATE \S+bingo_session_schedules s\s+SET s\.mode\s+= \'weekly\'/' );
		$this->ok(
			null !== $weekly_sql
				&& 1 === preg_match( '/recurrence_start\s+= \'\d{4}-\d{2}-\d{2}\'/', $weekly_sql )
				&& 1 === preg_match( '/recurrence_end\s+= \'\d{4}-\d{2}-\d{2}\'/', $weekly_sql ),
			'item 4: weekly create-commit binds validated non-null date bounds'
		);
		$this->ok(
			null !== $weekly_sql && 1 === preg_match( '/weekday_mask\s+= 17\b/', $weekly_sql ),
			'item 4: and binds the validated weekday mask as an integer'
		);

		// Edit, for item 2.
		$this->capture_start();
		$edited = $service->edit( $weekly_id, 'weekly', '19:30', [], [ $package ], $this->local_date( '+3 days' ), $this->local_date( '+45 days' ), ( 1 << 0 ) | ( 1 << 4 ) );
		$this->capture_stop();

		$this->ok( $edited instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $edited->status, 'the weekly definition edits' );
		$edit_sql = $this->one_captured( '/UPDATE \S+bingo_session_schedules s\s+SET s\.mode\s+= \'weekly\'/' );
		$this->ok(
			null !== $edit_sql && 1 === preg_match( "/AND s\.active_definition_token = '[0-9a-f-]{36}'/", $edit_sql ),
			'item 2: edit-commit fences on the exact token observed before the edit'
		);
		$this->ok(
			null !== $edit_sql && false === strpos( $edit_sql, 'active_definition_token IS NULL' ),
			'item 2: an edit WITH a definition to supersede does not use the IS NULL fence'
		);

		/* --- item 7: EXPLAIN every definition-commit statement against the live
		 * server, and prove none trips MySQL error 1093. The commit statements
		 * are UPDATEs with a correlated EXISTS over a DIFFERENT table, which is
		 * legal; 1093 would mean one of them referenced its own target in a
		 * subquery, which MySQL refuses for UPDATE and DELETE. */
		$this->explain_group( array_values( array_filter( [ $create, $weekly_sql, $edit_sql ] ) ) );

		restore_current_blog();
	}

	/** @param array<int,string> $statements */
	private function explain_group( array $statements ): void {
		global $wpdb;

		$this->ok( [] !== $statements, 'item 7: definition-commit statements were captured for EXPLAIN' );

		foreach ( $statements as $index => $sql ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->last_error = '';
			$wpdb->get_results( 'EXPLAIN ' . $sql );
			$error = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $suppress );

			$this->same( '', $error, sprintf( 'item 7: statement %d EXPLAINs cleanly on the live server', $index + 1 ) );
			$this->ok(
				false === stripos( $error, 'target table' ) && false === strpos( $error, '1093' ),
				sprintf( '  and statement %d does not trip MySQL error 1093', $index + 1 )
			);
		}
	}

	/* ================================================================== *
	 * B. Definition commit and concurrency (§16 B, items 8-10, 16-17)
	 * ================================================================== */

	private function commit_group( int $hall ): void {
		global $wpdb;

		$this->group( 'B. Definition commit' );
		switch_to_blog( $hall );

		$template = $this->plant_template( $hall, 'Concurrent Bingo' );
		$package  = $this->make_package( $hall, 'concpkg' );
		$service  = new SessionScheduleService( [ $this, 'swallow_audit' ] );

		$seed = $service->create( $template, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$this->ok( $seed instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $seed->status, 'a schedule exists to contend over' );
		$schedule_id = $seed instanceof SessionScheduleResult ? (int) $seed->schedule['schedule_id'] : 0;
		$this->track_schedule( $schedule_id );

		$first_token = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT active_definition_token FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id )
		);

		/* --- items 8, 9, 10: two concurrent edits, deterministically ordered.
		 *
		 * Editor B commits COMPLETELY from inside the `query` filter, at the
		 * instant immediately before editor A's own CAS statement is issued. Both
		 * observed the same $seen; B's commit therefore moves the token out from
		 * under A, and A's CAS must affect zero rows. No sleep decides this. */
		$rival        = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$rival_result = null;
		$a_date       = $this->local_date( '+5 days' );
		$b_date       = $this->local_date( '+6 days' );

		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $rival, $schedule_id, $b_date, $package, &$rival_result ): void {
				$rival_result = $rival->edit( $schedule_id, 'dates', '20:00', [ $b_date ], [ $package ] );
			}
		);

		$loser = $service->edit( $schedule_id, 'dates', '21:00', [ $a_date ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'item 8: the barrier fired — the race was real, not vacuous' );

		$this->ok(
			$rival_result instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $rival_result->status,
			'item 8: the interposed editor commits exactly once'
		);
		$this->ok(
			$loser instanceof SessionScheduleResult && SessionScheduleResult::STATUS_SCHEDULE_STATE_CHANGED === $loser->status,
			'item 8: the other editor affects zero rows and reports schedule_state_changed'
		);
		if ( $loser instanceof SessionScheduleResult ) {
			$this->same( false, $loser->complete, '  and is never reported as complete' );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT active_definition_token, start_time_local FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);
		$winner_token = (string) $row['active_definition_token'];

		$this->same( '20:00:00', (string) $row['start_time_local'], 'item 10: every header field reflects the winner, from one row read' );
		$this->ok( $winner_token !== $first_token, 'item 10: and the active token advanced' );

		/* --- item 9: no set is merged. Each token holds exactly its own dates. */
		$per_token = $this->dates_by_token( $schedule_id );
		$this->same( [ $b_date ], $per_token[ $winner_token ] ?? [], 'item 9: the winner\'s token holds only its own date' );
		foreach ( $per_token as $token => $dates ) {
			if ( $token === $winner_token ) {
				continue;
			}
			$this->ok( ! in_array( $b_date, $dates, true ), "item 9: token {$token} did not absorb the winner's date" );
		}

		/* --- item 16: a failed commit deletes ONLY its own private token.
		 *
		 * Asserted as an EXACT SET, not as "some token is absent". The earlier
		 * form asked whether a token found by searching for one that is neither
		 * the winner nor the original was empty — and when the loser's cleanup
		 * worked, that search returned '' and the assertion passed by looking up
		 * a key that was never there. It would have passed just as readily if
		 * cleanup had deleted nothing at all. What follows enumerates every token
		 * present and compares the whole set. */
		$tokens_now = array_keys( $this->dates_by_token( $schedule_id ) );
		sort( $tokens_now );

		$this->same(
			[ $winner_token ],
			$tokens_now,
			'item 16: exactly ONE token has date rows — the winner\'s'
		);
		$this->same( [ $b_date ], $per_token[ $winner_token ] ?? [], 'item 16: holding exactly the winner\'s date' );
		$this->same(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Migrations::schedule_dates_table() . ' WHERE schedule_id = %d AND definition_token <> %s',
					$schedule_id,
					$winner_token
				)
			),
			'item 16: the loser\'s staged date rows are GONE — zero rows under any other token'
		);
		$this->same(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Migrations::schedule_packages_table() . ' WHERE schedule_id = %d AND definition_token <> %s',
					$schedule_id,
					$winner_token
				)
			),
			'item 16: and so are its staged Package rows'
		);
		$this->same(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Migrations::schedule_packages_table() . ' WHERE schedule_id = %d AND definition_token = %s',
					$schedule_id,
					$winner_token
				)
			),
			'item 16: the winner\'s own Package row was not deleted'
		);

		/* --- item 17: no staged-token cross-request deletion.
		 *
		 * A REAL SECOND PRODUCTION REQUEST, paused after staging and then allowed
		 * to attempt its own CAS. The earlier form planted rows with raw INSERTs
		 * and asserted they survived; it never ran a commit, so "B then commits"
		 * was an unexecuted claim. Here request B is an actual
		 * SessionScheduleService::edit(), suspended by the barrier at the instant
		 * BETWEEN its staging INSERTs and its CAS — which is exactly the window
		 * §16 item 17 is about.
		 *
		 * While B is suspended, request A runs a COMPLETE edit that fails its own
		 * commit and runs its own cleanup. A's failure is caused by invalidating
		 * the template, which is the one way to fail a CAS WITHOUT moving the
		 * token — so when B resumes, the token B fenced on is still current and
		 * B's commit can legitimately land. The template is restored before B
		 * resumes, because B's CAS carries the template guard too.
		 *
		 * Assertions: B's staged rows survived A's cleanup, B's CAS really
		 * executed, and B's definition is the one that ends up active. */
		$b_service   = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$b_date_real = $this->local_date( '+12 days' );
		$a_ran       = false;
		$a_result    = null;

		$a_nested_fired = false;

		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $wpdb, $schedule_id, $package, $template, &$a_ran, &$a_result, &$a_nested_fired ): void {
				// Request B is now suspended, having staged its rows and not yet
				// issued its CAS.
				$a_ran = true;

				/*
				 * Request A must get PAST request-time validation and then fail at
				 * its own CAS — otherwise it never stages a private token and
				 * never runs the cleanup this test is about. So the template is
				 * invalidated by a nested barrier at A's CAS, not before A starts:
				 * invalidating it up front would make A fail validation instead,
				 * which proves nothing here.
				 */
				$this->intercept_nested_once(
					'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
					function () use ( $wpdb, $template ): void {
						$wpdb->update(
							Migrations::portal_session_templates_table(),
							[ 'is_current' => 0 ],
							[ 'portal_session_id' => $template ]
						);
					}
				);

				$a_service = new SessionScheduleService( [ $this, 'swallow_audit' ] );
				$a_result  = $a_service->edit( $schedule_id, 'dates', '22:15', [ $this->local_date( '+13 days' ) ], [ $package ] );

				$a_nested_fired = $this->clear_nested_intercept();

				// Restore eligibility so B's own template guard can pass.
				$wpdb->update(
					Migrations::portal_session_templates_table(),
					[ 'is_current' => 1 ],
					[ 'portal_session_id' => $template ]
				);
			}
		);

		$staged_before = $this->dates_by_token( $schedule_id );
		$b_result      = $b_service->edit( $schedule_id, 'dates', '20:30', [ $b_date_real ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'item 17: the barrier fired — request B really was suspended after staging' );

		$this->ok( $a_ran, 'item 17: request A ran while B was suspended' );
		$this->ok( $a_nested_fired, 'item 17: the NESTED barrier fired — A reached its own CAS before failing' );
		$this->ok(
			$a_result instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK !== $a_result->status,
			'item 17: request A staged a private token, then its commit failed and its own cleanup ran'
		);
		$this->ok(
			$b_result instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $b_result->status,
			'item 17: request B then COMMITTED — its staged rows survived A\'s cleanup'
		);

		if ( $b_result instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $b_result->status ) {
			$b_token = (string) $b_result->schedule['active_definition_token'];
			$this->same(
				$b_token,
				(string) $wpdb->get_var(
					$wpdb->prepare( 'SELECT active_definition_token FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id )
				),
				'item 17: and B\'s token is the active one'
			);
			$this->same(
				[ $b_date_real ],
				$this->dates_by_token( $schedule_id )[ $b_token ] ?? [],
				'item 17: holding exactly B\'s own date, unmerged with A\'s'
			);
			$this->same(
				0,
				(int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . Migrations::schedule_dates_table() . ' WHERE schedule_id = %d AND definition_token <> %s',
						$schedule_id,
						$b_token
					)
				),
				'item 17: and A left no staged rows of its own behind'
			);
		}
		unset( $staged_before );

		restore_current_blog();
	}

	/* ================================================================== *
	 * B. Template guard (§16 B, items 11-15)
	 * ================================================================== */

	private function template_guard_group( int $hall ): void {
		global $wpdb;

		$this->group( 'B. Template guard' );
		switch_to_blog( $hall );

		$package = $this->make_package( $hall, 'guardpkg' );
		$service = new SessionScheduleService( [ $this, 'swallow_audit' ] );

		/* --- items 11 and 12: the template is invalidated AFTER request-time
		 * validation and BEFORE the CAS. The schedule must stay inert, and the
		 * result must name the template — not report a generic lost race. */
		foreach (
			[
				'item 11' => [ 'is_current', 0, 'made non-current' ],
				'item 12' => [ 'portal_status', 'draft', 'made draft' ],
			] as $item => $invalidation
		) {
			$template = $this->plant_template( $hall, 'Guarded ' . $item );

			$this->intercept_once(
				'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
				function () use ( $wpdb, $template, $invalidation ): void {
					$wpdb->update(
						Migrations::portal_session_templates_table(),
						[ $invalidation[0] => $invalidation[1] ],
						[ 'portal_session_id' => $template ]
					);
				}
			);

			$result = $service->create( $template, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
			$this->ok( $this->clear_intercept(), "  {$item}: the barrier fired — the template really was invalidated mid-request" );

			$this->ok(
				$result instanceof SessionScheduleResult && SessionScheduleResult::STATUS_FAILED === $result->status,
				"{$item}: create commit affects zero rows when the template is {$invalidation[2]} mid-request"
			);
			if ( $result instanceof SessionScheduleResult ) {
				$this->same( 'eligibility_failed', $result->failure_category, "  {$item}: reported as eligibility_failed, not a lost race" );
				$this->same(
					'template_not_commerce_eligible',
					(string) ( $result->blockers[0]['kind'] ?? '' ),
					"  {$item}: and the blocker names the template"
				);

				$schedule_id = (int) ( $result->schedule['schedule_id'] ?? 0 );
				if ( $schedule_id > 0 ) {
					$this->track_schedule( $schedule_id );
					$token = $wpdb->get_var(
						$wpdb->prepare( 'SELECT active_definition_token FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id )
					);
					$this->same( null, $token, "  {$item}: the schedule remains INERT — active_definition_token is SQL NULL" );
					$this->same(
						false,
						(bool) ( $result->schedule['has_definition'] ?? true ),
						"  {$item}: and is reported as having no readable definition"
					);
				}
			}
		}

		/* --- item 13: the same two invalidations against an EDIT. The previous
		 * definition must be intact and unchanged. */
		foreach (
			[
				'item 13a' => [ 'is_current', 0 ],
				'item 13b' => [ 'portal_status', 'draft' ],
			] as $item => $invalidation
		) {
			$template = $this->plant_template( $hall, 'Editable ' . $item );
			$seed     = $service->create( $template, 'dates', '17:45', [ $this->local_date( '+2 days' ) ], [ $package ] );
			$this->ok( $seed instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $seed->status, "{$item}: a committed schedule exists to edit" );
			$schedule_id = $seed instanceof SessionScheduleResult ? (int) $seed->schedule['schedule_id'] : 0;
			$this->track_schedule( $schedule_id );

			$before = $this->header_snapshot( $schedule_id );

			$this->intercept_once(
				'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
				function () use ( $wpdb, $template, $invalidation ): void {
					$wpdb->update(
						Migrations::portal_session_templates_table(),
						[ $invalidation[0] => $invalidation[1] ],
						[ 'portal_session_id' => $template ]
					);
				}
			);
			$result = $service->edit( $schedule_id, 'dates', '23:00', [ $this->local_date( '+11 days' ) ], [ $package ] );
			$this->ok( $this->clear_intercept(), "  {$item}: the barrier fired" );

			$this->ok(
				$result instanceof SessionScheduleResult && SessionScheduleResult::STATUS_FAILED === $result->status,
				"{$item}: edit commit affects zero rows under template invalidation"
			);
			$this->same( $before, $this->header_snapshot( $schedule_id ), "  {$item}: the previous definition is intact and unchanged" );
		}

		/* --- items 14 and 15: restore is guarded, archive is not. --- */
		$template = $this->plant_template( $hall, 'Lifecycle Guard' );
		$seed     = $service->create( $template, 'dates', '16:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$schedule_id = $seed instanceof SessionScheduleResult ? (int) $seed->schedule['schedule_id'] : 0;
		$this->track_schedule( $schedule_id );

		$wpdb->update( Migrations::portal_session_templates_table(), [ 'is_current' => 0 ], [ 'portal_session_id' => $template ] );

		$archived = $service->archive( $schedule_id );
		$this->ok(
			$archived instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $archived->status,
			'item 15: archive SUCCEEDS while the template is non-current — stopping commerce is never blocked'
		);
		$this->same(
			'archived',
			(string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id ) ),
			'  and the row really is archived'
		);

		$restored = $service->restore( $schedule_id );
		$this->ok(
			$restored instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK !== $restored->status,
			'item 14: restore affects zero rows while the template is non-current'
		);
		$this->same(
			'archived',
			(string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id ) ),
			'  and the schedule stays archived'
		);

		$wpdb->update( Migrations::portal_session_templates_table(), [ 'is_current' => 1, 'portal_status' => 'draft' ], [ 'portal_session_id' => $template ] );
		$restored_draft = $service->restore( $schedule_id );
		$this->ok(
			$restored_draft instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK !== $restored_draft->status,
			'item 14: restore affects zero rows while the template is draft'
		);

		$wpdb->update( Migrations::portal_session_templates_table(), [ 'portal_status' => 'active' ], [ 'portal_session_id' => $template ] );
		$restored_ok = $service->restore( $schedule_id );
		$this->ok(
			$restored_ok instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $restored_ok->status,
			'  and restore succeeds once the template is commerce-eligible again'
		);

		restore_current_blog();
	}

	/* ================================================================== *
	 * C. Superseded-definition cleanup (§16 C, items 20, 21, 23)
	 * ================================================================== */

	private function token_cleanup_group( int $hall ): void {
		global $wpdb;

		$this->group( 'C. Superseded cleanup' );
		switch_to_blog( $hall );

		$template = $this->plant_template( $hall, 'Cleanup Bingo' );
		$package  = $this->make_package( $hall, 'cleanpkg' );
		$service  = new SessionScheduleService( [ $this, 'swallow_audit' ] );

		$first = $service->create( $template, 'dates', '18:00', [ $this->local_date( '+2 days' ), $this->local_date( '+3 days' ) ], [ $package ] );
		$this->ok( $first instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $first->status, 'a schedule with a committed definition exists' );
		$schedule_id = $first instanceof SessionScheduleResult ? (int) $first->schedule['schedule_id'] : 0;
		$this->track_schedule( $schedule_id );

		$seen = (string) $first->schedule['active_definition_token'];
		$this->same( 2, count( $this->dates_by_token( $schedule_id )[ $seen ] ?? [] ), 'the first token holds its two dates' );

		// A third-party staged token, to prove cleanup never reaches it.
		$foreign = strtolower( wp_generate_uuid4() );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::schedule_dates_table() . ' (schedule_id, definition_token, schedule_date, created_at) VALUES (%d, %s, %s, %s)',
				$schedule_id,
				$foreign,
				$this->local_date( '+20 days' ),
				current_time( 'mysql', true )
			)
		);

		/* --- item 20 --- */
		$edited = $service->edit( $schedule_id, 'dates', '18:30', [ $this->local_date( '+4 days' ) ], [ $package ] );
		$this->ok( $edited instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $edited->status, 'item 20: the edit commits' );
		$new_token = $edited instanceof SessionScheduleResult ? (string) $edited->schedule['active_definition_token'] : '';

		$by_token = $this->dates_by_token( $schedule_id );
		$this->same( [], $by_token[ $seen ] ?? [], 'item 20: the superseded token\'s date rows are gone' );
		$this->same(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Migrations::schedule_packages_table() . ' WHERE schedule_id = %d AND definition_token = %s',
					$schedule_id,
					$seen
				)
			),
			'item 20: and so are its Package rows'
		);
		$this->same( 1, count( $by_token[ $new_token ] ?? [] ), 'item 23: the new active token\'s rows are untouched' );
		$this->same( 1, count( $by_token[ $foreign ] ?? [] ), 'item 23: and another token\'s staged rows are untouched' );

		/* --- item 21: the guard `sch.active_definition_token <> $seen` proven by
		 * forcing $seen to be active again at the moment cleanup runs.
		 *
		 * The interceptor fires on the cleanup DELETE itself and rewrites the
		 * header back to $seen immediately before it is issued, so the JOIN
		 * condition is false when the delete executes. Zero rows must be
		 * deleted. */
		$revived = strtolower( wp_generate_uuid4() );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::schedule_dates_table() . ' (schedule_id, definition_token, schedule_date, created_at) VALUES (%d, %s, %s, %s)',
				$schedule_id,
				$revived,
				$this->local_date( '+21 days' ),
				current_time( 'mysql', true )
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::schedules_table() . ' SET active_definition_token = %s WHERE schedule_id = %d',
				$revived,
				$schedule_id
			)
		);

		$this->intercept_once(
			'/DELETE d\s+FROM \S+bingo_session_schedule_dates/',
			function () use ( $wpdb, $schedule_id, $revived ): void {
				// Restore the token being deleted AS THE ACTIVE ONE, immediately
				// before the delete is issued.
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . Migrations::schedules_table() . ' SET active_definition_token = %s WHERE schedule_id = %d',
						$revived,
						$schedule_id
					)
				);
			}
		);

		$edited_again = $service->edit( $schedule_id, 'dates', '18:45', [ $this->local_date( '+5 days' ) ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'item 21: the barrier fired on the cleanup DELETE itself' );

		$this->ok( $edited_again instanceof SessionScheduleResult, 'item 21: the second edit returned a result' );
		$still = $this->dates_by_token( $schedule_id );
		$this->same(
			1,
			count( $still[ $revived ] ?? [] ),
			'item 21: cleanup deleted ZERO rows because the token was active again at the instant of the DELETE'
		);

		restore_current_blog();
	}

	/* ================================================================== *
	 * Inert schedule recovery (§3.8 step 5)
	 * ================================================================== */

	private function inert_resave_group( int $hall ): void {
		global $wpdb;

		$this->group( 'Inert re-save' );
		switch_to_blog( $hall );

		$template = $this->plant_template( $hall, 'Inert Bingo' );
		$package  = $this->make_package( $hall, 'inertpkg' );
		$service  = new SessionScheduleService( [ $this, 'swallow_audit' ] );

		$headers_before = $this->header_count( $template );

		// 1. Make a create go inert by invalidating the template mid-request.
		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $wpdb, $template ): void {
				$wpdb->update( Migrations::portal_session_templates_table(), [ 'portal_status' => 'draft' ], [ 'portal_session_id' => $template ] );
			}
		);
		$inert = $service->create( $template, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'the barrier fired — the template was invalidated mid-request' );

		$this->ok(
			$inert instanceof SessionScheduleResult && SessionScheduleResult::STATUS_FAILED === $inert->status,
			'a create whose template goes draft mid-request does not commit'
		);
		$schedule_id = $inert instanceof SessionScheduleResult ? (int) ( $inert->schedule['schedule_id'] ?? 0 ) : 0;
		$this->ok( $schedule_id > 0, '  and the header row survives with an id' );
		$this->track_schedule( $schedule_id );

		$this->same(
			null,
			$wpdb->get_var( $wpdb->prepare( 'SELECT active_definition_token FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id ) ),
			'  the row is INERT — active_definition_token is SQL NULL'
		);
		$this->same( $headers_before + 1, $this->header_count( $template ), '  exactly one header row exists for the template' );

		$frozen = $this->header_snapshot( $schedule_id );

		// 2. Fix what blocked the commit.
		$wpdb->update( Migrations::portal_session_templates_table(), [ 'portal_status' => 'active' ], [ 'portal_session_id' => $template ] );

		// 3. Re-save THE SAME schedule_id.
		$resaved = $service->edit( $schedule_id, 'dates', '19:15', [ $this->local_date( '+4 days' ) ], [ $package ] );
		$this->ok(
			$resaved instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $resaved->status,
			'the inert schedule is re-savable once the template is eligible again'
		);

		if ( $resaved instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $resaved->status ) {
			$this->same( $schedule_id, (int) $resaved->schedule['schedule_id'], '  using the SAME schedule_id' );
			$this->same( true, (bool) $resaved->schedule['has_definition'], '  and it now has a readable definition' );

			$after = $this->header_snapshot( $schedule_id );
			$this->same( $frozen['portal_session_id'], $after['portal_session_id'], '  portal_session_id is unchanged (immutable)' );
			$this->same( $frozen['timezone_name'], $after['timezone_name'], '  timezone_name is unchanged (immutable, §5.1)' );
			$this->same( '19:15:00', (string) $after['start_time_local'], '  the re-saved start time is stored' );
		}

		// 4. NO second header was created.
		$this->same( $headers_before + 1, $this->header_count( $template ), 'no second schedule header was created by the re-save' );

		// 5. Only the committed token has rows; the failed create cleaned its own.
		$tokens = array_keys( $this->dates_by_token( $schedule_id ) );
		sort( $tokens );
		$this->same(
			[ (string) ( $resaved instanceof SessionScheduleResult ? $resaved->schedule['active_definition_token'] : '' ) ],
			$tokens,
			'exactly one token has date rows — the re-save cleaned the failed create\'s private token'
		);

		/* --- Two concurrent inert re-saves: exactly one commits.
		 *
		 * Both fence on `active_definition_token IS NULL`, so the first to land
		 * makes the column non-NULL and the second must affect zero rows. */
		$template2 = $this->plant_template( $hall, 'Inert Race' );
		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $wpdb, $template2 ): void {
				$wpdb->update( Migrations::portal_session_templates_table(), [ 'portal_status' => 'draft' ], [ 'portal_session_id' => $template2 ] );
			}
		);
		$inert2 = $service->create( $template2, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'the barrier fired for the second inert row' );
		$inert2_id = $inert2 instanceof SessionScheduleResult ? (int) ( $inert2->schedule['schedule_id'] ?? 0 ) : 0;
		$this->track_schedule( $inert2_id );
		$wpdb->update( Migrations::portal_session_templates_table(), [ 'portal_status' => 'active' ], [ 'portal_session_id' => $template2 ] );

		$rival        = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$rival_result = null;
		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $rival, $inert2_id, $package, &$rival_result ): void {
				$rival_result = $rival->edit( $inert2_id, 'dates', '21:45', [ $this->local_date( '+6 days' ) ], [ $package ] );
			}
		);
		$loser = $service->edit( $inert2_id, 'dates', '22:45', [ $this->local_date( '+7 days' ) ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'the barrier fired — two inert re-saves really did contend' );

		$winners = 0;
		foreach ( [ $rival_result, $loser ] as $outcome ) {
			if ( $outcome instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $outcome->status ) {
				++$winners;
			}
		}
		$this->same( 1, $winners, 'two concurrent inert re-saves: EXACTLY ONE commits' );
		$this->ok(
			$loser instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK !== $loser->status,
			'  and the loser is not reported as complete'
		);
		$this->same(
			1,
			count( $this->dates_by_token( $inert2_id ) ),
			'  exactly one token holds date rows afterwards'
		);

		restore_current_blog();
	}

	/* ================================================================== *
	 * Failure classification when the authoritative re-read itself fails
	 * ================================================================== */

	private function read_failure_group( int $hall ): void {
		global $wpdb;

		$this->group( 'Read-failure classification' );
		switch_to_blog( $hall );

		$package  = $this->make_package( $hall, 'readfailpkg' );
		$service  = new SessionScheduleService( [ $this, 'swallow_audit' ] );

		/*
		 * Both cases below force a CAS to affect zero rows and then break ONE of
		 * the authoritative re-reads that classify the failure. The old code
		 * coerced a failed read to an empty array and reported
		 * `schedule_state_changed` — a specific, reassuring claim ("someone beat
		 * you to it, reload and retry") that the evidence did not support and
		 * that retrying would not fix.
		 */

		/* --- the SCHEDULE re-read fails --- */
		$template = $this->plant_template( $hall, 'ReadFail A' );
		$seed     = $service->create( $template, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$this->ok( $seed instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $seed->status, 'a committed schedule exists' );
		$schedule_id = $seed instanceof SessionScheduleResult ? (int) $seed->schedule['schedule_id'] : 0;
		$this->track_schedule( $schedule_id );

		$rival = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $rival, $schedule_id, $package ): void {
				// Move the token so the outer CAS must fail ...
				$rival->edit( $schedule_id, 'dates', '20:00', [ $this->local_date( '+8 days' ) ], [ $package ] );
				// ... then break the schedule re-read that classifies the failure.
				$this->break_reads_matching( '/SELECT schedule_id, portal_session_id/' );
			}
		);
		$result = $service->edit( $schedule_id, 'dates', '21:00', [ $this->local_date( '+9 days' ) ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'the barrier fired for the schedule-read failure' );
		$this->restore_reads();

		$this->same( 'persistence_failed', $this->error_code( $result ), 'a FAILED schedule re-read is propagated as persistence_failed' );
		$this->ok(
			! ( $result instanceof SessionScheduleResult ),
			'  and is never reported as schedule_state_changed'
		);

		/* --- the TEMPLATE re-read fails --- */
		$template_b = $this->plant_template( $hall, 'ReadFail B' );
		$seed_b     = $service->create( $template_b, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$schedule_b = $seed_b instanceof SessionScheduleResult ? (int) $seed_b->schedule['schedule_id'] : 0;
		$this->track_schedule( $schedule_b );

		$rival_b = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $rival_b, $schedule_b, $package ): void {
				$rival_b->edit( $schedule_b, 'dates', '20:00', [ $this->local_date( '+8 days' ) ], [ $package ] );
				$this->break_reads_matching( '/SELECT portal_session_id, name, portal_status/' );
			}
		);
		$result_b = $service->edit( $schedule_b, 'dates', '21:00', [ $this->local_date( '+9 days' ) ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'the barrier fired for the template-read failure' );
		$this->restore_reads();

		$this->same( 'persistence_failed', $this->error_code( $result_b ), 'a FAILED template re-read is propagated as persistence_failed' );
		$this->ok(
			! ( $result_b instanceof SessionScheduleResult ),
			'  and is never reported as schedule_state_changed'
		);

		// The control: with both reads healthy, the SAME race really does yield
		// schedule_state_changed — so the two assertions above are not passing
		// merely because every lost race now errors.
		$template_c = $this->plant_template( $hall, 'ReadFail Control' );
		$seed_c     = $service->create( $template_c, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		$schedule_c = $seed_c instanceof SessionScheduleResult ? (int) $seed_c->schedule['schedule_id'] : 0;
		$this->track_schedule( $schedule_c );

		$rival_c = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$this->intercept_once(
			'/UPDATE \S+bingo_session_schedules s\s+SET s\.mode/',
			function () use ( $rival_c, $schedule_c, $package ): void {
				$rival_c->edit( $schedule_c, 'dates', '20:00', [ $this->local_date( '+8 days' ) ], [ $package ] );
			}
		);
		$control = $service->edit( $schedule_c, 'dates', '21:00', [ $this->local_date( '+9 days' ) ], [ $package ] );
		$this->ok( $this->clear_intercept(), 'the control barrier fired' );

		$this->ok(
			$control instanceof SessionScheduleResult && SessionScheduleResult::STATUS_SCHEDULE_STATE_CHANGED === $control->status,
			'control: with healthy reads the same race DOES report schedule_state_changed'
		);

		restore_current_blog();
	}

	/* ================================================================== *
	 * §11.3 Portal-sync readiness separation
	 * ================================================================== */

	private function sync_readiness_group( int $hall ): void {
		$this->group( '§11.3 Portal-sync readiness' );
		switch_to_blog( $hall );

		if ( ! class_exists( '\BingoCommerce\PortalIntegration\PortalSyncService' ) ) {
			$this->ok( false, 'PortalSyncService is unavailable, so §11.3 cannot be verified' );
			restore_current_blog();

			return;
		}

		$sync = new \BingoCommerce\PortalIntegration\PortalSyncService();
		$this->ok( is_callable( [ $sync, 'assert_snapshot_schemas' ] ), 'assert_snapshot_schemas() is the exposed gate' );

		$this->same( true, $sync->assert_snapshot_schemas(), 'an intact hall passes the snapshot-structure gate' );

		/* --- The gate performs NO DDL and NO migration write.
		 *
		 * Measured on the HEALTHY path, before any damage below, so the statement
		 * list is the complete one a real synchronization would emit rather than
		 * a short list truncated by an early refusal. */
		$this->capture_start();
		$sync->assert_snapshot_schemas();
		$this->capture_stop();

		$this->ok( [] !== $this->captured_sql, 'the readiness path emitted statements to inspect' );

		$writes = [];
		foreach ( $this->captured_sql as $sql ) {
			$head = strtoupper( ltrim( $sql ) );
			foreach ( [ 'CREATE ', 'ALTER ', 'DROP ', 'INSERT ', 'UPDATE ', 'DELETE ', 'REPLACE ', 'TRUNCATE ', 'RENAME ', 'GRANT ' ] as $verb ) {
				if ( 0 === strpos( $head, $verb ) ) {
					$writes[] = substr( trim( $sql ), 0, 60 );
				}
			}
		}
		$this->same( [], $writes, 'the readiness path performs NO DDL and NO write of any kind' );

		$version_before = get_option( 'bc_sessions_db_version' );
		$catalog_before = get_option( 'bc_catalog_db_version' );
		$sync->assert_snapshot_schemas();
		$this->same( $version_before, get_option( 'bc_sessions_db_version' ), 'and advances no Sessions version marker' );
		$this->same( $catalog_before, get_option( 'bc_catalog_db_version' ), 'and advances no Catalog version marker' );

		$this->note( sprintf( '§11.3 readiness gate emitted %d statements, all read-only', count( $this->captured_sql ) ) );

		/* --- A hall recorded at Sessions v2 still synchronizes. --- */
		$recorded = get_option( 'bc_sessions_db_version' );
		update_option( 'bc_sessions_db_version', 2 );

		$this->same( false, Migrations::is_current(), 'with the marker at 2 the whole-plugin VERSION gate is false' );
		$this->same(
			true,
			$sync->assert_snapshot_schemas(),
			'a hall at Sessions version 2 with intact snapshot structures STILL synchronizes'
		);

		update_option( 'bc_sessions_db_version', $recorded );

		/* --- Damage limited to unrelated bingo_sessions v3 structure must not
		 * block synchronization: a snapshot touches none of it.
		 *
		 * The contrast is drawn against the STRUCTURAL verifier, not against
		 * is_current(). is_current() is a pure version-marker comparison
		 * (`…/Migrations.php`), so dropping a column leaves it true — which is
		 * itself worth stating, because it means the old gate would have waved a
		 * structurally damaged hall through while refusing an intact one recorded
		 * at 2. The new gate gets both cases right. */
		$this->disposable_ddl( $hall, 'ALTER TABLE ' . Migrations::sessions_table() . ' DROP COLUMN starts_at_utc' );

		$this->ok(
			is_wp_error( Migrations::verify_current_site_schema() ),
			'dropping a v3 column makes the whole-plugin STRUCTURAL verifier fail'
		);
		$this->same(
			true,
			Migrations::is_current(),
			'  while the version marker alone still reads as current — the old gate could not see this at all'
		);
		$this->same(
			true,
			$sync->assert_snapshot_schemas(),
			'damage limited to unrelated bingo_sessions v3 structure does NOT block snapshot synchronization'
		);

		$this->disposable_ddl( $hall, 'DROP TABLE IF EXISTS ' . Migrations::schedule_dates_table() );
		$this->same(
			true,
			$sync->assert_snapshot_schemas(),
			'and neither does losing a v3 schedule table'
		);

		/* --- Damaged REQUIRED replica structure fails closed. --- */
		$this->disposable_ddl( $hall, 'ALTER TABLE ' . Migrations::portal_session_templates_table() . ' DROP COLUMN not_current_at' );
		$this->same(
			'portal_sync_schema_unavailable',
			$this->error_code( $sync->assert_snapshot_schemas() ),
			'damaged Session-template replica structure FAILS CLOSED'
		);

		$this->disposable_ddl( $hall, 'ALTER TABLE ' . Migrations::portal_session_templates_table() . ' ADD COLUMN not_current_at datetime DEFAULT NULL' );
		$this->same( true, $sync->assert_snapshot_schemas(), '  and passes again once that column is restored' );

		/* --- THE READINESS GATE DOES NOT USURP THE TRANSPORT'S ENGINE GATE.
		 *
		 * A non-InnoDB replica is a real fault, but it is the SNAPSHOT
		 * TRANSPORT's fault to report: it asserts InnoDB through
		 * `REQUIRED_INNODB_TABLES` after the fetch and immediately before BEGIN,
		 * with its own `portal_sync_storage_engine_unsupported` code, and
		 * tests/portal-snapshot-application.php asserts exactly that ordering.
		 * A readiness gate that rejected the table here would move the engine
		 * decision ahead of the fetch and swap a specific error code for a vaguer
		 * one — which is why the gate calls the structure-only verifiers. */
		$this->disposable_ddl( $hall, 'ALTER TABLE ' . Migrations::portal_session_templates_table() . ' ENGINE=MyISAM' );
		$this->same(
			'MyISAM',
			(string) $GLOBALS['wpdb']->get_var(
				$GLOBALS['wpdb']->prepare(
					'SELECT engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					Migrations::portal_session_templates_table()
				)
			),
			'the replica table is temporarily non-transactional'
		);
		$this->same(
			true,
			$sync->assert_snapshot_schemas(),
			'the readiness gate passes a non-InnoDB replica — the engine belongs to the transport\'s own gate'
		);
		$this->ok(
			is_wp_error( Migrations::verify_portal_session_templates_schema() ),
			'  while the FULL verifier, which migration uses, still rejects it'
		);
		$this->disposable_ddl( $hall, 'ALTER TABLE ' . Migrations::portal_session_templates_table() . ' ENGINE=InnoDB' );
		$this->same( true, $sync->assert_snapshot_schemas(), '  and the table is restored to InnoDB' );

		/*
		 * The Game Type replica is damaged LAST and not restored.
		 *
		 * `verify_game_types_schema()` also proves every stored `local_name_key`
		 * matches its row identity, and dropping that column destroys the DATA,
		 * not just the structure — re-adding it gives every row a NULL key, which
		 * the verifier correctly still rejects. Asserting "and passes again"
		 * after DDL alone would be asserting something false; restoring it
		 * properly means running the Catalog migration, which is a WRITE and has
		 * no place in a test about a read-only gate. So this case ends the group.
		 */
		$this->disposable_ddl( $hall, 'ALTER TABLE ' . \BingoCommerce\Catalog\Migrations::game_types_table() . ' DROP COLUMN local_name_key' );
		$this->same(
			'portal_sync_schema_unavailable',
			$this->error_code( $sync->assert_snapshot_schemas() ),
			'damaged Game Type replica structure FAILS CLOSED'
		);


		restore_current_blog();
	}

	/* ================================================================== *
	 * Lifecycle, validation and authorization
	 * ================================================================== */

	private function lifecycle_group( int $hall ): void {
		$this->group( 'Validation' );
		switch_to_blog( $hall );

		$template = $this->plant_template( $hall, 'Validation Bingo' );
		$package  = $this->make_package( $hall, 'valpkg' );
		$service  = new SessionScheduleService( [ $this, 'swallow_audit' ] );

		$cases = [
			'invalid_start_time'           => [ $template, 'dates', '18:00:30', [ $this->local_date( '+2 days' ) ], [ $package ] ],
			'invalid_schedule_mode'        => [ $template, 'monthly', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] ],
			'date_must_be_tomorrow_or_later' => [ $template, 'dates', '18:00', [ $this->local_date( '-1 day' ) ], [ $package ] ],
			'schedule_requires_a_package'  => [ $template, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [] ],
			'schedule_requires_a_date'     => [ $template, 'dates', '18:00', [], [ $package ] ],
			'template_unavailable'         => [ 99999999, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] ],
		];

		foreach ( $cases as $expected => $args ) {
			$result = $service->create( $args[0], $args[1], $args[2], $args[3], $args[4] );
			$this->same(
				$expected,
				is_wp_error( $result ) ? $result->get_error_code() : 'NOT-REFUSED(' . $this->describe( $result ) . ')',
				"a complete request is validated before anything is written: {$expected}"
			);
		}

		// §5.2 rejections reach the caller intact.
		$this->same(
			'nonexistent_local_time',
			$this->error_code( ScheduleTimezone::exact_instant( '2026-03-08', '02:30:00', self::ZONE ) ),
			'a spring-forward gap is refused, not normalised'
		);

		// Nothing above may have left a row: validation precedes the INSERT.
		global $wpdb;
		$this->same(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Migrations::schedules_table() . ' WHERE portal_session_id = %d',
					$template
				)
			),
			'a refused request wrote no provisional row at all'
		);

		restore_current_blog();
	}

	private function authorization_group( int $hall ): void {
		$this->group( 'Authorization' );
		switch_to_blog( $hall );

		$service = new SessionScheduleService( [ $this, 'swallow_audit' ] );

		$previous = get_current_user_id();
		wp_set_current_user( 0 );
		$this->same(
			'authentication_required',
			$this->error_code( $service->create( 1, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ 1 ] ) ),
			'a signed-out request is refused'
		);
		$this->same( 'authentication_required', $this->error_code( $service->archive( 1 ) ), 'archive is refused too' );
		$this->same( 'authentication_required', $this->error_code( $service->restore( 1 ) ), 'restore is refused too' );
		$this->same( 'authentication_required', $this->error_code( $service->get( 1 ) ), 'and so is the read' );
		$this->same(
			'authentication_required',
			$this->error_code( $service->definition_packages( 1, str_repeat( 'a', 36 ) ) ),
			'definition_packages() is a supported service read and is refused when signed out'
		);
		wp_set_current_user( $previous );

		/*
		 * A signed-in user who is not authorized for this hall. The capability is
		 * removed for the duration rather than a new account being created, so the
		 * check being exercised is `bc_manage_sessions`, not the presence of a
		 * user — and the super-admin override is removed too, or it would satisfy
		 * authorize() by itself.
		 */
		$unprivileged = $this->make_unprivileged_user( $hall );
		if ( $unprivileged > 0 ) {
			wp_set_current_user( $unprivileged );

			$this->same(
				'session_management_denied',
				$this->error_code( $service->definition_packages( 1, str_repeat( 'a', 36 ) ) ),
				'definition_packages() is refused for a signed-in user without bc_manage_sessions'
			);
			$this->same(
				'session_management_denied',
				$this->error_code( $service->get( 1 ) ),
				'  as is the schedule read'
			);
			$this->same(
				'session_management_denied',
				$this->error_code( $service->create( 1, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ 1 ] ) ),
				'  and the writer'
			);

			wp_set_current_user( $previous );
		}

		/*
		 * And the authorized path really does return data, so the two refusals
		 * above are not passing merely because the method always errors.
		 */
		$template = $this->plant_template( $hall, 'Authorized Read' );
		$package  = $this->make_package( $hall, 'authpkg' );
		$seed     = $service->create( $template, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );
		if ( $seed instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $seed->status ) {
			$this->track_schedule( (int) $seed->schedule['schedule_id'] );
			$this->same(
				[ $package ],
				$service->definition_packages( (int) $seed->schedule['schedule_id'], (string) $seed->schedule['active_definition_token'] ),
				'an authorized caller reads the definition\'s Package ids'
			);
		}

		restore_current_blog();
	}

	/**
	 * A signed-in user with no Session-management capability on this hall.
	 *
	 * ADR-025's HallStaffWriterGuard refuses `add_user_to_blog()` for hall roles,
	 * so the capability row is planted directly — the precedent
	 * `tests/hall-staff-create-invite.php` already sets. Here the point is the
	 * ABSENCE of `bc_manage_sessions`, so a bare subscriber row is planted.
	 */
	private function make_unprivileged_user( int $hall ): int {
		global $wpdb;

		$login = 'sched1-unpriv-' . wp_generate_password( 8, false );
		$user  = wp_insert_user(
			[
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => $login . '@example.invalid',
				'role'       => '',
			]
		);
		if ( is_wp_error( $user ) ) {
			$this->ok( false, 'unprivileged fixture user could not be created: ' . $user->get_error_message() );

			return 0;
		}

		$this->created_users[] = (int) $user;

		$key = $wpdb->get_blog_prefix( $hall ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => (int) $user, 'meta_key' => $key ] );
		$wpdb->insert(
			$wpdb->usermeta,
			[
				'user_id'    => (int) $user,
				'meta_key'   => $key,
				'meta_value' => serialize( [ 'subscriber' => true ] ),
			]
		);
		clean_user_cache( (int) $user );

		// Fail closed if the fixture did not actually produce an unprivileged
		// user: a test that silently ran as somebody privileged would assert
		// nothing.
		if ( user_can( (int) $user, 'bc_manage_sessions' ) || is_super_admin( (int) $user ) ) {
			$this->ok( false, 'the unprivileged fixture user unexpectedly holds Session-management rights' );

			return 0;
		}

		return (int) $user;
	}

	/* ================================================================== *
	 * Deterministic interception — the `query` filter as a barrier
	 * ================================================================== */

	/** @var callable|null */
	private $intercept_callback = null;
	private string $intercept_pattern = '';
	private bool $intercept_fired = false;
	private bool $intercept_running = false;

	/**
	 * Run `$callback` exactly once, immediately BEFORE the next statement
	 * matching `$pattern` is issued.
	 *
	 * This is the barrier §16 requires, in its `query`-filter form. The competing
	 * write happens at an exactly known point in the statement stream, so which
	 * request wins is decided by the database's own guard rather than by timing.
	 * `$intercept_running` stops the callback's own statements from re-entering.
	 */
	private function intercept_once( string $pattern, callable $callback ): void {
		$this->intercept_pattern  = $pattern;
		$this->intercept_callback = $callback;
		$this->intercept_fired    = false;
		$this->intercept_running  = false;

		add_filter( 'query', [ $this, 'observe_query' ], 1 );
	}

	/**
	 * Tear the barrier down and report whether it ACTUALLY FIRED.
	 *
	 * Every race below asserts this. A barrier whose pattern stopped matching —
	 * because a statement was reformatted, say — would make its race test pass
	 * vacuously: no competing write would ever happen, the "loser" would simply
	 * win, and the assertion that it lost would be the only thing to fail. This
	 * turns that silent failure into an explicit one.
	 */
	private function clear_intercept(): bool {
		remove_filter( 'query', [ $this, 'observe_query' ], 1 );
		$fired                    = $this->intercept_fired;
		$this->intercept_callback = null;
		$this->intercept_pattern  = '';

		return $fired;
	}

	/** @internal Public only because WordPress needs a callable. */
	public function observe_query( string $sql ): string {
		if ( $this->capturing ) {
			$this->captured_sql[] = $sql;
		}

		if (
			null !== $this->intercept_callback
			&& ! $this->intercept_fired
			&& ! $this->intercept_running
			&& '' !== $this->intercept_pattern
			&& 1 === preg_match( $this->intercept_pattern, $sql )
		) {
			$this->intercept_fired   = true;
			$this->intercept_running = true;
			try {
				call_user_func( $this->intercept_callback );
			} finally {
				$this->intercept_running = false;
			}
		}

		// The statement itself is NEVER rewritten. This filter observes and
		// interposes; it does not alter what the code under test executes.
		return $sql;
	}

	/* ------------------------------------------------------------------ *
	 * Nested barrier
	 * ------------------------------------------------------------------ */

	/** @var callable|null */
	private $nested_callback = null;
	private string $nested_pattern = '';
	private bool $nested_fired = false;
	private bool $nested_running = false;

	/**
	 * A SECOND barrier, usable while the first one's callback is running.
	 *
	 * Item 17 needs two suspension points at once: request B paused between its
	 * staging INSERTs and its CAS, and — inside that pause — request A paused
	 * between ITS staging and ITS CAS, so A can be made to fail the template
	 * guard without ever moving the token B is fenced on. One interceptor cannot
	 * express that, because its re-entry guard exists precisely to stop the
	 * callback's own statements from re-triggering it.
	 *
	 * Registered at a different priority and with its own state, so the two never
	 * consume each other's trigger.
	 */
	private function intercept_nested_once( string $pattern, callable $callback ): void {
		$this->nested_pattern  = $pattern;
		$this->nested_callback = $callback;
		$this->nested_fired    = false;
		$this->nested_running  = false;

		add_filter( 'query', [ $this, 'observe_nested_query' ], 2 );
	}

	private function clear_nested_intercept(): bool {
		remove_filter( 'query', [ $this, 'observe_nested_query' ], 2 );
		$fired                 = $this->nested_fired;
		$this->nested_callback = null;
		$this->nested_pattern  = '';

		return $fired;
	}

	/** @internal Public only because WordPress needs a callable. */
	public function observe_nested_query( string $sql ): string {
		if (
			null !== $this->nested_callback
			&& ! $this->nested_fired
			&& ! $this->nested_running
			&& '' !== $this->nested_pattern
			&& 1 === preg_match( $this->nested_pattern, $sql )
		) {
			$this->nested_fired   = true;
			$this->nested_running = true;
			try {
				call_user_func( $this->nested_callback );
			} finally {
				$this->nested_running = false;
			}
		}

		return $sql;
	}

	private function capture_start(): void {
		$this->captured_sql = [];
		$this->capturing    = true;
		add_filter( 'query', [ $this, 'observe_query' ], 1 );
	}

	private function capture_stop(): void {
		$this->capturing = false;
		remove_filter( 'query', [ $this, 'observe_query' ], 1 );
	}

	/** The single captured statement matching `$pattern`, or null. */
	private function one_captured( string $pattern ): ?string {
		foreach ( $this->captured_sql as $sql ) {
			if ( 1 === preg_match( $pattern, $sql ) ) {
				return $sql;
			}
		}

		return null;
	}

	/**
	 * Whether `active_definition_token = ` appears ONLY in the SET clause of a
	 * create-commit, which is where it legitimately belongs.
	 */
	private function only_in_set_clause( string $sql ): bool {
		$where = strpos( $sql, 'WHERE' );
		if ( false === $where ) {
			return false;
		}

		return false === strpos( substr( $sql, $where ), 'active_definition_token = ' );
	}

	/* ================================================================== *
	 * Fixtures
	 * ================================================================== */

	/**
	 * Plant a Portal Session-template replica row directly.
	 *
	 * The replica's real writer is ADR-040's snapshot transport, which needs a
	 * whole Portal sync to run. This suite is about the SCHEDULE, so the row is
	 * planted the way `tests/hall-staff-create-invite.php` plants a capability
	 * row: directly, and tracked by its exact id for cleanup. No production
	 * class is replaced, and nothing here asserts anything about the transport.
	 */
	private function plant_template( int $hall, string $name ): int {
		global $wpdb;

		// A portal_session_id this run owns. Deliberately far above anything the
		// replica suites use, and recorded by exact value.
		$portal_session_id = 900000 + count( $this->created_templates ) + 1;
		$now               = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::portal_session_templates_table()
				. ' (portal_session_id, name, portal_status, is_current, created_at, updated_at, not_current_at)'
				. " VALUES (%d, %s, 'active', 1, %s, %s, NULL)",
				$portal_session_id,
				self::PREFIX . ' ' . $name,
				$now,
				$now
			)
		);

		$this->created_templates[] = $portal_session_id;

		return $portal_session_id;
	}

	private function make_package( int $hall, string $label ): int {
		$play = new BingoPlayProductService();

		$product = $this->make_product( $label . ' pp' );
		$result  = $play->create( $product, 'paper', 2, null );
		if ( is_wp_error( $result ) ) {
			$this->ok( false, "fixture Play Product creation failed for {$label}: " . $result->get_error_code() );

			return 0;
		}

		$package = ( new PackageService() )->create(
			$this->make_product( 'pkg ' . $label ),
			[ [ 'bingo_play_product_id' => (int) $result->play_product['bingo_play_product_id'], 'quantity' => 1 ] ]
		);
		if ( is_wp_error( $package ) ) {
			$this->ok( false, "fixture Package creation failed for {$label}: " . $package->get_error_code() );

			return 0;
		}

		return (int) $package->package['package_id'];
	}

	private function make_product( string $label ): int {
		$product = new WC_Product_Simple();
		$product->set_name( self::PREFIX . ' ' . $label . ' ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}

	/** A calendar date relative to today IN THE SCHEDULE'S ZONE (§5.3). */
	private function local_date( string $modifier ): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( self::ZONE ) ) )->modify( $modifier )->format( 'Y-m-d' );
	}

	/** @return array<string,array<int,string>> dates grouped by definition token */
	private function dates_by_token( int $schedule_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT definition_token, schedule_date FROM ' . Migrations::schedule_dates_table()
				. ' WHERE schedule_id = %d ORDER BY definition_token, schedule_date',
				$schedule_id
			),
			ARRAY_A
		);

		$grouped = [];
		foreach ( (array) $rows as $row ) {
			$grouped[ (string) $row['definition_token'] ][] = (string) $row['schedule_date'];
		}

		return $grouped;
	}

	/** @return array<string,mixed> */
	private function header_snapshot( int $schedule_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT portal_session_id, timezone_name, start_time_local, mode, weekday_mask,
				        recurrence_start, recurrence_end, active_definition_token, status
				   FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d',
				$schedule_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : [];
	}

	/** How many schedule HEADER rows exist for one Portal template. */
	private function header_count( int $portal_session_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Migrations::schedules_table() . ' WHERE portal_session_id = %d',
				$portal_session_id
			)
		);
	}

	/**
	 * Structural SQL, and ONLY on a hall this run created.
	 *
	 * The gate is the same one tests/session-increment-1.php uses: a site id
	 * above the legacy range AND present in this run's own registry. A domain
	 * shape or an id range is never treated as ownership.
	 */
	private function disposable_ddl( int $site_id, string $sql ): void {
		global $wpdb;

		$this->assert_disposable_hall( $site_id );
		$wpdb->query( $sql );
	}

	/* ------------------------------------------------------------------ *
	 * Deterministic read failure
	 * ------------------------------------------------------------------ */

	private string $break_pattern = '';

	/**
	 * Make every statement matching `$pattern` fail, deterministically.
	 *
	 * Rewrites the statement to select from a table that cannot exist, so
	 * `$wpdb->last_error` is set and the read returns null — the exact shape of a
	 * genuine read failure, produced without damaging anything. No sleep, no
	 * timing, and reversible with restore_reads().
	 */
	private function break_reads_matching( string $pattern ): void {
		$this->break_pattern = $pattern;
		add_filter( 'query', [ $this, 'break_query' ], 99 );
	}

	private function restore_reads(): void {
		remove_filter( 'query', [ $this, 'break_query' ], 99 );
		$this->break_pattern = '';
	}

	/** @internal Public only because WordPress needs a callable. */
	public function break_query( string $sql ): string {
		if ( '' !== $this->break_pattern && 1 === preg_match( $this->break_pattern, $sql ) ) {
			return 'SELECT 1 FROM bc_sched1_table_that_cannot_exist';
		}

		return $sql;
	}

	private function track_schedule( int $schedule_id ): void {
		if ( $schedule_id > 0 ) {
			$this->created_schedules[] = $schedule_id;
		}
	}

	/* ================================================================== *
	 * Persistent-hall preservation
	 * ================================================================== */

	private function record_persistent_baseline(): void {
		$this->persistent_baseline = $this->persistent_snapshot();
	}

	/** @return array<string,array<string,mixed>> */
	private function persistent_snapshot(): array {
		global $wpdb;

		$snapshot = [];
		foreach ( self::PERSISTENT as $site_id ) {
			if ( ! get_site( $site_id ) instanceof WP_Site ) {
				continue;
			}
			switch_to_blog( $site_id );

			$row = [];
			foreach (
				[
					'schedules' => Migrations::schedules_table(),
					'dates'     => Migrations::schedule_dates_table(),
					'packages'  => Migrations::schedule_packages_table(),
					'sessions'  => Migrations::sessions_table(),
					'templates' => Migrations::portal_session_templates_table(),
				] as $label => $table
			) {
				$row[ $label ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
			}
			$row['schema_version'] = (int) get_option( 'bc_sessions_db_version', 0 );

			$snapshot[ 'site_' . $site_id ] = $row;
			restore_current_blog();
		}

		return $snapshot;
	}

	/* ================================================================== *
	 * Site lifecycle
	 * ================================================================== */

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'sched1' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'SCHED1 Disposable Hall',
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
			WC_Install::create_tables();

			/*
			 * Action Scheduler's own per-site tables, for the same reason
			 * tests/fixtures/wordpress-bootstrap.php creates them for the seeded
			 * halls: WC_Install does not, because on a real installation Action
			 * Scheduler registers its schema on `init`, which a CLI harness never
			 * reaches. Without them every WC_Product->save() in this suite's
			 * Package fixtures logs "table doesn't exist" — noise that reads like
			 * a defect in the code under test. register_tables() is a dbDelta
			 * wrapper and is idempotent.
			 */
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) {
				if ( class_exists( $schema ) ) {
					( new $schema() )->register_tables( true );
				}
			}
			restore_current_blog();
		}

		return (int) $site;
	}

	/** The single gate that keeps every destructive action off a persistent hall. */
	private function assert_disposable_hall( int $site_id ): void {
		if ( $site_id > 4 && ! empty( $this->disposable_sites[ $site_id ] ) ) {
			return;
		}

		fwrite(
			STDERR,
			"ABORT: refusing a destructive action on site {$site_id}.\n"
			. "Only a disposable hall created by this run may be destroyed.\n"
		);
		exit( 1 );
	}

	private function cleanup(): void {
		$this->clear_intercept();
		$this->capture_stop();

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$this->assert_disposable_hall( (int) $site_id );

			$site = get_site( (int) $site_id );
			if ( $site instanceof WP_Site ) {
				wp_delete_site( (int) $site_id );
			}
		}

		// Every schedule, date, Package and template row this suite created lived
		// on the disposable hall, whose tables are dropped with the site. Nothing
		// was written to a persistent hall, which the Preservation group proves
		// rather than assumes.
		$this->disposable_sites = [];

		/*
		 * Users are NETWORK-global, so unlike everything else they do not go away
		 * with the site. Only ids this run recorded are removed, and only by exact
		 * id — never by a login prefix or an id range.
		 */
		foreach ( $this->created_users as $user_id ) {
			if ( $user_id > 0 && get_userdata( $user_id ) instanceof WP_User ) {
				wpmu_delete_user( $user_id );
			}
		}
		$this->created_users = [];
	}

	/* ================================================================== *
	 * Reporting
	 * ================================================================== */

	/** @internal Audit is exercised by tests/session-increment-1.php; here it is noise. */
	public function swallow_audit( array $event ): int {
		return 1;
	}

	private function find_super_admin_id(): int {
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/** @param mixed $value */
	private function error_code( $value ): string {
		return is_wp_error( $value ) ? (string) $value->get_error_code() : 'NOT-AN-ERROR';
	}

	/** @param mixed $value */
	private function describe( $value ): string {
		if ( is_wp_error( $value ) ) {
			return 'WP_Error:' . $value->get_error_code();
		}
		if ( $value instanceof SessionScheduleResult ) {
			return 'Result:' . $value->status . '/' . (string) $value->failure_category;
		}

		return gettype( $value );
	}

	private function group( string $name ): void {
		$this->group = $name;
		if ( ! isset( $this->groups[ $name ] ) ) {
			$this->groups[ $name ] = [ 'pass' => 0, 'fail' => 0 ];
		}
	}

	private function note( string $message ): void {
		$this->notes[] = $message;
	}

	private function defer( string $item, string $reason ): void {
		$this->deferred[] = $item . ' — ' . $reason;
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
				"  %-38s %4d assertions   %s\n",
				$name,
				$counts['pass'] + $counts['fail'],
				0 === $counts['fail'] ? 'PASS' : 'FAIL (' . $counts['fail'] . ')'
			);
		}
		echo str_repeat( '-', 72 ), "\n";
		printf( "  %-38s %4d assertions   %s\n", 'TOTAL', $pass + $fail, 0 === $fail ? 'PASS' : 'FAIL' );

		foreach ( $this->notes as $note ) {
			echo "NOTE: {$note}\n";
		}
		foreach ( $this->deferred as $item ) {
			echo "DEFERRED: {$item}\n";
		}

		if ( $fail > 0 ) {
			exit( 1 );
		}
	}
}

$bc_test = new SessionSchedulesIncrementOneTest();
$bc_test->run();
