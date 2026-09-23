<?php

declare(strict_types=1);

/**
 * ADR-044 Slice 3: real PackageAdmin rendering/controller and real Slice 2 services.
 * BC_TEST_DB=bingocommerce_state_recon php tests/package-addons-admin.php
 * All writes/cleanup require this run's owned disposable Halls. No live fallback.
 */
if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_test_scratch' === (string) getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI required; scratch is reserved for ownership controls.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\CatalogTransactionTransport;
use BingoCommerce\Catalog\Migrations;
use BingoCommerce\Catalog\PackageAdmin;
use BingoCommerce\Catalog\PackageAddonResult;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\Core\Migrations as CoreMigrations;

/** Test-only ambiguous COMMIT observation; production has no callback seam. */
final class AddonAdminUnknownCommit extends mysqli {
	public int $commits = 0;
	public function commit( int $flags = 0, ?string $name = null ): bool {
		++$this->commits;
		parent::commit( $flags, $name );
		return false;
	}
}

/** Exercise exact API return values, including values Woo's data-store setter normally cleans. */
final class AddonAdminPriceProduct extends WC_Product_Simple {
	public static mixed $regular = '';
	public static array $contexts = [];
	public function get_regular_price( $context = 'view' ) {
		self::$contexts[] = $context;
		return self::$regular;
	}
}

final class PackageAddonsAdminTest {
	private const OWNER = '_bc_package_addon_admin_owner';
	private const TABLES = [ 'bingo_game_types', 'bingo_play_products', 'bingo_packages', 'bingo_package_components', 'bingo_package_addons', 'bingo_sessions', 'bingo_session_packages', 'bingo_portal_session_templates', 'bingo_session_schedules', 'bingo_session_schedule_dates', 'bingo_session_schedule_packages' ];
	private array $sites = [];
	private array $users = [];
	private string $token;
	private int $admin;
	private int $game_type;
	private int $passed = 0;
	private int $failed = 0;
	private PackageAddonService $service;

	public function run(): void {
		global $wpdb;
		$this->token = wp_generate_password( 24, false, false );
		$this->admin = (int) username_exists( 'fixture_admin' );
		if ( $this->admin < 1 ) { throw new RuntimeException( 'Fixture admin missing.' ); }
		wp_set_current_user( $this->admin );
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->service = new PackageAddonService();
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		$tables_before = $wpdb->get_col( 'SHOW TABLES' );
		try {
			$a = $this->site(); $b = $this->site();
			switch_to_blog( $a );
			$this->game_type = $this->game_type();
			$this->base_price_display( $a, $b );
			$this->presentation();
			$this->prefill();
			$this->checkboxes( $a, $b );
			$this->save_messages( $a, $b );
			$this->save_summaries( $a, $b );
			$this->validation();
			$this->failures();
			$this->warnings( $a, $b );
			$this->recovery_after_read_failure( $a, $b );
			$this->authorization( $a, $b );
			restore_current_blog();
		} catch ( Throwable $error ) {
			$this->same( 'no exception', get_class( $error ) . ': ' . $error->getMessage(), 'suite completes' );
			echo $error->getTraceAsString() . "\n";
		} finally {
			while ( ms_is_switched() ) { restore_current_blog(); }
			wp_set_current_user( $this->admin );
			$_POST = []; $_GET = []; $_SERVER['REQUEST_METHOD'] = 'GET';
			$this->cleanup();
		}
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent Hall {$site}: every table schema/row byte-identical" ); }
		$this->same( $tables_before, $wpdb->get_col( 'SHOW TABLES' ), 'all disposable tables removed; no new persistence remains' );
		echo "RESULT: {$this->passed} passed; {$this->failed} failed.\n";
		exit( $this->failed ? 1 : 0 );
	}

	private function base_price_display( int $a, int $b ): void {
		global $wpdb;
		$service = new PackageService();
		$p = $this->play();
		$normal = $this->package( [ $p ] ); $sale = $this->package( [ $p ] );
		$empty = $this->package( [ $p ] ); $missing = $this->package( [ $p ] );
		$future = $this->package( [ $p ] );
		$normal_wc = $this->priced_package_product( $normal, '30.00' );
		$sale_wc = $this->priced_package_product( $sale, '50.33', '19.99' );
		$future_wc = $this->priced_package_product( $future, '40.00', '25.00', time() + DAY_IN_SECONDS );
		$missing_wc = $service->getById( $missing )['wc_product_id'];
		$this->owned( $a );
		// Owned broken link only; no Woo deletion jobs or changes to Catalog relationships.
		$wpdb->delete( $wpdb->posts, [ 'ID' => $missing_wc ], [ '%d' ] ); clean_post_cache( $missing_wc );
		switch_to_blog( $b );
		try {
			$b_package = $this->package( [ $this->play() ] );
			$b_wc = $this->priced_package_product( $b_package, '987.65' );
			$before_b = $this->state();
		} finally { restore_current_blog(); }
		$this->same( $normal, $b_package, 'price fixtures use the same Package number in separate Halls' );
		$this->same( $normal_wc->get_id(), $b_wc->get_id(), 'price fixtures use same-number WooCommerce IDs in separate Halls' );
		$before = $this->state();
		$html = $this->screen();
		$normal_cell = $this->price_cell( $html, $normal );
		$sale_cell = $this->price_cell( $html, $sale );
		$this->same( wp_kses_post( $normal_wc->get_price_html() ), $normal_cell, 'normal base price retains native Woo currency formatting' );
		$this->same( false, str_contains( $normal_cell, '<del' ) || str_contains( $normal_cell, '<ins' ) || str_contains( $normal_cell, 'screen-reader-text' ), 'normal price is shown once without sale explanation' );
		$this->same( wp_kses_post( $sale_wc->get_price_html() ), $sale_cell, 'active sale retains native sanitized Woo price HTML' );
		$this->same( true, str_contains( $sale_cell, '<del aria-hidden="true">' ) && str_contains( $sale_cell, '<ins aria-hidden="true">' ), 'sale retains struck regular price and separate current price with a11y attributes' );
		$dom = new DOMDocument();
		$prior = libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<html><body>' . $sale_cell . '</body></html>', LIBXML_NONET ); }
		finally { libxml_clear_errors(); libxml_use_internal_errors( $prior ); }
		$xpath = new DOMXPath( $dom );
		$sr = $xpath->query( '//span[@class="screen-reader-text"]' );
		$this->same( 2, $sr->length, 'both native screen-reader explanations retain the wp-admin visually-hidden class' );
		$this->same( true, str_contains( $sr->item( 0 )?->textContent ?? '', 'Original price was' ) && str_contains( $sr->item( 1 )?->textContent ?? '', 'Current price is' ), 'accessible explanations remain present inside screen-reader spans' );
		foreach ( $sr as $node ) { $node->parentNode->removeChild( $node ); }
		$visible = $dom->textContent;
		$this->same( true, 1 === substr_count( $visible, '50.33' ) && 1 === substr_count( $visible, '19.99' ) && ! str_contains( $visible, 'Original price' ) && ! str_contains( $visible, 'Current price' ), 'visually exposed text has each price once and no accessibility prose' );
		$this->same( true, (bool) preg_match( '/50\.33.*?<\/del>\s+<span class="screen-reader-text">/s', $sale_cell ), 'native whitespace separates visible regular and current sale prices' );
		$this->same( 'Unavailable', $this->price_cell( $html, $empty ), 'empty WooCommerce price displays Unavailable' );
		$this->same( 'Unavailable', $this->price_cell( $html, $missing ), 'missing linked WooCommerce product displays Unavailable' );
		$this->same( false, $future_wc->is_on_sale(), 'future scheduled sale is not yet effective' );
		$this->same( wp_kses_post( $future_wc->get_price_html() ), $this->price_cell( $html, $future ), 'list delegates scheduled-sale behavior to WooCommerce' );
		$this->same( false, str_contains( $this->price_cell( $html, $future ), '<del' ), 'future sale does not strike the current normal price' );
		$plain = $service->wooBasePrice( $sale_wc->get_id() );
		$this->same( wp_strip_all_tags( $sale_wc->get_price_html() ), $plain['formatted'], 'service formatted contract remains unchanged plain text' );
		$this->same( '19.99', $plain['price'], 'effective base price remains WooCommerce-owned' );
		$this->same( false, str_contains( $html, '987.65' ), 'other Hall price never appears in current Hall list' );

		$unsafe = static fn( $price, $product ) => $product->get_id() === $sale_wc->get_id() ? $price . '<script>alert(1)</script><img src="x" onerror="alert(1)"><span onclick="alert(1)">safe</span><iframe src="https://example.invalid"></iframe><a href="javascript:alert(1)">unsafe link</a>' : $price;
		add_filter( 'woocommerce_get_price_html', $unsafe, 10, 2 );
		try {
			$cell = $this->price_cell( $this->screen(), $sale );
			$this->same( wp_kses_post( $sale_wc->get_price_html() ), $cell, 'filtered price HTML is sanitized at the output boundary' );
			foreach ( [ '<script', '<iframe', 'onerror=', 'onclick=', 'javascript:' ] as $fragment ) { $this->same( false, str_contains( $cell, $fragment ), 'unsafe price markup removed: ' . $fragment ); }
			$this->same( true, str_contains( $cell, '<del' ) && str_contains( $cell, '<ins' ) && 2 === substr_count( $cell, 'class="screen-reader-text"' ), 'sanitization preserves safe sale and screen-reader markup' );
		} finally { remove_filter( 'woocommerce_get_price_html', $unsafe, 10 ); }

		$currency = static fn() => 'EUR'; $display = static fn() => 'incl';
		$suffix = static fn() => ' <small class="woocommerce-price-suffix">including tax</small>';
		$tax_calls = [];
		$tax = static function ( $price, $qty, $product ) use ( &$tax_calls ) { $tax_calls[] = $product->get_id(); return $price; };
		add_filter( 'pre_option_woocommerce_currency', $currency ); add_filter( 'pre_option_woocommerce_tax_display_shop', $display );
		add_filter( 'woocommerce_get_price_suffix', $suffix ); add_filter( 'woocommerce_get_price_including_tax', $tax, 10, 3 );
		try {
			$cell = $this->price_cell( $this->screen(), $sale );
			$this->same( true, in_array( $sale_wc->get_id(), $tax_calls, true ), 'list uses WooCommerce tax-inclusive display path' );
			$this->same( wp_kses_post( $sale_wc->get_price_html() ), $cell, 'currency tax display and suffix follow native filtered Woo HTML' );
			$this->same( true, str_contains( $cell, '&euro;' ) && str_contains( $cell, '<small class="woocommerce-price-suffix">including tax</small>' ), 'currency symbol and safe price suffix survive rendering' );
		} finally {
			remove_filter( 'pre_option_woocommerce_currency', $currency ); remove_filter( 'pre_option_woocommerce_tax_display_shop', $display );
			remove_filter( 'woocommerce_get_price_suffix', $suffix ); remove_filter( 'woocommerce_get_price_including_tax', $tax, 10 );
		}
		$this->same( $before, $this->state(), 'list price reads leave Catalog WooCommerce timestamps audit and projection byte-identical' );
		switch_to_blog( $b );
		try {
			$this->same( wp_kses_post( $b_wc->get_price_html() ), $this->price_cell( $this->screen(), $b_package ), 'same-number price resolves only in the newly selected Hall' );
			$this->same( $before_b, $this->state(), 'other Hall remains byte-identical after price rendering and switching' );
		} finally { restore_current_blog(); }
		$this->same( $normal_cell, $this->price_cell( $this->screen(), $normal ), 'switching back retains no other Hall price' );
	}

	private function priced_package_product( int $package, string $regular, string $sale = '', ?int $starts = null ): WC_Product {
		$this->owned( get_current_blog_id() );
		$product = wc_get_product( ( new PackageService() )->getById( $package )['wc_product_id'] );
		if ( ! $product instanceof WC_Product ) { throw new RuntimeException( 'Owned Package commerce shell missing.' ); }
		$product->set_regular_price( $regular ); $product->set_sale_price( $sale ); $product->set_date_on_sale_from( $starts );
		$product->set_price( '' !== $sale && null === $starts ? $sale : $regular );
		$product->save(); return wc_get_product( $product->get_id() );
	}

	private function price_cell( string $html, int $package ): string {
		preg_match( '/<tr>\s*<td>#' . $package . '<\/td>\s*<td>.*?<\/td>\s*<td>(.*?)<\/td>/s', $html, $match );
		return trim( $match[1] ?? '' );
	}

	private function presentation(): void {
		global $wpdb;
		$p = $this->play( 'paper', 'Visible Paper <script>bad</script>' );
		$d = $this->play( 'digital', 'Visible Digital' );
		$package = $this->package( [ $p ] );
		$digital = $this->package( [ $d ] );
		$hybrid = $this->package( [ $p, $d ] );
		$this->same( false, str_contains( $this->screen(), 'id="bc-package-addons"' ), 'no add-ons section on create/list screen' );
		$html = $this->screen( $package );
		$this->same( 1, substr_count( $html, 'id="bc-package-addons"' ), 'one Optional Add-ons section on existing editor' );
		$this->same( 1, substr_count( $html, '>Save Add-ons<' ), 'one primary Save Add-ons action' );
		$this->same( true, str_contains( $html, 'data-addon-row="p' . $p . '"' ), 'base component is still a candidate' );
		$this->same( false, str_contains( $html, 'bingocommerce-package-addons-page' ), 'no separate add-on dashboard' );
		$this->same( false, str_contains( $this->section( $package ), 'data-addon-row="p' . $d . '"' ), 'Paper excludes Digital candidate' );
		foreach ( [ $digital, $hybrid ] as $id ) {
			$model = $this->model( $id );
			$this->same( true, isset( $model['rows'][ 'p' . $p ], $model['rows'][ 'p' . $d ] ), 'Digital/Hybrid include both compatible media' );
		}
		$a = $this->row( $this->service->create( $package, $p, '21.00', 4 ) );
		$disabled = $this->play(); $archived = $this->play(); $missing = $this->play(); $wc_missing = $this->play();
		$b = $this->row( $this->service->create( $package, $disabled, '5.00' ) );
		$b = $this->row( $this->service->update( $b['package_addon_id'], $b['fingerprint'], '5.00', 1, false ) );
		$c = $this->row( $this->service->create( $package, $archived, '6.00' ) );
		$e = $this->row( $this->service->create( $package, $missing, '7.00' ) );
		$f = $this->row( $this->service->create( $package, $wc_missing, '8.00' ) );
		$this->play( 'paper', 'Unconfigured Paper candidate' );
		( new BingoPlayProductService() )->archive( $archived );
		$this->owned( get_current_blog_id() );
		$wpdb->delete( Migrations::play_products_table(), [ 'bingo_play_product_id' => $missing ] );
		wp_delete_post( $this->wc_id( $wc_missing ), true );
		$wpdb->update( Migrations::package_addons_table(), [ 'sort_order' => 9 ], [ 'package_addon_id' => $a['package_addon_id'] ] );
		$before = $this->state();
		$html = $this->section( $package );
		foreach ( [ $a, $b, $c, $e, $f ] as $row ) { $this->same( true, str_contains( $html, 'data-addon-row="r' . $row['package_addon_id'] . '"' ), 'configured row remains visible' ); }
		$this->same( true, strpos( $html, 'data-addon-row="r' . $b['package_addon_id'] ) < strpos( $html, 'data-addon-row="r' . $a['package_addon_id'] ), 'configured order follows sort_order then identity' );
		$this->same( true, strpos( $html, 'data-addon-row="r' . $a['package_addon_id'] ) < strpos( $html, 'data-addon-row="p' ), 'all configured rows before candidates' );
		foreach ( [ 'Configured — disabled', 'Unresolved Catalog Play Product', 'Unresolved WooCommerce product', 'Archived Play Product', 'data-addon-remove=', 'type="text" inputmode="decimal"' ] as $text ) { $this->same( true, str_contains( $html, $text ), 'renders ' . $text ); }
		$this->same( false, str_contains( $html, '<script>bad</script>' ), 'product label HTML escaped' );
		$this->same( false, str_contains( $this->html_row( $html, 'r' . $e['package_addon_id'] ), 'name="addons[' ), 'unresolved Catalog parent is entirely read-only' );
		$this->same( true, str_contains( $this->html_row( $html, 'r' . $f['package_addon_id'] ), 'readonly=' ), 'missing Woo product keeps saved values read-only' );
		$this->same( $before, $this->state(), 'form preparation changes no row, timestamp, audit or projection' );
		$this->same( substr_count( $html, '<form ' ), substr_count( $html, '</form>' ), 'add-ons form is closed independently' );
		$this->same( 1, substr_count( $html, '<form ' ), 'no nested forms inside section' );
		$this->same( true, strpos( $html, 'value="save"' ) < strpos( $html, 'value="remove:' ), 'implicit Enter defaults to Save, not destructive Remove' );
		// Incompatibility can exist on a disabled relationship after a supported medium change.
		( new BingoPlayProductService() )->updateMedium( $disabled, 'digital', $this->game_type );
		$this->same( true, str_contains( $this->section( $package ), 'Medium incompatible' ), 'disabled incompatible configured row remains visible' );
		( new PackageService() )->archive( $package );
		$html = $this->section( $package );
		$this->same( false, str_contains( $html, '>Save Add-ons<' ), 'archived Package offers no mutation' );
		$this->same( false, str_contains( $html, 'data-addon-remove=' ), 'archived Package offers no Remove' );
		$this->same( false, str_contains( $html, 'name="addons[' ), 'archived configured rows are read-only' );
		$this->same( true, str_contains( $this->screen(), '>View Package<' ), 'archived Package accessible for read-only review' );
		$result = $this->submit( $package, [ 'r' . $a['package_addon_id'] => $this->input( $a, false ) ] );
		$this->code( 'package_archived', $result['r' . $a['package_addon_id']]['result'], 'forged archived Package mutation rejected by service' );
		// Existing registered screen only; scripts enqueue only while editing.
		PackageAdmin::add_menu();
		$hook = get_plugin_page_hookname( 'bingocommerce-packages', 'edit.php?post_type=product' );
		$this->same( true, false !== has_action( 'load-' . $hook, [ PackageAdmin::class, 'load_editor' ] ), 'request processing registered before admin header' );
		wp_dequeue_script( 'bingocommerce-package-addons-admin' );
		$_POST = []; $_GET = []; PackageAdmin::load_editor();
		$this->same( false, wp_script_is( 'bingocommerce-package-addons-admin', 'enqueued' ), 'script absent outside editor' );
		$_GET = [ 'edit' => (string) $package ]; PackageAdmin::load_editor();
		$this->same( true, wp_script_is( 'bingocommerce-package-addons-admin', 'enqueued' ), 'script scoped to editor' );
		$js = file_get_contents( dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-catalog/assets/package-addons-admin.js' );
		$this->same( true, str_contains( $js, 'window.confirm(message)' ) && str_contains( $js, 'event.preventDefault()' ), 'simple browser confirmation cancels submission' );
		$this->same( true, str_contains( $js, 'row.hidden =' ) && ! str_contains( $js, 'parseFloat' ) && ! str_contains( $js, 'fetch(' ), 'filter is local presentation only; no price conversion/request' );
	}

	private function prefill(): void {
		$p = $this->play(); $package = $this->package( [ $p ] );
		$wc = $this->wc_id( $p );
		$cases = [ '5' => '5.00', '5.5' => '5.50', '4.23' => '4.23', '0.00' => '0.00', '99999999999999999.99' => '99999999999999999.99', '' => '', '-1' => '', '+5.00' => '', '05.00' => '', '1e2' => '', '4.234' => '', '100000000000000000.00' => '', '1,000.00' => '', ' 5.00' => '', '5.' => '' ];
		$view = static fn() => '999.99';
		add_filter( 'woocommerce_product_get_regular_price', $view );
		// Native data-store loading calls set_regular_price()/wc_format_decimal().
		// Test real canonical/sale/edit behavior first, then inject exact getter returns
		// through Woo's normal product-class factory; do not inspect raw price meta in UI.
		update_post_meta( $wc, '_regular_price', '23.55' );
		update_post_meta( $wc, '_sale_price', '1.00' ); update_post_meta( $wc, '_price', '1.00' );
		$this->same( '23.55', $this->model( $package )['rows'][ 'p' . $p ]['unit_price'], 'native Woo regular edit price, not sale/effective or filtered view' );
		$factory = static fn( $class, $type, $post_type, $id ) => $wc === (int) $id ? AddonAdminPriceProduct::class : $class;
		add_filter( 'woocommerce_product_class', $factory, 10, 4 );
		foreach ( $cases as $raw => $expected ) {
			AddonAdminPriceProduct::$regular = (string) $raw;
			$model = $this->model( $package );
			$this->same( $expected, $model['rows'][ 'p' . $p ]['unit_price'], 'regular edit-context string prefill: ' . (string) $raw );
			$this->same( 1, $model['rows'][ 'p' . $p ]['max_quantity'], 'candidate maximum defaults to 1' );
		}
		$this->same( [ 'edit' ], array_values( array_unique( AddonAdminPriceProduct::$contexts ) ), 'every prefill getter uses edit context' );
		remove_filter( 'woocommerce_product_class', $factory, 10 );
		remove_filter( 'woocommerce_product_get_regular_price', $view );
		$this->same( '', $this->call( 'addon_prefill', 5.5 ), 'float prefill rejected' );
		$row = $this->row( $this->service->create( $package, $p, '21.00' ) );
		update_post_meta( $wc, '_regular_price', '77.77' );
		$this->same( '21.00', $this->model( $package )['rows'][ 'r' . $row['package_addon_id'] ]['unit_price'], 'saved relationship price never refreshed' );
	}

	private function checkboxes( int $a, int $b ): void {
		$p = $this->play(); $q = $this->play(); $package = $this->package( [ $p ] );
		$before = $this->state();
		$result = $this->submit( $package, [ 'p' . $q => [ 'unit_price' => [ 'malformed' ], 'max_quantity' => [ 'malformed' ] ] ] );
		$this->same( [], $result, 'unchecked never-configured candidate ignored before any validation' );
		$this->same( $before, $this->state(), 'ignored candidate causes no writes, audit, projection or timestamps' );
		$input = [ 'unit_price' => '21.00', 'max_quantity' => '99', 'enabled' => '1' ];
		$result = $this->submit( $package, [ 'p' . $p => $input, 'p' . $q => [ 'unit_price' => '0.00', 'max_quantity' => '1', 'enabled' => '1' ] ] );
		$this->code( 'package_addon_created', $result['p' . $p]['result'], 'checked new candidate creates' );
		$this->code( 'package_addon_created', $result['p' . $q]['result'], 'multiple selection creates independent row' );
		$row = $this->row( $result['p' . $p]['result'] );
		$this->same( [ '21.00', 99, 1 ], [ $row['unit_price'], $row['max_quantity'], $row['enabled'] ], 'exact string price, native maximum and enabled default' );
		$before = $this->state();
		$result = $this->submit( $package, [ 'p' . $p => $input ] );
		$this->code( 'package_addon_unchanged', $result['p' . $p]['result'], 'identical create resubmission is no-op' );
		$this->same( $before, $this->state(), 'identical create is byte-identical including audit/projection' );
		foreach ( [ true, false, false, true ] as $enabled ) {
			$before = $this->state(); $old = $row;
			$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, $enabled ) ] );
			$status = (bool) $row['enabled'] === $enabled ? 'package_addon_unchanged' : 'package_addon_updated';
			$this->code( $status, $result['r' . $row['package_addon_id']]['result'], 'configured checkbox transition' );
			$row = $this->row( $result['r' . $row['package_addon_id']]['result'] );
			$this->same( [ '21.00', 99, (int) $enabled ], [ $row['unit_price'], $row['max_quantity'], $row['enabled'] ], 'toggle preserves price and maximum' );
			if ( 'package_addon_unchanged' === $status ) { $this->same( $before, $this->state(), 'unchanged checkbox row/timestamp/audit/projection byte-identical' ); }
		}
		$other = $this->package( [ $q ] );
		$foreign = $this->row( $this->service->create( $other, $p, '3.00' ) );
		$result = $this->submit( $package, [ 'r' . $foreign['package_addon_id'] => $this->input( $foreign, false ), 'r' . $row['package_addon_id'] => array_replace( $this->input( $row ), [ 'unit_price' => '22.00' ] ), 'malformed' => 'x' ] );
		$this->code( 'package_addon_not_found', $result['r' . $foreign['package_addon_id']]['result'], 'another Package relationship refused' );
		$this->code( 'package_addon_updated', $result['r' . $row['package_addon_id']]['result'], 'independent valid row succeeds beside forged/malformed rows' );
		$this->code( 'invalid_request', $result['malformed']['result'], 'malformed row gets its own result' );
		$this->same( true, str_contains( $this->html_row( $this->section( $package ), 'malformed' ), 'notice-error' ), 'even a scalar malformed row retains its own visible correction message' );
		$this->same( true, str_contains( $this->html_row( $this->section( $package ), 'r' . $foreign['package_addon_id'] ), 'notice-error' ), 'forged relationship has a safe row-specific visible error' );
		$this->same( $foreign['fingerprint'], $this->service->listForPackage( $other )[0]['fingerprint'], 'forged membership leaves other Package unchanged' );
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row ) ] );
		$this->code( 'package_addon_stale', $result['r' . $row['package_addon_id']]['result'], 'submitted stale fingerprint never refreshed' );
		$html = $this->section( $package );
		$this->same( true, str_contains( $html, 'value="' . $row['fingerprint'] . '"' ), 'failed form retains stale fingerprint rather than silently authorizing overwrite' );
		$row = $this->service->listForPackage( $package )[0];
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row ), 'p' . $p => [ 'enabled' => '1', 'unit_price' => 'invalid' ] ], 'remove:r' . $row['package_addon_id'] );
		$this->same( 1, count( $result ), 'Remove ignores every bulk Save field' );
		$this->code( 'package_addon_removed', $result['r' . $row['package_addon_id']]['result'], 'Remove deletes only selected relationship' );
		$this->same( [ 'Add-on relationship removed.' ], $this->success_messages( $this->section_after_toolbar( $a, $b ) ), 'Remove retains separate feedback after toolbar and full render, not a Save summary' );
		$before = $this->state();
		$result = $this->submit( $package, [ 'r' . $foreign['package_addon_id'] => $this->input( $foreign ) ], 'remove:r' . $foreign['package_addon_id'] );
		$this->code( 'package_addon_not_found', $result['r' . $foreign['package_addon_id']]['result'], 'forged Remove refused' );
		$this->same( $before, $this->state(), 'forged Remove changes nothing' );
	}

	private function save_messages( int $a, int $b ): void {
		$p = $this->play(); $d = $this->play( 'digital' );
		$package = $this->package( [ $p, $d ] );
		$row = $this->row( $this->service->create( $package, $p, '10.22' ) );
		$key = 'r' . $row['package_addon_id'];
		$bar = new WP_Admin_Bar();
		// Exercise the real header callback, limited to this run's owned Halls.
		$bar->user = (object) [ 'active_blog' => get_site( $a ), 'blogs' => [
			(object) [ 'userblog_id' => $a, 'blogname' => 'Owned Hall A' ],
			(object) [ 'userblog_id' => $b, 'blogname' => 'Owned Hall B' ],
		] ];
		$response = new ReflectionProperty( PackageAdmin::class, 'addons_response' );
		foreach ( [ false, true, true ] as $enabled ) {
			$before = $this->state();
			$unchanged = (bool) $row['enabled'] === $enabled;
			$result = $this->submit( $package, [ $key => $this->input( $row, $enabled ) ] );
			$this->code( $unchanged ? 'package_addon_unchanged' : 'package_addon_updated', $result[ $key ]['result'], 'same-request POST returns expected save result' );
			$saved = $response->getValue();
			$this->same( true, is_array( $saved ), 'load_editor retains result before admin header' );
			$switches = [];
			$trace = static function ( $new, $previous, $context ) use ( &$switches ): void {
				$switches[] = [ (int) $new, (int) $previous, $context ];
			};
			add_action( 'switch_blog', $trace, 20, 3 );
			try {
				// admin.php runs load-*, then admin-header.php's My Sites menu, then render().
				wp_admin_bar_my_sites_menu( $bar );
			} finally { remove_action( 'switch_blog', $trace, 20 ); }
			$this->same( true, in_array( [ $a, $a, 'switch' ], $switches, true ), 'native My Sites menu issues same-Hall switch hook' );
			$this->same( true, in_array( [ $b, $a, 'switch' ], $switches, true ), 'native My Sites menu temporarily visits another Hall' );
			$this->same( $a, get_current_blog_id(), 'native My Sites menu restores request Hall before rendering' );
			$this->same( true, $saved === $response->getValue(), 'request-local result survives native header rendering' );
			// Keep the POST and response intact: unlike screen(), do not start a new GET.
			ob_start(); PackageAdmin::render(); $html = ob_get_clean();
			preg_match( '/<section id="bc-package-addons">.*?<\/section>/s', $html, $match );
			$message = $unchanged ? 'Add-ons unchanged.' : '1 add-on updated.';
			$this->same( [ $unchanged ? 'info' : 'success', $message ], $this->summary_notice( $match[0] ?? '' ), 'same-request full page displays appropriately styled save summary inside Optional Add-ons' );
			$row = $this->service->listForPackage( $package )[0];
			$this->same( [ '10.22', 1, (int) $enabled ], [ $row['unit_price'], $row['max_quantity'], $row['enabled'] ], 'rendered toggle keeps exact saved price and maximum' );
			if ( $unchanged ) { $this->same( $before, $this->state(), 'POST plus header/full render no-op leaves rows timestamps audit and projection byte-identical' ); }
		}
		// A genuine destination context cannot display the source Hall's response.
		switch_to_blog( $b );
		try {
			$html = $this->section( $package );
			$this->same( [], $this->success_messages( $html ), 'another Hall cannot display the previous Hall save notice' );
			$this->same( false, str_contains( $html, 'Add-ons unchanged.' ), 'another Hall cannot display previous informational no-op summary' );
			$_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
			PackageAdmin::load_editor();
			$this->same( null, $response->getValue(), 'destination screen load discards previous response' );
		} finally { restore_current_blog(); }
		$this->same( [], $this->success_messages( $this->section( $package ) ), 'old notice cannot reappear after loading another Hall screen' );
		$this->same( [], $this->summary_notice( $this->section( $package ) ), 'old informational notice cannot reappear after loading another Hall screen' );
	}

	private function save_summaries( int $a, int $b ): void {
		global $wpdb;
		$p = $this->play(); $q = $this->play(); $d = $this->play( 'digital' );
		$package = $this->package( [ $p, $d ] );
		foreach ( [ $p, $q, $d ] as $id ) { $this->row( $this->service->create( $package, $id, '5.00' ) ); }
		$rows = $this->service->listForPackage( $package );
		$inputs = [];
		foreach ( $rows as $row ) { $inputs[ 'r' . $row['package_addon_id'] ] = $this->input( $row ); }
		$key = 'r' . $rows[0]['package_addon_id'];
		$inputs[ $key ]['unit_price'] = '5.05';
		$result = $this->submit( $package, $inputs );
		$this->same( [ 'package_addon_updated', 'package_addon_unchanged', 'package_addon_unchanged' ], array_map( static fn( $outcome ) => $outcome['result']->status, array_values( $result ) ), 'one edit still processes every configured row independently' );
		$html = $this->section_after_toolbar( $a, $b );
		$this->same( [ '1 add-on updated; 2 unchanged.' ], $this->success_messages( $html ), 'one update plus two unchanged renders exactly one summary' );
		$this->same( true, strpos( $html, '1 add-on updated; 2 unchanged.' ) < strpos( $html, '<table' ), 'Save summary appears above the add-ons table' );
		// Only the post-commit page-model read fails; the saved result must survive.
		$filter = static fn( $sql ) => str_contains( $sql, 'ORDER BY sort_order, package_addon_id' ) ? 'INVALID private_summary_model_read' : $sql;
		$prior = $wpdb->suppress_errors( true ); add_filter( 'query', $filter );
		try {
			$html = $this->section_after_toolbar( $a, $b );
			$this->same( [ 'success', '1 add-on updated; 2 unchanged.' ], $this->summary_notice( $html ), 'post-commit model read error does not erase confirmed summary' );
			$this->same( true, str_contains( $html, 'list is unavailable, not empty' ) && ! str_contains( $html, 'private_summary_model_read' ), 'post-commit model error is visible without raw database detail' );
		} finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }
		$rows = $this->service->listForPackage( $package );
		$this->same( '5.05', $rows[0]['unit_price'], 'summary follows committed service result and exact price' );
		$inputs = [];
		foreach ( $rows as $row ) { $inputs[ 'r' . $row['package_addon_id'] ] = $this->input( $row ); }
		$ignored = $this->play();
		$inputs[ 'p' . $ignored ] = [ 'unit_price' => [ 'invalid' ], 'max_quantity' => 'bad' ];
		$before = $this->state();
		$result = $this->submit( $package, $inputs );
		$this->same( 3, count( $result ), 'unchecked never-configured row excluded from outcomes and summary' );
		$html = $this->section_after_toolbar( $a, $b );
		$this->same( [ 'info', 'Add-ons unchanged.' ], $this->summary_notice( $html ), 'all unchanged renders informational summary after native toolbar and full render' );
		$this->same( 1, substr_count( $html, 'class="notice ' ), 'all unchanged displays one notice only' );
		$this->same( $before, $this->state(), 'all-unchanged summary makes no relationship timestamp audit or projection writes' );

		$new = $this->play();
		$result = $this->submit( $package, [
			'p' . $ignored => [ 'unit_price' => '1.00', 'max_quantity' => '1', 'enabled' => '1' ],
			'p' . $new => [ 'unit_price' => '2.00', 'max_quantity' => '2', 'enabled' => '1' ],
		] );
		$this->same( [ 'package_addon_created', 'package_addon_created' ], array_map( static fn( $outcome ) => $outcome['result']->status, array_values( $result ) ), 'multiple new rows retain independent create results' );
		$this->same( [ '2 add-ons created.' ], $this->success_messages( $this->section_after_toolbar( $a, $b ) ), 'multiple new rows render one created summary without zero counts' );

		$bad_key = 'r' . $rows[1]['package_addon_id'];
		$bad_input = array_replace( $this->input( $rows[1], false ), [ 'unit_price' => '5.0', 'max_quantity' => '2.5' ] );
		$result = $this->submit( $package, [ $bad_key => $bad_input, $key => array_replace( $this->input( $rows[0] ), [ 'unit_price' => '5.10' ] ) ] );
		$this->code( 'package_addon_price_invalid', $result[ $bad_key ]['result'], 'partial Save keeps specific row error' );
		$this->code( 'package_addon_updated', $result[ $key ]['result'], 'valid row commits after another row fails' );
		$html = $this->section_after_toolbar( $a, $b );
		$this->same( [ 'warning', '1 add-on updated; 1 row needs attention. Review the row below.' ], $this->summary_notice( $html ), 'partial save summary includes attention count with warning styling' );
		$this->same( [], $this->success_messages( $html ), 'mixed outcomes never display a green success notice' );
		$this->same( 1, substr_count( strstr( $html, '<table', true ), 'class="notice ' ), 'mixed save has exactly one top summary before the table' );
		$bad_html = $this->html_row( $html, $bad_key );
		$this->same( true, str_contains( $bad_html, 'notice-error' ) && str_contains( $bad_html, 'Enter a non-negative price with exactly two decimal digits' ), 'specific correction remains beside the failed row' );
		$this->same( true, str_contains( $bad_html, 'value="5.0"' ) && str_contains( $bad_html, 'value="2.5"' ) && ! str_contains( $bad_html, 'checked=' ), 'failed row retains submitted price maximum and unchecked state' );
		$this->same( true, str_contains( $bad_html, 'value="' . $bad_input['fingerprint'] . '"' ), 'row error preserves submitted fingerprint' );
		$current = $this->service->listForPackage( $package );
		$this->same( $rows[1]['fingerprint'], $current[1]['fingerprint'], 'failed row stored configuration remains unchanged' );
		$this->submit( $package, [ $bad_key => $bad_input, $key => $this->input( $current[0] ) ] );
		$html = $this->section_after_toolbar( $a, $b );
		$this->same( [ 'warning', '1 unchanged; 1 row needs attention. Review the row below.' ], $this->summary_notice( $html ), 'unchanged plus error warns instead of suggesting complete success' );
		$this->same( [], $this->success_messages( $html ), 'unchanged plus error has no green notice' );
		$result = $this->submit( $package, [ $key => $this->input( $rows[0] ) ] );
		$this->code( 'package_addon_stale', $result[ $key ]['result'], 'stale form remains a specific row error' );
		$html = $this->section_after_toolbar( $a, $b );
		$this->same( [ 'error', '1 row needs attention. Review the row below.' ], $this->summary_notice( $html ), 'single stale row gets attention summary without claiming commit failure' );
		$stale_html = $this->html_row( $html, $key );
		$this->same( true, str_contains( $stale_html, 'changed after the form was loaded' ) && str_contains( $stale_html, 'value="' . $rows[0]['fingerprint'] . '"' ), 'stale explanation and submitted fingerprint survive full render' );
		$this->submit( $package, [ $bad_key => $bad_input, $key => array_replace( $this->input( $current[0] ), [ 'unit_price' => 'invalid' ] ) ] );
		$html = $this->section_after_toolbar( $a, $b );
		$this->same( [ 'error', '2 rows need attention. Review the rows below.' ], $this->summary_notice( $html ), 'all invalid rows produce plural top error summary' );
		$this->same( [], $this->success_messages( $html ), 'all invalid rows produce no green success notice' );
		$this->same( 1, substr_count( strstr( $html, '<table', true ), 'class="notice ' ), 'all-invalid save has exactly one top summary' );
		$this->same( true, str_contains( $this->html_row( $html, $key ), 'notice-error' ) && str_contains( $this->html_row( $html, $bad_key ), 'notice-error' ), 'all invalid rows keep their individual errors beside each row' );
		$this->submit( $package, [ 'p999999999' => [ 'unit_price' => 'bad', 'max_quantity' => 'bad' ] ] );
		$this->same( [], $this->summary_notice( $this->section_after_toolbar( $a, $b ) ), 'ignored-only Save produces no summary of any severity' );
	}

	private function validation(): void {
		$p = $this->play(); $package = $this->package( [ $p ] );
		foreach ( [ '5', '5.0', '5.5', '4.234', '+5.00', '-5.00', '05.00', '1e2', '$5.00', '1,000.00', '100000000000000000.00', 5.5, [ '5.00' ] ] as $value ) {
			$result = $this->submit( $package, [ 'p' . $p => [ 'enabled' => '1', 'unit_price' => $value, 'max_quantity' => '1' ] ] );
			$this->code( 'package_addon_price_invalid', $result['p' . $p]['result'], 'submitted price is never padded/normalized/rounded' );
		}
		foreach ( [ '+1', '-1', ' 1', '1 ', '1.0', '1e1', '0', '100', '1x', 1.5, 1, [ '1' ] ] as $value ) {
			$result = $this->submit( $package, [ 'p' . $p => [ 'enabled' => '1', 'unit_price' => '5.00', 'max_quantity' => $value ] ] );
			$this->code( 'package_addon_max_quantity_invalid', $result['p' . $p]['result'], 'strict HTTP maximum rejects invalid shape/range' );
		}
		$q = $this->play();
		$result = $this->submit( $package, [ 'p' . $p => [ 'enabled' => '1', 'unit_price' => '<bad>', 'max_quantity' => '1.5' ], 'p' . $q => [ 'enabled' => '1', 'unit_price' => '4.23', 'max_quantity' => '01' ] ] );
		$this->code( 'package_addon_created', $result['p' . $q]['result'], 'digit-only HTTP maximum becomes native integer before service' );
		$html = $this->html_row( $this->section( $package ), 'p' . $p );
		$this->same( true, str_contains( $html, 'value="&lt;bad&gt;"' ) && str_contains( $html, 'value="1.5"' ) && str_contains( $html, 'checked=' ), 'failed row retains escaped submitted price maximum and checkbox' );
		$this->same( false, str_contains( $html, '<bad>' ), 'failed raw HTML never exposed' );
		$row = $this->service->listForPackage( $package )[0];
		( new BingoPlayProductService() )->archive( $q );
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => array_replace( $this->input( $row ), [ 'unit_price' => '6.00' ] ) ] );
		$this->code( 'play_product_not_active', $result['r' . $row['package_addon_id']]['result'], 'HTML readonly is not authority; forged archived price rejected' );
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, false ) ] );
		$this->code( 'package_addon_updated', $result['r' . $row['package_addon_id']]['result'], 'archived Play Product may be disabled' );
		$row = $this->row( $result['r' . $row['package_addon_id']]['result'] );
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row ) ], 'remove:r' . $row['package_addon_id'] );
		$this->code( 'package_addon_removed', $result['r' . $row['package_addon_id']]['result'], 'archived Play Product may be removed' );
	}

	private function failures(): void {
		global $wpdb;
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->row( $this->service->create( $package, $p, '5.00' ) );
		$before = $this->state();
		foreach ( [ 'ORDER BY sort_order, package_addon_id', 'SELECT bingo_play_product_id FROM' ] as $needle ) {
			$filter = static fn( $sql ) => str_contains( $sql, $needle ) ? 'INVALID private_sql_do_not_display' : $sql;
			$prior = $wpdb->suppress_errors( true ); add_filter( 'query', $filter );
			try {
				$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, false ) ] );
				$this->code( 'persistence_failed', $result, 'complete read failure stops request before any row' );
				$html = $this->section( $package );
				$this->same( true, str_contains( $html, 'list is unavailable, not empty' ), 'database failure visibly distinct from empty' );
				$this->same( false, str_contains( $html, 'private_sql_do_not_display' ), 'raw SQL never rendered' );
			} finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }
		}
		$this->same( $before, $this->state(), 'read failures preserve all rows and metadata' );
		$wpdb->delete( Migrations::play_products_table(), [ 'bingo_play_product_id' => $p ] );
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, false ) ] );
		$this->code( 'play_product_not_found', $result['r' . $row['package_addon_id']]['result'], 'missing Catalog parent remains mutation failure' );
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row ) ], 'remove:r' . $row['package_addon_id'] );
		$this->code( 'play_product_not_found', $result['r' . $row['package_addon_id']]['result'], 'missing parent Remove never detaches' );
		$this->same( 1, count( $this->service->listForPackage( $package ) ), 'unresolved configured relationship preserved' );
		$q = $this->play(); $other = $this->package( [ $q ] );
		$r = $this->play(); $relation = $this->row( $this->service->create( $other, $r, '8.00' ) );
		$this->owned( get_current_blog_id() );
		$wc = $this->wc_id( $r );
		// Deliberately break only this run's commerce-shell link without invoking Woo deletion jobs.
		$wpdb->delete( $wpdb->posts, [ 'ID' => $wc ], [ '%d' ] ); clean_post_cache( $wc );
		$result = $this->submit( $other, [ 'r' . $relation['package_addon_id'] => array_replace( $this->input( $relation ), [ 'unit_price' => '9.00' ] ) ] );
		$this->code( 'play_product_not_found', $result['r' . $relation['package_addon_id']]['result'], 'missing Woo link refuses forged price editing' );
		$result = $this->submit( $other, [ 'r' . $relation['package_addon_id'] => $this->input( $relation, false ) ] );
		$this->code( 'package_addon_updated', $result['r' . $relation['package_addon_id']]['result'], 'missing Woo link permits lifecycle-supported disable' );
		$relation = $this->row( $result['r' . $relation['package_addon_id']]['result'] );
		$result = $this->submit( $other, [ 'r' . $relation['package_addon_id'] => $this->input( $relation, true ) ] );
		$this->code( 'play_product_not_found', $result['r' . $relation['package_addon_id']]['result'], 'missing Woo link refuses re-enable' );
		$result = $this->submit( $other, [ 'r' . $relation['package_addon_id'] => $this->input( $relation ) ], 'remove:r' . $relation['package_addon_id'] );
		$this->code( 'package_addon_removed', $result['r' . $relation['package_addon_id']]['result'], 'missing Woo link permits lifecycle-supported Remove' );
		$this->same( false, isset( $this->model( $other )['rows'][ 'p' . $r ] ), 'missing Woo link never offered as new candidate' );
		$wpdb->update( Migrations::package_addons_table(), [ 'enabled' => 2 ], [ 'package_addon_id' => $row['package_addon_id'] ] );
		$this->same( true, str_contains( $this->section( $package ), 'list is unavailable, not empty' ), 'corrupt configured relationship fails visibly, not empty' );
	}

	private function warnings( int $a, int $b ): void {
		global $wpdb;
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->row( $this->service->create( $package, $p, '5.00' ) );
		$this->same( false, str_contains( $this->section( $package ), 'value="retry:' ), 'no proactive retry control/scanner on normal view' );
		$block = static fn( $check, $mid, $value, $key ) => 'bc_package_relation' === $key ? false : $check;
		add_filter( 'update_post_metadata_by_mid', $block, 10, 4 );
		try { $result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, false ) ] ); }
		finally { remove_filter( 'update_post_metadata_by_mid', $block, 10 ); }
		$value = $result['r' . $row['package_addon_id']]['result'];
		$this->code( 'package_addon_updated', $value, 'projection failure retains committed success' );
		$this->same( [ 'projection_reconciliation_required' ], array_column( $value->warnings, 'code' ), 'projection warning returned' );
		$html = $this->section_after_toolbar( $a, $b );
		$this->same( [ '1 add-on updated.' ], $this->success_messages( $html ), 'committed warning keeps the successful Save summary' );
		$this->same( [ 'success', '1 add-on updated.' ], $this->summary_notice( $html ), 'committed warning is not counted as a row error in the top summary' );
		$this->same( true, str_contains( $html, 'notice-warning' ) && str_contains( $html, 'WooCommerce synchronization needs attention' ) && str_contains( $html, 'value="retry:' . $p . ':' ), 'summary preserves warning and targeted retry after toolbar and full page render' );
		preg_match( '/value="(retry:[^"]+)"/', $html, $match );
		$row = $this->row( $value );
		$audit = $this->audits();
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => [ 'enabled' => '1', 'unit_price' => 'bad' ] ], html_entity_decode( $match[1] ?? '' ) );
		$this->same( 1, count( $result ), 'Retry ignores bulk Save fields' );
		$this->code( 'package_addon_projection_synchronized', $result['retry']['result'], 'Retry uses current-Hall Play Product identity' );
		$this->same( [ 'Synchronization verified.' ], $this->success_messages( $this->section_after_toolbar( $a, $b ) ), 'Retry retains separate feedback, not a Save summary' );
		$this->same( $audit, $this->audits(), 'Retry is not a domain mutation audit' );
		$this->same( 0, $this->service->listForPackage( $package )[0]['enabled'], 'Retry leaves relationship disabled' );
		$this->same( false, str_contains( $this->section( $package ), 'value="retry:' ), 'successful Retry clears request-local retry presentation' );
		$this->code( 'invalid_request', $this->submit( $package, [], 'retry:' . $p . ':forged' ), 'retry control proof cannot be forged' );
		// Safe warning text for each documented committed-success warning.
		foreach ( [ 'state_read_failed', 'audit_write_failed' ] as $code ) {
			ob_start(); $this->call( 'addon_notice', new PackageAddonResult( 'package_addon_updated', $row, [ [ 'code' => $code ] ] ) ); $notice = ob_get_clean();
			$this->same( true, str_contains( $notice, 'notice-success' ) && str_contains( $notice, 'notice-warning' ), $code . ' does not turn success into failure' );
			ob_start(); $this->call( 'addon_notice', new PackageAddonResult( 'package_addon_updated', $row, [ [ 'code' => $code ] ] ), false ); $notice = ob_get_clean();
			$this->same( true, ! str_contains( $notice, 'notice-success' ) && str_contains( $notice, 'notice-warning' ), $code . ' remains visible when Save suppresses per-row success text' );
		}
		$q = $this->play();
		$original = $wpdb->dbh;
		$connection = new AddonAdminUnknownCommit( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$connection->set_charset( 'utf8mb4' );
		$wpdb->dbh = $connection;
		$audit = $this->audits();
		try {
			$result = $this->submit( $package, [ 'p' . $q => [ 'enabled' => '1', 'unit_price' => '5.00', 'max_quantity' => '1' ] ] );
			$this->code( 'catalog_commit_outcome_unknown', $result['p' . $q]['result'], 'ambiguous COMMIT remains row error' );
			$this->same( 1, $connection->commits, 'ambiguous COMMIT never automatically retried' );
			$this->same( $audit, $this->audits(), 'ambiguous COMMIT no audit' );
			$this->same( '', get_post_meta( $this->wc_id( $q ), 'bc_package_relation', true ), 'ambiguous COMMIT no projection' );
			$html = $this->section_after_toolbar( $a, $b );
			$this->same( [ 'error', '1 row needs attention. Review the row below.' ], $this->summary_notice( $html ), 'unknown COMMIT is summarized as needing attention, not a definite failure' );
			$this->same( [], $this->success_messages( $html ), 'unknown COMMIT produces no green notice' );
			$this->same( true, str_contains( $this->html_row( $html, 'p' . $q ), 'The save outcome is unknown. No automatic retry was made.' ), 'unknown COMMIT keeps exact row-specific explanation after toolbar and full render' );
			$this->same( 1, $connection->commits, 'rendering unknown outcome never retries the commit' );
			$this->same( false, str_contains( $html, 'value="retry:' ), 'unknown COMMIT never offers projection Retry' );
			$filter = static fn( $sql ) => str_contains( $sql, 'ORDER BY sort_order, package_addon_id' ) ? 'INVALID unknown_commit_model_read' : $sql;
			$prior = $wpdb->suppress_errors( true ); add_filter( 'query', $filter );
			try {
				$html = $this->section_after_toolbar( $a, $b );
				$this->same( true, str_contains( $html, 'list is unavailable, not empty' ) && ! str_contains( $html, '<form' ), 'unknown COMMIT plus model failure offers no recovery form' );
			} finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }
		} finally {
			$wpdb->dbh = $original; $connection->close();
			// Test-only fresh trusted session state between cases; no production recovery added.
			( new ReflectionProperty( CatalogTransactionTransport::class, 'untrusted' ) )->setValue( null, false );
		}
	}

	private function recovery_after_read_failure( int $a, int $b ): void {
		global $wpdb;
		$p = $this->play(); $q = $this->play(); $package = $this->package( [ $p ] );
		$other = $this->package( [ $q ] );
		$manager = $this->user( 'bc_hall_manager', $a ); $operator = $this->user( 'bc_game_operator', $a );
		$row = $this->row( $this->service->create( $package, $p, '5.00' ) );
		$bad = $this->row( $this->service->create( $package, $q, '6.00' ) );
		$block = static fn( $check, $mid, $value, $key ) => 'bc_package_relation' === $key ? false : $check;
		add_filter( 'update_post_metadata_by_mid', $block, 10, 4 );
		try { $result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, false ), 'r' . $bad['package_addon_id'] => array_replace( $this->input( $bad ), [ 'unit_price' => '6.0' ] ) ] ); }
		finally { remove_filter( 'update_post_metadata_by_mid', $block, 10 ); }
		$this->code( 'package_addon_updated', $result['r' . $row['package_addon_id']]['result'], 'recovery fixture save is confirmed committed' );
		$this->same( [ 'projection_reconciliation_required' ], array_column( $result['r' . $row['package_addon_id']]['result']->warnings, 'code' ), 'recovery fixture has confirmed projection warning' );
		$before = $this->state();
		$before_b = $this->snapshot( $b );
		$filter = static fn( $sql ) => str_contains( $sql, 'ORDER BY sort_order, package_addon_id' ) ? 'INVALID recovery_model_read' : $sql;
		$prior = $wpdb->suppress_errors( true ); add_filter( 'query', $filter );
		try {
			$html = $this->section_after_toolbar( $a, $b );
			$this->same( [ 'warning', '1 add-on updated; 1 row needs attention. Review the row below.' ], $this->summary_notice( $html ), 'combined failure preserves partial-save summary through toolbar and full render' );
			$this->same( true, str_contains( $html, 'WooCommerce synchronization needs attention' ) && str_contains( $html, 'exactly two decimal digits' ), 'combined failure preserves committed warning and other row error' );
			$this->same( true, str_contains( $html, 'list is unavailable, not empty' ), 'recovery form never pretends the failed list is empty' );
			foreach ( [ '<table', 'data-addon-row=', 'name="addons[', 'value="save"', 'value="remove:', 'bc_addons_complete', 'recovery_model_read' ] as $fragment ) { $this->same( false, str_contains( $html, $fragment ), 'recovery-only form excludes ' . $fragment ); }
			$this->same( 1, substr_count( $html, '<form ' ), 'one recovery-only form, not nested forms' );
			$this->same( 1, substr_count( $html, 'value="retry:' . $p . ':' ), 'confirmed current-Hall Play Product has exactly one targeted Retry' );
			// Submit the actual rendered recovery fields, without bulk Save's envelope or rows.
			$dom = new DOMDocument(); $old = libxml_use_internal_errors( true );
			try { $dom->loadHTML( '<html><body>' . $html . '</body></html>', LIBXML_NONET ); }
			finally { libxml_clear_errors(); libxml_use_internal_errors( $old ); }
			$xpath = new DOMXPath( $dom ); $payload = [];
			foreach ( $xpath->query( '//form/input[@name] | //form//button[@name]' ) as $field ) { $payload[ $field->getAttribute( 'name' ) ] = $field->getAttribute( 'value' ); }
			$this->same( false, str_contains( $this->section( $other ), 'value="retry:' ), 'recovery control never renders for another Package' );
			wp_set_current_user( $manager );
			try { $this->same( false, str_contains( $this->section( $package ), 'value="retry:' ), 'recovery control never renders for another actor' ); }
			finally { wp_set_current_user( $this->admin ); }
			switch_to_blog( $b );
			try { $this->same( false, str_contains( $this->section( $package ), 'value="retry:' ), 'recovery control never renders in another Hall' ); }
			finally { restore_current_blog(); }
			$send = function ( array $override = [], ?int $editing = null, string $method = 'POST' ) use ( $package, $payload ): mixed {
				$_GET = [ 'edit' => (string) ( $editing ?? $package ) ]; $_SERVER['REQUEST_METHOD'] = $method;
				$_POST = wp_slash( array_replace( $payload, $override ) ); PackageAdmin::load_editor();
				return ( new ReflectionProperty( PackageAdmin::class, 'addons_response' ) )->getValue()['result'];
			};
			$this->code( 'persistence_failed', $send(), 'Retry still fails closed while the authoritative list is unreadable' );
		} finally { remove_filter( 'query', $filter ); $wpdb->suppress_errors( $prior ); }
		$this->same( $before, $this->state(), 'recovery rendering and blocked Retry perform no relationship timestamp audit or projection writes' );
		$this->code( 'invalid_request', $send( [], null, 'GET' ), 'recovery Retry still requires POST' );
		$this->code( 'invalid_nonce', $send( [ '_wpnonce' => 'invalid' ] ), 'recovery Retry rejects invalid form nonce' );
		$this->code( 'invalid_request', $send( [ 'package_id' => (string) $other ] ), 'recovery Retry rejects mismatched Package envelope' );
		$this->code( 'invalid_request', $send( [ 'package_id' => (string) $other, '_wpnonce' => wp_create_nonce( $this->call( 'addons_nonce', $other ) ) ], $other ), 'recovery Retry token cannot be reused for another Package' );
		$this->code( 'package_not_found', $send( [ 'package_id' => '999999999', '_wpnonce' => wp_create_nonce( $this->call( 'addons_nonce', 999999999 ) ) ], 999999999 ), 'recovery Retry requires a current existing Package' );
		wp_set_current_user( $manager );
		$this->code( 'invalid_nonce', $send(), 'another authorized actor cannot reuse the rendered recovery nonce' );
		$this->code( 'invalid_request', $send( [ '_wpnonce' => wp_create_nonce( $this->call( 'addons_nonce', $package ) ) ] ), 'targeted recovery proof is actor-bound even with fresh form nonce' );
		wp_set_current_user( $operator );
		$this->code( 'package_addon_management_denied', $send(), 'Game Operator cannot invoke recovery Retry' );
		wp_set_current_user( $this->admin );
		switch_to_blog( $b );
		try { $this->code( 'invalid_nonce', $send(), 'another Hall cannot reuse recovery nonce or target proof' ); }
		finally { restore_current_blog(); }
		switch_to_blog( 1 );
		try { $this->code( 'invalid_hall_context', $send(), 'Super Admin recovery requires explicit Hall context' ); }
		finally { restore_current_blog(); }
		$this->same( $before, $this->state(), 'all rejected recovery requests leave current Hall byte-identical' );
		$relationships = $this->service->listForPackage( $package ); $audit = $this->audits();
		$retried = $send();
		$this->code( 'package_addon_projection_synchronized', $retried['retry']['result'], 'rendered recovery-only form works once the read recovers' );
		$this->same( 'none', get_post_meta( $this->wc_id( $p ), 'bc_package_relation', true ), 'recovery Retry derives zero enabled count from current Catalog state' );
		$this->same( $relationships, $this->service->listForPackage( $package ), 'successful recovery Retry changes no relationship values fingerprints or timestamps' );
		$this->same( $audit, $this->audits(), 'successful recovery Retry emits no relationship audit' );
		$this->row( $this->service->create( $other, $p, '7.00' ) );
		$before = $this->state(); $retried = $send();
		$this->code( 'package_addon_projection_unchanged', $retried['retry']['result'], 'old recovery control rederives current state after another Package enables the product' );
		$this->same( 'package_only_addon', get_post_meta( $this->wc_id( $p ), 'bc_package_relation', true ), 'Retry never replays the old zero-enabled projection' );
		$this->same( $before, $this->state(), 'already-correct recovery Retry is byte-identical' );
		$this->same( $before_b, $this->snapshot( $b ), 'recovery rendering and requests leave the other Hall byte-identical' );
	}

	private function authorization( int $a, int $b ): void {
		global $wpdb;
		$p = $this->play(); $package = $this->package( [ $p ] );
		$row = $this->row( $this->service->create( $package, $p, '5.00' ) );
		$before = $this->state();
		$this->code( 'invalid_request', $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, false ) ], 'save', [], 'GET' ), 'GET never mutates' );
		$this->code( 'invalid_nonce', $this->submit( $package, [], 'save', [ '_wpnonce' => 'invalid' ] ), 'invalid nonce refused' );
		$this->same( [ 'error', 'The form expired or could not be verified. Reload before trying again.' ], $this->summary_notice( $this->section_after_toolbar( $a, $b ) ), 'global nonce error retains its own feedback without a row summary' );
		$this->same( $before, $this->state(), 'method and nonce failures cause no change' );
		foreach ( [ [ 'package_id' => '999999999' ], [ 'bc_addons_complete' => '' ], [ 'addons' => 'bad' ], [ 'bc_addons_action' => [ 'save' ] ] ] as $bad ) {
			$this->code( 'invalid_request', $this->submit( $package, [], 'save', $bad ), 'strict request envelope rejected before row processing' );
		}
		wp_set_current_user( 0 );
		$this->code( 'package_addon_management_denied', $this->submit( $package, [] ), 'anonymous actor denied; no unauthenticated handler' );
		wp_set_current_user( $this->admin );
		$manager = $this->user( 'bc_hall_manager', $a );
		$operator = $this->user( 'bc_game_operator', $a );
		wp_set_current_user( $operator );
		$this->code( 'package_addon_management_denied', $this->submit( $package, [] ), 'Game Operator denied before nonce/row work' );
		wp_set_current_user( $manager );
		$this->same( true, current_user_can( 'bc_manage_products' ), 'owned Hall Manager fixture has required capability' );
		$result = $this->submit( $package, [ 'r' . $row['package_addon_id'] => $this->input( $row, false ) ] );
		$this->code( 'package_addon_updated', $result['r' . $row['package_addon_id']]['result'], 'Hall Manager current-Hall success' );
		wp_set_current_user( $this->admin );
		switch_to_blog( 1 );
		$this->code( 'invalid_hall_context', $this->submit( $package, [] ), 'Super Admin without explicit Hall denied' );
		restore_current_blog();
		$a_row = $this->service->listForPackage( $package )[0];
		switch_to_blog( $b );
		$this->game_type = $this->game_type();
		$this->owned( $b );
		$now = current_time( 'mysql', true );
		// Deliberately same numeric IDs in independent per-site namespaces.
		$wpdb->insert( Migrations::play_products_table(), [ 'bingo_play_product_id' => $p, 'wc_product_id' => $this->product( 'Hall B secret product' ), 'medium' => 'paper', 'faces_per_unit' => 6, 'game_type_id' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
		$wpdb->insert( Migrations::packages_table(), [ 'package_id' => $package, 'wc_product_id' => $this->product( 'Hall B Package' ), 'package_type' => 'paper', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
		$b_row = $this->row( $this->service->create( $package, $p, '88.88' ) );
		$wpdb->update( Migrations::package_addons_table(), [ 'package_addon_id' => $a_row['package_addon_id'] ], [ 'package_addon_id' => $b_row['package_addon_id'] ] );
		$only_b = $this->play( 'paper', 'Hall B only candidate' );
		$only_b_row = $this->row( $this->service->create( $package, $only_b, '99.99' ) );
		$this->grant( $b, $manager, 'bc_hall_manager' );
		$before_b = $this->snapshot( $b ); $audit_b = $this->audits();
		restore_current_blog();
		wp_set_current_user( $manager );
		$changed = $this->submit( $package, [ 'r' . $a_row['package_addon_id'] => array_replace( $this->input( $a_row, false ), [ 'unit_price' => '27.00' ] ) ] );
		$this->code( 'package_addon_updated', $changed['r' . $a_row['package_addon_id']]['result'], 'same-number Hall A identity permits actual Hall A mutation only' );
		$a_row = $this->row( $changed['r' . $a_row['package_addon_id']]['result'] );
		for ( $i = 0; $i < 3; ++$i ) {
			$result = $this->submit( $package, [ 'r' . $a_row['package_addon_id'] => $this->input( $a_row, false ), 'p' . $only_b => [ 'enabled' => '1', 'unit_price' => '4.23', 'max_quantity' => '1' ], 'r' . $only_b_row['package_addon_id'] => $this->input( $only_b_row, false ) ], 'save', [ 'blog_id' => (string) $b, 'site_id' => (string) $b, 'hall_id' => (string) $b ] );
			$this->code( 'package_addon_unchanged', $result['r' . $a_row['package_addon_id']]['result'], 'same-number identity resolves only Hall A' );
			$this->code( 'play_product_not_found', $result['p' . $only_b]['result'], 'Hall B-only product inaccessible from A' );
			$this->code( 'package_addon_not_found', $result['r' . $only_b_row['package_addon_id']]['result'], 'Hall B-only relationship inaccessible from A' );
			$html = $this->section( $package );
			$this->same( false, str_contains( $html, 'Hall B secret' ) || str_contains( $html, '88.88' ), 'Hall B name and price never rendered in A' );
			switch_to_blog( $b );
			$html = $this->section( $package );
			$this->same( false, str_contains( $html, 'class="notice ' ) || str_contains( $html, 'value="retry:' ), 'site switch cannot expose previous Hall summary/error/warning/retry state even with same-number Package IDs' );
			$this->same( true, str_contains( $html, 'Hall B secret' ) && str_contains( $html, '88.88' ), 'new Hall resolves its own same-number product/configuration' );
			$this->same( $audit_b, $this->audits(), 'Hall A requests never write Hall B audit' );
			restore_current_blog();
		}
		$this->same( $before_b, $this->snapshot( $b ), 'Hall B byte-identical after Hall A operations and repeated switching' );
		wp_set_current_user( $this->admin );
	}

	private function call( string $method, mixed ...$arguments ): mixed { return ( new ReflectionMethod( PackageAdmin::class, $method ) )->invoke( null, ...$arguments ); }
	private function model( int $package ): array { $result = $this->call( 'addons_model', $package, true ); if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Model: ' . $result->get_error_code() ); } return $result; }
	private function section( int $package ): string { ob_start(); $this->call( 'render_addons', $package ); return ob_get_clean(); }
	private function success_messages( string $html ): array { preg_match_all( '/<p class="notice notice-success">(.*?)<\/p>/s', $html, $matches ); return $matches[1]; }
	private function summary_notice( string $html ): array { preg_match( '/<p class="notice notice-(success|info|warning|error)">(.*?)<\/p>/s', $html, $match ); return $match ? [ $match[1], $match[2] ] : []; }
	private function section_after_toolbar( int $a, int $b ): string {
		$bar = new WP_Admin_Bar();
		$bar->user = (object) [ 'active_blog' => get_site( $a ), 'blogs' => [
			(object) [ 'userblog_id' => $a, 'blogname' => 'Owned Hall A' ],
			(object) [ 'userblog_id' => $b, 'blogname' => 'Owned Hall B' ],
		] ];
		wp_admin_bar_my_sites_menu( $bar );
		ob_start(); PackageAdmin::render(); $html = ob_get_clean();
		preg_match( '/<section id="bc-package-addons">.*?<\/section>/s', $html, $match );
		return $match[0] ?? '';
	}
	private function screen( int $package = 0 ): string {
		$_POST = []; $_GET = $package ? [ 'edit' => (string) $package ] : []; $_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start(); PackageAdmin::render(); return ob_get_clean();
	}
	private function html_row( string $html, string $key ): string { preg_match( '/<tr data-addon-row="' . preg_quote( $key, '/' ) . '".*?<\/tr>/s', $html, $match ); return $match[0] ?? ''; }
	private function submit( int $package, array $rows, string $action = 'save', array $override = [], string $method = 'POST' ): mixed {
		$this->owned( get_current_blog_id() === 1 ? array_key_first( $this->sites ) : get_current_blog_id() );
		$_GET = [ 'edit' => (string) $package ]; $_SERVER['REQUEST_METHOD'] = $method;
		$_POST = wp_slash( array_replace( [ 'bc_addons_form' => '1', 'bc_addons_complete' => '1', 'package_id' => (string) $package, '_wpnonce' => wp_create_nonce( $this->call( 'addons_nonce', $package ) ), 'bc_addons_action' => $action, 'addons' => $rows ], $override ) );
		PackageAdmin::load_editor();
		return ( new ReflectionProperty( PackageAdmin::class, 'addons_response' ) )->getValue()['result'];
	}
	private function input( array $row, bool $enabled = true ): array {
		$input = [ 'unit_price' => $row['unit_price'], 'max_quantity' => (string) $row['max_quantity'], 'fingerprint' => $row['fingerprint'] ];
		if ( $enabled ) { $input['enabled'] = '1'; } return $input;
	}
	private function row( mixed $result ): array { if ( ! $result instanceof PackageAddonResult ) { throw new RuntimeException( 'Expected relationship: ' . ( $result instanceof WP_Error ? $result->get_error_code() : get_debug_type( $result ) ) ); } return $result->package_addon; }
	private function code( string $code, mixed $result, string $label ): void { $this->same( $code, $result instanceof WP_Error ? $result->get_error_code() : ( $result->status ?? null ), $label ); }
	private function same( mixed $expected, mixed $actual, string $label ): void {
		if ( $expected === $actual ) { ++$this->passed; echo "PASS {$label}\n"; }
		else { ++$this->failed; echo "FAIL {$label}: " . var_export( $actual, true ) . " (expected " . var_export( $expected, true ) . ")\n"; }
	}
	private function site(): int {
		$site = wp_insert_site( [ 'domain' => 'addon-admin-' . strtolower( wp_generate_password( 16, false, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Owned add-on admin fixture', 'user_id' => $this->admin ] );
		if ( $site instanceof WP_Error || $site <= 4 ) { throw new RuntimeException( 'Disposable site creation failed.' ); }
		$this->sites[ (int) $site ] = true; update_site_meta( $site, self::OWNER, $this->token ); return (int) $site;
	}
	private function owned( int $site ): void {
		global $wpdb;
		if ( ! isset( $this->sites[ $site ] ) || $site <= 4 || $this->token !== get_site_meta( $site, self::OWNER, true ) || $wpdb->get_blog_prefix( $site ) !== $wpdb->base_prefix . $site . '_' || ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) || DB_NAME === BC_Fixture::$live_db || BC_FIXTURE_IDENTITY !== $wpdb->get_var( 'SELECT identity FROM bc_fixture_sentinel' ) ) { throw new RuntimeException( 'Fixture ownership guard refused operation.' ); }
	}
	private function user( string $role, int $site ): int {
		$this->owned( $site ); $name = 'addon-admin-' . strtolower( wp_generate_password( 14, false, false ) );
		$id = wp_insert_user( [ 'user_login' => $name, 'user_pass' => wp_generate_password(), 'user_email' => $name . '@example.invalid', 'role' => '' ] );
		if ( $id instanceof WP_Error ) { throw new RuntimeException( 'Owned user creation failed.' ); }
		$this->users[ $id ] = true; update_user_meta( $id, self::OWNER, $this->token );
		$this->grant( $site, $id, $role ); return $id;
	}
	private function grant( int $site, int $id, string $role ): void {
		global $wpdb;
		$this->owned( $site );
		if ( ! isset( $this->users[ $id ] ) || $this->token !== get_user_meta( $id, self::OWNER, true ) ) { throw new RuntimeException( 'Owned user grant refused.' ); }
		// Same exact owned-user fixture pattern as Slice 2; never weaken Staff writer guards.
		$key = $wpdb->get_blog_prefix( $site ) . 'capabilities';
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key ] );
		if ( 1 !== $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $id, 'meta_key' => $key, 'meta_value' => serialize( [ $role => true ] ) ] ) ) { throw new RuntimeException( 'Owned role fixture failed.' ); }
		clean_user_cache( $id );
	}
	private function product( string $title = 'Owned add-on product' ): int {
		$this->owned( get_current_blog_id() );
		$id = wp_insert_post( [ 'post_type' => 'product', 'post_title' => $title, 'post_status' => 'publish' ], true );
		if ( $id instanceof WP_Error || $id < 1 ) { throw new RuntimeException( 'Owned product creation failed.' ); } return (int) $id;
	}
	private function game_type(): int {
		global $wpdb; $this->owned( get_current_blog_id() ); $now = current_time( 'mysql', true ); $code = 'addon-admin-' . wp_generate_password( 12, false, false );
		if ( 1 !== $wpdb->insert( Migrations::game_types_table(), [ 'code' => $code, 'name' => $code, 'local_name_key' => $code, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] ) ) { throw new RuntimeException( 'Game Type fixture failed.' ); } return (int) $wpdb->insert_id;
	}
	private function play( string $medium = 'paper', string $title = 'Owned add-on product' ): int {
		$result = ( new BingoPlayProductService() )->create( $this->product( $title ), $medium, 6, 'digital' === $medium ? $this->game_type : null );
		if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Play fixture: ' . $result->get_error_code() ); } return (int) $result->play_product['bingo_play_product_id'];
	}
	private function package( array $ids ): int {
		$result = ( new PackageService() )->create( $this->product( 'Owned Package' ), array_map( static fn( $id ) => [ 'bingo_play_product_id' => $id, 'quantity' => 1 ], $ids ) );
		if ( $result instanceof WP_Error ) { throw new RuntimeException( 'Package fixture: ' . $result->get_error_code() ); } return (int) $result->package['package_id'];
	}
	private function wc_id( int $id ): int { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT wc_product_id FROM %i WHERE bingo_play_product_id = %d', Migrations::play_products_table(), $id ) ); }
	private function audits(): array { global $wpdb; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE blog_id = %d ORDER BY id', CoreMigrations::audit_log_table(), get_current_blog_id() ), ARRAY_A ); if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Audit read failed.' ); } return $rows; }
	private function state(): array { return [ $this->snapshot( get_current_blog_id() ), $this->audits() ]; }
	private function snapshot( int $site ): array {
		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) );
		if ( ! is_array( $tables ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot inventory failed.' ); }
		$result = [];
		foreach ( $tables as $table ) {
			$ddl = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
			if ( ! is_array( $ddl ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot schema failed.' ); }
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot rows failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded, SORT_STRING );
			$result[ $table ] = hash( 'sha256', $ddl[1] . serialize( $encoded ) );
		}
		ksort( $result ); return $result;
	}
	private function cleanup(): void {
		global $wpdb;
		foreach ( array_keys( $this->users ) as $user ) {
			if ( $this->token !== get_user_meta( $user, self::OWNER, true ) || is_super_admin( $user ) ) { throw new RuntimeException( 'User ownership guard refused cleanup.' ); }
			foreach ( array_keys( $this->sites ) as $site ) {
				$this->owned( $site );
				$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => $wpdb->get_blog_prefix( $site ) . 'capabilities' ] );
			}
			clean_user_cache( $user );
			if ( ! wpmu_delete_user( $user ) || false !== get_userdata( $user ) ) { throw new RuntimeException( 'Owned user cleanup failed.' ); }
			unset( $this->users[ $user ] );
		}
		foreach ( array_keys( $this->sites ) as $site ) {
			$this->owned( $site );
			if ( false === $wpdb->delete( CoreMigrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] ) || false === $wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $site ], [ '%d' ] ) ) { throw new RuntimeException( 'Owned Hall log cleanup failed.' ); }
			foreach ( self::TABLES as $suffix ) { if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $site ) . $suffix ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); } }
			$deleted = wp_delete_site( $site );
			if ( $deleted instanceof WP_Error || null !== get_site( $site ) ) { throw new RuntimeException( 'Disposable site cleanup failed.' ); }
			$this->same( null, get_site( $site ), 'ownership-proven disposable site deletion verified' ); unset( $this->sites[ $site ] );
		}
	}
}

( new PackageAddonsAdminTest() )->run();
