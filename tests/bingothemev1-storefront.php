<?php

/**
 * ADR-045 / BR-084 storefront checks that need the real theme and role model.
 * BC_TEST_DB=bingocommerce_state_recon php tests/bingothemev1-storefront.php
 *
 * 1. The BingoThemev1 Store Details helper returns the CURRENT Hall's values when
 *    one request switches Hall A -> Hall B -> Hall A; social icons appear only
 *    for saved links, and contact shows the address line, then phone and email.
 * 2. Hall Managers cannot write site-wide global styles (fonts/colours), which
 *    WordPress would otherwise render over the fixed theme; reads and the rest of
 *    edit_theme_options (menus, Customizer logo) stay available. The unavailable
 *    Appearance -> Fonts entry is removed for them and kept for Super Admins.
 * Disposable token-owned Halls and actors only; persistent fixture Halls are compared byte-for-byte.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
// The theme's own bootstrap: Template and Theme exactly as the storefront loads them.
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';

use BingoCommerce\BingoThemeV1\Template;
use BingoCommerce\Core\StoreDetails;

final class BingoThemeV1StorefrontTest {
	private const THEME = 'bingocommerce-bingothemev1';
	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		try {
			$a = $this->hall( 'a' ); $b = $this->hall( 'b' );
			$this->details_follow_site_switches( $a, $b );
			$this->contact_and_social_output( $a, $b );
			$this->global_styles_are_platform_only( $a, $b );
			$this->fonts_menu_only_where_saving_is_allowed( $a );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "BingoThemev1 storefront: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	private function details_follow_site_switches( int $a, int $b ): void {
		wp_set_current_user( $this->admin );
		$alpha = array_replace( StoreDetails::blank(), [ 'address_line_1' => '1 Alpha Street', 'city' => 'Alpha City', 'phone' => '+1 (813) 555-0101', 'email' => 'alpha@example.com' ] );
		$beta  = array_replace( StoreDetails::blank(), [ 'address_line_1' => '2 Beta Avenue', 'city' => 'Beta City', 'facebook_url' => 'https://www.facebook.com/beta' ] );
		switch_to_blog( $a ); try { $this->same( true, StoreDetails::save( $alpha ), 'Hall A details saved' ); } finally { restore_current_blog(); }
		switch_to_blog( $b ); try { $this->same( true, StoreDetails::save( $beta ), 'Hall B details saved' ); } finally { restore_current_blog(); }

		// One request, three switches: the theme helper must follow the current site every time.
		foreach ( [ [ $a, 'A', $alpha ], [ $b, 'B', $beta ], [ $a, 'A again', $alpha ] ] as [ $site, $label, $expected ] ) {
			switch_to_blog( $site );
			try {
				$details = Template::details();
				$this->same( $expected['city'], $details['city'], "theme helper shows Hall {$label} city" );
				$this->same( $expected['address_line_1'], $details['address_line_1'], "theme helper shows Hall {$label} address" );
				$this->same( $expected['facebook_url'], $details['facebook_url'], "theme helper shows Hall {$label} social link" );
				$this->same( StoreDetails::tel_uri( $expected['phone'] ), $details['phone_href'], "theme helper shows Hall {$label} tel link" );
				$this->same( array_values( array_filter( [ $expected['address_line_1'], $expected['city'] ] ) ), Template::address_lines(), "address lines follow Hall {$label}" );
			} finally { restore_current_blog(); }
		}
		switch_to_blog( get_main_site_id() );
		try { $this->same( '', Template::details()['city'], 'theme helper is blank on the main network site after Hall reads' ); }
		finally { restore_current_blog(); }
	}

	private function contact_and_social_output( int $a, int $b ): void {
		$render = static function ( callable $callback ): string { ob_start(); try { $callback(); return (string) ob_get_contents(); } finally { ob_end_clean(); } };
		switch_to_blog( $a );
		try {
			// Hall A: address, phone and email; no social links saved.
			$this->same( '', $render( [ Template::class, 'social_links' ] ), 'no social links saved: no icons and no empty list' );
			$html = $render( [ Template::class, 'contact' ] );
			$this->ok( str_contains( $html, '<address class="visit-contact__row visit-contact__address">' ), 'address has its own row' );
			$this->ok( str_contains( $html, '<span>1 Alpha Street, Alpha City</span>' ), 'address parts joined into one line' );
			$reach = strpos( $html, '<p class="visit-contact__row visit-contact__reach">' );
			$this->ok( false !== $reach && $reach > strpos( $html, 'visit-contact__address' ), 'phone and email row follows the address row' );
			$this->ok( false !== $reach && strpos( $html, 'tel:+18135550101', $reach ) < strpos( $html, 'mailto:alpha@example.com', $reach ), 'phone then email on the same row' );
			$this->same( 1, substr_count( $html, 'visit-contact__reach' ), 'phone and email share one row' );
		} finally { restore_current_blog(); }
		switch_to_blog( $b );
		try {
			// Hall B: address and one social link; no phone or email.
			$social = $render( [ Template::class, 'social_links' ] );
			$this->same( 1, substr_count( $social, '<li>' ), 'only the saved social network is shown' );
			$this->ok( str_contains( $social, 'href="https://www.facebook.com/beta"' ) && str_contains( $social, 'aria-label="Facebook"' ), 'saved Facebook link rendered' );
			$this->ok( ! str_contains( $social, 'href="#"' ) && ! str_contains( $social, 'aria-disabled' ), 'no placeholder # links' );
			$html = $render( [ Template::class, 'contact' ] );
			$this->ok( str_contains( $html, '2 Beta Avenue, Beta City' ) && ! str_contains( $html, 'visit-contact__reach' ), 'no phone/email row when neither is saved' );
		} finally { restore_current_blog(); }
		switch_to_blog( get_main_site_id() );
		try {
			$this->same( '', $render( [ Template::class, 'contact' ] ) . $render( [ Template::class, 'social_links' ] ), 'nothing rendered where no Store Details exist' );
		} finally { restore_current_blog(); }
	}

	private function global_styles_are_platform_only( int $a, int $b ): void {
		$manager = $this->actor( 'manager', [ $a => 'bc_hall_manager' ] );
		switch_to_blog( $a );
		try {
			switch_theme( self::THEME );
			wp_clean_theme_json_cache();
			WP_Theme_JSON_Resolver::clean_cached_data();
			wp_set_current_user( $this->admin );
			$id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			$this->ok( $id > 0, 'Hall user global styles record exists for BingoThemev1' );
			$before = get_post_field( 'post_content', $id );

			wp_set_current_user( $manager );
			$this->same( true, current_user_can( 'edit_theme_options' ), 'Hall Manager keeps edit_theme_options (Menus screen capability)' );
			$this->same( true, current_user_can( 'customize' ), 'Hall Manager keeps the Customizer (logo)' );
			$page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture page', 'post_author' => $manager ] );
			$this->same( true, current_user_can( 'edit_post', $page ), 'Hall Manager keeps ordinary page editing' );
			$this->same( false, current_user_can( 'edit_post', $id ), 'Hall Manager cannot edit Hall global styles' );
			$this->same( false, current_user_can( 'delete_post', $id ), 'Hall Manager cannot delete Hall global styles' );
			$this->same( true, current_user_can( 'read_post', $id ), 'Hall Manager can still read Hall global styles' );

			$styles = [ 'styles' => [ 'typography' => [ 'fontFamily' => 'FixtureBodyFont, serif' ], 'color' => [ 'background' => '#ff00ff' ], 'elements' => [ 'heading' => [ 'typography' => [ 'fontFamily' => 'FixtureHeadingFont, serif' ] ] ] ] ];
			$put = $this->rest( 'PUT', '/wp/v2/global-styles/' . $id, $styles );
			$this->same( 403, $put->get_status(), 'REST global-styles update refused for Hall Manager' );
			$fonts = $this->rest( 'PUT', '/wp/v2/global-styles/' . $id, [ 'settings' => [ 'typography' => [ 'fontFamilies' => [ 'custom' => [ [ 'slug' => 'fixture', 'name' => 'Fixture', 'fontFamily' => 'Fixture' ] ] ] ] ] ] );
			$this->same( 403, $fonts->get_status(), 'Font activation (global-styles settings) refused for Hall Manager' );
			$this->same( 200, $this->rest( 'GET', '/wp/v2/global-styles/' . $id, [], [ 'context' => 'view' ] )->get_status(), 'REST global-styles read still allowed for Hall Manager' );
			$this->same( $before, get_post_field( 'post_content', $id ), 'refused writes leave Hall global styles unchanged' );
			wp_clean_theme_json_cache(); WP_Theme_JSON_Resolver::clean_cached_data();
			$this->ok( ! str_contains( wp_get_global_stylesheet(), 'FixtureBodyFont' ), 'storefront stylesheet has no Manager font override' );

			wp_set_current_user( $this->admin );
			$super = $this->rest( 'PUT', '/wp/v2/global-styles/' . $id, $styles );
			$this->same( 200, $super->get_status(), 'Super Admin (Platform Admin) keeps global-styles authority' );
			wp_clean_theme_json_cache(); WP_Theme_JSON_Resolver::clean_cached_data();
			$this->ok( str_contains( wp_get_global_stylesheet(), 'FixtureBodyFont' ), 'saved global styles do render on BingoThemev1 (why writes are restricted)' );
			wp_delete_post( $page, true );
		} finally { restore_current_blog(); }

		// A Manager of Hall A has no Hall B role at all; Game Operator is still denied too.
		$operator = $this->actor( 'operator', [ $b => 'bc_game_operator' ] );
		switch_to_blog( $b );
		try {
			switch_theme( self::THEME );
			wp_set_current_user( $this->admin );
			$b_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			wp_set_current_user( $operator );
			$this->same( false, current_user_can( 'edit_post', $b_id ), 'Game Operator cannot edit Hall global styles' );
			wp_set_current_user( $manager );
			$this->same( false, current_user_can( 'edit_post', $b_id ), 'Hall A Manager cannot edit Hall B global styles' );
		} finally { restore_current_blog(); }
	}

	private function fonts_menu_only_where_saving_is_allowed( int $a ): void {
		global $submenu;
		// Drift guard: the exact registration this relies on (WordPress 7.0.3, wp-admin/menu.php).
		$this->ok( str_contains( (string) file_get_contents( ABSPATH . 'wp-admin/menu.php' ), "\$submenu['themes.php'][9] = array( __( 'Fonts' ), 'edit_theme_options', 'font-library.php' );" ), 'WordPress still registers Fonts as themes.php / font-library.php' );
		$this->same( 999, has_action( 'admin_menu', [ \BingoCommerce\Core\StorefrontStyleGuard::class, 'hide_fonts_menu' ] ), 'Fonts removal runs late on admin_menu' );
		$manager = $this->actor( 'fonts', [ $a => 'bc_hall_manager' ] );
		$appearance = static fn() => [
			5  => [ 'Themes', 'edit_theme_options', 'themes.php' ],
			6  => [ 'Patterns', 'edit_theme_options', 'site-editor.php?p=/pattern' ],
			7  => [ 'Customize', 'customize', 'customize.php' ],
			9  => [ 'Fonts', 'edit_theme_options', 'font-library.php' ],
			10 => [ 'Menus', 'edit_theme_options', 'nav-menus.php' ],
			11 => [ 'Store Details', 'bc_manage_hall_settings', 'bingocommerce-store-details' ],
		];
		$slugs = static function (): array { global $submenu; return array_column( $submenu['themes.php'], 2 ); };
		$saved = $submenu;
		switch_to_blog( $a );
		try {
			wp_set_current_user( $manager );
			$submenu = [ 'themes.php' => $appearance() ];
			\BingoCommerce\Core\StorefrontStyleGuard::hide_fonts_menu();
			$this->same( [ 'themes.php', 'site-editor.php?p=/pattern', 'customize.php', 'nav-menus.php', 'bingocommerce-store-details' ], array_values( $slugs() ), 'Hall Manager: Fonts removed; Themes, Customize (logo), Menus and Store Details kept' );
			$this->same( true, current_user_can( 'edit_theme_options' ) && current_user_can( 'customize' ) && current_user_can( 'bc_manage_hall_settings' ), 'Hall Manager keeps logo, menu and Store Details capabilities' );
			wp_set_current_user( $this->admin );
			$submenu = [ 'themes.php' => $appearance() ];
			\BingoCommerce\Core\StorefrontStyleGuard::hide_fonts_menu();
			$this->ok( in_array( 'font-library.php', $slugs(), true ), 'Super Admin (Platform Admin) keeps Appearance -> Fonts on a Hall' );
		} finally { restore_current_blog(); $submenu = $saved; }
		switch_to_blog( get_main_site_id() );
		try { $this->same( false, \BingoCommerce\Core\StorefrontStyleGuard::is_restricted( $manager ), 'main network site is outside the Hall restriction' ); }
		finally { restore_current_blog(); }
	}

	/* ------------------------------------------------------------------ helpers */

	private function rest( string $method, string $route, array $body = [], array $query = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $query as $key => $value ) { $request->set_param( $key, $value ); }
		if ( $body ) { $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); }
		return rest_ensure_response( rest_do_request( $request ) );
	}

	private function hall( string $label ): int {
		wp_set_current_user( $this->admin ); add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'bingothemev1-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable BingoThemev1 Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site;
		update_blog_option( $site, 'bc_bingothemev1_test_owner', $this->token );
		switch_to_blog( $site );
		try { WC_Install::create_tables(); WC_Install::create_roles(); \BingoCommerce\Core\Roles::sync_current_site(); }
		finally { restore_current_blog(); }
		return $site;
	}

	/** @param array<int,string> $roles site => role */
	private function actor( string $label, array $roles ): int {
		$id = wp_insert_user( [ 'user_login' => 'bingothemev1-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Actor creation failed.' ); }
		$this->users[] = $id; update_user_meta( $id, 'bc_bingothemev1_test_owner', $this->token );
		global $wpdb;
		foreach ( $roles as $site => $role ) {
			if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, 'bc_bingothemev1_test_owner' ) ) { throw new RuntimeException( 'Role fixture ownership refused.' ); }
			// Same owned-user setup as the Electronic Limits settings suite; the staff writer guard stays enabled.
			$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
			$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key ] );
			if ( 1 !== $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] ) ) { throw new RuntimeException( 'Role fixture failed.' ); }
		}
		clean_user_cache( $id );
		return $id;
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
			if ( $this->token !== get_user_meta( $user, 'bc_bingothemev1_test_owner', true ) ) { throw new RuntimeException( 'Actor cleanup ownership refused.' ); }
			foreach ( $this->sites as $site ) {
				if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_bingothemev1_test_owner' ) ) { throw new RuntimeException( 'Role cleanup ownership refused.' ); }
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			wpmu_delete_user( $user );
			$this->same( false, get_userdata( $user ), 'owned disposable actor deleted' );
		}
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_bingothemev1_test_owner' ) ) { throw new RuntimeException( 'Hall cleanup ownership refused.' ); }
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

	private function same( mixed $expected, mixed $actual, string $label ): void {
		$this->ok( $expected === $actual, $label . ( $expected === $actual ? '' : ' expected=' . wp_json_encode( $expected ) . ' actual=' . wp_json_encode( $actual ) ) );
	}
	private function ok( bool $condition, string $label ): void {
		if ( $condition ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	}
}
( new BingoThemeV1StorefrontTest() )->run();
