<?php

/**
 * ADR-045 / BR-084 Hall public storefront profile (Store Details).
 * BC_TEST_DB=bingocommerce_state_recon php tests/store-details.php
 * Disposable token-owned Halls and actors only; persistent fixture Halls are compared byte-for-byte.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use BingoCommerce\Core\StoreDetails;
use BingoCommerce\Core\StoreDetailsAdmin;

final class StoreDetailsTest {
	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	private const VALID = [
		'address_line_1' => '5411 W Cypress Creek Rd',
		'address_line_2' => 'Suite 2',
		'city'           => 'Sunrise',
		'state'          => 'FL',
		'postal_code'    => '33309',
		'country'        => 'United States',
		'phone'          => '+1 (813) 555-0142',
		'email'          => 'hello@example.com',
		'facebook_url'   => 'https://www.facebook.com/examplehall',
		'instagram_url'  => 'https://www.instagram.com/examplehall/',
		'youtube_url'    => 'https://www.youtube.com/@examplehall',
		'tiktok_url'     => 'https://www.tiktok.com/@examplehall?lang=en&x=1',
	];

	public function run(): void {
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( 'Native denial: ' . wp_strip_all_tags( (string) $message ) ); } );
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		$old_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$old_post = $_POST;
		try {
			$this->registration_and_main_site();
			$a = $this->hall( 'a' ); $b = $this->hall( 'b' );
			$actors = $this->actors( $a );
			switch_to_blog( $a );
			try {
				set_current_screen( 'dashboard' );
				$this->tel_links();
				$this->menu_and_screen( $actors );
				$this->saves( $actors );
				$this->validation( $actors );
				$this->requests( $actors );
				$this->authorization( $actors );
				$this->malformed_storage( $actors );
				$this->escaping( $actors );
			} finally { restore_current_blog(); }
			$this->isolation( $a, $b, $actors );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			$_SERVER['REQUEST_METHOD'] = $old_method; $_POST = $old_post;
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Store Details: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	private function registration_and_main_site(): void {
		$this->same( 10, has_filter( StoreDetails::FILTER, [ StoreDetails::class, 'provide' ] ), 'read filter registered' );
		$this->same( 10, has_action( 'admin_menu', [ StoreDetailsAdmin::class, 'menu' ] ), 'admin menu registered' );
		switch_to_blog( get_main_site_id() );
		try { $this->same( [], apply_filters( StoreDetails::FILTER, [] ), 'main network site has no Hall profile' ); }
		finally { restore_current_blog(); }
	}

	private function tel_links(): void {
		$this->same( 'tel:+18135550142', StoreDetails::tel_uri( '+1 (813) 555-0142' ), 'tel link keeps leading +' );
		$this->same( 'tel:+442079460958', StoreDetails::tel_uri( '+44 20 7946 0958' ), 'international number keeps leading +' );
		$this->same( 'tel:8135550142', StoreDetails::tel_uri( '(813) 555-0142' ), 'number without + gets digits only' );
		$this->same( 'tel:8135550142', StoreDetails::tel_uri( '813.555.0142' ), 'dotted number gets digits only' );
		$this->same( '', StoreDetails::tel_uri( '' ), 'blank phone has no link' );
		$this->same( '', StoreDetails::tel_uri( '1+2345678' ), 'invalid phone has no link' );
	}

	private function menu_and_screen( array $actors ): void {
		wp_set_current_user( $actors['manager'] );
		$this->same( true, current_user_can( 'bc_manage_hall_settings' ), 'Hall Manager has existing Hall settings capability' );
		StoreDetailsAdmin::menu();
		$this->same( 10, has_action( get_plugin_page_hookname( StoreDetailsAdmin::PAGE, 'themes.php' ), [ StoreDetailsAdmin::class, 'render' ] ), 'Appearance submenu routes to guarded screen' );
		$this->same( false, get_option( StoreDetails::OPTION, false ), 'no profile exists before first save' );
		$blank = apply_filters( StoreDetails::FILTER, [] );
		$this->same( array_merge( StoreDetails::blank(), [ 'phone_href' => '' ] ), $blank, 'unsaved Hall renders a blank profile' );
		$html = $this->screen();
		$this->ok( str_contains( $html, '<form method="post" novalidate>' ), 'screen renders the form' );
		foreach ( array_keys( StoreDetails::FIELDS ) as $key ) {
			$this->ok( str_contains( $html, 'name="bc_store_details[' . $key . ']"' ), 'form has field ' . $key );
		}
		$this->ok( str_contains( $html, 'separate from the WooCommerce store address' ), 'screen explains separation from WooCommerce address and Portal' );
		$this->same( false, get_option( StoreDetails::OPTION, false ), 'GET creates no profile' );
	}

	private function saves( array $actors ): void {
		global $wpdb;
		wp_set_current_user( $actors['manager'] );
		update_option( 'woocommerce_store_address', 'WooCommerce Business Street 1' );
		update_option( 'woocommerce_store_city', 'Tax City' );
		update_option( 'blogname', 'Portal Hall Name' );
		$others = static fn() => $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name <> %s ORDER BY option_name', $wpdb->options, StoreDetails::OPTION ), ARRAY_A );
		$before = $others();

		$this->same( true, $this->submit( self::VALID )['result'], 'Hall Manager saves full profile' );
		$this->same( self::VALID, get_option( StoreDetails::OPTION ), 'full profile stored exactly' );
		$this->same( array_merge( self::VALID, [ 'phone_href' => 'tel:+18135550142' ] ), apply_filters( StoreDetails::FILTER, [] ), 'storefront filter returns saved profile and tel link' );
		$this->same( $before, $others(), 'saving changes no other option: WooCommerce address, blogname and all else untouched' );
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, StoreDetails::OPTION ) );
		$this->ok( in_array( $autoload, [ 'yes', 'on' ], true ), 'profile is autoloaded for storefront pages' );
		$this->same( true, $this->submit( self::VALID )['result'], 'unchanged resubmission succeeds' );

		$this->same( true, $this->submit( StoreDetails::blank() )['result'], 'all-blank profile saves' );
		$this->same( StoreDetails::blank(), get_option( StoreDetails::OPTION ), 'all-blank profile stored' );

		$partial = array_replace( StoreDetails::blank(), [ 'city' => '  Tampa  ', 'instagram_url' => 'https://instagram.com/hall', 'phone' => '(813) 555-0142' ] );
		$this->same( true, $this->submit( $partial )['result'], 'partial profile with optional blanks saves' );
		$stored = get_option( StoreDetails::OPTION );
		$this->same( 'Tampa', $stored['city'], 'surrounding whitespace trimmed' );
		$this->same( '', $stored['facebook_url'], 'blank social field stays blank' );
		$this->same( 'tel:8135550142', apply_filters( StoreDetails::FILTER, [] )['phone_href'], 'number without + links digits only' );
		$this->same( $before, $others(), 'repeated saves still change no other option' );
	}

	private function validation( array $actors ): void {
		wp_set_current_user( $actors['manager'] );
		$this->same( true, $this->submit( self::VALID )['result'], 'reset to valid profile' );
		$invalid = [
			'address_line_1' => [ '<b>Main</b>', "Line\nbreak", "Tab\there", str_repeat( 'a', 101 ), "\x80\x81" ],
			'city'           => [ 'City>', "Nu\0l" ],
			'phone'          => [ '12345', '+1 555 0142 abc', '1+2345678', str_repeat( '1', 16 ), '++18135550142', 'call me', '+1 (813) 555-0142 ext 2' ],
			'email'          => [ 'not-an-email', 'a@b', 'x@example.com<script>', 'two@@example.com', str_repeat( 'a', 90 ) . '@example.com' ],
			'facebook_url'   => [ 'facebook.com/hall', 'javascript:alert(1)', 'ftp://example.com', 'https://', 'https://exa mple.com', 'https://user:pass@example.com/', '#', 'https://example.com/"onmouseover=x', '//example.com/hall', 'https://example.com/' . str_repeat( 'a', 2040 ) ],
			'tiktok_url'     => [ 'data:text/html,hi', 'mailto:a@example.com', "https://example.com/\npath" ],
		];
		foreach ( $invalid as $key => $values ) {
			foreach ( $values as $value ) {
				$outcome = $this->submit( array_replace( self::VALID, [ $key => $value ] ) );
				$this->error( 'bc_store_details_value', $outcome['result'], "invalid {$key} rejected: " . wp_json_encode( $value ) );
				$this->ok( isset( $outcome['errors'][ $key ] ) && 1 === count( $outcome['errors'] ), "only {$key} carries a field error" );
				$this->same( self::VALID, get_option( StoreDetails::OPTION ), 'rejected submission saves nothing' );
			}
		}
		$two = $this->submit( array_replace( self::VALID, [ 'email' => 'bad', 'youtube_url' => 'youtube.com' ] ) );
		$this->same( [ 'email', 'youtube_url' ], array_keys( $two['errors'] ), 'every invalid field is reported together' );
		$this->same( 'bad', $two['values']['email'], 'rejected values are returned for re-display' );
		$non_string = $this->submit( array_replace( self::VALID, [ 'phone' => [ '8135550142' ] ] ) );
		$this->error( 'bc_store_details_value', $non_string['result'], 'non-string field value rejected' );
		$this->same( self::VALID, get_option( StoreDetails::OPTION ), 'non-string submission saves nothing' );

		$html = $this->screen( 'POST', $this->payload( array_replace( self::VALID, [ 'email' => 'not-an-email' ] ) ) );
		$this->ok( str_contains( $html, 'Some Store Details need correcting. Nothing was saved.' ), 'error summary shown' );
		$this->ok( str_contains( $html, 'aria-invalid="true" aria-describedby="bc-store-email-error"' ), 'invalid field marked for assistive technology' );
		$this->ok( str_contains( $html, 'value="not-an-email"' ), 'rejected value re-displayed for correction' );
		$this->ok( str_contains( $html, 'href="#bc-store-email"' ), 'error summary links to the field' );
		$html = $this->screen( 'POST', $this->payload( self::VALID ) );
		$this->ok( str_contains( $html, 'Store Details saved.' ), 'success notice shown' );
	}

	private function requests( array $actors ): void {
		wp_set_current_user( $actors['manager'] );
		foreach ( [ '', 'invalid', [] ] as $nonce ) {
			$this->error( 'bc_store_details_nonce', $this->submit( self::VALID, [ '_wpnonce' => $nonce ] )['result'], 'missing/invalid nonce refused' );
		}
		$this->error( 'bc_store_details_request', $this->submit( self::VALID, [], 'GET' )['result'], 'GET cannot save' );
		$this->error( 'bc_store_details_request', $this->submit( self::VALID, [], 'PUT' )['result'], 'non-POST cannot save' );
		foreach ( [ 'site_id', 'blog_id', 'hall_id', 'network_id', 'blogname', 'woocommerce_store_address' ] as $key ) {
			$this->error( 'bc_store_details_request', $this->submit( self::VALID, [ $key => '1' ] )['result'], 'unexpected request key rejected: ' . $key );
		}
		$missing = self::VALID; unset( $missing['tiktok_url'] );
		$this->error( 'bc_store_details_request', $this->submit( $missing )['result'], 'missing field rejected rather than cleared' );
		$this->error( 'bc_store_details_request', $this->submit( array_merge( self::VALID, [ 'portal_hall_id' => '9' ] ) )['result'], 'unknown profile field rejected' );
		$this->error( 'bc_store_details_request', $this->submit( self::VALID, [ 'bc_store_details' => 'text' ] )['result'], 'non-array profile rejected' );
		$this->same( self::VALID, get_option( StoreDetails::OPTION ), 'refused requests save nothing' );
	}

	private function authorization( array $actors ): void {
		wp_set_current_user( $this->admin );
		$this->same( true, $this->submit( self::VALID )['result'], 'Super Admin may save' );
		foreach ( [ 0, $actors['operator'], $actors['customer'], $actors['wp_admin'] ] as $actor ) {
			wp_set_current_user( $actor );
			$changed = array_replace( self::VALID, [ 'city' => 'Changed by ' . $actor ] );
			$this->error( 'bc_store_details_denied', $this->submit( $changed )['result'], 'role without Hall settings capability denied: ' . $actor );
			$this->same( self::VALID, get_option( StoreDetails::OPTION ), 'denied save preserves profile' );
			$denied = false;
			try { $this->screen(); } catch ( RuntimeException $e ) { $denied = str_starts_with( $e->getMessage(), 'Native denial' ); }
			$this->same( true, $denied, 'screen access denied: ' . $actor );
		}
	}

	private function malformed_storage( array $actors ): void {
		wp_set_current_user( $actors['manager'] );
		update_option( StoreDetails::OPTION, [ 'city' => 'Only city' ] );
		$this->error( 'bc_store_details_storage', StoreDetails::stored(), 'wrong-shape storage reported, not repaired' );
		$this->same( array_merge( StoreDetails::blank(), [ 'phone_href' => '' ] ), apply_filters( StoreDetails::FILTER, [] ), 'storefront renders blank for unreadable storage' );
		$this->same( [ 'city' => 'Only city' ], get_option( StoreDetails::OPTION ), 'reading does not rewrite storage' );
		$this->ok( str_contains( $this->screen(), 'could not be read' ), 'screen warns about unreadable storage' );
		update_option( StoreDetails::OPTION, array_replace( self::VALID, [ 'phone' => 42 ] ) );
		$this->error( 'bc_store_details_storage', StoreDetails::stored(), 'non-string stored value reported' );
		$this->same( true, $this->submit( self::VALID )['result'], 'valid save replaces unreadable storage' );
		$this->same( self::VALID, StoreDetails::stored(), 'profile readable again' );
	}

	private function escaping( array $actors ): void {
		wp_set_current_user( $actors['manager'] );
		$quoted = array_replace( self::VALID, [ 'address_line_1' => 'O\'Brien & Sons "Hall"' ] );
		$this->same( true, $this->submit( $quoted )['result'], 'quotes and ampersand are plain text' );
		$html = $this->screen();
		$this->ok( str_contains( $html, 'value="O&#039;Brien &amp; Sons &quot;Hall&quot;"' ), 'admin form escapes saved value' );
		$this->same( 'O\'Brien & Sons "Hall"', apply_filters( StoreDetails::FILTER, [] )['address_line_1'], 'filter returns the raw value for the theme to escape' );
		$this->same( true, $this->submit( self::VALID )['result'], 'restore valid profile' );
	}

	private function isolation( int $a, int $b, array $actors ): void {
		$b_before = $this->snapshot( $b );
		wp_set_current_user( $actors['manager'] );
		switch_to_blog( $a );
		try {
			$this->same( true, $this->submit( array_replace( self::VALID, [ 'city' => 'Hall A only' ] ) )['result'], 'Hall A save' );
			$nonce_a = wp_create_nonce( 'bingocommerce_store_details:hall:' . $a );
		} finally { restore_current_blog(); }
		$this->same( $b_before, $this->snapshot( $b ), 'Hall A save leaves every Hall B table byte-identical' );
		$this->same( false, get_blog_option( $b, StoreDetails::OPTION, false ), 'Hall B has no profile' );
		switch_to_blog( $b );
		try {
			set_current_screen( 'dashboard' );
			$this->error( 'bc_store_details_denied', $this->submit( self::VALID )['result'], 'Hall A Manager has no Hall B authority' );
			$this->same( array_merge( StoreDetails::blank(), [ 'phone_href' => '' ] ), apply_filters( StoreDetails::FILTER, [] ), 'Hall B storefront still blank' );
		} finally { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		$this->grant( $b, $actors['manager'], 'bc_hall_manager' );
		wp_set_current_user( $actors['manager'] );
		switch_to_blog( $b );
		try {
			$this->error( 'bc_store_details_nonce', $this->submit( self::VALID, [ '_wpnonce' => $nonce_a ] )['result'], 'multi-Hall Manager cannot reuse Hall A nonce in Hall B' );
			$this->same( true, $this->submit( array_replace( self::VALID, [ 'city' => 'Hall B only' ] ) )['result'], 'fresh Hall B form saves only Hall B' );
			$this->same( 'Hall B only', apply_filters( StoreDetails::FILTER, [] )['city'], 'Hall B storefront reads Hall B profile' );
		} finally { restore_current_blog(); }
		$this->same( 'Hall A only', get_blog_option( $a, StoreDetails::OPTION )['city'], 'Hall A profile untouched by Hall B save' );
	}

	/* ------------------------------------------------------------------ helpers */

	private function payload( array $fields, array $overrides = [] ): array {
		return array_replace( [ '_wpnonce' => wp_create_nonce( 'bingocommerce_store_details:hall:' . get_current_blog_id() ), 'bc_store_details' => $fields ], $overrides );
	}

	private function submit( array $fields, array $overrides = [], string $method = 'POST' ): array {
		$_SERVER['REQUEST_METHOD'] = $method;
		return StoreDetailsAdmin::handle_request( $this->payload( $fields, $overrides ) );
	}

	private function screen( string $method = 'GET', array $post = [] ): string {
		$_SERVER['REQUEST_METHOD'] = $method; $_POST = wp_slash( $post ); ob_start();
		try { StoreDetailsAdmin::render(); return (string) ob_get_contents(); }
		finally { ob_end_clean(); $_POST = []; }
	}

	private function hall( string $label ): int {
		wp_set_current_user( $this->admin ); add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'store-details-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Store Details Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site;
		update_blog_option( $site, 'bc_store_details_test_owner', $this->token );
		return $site;
	}

	private function actors( int $site ): array {
		$actors = [];
		foreach ( [ 'manager' => 'bc_hall_manager', 'operator' => 'bc_game_operator', 'customer' => 'subscriber', 'wp_admin' => 'administrator' ] as $label => $role ) {
			$id = wp_insert_user( [ 'user_login' => 'store-details-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Actor creation failed.' ); }
			$this->users[] = $id; $actors[ $label ] = $id; update_user_meta( $id, 'bc_store_details_test_owner', $this->token );
			$this->grant( $site, $id, $role );
		}
		return $actors;
	}

	private function grant( int $site, int $user, string $role ): void {
		global $wpdb;
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, 'bc_store_details_test_owner' )
			|| ! in_array( $user, $this->users, true ) || $this->token !== get_user_meta( $user, 'bc_store_details_test_owner', true ) ) { throw new RuntimeException( 'Role fixture ownership refused.' ); }
		// Same owned-user setup as the Electronic Limits settings suite; the staff writer guard stays enabled.
		$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $key ] );
		if ( 1 !== $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] ) ) { throw new RuntimeException( 'Role fixture failed.' ); }
		clean_user_cache( $user );
		wp_set_current_user( get_current_user_id() );
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
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( $this->admin );
		foreach ( $this->users as $user ) {
			if ( $this->token !== get_user_meta( $user, 'bc_store_details_test_owner', true ) ) { throw new RuntimeException( 'Actor cleanup ownership refused.' ); }
			foreach ( $this->sites as $site ) {
				if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_store_details_test_owner' ) ) { throw new RuntimeException( 'Role cleanup ownership refused.' ); }
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			wpmu_delete_user( $user );
			$this->same( false, get_userdata( $user ), 'owned disposable actor deleted' );
		}
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_store_details_test_owner' ) ) { throw new RuntimeException( 'Hall cleanup ownership refused.' ); }
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Owned table discovery failed.' ); }
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$this->same( null, get_site( $site ), 'owned disposable Hall deleted' );
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
( new StoreDetailsTest() )->run();
