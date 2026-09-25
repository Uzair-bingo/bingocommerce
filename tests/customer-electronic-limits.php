<?php

/**
 * BR-038/BR-042/BR-082, real Woo cart and final checkout validation, signed out.
 * BC_TEST_DB=bingocommerce_state_recon php tests/customer-electronic-limits.php
 * Disposable token-owned Halls only; restore the fixture network option on exit.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\Migrations as CatalogMigrations;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Sessions\CartSelection;
use BingoCommerce\Sessions\CustomerCart;
use BingoCommerce\Sessions\Migrations;
use BingoCommerce\Sessions\SessionScheduleService;
use BingoCommerce\Sessions\SessionService;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

final class CustomerElectronicLimitsTest {
	private array $sites = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		$missing = new stdClass();
		$original = get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		try {
			$a = $this->fixture( 'a' ); $b = $this->fixture( 'b' );
			switch_to_blog( $a['site'] ); wp_set_current_user( 0 );
			WC()->initialize_session(); WC()->initialize_cart();
			$this->same( 0, get_current_user_id(), 'customer is signed out' );
			$this->same( false, current_user_can( 'bc_manage_products' ), 'guest has no management capability' );
			$this->ok( is_wp_error( ( new PackageService() )->getById( $a['digital'] ) ), 'management read remains denied' );
			$this->contributions( $a );
			$this->settings( $a );
			$this->stale_and_unreadable( $a );
			$this->isolation( $a, $b );
			$this->same( 0, get_current_user_id(), 'all customer cases remain signed out' );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			if ( $original === $missing ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
			else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $original ); }
			$this->same( $original, get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing ), 'fixture network setting restored' );
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Electronic limits: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	private function contributions( array $f ): void {
		$this->configure( 48 );
		$this->same( [], ElectronicLimit::validate_selection( $f['paper'], [] ), 'Paper contributes zero despite its large face count' );
		$this->same( [ $f['game_a'] => 36 ], ElectronicLimit::validate_selection( $f['digital'], [] ), 'base faces multiply units by component quantity' );
		$addons = [ $f['same_base_addon'] => 1, $f['second_addon'] => 2, $f['paper_addon'] => 99, $f['other_game_addon'] => 6 ];
		$this->same( [ $f['game_a'] => 48, $f['game_b'] => 48 ], ElectronicLimit::validate_selection( $f['digital'], $addons ), 'base plus multiple add-ons aggregate independently by Game Type; Paper excluded' );
		$this->clear();
		$this->ok( is_string( CustomerCart::add( $this->selection( $f, 'digital', $addons ) ) ), 'exactly-at-limit digital selection accepted' );
		$this->checkout_paths( true, 'exact digital limit with add-ons' );
		$this->clear();
		$this->ok( is_string( CustomerCart::add( $this->selection( $f, 'multi' ) ) ), 'Hybrid base totals of 48 each accepted although combined total is 96' );
		$this->checkout_paths( true, 'multiple Game Types and Paper base' );
		$this->clear();
		$this->configure( 47 );
		$this->error( 'bc_electronic_limit_exceeded', CustomerCart::add( $this->selection( $f, 'multi' ) ), 'over-limit base rejected' );
		$this->configure( 48 );
		$over = $this->selection( $f, 'digital', [ $f['same_base_addon'] => 3 ] );
		$this->error( 'bc_electronic_limit_exceeded', CustomerCart::add( $over ), 'same Play Product in base and add-on counted separately: 36 + 18 exceeds 48' );
		$response = $this->request( '/cart/extensions', [ 'namespace' => 'bingocommerce-selection', 'data' => $over ] );
		$this->same( 400, $response->get_status(), 'real Store API add gives immediate over-limit feedback' );
		$this->same( [], WC()->cart->get_cart(), 'rejected selection adds no partial lines' );
		$this->configure( 48, 35 );
		$this->same( 35, ElectronicLimit::effective_limit(), 'lower Hall override applied' );
		$this->error( 'bc_electronic_limit_exceeded', CustomerCart::add( $this->selection( $f, 'digital' ) ), 'Hall override blocks otherwise-valid base' );
		$this->configure( 48, 99 );
		$this->same( 48, ElectronicLimit::effective_limit(), 'saved override cannot exceed current platform maximum' );
		$this->ok( is_string( CustomerCart::add( $this->selection( $f, 'digital', [ $f['same_base_addon'] => 2 ] ) ) ), 'higher saved override remains bounded, exact limit allowed' );
		$this->clear();
		$this->configure( 48 );
		CustomerCart::add( $this->selection( $f, 'digital', [ $f['same_base_addon'] => 1 ] ) );
		$keys = array_keys( WC()->cart->get_cart() ); $child = end( $keys );
		WC()->cart->set_quantity( $child, 3 );
		$this->same( 1, WC()->cart->cart_contents[ $child ]['quantity'], 'quantity change cannot exceed electronic limit even within relationship maximum' );
	}

	private function settings( array $f ): void {
		$this->configure( 1 );
		$this->same( 1, ElectronicLimit::effective_limit(), 'platform minimum 1 is valid with an absent Hall override' );
		$this->configure( 48, 1 );
		$this->same( 1, ElectronicLimit::effective_limit(), 'configured Hall minimum 1 is valid' );
		foreach ( [ null, 0, '0', '', 'bad', '-1', '48.5', '1e3', '048', [], (string) PHP_INT_MAX . '0' ] as $value ) {
			$this->clear(); $this->configure( 48 );
			CustomerCart::add( $this->selection( $f, 'digital' ) );
			$this->configure( $value );
			$label = 'platform ' . wp_json_encode( $value );
			$this->error( 'bc_electronic_limit_unavailable', ElectronicLimit::effective_limit(), $label . ' is invalid configuration' );
			$this->checkout_paths( false, $label, true );
			$this->clear();
			$this->error( 'bc_electronic_limit_unavailable', CustomerCart::add( $this->selection( $f, 'digital' ) ), $label . ' blocks digital add' );
			$this->ok( is_string( WC()->cart->add_to_cart( $f['merch'], 10 ) ), $label . ' permits ordinary merchandise' );
			$this->checkout_paths( true, $label . ' merchandise-only checkout' );
			$this->ok( is_string( CustomerCart::add( $this->selection( $f, 'paper' ) ) ), $label . ' permits Paper Package' );
			$this->checkout_paths( true, $label . ' Paper checkout' );
			if ( null === $value ) {
				$this->same( null, get_site_option( ElectronicLimit::PLATFORM_OPTION, null ), 'Paper purchase did not initialize 300' );
			}
		}
		foreach ( [ 0, '0', '', 'bad', '-1', '1.5', [] ] as $value ) {
			$this->clear(); $this->configure( 48 );
			CustomerCart::add( $this->selection( $f, 'digital' ) );
			$this->configure( 48, $value );
			$label = 'Hall override ' . wp_json_encode( $value );
			$this->error( 'bc_electronic_limit_unavailable', ElectronicLimit::effective_limit(), $label . ' is invalid configuration' );
			$this->checkout_paths( false, $label, true );
			$this->clear();
			$this->error( 'bc_electronic_limit_unavailable', CustomerCart::add( $this->selection( $f, 'digital' ) ), $label . ' blocks digital add' );
			$this->ok( is_string( CustomerCart::add( $this->selection( $f, 'paper' ) ) ), $label . ' permits Paper' );
			$this->checkout_paths( true, $label . ' Paper checkout' );
		}
	}

	private function stale_and_unreadable( array $f ): void {
		$this->clear(); $this->configure( 48, 99 );
		$input = $this->selection( $f, 'digital', [ $f['same_base_addon'] => 2 ] );
		CustomerCart::add( $input );
		$stored = ( new WC_Cart_Session( WC()->cart ) )->get_cart_for_session();
		$this->same( '48', (string) get_site_option( ElectronicLimit::PLATFORM_OPTION ), 'prime native option cache at old limit' );
		// Simulate another request changing storage while this request retains its cache.
		global $wpdb;
		$wpdb->update( $wpdb->sitemeta, [ 'meta_value' => '47' ], [ 'site_id' => get_current_network_id(), 'meta_key' => ElectronicLimit::PLATFORM_OPTION ] );
		$this->same( 47, ElectronicLimit::effective_limit(), 'fresh resolver sees lower platform value even with old override and cached option' );
		$this->checkout_paths( false, 'platform lowered after cart creation' );
		WC()->cart->cart_contents = []; WC()->session->set( 'cart', $stored ); WC()->session->set( 'cart_totals', null );
		( new WC_Cart_Session( WC()->cart ) )->get_cart_from_session();
		$this->checkout_paths( false, 'restored cart rechecks current limit' );
		wp_cache_delete( get_current_network_id() . ':' . ElectronicLimit::PLATFORM_OPTION, 'site-options' );
		$this->configure( 48 );
		$this->checkout_paths( true, 'corrected configuration makes existing cart valid' );
		foreach ( [ ElectronicLimit::PLATFORM_OPTION, ElectronicLimit::HALL_OPTION ] as $option ) {
			$break = static fn( $sql ) => str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, "'{$option}'" ) ? 'SELECT * FROM bc_electronic_missing_dependency' : $sql;
			$previous = $wpdb->suppress_errors( true ); add_filter( 'query', $break );
			try {
				$this->checkout_paths( false, 'unreadable ' . $option );
				$this->clear();
				$this->ok( is_string( CustomerCart::add( $this->selection( $f, 'paper' ) ) ), 'Paper does not read failed electronic setting' );
				$this->checkout_paths( true, 'Paper with unreadable ' . $option );
			} finally { remove_filter( 'query', $break ); $wpdb->suppress_errors( $previous ); }
			$this->clear(); CustomerCart::add( $input );
		}
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'game_type_id' => 999999 ], [ 'bingo_play_product_id' => $f['digital_play'] ] );
		$this->checkout_paths( false, 'missing digital Game Type dependency' );
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'game_type_id' => $f['game_a'] ], [ 'bingo_play_product_id' => $f['digital_play'] ] );
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => 7 ], [ 'bingo_play_product_id' => $f['digital_play'] ] );
		$this->checkout_paths( false, 'current component/add-on faces re-read after cart creation' );
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => 6 ], [ 'bingo_play_product_id' => $f['digital_play'] ] );
		$this->clear();
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => 4294967295 ], [ 'bingo_play_product_id' => $f['digital_play'] ] );
		$wpdb->update( CatalogMigrations::package_components_table(), [ 'quantity' => 4294967295 ], [ 'package_id' => $f['digital'] ] );
		$this->error( 'bc_electronic_faces_unknown', CustomerCart::add( $this->selection( $f, 'digital' ) ), 'integer multiplication overflow fails closed' );
		$wpdb->update( CatalogMigrations::play_products_table(), [ 'faces_per_unit' => 6 ], [ 'bingo_play_product_id' => $f['digital_play'] ] );
		$wpdb->update( CatalogMigrations::package_components_table(), [ 'quantity' => 6 ], [ 'package_id' => $f['digital'] ] );
	}

	private function isolation( array $a, array $b ): void {
		$this->clear(); $this->configure( 48, 40 );
		$this->same( $a['game_a'], $b['game_a'], 'Hall-local Game Type IDs collide' );
		$this->same( $a['digital'], $b['digital'], 'Hall-local Package IDs collide' );
		$this->ok( is_string( CustomerCart::add( $this->selection( $a, 'digital' ) ) ), 'Hall A permits 36 faces under override 40' );
		$contents = WC()->cart->get_cart();
		switch_to_blog( $b['site'] );
		try {
			update_option( ElectronicLimit::HALL_OPTION, '30' );
			$this->same( 30, ElectronicLimit::effective_limit(), 'Hall B resolves its own override' );
			$this->error( 'bc_electronic_limit_exceeded', CartSelection::resolve( $this->selection( $b, 'digital' ) ), 'Hall B rejects colliding Package under its own lower limit' );
			$this->ok( CartSelection::inspect( $contents )['errors']->has_errors(), 'Hall A cart cannot be validated as Hall B' );
		} finally { restore_current_blog(); }
		$this->same( 40, ElectronicLimit::effective_limit(), 'switch back restores Hall A isolation without stale cache' );
		$this->checkout_paths( true, 'Hall A cart still valid' );
	}

	/** Real checkout entry points. Sentinels stop only AFTER validation, before orders/payment. */
	private function checkout_paths( bool $allowed, string $label, bool $configuration_failure = false ): void {
		$this->same( 0, get_current_user_id(), $label . ': guest checkout' );
		wc_clear_notices(); WC()->cart->cart_context = 'shortcode';
		$reached = false; $codes = null;
		$stop = static function () use ( &$reached ) { $reached = true; throw new RuntimeException( 'BC_FIXTURE_AFTER_VALIDATION' ); };
		$observe = static function ( $data, $errors ) use ( &$codes ) { $codes = $errors->get_error_codes(); };
		add_filter( 'woocommerce_create_order', $stop );
		add_action( 'woocommerce_after_checkout_validation', $observe, 101, 2 );
		$post = $_POST; $request = $_REQUEST;
		$address = [ 'first_name' => 'Fixture', 'last_name' => 'Customer', 'address_1' => '1 Test Street', 'city' => 'Albany', 'state' => 'NY', 'postcode' => '12207', 'country' => 'US', 'email' => 'guest@example.invalid', 'phone' => '5185550100' ];
		try {
			$_POST = [ 'terms' => '1', 'payment_method' => '' ];
			foreach ( $address as $key => $value ) { $_POST[ 'billing_' . $key ] = $value; }
			$_REQUEST = $_POST + [ 'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ) ];
			WC()->checkout()->process_checkout();
			$this->same( $allowed, $reached, $label . ': classic reaches order boundary only when valid' );
			$this->ok( is_array( $codes ) && ( $allowed ? [] === $codes : [] !== array_filter( $codes, static fn( $code ) => str_starts_with( $code, 'bc_electronic_' ) ) ), $label . ': classic final electronic validation result ' . wp_json_encode( $codes ) );
			if ( $configuration_failure ) { $this->same( [ 'bc_electronic_limit_unavailable' ], $codes, $label . ': classic reports invalid configuration, not limit exceeded' ); }
		} finally {
			$_POST = $post; $_REQUEST = $request;
			remove_filter( 'woocommerce_create_order', $stop ); remove_action( 'woocommerce_after_checkout_validation', $observe, 101 );
		}
		wc_clear_notices();
		$reached = false;
		$stop_api = static function () use ( &$reached ) { $reached = true; throw new RouteException( 'bc_fixture_after_validation', 'Fixture stopped after checkout cart validation.', 418 ); };
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', $stop_api );
		try {
			$response = $this->request( '/checkout', [ 'billing_address' => $address ] );
			$this->same( $allowed, $reached, $label . ': Store API reaches post-validation boundary only when valid' );
			$this->same( $allowed ? 418 : 409, $response->get_status(), $label . ': Store API checkout response ' . ( 409 === $response->get_status() || 418 === $response->get_status() ? '' : wp_json_encode( $response->get_data() ) ) );
			if ( $configuration_failure ) { $this->ok( str_contains( wp_json_encode( $response->get_data() ), 'The Hall electronic-face limit could not be verified.' ), $label . ': Store API reports configuration failure through its existing cart-error envelope' ); }
		} finally { remove_action( 'woocommerce_store_api_checkout_update_customer_from_request', $stop_api ); }
		wc_clear_notices(); WC()->cart->cart_context = 'shortcode';
	}

	private function configure( mixed $platform, mixed $override = null ): void {
		if ( null === $platform ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
		else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $platform ); }
		if ( null === $override ) { delete_option( ElectronicLimit::HALL_OPTION ); }
		else { update_option( ElectronicLimit::HALL_OPTION, $override ); }
	}
	private function selection( array $f, string $package, array $addons = [] ): array {
		$rows = [];
		foreach ( $addons as $id => $quantity ) { $rows[] = [ 'package_addon_id' => $id, 'quantity' => $quantity ]; }
		return [ 'session_id' => $f['session'], 'package_id' => $f[ $package ], 'addons' => $rows ];
	}
	private function request( string $path, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1' . $path );
		$request->set_body_params( $body ); $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		return rest_get_server()->dispatch( $request );
	}
	private function clear(): void { WC()->cart->empty_cart( false ); wc_clear_notices(); WC()->cart->cart_context = 'shortcode'; }

	private function fixture( string $label ): array {
		global $wpdb;
		wp_set_current_user( $this->admin ); add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'faces-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Electronic Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site; switch_to_blog( $site ); update_option( 'bc_faces_test_owner', $this->token );
		try {
			WC_Install::create_tables();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) { ( new $schema() )->register_tables( true ); }
			update_option( 'timezone_string', 'America/New_York' ); update_option( 'woocommerce_enable_guest_checkout', 'yes' );
			$game_a = ( new GameTypeService() )->create( 'Regular' )->game_type['game_type_id'];
			$game_b = ( new GameTypeService() )->create( 'Winner' )->game_type['game_type_id'];
			$plays = new BingoPlayProductService();
			$paper_play = $plays->create( $this->product(), 'paper', 1000, null )->play_product['bingo_play_product_id'];
			$digital_play = $plays->create( $this->product(), 'digital', 6, $game_a )->play_product['bingo_play_product_id'];
			$second_play = $plays->create( $this->product(), 'digital', 3, $game_a )->play_product['bingo_play_product_id'];
			$other_play = $plays->create( $this->product(), 'digital', 8, $game_b )->play_product['bingo_play_product_id'];
			$packages = new PackageService();
			$paper = $packages->create( $this->product(), [ [ 'bingo_play_product_id' => $paper_play, 'quantity' => 1 ] ] )->package['package_id'];
			$digital = $packages->create( $this->product(), [ [ 'bingo_play_product_id' => $digital_play, 'quantity' => 6 ] ] )->package['package_id'];
			$multi = $packages->create( $this->product(), [ [ 'bingo_play_product_id' => $digital_play, 'quantity' => 6 ], [ 'bingo_play_product_id' => $second_play, 'quantity' => 4 ], [ 'bingo_play_product_id' => $other_play, 'quantity' => 6 ], [ 'bingo_play_product_id' => $paper_play, 'quantity' => 1 ] ] )->package['package_id'];
			$addons = new PackageAddonService();
			$same_base_addon = $addons->create( $digital, $digital_play, '0.00', 6 )->package_addon['package_addon_id'];
			$second_addon = $addons->create( $digital, $second_play, '0.00', 6 )->package_addon['package_addon_id'];
			$paper_addon = $addons->create( $digital, $paper_play, '0.00', 99 )->package_addon['package_addon_id'];
			$other_game_addon = $addons->create( $digital, $other_play, '0.00', 6 )->package_addon['package_addon_id'];
			$merch = $this->product(); $now = current_time( 'mysql', true );
			$wpdb->insert( Migrations::portal_session_templates_table(), [ 'portal_session_id' => 80002, 'name' => 'Faces fixture', 'portal_status' => 'active', 'is_current' => 1, 'created_at' => $now, 'updated_at' => $now ] );
			$date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d' );
			$schedules = new SessionScheduleService();
			$schedule = $schedules->create( 80002, 'dates', '18:00', [ $date ], [ $paper, $digital, $multi ] );
			if ( is_wp_error( $schedule ) ) { throw new RuntimeException( 'Fixture schedule creation failed.' ); }
			$schedules->generate( (int) $schedule->schedule['schedule_id'] );
			$session = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT session_id FROM %i WHERE schedule_id = %d', Migrations::sessions_table(), $schedule->schedule['schedule_id'] ) );
			if ( is_wp_error( ( new SessionService() )->setAvailableForSale( $session, true ) ) ) { throw new RuntimeException( 'Fixture sales enable failed.' ); }
			return array_map( 'intval', compact( 'site', 'game_a', 'game_b', 'paper', 'digital', 'multi', 'digital_play', 'same_base_addon', 'second_addon', 'paper_addon', 'other_game_addon', 'merch', 'session' ) );
		} finally { restore_current_blog(); }
	}
	private function product(): int {
		$p = new WC_Product_Simple(); $p->set_name( 'Faces fixture ' . wp_generate_password( 5, false ) ); $p->set_status( 'publish' ); $p->set_regular_price( '0' ); $p->set_virtual( true ); return $p->save();
	}
	private function snapshot( int $site ): array {
		global $wpdb; $result = [];
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded ); $result[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $result ); return $result;
	}
	private function cleanup(): void {
		global $wpdb;
		if ( WC()->cart ) { WC()->cart->empty_cart( false ); }
		if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_faces_test_owner' ) ) { throw new RuntimeException( 'Cleanup ownership guard refused.' ); }
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned table discovery failed.' ); }
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
			$this->same( [], $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ), 'owned Hall tables cleaned up' );
		}
	}
	private function error( string $code, mixed $actual, string $label ): void { $this->same( $code, is_wp_error( $actual ) ? $actual->get_error_code() : $actual, $label ); }
	private function same( mixed $expected, mixed $actual, string $label ): void {
		$this->ok( $expected === $actual, $label . ( $expected === $actual ? '' : ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) ) );
	}
	private function ok( bool $condition, string $label ): void {
		if ( $condition ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	}
}
( new CustomerElectronicLimitsTest() )->run();
