<?php

/**
 * CLI integration coverage for Phase 3D1 Portal HTTP transport.
 *
 * Run from the repository root:
 * php tests/portal-http-client.php
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This integration test is CLI-only.\n" );
	exit( 1 );
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( defined( 'BINGOCOMMERCE_PORTAL_BASE_URL' ) || defined( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' ) ) {
	fwrite( STDERR, "Refusing to run with pre-defined Portal credentials.\n" );
	exit( 1 );
}
define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'https://portal-http-client.example.test/root/' );
define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'phase-3d1-test-token' );

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require dirname( __DIR__ ) . '/wp-load.php';

if ( ! class_exists( '\BingoCommerce\PortalIntegration\PortalHttpClient' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}

use BingoCommerce\PortalIntegration\PortalConfig;
use BingoCommerce\PortalIntegration\PortalHttpClient;

final class PortalHttpClientTest {

	private const PERSISTENT_HALLS = [ 2, 3, 4 ];

	private int $assertions = 0;

	/** @var array<int,\WP_Error> */
	private array $returned_errors = [];

	/** @var array<string,string> */
	private array $domain_before = [];

	/** @var array<string,string> */
	private array $woo_before = [];

	/** @var array<string,mixed> */
	private array $mapping_before = [];

	public function run(): void {
		$this->assert_true( is_multisite(), 'WordPress Multisite is enabled' );
		$this->domain_before  = $this->snapshot_rows( self::PERSISTENT_HALLS, $this->domain_table_suffixes() );
		$this->woo_before     = $this->snapshot_rows( self::PERSISTENT_HALLS, $this->woo_table_suffixes() );
		$this->mapping_before = $this->snapshot_mapping();

		$config = PortalConfig::fromConstants();
		$this->assert_true( $config instanceof PortalConfig, 'validated server constants provide Portal configuration' );
		if ( ! $config instanceof PortalConfig ) {
			throw new \RuntimeException( 'Validated Portal configuration is required for the transport assertions.' );
		}

		$this->successful_request( $config );
		$this->invalid_hall_ids_fail_before_http();
		$this->configuration_failures_precede_http();
		$this->transport_failure_is_safe();
		$this->http_status_classification();
		$this->source_boundaries();
		$this->assert_secret_absent_from_errors();

		$this->assert_same( $this->domain_before, $this->snapshot_rows( self::PERSISTENT_HALLS, $this->domain_table_suffixes() ), 'persistent Catalog and Session rows are byte-identical after the suite' );
		$this->assert_same( $this->woo_before, $this->snapshot_rows( self::PERSISTENT_HALLS, $this->woo_table_suffixes() ), 'persistent WooCommerce product/order rows are byte-identical after the suite' );
		$this->assert_same( $this->mapping_before, $this->snapshot_mapping(), 'Portal Hall mapping schema and rows are byte-identical after the suite' );

		echo "PASS: {$this->assertions} Phase 3D1 Portal HTTP client assertions.\n";
	}

	private function successful_request( PortalConfig $config ): void {
		$body     = "not-json:{raw-body}\n";
		$captured = $this->intercept_http( $this->response( 200, $body ), static fn() => ( new PortalHttpClient() )->fetchHallSnapshot( 42 ) );
		$result   = $captured['result'];
		$args     = $captured['args'];
		$url      = $captured['url'];
		$token    = $config->integrationToken();

		$this->assert_same( 1, $captured['requests'], 'a positive Portal Hall ID issues exactly one request' );
		$this->assert_same( $config->baseUrl() . '/integration/v1/halls/42/snapshot', $url, 'the exact Hall snapshot endpoint URL is constructed from validated configuration' );
		$this->assert_same( 'GET', $args['method'] ?? null, 'the WordPress request method is GET' );
		$this->assert_same( true, $args['blocking'] ?? null, 'the request is blocking' );
		$this->assert_same( 0, $args['redirection'] ?? null, 'automatic redirects are disabled' );
		$authorization          = $args['headers']['Authorization'] ?? null;
		$expected_authorization = hash( 'sha256', 'Bearer ' . $token );
		$actual_authorization   = is_string( $authorization ) ? hash( 'sha256', $authorization ) : '';
		$this->assert_true( hash_equals( $expected_authorization, $actual_authorization ), 'the Authorization header carries the exact bearer token' );
		$this->assert_same( 'application/json', $args['headers']['Accept'] ?? null, 'the Accept header requests JSON' );
		$this->assert_same( [], $args['cookies'] ?? null, 'the client supplies no cookies' );
		$this->assert_same( true, $args['sslverify'] ?? null, 'WordPress SSL certificate verification remains enabled' );
		$this->assert_same( 5.0, (float) ( $args['timeout'] ?? 0 ), 'the installed WordPress five-second timeout default is retained' );
		$this->assert_true( false === strpos( $url, $token ), 'the bearer token is absent from the URL' );
		$this->assert_same( null, wp_parse_url( $url, PHP_URL_QUERY ), 'the request URL contains no query string' );
		$this->assert_same( $body, $result, 'HTTP 200 returns the raw body byte-for-byte' );
		$this->assert_true( ! is_array( $result ) && ! is_object( $result ), 'the raw body is not JSON-decoded or interpreted' );
	}

	private function invalid_hall_ids_fail_before_http(): void {
		foreach ( [ 0, -1 ] as $portal_hall_id ) {
			$captured = $this->intercept_http( $this->response( 200, 'unused' ), static fn() => ( new PortalHttpClient() )->fetchHallSnapshot( $portal_hall_id ) );
			$this->assert_same( 0, $captured['requests'], "Portal Hall ID {$portal_hall_id} is rejected before HTTP" );
			$this->assert_error( 'invalid_portal_hall_id', $captured['result'], "Portal Hall ID {$portal_hall_id} has the stable validation error" );
		}
	}

	private function configuration_failures_precede_http(): void {
		$cases = [
			'missing configuration' => new \WP_Error( 'portal_base_url_missing', 'Portal configuration is incomplete.' ),
			'invalid configuration' => new \WP_Error( 'portal_configuration_invalid', 'Portal configuration is invalid.' ),
		];
		foreach ( $cases as $label => $configuration_error ) {
			$client = new PortalHttpClient( static fn() => $configuration_error );
			$captured = $this->intercept_http( $this->response( 200, 'unused' ), static fn() => $client->fetchHallSnapshot( 42 ) );
			$this->assert_same( 0, $captured['requests'], "{$label} fails before HTTP" );
			$this->assert_error( 'portal_configuration_unavailable', $captured['result'], "{$label} has the stable client error" );
		}
	}

	private function transport_failure_is_safe(): void {
		$upstream = new \WP_Error( 'http_request_failed', 'Upstream transport detail must not escape.' );
		$captured = $this->intercept_http( $upstream, static fn() => ( new PortalHttpClient() )->fetchHallSnapshot( 42 ) );

		$this->assert_same( 1, $captured['requests'], 'transport failure occurs after one request attempt' );
		$this->assert_error( 'portal_request_failed', $captured['result'], 'WordPress transport failure has the stable client error' );
		$this->assert_true( false === strpos( serialize( $captured['result'] ), 'Upstream transport detail' ), 'raw upstream transport text is not exposed' );
	}

	private function http_status_classification(): void {
		$cases = [
			401 => 'portal_authentication_failed',
			403 => 'portal_authentication_failed',
			404 => 'portal_hall_not_accessible',
			500 => 'portal_http_error',
			302 => 'portal_http_error',
		];
		foreach ( $cases as $status => $code ) {
			$captured = $this->intercept_http( $this->response( $status, 'response body must not escape' ), static fn() => ( new PortalHttpClient() )->fetchHallSnapshot( 42 ) );
			$this->assert_same( 1, $captured['requests'], "HTTP {$status} causes exactly one request" );
			$this->assert_error( $code, $captured['result'], "HTTP {$status} has the stable client classification" );
			$this->assert_same( [ 'status' => $status ], $captured['result']->get_error_data(), "HTTP {$status} exposes only the safe status integer" );
			$this->assert_true( false === strpos( serialize( $captured['result'] ), 'response body must not escape' ), "HTTP {$status} response body is not exposed" );
			if ( 302 === $status ) {
				$this->assert_same( 0, $captured['args']['redirection'] ?? null, 'redirect response cannot be followed' );
			}
		}
	}

	private function source_boundaries(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/src/PortalHttpClient.php' );

		$this->assert_true( false === strpos( $source, 'json_decode' ), 'the HTTP client contains no JSON decoding' );
		$this->assert_true( false === strpos( $source, 'HallMappingRepository' ), 'the low-level HTTP client does not resolve Hall mappings' );
		$this->assert_true( false === strpos( $source, '$wpdb' ), 'the HTTP client performs no database write or read' );
		$this->assert_true( false === strpos( $source, 'file_get_contents' ), 'the HTTP client does not use file_get_contents for HTTP' );
		$this->assert_true( false === strpos( $source, 'curl_' ), 'the HTTP client contains no custom cURL transport' );
		$this->assert_true( false === strpos( $source, 'wp_remote_post' ), 'the HTTP client contains no POST request' );
	}

	private function assert_secret_absent_from_errors(): void {
		$token = (string) constant( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' );
		foreach ( $this->returned_errors as $error ) {
			$this->assert_true( false === strpos( serialize( $error ), $token ), 'returned WP_Error message and data contain no bearer token' );
		}
	}

	/**
	 * @param array<string,mixed>|\WP_Error $preempt
	 * @param callable():mixed $request
	 * @return array{result:mixed,requests:int,args:array<string,mixed>,url:string}
	 */
	private function intercept_http( $preempt, callable $request ): array {
		$count = 0;
		$args  = [];
		$url   = '';
		$filter = static function ( $response, array $request_args, string $request_url ) use ( $preempt, &$count, &$args, &$url ) {
			++$count;
			$args = $request_args;
			$url  = $request_url;

			return $preempt;
		};

		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$result = $request();
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
		}

		return [
			'result'   => $result,
			'requests' => $count,
			'args'     => $args,
			'url'      => $url,
		];
	}

	/** @return array<string,mixed> */
	private function response( int $status, string $body ): array {
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [ 'code' => $status, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
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

( new PortalHttpClientTest() )->run();
