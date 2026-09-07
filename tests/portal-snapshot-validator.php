<?php

/**
 * CLI integration coverage for Phase 3D2 strict Portal snapshot validation.
 *
 * Run from the repository root:
 * php tests/portal-snapshot-validator.php
 *
 * The validator is pure: no Portal credential is defined or read here, no live
 * Portal request is made, and no fixture row is created or deleted.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require dirname( __DIR__ ) . '/wp-load.php';

if ( ! class_exists( '\BingoCommerce\PortalIntegration\PortalSnapshotValidator' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}

use BingoCommerce\PortalIntegration\Migrations;
use BingoCommerce\PortalIntegration\PortalSnapshotValidator;

final class PortalSnapshotValidatorTest {

	private const PERSISTENT_HALLS = [ 2, 3, 4 ];
	private const HALL_ID = 42;

	/** Every public error code the validator is allowed to return. */
	private const APPROVED_ERROR_CODES = [
		'invalid_portal_hall_id',
		'portal_snapshot_invalid_json',
		'portal_snapshot_invalid_contract',
		'portal_snapshot_unsupported_version',
		'portal_snapshot_hall_mismatch',
		'portal_snapshot_duplicate_identity',
	];

	private int $assertions = 0;

	/** @var array<int,\WP_Error> */
	private array $returned_errors = [];

	/** @var array<string,string> */
	private array $domain_before = [];

	/** @var array<string,string> */
	private array $woo_before = [];

	/** @var array<string,mixed> */
	private array $mapping_before = [];

	/** @var array<string,string> */
	private array $versions_before = [];

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->assert_true( class_exists( PortalSnapshotValidator::class ), 'the plugin bootstrap loads PortalSnapshotValidator' );
		$this->assert_true( function_exists( 'mb_strlen' ), 'the runtime can count Unicode characters with mbstring' );

		$this->domain_before   = $this->snapshot_rows( $this->domain_table_suffixes() );
		$this->woo_before      = $this->snapshot_rows( $this->woo_table_suffixes() );
		$this->mapping_before  = $this->snapshot_mapping();
		$this->versions_before = $this->snapshot_versions();

		$requests = $this->without_http( function (): void {
			$this->valid_snapshots();
			$this->json_failures();
			$this->contract_shape_failures();
			$this->version_failures();
			$this->timestamp_failures();
			$this->identity_failures();
			$this->duplicate_failures();
			$this->enum_failures();
			$this->name_failures();
			$this->failure_safety();
			$this->callback_free_validation();
		} );
		$this->assert_same( 0, $requests, 'the validator performed zero HTTP requests across every scenario' );

		$this->error_vocabulary();
		$this->source_boundaries();

		$this->assert_same( $this->domain_before, $this->snapshot_rows( $this->domain_table_suffixes() ), 'persistent Catalog, Session, and Portal replica rows are byte-identical after the suite' );
		$this->assert_same( $this->woo_before, $this->snapshot_rows( $this->woo_table_suffixes() ), 'persistent WooCommerce product/order rows are byte-identical after the suite' );
		$this->assert_same( $this->mapping_before, $this->snapshot_mapping(), 'Portal Hall mapping schema and rows are byte-identical after the suite' );
		$this->assert_same( $this->versions_before, $this->snapshot_versions(), 'Catalog, Session, and Portal schema version options are byte-identical after the suite' );

		echo "PASS: {$this->assertions} Phase 3D2 Portal snapshot validation assertions.\n";
	}

	// =================================================================
	// VALID CASES
	// =================================================================

	private function valid_snapshots(): void {
		$result = $this->validate_payload( $this->payload() );
		$this->assert_ok( $result, 'a valid complete snapshot is accepted' );
		$this->assert_same(
			[ 'api_version', 'generated_at', 'hall', 'game_types', 'sessions' ],
			array_keys( $result ),
			'the reconstructed envelope carries exactly the allowlisted keys in contract order'
		);
		$this->assert_same( 'v1', $result['api_version'], 'the api_version is preserved' );
		$this->assert_same( '2026-09-04T12:34:56+00:00', $result['generated_at'], 'generated_at is preserved unchanged' );
		$this->assert_same(
			[ 'id' => 42, 'name' => 'Example Hall', 'state' => 'active', 'updated_at' => '2026-09-04T12:30:00+00:00' ],
			$result['hall'],
			'the reconstructed Hall carries exactly the allowlisted fields'
		);
		$this->assert_same(
			[ [ 'id' => 10, 'hall_id' => 42, 'name' => 'Regular', 'updated_at' => null ] ],
			$result['game_types'],
			'the reconstructed Game Type carries exactly the allowlisted fields'
		);
		$this->assert_same(
			[ [ 'id' => 100, 'hall_id' => 42, 'name' => 'Friday Evening', 'status' => 'draft', 'updated_at' => '2026-09-04T12:31:00+00:00' ] ],
			$result['sessions'],
			'the reconstructed Session carries exactly the allowlisted fields'
		);

		$this->assert_true( is_array( $result ), 'the success value is a PHP array' );
		$this->assert_true( ! $this->contains_object( $result ), 'the success value contains no decoded stdClass anywhere' );
		$this->assert_true( is_int( $result['hall']['id'] ), 'the reconstructed Hall ID stays a native integer' );

		$empty_game_types = $this->payload();
		$empty_game_types['game_types'] = [];
		$result = $this->validate_payload( $empty_game_types );
		$this->assert_ok( $result, 'an empty game_types collection is accepted' );
		$this->assert_same( [], $result['game_types'], 'an empty game_types collection stays empty' );

		$empty_sessions = $this->payload();
		$empty_sessions['sessions'] = [];
		$result = $this->validate_payload( $empty_sessions );
		$this->assert_ok( $result, 'an empty sessions collection is accepted' );
		$this->assert_same( [], $result['sessions'], 'an empty sessions collection stays empty' );

		$empty_both = $this->payload();
		$empty_both['game_types'] = [];
		$empty_both['sessions']   = [];
		$result = $this->validate_payload( $empty_both );
		$this->assert_ok( $result, 'a snapshot with both collections empty is accepted' );
		$this->assert_same( [], $result['game_types'], 'both-empty game_types stays empty' );
		$this->assert_same( [], $result['sessions'], 'both-empty sessions stays empty' );

		$draft = $this->payload();
		$draft['sessions'][0]['status'] = 'draft';
		$result = $this->validate_payload( $draft );
		$this->assert_ok( $result, 'a draft Session is accepted' );
		$this->assert_same( 'draft', $result['sessions'][0]['status'], 'a draft Session status is preserved, never rewritten to active' );

		$active_session = $this->payload();
		$active_session['sessions'][0]['status'] = 'active';
		$result = $this->validate_payload( $active_session );
		$this->assert_ok( $result, 'an active Session is accepted' );
		$this->assert_same( 'active', $result['sessions'][0]['status'], 'an active Session status is preserved' );

		$inactive = $this->payload();
		$inactive['hall']['state'] = 'inactive';
		$result = $this->validate_payload( $inactive );
		$this->assert_ok( $result, 'an inactive Hall snapshot is accepted' );
		$this->assert_same( 'inactive', $result['hall']['state'], 'an inactive Hall state is preserved, never rewritten to active' );

		$null_hall_timestamp = $this->payload();
		$null_hall_timestamp['hall']['updated_at'] = null;
		$result = $this->validate_payload( $null_hall_timestamp );
		$this->assert_ok( $result, 'a null Hall updated_at is accepted' );
		$this->assert_same( null, $result['hall']['updated_at'], 'a null Hall updated_at stays null' );

		$null_game_type_timestamp = $this->payload();
		$null_game_type_timestamp['game_types'][0]['updated_at'] = null;
		$result = $this->validate_payload( $null_game_type_timestamp );
		$this->assert_ok( $result, 'a null Game Type updated_at is accepted' );
		$this->assert_same( null, $result['game_types'][0]['updated_at'], 'a null Game Type updated_at stays null' );

		$null_session_timestamp = $this->payload();
		$null_session_timestamp['sessions'][0]['updated_at'] = null;
		$result = $this->validate_payload( $null_session_timestamp );
		$this->assert_ok( $result, 'a null Session updated_at is accepted' );
		$this->assert_same( null, $result['sessions'][0]['updated_at'], 'a null Session updated_at stays null' );

		foreach ( [ '2026-09-04T12:34:56+00:00', '2026-09-04T08:34:56-04:00', '2024-02-29T23:59:59+13:45', '2026-09-04T12:34:56+23:59', '2026-09-04T12:34:56-23:59' ] as $timestamp ) {
			$valid_timestamps = $this->payload();
			$valid_timestamps['generated_at']                 = $timestamp;
			$valid_timestamps['hall']['updated_at']           = $timestamp;
			$valid_timestamps['game_types'][0]['updated_at']  = $timestamp;
			$valid_timestamps['sessions'][0]['updated_at']    = $timestamp;
			$result = $this->validate_payload( $valid_timestamps );
			$this->assert_ok( $result, "a valid Portal Atom timestamp {$timestamp} is accepted" );
			$this->assert_same( $timestamp, $result['generated_at'], "generated_at {$timestamp} is preserved byte-for-byte" );
			$this->assert_same( $timestamp, $result['sessions'][0]['updated_at'], "Session updated_at {$timestamp} is preserved byte-for-byte" );
		}

		$ordered = $this->payload();
		$ordered['game_types'] = [
			[ 'id' => 30, 'hall_id' => 42, 'name' => 'Third Created', 'updated_at' => null ],
			[ 'id' => 10, 'hall_id' => 42, 'name' => 'First Created', 'updated_at' => null ],
			[ 'id' => 20, 'hall_id' => 42, 'name' => 'Second Created', 'updated_at' => null ],
		];
		$ordered['sessions'] = [
			[ 'id' => 300, 'hall_id' => 42, 'name' => 'Late', 'status' => 'active', 'updated_at' => null ],
			[ 'id' => 100, 'hall_id' => 42, 'name' => 'Early', 'status' => 'draft', 'updated_at' => null ],
			[ 'id' => 200, 'hall_id' => 42, 'name' => 'Middle', 'status' => 'active', 'updated_at' => null ],
		];
		$result = $this->validate_payload( $ordered );
		$this->assert_ok( $result, 'an unsorted input collection is accepted without requiring Portal ordering' );
		$this->assert_same( [ 30, 10, 20 ], array_column( $result['game_types'], 'id' ), 'input Game Type order is preserved exactly, never sorted' );
		$this->assert_same( [ 300, 100, 200 ], array_column( $result['sessions'], 'id' ), 'input Session order is preserved exactly, never sorted' );

		$shared_identity = $this->payload();
		$shared_identity['game_types'][0]['id'] = 10;
		$shared_identity['sessions'][0]['id']   = 10;
		$result = $this->validate_payload( $shared_identity );
		$this->assert_ok( $result, 'the same numeric ID in the Game Type and Session identity domains is accepted' );
		$this->assert_same( 10, $result['game_types'][0]['id'], 'the shared numeric Game Type identity is preserved' );
		$this->assert_same( 10, $result['sessions'][0]['id'], 'the shared numeric Session identity is preserved' );

		$unicode = $this->payload();
		$unicode['hall']['name']            = 'Café Bingo — Español 日本語';
		$unicode['game_types'][0]['name']   = 'Régulier 🎱';
		$unicode['sessions'][0]['name']     = 'Vendredi soir — 金曜日';
		$result = $this->validate_payload( $unicode );
		$this->assert_ok( $result, 'valid Unicode multibyte names are accepted' );
		$this->assert_same( 'Café Bingo — Español 日本語', $result['hall']['name'], 'a multibyte Hall name is preserved unchanged' );
		$this->assert_same( 'Régulier 🎱', $result['game_types'][0]['name'], 'a multibyte Game Type name is preserved unchanged' );
		$this->assert_same( 'Vendredi soir — 金曜日', $result['sessions'][0]['name'], 'a multibyte Session name is preserved unchanged' );

		$untrimmed = $this->payload();
		$untrimmed['hall']['name'] = '  Padded Hall  ';
		$result = $this->validate_payload( $untrimmed );
		$this->assert_ok( $result, 'a name with surrounding whitespace and real content is accepted' );
		$this->assert_same( '  Padded Hall  ', $result['hall']['name'], 'a valid name is never trimmed, sanitized, or escaped' );

		$boundary = $this->payload();
		$boundary['hall']['name']          = str_repeat( 'h', 255 );
		$boundary['game_types'][0]['name'] = str_repeat( 'g', 50 );
		$boundary['sessions'][0]['name']   = str_repeat( 's', 255 );
		$result = $this->validate_payload( $boundary );
		$this->assert_ok( $result, 'boundary-length ASCII names are accepted' );
		$this->assert_same( 255, strlen( $result['hall']['name'] ), 'a 255-character Hall name is preserved at full length' );
		$this->assert_same( 50, strlen( $result['game_types'][0]['name'] ), 'a 50-character Game Type name is preserved at full length' );
		$this->assert_same( 255, strlen( $result['sessions'][0]['name'] ), 'a 255-character Session name is preserved at full length' );

		// Character counting, not byte counting: 50 three-byte characters are
		// 150 bytes and must still be inside the 50-character Game Type limit.
		$multibyte_boundary = $this->payload();
		$multibyte_boundary['game_types'][0]['name'] = str_repeat( '日', 50 );
		$result = $this->validate_payload( $multibyte_boundary );
		$this->assert_ok( $result, 'a 50-character multibyte Game Type name is accepted although it exceeds 50 bytes' );
		$this->assert_same( 150, strlen( $result['game_types'][0]['name'] ), 'the accepted multibyte Game Type name really is 150 bytes long' );
		$this->assert_same( 50, mb_strlen( $result['game_types'][0]['name'], 'UTF-8' ), 'the accepted multibyte Game Type name is exactly 50 Unicode characters' );

		$multibyte_hall = $this->payload();
		$multibyte_hall['hall']['name'] = str_repeat( '日', 255 );
		$result = $this->validate_payload( $multibyte_hall );
		$this->assert_ok( $result, 'a 255-character multibyte Hall name is accepted although it exceeds 255 bytes' );
		$this->assert_same( 765, strlen( $result['hall']['name'] ), 'the accepted multibyte Hall name really is 765 bytes long' );

		$this->member_order_is_irrelevant();
	}

	/**
	 * JSON object member ordering carries no contract meaning.
	 *
	 * The body below is serialized with deliberately shuffled members at all
	 * four levels — envelope, Hall, Game Type, Session — and each collection row
	 * shuffles differently again, so a result in canonical order cannot be the
	 * input order being echoed back.
	 */
	private function member_order_is_irrelevant(): void {
		$shuffled = [
			'sessions'     => [
				[ 'status' => 'draft', 'updated_at' => '2026-09-04T12:31:00+00:00', 'name' => 'Friday Evening', 'id' => 100, 'hall_id' => self::HALL_ID ],
				[ 'hall_id' => self::HALL_ID, 'name' => 'Saturday Matinee', 'id' => 101, 'updated_at' => null, 'status' => 'active' ],
			],
			'hall'         => [ 'updated_at' => '2026-09-04T12:30:00+00:00', 'state' => 'active', 'name' => 'Example Hall', 'id' => self::HALL_ID ],
			'api_version'  => 'v1',
			'game_types'   => [
				[ 'name' => 'Regular', 'updated_at' => null, 'hall_id' => self::HALL_ID, 'id' => 10 ],
				[ 'updated_at' => '2026-09-04T12:32:00+00:00', 'id' => 11, 'name' => 'Special', 'hall_id' => self::HALL_ID ],
			],
			'generated_at' => '2026-09-04T12:34:56+00:00',
		];

		$body = (string) json_encode( $shuffled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );

		// Guards the point of the test: the bytes really are out of order.
		$this->assert_true( 0 === strpos( $body, '{"sessions":' ), 'the shuffled body really is serialized with the envelope out of canonical order' );
		$this->assert_true( false !== strpos( $body, '"updated_at":"2026-09-04T12:30:00+00:00","state":"active","name":"Example Hall","id":42' ), 'the shuffled Hall object really is serialized in reverse canonical order' );
		$this->assert_true( false !== strpos( $body, '"name":"Regular","updated_at":null,"hall_id":42,"id":10' ), 'the shuffled Game Type row really is serialized out of canonical order' );
		$this->assert_true( false !== strpos( $body, '"status":"draft","updated_at":"2026-09-04T12:31:00+00:00","name":"Friday Evening","id":100' ), 'the shuffled Session row really is serialized out of canonical order' );

		$result = $this->validate_raw( $body );
		$this->assert_ok( $result, 'a valid snapshot whose JSON members are reordered at every level is accepted' );

		$this->assert_same( [ 'api_version', 'generated_at', 'hall', 'game_types', 'sessions' ], array_keys( $result ), 'the envelope is reconstructed in canonical allowlisted order regardless of input member order' );
		$this->assert_same( [ 'id', 'name', 'state', 'updated_at' ], array_keys( $result['hall'] ), 'the Hall is reconstructed in canonical allowlisted order regardless of input member order' );
		foreach ( $result['game_types'] as $index => $game_type ) {
			$this->assert_same( [ 'id', 'hall_id', 'name', 'updated_at' ], array_keys( $game_type ), "Game Type row {$index} is reconstructed in canonical allowlisted order" );
		}
		foreach ( $result['sessions'] as $index => $session ) {
			$this->assert_same( [ 'id', 'hall_id', 'name', 'status', 'updated_at' ], array_keys( $session ), "Session row {$index} is reconstructed in canonical allowlisted order" );
		}

		// assert_same() compares arrays with ===, so each of these also proves
		// key order, not just key/value membership. No value is altered, and
		// collection order still follows the input.
		$this->assert_same( 'v1', $result['api_version'], 'the reordered api_version value is unchanged' );
		$this->assert_same( '2026-09-04T12:34:56+00:00', $result['generated_at'], 'the reordered generated_at value is unchanged' );
		$this->assert_same(
			[ 'id' => 42, 'name' => 'Example Hall', 'state' => 'active', 'updated_at' => '2026-09-04T12:30:00+00:00' ],
			$result['hall'],
			'the reordered Hall reconstructs to canonical order with every value unchanged'
		);
		$this->assert_same(
			[
				[ 'id' => 10, 'hall_id' => 42, 'name' => 'Regular', 'updated_at' => null ],
				[ 'id' => 11, 'hall_id' => 42, 'name' => 'Special', 'updated_at' => '2026-09-04T12:32:00+00:00' ],
			],
			$result['game_types'],
			'the reordered Game Types reconstruct to canonical order with every value and row position unchanged'
		);
		$this->assert_same(
			[
				[ 'id' => 100, 'hall_id' => 42, 'name' => 'Friday Evening', 'status' => 'draft', 'updated_at' => '2026-09-04T12:31:00+00:00' ],
				[ 'id' => 101, 'hall_id' => 42, 'name' => 'Saturday Matinee', 'status' => 'active', 'updated_at' => null ],
			],
			$result['sessions'],
			'the reordered Sessions reconstruct to canonical order with every value and row position unchanged'
		);
	}

	// =================================================================
	// JSON FAILURES
	// =================================================================

	private function json_failures(): void {
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( '' ), 'an empty body is rejected' );
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( '   ' ), 'a whitespace-only body is rejected' );
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( '{"api_version":"v1"' ), 'a truncated JSON body is rejected' );
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( '{"a":1,}' ), 'a malformed JSON body is rejected' );
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( "{\"api_version\":\"\xC3\x28\"}" ), 'a body containing invalid UTF-8 is rejected' );
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( '{"api_version":"\\uD800"}' ), 'a body containing an unpaired UTF-16 surrogate escape is rejected' );

		// Production explicitly passes 512 as json_decode()'s depth argument,
		// because the flags argument follows it positionally. 512 is a technical
		// decoder safety bound, not a Portal contract value, and the tests below
		// exercise that configured bound. The reflection check only records that
		// the explicit production value still coincides with PHP's own standard
		// default; it is NOT how production obtains the number.
		$configured_depth = 512;
		$this->assert_same(
			$configured_depth,
			( new ReflectionFunction( 'json_decode' ) )->getParameters()[2]->getDefaultValue(),
			"the explicitly passed production decoder depth still coincides with PHP's standard json_decode() default"
		);

		$too_deep = str_repeat( '[', $configured_depth ) . str_repeat( ']', $configured_depth );
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( $too_deep ), 'a body at the configured JSON decoding depth fails safely as invalid JSON' );

		$far_too_deep = str_repeat( '[', $configured_depth + 200 ) . str_repeat( ']', $configured_depth + 200 );
		$this->assert_error( 'portal_snapshot_invalid_json', $this->validate_raw( $far_too_deep ), 'a body far beyond the configured JSON decoding depth fails safely as invalid JSON' );

		// Proves the configured decoder bound is the reason the cases above
		// failed, and that no narrower BingoCommerce depth limit is applied: a
		// body just inside the bound decodes and is rejected on contract.
		$within_depth = str_repeat( '[', $configured_depth - 1 ) . str_repeat( ']', $configured_depth - 1 );
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_raw( $within_depth ), 'a body just inside the configured depth decodes and is rejected on contract, not on JSON' );

		// The retired 16-level bound must no longer reject anything on its own.
		$formerly_rejected = str_repeat( '[', 20 ) . str_repeat( ']', 20 );
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_raw( $formerly_rejected ), 'a 20-level body is no longer rejected by the retired 16-level depth bound' );

		$roots = [
			'null'    => 'null',
			'string'  => '"snapshot"',
			'integer' => '42',
			'float'   => '4.2',
			'boolean' => 'true',
			'array'   => '[]',
		];
		foreach ( $roots as $label => $body ) {
			$error = $this->validate_raw( $body );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} JSON root is rejected" );
			$this->assert_same( [ 'path' => 'root' ], $error->get_error_data(), "a {$label} JSON root reports the validator-owned root path" );
		}

		$object_game_types = $this->payload();
		$object_game_types['game_types'] = (object) [];
		$error = $this->validate_payload( $object_game_types );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a JSON object where game_types must be an array is rejected' );
		$this->assert_same( [ 'path' => 'game_types' ], $error->get_error_data(), 'the game_types collection failure reports its own path' );

		$object_sessions = $this->payload();
		$object_sessions['sessions'] = (object) [];
		$error = $this->validate_payload( $object_sessions );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a JSON object where sessions must be an array is rejected' );
		$this->assert_same( [ 'path' => 'sessions' ], $error->get_error_data(), 'the sessions collection failure reports its own path' );

		$keyed_collection = '{"api_version":"v1","generated_at":"2026-09-04T12:34:56+00:00",'
			. '"hall":{"id":42,"name":"Example Hall","state":"active","updated_at":null},'
			. '"game_types":{"7":{"id":7,"hall_id":42,"name":"Keyed","updated_at":null}},"sessions":[]}';
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_raw( $keyed_collection ), 'a keyed JSON object masquerading as a game_types collection is rejected' );

		$scalar_row = $this->payload();
		$scalar_row['game_types'] = [ 'not-an-object' ];
		$error = $this->validate_payload( $scalar_row );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a scalar element inside game_types is rejected' );
		$this->assert_same( [ 'path' => 'game_types[0]' ], $error->get_error_data(), 'a scalar collection element reports its indexed path' );

		$nested_row = $this->payload();
		$nested_row['sessions'] = [ [ 'nested', 'array' ] ];
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $nested_row ), 'a nested array element inside sessions is rejected' );
	}

	// =================================================================
	// CONTRACT SHAPE
	// =================================================================

	private function contract_shape_failures(): void {
		foreach ( [ 'api_version', 'generated_at', 'hall', 'game_types', 'sessions' ] as $field ) {
			$payload = $this->payload();
			unset( $payload[ $field ] );
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a snapshot missing the top-level {$field} field is rejected" );
			$this->assert_same( [ 'path' => 'root' ], $error->get_error_data(), "the missing top-level {$field} field reports the root path" );
		}

		$unknown_envelope = $this->payload();
		$unknown_envelope['cursor'] = 'abc';
		$error = $this->validate_payload( $unknown_envelope );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a snapshot carrying an unknown top-level field is rejected' );
		$this->assert_same( [ 'path' => 'root' ], $error->get_error_data(), 'the unknown top-level field reports the root path' );

		foreach ( [ 'id', 'name', 'state', 'updated_at' ] as $field ) {
			$payload = $this->payload();
			unset( $payload['hall'][ $field ] );
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a Hall missing the {$field} field is rejected" );
			$this->assert_same( [ 'path' => 'hall' ], $error->get_error_data(), "the missing Hall {$field} field reports the hall path" );
		}

		$unknown_hall = $this->payload();
		$unknown_hall['hall']['is_active'] = true;
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $unknown_hall ), 'a Hall carrying an unknown field is rejected' );

		foreach ( [ 'id', 'hall_id', 'name', 'updated_at' ] as $field ) {
			$payload = $this->payload();
			unset( $payload['game_types'][0][ $field ] );
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a Game Type missing the {$field} field is rejected" );
			$this->assert_same( [ 'path' => 'game_types[0]' ], $error->get_error_data(), "the missing Game Type {$field} field reports its indexed path" );
		}

		$unknown_game_type = $this->payload();
		$unknown_game_type['game_types'][0]['sort_order'] = 3;
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $unknown_game_type ), 'a Game Type carrying an unknown field is rejected' );

		foreach ( [ 'id', 'hall_id', 'name', 'status', 'updated_at' ] as $field ) {
			$payload = $this->payload();
			unset( $payload['sessions'][0][ $field ] );
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a Session missing the {$field} field is rejected" );
			$this->assert_same( [ 'path' => 'sessions[0]' ], $error->get_error_data(), "the missing Session {$field} field reports its indexed path" );
		}

		$unknown_session = $this->payload();
		$unknown_session['sessions'][0]['notes'] = 'internal';
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $unknown_session ), 'a Session carrying an unknown field is rejected' );

		// Operational Portal fields that must never enter the commerce boundary.
		foreach ( [ 'days', 'games', 'patterns', 'caller', 'device_group_id', 'xml_id', 'deleted_at', 'game_type_ids', 'type', 'paper_type', 'allow_sleeper', 'created_by' ] as $forbidden ) {
			$envelope_case = $this->payload();
			$envelope_case[ $forbidden ] = 'rejected';
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $envelope_case ), "the forbidden envelope field {$forbidden} rejects the snapshot" );

			$hall_case = $this->payload();
			$hall_case['hall'][ $forbidden ] = 'rejected';
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $hall_case ), "the forbidden Hall field {$forbidden} rejects the snapshot" );

			$game_type_case = $this->payload();
			$game_type_case['game_types'][0][ $forbidden ] = 'rejected';
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $game_type_case ), "the forbidden Game Type field {$forbidden} rejects the snapshot" );

			$session_case = $this->payload();
			$session_case['sessions'][0][ $forbidden ] = 'rejected';
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $session_case ), "the forbidden Session field {$forbidden} rejects the snapshot" );
		}

		// A trailing row is validated as strictly as the first one.
		$late_row = $this->payload();
		$late_row['sessions'] = [
			[ 'id' => 100, 'hall_id' => 42, 'name' => 'Good', 'status' => 'active', 'updated_at' => null ],
			[ 'id' => 101, 'hall_id' => 42, 'name' => 'Bad', 'status' => 'active', 'updated_at' => null, 'days' => [ 'Mon' ] ],
		];
		$error = $this->validate_payload( $late_row );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a forbidden field on a later Session row still rejects the whole snapshot' );
		$this->assert_same( [ 'path' => 'sessions[1]' ], $error->get_error_data(), 'the later Session failure reports its own index' );
	}

	// =================================================================
	// VERSION
	// =================================================================

	private function version_failures(): void {
		$missing = $this->payload();
		unset( $missing['api_version'] );
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $missing ), 'a missing api_version is rejected on the envelope member set' );

		foreach ( [ 'null' => null, 'integer' => 1, 'float' => 1.0, 'boolean' => true, 'array' => [ 'v1' ] ] as $label => $value ) {
			$payload = $this->payload();
			$payload['api_version'] = $value;
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_unsupported_version', $error, "a {$label} api_version is rejected" );
			$this->assert_same( [ 'path' => 'api_version' ], $error->get_error_data(), "the {$label} api_version failure reports the api_version path" );
		}

		foreach ( [ 'v2', 'V1', '1', 'v1.0', 'v1 ', ' v1', '' ] as $version ) {
			$payload = $this->payload();
			$payload['api_version'] = $version;
			$this->assert_error( 'portal_snapshot_unsupported_version', $this->validate_payload( $payload ), "the api_version value '{$version}' is rejected as unsupported" );
		}
	}

	// =================================================================
	// TIMESTAMPS
	// =================================================================

	/** @return array<string,string> */
	private function invalid_timestamps(): array {
		return [
			'date only'            => '2026-09-04',
			'space separated'      => '2026-09-04 12:34:56',
			'offset free'          => '2026-09-04T12:34:56',
			'Z suffix'             => '2026-09-04T12:34:56Z',
			'fractional seconds'   => '2026-09-04T12:34:56.123+00:00',
			'compact offset'       => '2026-09-04T12:34:56+0000',
			'offset hour 24'       => '2026-09-04T12:34:56+24:00',
			'offset hour 25'       => '2026-09-04T12:34:56+25:00',
			'negative offset 24'   => '2026-09-04T12:34:56-24:00',
			'offset hour 99'       => '2026-09-04T12:34:56+99:59',
			'offset minute 60'     => '2026-09-04T12:34:56+10:60',
			'offset minute 75'     => '2026-09-04T12:34:56+10:75',
			'single digit offset'  => '2026-09-04T12:34:56+1:00',
			'impossible day'       => '2026-02-30T12:00:00+00:00',
			'impossible month'     => '2026-13-01T12:00:00+00:00',
			'zero day'             => '2026-09-00T12:00:00+00:00',
			'non leap 29 February' => '2025-02-29T00:00:00+00:00',
			'impossible hour'      => '2026-09-04T25:00:00+00:00',
			'impossible minute'    => '2026-09-04T12:60:00+00:00',
			'impossible second'    => '2026-09-04T12:34:61+00:00',
			'leading space'        => ' 2026-09-04T12:34:56+00:00',
			'trailing newline'     => "2026-09-04T12:34:56+00:00\n",
			'trailing text'        => '2026-09-04T12:34:56+00:00Z',
			'two digit year'       => '26-09-04T12:34:56+00:00',
			'unpadded month'       => '2026-9-04T12:34:56+00:00',
			'arbitrary string'     => 'yesterday',
			'empty string'         => '',
		];
	}

	private function timestamp_failures(): void {
		$null_generated_at = $this->payload();
		$null_generated_at['generated_at'] = null;
		$error = $this->validate_payload( $null_generated_at );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a null generated_at is rejected' );
		$this->assert_same( [ 'path' => 'generated_at' ], $error->get_error_data(), 'the null generated_at failure reports the generated_at path' );

		$missing_generated_at = $this->payload();
		unset( $missing_generated_at['generated_at'] );
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $missing_generated_at ), 'a missing generated_at is rejected' );

		foreach ( [ 'integer' => 1757000000, 'float' => 1.5, 'boolean' => true, 'array' => [], 'object' => (object) [] ] as $label => $value ) {
			$payload = $this->payload();
			$payload['generated_at'] = $value;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $payload ), "a {$label} generated_at is rejected" );
		}

		foreach ( $this->invalid_timestamps() as $label => $timestamp ) {
			$payload = $this->payload();
			$payload['generated_at'] = $timestamp;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $payload ), "a {$label} generated_at is rejected" );

			$hall_case = $this->payload();
			$hall_case['hall']['updated_at'] = $timestamp;
			$error = $this->validate_payload( $hall_case );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} Hall updated_at is rejected" );
			$this->assert_same( [ 'path' => 'hall.updated_at' ], $error->get_error_data(), "the {$label} Hall updated_at failure reports its own path" );

			$game_type_case = $this->payload();
			$game_type_case['game_types'][0]['updated_at'] = $timestamp;
			$error = $this->validate_payload( $game_type_case );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} Game Type updated_at is rejected" );
			$this->assert_same( [ 'path' => 'game_types[0].updated_at' ], $error->get_error_data(), "the {$label} Game Type updated_at failure reports its own path" );

			$session_case = $this->payload();
			$session_case['sessions'][0]['updated_at'] = $timestamp;
			$error = $this->validate_payload( $session_case );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} Session updated_at is rejected" );
			$this->assert_same( [ 'path' => 'sessions[0].updated_at' ], $error->get_error_data(), "the {$label} Session updated_at failure reports its own path" );
		}

		foreach ( [ 'integer' => 0, 'float' => 1.5, 'boolean' => false, 'array' => [] ] as $label => $value ) {
			$payload = $this->payload();
			$payload['sessions'][0]['updated_at'] = $value;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $payload ), "a {$label} Session updated_at is rejected" );
		}

		$this->offset_range();
	}

	/**
	 * The UTC offset is range-checked structurally, because PHP's parser accepts
	 * and round-trips impossible offsets such as +25:00 without a warning.
	 *
	 * The bound is the numeric shape of an Atom offset — hour 00-23, minute
	 * 00-59 — and deliberately not a Portal-specific -12:00..+14:00 timezone
	 * range.
	 */
	private function offset_range(): void {
		foreach ( [ '+00:00', '+14:00', '-12:00', '+23:59', '-23:59', '+00:59' ] as $offset ) {
			$payload = $this->payload();
			$payload['generated_at'] = '2026-09-04T12:34:56' . $offset;
			$result = $this->validate_payload( $payload );
			$this->assert_ok( $result, "the structurally valid offset {$offset} is accepted" );
			$this->assert_same( '2026-09-04T12:34:56' . $offset, $result['generated_at'], "the offset {$offset} is preserved without normalization" );
		}

		// '-00:00' is inside the numeric range but is not a form the Portal can
		// emit: PHP's 'P' always renders UTC as '+00:00', so exact round-trip
		// equality rejects it. That is the round-trip rule working, not an
		// offset-range rule.
		$negative_zero = $this->payload();
		$negative_zero['generated_at'] = '2026-09-04T12:34:56-00:00';
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $negative_zero ), 'the negative-zero offset -00:00 is rejected by exact round-trip equality' );

		foreach ( [ '+24:00', '-24:00', '+25:00', '+99:59', '+10:60', '+10:75', '+1:00', '+100:00', '+2:5' ] as $offset ) {
			$timestamp = '2026-09-04T12:34:56' . $offset;

			// Guards the reason this block exists: PHP alone would accept several
			// of these, so the anchored shape is the only thing rejecting them.
			$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:sP', $timestamp );
			$php_would_accept = $parsed instanceof \DateTimeImmutable && $timestamp === $parsed->format( 'Y-m-d\TH:i:sP' );
			if ( in_array( $offset, [ '+24:00', '-24:00', '+25:00', '+99:59' ], true ) ) {
				$this->assert_true( $php_would_accept, "PHP alone would wrongly accept the impossible offset {$offset}" );
			}

			$payload = $this->payload();
			$payload['generated_at'] = $timestamp;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $payload ), "the out-of-range offset {$offset} is rejected in generated_at" );

			$hall_case = $this->payload();
			$hall_case['hall']['updated_at'] = $timestamp;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $hall_case ), "the out-of-range offset {$offset} is rejected in hall.updated_at" );

			$session_case = $this->payload();
			$session_case['sessions'][0]['updated_at'] = $timestamp;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $session_case ), "the out-of-range offset {$offset} is rejected in a Session updated_at" );
		}
	}

	// =================================================================
	// IDENTITIES
	// =================================================================

	private function identity_failures(): void {
		foreach ( [ 0, -1, PHP_INT_MIN ] as $expected ) {
			$error = $this->validate_raw( 'this body must never be parsed', $expected );
			$this->assert_error( 'invalid_portal_hall_id', $error, "an expected Portal Hall ID of {$expected} is rejected before any payload processing" );
			$this->assert_same( null, $error->get_error_data(), "the invalid expected Portal Hall ID {$expected} exposes no error data" );
		}

		foreach ( [ 'zero' => 0, 'negative' => -1 ] as $label => $id ) {
			$payload = $this->payload();
			$payload['hall']['id'] = $id;
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} hall.id is rejected" );
			$this->assert_same( [ 'path' => 'hall.id' ], $error->get_error_data(), "the {$label} hall.id failure reports the hall.id path" );
		}

		foreach ( [ 'numeric string' => '42', 'float' => 42.0, 'fractional float' => 42.5, 'boolean true' => true, 'boolean false' => false, 'null' => null, 'array' => [], 'object' => (object) [] ] as $label => $id ) {
			$payload = $this->payload();
			$payload['hall']['id'] = $id;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $payload ), "a {$label} hall.id is rejected without coercion" );
		}

		// JSON_BIGINT_AS_STRING keeps an oversized integer out of the int range
		// instead of rounding it into a plausible Portal identity.
		$oversized = '{"api_version":"v1","generated_at":"2026-09-04T12:34:56+00:00",'
			. '"hall":{"id":123456789012345678901234567890,"name":"Example Hall","state":"active","updated_at":null},'
			. '"game_types":[],"sessions":[]}';
		$error = $this->validate_raw( $oversized );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'an oversized JSON integer hall.id fails safely instead of being rounded' );
		$this->assert_same( [ 'path' => 'hall.id' ], $error->get_error_data(), 'the oversized hall.id failure reports the hall.id path' );

		$oversized_child = '{"api_version":"v1","generated_at":"2026-09-04T12:34:56+00:00",'
			. '"hall":{"id":42,"name":"Example Hall","state":"active","updated_at":null},'
			. '"game_types":[{"id":123456789012345678901234567890,"hall_id":42,"name":"Regular","updated_at":null}],"sessions":[]}';
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_raw( $oversized_child ), 'an oversized JSON integer Game Type ID fails safely' );

		foreach ( [ 'zero' => 0, 'negative' => -1, 'numeric string' => '10', 'float' => 10.0, 'boolean' => true, 'null' => null ] as $label => $id ) {
			$game_type_case = $this->payload();
			$game_type_case['game_types'][0]['id'] = $id;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $game_type_case ), "a {$label} Game Type ID is rejected" );

			$session_case = $this->payload();
			$session_case['sessions'][0]['id'] = $id;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $session_case ), "a {$label} Session ID is rejected" );

			$game_type_hall = $this->payload();
			$game_type_hall['game_types'][0]['hall_id'] = $id;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $game_type_hall ), "a {$label} Game Type hall_id is rejected" );

			$session_hall = $this->payload();
			$session_hall['sessions'][0]['hall_id'] = $id;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $session_hall ), "a {$label} Session hall_id is rejected" );
		}

		$hall_mismatch = $this->payload();
		$error = $this->validate_payload( $hall_mismatch, 43 );
		$this->assert_error( 'portal_snapshot_hall_mismatch', $error, 'a snapshot describing a different Portal Hall is rejected' );
		$this->assert_same( [ 'path' => 'hall.id' ], $error->get_error_data(), 'the top-level Hall mismatch reports the hall.id path' );

		$game_type_mismatch = $this->payload();
		$game_type_mismatch['game_types'][0]['hall_id'] = 43;
		$error = $this->validate_payload( $game_type_mismatch );
		$this->assert_error( 'portal_snapshot_hall_mismatch', $error, 'a Game Type owned by a different Hall rejects the complete snapshot' );
		$this->assert_same( [ 'path' => 'game_types[0].hall_id' ], $error->get_error_data(), 'the Game Type tenant mismatch reports its indexed path' );

		$session_mismatch = $this->payload();
		$session_mismatch['sessions'] = [
			[ 'id' => 100, 'hall_id' => 42, 'name' => 'Owned', 'status' => 'active', 'updated_at' => null ],
			[ 'id' => 101, 'hall_id' => 42, 'name' => 'Owned Too', 'status' => 'active', 'updated_at' => null ],
			[ 'id' => 102, 'hall_id' => 99, 'name' => 'Foreign', 'status' => 'active', 'updated_at' => null ],
		];
		$error = $this->validate_payload( $session_mismatch );
		$this->assert_error( 'portal_snapshot_hall_mismatch', $error, 'a Session owned by a different Hall rejects the complete snapshot' );
		$this->assert_same( [ 'path' => 'sessions[2].hall_id' ], $error->get_error_data(), 'the Session tenant mismatch reports its exact indexed path' );
		$this->assert_true( ! is_array( $error->get_error_data() ) || ! isset( $error->get_error_data()['rows'] ), 'a tenant mismatch returns no partial valid rows' );
	}

	// =================================================================
	// DUPLICATES
	// =================================================================

	private function duplicate_failures(): void {
		$duplicate_game_types = $this->payload();
		$duplicate_game_types['game_types'] = [
			[ 'id' => 10, 'hall_id' => 42, 'name' => 'Regular', 'updated_at' => null ],
			[ 'id' => 11, 'hall_id' => 42, 'name' => 'Special', 'updated_at' => null ],
			[ 'id' => 10, 'hall_id' => 42, 'name' => 'Regular Again', 'updated_at' => null ],
		];
		$error = $this->validate_payload( $duplicate_game_types );
		$this->assert_error( 'portal_snapshot_duplicate_identity', $error, 'a duplicate Game Type ID rejects the complete snapshot' );
		$this->assert_same( [ 'path' => 'game_types[2].id' ], $error->get_error_data(), 'the duplicate Game Type identity reports the repeating index' );

		$duplicate_sessions = $this->payload();
		$duplicate_sessions['sessions'] = [
			[ 'id' => 100, 'hall_id' => 42, 'name' => 'Friday', 'status' => 'active', 'updated_at' => null ],
			[ 'id' => 100, 'hall_id' => 42, 'name' => 'Friday Copy', 'status' => 'draft', 'updated_at' => null ],
		];
		$error = $this->validate_payload( $duplicate_sessions );
		$this->assert_error( 'portal_snapshot_duplicate_identity', $error, 'a duplicate Session ID rejects the complete snapshot' );
		$this->assert_same( [ 'path' => 'sessions[1].id' ], $error->get_error_data(), 'the duplicate Session identity reports the repeating index' );

		$duplicate_names = $this->payload();
		$duplicate_names['game_types'] = [
			[ 'id' => 10, 'hall_id' => 42, 'name' => 'Regular', 'updated_at' => null ],
			[ 'id' => 11, 'hall_id' => 42, 'name' => 'Regular', 'updated_at' => null ],
		];
		$duplicate_names['sessions'] = [
			[ 'id' => 100, 'hall_id' => 42, 'name' => 'Evening', 'status' => 'active', 'updated_at' => null ],
			[ 'id' => 101, 'hall_id' => 42, 'name' => 'Evening', 'status' => 'draft', 'updated_at' => null ],
		];
		$result = $this->validate_payload( $duplicate_names );
		$this->assert_ok( $result, 'duplicate names with distinct IDs remain valid, because names are never uniqueness keys' );
		$this->assert_same( [ 10, 11 ], array_column( $result['game_types'], 'id' ), 'both same-named Game Types are retained' );
		$this->assert_same( [ 100, 101 ], array_column( $result['sessions'], 'id' ), 'both same-named Sessions are retained' );
	}

	// =================================================================
	// ENUMS
	// =================================================================

	private function enum_failures(): void {
		foreach ( [ 'Active', 'INACTIVE', 'Inactive', 'archived', 'disabled', 'enabled', '', ' active' ] as $state ) {
			$payload = $this->payload();
			$payload['hall']['state'] = $state;
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "the Hall state '{$state}' is rejected without being mapped to a known state" );
			$this->assert_same( [ 'path' => 'hall.state' ], $error->get_error_data(), "the rejected Hall state '{$state}' reports the hall.state path" );
		}

		foreach ( [ 'null' => null, 'integer' => 1, 'boolean' => true, 'array' => [ 'active' ] ] as $label => $state ) {
			$payload = $this->payload();
			$payload['hall']['state'] = $state;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $payload ), "a {$label} Hall state is rejected" );
		}

		foreach ( [ 'Active', 'Draft', 'DRAFT', 'archived', 'cancelled', 'inactive', '', 'draft ' ] as $status ) {
			$payload = $this->payload();
			$payload['sessions'][0]['status'] = $status;
			$error = $this->validate_payload( $payload );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "the Session status '{$status}' is rejected without being mapped to a known status" );
			$this->assert_same( [ 'path' => 'sessions[0].status' ], $error->get_error_data(), "the rejected Session status '{$status}' reports its own path" );
		}

		foreach ( [ 'null' => null, 'integer' => 0, 'boolean' => false, 'array' => [ 'draft' ] ] as $label => $status ) {
			$payload = $this->payload();
			$payload['sessions'][0]['status'] = $status;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $payload ), "a {$label} Session status is rejected" );
		}
	}

	// =================================================================
	// NAMES
	// =================================================================

	private function name_failures(): void {
		$blank_names = [ 'empty' => '', 'single space' => ' ', 'spaces' => '     ', 'tab' => "\t", 'newline' => "\n", 'mixed whitespace' => " \t\r\n " ];

		foreach ( $blank_names as $label => $name ) {
			$hall_case = $this->payload();
			$hall_case['hall']['name'] = $name;
			$error = $this->validate_payload( $hall_case );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} Hall name is rejected" );
			$this->assert_same( [ 'path' => 'hall.name' ], $error->get_error_data(), "the {$label} Hall name failure reports the hall.name path" );

			$game_type_case = $this->payload();
			$game_type_case['game_types'][0]['name'] = $name;
			$error = $this->validate_payload( $game_type_case );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} Game Type name is rejected" );
			$this->assert_same( [ 'path' => 'game_types[0].name' ], $error->get_error_data(), "the {$label} Game Type name failure reports its own path" );

			$session_case = $this->payload();
			$session_case['sessions'][0]['name'] = $name;
			$error = $this->validate_payload( $session_case );
			$this->assert_error( 'portal_snapshot_invalid_contract', $error, "a {$label} Session name is rejected" );
			$this->assert_same( [ 'path' => 'sessions[0].name' ], $error->get_error_data(), "the {$label} Session name failure reports its own path" );
		}

		foreach ( [ 'null' => null, 'integer' => 42, 'float' => 4.2, 'boolean' => true, 'array' => [ 'Name' ], 'object' => (object) [] ] as $label => $name ) {
			$hall_case = $this->payload();
			$hall_case['hall']['name'] = $name;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $hall_case ), "a {$label} Hall name is rejected" );

			$game_type_case = $this->payload();
			$game_type_case['game_types'][0]['name'] = $name;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $game_type_case ), "a {$label} Game Type name is rejected" );

			$session_case = $this->payload();
			$session_case['sessions'][0]['name'] = $name;
			$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $session_case ), "a {$label} Session name is rejected" );
		}

		$long_hall = $this->payload();
		$long_hall['hall']['name'] = str_repeat( 'h', 256 );
		$error = $this->validate_payload( $long_hall );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a 256-character Hall name exceeds the 255-character limit and is rejected' );
		$this->assert_same( [ 'path' => 'hall.name' ], $error->get_error_data(), 'the over-length Hall name reports the hall.name path' );

		$long_game_type = $this->payload();
		$long_game_type['game_types'][0]['name'] = str_repeat( 'g', 51 );
		$error = $this->validate_payload( $long_game_type );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a 51-character Game Type name exceeds the 50-character limit and is rejected' );
		$this->assert_same( [ 'path' => 'game_types[0].name' ], $error->get_error_data(), 'the over-length Game Type name reports its own path' );

		$long_session = $this->payload();
		$long_session['sessions'][0]['name'] = str_repeat( 's', 256 );
		$error = $this->validate_payload( $long_session );
		$this->assert_error( 'portal_snapshot_invalid_contract', $error, 'a 256-character Session name exceeds the 255-character limit and is rejected' );
		$this->assert_same( [ 'path' => 'sessions[0].name' ], $error->get_error_data(), 'the over-length Session name reports its own path' );

		// The decisive character-vs-byte proof: 51 three-byte characters are
		// rejected on character count, while the 50-character/150-byte name
		// accepted above proves byte length was never the limit.
		$multibyte_over = $this->payload();
		$multibyte_over['game_types'][0]['name'] = str_repeat( '日', 51 );
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $multibyte_over ), 'a 51-character multibyte Game Type name is rejected on Unicode character count' );

		$multibyte_hall_over = $this->payload();
		$multibyte_hall_over['hall']['name'] = str_repeat( '日', 256 );
		$this->assert_error( 'portal_snapshot_invalid_contract', $this->validate_payload( $multibyte_hall_over ), 'a 256-character multibyte Hall name is rejected on Unicode character count' );

		// A 200-character/600-byte Hall name is inside the character limit even
		// though a byte-based check would have rejected it.
		$multibyte_within = $this->payload();
		$multibyte_within['hall']['name'] = str_repeat( '日', 200 );
		$result = $this->validate_payload( $multibyte_within );
		$this->assert_ok( $result, 'a 200-character multibyte Hall name is accepted although it is 600 bytes' );
		$this->assert_same( 600, strlen( $result['hall']['name'] ), 'the accepted 200-character Hall name really is 600 bytes long' );
	}

	// =================================================================
	// FAILURE SAFETY
	// =================================================================

	private function failure_safety(): void {
		$raw_sentinel  = 'RAWBODYSENTINELMUSTNOTLEAK';
		$name_sentinel = 'PORTALNAMESENTINELMUSTNOTLEAK';

		$errors = [];

		$errors[] = $this->validate_raw( '{"api_version":"v1","leak":"' . $raw_sentinel . '"' );
		$errors[] = $this->validate_raw( '"' . $raw_sentinel . '"' );
		$errors[] = $this->validate_raw( "{\"leak\":\"{$raw_sentinel}\xC3\x28\"}" );

		$bad_state = $this->payload();
		$bad_state['hall']['name']  = $name_sentinel;
		$bad_state['hall']['state'] = 'archived';
		$errors[] = $this->validate_payload( $bad_state );

		$mismatch = $this->payload();
		$mismatch['hall']['name']          = $name_sentinel;
		$mismatch['game_types'][0]['name'] = $name_sentinel;
		$mismatch['sessions'][0]['name']   = $name_sentinel;
		$errors[] = $this->validate_payload( $mismatch, 7 );

		$over_length = $this->payload();
		$over_length['game_types'][0]['name'] = str_repeat( $name_sentinel, 3 );
		$errors[] = $this->validate_payload( $over_length );

		$unknown_field = $this->payload();
		$unknown_field['hall']['secret'] = $name_sentinel;
		$errors[] = $this->validate_payload( $unknown_field );

		foreach ( $errors as $index => $error ) {
			$this->assert_true( is_wp_error( $error ), "failure-safety scenario {$index} returned WP_Error rather than throwing" );
			if ( $error instanceof \WP_Error ) {
				$this->returned_errors[] = $error;
			}
		}

		$serialized = serialize( $errors );
		$raw_safe   = false === strpos( $serialized, $raw_sentinel );
		$name_safe  = false === strpos( $serialized, $name_sentinel );
		$this->assert_true( $raw_safe, 'no returned WP_Error exposes raw response body content' );
		$this->assert_true( $name_safe, 'no returned WP_Error exposes an untrusted Portal name' );

		// Malformed JSON must return, never throw, so a Portal response can
		// never take down the calling request.
		$threw = false;
		try {
			$this->validate_raw( '{"broken":' );
		} catch ( \Throwable $exception ) {
			$threw = true;
		}
		$this->assert_true( ! $threw, 'no exception escapes validate() for malformed JSON' );

		$threw_on_binary = false;
		try {
			$this->validate_raw( "\x00\x01\x02\xff\xfe" );
		} catch ( \Throwable $exception ) {
			$threw_on_binary = true;
		}
		$this->assert_true( ! $threw_on_binary, 'no exception escapes validate() for a binary body' );
	}

	/**
	 * Validation must invoke no WordPress error or translation callback.
	 *
	 * is_wp_error() fires the is_wp_error_instance action and __() fires the
	 * gettext filters, so a validator using either would run arbitrary
	 * third-party callbacks while handling untrusted Portal data. Nothing
	 * inside the observed block may call is_wp_error(), assert_error(), or a
	 * translation function, or the observers would count this harness's own
	 * activity rather than the validator's.
	 */
	private function callback_free_validation(): void {
		$counts = [
			'is_wp_error_instance' => 0,
			'gettext'              => 0,
			'gettext_domain'       => 0,
		];

		$wp_error_observer = static function ( $thing = null ) use ( &$counts ) {
			++$counts['is_wp_error_instance'];
		};
		$gettext_observer = static function ( $translation, $text = '', $domain = '' ) use ( &$counts ) {
			++$counts['gettext'];

			return $translation;
		};
		$domain_observer = static function ( $translation, $text = '', $domain = '' ) use ( &$counts ) {
			++$counts['gettext_domain'];

			return $translation;
		};

		// A nested child Hall mismatch: the deepest error path the validator
		// builds, so construction and propagation are both observed.
		$payload = $this->payload();
		$payload['sessions'][0]['hall_id'] = 99;

		add_action( 'is_wp_error_instance', $wp_error_observer, 10, 1 );
		add_filter( 'gettext', $gettext_observer, 10, 3 );
		add_filter( 'gettext_bingocommerce-portal-integration', $domain_observer, 10, 3 );
		try {
			$result = $this->validate_payload( $payload );
		} finally {
			remove_action( 'is_wp_error_instance', $wp_error_observer, 10 );
			remove_filter( 'gettext', $gettext_observer, 10 );
			remove_filter( 'gettext_bingocommerce-portal-integration', $domain_observer, 10 );
		}

		// Only now, with the observers gone, may the harness use its ordinary
		// is_wp_error()-based helpers again.
		$this->assert_true( $result instanceof \WP_Error, 'the observed nested Hall-mismatch scenario returns WP_Error' );
		$this->assert_same( 0, $counts['is_wp_error_instance'], 'validation fired no is_wp_error_instance callback' );
		$this->assert_same( 0, $counts['gettext'], 'validation fired no general gettext callback' );
		$this->assert_same( 0, $counts['gettext_domain'], 'validation fired no bingocommerce-portal-integration gettext callback' );

		$this->assert_error( 'portal_snapshot_hall_mismatch', $result, 'the callback-free scenario still reports the Hall mismatch' );
		$this->assert_same( [ 'path' => 'sessions[0].hall_id' ], $result->get_error_data(), 'the callback-free scenario still reports the exact child Hall path' );
	}

	private function error_vocabulary(): void {
		$this->assert_true( count( $this->returned_errors ) > 0, 'the suite collected returned validator errors to inspect' );

		$codes = [];
		foreach ( $this->returned_errors as $error ) {
			$code = $error->get_error_code();
			$codes[ $code ] = true;
			$this->assert_true( in_array( $code, self::APPROVED_ERROR_CODES, true ), "returned error code '{$code}' is inside the approved vocabulary" );

			$data = $error->get_error_data();
			$safe = null === $data
				|| ( is_array( $data )
					&& [ 'path' ] === array_keys( $data )
					&& is_string( $data['path'] )
					&& 1 === preg_match( '/\A(root|[a-z_]+(\[\d+\])?(\.[a-z_]+)?)\z/', $data['path'] ) );
			$this->assert_true( $safe, 'the returned error exposes only a validator-owned structural path' );
		}

		$this->assert_true( count( $codes ) <= count( self::APPROVED_ERROR_CODES ), 'the validator public error surface stays small' );
		foreach ( self::APPROVED_ERROR_CODES as $code ) {
			$this->assert_true( isset( $codes[ $code ] ), "the approved error code '{$code}' is exercised by this suite" );
		}
	}

	// =================================================================
	// SOURCE BOUNDARY
	// =================================================================

	private function source_boundaries(): void {
		$raw = (string) file_get_contents(
			dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/src/PortalSnapshotValidator.php'
		);
		$this->assert_true( '' !== $raw, 'the validator production source was read for the boundary check' );

		// Comments are stripped first, so this proves the absence of real write
		// and transport dependencies rather than the absence of prose.
		$source = $this->code_without_comments( $raw );
		$this->assert_true( '' !== $source, 'the validator source was tokenized for the boundary check' );
		$this->assert_true( strlen( $source ) < strlen( $raw ), 'the boundary check inspects code with comments removed' );

		$forbidden = [
			// Writes, transport, and site switching.
			'wp_remote_', '$wpdb', 'switch_to_blog', 'restore_current_blog',
			'update_option', 'add_option', 'delete_option', 'update_site_option',
			'update_post_meta', 'add_post_meta', 'delete_post_meta',
			'wp_insert_post', 'wp_update_post',
			'HallMappingRepository', 'GameTypeService', 'SessionService',
			'curl_', 'file_get_contents', 'dbDelta',
			'JSON_INVALID_UTF8_IGNORE', 'JSON_INVALID_UTF8_SUBSTITUTE',
			'absint(', 'intval(', 'sanitize_text_field', 'esc_html', 'wp_kses',
			// Portal transport configuration and credentials. The validator
			// neither reads nor depends on them; whether a site happens to
			// define them is not this class's concern.
			'PortalConfig', 'PortalHttpClient', 'Authorization',
			'BINGOCOMMERCE_PORTAL_BASE_URL', 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN',
			// Callback-firing WordPress helpers. is_wp_error() fires the
			// is_wp_error_instance action and every localization wrapper fires
			// the gettext filters.
			'is_wp_error(', '__(', '_x(', 'translate(', '_e(',
			'esc_html__(', 'esc_attr__(',
		];
		foreach ( $forbidden as $needle ) {
			$this->assert_true( false === strpos( $source, $needle ), "the validator source contains no {$needle}" );
		}

		$this->assert_true( false !== strpos( $source, 'JSON_THROW_ON_ERROR' ), 'the validator decodes with JSON_THROW_ON_ERROR' );
		$this->assert_true( false !== strpos( $source, 'JSON_BIGINT_AS_STRING' ), 'the validator decodes with JSON_BIGINT_AS_STRING' );
	}

	// =================================================================
	// HARNESS PLUMBING
	// =================================================================

	/**
	 * Counts every HTTP request attempted while the scenarios run.
	 *
	 * The seam is installed after WordPress has finished bootstrapping and is
	 * removed in finally, so it never becomes responsible for bootstrap
	 * behaviour.
	 *
	 * @param callable():void $scenarios
	 */
	private function without_http( callable $scenarios ): int {
		$count = 0;
		$filter = static function ( $response, array $args, string $url ) use ( &$count ) {
			++$count;

			return new \WP_Error( 'test_http_blocked', 'HTTP is blocked during validator scenarios.' );
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$scenarios();
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}

		return $count;
	}

	/** @return array<string,mixed> */
	private function payload(): array {
		return [
			'api_version'  => 'v1',
			'generated_at' => '2026-09-04T12:34:56+00:00',
			'hall'         => [
				'id'         => self::HALL_ID,
				'name'       => 'Example Hall',
				'state'      => 'active',
				'updated_at' => '2026-09-04T12:30:00+00:00',
			],
			'game_types'   => [
				[
					'id'         => 10,
					'hall_id'    => self::HALL_ID,
					'name'       => 'Regular',
					'updated_at' => null,
				],
			],
			'sessions'     => [
				[
					'id'         => 100,
					'hall_id'    => self::HALL_ID,
					'name'       => 'Friday Evening',
					'status'     => 'draft',
					'updated_at' => '2026-09-04T12:31:00+00:00',
				],
			],
		];
	}

	/** @param array<string,mixed> $payload
	 *  @return array<string,mixed>|\WP_Error
	 */
	private function validate_payload( array $payload, int $expected_portal_hall_id = self::HALL_ID ) {
		return $this->validate_raw(
			// JSON_PRESERVE_ZERO_FRACTION keeps a whole float such as 42.0 a JSON
			// float, so the float-identity scenarios really reach the validator.
			(string) json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ),
			$expected_portal_hall_id
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function validate_raw( string $raw_body, int $expected_portal_hall_id = self::HALL_ID ) {
		return ( new PortalSnapshotValidator() )->validate( $raw_body, $expected_portal_hall_id );
	}

	/** Production source with every comment and docblock removed. */
	private function code_without_comments( string $source ): string {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}
				$code .= $token[1];
				continue;
			}
			$code .= $token;
		}

		return $code;
	}

	/** @param mixed $value */
	private function contains_object( $value ): bool {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_object( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param array<int,string> $suffixes
	 *  @return array<string,string>
	 */
	private function snapshot_rows( array $suffixes ): array {
		global $wpdb;

		$snapshot = [];
		foreach ( self::PERSISTENT_HALLS as $site_id ) {
			$prefix = $wpdb->get_blog_prefix( (int) $site_id );
			foreach ( $suffixes as $suffix ) {
				$table = $prefix . $suffix;
				if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
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

	/** @return array<int,string> */
	private function domain_table_suffixes(): array {
		return [
			'bingo_game_types',
			'bingo_play_products',
			'bingo_packages',
			'bingo_package_components',
			'bingo_package_addons',
			'bingo_sessions',
			'bingo_session_packages',
			'bingo_portal_session_templates',
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
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return [];
		}

		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

		return [
			'ddl'  => is_array( $row ) ? (string) ( $row[1] ?? '' ) : '',
			'rows' => (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY portal_hall_id", ARRAY_A ),
		];
	}

	/** @return array<string,string> */
	private function snapshot_versions(): array {
		global $wpdb;

		$snapshot = [
			'network:' . Migrations::NETWORK_VERSION_OPTION => (string) get_site_option( Migrations::NETWORK_VERSION_OPTION, '' ),
		];
		foreach ( self::PERSISTENT_HALLS as $site_id ) {
			$table = $wpdb->get_blog_prefix( (int) $site_id ) . 'options';
			foreach ( [ 'bc_catalog_db_version', 'bc_sessions_db_version' ] as $option ) {
				$snapshot[ $site_id . ':' . $option ] = (string) $wpdb->get_var(
					$wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s", $option )
				);
			}
		}
		ksort( $snapshot );

		return $snapshot;
	}

	/** @param mixed $actual */
	private function assert_ok( $actual, string $message ): void {
		$this->assert_true( is_array( $actual ) && ! is_wp_error( $actual ), $message );
	}

	/** @param mixed $actual */
	private function assert_error( string $code, $actual, string $message ): void {
		$this->assert_true( is_wp_error( $actual ) && $code === $actual->get_error_code(), $message );
		if ( $actual instanceof \WP_Error ) {
			$this->returned_errors[] = $actual;
		}
	}

	/** @param mixed $expected
	 *  @param mixed $actual
	 */
	private function assert_same( $expected, $actual, string $message ): void {
		$this->assert_true( $expected === $actual, $message );
	}

	private function assert_true( bool $condition, string $message ): void {
		++$this->assertions;
		if ( ! $condition ) {
			throw new \RuntimeException( "Assertion {$this->assertions} failed: {$message}" );
		}

		echo "PASS: {$message}\n";
	}
}

( new PortalSnapshotValidatorTest() )->run();
