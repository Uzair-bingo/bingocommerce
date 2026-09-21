<?php

/**
 * CLI integration coverage for the read-only SetupReadiness evaluator.
 *
 * Run from the repository root:
 * BC_TEST_DB=bingocommerce_state_test_suites php tests/setup-readiness.php
 *
 * Non-destructive by construction:
 *
 *   - every failure scenario is produced by rewriting a read statement through
 *     WordPress's own `query` filter, so no hall data is ever the target;
 *   - a write GUARD sits on the same filter and substitutes a harmless no-op
 *     for any statement that is not read-only, so an unexpected write is
 *     prevented rather than merely noticed afterwards; and
 *   - the guard runs AFTER fault-injection rewrites, so an injected statement
 *     is policed exactly like an organic one.
 *
 * No raw SQL and no stored option/audit content is ever printed: blocked
 * statements are reported as a leading keyword plus a short digest, and
 * preservation evidence is compared as hashes.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

/*
 * ADR-043 §16 amendment: routed through the shared fixture bootstrap.
 *
 * NOT because this suite creates sites — it creates none and writes nothing.
 * Because of what it ASSERTS. SetupReadiness compares each hall's applied
 * Sessions schema version against `Sessions\Migrations::DB_VERSION` and runs
 * that migration's structural verifier, so the suite's whole premise is that
 * the booted database is at the version the CODE declares. ADR-043 raises
 * DB_VERSION to 3 while deliberately leaving the development installation at
 * 2, which makes every hall there legitimately unready: the suite would report
 * `sessions_schema_invalid` for sites 1-4 and 868-870 and fail, and the failure
 * would be a true statement about a database the ADR requires to stay where it
 * is, not a defect.
 *
 * The fixture is the only database where v3 is actually applied, so it is the
 * only one against which this suite's assertions mean anything while the
 * migration is in flight.
 *
 * tests/fixtures/wordpress-bootstrap.php is fail-closed: without `BC_TEST_DB`
 * it stops with a guard message rather than reaching the development
 * installation.
 */
/*
 * WooCommerce is network-active on the development installation, so it has
 * always been loaded here. The fixture loads it only on request, and this
 * suite evaluates WooCommerce readiness, so it asks for it explicitly.
 */
putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( ! class_exists( '\BingoCommerce\PortalIntegration\SetupReadiness' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}

use BingoCommerce\Core\Roles;
use BingoCommerce\PortalIntegration\Migrations;
use BingoCommerce\PortalIntegration\SetupReadiness;

final class SetupReadinessTest {

	/** Substituted for any statement the guard refuses. Valid MySQL, zero rows. */
	private const NOOP_SQL = 'SELECT NULL FROM DUAL WHERE 1 = 0';

	private int $assertions = 0;
	private int $failures   = 0;

	/** @var array<int,string> */
	private array $hall_sites = [];

	/** @var array<string,string> */
	private array $baseline = [];

	/** @var array<int,string> Redacted fingerprints of statements the guard blocked. */
	private array $blocked = [];

	/** @var array<string,bool> Lowercased table names seen in DESCRIBE probes. */
	private array $described = [];

	private bool $watching = false;

	/** @var array<int,array{needle:string,replacement:string,exact:bool}> */
	private array $rewrites = [];

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->assert_true( class_exists( SetupReadiness::class ), 'SetupReadiness is loaded' );
		$this->assert_true( class_exists( Roles::class ), 'Core Roles is loaded' );

		$this->discover_halls();
		$this->baseline = $this->capture_baseline();

		$this->test_hash_encoding();
		$this->test_guard_negative_control();
		$this->test_all_evaluations_guarded();
		$this->test_no_hooks_registered();
		$this->test_error_cases();
		$this->test_live_halls();
		$this->test_context_restoration();
		$this->test_context_exception();
		$this->test_observer_cleanup();
		$this->test_woocommerce_version();
		$this->test_hpos_interpretation();
		$this->test_missing_base_table();
		$this->test_missing_hpos_table();
		$this->test_main_site_table_does_not_satisfy_hall();
		$this->test_foreign_lookup_absence_does_not_reject_hall();
		$this->test_foreign_classification();
		$this->test_hpos_tables_follow_the_hall();
		$this->test_role_capabilities();
		$this->test_other_prerequisites();
		$this->test_unreadable_states();
		$this->test_recovery_after_injection();

		$this->assert_unchanged();
		$this->report();
	}

	/* ------------------------------------------------------------------ *
	 * Discovery and preservation evidence
	 * ------------------------------------------------------------------ */

	private function discover_halls(): void {
		foreach ( get_sites( [ 'number' => 0 ] ) as $site ) {
			$blog_id = (int) $site->blog_id;
			if ( is_main_site( $blog_id ) ) {
				continue;
			}
			$this->hall_sites[ $blog_id ] = (string) $site->domain;
		}
		ksort( $this->hall_sites );
		$this->assert_true( [] !== $this->hall_sites, 'at least one hall site exists to evaluate' );
	}

	/**
	 * Deterministic full-row hashes of every surface this suite must not disturb.
	 *
	 * A row-by-row SHA-256 over ORDERED, fully-enumerated columns, not an
	 * aggregate: a CRC32 sum is order-insensitive and collides cheaply, so two
	 * different table states can share one. Capture FAILS VISIBLY — a recorded
	 * assertion failure, not a sentinel string — when a required surface cannot
	 * be read, because a baseline that silently degrades to "unreadable" would
	 * compare equal to itself at the end and prove nothing.
	 *
	 * @return array<string,string>
	 */
	private function capture_baseline(): array {
		global $wpdb;

		$out = [];
		foreach ( array_keys( $this->hall_sites ) as $blog_id ) {
			$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
			$hash  = $this->hash_table( $table, [ 'option_id', 'option_name', 'option_value', 'autoload' ], 'option_id' );
			$this->assert_true( null !== $hash, "baseline captured for {$table}" );
			$out[ "options:{$blog_id}" ] = (string) $hash;
		}

		$mappings = Migrations::hall_mappings_table();
		$hash     = $this->hash_table( $mappings, [ 'portal_hall_id', 'blog_id', 'status', 'created_at', 'updated_at' ], 'portal_hall_id' );
		$this->assert_true( null !== $hash, "baseline captured for {$mappings}" );
		$out['mappings'] = (string) $hash;

		$audit = \BingoCommerce\Core\Migrations::audit_log_table();
		$hash  = $this->hash_table(
			$audit,
			[ 'id', 'operation_id', 'blog_id', 'actor_user_id', 'action', 'target_type', 'target_id', 'created_at' ],
			'id'
		);
		$this->assert_true( null !== $hash, "baseline captured for {$audit}" );
		$out['audit'] = (string) $hash;

		return $out;
	}

	/**
	 * SHA-256 over every row, in a fixed order, across every named column.
	 *
	 * Returns null when the surface cannot be read — the caller turns that into
	 * a visible failure. Row values are hashed, never returned or printed.
	 *
	 * @param array<int,string> $columns
	 */
	private function hash_table( string $table, array $columns, string $order_by ): ?string {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== (string) $wpdb->last_error || $found !== $table ) {
			return null;
		}

		$list = '`' . implode( '`, `', $columns ) . '`';
		$rows = $wpdb->get_results( "SELECT {$list} FROM `{$table}` ORDER BY `{$order_by}` ASC", ARRAY_N );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return $this->hash_rows( $table, $rows );
	}

	/**
	 * Unambiguous encoding of an ordered row set.
	 *
	 * Every value is LENGTH-PREFIXED. Separator bytes alone are not enough: any
	 * byte chosen as a delimiter can also occur inside a stored option value, so
	 * "A<sep>B" in one field and "A", "B" in two fields would hash identically
	 * and a real difference could hide behind an equal digest. A length prefix
	 * makes the field boundaries impossible to forge from content.
	 *
	 * NULL has its own marker, distinct from the zero-length string.
	 *
	 * @param array<int,array<int,?string>> $rows
	 */
	private function hash_rows( string $label, array $rows ): string {
		$ctx = hash_init( 'sha256' );
		hash_update( $ctx, 'L' . strlen( $label ) . ':' . $label . ';' );
		hash_update( $ctx, 'C' . count( $rows ) . ';' );

		foreach ( $rows as $row ) {
			$row = (array) $row;
			hash_update( $ctx, 'F' . count( $row ) . ';' );
			foreach ( $row as $value ) {
				if ( null === $value ) {
					hash_update( $ctx, 'N;' );
					continue;
				}
				$value = (string) $value;
				hash_update( $ctx, 'S' . strlen( $value ) . ':' . $value . ';' );
			}
		}

		return hash_final( $ctx );
	}

	/**
	 * In-memory proof that the encoding cannot conflate distinct row sets.
	 *
	 * Uses the bytes the previous separator-based encoding relied on, which is
	 * exactly where that scheme collided.
	 */
	private function test_hash_encoding(): void {
		$this->assert_true(
			$this->hash_rows( 't', [ [ null ] ] ) !== $this->hash_rows( 't', [ [ '' ] ] ),
			'NULL and the empty string encode differently'
		);

		$this->assert_true(
			$this->hash_rows( 't', [ [ "A\x1eB", 'C' ] ] ) !== $this->hash_rows( 't', [ [ 'A', "B\x1eC" ] ] ),
			'field values containing the old row separator cannot be conflated'
		);

		$this->assert_true(
			$this->hash_rows( 't', [ [ "A\x1fB", 'C' ] ] ) !== $this->hash_rows( 't', [ [ 'A', "B\x1fC" ] ] ),
			'field values containing the old field separator cannot be conflated'
		);

		$this->assert_true(
			$this->hash_rows( 't', [ [ 'a', 'b' ] ] ) === $this->hash_rows( 't', [ [ 'a', 'b' ] ] ),
			'identical rows produce identical hashes'
		);

		$this->assert_true(
			$this->hash_rows( 't', [ [ 'a' ], [ 'b' ] ] ) !== $this->hash_rows( 't', [ [ 'a', 'b' ] ] ),
			'two single-field rows differ from one two-field row'
		);

		$this->assert_true(
			$this->hash_rows( 'x', [ [ 'a' ] ] ) !== $this->hash_rows( 'y', [ [ 'a' ] ] ),
			'the table label participates in the hash'
		);
	}

	private function assert_unchanged(): void {
		$after = $this->capture_baseline();
		foreach ( $this->baseline as $key => $value ) {
			$this->assert_true(
				isset( $after[ $key ] ) && $after[ $key ] === $value,
				"persistent surface unchanged: {$key}"
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Write guard and fault injection
	 * ------------------------------------------------------------------ */

	private function start_watch(): void {
		if ( ! $this->watching ) {
			add_filter( 'query', [ $this, 'guard_query' ], 9999 );
			$this->watching = true;
		}
		$this->blocked   = [];
		$this->described = [];
	}

	/** @return array<int,string> Blocked statement fingerprints. */
	private function stop_watch(): array {
		if ( $this->watching ) {
			remove_filter( 'query', [ $this, 'guard_query' ], 9999 );
			$this->watching = false;
		}
		$this->rewrites = [];

		return $this->blocked;
	}

	/**
	 * Rewrite first, then REFUSE anything that is not read-only.
	 *
	 * Ordering matters: the guard inspects the statement that would actually
	 * execute, so a mis-targeted fault injection cannot smuggle a write past it.
	 *
	 * A refused statement is replaced with a valid zero-row SELECT, so it never
	 * reaches MySQL at all — prevention, not detection. Only a leading keyword
	 * and a short digest are recorded; raw SQL and stored values never are.
	 */
	public function guard_query( $query ) {
		$sql = ltrim( (string) $query );

		foreach ( $this->rewrites as $rule ) {
			$trimmed = rtrim( $sql, "; \t\n\r" );
			if ( $rule['exact'] ) {
				$hit = ( $trimmed === $rule['needle'] );
			} elseif ( '/' === $rule['needle'][0] ) {
				$hit = ( 1 === preg_match( $rule['needle'], $trimmed ) );
			} else {
				$hit = ( false !== strpos( $sql, $rule['needle'] ) );
			}
			if ( $hit ) {
				$sql = $rule['replacement'];
				break;
			}
		}

		if ( 1 !== preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql ) ) {
			$keyword         = strtoupper( (string) strtok( ltrim( $sql ), " \t\n\r(" ) );
			$this->blocked[] = $keyword . ':' . substr( hash( 'sha256', $sql ), 0, 12 );

			return self::NOOP_SQL;
		}

		if ( 1 === preg_match( '/^DESCRIBE\s+`?([A-Za-z0-9_]+)`?/i', ltrim( $sql ), $m ) ) {
			$this->described[ strtolower( $m[1] ) ] = true;
		}

		return $sql;
	}

	/**
	 * @param bool $exact Match the whole statement, needed where a needle would
	 *                    otherwise prefix-match unrelated probes. A needle
	 *                    beginning with '/' is a regular expression — the only
	 *                    practical way to target statements whose literal SQL
	 *                    carries wpdb's doubled esc_like backslashes.
	 */
	private function inject( string $needle, string $replacement, bool $exact = false ): void {
		$this->rewrites[] = [ 'needle' => $needle, 'replacement' => $replacement, 'exact' => $exact ];
	}

	/**
	 * Run one guarded scenario and ALWAYS assert nothing tried to write.
	 *
	 * The assertion lives in finally, so a scenario that throws still has its
	 * write attempts checked rather than silently discarded, and the filter is
	 * always removed.
	 *
	 * @return mixed
	 */
	private function watched( string $label, callable $fn ) {
		global $wpdb;

		$this->start_watch();
		$previous = $wpdb->suppress_errors( true );
		try {
			return $fn();
		} finally {
			$wpdb->suppress_errors( $previous );
			$blocked = $this->stop_watch();
			$this->assert_true(
				[] === $blocked,
				"{$label}: no write attempted" . ( [] === $blocked ? '' : ' (blocked: ' . implode( ', ', $blocked ) . ')' )
			);
		}
	}

	/**
	 * Matches ONLY the hall-scoped catalogue query, whose LIKE pattern ends in
	 * `%'`. The single-table probes the shipped Catalog/Sessions verifiers issue
	 * also begin "SHOW TABLES LIKE", so a looser needle would silently break
	 * those checks and make a scenario pass for the wrong reason.
	 */
	private const CATALOGUE_NEEDLE = "/^SHOW TABLES LIKE '.*%'$/i";

	/**
	 * The hall's catalogue with exactly one table removed.
	 *
	 * remove_placeholder_escape() is essential: prepare() leaves a `%` protection
	 * token in its output, and a statement handed back from the `query` filter is
	 * NOT run through wpdb's own token removal. Without stripping it the LIKE
	 * matches nothing, the catalogue comes back empty, and every direct check
	 * fails for a reason that has nothing to do with the scenario.
	 */
	private function catalogue_without( int $blog_id, string $suffix ): string {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $blog_id );

		return $wpdb->remove_placeholder_escape(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND TABLE_NAME <> %s',
				str_replace( '_', '\\_', $prefix ) . '%',
				$prefix . $suffix
			)
		);
	}

	/** @return mixed */
	private function evaluate_watched( string $label, int $blog_id ) {
		return $this->watched(
			$label,
			static function () use ( $blog_id ) {
				return SetupReadiness::evaluate( $blog_id );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Guard proof
	 * ------------------------------------------------------------------ */

	private function test_guard_negative_control(): void {
		global $wpdb;

		/*
		 * Negative control: a statement that WOULD write is prevented from
		 * executing. The target table does not exist, so even a total guard
		 * failure could not alter data — and that failure would surface as a
		 * MySQL error, which the last_error assertion below detects.
		 */
		$this->start_watch();
		$previous = $wpdb->suppress_errors( true );
		try {
			$wpdb->query( 'INSERT INTO zz_bc_guard_probe_table (a) VALUES (1)' );
			$last_query = (string) $wpdb->last_query;
			$last_error = (string) $wpdb->last_error;
		} finally {
			$wpdb->suppress_errors( $previous );
			$blocked = $this->stop_watch();
		}

		$this->assert_true( 1 === count( $blocked ), 'the guard recorded exactly one blocked statement' );
		$this->assert_true(
			isset( $blocked[0] ) && 0 === strpos( $blocked[0], 'INSERT:' ),
			'the blocked statement is reported by keyword and digest only'
		);
		$this->assert_true( self::NOOP_SQL === $last_query, 'the write was replaced with a no-op before execution' );
		$this->assert_true( '' === $last_error, 'the write never reached MySQL' );

		// A read in the same position is untouched.
		$this->start_watch();
		try {
			$wpdb->get_var( 'SELECT 1' );
			$read_query = (string) $wpdb->last_query;
		} finally {
			$blocked = $this->stop_watch();
		}
		$this->assert_true( [] === $blocked, 'a read statement is not blocked' );
		$this->assert_true( 'SELECT 1' === $read_query, 'a read statement passes through unmodified' );
	}

	/* ------------------------------------------------------------------ *
	 * Structural
	 * ------------------------------------------------------------------ */

	/**
	 * Every evaluation in this harness must run under the write guard.
	 *
	 * Checked against this file's own source so a future edit that calls the
	 * evaluator directly fails here rather than silently losing write coverage.
	 * The only permitted form is a `return` inside a closure handed to
	 * watched()/evaluate_watched().
	 */
	private function test_all_evaluations_guarded(): void {
		$source = (string) file_get_contents( __FILE__ );

		// Split so these lines cannot match their own pattern.
		$needle = 'SetupReadiness' . '::' . 'evaluate';
		preg_match_all( '/^.*' . preg_quote( $needle, '/' ) . '\s*\(.*$/m', $source, $matches );

		$unguarded = [];
		foreach ( $matches[0] as $line ) {
			if ( 1 !== preg_match( '/^\s*return ' . preg_quote( $needle, '/' ) . '\(/', $line ) ) {
				$unguarded[] = trim( $line );
			}
		}

		$this->assert_true( [] !== $matches[0], 'the guard audit found evaluator calls to inspect' );
		$this->assert_true(
			[] === $unguarded,
			'every evaluator call is inside a guarded closure'
				. ( [] === $unguarded ? '' : ' (unguarded: ' . count( $unguarded ) . ')' )
		);

		$wrapper = new ReflectionMethod( self::class, 'evaluate_watched' );
		$body    = implode(
			"\n",
			array_slice(
				file( __FILE__ ),
				$wrapper->getStartLine() - 1,
				$wrapper->getEndLine() - $wrapper->getStartLine() + 1
			)
		);
		$this->assert_true(
			false !== strpos( $body, '$this->watched(' ),
			'evaluate_watched() delegates to the write guard'
		);
	}

	private function test_no_hooks_registered(): void {
		$this->assert_true(
			false === has_action( 'rest_api_init', [ SetupReadiness::class, 'evaluate' ] ),
			'SetupReadiness registers no REST hook'
		);
		$this->assert_true(
			false === has_action( 'admin_init', [ SetupReadiness::class, 'evaluate' ] )
			&& false === has_action( 'init', [ SetupReadiness::class, 'evaluate' ] ),
			'SetupReadiness registers no bootstrap hook'
		);
		$this->assert_true(
			! method_exists( SetupReadiness::class, 'register' ),
			'SetupReadiness exposes no register() entry point'
		);
	}

	private function test_error_cases(): void {
		foreach ( [ 0, -1 ] as $bad ) {
			$r = $this->evaluate_watched( "invalid blog {$bad}", $bad );
			$this->assert_true(
				is_wp_error( $r ) && 'invalid_blog_id' === $r->get_error_code(),
				"blog_id {$bad} is rejected as invalid_blog_id"
			);
		}

		$missing = 990000 + wp_rand( 1, 9999 );
		$r       = $this->evaluate_watched( 'non-existent site', $missing );
		$this->assert_true(
			is_wp_error( $r ) && 'site_not_found' === $r->get_error_code(),
			'a non-existent site is rejected as site_not_found'
		);

		$r = $this->evaluate_watched( 'main network site', (int) get_main_site_id() );
		$this->assert_true(
			is_wp_error( $r ) && 'main_site_not_allowed' === $r->get_error_code(),
			'the main network site is rejected as main_site_not_allowed'
		);
	}

	private function test_live_halls(): void {
		echo "\n--- live hall findings ---\n";

		foreach ( $this->hall_sites as $blog_id => $domain ) {
			$before = get_current_blog_id();
			$result = $this->evaluate_watched( "site {$blog_id}", $blog_id );
			$after  = get_current_blog_id();

			$this->assert_true( is_array( $result ), "site {$blog_id} evaluates to a result array" );
			if ( ! is_array( $result ) ) {
				continue;
			}

			$this->assert_true( $before === $after, "site {$blog_id} leaves the blog context unchanged" );
			$this->assert_true(
				count( $result['checks'] ) === count( SetupReadiness::CHECKS ),
				"site {$blog_id} returns every declared check"
			);

			printf( "  site %d (%s): ready=%s\n", $blog_id, $domain, $result['ready'] ? 'YES' : 'NO' );
			foreach ( $result['checks'] as $name => $check ) {
				printf(
					"      %-20s %s%s\n",
					$name,
					$check['ok'] ? 'ok' : 'FAIL',
					$check['ok'] ? '' : ' [' . $check['code'] . '] ' . $this->summarise( $check['detail'] )
				);
			}
		}
		echo "\n";
	}

	/* ------------------------------------------------------------------ *
	 * Context
	 * ------------------------------------------------------------------ */

	private function test_context_restoration(): void {
		$blog_id = (int) array_key_first( $this->hall_sites );

		$before = get_current_blog_id();
		$depth  = $this->switch_depth();
		$this->evaluate_watched( 'context normal', $blog_id );
		$this->assert_true( get_current_blog_id() === $before, 'context restored after a normal evaluation' );
		$this->assert_true( $this->switch_depth() === $depth, 'switch stack depth restored after a normal evaluation' );

		$this->evaluate_watched( 'context validation error', 0 );
		$this->assert_true( get_current_blog_id() === $before, 'context unchanged after a validation error' );
		$this->assert_true( $this->switch_depth() === $depth, 'switch stack untouched after a validation error' );

		$others = array_keys( $this->hall_sites );
		if ( count( $others ) > 1 ) {
			switch_to_blog( $others[1] );
			$inner       = get_current_blog_id();
			$inner_depth = $this->switch_depth();
			$this->evaluate_watched( 'context nested', $others[0] );
			$this->assert_true( get_current_blog_id() === $inner, 'context restored to the calling hall, not the main site' );
			$this->assert_true( $this->switch_depth() === $inner_depth, 'an outer switched context is preserved' );
			restore_current_blog();
		}
		$this->assert_true( get_current_blog_id() === $before, 'context restored after nested evaluation' );
		$this->assert_true( $this->switch_depth() === $depth, 'switch stack depth restored after nested evaluation' );
	}

	/**
	 * An exception raised DURING the switch, after WordPress has already pushed
	 * the stack and moved context, must still leave the caller where it started.
	 */
	private function test_context_exception(): void {
		$others = array_keys( $this->hall_sites );
		if ( count( $others ) < 2 ) {
			return;
		}
		$target = $others[0];
		$outer  = $others[1];

		// Throws only on the 'switch' leg so the unwind's own 'restore' leg can
		// complete — modelling a callback that fails while switching.
		$thrower = static function ( $new_blog_id, $prev_blog_id, $context ) {
			if ( 'switch' === $context ) {
				throw new RuntimeException( 'switch_blog callback failure' );
			}
		};

		$before = get_current_blog_id();
		$depth  = $this->switch_depth();
		$caught = false;
		add_action( 'switch_blog', $thrower, 1, 3 );
		try {
			$this->evaluate_watched( 'context exception during switch', $target );
		} catch ( Throwable $e ) {
			$caught = true;
		} finally {
			remove_action( 'switch_blog', $thrower, 1 );
		}
		$this->assert_true( $caught, 'an exception during switch_to_blog propagates to the caller' );
		$this->assert_true( get_current_blog_id() === $before, 'context restored after an exception during the switch' );
		$this->assert_true( $this->switch_depth() === $depth, 'switch stack depth restored after an exception during the switch' );

		// The same, from inside an outer switched context.
		switch_to_blog( $outer );
		$inner       = get_current_blog_id();
		$inner_depth = $this->switch_depth();
		$caught      = false;
		add_action( 'switch_blog', $thrower, 1, 3 );
		try {
			$this->evaluate_watched( 'context exception inside outer switch', $target );
		} catch ( Throwable $e ) {
			$caught = true;
		} finally {
			remove_action( 'switch_blog', $thrower, 1 );
		}
		$this->assert_true( $caught, 'the exception still propagates from inside an outer switch' );
		$this->assert_true( get_current_blog_id() === $inner, 'an outer switched context survives the exception' );
		$this->assert_true( $this->switch_depth() === $inner_depth, 'outer switch stack depth survives the exception' );
		restore_current_blog();
		$this->assert_true( get_current_blog_id() === $before, 'outer context unwinds normally afterwards' );

		// An exception raised during evaluation, after the switch succeeded.
		$boom = static function ( $query ) {
			if ( 1 === preg_match( '/^SHOW TABLES LIKE/i', ltrim( (string) $query ) ) ) {
				throw new RuntimeException( 'evaluation failure' );
			}

			return $query;
		};
		$depth  = $this->switch_depth();
		$caught = false;
		add_filter( 'query', $boom, 1 );
		try {
			$this->evaluate_watched( 'context exception during evaluation', $target );
		} catch ( Throwable $e ) {
			$caught = true;
		} finally {
			remove_filter( 'query', $boom, 1 );
		}
		$this->assert_true( $caught, 'an exception during evaluation propagates' );
		$this->assert_true( get_current_blog_id() === $before, 'context restored after an exception during evaluation' );
		$this->assert_true( $this->switch_depth() === $depth, 'switch stack depth restored after an exception during evaluation' );
	}

	private function test_observer_cleanup(): void {
		$blog_id = (int) array_key_first( $this->hall_sites );

		$caught = false;
		try {
			$this->watched(
				'throwing scenario',
				static function () {
					throw new RuntimeException( 'scenario failure' );
				}
			);
		} catch ( Throwable $e ) {
			$caught = true;
		}

		$this->assert_true( $caught, 'a throwing scenario propagates out of watched()' );
		$this->assert_true( false === $this->watching, 'the guard is removed even when the scenario throws' );
		$this->assert_true(
			false === has_filter( 'query', [ $this, 'guard_query' ] ),
			'no query filter remains registered after a throwing scenario'
		);
		$this->assert_true( [] === $this->rewrites, 'fault injections are cleared after a throwing scenario' );

		$r = $this->evaluate_watched( 'post-cleanup', $blog_id );
		$this->assert_true( is_array( $r ), 'evaluation works after a throwing scenario cleaned up' );
	}

	private function switch_depth(): int {
		return is_array( $GLOBALS['_wp_switched_stack'] ?? null )
			? count( $GLOBALS['_wp_switched_stack'] )
			: 0;
	}

	/* ------------------------------------------------------------------ *
	 * Prerequisite scenarios
	 * ------------------------------------------------------------------ */

	private function test_woocommerce_version(): void {
		global $wpdb;
		$blog_id = (int) array_key_first( $this->hall_sites );

		$r = $this->watched(
			'version behind',
			function () use ( $blog_id, $wpdb ) {
				$this->inject( "option_name = 'woocommerce_version'", 'SELECT ' . $wpdb->prepare( '%s', '0.0.1' ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WOOCOMMERCE, 'woocommerce_version_behind_code', 'a stored version behind the code is rejected' );

		$r = $this->watched(
			'version ahead',
			function () use ( $blog_id, $wpdb ) {
				$this->inject( "option_name = 'woocommerce_version'", 'SELECT ' . $wpdb->prepare( '%s', '99999.0.0' ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WOOCOMMERCE, 'woocommerce_version_ahead_of_code', 'a stored version ahead of the code is rejected, not silently accepted' );

		$r = $this->watched(
			'version absent',
			function () use ( $blog_id, $wpdb ) {
				$this->inject( "option_name = 'woocommerce_version'", 'SELECT ' . $wpdb->prepare( '%s', '' ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WOOCOMMERCE, 'woocommerce_not_installed_on_site', 'an absent WooCommerce version is reported' );

		// Controlled valid result: the exact loaded version passes.
		$r = $this->watched(
			'version exact',
			function () use ( $blog_id, $wpdb ) {
				$this->inject( "option_name = 'woocommerce_version'", 'SELECT ' . $wpdb->prepare( '%s', (string) WC()->version ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_ok( $r, SetupReadiness::CHECK_WOOCOMMERCE, 'the exact loaded WooCommerce version passes' );
	}

	private function test_hpos_interpretation(): void {
		global $wpdb;
		$blog_id = (int) array_key_first( $this->hall_sites );

		// Only 'yes' is enabled, per CustomOrdersTableController.
		foreach ( [ '1', 'true', 'on', 'Yes', 'YES', 'no', '' ] as $value ) {
			$r = $this->watched(
				"hpos value '{$value}'",
				function () use ( $blog_id, $wpdb, $value ) {
					$this->inject( "option_name = 'woocommerce_custom_orders_table_enabled'", 'SELECT ' . $wpdb->prepare( '%s', $value ) . ' AS option_value' );

					return SetupReadiness::evaluate( $blog_id );
				}
			);
			$this->assert_check_failed(
				$r,
				SetupReadiness::CHECK_HPOS,
				'hpos_not_enabled',
				"HPOS value '{$value}' is not treated as enabled"
			);
		}

		$r = $this->watched(
			'hpos yes',
			function () use ( $blog_id, $wpdb ) {
				$this->inject( "option_name = 'woocommerce_custom_orders_table_enabled'", 'SELECT ' . $wpdb->prepare( '%s', 'yes' ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_ok( $r, SetupReadiness::CHECK_HPOS, "only the exact value 'yes' is treated as enabled" );
	}

	private function test_missing_base_table(): void {
		$blog_id = (int) array_key_first( $this->hall_sites );
		$prefix  = $GLOBALS['wpdb']->get_blog_prefix( $blog_id );

		/*
		 * dbDelta decides existence with "DESCRIBE {$table};": an empty result
		 * puts that table's CREATE back into the missing set. DESCRIBE ->
		 * DESCRIBE, so nothing is mutated.
		 */
		$r = $this->watched(
			'missing base table',
			function () use ( $blog_id ) {
				$this->inject( '/^DESCRIBE .*wc_reserved_stock$/i', 'DESCRIBE zz_bc_no_such_table' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WC_TABLES, 'woocommerce_tables_missing', 'an absent WooCommerce base table is reported' );
		$this->assert_true(
			is_array( $r ) && [ $prefix . 'wc_reserved_stock' ] === ( $r['checks'][ SetupReadiness::CHECK_WC_TABLES ]['detail']['missing'] ?? [] ),
			'exactly the injected base table is named as missing'
		);
		$this->assert_check_ok( $r, SetupReadiness::CHECK_ACTION_SCHEDULER, 'a missing base table does not disturb the Action Scheduler answer' );
	}

	private function test_missing_hpos_table(): void {
		$blog_id = (int) array_key_first( $this->hall_sites );
		$prefix  = $GLOBALS['wpdb']->get_blog_prefix( $blog_id );

		$r = $this->watched(
			'missing HPOS table',
			function () use ( $blog_id ) {
				$this->inject( self::CATALOGUE_NEEDLE, $this->catalogue_without( $blog_id, 'wc_orders' ) );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WC_TABLES, 'woocommerce_tables_missing', 'an absent required HPOS table is reported' );
		// EXACT equality, not "contains": a broken injection that emptied the
		// catalogue would also contain this name, and would pass a weaker check.
		$this->assert_true(
			is_array( $r ) && [ $prefix . 'wc_orders' ] === ( $r['checks'][ SetupReadiness::CHECK_WC_TABLES ]['detail']['missing'] ?? [] ),
			'exactly the hidden HPOS table is named as missing'
		);
		$this->assert_true(
			is_array( $r ) && true === ( $r['checks'][ SetupReadiness::CHECK_WC_TABLES ]['detail']['hpos_verified'] ?? false ),
			'HPOS tables are recorded as verified for this hall'
		);
		$this->assert_check_ok( $r, SetupReadiness::CHECK_CATALOG_SCHEMA, 'the narrow catalogue injection leaves the Catalog verifier intact' );
		$this->assert_check_ok( $r, SetupReadiness::CHECK_SESSIONS_SCHEMA, 'the narrow catalogue injection leaves the Sessions verifier intact' );
	}

	/**
	 * A healthy MAIN-SITE table must not satisfy a hall's prerequisite.
	 *
	 * WooCommerce's dry run probes {base}wc_product_attributes_lookup rather than
	 * the hall's own copy, so before the direct check existed this exact state —
	 * main-site table present, hall table absent, all HPOS tables present —
	 * reported the hall as READY.
	 */
	private function test_main_site_table_does_not_satisfy_hall(): void {
		global $wpdb;

		$blog_id = (int) array_key_first( $this->hall_sites );
		$prefix  = $wpdb->get_blog_prefix( $blog_id );
		$base    = $wpdb->base_prefix;

		$main_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base . 'wc_product_attributes_lookup' ) ) );
		$this->assert_true(
			$main_exists === $base . 'wc_product_attributes_lookup',
			'precondition: the main-site attributes-lookup table exists'
		);

		$r = $this->watched(
			'hall lookup hidden',
			function () use ( $blog_id ) {
				$this->inject( self::CATALOGUE_NEEDLE, $this->catalogue_without( $blog_id, 'wc_product_attributes_lookup' ) );

				return SetupReadiness::evaluate( $blog_id );
			}
		);

		$this->assert_true( is_array( $r ) && false === $r['ready'], 'a hall missing its own attributes-lookup table is NOT ready' );
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WC_TABLES, 'woocommerce_tables_missing', 'the missing hall table is reported' );
		$this->assert_true(
			is_array( $r ) && [ $prefix . 'wc_product_attributes_lookup' ] === ( $r['checks'][ SetupReadiness::CHECK_WC_TABLES ]['detail']['missing'] ?? [] ),
			"exactly the hall's own attributes-lookup table is named as missing"
		);
		// All HPOS tables were left intact, so the rejection is attributable
		// solely to the hall's own lookup table.
		$this->assert_check_ok( $r, SetupReadiness::CHECK_ACTION_SCHEDULER, 'Action Scheduler remains satisfied in this scenario' );
	}

	/**
	 * A missing FOREIGN lookup result must not reject a healthy hall.
	 *
	 * WooCommerce's cached reference points at another site's copy, so its
	 * verdict on that name — present or absent — says nothing about this hall.
	 * The direct check of the hall's own table is authoritative.
	 */
	private function test_foreign_lookup_absence_does_not_reject_hall(): void {
		$blog_id = (int) array_key_first( $this->hall_sites );

		$r = $this->watched(
			'foreign lookup missing',
			function () use ( $blog_id ) {
				// Only the cached out-of-hall name is described for this table;
				// the hall's own copy is never probed by the dry run.
				$this->inject( '/^DESCRIBE .*wc_product_attributes_lookup$/i', 'DESCRIBE zz_bc_no_such_table' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);

		$this->assert_check_ok( $r, SetupReadiness::CHECK_WC_TABLES, 'a missing foreign lookup table does not reject a healthy hall' );
		$this->assert_true(
			is_array( $r ) && [] !== ( $r['checks'][ SetupReadiness::CHECK_WC_TABLES ]['detail']['superseded'] ?? [] ),
			'the superseded foreign result is recorded rather than silently dropped'
		);
		$this->assert_true( is_array( $r ) && true === $r['ready'], 'the hall remains ready when only the foreign lookup is absent' );
	}

	/**
	 * Foreign results are discriminated, not blanket-ignored.
	 *
	 * The scope-mismatch branch itself cannot be reached by read-only injection
	 * on this installation: WC_Install::get_schema() is private and unfilterable,
	 * and the only out-of-hall name WooCommerce emits here is the lookup table.
	 * The classifier that separates "known and superseded" from "unexpected" is
	 * therefore exercised directly.
	 */
	private function test_foreign_classification(): void {
		global $wpdb;

		$method = new ReflectionMethod( SetupReadiness::class, 'is_known_unscoped_table' );
		$method->setAccessible( true );

		$this->assert_true(
			true === $method->invoke( null, $wpdb->base_prefix . 'wc_product_attributes_lookup' ),
			'the cached lookup table is classified as known and superseded'
		);
		$this->assert_true(
			true === $method->invoke( null, 'some_other_prefix_wc_product_attributes_lookup' ),
			'the lookup table is recognised under any prefix'
		);
		foreach ( [ 'wc_orders', 'wc_reserved_stock', 'woocommerce_sessions', 'wc_product_meta_lookup' ] as $suffix ) {
			$this->assert_true(
				false === $method->invoke( null, $wpdb->base_prefix . '9_' . $suffix ),
				"an unexpected foreign {$suffix} result is NOT superseded"
			);
		}
	}

	/**
	 * The HPOS tables actually probed must belong to the hall under evaluation,
	 * not to whichever hall was evaluated previously.
	 */
	private function test_hpos_tables_follow_the_hall(): void {
		$halls = array_keys( $this->hall_sites );
		if ( count( $halls ) < 2 ) {
			return;
		}

		foreach ( [ $halls[0], $halls[1], $halls[0] ] as $blog_id ) {
			$prefix = $GLOBALS['wpdb']->get_blog_prefix( $blog_id );
			$this->evaluate_watched( "hpos scope {$blog_id}", $blog_id );

			$this->assert_true(
				isset( $this->described[ strtolower( $prefix . 'wc_orders' ) ] ),
				"evaluating site {$blog_id} probes {$prefix}wc_orders"
			);
			$this->assert_true(
				isset( $this->described[ strtolower( $prefix . 'wc_order_operational_data' ) ] ),
				"evaluating site {$blog_id} probes {$prefix}wc_order_operational_data"
			);

			/*
			 * No OTHER HALL's tables. Base-prefixed (main-site) names are counted
			 * separately: WooCommerce's dry run is known to probe
			 * {base}wc_product_attributes_lookup from a container singleton bound
			 * to the original blog context. That leak is read-only and is asserted
			 * explicitly below so it stays visible rather than silently tolerated.
			 */
			$other_hall = [];
			$main_site  = [];
			foreach ( array_keys( $this->described ) as $table ) {
				if ( 0 === strpos( $table, strtolower( $prefix ) ) ) {
					continue;
				}
				if ( 1 === preg_match( '/^' . preg_quote( strtolower( $GLOBALS['wpdb']->base_prefix ), '/' ) . '\d+_/', $table ) ) {
					$other_hall[] = $table;
				} else {
					$main_site[] = $table;
				}
			}

			$this->assert_true(
				[] === $other_hall,
				"evaluating site {$blog_id} probes no other hall's tables"
					. ( [] === $other_hall ? '' : ' (' . implode( ', ', $other_hall ) . ')' )
			);
			$this->assert_true(
				[ strtolower( $GLOBALS['wpdb']->base_prefix . 'wc_product_attributes_lookup' ) ] === $main_site
					|| [] === $main_site,
				"evaluating site {$blog_id}: the only non-hall probe is the known WooCommerce container leak"
					. ( [] === $main_site ? '' : ' (' . implode( ', ', $main_site ) . ')' )
			);
			$this->assert_true(
				! isset( $this->described[ strtolower( $prefix . 'wc_product_attributes_lookup' ) ] ),
				"evaluating site {$blog_id}: the leak means this hall's own attributes-lookup is not probed by the dry run"
			);
		}
	}

	private function test_role_capabilities(): void {
		global $wpdb;
		$blog_id = (int) array_key_first( $this->hall_sites );
		$prefix  = $wpdb->get_blog_prefix( $blog_id );
		$needle  = "option_name = '{$prefix}user_roles'";

		$valid = $this->roles_blob();
		if ( [] === $valid ) {
			return;
		}

		// Controlled valid result first, so the rejections below are meaningful.
		$r = $this->watched(
			'roles valid',
			function () use ( $blog_id, $wpdb, $needle, $valid ) {
				$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', serialize( $valid ) ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_ok( $r, SetupReadiness::CHECK_HALL_ROLES, 'a complete reconstructed role set passes' );

		// Missing a bc_* Hall Manager capability.
		$blob = $valid;
		unset( $blob[ Roles::HALL_MANAGER ]['capabilities']['bc_sync_portal'] );
		$r = $this->watched(
			'hall manager missing bc cap',
			function () use ( $blog_id, $wpdb, $needle, $blob ) {
				$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', serialize( $blob ) ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_HALL_ROLES, 'hall_roles_incomplete', 'a missing Hall Manager bc_* capability is reported' );
		$this->assert_true(
			is_array( $r ) && in_array( 'bc_sync_portal', $r['checks'][ SetupReadiness::CHECK_HALL_ROLES ]['detail'][ Roles::HALL_MANAGER ]['missing_capabilities'] ?? [], true ),
			'the missing Hall Manager capability is named'
		);

		// Missing a WooCommerce-derived Hall Manager capability.
		$derived = null;
		foreach ( array_keys( $valid['shop_manager']['capabilities'] ) as $cap ) {
			if ( ! in_array( $cap, Roles::HALL_MANAGER_CAPS, true ) && 'read' !== $cap
				&& ! in_array( $cap, Roles::PROHIBITED_NATIVE_CAPS, true ) ) {
				$derived = $cap;
				break;
			}
		}
		if ( null !== $derived ) {
			$blob = $valid;
			unset( $blob[ Roles::HALL_MANAGER ]['capabilities'][ $derived ] );
			$r = $this->watched(
				'hall manager missing derived cap',
				function () use ( $blog_id, $wpdb, $needle, $blob ) {
					$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', serialize( $blob ) ) . ' AS option_value' );

					return SetupReadiness::evaluate( $blog_id );
				}
			);
			$this->assert_check_failed( $r, SetupReadiness::CHECK_HALL_ROLES, 'hall_roles_incomplete', 'a missing WooCommerce-derived Hall Manager capability is reported' );
			$this->assert_true(
				is_array( $r ) && in_array( $derived, $r['checks'][ SetupReadiness::CHECK_HALL_ROLES ]['detail'][ Roles::HALL_MANAGER ]['missing_capabilities'] ?? [], true ),
				'the missing WooCommerce-derived capability is named'
			);
		}

		// A prohibited native capability present on Hall Manager.
		$blob = $valid;
		$blob[ Roles::HALL_MANAGER ]['capabilities']['install_plugins'] = true;
		$r = $this->watched(
			'hall manager prohibited cap',
			function () use ( $blog_id, $wpdb, $needle, $blob ) {
				$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', serialize( $blob ) ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_true(
			is_array( $r ) && in_array( 'install_plugins', $r['checks'][ SetupReadiness::CHECK_HALL_ROLES ]['detail'][ Roles::HALL_MANAGER ]['prohibited_capabilities'] ?? [], true ),
			'a prohibited native capability on Hall Manager is reported'
		);

		// Game Operator widened beyond its restricted set.
		$blob = $valid;
		$blob[ Roles::GAME_OPERATOR ]['capabilities']['bc_manage_products'] = true;
		$r = $this->watched(
			'game operator widened',
			function () use ( $blog_id, $wpdb, $needle, $blob ) {
				$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', serialize( $blob ) ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_HALL_ROLES, 'hall_roles_incomplete', 'a widened Game Operator is reported' );
		$this->assert_true(
			is_array( $r ) && in_array( 'bc_manage_products', $r['checks'][ SetupReadiness::CHECK_HALL_ROLES ]['detail'][ Roles::GAME_OPERATOR ]['unexpected_capabilities'] ?? [], true ),
			'the unexpected Game Operator capability is named'
		);

		// Game Operator missing a required capability.
		$blob = $valid;
		unset( $blob[ Roles::GAME_OPERATOR ]['capabilities']['bc_redeem_tickets'] );
		$r = $this->watched(
			'game operator missing cap',
			function () use ( $blog_id, $wpdb, $needle, $blob ) {
				$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', serialize( $blob ) ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_true(
			is_array( $r ) && in_array( 'bc_redeem_tickets', $r['checks'][ SetupReadiness::CHECK_HALL_ROLES ]['detail'][ Roles::GAME_OPERATOR ]['missing_capabilities'] ?? [], true ),
			'a missing Game Operator capability is named'
		);

		// Shop Manager absent: the WooCommerce baseline was never applied.
		$blob = $valid;
		unset( $blob['shop_manager'] );
		$r = $this->watched(
			'shop manager absent',
			function () use ( $blog_id, $wpdb, $needle, $blob ) {
				$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', serialize( $blob ) ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_HALL_ROLES, 'hall_roles_incomplete', 'an absent Shop Manager baseline is reported' );
		$this->assert_true(
			is_array( $r ) && 'shop_manager_absent' === ( $r['checks'][ SetupReadiness::CHECK_HALL_ROLES ]['detail']['native_baseline'] ?? '' ),
			'the absent native baseline is named'
		);

		// Roles option entirely absent.
		$r = $this->watched(
			'roles absent',
			function () use ( $blog_id, $wpdb, $needle ) {
				$this->inject( $needle, 'SELECT ' . $wpdb->prepare( '%s', '' ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_HALL_ROLES, 'roles_absent', 'an absent roles option is reported' );
	}

	/**
	 * A role blob matching the shipped provisioning contract exactly.
	 *
	 * Built from the LIVE shop_manager definition on the hall, so the expected
	 * WooCommerce-derived set is whatever WooCommerce actually grants here,
	 * rather than a hard-coded copy that would rot against a WooCommerce update.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function roles_blob(): array {
		global $wpdb;

		$blog_id = (int) array_key_first( $this->hall_sites );
		$prefix  = $wpdb->get_blog_prefix( $blog_id );
		$raw     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM `{$prefix}options` WHERE option_name = %s LIMIT 1",
				$prefix . 'user_roles'
			)
		);
		$roles = maybe_unserialize( (string) $raw );
		$this->assert_true(
			is_array( $roles ) && isset( $roles['shop_manager'] ),
			'the live roles blob provides a shop_manager baseline'
		);
		if ( ! is_array( $roles ) || ! isset( $roles['shop_manager'] ) ) {
			return [];
		}

		$shop = [];
		foreach ( $roles['shop_manager']['capabilities'] ?? [] as $cap => $granted ) {
			if ( $granted ) {
				$shop[ $cap ] = true;
			}
		}

		$hm = [ 'read' => true ] + $shop;
		foreach ( Roles::PROHIBITED_NATIVE_CAPS as $cap ) {
			unset( $hm[ $cap ] );
		}
		foreach ( Roles::HALL_MANAGER_CAPS as $cap ) {
			$hm[ $cap ] = true;
		}

		$go = [ 'read' => true ];
		foreach ( Roles::GAME_OPERATOR_CAPS as $cap ) {
			$go[ $cap ] = true;
		}

		return [
			'shop_manager'       => [ 'name' => 'Shop manager', 'capabilities' => $shop ],
			Roles::HALL_MANAGER  => [ 'name' => 'Hall Manager', 'capabilities' => $hm ],
			Roles::GAME_OPERATOR => [ 'name' => 'Game Operator', 'capabilities' => $go ],
		];
	}

	private function test_other_prerequisites(): void {
		global $wpdb;
		$blog_id = (int) array_key_first( $this->hall_sites );

		$r = $this->watched(
			'action scheduler missing',
			function () use ( $blog_id ) {
				$this->inject( self::CATALOGUE_NEEDLE, "SHOW TABLES LIKE 'zz_no_such_table_%'" );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_ACTION_SCHEDULER, 'action_scheduler_tables_missing', 'absent Action Scheduler tables are reported' );
		$this->assert_true(
			is_array( $r ) && 4 === count( $r['checks'][ SetupReadiness::CHECK_ACTION_SCHEDULER ]['detail']['missing'] ?? [] ),
			'all four Action Scheduler tables are named as missing'
		);

		$r = $this->watched(
			'cart page unconfigured',
			function () use ( $blog_id, $wpdb ) {
				$this->inject( "option_name = 'woocommerce_cart_page_id'", 'SELECT ' . $wpdb->prepare( '%s', '' ) . ' AS option_value' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WC_PAGES, 'woocommerce_pages_unusable', 'an unconfigured cart page is reported' );
		$this->assert_true(
			is_array( $r ) && 'not_configured' === ( $r['checks'][ SetupReadiness::CHECK_WC_PAGES ]['detail']['pages']['cart'] ?? '' ),
			'the cart page is named as not_configured'
		);

		$r = $this->watched(
			'catalog structure invalid',
			function () use ( $blog_id ) {
				$this->inject( "/^SHOW TABLES LIKE '.*game.*types'$/i", "SHOW TABLES LIKE 'zz_no_such_table'" );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_CATALOG_SCHEMA, 'catalog_schema_invalid', 'invalid Catalog structure is reported' );
		$this->assert_true(
			is_array( $r ) && 'invalid' === ( $r['checks'][ SetupReadiness::CHECK_CATALOG_SCHEMA ]['detail']['structure'] ?? '' ),
			'Catalog structure is reported invalid, not merely version-stale'
		);

		$r = $this->watched(
			'sessions structure invalid',
			function () use ( $blog_id ) {
				$this->inject( "/^SHOW TABLES LIKE '.*bingo.*sessions'$/i", "SHOW TABLES LIKE 'zz_no_such_table'" );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_SESSIONS_SCHEMA, 'sessions_schema_invalid', 'invalid Sessions structure is reported' );
	}

	private function test_unreadable_states(): void {
		$blog_id = (int) array_key_first( $this->hall_sites );

		$r = $this->watched(
			'inventory unreadable',
			function () use ( $blog_id ) {
				$this->inject( self::CATALOGUE_NEEDLE, 'SELECT 1 FROM zz_bc_missing_table_for_test' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_ACTION_SCHEDULER, 'table_inventory_unreadable', 'an unreadable inventory is not reported as missing tables' );
		$this->assert_check_failed( $r, SetupReadiness::CHECK_WC_TABLES, 'table_inventory_unreadable', 'an unreadable hall catalogue still fails the WooCommerce table check' );

		$r = $this->watched(
			'hpos unreadable',
			function () use ( $blog_id ) {
				$this->inject( "option_name = 'woocommerce_custom_orders_table_enabled'", 'SELECT option_value FROM zz_bc_missing_options_for_test LIMIT 1' );

				return SetupReadiness::evaluate( $blog_id );
			}
		);
		$this->assert_check_failed( $r, SetupReadiness::CHECK_HPOS, 'hpos_state_unreadable', 'an unreadable HPOS option is not reported as disabled' );
	}

	private function test_recovery_after_injection(): void {
		foreach ( array_keys( $this->hall_sites ) as $blog_id ) {
			$r = $this->evaluate_watched( "recovery {$blog_id}", $blog_id );
			$this->assert_true(
				is_array( $r ) && true === $r['ready'],
				"site {$blog_id} is ready again after all fault injection"
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** @param mixed $result */
	private function assert_check_failed( $result, string $check, string $code, string $label ): void {
		$ok = is_array( $result )
			&& isset( $result['checks'][ $check ] )
			&& false === $result['checks'][ $check ]['ok']
			&& $code === $result['checks'][ $check ]['code'];

		if ( ! $ok && is_array( $result ) && isset( $result['checks'][ $check ] ) ) {
			$label .= ' (got ' . $result['checks'][ $check ]['code'] . ')';
		}

		$this->assert_true( $ok, $label );
	}

	/** @param mixed $result */
	private function assert_check_ok( $result, string $check, string $label ): void {
		$ok = is_array( $result )
			&& isset( $result['checks'][ $check ] )
			&& true === $result['checks'][ $check ]['ok'];

		if ( ! $ok && is_array( $result ) && isset( $result['checks'][ $check ] ) ) {
			$label .= ' (got ' . $result['checks'][ $check ]['code'] . ')';
		}

		$this->assert_true( $ok, $label );
	}

	/** @param array<string,mixed> $detail */
	private function summarise( array $detail ): string {
		$parts = [];
		foreach ( $detail as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = 'count=' . count( $v ) . ( [] === $v ? '' : ' ' . wp_json_encode( array_slice( $v, 0, 4 ) ) );
			}
			$parts[] = $k . '=' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
		}

		return implode( ' ', array_slice( $parts, 0, 5 ) );
	}

	private function assert_true( bool $condition, string $label ): void {
		$this->assertions++;
		if ( ! $condition ) {
			$this->failures++;
			echo "FAIL: {$label}\n";
		}
	}

	private function report(): void {
		$passed = $this->assertions - $this->failures;
		echo "\n{$passed}/{$this->assertions} assertions passed";
		echo 0 === $this->failures ? " — PASS\n" : " — {$this->failures} FAILED\n";
		exit( 0 === $this->failures ? 0 : 1 );
	}
}

( new SetupReadinessTest() )->run();
