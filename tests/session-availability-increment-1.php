<?php

/**
 * CLI integration coverage for ADR-043 Increment 3 — guarded availability.
 *
 * Covers §16 group E (guarded availability, ownership and races, 32-42) and the
 * §6.4 sellability predicate, to the extent those items are reachable before
 * reconciliation exists. Items that are NOT reachable yet are reported by name
 * as DEFERRED and are never counted as a pass.
 *
 * ISOLATED DATABASE ONLY (ADR-043 §16 amendment). This suite creates disposable
 * Multisite SITES and applies Sessions migrations, so it must never boot the
 * development installation.
 *
 * GENERATED OCCURRENCES ARE PLANTED, NOT GENERATED. The writer that creates them
 * — OccurrenceGenerationWriter — is Increment 4 and does not exist yet, so the
 * rows are inserted directly with the exact shape §2.4 specifies: `schedule_id`,
 * `start_time_local`, `timezone_name`, `starts_at_utc` and, where the scenario
 * calls for a COMPLETE occurrence, `generation_completed_at`. This is fixture
 * planting standing in for a component under construction, the same way
 * tests/session-schedules-increment-1.php plants Portal template replica rows.
 * NO PRODUCTION CLASS IS REPLACED OR STUBBED: every availability transition and
 * every sellability answer below comes from the real
 * `SessionService::setAvailableForSale()`, `OccurrenceAvailability` and
 * `SessionEligibility`.
 *
 * CONCURRENCY IS DETERMINISTIC, NEVER TIMED. Every race is produced by a barrier
 * on WordPress's own `query` filter that fires immediately before a named
 * statement, or by an injected PackageGateway failure. No test sleeps, and no
 * winner is decided by timing. Every barrier asserts that it fired — a barrier
 * whose pattern silently stopped matching would make its race vacuous.
 *
 * HARNESS SAFETY: every scenario runs on a hall this run created, behind
 * assert_disposable_hall(); persistent halls 2/3/4 are READ ONLY and their
 * baselines are re-asserted at the end.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_test_suites php tests/session-availability-increment-1.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$bc_target = (string) getenv( 'BC_TEST_DB' );
if ( '' === $bc_target ) {
	fwrite( STDERR, "BC_TEST_DB is not set. This suite applies Sessions migrations and creates\n" );
	fwrite( STDERR, "disposable sites, so it must never run against the development database.\n" );
	fwrite( STDERR, "  BC_TEST_DB=bingocommerce_state_test_suites php tests/session-availability-increment-1.php\n" );
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
if ( ! class_exists( '\BingoCommerce\Sessions\SessionEligibility' ) ) {
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
use BingoCommerce\Sessions\OccurrenceAvailability;
use BingoCommerce\Sessions\PackageGateway;
use BingoCommerce\Sessions\SessionEligibility;
use BingoCommerce\Sessions\SessionResult;
use BingoCommerce\Sessions\SessionScheduleResult;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionService;

final class SessionAvailabilityIncrementOneTest {

	private const PERSISTENT = [ 2, 3, 4 ];
	private const PREFIX     = 'AVAIL1';
	private const ZONE       = 'America/New_York';

	private int $super_admin_id = 0;

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
	/** @var array<int,string> */
	private array $deferred = [];

	/**
	 * Active barriers, keyed by name.
	 *
	 * A LIST rather than a single slot, because several scenarios need two
	 * suspension points at once — a barrier on the guarded ON, and another on the
	 * statement that runs after it. Each entry carries its own `fired` and
	 * `running` flags, so one barrier's callback cannot consume another's
	 * trigger and no barrier can re-enter itself.
	 *
	 * @var array<string,array{pattern:string,callback:callable,nth:int,seen:int,fired:bool,running:bool}>
	 */
	private array $barriers = [];

	public function run(): void {
		$this->super_admin_id = $this->find_super_admin_id();

		$this->group( 'Setup' );
		$this->ok( $this->super_admin_id > 0, 'native Super Admin fixture exists' );
		wp_set_current_user( $this->super_admin_id );

		$this->persistent_baseline = $this->persistent_snapshot();

		add_filter( 'query', [ $this, 'observe_query' ], 1 );

		$hall = $this->create_disposable_site();
		$this->ok( $hall > 0, 'disposable hall created for availability scenarios' );
		$this->ok( ! in_array( $hall, array_merge( [ 1 ], self::PERSISTENT ), true ), '  and it is not a persistent hall' );

		switch_to_blog( $hall );
		update_option( 'timezone_string', self::ZONE );
		restore_current_blog();

		try {
			$this->lock_group( $hall );
			$this->guard_group( $hall );
			$this->compensation_group( $hall );
			$this->ownership_group( $hall );
			$this->process_death_group( $hall );
			$this->sellability_group( $hall );
			$this->mutation_ownership_group( $hall );
			$this->session_admin_group( $hall );
			$this->enable_disable_sales_group();
			$this->pagination_group();
			$this->upcoming_default_group();
			$this->legacy_group( $hall );
			$this->authorization_group( $hall );
			$this->forged_context_group( $hall );
			$this->removed_definition_group();
		} finally {
			remove_filter( 'query', [ $this, 'observe_query' ], 1 );
			$this->cleanup();
		}

		$this->group( 'Preservation' );
		$this->same( $this->persistent_baseline, $this->persistent_snapshot(), 'persistent halls 2/3/4 unchanged by this suite' );

		$this->note( '§16 E34: tests/session-reconciliation-increment-1.php asserts that removed dates cannot be rescued by ON, and withdrawal before the ON statement remains safe.' );
		$this->note( '§16 E35 (ON versus retime): PASSED in Increment 4 — tests/session-reconciliation-increment-1.php on_race_group(), ON wins against retime.' );
		$this->note( '§16 F46 (internal generation writer unreachable, by enumeration): PASSED in Increment 4 — tests/session-reconciliation-increment-1.php generation_writer_unreachable_group().' );
		$this->note( '§16 F47 (internal writer affects zero rows: completed/locked/ON): PASSED in Increment 4 — tests/session-reconciliation-increment-1.php generation_writer_unreachable_group().' );
		$this->note( '§16 F48 (internal writer affects zero rows: template ineligible): PASSED in Increment 4 — tests/session-reconciliation-increment-1.php generation_writer_unreachable_group().' );

		$this->report();
	}

	/* ================================================================== *
	 * §16 E32-E33 — write-once sale_locked_at, and the basic transitions
	 * ================================================================== */

	private function lock_group( int $hall ): void {
		$this->group( 'E32-33 sale_locked_at' );
		switch_to_blog( $hall );

		/*
		 * §16 E32, BY TOKEN-LEVEL ENUMERATION — not by trust, and this is
		 * specifically an ON-WRITER enumeration, not a general one.
		 *
		 * WHAT THIS PROVES. `available_for_sale` has writers on BOTH sides:
		 * something must be able to turn it OFF (compensate(), turn_off(),
		 * the legacy writers with a falsy value) as much as something must be
		 * able to turn it ON. Test 32 is specifically about the ON side — "every
		 * ON writer sets sale_locked_at" — so this enumeration finds every
		 * writer CAPABLE of setting the column to a truthy value, and proves
		 * each one is either the single schedule-owned ON path or one of the
		 * three legacy-only ON paths. An OFF-only writer is correctly ABSENT
		 * from this list, and a dedicated control below proves that absence is
		 * not an accident of the detectors' design.
		 *
		 * THE DEFECT THIS ONCE HAD, TWICE. The original version of this
		 * assertion contained literal ASCII BACKSPACE bytes (0x08) where a
		 * regex word boundary was intended — a string-escaping accident, not a
		 * deliberate choice, and the affected `preg_match_all()` call always
		 * returned 0 no matter what the source contained: it could never fail,
		 * on the real source or a mutated one. The SAME accident then recurred
		 * in this file's own descriptive prose one revision later, this time
		 * from a shell `echo` builtin that interprets that two-character
		 * escape by default. Both instances are gone from this file now, and
		 * the word-boundary escape is avoided entirely in comments describing
		 * it — this paragraph says "regex word boundary" rather than typing
		 * the two characters that keep re-triggering the bug.
		 *
		 * Replacing the corrupted bytes with the correct escape would not have
		 * been enough on its own, either time: a whole-file regex over RAW
		 * SOURCE TEXT also matches inside comments and docblocks, so a stray
		 * mention of `available_for_sale = 1` in a comment — this very
		 * docblock, for instance — would silently inflate the count.
		 * `enumerate_available_for_sale_on_writers()` below tokenizes every
		 * file with `token_get_all()` and discards T_COMMENT/T_DOC_COMMENT
		 * tokens before anything is scanned, so comment text can never
		 * contribute.
		 *
		 * WHAT IS SCANNED. Every `.php` file under the Sessions plugin's own
		 * root — `wp-content/plugins/bingocommerce-sessions/` — RECURSIVELY,
		 * including the plugin bootstrap file at the plugin root and any
		 * subdirectory a later increment adds, not merely the two files this
		 * ADR names and not merely `src/`. Three independent, token-level
		 * detectors, each keyed to a distinct PHP construct a real ON writer
		 * must use:
		 *
		 *   1. SQL UNCONDITIONAL WRITE — a string-literal token sequence
		 *      containing `available_for_sale = 1` whose nearest preceding
		 *      word is NOT `AND` (a `WHERE`/`JOIN` guard reads
		 *      `AND available_for_sale = 1`; a write never does).
		 *   2. SQL CONDITIONAL WRITE — a function that (a) calls
		 *      `$wpdb->query(`, (b) has a string-literal blob mentioning both
		 *      `available_for_sale` and a `%d`/`%s` placeholder ADJACENT to it
		 *      (directly for UPDATE-style SQL, positionally for INSERT-style
		 *      SQL, where the column and its placeholder sit in two separate
		 *      parenthesized lists), and (c) contains the CODE-level token
		 *      sequence `$available… ? 1 : 0` bound to a variable whose name
		 *      contains "available".
		 *   3. ARRAY-LITERAL WRITE — the exact string token
		 *      `'available_for_sale'` immediately followed by `=>` (PHP's
		 *      array-literal key syntax, not `$row['available_for_sale']`
		 *      index access, which is a different token shape entirely),
		 *      inside a function that ALSO references `$wpdb` or calls
		 *      `apply_field_change(` — which is what correctly excludes
		 *      `audit_payload()`'s identically-shaped array (no `$wpdb`, no
		 *      `apply_field_change()` — it only ever builds a log payload) and
		 *      `Migrations::verify_sessions_schema()`'s schema-assertion array
		 *      (same shape again, same absence of a write call in that
		 *      function).
		 *
		 * Each candidate is recorded as `[path, method, kind]` — a path
		 * RELATIVE to the plugin root, not merely a basename, so two files of
		 * the same name in different subdirectories could never be confused —
		 * not merely counted, so the assertions below can name exactly where a
		 * writer lives, not just how many exist.
		 */
		$writers = $this->enumerate_available_for_sale_on_writers();

		$expected = [
			[ 'path' => 'src/OccurrenceAvailability.php', 'method' => 'guarded_on', 'kind' => 'sql_unconditional', 'owner' => 'schedule_owned' ],
			[ 'path' => 'src/SessionService.php', 'method' => 'create', 'kind' => 'sql_conditional', 'owner' => 'legacy' ],
			[ 'path' => 'src/SessionService.php', 'method' => 'updateDetails', 'kind' => 'sql_conditional', 'owner' => 'legacy' ],
			[ 'path' => 'src/SessionService.php', 'method' => 'setAvailableForSale', 'kind' => 'array_literal', 'owner' => 'legacy' ],
		];

		$found_keys    = [];
		$expected_keys = [];
		foreach ( $writers as $w ) {
			$found_keys[] = $w['path'] . '::' . $w['method'] . '::' . $w['kind'];
		}
		foreach ( $expected as $w ) {
			$expected_keys[] = $w['path'] . '::' . $w['method'] . '::' . $w['kind'];
		}
		sort( $found_keys );
		sort( $expected_keys );

		$this->same( 4, count( $writers ), 'E32: the token-level scan finds exactly FOUR available_for_sale ON writers across the whole plugin, scanned recursively' );
		$this->same( $expected_keys, $found_keys, 'E32: and they are the EXACT allowlisted (path, method, kind) triples — none missing, none extra, none moved' );

		$schedule_owned = array_values( array_filter( $writers, static fn( array $w ): bool => 'schedule_owned' === $w['owner'] ) );
		$legacy         = array_values( array_filter( $writers, static fn( array $w ): bool => 'legacy' === $w['owner'] ) );

		$this->same( 1, count( $schedule_owned ), 'E32: exactly ONE ON writer is reachable for a schedule-owned row' );
		if ( 1 === count( $schedule_owned ) ) {
			$this->same( 'src/OccurrenceAvailability.php', $schedule_owned[0]['path'], 'E32: and it lives in OccurrenceAvailability.php' );
			$this->same( 'guarded_on', $schedule_owned[0]['method'], 'E32: specifically guarded_on() — proving it is the ONLY schedule-owned ON writer' );
		}
		$this->same( 3, count( $legacy ), 'E32: exactly THREE ON writers remain, all legacy-only (create, updateDetails, setAvailableForSale)' );

		/*
		 * CONTROL: an OFF-only writer is NOT misclassified as ON-capable.
		 * `OccurrenceAvailability::compensate()` and `::turn_off()` both write
		 * `available_for_sale = 0` unconditionally, never `= 1`, so neither may
		 * appear in the ON-writer list above. Asserted explicitly — proving the
		 * absence is a property of what these methods actually contain, not an
		 * accident of what the detectors happen to look for.
		 */
		$off_only_methods = [ 'compensate', 'turn_off' ];
		foreach ( $off_only_methods as $off_method ) {
			$present = array_filter(
				$writers,
				static fn( array $w ): bool => 'src/OccurrenceAvailability.php' === $w['path'] && $off_method === $w['method']
			);
			$this->same( [], array_values( $present ), "E32 control: the OFF-only writer {$off_method}() is correctly ABSENT from the ON-writer enumeration" );
		}
		// And directly, from the source: both really do write only `= 0`, never `= 1`.
		$availability_source = (string) file_get_contents(
			dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/OccurrenceAvailability.php'
		);
		$this->ok(
			false !== strpos( $availability_source, 'available_for_sale           = 0,' )
				|| false !== strpos( $availability_source, 'available_for_sale = 0' ),
			'E32 control: OccurrenceAvailability.php does contain an available_for_sale = 0 write — the control is exercising a REAL OFF writer, not a name that matches nothing'
		);

		$this->note( 'E32 enumeration: ' . count( $writers ) . ' ON writers found by recursive token scan — ' . implode( '; ', $found_keys ) );

		/*
		 * The harness itself, and every file just scanned, are checked for
		 * prohibited C0 control characters — the exact defect class this
		 * correction exists to prevent from recurring silently. TAB (0x09), LF
		 * (0x0A) and CR (0x0D) are the only C0 bytes ordinary source text or
		 * this repository's line endings ever legitimately contain.
		 */
		$scanned_files = array_merge( [ __FILE__ ], $this->recursive_php_files( $this->sessions_plugin_root() ) );
		$control_offenders = [];
		foreach ( $scanned_files as $path ) {
			$offense = $this->find_prohibited_control_byte( $path );
			if ( null !== $offense ) {
				$control_offenders[] = basename( $path ) . '@byte ' . $offense['offset'] . ' (0x' . $offense['hex'] . ')';
			}
		}
		$this->same(
			[],
			$control_offenders,
			'no prohibited C0 control byte (anything outside TAB/LF/CR) exists in the harness or any recursively scanned production source'
		);

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Lock Bingo' );
		$session_id = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Lock Occurrence', true, $package_id );
		$service    = new SessionService( [ $this, 'swallow_audit' ] );

		$before = $this->row( $session_id );
		$this->same( null, $before['sale_locked_at'], 'a freshly generated occurrence carries no sale lock' );
		$this->same( null, $before['availability_operation_token'], '  and no availability operation token' );

		$on = $service->setAvailableForSale( $session_id, true );
		$this->ok( $on instanceof SessionResult, 'the first ON succeeds: ' . $this->describe( $on ) );

		$after = $this->row( $session_id );
		$this->same( 1, (int) $after['available_for_sale'], 'E32: the occurrence is ON' );
		$this->ok( null !== $after['sale_locked_at'], 'E32: the ON writer set sale_locked_at' );
		$this->same( null, $after['availability_operation_token'], 'E41: a settled ON leaves NO operation token' );

		$locked_at = (string) $after['sale_locked_at'];

		$off = $service->setAvailableForSale( $session_id, false );
		$this->ok( $off instanceof SessionResult, 'OFF succeeds' );
		$after_off = $this->row( $session_id );
		$this->same( 0, (int) $after_off['available_for_sale'], 'the occurrence is OFF' );
		$this->same( $locked_at, (string) $after_off['sale_locked_at'], 'E33: OFF does NOT clear sale_locked_at' );

		/*
		 * The lock is pinned to a DISTINGUISHABLE earlier value before the second
		 * ON. Re-running ON within the same wall-clock second would otherwise
		 * produce an identical UTC_TIMESTAMP(), and the assertion would pass just
		 * as readily with the COALESCE removed — proved by mutation: replacing
		 * COALESCE(s.sale_locked_at, UTC_TIMESTAMP()) with a bare UTC_TIMESTAMP()
		 * went undetected until this pin was added. Pinning it makes the
		 * statement's write-once behaviour the only thing that can hold the
		 * assertion up.
		 */
		global $wpdb;
		$pinned = '2020-01-02 03:04:05';
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::sessions_table() . ' SET sale_locked_at = %s WHERE session_id = %d',
				$pinned,
				$session_id
			)
		);

		$service->setAvailableForSale( $session_id, true );
		$after_second = $this->row( $session_id );
		$this->same( 1, (int) $after_second['available_for_sale'], 'the second ON succeeds' );
		$this->same(
			$pinned,
			(string) $after_second['sale_locked_at'],
			'E33: a second ON does NOT overwrite an EXISTING lock — COALESCE makes it write-once'
		);

		// The lock is server-side, inside the guarded statement, so it cannot be
		// skewed by a PHP timestamp computed before the race resolved.
		$this->ok(
			1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $locked_at ),
			'the lock is a server-side UTC timestamp'
		);

		$service->setAvailableForSale( $session_id, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * §16 E36-E37 — what the guarded statement refuses
	 * ================================================================== */

	private function guard_group( int $hall ): void {
		global $wpdb;

		$this->group( 'E36-37 guarded ON' );
		switch_to_blog( $hall );

		$service = new SessionService( [ $this, 'swallow_audit' ] );

		/* --- E36: an INCOMPLETE occurrence cannot be turned ON. --- */
		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Incomplete Bingo' );
		$incomplete = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Incomplete Occurrence', false, $package_id );

		$result = $service->setAvailableForSale( $incomplete, true );
		$this->same(
			'occurrence_generation_incomplete',
			$this->error_code( $result ),
			'E36: an occurrence with generation_completed_at IS NULL cannot be turned ON'
		);
		$row = $this->row( $incomplete );
		$this->same( 0, (int) $row['available_for_sale'], '  and it stays OFF' );
		$this->same( null, $row['sale_locked_at'], '  with no sale lock' );
		$this->same( null, $row['availability_operation_token'], '  and NO token written' );

		/* --- E37: the schedule is ARCHIVED between the pre-proof and the ON. --- */
		[ $sched_a, $pkg_a ] = $this->make_schedule( $hall, 'Archived Mid Bingo' );
		$session_a = $this->plant_occurrence( $hall, $sched_a, $this->local_date( '+2 days' ), 'Archived Mid Occurrence', true, $pkg_a );

		$this->barrier(
			'archive-schedule',
			'/UPDATE \S+bingo_sessions s\s+JOIN/',
			function () use ( $wpdb, $sched_a ): void {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . Migrations::schedules_table() . " SET status = 'archived' WHERE schedule_id = %d",
						$sched_a
					)
				);
			}
		);
		$result_a = $service->setAvailableForSale( $session_a, true );
		$this->assert_barrier_fired( 'archive-schedule', 'E37: the barrier fired — the schedule really was archived mid-request' );

		$this->same( 'schedule_state_changed', $this->error_code( $result_a ), 'E37: an archived schedule makes the guarded ON affect zero rows' );
		$row_a = $this->row( $session_a );
		$this->same( 0, (int) $row_a['available_for_sale'], '  the occurrence stays OFF' );
		$this->same( null, $row_a['availability_operation_token'], '  and NO token is written' );
		$this->same( null, $row_a['sale_locked_at'], '  and no sale lock is taken' );

		/* --- E37: the TEMPLATE is invalidated between the pre-proof and the ON. --- */
		foreach (
			[
				'non-current' => [ 'is_current', 0 ],
				'draft'       => [ 'portal_status', 'draft' ],
			] as $label => $invalidation
		) {
			[ $sched_b, $pkg_b, $template_b ] = $this->make_schedule( $hall, 'Template Mid ' . $label );
			$session_b = $this->plant_occurrence( $hall, $sched_b, $this->local_date( '+2 days' ), 'Template Mid ' . $label, true, $pkg_b );

			$this->barrier(
				'invalidate-template',
				'/UPDATE \S+bingo_sessions s\s+JOIN/',
				function () use ( $wpdb, $template_b, $invalidation ): void {
					$wpdb->update(
						Migrations::portal_session_templates_table(),
						[ $invalidation[0] => $invalidation[1] ],
						[ 'portal_session_id' => $template_b ]
					);
				}
			);
			$result_b = $service->setAvailableForSale( $session_b, true );
			$this->assert_barrier_fired( 'invalidate-template', "E37: the barrier fired for a {$label} template" );

			$this->same(
				'template_not_commerce_eligible',
				$this->error_code( $result_b ),
				"E37: a {$label} template makes the guarded ON affect zero rows, named exactly"
			);
			$row_b = $this->row( $session_b );
			$this->same( 0, (int) $row_b['available_for_sale'], "  the occurrence stays OFF ({$label})" );
			$this->same( null, $row_b['availability_operation_token'], "  and NO token is written ({$label})" );
		}

		/* --- the ON guard `available_for_sale = 0`: exactly ONE request can win
		 * the OFF -> ON transition.
		 *
		 * Driven through OccurrenceAvailability directly, because
		 * SessionService::setAvailableForSale() short-circuits with
		 * `session_availability_unchanged` before the statement is reached when
		 * the row is already ON — so the service path cannot exercise this guard
		 * at all. Without the guard a second ON would re-stamp a NEW token over a
		 * transition somebody else owns. */
		[ $sched_w, $pkg_w ] = $this->make_schedule( $hall, 'Win Once Bingo' );
		$session_w = $this->plant_occurrence( $hall, $sched_w, $this->local_date( '+2 days' ), 'Win Once Occurrence', true, $pkg_w );

		$service->setAvailableForSale( $session_w, true );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::sessions_table() . ' SET availability_operation_token = %s WHERE session_id = %d',
				'11111111-2222-3333-4444-555555555555',
				$session_w
			)
		);

		$owned_by = (string) $this->row( $session_w )['availability_operation_token'];
		$second   = ( new OccurrenceAvailability() )->turn_on(
			$session_w,
			$this->row( $session_w ),
			[ 'blog_id' => get_current_blog_id(), 'actor_user_id' => $this->super_admin_id ]
		);

		$this->ok( $second instanceof SessionResult, 'a second ON on an already-ON occurrence is a verified no-op, not a failure' );
		$this->same(
			'session_availability_unchanged',
			$second instanceof SessionResult ? $second->status : '',
			'  reported as unchanged'
		);
		$this->same(
			$owned_by,
			(string) $this->row( $session_w )['availability_operation_token'],
			'the `available_for_sale = 0` guard means a second ON CANNOT re-stamp the owner\'s token'
		);
		$service->setAvailableForSale( $session_w, false );

		/* --- the ON guard on the EXACT active definition token.
		 *
		 * The schedule's active token is advanced immediately before the guarded
		 * UPDATE, so the definition the request proved at read time is no longer
		 * the live one. The ON must affect zero rows: selling against a
		 * superseded definition is exactly what this fence exists to stop. */
		[ $sched_t, $pkg_t ] = $this->make_schedule( $hall, 'Token Moved Bingo' );
		$session_t = $this->plant_occurrence( $hall, $sched_t, $this->local_date( '+2 days' ), 'Token Moved Occurrence', true, $pkg_t );

		$this->barrier(
			'advance-definition',
			'/UPDATE \S+bingo_sessions s\s+JOIN/',
			function () use ( $wpdb, $sched_t ): void {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . Migrations::schedules_table() . ' SET active_definition_token = %s WHERE schedule_id = %d',
						strtolower( wp_generate_uuid4() ),
						$sched_t
					)
				);
			}
		);
		$result_t = $service->setAvailableForSale( $session_t, true );
		$this->assert_barrier_fired( 'advance-definition', 'the barrier fired — the active definition token really moved mid-request' );

		$this->same(
			'schedule_state_changed',
			$this->error_code( $result_t ),
			'a definition token that changed between read and statement makes the guarded ON affect zero rows'
		);
		$row_t = $this->row( $session_t );
		$this->same( 0, (int) $row_t['available_for_sale'], '  the occurrence stays OFF' );
		$this->same( null, $row_t['availability_operation_token'], '  and NO token is written' );
		$this->same( null, $row_t['sale_locked_at'], '  and no sale lock is taken against a superseded definition' );

		/* --- the pre-proof itself: no assignable Package at all. --- */
		[ $sched_c, $pkg_c ] = $this->make_schedule( $hall, 'No Package Bingo' );
		$session_c = $this->plant_occurrence( $hall, $sched_c, $this->local_date( '+2 days' ), 'No Package Occurrence', true, 0 );
		$result_c  = $service->setAvailableForSale( $session_c, true );
		$this->same(
			'occurrence_has_no_package',
			$this->error_code( $result_c ),
			'an occurrence with no assigned Package is refused before any write'
		);
		$this->same( null, $this->row( $session_c )['availability_operation_token'], '  and nothing is written' );
		unset( $pkg_c );

		restore_current_blog();
	}

	/* ================================================================== *
	 * §16 E38-E39 — compensation, and the ownership fence on it
	 * ================================================================== */

	private function compensation_group( int $hall ): void {
		global $wpdb;

		$this->group( 'E38-39 compensation' );
		switch_to_blog( $hall );

		$service = new SessionService( [ $this, 'swallow_audit' ] );

		/* --- E38: the Package is invalidated BETWEEN the ON update and the
		 * post-proof. The occurrence must be forced OFF by its OWN token, the
		 * result must be a failure, and sale_locked_at must REMAIN SET — the
		 * occurrence genuinely was on sale for the interval between the two
		 * statements, and the lock is the durable record of that. */
		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Compensate Bingo' );
		$session_id = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Compensate Occurrence', true, $package_id );

		$this->barrier(
			'archive-package',
			'/UPDATE \S+bingo_sessions s\s+JOIN/',
			function () use ( $package_id ): void {
				// A REAL invalidation through the Catalog's own service, not an
				// injected error: this is the state §6.2 is written for.
				( new PackageService() )->archive( $package_id );
			}
		);
		$result = $service->setAvailableForSale( $session_id, true );
		$this->assert_barrier_fired( 'archive-package', 'E38: the barrier fired — the Package was archived between ON and the post-proof' );

		$this->same( 'package_became_unassignable', $this->error_code( $result ), 'E38: the request FAILS when the post-proof fails' );
		$this->same(
			true,
			(bool) ( is_wp_error( $result ) ? $result->get_error_data()['compensated'] ?? false : false ),
			'E38: and it compensated its own transition'
		);

		$row = $this->row( $session_id );
		$this->same( 0, (int) $row['available_for_sale'], 'E38: the occurrence was forced back OFF' );
		$this->same( null, $row['availability_operation_token'], 'E38: and its operation token was cleared' );
		$this->ok( null !== $row['sale_locked_at'], 'E38: sale_locked_at REMAINS SET — compensation never clears it' );

		( new PackageService() )->restore( $package_id );

		/* --- E39: compensation cannot touch a row it does not own.
		 *
		 * The token is changed between the ON update and the compensating UPDATE,
		 * so the fence no longer matches. Compensation must affect ZERO rows and
		 * the request must write nothing further. */
		[ $sched_b, $pkg_b ] = $this->make_schedule( $hall, 'Unowned Bingo' );
		$session_b = $this->plant_occurrence( $hall, $sched_b, $this->local_date( '+2 days' ), 'Unowned Occurrence', true, $pkg_b );

		$foreign_token = strtolower( wp_generate_uuid4() );

		$this->barrier(
			'break-post-proof',
			'/UPDATE \S+bingo_sessions s\s+JOIN/',
			function () use ( $pkg_b ): void {
				( new PackageService() )->archive( $pkg_b );
			}
		);
		$this->barrier(
			'steal-ownership',
			'/UPDATE \S+bingo_sessions\s+SET available_for_sale\s+= 0,[\s\S]*AND schedule_id/',
			function () use ( $wpdb, $session_b, $foreign_token ): void {
				// A different request has taken ownership: the occurrence is still
				// ON, but the token on the row is no longer the compensating
				// request's.
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . Migrations::sessions_table() . ' SET availability_operation_token = %s WHERE session_id = %d',
						$foreign_token,
						$session_b
					)
				);
			}
		);

		$result_b = $service->setAvailableForSale( $session_b, true );
		$this->assert_barrier_fired( 'break-post-proof', 'E39: the post-proof barrier fired' );
		$this->assert_barrier_fired( 'steal-ownership', 'E39: the ownership-change barrier fired immediately before the compensating UPDATE' );

		$this->same( 'package_became_unassignable', $this->error_code( $result_b ), 'E39: the request still reports the failure' );
		$this->same(
			false,
			(bool) ( is_wp_error( $result_b ) ? $result_b->get_error_data()['compensated'] ?? true : true ),
			'E39: but it reports that it did NOT compensate'
		);

		$row_b = $this->row( $session_b );
		$this->same( 1, (int) $row_b['available_for_sale'], 'E39: the occurrence it does not own is still ON' );
		$this->same( $foreign_token, (string) $row_b['availability_operation_token'], 'E39: still carrying the OTHER request\'s token' );
		$this->ok( null !== $row_b['sale_locked_at'], 'E39: and still locked' );

		/*
		 * Item 2. THE RE-READ ACTUALLY OCCURRED, proved by its RESULT, not by
		 * trusting the source. `compensated: false` alone does not distinguish
		 * "we re-read and found another owner" from "we assumed it and never
		 * looked" — the presence of `observed_available_for_sale` and
		 * `observed_status`, matching the row's REAL current values, is proof a
		 * read happened and its answer was reported.
		 */
		$data = is_wp_error( $result_b ) ? $result_b->get_error_data() : [];
		$this->ok( array_key_exists( 'observed_available_for_sale', $data ), 'item 2: the re-read\'s observed_available_for_sale is present in the error data' );
		$this->ok( array_key_exists( 'observed_status', $data ), 'item 2: and observed_status is present' );
		$this->same( true, $data['observed_available_for_sale'] ?? null, 'item 2: observed_available_for_sale matches the row\'s ACTUAL state (still ON)' );
		$this->same( 'active', $data['observed_status'] ?? null, 'item 2: observed_status matches too' );
		$this->ok( ! array_key_exists( 'availability_operation_token', $data ), 'item 2: the OTHER request\'s token is NEVER exposed in the compensation result' );
		$this->ok(
			false === strpos( (string) json_encode( $data ), $foreign_token ),
			'item 2: and the foreign token string does not appear anywhere in the reported data'
		);

		( new PackageService() )->restore( $pkg_b );

		/*
		 * Item 2. The newer owner's row is BYTE-IDENTICAL before and after the
		 * older request's entire failed attempt — not merely "still ON", but
		 * every column, captured as a snapshot before A's post-proof runs and
		 * compared after A's compensation attempt completes.
		 */
		[ $sched_c, $pkg_c ] = $this->make_schedule( $hall, 'Identical Row Bingo' );
		$session_c = $this->plant_occurrence( $hall, $sched_c, $this->local_date( '+2 days' ), 'Identical Row Occurrence', true, $pkg_c );
		$rival_token = strtolower( wp_generate_uuid4() );

		$this->barrier(
			'break-post-proof-identical',
			'/UPDATE \S+bingo_sessions s\s+JOIN/',
			function () use ( $pkg_c ): void {
				( new PackageService() )->archive( $pkg_c );
			}
		);
		$snapshot_before = null;
		$this->barrier(
			'steal-ownership-identical',
			'/UPDATE \S+bingo_sessions\s+SET available_for_sale\s+= 0,[\s\S]*AND schedule_id/',
			function () use ( $wpdb, $session_c, $rival_token, &$snapshot_before ): void {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . Migrations::sessions_table() . ' SET availability_operation_token = %s WHERE session_id = %d',
						$rival_token,
						$session_c
					)
				);
				// The FULL row, captured at the instant ownership changes —
				// before the older request's compensating UPDATE is even
				// attempted.
				$snapshot_before = $this->row( $session_c );
			}
		);

		$result_c = $service->setAvailableForSale( $session_c, true );
		$this->assert_barrier_fired( 'break-post-proof-identical', 'item 2: the post-proof barrier fired' );
		$this->assert_barrier_fired( 'steal-ownership-identical', 'item 2: the ownership-change barrier fired' );
		$this->ok( is_wp_error( $result_c ), 'item 2: the older request still reports its own failure' );

		$snapshot_after = $this->row( $session_c );
		$this->same( $snapshot_before, $snapshot_after, 'item 2: the newer owner\'s row is BYTE-IDENTICAL before and after the older request\'s compensation attempt' );

		( new PackageService() )->restore( $pkg_c );

		/*
		 * Item 2. A FAILED re-read propagates as persistence_failed, not as a
		 * lost race. The compensating UPDATE's own table name is broken at the
		 * exact moment the re-read would run — after the UPDATE affected zero
		 * rows, before compensate() decides what happened.
		 */
		[ $sched_d, $pkg_d ] = $this->make_schedule( $hall, 'ReadFail Bingo' );
		$session_d = $this->plant_occurrence( $hall, $sched_d, $this->local_date( '+2 days' ), 'ReadFail Occurrence', true, $pkg_d );

		$this->barrier(
			'break-post-proof-readfail',
			'/UPDATE \S+bingo_sessions s\s+JOIN/',
			function () use ( $pkg_d ): void {
				( new PackageService() )->archive( $pkg_d );
			}
		);
		$this->barrier(
			'steal-then-break-read',
			'/UPDATE \S+bingo_sessions\s+SET available_for_sale\s+= 0,[\s\S]*AND schedule_id/',
			function () use ( $wpdb, $session_d ): void {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . Migrations::sessions_table() . ' SET availability_operation_token = %s WHERE session_id = %d',
						strtolower( wp_generate_uuid4() ),
						$session_d
					)
				);
				// Break the SPECIFIC re-read compensate() is about to issue.
				$this->break_reads( '/SELECT session_id, session_date, display_name, available_for_sale, status, schedule_id/' );
			}
		);

		$result_d = $service->setAvailableForSale( $session_d, true );
		$this->assert_barrier_fired( 'break-post-proof-readfail', 'item 2: the post-proof barrier fired' );
		$this->assert_barrier_fired( 'steal-then-break-read', 'item 2: the ownership-change-and-break barrier fired' );
		$this->restore_reads();

		$this->same( 'persistence_failed', $this->error_code( $result_d ), 'item 2: a FAILED compensation re-read propagates as persistence_failed' );
		$this->ok(
			! ( is_wp_error( $result_d ) && 'package_became_unassignable' === $result_d->get_error_code() ),
			'item 2: and is NEVER reported as package_became_unassignable / a lost race'
		);

		( new PackageService() )->restore( $pkg_d );

		/*
		 * Item 2. session_not_found when the occurrence genuinely disappeared
		 * between the compensating UPDATE and the re-read. Simulated the only
		 * honest way available in a single-process harness: the row is deleted
		 * at the exact instant ownership would have been stolen, so the
		 * compensating UPDATE affects zero rows because the row is gone, and the
		 * re-read that follows finds nothing.
		 */
		[ $sched_e, $pkg_e ] = $this->make_schedule( $hall, 'Vanish Bingo' );
		$session_e = $this->plant_occurrence( $hall, $sched_e, $this->local_date( '+2 days' ), 'Vanish Occurrence', true, $pkg_e );

		$this->barrier(
			'break-post-proof-vanish',
			'/UPDATE \S+bingo_sessions s\s+JOIN/',
			function () use ( $pkg_e ): void {
				( new PackageService() )->archive( $pkg_e );
			}
		);
		$this->barrier(
			'vanish-before-compensate',
			'/UPDATE \S+bingo_sessions\s+SET available_for_sale\s+= 0,[\s\S]*AND schedule_id/',
			function () use ( $hall, $wpdb, $session_e ): void {
				$this->assert_disposable_hall( $hall );
				$wpdb->delete( Migrations::session_packages_table(), [ 'session_id' => $session_e ] );
				$wpdb->delete( Migrations::sessions_table(), [ 'session_id' => $session_e ] );
			}
		);

		$result_e = $service->setAvailableForSale( $session_e, true );
		$this->assert_barrier_fired( 'break-post-proof-vanish', 'item 2: the post-proof barrier fired' );
		$this->assert_barrier_fired( 'vanish-before-compensate', 'item 2: the vanish barrier fired' );

		$this->same( 'session_not_found', $this->error_code( $result_e ), 'item 2: the re-read reports session_not_found when the occurrence is genuinely gone' );

		( new PackageService() )->restore( $pkg_e );

		restore_current_blog();
	}

	/* ================================================================== *
	 * §16 E40-E41 — three-request ownership, settlement, and ON versus OFF
	 * ================================================================== */

	private function ownership_group( int $hall ): void {
		global $wpdb;

		$this->group( 'E40-41 ownership' );
		switch_to_blog( $hall );

		/* --- E40: the three-request A/B/C race.
		 *
		 * A turns ON (token Ta) and pauses before its failed post-proof.
		 * B turns it OFF. C turns it ON again (token Tc). A resumes and
		 * compensates. The occurrence must still be ON, must still carry Tc, and
		 * A must have changed NOTHING.
		 *
		 * A's post-proof is broken by failing its Package READ at the SQL
		 * boundary — an injected failure at an exact call boundary, scoped to the
		 * moment B and C have finished, so that B and C themselves see a healthy
		 * Catalog and C's own ON can legitimately succeed. */
		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'ABC Bingo' );
		$session_id = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'ABC Occurrence', true, $package_id );

		$a = new SessionService( [ $this, 'swallow_audit' ] );
		$b = new SessionService( [ $this, 'swallow_audit' ] );
		$c = new SessionService( [ $this, 'swallow_audit' ] );

		$b_result   = null;
		$c_result   = null;
		$token_a    = '';
		$token_c    = '';
		$c_killed   = false;
		$c_barrier  = false;

		$this->barrier(
			'abc-pause',
			'/SELECT package_id FROM \S+bingo_session_packages/',
			function () use ( $wpdb, $session_id, $b, $c, &$b_result, &$c_result, &$token_a, &$token_c, &$c_killed, &$c_barrier ): void {
				// A has committed its guarded ON and has not yet re-proved.
				$token_a = (string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT availability_operation_token FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d',
						$session_id
					)
				);

				$b_result = $b->setAvailableForSale( $session_id, false );

				/*
				 * C is stopped before ITS settlement, so the row still CARRIES Tc
				 * when A resumes. That is the case §16 test 40 actually names, and
				 * it is the strict one: a NULL token would let A's fence fail for
				 * the trivial reason that there is nothing to match, whereas a
				 * different NON-NULL token proves the fence discriminates by
				 * OWNER. It is also the §6.5 unsettled state, reached honestly.
				 */
				$this->barrier(
					'kill-c-before-settle',
					'/SET\s+availability_operation_token = NULL/',
					static function (): void {
						throw new RuntimeException( 'simulated death of request C between its ON and settlement' );
					}
				);

				try {
					$c_result = $c->setAvailableForSale( $session_id, true );
				} catch ( RuntimeException $e ) {
					$c_killed = true;
				}

				$c_barrier = ! empty( $this->barriers['kill-c-before-settle']['fired'] );
				unset( $this->barriers['kill-c-before-settle'] );

				$token_c = (string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT availability_operation_token FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d',
						$session_id
					)
				);

				// Now break A's post-proof, and only A's: B and C have finished.
				$this->break_reads( '/SELECT package_id FROM \S+bingo_session_packages/' );
			},
			2 // the SECOND package read of A's request: its post-proof.
		);

		$a_result = $a->setAvailableForSale( $session_id, true );
		$this->assert_barrier_fired( 'abc-pause', 'E40: the barrier fired — A really paused between its ON and its post-proof' );
		$this->restore_reads();

		$this->ok( '' !== $token_a, 'E40: A committed its ON and stamped token Ta' );
		$this->ok( $b_result instanceof SessionResult, 'E40: B turned the occurrence OFF' );
		$this->ok( $c_barrier, 'E40: the nested barrier fired — C really was stopped before its settlement' );
		$this->ok( $c_killed, 'E40: C committed its ON and then died, leaving Tc unsettled' );
		$this->ok( is_wp_error( $a_result ), 'E40: A reports a failure: ' . $this->describe( $a_result ) );

		$this->ok( '' !== $token_c, 'E40: the row carries a NON-NULL token after C' );
		$this->ok( $token_c !== $token_a, 'E40: and it is Tc, not Ta' );

		$final = $this->row( $session_id );

		$this->same( 1, (int) $final['available_for_sale'], 'E40: the occurrence is STILL ON — A did not turn it off' );
		$this->same( $token_c, (string) $final['availability_operation_token'], 'E40: and STILL CARRIES Tc — A changed nothing' );
		$this->ok( null !== $final['sale_locked_at'], 'E40: and is still locked' );
		$this->same(
			false,
			(bool) ( is_wp_error( $a_result ) ? $a_result->get_error_data()['compensated'] ?? true : true ),
			'E40: A reports that it compensated NOTHING'
		);

		$this->note( sprintf( 'E40: Ta=%s… superseded by Tc=%s…, which survived A\'s compensation attempt', substr( $token_a, 0, 8 ), substr( $token_c, 0, 8 ) ) );
		unset( $c_result );

		( new SessionService( [ $this, 'swallow_audit' ] ) )->setAvailableForSale( $session_id, false );

		/* --- E41: settlement clears ONLY the request's own token, and zero rows
		 * there is not an error. */
		[ $sched_s, $pkg_s ] = $this->make_schedule( $hall, 'Settle Bingo' );
		$session_s = $this->plant_occurrence( $hall, $sched_s, $this->local_date( '+2 days' ), 'Settle Occurrence', true, $pkg_s );
		$stolen    = strtolower( wp_generate_uuid4() );

		$this->barrier(
			'steal-before-settle',
			'/SET\s+availability_operation_token = NULL/',
			function () use ( $wpdb, $session_s, $stolen ): void {
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . Migrations::sessions_table() . ' SET availability_operation_token = %s WHERE session_id = %d',
						$stolen,
						$session_s
					)
				);
			}
		);
		$settled = ( new SessionService( [ $this, 'swallow_audit' ] ) )->setAvailableForSale( $session_s, true );
		$this->assert_barrier_fired( 'steal-before-settle', 'E41: the barrier fired immediately before the settling UPDATE' );

		$this->ok( $settled instanceof SessionResult, 'E41: a settle that affects zero rows is NOT an error: ' . $this->describe( $settled ) );
		$row_s = $this->row( $session_s );
		$this->same( 1, (int) $row_s['available_for_sale'], 'E41: the occurrence is ON' );
		$this->same( $stolen, (string) $row_s['availability_operation_token'], 'E41: and the other request\'s token was not cleared' );

		// Normal OFF clears the token and never clears the lock.
		$locked = (string) $row_s['sale_locked_at'];
		( new SessionService( [ $this, 'swallow_audit' ] ) )->setAvailableForSale( $session_s, false );
		$row_off = $this->row( $session_s );
		$this->same( null, $row_off['availability_operation_token'], 'E41: OFF clears the operation token' );
		$this->same( $locked, (string) $row_off['sale_locked_at'], 'E41: and never clears sale_locked_at' );

		/* --- ON versus OFF, barrier-released: exactly one outcome stands. --- */
		[ $sched_r, $pkg_r ] = $this->make_schedule( $hall, 'Race Bingo' );
		$session_r = $this->plant_occurrence( $hall, $sched_r, $this->local_date( '+2 days' ), 'Race Occurrence', true, $pkg_r );

		$off_service = new SessionService( [ $this, 'swallow_audit' ] );
		$off_result  = null;
		$this->barrier(
			'off-during-on',
			'/SELECT package_id FROM \S+bingo_session_packages/',
			function () use ( $off_service, $session_r, &$off_result ): void {
				$off_result = $off_service->setAvailableForSale( $session_r, false );
			},
			2
		);
		$on_result = ( new SessionService( [ $this, 'swallow_audit' ] ) )->setAvailableForSale( $session_r, true );
		$this->assert_barrier_fired( 'off-during-on', 'ON vs OFF: the barrier fired between the ON and its settlement' );

		$row_r = $this->row( $session_r );
		$this->ok( $off_result instanceof SessionResult, 'ON vs OFF: the OFF request completed' );
		$this->ok( $on_result instanceof SessionResult, 'ON vs OFF: the ON request completed' );
		$this->same( 0, (int) $row_r['available_for_sale'], 'ON vs OFF: the later OFF stands' );
		$this->same( null, $row_r['availability_operation_token'], 'ON vs OFF: with no dangling token' );
		$this->ok( null !== $row_r['sale_locked_at'], 'ON vs OFF: and the lock taken by the ON survives' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * §16 E42 — process death between the guarded ON and settlement
	 * ================================================================== */

	private function process_death_group( int $hall ): void {
		$this->group( 'E42 process death' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Death Bingo' );
		$session_id = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Death Occurrence', true, $package_id );

		/*
		 * The kill is deterministic and happens at an EXACT statement boundary:
		 * the barrier throws immediately before the settling UPDATE, so the
		 * guarded ON has committed and the settlement never runs. That is the
		 * same observable end state as a fatal error, a PHP timeout, a worker
		 * kill or a deployment landing in that window — the process stops between
		 * the two statements, and nothing rolls the first one back.
		 */
		$this->barrier(
			'kill-before-settle',
			'/SET\s+availability_operation_token = NULL/',
			static function (): void {
				throw new RuntimeException( 'simulated process death between the guarded ON and settlement' );
			}
		);

		$died = false;
		try {
			( new SessionService( [ $this, 'swallow_audit' ] ) )->setAvailableForSale( $session_id, true );
		} catch ( RuntimeException $e ) {
			$died = true;
		}
		$this->assert_barrier_fired( 'kill-before-settle', 'E42: the barrier fired at the settlement boundary' );
		$this->ok( $died, 'E42: the request was killed between the guarded ON and its settlement' );

		$row = $this->row( $session_id );
		$dangling = (string) $row['availability_operation_token'];

		$this->same( 1, (int) $row['available_for_sale'], 'E42.1: the occurrence is genuinely ON — the guarded UPDATE committed' );
		$this->ok( null !== $row['sale_locked_at'], 'E42.1: and sale_locked_at is genuinely set' );
		$this->ok( '' !== $dangling, 'E42: availability_operation_token is non-NULL and will never be settled by its owner' );

		/* --- E42.2: storefront eligibility is unaffected by the token. --- */
		$sellable_with_token = SessionEligibility::is_sellable( $session_id );
		$this->same( true, $sellable_with_token, 'E42.2: isSellable() answers from its own facts, and the dangling token does not change it' );

		// The same row, with the token cleared by hand, must answer identically —
		// which is the direct proof that the token is not an input.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::sessions_table() . ' SET availability_operation_token = NULL WHERE session_id = %d',
				$session_id
			)
		);
		$this->same(
			$sellable_with_token,
			SessionEligibility::is_sellable( $session_id ),
			'E42.2: and the answer is IDENTICAL with the token cleared — sellability never reads it'
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::sessions_table() . ' SET availability_operation_token = %s WHERE session_id = %d',
				$dangling,
				$session_id
			)
		);

		/* --- E42.3: the stale token blocks nothing, and OFF clears it. --- */
		$service = new SessionService( [ $this, 'swallow_audit' ] );
		$off     = $service->setAvailableForSale( $session_id, false );
		$this->ok( $off instanceof SessionResult, 'E42.3: a subsequent OFF succeeds despite the dangling token' );
		$after_off = $this->row( $session_id );
		$this->same( null, $after_off['availability_operation_token'], 'E42.3: OFF clears the abandoned token' );
		$this->ok( null !== $after_off['sale_locked_at'], 'E42.3: and still never clears sale_locked_at' );

		/* --- E42.4: a later ON replaces it, and the dead token can never match. --- */
		$service->setAvailableForSale( $session_id, true );
		$replaced = $this->row( $session_id );
		$this->same( 1, (int) $replaced['available_for_sale'], 'E42.4: a subsequent ON succeeds' );
		$this->ok(
			(string) $replaced['availability_operation_token'] !== $dangling,
			'E42.4: the new ON stamped a different token, so the dead request\'s fence can never match again'
		);

		$service->setAvailableForSale( $session_id, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * §6.4 — the fail-closed sellability predicate
	 * ================================================================== */

	private function sellability_group( int $hall ): void {
		global $wpdb;

		$this->group( '6.4 sellability' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id, $template_id ] = $this->make_schedule( $hall, 'Sellable Bingo' );
		$session_id = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Sellable Occurrence', true, $package_id );
		$service    = new SessionService( [ $this, 'swallow_audit' ] );

		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'an occurrence that is OFF is not sellable' );

		$service->setAvailableForSale( $session_id, true );
		$this->same( true, SessionEligibility::is_sellable( $session_id ), 'a complete, ON, in-window occurrence IS sellable' );

		/* --- outside the BR-051/BR-063 window --- */
		$far = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+40 days' ), 'Far Occurrence', true, $package_id );
		$service->setAvailableForSale( $far, true );
		$this->same( false, SessionEligibility::is_sellable( $far ), 'an occurrence outside the 30-day window is not sellable' );

		/*
		 * THE EXACT 29/30/31-DAY BOUNDARY, FOR A SCHEDULE-GENERATED
		 * OCCURRENCE SPECIFICALLY — proving parity with the legacy-Session
		 * boundary in tests/session-increment-1.php's H. Sales window group,
		 * which exercises the same SessionService::evaluate_available_today()
		 * this predicate delegates to, but never through a generated row.
		 * `plant_occurrence()` inserts the row directly, so `$today` is read
		 * the same way the eligibility predicate itself reads it — via
		 * SessionService::hall_local_today() — rather than assumed.
		 */
		$boundary_today = SessionService::hall_local_today();
		foreach (
			[
				29 => true,
				30 => true,
				31 => false,
			] as $offset => $expected
		) {
			$boundary_date = ( new DateTimeImmutable( $boundary_today, new DateTimeZone( 'UTC' ) ) )->modify( "+{$offset} days" )->format( 'Y-m-d' );
			$boundary_id   = $this->plant_occurrence( $hall, $schedule_id, $boundary_date, "Boundary D-{$offset}", true, $package_id );
			$service->setAvailableForSale( $boundary_id, true );
			$this->same(
				$expected,
				SessionEligibility::is_sellable( $boundary_id ),
				"a generated occurrence {$offset} days out is " . ( $expected ? 'sellable (inside the 30-day window)' : 'NOT sellable (one day past the boundary)' )
			);
		}

		/* --- incomplete generation --- */
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::sessions_table() . ' SET generation_completed_at = NULL WHERE session_id = %d',
				$session_id
			)
		);
		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'an INCOMPLETE occurrence is not sellable even while ON' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::sessions_table() . " SET generation_completed_at = '2026-01-01 00:00:00' WHERE session_id = %d",
				$session_id
			)
		);
		$this->same( true, SessionEligibility::is_sellable( $session_id ), '  and sellable again once complete' );

		/* --- archived schedule --- */
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::schedules_table() . " SET status = 'archived' WHERE schedule_id = %d",
				$schedule_id
			)
		);
		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'an ARCHIVED schedule makes its occurrences unsellable, with no write' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::schedules_table() . " SET status = 'active' WHERE schedule_id = %d",
				$schedule_id
			)
		);

		/* --- stale/absent definition: an INERT schedule --- */
		$token = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT active_definition_token FROM ' . Migrations::schedules_table() . ' WHERE schedule_id = %d', $schedule_id )
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::schedules_table() . ' SET active_definition_token = NULL WHERE schedule_id = %d',
				$schedule_id
			)
		);
		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'B18: an INERT schedule excludes its occurrences from isSellable()' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Migrations::schedules_table() . ' SET active_definition_token = %s WHERE schedule_id = %d',
				$token,
				$schedule_id
			)
		);

		/* --- template ineligible --- */
		foreach ( [ [ 'is_current', 0, 1 ], [ 'portal_status', 'draft', 'active' ] ] as $case ) {
			$wpdb->update( Migrations::portal_session_templates_table(), [ $case[0] => $case[1] ], [ 'portal_session_id' => $template_id ] );
			$this->same( false, SessionEligibility::is_sellable( $session_id ), "a template with {$case[0]}={$case[1]} makes the occurrence unsellable" );
			$wpdb->update( Migrations::portal_session_templates_table(), [ $case[0] => $case[2] ], [ 'portal_session_id' => $template_id ] );
		}
		$this->same( true, SessionEligibility::is_sellable( $session_id ), '  and sellable again once the template is eligible' );

		/* --- Package ineligible --- */
		( new PackageService() )->archive( $package_id );
		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'an occurrence whose only Package is archived is not sellable' );
		( new PackageService() )->restore( $package_id );
		$this->same( true, SessionEligibility::is_sellable( $session_id ), '  and sellable again once it is restored' );

		/* --- fail closed when a linked fact cannot be READ --- */
		$this->break_reads( '/SELECT schedule_id, portal_session_id, status, active_definition_token/' );
		$unknown = SessionEligibility::is_sellable( $session_id );
		$this->restore_reads();
		$this->same( 'sellability_unknown', $this->error_code( $unknown ), 'an unreadable schedule FAILS CLOSED with a WP_Error, never false-as-true' );
		$this->ok( is_wp_error( $unknown ), '  and callers must treat it as not sellable' );

		$this->break_reads( '/SELECT package_id FROM \S+bingo_session_packages/' );
		$unknown_pkg = SessionEligibility::is_sellable( $session_id );
		$this->restore_reads();
		$this->same( 'sellability_unknown', $this->error_code( $unknown_pkg ), 'an unreadable Package list FAILS CLOSED too' );

		/* --- an absent occurrence --- */
		$this->same( false, SessionEligibility::is_sellable( 99999999 ), 'an absent Session is not sellable' );
		$this->same( false, SessionEligibility::is_sellable( 0 ), 'and neither is an invalid id' );

		$service->setAvailableForSale( $session_id, false );
		$service->setAvailableForSale( $far, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * §16 F43, F45, F49, F50 — mutation ownership (service layer)
	 * ================================================================== */

	private function mutation_ownership_group( int $hall ): void {
		$this->group( 'F43-45,49-50 mutation ownership' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Owned Bingo' );
		$complete_session   = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Owned Complete', true, $package_id );
		$incomplete_session = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+3 days' ), 'Owned Incomplete', false, $package_id );

		/*
		 * item 2: an AUDIT SPY, not the swallow used elsewhere in this suite.
		 * Every refusal below must leave $this->audit_calls at exactly the
		 * value it held before the call — proving no audit event was created,
		 * not merely that this test did not look for one.
		 */
		$this->audit_calls = 0;
		$service            = new SessionService( [ $this, 'spy_audit' ] );

		/* --- F43: rename(), reschedule(), updateDetails(), archive(), restore()
		 * are ALL refused at the service layer, called directly. --- */
		$before = $this->row( $complete_session );
		$calls_before = $this->audit_calls;

		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $service->rename( $complete_session, 'Renamed Directly' ) ),
			'F43: rename() is refused on a schedule-owned occurrence'
		);
		$this->same( $calls_before, $this->audit_calls, 'item 2: rename()\'s refusal created NO audit event' );

		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $service->reschedule( $complete_session, $this->local_date( '+9 days' ) ) ),
			'F43: reschedule() is refused'
		);
		$this->same( $calls_before, $this->audit_calls, 'item 2: reschedule()\'s refusal created NO audit event' );

		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $service->updateDetails( $complete_session, $this->local_date( '+9 days' ), 'Updated Directly', true ) ),
			'F43: updateDetails() is refused'
		);
		$this->same( $calls_before, $this->audit_calls, 'item 2: updateDetails()\'s refusal created NO audit event' );

		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $service->archive( $complete_session ) ),
			'F43: archive() is refused'
		);
		$this->same( $calls_before, $this->audit_calls, 'item 2: archive()\'s refusal created NO audit event' );

		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $service->restore( $complete_session ) ),
			'F43: restore() is refused'
		);
		$this->same( $calls_before, $this->audit_calls, 'item 2: restore()\'s refusal created NO audit event' );

		$this->same( 0, $this->audit_calls, 'item 2: across all five refusals, the audit recorder was NEVER invoked' );
		$this->same( $before, $this->row( $complete_session ), 'F43: NONE of the five refusals wrote anything — the row is byte-identical' );

		/*
		 * Control: the SAME spy, on a call that genuinely succeeds, DOES
		 * increment — proving the zero counts above are not merely an artefact
		 * of a spy that never counts anything.
		 */
		$control_before = $this->audit_calls;
		$control_package = $this->make_package( 'F43 audit control' );
		$service->assignPackage( $complete_session, $control_package );
		$this->ok( $this->audit_calls > $control_before, 'item 2 control: a genuinely successful call DOES increment the audit spy' );
		$service->removePackage( $complete_session, $control_package );

		/*
		 * "updateDetails() must never change available_for_sale on a generated
		 * occurrence" — proved directly: the call above asked for
		 * available_for_sale = true on a row that started OFF, and it is still
		 * OFF, because the whole method refused before writing anything.
		 */
		$this->same( 0, (int) $this->row( $complete_session )['available_for_sale'], '  specifically, updateDetails() did NOT turn availability on' );

		/* --- F44: the SAME refusals hold through SessionAdmin's POST handler.
		 * (moved to session_admin_group(), which drives handle_request()
		 * directly — the exact dispatcher SessionAdmin::render() calls.) --- */

		/* --- F45: assignPackage()/removePackage() refused while incomplete,
		 * allowed after completion. --- */
		$second_package = $this->make_package( 'F45 second' );

		$this->same(
			'occurrence_generation_incomplete',
			$this->error_code( $service->assignPackage( $incomplete_session, $second_package ) ),
			'F45: assignPackage() is refused while generation_completed_at IS NULL'
		);
		$this->same(
			'occurrence_generation_incomplete',
			$this->error_code( $service->removePackage( $incomplete_session, $package_id ) ),
			'F45: removePackage() is refused too'
		);
		$this->same(
			0,
			(int) $GLOBALS['wpdb']->get_var(
				$GLOBALS['wpdb']->prepare(
					'SELECT COUNT(*) FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d AND package_id = %d',
					$incomplete_session,
					$second_package
				)
			),
			'F45: and nothing was written'
		);

		$assigned = $service->assignPackage( $complete_session, $second_package );
		$this->ok( $assigned instanceof SessionResult, 'F45: assignPackage() is ALLOWED once generation_completed_at IS NOT NULL: ' . $this->describe( $assigned ) );
		$removed = $service->removePackage( $complete_session, $second_package );
		$this->ok( $removed instanceof SessionResult, 'F45: removePackage() is allowed too' );

		/* --- F49: setAvailableForSale() on a generated occurrence routes
		 * through §6.1/§6.3 — proved by the presence of the guard fences the
		 * legacy path never uses, not merely by the call succeeding. --- */
		$service->setAvailableForSale( $complete_session, true );
		$routed = $this->row( $complete_session );
		$this->ok( null !== $routed['sale_locked_at'], 'F49: ON on a generated occurrence acquired a sale lock — proof of the §6.1 guarded path, never present on the legacy writer' );
		$service->setAvailableForSale( $complete_session, false );
		$this->same( null, $this->row( $complete_session )['availability_operation_token'], 'F49: OFF cleared the token through §6.3' );

		/* --- F50: every writer behaves observably as at v2 for
		 * schedule_id IS NULL, against a captured baseline.
		 *
		 * The baseline IS tests/session-increment-1.php, run unmodified by this
		 * suite's own matrix (346/346, byte-for-byte the same assertions this
		 * ADR started from). What is proved HERE, directly, is that the new §9
		 * guards this pass added are complete no-ops for a legacy row — same
		 * status strings, same success, nothing refused that used to succeed. */
		$legacy = $service->create( $this->local_date( '+4 days' ), self::PREFIX . ' F50 Legacy ' . wp_generate_password( 5, false ), false );
		$this->ok( $legacy instanceof SessionResult, 'F50: create() unaffected for a legacy row' );
		$legacy_id = $legacy instanceof SessionResult ? (int) $legacy->session['session_id'] : 0;

		$renamed = $service->rename( $legacy_id, 'F50 Legacy Renamed' );
		$this->same( 'session_renamed', $renamed instanceof SessionResult ? $renamed->status : '', 'F50: rename() still succeeds for schedule_id IS NULL, same status string' );

		$rescheduled = $service->reschedule( $legacy_id, $this->local_date( '+5 days' ) );
		$this->same( 'session_rescheduled', $rescheduled instanceof SessionResult ? $rescheduled->status : '', 'F50: reschedule() still succeeds' );

		$updated = $service->updateDetails( $legacy_id, $this->local_date( '+6 days' ), 'F50 Legacy Updated', true );
		$this->same( 'session_updated', $updated instanceof SessionResult ? $updated->status : '', 'F50: updateDetails() still succeeds, including flipping availability' );
		$this->same( 1, (int) $this->row( $legacy_id )['available_for_sale'], '  and it really did turn availability on for a legacy row' );

		$archived = $service->archive( $legacy_id );
		$this->same( 'session_archived', $archived instanceof SessionResult ? $archived->status : '', 'F50: archive() still succeeds' );
		$restored = $service->restore( $legacy_id );
		$this->same( 'session_restored', $restored instanceof SessionResult ? $restored->status : '', 'F50: restore() still succeeds' );

		$legacy_package = $this->make_package( 'F50 legacy pkg' );
		$assign_legacy  = $service->assignPackage( $legacy_id, $legacy_package );
		$this->same( 'session_package_assigned', $assign_legacy instanceof SessionResult ? $assign_legacy->status : '', 'F50: assignPackage() still succeeds for a legacy row with NO completion gate' );
		$remove_legacy = $service->removePackage( $legacy_id, $legacy_package );
		$this->same( 'session_package_removed', $remove_legacy instanceof SessionResult ? $remove_legacy->status : '', 'F50: removePackage() still succeeds' );

		$service->setAvailableForSale( $legacy_id, false );
		$service->setAvailableForSale( $complete_session, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * §16 F44 — SessionAdmin: POST handler and presentation
	 * ================================================================== */

	private function session_admin_group( int $hall ): void {
		global $wpdb;

		$this->group( 'F44 SessionAdmin' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Admin Owned Bingo' );
		/*
		 * +1 day, not +2: SessionAdmin::render() now paginates nearest-first
		 * (PER_PAGE below), and dozens of other groups in this shared $hall
		 * already plant +2-day occurrences before this group runs. +1 day
		 * keeps this fixture findable on page 1 of the item-3 render check
		 * below regardless of how much +2-day volume accumulates elsewhere.
		 */
		$generated_date    = $this->local_date( '+1 days' );
		$generated_display = ( new DateTimeImmutable( $generated_date ) )->format( 'm/d/Y' );
		$generated         = $this->plant_occurrence( $hall, $schedule_id, $generated_date, 'Admin Owned Occurrence', true, $package_id );

		// item 2: the same audit SPY, at the handler layer.
		$this->audit_calls = 0;
		$service            = new SessionService( [ $this, 'spy_audit' ] );

		$handler = new ReflectionMethod( \BingoCommerce\Sessions\SessionAdmin::class, 'handle_request' );
		$handler->setAccessible( true );

		/* --- Bingo Sessions' admin input boundary is MM/DD/YYYY. --- */
		$date_service  = new SessionService( [ $this, 'swallow_audit' ] );
		$create_iso    = $this->local_date( '+20 days' );
		$create_input  = ( new DateTimeImmutable( $create_iso ) )->format( 'm/d/Y' );
		$created       = $handler->invoke(
			null,
			[ 'bc_session_action' => 'create', 'session_date' => $create_input, 'display_name' => self::PREFIX . ' Admin Date Input' ],
			$date_service
		);
		$created_id  = $created instanceof SessionResult ? (int) $created->session['session_id'] : 0;
		$created_row = $created_id > 0 ? $this->row( $created_id ) : [];
		$this->ok( $created_id > 0, 'date format: create accepts MM/DD/YYYY' );
		$this->same( $create_iso, (string) ( $created_row['session_date'] ?? '' ), '  and stores canonical Y-m-d' );

		$count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() );
		$impossible   = $handler->invoke(
			null,
			[ 'bc_session_action' => 'create', 'session_date' => '02/31/2026', 'display_name' => self::PREFIX . ' Impossible Date' ],
			$date_service
		);
		$this->same( 'invalid_session_date', $this->error_code( $impossible ), 'date format: impossible MM/DD/YYYY is rejected' );
		$this->same( $count_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() ), '  without creating a row' );

		$iso_create = $handler->invoke(
			null,
			[ 'bc_session_action' => 'create', 'session_date' => $this->local_date( '+22 days' ), 'display_name' => self::PREFIX . ' ISO Date' ],
			$date_service
		);
		$this->same( 'invalid_session_date', $this->error_code( $iso_create ), 'date format: ISO-form user input is rejected' );
		$this->same( $count_before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() ), '  without creating a row' );

		$edit_iso   = $this->local_date( '+21 days' );
		$edit_input = ( new DateTimeImmutable( $edit_iso ) )->format( 'm/d/Y' );
		$edited     = $handler->invoke(
			null,
			[ 'bc_session_action' => 'update', 'session_id' => $created_id, 'session_date' => $edit_input, 'display_name' => self::PREFIX . ' Admin Date Input' ],
			$date_service
		);
		$this->ok( $edited instanceof SessionResult, 'date format: legacy edit accepts MM/DD/YYYY' );
		$this->same( $edit_iso, (string) $this->row( $created_id )['session_date'], '  and stores canonical Y-m-d' );

		$edit_snapshot = $this->row( $created_id );
		$bad_edit      = $handler->invoke(
			null,
			[ 'bc_session_action' => 'update', 'session_id' => $created_id, 'session_date' => '2/03/2026', 'display_name' => 'Not Written' ],
			$date_service
		);
		$this->same( 'invalid_session_date', $this->error_code( $bad_edit ), 'date format: one-digit month input is rejected' );
		$this->same( $edit_snapshot, $this->row( $created_id ), '  without changing the legacy Session' );

		$before = $this->row( $generated );
		$calls_before = $this->audit_calls;

		/* --- F44: the SAME refusals hold through SessionAdmin's POST handler —
		 * handle_request() is the exact dispatcher SessionAdmin::render() calls
		 * on every POST, so driving it directly exercises the real code path
		 * without the wp_die()/nonce plumbing of the full admin-page request. */
		foreach (
			[
				'update'  => [ 'bc_session_action' => 'update', 'session_id' => $generated, 'session_date' => ( new DateTimeImmutable( $this->local_date( '+9 days' ) ) )->format( 'm/d/Y' ), 'display_name' => 'Via Handler', 'available_for_sale' => '1' ],
				'archive' => [ 'bc_session_action' => 'archive', 'session_id' => $generated ],
				'restore' => [ 'bc_session_action' => 'restore', 'session_id' => $generated ],
			] as $action => $post
		) {
			$result = $handler->invoke( null, $post, $service );
			$this->same(
				'schedule_owned_occurrence',
				$this->error_code( $result ),
				"F44: SessionAdmin's POST handler refuses '{$action}' on a schedule-owned occurrence, exactly as the service does"
			);
			$this->same( $calls_before, $this->audit_calls, "item 2: the '{$action}' handler refusal created NO audit event" );
		}
		$this->same( $before, $this->row( $generated ), 'F44: none of the three handler-level attempts wrote anything' );

		/* --- rename()/reschedule() have no direct 'bc_session_action', so their
		 * refusal is proved at the service layer above (F43); nothing in
		 * handle_request() dispatches to them. --- */

		/* --- F45 through the handler too. --- */
		$incomplete = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+10 days' ), 'Admin Incomplete', false, $package_id );
		$handler_pkg_result = $handler->invoke(
			null,
			[ 'bc_session_action' => 'assign_package', 'session_id' => $incomplete, 'package_id' => $package_id ],
			$service
		);
		$this->same(
			'occurrence_generation_incomplete',
			$this->error_code( $handler_pkg_result ),
			'F44/F45: the handler refuses assign_package on an incomplete occurrence too'
		);
		$this->same( $calls_before, $this->audit_calls, 'item 2: and that refusal created no audit event either' );
		$this->same( 0, $this->audit_calls, 'item 2: across all four handler-level refusals, the audit recorder was NEVER invoked' );

		/*
		 * UI HIDING IS NOT ENFORCEMENT, and this section proves it is not being
		 * relied on as one: render_edit_form()'s output is checked ONLY for
		 * what is shown, never asserted as a security boundary. The refusals
		 * above, at the service and handler layers, are the enforcement.
		 */
		$render = new ReflectionMethod( \BingoCommerce\Sessions\SessionAdmin::class, 'render_edit_form' );
		$render->setAccessible( true );

		ob_start();
		$render->invoke( null, $service, $generated, [], false );
		$generated_html = (string) ob_get_clean();

		$this->ok( false === strpos( $generated_html, 'value="update"' ), 'presentation: no update FORM is rendered for a schedule-owned occurrence' );
		$this->ok( false === strpos( $generated_html, 'name="session_date"' ), '  no editable date field' );
		$this->ok( false === strpos( $generated_html, 'name="display_name"' ), '  no editable name field' );
		$this->ok( false !== strpos( $generated_html, 'commerce schedule' ), '  and a notice explains why' );
		$this->ok( false !== strpos( $generated_html, $generated_display ), '  and its read-only date renders as MM/DD/YYYY' );
		$this->ok( false === strpos( $generated_html, $generated_date ), '  without exposing the stored Y-m-d value' );

		/*
		 * +1 day, not the +11 an earlier revision used: SessionAdmin::render()
		 * now paginates nearest-first, and dozens of OTHER groups in this
		 * shared $hall already planted +2-day occurrences before this group
		 * runs. +1 day guarantees this fixture sorts ahead of that entire
		 * block, so it is still on page 1 below regardless of how many other
		 * groups' fixtures accumulate here.
		 */
		$legacy_date    = $this->local_date( '+1 days' );
		$legacy_display = ( new DateTimeImmutable( $legacy_date ) )->format( 'm/d/Y' );
		$legacy_create = $service->create( $legacy_date, self::PREFIX . ' Admin Legacy ' . wp_generate_password( 5, false ), false );
		$legacy_id     = $legacy_create instanceof SessionResult ? (int) $legacy_create->session['session_id'] : 0;

		ob_start();
		$render->invoke( null, $service, $legacy_id, [], false );
		$legacy_html = (string) ob_get_clean();

		$this->ok( false !== strpos( $legacy_html, 'value="update"' ), 'presentation: the update form IS rendered for a legacy Session, unchanged' );
		$this->ok( false !== strpos( $legacy_html, 'name="session_date"' ), '  with its editable date field' );
		$this->ok( false !== strpos( $legacy_html, 'value="' . $legacy_display . '"' ), '  whose value renders as MM/DD/YYYY' );
		$this->ok( false === strpos( $legacy_html, 'type="date"' ), '  and uses no locale-dependent date control' );
		$this->ok( false !== strpos( $legacy_html, 'placeholder="MM/DD/YYYY"' ), '  with the required format placeholder' );

		/*
		 * Item 3. The LIST-TABLE view, not just the edit form.
		 *
		 * SessionAdmin::render() is called directly — it is public, unlike
		 * handle_request() and render_edit_form() above — with REQUEST_METHOD
		 * forced to GET and $_GET['edit'] cleared, so it renders the list
		 * table rather than either the update form or the POST handler. The
		 * environment globals it reads are saved and restored around the call,
		 * so nothing here leaks into the rest of the suite.
		 */
		/*
		 * Item 3 (extended). BOTH lifecycle branches, not only the active one.
		 *
		 * An ARCHIVED schedule-owned occurrence cannot be REACHED through
		 * archive() — that writer is refused for a schedule-owned row by §9,
		 * which is exactly the behaviour under test — so the row is planted
		 * directly at `status = 'archived'`, the same fixture-planting
		 * technique plant_occurrence() already uses for `generation_completed_at`.
		 * Presentation must be correct for this state regardless of how a row
		 * reaches it: a future reconciliation-driven archive (§7.5) will reach
		 * it through a different writer, and the list table must still hide
		 * the same three controls.
		 *
		 * An ARCHIVED legacy occurrence IS reachable through the ordinary
		 * public writer, so it is created that way.
		 */
		// Its own schedule: $generated already occupies +1 day under
		// $schedule_id, and schedule_occurrence_unique (schedule_id,
		// session_date) refuses a second row on the same date there.
		[ $archived_schedule_id, $archived_package_id ] = $this->make_schedule( $hall, 'Admin Archived Generated Bingo' );
		$archived_generated = $this->plant_occurrence( $hall, $archived_schedule_id, $this->local_date( '+1 days' ), 'Admin Archived Generated', true, $archived_package_id );
		$wpdb->update( Migrations::sessions_table(), [ 'status' => 'archived', 'archived_at' => current_time( 'mysql', true ) ], [ 'session_id' => $archived_generated ] );

		$legacy_for_archive = $service->create( $this->local_date( '+1 days' ), self::PREFIX . ' Admin Legacy Archived ' . wp_generate_password( 5, false ), false );
		$archived_legacy_id = $legacy_for_archive instanceof SessionResult ? (int) $legacy_for_archive->session['session_id'] : 0;
		$archived_result    = $service->archive( $archived_legacy_id );
		$this->ok( $archived_result instanceof SessionResult, 'item 3 fixture: a legacy Session archives normally, to exercise its list row' );

		$saved_get     = $_GET;
		$saved_method  = $_SERVER['REQUEST_METHOD'] ?? null;
		$_GET          = [ 'page' => 'bingocommerce-sessions', 'status' => 'all' ];
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		\BingoCommerce\Sessions\SessionAdmin::render();
		$list_html = (string) ob_get_clean();

		$_GET = $saved_get;
		if ( null === $saved_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $saved_method;
		}

		$this->ok( '' !== $list_html, 'item 3: the list table rendered output' );
		$this->ok( false === strpos( $list_html, 'type="date"' ), 'date format: the Bingo Sessions screen renders no type="date" control' );
		$this->ok( false !== strpos( $list_html, 'placeholder="MM/DD/YYYY"' ), 'date format: the create form shows the MM/DD/YYYY placeholder' );
		$this->ok( false !== strpos( $list_html, 'Enter the date as MM/DD/YYYY' ), 'date format: the create form states the required format' );

		$generated_row          = $this->extract_row_html( $list_html, $generated );
		$legacy_row             = $this->extract_row_html( $list_html, $legacy_id );
		$archived_generated_row = $this->extract_row_html( $list_html, $archived_generated );
		$archived_legacy_row    = $this->extract_row_html( $list_html, $archived_legacy_id );

		$this->ok( '' !== $generated_row, 'item 3: the generated occurrence\'s row was found in the list output' );
		$this->ok( '' !== $legacy_row, 'item 3: the legacy Session\'s row was found too' );
		$this->ok( '' !== $archived_generated_row, 'item 3: the ARCHIVED generated occurrence\'s row was found too' );
		$this->ok( '' !== $archived_legacy_row, 'item 3: the ARCHIVED legacy Session\'s row was found too' );
		$this->ok( false !== strpos( $generated_row, $generated_display ), 'date format: generated list date renders as MM/DD/YYYY' );
		$this->ok( false !== strpos( $legacy_row, $legacy_display ), 'date format: legacy list date renders as MM/DD/YYYY' );

		$admin_source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions/src/SessionAdmin.php' );
		$this->ok( false === strpos( $admin_source, 'type="date"' ), 'date format: SessionAdmin contains no type="date" control' );
		$this->ok( false !== strpos( $admin_source, "dateFormat: 'mm/dd/yy'" ), 'date format: the bundled datepicker is configured explicitly as mm/dd/yy' );
		$this->ok( false === strpos( $admin_source, 'strtotime(' ), 'date format: SessionAdmin does not use strtotime()' );

		/* --- generated occurrences (ACTIVE) expose NONE of the three controls --- */
		$this->ok( false === strpos( $generated_row, 'edit=' . $generated ), 'item 3: the active generated row has NO Edit link' );
		$this->ok( false === strpos( $generated_row, 'value="archive"' ), 'item 3: NO Archive control' );
		$this->ok( false === strpos( $generated_row, 'value="restore"' ), 'item 3: NO Restore control' );
		$this->ok( false !== strpos( $generated_row, 'schedule-managed' ), 'item 3: and it is labelled schedule-managed instead' );

		/* --- generated occurrences (ARCHIVED) ALSO expose none of the three --- */
		$this->ok( false === strpos( $archived_generated_row, 'edit=' . $archived_generated ), 'item 3: the ARCHIVED generated row has NO Edit link either' );
		$this->ok( false === strpos( $archived_generated_row, 'value="archive"' ), 'item 3: NO Archive control on the archived generated row' );
		$this->ok( false === strpos( $archived_generated_row, 'value="restore"' ), 'item 3: and — the case that matters most here — NO Restore control either' );
		$this->ok( false !== strpos( $archived_generated_row, 'schedule-managed' ), 'item 3: still labelled schedule-managed' );

		/* --- legacy Sessions (ACTIVE) still expose the original controls, unchanged --- */
		$this->ok( false !== strpos( $legacy_row, 'edit=' . $legacy_id ), 'item 3: the active legacy row STILL has its Edit link' );
		$this->ok( false !== strpos( $legacy_row, 'value="archive"' ), 'item 3: STILL has its Archive control (legacy Sessions are active by default)' );

		/* --- legacy Sessions (ARCHIVED) expose their ordinary Restore control --- */
		$this->ok( false === strpos( $archived_legacy_row, 'edit=' . $archived_legacy_id ), 'item 3: an archived legacy row has no Edit link (matches the archived-session UI rule, unrelated to §9)' );
		$this->ok( false !== strpos( $archived_legacy_row, 'value="restore"' ), 'item 3: but its ORDINARY Restore control remains available, unchanged from v2' );
		$this->ok( false === strpos( $archived_legacy_row, 'value="archive"' ), 'item 3: and it no longer offers Archive, since it already is' );
		$this->ok( false === strpos( $archived_legacy_row, 'schedule-managed' ), 'item 3: and is NEVER labelled schedule-managed — it is a legacy row' );

		/* --- enforcement for the ARCHIVED generated row too, not only the active one --- */
		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( ( new SessionService( [ $this, 'swallow_audit' ] ) )->restore( $archived_generated ) ),
			'item 3: restore() is refused on the ARCHIVED generated occurrence at the service layer — hiding the control is not what stops it'
		);
		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $handler->invoke( null, [ 'bc_session_action' => 'restore', 'session_id' => $archived_generated ], new SessionService( [ $this, 'swallow_audit' ] ) ) ),
			'item 3: nor is it the handler — a hand-crafted restore POST is refused identically'
		);
		$this->same( 'archived', (string) $this->row( $archived_generated )['status'], 'item 3: and it was not actually restored by either attempt' );

		/*
		 * item 3: PRESENTATION REMAINS NON-AUTHORITATIVE. Hiding the controls in
		 * the markup above proves nothing about enforcement by itself — a
		 * Manager who never sees a button cannot click it, but nothing stops a
		 * crafted request. What actually enforces the refusal is F43 (direct
		 * service) and F44 (the handler) above, both of which already drove
		 * 'archive' and 'restore' against this EXACT $generated session_id and
		 * got schedule_owned_occurrence with no write. Restated here, against
		 * the CURRENT row, so this group does not depend on assertion order
		 * elsewhere in the suite to make the point true.
		 */
		$enforcement_service = new SessionService( [ $this, 'swallow_audit' ] );
		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $enforcement_service->archive( $generated ) ),
			'item 3: hiding the control is NOT the enforcement — archive() is still refused at the service layer regardless of what the list shows'
		);
		$forged_post = [ 'bc_session_action' => 'archive', 'session_id' => $generated ];
		$this->same(
			'schedule_owned_occurrence',
			$this->error_code( $handler->invoke( null, $forged_post, $enforcement_service ) ),
			'item 3: nor is it the handler — a hand-crafted POST for the hidden action is refused identically'
		);
		$this->same( 'active', (string) $this->row( $generated )['status'], 'item 3: and the row was not actually archived by either attempt' );

		$service->setAvailableForSale( $generated, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * Enable/Disable Sales — the new per-row actions for generated Sessions.
	 *
	 * Both go through SessionAdmin::handle_request() -> the SAME
	 * SessionService::setAvailableForSale() every other availability path in
	 * this file already proves is guarded (§6.1/§6.2) for a schedule-owned
	 * row. Nothing here re-implements or bypasses that guard; these
	 * assertions exist to prove the ADMIN ACTION NAMES ('enable_sales',
	 * 'disable_sales') dispatch to it correctly, exactly the same way F44
	 * above proves 'archive'/'restore' dispatch to their refusal.
	 *
	 * Its OWN disposable hall, not the shared $hall: the rendered-UI
	 * assertions below need to find a SPECIFIC row within SessionAdmin's
	 * nearest-first, paginated list, and the shared hall accumulates dozens
	 * of other groups' fixtures ahead of anything planted this late in the
	 * run — exactly the fragility pagination_group() below was already
	 * written to avoid.
	 * ================================================================== */

	private function enable_disable_sales_group(): void {
		$this->group( 'Enable/Disable Sales' );

		$hall = $this->create_disposable_site();
		$this->ok( $hall > 0, 'fixture: a dedicated disposable hall for Enable/Disable Sales' );
		switch_to_blog( $hall );

		$service = new SessionService( [ $this, 'swallow_audit' ] );
		$handler = new ReflectionMethod( \BingoCommerce\Sessions\SessionAdmin::class, 'handle_request' );
		$handler->setAccessible( true );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'EnableDisable Bingo' );

		/* --- A complete, in-window generated occurrence: the ordinary case. --- */
		$in_window = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'In Window', true, $package_id );

		$on = $handler->invoke( null, [ 'bc_session_action' => 'enable_sales', 'session_id' => $in_window ], $service );
		$this->ok( $on instanceof SessionResult, 'enable_sales dispatches to a successful result' );
		$this->same( 'session_availability_changed', $on instanceof SessionResult ? $on->status : '', '  reporting the SAME status string as any other availability change' );

		$row = $this->row( $in_window );
		$this->same( 1, (int) $row['available_for_sale'], '  and the row is really ON' );
		$this->ok(
			null !== $row['sale_locked_at'],
			'  through the GUARDED generated-row path specifically: sale_locked_at is set, which ONLY turn_on() sets — the legacy writer never touches it'
		);

		$off = $handler->invoke( null, [ 'bc_session_action' => 'disable_sales', 'session_id' => $in_window ], $service );
		$this->ok( $off instanceof SessionResult, 'disable_sales dispatches to a successful result' );
		$this->same( 'session_availability_changed', $off instanceof SessionResult ? $off->status : '', '  reporting the same status string' );
		$this->same( 0, (int) $this->row( $in_window )['available_for_sale'], '  and the row is really OFF again' );
		$this->ok(
			null !== $this->row( $in_window )['sale_locked_at'],
			'  sale_locked_at is DURABLE proof of having ever been on sale — turning off never clears it (§6.3, unchanged)'
		);

		/*
		 * ENABLING OUTSIDE THE 30-DAY WINDOW MUST NOT MAKE IT IMMEDIATELY
		 * SELLABLE. enable_sales is allowed to succeed (a Manager may stage a
		 * Session ahead of its window, exactly as the legacy checkbox always
		 * could) — what must NOT happen is available_for_sale_today
		 * following it early. This is the same §6.4 predicate
		 * SessionAdmin's own "Available to customers today" column reads, so
		 * the read here is the one the screen actually shows, not a
		 * reimplementation of the window rule.
		 */
		$outside_window = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+45 days' ), 'Outside Window', true, $package_id );
		$before_today    = $service->getById( $outside_window );
		$this->ok( is_array( $before_today ) && false === $before_today['available_for_sale_today'], 'fixture: 45 days out starts NOT available to customers today' );

		$enabled_outside = $handler->invoke( null, [ 'bc_session_action' => 'enable_sales', 'session_id' => $outside_window ], $service );
		$this->ok( $enabled_outside instanceof SessionResult, 'enable_sales still SUCCEEDS 45 days out — a Manager may stage a Session ahead of its window' );
		$this->same( 1, (int) $this->row( $outside_window )['available_for_sale'], '  and Enabled by manager really is ON now' );

		$after_today = $service->getById( $outside_window );
		$this->ok(
			is_array( $after_today ) && false === $after_today['available_for_sale_today'],
			'  but Available to customers today is STILL No — enabling early never bypasses the 30-day window'
		);

		/* --- Refused where the guard already refuses: an incomplete occurrence. --- */
		$incomplete = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+3 days' ), 'Incomplete For Enable', false, $package_id );
		$refused    = $handler->invoke( null, [ 'bc_session_action' => 'enable_sales', 'session_id' => $incomplete ], $service );
		$this->same(
			'occurrence_generation_incomplete',
			$this->error_code( $refused ),
			'enable_sales on an incomplete occurrence is refused with the SAME code the guard already reports'
		);
		$this->same( 0, (int) $this->row( $incomplete )['available_for_sale'], '  and it was not turned on' );

		/* --- The admin error-message map covers what the guard can return. --- */
		foreach (
			[ 'occurrence_generation_incomplete', 'template_not_commerce_eligible', 'schedule_state_changed', 'package_became_unassignable', 'occurrence_not_schedule_owned' ] as $code
		) {
			$message = \BingoCommerce\Sessions\SessionAdmin::error_message( new \WP_Error( $code, 'irrelevant' ) );
			$this->ok(
				'' !== $message && __( 'The Bingo Session change could not be completed.', 'bingocommerce-sessions' ) !== $message,
				"error_message() maps '{$code}' to its own text, not the generic fallback"
			);
		}

		/*
		 * RENDERED UI: the row actually offers the two buttons, dispatching
		 * to the two action names just proven above, and the button shown
		 * matches the CURRENT available_for_sale state.
		 */
		$service->setAvailableForSale( $in_window, false );

		$saved_get    = $_GET;
		$saved_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$_GET         = [ 'page' => 'bingocommerce-sessions', 'status' => 'all' ];
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		\BingoCommerce\Sessions\SessionAdmin::render();
		$off_html = (string) ob_get_clean();

		$_GET = $saved_get;
		if ( null === $saved_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $saved_method;
		}

		$off_row = $this->extract_row_html( $off_html, $in_window );
		$this->ok( '' !== $off_row, 'the OFF generated row was found in the rendered list' );
		$this->ok( false !== strpos( $off_row, 'value="enable_sales"' ), '  it offers Enable Sales' );
		$this->ok( false === strpos( $off_row, 'value="disable_sales"' ), '  and NOT Disable Sales' );
		$this->ok( false !== strpos( $off_row, '_wpnonce' ), '  the Enable Sales form carries a nonce field, exactly like every other mutating form on this screen' );

		$service->setAvailableForSale( $in_window, true );

		$_GET = [ 'page' => 'bingocommerce-sessions', 'status' => 'all' ];
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		\BingoCommerce\Sessions\SessionAdmin::render();
		$on_html = (string) ob_get_clean();

		$_GET = $saved_get;
		if ( null === $saved_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $saved_method;
		}

		$on_row = $this->extract_row_html( $on_html, $in_window );
		$this->ok( '' !== $on_row, 'the ON generated row was found in the rendered list' );
		$this->ok( false !== strpos( $on_row, 'value="disable_sales"' ), '  it offers Disable Sales' );
		$this->ok( false === strpos( $on_row, 'value="enable_sales"' ), '  and NOT Enable Sales' );

		/* --- Edit/Archive/Restore/name/date/Package remain blocked either way. --- */
		foreach ( [ $off_row, $on_row ] as $html ) {
			$this->ok( false === strpos( $html, 'edit=' . $in_window ), 'still no Edit link for a generated row, regardless of its sales state' );
			$this->ok( false === strpos( $html, 'value="archive"' ), '  and no Archive control' );
			$this->ok( false === strpos( $html, 'value="restore"' ), '  and no Restore control' );
		}

		/* --- The old repeated sentence is gone; the compact label replaces it, exactly once per row. --- */
		$this->ok(
			false === strpos( $off_html, 'Managed by its commerce schedule.' ),
			'the old, repeated full-sentence label is gone from the whole screen'
		);
		$this->same(
			1,
			substr_count( strtolower( $off_row ), 'schedule-managed' ),
			'  the compact schedule-managed label appears EXACTLY ONCE in this row, not once per column'
		);
		$this->same(
			1,
			substr_count( strtolower( $on_row ), 'schedule-managed' ),
			'  exactly once regardless of the row\'s sales state too'
		);

		/* --- Both states are still shown clearly for every generated row. --- */
		$this->ok( false !== strpos( $off_html, 'Enabled by manager' ), 'the Enabled by manager column heading is still present' );
		$this->ok( false !== strpos( $off_html, 'Available to customers today' ), '  and so is Available to customers today' );

		$service->setAvailableForSale( $in_window, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * Pagination and nearest-first ordering.
	 *
	 * Runs on its OWN disposable hall: every other group in this file shares
	 * $hall and plants dozens of Sessions in it, so proving an exact page
	 * boundary and an exact sort order needs a hall this group fully
	 * controls, not the ambient state every earlier group already left in
	 * the shared one.
	 * ================================================================== */

	private function pagination_group(): void {
		$this->group( 'Pagination and ordering' );

		$hall = $this->create_disposable_site();
		$this->ok( $hall > 0, 'fixture: a dedicated disposable hall for pagination' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Pagination Bingo' );

		// PER_PAGE is 20 (SessionAdmin::PER_PAGE). 22 occurrences, one day
		// apart, force exactly two pages: 20 on page 1, 2 on page 2.
		$ids = [];
		for ( $offset = 1; $offset <= 22; ++$offset ) {
			$ids[ $offset ] = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( "+{$offset} days" ), "Paged {$offset}", true, $package_id );
		}

		$saved_get    = $_GET;
		$saved_method = $_SERVER['REQUEST_METHOD'] ?? null;

		$_GET                      = [ 'page' => 'bingocommerce-sessions', 'status' => 'all' ];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		\BingoCommerce\Sessions\SessionAdmin::render();
		$page1_html = (string) ob_get_clean();

		$_GET                      = [ 'page' => 'bingocommerce-sessions', 'status' => 'all', 'paged' => '2' ];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		\BingoCommerce\Sessions\SessionAdmin::render();
		$page2_html = (string) ob_get_clean();

		$_GET = $saved_get;
		if ( null === $saved_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $saved_method;
		}

		/* --- Page 1 holds exactly the NEAREST 20 — offsets 1 through 20 — and none of the last 2. --- */
		for ( $offset = 1; $offset <= 20; ++$offset ) {
			$this->ok( '' !== $this->extract_row_html( $page1_html, $ids[ $offset ] ), "page 1 contains the +{$offset}-day occurrence" );
		}
		for ( $offset = 21; $offset <= 22; ++$offset ) {
			$this->ok( '' === $this->extract_row_html( $page1_html, $ids[ $offset ] ), "page 1 does NOT contain the +{$offset}-day occurrence — the table is bounded, not one unbounded list" );
		}

		/* --- Nearest-first: on page 1, the +1 row's marker appears before the +20 row's marker. --- */
		$pos_first = strpos( $page1_html, '#' . $ids[1] . ' —' );
		$pos_last  = strpos( $page1_html, '#' . $ids[20] . ' —' );
		$this->ok( false !== $pos_first && false !== $pos_last && $pos_first < $pos_last, 'the soonest occurrence (+1 day) renders BEFORE the furthest one shown (+20 days) — nearest-first' );

		/* --- Page 1 offers Next, not Previous. --- */
		$this->ok( false !== strpos( $page1_html, 'paged=2' ), 'page 1 offers a link to page 2' );
		$this->ok( false === strpos( $page1_html, 'paged=0' ), 'page 1 offers no link back past itself' );

		/* --- Page 2 holds exactly the remaining 2, and nothing from page 1. --- */
		for ( $offset = 21; $offset <= 22; ++$offset ) {
			$this->ok( '' !== $this->extract_row_html( $page2_html, $ids[ $offset ] ), "page 2 contains the +{$offset}-day occurrence" );
		}
		$this->ok( '' === $this->extract_row_html( $page2_html, $ids[1] ), 'page 2 does NOT repeat the +1-day occurrence from page 1' );

		/* --- Page 2 offers Previous, and no Next (exactly 22 rows, 2 pages). --- */
		$this->ok( false !== strpos( $page2_html, 'paged=1' ), 'page 2 offers a link back to page 1' );
		$this->ok( false === strpos( $page2_html, 'paged=3' ), 'page 2 offers no link to a third page — there is nothing to show there' );

		restore_current_blog();
	}

	/* ================================================================== *
	 * The default view is truly upcoming — a past-dated ACTIVE Session never
	 * occupies it, while 'all' still reaches it.
	 *
	 * Its own disposable hall, for the same reason pagination_group() and
	 * enable_disable_sales_group() above use one: this proves an EXACT
	 * absence/presence pair, which the heavily-populated shared $hall cannot
	 * guarantee.
	 * ================================================================== */

	private function upcoming_default_group(): void {
		$this->group( 'Upcoming default view' );

		$hall = $this->create_disposable_site();
		$this->ok( $hall > 0, 'fixture: a dedicated disposable hall for the default-view check' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Upcoming Default Bingo' );

		// Both ACTIVE. One already happened, one has not.
		$past   = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '-5 days' ), 'Past Active', true, $package_id );
		$future = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+3 days' ), 'Future Active', true, $package_id );

		$saved_get    = $_GET;
		$saved_method = $_SERVER['REQUEST_METHOD'] ?? null;

		// The TRUE default: no ?status= at all, exactly what SessionAdmin's
		// own menu link produces.
		$_GET                      = [ 'page' => 'bingocommerce-sessions' ];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		\BingoCommerce\Sessions\SessionAdmin::render();
		$default_html = (string) ob_get_clean();

		$_GET                      = [ 'page' => 'bingocommerce-sessions', 'status' => 'all' ];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		\BingoCommerce\Sessions\SessionAdmin::render();
		$all_html = (string) ob_get_clean();

		$_GET = $saved_get;
		if ( null === $saved_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $saved_method;
		}

		$this->ok( false !== strpos( $default_html, 'Upcoming' ), 'the default view is labelled Upcoming' );
		$this->ok(
			false !== strpos( $default_html, 'class="current"' ) && false !== strpos( self::extract_current_tab( $default_html ), 'Upcoming' ),
			'  and the Upcoming tab itself shows as the current one'
		);

		$this->ok(
			'' === $this->extract_row_html( $default_html, $past ),
			'the default view EXCLUDES the past-dated active Session — filtered at the read, not merely hidden'
		);
		$this->ok(
			'' !== $this->extract_row_html( $default_html, $future ),
			'  while the future-dated active Session IS shown'
		);

		/* Existing views remain available for historical Sessions. */
		$this->ok(
			'' !== $this->extract_row_html( $all_html, $past ),
			'the existing "All" view is UNCHANGED — the past-dated Session is still reachable there'
		);
		$this->ok(
			'' !== $this->extract_row_html( $all_html, $future ),
			'  alongside the future one'
		);

		/* Archived Sessions remain reachable exactly as before too. */
		$service = new SessionService( [ $this, 'swallow_audit' ] );
		$archived_check = $service->getById( $past );
		$this->ok( is_array( $archived_check ), 'fixture sanity: the past Session itself still reads back fine through the ordinary service — this filter lives only in the list query' );

		restore_current_blog();
	}

	/** The label of whichever subsubsub tab carries class="current". */
	private function extract_current_tab( string $html ): string {
		if ( 1 !== preg_match( '/<a href="[^"]*"\s+class="current">([^<]*)<\/a>/', $html, $m ) ) {
			return '';
		}

		return $m[1];
	}

	/**
	 * The HTML for ONE row of SessionAdmin's list table, isolated by locating
	 * this session's identifying cell text and taking the nearest enclosing
	 * `<tr>...</tr>`. Returns '' if the row is not found.
	 */
	private function extract_row_html( string $html, int $session_id ): string {
		$marker = '#' . $session_id . ' —';
		$at     = strpos( $html, $marker );
		if ( false === $at ) {
			return '';
		}

		$row_start = strrpos( substr( $html, 0, $at ), '<tr>' );
		$row_end   = strpos( $html, '</tr>', $at );
		if ( false === $row_start || false === $row_end ) {
			return '';
		}

		return substr( $html, $row_start, $row_end - $row_start + strlen( '</tr>' ) );
	}

	/* ================================================================== *
	 * Legacy regression — §9's "observable behaviour exactly"
	 * ================================================================== */

	private function legacy_group( int $hall ): void {
		$this->group( 'Legacy regression' );
		switch_to_blog( $hall );

		$service = new SessionService( [ $this, 'swallow_audit' ] );

		// Created through the ORDINARY public writer, so this is a real legacy
		// row in every respect, not a planted one.
		$created = $service->create( $this->local_date( '+3 days' ), self::PREFIX . ' Legacy Session ' . wp_generate_password( 5, false ), false );
		$this->ok( $created instanceof SessionResult, 'a legacy Session is created by the ordinary writer' );
		$session_id = $created instanceof SessionResult ? (int) $created->session['session_id'] : 0;

		$row = $this->row( $session_id );
		$this->same( null, $row['schedule_id'], 'a legacy Session has schedule_id NULL' );

		$on = $service->setAvailableForSale( $session_id, true );
		$this->ok( $on instanceof SessionResult, 'legacy ON succeeds' );
		$this->same( 'session_availability_changed', $on instanceof SessionResult ? $on->status : '', '  with the SAME status string as at v2' );

		$after = $this->row( $session_id );
		$this->same( 1, (int) $after['available_for_sale'], 'legacy ON turns the Session on' );
		$this->same( null, $after['sale_locked_at'], 'a legacy Session acquires NO sale lock — v2 behaviour exactly' );
		$this->same( null, $after['availability_operation_token'], 'and NO availability operation token' );
		$this->same( null, $after['timezone_name'], 'and no timezone_name' );
		$this->same( null, $after['starts_at_utc'], 'and no starts_at_utc' );
		$this->same( null, $after['generation_completed_at'], 'and no generation_completed_at' );

		$off = $service->setAvailableForSale( $session_id, false );
		$this->ok( $off instanceof SessionResult, 'legacy OFF succeeds' );
		$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], 'legacy OFF turns it off' );

		$unchanged = $service->setAvailableForSale( $session_id, false );
		$this->same(
			'session_availability_unchanged',
			$unchanged instanceof SessionResult ? $unchanged->status : '',
			'a legacy no-op still reports session_availability_unchanged'
		);

		/* --- legacy sellability uses wp_timezone() and needs no further test --- */
		$service->setAvailableForSale( $session_id, true );
		$this->same( true, SessionEligibility::is_sellable( $session_id ), 'a legacy Session in the window IS sellable, with no schedule tests applied' );

		$archived = $service->archive( $session_id );
		$this->ok( $archived instanceof SessionResult, 'a legacy Session still archives through the ordinary writer' );
		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'an archived legacy Session is not sellable' );
		$service->restore( $session_id );

		// The sales window itself — 30 calendar days — applies to a legacy row
		// exactly as it does to a generated one; +40 days is outside it either way.
		$far = $service->create( $this->local_date( '+40 days' ), self::PREFIX . ' Legacy Far ' . wp_generate_password( 5, false ), true );
		if ( $far instanceof SessionResult ) {
			$this->same( false, SessionEligibility::is_sellable( (int) $far->session['session_id'] ), 'a legacy Session outside the window is not sellable' );
		}

		$service->setAvailableForSale( $session_id, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * OccurrenceAvailability: forged-context rejection
	 * ================================================================== */

	/**
	 * A positive-looking but FABRICATED authorization context must be refused.
	 *
	 * This is what distinguishes item 3's fix from the earlier revision: the old
	 * check only tested `blog_id > 0` and `actor_user_id > 0`, which a forged
	 * `['blog_id' => 1, 'actor_user_id' => 1]` satisfies trivially. The new
	 * check independently RE-PROVES hall context, matches the context against
	 * `get_current_blog_id()`/`get_current_user_id()`, capability and schema —
	 * so a forged context is refused regardless of whether its numbers happen to
	 * be positive.
	 */
	private function forged_context_group( int $hall ): void {
		$this->group( 'Forged authorization context' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Forged Bingo' );
		$session_id = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Forged Occurrence', true, $package_id );
		$row        = $this->row( $session_id );

		$availability = new OccurrenceAvailability();

		/* --- a POSITIVE but entirely fabricated context, matching neither the
		 * current hall nor the current user. The old check would have accepted
		 * this outright. --- */
		$forged = [ 'blog_id' => 999999, 'actor_user_id' => 999999, 'actor_is_super' => true ];
		$this->same(
			'session_management_denied',
			$this->error_code( $availability->turn_on( $session_id, $row, $forged ) ),
			'a positive but forged context (wrong hall AND wrong user) is refused for ON'
		);
		$this->same(
			'session_management_denied',
			$this->error_code( $availability->turn_off( $session_id, $row, $forged ) ),
			'  and for OFF'
		);
		$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], '  with no write performed' );
		$this->same( null, $this->row( $session_id )['availability_operation_token'], '  and no token stamped' );

		/* --- a context naming a MISMATCHED hall — a real, positive blog_id, but
		 * not this one — while the actor_user_id is genuinely correct. Proves the
		 * blog_id comparison independently of the actor_user_id comparison. --- */
		$other_hall = $this->create_disposable_site();
		$this->ok( $other_hall > 0 && $other_hall !== $hall, 'a second disposable hall exists to forge a mismatched blog_id' );

		$mismatched_hall = [ 'blog_id' => $other_hall, 'actor_user_id' => $this->super_admin_id, 'actor_is_super' => true ];
		$this->same(
			'session_management_denied',
			$this->error_code( $availability->turn_on( $session_id, $row, $mismatched_hall ) ),
			'a context naming a DIFFERENT hall (correct user, wrong blog_id) is refused'
		);
		$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], '  with no write performed' );

		/* --- an UNPRIVILEGED current user: the context correctly names the
		 * current hall and the current user, but the current user genuinely
		 * lacks bc_manage_sessions. Proves the capability check runs
		 * independently of, and cannot be bypassed by, a context that
		 * "correctly" names an unauthorized user. --- */
		$unprivileged = $this->make_unprivileged_user( $hall );
		if ( $unprivileged > 0 ) {
			$previous = get_current_user_id();
			wp_set_current_user( $unprivileged );

			$honest_but_unprivileged = [ 'blog_id' => get_current_blog_id(), 'actor_user_id' => $unprivileged, 'actor_is_super' => false ];
			$this->same(
				'session_management_denied',
				$this->error_code( $availability->turn_on( $session_id, $row, $honest_but_unprivileged ) ),
				'a context that correctly names the current hall and user is STILL refused when that user lacks bc_manage_sessions'
			);

			wp_set_current_user( $previous );
			$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], '  with no write performed' );
		}

		/* --- the GENUINE authorized context, for contrast: it must succeed,
		 * proving the three refusals above are not failing for an unrelated
		 * reason. --- */
		$context = [ 'blog_id' => get_current_blog_id(), 'actor_user_id' => get_current_user_id(), 'actor_is_super' => is_super_admin() ];
		$genuine = $availability->turn_on( $session_id, $this->row( $session_id ), $context );
		$this->ok( $genuine instanceof SessionResult, 'the GENUINE context succeeds, for contrast: ' . $this->describe( $genuine ) );

		( new SessionService( [ $this, 'swallow_audit' ] ) )->setAvailableForSale( $session_id, false );
		restore_current_blog();
	}

	/* ================================================================== *
	 * Authorization and cross-hall refusal
	 * ================================================================== */

	private function authorization_group( int $hall ): void {
		$this->group( 'Authorization' );
		switch_to_blog( $hall );

		[ $schedule_id, $package_id ] = $this->make_schedule( $hall, 'Auth Bingo' );
		$session_id = $this->plant_occurrence( $hall, $schedule_id, $this->local_date( '+2 days' ), 'Auth Occurrence', true, $package_id );
		$service    = new SessionService( [ $this, 'swallow_audit' ] );

		$previous = get_current_user_id();

		/* --- signed out --- */
		wp_set_current_user( 0 );
		$this->same( 'authentication_required', $this->error_code( $service->setAvailableForSale( $session_id, true ) ), 'a signed-out ON is refused' );
		$this->same( 'authentication_required', $this->error_code( $service->setAvailableForSale( $session_id, false ) ), 'a signed-out OFF is refused' );
		wp_set_current_user( $previous );
		$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], '  and nothing was written' );

		/* --- signed in, but without bc_manage_sessions --- */
		$unprivileged = $this->make_unprivileged_user( $hall );
		if ( $unprivileged > 0 ) {
			wp_set_current_user( $unprivileged );
			$this->same(
				'session_management_denied',
				$this->error_code( $service->setAvailableForSale( $session_id, true ) ),
				'an unauthorized signed-in user cannot turn an occurrence ON'
			);
			wp_set_current_user( $previous );
			$this->same( null, $this->row( $session_id )['availability_operation_token'], '  and no token was written' );
		}

		/* --- the collaborator refuses a context that did not come from an
		 * authorization, so it cannot be wired up to bypass the service. --- */
		$availability = new OccurrenceAvailability();
		$row          = $this->row( $session_id );
		$this->same(
			'session_management_denied',
			$this->error_code( $availability->turn_on( $session_id, $row, [] ) ),
			'OccurrenceAvailability refuses an empty authorization context'
		);
		$this->same(
			'session_management_denied',
			$this->error_code( $availability->turn_off( $session_id, $row, [ 'blog_id' => 0, 'actor_user_id' => 0 ] ) ),
			'  and an unauthenticated one'
		);

		/* --- CROSS-HALL: hall B cannot reach hall A's occurrence.
		 *
		 * Per-site `$wpdb->prefix` is the isolation boundary, so hall A's
		 * session_id simply is not addressable from hall B. The refusal is
		 * asserted rather than assumed. */
		restore_current_blog();
		$other = $this->create_disposable_site();
		$this->ok( $other > 0 && $other !== $hall, 'a second disposable hall exists for the isolation check' );

		switch_to_blog( $other );
		$foreign = new SessionService( [ $this, 'swallow_audit' ] );
		$this->same(
			'session_not_found',
			$this->error_code( $foreign->setAvailableForSale( $session_id, true ) ),
			'hall B cannot turn hall A\'s occurrence ON — it is not addressable there'
		);
		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'and hall B cannot read its sellability either' );
		restore_current_blog();

		switch_to_blog( $hall );
		$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], 'hall A\'s occurrence is untouched by the cross-hall attempt' );
		$this->same( null, $this->row( $session_id )['availability_operation_token'], '  with no token written' );
		restore_current_blog();
	}

	/* ================================================================== *
	 * Barriers and injected read failure
	 * ================================================================== */

	/**
	 * Run `$callback` exactly once, immediately BEFORE the `$nth` statement
	 * matching `$pattern` is issued.
	 *
	 * `$nth` exists because several scenarios need the SECOND occurrence of a
	 * statement the request issues twice — the Package read that serves both the
	 * pre-proof and the post-proof. Firing on the first would suspend the request
	 * before it had written anything, which is a different scenario.
	 */
	private function barrier( string $name, string $pattern, callable $callback, int $nth = 1 ): void {
		$this->barriers[ $name ] = [
			'pattern'  => $pattern,
			'callback' => $callback,
			'nth'      => $nth,
			'seen'     => 0,
			'fired'    => false,
			'running'  => false,
		];
	}

	private function assert_barrier_fired( string $name, string $message ): void {
		$this->ok( ! empty( $this->barriers[ $name ]['fired'] ), $message );
		unset( $this->barriers[ $name ] );
	}

	/** @internal Public only because WordPress needs a callable. */
	public function observe_query( string $sql ): string {
		foreach ( $this->barriers as $name => $barrier ) {
			if ( $barrier['fired'] || $barrier['running'] ) {
				continue;
			}
			if ( 1 !== preg_match( $barrier['pattern'], $sql ) ) {
				continue;
			}

			$this->barriers[ $name ]['seen'] = $barrier['seen'] + 1;
			if ( $this->barriers[ $name ]['seen'] < $barrier['nth'] ) {
				continue;
			}

			$this->barriers[ $name ]['fired']   = true;
			$this->barriers[ $name ]['running'] = true;
			try {
				call_user_func( $barrier['callback'] );
			} finally {
				if ( isset( $this->barriers[ $name ] ) ) {
					$this->barriers[ $name ]['running'] = false;
				}
			}

			// One barrier per statement: a callback's own statements must not be
			// able to trigger a second barrier in the same frame.
			break;
		}

		if ( '' !== $this->break_pattern && 1 === preg_match( $this->break_pattern, $sql ) ) {
			// Deterministic read failure: a table that cannot exist, so
			// $wpdb->last_error is set exactly as a genuine failure would set it.
			return 'SELECT 1 FROM bc_avail1_table_that_cannot_exist';
		}

		return $sql;
	}

	private string $break_pattern = '';

	private function break_reads( string $pattern ): void {
		$this->break_pattern = $pattern;
	}

	private function restore_reads(): void {
		$this->break_pattern = '';
	}

	/* ================================================================== *
	 * Fixtures
	 * ================================================================== */

	/**
	 * A committed schedule on a freshly planted template, with one assignable
	 * Package.
	 *
	 * The SCHEDULE is created through the real SessionScheduleService, so its
	 * `active_definition_token` is a genuine committed token rather than a value
	 * this harness invented — which matters, because the guarded ON proves that
	 * exact token in SQL.
	 *
	 * @return array{0:int,1:int,2:int} schedule_id, package_id, portal_session_id
	 */
	private function make_schedule( int $hall, string $label ): array {
		$template = $this->plant_template( $label );
		$package  = $this->make_package( $label );

		$result = ( new SessionScheduleService( [ $this, 'swallow_audit' ] ) )
			->create( $template, 'dates', '18:00', [ $this->local_date( '+2 days' ) ], [ $package ] );

		if ( ! $result instanceof SessionScheduleResult || SessionScheduleResult::STATUS_OK !== $result->status ) {
			$this->ok( false, "fixture schedule creation failed for {$label}: " . $this->describe( $result ) );

			return [ 0, $package, $template ];
		}

		return [ (int) $result->schedule['schedule_id'], $package, $template ];
	}

	/**
	 * Plant one generated occurrence.
	 *
	 * STANDING IN FOR OccurrenceGenerationWriter, which is Increment 4. The shape
	 * is exactly what §2.4 specifies for a generated row, and `$complete`
	 * controls the one field that decides sellability at the SQL level.
	 * `$package_id` of 0 plants an occurrence with NO Package, for the
	 * no-assignable-Package path.
	 */
	private function plant_occurrence( int $hall, int $schedule_id, string $date, string $name, bool $complete, int $package_id ): int {
		global $wpdb;

		$this->assert_disposable_hall( $hall );

		// These older availability fixtures stand in for generation. Keep their
		// dates in the committed explicit definition, as real generation does.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Migrations::schedule_dates_table() . ' (schedule_id, definition_token, schedule_date, created_at)
				 SELECT schedule_id, active_definition_token, %s, %s FROM ' . Migrations::schedules_table() . " sch
				 WHERE schedule_id = %d AND mode = 'dates' AND NOT EXISTS (
				 SELECT 1 FROM " . Migrations::schedule_dates_table() . ' d WHERE d.schedule_id = sch.schedule_id
				 AND d.definition_token = sch.active_definition_token AND d.schedule_date = %s)',
				$date,
				current_time( 'mysql', true ),
				$schedule_id,
				$date
			)
		);

		$now      = current_time( 'mysql', true );
		$instant  = \BingoCommerce\Sessions\ScheduleTimezone::exact_instant( $date, '18:00:00', self::ZONE );
		$starts   = is_wp_error( $instant ) ? null : $instant;

		$wpdb->insert(
			Migrations::sessions_table(),
			[
				'session_date'            => $date,
				'display_name'            => self::PREFIX . ' ' . $name . ' ' . wp_generate_password( 5, false ),
				'available_for_sale'      => 0,
				'status'                  => 'active',
				'created_at'              => $now,
				'updated_at'              => $now,
				'schedule_id'             => $schedule_id,
				'start_time_local'        => '18:00:00',
				'timezone_name'           => self::ZONE,
				'starts_at_utc'           => $starts,
				'generation_completed_at' => $complete ? $now : null,
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		$session_id = (int) $wpdb->insert_id;
		$this->ok( $session_id > 0, "planted occurrence '{$name}' exists" );

		if ( $package_id > 0 && $session_id > 0 ) {
			$wpdb->insert(
				Migrations::session_packages_table(),
				[ 'session_id' => $session_id, 'package_id' => $package_id, 'created_at' => $now ],
				[ '%d', '%d', '%s' ]
			);
		}

		return $session_id;
	}

	/** BR-078: drive real definition saves, generation, sales and admin reads. */
	private function removed_definition_group(): void {
		global $wpdb;

		$this->group( 'Removed definition membership' );
		$hall = $this->create_disposable_site();
		switch_to_blog( $hall );
		wp_set_current_user( $this->super_admin_id );
		$schedules = new SessionScheduleService( [ $this, 'swallow_audit' ] );
		$sessions  = new SessionService( [ $this, 'swallow_audit' ] );
		$handler   = new ReflectionMethod( \BingoCommerce\Sessions\SessionAdmin::class, 'handle_request' );
		$handler->setAccessible( true );
		$date = $this->local_date( '+2 days' );
		$end  = $this->local_date( '+9 days' );
		$mask = 1 << ( (int) ( new DateTimeImmutable( $date ) )->format( 'N' ) - 1 );

		foreach ( [ 'explicit', 'weekly_bounds', 'weekly_weekday' ] as $case ) {
			$template = $this->plant_template( 'Removed ' . $case );
			$package  = $this->make_package( 'Removed ' . $case );
			$weekly   = 'explicit' !== $case;
			$mode     = $weekly ? 'weekly' : 'dates';
			$created  = $schedules->create( $template, $mode, '18:00', $weekly ? [] : [ $date, $end ], [ $package ], $weekly ? $date : '', $weekly ? $end : '', $weekly ? $mask : 0 );
			$id = $created instanceof SessionScheduleResult ? (int) $created->schedule['schedule_id'] : 0;
			$this->ok( $id > 0, "$case: committed schedule exists" );
			$generated = $schedules->generate( $id );
			$this->ok( $generated instanceof SessionScheduleResult && $generated->complete, "$case: generation completed" );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::sessions_table() . ' WHERE schedule_id = %d ORDER BY session_date', $id ), ARRAY_A );
			$this->same( 2, count( $rows ), "$case: exactly two occurrences generated" );
			$session_id = (int) $rows[0]['session_id'];
			$this->same( true, SessionEligibility::belongs_to_current_definition( $session_id ), "$case: original date is a member, including the weekly lower bound" );
			$this->same( true, SessionEligibility::belongs_to_current_definition( (int) $rows[1]['session_id'] ), "$case: the upper bound is inclusive" );
			$this->ok( $sessions->setAvailableForSale( $session_id, true ) instanceof SessionResult, "$case: original date can be enabled" );
			$before = $this->row( $session_id );
			$packages = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d', $session_id ), ARRAY_A );
			$removed_start = 'weekly_bounds' === $case ? $end : $date;
			$removed_mask  = 'weekly_weekday' === $case ? ( ( $mask << 1 ) > 127 ? 1 : $mask << 1 ) : $mask;
			$edited = $schedules->edit( $id, $mode, '18:00', $weekly ? [] : [ $end ], [ $package ], $weekly ? $removed_start : '', $weekly ? $end : '', $weekly ? $removed_mask : 0 );
			$this->ok( $edited instanceof SessionScheduleResult && $edited->complete, "$case: removing an ON date is allowed" );
			$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], "$case: successful removal clears sales before any retry" );
			$this->ok( $sessions->setAvailableForSale( $session_id, false ) instanceof SessionResult, "$case: manual Disable Sales remains safe" );
			$retry = $schedules->generate( $id );
			$this->ok( $retry instanceof SessionScheduleResult && $retry->complete, "$case: retry uses existing protected-history rules" );
			$this->same( false, SessionEligibility::belongs_to_current_definition( $session_id ), "$case: removed date is outside the committed definition" );
			$upcoming = $sessions->listForAdministration( SessionService::ADMIN_STATUS_UPCOMING, 1, 200 );
			$this->ok( is_array( $upcoming ) && ! in_array( $session_id, array_column( $upcoming, 'session_id' ), true ), "$case: removed date is absent from Upcoming" );
			$all = $sessions->listForAdministration( 'all', 1, 200 );
			$this->ok( is_array( $all ) && in_array( $session_id, array_column( $all, 'session_id' ), true ), "$case: history is reachable through All" );
			$this->same( $before['sale_locked_at'], $this->row( $session_id )['sale_locked_at'], "$case: sale lock is preserved" );
			$this->same( $packages, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d', $session_id ), ARRAY_A ), "$case: Package assignments are byte-identical" );

			$saved_get = $_GET;
			$saved_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
			$_GET = [ 'status' => 'all' ];
			$_SERVER['REQUEST_METHOD'] = 'GET';
			ob_start();
			\BingoCommerce\Sessions\SessionAdmin::render();
			$html = (string) ob_get_clean();
			$_GET = $saved_get;
			$_SERVER['REQUEST_METHOD'] = $saved_method;
			$row_html = $this->extract_row_html( $html, $session_id );
			$this->ok( '' !== $row_html && false === strpos( $row_html, 'Enable Sales' ), "$case: retained row renders without Enable Sales" );
			$this->same( 'outside_current_definition', $this->error_code( $sessions->setAvailableForSale( $session_id, true ) ), "$case: forced service ON is refused" );
			$forced = $handler->invoke( null, [ 'bc_session_action' => 'enable_sales', 'session_id' => (string) $session_id ], $sessions );
			$this->same( 'outside_current_definition', $this->error_code( $forced ), "$case: forced admin handler ON is refused" );
			$direct = ( new OccurrenceAvailability() )->turn_on(
				$session_id,
				$this->row( $session_id ),
				[ 'blog_id' => $hall, 'actor_user_id' => $this->super_admin_id, 'actor_is_super' => true ]
			);
			$this->same( 'outside_current_definition', $this->error_code( $direct ), "$case: the final SQL guard independently refuses direct occurrence ON" );
			$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], "$case: direct refusal performs no ON write" );

			// A stale ON flag must not bypass purchase eligibility or the no-op path.
			$wpdb->update( Migrations::sessions_table(), [ 'available_for_sale' => 1 ], [ 'session_id' => $session_id ] );
			$this->same( false, SessionEligibility::is_sellable( $session_id ), "$case: stale ON is unavailable for purchase" );
			$this->break_reads( '/SELECT \(s\.schedule_id IS NULL OR EXISTS/' );
			$unknown = SessionEligibility::is_sellable( $session_id );
			$this->restore_reads();
			$this->same( 'sellability_unknown', $this->error_code( $unknown ), "$case: an unreadable membership check fails closed" );
			$this->same( 'outside_current_definition', $this->error_code( $sessions->setAvailableForSale( $session_id, true ) ), "$case: already-ON service request still refuses removed membership" );
			$sessions->setAvailableForSale( $session_id, false );

			$readded = $schedules->edit( $id, $mode, '18:00', $weekly ? [] : [ $date, $end ], [ $package ], $weekly ? $date : '', $weekly ? $end : '', $weekly ? $mask : 0 );
			$this->ok( $readded instanceof SessionScheduleResult && $readded->complete, "$case: date can be re-added" );
			$schedules->generate( $id );
			$restored = $this->row( $session_id );
			$this->same( 0, (int) $restored['available_for_sale'], "$case: re-add and generation preserve OFF" );
			$this->same( $session_id, (int) $restored['session_id'], "$case: the same historical identity survives re-add" );
			$this->same( true, SessionEligibility::belongs_to_current_definition( $session_id ), "$case: re-added date is a member again" );
			// Repeat without manual OFF: the save itself prevents automatic resale.
			$sessions->setAvailableForSale( $session_id, true );
			$schedules->edit( $id, $mode, '18:00', $weekly ? [] : [ $end ], [ $package ], $weekly ? $removed_start : '', $weekly ? $end : '', $weekly ? $removed_mask : 0 );
			$schedules->edit( $id, $mode, '18:00', $weekly ? [] : [ $date, $end ], [ $package ], $weekly ? $date : '', $weekly ? $end : '', $weekly ? $mask : 0 );
			$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], "$case: remove and re-add without manual OFF does not resume sales" );
			$schedules->edit( $id, $mode, '18:00', $weekly ? [] : [ $end ], [ $package ], $weekly ? $removed_start : '', $weekly ? $end : '', $weekly ? $removed_mask : 0 );
			// Existing archived history is planted; this correction introduces no
			// new archival writer. Existing reconciliation must restore it OFF.
			$wpdb->update( Migrations::sessions_table(), [ 'status' => 'archived', 'archived_at' => current_time( 'mysql', true ) ], [ 'session_id' => $session_id ] );
			$archived = $sessions->listForAdministration( 'archived', 1, 200 );
			$this->ok( is_array( $archived ) && in_array( $session_id, array_column( $archived, 'session_id' ), true ), "$case: removed archived history remains reachable" );
			$schedules->edit( $id, $mode, '18:00', $weekly ? [] : [ $date, $end ], [ $package ], $weekly ? $date : '', $weekly ? $end : '', $weekly ? $mask : 0 );
			$schedules->generate( $id );
			$this->same( 'active', $this->row( $session_id )['status'], "$case: existing restoration reuses the archived identity" );
			$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], "$case: archived restoration remains OFF" );
			$this->same( $packages, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Migrations::session_packages_table() . ' WHERE session_id = %d', $session_id ), ARRAY_A ), "$case: restored archived history preserves Packages" );
		}

		/*
		 * Deterministic remove/re-add race. Request A has committed its removal
		 * when its captured OFF statement reaches the query filter. Request B then
		 * re-adds the date before A's statement is allowed to execute. No timing or
		 * sleep is involved: the statement itself is the barrier.
		 */
		$template = $this->plant_template( 'Concurrent remove re-add' );
		$package  = $this->make_package( 'Concurrent remove re-add' );
		$created  = $schedules->create( $template, 'dates', '18:00', [ $date, $end ], [ $package ] );
		$schedule_id = $created instanceof SessionScheduleResult ? (int) $created->schedule['schedule_id'] : 0;
		$this->ok( $schedule_id > 0, 'concurrent remove/re-add: committed schedule exists' );
		$generated = $schedules->generate( $schedule_id );
		$this->ok( $generated instanceof SessionScheduleResult && $generated->complete, 'concurrent remove/re-add: generation completed' );
		$occurrence = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, session_date FROM ' . Migrations::sessions_table() . ' WHERE schedule_id = %d AND session_date = %s',
				$schedule_id,
				$date
			),
			ARRAY_A
		);
		$session_id = is_array( $occurrence ) ? (int) $occurrence['session_id'] : 0;
		$this->ok( $session_id > 0, 'concurrent remove/re-add: future occurrence exists' );
		$this->ok( $sessions->setAvailableForSale( $session_id, true ) instanceof SessionResult, 'concurrent remove/re-add: occurrence can be enabled' );
		$this->same( true, SessionEligibility::is_sellable( $session_id ), 'concurrent remove/re-add: occurrence is genuinely purchasable before removal' );

		$off_pattern = '/UPDATE\s+' . preg_quote( Migrations::sessions_table(), '/' ) . '\s+SET\s+available_for_sale = 0, availability_operation_token = NULL.*session_id IN \(/s';
		$this->barrier(
			'concurrent_remove_readd_off',
			$off_pattern,
			function () use ( $schedules, $schedule_id, $date, $end, $package, $session_id ): void {
				$this->same( false, SessionEligibility::belongs_to_current_definition( $session_id ), 'concurrent remove/re-add: A committed removal before its OFF write' );
				$readded = $schedules->edit( $schedule_id, 'dates', '18:00', [ $date, $end ], [ $package ] );
				$this->ok( $readded instanceof SessionScheduleResult && $readded->complete, 'concurrent remove/re-add: B commits the re-add while A is paused' );
				$this->same( true, SessionEligibility::belongs_to_current_definition( $session_id ), 'concurrent remove/re-add: current definition contains the re-added date before A resumes' );
				$this->same( 0, (int) $this->row( $session_id )['available_for_sale'], 'concurrent remove/re-add: B turns the occurrence OFF before publishing the re-add' );
				$this->same( false, SessionEligibility::is_sellable( $session_id ), 'concurrent remove/re-add: no purchase window opens before A resumes' );
			}
		);

		$removed = $schedules->edit( $schedule_id, 'dates', '18:00', [ $end ], [ $package ] );
		$this->assert_barrier_fired( 'concurrent_remove_readd_off', 'concurrent remove/re-add: A paused after its CAS and before its captured OFF write' );
		$this->ok( $removed instanceof SessionScheduleResult && $removed->complete, 'concurrent remove/re-add: A completes after B re-adds the date' );
		$after = $this->row( $session_id );
		$this->same( $session_id, (int) $after['session_id'], 'concurrent remove/re-add: occurrence identity is preserved' );
		$this->same( $date, (string) $after['session_date'], 'concurrent remove/re-add: occurrence date is preserved' );
		$this->same( true, SessionEligibility::belongs_to_current_definition( $session_id ), 'concurrent remove/re-add: occurrence belongs to the current re-added definition' );
		$this->same( 0, (int) $after['available_for_sale'], 'concurrent remove/re-add: A still turns the captured occurrence OFF' );
		$this->same( false, SessionEligibility::is_sellable( $session_id ), 'concurrent remove/re-add: purchase eligibility remains false after re-add' );
		$this->ok( $sessions->setAvailableForSale( $session_id, true ) instanceof SessionResult, 'concurrent remove/re-add: a later explicit Enable Sales passes existing guards' );
		$this->same( 1, (int) $this->row( $session_id )['available_for_sale'], 'concurrent remove/re-add: only the later explicit request returns it to sale' );
		$this->same( true, SessionEligibility::is_sellable( $session_id ), 'concurrent remove/re-add: explicit Enable Sales restores purchase eligibility' );
		restore_current_blog();
	}

	private function plant_template( string $name ): int {
		global $wpdb;

		static $next = 0;
		++$next;
		$portal_session_id = 950000 + $next;
		$now               = current_time( 'mysql', true );

		$wpdb->insert(
			Migrations::portal_session_templates_table(),
			[
				'portal_session_id' => $portal_session_id,
				'name'              => self::PREFIX . ' ' . $name,
				'portal_status'     => 'active',
				'is_current'        => 1,
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
		$product = new WC_Product_Simple();
		$product->set_name( self::PREFIX . ' ' . $label . ' ' . wp_generate_password( 5, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );

		return (int) $product->save();
	}

	private function make_unprivileged_user( int $hall ): int {
		global $wpdb;

		$login = 'avail1-unpriv-' . wp_generate_password( 8, false );
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

		// ADR-025's HallStaffWriterGuard refuses add_user_to_blog() for hall
		// roles, so the capability row is planted directly — the precedent
		// tests/hall-staff-create-invite.php already sets.
		$key = $wpdb->get_blog_prefix( $hall ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => (int) $user, 'meta_key' => $key ] );
		$wpdb->insert(
			$wpdb->usermeta,
			[ 'user_id' => (int) $user, 'meta_key' => $key, 'meta_value' => serialize( [ 'subscriber' => true ] ) ]
		);
		clean_user_cache( (int) $user );

		if ( user_can( (int) $user, 'bc_manage_sessions' ) || is_super_admin( (int) $user ) ) {
			$this->ok( false, 'the unprivileged fixture user unexpectedly holds Session-management rights' );

			return 0;
		}

		return (int) $user;
	}

	/** @return array<string,mixed> */
	private function row( int $session_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT session_id, session_date, display_name, available_for_sale, status, schedule_id,
				        start_time_local, timezone_name, starts_at_utc, sale_locked_at,
				        generation_completed_at, availability_operation_token
				   FROM ' . Migrations::sessions_table() . ' WHERE session_id = %d',
				$session_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : [];
	}

	private function local_date( string $modifier ): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( self::ZONE ) ) )->modify( $modifier )->format( 'Y-m-d' );
	}

	/* ================================================================== *
	 * §16 E32 — token-level available_for_sale ON-writer enumeration
	 * ================================================================== */

	/** The Sessions plugin's own root — everything under it is scanned, recursively. */
	private function sessions_plugin_root(): string {
		return dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-sessions';
	}

	/**
	 * Every `.php` file under `$root`, RECURSIVELY — the plugin bootstrap at
	 * the root, every file under `src/`, and any subdirectory a later
	 * increment adds. `RecursiveDirectoryIterator` is used rather than `glob()`
	 * precisely because `glob( $dir . '/*.php' )` — this scan's own earlier
	 * form — only ever sees ONE directory: it does not walk into `src/`, let
	 * alone anything nested under it.
	 *
	 * @return array<int,string> absolute paths
	 */
	private function recursive_php_files( string $root ): array {
		$found = [];
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file instanceof SplFileInfo && 'php' === strtolower( $file->getExtension() ) ) {
				$found[] = $file->getPathname();
			}
		}
		sort( $found );

		return $found;
	}

	/**
	 * Every `available_for_sale` writer CAPABLE OF SETTING IT ON, found in the
	 * Sessions plugin's own source, by TOKEN-LEVEL scan — never by matching
	 * against raw file text, which cannot distinguish executable code from a
	 * comment describing it. Scanned RECURSIVELY from the plugin root, not
	 * merely `src/*.php`, so the bootstrap file and any subdirectory a later
	 * increment adds are covered without this method needing to change.
	 *
	 * NOT an OFF-writer enumeration. `compensate()` and `turn_off()` write
	 * `available_for_sale = 0` and are correctly absent from this list — E32's
	 * own control, in the calling test, proves that absence directly.
	 *
	 * @return array<int,array{path:string,method:string,kind:string,owner:string}>
	 */
	private function enumerate_available_for_sale_on_writers(): array {
		$root    = $this->sessions_plugin_root();
		$writers = [];

		foreach ( $this->recursive_php_files( $root ) as $file ) {
			$relative = ltrim( substr( $file, strlen( $root ) ), '/' );
			foreach ( $this->scan_file_for_on_writers( $file, $relative ) as $writer ) {
				$writers[] = $writer;
			}
		}

		return $writers;
	}

	/**
	 * Group ONE file's tokens by enclosing function/method, dropping comments,
	 * docblocks and whitespace before anything is analysed — so text that only
	 * ever existed inside a `/* ... *\/` or `// ...` comment can never reach
	 * either detector below, structurally, not by a filter that could be wrong.
	 *
	 * @return array<int,array{path:string,method:string,kind:string,owner:string}>
	 */
	private function scan_file_for_on_writers( string $file, string $relative_path ): array {
		$source = (string) file_get_contents( $file );
		$tokens = token_get_all( $source );

		/** @var array<string,array<int,array{0:int|string,1:string}>> */
		$by_function = [];
		$depth       = 0;
		/** @var array<int,array{name:string,depth:int}> */
		$stack      = [];
		$pending_fn = null; // '' once T_FUNCTION seen; the name once T_STRING seen after it.

		foreach ( $tokens as $token ) {
			if ( is_string( $token ) ) {
				$id   = -1;
				$text = $token;
			} else {
				[ $id, $text ] = $token;
				if ( in_array( $id, [ T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ], true ) ) {
					continue;
				}
			}

			if ( '{' === $text ) {
				++$depth;
				if ( is_string( $pending_fn ) && '' !== $pending_fn ) {
					$stack[]    = [ 'name' => $pending_fn, 'depth' => $depth ];
					$pending_fn = null;
				}
				continue;
			}
			if ( '}' === $text ) {
				if ( [] !== $stack && end( $stack )['depth'] === $depth ) {
					array_pop( $stack );
				}
				--$depth;
				continue;
			}
			if ( ';' === $text && is_string( $pending_fn ) ) {
				// An interface/abstract signature with no body.
				$pending_fn = null;
			}

			if ( T_FUNCTION === $id ) {
				$pending_fn = '';
				continue;
			}
			if ( is_string( $pending_fn ) && '' === $pending_fn && T_STRING === $id ) {
				$pending_fn = $text;
				continue;
			}

			if ( [] !== $stack ) {
				$by_function[ end( $stack )['name'] ][] = [ $id, $text ];
			}
		}

		$writers = [];
		foreach ( $by_function as $method => $fn_tokens ) {
			foreach ( $this->classify_function_tokens_for_on_write( $fn_tokens ) as $kind ) {
				$writers[] = [
					'path'   => $relative_path,
					'method' => $method,
					'kind'   => $kind,
					'owner'  => 'guarded_on' === $method ? 'schedule_owned' : 'legacy',
				];
			}
		}

		return $writers;
	}

	/**
	 * The three independent ON-WRITE detectors, applied to ONE function's
	 * already comment-free token list. An OFF-write (`= 0`) never satisfies
	 * any of the three: detector 1 matches the literal `1`, never `0`;
	 * detectors 2 and 3 both require the `? 1 : 0` ternary shape specifically.
	 *
	 * @param array<int,array{0:int|string,1:string}> $tokens
	 * @return array<int,string> zero or more of 'sql_unconditional', 'sql_conditional', 'array_literal'
	 */
	private function classify_function_tokens_for_on_write( array $tokens ): array {
		$sql_blob      = '';
		$has_wpdb      = false;
		$has_query     = false;
		$has_apply_fc  = false;
		$has_ternary   = false;
		$array_literal = false;

		foreach ( $tokens as $i => [ $id, $text ] ) {
			if ( in_array( $id, [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ], true ) ) {
				$sql_blob .= $text . "\n";

				if ( "'available_for_sale'" === trim( $text ) ) {
					$next = $tokens[ $i + 1 ] ?? null;
					if ( is_array( $next ) && T_DOUBLE_ARROW === $next[0] ) {
						$array_literal = true;
					}
				}
			}

			if ( T_VARIABLE === $id && '$wpdb' === $text ) {
				$has_wpdb = true;
			}
			if ( T_STRING === $id && 'apply_field_change' === $text ) {
				$next = $tokens[ $i + 1 ] ?? null;
				if ( is_array( $next ) && '(' === $next[1] ) {
					$has_apply_fc = true;
				}
			}
			if ( T_STRING === $id && 'query' === $text ) {
				$has_query = true;
			}

			// The token-level ternary `$available… ? 1 : 0`: a T_VARIABLE whose
			// name contains "available", then '?', T_LNUMBER('1'), ':', T_LNUMBER('0').
			if ( T_VARIABLE === $id && false !== strpos( $text, 'available' ) ) {
				$t1 = $tokens[ $i + 1 ] ?? null;
				$t2 = $tokens[ $i + 2 ] ?? null;
				$t3 = $tokens[ $i + 3 ] ?? null;
				$t4 = $tokens[ $i + 4 ] ?? null;
				if (
					is_array( $t1 ) && '?' === $t1[1]
					&& is_array( $t2 ) && T_LNUMBER === $t2[0] && '1' === $t2[1]
					&& is_array( $t3 ) && ':' === $t3[1]
					&& is_array( $t4 ) && T_LNUMBER === $t4[0] && '0' === $t4[1]
				) {
					$has_ternary = true;
				}
			}
		}

		$kinds = [];

		// Detector 1: SQL unconditional write — `available_for_sale = 1` whose
		// nearest preceding word is NOT "AND".
		if ( preg_match_all( '/(AND\s+)?(?:[a-z_][a-z0-9_]*\.)?available_for_sale\s*=\s*1\b/i', $sql_blob, $matches, PREG_SET_ORDER ) > 0 ) {
			foreach ( $matches as $m ) {
				if ( '' === trim( (string) ( $m[1] ?? '' ) ) ) {
					$kinds[] = 'sql_unconditional';
				}
			}
		}

		/*
		 * Detector 2: SQL conditional write — a real write call, bound by a
		 * ternary that CAN write 1, whose SQL text binds `available_for_sale`
		 * to a `%d`/`%s` placeholder. Two sub-shapes are checked, because this
		 * codebase uses both and neither alone covers the other:
		 *
		 *   2a. UPDATE-style: the placeholder sits directly against the column
		 *       name — `available_for_sale = %d`.
		 *   2b. INSERT-style: the column name and its placeholder are in TWO
		 *       separate parenthesized lists (`(col, col, available_for_sale)`
		 *       ... `VALUES (%s, %s, %d)`), matched POSITIONALLY — checked by
		 *       insert_style_conditional_write() below.
		 *
		 * An earlier version of this detector checked only "does the blob
		 * contain available_for_sale ANYWHERE, and %d/%s ANYWHERE" — which is
		 * satisfied by any UNRELATED placeholder in the same statement, so a
		 * mutation that hardcoded `available_for_sale = 0` while leaving other
		 * `%s` placeholders in the same string went UNDETECTED by this
		 * assertion (it was caught only by an unrelated F50 behavioural
		 * assertion). Proven by mutation and corrected to require adjacency —
		 * direct for 2a, positional for 2b.
		 */
		if (
			$has_wpdb && $has_query && $has_ternary
			&& (
				1 === preg_match( '/available_for_sale\s*=\s*%[ds]\b/', $sql_blob )
				|| $this->insert_style_conditional_write( $sql_blob )
			)
		) {
			$kinds[] = 'sql_conditional';
		}

		// Detector 3: array-literal write — only when the SAME function also
		// writes somewhere (via $wpdb or apply_field_change()), which is exactly
		// what excludes audit_payload() and a schema-verification array of
		// identical shape.
		if ( $array_literal && ( $has_wpdb || $has_apply_fc ) ) {
			$kinds[] = 'array_literal';
		}

		return $kinds;
	}

	/**
	 * The INSERT-style shape of Detector 2 (§16 E32): the column name and its
	 * bound placeholder live in TWO SEPARATE parenthesized lists — a column
	 * list, then a VALUES list — so adjacency in the TEXT proves nothing; only
	 * adjacency in POSITION does. This finds the first parenthesized group
	 * containing `available_for_sale`, treats it as the column list, takes the
	 * NEXT parenthesized group as the VALUES list, and checks whether the
	 * placeholder at the SAME index is `%d` or `%s`.
	 */
	private function insert_style_conditional_write( string $blob ): bool {
		if ( false === strpos( $blob, 'available_for_sale' ) ) {
			return false;
		}
		if ( 0 === preg_match_all( '/\(([^()]*)\)/', $blob, $matches ) ) {
			return false;
		}

		$groups = $matches[1];
		foreach ( $groups as $index => $group ) {
			if ( false === strpos( $group, 'available_for_sale' ) ) {
				continue;
			}
			$columns = array_map( 'trim', explode( ',', $group ) );
			$position = array_search( 'available_for_sale', $columns, true );
			if ( false === $position || ! isset( $groups[ $index + 1 ] ) ) {
				continue;
			}
			$values = array_map( 'trim', explode( ',', $groups[ $index + 1 ] ) );
			if ( isset( $values[ $position ] ) && 1 === preg_match( '/^%[ds]$/', $values[ $position ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The first prohibited C0 control byte in a file, or null.
	 *
	 * TAB (0x09), LF (0x0A) and CR (0x0D) are permitted; every other byte below
	 * 0x20, and 0x7F (DEL), is not. This is a BYTE scan, not a regex: the exact
	 * defect this correction exists to catch — `\b` silently becoming a literal
	 * 0x08 — produces a byte a regex over the same string could not reliably
	 * name, since the corrupted byte IS the pattern being searched for.
	 *
	 * @return array{offset:int,hex:string}|null
	 */
	private function find_prohibited_control_byte( string $path ): ?array {
		$bytes = (string) file_get_contents( $path );
		$len   = strlen( $bytes );

		for ( $i = 0; $i < $len; $i++ ) {
			$byte = ord( $bytes[ $i ] );
			if ( $byte < 0x20 && ! in_array( $byte, [ 0x09, 0x0A, 0x0D ], true ) ) {
				return [ 'offset' => $i, 'hex' => str_pad( dechex( $byte ), 2, '0', STR_PAD_LEFT ) ];
			}
			if ( 0x7F === $byte ) {
				return [ 'offset' => $i, 'hex' => '7f' ];
			}
		}

		return null;
	}

	/* ================================================================== *
	 * Site lifecycle, preservation and reporting
	 * ================================================================== */

	private function create_disposable_site(): int {
		add_filter( 'pre_wp_mail', '__return_true' );

		$site = wp_insert_site(
			[
				'domain'     => strtolower( 'avail1' . wp_generate_password( 6, false ) . '.bingocommerce.test' ),
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'title'      => 'AVAIL1 Disposable Hall',
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
			// Action Scheduler's per-site tables, for the same reason the shared
			// fixture creates them: WC_Install does not, and without them every
			// WC_Product->save() in the Package fixtures logs "table doesn't
			// exist" — noise that reads like a defect in the code under test.
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) {
				if ( class_exists( $schema ) ) {
					( new $schema() )->register_tables( true );
				}
			}
			update_option( 'timezone_string', self::ZONE );
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
			. "Only a disposable hall created by this run may be written to structurally.\n"
		);
		exit( 1 );
	}

	private function cleanup(): void {
		$this->barriers      = [];
		$this->break_pattern = '';

		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		foreach ( array_keys( $this->disposable_sites ) as $site_id ) {
			$this->assert_disposable_hall( (int) $site_id );
			if ( get_site( (int) $site_id ) instanceof WP_Site ) {
				wp_delete_site( (int) $site_id );
			}
		}
		$this->disposable_sites = [];

		// Users are NETWORK-global, so unlike everything else they do not go away
		// with the site. Only ids this run recorded are removed, by exact id.
		foreach ( $this->created_users as $user_id ) {
			if ( $user_id > 0 && get_userdata( $user_id ) instanceof WP_User ) {
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
			if ( ! get_site( $site_id ) instanceof WP_Site ) {
				continue;
			}
			switch_to_blog( $site_id );

			$row = [];
			foreach (
				[
					'sessions'  => Migrations::sessions_table(),
					'packages'  => Migrations::session_packages_table(),
					'schedules' => Migrations::schedules_table(),
					'templates' => Migrations::portal_session_templates_table(),
				] as $label => $table
			) {
				$row[ $label ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
			}
			$row['on']     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() . ' WHERE available_for_sale = 1' );
			$row['locked'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() . ' WHERE sale_locked_at IS NOT NULL' );
			$row['tokens'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Migrations::sessions_table() . ' WHERE availability_operation_token IS NOT NULL' );
			$row['version'] = (int) get_option( 'bc_sessions_db_version', 0 );

			$snapshot[ 'site_' . $site_id ] = $row;
			restore_current_blog();
		}

		return $snapshot;
	}

	/** @internal Audit content is covered by tests/session-increment-1.php. */
	public function swallow_audit( array $event ): int {
		return 1;
	}

	/**
	 * An audit SPY, for item 2: refused operations must create NO audit event.
	 *
	 * A count, not a boolean — so "called once when it should have been called
	 * zero times" is distinguishable from "never called", and a caller that
	 * accidentally invokes the recorder twice is caught too.
	 */
	private int $audit_calls = 0;

	/** @internal Public only because it is passed as a callable. */
	public function spy_audit( array $event ): int {
		++$this->audit_calls;

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
		if ( $value instanceof SessionResult ) {
			return 'SessionResult:' . $value->status;
		}
		if ( $value instanceof SessionScheduleResult ) {
			return 'ScheduleResult:' . $value->status . '/' . (string) $value->failure_category;
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
		foreach ( $this->deferred as $item ) {
			echo "DEFERRED: {$item}\n";
		}

		if ( $fail > 0 ) {
			exit( 1 );
		}
	}
}

( new SessionAvailabilityIncrementOneTest() )->run();
