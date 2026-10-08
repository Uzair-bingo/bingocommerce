<?php

/**
 * Owned disposable QR-redemption fixtures, never the development database.
 * Including this file boots the existing isolated fixture; call prepare() to
 * create per-run data and cleanup() in finally. Nothing runs as a test here.
 *
 * Orders below deliberately synthesize the existing saved purchase contract,
 * then use native payment_complete() without invoking a payment gateway. This
 * is a backend fixture, not checkout or real Caller/Android integration proof.
 */
declare(strict_types=1);

$bc_qr_sapi = 'cli' === PHP_SAPI || ( 'cgi-fcgi' === PHP_SAPI && '1' === getenv( 'BC_FIXTURE_CGI' ) && ! isset( $_SERVER['SERVER_SOFTWARE'] ) && ! isset( $_SERVER['REMOTE_ADDR'] ) );
if ( ! $bc_qr_sapi || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: isolated bingocommerce_state_recon is required.\n" );
	exit( 1 );
}
unset( $bc_qr_sapi );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require_once __DIR__ . '/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/bingocommerce-digital-tickets.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\DigitalTickets\DigitalTicket;
use BingoCommerce\DigitalTickets\EntitlementSnapshot;
use BingoCommerce\DigitalTickets\Migrations as TicketMigrations;
use BingoCommerce\DigitalTickets\Plugin as TicketPlugin;
use BingoCommerce\PortalIntegration\HallMappingRepository;
use BingoCommerce\PortalIntegration\Migrations as MappingMigrations;
use BingoCommerce\Sessions\SessionService;

final class QRRedemptionFixture {

	public const OWNER_OPTION = 'bc_qr_redemption_fixture_owner';
	public array $halls = [];
	/** Private fixture credentials. Never print or commit this array. */
	public array $service = [];
	public string $owner;
	private int $admin;
	private int $original_user;
	private array $sites = [];
	private array $seed_baseline = [];
	private mixed $previous_limit = null;
	private object $absent;
	private bool $limit_captured = false;
	private bool $prepared = false;
	private bool $cleaned = false;
	private Closure $mail_block;
	private Closure $http_block;
	private mixed $checkpoint = null;

	public function __construct() {
		self::assertDatabase();
		$this->owner = wp_generate_uuid4();
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->original_user = get_current_user_id();
		$this->absent = new stdClass();
		if ( $this->admin < 1 || ! is_super_admin( $this->admin ) ) { throw new RuntimeException( 'Fixture administrator unavailable.' ); }
		$this->mail_block = static fn() => true;
		$this->http_block = static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP from fixtures.' );
		add_filter( 'pre_wp_mail', $this->mail_block, PHP_INT_MIN );
		add_filter( 'pre_http_request', $this->http_block, PHP_INT_MIN );
		if ( false === has_action( 'woocommerce_payment_complete', [ EntitlementSnapshot::class, 'on_payment_complete' ] ) ) { TicketPlugin::init(); }
	}

	/** Creates exactly two per-run Halls and one least-privilege machine user. */
	public function prepare( int $limit = 30, ?callable $checkpoint = null ): void {
		if ( $this->prepared || $limit < 1 ) { throw new RuntimeException( 'Fixture prepare called twice or with invalid limit.' ); }
		self::assertDatabase();
		$this->prepared = true;
		$this->checkpoint = $checkpoint;
		foreach ( [ 2, 3, 4 ] as $site ) { $this->seed_baseline[ $site ] = self::hallSnapshot( $site ); }
		$this->previous_limit = get_site_option( ElectronicLimit::PLATFORM_OPTION, $this->absent );
		$this->limit_captured = true;
		$this->checkpoint();
		update_site_option( ElectronicLimit::PLATFORM_OPTION, (string) $limit );
		if ( (string) get_site_option( ElectronicLimit::PLATFORM_OPTION ) !== (string) $limit ) { throw new RuntimeException( 'Fixture limit could not be configured.' ); }
		wp_set_current_user( $this->admin );
		$this->halls['a'] = $this->createHall( 'a' );
		$this->halls['b'] = $this->createHall( 'b' );
		$this->createService();
		$this->checkpoint();
		wp_set_current_user( $this->original_user );
	}

	/** Private retained-fixture state. Never publish it: it includes test credentials. */
	public function exportState(): array {
		return [ 'version' => 1, 'owner' => $this->owner, 'halls' => $this->halls, 'service' => $this->service,
			'sites' => $this->sites, 'seed_baseline' => $this->seed_baseline, 'prepared' => $this->prepared,
			'limit_captured' => $this->limit_captured, 'limit_absent' => $this->previous_limit === $this->absent,
			'previous_limit' => $this->previous_limit === $this->absent ? null : $this->previous_limit ];
	}

	public static function resume( array $state ): self {
		self::assertDatabase();
		if ( 1 !== ( $state['version'] ?? null ) || ! is_string( $state['owner'] ?? null ) || ! wp_is_uuid( $state['owner'], 4 )
			|| ! is_array( $state['sites'] ?? null ) || ! is_array( $state['seed_baseline'] ?? null ) ) { throw new RuntimeException( 'Retained fixture state refused.' ); }
		$fixture = new self();
		$fixture->owner = $state['owner']; $fixture->halls = $state['halls']; $fixture->service = $state['service'];
		$fixture->sites = $state['sites']; $fixture->seed_baseline = $state['seed_baseline'];
		$fixture->prepared = $state['prepared']; $fixture->limit_captured = $state['limit_captured'];
		$fixture->previous_limit = $state['limit_absent'] ? $fixture->absent : $state['previous_limit'];
		foreach ( $fixture->sites as $site ) { $fixture->assertHall( $site ); }
		return $fixture;
	}

	private function checkpoint(): void {
		if ( null !== $this->checkpoint ) { ( $this->checkpoint )( $this->exportState() ); }
	}

	/** Current database and sentinel are re-proven before fixture mutations. */
	public static function assertDatabase(): void {
		global $wpdb;
		if ( ! defined( 'DB_NAME' ) || 'bingocommerce_state_recon' !== DB_NAME || DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db
			|| (string) $wpdb->get_var( 'SELECT DATABASE()' ) !== DB_NAME || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Fixture database ownership refused.' ); }
		$sentinel = $wpdb->get_var( $wpdb->prepare( 'SELECT identity FROM %i LIMIT 1', BC_FIXTURE_SENTINEL_TABLE ) );
		if ( '' !== $wpdb->last_error || BC_FIXTURE_IDENTITY !== $sentinel ) { throw new RuntimeException( 'Fixture sentinel ownership refused.' ); }
	}

	public function assertHall( int $site ): void {
		self::assertDatabase();
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || get_blog_option( $site, self::OWNER_OPTION ) !== $this->owner ) { throw new RuntimeException( 'Fixture Hall ownership refused.' ); }
	}

	/** Always restores the incoming Hall and actor, including on exceptions. */
	public function inHall( int $site, callable $work ): mixed {
		$this->assertHall( $site );
		$user = get_current_user_id();
		switch_to_blog( $site );
		try { return $work(); }
		finally { restore_current_blog(); wp_set_current_user( $user ); }
	}

	/**
	 * $faces is a frozen aggregate keyed by this Hall's local Game Type IDs.
	 * All quantities deliberately come from this argument, not current Catalog.
	 * Paper forces an empty map. Hybrid has the same digital aggregation behavior.
	 * Returns credentials only in memory for authenticated fixture requests.
	 */
	public function createTicket( array $hall, array $faces, string $kind = 'digital', bool $timing = false ): array {
		if ( ! in_array( $kind, [ 'digital', 'hybrid', 'paper' ], true ) ) { throw new InvalidArgumentException( 'Unknown fixture Package type.' ); }
		if ( 'paper' === $kind ) { $faces = []; }
		foreach ( $faces as $game => $count ) {
			if ( ! is_int( $game ) || ! isset( $hall['games'][ $game ] ) || ! is_int( $count ) || $count < 1 ) { throw new InvalidArgumentException( 'Invalid fixture digital aggregate.' ); }
		}
		if ( 'paper' !== $kind && [] === $faces ) { throw new InvalidArgumentException( 'Digital fixture requires faces.' ); }
		ksort( $faces, SORT_NUMERIC );
		return $this->inHall( $hall['site'], function () use ( $hall, $faces, $kind, $timing ): array {
			wp_set_current_user( 0 );
			$package = $hall['packages'][ $kind ];
			$session = $timing ? $hall['timed_session'] : $hall['session'];
			$facts = [
				'version' => 1, 'site_id' => $hall['site'], 'selection_id' => wp_generate_uuid4(),
				'session_id' => $session, 'session_date' => $hall['session_date'], 'session_name' => $timing ? 'Saved timed QR Session' : 'Saved QR Session',
				'schedule_id' => null, 'start_time_local' => $timing ? '18:00:00' : null,
				'timezone_name' => $timing ? 'America/New_York' : null, 'starts_at_utc' => $timing ? $hall['starts_at_utc'] : null,
				'package_id' => $package['package_id'], 'wc_product_id' => $package['wc_product_id'],
				'package_name' => 'Saved ' . ucfirst( $kind ) . ' Package', 'package_type' => $kind,
				'quantity' => 1, 'addons' => [], 'digital_faces_by_game_type' => $faces,
			];
			$order = new WC_Order();
			$order->set_customer_id( 0 );
			$order->set_created_via( 'checkout' );
			$order->set_status( 'pending' );
			$order->set_currency( 'USD' );
			$order->set_payment_method( 'bc_qr_fixture_no_gateway' );
			$order->set_billing_email( 'qr-fixture@example.invalid' );
			$order->update_meta_data( 'bc_purchase', $facts );
			$order->update_meta_data( self::OWNER_OPTION, $this->owner );
			$item = new WC_Order_Item_Product();
			$item->set_product_id( $package['wc_product_id'] );
			$item->set_name( $facts['package_name'] );
			$item->set_quantity( 1 );
			$item->set_subtotal( '20.00' );
			$item->set_total( '20.00' );
			$item->add_meta_data( 'bc_purchase', [
				'version' => 1, 'site_id' => $hall['site'], 'selection_id' => $facts['selection_id'],
				'session_id' => $session, 'package_id' => $package['package_id'], 'role' => 'package', 'addon_id' => 0, 'quantity' => 1,
			], true );
			$order->add_item( $item );
			$order->set_total( '20.00' );
			$order_id = $order->save();
			if ( $order_id < 1 || ! EntitlementSnapshot::valid_facts( $order, $facts ) || ! $order->payment_complete( 'disposable-no-money-' . $order_id ) ) { throw new RuntimeException( 'Fixture order confirmation failed.' ); }
			global $wpdb;
			$entitlement = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', TicketMigrations::table(), $order_id ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Fixture entitlement read failed.' ); }
			$qr = DigitalTicket::reference_for_order( $order_id );
			if ( ( 'paper' === $kind && ( null !== $qr || null !== $entitlement ) ) || ( 'paper' !== $kind && ( ! is_string( $qr ) || ! is_array( $entitlement ) ) ) ) { throw new RuntimeException( 'Fixture native issuance did not match the Package type.' ); }
			return [ 'site' => $hall['site'], 'portal_hall_id' => $hall['portal_hall_id'], 'order_id' => $order_id,
				'entitlement_id' => null === $entitlement ? null : (int) $entitlement['entitlement_id'], 'qr' => $qr, 'facts' => $facts ];
		} );
	}

	private function createHall( string $label ): array {
		self::assertDatabase();
		$site = wp_insert_site( [ 'domain' => 'qr-' . $label . '-' . substr( $this->owner, 0, 8 ) . '.' . BC_FIXTURE_DOMAIN,
			'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable QR redemption Hall', 'user_id' => $this->admin ] );
		if ( $site instanceof WP_Error || ! is_int( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable QR Hall creation failed.' ); }
		$this->sites[] = $site;
		update_blog_option( $site, self::OWNER_OPTION, $this->owner );
		$this->checkpoint();
		return $this->inHall( $site, function () use ( $site ): array {
			wp_set_current_user( $this->admin );
			WC_Install::create_tables();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) { ( new $schema() )->register_tables( true ); }
			foreach ( [ 'woocommerce_custom_orders_table_enabled' => 'yes', 'woocommerce_custom_orders_table_data_sync_enabled' => 'no',
				'timezone_string' => 'America/New_York', 'woocommerce_currency' => 'USD', 'woocommerce_calc_taxes' => 'no' ] as $key => $value ) { update_option( $key, $value ); }
			foreach ( [ 'background_color' => '#ffffff', 'body_background_color' => '#ffffff', 'base_color' => '#555555', 'text_color' => '#222222', 'footer_text_color' => '#444444' ] as $key => $value ) { update_option( 'woocommerce_email_' . $key, $value ); }
			if ( true !== TicketMigrations::migrate_current_site() ) { throw new RuntimeException( 'Fixture Digital Tickets migration failed.' ); }
			$games = [];
			foreach ( [ 1001 => 'QR Regular', 1002 => 'QR Special' ] as $portal => $name ) {
				$result = ( new GameTypeService() )->create( $name );
				if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Fixture Game Type creation failed.' ); }
				$games[ (int) $result->game_type['game_type_id'] ] = $portal;
			}
			$first = array_key_first( $games );
			$digital = ( new BingoPlayProductService() )->create( self::product( 'QR digital base' ), 'digital', 1, $first );
			$paper = ( new BingoPlayProductService() )->create( self::product( 'QR paper base' ), 'paper', 100, null );
			if ( $digital instanceof WP_Error || $paper instanceof WP_Error ) { throw new RuntimeException( 'Fixture Play Product creation failed.' ); }
			$packages = [];
			foreach ( [ 'digital', 'hybrid', 'paper' ] as $kind ) {
				$components = [];
				if ( 'paper' !== $kind ) { $components[] = [ 'bingo_play_product_id' => $digital->play_product['bingo_play_product_id'], 'quantity' => 1 ]; }
				if ( 'digital' !== $kind ) { $components[] = [ 'bingo_play_product_id' => $paper->play_product['bingo_play_product_id'], 'quantity' => 1 ]; }
				$product = self::product( 'Saved ' . ucfirst( $kind ) . ' Package' );
				$result = ( new PackageService() )->create( $product, $components );
				if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Fixture Package creation failed.' ); }
				$packages[ $kind ] = [ 'package_id' => (int) $result->package['package_id'], 'wc_product_id' => $product ];
			}
			$date = ( new DateTimeImmutable( 'yesterday', new DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d' );
			$service = new SessionService();
			$plain = $service->create( $date, 'Saved QR Session', false );
			$timed = $service->create( $date, 'Saved timed QR Session', false );
			if ( $plain instanceof WP_Error || $timed instanceof WP_Error ) { throw new RuntimeException( 'Fixture dated Session creation failed.' ); }
			$session = (int) $plain->session['session_id']; $timed_session = (int) $timed->session['session_id'];
			$starts_at_utc = ( new DateTimeImmutable( $date . ' 18:00:00', new DateTimeZone( 'America/New_York' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			global $wpdb;
			if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET start_time_local=%s,timezone_name=%s,starts_at_utc=%s WHERE session_id=%d',
				\BingoCommerce\Sessions\Migrations::sessions_table(), '18:00:00', 'America/New_York', $starts_at_utc, $timed_session ) ) ) { throw new RuntimeException( 'Fixture recorded Session timing failed.' ); }
			foreach ( $games as $local => $portal ) {
				if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET portal_game_type_id=%d,local_name_key=NULL WHERE game_type_id=%d',
					\BingoCommerce\Catalog\Migrations::game_types_table(), $portal, $local ) ) ) { throw new RuntimeException( 'Fixture Portal Game Type mapping failed.' ); }
			}
			$mapping = new HallMappingRepository();
			do { $portal_hall_id = random_int( 800000000, 899999999 ); $existing = $mapping->findByPortalHallId( $portal_hall_id );
				if ( $existing instanceof WP_Error ) { throw new RuntimeException( 'Fixture Hall mapping read failed.' ); }
			} while ( null !== $existing );
			if ( $mapping->bind( $portal_hall_id, $site ) instanceof WP_Error ) { throw new RuntimeException( 'Fixture Hall binding failed.' ); }
			return [ 'site' => $site, 'portal_hall_id' => $portal_hall_id, 'session' => $session, 'timed_session' => $timed_session,
				'session_date' => $date, 'starts_at_utc' => $starts_at_utc, 'games' => $games, 'packages' => $packages ];
		} );
	}

	private static function product( string $name ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name ); $product->set_status( 'publish' ); $product->set_regular_price( '20.00' );
		$product->set_virtual( true ); $product->set_tax_status( 'none' );
		$id = $product->save();
		if ( $id < 1 ) { throw new RuntimeException( 'Fixture Woo product failed.' ); }
		return $id;
	}

	private function createService(): void {
		self::assertDatabase();
		$main = get_main_site_id( get_current_network_id() );
		switch_to_blog( $main );
		try {
			$username = 'qr-service-' . substr( $this->owner, 0, 8 );
			$user_id = wp_insert_user( [ 'user_login' => $username, 'user_pass' => wp_generate_password( 48 ), 'user_email' => $username . '@example.invalid', 'role' => '' ] );
			if ( $user_id instanceof WP_Error || $user_id < 1 ) { throw new RuntimeException( 'Fixture service user failed.' ); }
			$this->service = [ 'user_id' => $user_id, 'username' => $username ];
			update_user_meta( $user_id, self::OWNER_OPTION, $this->owner );
			$this->checkpoint();
			$user = new WP_User( $user_id );
			$user->add_cap( 'bc_receive_portal_redemptions' );
			$created = WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => 'Disposable QR redemption fixture' ] );
			if ( $created instanceof WP_Error || ! is_array( $created ) ) { throw new RuntimeException( 'Fixture Application Password failed.' ); }
			$this->service += [ 'password' => $created[0], 'application_uuid' => $created[1]['uuid'] ];
		} finally { restore_current_blog(); }
	}

	public static function hallSnapshot( int $site ): array {
		self::assertDatabase();
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Fixture Hall table discovery failed.' ); }
		$hashes = [];
		foreach ( $tables as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Fixture Hall snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded ); $hashes[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $hashes );
		return $hashes;
	}

	/** Returns non-secret cleanup evidence; throws if any ownership proof fails. */
	public function cleanup(): array {
		if ( $this->cleaned ) { throw new RuntimeException( 'Fixture cleanup already completed.' ); }
		self::assertDatabase();
		global $wpdb;
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		$evidence = [ 'database' => DB_NAME, 'sites_removed' => [], 'service_removed' => true, 'limit_restored' => true, 'seed_halls_unchanged' => [] ];
		foreach ( $this->sites as $site ) {
			$this->assertHall( $site );
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Fixture cleanup discovery failed.' ); }
			if ( false === $wpdb->delete( MappingMigrations::hall_mappings_table(), [ 'blog_id' => $site ], [ '%d' ] )
				|| false === $wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] ) ) { throw new RuntimeException( 'Fixture network row cleanup failed.' ); }
			$result = wp_delete_site( $site );
			if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Fixture Hall deletion failed.' ); }
			foreach ( $tables as $table ) {
				self::assertDatabase();
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Fixture owned table cleanup failed.' ); }
			}
			if ( null !== get_site( $site ) || [] !== $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Fixture Hall cleanup was not verified.' ); }
			$evidence['sites_removed'][] = $site;
		}
		if ( isset( $this->service['user_id'] ) ) {
			self::assertDatabase();
			$id = $this->service['user_id'];
			if ( get_user_meta( $id, self::OWNER_OPTION, true ) !== $this->owner || is_super_admin( $id ) ) { throw new RuntimeException( 'Fixture user cleanup ownership refused.' ); }
			WP_Application_Passwords::delete_all_application_passwords( $id );
			if ( ! wpmu_delete_user( $id ) || false !== get_userdata( $id ) ) { throw new RuntimeException( 'Fixture service cleanup failed.' ); }
			$this->service = [];
		}
		if ( $this->limit_captured ) {
			self::assertDatabase();
			if ( $this->previous_limit === $this->absent ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
			else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $this->previous_limit ); }
			if ( get_site_option( ElectronicLimit::PLATFORM_OPTION, $this->absent ) !== $this->previous_limit ) { throw new RuntimeException( 'Fixture limit restoration failed.' ); }
		}
		foreach ( $this->seed_baseline as $site => $before ) {
			if ( $before !== self::hallSnapshot( $site ) ) { throw new RuntimeException( 'Persistent fixture Hall changed: ' . $site ); }
			$evidence['seed_halls_unchanged'][] = $site;
		}
		remove_filter( 'pre_wp_mail', $this->mail_block, PHP_INT_MIN );
		remove_filter( 'pre_http_request', $this->http_block, PHP_INT_MIN );
		wp_set_current_user( $this->original_user );
		$this->cleaned = true;
		return $evidence;
	}
}
