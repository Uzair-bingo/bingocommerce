<?php
/**
 * Shop by Category: current-Hall configuration, media, taxonomy, administration and rendering.
 * BC_TEST_DB=bingocommerce_state_recon php tests/homepage-categories.php
 * Optional BC_CATEGORY_PREVIEW_DIR exports static markup in an existing /private/tmp directory.
 * BC_CATEGORY_RECOVERY_BROWSER=1 adds real keyboard/form recovery via local Node/Playwright:
 * set BC_NODE_EXECUTABLE, BC_PLAYWRIGHT_MODULE and BC_CHROME_EXECUTABLE as needed.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
$_SERVER['SERVER_NAME'] = BC_FIXTURE_DOMAIN;
foreach ( [ 'plugin.php', 'screen.php', 'class-wp-screen.php', 'template.php', 'media.php', 'ajax-actions.php' ] as $file ) { require_once ABSPATH . 'wp-admin/includes/' . $file; }
require_once ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/functions.php';

use BingoCommerce\Core\HomepageCategories as Categories;
use BingoCommerce\Core\HomepageCategoriesAdmin as CardsAdmin;
use BingoCommerce\Core\HomepageSlider as Slider;
use BingoCommerce\Core\HomepageAdmin as Admin;
use BingoCommerce\Core\StoreDetails;

final class HomepageCategoriesTest {
	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private int $passes = 0;
	private int $failures = 0;

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' ); $this->token = wp_generate_uuid4();
		$baseline = []; foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		try {
			$this->same( [], Categories::provide(), 'no category section on main site' );
			$a = $this->hall( 'a' ); $b = $this->hall( 'b' ); $actors = $this->actors( $a ); $terms = [];
			foreach ( [ $a => 'Alpha', $b => 'Beta' ] as $site => $label ) {
				switch_to_blog( $site );
				try {
					WC_Install::create_tables(); WC_Install::create_roles(); \BingoCommerce\Core\Roles::sync_current_site();
					switch_theme( 'bingocommerce-bingothemev1' );
					$this->same( 900001, $this->attachment( 900001, $label ), 'overlapping attachment ID created in owned Hall' );
					$term = wp_insert_term( $label . ' empty category', 'product_cat' );
					if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
					$terms[ $site ] = (int) $term['term_id'];
				} finally { restore_current_blog(); }
			}
			$this->same( $terms[ $a ], $terms[ $b ], 'fixture has overlapping current-Hall category IDs' );
			switch_to_blog( $a );
			try {
				set_current_screen( 'dashboard' ); wp_set_current_user( $actors['manager'] );
				$this->same( false, get_option( Categories::OPTION, false ), 'new Hall has no seeded cards' );
				$this->same( [], Categories::provide(), 'new Hall hides section' );
				$this->screen(); $this->same( false, get_option( Categories::OPTION, false ), 'GET never creates category option' );
				$valid = $this->config();
				$this->saves_and_rendering( $valid, $terms[ $a ] );
				if ( '1' === getenv( 'BC_CATEGORY_RECOVERY_BROWSER' ) ) {
					$this->legacy_background_recovery( $valid, $terms[ $a ], $b );
				} else { echo "SKIP browser-backed legacy recovery: set BC_CATEGORY_RECOVERY_BROWSER=1 with Node/Playwright.\n"; }
				$this->validation( $valid );
				$this->requests_and_roles( $valid, $actors );
				wp_set_current_user( $actors['manager'] );
				$this->same( true, $this->submit( $valid )['result'], 'restore valid owned configuration' );
				$media = $this->media_query( 900001 );
				$this->same( 900001, $media['data'][0]['id'] ?? null, 'native Media Library query returns current Hall attachment' );
			} finally { restore_current_blog(); }
			$this->isolation( $a, $b, $terms, $actors );
			switch_to_blog( $a );
			try { $this->unavailable( $valid, $terms[ $a ] ); } finally { restore_current_blog(); }
		} catch ( Throwable $e ) {
			++$this->failures; fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally { $this->cleanup(); }
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Homepage categories: %d passed, %d failed.\n", $this->passes, $this->failures ); ob_end_flush(); exit( $this->failures ? 1 : 0 );
	}

	private function attachment( int $id, string $name ): int {
		$id = wp_insert_attachment( [ 'import_id' => $id, 'post_title' => $name, 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ] );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Fixture attachment failed.' ); }
		$file = 'category-fixture-' . $this->token . '/' . $name . '.png';
		update_post_meta( $id, '_wp_attached_file', $file );
		update_post_meta( $id, '_wp_attachment_image_alt', $name . ' supplies' );
		wp_update_attachment_metadata( $id, [ 'width' => 1122, 'height' => 1402, 'file' => $file, 'sizes' => [] ] );
		return $id;
	}
	private function config(): array {
		$card = Categories::card(); $card['image_id'] = 900001; $card['destination']['url'] = 'https://example.com/shop/?q=bingo&sort=name';
		return [ 'version' => 1, 'background_id' => 0, 'cards' => [ $card ] ];
	}
	private function payload( array $config ): array {
		return [ 'bc_category_nonce' => wp_create_nonce( 'bingocommerce_homepage_categories:hall:' . get_current_blog_id() ), 'bc_category_action' => 'save', Categories::OPTION => $config, 'bc_category_count' => (string) count( $config['cards'] ?? [] ), 'bc_category_complete' => '1' ];
	}
	private function submit( array $config ): array {
		$_SERVER['REQUEST_METHOD'] = 'POST'; return CardsAdmin::handle_request( $this->payload( $config ) );
	}
	private function screen( string $method = 'GET', array $post = [], mixed $tab = 'categories' ): string {
		$query = $_GET; unset( $_GET['tab'] ); if ( null !== $tab ) { $_GET['tab'] = $tab; }
		$_SERVER['REQUEST_METHOD'] = $method; $_POST = wp_slash( $post ); ob_start();
		try { Admin::render(); return (string) ob_get_contents(); } finally { ob_end_clean(); $_POST = []; $_GET = $query; }
	}
	private function assert_tab( string $html, string $tab ): void {
		$document = new DOMDocument(); @$document->loadHTML( $html ); $xpath = new DOMXPath( $document );
		$active = $xpath->query( '//nav[@aria-label="Homepage settings"]/a[@aria-current="page"]' );
		$this->same( 1, $active->length, 'exactly one current settings tab' );
		$this->same( Admin::tab_url( $tab ), $active->item( 0 )->getAttribute( 'href' ), "{$tab}: current tab links to current Hall" );
		$id = 'categories' === $tab ? 'bc-category-form' : 'bc-form';
		$this->same( 1, $xpath->query( '//form[@id="' . $id . '"]' )->length, "{$tab}: requested form rendered" );
		$this->same( 1, $xpath->query( '//form' )->length, "{$tab}: other editor is absent" );
		$this->same( Admin::tab_url( $tab ), $xpath->query( '//form' )->item( 0 )->getAttribute( 'action' ), "{$tab}: save targets own tab" );
	}
	private function front(): string {
		// CLI switches themes after bootstrap; refresh WordPress's template paths for this render.
		wp_set_template_globals();
		ob_start(); try { include ABSPATH . 'wp-content/themes/bingocommerce-bingothemev1/front-page.php'; return (string) ob_get_contents(); } finally { ob_end_clean(); }
	}
	private function export( string $name, string $html ): void {
		$directory = getenv( 'BC_CATEGORY_PREVIEW_DIR' ); if ( ! $directory ) { return; }
		$real = realpath( $directory );
		if ( false === $real || ! str_starts_with( $real, '/private/tmp/' ) ) { throw new RuntimeException( 'Preview directory guard refused.' ); }
		if ( false === file_put_contents( $real . '/' . $name . '.html', $html ) ) { throw new RuntimeException( 'Preview export failed.' ); }
	}
	private function saves_and_rendering( array $valid, int $term ): void {
		global $wpdb;
		$slider = [ 'version' => 1, 'slides' => [ [ 'image_id' => 900001, 'image_position' => 'center', 'heading' => 'Keep slider', 'text' => '', 'buttons' => [] ] ] ];
		Slider::save( $slider );
		StoreDetails::save( array_replace( StoreDetails::blank(), [ 'address_line_1' => '12 Alpha Street', 'phone' => '+1 813 555 0101' ] ) );
		$details = StoreDetails::stored();
		$taxonomy = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY term_taxonomy_id', $wpdb->term_taxonomy ), ARRAY_A );
		foreach ( range( 0, 5 ) as $count ) {
			$value = $valid; $value['cards'] = array_fill( 0, $count, $valid['cards'][0] );
			foreach ( $value['cards'] as $i => &$card ) { if ( $i > 0 ) { $card['text'] = 'Card ' . $i; } } unset( $card );
			$this->same( true, $this->submit( $value )['result'], "{$count} cards save" );
			$this->same( $value, Categories::stored(), "{$count} cards retain configured order" );
			$html = $this->front();
			$this->same( $count, substr_count( $html, 'class="bc-category-card"' ), "{$count} links rendered" );
			$this->same( $count > 0, str_contains( $html, 'id="bc-category-heading"' ), "{$count} cards: section visibility correct" );
			$this->ok( str_contains( $html, 'Keep slider' ) && str_contains( $html, 'visit-map' ) && str_contains( $html, 'tel:+18135550101' ), 'slider, map and contact preserved' );
			if ( $count ) { $this->ok( strpos( $html, 'data-homepage-slider' ) < strpos( $html, 'id="bc-category-heading"' ) && strpos( $html, 'id="bc-category-heading"' ) < strpos( $html, 'visit-map' ), 'section order: slider -> categories -> map' ); }
			$this->export( 'cards-' . $count, $html );
		}
		$this->same( $slider, Slider::stored(), 'category saves preserve slider settings' );
		$this->same( $details, StoreDetails::stored(), 'category saves preserve map Store Details' );
		$aut = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name=%s', $wpdb->options, Categories::OPTION ) );
		$this->ok( in_array( $aut, [ 'no', 'off' ], true ), 'category option is non-autoloaded' );
		$both = $valid; $both['cards'][] = $valid['cards'][0]; $both['cards'][1]['destination'] = [ 'type' => 'category', 'url' => '', 'category_id' => $term ];
		$bg = $this->attachment( 900002, 'Background' ); $both['background_id'] = $bg; $both['cards'][0]['background_id'] = $bg;
		$this->same( true, $this->submit( $both )['result'], 'optional backgrounds and both destinations save' );
		$provided = Categories::provide();
		$this->same( 'Alpha supplies', $provided['cards'][0]['name'], 'image-only custom link uses descriptive image ALT' );
		$this->same( 'Alpha empty category', $provided['cards'][1]['name'], 'image-only category link uses category name before image ALT' );
		$this->same( get_term_link( $term, 'product_cat' ), $provided['cards'][1]['url'], 'native current category archive link resolved' );
		$this->same( $bg, $provided['background_id'], 'section background stays separate' );
		$this->same( $bg, $provided['cards'][0]['background_id'], 'card background stays separate from main image' );
		$this->export( 'backgrounds', $this->front() );
		foreach ( [ 1, 5 ] as $count ) {
			$coverage = $valid; $coverage['background_id'] = $bg; $coverage['cards'] = array_fill( 0, $count, $valid['cards'][0] );
			foreach ( $coverage['cards'] as $i => &$card ) { $card['text'] = 'Category ' . ( $i + 1 ); } unset( $card );
			$coverage['cards'][0]['background_id'] = $bg;
			$this->same( true, $this->submit( $coverage )['result'], "{$count}-card section-background fixture saves" );
			$this->export( 'coverage-' . $count, $this->front() );
		}
		$artwork = $valid; $artwork['background_id'] = $bg; $artwork['cards'] = array_fill( 0, 4, $valid['cards'][0] );
		$this->submit( $artwork ); $this->export( 'reference-match', $this->front() ); $this->submit( $both );
		$before_tabs = [ Categories::stored(), Slider::stored(), StoreDetails::stored() ];
		foreach ( [ null, 'slider', 'categories', 'unknown', [ 'categories' ], '<script>alert(1)</script>' ] as $tab ) {
			$this->assert_tab( $this->screen( 'GET', [], $tab ), 'categories' === $tab ? 'categories' : 'slider' );
		}
		$this->same( $before_tabs, [ Categories::stored(), Slider::stored(), StoreDetails::stored() ], 'GET tab navigation does not change settings' );
		$this->export( 'admin-slider', $this->screen( 'GET', [], 'slider' ) );
		$this->ok( str_contains( $this->screen(), 'Alpha empty category' ), 'empty categories included in editor' );
		wp_update_term( $term, 'product_cat', [ 'name' => 'Renamed category', 'slug' => 'renamed-category' ] );
		$this->same( get_term_link( $term, 'product_cat' ), Categories::provide()['cards'][1]['url'], 'category rename/slug change reflected without settings save' );
		wp_update_term( $term, 'product_cat', [ 'name' => 'Supplies > Gifts & Games' ] );
		$this->same( 'Supplies > Gifts & Games', Categories::provide()['cards'][1]['name'], 'native category punctuation remains usable as a plain accessible name' );
		wp_update_term( $term, 'product_cat', [ 'name' => 'Alpha empty category', 'slug' => 'alpha-empty-category' ] );
		// Compare taxonomy after restoring deliberate fixture edits: config operations never assign products.
		$this->same( $taxonomy, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY term_taxonomy_id', $wpdb->term_taxonomy ), ARRAY_A ), 'display configuration never changes taxonomy relationships/counts' );
		$saved_categories = Categories::stored();
		$slider_post = [ '_wpnonce' => wp_create_nonce( 'bingocommerce_homepage_slider:hall:' . get_current_blog_id() ), Slider::OPTION => $slider, 'bc_slider_count' => '1', 'bc_slider_complete' => '1' ];
		$html = $this->screen( 'POST', $slider_post );
		$this->assert_tab( $html, 'slider' );
		$this->ok( str_contains( $html, 'Homepage slider saved.' ) && ! str_contains( $html, 'Shop by Category saved.' ), 'slider POST has only slider success notice' );
		$this->same( $saved_categories, Categories::stored(), 'slider form save preserves category settings' );
		$html = $this->screen( 'POST', $this->payload( $valid ), 'slider' );
		$this->assert_tab( $html, 'categories' );
		$this->ok( str_contains( $html, 'Shop by Category saved.' ) && ! str_contains( $html, 'Homepage slider saved.' ), 'category POST has only category success notice' );
		$this->same( $slider, Slider::stored(), 'category form save preserves slider settings' );
		$bad_slider = $slider_post; $bad_slider[ Slider::OPTION ]['slides'][0]['heading'] = str_repeat( 'x', 81 );
		$html = $this->screen( 'POST', $bad_slider, 'categories' ); $this->assert_tab( $html, 'slider' );
		$this->ok( str_contains( $html, str_repeat( 'x', 81 ) ) && str_contains( $html, 'The slider needs correcting.' ), 'slider errors and submitted text stay on slider tab' );
		$this->same( [ $valid, $slider ], [ Categories::stored(), Slider::stored() ], 'invalid slider tab save preserves both settings' );
		$with_background = $valid; $with_background['cards'][0]['background_id'] = $bg;
		$this->submit( $with_background );
		$html = $this->screen();
		$this->ok( ! str_contains( $html, 'Card background (optional)' ), 'optional card-background picker is removed' );
		$document = new DOMDocument(); @$document->loadHTML( $html ); $xpath = new DOMXPath( $document );
		$field = $xpath->query( '//form[@id="bc-category-form"]//input[@name="bc_homepage_categories[cards][0][background_id]"]' )->item( 0 );
		$this->ok( $field instanceof DOMElement && 'hidden' === $field->getAttribute( 'type' ), 'existing card background retained in hidden form field' );
		$round_trip = $valid; $round_trip['cards'][0]['background_id'] = $field->getAttribute( 'value' );
		$this->same( true, $this->submit( $round_trip )['result'], 'form without card-background picker saves' );
		$this->same( $with_background, Categories::stored(), 'form save preserves existing card background' );
		$this->export( 'admin', $this->screen() );
		$escaped = $valid; $escaped['cards'][0]['text'] = 'Books "Plus" & Gifts'; $this->submit( $escaped );
		$html = $this->front(); $this->ok( str_contains( $html, 'aria-label="Books &quot;Plus&quot; &amp; Gifts"' ) && str_contains( $html, 'Books &quot;Plus&quot; &amp; Gifts</span>' ), 'visible text and accessible name escaped' );
		$long = $valid; $long['cards'][0]['text'] = str_repeat( 'Long label ', 7 ); $this->submit( $long ); $this->export( 'long', $this->front() );
		$this->submit( $valid );
	}
	private function invalid( array $input, string $label ): void {
		$before = Categories::stored(); $slider = Slider::stored();
		$result = $this->submit( $input );
		$this->ok( is_wp_error( $result['result'] ), $label . ': rejected' );
		$this->same( $before, Categories::stored(), $label . ': saved config identical' );
		$this->same( $slider, Slider::stored(), $label . ': slider unchanged' );
	}
	/** Return the actual browser form submission, without writing any settings yet. */
	private function browser_post( string $html, string $action, bool $error_links = false ): array {
		$process = proc_open( [ getenv( 'BC_NODE_EXECUTABLE' ) ?: 'node', __DIR__ . '/homepage-categories-recovery-browser.cjs' ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Recovery browser could not start.' ); }
		fwrite( $pipes[0], json_encode( [ 'html' => $html, 'action' => $action, 'index' => 1, 'errorLinks' => $error_links ], JSON_THROW_ON_ERROR ) ); fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Recovery browser failed: ' . $errors ); }
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		foreach ( $result['checks'] as $label ) { $this->ok( true, 'browser recovery: ' . $label ); }
		parse_str( $result['submission'], $posted ); $_SERVER['REQUEST_METHOD'] = 'POST';
		return $posted;
	}
	private function legacy_background_recovery( array $valid, int $term, int $other_hall ): void {
		$other_before = $this->snapshot( $other_hall ); $slider = Slider::stored(); $details = StoreDetails::stored();
		$deleted = $this->attachment( 900006, 'Legacy background to delete' );
		$retained = $this->attachment( 900007, 'Valid retained background' );
		$main = $this->attachment( 900008, 'Distinct second main image' );
		$config = $valid; $config['background_id'] = 900002;
		$config['cards'][0]['background_id'] = $retained; $config['cards'][0]['text'] = 'First card stays first';
		$config['cards'][] = array_replace( $valid['cards'][0], [ 'image_id' => $main, 'background_id' => $deleted, 'text' => 'Second card stays second', 'destination' => [ 'type' => 'category', 'url' => '', 'category_id' => $term ] ] );
		$this->same( true, $this->submit( $config )['result'], 'legacy fixture saves valid individual backgrounds' );
		$posted = $this->browser_post( $this->screen(), 'save' );
		$this->same( true, CardsAdmin::handle_request( $posted )['result'], 'ordinary rendered form submission accepts valid backgrounds' );
		$this->same( $config, Categories::stored(), 'ordinary browser save preserves valid backgrounds and every card field' );
		wp_delete_attachment( $deleted, true );
		$this->same( null, get_post( $deleted ), 'legacy background attachment deleted only by fixture setup' );
		$posted = $this->browser_post( $this->screen(), 'save' );
		$this->same( $config, Categories::stored(), 'loading and submitting browser form never silently clears unavailable background' );
		$outcome = CardsAdmin::handle_request( $posted );
		$this->ok( is_wp_error( $outcome['result'] ) && isset( $outcome['errors']['card-1-background_id'] ), 'normal rendered form submission rejects deleted retained background' );
		$this->same( $config, Categories::stored(), 'rejected legacy submission preserves all saved fields' );
		$failed_form = $this->screen( 'POST', $posted );
		$cleared = $this->browser_post( $failed_form, 'remove', true );
		$this->same( $config, Categories::stored(), 'keyboard removal remains local until save handler runs' );
		$this->same( true, CardsAdmin::handle_request( $cleared )['result'], 'rendered form saves after explicit legacy background removal' );
		$expected = $config; $expected['cards'][1]['background_id'] = 0;
		$this->same( $expected, Categories::stored(), 'recovery changes only selected background: main image, text, destination, order and section background preserved' );
		$this->ok( get_post( $main ) instanceof WP_Post && get_post( $retained ) instanceof WP_Post, 'recovery does not delete other Media Library attachments' );
		$reordered = $this->browser_post( $this->screen(), 'reorder' );
		$this->same( true, CardsAdmin::handle_request( $reordered )['result'], 'rendered form saves keyboard reordering' );
		$expected['cards'] = array_reverse( $expected['cards'] );
		$this->same( $expected, Categories::stored(), 'reorder retains valid background with its card and every other field' );
		$cleared = $this->browser_post( $this->screen(), 'remove' );
		$this->same( $expected, Categories::stored(), 'valid background removal also waits for save' );
		$this->same( true, CardsAdmin::handle_request( $cleared )['result'], 'explicit removal of valid background saves through rendered form' );
		$expected['cards'][1]['background_id'] = 0;
		$this->same( $expected, Categories::stored(), 'valid-background removal changes only its reference' );
		$this->ok( get_post( $retained ) instanceof WP_Post, 'explicit removal never deletes the background attachment' );
		$this->same( $slider, Slider::stored(), 'legacy recovery leaves slider settings untouched' );
		$this->same( $details, StoreDetails::stored(), 'legacy recovery leaves map/contact settings untouched' );
		$this->same( $other_before, $this->snapshot( $other_hall ), 'legacy recovery leaves every table in the other owned Hall unchanged' );
		$this->submit( $valid );
	}
	private function validation( array $valid ): void {
		foreach ( [ 'background_id' => [ -1, '00', '1.5', [], PHP_INT_MAX ], 'version' => [ 2, null ] ] as $field => $bad_values ) {
			foreach ( $bad_values as $bad ) { $v = $valid; $v[ $field ] = $bad; $this->invalid( $v, 'invalid ' . $field ); }
		}
		foreach ( [ 'image_id' => [ 0, -1, [], '900001x', 900999 ], 'background_id' => [ 900999, null ], 'text' => [ str_repeat( 'x', 81 ), '<b>text</b>', "line\nbreak", "\xFF", [] ] ] as $field => $bad_values ) {
			foreach ( $bad_values as $bad ) { $v = $valid; $v['cards'][0][ $field ] = $bad; $this->invalid( $v, 'invalid card ' . $field ); }
		}
		foreach ( [ '', 'javascript:alert(1)', 'https://user:pass@example.com/', 'https://example.com/?add-to-cart=12', 'https://example.com/?wc-ajax=add_to_cart', '/shop/', 'https://example.com/wp-admin/', 'https://example.com/%0a', 'https://example.com/' . str_repeat( 'x', 2048 ) ] as $url ) {
			$v = $valid; $v['cards'][0]['destination']['url'] = $url; $this->invalid( $v, 'invalid navigation URL' );
		}
		$v = $valid; $v['cards'] = array_fill( 0, 6, $valid['cards'][0] ); $this->invalid( $v, 'six cards' );
		$v = $valid; $v['cards'] = [ 2 => $valid['cards'][0] ]; $this->invalid( $v, 'sparse card list' );
		$v = $valid; $v['cards'][0]['extra'] = 1; $this->invalid( $v, 'unknown card field' );
		$v = $valid; $v['hall_id'] = get_current_blog_id(); $this->invalid( $v, 'embedded tenant selector' );
		$wrong = wp_insert_term( 'Not a product category', 'category' );
		foreach ( [ [ 'type' => 'invalid', 'url' => 'https://example.com/', 'category_id' => 0 ], [ 'type' => 'url', 'url' => 'https://example.com/', 'category_id' => 1 ], [ 'type' => 'category', 'url' => 'https://example.com/', 'category_id' => 1 ], [ 'type' => 'category', 'url' => '', 'category_id' => $wrong['term_id'] ], [ 'type' => 'category', 'url' => '', 'category_id' => 900999 ], [] ] as $d ) {
			$v = $valid; $v['cards'][0]['destination'] = $d; $this->invalid( $v, 'invalid/ambiguous/foreign taxonomy destination' );
		}
		delete_post_meta( 900001, '_wp_attachment_image_alt' ); $this->invalid( $valid, 'no visible text, category name or ALT; filename never used' );
		$v = $valid; $v['cards'][0]['text'] = str_repeat( 'x', 80 ); $this->same( true, $this->submit( $v )['result'], '80-character visible label supplies accessible name without ALT' );
		update_post_meta( 900001, '_wp_attachment_image_alt', 'Alpha supplies' ); $this->submit( $valid );
		$v['cards'][0]['text'] = str_repeat( 'x', 81 ); $html = $this->screen( 'POST', $this->payload( $v ), 'slider' );
		$this->assert_tab( $html, 'categories' );
		$this->ok( str_contains( $html, str_repeat( 'x', 81 ) ) && str_contains( $html, 'aria-invalid="true"' ) && str_contains( $html, 'Shop by Category needs correcting.' ) && ! str_contains( $html, 'Homepage slider saved.' ), 'invalid submission redisplays value and category field error only' );
	}
	private function requests_and_roles( array $valid, array $actors ): void {
		$before = Categories::stored();
		foreach ( [ 'bc_category_nonce', 'bc_category_action', 'bc_category_count', 'bc_category_complete', Categories::OPTION ] as $missing ) {
			$p = $this->payload( $valid ); unset( $p[ $missing ] ); $_SERVER['REQUEST_METHOD'] = 'POST';
			$this->ok( is_wp_error( CardsAdmin::handle_request( $p )['result'] ), 'missing ' . $missing . ' rejected' );
			$this->same( $before, Categories::stored(), 'truncated payload never empties configuration' );
		}
		foreach ( [ 'blog_id' => 1, 'hall_id' => 1, 'site_id' => 1, 'bc_slider_complete' => '1' ] as $key => $value ) {
			$p = $this->payload( $valid ); $p[ $key ] = $value; $this->ok( is_wp_error( CardsAdmin::handle_request( $p )['result'] ), 'unexpected ' . $key . ' refused' );
		}
		$p = $this->payload( $valid ); $p['bc_category_count'] = '0'; $this->ok( is_wp_error( CardsAdmin::handle_request( $p )['result'] ), 'mismatched count rejected' );
		$p = $this->payload( Categories::blank() ); unset( $p[ Categories::OPTION ]['cards'] ); $this->same( true, CardsAdmin::handle_request( $p )['result'], 'explicit zero and completion permit removing all cards' );
		$this->same( [], Categories::provide(), 'explicit empty hides section' ); $this->submit( $valid );
		foreach ( [ $actors['operator'], $actors['customer'], 0 ] as $user ) {
			wp_set_current_user( $user ); $this->error( 'bc_homepage_categories_denied', $this->submit( $valid )['result'], 'unauthorized role or logged-out save refused' );
		}
		wp_set_current_user( $this->admin ); $this->same( true, $this->submit( $valid )['result'], 'Platform Admin saves' );
		wp_set_current_user( $actors['manager'] ); $_SERVER['REQUEST_METHOD'] = 'GET';
		$this->error( 'bc_homepage_categories_request', CardsAdmin::handle_request( $this->payload( $valid ) )['result'], 'GET cannot save' );
		$this->same( $before, Categories::stored(), 'request/authorization failures preserve original configuration' );
		$p = $this->payload( $valid ); $p[ Slider::OPTION ] = Slider::blank();
		$slider = Slider::stored(); $html = $this->screen( 'POST', $p );
		$this->ok( str_contains( $html, 'Shop by Category needs correcting.' ) && ! str_contains( $html, 'Homepage slider saved.' ), 'mixed form payload shows category error without dispatching slider save' );
		$this->same( $slider, Slider::stored(), 'mixed form payload preserves slider' );
		$this->same( $before, Categories::stored(), 'mixed form payload preserves categories' );
		$html = $this->screen( 'POST', [ 'bc_category_nonce' => $this->payload( $valid )['bc_category_nonce'] ] );
		$this->ok( str_contains( $html, 'Shop by Category needs correcting.' ), 'nonce-only truncated category POST retains its own section error notice' );
		$this->same( $before, Categories::stored(), 'nonce-only truncated request preserves category settings' );
	}
	private function unavailable( array $valid, int $term ): void {
		$v = $valid; $v['background_id'] = 900002; $v['cards'][0]['background_id'] = 900002; $this->submit( $v );
		wp_delete_attachment( 900002, true ); $before = get_option( Categories::OPTION ); $provided = Categories::provide();
		$this->same( 0, $provided['background_id'], 'deleted optional section background falls back' );
		$this->same( 0, $provided['cards'][0]['background_id'], 'deleted optional card background falls back' );
		$this->same( $before, get_option( Categories::OPTION ), 'background reads never rewrite settings' );
		$this->ok( str_contains( $this->screen(), 'Some saved category content is unavailable.' ), 'unavailable references produce administrator warning' );
		$this->submit( $valid ); wp_update_post( [ 'ID' => 900001, 'post_status' => 'trash' ] );
		$this->same( [], Categories::provide(), 'trashed main image omits card and section' ); wp_update_post( [ 'ID' => 900001, 'post_status' => 'inherit' ] );
		$v = $valid; $v['cards'][0]['destination'] = [ 'type' => 'category', 'url' => '', 'category_id' => $term ]; $this->submit( $v );
		$broken = static fn() => new WP_Error( 'test', 'No link' ); add_filter( 'term_link', $broken );
		try { $this->same( [], Categories::provide(), 'term link errors safely omit section' ); $this->invalid( $v, 'unavailable category link save' ); } finally { remove_filter( 'term_link', $broken ); }
		$before = get_option( Categories::OPTION ); wp_delete_term( $term, 'product_cat' );
		$this->same( [], Categories::provide(), 'deleted category omits card even with image ALT available' );
		$this->same( $before, get_option( Categories::OPTION ), 'missing category read preserves configuration' );
		$this->ok( str_contains( $this->screen(), 'Unavailable category' ), 'deleted category remains repairable in editor' );
		$this->submit( $valid ); remove_filter( Categories::FILTER, [ Categories::class, 'provide' ] );
		try { $this->ok( ! str_contains( $this->front(), 'id="bc-category-heading"' ), 'Core provider absent omits section safely' ); } finally { Categories::register(); }
		delete_post_meta( 900001, '_wp_attachment_image_alt' );
		$this->same( [], Categories::provide(), 'removed ALT omits an otherwise unnamed card' );
		update_post_meta( 900001, '_wp_attachment_image_alt', 'Alpha supplies' );
		$deleted = $valid; $deleted['cards'][0]['image_id'] = $this->attachment( 900004, 'Temporary main artwork' ); $this->submit( $deleted );
		$before = get_option( Categories::OPTION ); wp_delete_attachment( 900004, true );
		$this->same( [], Categories::provide(), 'deleted main image omits card and section' );
		$this->same( $before, get_option( Categories::OPTION ), 'deleted main-image read never repairs settings' );
		// Removing configuration references does not delete Media Library attachments.
		$this->submit( Categories::blank() ); $this->ok( get_post( 900001 ) instanceof WP_Post, 'removing all cards preserves attachment' );
	}
	private function isolation( int $a, int $b, array $terms, array $actors ): void {
		// Reads use overlapping category IDs; a separate A-only term tests foreign-ID refusal.
		switch_to_blog( $a );
		try {
			$term = wp_insert_term( 'Alpha only', 'product_cat' ); $foreign_term = (int) $term['term_id']; $a_term = $terms[ $a ];
			$nonce = wp_create_nonce( 'bingocommerce_homepage_categories:hall:' . $a );
			$foreign_image = $this->attachment( 900003, 'Only-A' );
			$v = $this->config(); $v['cards'][0]['destination'] = [ 'type' => 'category', 'url' => '', 'category_id' => $a_term ]; $this->submit( $v );
		} finally { restore_current_blog(); }
		switch_to_blog( $b );
		try {
			$this->error( 'bc_homepage_categories_denied', $this->submit( $this->config() )['result'], 'unassigned Hall A Manager cannot save Hall B' );
			wp_set_current_user( $this->admin );
			$v = $this->config(); $v['cards'][0]['image_id'] = $foreign_image; $this->invalid( $v, 'Hall A-only media refused in Hall B' );
			$v = $this->config(); $v['cards'][0]['destination'] = [ 'type' => 'category', 'url' => '', 'category_id' => $foreign_term ]; $this->invalid( $v, 'Hall A-only term refused in Hall B' );
			$v['cards'][0]['destination']['category_id'] = $terms[ $b ]; $this->same( true, $this->submit( $v )['result'], 'Platform Admin saves Hall B local term' );
			$this->grant( $b, $actors['manager'], 'bc_hall_manager' ); wp_set_current_user( $actors['manager'] );
			$p = $this->payload( $v ); $p['bc_category_nonce'] = $nonce;
			$this->error( 'bc_homepage_categories_nonce', CardsAdmin::handle_request( $p )['result'], 'Hall A nonce rejected in Hall B even for assigned Manager' );
			$media = $this->media_query( 900001 ); $this->same( 'Beta', $media['data'][0]['title'] ?? '', 'native Media Library resolves overlapping ID to Hall B' );
		} finally { restore_current_blog(); }
		foreach ( [ [ $a, 'Alpha empty category', 'Alpha supplies' ], [ $b, 'Beta empty category', 'Beta supplies' ], [ $a, 'Alpha empty category', 'Alpha supplies' ] ] as [ $site, $name, $alt ] ) {
			switch_to_blog( $site ); try {
				$before = get_option( Categories::OPTION ); $value = Categories::provide();
				$this->same( $name, $value['cards'][0]['name'], 'A -> B -> A category name follows Hall' );
				$this->same( $alt, get_post_meta( $value['cards'][0]['image_id'], '_wp_attachment_image_alt', true ), 'overlapping media ID follows Hall' );
				$this->same( get_term_link( $before['cards'][0]['destination']['category_id'], 'product_cat' ), $value['cards'][0]['url'], 'current-Hall archive URL follows switches' );
				$this->same( $before, get_option( Categories::OPTION ), 'read never mutates settings' );
			} finally { restore_current_blog(); }
		}
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
		$site = wp_insert_site( [ 'domain' => 'homepage-categories-' . $label . '-' . wp_generate_password( 8, false ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Homepage Categories Hall', 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = $site;
		update_blog_option( $site, 'bc_homepage_categories_test_owner', $this->token );
		return $site;
	}

	private function actors( int $site ): array {
		$actors = [];
		foreach ( [ 'manager' => 'bc_hall_manager', 'operator' => 'bc_game_operator', 'customer' => 'subscriber', 'wp_admin' => 'administrator' ] as $label => $role ) {
			$id = wp_insert_user( [ 'user_login' => 'categories-' . $label . '-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => '' ] );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Actor creation failed.' ); }
			$this->users[] = $id; $actors[ $label ] = $id; update_user_meta( $id, 'bc_homepage_categories_test_owner', $this->token );
			$this->grant( $site, $id, $role );
		}
		return $actors;
	}

	private function grant( int $site, int $user, string $role ): void {
		global $wpdb;
		if ( $site <= 4 || ! in_array( $site, $this->sites, true ) || $this->token !== get_blog_option( $site, 'bc_homepage_categories_test_owner' )
			|| ! in_array( $user, $this->users, true ) || $this->token !== get_user_meta( $user, 'bc_homepage_categories_test_owner', true ) ) { throw new RuntimeException( 'Role fixture ownership refused.' ); }
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
			if ( $this->token !== get_user_meta( $user, 'bc_homepage_categories_test_owner', true ) ) { throw new RuntimeException( 'Actor cleanup ownership refused.' ); }
			foreach ( $this->sites as $site ) {
				if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_homepage_categories_test_owner' ) ) { throw new RuntimeException( 'Role cleanup ownership refused.' ); }
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			wpmu_delete_user( $user );
			$this->same( false, get_userdata( $user ), 'owned disposable actor deleted' );
		}
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, 'bc_homepage_categories_test_owner' ) ) { throw new RuntimeException( 'Hall cleanup ownership refused.' ); }
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
ob_start();
( new HomepageCategoriesTest() )->run();
