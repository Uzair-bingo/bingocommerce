<?php

/**
 * BR-064 settings plus BR-038/BR-042/BR-082 guest cart/final checkout validation.
 * BC_TEST_DB=bingocommerce_state_recon php tests/customer-electronic-limits.php
 * Disposable token-owned Halls only; restore the fixture network option on exit.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\ElectronicLimitSettings;
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
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	public function run(): void {
		// Keep native denials observable without letting wp_die bypass fixture cleanup.
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( 'Native settings denial: ' . wp_strip_all_tags( (string) $message ) ); } );
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
			$this->settings_administration( $a, $b );
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

	private function settings_administration( array $a, array $b ): void {
		global $wpdb;
		$old_screen = $GLOBALS['current_screen'] ?? null;
		$old_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$old_post = $_POST;
		try {
			wp_set_current_user( $this->admin );
			WC_Install::create_roles(); \BingoCommerce\Core\Roles::sync_current_site();
			$actors = [];
			foreach ( [ 'manager' => 'bc_hall_manager', 'operator' => 'bc_game_operator', 'customer' => 'subscriber', 'wp_admin' => 'administrator', 'shop_manager' => 'shop_manager' ] as $label => $role ) {
				$id = wp_insert_user( [ 'user_login' => 'limit-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
				if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Settings fixture actor creation failed.' ); }
				$this->users[] = $id; $actors[ $label ] = $id; update_user_meta( $id, 'bc_faces_test_owner', $this->token );
				$this->settings_grant( $a['site'], $id, $role );
			}
			$this->same( 10, has_action( 'network_admin_menu', [ ElectronicLimitSettings::class, 'network_menu' ] ), 'network settings menu registered' );
			$this->same( 10, has_action( 'admin_menu', [ ElectronicLimitSettings::class, 'hall_menu' ] ), 'Hall settings menu registered' );
			$this->configure( null ); set_current_screen( 'dashboard-network' );
			ElectronicLimitSettings::network_menu();
			$this->same( 10, has_action( get_plugin_page_hookname( ElectronicLimitSettings::PAGE, 'settings.php' ), [ ElectronicLimitSettings::class, 'render_network' ] ), 'Network Settings submenu routes to guarded screen' );
			$html = $this->settings_screen( true );
			$this->ok( str_contains( $html, 'Not configured' ) && str_contains( $html, 'Unavailable' ), 'network page clearly shows missing configuration' );
			$this->same( false, get_site_option( ElectronicLimit::PLATFORM_OPTION, false ), 'opening network settings creates no 300 or other default' );
			$this->ok( ! str_contains( $html, 'value="remove"' ), 'network page offers no remove action' );
			$this->same( true, $this->settings_submit( true, [ 'limit' => '1' ] ), 'Super Admin saves platform minimum' );
			$this->same( '1', (string) get_site_option( ElectronicLimit::PLATFORM_OPTION ), 'platform minimum persisted' );
			$this->same( true, $this->settings_submit( true, [ 'limit' => (string) PHP_INT_MAX ] ), 'maximum supported integer accepted' );
			$this->same( true, $this->settings_submit( true, [ 'limit' => '48' ] ), 'platform maximum saved' );
			$this->same( true, $this->settings_submit( true, [ 'limit' => '48' ] ), 'unchanged save succeeds without default initialization' );
			$this->settings_form_save( true, 'save', '48' );
			$invalid = [ 0, '0', '', null, -1, '-1', 1.5, '1.5', '1e3', '048', '+48', ' 48 ', "48\n", [], true, (string) PHP_INT_MAX . '0', '9223372036854775808', '<b>48</b>' ];
			foreach ( $invalid as $value ) {
				$this->error( 'bc_electronic_settings_value', $this->settings_submit( true, [ 'limit' => $value ] ), 'invalid platform input rejected: ' . wp_json_encode( $value ) );
				$this->same( '48', (string) get_site_option( ElectronicLimit::PLATFORM_OPTION ), 'invalid platform submission preserves stored maximum' );
			}
			$this->error( 'bc_electronic_settings_request', $this->settings_submit( true, [ 'bc_limit_action' => 'remove' ] ), 'network maximum cannot be removed by Hall action' );
			foreach ( array_merge( [ 0 ], array_values( $actors ) ) as $actor ) {
				wp_set_current_user( $actor );
				$this->error( 'bc_electronic_settings_denied', $this->settings_submit( true ), 'guest/local role cannot save network setting: ' . $actor );
				$this->same( '48', (string) get_site_option( ElectronicLimit::PLATFORM_OPTION ), 'denied network save preserves value' );
				$this->settings_denied_screen( true );
			}
			set_current_screen( 'dashboard' ); wp_set_current_user( $actors['manager'] );
			$this->same( true, current_user_can( 'bc_manage_hall_settings' ), 'owned Hall Manager has existing settings capability' );
			ElectronicLimitSettings::hall_menu();
			$this->same( 10, has_action( get_plugin_page_hookname( ElectronicLimitSettings::PAGE, 'woocommerce' ), [ ElectronicLimitSettings::class, 'render_hall' ] ), 'WooCommerce Hall submenu routes to guarded screen' );
			$html = $this->settings_screen( false );
			$this->ok( str_contains( $html, 'Absent — use platform maximum' ) && str_contains( $html, 'Effective Hall limit' ), 'Hall screen labels absent override and effective value' );
			$this->same( false, get_option( ElectronicLimit::HALL_OPTION, false ), 'Hall GET does not create an override' );
			$b_before = $this->snapshot( $b['site'] );
			$this->same( true, $this->settings_submit( false, [ 'limit' => '1' ] ), 'authorized Hall Manager saves minimum override' );
			$this->same( 1, ElectronicLimit::effective_limit(), 'saved Hall minimum takes effect' );
			$this->same( true, $this->settings_submit( false, [ 'limit' => '99' ] ), 'Hall may store override above platform without clamping' );
			$this->settings_form_save( false, 'save', '99' );
			$this->same( 48, ElectronicLimit::effective_limit(), 'higher stored override is dynamically bounded' );
			$this->settings_override_notice();
			foreach ( $invalid as $value ) {
				$this->error( 'bc_electronic_settings_value', $this->settings_submit( false, [ 'limit' => $value ] ), 'invalid Hall input rejected: ' . wp_json_encode( $value ) );
				$this->same( '99', (string) get_option( ElectronicLimit::HALL_OPTION ), 'invalid Hall submission preserves override' );
			}
			foreach ( [ true, false ] as $network ) {
				wp_set_current_user( $network ? $this->admin : $actors['manager'] ); set_current_screen( $network ? 'dashboard-network' : 'dashboard' );
				foreach ( [ '', 'invalid', [] ] as $nonce ) { $this->error( 'bc_electronic_settings_nonce', $this->settings_submit( $network, [ '_wpnonce' => $nonce ] ), 'missing/invalid nonce refused' ); }
				$this->error( 'bc_electronic_settings_request', $this->settings_submit( $network, [], 'GET' ), 'GET cannot save' );
				$this->error( 'bc_electronic_settings_request', $this->settings_submit( $network, [], 'PUT' ), 'non-POST cannot save' );
				foreach ( [ 'site_id', 'blog_id', 'hall_id', 'network_id', 'actor_user_id' ] as $key ) {
					$this->error( 'bc_electronic_settings_request', $this->settings_submit( $network, [ $key => $b['site'] ] ), 'submitted identity/scope rejected: ' . $key );
				}
			}
			foreach ( [ 0, $actors['operator'], $actors['customer'], $actors['wp_admin'], $actors['shop_manager'] ] as $actor ) {
				wp_set_current_user( $actor );
				$this->error( 'bc_electronic_settings_denied', $this->settings_submit( false ), 'guest/non-manager cannot save Hall override' );
				$this->error( 'bc_electronic_settings_denied', $this->settings_submit( false, [ 'bc_limit_action' => 'remove' ] ), 'guest/non-manager cannot remove Hall override' );
				$this->settings_denied_screen( false );
			}
			wp_set_current_user( $actors['manager'] ); $nonce_a = $this->settings_nonce( false );
			switch_to_blog( $b['site'] );
			try { $this->error( 'bc_electronic_settings_denied', $this->settings_submit( false ), 'Hall A Manager has no Hall B authority' ); }
			finally { restore_current_blog(); }
			$this->same( $b_before, $this->snapshot( $b['site'] ), 'Hall A settings requests leave every Hall B table byte-identical' );
			wp_set_current_user( $this->admin );
			$this->settings_grant( $b['site'], $actors['manager'], 'bc_hall_manager' );
			wp_set_current_user( $actors['manager'] ); switch_to_blog( $b['site'] );
			try {
				$this->error( 'bc_electronic_settings_nonce', $this->settings_submit( false, [ '_wpnonce' => $nonce_a ] ), 'multi-Hall Manager cannot reuse Hall A nonce in B' );
				$this->same( true, $this->settings_submit( false, [ 'limit' => '17' ] ), 'fresh Hall B form saves only B' );
				$this->same( 17, ElectronicLimit::effective_limit(), 'Hall B effective override is independent' );
			} finally { restore_current_blog(); }
			$this->same( '99', (string) get_option( ElectronicLimit::HALL_OPTION ), 'Hall A override untouched by B save' );
			$this->same( true, $this->settings_submit( false, [ 'bc_limit_action' => 'remove' ] ), 'explicit Hall removal succeeds' );
			$this->settings_form_save( false, 'remove' );
			$this->same( false, get_option( ElectronicLimit::HALL_OPTION, false ), 'removal deletes option rather than storing zero/blank' );
			$this->same( 48, ElectronicLimit::effective_limit(), 'absent override inherits platform' );
			$this->same( true, $this->settings_submit( false, [ 'bc_limit_action' => 'remove' ] ), 'repeated removal harmless' );
			$this->same( true, $this->settings_submit( false, [ 'limit' => '99' ] ), 'override restored for lowering test' );
			// UI rendering escapes corrupt stored data and does not silently repair it.
			update_option( ElectronicLimit::HALL_OPTION, '<script>bad</script>' );
			$html = $this->settings_screen( false );
			$this->ok( str_contains( $html, '&lt;script&gt;bad&lt;/script&gt;' ) && ! str_contains( $html, '<script>bad</script>' ) && str_contains( $html, 'Unavailable' ), 'invalid saved value escaped and effective limit unavailable' );
			$this->same( '<script>bad</script>', get_option( ElectronicLimit::HALL_OPTION ), 'render leaves malformed saved value unchanged' );
			$this->same( true, $this->settings_submit( false, [ 'limit' => '99' ] ), 'authorized correction repairs malformed override' );
			foreach ( [ false, true ] as $network ) {
				wp_set_current_user( $network ? $this->admin : $actors['manager'] ); set_current_screen( $network ? 'dashboard-network' : 'dashboard' );
				$table = $network ? $wpdb->sitemeta : $wpdb->options;
				foreach ( [ 'SELECT', 'UPDATE' ] as $statement ) {
					$fail = static fn( $sql ) => str_starts_with( $sql, $statement . ' ' ) && str_contains( $sql, '`' . $table . '`' ) ? 'SELECT * FROM bc_fixture_missing_limit_setting' : $sql;
					$quiet = $wpdb->suppress_errors( true ); add_filter( 'query', $fail );
					try { $this->error( 'bc_electronic_settings_storage', $this->settings_submit( $network ), 'unreadable/failed settings storage never claims success' ); }
					finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $quiet ); }
					$this->same( $network ? '48' : '99', (string) ( $network ? get_site_option( ElectronicLimit::PLATFORM_OPTION ) : get_option( ElectronicLimit::HALL_OPTION ) ), 'failed storage leaves previous setting intact' );
				}
			}
			wp_set_current_user( 0 ); $this->clear();
			$this->ok( is_string( CustomerCart::add( $this->selection( $a, 'digital' ) ) ), 'guest adds 36 faces under admin-configured 48 limit' );
			$this->checkout_paths( true, 'admin-configured original limit' );
			wp_set_current_user( $this->admin ); set_current_screen( 'dashboard-network' );
			$this->settings_form_save( true, 'save', '35' );
			set_current_screen( 'dashboard' ); wp_set_current_user( $actors['manager'] );
			$html = $this->settings_screen( false );
			$this->ok( str_contains( $html, '<td>99</td>' ) && str_contains( $html, '<td>35</td>' ), 'Hall screen shows unchanged saved 99 and effective 35' );
			$this->ok( str_contains( $html, 'Your Hall override is saved as 99, but the platform maximum is 35. This Hall is currently limited to 35 digital faces per Game Type in a customer selection.' ), 'later visit notice uses newly lowered platform value without rewriting override' );
			$this->same( '99', (string) get_option( ElectronicLimit::HALL_OPTION ), 'lower platform never rewrites saved override' );
			wp_set_current_user( 0 );
			$this->checkout_paths( false, 'admin lowered platform under an existing digital cart' );
			$this->clear();
			$this->error( 'bc_electronic_limit_exceeded', CustomerCart::add( $this->selection( $a, 'digital' ) ), 'new digital add also sees saved lower platform' );
			$this->ok( is_string( CustomerCart::add( $this->selection( $a, 'paper' ) ) ), 'Paper unaffected by lower setting' );
			$this->ok( is_string( WC()->cart->add_to_cart( $a['merch'], 10 ) ), 'merchandise unaffected by lower setting' );
			$this->checkout_paths( true, 'Paper and merchandise after admin platform reduction' );
			$this->clear();
			wp_set_current_user( $this->admin ); set_current_screen( 'dashboard' );
			$this->same( true, $this->settings_submit( false, [ 'limit' => '37' ] ), 'Platform Admin can configure current Hall' );
			$this->error( 'bc_electronic_settings_denied', $this->settings_submit( true ), 'network writer unavailable in Hall admin context' );
			set_current_screen( 'dashboard-network' );
			$this->error( 'bc_electronic_settings_denied', $this->settings_submit( false ), 'Hall writer unavailable in Network Admin context' );
			switch_to_blog( get_main_site_id() ); set_current_screen( 'dashboard' );
			try { $this->error( 'bc_electronic_settings_denied', $this->settings_submit( false ), 'main site is not a Hall' ); }
			finally { restore_current_blog(); }
		} finally {
			$GLOBALS['current_screen'] = $old_screen; $_SERVER['REQUEST_METHOD'] = $old_method; $_POST = $old_post; wp_set_current_user( 0 );
		}
	}

	private function settings_grant( int $site, int $user, string $role ): void {
		global $wpdb;
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, 'bc_faces_test_owner' )
			|| ! in_array( $user, $this->users, true ) || $this->token !== get_user_meta( $user, 'bc_faces_test_owner', true ) ) { throw new RuntimeException( 'Settings role fixture ownership refused.' ); }
		// Same owned-user setup as package-addons-admin.php; do not disable the staff writer guard.
		$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $key ] );
		if ( 1 !== $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] ) ) { throw new RuntimeException( 'Settings role fixture failed.' ); }
		clean_user_cache( $user );
	}

	private function settings_nonce( bool $network ): string {
		return wp_create_nonce( 'bingocommerce_electronic_limit:' . ( $network ? 'network:' . get_current_network_id() : 'hall:' . get_current_blog_id() ) );
	}
	private function settings_form_save( bool $network, string $action, ?string $value = null ): string {
		$dom = new DOMDocument(); @$dom->loadHTML( $this->settings_screen( $network ), LIBXML_NONET );
		$forms = ( new DOMXPath( $dom ) )->query( '//form[input[@name="bc_limit_action" and @value="' . $action . '"]]' );
		$this->same( 1, $forms->length, 'native settings screen renders the expected form' );
		if ( 1 !== $forms->length ) { throw new RuntimeException( 'Settings form missing.' ); }
		$posted = [];
		foreach ( $forms->item( 0 )->getElementsByTagName( 'input' ) as $input ) {
			if ( $input->hasAttribute( 'name' ) ) { $posted[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' ); }
		}
		if ( null !== $value ) { $posted['limit'] = $value; }
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = wp_slash( $posted ); ob_start();
		try { $network ? ElectronicLimitSettings::render_network() : ElectronicLimitSettings::render_hall(); $html = ob_get_contents(); }
		finally { ob_end_clean(); $_POST = []; }
		$this->ok( str_contains( $html, 'notice-success' ), 'posted native form passes nonce and reports a confirmed save' );
		$this->same( $value, $network ? get_site_option( ElectronicLimit::PLATFORM_OPTION, null ) : get_option( ElectronicLimit::HALL_OPTION, null ), 'form round trip persists exact value or removes override' );
		return $html;
	}
	private function settings_override_notice(): void {
		$platform = get_site_option( ElectronicLimit::PLATFORM_OPTION );
		$override = get_option( ElectronicLimit::HALL_OPTION );
		try {
			$this->configure( '300' );
			foreach ( [ '350', '300', '250' ] as $value ) {
				$above = '350' === $value;
				$post = $this->settings_form_save( false, 'save', $value );
				$get = $this->settings_screen( false );
				$expected = 'Your Hall override is saved as 350, but the platform maximum is 300. This Hall is currently limited to 300 digital faces per Game Type in a customer selection.';
				foreach ( [ 'after save' => $post, 'later visit' => $get ] as $when => $html ) {
					$this->same( $above ? 1 : 0, substr_count( $html, 'Your Hall override is saved as ' ), "override $value $when shows notice only above platform" );
					if ( $above ) { $this->ok( str_contains( $html, $expected ) && str_contains( $html, 'notice notice-info' ), "$when renders exact notice with actual saved and effective values" ); }
				}
				$this->same( $value, get_option( ElectronicLimit::HALL_OPTION ), 'notice rendering never clamps the saved override' );
				$this->same( min( 300, (int) $value ), ElectronicLimit::effective_limit(), 'notice leaves existing effective-limit calculation unchanged' );
			}
			foreach ( [ [ '300', null ], [ '300', 'bad' ], [ '0', '350' ], [ null, '350' ] ] as [ $maximum, $hall ] ) {
				$this->configure( $maximum, $hall );
				$this->same( false, str_contains( $this->settings_screen( false ), 'Your Hall override is saved as ' ), 'absent or invalid configuration never displays a valid-limit notice' );
			}
		} finally { $this->configure( $platform, $override ); }
	}
	private function settings_submit( bool $network, array $values = [], string $method = 'POST' ): mixed {
		$_SERVER['REQUEST_METHOD'] = $method;
		return ElectronicLimitSettings::handle_request( array_replace( [ '_wpnonce' => $this->settings_nonce( $network ), 'bc_limit_action' => 'save', 'limit' => '12' ], $values ), $network );
	}
	private function settings_screen( bool $network ): string {
		$_SERVER['REQUEST_METHOD'] = 'GET'; ob_start();
		try { $network ? ElectronicLimitSettings::render_network() : ElectronicLimitSettings::render_hall(); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}
	private function settings_denied_screen( bool $network ): void {
		$handler = static fn() => static function () { throw new RuntimeException( 'fixture-settings-denied' ); };
		add_filter( 'wp_die_handler', $handler ); $denied = false;
		try { $this->settings_screen( $network ); }
		catch ( RuntimeException $error ) { $denied = 'fixture-settings-denied' === $error->getMessage(); }
		finally { remove_filter( 'wp_die_handler', $handler ); }
		$this->same( true, $denied, 'unauthorized settings page access denied' );
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
		foreach ( $this->users as $user ) {
			if ( $this->token !== get_user_meta( $user, 'bc_faces_test_owner', true ) ) { throw new RuntimeException( 'Settings actor cleanup ownership refused.' ); }
			foreach ( $this->sites as $site ) {
				if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_faces_test_owner' ) ) { throw new RuntimeException( 'Settings role cleanup ownership refused.' ); }
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			wpmu_delete_user( $user );
			$this->same( false, get_userdata( $user ), 'owned disposable settings actor deleted' );
			$this->same( [], get_user_meta( $user ), 'owned settings actor leaves no orphan metadata' );
		}
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
