<?php
/**
 * Homepage Trust row and Testimonials: current-Hall configuration, administration and rendering.
 * BC_TEST_DB=bingocommerce_state_recon php tests/homepage-trust-testimonials.php
 * Optional BC_TRUST_PREVIEW_DIR exports static markup in an existing /private/tmp directory.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
$_SERVER['SERVER_NAME'] = BC_FIXTURE_DOMAIN;
foreach ( [ 'plugin.php', 'screen.php', 'class-wp-screen.php', 'template.php', 'media.php' ] as $file ) { require_once ABSPATH . 'wp-admin/includes/' . $file; }
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';

use BingoCommerce\Core\HomepageAdmin as Admin;
use BingoCommerce\Core\HomepageCategories as Categories;
use BingoCommerce\Core\HomepageSlider as Slider;
use BingoCommerce\Core\HomepageTestimonials as Testimonials;
use BingoCommerce\Core\HomepageTestimonialsAdmin as TestimonialsAdmin;
use BingoCommerce\Core\HomepageTrust as Trust;
use BingoCommerce\Core\HomepageTrustAdmin as TrustAdmin;

final class HomepageTrustTestimonialsTest {
	private const OWNER = 'bc_homepage_trust_test_owner';
	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	public function run(): void {
		add_filter( 'wp_die_handler', static fn() => static function ( $message ) { throw new RuntimeException( 'Native denial: ' . wp_strip_all_tags( (string) $message ) ); } );
		$this->admin = (int) username_exists( 'fixture_admin' ); $this->token = wp_generate_uuid4();
		$baseline = []; foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		$old_post = $_POST; $old_get = $_GET; $old_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		try {
			$this->same( 10, has_filter( Trust::FILTER, [ Trust::class, 'provide' ] ), 'trust read filter registered' );
			$this->same( 10, has_filter( Testimonials::FILTER, [ Testimonials::class, 'provide' ] ), 'testimonials read filter registered' );
			$this->same( [], Trust::provide(), 'main site has no trust row' );
			$this->same( [], Testimonials::provide(), 'main site has no testimonials' );
			$a = $this->hall( 'a' ); $b = $this->hall( 'b' ); $actors = $this->actors( $a );
			foreach ( [ $a, $b ] as $site ) {
				switch_to_blog( $site );
				try { WC_Install::create_tables(); WC_Install::create_roles(); \BingoCommerce\Core\Roles::sync_current_site(); }
				finally { restore_current_blog(); }
			}
			switch_to_blog( $a );
			try {
				set_current_screen( 'dashboard' );
				wp_set_current_user( $actors['manager'] );
				$this->defaults_and_screens();
				$this->trust_saves();
				$this->trust_validation();
				$this->testimonial_saves_and_validation();
				$this->requests();
				$this->authorization( $actors );
				wp_set_current_user( $actors['manager'] );
				$this->routing();
				$this->rendering();
			} finally { restore_current_blog(); }
			$this->isolation( $a, $b, $actors );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			$_POST = $old_post; $_GET = $old_get; $_SERVER['REQUEST_METHOD'] = $old_method;
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Homepage trust row and testimonials: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	private function trust( int $count ): array {
		$items = [];
		for ( $i = 0; $i < $count; ++$i ) { $items[] = [ 'icon' => Trust::ICONS[ $i ], 'title' => 'Title ' . ( $i + 1 ), 'text' => 0 === $i ? '' : 'Text ' . ( $i + 1 ) ]; }
		return [ 'version' => 1, 'items' => $items ];
	}
	private function quotes( int $count ): array {
		$items = [];
		for ( $i = 0; $i < $count; ++$i ) { $items[] = [ 'quote' => 'Quote ' . ( $i + 1 ), 'name' => 'Name ' . ( $i + 1 ), 'role' => 0 === $i ? '' : 'Role ' . ( $i + 1 ) ]; }
		return [ 'version' => 1, 'items' => $items ];
	}
	private function trust_payload( array $config ): array {
		return [ 'bc_trust_action' => 'save', 'bc_trust_nonce' => wp_create_nonce( 'bingocommerce_homepage_trust:hall:' . get_current_blog_id() ), Trust::OPTION => $config, 'bc_trust_count' => (string) count( $config['items'] ?? [] ), 'bc_trust_complete' => '1' ];
	}
	private function quote_payload( array $config ): array {
		return [ 'bc_testimonials_action' => 'save', 'bc_testimonials_nonce' => wp_create_nonce( 'bingocommerce_homepage_testimonials:hall:' . get_current_blog_id() ), Testimonials::OPTION => $config, 'bc_testimonials_count' => (string) count( $config['items'] ?? [] ), 'bc_testimonials_complete' => '1' ];
	}
	private function slider_payload( array $config ): array {
		return [ '_wpnonce' => wp_create_nonce( 'bingocommerce_homepage_slider:hall:' . get_current_blog_id() ), Slider::OPTION => $config, 'bc_slider_count' => (string) count( $config['slides'] ), 'bc_slider_complete' => '1' ];
	}
	private function save_trust( array $config ): mixed {
		$_SERVER['REQUEST_METHOD'] = 'POST'; return TrustAdmin::handle_request( $this->trust_payload( $config ) )['result'];
	}
	private function save_quotes( array $config ): mixed {
		$_SERVER['REQUEST_METHOD'] = 'POST'; return TestimonialsAdmin::handle_request( $this->quote_payload( $config ) )['result'];
	}
	private function screen( string $method = 'GET', array $post = [], mixed $tab = null ): string {
		$query = $_GET; unset( $_GET['tab'] ); if ( null !== $tab ) { $_GET['tab'] = $tab; }
		$_SERVER['REQUEST_METHOD'] = $method; $_POST = wp_slash( $post ); ob_start();
		try { Admin::render(); return (string) ob_get_contents(); } finally { ob_end_clean(); $_POST = []; $_GET = $query; }
	}
	private function document( string $html ): DOMXPath {
		$dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET ); } finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		return new DOMXPath( $dom );
	}
	private function assert_tab( string $html, string $tab, string $form ): void {
		$xpath = $this->document( $html );
		$this->same( 4, $xpath->query( '//nav[@aria-label="Homepage settings"]/a' )->length, "{$tab}: four homepage tabs" );
		$active = $xpath->query( '//nav[@aria-label="Homepage settings"]/a[@aria-current="page"]' );
		$this->same( 1, $active->length, "{$tab}: exactly one current tab" );
		$this->same( Admin::tab_url( $tab ), $active->length ? $active->item( 0 )->getAttribute( 'href' ) : '', "{$tab}: current tab is the expected one" );
		$this->same( 1, $xpath->query( '//form' )->length, "{$tab}: only one editor form" );
		$this->same( 1, $xpath->query( '//form[@id="' . $form . '"]' )->length, "{$tab}: expected form rendered" );
		$this->same( Admin::tab_url( $tab ), $xpath->evaluate( 'string(//form/@action)' ), "{$tab}: form posts back to its own tab" );
	}
	private function front(): string {
		if ( function_exists( 'wp_set_template_globals' ) ) { wp_set_template_globals(); }
		ob_start(); try { include ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/front-page.php'; return (string) ob_get_contents(); } finally { ob_end_clean(); }
	}
	private function export( string $name, string $html ): void {
		$directory = getenv( 'BC_TRUST_PREVIEW_DIR' ); if ( ! $directory ) { return; }
		$real = realpath( $directory );
		if ( false === $real || ! str_starts_with( $real, '/private/tmp/' ) ) { throw new RuntimeException( 'Preview export requires an existing private/tmp directory.' ); }
		if ( false === file_put_contents( $real . '/' . $name . '.html', $html ) ) { throw new RuntimeException( 'Preview export failed.' ); }
	}

	private function defaults_and_screens(): void {
		$this->same( false, get_option( Trust::OPTION, false ), 'new Hall has no saved trust row' );
		$this->same( Trust::defaults()['items'], Trust::provide(), 'unsaved Hall provides the four default trust items' );
		$this->same( [ 'Secure Checkout', 'Trusted by Bingo Halls', 'Easy Reordering', 'Quality Guaranteed' ], array_column( Trust::provide(), 'title' ), 'defaults keep the reference titles in order' );
		$this->same( [ 'lock', 'star', 'refresh', 'shield' ], array_column( Trust::provide(), 'icon' ), 'defaults keep the reference icons in order' );
		$this->same( false, get_option( Testimonials::OPTION, false ), 'new Hall has no saved testimonials' );
		$this->same( [], Testimonials::provide(), 'new Hall has no testimonials (nothing seeded)' );
		$html = $this->screen( 'GET', [], 'trust' );
		$this->assert_tab( $html, 'trust', 'bc-trust-form' );
		$xpath = $this->document( $html );
		$this->same( 4, $xpath->query( '//form//fieldset[@data-list-item]' )->length, 'trust editor shows the four default items' );
		$this->same( 'Secure Checkout', $xpath->evaluate( 'string(//form//input[@name="bc_homepage_trust[items][0][title]"]/@value)' ), 'default title prefilled' );
		$this->same( '4', $xpath->evaluate( 'string(//form//input[@name="bc_trust_count"]/@value)' ), 'count marker matches default items' );
		$this->ok( str_contains( $html, 'default items until you save your own' ), 'editor explains the defaults' );
		$this->export( 'admin-trust', $html );
		$html = $this->screen( 'GET', [], 'testimonials' );
		$this->assert_tab( $html, 'testimonials', 'bc-testimonials-form' );
		$this->same( 0, $this->document( $html )->query( '//form//fieldset[@data-list-item]' )->length, 'testimonial editor starts empty' );
		$this->same( false, get_option( Trust::OPTION, false ), 'GET does not create the trust option' );
		$this->same( false, get_option( Testimonials::OPTION, false ), 'GET does not create the testimonials option' );
		$this->same( true, $this->save_trust( Trust::defaults() ), 'saving the unchanged defaults succeeds' );
		$this->same( Trust::defaults(), get_option( Trust::OPTION ), 'saving the defaults stores them' );
		$this->ok( ! str_contains( $this->screen( 'GET', [], 'trust' ), 'default items until you save your own' ), 'default note disappears once saved' );
	}

	private function trust_saves(): void {
		global $wpdb;
		$others = static fn() => $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name <> %s ORDER BY option_name', $wpdb->options, Trust::OPTION ), ARRAY_A );
		$before = $others();
		$this->same( true, $this->save_trust( $this->trust( 2 ) ), 'Manager saves two trust items' );
		$this->same( $this->trust( 2 ), get_option( Trust::OPTION ), 'trust items stored canonically and in order' );
		$this->same( $before, $others(), 'trust save changes no other option' );
		$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, Trust::OPTION ) );
		$this->ok( in_array( $autoload, [ 'off', 'no' ], true ), 'trust option does not autoload' );
		foreach ( range( 0, 4 ) as $count ) {
			$this->same( true, $this->save_trust( $this->trust( $count ) ), "{$count} trust items save" );
			$this->same( $this->trust( $count )['items'], Trust::provide(), "{$count} trust items read in order" );
		}
		$this->save_trust( $this->trust( 0 ) );
		$this->same( [], Trust::provide(), 'removing every item hides the row instead of restoring defaults' );
		$max = $this->trust( 1 ); $max['items'][0]['title'] = str_repeat( 'é', 40 ); $max['items'][0]['text'] = str_repeat( 'é', 80 );
		$this->same( true, $this->save_trust( $max ), 'trust limits count UTF-8 characters' );
		$quote = $this->trust( 1 ); $quote['items'][0]['title'] = 'O\'Brien & "Sons"'; $quote['items'][0]['text'] = '  padded  ';
		$this->same( true, $this->save_trust( $quote ), 'quotes and ampersands are plain text' );
		$this->same( 'padded', Trust::stored()['items'][0]['text'], 'surrounding spaces trimmed' );
		$this->ok( str_contains( $this->screen( 'GET', [], 'trust' ), 'O&#039;Brien &amp; &quot;Sons&quot;' ), 'trust admin values escaped' );
		$this->save_trust( $this->trust( 4 ) );
	}

	private function trust_validation(): void {
		$valid = $this->trust( 4 ); $cases = [];
		foreach ( [ 'title' => [ '', '   ', '<b>x</b>', "a\nb", "a\0b", "\x80", str_repeat( 'x', 41 ), [] ], 'text' => [ '<script>x</script>', "a\tb", str_repeat( 'x', 81 ), [] ], 'icon' => [ '', 'heart', 'LOCK', [], 1 ] ] as $field => $bad ) {
			foreach ( $bad as $v ) { $c = $valid; $c['items'][1][ $field ] = $v; $cases[] = $c; }
		}
		$c = $valid; $c['items'][] = $valid['items'][0]; $cases[] = $c;
		$c = $valid; $c['items'][0]['extra'] = 'x'; $cases[] = $c;
		$c = $valid; unset( $c['items'][0]['text'] ); $cases[] = $c;
		$c = $valid; $c['items'][0]['site_id'] = 4; $cases[] = $c;
		$c = $valid; $c['version'] = 2; $cases[] = $c;
		$c = $valid; $c['items'] = [ 2 => $valid['items'][0] ]; $cases[] = $c;
		$c = $valid; $c['items'][0] = 'x'; $cases[] = $c;
		foreach ( $cases as $i => $c ) {
			$this->ok( is_wp_error( $this->save_trust( $c ) ), "invalid trust submission {$i} refused" );
			$this->same( $valid, Trust::stored(), "invalid trust submission {$i} preserves saved row" );
		}
		// The form's count check refuses a fifth item first; prove the writer's own maximum too.
		$five = $valid; $five['items'][] = $valid['items'][0];
		$this->same( [ 'form' ], array_keys( Trust::validate( $five )['errors'] ), 'model refuses a fifth trust item on its own' );
		$this->error( 'bc_homepage_trust_value', Trust::save( $five ), 'direct save of five trust items refused' );
		$this->same( $valid, Trust::stored(), 'direct five-item save preserves saved row' );
		$bad = $valid; $bad['items'][1]['title'] = '<b>Bad</b>';
		$html = $this->screen( 'POST', $this->trust_payload( $bad ) );
		$this->assert_tab( $html, 'trust', 'bc-trust-form' );
		$this->ok( str_contains( $html, '&lt;b&gt;Bad&lt;/b&gt;' ) && str_contains( $html, 'aria-invalid="true"' ) && str_contains( $html, 'The trust row needs correcting.' ), 'invalid trust input redisplayed safely with field error' );
		$this->ok( str_contains( $html, 'href="#bc-trust-item-1-title"' ) && str_contains( $html, 'id="bc-trust-item-1-title"' ), 'error summary links to the field' );
		$this->ok( is_wp_error( Trust::save( $bad ) ), 'internal trust save also validates' );
		update_option( Trust::OPTION, [ 'broken' => true ] );
		$this->ok( is_wp_error( Trust::stored() ), 'malformed trust storage reported' );
		$this->same( [], Trust::provide(), 'malformed trust storage shows no row (not the defaults)' );
		$this->same( [ 'broken' => true ], get_option( Trust::OPTION ), 'trust read never repairs storage' );
		$this->ok( str_contains( $this->screen( 'GET', [], 'trust' ), 'The saved trust row could not be read' ), 'admin warns about malformed trust storage' );
		$this->same( true, $this->save_trust( $valid ), 'a valid save replaces malformed trust storage' );
	}

	private function testimonial_saves_and_validation(): void {
		$this->same( true, $this->save_quotes( $this->quotes( 2 ) ), 'Manager saves two testimonials' );
		$this->same( $this->quotes( 2 ), get_option( Testimonials::OPTION ), 'testimonials stored canonically and in order' );
		foreach ( range( 0, 6 ) as $count ) {
			$this->same( true, $this->save_quotes( $this->quotes( $count ) ), "{$count} testimonials save" );
			$this->same( $this->quotes( $count )['items'], Testimonials::provide(), "{$count} testimonials read in order" );
		}
		$max = $this->quotes( 1 ); $max['items'][0] = [ 'quote' => str_repeat( 'é', 420 ), 'name' => str_repeat( 'é', 60 ), 'role' => str_repeat( 'é', 60 ) ];
		$this->same( true, $this->save_quotes( $max ), 'testimonial limits count UTF-8 characters' );
		$valid = $this->quotes( 3 ); $this->save_quotes( $valid ); $cases = [];
		foreach ( [ 'quote' => [ '', ' ', "Line one\nLine two", "a\r\nb", '<p>x</p>', str_repeat( 'x', 421 ), [] ], 'name' => [ '', '<b>x</b>', str_repeat( 'x', 61 ), [] ], 'role' => [ '<i>x</i>', str_repeat( 'x', 61 ), [] ] ] as $field => $bad ) {
			foreach ( $bad as $v ) { $c = $valid; $c['items'][2][ $field ] = $v; $cases[] = $c; }
		}
		$c = $this->quotes( 7 ); $cases[] = $c;
		$c = $valid; $c['items'][0]['photo'] = 'x'; $cases[] = $c;
		$c = $valid; unset( $c['items'][0]['role'] ); $cases[] = $c;
		$c = $valid; $c['version'] = 2; $cases[] = $c;
		$c = $valid; $c['items'] = [ 1 => $valid['items'][0] ]; $cases[] = $c;
		foreach ( $cases as $i => $c ) {
			$this->ok( is_wp_error( $this->save_quotes( $c ) ), "invalid testimonial submission {$i} refused" );
			$this->same( $valid, Testimonials::stored(), "invalid testimonial submission {$i} preserves saved testimonials" );
		}
		// The form's count check refuses a seventh testimonial first; prove the writer's own maximum too.
		$seven = $this->quotes( 7 );
		$this->same( [ 'form' ], array_keys( Testimonials::validate( $seven )['errors'] ), 'model refuses a seventh testimonial on its own' );
		$this->error( 'bc_homepage_testimonials_value', Testimonials::save( $seven ), 'direct save of seven testimonials refused' );
		$this->same( $valid, Testimonials::stored(), 'direct seven-item save preserves saved testimonials' );
		$bad = $valid; $bad['items'][2]['quote'] = "Two\nparagraphs";
		$html = $this->screen( 'POST', $this->quote_payload( $bad ) );
		$this->assert_tab( $html, 'testimonials', 'bc-testimonials-form' );
		$this->ok( str_contains( $html, 'one paragraph of plain text' ) && str_contains( $html, 'aria-invalid="true"' ) && str_contains( $html, 'Testimonials need correcting.' ), 'line break refused with a field error' );
		$this->ok( is_wp_error( Testimonials::save( $bad ) ), 'internal testimonial save also validates' );
		update_option( Testimonials::OPTION, [ 'items' => 'broken' ] );
		$this->ok( is_wp_error( Testimonials::stored() ), 'malformed testimonial storage reported' );
		$this->same( [], Testimonials::provide(), 'malformed testimonial storage shows nothing' );
		$this->same( [ 'items' => 'broken' ], get_option( Testimonials::OPTION ), 'testimonial read never repairs storage' );
		$this->same( true, $this->save_quotes( $valid ), 'a valid save replaces malformed testimonial storage' );
	}

	private function requests(): void {
		$trust = Trust::stored(); $quotes = Testimonials::stored();
		foreach ( [ [ 'trust', 'bc_trust_', $this->trust_payload( $trust ), [ TrustAdmin::class, 'handle_request' ] ], [ 'testimonials', 'bc_testimonials_', $this->quote_payload( $quotes ), [ TestimonialsAdmin::class, 'handle_request' ] ] ] as [ $label, $prefix, $payload, $handler ] ) {
			$code = 'bc_homepage_' . $label . '_';
			$_SERVER['REQUEST_METHOD'] = 'POST';
			foreach ( [ '', [], 'invalid' ] as $nonce ) { $p = $payload; $p[ $prefix . 'nonce' ] = $nonce; $this->error( $code . 'nonce', $handler( $p )['result'], "{$label}: invalid nonce refused" ); }
			$other = $payload; $other[ $prefix . 'nonce' ] = wp_create_nonce( 'bingocommerce_homepage_slider:hall:' . get_current_blog_id() );
			$this->error( $code . 'nonce', $handler( $other )['result'], "{$label}: another form's nonce refused" );
			foreach ( [ 'GET', 'PUT' ] as $method ) { $_SERVER['REQUEST_METHOD'] = $method; $this->error( $code . 'request', $handler( $payload )['result'], "{$label}: only POST saves ({$method})" ); }
			$_SERVER['REQUEST_METHOD'] = 'POST';
			foreach ( [ 'site_id', 'blog_id', 'hall_id', Slider::OPTION ] as $key ) { $p = $payload; $p[ $key ] = 4; $this->error( $code . 'request', $handler( $p )['result'], "{$label}: unexpected key refused ({$key})" ); }
			foreach ( [ $prefix . 'complete', $prefix . 'count', $prefix . 'action' ] as $key ) { $p = $payload; unset( $p[ $key ] ); $this->error( $code . 'request', $handler( $p )['result'], "{$label}: incomplete request refused ({$key})" ); }
			$p = $payload; $p[ $prefix . 'count' ] = '0'; $this->error( $code . 'request', $handler( $p )['result'], "{$label}: mismatched count refused" );
			$p = $payload; $p[ $prefix . 'count' ] = '9'; $this->error( $code . 'request', $handler( $p )['result'], "{$label}: count above the maximum refused" );
			$p = $payload; unset( $p[ 'trust' === $label ? Trust::OPTION : Testimonials::OPTION ]['items'] );
			$this->error( $code . 'request', $handler( $p )['result'], "{$label}: missing list does not mean empty" );
		}
		$this->same( $trust, Trust::stored(), 'refused requests leave the trust row unchanged' );
		$this->same( $quotes, Testimonials::stored(), 'refused requests leave testimonials unchanged' );
		$p = $this->trust_payload( Trust::blank() ); unset( $p[ Trust::OPTION ]['items'] ); $_SERVER['REQUEST_METHOD'] = 'POST';
		$this->same( true, TrustAdmin::handle_request( $p )['result'], 'explicit zero with completion removes every trust item' );
		$this->same( Trust::blank(), Trust::stored(), 'empty trust row stored' );
		$p = $this->quote_payload( Testimonials::blank() ); unset( $p[ Testimonials::OPTION ]['items'] );
		$this->same( true, TestimonialsAdmin::handle_request( $p )['result'], 'explicit zero with completion removes every testimonial' );
		$this->same( Testimonials::blank(), Testimonials::stored(), 'empty testimonials stored' );
		$this->save_trust( $trust ); $this->save_quotes( $quotes );
	}

	private function authorization( array $actors ): void {
		wp_set_current_user( $this->admin );
		$this->same( true, $this->save_trust( $this->trust( 3 ) ), 'Platform Admin saves the trust row' );
		$this->same( true, $this->save_quotes( $this->quotes( 3 ) ), 'Platform Admin saves testimonials' );
		set_current_screen( 'dashboard-network' );
		$this->error( 'bc_homepage_trust_denied', $this->save_trust( $this->trust( 1 ) ), 'network admin cannot save the trust row' );
		$this->error( 'bc_homepage_testimonials_denied', $this->save_quotes( $this->quotes( 1 ) ), 'network admin cannot save testimonials' );
		set_current_screen( 'dashboard' );
		foreach ( [ 0, $actors['operator'], $actors['customer'], $actors['wp_admin'] ] as $id ) {
			wp_set_current_user( $id );
			$this->error( 'bc_homepage_trust_denied', $this->save_trust( $this->trust( 1 ) ), 'actor without Hall capability cannot save the trust row' );
			$this->error( 'bc_homepage_testimonials_denied', $this->save_quotes( $this->quotes( 1 ) ), 'actor without Hall capability cannot save testimonials' );
			foreach ( [ 'trust', 'testimonials' ] as $tab ) {
				$denied = false; try { $this->screen( 'GET', [], $tab ); } catch ( RuntimeException $e ) { $denied = str_starts_with( $e->getMessage(), 'Native denial' ); }
				$this->same( true, $denied, "direct {$tab} tab denied" );
			}
		}
		$this->same( $this->trust( 3 ), Trust::stored(), 'denied saves change no trust item' );
		$this->same( $this->quotes( 3 ), Testimonials::stored(), 'denied saves change no testimonial' );
	}

	private function routing(): void {
		$slider = Slider::stored(); $categories = Categories::stored(); $trust = Trust::stored(); $quotes = Testimonials::stored();
		$html = $this->screen( 'POST', $this->trust_payload( $this->trust( 2 ) ), 'slider' );
		$this->assert_tab( $html, 'trust', 'bc-trust-form' );
		$this->ok( str_contains( $html, 'Trust row saved.' ) && ! str_contains( $html, 'Homepage slider saved.' ) && ! str_contains( $html, 'Shop by Category saved.' ), 'trust POST shows only its own notice' );
		$this->same( [ $slider, $categories, $quotes ], [ Slider::stored(), Categories::stored(), Testimonials::stored() ], 'trust save leaves slider, categories and testimonials unchanged' );
		$html = $this->screen( 'POST', $this->quote_payload( $this->quotes( 2 ) ), 'trust' );
		$this->assert_tab( $html, 'testimonials', 'bc-testimonials-form' );
		$this->ok( str_contains( $html, 'Testimonials saved.' ) && ! str_contains( $html, 'Trust row saved.' ), 'testimonials POST shows only its own notice' );
		$this->same( [ $slider, $categories, $this->trust( 2 ) ], [ Slider::stored(), Categories::stored(), Trust::stored() ], 'testimonials save leaves slider, categories and trust unchanged' );
		$html = $this->screen( 'POST', $this->slider_payload( Slider::blank() ), 'trust' );
		$this->ok( str_contains( $html, 'Homepage slider saved.' ) && str_contains( $html, 'id="bc-form"' ), 'slider POST still reaches the slider editor' );
		$this->same( [ $this->trust( 2 ), $this->quotes( 2 ) ], [ Trust::stored(), Testimonials::stored() ], 'slider save leaves the new sections unchanged' );
		$mixed = $this->trust_payload( $this->trust( 1 ) ) + $this->slider_payload( Slider::blank() );
		$html = $this->screen( 'POST', $mixed );
		$this->assert_tab( $html, 'trust', 'bc-trust-form' );
		$this->ok( str_contains( $html, 'The trust row needs correcting.' ) && ! str_contains( $html, 'Homepage slider saved.' ), 'mixed trust/slider payload refused without a slider save' );
		$this->same( $this->trust( 2 ), Trust::stored(), 'mixed payload preserves the trust row' );
		$html = $this->screen( 'POST', [ 'bc_testimonials_nonce' => $this->quote_payload( $quotes )['bc_testimonials_nonce'] ] );
		$this->ok( str_contains( $html, 'Testimonials need correcting.' ), 'nonce-only truncated testimonials POST keeps its own error' );
		foreach ( [ 'bogus' => 'slider', 'categories' => 'categories' ] as $requested => $expected ) {
			$this->same( 1, $this->document( $this->screen( 'GET', [], $requested ) )->query( '//nav//a[@aria-current="page"][@href="' . Admin::tab_url( $expected ) . '"]' )->length, "GET tab {$requested} opens {$expected}" );
		}
		$this->same( 1, $this->document( $this->screen( 'GET', [], [ 'trust' ] ) )->query( '//form[@id="bc-form"]' )->length, 'array tab value falls back to the slider' );
		$this->same( Admin::tab_url( 'slider' ), Admin::tab_url( 'unknown' ), 'unknown tab URL falls back to the slider' );
		$this->save_quotes( $quotes );
	}

	private function rendering(): void {
		switch_theme( 'bingocommerce-bingothemev1' );
		\BingoCommerce\Core\StoreDetails::save( array_replace( \BingoCommerce\Core\StoreDetails::blank(), [ 'city' => 'Contact stays here' ] ) );
		$order = function ( string $html ): array {
			$order = [];
			foreach ( $this->document( $html )->query( '//section[not(ancestor::section)]' ) as $section ) { $order[] = $section->getAttribute( 'id' ) ?: $section->getAttribute( 'class' ); }
			return $order;
		};
		delete_option( Trust::OPTION ); $this->save_quotes( Testimonials::blank() );
		$html = $this->front(); $xpath = $this->document( $html );
		$this->same( [ 'hero', 'calendar', 'bc-home-trust', 'visit-section' ], $order( $html ), 'default trust row sits after the calendar and before Visit us' );
		$this->same( 4, $xpath->query( '//section[@class="bc-home-trust"]//li' )->length, 'default row has four items' );
		$this->same( 4, $xpath->query( '//section[@class="bc-home-trust"]//li/span[@class="bc-home-trust__icon"]/svg[@aria-hidden="true"][path or rect]' )->length, 'every default item has a drawn, decorative icon' );
		$this->ok( str_contains( $html, 'Restock daubers &amp; cards in seconds' ), 'default text escaped' );
		$this->same( 0, $xpath->query( '//section[@id="about"]' )->length, 'no testimonials: no section and no empty heading' );
		$this->ok( ! str_contains( $html, 'Loved by Bingo Communities' ), 'no testimonials: heading absent' );
		$this->export( 'default', $html );
		$this->save_trust( $this->trust( 2 ) );
		$xpath = $this->document( $this->front() );
		$this->same( [ 'Title 1', 'Title 2' ], array_map( static fn( $n ) => $n->textContent, iterator_to_array( $xpath->query( '//section[@class="bc-home-trust"]//strong' ) ) ), 'saved trust items render in order' );
		$this->same( 1, $xpath->query( '//section[@class="bc-home-trust"]//li//p' )->length, 'blank trust text renders no empty paragraph' );
		$this->save_trust( Trust::blank() );
		$html = $this->front();
		$this->same( [ 'hero', 'calendar', 'visit-section' ], $order( $html ), 'removing every trust item removes the row' );
		$this->save_trust( $this->trust( 4 ) );
		$one = $this->quotes( 1 ); $one['items'][0] = [ 'quote' => 'O\'Brien & "Sons" <b>', 'name' => 'Amara <i>O.</i>', 'role' => '' ];
		$this->ok( is_wp_error( $this->save_quotes( $one ) ), 'markup in testimonials refused' );
		$one['items'][0] = [ 'quote' => 'O\'Brien & "Sons" saved us time', 'name' => 'Amara O.', 'role' => '' ];
		$this->same( true, $this->save_quotes( $one ), 'one testimonial saves' );
		$html = $this->front(); $xpath = $this->document( $html );
		$this->same( [ 'hero', 'calendar', 'bc-home-trust', 'about', 'visit-section' ], $order( $html ), 'testimonials sit after the trust row and before Visit us' );
		$this->same( 1, $xpath->query( '//section[@id="about"]//figure' )->length, 'one testimonial renders one figure' );
		$this->same( 0, $xpath->query( '//section[@id="about"][@data-homepage-testimonials]' )->length + $xpath->query( '//*[@data-testimonial-dots]' )->length, 'one testimonial has no dots or script hook' );
		$this->ok( str_contains( $html, '&#8220;O&#039;Brien &amp; &quot;Sons&quot; saved us time&#8221;' ), 'quote escaped and wrapped in theme quotation marks' );
		$this->same( 0, $xpath->query( '//section[@id="about"]//figcaption/span' )->length, 'blank role renders nothing' );
		$this->export( 'one-testimonial', $html );
		$this->save_quotes( $this->quotes( 3 ) );
		$html = $this->front(); $xpath = $this->document( $html );
		$this->same( 3, $xpath->query( '//section[@id="about"][@data-homepage-testimonials]//figure[@data-testimonial]' )->length, 'three testimonials render three figures' );
		$this->same( 2, $xpath->query( '//section[@id="about"]//figure[@hidden]' )->length, 'only the first testimonial shows without JavaScript' );
		$this->same( 3, $xpath->query( '//div[@data-testimonial-dots][@hidden]/button[@type="button"]' )->length, 'three dots, hidden until JavaScript' );
		$this->same( 1, $xpath->query( '//div[@data-testimonial-dots]/button[@aria-current="true"]' )->length, 'first dot is current' );
		$this->same( 'Show testimonial 2 of 3', $xpath->evaluate( 'string(//div[@data-testimonial-dots]/button[2]/@aria-label)' ), 'dots have accessible names' );
		$this->export( 'three-testimonials', $html );
		$this->export( 'admin-testimonials', $this->screen( 'GET', [], 'testimonials' ) );
		remove_filter( Trust::FILTER, [ Trust::class, 'provide' ] ); remove_filter( Testimonials::FILTER, [ Testimonials::class, 'provide' ] );
		try { $this->same( [ 'hero', 'calendar', 'visit-section' ], $order( $this->front() ), 'Core provider absent: neither section renders' ); }
		finally { Trust::register(); Testimonials::register(); }
		$this->assets();
	}

	private function assets(): void {
		global $wp_query;
		$previous = $wp_query; $front = static fn() => 'posts';
		add_filter( 'pre_option_show_on_front', $front );
		try {
			$wp_query = new WP_Query(); $wp_query->is_home = false;
			\BingoCommerce\BingoThemeV1\Theme::assets();
			$this->same( false, wp_script_is( 'bingothemev1-homepage-testimonials', 'enqueued' ), 'testimonials script absent from other pages' );
			$wp_query->is_home = true;
			\BingoCommerce\BingoThemeV1\Theme::assets();
			$this->same( true, wp_script_is( 'bingothemev1-homepage-testimonials', 'enqueued' ), 'testimonials script enqueued on the front page' );
		} finally { $wp_query = $previous; remove_filter( 'pre_option_show_on_front', $front ); wp_dequeue_script( 'bingothemev1-homepage-testimonials' ); }
	}

	private function isolation( int $a, int $b, array $actors ): void {
		wp_set_current_user( $actors['manager'] );
		switch_to_blog( $a );
		try { $trust_nonce = wp_create_nonce( 'bingocommerce_homepage_trust:hall:' . $a ); $quote_nonce = wp_create_nonce( 'bingocommerce_homepage_testimonials:hall:' . $a ); $a_trust = Trust::stored(); $a_quotes = Testimonials::stored(); }
		finally { restore_current_blog(); }
		$before_a = $this->snapshot( $a );
		switch_to_blog( $b );
		try {
			set_current_screen( 'dashboard' );
			$this->same( Trust::defaults()['items'], Trust::provide(), 'Hall B shows its own defaults, not Hall A items' );
			$this->same( [], Testimonials::provide(), 'Hall B has no Hall A testimonials' );
			$this->error( 'bc_homepage_trust_denied', $this->save_trust( $this->trust( 1 ) ), 'Hall A Manager cannot save Hall B trust row' );
			$this->error( 'bc_homepage_testimonials_denied', $this->save_quotes( $this->quotes( 1 ) ), 'Hall A Manager cannot save Hall B testimonials' );
			wp_set_current_user( $this->admin ); $this->grant( $b, $actors['manager'], 'bc_hall_manager' ); wp_set_current_user( $actors['manager'] );
			$p = $this->trust_payload( $this->trust( 1 ) ); $p['bc_trust_nonce'] = $trust_nonce; $_SERVER['REQUEST_METHOD'] = 'POST';
			$this->error( 'bc_homepage_trust_nonce', TrustAdmin::handle_request( $p )['result'], 'multi-Hall Manager cannot reuse the Hall A trust nonce' );
			$p = $this->quote_payload( $this->quotes( 1 ) ); $p['bc_testimonials_nonce'] = $quote_nonce;
			$this->error( 'bc_homepage_testimonials_nonce', TestimonialsAdmin::handle_request( $p )['result'], 'multi-Hall Manager cannot reuse the Hall A testimonials nonce' );
			$b_trust = $this->trust( 1 ); $b_trust['items'][0]['title'] = 'Hall B only';
			$b_quotes = $this->quotes( 1 ); $b_quotes['items'][0]['name'] = 'Hall B customer';
			$this->same( true, $this->save_trust( $b_trust ), 'fresh Hall B trust form saves' );
			$this->same( true, $this->save_quotes( $b_quotes ), 'fresh Hall B testimonials form saves' );
		} finally { restore_current_blog(); }
		$this->same( $before_a, $this->snapshot( $a ), 'Hall B saves leave every Hall A table unchanged' );
		foreach ( [ $a, $b, $a ] as $site ) {
			switch_to_blog( $site );
			try {
				$this->same( $site === $a ? $a_trust['items'] : $b_trust['items'], Trust::provide(), 'trust filter follows A -> B -> A' );
				$this->same( $site === $a ? $a_quotes['items'] : $b_quotes['items'], Testimonials::provide(), 'testimonials filter follows A -> B -> A' );
			} finally { restore_current_blog(); }
		}
		switch_to_blog( get_main_site_id() );
		try {
			$this->same( [], Trust::provide(), 'main site still has no trust row after Hall switches' );
			$this->same( [], Testimonials::provide(), 'main site still has no testimonials after Hall switches' );
			$this->error( 'bc_homepage_trust_denied', $this->save_trust( $this->trust( 1 ) ), 'main site refuses trust saves' );
			$this->error( 'bc_homepage_testimonials_denied', $this->save_quotes( $this->quotes( 1 ) ), 'main site refuses testimonial saves' );
		} finally { restore_current_blog(); }
	}

	private function hall( string $label ): int {
		wp_set_current_user( $this->admin ); add_filter( 'pre_wp_mail', '__return_true' );
		$site = wp_insert_site( [ 'domain' => 'homepage-trust-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Homepage Trust Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site;
		update_blog_option( $site, self::OWNER, $this->token );
		return $site;
	}

	private function actors( int $site ): array {
		$actors = [];
		foreach ( [ 'manager' => 'bc_hall_manager', 'operator' => 'bc_game_operator', 'customer' => 'subscriber', 'wp_admin' => 'administrator' ] as $label => $role ) {
			$id = wp_insert_user( [ 'user_login' => 'trust-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Actor creation failed.' ); }
			$this->users[] = $id; $actors[ $label ] = $id; update_user_meta( $id, self::OWNER, $this->token );
			$this->grant( $site, $id, $role );
		}
		return $actors;
	}

	private function grant( int $site, int $user, string $role ): void {
		global $wpdb;
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, self::OWNER )
			|| ! in_array( $user, $this->users, true ) || $this->token !== get_user_meta( $user, self::OWNER, true ) ) { throw new RuntimeException( 'Role fixture ownership refused.' ); }
		// Same owned-user setup as the homepage slider suite; the staff writer guard stays enabled.
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
			if ( $this->token !== get_user_meta( $user, self::OWNER, true ) ) { throw new RuntimeException( 'Actor cleanup ownership refused.' ); }
			foreach ( $this->sites as $site ) {
				if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER ) ) { throw new RuntimeException( 'Role cleanup ownership refused.' ); }
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			wpmu_delete_user( $user );
			$this->same( false, get_userdata( $user ), 'owned disposable actor deleted' );
		}
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER ) ) { throw new RuntimeException( 'Hall cleanup ownership refused.' ); }
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
( new HomepageTrustTestimonialsTest() )->run();
