<?php
/**
 * Homepage slider: real WordPress/Core/Media/role boundary, disposable fixtures only.
 * BC_TEST_DB=bingocommerce_state_recon php tests/homepage-slider.php
 * Optional BC_SLIDER_PREVIEW_DIR: export static rendered pages before owned fixtures are removed.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
$_SERVER['SERVER_NAME'] = BC_FIXTURE_DOMAIN;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';

use BingoCommerce\Core\HomepageSlider as Slider;
use BingoCommerce\Core\HomepageAdmin as Admin;

final class HomepageSliderTest {
	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	public function run(): void {
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( 'Native denial: ' . wp_strip_all_tags( (string) $message ) ); } );
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		$old_post = $_POST;
		$old_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		try {
			$this->same( 10, has_filter( Slider::FILTER, [ Slider::class, 'provide' ] ), 'Core read filter registered' );
			$this->same( 10, has_action( 'admin_menu', [ Admin::class, 'menu' ] ), 'Core administration registered' );
			$this->same( [], Slider::provide(), 'main site has no slider' );
			$a = $this->hall( 'a' ); $b = $this->hall( 'b' );
			$actors = $this->actors( $a );
			foreach ( [ $a, $b ] as $site ) {
				switch_to_blog( $site );
				try { WC_Install::create_tables(); WC_Install::create_roles(); \BingoCommerce\Core\Roles::sync_current_site(); }
				finally { restore_current_blog(); }
			}
			switch_to_blog( $a );
			try {
				set_current_screen( 'dashboard' );
				wp_set_current_user( $actors['manager'] );
				$this->same( true, current_user_can( 'upload_files' ), 'Hall Manager keeps native Media Library capability' );
				Admin::menu();
				$this->same( 10, has_action( get_plugin_page_hookname( Admin::PAGE, 'themes.php' ), [ Admin::class, 'render' ] ), 'Appearance -> Homepage routes to guarded screen' );
				$this->same( false, get_option( Slider::OPTION, false ), 'new Hall has no seeded configuration' );
				$this->same( [], Slider::provide(), 'new Hall provides no slides' );
				$this->ok( str_contains( $this->screen(), 'Homepage slider' ), 'screen renders only slider editor' );
				$this->same( false, get_option( Slider::OPTION, false ), 'GET does not create option' );
				$id = $this->attachment( 900001, 'hall-a' );
				$valid = $this->config( $id );
				$this->saves( $valid );
				$this->validation( $valid );
				$this->requests( $valid );
				$this->authorization( $valid, $actors );
				wp_set_current_user( $actors['manager'] );
				$this->media_and_rendering( $valid );
			} finally { restore_current_blog(); }
			$this->isolation( $a, $b, $actors, $id );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			$_POST = $old_post; $_SERVER['REQUEST_METHOD'] = $old_method;
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Homepage slider: %d passed, %d failed.\n", $this->passes, $this->failures );
		ob_end_flush();
		exit( $this->failures ? 1 : 0 );
	}

	private function attachment( int $id, string $name ): int {
		$created = wp_insert_attachment( [ 'import_id' => $id, 'post_title' => $name, 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ] );
		if ( is_wp_error( $created ) ) { throw new RuntimeException( 'Fixture attachment failed.' ); }
		update_post_meta( $created, '_wp_attached_file', 'slider-fixture-' . $this->token . '/' . $name . '.png' );
		wp_update_attachment_metadata( $created, [ 'width' => 1672, 'height' => 941, 'file' => 'slider-fixture-' . $this->token . '/' . $name . '.png', 'sizes' => [] ] );
		return $created;
	}
	private function config( int $id ): array {
		return [ 'version' => 1, 'slides' => [ [ 'image_id' => $id, 'image_position' => 'right-low', 'heading' => 'Early Bird Special', 'text' => 'Starts Every Morning at 9 AM', 'buttons' => [ [ 'label' => 'Buy Now', 'url' => 'https://example.com/shop/?q=bingo&sort=name' ], [ 'label' => 'Learn More', 'url' => 'https://example.com/about/' ] ] ] ] ];
	}
	private function payload( array $config ): array {
		return [ '_wpnonce' => wp_create_nonce( 'bingocommerce_homepage_slider:hall:' . get_current_blog_id() ), Slider::OPTION => $config, 'bc_slider_count' => (string) count( $config['slides'] ?? [] ), 'bc_slider_complete' => '1' ];
	}
	private function submit( array $config ): array {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		return Admin::handle_request( $this->payload( $config ) );
	}
	private function screen( string $method = 'GET', array $post = [] ): string {
		$_SERVER['REQUEST_METHOD'] = $method; $_POST = wp_slash( $post ); ob_start();
		try { Admin::render(); return (string) ob_get_contents(); }
		finally { ob_end_clean(); $_POST = []; }
	}
	private function saves( array $valid ): void {
		global $wpdb;
		$others = static fn() => $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name <> %s ORDER BY option_name', $wpdb->options, Slider::OPTION ), ARRAY_A );
		$before = $others();
		$this->same( true, $this->submit( $valid )['result'], 'Manager saves complete slider' );
		$this->same( $valid, get_option( Slider::OPTION ), 'saved data canonical and ordered' );
		$this->same( $before, $others(), 'save changes no other option' );
		$this->same( true, $this->submit( $valid )['result'], 'identical save succeeds' );
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, Slider::OPTION ) );
		$this->ok( in_array( $autoload, [ 'off', 'no' ], true ), 'slider option does not autoload' );
		foreach ( range( 0, 6 ) as $count ) {
			$value = [ 'version' => 1, 'slides' => array_fill( 0, $count, $valid['slides'][0] ) ];
			foreach ( $value['slides'] as $i => &$slide ) { $slide['heading'] = 'Slide ' . $i; } unset( $slide );
			$this->same( true, $this->submit( $value )['result'], "{$count} slides save" );
			$this->same( $value['slides'], Slider::provide(), "{$count} slides read in order" );
		}
		$blank_buttons = $valid; $blank_buttons['slides'][0]['buttons'] = [ [ 'label' => '', 'url' => '' ], [ 'label' => '', 'url' => '' ] ];
		$this->same( true, $this->submit( $blank_buttons )['result'], 'two blank button pairs are valid' );
		$this->same( [], Slider::stored()['slides'][0]['buttons'], 'blank buttons omitted canonically' );
		$max = $valid;
		foreach ( [ 'heading' => 80, 'text' => 160 ] as $field => $length ) { $max['slides'][0][$field] = str_repeat( 'é', $length ); }
		$max['slides'][0]['buttons'][0]['label'] = str_repeat( 'é', 32 );
		$this->same( true, $this->submit( $max )['result'], 'limits count UTF-8 characters, not bytes' );
		$quote = $valid; $quote['slides'][0]['heading'] = 'O\'Brien & Sons "Bingo"';
		$this->same( true, $this->submit( $quote )['result'], 'quotes and ampersands allowed as text' );
		$this->ok( str_contains( $this->screen(), 'O&#039;Brien &amp; Sons &quot;Bingo&quot;' ), 'admin values escaped' );
		$this->submit( $valid );
	}
	private function validation( array $valid ): void {
		$cases = [];
		foreach ( [ 'heading' => [ '', '<b>hi</b>', "a\nb", "a\0b", "\x80", str_repeat( 'x', 81 ), [] ], 'text' => [ '<script>x</script>', str_repeat( 'x', 161 ), [] ], 'image_id' => [ 0, -1, '1e5', '900001x', true, 900001.0, [], 9999999 ], 'image_position' => [ 'top', [], 'center; color:red' ] ] as $field => $bad ) {
			foreach ( $bad as $v ) { $c = $valid; $c['slides'][0][$field] = $v; $cases[] = $c; }
		}
		foreach ( [ 'javascript:alert(1)', 'data:text/html,x', 'ftp://example.com', '//example.com/', '/shop/', 'https://', 'https://user:pass@example.com', 'https://exa mple.com', 'https://example.com/?add-to-cart=1', 'https://example.com/?%61dd-to-cart=1', 'https://example.com/?wc-ajax=add_to_cart', 'https://example.com/?remove_item=x', 'https://example.com/?action=x', 'https://example.com/wp-json/wc/store/v1/cart', 'https://example.com/%77p-admin/admin-post.php', 'https://example.com/%0aX', 'https://example.com/' . str_repeat( 'a', 2048 ), [] ] as $url ) {
			$c = $valid; $c['slides'][0]['buttons'][0]['url'] = $url; $cases[] = $c;
		}
		foreach ( [ '', '<b>hi</b>', str_repeat( 'x', 33 ), [] ] as $label ) { $c = $valid; $c['slides'][0]['buttons'][0]['label'] = $label; $cases[] = $c; }
		$c = $valid; $c['slides'][0]['buttons'][0]['url'] = ''; $cases[] = $c;
		$c = $valid; $c['slides'] = array_fill( 0, 7, $valid['slides'][0] ); $cases[] = $c;
		$c = $valid; $c['slides'][0]['buttons'][] = $c['slides'][0]['buttons'][0]; $cases[] = $c;
		$c = $valid; $c['slides'][0]['buttons'][0]['extra'] = 'x'; $cases[] = $c;
		$c = $valid; unset( $c['slides'][0]['text'] ); $cases[] = $c;
		$c = $valid; $c['slides'][0]['site_id'] = 4; $cases[] = $c;
		$c = $valid; $c['version'] = 99; $cases[] = $c;
		$c = $valid; $c['slides'] = [ 2 => $c['slides'][0] ]; $cases[] = $c;
		foreach ( $cases as $i => $c ) {
			$this->ok( is_wp_error( $this->submit( $c )['result'] ), "invalid submission {$i} refused" );
			$this->same( $valid, Slider::stored(), "invalid submission {$i} preserves saved option" );
		}
		$bad = $valid; $bad['slides'][0]['heading'] = '<b>Bad</b>';
		$html = $this->screen( 'POST', $this->payload( $bad ) );
		$this->ok( str_contains( $html, '&lt;b&gt;Bad&lt;/b&gt;' ) && str_contains( $html, 'aria-invalid="true"' ), 'invalid input redisplayed safely with field error' );
		$this->ok( is_wp_error( Slider::save( $bad ) ), 'internal save also validates' );
		update_option( Slider::OPTION, [ 'broken' => true ] );
		$this->ok( is_wp_error( Slider::stored() ), 'malformed storage reported' );
		$this->same( [], Slider::provide(), 'malformed storage provides no slides' );
		$this->same( [ 'broken' => true ], get_option( Slider::OPTION ), 'read never repairs storage' );
		$this->submit( $valid );
	}
	private function requests( array $valid ): void {
		foreach ( [ '', [], 'invalid' ] as $nonce ) {
			$p = $this->payload( $valid ); $p['_wpnonce'] = $nonce; $_SERVER['REQUEST_METHOD'] = 'POST';
			$this->error( 'bc_homepage_slider_nonce', Admin::handle_request( $p )['result'], 'invalid nonce refused' );
		}
		foreach ( [ 'GET', 'PUT' ] as $method ) { $_SERVER['REQUEST_METHOD'] = $method; $this->error( 'bc_homepage_slider_request', Admin::handle_request( $this->payload( $valid ) )['result'], 'only POST saves' ); }
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( [ 'site_id', 'blog_id', 'hall_id', 'network_id' ] as $key ) {
			$p = $this->payload( $valid ); $p[$key] = 4;
			$this->error( 'bc_homepage_slider_request', Admin::handle_request( $p )['result'], 'tenant selector refused: ' . $key );
		}
		foreach ( [ 'bc_slider_complete', 'bc_slider_count', Slider::OPTION ] as $key ) { $p = $this->payload( $valid ); unset( $p[$key] ); $this->error( 'bc_homepage_slider_request', Admin::handle_request( $p )['result'], 'incomplete request refused' ); }
		$p = $this->payload( $valid ); unset( $p[Slider::OPTION]['slides'] );
		$this->error( 'bc_homepage_slider_request', Admin::handle_request( $p )['result'], 'missing list does not mean empty' );
		$this->same( $valid, Slider::stored(), 'invalid requests leave option unchanged' );
		$p['bc_slider_count'] = '0';
		$this->same( true, Admin::handle_request( $p )['result'], 'explicit zero-count HTML form saves empty list' );
		$this->same( Slider::blank(), Slider::stored(), 'empty list stored' );
		$this->submit( $valid );
	}
	private function authorization( array $valid, array $actors ): void {
		wp_set_current_user( $this->admin );
		$this->same( true, $this->submit( $valid )['result'], 'Platform Admin may save' );
		set_current_screen( 'dashboard-network' );
		$this->error( 'bc_homepage_slider_denied', $this->submit( $valid )['result'], 'network admin cannot save Hall settings' );
		set_current_screen( 'dashboard' );
		foreach ( [ 0, $actors['operator'], $actors['customer'], $actors['wp_admin'] ] as $id ) {
			wp_set_current_user( $id );
			$this->error( 'bc_homepage_slider_denied', $this->submit( $valid )['result'], 'actor without Hall capability denied' );
			$denied = false; try { $this->screen(); } catch ( RuntimeException $e ) { $denied = str_starts_with( $e->getMessage(), 'Native denial' ); }
			$this->same( true, $denied, 'direct admin page denied' );
			$this->same( $valid, Slider::stored(), 'denied save changes nothing' );
		}
	}
	private function render_front(): string {
		ob_start();
		try { include ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/front-page.php'; return (string) ob_get_contents(); }
		finally { ob_end_clean(); }
	}
	private function media_and_rendering( array $valid ): void {
		switch_theme( 'bingocommerce-bingothemev1' );
		$shop = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Shop' ] );
		update_option( 'woocommerce_shop_page_id', $shop );
		\BingoCommerce\Core\StoreDetails::save( array_replace( \BingoCommerce\Core\StoreDetails::blank(), [ 'city' => 'Contact stays here' ] ) );
		$this->submit( Slider::blank() );
		$html = $this->render_front();
		$this->ok( str_contains( $html, 'class="hero"' ) && str_contains( $html, 'Shop now' ), 'zero slides retain original banner and Shop link' );
		$this->ok( str_contains( $html, 'Contact stays here' ), 'contact retained' );
		$this->export( 'zero', $html );
		$this->submit( $valid );
		$html = $this->render_front();
		$this->ok( str_contains( $html, 'data-homepage-slider' ) && ! str_contains( $html, 'data-prev' ) && ! str_contains( $html, 'data-rotation' ), 'one slide has no controls' );
		$this->ok( str_contains( $html, 'fetchpriority="high"' ) && str_contains( $html, 'loading="eager"' ), 'initial image prioritized' );
		$this->export( 'one', $html );
		$many = $valid; $many['slides'] = array_fill( 0, 3, $valid['slides'][0] );
		$many['slides'][1]['heading'] = 'Cosmic Bingo'; $many['slides'][2]['heading'] = 'High Rollers'; $many['slides'][2]['image_position'] = 'center';
		$this->submit( $many ); $html = $this->render_front();
		$this->same( 3, substr_count( $html, 'data-dot=' ), 'multiple slides get dots' );
		$this->ok( str_contains( $html, 'data-prev' ) && str_contains( $html, 'data-next' ) && str_contains( $html, 'data-rotation hidden' ), 'multiple slide controls progressively enhanced' );
		$this->same( 2, substr_count( $html, 'hidden inert aria-hidden="true"' ), 'inactive slides excluded before JavaScript' );
		$this->export( 'multiple', $html );
		$this->export( 'admin', $this->screen() );
		$long = $many; $long['slides'] = array_fill( 0, 6, $valid['slides'][0] );
		foreach ( $long['slides'] as &$slide ) { $slide['heading'] = str_repeat( 'Long heading ', 6 ); $slide['text'] = str_repeat( 'Supporting text ', 10 ); $slide['buttons'][0]['label'] = str_repeat( 'W', 32 ); } unset( $slide );
		$this->submit( $long ); $this->export( 'long', $this->render_front() );
		$quote = $valid; $quote['slides'][0]['heading'] = 'O\'Brien & Sons "Hall"'; $this->submit( $quote );
		$this->ok( str_contains( $this->render_front(), 'O&#039;Brien &amp; Sons &quot;Hall&quot;' ), 'theme escapes plain text' );
		$id = $valid['slides'][0]['image_id'];
		wp_update_post( [ 'ID' => $id, 'post_status' => 'trash' ] );
		$this->same( [], Slider::provide(), 'trashed image omitted' );
		$this->ok( str_contains( $this->screen(), 'This image is unavailable' ), 'admin warns without deleting configuration' );
		$this->ok( str_contains( $this->render_front(), 'class="hero"' ), 'all unavailable images use fallback' );
		$this->ok( is_wp_error( $this->submit( $valid )['result'] ), 'save with unavailable image rejected' );
		$this->same( $quote, Slider::stored(), 'image failure leaves saved configuration intact' );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf' ] );
		$this->ok( is_wp_error( $this->submit( $valid )['result'] ), 'non-image attachment refused' );
		wp_update_post( [ 'ID' => $id, 'post_mime_type' => 'image/png' ] );
		$other = $this->attachment( 900003, 'deleted' );
		$mixed = $valid; $mixed['slides'][] = array_replace( $valid['slides'][0], [ 'image_id' => $other ] ); $this->submit( $mixed );
		wp_delete_attachment( $other, true );
		$this->same( $valid['slides'], Slider::provide(), 'deleted image omitted; remaining slide retained' );
		$this->same( $mixed, Slider::stored(), 'deleted attachment does not rewrite slider' );
		remove_filter( Slider::FILTER, [ Slider::class, 'provide' ] );
		$this->ok( str_contains( $this->render_front(), 'class="hero"' ), 'Core provider unavailable uses fallback' );
		Slider::register(); $this->submit( $valid );
	}
	private function export( string $name, string $html ): void {
		$directory = getenv( 'BC_SLIDER_PREVIEW_DIR' );
		if ( ! $directory ) { return; }
		$real = realpath( $directory );
		if ( false === $real || ! str_starts_with( $real, '/private/tmp/' ) ) { throw new RuntimeException( 'Preview export requires an existing private/tmp directory.' ); }
		if ( false === file_put_contents( $real . '/' . $name . '.html', $html ) ) { throw new RuntimeException( 'Preview export failed.' ); }
	}
	private function isolation( int $a, int $b, array $actors, int $id ): void {
		wp_set_current_user( $actors['manager'] );
		switch_to_blog( $a ); try { $nonce = wp_create_nonce( 'bingocommerce_homepage_slider:hall:' . $a ); $before_a = $this->snapshot( $a ); } finally { restore_current_blog(); }
		switch_to_blog( $b );
		try {
			set_current_screen( 'dashboard' );
			$this->error( 'bc_homepage_slider_denied', $this->submit( $this->config( $id ) )['result'], 'Hall A Manager cannot save Hall B' );
			$this->same( false, Slider::image( $id ), 'Hall A attachment ID cannot resolve in empty Hall B' );
			$this->same( [], Slider::provide(), 'Hall B has no Hall A slides' );
			wp_set_current_user( $this->admin ); $this->grant( $b, $actors['manager'], 'bc_hall_manager' ); wp_set_current_user( $actors['manager'] );
			$this->ok( is_wp_error( $this->submit( $this->config( $id ) )['result'] ), 'authorized Manager still cannot select foreign attachment' );
			$b_id = $this->attachment( $id, 'hall-b' );
			$this->same( $id, $b_id, 'fixture has overlapping attachment IDs' );
			$p = $this->payload( $this->config( $b_id ) ); $p['_wpnonce'] = $nonce;
			$this->error( 'bc_homepage_slider_nonce', Admin::handle_request( $p )['result'], 'multi-Hall Manager cannot reuse other Hall nonce' );
			$b_config = $this->config( $b_id ); $b_config['slides'][0]['heading'] = 'Hall B only';
			$this->same( true, $this->submit( $b_config )['result'], 'fresh Hall B form saves' );
			$this->ok( str_contains( Slider::image( $b_id )[0], 'hall-b.png' ), 'overlapping ID resolves to local Hall B media' );
		} finally { restore_current_blog(); }
		$this->same( $before_a, $this->snapshot( $a ), 'Hall B mutations leave all Hall A tables unchanged' );
		foreach ( [ $a, $b, $a ] as $site ) {
			switch_to_blog( $site );
			try {
				$this->same( $site === $a ? 'Early Bird Special' : 'Hall B only', Slider::provide()[0]['heading'], 'filter follows A -> B -> A' );
				$this->ok( str_contains( Slider::image( $id )[0], $site === $a ? 'hall-a.png' : 'hall-b.png' ), 'media follows current Hall, including overlapping IDs' );
				$media = $this->media_query( $id );
				$this->same( true, $media['success'], 'native Media Library query permitted for Manager' );
				$this->same( [ $id ], array_column( $media['data'], 'id' ), 'native media query returns local attachment only' );
				$this->ok( str_contains( $media['data'][0]['url'], $site === $a ? 'hall-a.png' : 'hall-b.png' ), 'native Media Library response follows current Hall' );
			} finally { restore_current_blog(); }
		}
		switch_to_blog( get_main_site_id() );
		try {
			$this->same( [], Slider::provide(), 'main site remains blank after Hall switches' );
			$this->error( 'bc_homepage_slider_denied', $this->submit( $this->config( $id ) )['result'], 'main site rejects Hall setting saves' );
		} finally { restore_current_blog(); }
	}
	private function media_query( int $id ): array {
		$previous = $_REQUEST;
		$_REQUEST = [ 'query' => [ 'post__in' => [ $id ], 'post_mime_type' => 'image', 'posts_per_page' => 20 ] ];
		$die = static fn() => static function () { throw new RuntimeException( 'Media JSON complete' ); };
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			try { wp_ajax_query_attachments(); } catch ( RuntimeException $e ) { if ( 'Media JSON complete' !== $e->getMessage() ) { throw $e; } }
			return json_decode( (string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR );
		} finally {
			ob_end_clean(); $_REQUEST = $previous;
			remove_filter( 'wp_doing_ajax', '__return_true' ); remove_filter( 'wp_die_ajax_handler', $die );
		}
	}

	private function hall( string $label ): int {
		wp_set_current_user( $this->admin ); add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'homepage-slider-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Homepage Slider Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site;
		update_blog_option( $site, 'bc_homepage_slider_test_owner', $this->token );
		return $site;
	}

	private function actors( int $site ): array {
		$actors = [];
		foreach ( [ 'manager' => 'bc_hall_manager', 'operator' => 'bc_game_operator', 'customer' => 'subscriber', 'wp_admin' => 'administrator' ] as $label => $role ) {
			$id = wp_insert_user( [ 'user_login' => 'slider-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Actor creation failed.' ); }
			$this->users[] = $id; $actors[ $label ] = $id; update_user_meta( $id, 'bc_homepage_slider_test_owner', $this->token );
			$this->grant( $site, $id, $role );
		}
		return $actors;
	}

	private function grant( int $site, int $user, string $role ): void {
		global $wpdb;
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, 'bc_homepage_slider_test_owner' )
			|| ! in_array( $user, $this->users, true ) || $this->token !== get_user_meta( $user, 'bc_homepage_slider_test_owner', true ) ) { throw new RuntimeException( 'Role fixture ownership refused.' ); }
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
			if ( $this->token !== get_user_meta( $user, 'bc_homepage_slider_test_owner', true ) ) { throw new RuntimeException( 'Actor cleanup ownership refused.' ); }
			foreach ( $this->sites as $site ) {
				if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_homepage_slider_test_owner' ) ) { throw new RuntimeException( 'Role cleanup ownership refused.' ); }
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			wpmu_delete_user( $user );
			$this->same( false, get_userdata( $user ), 'owned disposable actor deleted' );
		}
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_homepage_slider_test_owner' ) ) { throw new RuntimeException( 'Hall cleanup ownership refused.' ); }
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
ob_start(); // Keep native AJAX headers testable after assertion output.
( new HomepageSliderTest() )->run();
