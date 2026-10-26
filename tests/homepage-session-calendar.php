<?php

/**
 * Homepage Session calendar: date -> eligible dated Session -> its Packages.
 * BC_TEST_DB=bingocommerce_state_recon php tests/homepage-session-calendar.php
 *
 *  1. Every request rule, deterministically, through HomepageSessionCalendar::resolve()
 *     with synthetic eligibility results (three-month, leap-year and year-boundary spans
 *     included), plus the pure reachable-month walk and the theme's response checks.
 *  2. The real current-Hall eligibility read on disposable Halls, signed out: window
 *     edges, Sales OFF, membership, archived schedule, inactive template, incomplete
 *     generation, legacy rows, per-occurrence saved zones (Pacific/Kiritimati vs
 *     Pacific/Pago_Pago, whose local dates always differ), ok/partial/unavailable
 *     classification (a false result plus a WP_Error is unavailable), the candidate
 *     cap, guards, colliding IDs and A -> B -> A switches.
 *  3. Package layers: assignment + BR-080, public visibility and WooCommerce
 *     purchasability, catalog-hidden Packages, and read-failure statuses.
 *  4. In-process homepage rendering, provider failure states and the main-site
 *     omission; then actual homepage GETs through php-cgi
 *     (tests/homepage-session-calendar-request.php).
 *
 * Optional: BC_SESSION_CALENDAR_BROWSER=1 with BC_NODE_EXECUTABLE, BC_PLAYWRIGHT_MODULE,
 * BC_CHROME_EXECUTABLE and BC_SESSION_CALENDAR_ARTIFACTS (an existing /private/tmp
 * directory) also runs tests/homepage-session-calendar-browser.cjs against live CGI
 * requests for the Halls built here. Without the flag that coverage is reported skipped.
 *
 * Disposable token-owned Halls and actors only; persistent fixture Halls 2/3/4 are
 * compared byte-for-byte. Fixture media are attachment rows with metadata only: no file
 * is written to the shared uploads directory.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
$_SERVER['SERVER_NAME'] = BC_FIXTURE_DOMAIN;
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';

use BingoCommerce\BingoThemeV1\SessionCalendar;
use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Core\StoreDetails;
use BingoCommerce\Sessions\HomepageSessionCalendar;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\ScheduleExpansion;
use BingoCommerce\Sessions\ScheduleTimezone;
use BingoCommerce\Sessions\SessionResult;
use BingoCommerce\Sessions\SessionScheduleResult;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionService;

final class HomepageSessionCalendarTest {
	private const THEME = 'bingocommerce-bingothemev1';
	private const OWNER = 'bc_session_calendar_test_owner';
	private const NY    = 'America/New_York';
	private const K     = 'Pacific/Kiritimati';
	private const P     = 'Pacific/Pago_Pago';

	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;
	private string $group = '';
	private string $break = '';
	/** @var callable */
	private $audit;
	private array $notes = [];
	/** Facts handed to the browser check. */
	private array $browser = [];

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		$this->audit = static fn( array $event ): int => 1;
		add_filter( 'query', [ $this, 'query' ] );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) {
			$baseline[ $site ] = $this->snapshot( $site );
		}
		try {
			$this->resolution_rules();
			$this->reachable_months();
			$this->theme_contract();
			$main = $this->main_hall();
			$this->main_eligibility( $main );
			$this->packages( $main );
			$this->timezones();
			$this->classification();
			$this->collisions();
			$this->rendering( $main );
			$this->homepage_requests( $main );
			$this->browser();
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			$this->break = '';
			$this->cleanup();
		}
		$this->group( 'Preservation' );
		foreach ( $baseline as $site => $before ) {
			$this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" );
		}
		foreach ( $this->notes as $note ) {
			echo 'NOTE ' . $note . "\n";
		}
		printf( "Homepage Session calendar: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	/* ================================================================== *
	 * 1. Request rules, deterministic (synthetic eligibility results)
	 * ================================================================== */

	private function resolution_rules(): void {
		$this->group( 'Resolution rules' );
		$three = [ '2026-01-31' => 2, '2026-03-02' => 1 ];
		$r     = fn( array $query, ?array $dates = null, string $status = 'ok', string $today = '2026-01-31' ): array
			=> HomepageSessionCalendar::resolve( $this->synthetic( $dates ?? $three, $status ), $query, $today );

		$m = $r( [] );
		$this->same( [ 'date', '2026-01-31', '2026-01-01' ], [ $m['state'], $m['selected_date'], $m['month'] ], 'all three absent: earliest eligible date selected automatically' );
		$this->same( [ '2026-01-01', '2026-02-01', '2026-03-01' ], array_column( $m['months'], 'month' ), 'January 31 -> March 2 keeps February reachable' );
		$this->same( [ null, '2026-02-01' ], [ $m['months'][0]['prev'], $m['months'][0]['next']['month'] ], 'January: no previous link, next leads to February' );
		$this->same( [ '2026-01-01', '2026-03-01' ], [ $m['months'][1]['prev']['month'], $m['months'][1]['next']['month'] ], 'February: previous January, next March' );
		$this->same( [ '2026-02-01', null ], [ $m['months'][2]['prev']['month'], $m['months'][2]['next'] ], 'March: previous February, no next link' );
		$this->same( null, $m['session'], 'no Session selected without a Session request' );
		$this->same( null, $m['packages'], 'resolution never reads Packages' );
		$this->same( home_url( '/' ) . '#calendar', $m['available_url'], 'recovery link carries no parameters' );
		$this->same( '01/31/2026', $m['selected_label'], 'selected date labelled MM/DD/YYYY' );
		foreach ( $m['dates'] as $date => $entry ) {
			$this->ok( str_ends_with( $entry['url'], '?bc_date=' . $date . '#calendar' ), "date link for {$date} is a YYYY-MM-DD homepage GET" );
		}
		foreach ( $m['months'] as $entry ) {
			foreach ( [ 'prev', 'next' ] as $side ) {
				if ( null !== $entry[ $side ] ) {
					$this->ok( 1 === preg_match( '/\?bc_month=\d{4}-\d{2}-01#calendar$/', $entry[ $side ]['url'] ), "{$side} link from {$entry['month']} is a first-of-month YYYY-MM-DD value" );
				}
			}
		}
		$this->ok( ! preg_match( '/T\d{2}:|Z"|\+00:00/', (string) wp_json_encode( $m ) ), 'no timestamps anywhere in the model' );

		$this->same( [ 'month_empty', null, '2026-02-01' ], $this->pick( $r( [ 'bc_month' => '2026-02-01' ] ) ), 'month only, intermediate month without dates: empty month, nothing selected' );
		$this->same( [ 'month', null, '2026-03-01' ], $this->pick( $r( [ 'bc_month' => '2026-03-01' ] ) ), 'month only, month with dates: prompt, nothing selected' );
		$this->same( [ 'month', null, '2026-01-01' ], $this->pick( $r( [ 'bc_month' => '2026-01-01' ] ) ), 'month only never auto-selects a date' );
		foreach ( [ '2026-04-01', '2025-12-01' ] as $month ) {
			$this->same( [ 'month_unavailable', null, '2026-01-01' ], $this->pick( $r( [ 'bc_month' => $month ] ) ), "unreachable month {$month}: recovery, first reachable month shown" );
		}
		$this->same( [ 'date', '2026-03-02', '2026-03-01' ], $this->pick( $r( [ 'bc_date' => '2026-03-02' ] ) ), 'requested eligible date selected; its month displayed' );
		$m = $r( [ 'bc_date' => '2026-02-15' ] );
		$this->same( [ 'date_empty', '2026-02-15', '2026-02-01', '02/15/2026' ], [ $m['state'], $m['selected_date'], $m['month'], $m['selected_label'] ], 'requested date without Sessions is preserved; no substitute' );
		$m = $r( [ 'bc_date' => '2025-06-01' ] );
		$this->same( [ 'date_empty', '2025-06-01', '2025-06-01' ], $this->pick( $m ), 'past requested date keeps its own empty state' );
		$this->same( [ '2025-06-01', '2026-01-01', '2026-02-01', '2026-03-01' ], array_column( $m['months'], 'month' ), 'requested month outside the span is rendered alongside the reachable months' );
		$this->same( [ null, null ], [ $m['months'][0]['prev'], $m['months'][0]['next'] ], 'navigation never leads into an unreachable month' );

		$m = $r( [ 'bc_session' => '103' ] );
		$this->same( [ 'date', '2026-03-02', '2026-03-01', 103 ], [ $m['state'], $m['selected_date'], $m['month'], $m['session']['id'] ], 'Session-only link: its own date and month' );
		$this->same( 103, $r( [ 'bc_session' => '103', 'bc_date' => '2026-03-02' ] )['session']['id'], 'Session with its exact date' );
		$m = $r( [ 'bc_session' => '103', 'bc_date' => '2026-01-31' ] );
		$this->same( [ 'mismatch', null, '2026-01-01', null ], [ $m['state'], $m['selected_date'], $m['month'], $m['session'] ], 'Session on another date: rejected; nothing selected' );
		$this->same( 'mismatch', $r( [ 'bc_session' => '103', 'bc_date' => '2026-02-15' ] )['state'], 'mismatch with an empty date is still rejected' );
		$this->same( 'mismatch', $r( [ 'bc_session' => '103', 'bc_date' => '2025-06-01' ] )['state'], 'mismatch with a past date is still rejected' );
		$m = $r( [ 'bc_session' => '999' ] );
		$this->same( [ 'session_unavailable', null, null ], [ $m['state'], $m['selected_date'], $m['session'] ], 'stale Session: recovery; no replacement' );
		$this->same( [ 'session_unavailable', null ], [ $r( [ 'bc_session' => '999', 'bc_date' => '2026-01-31' ] )['state'], $r( [ 'bc_session' => '999', 'bc_date' => '2026-01-31' ] )['selected_date'] ], 'stale Session with an eligible date does not select that date' );
		$this->same( 'session_unavailable', $r( [ 'bc_session' => '999', 'bc_month' => '2026-02-01' ] )['state'], 'stale Session with a valid month' );
		$m = $r( [ 'bc_session' => '103', 'bc_month' => '2026-01-01' ] );
		$this->same( [ 'date', '2026-03-01' ], [ $m['state'], $m['month'] ], 'valid month with a verified Session: the Session decides the month' );
		$this->same( '2026-03-01', $r( [ 'bc_date' => '2026-03-02', 'bc_month' => '2026-01-01' ] )['month'], 'valid month with a valid date: the date decides the month' );
		$this->same( [ 'date_empty', '2026-02-01' ], [ $r( [ 'bc_date' => '2026-02-15', 'bc_month' => '2026-03-01' ] )['state'], $r( [ 'bc_date' => '2026-02-15', 'bc_month' => '2026-03-01' ] )['month'] ], 'empty date with a valid month: the date decides' );
		foreach ( [ [ 'bc_session' => '103' ], [ 'bc_date' => '2026-03-02' ], [ 'bc_session' => '103', 'bc_date' => '2026-03-02' ] ] as $valid ) {
			$this->same( 'month_unavailable', $r( $valid + [ 'bc_month' => '2026-04-01' ] )['state'], 'an unreachable month is never bypassed by ' . wp_json_encode( $valid ) );
			$this->same( 'invalid', $r( $valid + [ 'bc_month' => '2026-03-15' ] )['state'], 'a malformed month is never bypassed by ' . wp_json_encode( $valid ) );
		}
		$this->same( null, $r( [ 'bc_session' => '103', 'bc_month' => 'x' ] )['session'], 'malformed month with an eligible Session: no Session, so no Packages' );

		foreach ( [ 'bc_date', 'bc_session', 'bc_month' ] as $key ) {
			$m = $r( [ $key => '' ] );
			$this->same( [ 'invalid', null, null ], [ $m['state'], $m['selected_date'], $m['session'] ], "empty {$key} is supplied and invalid, not absent" );
		}
		$this->same( 'invalid', $r( [ 'bc_session' => '', 'bc_date' => '2026-01-31' ] )['state'], 'empty Session with an eligible date is rejected, not date-only' );
		$this->same( 'invalid', $r( [ 'bc_date' => '', 'bc_session' => '103' ] )['state'], 'empty date with an eligible Session is rejected' );
		$bad = [
			'bc_date'    => [ '2026-02-30', '2026-2-14', '02/14/2026', ' 2026-02-14', '2026-02-14 ', '2026-02-14T00:00', [ '2026-02-14' ], 20260214 ],
			'bc_session' => [ '0', '-1', '+1', '01', '1.5', '1e3', ' 1', '1 ', '9223372036854775808', [ '1' ], 'abc' ],
			'bc_month'   => [ '2026-02', '2026-02-14', '2026-13-01', ' 2026-02-01', [ '2026-02-01' ], '2026-02-01T00:00' ],
		];
		foreach ( $bad as $key => $values ) {
			foreach ( $values as $value ) {
				$m = $r( [ $key => $value ] );
				$this->same( [ 'invalid', null, null, '2026-01-01' ], [ $m['state'], $m['selected_date'], $m['session'], $m['month'] ], "malformed {$key} " . wp_json_encode( $value ) . ': recovery, nothing selected' );
			}
		}
		$this->same( 'invalid', $r( [ 'bc_date' => 'x', 'bc_month' => '2026-04-01' ] )['state'], 'malformed parameters are reported before month reachability' );
		$this->same( $this->pick( $r( [] ) ), $this->pick( $r( [ 'utm_source' => 'newsletter', 'fbclid' => 'x' ] ) ), 'unrelated parameters are ignored' );

		$this->group( 'Resolution: empty and partial results' );
		$e = fn( array $query ): array => HomepageSessionCalendar::resolve( [ 'status' => 'ok', 'dates' => [] ], $query, '2026-10-01' );
		$this->same( [ 'none', null, '2026-10-01', [ '2026-10-01' ] ], [ $e( [] )['state'], $e( [] )['selected_date'], $e( [] )['month'], array_column( $e( [] )['months'], 'month' ) ], 'successful empty result: "none" on the current Hall month only' );
		$this->same( 'none', $e( [ 'bc_month' => '2026-10-01' ] )['state'], 'month-only request with a successful empty result shows the empty state, not the prompt' );
		$this->same( 'month_unavailable', $e( [ 'bc_month' => '2026-11-01' ] )['state'], 'empty result: any other month is unreachable' );
		$this->same( [ 'date_empty', '2026-10-05' ], [ $e( [ 'bc_date' => '2026-10-05' ] )['state'], $e( [ 'bc_date' => '2026-10-05' ] )['selected_date'] ], 'empty result: requested date keeps its own empty state' );
		$this->same( 'session_unavailable', $e( [ 'bc_session' => '5' ] )['state'], 'empty result: any Session is stale' );
		$p = $r( [ 'bc_date' => '2026-02-15' ], null, 'partial' );
		$this->same( [ 'partial', 'date_empty' ], [ $p['status'], $p['state'] ], 'partial result keeps its status through resolution' );
		$this->ok( str_contains( SessionCalendar::message( $p ), 'could be confirmed' ) && ! str_contains( SessionCalendar::message( $p ), 'available for purchase' ), 'partial empty date never claims there are no Sessions' );
		$this->ok( str_contains( SessionCalendar::message( $r( [ 'bc_month' => '2026-02-01' ], null, 'partial' ) ), 'could be confirmed this month' ), 'partial empty month says nothing could be confirmed' );
		$this->ok( str_contains( SessionCalendar::message( $r( [ 'bc_date' => '2026-02-15' ] ) ), 'No Sessions are available for purchase on 02/15/2026.' ), 'successful empty date says so, with MM/DD/YYYY' );
		$this->same( [ 'version' => 1, 'status' => 'unavailable' ], HomepageSessionCalendar::resolve( [ 'status' => 'partial', 'dates' => [] ], [], '2026-10-01' ), 'a partial result with nothing verified can never resolve to "none"' );
		$this->same( 'unavailable', HomepageSessionCalendar::resolve( [ 'status' => 'weird', 'dates' => [] ], [], '2026-10-01' )['status'], 'unknown eligibility status is unavailable' );

		$this->group( 'Resolution: leap year and year boundary' );
		$leap = $r( [ 'bc_month' => '2028-02-01' ], [ '2028-01-31' => 1, '2028-03-01' => 1 ], 'ok', '2028-01-31' );
		$this->same( [ 'month_empty', [ '2028-01-01', '2028-02-01', '2028-03-01' ] ], [ $leap['state'], array_column( $leap['months'], 'month' ) ], '01/31/2028 -> 03/01/2028: leap-year February reachable and empty' );
		$year = $r( [], [ '2026-12-02' => 1, '2027-01-01' => 1 ], 'ok', '2026-12-02' );
		$this->same( [ [ '2026-12-01', '2027-01-01' ], '2027-01-01', '2026-12-01' ], [ array_column( $year['months'], 'month' ), $year['months'][0]['next']['month'], $year['months'][1]['prev']['month'] ], 'year boundary navigation' );
	}

	private function reachable_months(): void {
		$this->group( 'Reachable months' );
		$months = static fn( array $dates, string $today = '2026-10-01' ): array => HomepageSessionCalendar::reachable_months( $dates, $today );
		$this->same( [ '2026-01-01', '2026-02-01', '2026-03-01' ], $months( [ '2026-03-02', '2026-01-31' ] ), 'unsorted verified dates; intermediate month included' );
		$this->same( [ '2028-01-01', '2028-02-01', '2028-03-01' ], $months( [ '2028-01-31', '2028-03-01' ] ), 'leap year span' );
		$this->same( [ '2026-12-01', '2027-01-01' ], $months( [ '2026-12-02', '2027-01-01' ] ), 'year boundary span' );
		$this->same( [ '2026-10-01' ], $months( [ '2026-10-01', '2026-10-31' ] ), 'single month' );
		$this->same( [ '2026-10-01' ], $months( [] ), 'no verified dates: the Hall-local current month only' );
		$this->same( [ '2026-10-01' ], $months( [ 'garbage', '2026-02-30' ] ), 'invalid values never extend a span' );
		$this->same( [ '9999-12-01' ], $months( [ '9999-12-31' ] ), 'year 9999 terminates' );
	}

	/** @param array<string,int> $dates date => number of Sessions */
	private function synthetic( array $dates, string $status = 'ok' ): array {
		$result = [ 'status' => $status, 'dates' => [] ];
		$id     = 100;
		foreach ( $dates as $date => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				++$id;
				$result['dates'][ $date ][] = [
					'id' => $id, 'date' => $date, 'date_label' => HomepageSessionCalendar::date_label( $date ), 'name' => 'Synthetic Session ' . $id,
					'time' => '18:00', 'timezone' => self::NY, 'packages_url' => home_url( '/?bc_session=' . $id ) . '#session-packages',
				];
			}
		}
		return $result;
	}

	private function pick( array $m ): array {
		return [ $m['state'], $m['selected_date'], $m['month'] ];
	}

	/* ================================================================== *
	 * Theme response checks and month grids
	 * ================================================================== */

	private function theme_contract(): void {
		$this->group( 'Theme response contract' );
		$valid = HomepageSessionCalendar::resolve( $this->synthetic( [ '2026-01-31' => 1, '2026-03-02' => 1 ] ), [], '2026-01-31' );
		$this->same( $valid, SessionCalendar::model( $valid ), 'a complete provider response is usable' );
		$this->same( [ 'status' => 'unavailable' ], SessionCalendar::model( [ 'version' => 1, 'status' => 'unavailable' ] ), 'provider-reported unavailability is preserved' );
		$broken = [
			'no provider (null)'         => null,
			'non-array'                  => 'ok',
			'empty array'                => [],
			'unknown version'            => [ 'version' => 2 ] + $valid,
			'unknown status'             => [ 'status' => 'fine' ] + $valid,
			'status only'                => [ 'version' => 1, 'status' => 'ok' ],
			'no months'                  => array_merge( $valid, [ 'months' => [] ] ),
			'displayed month not listed' => array_merge( $valid, [ 'month' => '2026-05-01' ] ),
			'state outside contract'     => array_merge( $valid, [ 'state' => 'anything' ] ),
			'date entry mismatched'      => array_merge( $valid, [ 'dates' => [ '2026-01-31' => array_merge( $valid['dates']['2026-01-31'], [ 'date' => '2026-02-01' ] ) ] ] ),
			'Session row missing time'   => array_merge( $valid, [ 'dates' => [ '2026-01-31' => array_merge( $valid['dates']['2026-01-31'], [ 'sessions' => [ array_diff_key( $valid['dates']['2026-01-31']['sessions'][0], [ 'time' => 1 ] ) ] ] ) ] ] ),
			'selection without date'     => array_merge( $valid, [ 'selected_date' => '2026-02-01' ] ),
			'Packages without Session'   => array_merge( $valid, [ 'packages' => [ 'status' => 'ok', 'items' => [] ] ] ),
			'Session without Packages'   => array_merge( $valid, [ 'session' => $valid['dates']['2026-01-31']['sessions'][0] ] ),
			'month link malformed'       => array_merge( $valid, [ 'months' => [ array_merge( $valid['months'][0], [ 'next' => [ 'month' => '2026-02-15', 'label' => 'x', 'url' => 'x' ] ] ) ] + $valid['months'] ] ),
		];
		foreach ( $broken as $label => $value ) {
			$this->same( null, SessionCalendar::model( $value ), "unusable response ({$label}) renders the failure state" );
		}

		$this->group( 'Month grids' );
		$weeks = SessionCalendar::weeks( '2026-02-01' );
		$this->same( [ 5, '2026-01-26', '2026-03-01' ], [ count( $weeks ), $weeks[0][0]['date'], $weeks[4][6]['date'] ], 'February 2026 (starts on a Sunday): five Monday-first weeks' );
		$this->same( [ false, true ], [ $weeks[0][0]['in_month'], $weeks[0][6]['in_month'] ], 'leading days belong to January; February 1 is a Sunday' );
		$this->same( 6, count( SessionCalendar::weeks( '2026-03-01' ) ), 'March 2026 spans six weeks' );
		$leap = SessionCalendar::weeks( '2028-02-01' );
		$in   = array_values( array_filter( array_merge( ...$leap ), static fn( array $c ): bool => $c['in_month'] ) );
		$this->same( [ 29, '2028-02-29' ], [ count( $in ), end( $in )['date'] ], 'leap February has 29 in-month days' );
		foreach ( [ '2026-01-01', '2026-10-01', '2027-01-01' ] as $month ) {
			$all = array_merge( ...SessionCalendar::weeks( $month ) );
			$this->ok( 0 === count( $all ) % 7 && 'Mon' === gmdate( 'D', strtotime( $all[0]['date'] . ' 00:00:00 UTC' ) ), "{$month}: whole weeks starting on Monday" );
		}
		$this->same( [], SessionCalendar::weeks( '2026-02-30' ), 'an impossible month renders no grid' );
	}

	/* ================================================================== *
	 * 2. Real current-Hall eligibility (main Hall)
	 * ================================================================== */

	private function main_hall(): array {
		$this->group( 'Main Hall fixtures' );
		$site = $this->hall( 'main', self::NY );
		switch_to_blog( $site );
		try {
			wp_set_current_user( $this->admin );
			$t     = static fn( int $days ): string => self::day( self::NY, $days );
			$image = $this->image( 'paper-package.png' );
			$game  = ( new GameTypeService( $this->audit ) )->create( 'Calendar Fixture Game' );
			if ( is_wp_error( $game ) ) { throw new RuntimeException( 'Game Type fixture failed: ' . $game->get_error_code() ); }
			$game_id = (int) $game->game_type['game_type_id'];
			$paper   = $this->play( 'Paper', 'paper' );
			$digital = $this->play( 'Digital', 'digital', $game_id );
			$fragile = $this->play( 'Fragile', 'paper' );

			// Creation order fixes package_id order, which the read preserves.
			$p = [];
			$p['paper']      = $this->package( 'Early Bird Paper Pack', [ [ $paper, 2 ] ], [ 'price' => '10.00', 'image' => $image ] );
			$p['draft']      = $this->package( 'Draft Pack', [ [ $paper, 1 ] ], [ 'status' => 'draft' ] );
			$p['pending']    = $this->package( 'Pending Pack', [ [ $paper, 1 ] ], [ 'status' => 'pending' ] );
			$p['private']    = $this->package( 'Private Pack', [ [ $paper, 1 ] ], [ 'status' => 'private' ] );
			$p['noprice']    = $this->package( 'No Price Pack', [ [ $paper, 1 ] ], [ 'price' => '' ] );
			$p['hidden']     = $this->package( 'Hidden & Fun Pack', [ [ $paper, 1 ] ], [ 'visibility' => 'hidden', 'price' => '7.50' ] );
			$p['digital']    = $this->package( 'Digital Dozen', [ [ $digital, 4 ] ], [ 'price' => '12.00', 'sale' => '9.00' ] );
			$p['hybrid']     = $this->package( 'Combo Night', [ [ $paper, 1 ], [ $digital, 2 ] ], [ 'price' => '15.00' ] );
			$p['archived']   = $this->package( 'Archived Pack', [ [ $paper, 1 ] ] );
			$p['archcomp']   = $this->package( 'Archived Component Pack', [ [ $fragile, 1 ] ] );
			$p['unassigned'] = $this->package( 'Unassigned Pack', [ [ $paper, 1 ] ] );

			$main  = $this->schedule( $this->template( 'Calendar Main' ), [ $p['paper'] ] );
			$tpl   = $this->schedule( $this->template( 'Calendar Template Check' ), [ $p['paper'] ] );
			$arch  = $this->schedule( $this->template( 'Calendar Archived Schedule' ), [ $p['paper'] ] );
			$s = [];
			$s['t0']     = $this->occurrence( $main, $t( 0 ), [ $p['paper'] ] );
			$s['x']      = $this->occurrence( $main, $t( 3 ), array_values( array_diff_key( $p, [ 'unassigned' => 1 ] ) ) );
			$s['z']      = $this->occurrence( $main, $t( 4 ), [ $p['draft'], $p['hybrid'] ] );
			$s['y']      = $this->occurrence( $main, $t( 10 ), [ $p['archcomp'] ] );
			$s['t30']    = $this->occurrence( $main, $t( 30 ), [ $p['paper'] ] );
			$s['t31']    = $this->occurrence( $main, $t( 31 ), [ $p['paper'] ] );
			$s['tm1']    = $this->occurrence( $main, $t( -1 ), [ $p['paper'] ] );
			$s['member'] = $this->occurrence( $main, $t( 12 ), [ $p['paper'] ] );
			$s['incomp'] = $this->occurrence( $main, $t( 14 ), [ $p['paper'] ], [ 'complete' => false, 'force_on' => true ] );
			$s['tpl']    = $this->occurrence( $tpl, $t( 13 ), [ $p['paper'] ] );
			$s['arch']   = $this->occurrence( $arch, $t( 15 ), [ $p['paper'] ] );
			$s['none']   = $this->legacy( $t( 0 ), 'Legacy Early Bird 7:00 PM', true );
			$s['off']    = $this->legacy( $t( 6 ), 'Legacy Sales Off', false, [ $p['paper'] ] );
			$s['larch']  = $this->legacy( $t( 7 ), 'Legacy Archived', true, [ $p['paper'] ] );

			global $wpdb;
			// Removed from the current committed definition while ON (BR-078).
			$wpdb->delete( Migrations::schedule_dates_table(), [ 'schedule_id' => $main['id'], 'schedule_date' => $t( 12 ) ] );
			// Template no longer active; schedule archived; legacy Session archived.
			$wpdb->update( Migrations::portal_session_templates_table(), [ 'portal_status' => 'draft' ], [ 'portal_session_id' => $tpl['template'] ] );
			$this->ok( ! is_wp_error( ( new SessionScheduleService( $this->audit ) )->archive( $arch['id'] ) ), 'fixture schedule archived' );
			$this->ok( ( new SessionService( $this->audit ) )->archive( $s['larch'] ) instanceof SessionResult, 'fixture legacy Session archived' );
			// BR-080 / BR-070 states, after their assignments exist.
			$this->ok( ! is_wp_error( ( new PackageService( $this->audit ) )->archive( $p['archived'] ) ), 'fixture Package archived' );
			$this->ok( ! is_wp_error( ( new BingoPlayProductService( $this->audit ) )->archive( $fragile ) ), 'fixture required component archived' );

			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home' ] ) );
			StoreDetails::save( array_replace( StoreDetails::blank(), [ 'phone' => '+1 (813) 555-0101' ] ) );
			return [ 'site' => $site, 'packages' => $p, 'sessions' => $s, 'image' => $image, 'fragile' => $fragile, 'today' => $t( 0 ) ];
		} finally {
			restore_current_blog();
		}
	}

	private function main_eligibility( array $f ): void {
		$this->group( 'Main Hall eligibility (signed out)' );
		$s = $f['sessions'];
		$t = static fn( int $days ): string => self::day( self::NY, $days );
		switch_to_blog( $f['site'] );
		try {
			$m = $this->model();
			$this->same( 'ok', $m['status'], 'no eligibility errors: ok' );
			$this->same( [ $t( 0 ), $t( 3 ), $t( 4 ), $t( 10 ), $t( 30 ) ], array_keys( $m['dates'] ), 'eligible dates: today, +3, +4, +10 and +30 only' );
			$this->same( [ $s['t0'], $s['none'] ], array_column( $m['dates'][ $t( 0 ) ]['sessions'], 'id' ), 'same-day order: structured start time first, legacy without time last' );
			$this->same( [ '18:00', self::NY ], [ $m['dates'][ $t( 0 ) ]['sessions'][0]['time'], $m['dates'][ $t( 0 ) ]['sessions'][0]['timezone'] ], 'generated Session keeps its structured time and saved zone' );
			$this->same( [ null, null, 'Legacy Early Bird 7:00 PM' ], [ $m['dates'][ $t( 0 ) ]['sessions'][1]['time'], $m['dates'][ $t( 0 ) ]['sessions'][1]['timezone'], $m['dates'][ $t( 0 ) ]['sessions'][1]['name'] ], 'legacy time text stays in the name and is never parsed' );
			$all = array_merge( ...array_column( $m['dates'], 'sessions' ) );
			foreach ( [ 't31' => '+31 days', 'tm1' => 'yesterday', 'off' => 'Sales OFF', 'larch' => 'archived legacy', 'member' => 'removed from definition', 'incomp' => 'incomplete generation', 'tpl' => 'inactive template', 'arch' => 'archived schedule' ] as $key => $label ) {
				$this->ok( ! in_array( $s[ $key ], array_column( $all, 'id' ), true ), "{$label} is not shown" );
			}
			$this->same( [ 'date', $t( 0 ), substr( $t( 0 ), 0, 8 ) . '01' ], $this->pick( $m ), 'nothing requested: earliest eligible date selected' );
			$this->same( HomepageSessionCalendar::reachable_months( array_keys( $m['dates'] ), $m['today'] ), array_column( $m['months'], 'month' ), 'reachable months come from the same verified dates' );
			$this->same( $t( 0 ), $m['today'], 'today marker is the Hall-local date' );
			$this->same( null, $m['packages'], 'no Package read without a Session request' );
			$this->same( $m, $this->model( [], $this->manager( $f['site'] ) ), 'a signed-in Hall Manager reads the same calendar' );
			$this->same( $m, $this->model( [], $this->admin ), 'a Super Admin reads the same calendar' );
		} finally {
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * 3. Package layers
	 * ================================================================== */

	private function packages( array $f ): void {
		$this->group( 'Package layers (signed out)' );
		$p = $f['packages'];
		$s = $f['sessions'];
		switch_to_blog( $f['site'] );
		try {
			$m     = $this->model( [ 'bc_session' => (string) $s['x'] ] );
			$items = $m['packages']['items'];
			$this->same( [ 'date', $s['x'], 'ok' ], [ $m['state'], $m['session']['id'], $m['packages']['status'] ], 'verified Session link reads its Packages' );
			$this->same( [ $p['paper'], $p['hidden'], $p['digital'], $p['hybrid'] ], array_column( $items, 'id' ), 'assigned + BR-080 + public + purchasable, in package_id order; catalog-hidden included' );
			$this->same( [ 'paper', 'paper', 'digital', 'hybrid' ], array_column( $items, 'type' ), 'authoritative Package types' );
			$this->same( [ 'Early Bird Paper Pack', 'Hidden & Fun Pack', 'Digital Dozen', 'Combo Night' ], array_column( $items, 'name' ), 'authoritative names as plain text' );
			$this->same( [ $f['image'], 0, 0, 0 ], array_column( $items, 'image_id' ), 'product image only where one is set' );
			$this->ok( str_contains( $items[0]['price_html'], '10.00' ) && str_contains( $items[2]['price_html'], '<del' ) && str_contains( $items[2]['price_html'], '9.00' ), 'native WooCommerce price HTML, including a sale price' );
			$this->same( [ 'id', 'type', 'name', 'image_id', 'price_html', 'contents', 'addons' ], array_keys( $items[0] ), 'only presentation fields are returned' );
			$this->same( [ [ 'status' => 'ok', 'items' => [ [ 'name' => 'Paper play product', 'quantity' => 2, 'medium' => 'paper' ] ] ], [ 'status' => 'ok', 'items' => [ [ 'name' => 'Paper play product', 'quantity' => 1, 'medium' => 'paper' ], [ 'name' => 'Digital play product', 'quantity' => 2, 'medium' => 'digital' ] ] ] ], [ $items[0]['contents'], $items[3]['contents'] ], 'each Package carries its saved base composition (“What’s included”): names, configured quantities and media' );
			$this->same( [ 'status' => 'ok', 'items' => [] ], $items[0]['addons'], 'a Package without add-on relationships reads as a successful empty list' );
			foreach ( [ 'draft', 'pending', 'private' ] as $key ) {
				$this->ok( ! in_array( $p[ $key ], array_column( $items, 'id' ), true ), "{$key} product is not publicly visible: excluded" );
			}
			$this->ok( ! in_array( $p['noprice'], array_column( $items, 'id' ), true ), 'product without a price is not purchasable: excluded' );
			$this->ok( ! in_array( $p['archived'], array_column( $items, 'id' ), true ), 'archived Package excluded (BR-070)' );
			$this->ok( ! in_array( $p['archcomp'], array_column( $items, 'id' ), true ), 'archived required component excluded (BR-080)' );
			$this->ok( ! in_array( $p['unassigned'], array_column( $items, 'id' ), true ), 'unassigned Package never listed' );
			$this->same( $items, $this->model( [ 'bc_session' => (string) $s['x'] ], $this->manager( $f['site'] ) )['packages']['items'], 'a Hall Manager sees exactly what a customer sees (no draft Packages)' );

			$y = $this->model( [ 'bc_session' => (string) $s['y'] ] );
			$this->same( [ 'date', 'ok', [] ], [ $y['state'], $y['packages']['status'], $y['packages']['items'] ], 'eligible Session whose only Package fails BR-080 stays visible with no Packages' );
			$none = $this->model( [ 'bc_session' => (string) $s['none'] ] );
			$this->same( [ 'date', 'ok', [] ], [ $none['state'], $none['packages']['status'], $none['packages']['items'] ], 'legacy Session without Packages stays visible with no Packages' );

			$table = preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . 'bingo_packages';
			$this->break = '/FROM ' . $table . ' WHERE package_id = ' . $p['hybrid'] . '\b/';
			$partial = $this->model( [ 'bc_session' => (string) $s['x'] ] );
			$this->same( [ 'partial', [ $p['paper'], $p['hidden'], $p['digital'] ] ], [ $partial['packages']['status'], array_column( $partial['packages']['items'], 'id' ) ], 'one unreadable Package: partial, verified cards kept' );
			$broken = $this->model( [ 'bc_session' => (string) $s['z'] ] );
			$this->same( [ 'unavailable', [] ], [ $broken['packages']['status'], $broken['packages']['items'] ], 'no verified Package and an error: unavailable, never "none"' );
			$this->break = '/SELECT package_id FROM `' . preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . 'bingo_session_packages` WHERE session_id = ' . $s['x'] . ' /';
			$this->same( 'unavailable', $this->model( [ 'bc_session' => (string) $s['x'] ] )['packages']['status'], 'unreadable assignments: unavailable' );
			$this->break = '';

			wp_set_current_user( $this->admin );
			$this->ok( ! is_wp_error( ( new BingoPlayProductService( $this->audit ) )->restore( $f['fragile'] ) ), 'required component restored' );
			$this->same( [ $p['archcomp'] ], array_column( $this->model( [ 'bc_session' => (string) $s['y'] ] )['packages']['items'], 'id' ), 'restored component makes the Package available again' );
			$this->ok( ! is_wp_error( ( new BingoPlayProductService( $this->audit ) )->archive( $f['fragile'] ) ), 'required component archived again for later checks' );
		} finally {
			$this->break = '';
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * Per-occurrence saved time zones
	 * ================================================================== */

	private function timezones(): void {
		$this->group( 'Saved time zones and window edges' );
		$site = $this->hall( 'zones', self::K );
		switch_to_blog( $site );
		try {
			wp_set_current_user( $this->admin );
			$paper = $this->play( 'Zone paper', 'paper' );
			$pack  = $this->package( 'Zone Pack', [ [ $paper, 1 ] ] );
			$sk    = $this->schedule( $this->template( 'Kiritimati Night' ), [ $pack ], self::K );
			$sp    = $this->schedule( $this->template( 'Pago Pago Night' ), [ $pack ], self::P );
			update_option( 'timezone_string', self::K ); // The Hall setting changes after both schedules exist.
			$k  = static fn( int $days ): string => self::day( self::K, $days );
			$pd = static fn( int $days ): string => self::day( self::P, $days );
			$gap = ( new DateTimeImmutable( $k( 0 ) ) )->diff( new DateTimeImmutable( $pd( 0 ) ) )->days;
			$this->ok( in_array( $gap, [ 1, 2 ], true ), 'Kiritimati and Pago Pago local dates differ (' . $gap . ' day[s]) right now' );
			$this->same( [ self::K, self::P ], [ $sk['zone'], $sp['zone'] ], 'each schedule captured the Hall zone at creation' );

			$on = [];
			$on['k0']  = $this->occurrence( $sk, $k( 0 ), [ $pack ] );
			$on['kp']  = $this->occurrence( $sk, $pd( 0 ), [ $pack ] );   // Before Kiritimati's today.
			$on['k30'] = $this->occurrence( $sk, $k( 30 ), [ $pack ] );
			$on['k31'] = $this->occurrence( $sk, $k( 31 ), [ $pack ] );
			$on['p0']  = $this->occurrence( $sp, $pd( 0 ), [ $pack ] );
			$on['pk']  = $this->occurrence( $sp, $k( 0 ), [ $pack ] );   // Future for Pago Pago.
			$on['p30'] = $this->occurrence( $sp, $pd( 30 ), [ $pack ] );
			$on['p31'] = $this->occurrence( $sp, $pd( 31 ), [ $pack ] );
			$on['pm1'] = $this->occurrence( $sp, $pd( -1 ), [ $pack ] );
			$on['l0']  = $this->legacy( $k( 0 ), 'Legacy Kiritimati', true, [ $pack ] );
			$on['lm1'] = $this->legacy( $k( -1 ), 'Legacy Yesterday', true, [ $pack ] );

			$m        = $this->model();
			$expected = [];
			foreach ( [ 'k0' => $k( 0 ), 'k30' => $k( 30 ), 'p0' => $pd( 0 ), 'pk' => $k( 0 ), 'p30' => $pd( 30 ), 'l0' => $k( 0 ) ] as $key => $date ) {
				$expected[ $date ][] = $on[ $key ];
			}
			ksort( $expected );
			$actual = array_map( static fn( array $entry ): array => array_column( $entry['sessions'], 'id' ), $m['dates'] );
			foreach ( $actual as &$ids ) { sort( $ids ); }
			unset( $ids );
			foreach ( $expected as &$ids ) { sort( $ids ); }
			unset( $ids );
			$this->same( $expected, $actual, 'each occurrence is judged in its own saved zone; legacy rows in the Hall setting' );
			$this->same( $k( 0 ), $m['today'], 'today marker follows the current Hall setting' );
			$this->ok( isset( $m['dates'][ $pd( 0 ) ] ) && $pd( 0 ) < $m['today'], 'a Pago Pago Session dated before the Kiritimati marker is still selectable' );
			$ids = array_column( array_merge( ...array_column( $m['dates'], 'sessions' ) ), 'id' );
			foreach ( [ 'kp' => 'Kiritimati yesterday', 'k31' => 'Kiritimati +31', 'p31' => 'Pago Pago +31', 'pm1' => 'Pago Pago yesterday', 'lm1' => 'legacy yesterday' ] as $key => $label ) {
				$this->ok( ! in_array( $on[ $key ], $ids, true ), "{$label} is outside its window" );
			}
			$times = [];
			foreach ( $m['dates'] as $entry ) {
				foreach ( $entry['sessions'] as $session ) { $times[ $session['id'] ] = [ $session['time'], $session['timezone'] ]; }
			}
			$this->same( [ '18:00', self::K ], $times[ $on['k0'] ], 'Kiritimati start time shown with its saved zone' );
			$this->same( [ '18:00', self::P ], $times[ $on['pk'] ], 'Pago Pago start time shown with its saved zone, not the Hall setting' );
			[ , $xpath ] = $this->render();
			$this->same( 1, $xpath->query( '//a[@data-calendar-date="' . $pd( 0 ) . '"]' )->length, 'the earlier Pago Pago date renders as a selectable link' );
			$this->same( 1, $xpath->query( '//a[@data-calendar-date="' . $k( 0 ) . '"][contains(@class, "is-today")]' )->length, 'the today marker sits on the Hall-local date' );
			$this->same( 0, $xpath->query( '//a[@data-calendar-date="' . $pd( 0 ) . '"][contains(@class, "is-today")]' )->length, 'the earlier saved-zone date is selectable without the marker' );
		} finally {
			update_option( 'timezone_string', self::K );
			restore_current_blog();
		}
	}

	/* ================================================================== *
	 * Classification, cap, query failure and guards
	 * ================================================================== */

	private function classification(): void {
		$this->group( 'Result classification' );
		$site = $this->hall( 'partial', self::NY );
		switch_to_blog( $site );
		try {
			wp_set_current_user( $this->admin );
			$t     = static fn( int $days ): string => self::day( self::NY, $days );
			$paper = $this->play( 'Partial paper', 'paper' );
			$pack  = $this->package( 'Partial Pack', [ [ $paper, 1 ] ] );
			$sched = $this->schedule( $this->template( 'Corrupt Zone Night' ), [ $pack ] );
			$this->legacy( $t( 31 ), 'Legacy Outside Window', true, [ $pack ] );                                   // false
			$corrupt = $this->occurrence( $sched, $t( 3 ), [ $pack ], [ 'zone' => 'Invalid/Zone', 'force_on' => true ] ); // WP_Error
			$this->same( [ 'version' => 1, 'status' => 'unavailable' ], $this->model(), 'one false result plus one WP_Error: unavailable' );
			[ , $xpath ] = $this->render();
			$this->same( [ 1, 1, 0, 0 ], [
				$xpath->query( '//section[@id="calendar"]//h2[normalize-space()="Find Your Next Session"]' )->length,
				$xpath->query( '//section[@id="calendar"]//p[normalize-space()="Sessions can’t be shown right now."]' )->length,
				$xpath->query( '//section[@id="calendar"]//table' )->length,
				$xpath->query( '//section[@id="calendar"][@data-session-calendar]' )->length,
			], 'unavailable: heading and failure message, no grid' );

			$verified = $this->legacy( $t( 2 ), 'Legacy Verified', true, [ $pack ] );
			$m        = $this->model();
			$this->same( [ 'partial', [ $t( 2 ) ], [ $verified ] ], [ $m['status'], array_keys( $m['dates'] ), array_column( $m['dates'][ $t( 2 ) ]['sessions'], 'id' ) ], 'one verified result plus an error: partial' );
			$this->ok( ! in_array( $corrupt, array_column( array_merge( ...array_column( $m['dates'], 'sessions' ) ), 'id' ), true ), 'unverifiable Session never selectable' );
			[ , $xpath ] = $this->render( [ 'bc_date' => $t( 3 ) ] );
			$this->same( 1, $xpath->query( '//p[@data-calendar-notice][normalize-space()="Some Sessions couldn’t be checked right now."]' )->length, 'partial notice rendered' );
			$this->same( 1, $xpath->query( '//p[@class="calendar-message"][contains(., "No Sessions could be confirmed for ' . HomepageSessionCalendar::date_label( $t( 3 ) ) . '.")]' )->length, 'partial empty date: "could be confirmed" wording' );
			$this->same( 0, $xpath->query( '//*[contains(., "No Sessions are available")]' )->length, 'partial result never claims there are no Sessions' );

			// Unverifiable Sessions never extend the reachable span.
			update_option( 'timezone_string', self::K );
			$late = $this->schedule( $this->template( 'Late Kiritimati' ), [ $pack ] );
			update_option( 'timezone_string', self::NY );
			$late_date = self::day( self::K, 30 );
			$this->occurrence( $late, $late_date, [ $pack ] );
			$this->break = '/FROM ' . preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . 'bingo_session_schedules WHERE schedule_id = ' . $late['id'] . '\b/';
			$m           = $this->model();
			$this->break = '';
			$this->same( [ 'partial', HomepageSessionCalendar::reachable_months( [ $t( 2 ) ], $t( 0 ) ) ], [ $m['status'], array_column( $m['months'], 'month' ) ], 'months span verified dates only' );
			$this->ok( ! isset( $m['dates'][ $late_date ] ), 'an unverifiable in-window Session adds no date' );
			if ( substr( $late_date, 0, 7 ) !== substr( $t( 2 ), 0, 7 ) ) {
				$this->same( 'month_unavailable', $this->model_with_break( [ 'bc_month' => substr( $late_date, 0, 8 ) . '01' ], $late['id'] )['state'], "the unverifiable Session's later month is unreachable" );
			} else {
				$this->notes[] = 'Unverifiable-span month check is indistinguishable today (same month); the deterministic resolution and reachable-month groups cover it.';
			}
			$this->browser['partial'] = [ 'site' => $site, 'host' => get_site( $site )->domain, 'empty_date' => $t( 3 ), 'date' => $t( 2 ) ];

			$this->group( 'Candidate cap, query failure and guards' );
			$cap = $this->hall( 'cap', self::NY );
			switch_to_blog( $cap );
			try {
				global $wpdb;
				$now  = current_time( 'mysql', true );
				$rows = [];
				for ( $i = 0; $i < 500; $i++ ) {
					$rows[] = $wpdb->prepare( '(%s, %s, 1, %s, %s, %s)', $t( $i % 31 ), 'Cap Session ' . $i, 'active', $now, $now );
				}
				$wpdb->query( 'INSERT INTO ' . Migrations::sessions_table() . ' (session_date, display_name, available_for_sale, status, created_at, updated_at) VALUES ' . implode( ',', $rows ) );
				$m = $this->model();
				$this->same( [ 'ok', 500 ], [ $m['status'], count( array_merge( ...array_column( $m['dates'], 'sessions' ) ) ) ], 'exactly 500 candidates are all read' );
				$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Migrations::sessions_table() . " (session_date, display_name, available_for_sale, status, created_at, updated_at) VALUES (%s, 'Cap Session 500', 1, 'active', %s, %s)", $t( 1 ), $now, $now ) );
				$this->same( 'unavailable', $this->model()['status'], 'more than 500 candidates: unavailable, never truncated' );
			} finally {
				restore_current_blog();
			}
			$this->break = '/FROM `' . preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . 'bingo_sessions`\s+WHERE status/';
			$this->same( 'unavailable', $this->model()['status'], 'candidate query failure: unavailable' );
			$this->break = '';
			$version = static fn() => '2';
			add_filter( 'pre_option_' . Migrations::VERSION_OPTION, $version );
			try { $this->same( 'unavailable', $this->model()['status'], 'Session schema not current: unavailable' ); }
			finally { remove_filter( 'pre_option_' . Migrations::VERSION_OPTION, $version ); }
		} finally {
			$this->break = '';
			restore_current_blog();
		}
		switch_to_blog( get_main_site_id() );
		try { $this->same( 'unavailable', $this->model()['status'], 'main network site (not a Hall): unavailable' ); }
		finally { restore_current_blog(); }
	}

	private function model_with_break( array $query, int $schedule_id ): array {
		$this->break = '/FROM ' . preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . 'bingo_session_schedules WHERE schedule_id = ' . $schedule_id . '\b/';
		try { return $this->model( $query ); } finally { $this->break = ''; }
	}

	/* ================================================================== *
	 * Colliding IDs across Halls
	 * ================================================================== */

	private function collisions(): void {
		$this->group( 'Colliding IDs and Hall switches' );
		$ids = [];
		foreach ( [ 'a' => [ 'Alpha', true ], 'b' => [ 'Beta', false ] ] as $label => [ $name, $on ] ) {
			$site = $this->hall( 'collide-' . $label, self::NY );
			switch_to_blog( $site );
			try {
				wp_set_current_user( $this->admin );
				$package = $this->package( $name . ' Package', [ [ $this->play( $name . ' paper', 'paper' ), 1 ] ] );
				$session = $this->legacy( self::day( self::NY, 2 ), $name . ' Session', $on, [ $package ] );
				$ids[ $label ] = [ $site, $session, $package ];
			} finally {
				restore_current_blog();
			}
		}
		$this->same( [ 1, 1 ], [ $ids['a'][1], $ids['b'][1] ], 'both Halls hold Session ID 1' );
		$this->same( [ 1, 1 ], [ $ids['a'][2], $ids['b'][2] ], 'both Halls hold Package ID 1' );
		foreach ( [ 'a', 'b', 'a' ] as $pass => $label ) {
			switch_to_blog( $ids[ $label ][0] );
			try {
				$m = $this->model( [ 'bc_session' => '1' ] );
				if ( 'a' === $label ) {
					$this->same( [ 'date', 'Alpha Session', [ 'Alpha Package' ] ], [ $m['state'], $m['session']['name'], array_column( $m['packages']['items'], 'name' ) ], "pass {$pass}: Hall A resolves its own Session 1 and Package 1" );
				} else {
					$this->same( [ 'session_unavailable', null ], [ $m['state'], $m['session'] ], "pass {$pass}: Hall B's Session 1 is off sale there" );
					$this->same( 'none', $this->model()['state'], "pass {$pass}: Hall B has no eligible Sessions" );
					[ , , $html ] = $this->render( [ 'bc_session' => '1' ] );
					$this->ok( ! str_contains( $html, 'Alpha' ), "pass {$pass}: nothing from Hall A leaks into Hall B" );
				}
			} finally {
				restore_current_blog();
			}
		}
		$this->browser['empty'] = [ 'site' => $ids['b'][0], 'host' => get_site( $ids['b'][0] )->domain ];
	}

	/* ================================================================== *
	 * 4. Rendering
	 * ================================================================== */

	private function rendering( array $f ): void {
		$this->group( 'Homepage rendering (signed out)' );
		$s = $f['sessions'];
		$t = static fn( int $days ): string => self::day( self::NY, $days );
		$cards = static fn() => [ 'background_id' => 0, 'cards' => [ [ 'image_id' => 0, 'background_id' => 0, 'text' => 'Supplies', 'url' => 'https://example.test/supplies', 'name' => 'Supplies' ] ] ];
		add_filter( 'bingocommerce_homepage_categories', $cards, 20 );
		switch_to_blog( $f['site'] );
		try {
			$m = $this->model();
			[ , $xpath ] = $this->render();
			$order = [];
			// get_header() uses require_once, so only a process's first render emits <main>; compare top-level sections.
			foreach ( $xpath->query( '//section[not(ancestor::section)]' ) as $section ) {
				$order[] = $section->getAttribute( 'id' ) ?: $section->getAttribute( 'class' );
			}
			// The default trust row (2026-10-23) follows the calendar; no testimonials are saved here.
			$this->same( [ 'hero', 'bc-home-categories', 'calendar', 'bc-home-trust', 'visit-section' ], $order, 'calendar below Shop by Category and before the trust row and Visit Us' );
			$links = array_map( static fn( DOMElement $a ): string => $a->getAttribute( 'data-calendar-date' ), iterator_to_array( $xpath->query( '//section[@id="calendar"]//a[@data-calendar-date]' ) ) );
			$this->same( array_keys( $m['dates'] ), $links, 'highlighted dates are exactly the eligible result' );
			foreach ( $m['dates'] as $date => $entry ) {
				$this->same( count( $entry['sessions'] ), $xpath->query( '//div[@data-calendar-date-panel="' . $date . '"]//li' )->length, "Session rows for {$date} come from the same result" );
			}
			$this->same( [ $t( 0 ) ], array_map( static fn( DOMElement $a ): string => $a->getAttribute( 'data-calendar-date' ), iterator_to_array( $xpath->query( '//a[@aria-current="date"]' ) ) ), 'earliest eligible date marked current' );
			$this->same( 1, $xpath->query( '//div[@data-calendar-date-panel][not(@hidden)]' )->length, 'exactly one Session panel visible' );
			$this->same( 'Sessions on ' . HomepageSessionCalendar::date_label( $t( 0 ) ), trim( $xpath->evaluate( 'string(//div[@data-calendar-date-panel][not(@hidden)]/h4)' ) ), 'panel heading uses MM/DD/YYYY' );
			$this->same( '18:00 ' . self::NY, trim( $xpath->evaluate( 'string(//div[@data-calendar-date-panel="' . $t( 0 ) . '"]//li[1]//span[@class="calendar-session-row__time"])' ) ), 'structured time with its saved zone' );
			$this->same( 0, $xpath->query( '//div[@data-calendar-date-panel="' . $t( 0 ) . '"]//li[2]//span[@class="calendar-session-row__time"]' )->length, 'legacy row shows no time' );
			foreach ( [ $t( -1 ), $t( 1 ), $t( 31 ) ] as $date ) {
				if ( substr( $date, 0, 7 ) === substr( $m['month'], 0, 7 ) ) {
					$this->same( 0, $xpath->query( '//div[@data-calendar-month="' . $m['month'] . '"]//a[@data-calendar-date="' . $date . '"]' )->length, "{$date} is not a link" );
					$this->same( 1, $xpath->query( '//div[@data-calendar-month="' . $m['month'] . '"]//span[contains(@class,"is-unavailable")][span[normalize-space()="' . (int) substr( $date, 8 ) . '"]]' )->length, "{$date} renders greyed out" );
				}
			}
			$this->same( 0, $xpath->query( '//section[@id="calendar"]//*[contains(@class,"is-unavailable") or contains(@class,"is-outside")][self::a or @tabindex or @href]' )->length, 'greyed and outside dates are not interactive' );
			$this->same( count( $m['months'] ), $xpath->query( '//div[@data-calendar-month]' )->length, 'every reachable month is rendered' );
			$this->same( 1, $xpath->query( '//div[@data-calendar-month][not(@hidden)]' )->length, 'one month visible' );
			$this->same( 0, $xpath->query( '//*[@id="session-packages"]' )->length, 'no Package block without a Session link' );
			foreach ( $xpath->query( '//section[@id="calendar"]//a[@aria-label]' ) as $link ) {
				if ( $link->hasAttribute( 'data-calendar-date' ) ) {
					$this->ok( 1 === preg_match( '#^\d{2}/\d{2}/\d{4}, \d+ Sessions?$#', $link->getAttribute( 'aria-label' ) ), 'date link named with MM/DD/YYYY and its Session count' );
				}
			}

			[ , $xpath, $html ] = $this->render( [ 'bc_session' => (string) $s['x'] ] );
			$block = $xpath->query( '//section[@id="calendar"]//div[@id="session-packages"]' )->item( 0 );
			$this->ok( $block instanceof DOMElement, 'Package block rendered inline below the calendar' );
			$this->same( [ 'Early Bird Paper Pack', 'Hidden & Fun Pack', 'Digital Dozen', 'Combo Night' ], array_map( static fn( DOMElement $h ): string => trim( $h->textContent ), iterator_to_array( $xpath->query( '//div[@id="session-packages"]//span[contains(@class,"session-package__name")]' ) ) ), 'cards show authoritative names' );
			$this->same( [ 'Paper Package', 'Paper Package', 'Digital Package', 'Hybrid Package' ], array_map( static fn( DOMElement $e ): string => trim( $e->textContent ), iterator_to_array( $xpath->query( '//div[@id="session-packages"]//span[contains(@class,"session-package__type")]' ) ) ), 'cards show Package types' );
			$this->same( 1, $xpath->query( '//div[@id="session-packages"]//li[1]//img' )->length, 'card image from the product image' );
			$this->same( 1, $xpath->query( '//div[@id="session-packages"]//li[3]//del' )->length, 'sale price shown natively' );
			// The purchase step uses only the Sessions-supplied cart-extension route: no direct WooCommerce shortcut.
			$this->same( 0, $xpath->query( '//div[@id="session-packages"]//*[contains(@href,"add-to-cart") or @name="add-to-cart" or @data-product_id or contains(@class,"add_to_cart_button") or contains(@class,"ajax_add_to_cart") or (self::form and @action)]' )->length, 'no direct WooCommerce add-to-cart link, field, product ID or form action' );
			$this->same( 0, $this->usable_controls( $xpath, '//div[@id="session-packages"]' ), 'without JavaScript every purchase control is hidden or disabled' );
			$this->same( 1, $xpath->query( '//form[@data-bingo-purchase]/p[@data-purchase-nojs][not(@hidden)][normalize-space()="Turn on JavaScript to choose a Package and add it to your cart."]' )->length, 'no-JS note shown with the informational cards' );
			$this->ok( ! str_contains( strtolower( $html ), 'per seat' ) && ! str_contains( strtolower( $html ), 'admission' ), 'no reference placeholder wording' );
			$this->same( [ 'What’s included Paper play product Paper · Quantity: 2', 'What’s included Paper play product Paper · Quantity: 1 Digital play product Digital · Quantity: 2' ], array_map( static fn( DOMElement $d ): string => trim( preg_replace( '/\s+/u', ' ', $d->textContent ) ), [ $xpath->query( '//div[@id="session-packages"]//div[contains(@class,"session-package__contents")]' )->item( 0 ), $xpath->query( '//div[@id="session-packages"]//div[contains(@class,"session-package__contents")]' )->item( 3 ) ] ), '“What’s included” shows each card’s own saved contents' );
			$this->same( 1, $xpath->query( '//li[@aria-current="true"][contains(@class,"is-selected")]' )->length, 'selected Session row marked current' );
			$this->ok( str_ends_with( $xpath->evaluate( 'string(//li[@aria-current="true"]//a/@href)' ), '?bc_session=' . $s['x'] . '#session-packages' ), 'View Packages is a Session-only server link' );

			[ , $xpath ] = $this->render( [ 'bc_session' => (string) $s['y'] ] );
			$this->same( 1, $xpath->query( '//div[@id="session-packages"]//p[normalize-space()="No Packages are currently available for this Session."]' )->length, 'eligible Session with no eligible Packages: explicit empty Package state' );

			[ , $xpath ] = $this->render( [ 'bc_date' => $t( 1 ) ] );
			$this->same( 1, $xpath->query( '//p[@class="calendar-message"][contains(., "No Sessions are available for purchase on ' . HomepageSessionCalendar::date_label( $t( 1 ) ) . '.")]' )->length, 'requested empty date: its own empty state' );
			$this->same( home_url( '/' ) . '#calendar', $xpath->evaluate( 'string(//div[@data-calendar-message]//a/@href)' ), 'link back to available dates' );
			$this->same( 0, $xpath->query( '//a[@aria-current="date"]' )->length, 'no other date selected in its place' );
			if ( substr( $t( 1 ), 0, 7 ) === substr( $t( 0 ), 0, 7 ) ) {
				$this->same( 1, $xpath->query( '//span[contains(@class,"is-requested")][@aria-current="date"]' )->length, 'requested date marked without becoming selectable' );
			}
			$this->same( 0, $xpath->query( '//div[@data-calendar-date-panel][not(@hidden)]' )->length, 'no Session rows for the empty date' );

			foreach ( [ [ 'bc_date' => '' ], [ 'bc_session' => '0' ], [ 'bc_month' => 'x' ] ] as $query ) {
				[ , $xpath ] = $this->render( $query );
				$this->same( [ 1, 0, 0 ], [ $xpath->query( '//p[@class="calendar-message"][contains(., "That selection isn’t valid.")]' )->length, $xpath->query( '//a[@aria-current="date"]' )->length, $xpath->query( '//*[@id="session-packages"]' )->length ], 'malformed ' . wp_json_encode( $query ) . ': recovery message, nothing selected, no Packages' );
			}
			[ , $xpath ] = $this->render( [ 'bc_session' => '999999' ] );
			$this->same( 1, $xpath->query( '//p[contains(., "That Session is no longer available for purchase.")]' )->length, 'stale Session: recovery message' );
			[ , $xpath ] = $this->render( [ 'bc_session' => (string) $s['x'], 'bc_date' => $t( 0 ) ] );
			$this->same( [ 1, 0 ], [ $xpath->query( '//p[contains(., "That date and Session don’t match.")]' )->length, $xpath->query( '//*[@id="session-packages"]' )->length ], 'conflicting date: rejected without Packages' );
			[ , $xpath ] = $this->render( [ 'bc_month' => $m['month'] ] );
			$this->same( [ 1, 0 ], [ $xpath->query( '//p[normalize-space()="Choose a highlighted date."]' )->length, $xpath->query( '//a[@aria-current="date"]' )->length ], 'month only: prompt, no date selected' );

			$this->group( 'Provider failure and main-site omission' );
			remove_filter( HomepageSessionCalendar::FILTER, [ HomepageSessionCalendar::class, 'provide' ] );
			try {
				$this->failure_rendered( 'provider absent' );
			} finally {
				HomepageSessionCalendar::register();
			}
			foreach ( [ 'malformed response' => [ 'version' => 1, 'status' => 'ok' ], 'string response' => 'ok', 'reported unavailable' => [ 'version' => 1, 'status' => 'unavailable' ] ] as $label => $value ) {
				$double = static fn() => $value;
				add_filter( HomepageSessionCalendar::FILTER, $double, 20 );
				try { $this->failure_rendered( $label ); }
				finally { remove_filter( HomepageSessionCalendar::FILTER, $double, 20 ); }
			}
		} finally {
			remove_filter( 'bingocommerce_homepage_categories', $cards, 20 );
			restore_current_blog();
		}
		switch_to_blog( $this->browser['empty']['site'] );
		try {
			[ , $xpath ] = $this->render();
			$this->same( [ 1, 1, 0 ], [
				$xpath->query( '//p[@class="calendar-message"][normalize-space()="No Sessions are currently available for purchase."]' )->length,
				$xpath->query( '//section[@id="calendar"]//table' )->length,
				$xpath->query( '//section[@id="calendar"]//a[@data-calendar-date]' )->length,
			], 'successful empty read: the only "no Sessions" message, with a greyed grid' );
			$this->same( 0, $xpath->query( '//*[contains(., "can’t be shown")]' )->length, 'successful empty read is not the failure state' );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( get_main_site_id() );
		try {
			[ , $xpath ] = $this->render();
			$this->same( 0, $xpath->query( '//section[@id="calendar"]' )->length, 'main network site (not a Hall): section omitted' );
		} finally {
			restore_current_blog();
		}

		$this->group( 'Synthetic three-month rendering' );
		switch_to_blog( $f['site'] );
		$three  = [ 'ok' => $this->synthetic( [ '2026-01-31' => 2, '2026-03-02' => 1 ] ), 'partial' => $this->synthetic( [ '2026-01-31' => 2, '2026-03-02' => 1 ], 'partial' ) ];
		$status = 'ok';
		$double = static function () use ( $three, &$status ): array {
			$model = HomepageSessionCalendar::resolve( $three[ $status ], $_GET, '2026-01-31' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_array( $model['session'] ?? null ) ) { $model['packages'] = [ 'status' => 'ok', 'items' => [] ]; }
			return $model;
		};
		add_filter( HomepageSessionCalendar::FILTER, $double, 20 );
		try {
			[ , $xpath ] = $this->render( [ 'bc_month' => '2026-02-01' ] );
			$this->same( [ '2026-01-01', '2026-02-01', '2026-03-01' ], array_map( static fn( DOMElement $d ): string => $d->getAttribute( 'data-calendar-month' ), iterator_to_array( $xpath->query( '//div[@data-calendar-month]' ) ) ), 'three month panels rendered' );
			$this->same( '2026-02-01', $xpath->evaluate( 'string(//div[@data-calendar-month][not(@hidden)]/@data-calendar-month)' ), 'requested February visible' );
			$this->same( 0, $xpath->query( '//div[@data-calendar-month="2026-02-01"]//a[@data-calendar-date]' )->length, 'February has no selectable dates' );
			$this->same( [ '2026-01-01', '2026-03-01' ], [ $xpath->evaluate( 'string(//div[@data-calendar-month="2026-02-01"]//a[@data-calendar-direction="prev"]/@data-calendar-nav)' ), $xpath->evaluate( 'string(//div[@data-calendar-month="2026-02-01"]//a[@data-calendar-direction="next"]/@data-calendar-nav)' ) ], 'February links to January and March' );
			$this->same( [ 0, 0 ], [ $xpath->query( '//div[@data-calendar-month="2026-01-01"]//a[@data-calendar-direction="prev"]' )->length, $xpath->query( '//div[@data-calendar-month="2026-03-01"]//a[@data-calendar-direction="next"]' )->length ], 'no navigation beyond the span' );
			$this->same( 1, $xpath->query( '//p[@class="calendar-message"][normalize-space()="No Sessions are available this month. See available dates"]' )->length, 'empty intermediate month: no highlighted-date prompt' );
			$kinds = static fn( DOMXPath $x, string $only = '' ): array => array_map( static fn( DOMElement $p ): string => $p->getAttribute( 'data-calendar-month-message' ), iterator_to_array( $x->query( '//div[@data-calendar-message][@data-calendar-month-only]/p[@data-calendar-month-message]' . $only ) ) );
			$this->same( [ [ 'month', 'month_empty' ], [ 'month_empty' ] ], [ $kinds( $xpath ), $kinds( $xpath, '[not(@hidden)]' ) ], 'month-only February: both month messages rendered for the arrows, only the empty-month one visible' );
			$this->same( 'Choose a highlighted date.', trim( $xpath->evaluate( 'string(//p[@data-calendar-month-message="month"][@hidden])' ) ), 'the hidden prompt is the translated server message' );
			[ , $xpath ] = $this->render( [ 'bc_month' => '2026-03-01' ] );
			$this->same( [ [ 'month' ], 'No Sessions are available this month. See available dates' ], [ $kinds( $xpath, '[not(@hidden)]' ), trim( preg_replace( '/\s+/', ' ', $xpath->evaluate( 'string(//p[@data-calendar-month-message="month_empty"][@hidden])' ) ) ) ], 'month-only March: prompt visible, empty-month message (with its recovery link) hidden' );
			$status = 'partial';
			[ , $xpath ] = $this->render( [ 'bc_month' => '2026-03-01' ] );
			$this->same( [ [ 'month' ], 'No Sessions could be confirmed this month. See available dates', 1 ], [ $kinds( $xpath, '[not(@hidden)]' ), trim( preg_replace( '/\s+/', ' ', $xpath->evaluate( 'string(//p[@data-calendar-month-message="month_empty"])' ) ) ), $xpath->query( '//p[@data-calendar-notice]' )->length ], 'partial month-only: the hidden empty-month message keeps the "could be confirmed" wording; notice rendered' );
			$this->same( 0, $xpath->query( '//*[@data-calendar-month-message][contains(., "No Sessions are available")]' )->length, 'partial month-only: no "are available" wording anywhere for the arrows to reveal' );
			$status = 'ok';
			foreach ( [ [], [ 'bc_date' => '2026-01-31' ], [ 'bc_date' => '2026-02-15' ], [ 'bc_month' => '2026-04-01' ], [ 'bc_date' => 'x' ], [ 'bc_session' => '999' ], [ 'bc_session' => '103' ], [ 'bc_session' => '103', 'bc_date' => '2026-01-31' ] ] as $query ) {
				[ , $xpath ] = $this->render( $query );
				$this->same( [ 0, 0 ], [ $xpath->query( '//*[@data-calendar-month-only]' )->length, $xpath->query( '//*[@data-calendar-month-message]' )->length ], 'not month-only ' . wp_json_encode( $query ) . ': no month message for the arrows to change' );
			}
			foreach ( $xpath->query( '//*[@data-calendar-date or @data-calendar-nav or @data-calendar-date-panel or @data-calendar-month]' ) as $node ) {
				foreach ( [ 'data-calendar-date', 'data-calendar-nav', 'data-calendar-date-panel', 'data-calendar-month' ] as $attribute ) {
					if ( $node->hasAttribute( $attribute ) ) {
						$this->ok( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $node->getAttribute( $attribute ) ), "{$attribute} is a YYYY-MM-DD value" );
					}
				}
			}
		} finally {
			remove_filter( HomepageSessionCalendar::FILTER, $double, 20 );
			restore_current_blog();
		}

		$this->group( 'Assets and boundaries' );
		$this->assets_only_on_front_page();
		$theme = ABSPATH . 'wp-content/themes/' . self::THEME;
		$js    = (string) file_get_contents( $theme . '/assets/js/homepage-calendar.js' );
		$this->ok( ! preg_match( '/new Date|Date\.(parse|now|UTC)|toISOString|getTimezoneOffset|fetch\(|XMLHttpRequest|localStorage/', $js ), 'script reads no clock, builds no dates and calls no API' );
		foreach ( [ 'template-parts/homepage-session-calendar.php', 'template-parts/homepage-session-packages.php', 'inc/session-calendar.php' ] as $file ) {
			$source = (string) file_get_contents( $theme . '/' . $file );
			$this->ok( ! str_contains( $source, '$_GET' ) && ! str_contains( $source, 'BingoCommerce\\Sessions' ) && ! str_contains( $source, '$wpdb' ), "{$file}: no request input, Session classes or database reads" );
		}
	}

	/** Controls a visitor could use or focus without the optional script: neither hidden nor disabled, nor inside a hidden element or disabled fieldset. */
	private function usable_controls( DOMXPath $xpath, string $scope ): int {
		$usable = 0;
		foreach ( $xpath->query( $scope . '//*[self::input or self::button or self::select or self::textarea or self::a]' ) as $control ) {
			$blocked = $control->hasAttribute( 'hidden' ) || $control->hasAttribute( 'disabled' );
			for ( $node = $control->parentNode; ! $blocked && $node instanceof DOMElement; $node = $node->parentNode ) {
				$blocked = $node->hasAttribute( 'hidden' ) || ( 'fieldset' === $node->nodeName && $node->hasAttribute( 'disabled' ) );
			}
			$usable += $blocked ? 0 : 1;
		}
		return $usable;
	}

	private function failure_rendered( string $label ): void {
		[ , $xpath ] = $this->render();
		$this->same( [ 1, 1, 0, 0 ], [
			$xpath->query( '//section[@id="calendar"]//h2[normalize-space()="Find Your Next Session"]' )->length,
			$xpath->query( '//section[@id="calendar"]//p[normalize-space()="Sessions can’t be shown right now."]' )->length,
			$xpath->query( '//section[@id="calendar"]//table' )->length,
			$xpath->query( '//*[contains(., "No Sessions are currently available")]' )->length,
		], "{$label}: heading kept, failure message, no grid, never the empty state" );
	}

	private function assets_only_on_front_page(): void {
		global $wp_query;
		$previous = $wp_query;
		$front    = static fn() => 'posts';
		add_filter( 'pre_option_show_on_front', $front );
		try {
			$wp_query          = new WP_Query();
			$wp_query->is_home = false;
			wp_dequeue_script( 'bingothemev1-homepage-calendar' );
			\BingoCommerce\BingoThemeV1\Theme::assets();
			$this->same( false, wp_script_is( 'bingothemev1-homepage-calendar', 'enqueued' ), 'calendar script absent from other pages' );
			$wp_query->is_home = true;
			\BingoCommerce\BingoThemeV1\Theme::assets();
			$this->same( true, wp_script_is( 'bingothemev1-homepage-calendar', 'enqueued' ), 'calendar script enqueued on the front page' );
		} finally {
			$wp_query = $previous;
			remove_filter( 'pre_option_show_on_front', $front );
			wp_dequeue_script( 'bingothemev1-homepage-calendar' );
		}
	}

	/* ================================================================== *
	 * Actual homepage GETs (php-cgi)
	 * ================================================================== */

	private function homepage_requests( array $f ): void {
		$this->group( 'Actual homepage GET (php-cgi)' );
		$s = $f['sessions'];
		$t = static fn( int $days ): string => self::day( self::NY, $days );
		[ $headers, $xpath, $redirects ] = $this->cgi( $f['site'], '' );
		$this->same( [], $redirects, 'bare homepage GET: no redirect' );
		foreach ( [ 'X-BC-Template: front-page.php', 'X-BC-Front: 1', 'X-BC-Registered-Vars: none', 'X-BC-Fixture-Guest: 0' ] as $header ) {
			$this->ok( str_contains( $headers, $header ), "static front page GET: {$header}" );
		}
		$this->ok( ! preg_match( '/^Status: [345]/mi', $headers ), 'no redirect or error status' );
		$this->same( $t( 0 ), $xpath->evaluate( 'string(//a[@aria-current="date"]/@data-calendar-date)' ), 'earliest eligible date selected on a bare request' );
		$this->same( 1, $xpath->query( '//script[contains(@src, "homepage-calendar.js")]' )->length, 'optional calendar script loaded on the homepage' );

		$cases = [
			'bc_date='                         => 'That selection isn’t valid.',
			'bc_date%5B%5D=' . $t( 3 )         => 'That selection isn’t valid.',
			'bc_date=%20' . $t( 3 )            => 'That selection isn’t valid.',
			'bc_session=01'                    => 'That selection isn’t valid.',
			'bc_session=' . $s['x'] . '&bc_date=' . $t( 0 ) => 'That date and Session don’t match.',
			'bc_session=' . $s['x'] . '&bc_month=2099-01-01' => 'That month can’t be shown.',
			'bc_month=' . substr( $t( 0 ), 0, 8 ) . '01'      => 'Choose a highlighted date.',
		];
		$canonical = [ 'bc_date=' => '?bc_date', 'bc_date%5B%5D=' . $t( 3 ) => '?bc_date%5B0%5D=' . $t( 3 ) ];
		foreach ( $cases as $query => $message ) {
			[ $headers, $xpath, $redirects ] = $this->cgi( $f['site'], $query );
			if ( isset( $canonical[ $query ] ) ) {
				$this->same( [ bc_hsc_home_url( $f['site'] ) . $canonical[ $query ] ], $redirects, "?{$query}: WordPress canonical redirect keeps the parameter supplied" );
			} else {
				$this->same( [], $redirects, "?{$query}: no redirect" );
			}
			$this->ok( str_contains( $headers, 'X-BC-Front: 1' ) && str_contains( $headers, 'X-BC-Template: front-page.php' ), "?{$query}: still the static front page" );
			$this->same( 1, $xpath->query( '//p[@class="calendar-message"][contains(., "' . $message . '")]' )->length, "?{$query}: {$message}" );
			$this->same( 0, $xpath->query( '//*[@id="session-packages"]' )->length, "?{$query}: no Packages" );
		}
		[ , $xpath ] = $this->cgi( $f['site'], 'bc_session=' . $s['x'] . '&bc_month=' . substr( $t( 0 ), 0, 8 ) . '01' );
		$this->same( [ 4, $t( 3 ) ], [ $xpath->query( '//div[@id="session-packages"]//li[contains(concat(" ", normalize-space(@class), " "), " session-package ")]' )->length, $xpath->evaluate( 'string(//a[@aria-current="date"]/@data-calendar-date)' ) ], 'Session GET with a valid month: its date, inline Packages' );
		[ , $xpath ] = $this->cgi( $f['site'], 'utm_source=newsletter' );
		$this->same( $t( 0 ), $xpath->evaluate( 'string(//a[@aria-current="date"]/@data-calendar-date)' ), 'unrelated parameters do not change the homepage' );

		switch_to_blog( $f['site'] );
		update_option( 'show_on_front', 'posts' );
		restore_current_blog();
		try {
			[ $headers, $xpath ] = $this->cgi( $f['site'], 'bc_date=' . $t( 3 ) );
			$this->ok( str_contains( $headers, 'X-BC-Template: front-page.php' ) && str_contains( $headers, 'X-BC-Front: 1' ), 'latest-posts front page: same template with parameters' );
			$this->same( $t( 3 ), $xpath->evaluate( 'string(//a[@aria-current="date"]/@data-calendar-date)' ), 'latest-posts front page resolves the requested date' );
		} finally {
			switch_to_blog( $f['site'] );
			update_option( 'show_on_front', 'page' );
			restore_current_blog();
		}
		foreach ( [ 'absent', 'unusable' ] as $provider ) {
			[ , $xpath ] = $this->cgi( $f['site'], '', [ 'BC_HSC_PROVIDER' => $provider ] );
			$this->same( [ 1, 1, 0 ], [
				$xpath->query( '//section[@id="calendar"]//h2' )->length,
				$xpath->query( '//p[normalize-space()="Sessions can’t be shown right now."]' )->length,
				$xpath->query( '//section[@id="calendar"]//table' )->length,
			], "GET with provider {$provider}: heading and failure message" );
		}
		$this->browser['main'] = [
			'site' => $f['site'], 'host' => get_site( $f['site'] )->domain, 'earliest' => $t( 0 ), 'second' => $t( 3 ), 'empty_date' => $t( 1 ),
			'session' => $s['x'], 'session_date' => $t( 3 ), 'other_session_date' => $t( 10 ),
			'packages' => [ 'Early Bird Paper Pack', 'Hidden & Fun Pack', 'Digital Dozen', 'Combo Night' ],
		];
	}

	/* ================================================================== *
	 * Optional browser check
	 * ================================================================== */

	private function browser(): void {
		$this->group( 'Browser (optional)' );
		if ( '1' !== getenv( 'BC_SESSION_CALENDAR_BROWSER' ) ) {
			$this->notes[] = 'Browser coverage SKIPPED: set BC_SESSION_CALENDAR_BROWSER=1 with BC_NODE_EXECUTABLE, BC_PLAYWRIGHT_MODULE, BC_CHROME_EXECUTABLE and BC_SESSION_CALENDAR_ARTIFACTS.';
			return;
		}
		$halls = [];
		foreach ( [ 'three' => 'ok', 'three_partial' => 'partial' ] as $name => $status ) {
			$site = $this->hall( str_replace( '_', '-', $name ), self::NY );
			$file = $this->artifacts() . '/' . str_replace( '_', '-', $name ) . '-month-eligible.json';
			switch_to_blog( $site );
			try {
				// Only the eligibility result is synthetic; the worker still runs real request parsing and resolve().
				file_put_contents( $file, wp_json_encode( [ 'today' => '2026-01-31', 'eligible' => $this->synthetic( [ '2026-01-31' => 2, '2026-03-02' => 1 ], $status ) ] ) );
			} finally {
				restore_current_blog();
			}
			$halls[ $name ] = [ 'site' => $site, 'host' => get_site( $site )->domain, 'eligible' => $file ];
		}
		$config = [
			'root' => rtrim( ABSPATH, '/' ), 'cgi' => dirname( PHP_BINARY ) . '/php-cgi', 'worker' => __DIR__ . '/homepage-session-calendar-request.php',
			'owner' => $this->token, 'network' => BC_FIXTURE_DOMAIN, 'artifacts' => $this->artifacts(), 'image' => dirname( ABSPATH ) . '/bingocommer-frontendstore/assets/images/product-bingo-paper.png',
			'main' => $this->browser['main'], 'partial' => $this->browser['partial'], 'empty' => $this->browser['empty'],
		] + $halls;
		$file = $this->artifacts() . '/browser-config.json';
		file_put_contents( $file, wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$node = (string) getenv( 'BC_NODE_EXECUTABLE' ) ?: 'node';
		$env  = [ 'PATH' => getenv( 'PATH' ), 'HOME' => getenv( 'HOME' ), 'BC_HSC_CONFIG' => $file, 'BC_PLAYWRIGHT_MODULE' => (string) getenv( 'BC_PLAYWRIGHT_MODULE' ), 'BC_CHROME_EXECUTABLE' => (string) getenv( 'BC_CHROME_EXECUTABLE' ) ];
		[ $exit, $out, $err ] = $this->process( [ $node, __DIR__ . '/homepage-session-calendar-browser.cjs' ], $env, 900 );
		echo $out;
		if ( '' !== trim( $err ) ) { fwrite( STDERR, $err ); }
		preg_match( '/Browser session calendar: (\d+) passed/', $out, $match );
		$this->ok( 0 === $exit && ! empty( $match[1] ), 'browser check passed (' . ( $match[1] ?? 0 ) . ' browser assertions)' );
	}

	/* ================================================================== *
	 * Helpers
	 * ================================================================== */

	/** @internal Deterministic read failure for one matching statement pattern. */
	public function query( string $sql ): string {
		if ( '' !== $this->break && 1 === preg_match( $this->break, $sql ) ) {
			return 'SELECT 1 FROM bc_session_calendar_table_that_cannot_exist';
		}
		return $sql;
	}

	/** The provider's model for the current Hall, as a signed-out visitor (or a given user). */
	private function model( array $query = [], int $user = 0 ): array {
		$previous = get_current_user_id();
		wp_set_current_user( $user );
		try { return HomepageSessionCalendar::model( $query ); }
		finally { wp_set_current_user( $previous ); }
	}

	/** Render the actual front page template in-process, signed out. */
	private function render( array $query = [] ): array {
		$previous_get  = $_GET;
		$previous_user = get_current_user_id();
		$_GET          = $query;
		wp_set_current_user( 0 );
		wp_set_template_globals();
		ob_start();
		try {
			include ABSPATH . 'wp-content/themes/' . self::THEME . '/front-page.php';
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = $previous_get;
			wp_set_current_user( $previous_user );
		}
		$this->debug( 'render-' . md5( wp_json_encode( $query ) . get_current_blog_id() ) . '.html', $html );
		return array_merge( $this->document( $html ), [ $html ] );
	}

	/** Opt-in raw output for diagnosis (BC_HSC_DEBUG=1); written only under the artifacts directory. */
	private function debug( string $name, string $content ): void {
		if ( '1' === getenv( 'BC_HSC_DEBUG' ) ) {
			file_put_contents( $this->artifacts() . '/' . $name, $content );
		}
	}

	private function document( string $html ): array {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		return [ $dom, new DOMXPath( $dom ) ];
	}

	/**
	 * One real homepage GET through php-cgi, following same-Hall redirects the way
	 * a browser would (WordPress core's canonical redirect normalizes some query
	 * strings). @return array{0:string,1:DOMXPath,2:array<int,string>} final headers, document, redirect targets
	 */
	private function cgi( int $site, string $query, array $extra = [] ): array {
		$this->own( $site );
		$worker    = __DIR__ . '/homepage-session-calendar-request.php';
		$cgi       = dirname( PHP_BINARY ) . '/php-cgi';
		$host      = get_site( $site )->domain;
		$redirects = [];
		if ( ! is_executable( $cgi ) ) { throw new RuntimeException( 'php-cgi is required for actual homepage GET checks.' ); }
		for ( $hop = 0; $hop < 4; $hop++ ) {
			$env = array_merge( [
				'PATH' => getenv( 'PATH' ), 'BC_TEST_DB' => 'bingocommerce_state_recon', 'BC_FIXTURE_CGI' => '1', 'BC_FIXTURE_WOOCOMMERCE' => '1',
				'BC_HSC_SITE' => (string) $site, 'BC_HSC_OWNER' => $this->token, 'HTTP_HOST' => $host, 'SERVER_NAME' => $host,
				'REQUEST_URI' => '/' . ( '' === $query ? '' : '?' . $query ), 'QUERY_STRING' => $query, 'REQUEST_METHOD' => 'GET',
				'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'SCRIPT_FILENAME' => $worker, 'REDIRECT_STATUS' => '1',
			], $extra );
			[ $exit, $out, $err ] = $this->process( [ $cgi, $worker ], $env, 60 );
			$this->debug( 'cgi-' . md5( $query . wp_json_encode( $extra ) ) . '.txt', $out . "\n----- STDERR -----\n" . $err );
			if ( 0 !== $exit ) { throw new RuntimeException( 'CGI homepage request failed: ' . $err ); }
			[ $headers, $html ] = array_pad( preg_split( '/\r?\n\r?\n/', $out, 2 ), 2, '' );
			if ( ! preg_match( '/^Status: 30[1278]/mi', $headers ) || ! preg_match( '/^Location: (\S+)/mi', $headers, $location ) ) {
				return [ $headers, $this->document( $html )[1], $redirects ];
			}
			$target = wp_parse_url( $location[1] );
			if ( ! is_array( $target ) || ( $target['host'] ?? '' ) !== $host || '/' !== ( $target['path'] ?? '/' ) ) {
				throw new RuntimeException( 'Homepage GET redirected away from the owned Hall front page: ' . $location[1] );
			}
			$redirects[] = $location[1];
			$query       = (string) ( $target['query'] ?? '' );
		}
		throw new RuntimeException( 'Too many homepage redirects.' );
	}

	/** Run a child process with non-blocking pipes and a hard timeout. @return array{0:int,1:string,2:string} */
	private function process( array $command, array $env, int $timeout ): array {
		$process = proc_open( $command, [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not launch ' . basename( (string) $command[1] ) . '.' ); }
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out      = '';
		$err      = '';
		$deadline = microtime( true ) + $timeout;
		do {
			$out   .= (string) stream_get_contents( $pipes[1] );
			$err   .= (string) stream_get_contents( $pipes[2] );
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) { break; }
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );
		if ( $status['running'] ) { proc_terminate( $process, 9 ); }
		$out .= (string) stream_get_contents( $pipes[1] );
		$err .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$closed = proc_close( $process );
		$exit   = $status['running'] ? 124 : ( $status['exitcode'] >= 0 ? $status['exitcode'] : $closed );
		return [ (int) $exit, $out, $err ];
	}

	private function hall( string $label, string $zone ): int {
		wp_set_current_user( $this->admin );
		add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'calendar-' . $label . '-' . strtolower( wp_generate_password( 8, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Calendar Hall ' . $label, 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = (int) $site;
		update_blog_option( $site, self::OWNER, $this->token );
		switch_to_blog( $site );
		try {
			WC_Install::create_tables();
			WC_Install::create_roles();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) {
				if ( class_exists( $schema ) ) { ( new $schema() )->register_tables( true ); }
			}
			\BingoCommerce\Core\Roles::sync_current_site();
			update_option( 'timezone_string', $zone );
			switch_theme( self::THEME );
			if ( ! Migrations::is_current() || ! \BingoCommerce\Catalog\Migrations::is_current() ) { throw new RuntimeException( 'Disposable Hall schemas are not current.' ); }
		} finally {
			restore_current_blog();
		}
		return (int) $site;
	}

	private function own( int $site ): void {
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, self::OWNER ) ) {
			throw new RuntimeException( 'Fixture Hall ownership refused.' );
		}
	}

	private function manager( int $site ): int {
		$this->own( $site );
		$id = wp_insert_user( [ 'user_login' => 'calendar-manager-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Manager fixture failed.' ); }
		$this->users[] = (int) $id;
		update_user_meta( $id, self::OWNER, $this->token );
		global $wpdb;
		// ADR-025's writer guard refuses add_user_to_blog() for Hall roles; plant the exact owned row.
		$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key ] );
		$wpdb->insert( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => serialize( [ \BingoCommerce\Core\Roles::HALL_MANAGER => true ] ) ] );
		clean_user_cache( $id );
		if ( ! user_can( $id, 'bc_manage_sessions' ) || is_super_admin( $id ) ) { throw new RuntimeException( 'Manager fixture lacks Hall capabilities.' ); }
		return (int) $id;
	}

	private function template( string $name ): int {
		global $wpdb;
		static $next = 0;
		$id  = 970000 + ( ++$next );
		$now = current_time( 'mysql', true );
		if ( 1 !== $wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => $id, 'name' => $name, 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ], [ '%d', '%s', '%s', '%d', '%s', '%s' ] ) ) {
			throw new RuntimeException( 'Template fixture failed.' );
		}
		return $id;
	}

	private function image( string $file ): int {
		// Attachment row and metadata only: nothing is written to the shared uploads directory.
		$id = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Calendar fixture image', 'post_status' => 'inherit' ], 'calendar-fixture/' . $file );
		if ( ! $id ) { throw new RuntimeException( 'Image fixture failed.' ); }
		update_post_meta( $id, '_wp_attached_file', 'calendar-fixture/' . $file );
		wp_update_attachment_metadata( $id, [ 'width' => 1200, 'height' => 900, 'file' => 'calendar-fixture/' . $file, 'sizes' => [] ] );
		return (int) $id;
	}

	private function product( string $name, array $options = [] ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( $options['status'] ?? 'publish' );
		$product->set_regular_price( $options['price'] ?? '10.00' );
		if ( isset( $options['sale'] ) ) { $product->set_sale_price( $options['sale'] ); }
		if ( isset( $options['visibility'] ) ) { $product->set_catalog_visibility( $options['visibility'] ); }
		if ( isset( $options['image'] ) ) { $product->set_image_id( $options['image'] ); }
		$product->set_virtual( true );
		$id = (int) $product->save();
		if ( $id < 1 ) { throw new RuntimeException( 'Product fixture failed.' ); }
		return $id;
	}

	private function play( string $name, string $medium, ?int $game_type = null ): int {
		$result = ( new BingoPlayProductService( $this->audit ) )->create( $this->product( $name . ' play product' ), $medium, 'digital' === $medium ? 3 : 2, $game_type );
		if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Play Product fixture failed: ' . $result->get_error_code() ); }
		return (int) $result->play_product['bingo_play_product_id'];
	}

	/** @param array<int,array{0:int,1:int}> $components */
	private function package( string $name, array $components, array $options = [] ): int {
		$result = ( new PackageService( $this->audit ) )->create(
			$this->product( $name, $options ),
			array_map( static fn( array $c ): array => [ 'bingo_play_product_id' => $c[0], 'quantity' => $c[1] ], $components )
		);
		if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Package fixture failed: ' . $result->get_error_code() ); }
		return (int) $result->package['package_id'];
	}

	/** A committed schedule through the real service; it captures the Hall zone at creation. */
	private function schedule( int $template, array $packages, ?string $zone = null ): array {
		global $wpdb;
		if ( null !== $zone ) { update_option( 'timezone_string', $zone ); }
		$date   = self::day( (string) get_option( 'timezone_string' ), 2 );
		$result = ( new SessionScheduleService( $this->audit ) )->create( $template, 'dates', '18:00', [ $date ], $packages );
		if ( ! $result instanceof SessionScheduleResult || SessionScheduleResult::STATUS_OK !== $result->status ) { throw new RuntimeException( 'Schedule fixture failed.' ); }
		$id   = (int) $result->schedule['schedule_id'];
		$name = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE portal_session_id = %d', Migrations::portal_session_templates_table(), $template ) );
		$zone = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT timezone_name FROM %i WHERE schedule_id = %d', Migrations::schedules_table(), $id ) );
		return [ 'id' => $id, 'zone' => $zone, 'template' => $template, 'name' => $name ];
	}

	/**
	 * Plant one generated occurrence in its schedule's committed definition (as
	 * generation leaves it), then switch sales ON through the real guarded writer.
	 */
	private function occurrence( array $schedule, string $date, array $packages, array $o = [] ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . Migrations::schedule_dates_table() . ' (schedule_id, definition_token, schedule_date, created_at)
			 SELECT schedule_id, active_definition_token, %s, %s FROM ' . Migrations::schedules_table() . " sch
			 WHERE schedule_id = %d AND mode = 'dates' AND NOT EXISTS (
			 SELECT 1 FROM " . Migrations::schedule_dates_table() . ' d WHERE d.schedule_id = sch.schedule_id
			 AND d.definition_token = sch.active_definition_token AND d.schedule_date = %s)',
			$date, $now, $schedule['id'], $date
		) );
		$zone    = $o['zone'] ?? $schedule['zone'];
		$instant = in_array( $zone, DateTimeZone::listIdentifiers( DateTimeZone::ALL ), true ) ? ScheduleTimezone::exact_instant( $date, '18:00:00', $zone ) : null;
		$inserted = $wpdb->insert( Migrations::sessions_table(), [
			'session_date' => $date, 'display_name' => ScheduleExpansion::occurrence_display_name( $schedule['name'], '18:00:00' ),
			'available_for_sale' => empty( $o['force_on'] ) ? 0 : 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
			'schedule_id' => $schedule['id'], 'start_time_local' => '18:00:00', 'timezone_name' => $zone,
			'starts_at_utc' => is_string( $instant ) ? $instant : null, 'generation_completed_at' => ( $o['complete'] ?? true ) ? $now : null,
		], [ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ] );
		$session = (int) $wpdb->insert_id;
		if ( 1 !== $inserted || $session < 1 ) { throw new RuntimeException( 'Occurrence fixture failed: ' . $wpdb->last_error ); }
		foreach ( $packages as $package ) {
			$wpdb->insert( Migrations::session_packages_table(), [ 'session_id' => $session, 'package_id' => $package, 'created_at' => $now ], [ '%d', '%d', '%s' ] );
		}
		if ( empty( $o['force_on'] ) ) {
			$on = ( new SessionService( $this->audit ) )->setAvailableForSale( $session, true );
			if ( ! $on instanceof SessionResult ) { throw new RuntimeException( 'Fixture sales ON failed: ' . ( is_wp_error( $on ) ? $on->get_error_code() : gettype( $on ) ) ); }
		}
		return $session;
	}

	private function legacy( string $date, string $name, bool $on, array $packages = [] ): int {
		$service = new SessionService( $this->audit );
		$created = $service->create( $date, $name, $on );
		if ( ! $created instanceof SessionResult ) { throw new RuntimeException( 'Legacy Session fixture failed.' ); }
		$id = (int) $created->session['session_id'];
		foreach ( $packages as $package ) {
			if ( ! $service->assignPackage( $id, $package ) instanceof SessionResult ) { throw new RuntimeException( 'Legacy assignment fixture failed.' ); }
		}
		return $id;
	}

	/** A calendar date N days from today in a zone, computed at noon so DST cannot shift it. */
	private static function day( string $zone, int $days ): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( $zone ) ) )->setTime( 12, 0 )->modify( ( $days < 0 ? '' : '+' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	private function artifacts(): string {
		$directory = (string) getenv( 'BC_SESSION_CALENDAR_ARTIFACTS' );
		if ( '' === $directory ) {
			$directory = '/private/tmp/bingocommerce-session-calendar-' . substr( $this->token, 0, 8 );
			if ( ! is_dir( $directory ) ) { mkdir( $directory, 0700, true ); }
		}
		$real = realpath( $directory );
		if ( false === $real || ! str_starts_with( $real, '/private/tmp/' ) ) { throw new RuntimeException( 'Artifacts require an existing /private/tmp directory.' ); }
		return $real;
	}

	private function snapshot( int $site ): array {
		global $wpdb;
		$result = [];
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows );
			sort( $encoded );
			$result[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $result );
		return $result;
	}

	private function cleanup(): void {
		global $wpdb;
		$this->group( 'Cleanup' );
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		foreach ( $this->users as $user ) {
			if ( $this->token !== get_user_meta( $user, self::OWNER, true ) ) { throw new RuntimeException( 'Actor cleanup ownership refused.' ); }
			foreach ( $this->sites as $site ) {
				$this->own( $site );
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			wpmu_delete_user( $user );
			$this->same( false, get_userdata( $user ), 'owned disposable actor deleted' );
		}
		foreach ( $this->sites as $site ) {
			$this->own( $site );
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned table discovery failed.' ); }
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
			$this->same( [], $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ), 'owned Hall tables removed' );
		}
	}

	private function group( string $name ): void {
		$this->group = $name;
		echo "== {$name}\n";
	}

	private function same( mixed $expected, mixed $actual, string $label ): void {
		$this->ok( $expected === $actual, $label . ( $expected === $actual ? '' : ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) ) );
	}

	private function ok( bool $condition, string $label ): void {
		if ( $condition ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . '[' . $this->group . '] ' . $label . "\n";
	}
}
/** The owned Hall's home URL without a trailing slash, for redirect comparisons. */
function bc_hsc_home_url( int $site ): string {
	return untrailingslashit( get_home_url( $site, '/' ) ) . '/';
}
( new HomepageSessionCalendarTest() )->run();
