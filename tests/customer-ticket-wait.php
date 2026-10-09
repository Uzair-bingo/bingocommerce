<?php
/**
 * Digital Bingo ticket waiting area and its read-only status route (2026-10-04/05; ADR-046).
 *
 *  1. States. For an eligible online card order (the Stripe card gateway ID) whose ticket is not shown,
 *     Order Received and the My Account order view render a waiting area: payment confirmation pending
 *     (pending/on-hold), payment confirmed without a ticket, or a failed read ('unavailable'). Offline
 *     gateways, other Stripe methods, Paper and merchandise, failed/cancelled/refunded/draft and voided orders
 *     render nothing, as before; a shown ticket keeps its markup plus its Hall/order scope. The waiting area
 *     carries the status route, a short-lived Hall-and-order-bound credential and, for a signed-in viewer
 *     only, a REST nonce.
 *  2. Authorization of the page. Hall, order key, customer ownership and guest email verification are
 *     unchanged; the credential is issued only after them.
 *  3. The status route (AGENTS.md rule-5 exception, this route only). One POST-only route with an explicit
 *     permission callback and a required, validated, sanitized credential taken from the JSON body only.
 *     Every request needs the credential; a customer-owned order also still requires the signed-in owner
 *     (current_user_can view_order), and for a guest-owned order the credential is the authorization.
 *     Missing, malformed, tampered, expired, over-long, unknown-order, cross-Hall, URL-supplied,
 *     order-ID-only and generic-nonce credentials disclose nothing. It returns only the scope and state, plus
 *     the reference for a displayable ticket, and never writes.
 *  4. Real requests through php-cgi (tests/customer-ticket-wait-request.php): both displays as a guest and
 *     as the signed-in owner; the route with real cookies and REST nonces, observed to start no WooCommerce
 *     session or cart and to write nothing (WooCommerce Admin caches one onboarding transient on a new
 *     Hall's first REST request of any kind, shown with a refused request); guest and signed-in carts filled
 *     after the page loaded survive the checks; guest access through WooCommerce's email-verification form;
 *     a Hall without a My Account page; the same saved ticket after payment.
 *  5. Optional browser stage (BC_TICKET_WAIT_BROWSER=1 with BC_PLAYWRIGHT_MODULE and BC_CHROME_EXECUTABLE;
 *     optional BC_NODE_EXECUTABLE and BC_TICKET_WAIT_SHOTS, an existing /private/tmp directory):
 *     tests/customer-ticket-wait-browser.cjs drives the production script on those real pages, changing
 *     server state between steps with tests/customer-ticket-wait-control.php.
 *
 * BC_TEST_DB=bingocommerce_state_recon php tests/customer-ticket-wait.php
 * Token-owned disposable Halls and customers only; mail suppressed, outbound HTTP refused, the network
 * electronic-limit setting restored. The fixture card gateway moves no money: like Stripe Checkout
 * Sessions it leaves the order pending, and native payment_complete() stands in for the webhook.
 */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: CLI and the isolated bingocommerce_state_recon database are required.\n" ); exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';
if ( 'bingocommerce_state_recon' !== DB_NAME ) { fwrite( STDERR, "FIXTURE GUARD: bound to the wrong database.\n" ); exit( 1 ); }
require_once ABSPATH . 'wp-content/plugins/bingocommerce-digital-tickets/bingocommerce-digital-tickets.php';

use BingoCommerce\Catalog\BingoPlayProductService;
use BingoCommerce\Catalog\ElectronicLimit;
use BingoCommerce\Catalog\GameTypeService;
use BingoCommerce\Catalog\PackageAddonService;
use BingoCommerce\Catalog\PackageService;
use BingoCommerce\DigitalTickets\CustomerTicket;
use BingoCommerce\DigitalTickets\DigitalTicket;
use BingoCommerce\DigitalTickets\Migrations;
use BingoCommerce\DigitalTickets\Plugin;
use BingoCommerce\DigitalTickets\TicketStatus;
use BingoCommerce\Sessions\CustomerCart;
use BingoCommerce\Sessions\SessionService;

/** The Stripe card gateway's ID with no payment transport: the order stays pending, as Checkout Sessions leave it. */
final class TicketWaitFixtureCardGateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id = 'stripe'; $this->enabled = 'yes'; $this->title = 'Credit / Debit Card'; $this->method_title = 'Fixture card (no payment transport)';
		$this->supports = [ 'products' ];
	}
	public function is_available() { return true; }
	public function process_payment( $order_id ) {
		return [ 'result' => 'success', 'redirect' => wc_get_order( $order_id )->get_checkout_order_received_url() ];
	}
}

final class CustomerTicketWaitTest {
	private const OWNER = 'bc_ticket_wait_test_owner';
	private const THEME = 'bingocommerce-bingothemev1';
	private const ROUTE = '/bingocommerce-digital-tickets/v1/ticket-status';

	// The approved customer wording (2026-10-05).
	private const PAYMENT     = 'Payment hasn’t been confirmed. If you have already submitted payment, please don’t pay again while confirmation is pending.';
	private const CONFIRMED   = 'Your payment is confirmed, but your Digital Bingo ticket isn’t available yet.';
	private const UNAVAILABLE = 'Your Digital Bingo ticket can’t be checked right now. If you have already submitted payment, please don’t pay again.';
	private const NOSCRIPT    = 'Reload this page later to check for your Digital Bingo ticket.';
	private const CONFIRMING  = 'Thank you. We’re confirming your payment. This usually takes a few seconds.';
	private const RECEIVED    = 'Thank you. Your order has been received.';
	private const STRINGS     = [ 'checking', 'checkAgain', 'checkingNow', 'exhausted', 'network', 'unexpected', 'storage', 'still', 'manual', 'reload', 'denied', 'closed', 'ready', 'statePayment', 'stateTicket', 'stateUnavailable', 'heading', 'intro', 'label', 'validLead', 'validDetail' ];
	private const CREDENTIAL  = '/\A[1-9][0-9]*\.[1-9][0-9]{9,10}\.[a-f0-9]{64}\z/';

	private array $sites = [];
	private array $users = [];
	private int $admin;
	private string $token;
	private string $observe = '';
	private int $observed = 0;
	private int $passes = 0;
	private int $failures = 0;
	private string $group = '';
	private array $address = [ 'first_name' => 'Fixture', 'last_name' => 'Customer', 'address_1' => '1 Test Street', 'city' => 'Albany', 'state' => 'NY', 'postcode' => '12207', 'country' => 'US', 'email' => 'ticket-wait@example.invalid', 'phone' => '5185550100' ];

	public function run(): void {
		$this->admin = (int) username_exists( 'fixture_admin' );
		$this->token = wp_generate_uuid4();
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'pre_http_request', static fn() => new WP_Error( 'bc_fixture_no_network', 'No outbound HTTP in ticket waiting fixtures.' ) );
		$baseline = [];
		foreach ( [ 2, 3, 4 ] as $site ) { $baseline[ $site ] = $this->snapshot( $site ); }
		$missing = new stdClass();
		$limit = get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing );
		try {
			update_site_option( ElectronicLimit::PLATFORM_OPTION, '500' );
			Plugin::init();
			WC()->payment_gateways()->payment_gateways[] = new TicketWaitFixtureCardGateway();
			// Woo built its gateway objects from the main fixture site; enable the offline BACS one for the Halls' orders.
			WC()->payment_gateways()->payment_gateways()['bacs']->enabled = 'yes';
			$a = $this->fixture( 'A' );
			$b = $this->fixture( 'B' );
			$owner = $this->customer( 'owner', [ $a['site'], $b['site'] ] );
			$stranger = $this->customer( 'stranger', [ $a['site'] ] );
			$pair = $this->colliding_pair( $a, $b, $owner );
			$this->states( $a, $owner );
			$this->authorization( $a, $b, $owner, $stranger, $pair );
			$this->route( $a, $b, $owner, $stranger, $pair );
			$this->email( $a, $owner );
			$this->pages( $a, $b, $owner, $stranger, $pair );
			$this->browser( $a, $owner );
		} catch ( Throwable $e ) {
			++$this->failures;
			fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
		} finally {
			if ( $limit === $missing ) { delete_site_option( ElectronicLimit::PLATFORM_OPTION ); }
			else { update_site_option( ElectronicLimit::PLATFORM_OPTION, $limit ); }
			$this->cleanup();
		}
		$this->group = 'Preservation';
		$this->same( $limit, get_site_option( ElectronicLimit::PLATFORM_OPTION, $missing ), 'network electronic-limit setting restored' );
		foreach ( $baseline as $site => $before ) { $this->same( $before, $this->snapshot( $site ), "persistent fixture Hall {$site} unchanged" ); }
		printf( "Ticket waiting area: %d passed, %d failed.\n", $this->passes, $this->failures );
		exit( $this->failures ? 1 : 0 );
	}

	/* ================================================================== *
	 * 1. States, exclusions and reads only
	 * ================================================================== */

	private function states( array $a, int $owner ): void {
		global $wpdb;
		$this->group = 'States';
		$this->enter( $a['site'], $owner );
		$before = $this->tables( $a['site'] );
		foreach ( [ [ 'digital', 'store' ], [ 'hybrid', 'classic' ] ] as [ $kind, $path ] ) {
			$id = $this->order( $a, $kind, $path );
			$this->same( 'pending', wc_get_order( $id )->get_status(), "{$path} {$kind}: fixture card checkout leaves the order pending" );
			foreach ( [ 'order-received', 'view-order' ] as $display ) {
				$this->waiting( $this->view( $id, $display, wc_get_order( $id )->get_order_key() ), $a, $id, 'payment', "{$path} {$kind} pending, {$display}" );
			}
		}
		$this->same( $before, $this->tables( $a['site'] ), 'rendering pending orders wrote no entitlement, ticket or marker row' );

		$this->group = 'Thank-you line';
		$id = $this->order( $a ); $key = wc_get_order( $id )->get_order_key();
		$n = $this->line( $this->noticed( $id, $key, true ) );
		$this->same( $this->lines( $a, $id, 'payment' ), $n, 'pending card order: “confirming your payment” shown; WooCommerce’s own line kept, hidden, for this Hall and order' );
		$this->pay( $id );
		$this->same( 'payment', $this->view( $id, 'order-received', $key )['section']['wait'] ?? null, 'the ticket area reuses the thank-you line’s read once, so both agree on one page even if payment lands in between' );
		$s = $this->view( $id, 'order-received', $key )['section'];
		$this->same( [ 1, null, DigitalTicket::reference_for_order( $id ) ], [ $s['count'] ?? 0, $s['wait'] ?? null, $s['reference'] ?? null ], 'the next page load reads afresh: the ticket' );
		$this->same( self::RECEIVED, $this->noticed( $id, $key ), 'paid card order with its ticket: WooCommerce’s line unchanged' );
		$id = $this->order( $a ); $this->pay( $id, Migrations::tickets_table() );
		$this->same( $this->lines( $a, $id, 'received' ), $this->line( $this->noticed( $id, wc_get_order( $id )->get_order_key() ) ), 'paid, ticket not issued yet: WooCommerce’s line stays visible (payment is confirmed); the confirming line is only available, hidden' );
		$id = $this->order( $a );
		$this->same( $this->lines( $a, $id, 'received' ), $this->line( $this->with_fault( static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT e.*,t.token' ), fn() => $this->noticed( $id, wc_get_order( $id )->get_order_key() ) ) ), 'pending order whose ticket read fails (unavailable): WooCommerce’s line stays visible (nothing is inferred); the confirming line is available, hidden, for a recovery to payment pending' );
		$this->same( self::RECEIVED, CustomerTicket::received_notice( self::RECEIVED, false ), 'no order (WooCommerce passes false): unchanged' );
		$this->group = 'States';

		$id = $this->order( $a );
		wc_get_order( $id )->update_status( 'on-hold' );
		$this->waiting( $this->view( $id, 'order-received', wc_get_order( $id )->get_order_key() ), $a, $id, 'payment', 'on-hold card order (e.g. under review)' );

		// Paid: the existing ticket, now carrying its Hall/order scope; nothing waiting, no credential.
		$id = $this->order( $a ); $this->pay( $id );
		$reference = DigitalTicket::reference_for_order( $id );
		foreach ( [ 'order-received', 'view-order' ] as $display ) {
			$v = $this->view( $id, $display, wc_get_order( $id )->get_order_key() );
			$s = $v['section'];
			$this->same( [ 1, $a['site'] . ':' . $id, null, null, null, 1, $reference ], [ $s['count'] ?? 0, $s['scope'] ?? null, $s['wait'] ?? null, $s['status'] ?? null, $s['credential'] ?? null, $s['canvases'] ?? null, $s['reference'] ?? null ], "paid card order, {$display}: the existing ticket with its scope; no waiting state or credential" );
			$this->same( [ true, [], 0 ], [ $v['enqueued'], $v['writes'], $v['payments'] ], "paid card order, {$display}: QR renderer enqueued; reads only" );
			$this->same( $this->validity( $id, true ), $s['validity'] ?? null, "paid card order, {$display}: under the QR, what it is valid for: one Package and its add-ons, one dated Bingo Session" );
		}

		$this->group = 'QR validity message';
		// A Session with a start time whose generated name already ends with it; markup in saved names stays text.
		$order = wc_get_order( $id ); $facts = $order->get_meta( 'bc_purchase', true );
		$facts['session_name'] = 'Star <b>Evening</b> — 18:30'; $facts['start_time_local'] = '18:30:00'; $facts['package_name'] = 'Night <i>Pack</i>';
		$order->update_meta_data( 'bc_purchase', $facts ); $order->save();
		$v = $this->view( $id, 'order-received', $order->get_order_key() );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $facts['session_date'] )->format( 'm/d/Y' );
		$this->same( 'Valid for one Session only. This QR covers only the digital part of your purchase: one Package (Night <i>Pack</i>) and its add-ons for the Bingo Session “Star <b>Evening</b>” on ' . $date . ' at 18:30. It can’t be used for any other Session, date or Package.', $v['section']['validity'] ?? null, 'with a start time: shown once (not repeated from the generated name); date as MM/DD/YYYY' );
		$this->same( [ false, false ], [ str_contains( $v['html'], '<b>Evening' ), str_contains( $v['html'], '<i>Pack' ) ], 'saved names are escaped: their markup shows as text and never becomes HTML' );
		$facts['package_type'] = 'paper'; $order->update_meta_data( 'bc_purchase', $facts ); $order->save();
		$s = $this->view( $id, 'order-received', $order->get_order_key() )['section'];
		$this->same( [ 1, true, null ], [ $s['count'] ?? 0, array_key_exists( 'validity', $s ), $s['validity'] ], 'facts that no longer describe a Digital/Hybrid purchase: the saved ticket still shows, without a validity claim' );
		$this->group = 'States';

		// Paid without a ticket: the ticket insert failed, or the entitlement insert failed.
		foreach ( [ 'tickets' => 'ticket insert failed', 'entitlements' => 'entitlement insert failed' ] as $table => $label ) {
			$id = $this->order( $a );
			$this->pay( $id, 'tickets' === $table ? Migrations::tickets_table() : Migrations::table() );
			$this->same( [ true, null ], [ wc_get_order( $id )->is_paid(), DigitalTicket::reference_for_order( $id ) ], "paid, {$label}: no ticket" );
			foreach ( [ 'order-received', 'view-order' ] as $display ) {
				$this->waiting( $this->view( $id, $display, wc_get_order( $id )->get_order_key() ), $a, $id, 'ticket', "paid, {$label}, {$display}" );
			}
		}

		$this->group = 'Exclusions';
		$cases = [];
		$cases['failed'] = fn( int $id ) => wc_get_order( $id )->update_status( 'failed' );
		$cases['cancelled'] = fn( int $id ) => $this->manager( static fn() => wc_get_order( $id )->update_status( 'cancelled' ) );
		$cases['checkout-draft'] = static function ( int $id ): void { $o = wc_get_order( $id ); $o->set_status( 'checkout-draft' ); $o->save(); };
		$cases['refunded (status only, paid without ticket)'] = function ( int $id ): void { $this->pay( $id, Migrations::tickets_table() ); $this->manager( static fn() => wc_get_order( $id )->update_status( 'refunded' ) ); };
		$cases['cancelled then pending again (void marker)'] = function ( int $id ): void { $this->manager( static fn() => wc_get_order( $id )->update_status( 'cancelled' ) ); $o = wc_get_order( $id ); $o->set_status( 'pending' ); $o->save(); };
		$cases['paid without ticket, entitlement voided'] = function ( int $id ) use ( $wpdb ): void { $this->pay( $id, Migrations::tickets_table() ); $wpdb->update( Migrations::table(), [ 'status' => 'void' ], [ 'order_id' => $id ] ); };
		$cases['paid without ticket, whole Package refunded'] = function ( int $id ): void {
			$this->pay( $id, Migrations::tickets_table() );
			$refund = $this->manager( fn() => wc_create_refund( [ 'order_id' => $id, 'amount' => $this->bingo_total( $id ), 'line_items' => $this->bingo_lines( $id ), 'refund_payment' => false ] ) );
			$this->same( true, $refund instanceof WC_Order_Refund, 'native whole-Package refund succeeds without money transport' );
		};
		$cases['bank transfer (on-hold)'] = 'bacs';
		$cases['bank transfer, paid without ticket'] = 'bacs-paid';
		$cases['cheque'] = static function ( int $id ): void { $o = wc_get_order( $id ); $o->set_payment_method( 'cheque' ); $o->save(); };
		$cases['cash on delivery'] = static function ( int $id ): void { $o = wc_get_order( $id ); $o->set_payment_method( 'cod' ); $o->save(); };
		$cases['another Stripe method (stripe_klarna)'] = static function ( int $id ): void { $o = wc_get_order( $id ); $o->set_payment_method( 'stripe_klarna' ); $o->save(); };
		$cases['no payment method'] = static function ( int $id ): void { $o = wc_get_order( $id ); $o->set_payment_method( '' ); $o->save(); };
		$cases['Paper Package'] = 'paper';
		$cases['merchandise only'] = 'merch';
		$cases['admin-created order'] = static function ( int $id ): void { $o = wc_get_order( $id ); $o->set_created_via( 'admin' ); $o->save(); };
		$cases['saved purchase facts missing'] = static function ( int $id ): void { $o = wc_get_order( $id ); $o->delete_meta_data( 'bc_purchase' ); $o->save(); };
		$cases['saved purchase facts name another Hall'] = static function ( int $id ): void { $o = wc_get_order( $id ); $f = $o->get_meta( 'bc_purchase', true ); $f['site_id'] = 3; $o->update_meta_data( 'bc_purchase', $f ); $o->save(); };
		foreach ( $cases as $label => $case ) {
			$kind = in_array( $case, [ 'paper', 'merch' ], true ) ? $case : 'digital';
			$gateway = in_array( $case, [ 'bacs', 'bacs-paid' ], true ) ? 'bacs' : 'stripe';
			$id = $this->order( $a, $kind, 'store', $gateway );
			if ( 'bacs-paid' === $case ) { $this->pay( $id, Migrations::tickets_table() ); }
			elseif ( is_callable( $case ) ) { $case( $id ); }
			$order = wc_get_order( $id ); $facts = $this->facts( $id ); $rows = $this->tables( $a['site'] );
			foreach ( [ 'order-received', 'view-order' ] as $display ) {
				$v = $this->view( $id, $display, $order->get_order_key() );
				$this->same( [ '', false, [], 0 ], [ $v['html'], $v['enqueued'], $v['writes'], $v['payments'] ], "{$label} ({$order->get_status()}, {$order->get_payment_method()}), {$display}: nothing rendered or enqueued; reads only" );
			}
			// Even a credential minted for such an order (the page never issues one) finds nothing to show.
			$r = $this->dispatch( TicketStatus::credential( $order ) );
			$this->same( [ 200, 'closed', [], 0 ], [ $r['status'], $r['data']['state'] ?? null, $r['writes'], $r['payments'] ], "{$label}: the status route answers closed, read-only" );
			$this->same( self::RECEIVED, $this->noticed( $id, $order->get_order_key() ), "{$label}: WooCommerce’s thank-you line unchanged" );
			$this->same( [ $facts, $rows ], [ $this->facts( $id ), $this->tables( $a['site'] ) ], "{$label}: order facts, money and ticket tables unchanged by the views and the route" );
		}

		$this->group = 'Read failures';
		$pending = $this->order( $a ); $paid = $this->order( $a ); $this->pay( $paid ); $cancelled = $this->order( $a );
		$this->manager( static fn() => wc_get_order( $cancelled )->update_status( 'cancelled' ) );
		$void_read = static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT (SELECT COUNT(*) FROM' ) && str_contains( $sql, 'bingo_entitlement_voids' );
		$ticket_read = static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT e.*,t.token' );
		foreach ( [
			'void read fails, pending order' => [ $pending, $void_read, 'unavailable' ],
			'ticket read fails, pending order' => [ $pending, $ticket_read, 'unavailable' ],
			'ticket read fails, paid order whose ticket exists' => [ $paid, $ticket_read, 'unavailable' ],
			'void read fails, cancelled order' => [ $cancelled, $void_read, null ],
			'ticket read fails, cancelled order' => [ $cancelled, $ticket_read, null ],
		] as $label => [ $id, $match, $state ] ) {
			$v = $this->with_fault( $match, fn() => $this->view( $id, 'order-received', wc_get_order( $id )->get_order_key() ) );
			$r = $this->with_fault( $match, fn() => $this->dispatch( TicketStatus::credential( wc_get_order( $id ) ) ) );
			if ( null === $state ) {
				$this->same( [ '', [], 'closed' ], [ $v['html'], $v['writes'], $r['data']['state'] ?? null ], "{$label}: an excluded status shows nothing and the route answers closed, even when reads fail" );
				continue;
			}
			$this->waiting( $v, $a, $id, $state, $label, false );
			$this->same( [ 'unavailable', false ], [ $r['data']['state'] ?? null, isset( $r['data']['reference'] ) ], "{$label}: the route also answers unavailable, without a reference" );
			$this->same( false, str_contains( $v['html'], self::PAYMENT ) || str_contains( $v['html'], self::CONFIRMED ), "{$label}: not labelled as payment pending or preparing" );
		}
		$v = $this->view( $paid, 'order-received', wc_get_order( $paid )->get_order_key() );
		$this->same( DigitalTicket::reference_for_order( $paid ), $v['section']['reference'] ?? null, 'after the fault clears, the same saved ticket is shown' );
		$this->leave();
	}

	/* ================================================================== *
	 * 2. Authorization of the page and Hall/order isolation
	 * ================================================================== */

	/** Each Hall's first order, placed in the same sequence after identical fixtures: colliding local order IDs. */
	private function colliding_pair( array $a, array $b, int $owner ): array {
		$this->group = 'Hall and order isolation';
		$ids = [];
		foreach ( [ $a, $b ] as $f ) { $this->enter( $f['site'], $owner ); $ids[] = $this->order( $f ); $this->leave(); }
		$this->same( $ids[0], $ids[1], 'Halls A and B each hold a card order with the same local ID' );
		return $ids;
	}

	private function authorization( array $a, array $b, int $owner, int $stranger, array $pair ): void {
		$this->group = 'Authorization';
		[ $own, $collide ] = $pair;
		$this->enter( $a['site'], $owner );
		$key = wc_get_order( $own )->get_order_key();
		$this->leave();

		$this->enter( $a['site'], $stranger );
		foreach ( [ 'order-received', 'view-order' ] as $display ) {
			$this->same( '', $this->view( $own, $display, $key )['html'], "another signed-in customer, {$display}, even with the order key: nothing, so no credential" );
		}
		$this->same( self::RECEIVED, $this->noticed( $own, $key ), 'another signed-in customer: WooCommerce’s thank-you line unchanged' );
		$this->leave();
		$this->enter( $a['site'], 0 );
		$this->same( '', $this->view( $own, 'order-received', $key )['html'], 'signed out with the key of a customer’s order: nothing (WooCommerce requires the account)' );
		$this->same( self::RECEIVED, $this->noticed( $own, $key ), 'signed out with that key: WooCommerce’s thank-you line unchanged' );
		$this->same( '', $this->view( $own, 'view-order', null )['html'], 'signed out, My Account view: nothing' );

		$guest = $this->order( $a );
		$order = wc_get_order( $guest ); $guest_key = $order->get_order_key();
		$this->same( 0, $order->get_customer_id(), 'guest order has no account' );
		$this->waiting( $this->view( $guest, 'order-received', $guest_key ), $a, $guest, 'payment', 'guest with the key, inside WooCommerce’s grace period' );
		$this->same( '', $this->view( $guest, 'order-received', 'wc_order_incorrect' )['html'], 'guest with a wrong key: nothing' );
		$this->same( '', $this->view( $guest, 'order-received', null )['html'], 'guest without a key: nothing' );
		$grace = static fn() => -1;
		add_filter( 'woocommerce_order_email_verification_grace_period', $grace );
		$customer = WC()->session->get( 'customer' );
		try {
			WC()->session->set( 'customer', [] );
			$this->same( '', $this->view( $guest, 'order-received', $guest_key )['html'], 'guest after the grace period, unverified: nothing, so no credential' );
			$verified = $this->view( $guest, 'order-received', $guest_key, [], null, [ 'email' => $this->address['email'], 'check_submission' => wp_create_nonce( 'wc_verify_email' ) ] );
			$this->waiting( $verified, $a, $guest, 'payment', 'guest verified through WooCommerce’s email form (its credential then works without the form)' );
			WC()->session->set( 'customer', [ 'email' => $this->address['email'] ] );
			$this->waiting( $this->view( $guest, 'order-received', $guest_key ), $a, $guest, 'payment', 'guest whose session holds the billing email' );
		} finally { remove_filter( 'woocommerce_order_email_verification_grace_period', $grace ); WC()->session->set( 'customer', $customer ); }
		$this->leave();

		$this->group = 'Hall and order isolation';
		$this->enter( $b['site'], $owner );
		$foreign = wc_get_order( $collide );
		$this->waiting( $this->view( $collide, 'view-order', null ), $b, $collide, 'payment', 'Hall B’s own order, in Hall B' );
		$this->leave();
		$this->enter( $a['site'], $owner );
		$v = $this->view( $own, 'view-order', null, [], $foreign );
		$this->same( '', $v['html'], 'Hall B’s order object rendered in Hall A: nothing (reloaded in Hall A, its key does not match)' );
		$this->waiting( $this->view( $own, 'view-order', null ), $a, $own, 'payment', 'Hall A’s own order with the colliding ID' );
		$this->manager( static fn() => wc_get_order( $own )->update_status( 'cancelled' ) );
		$this->same( '', $this->view( $own, 'view-order', null )['html'], 'Hall A’s order cancelled: nothing' );
		$this->leave();
		$this->enter( $b['site'], $owner );
		$this->waiting( $this->view( $collide, 'view-order', null ), $b, $collide, 'payment', 'Hall B’s colliding order unaffected by Hall A’s cancellation and marker' );
		$this->leave();
	}

	/* ================================================================== *
	 * 3. The status route
	 * ================================================================== */

	private function route( array $a, array $b, int $owner, int $stranger, array $pair ): void {
		$this->group = 'Status route';
		$handlers = rest_get_server()->get_routes()[ self::ROUTE ] ?? [];
		$handler = $handlers[0] ?? []; $arg = $handler['args']['credential'] ?? [];
		$this->same( [ 1, [ 'POST' => true ], [ TicketStatus::class, 'permit' ], true, 'string', [ TicketStatus::class, 'valid' ], [ TicketStatus::class, 'sanitize' ] ],
			[ count( $handlers ), $handler['methods'] ?? null, $handler['permission_callback'] ?? null, $arg['required'] ?? null, $arg['type'] ?? null, $arg['validate_callback'] ?? null, $arg['sanitize_callback'] ?? null ],
			'the rule-5 exception stays narrow: one POST-only route, an explicit permission callback, one required, validated and sanitized parameter' );
		$this->same( [ 'credential' ], array_keys( $handler['args'] ?? [] ), 'the credential is the route’s only parameter' );

		$this->enter( $a['site'], 0 );
		$id = $this->order( $a ); $order = wc_get_order( $id ); $credential = TicketStatus::credential( $order );
		$elsewhere = static fn() => 'https://elsewhere.example.invalid/wp-json/bingocommerce-digital-tickets/v1/ticket-status';
		add_filter( 'rest_url', $elsewhere );
		try { $s = $this->view( $id, 'order-received', $order->get_order_key() )['section']; } finally { remove_filter( 'rest_url', $elsewhere ); }
		$this->same( [ 'payment', null, null, null ], [ $s['wait'] ?? null, $s['status'] ?? null, $s['credential'] ?? null, $s['nonce'] ?? null ], 'a status route filtered onto another origin is never offered, and no credential is issued for it' );
		$other = $this->order( $a ); $other_credential = TicketStatus::credential( wc_get_order( $other ) );
		$r = $this->dispatch( $credential );
		$this->same( [ 200, [ 'scope' => "{$a['site']}:{$id}", 'state' => 'payment' ] ], [ $r['status'], $r['data'] ], 'guest credential, payment pending: only the scope and the state' );
		[ $cid, $cexp, $csig ] = explode( '.', $credential );
		$hidden = static fn( array $r ): array => array_values( array_intersect( [ 'scope', 'state', 'reference' ], array_keys( $r['data'] ) ) );
		foreach ( [
			'no credential'                         => [ fn() => $this->dispatch( null ), 400 ],
			'a number, not a string'                => [ fn() => $this->dispatch( null, [], '{"credential":12345}' ), 400 ],
			'malformed'                             => [ fn() => $this->dispatch( 'not-a-credential' ), 400 ],
			'the order ID alone'                    => [ fn() => $this->dispatch( (string) $id ), 400 ],
			'a generic WordPress REST nonce'        => [ fn() => $this->dispatch( wp_create_nonce( 'wp_rest' ) ), 400 ],
			'a WooCommerce Store API nonce'         => [ fn() => $this->dispatch( wp_create_nonce( 'wc_store_api' ) ), 400 ],
			'a tampered signature'                  => [ fn() => $this->dispatch( "{$cid}.{$cexp}." . ( '0' === $csig[63] ? substr( $csig, 0, 63 ) . '1' : substr( $csig, 0, 63 ) . '0' ) ), 403 ],
			'another order’s signature'             => [ fn() => $this->dispatch( "{$cid}.{$cexp}." . explode( '.', $other_credential )[2] ), 403 ],
			'this order’s signature, another ID'    => [ fn() => $this->dispatch( "{$other}.{$cexp}.{$csig}" ), 403 ],
			'an expired credential'                 => [ fn() => $this->dispatch( TicketStatus::credential( $order, time() - 1 ) ), 403 ],
			'a lifetime longer than allowed'        => [ fn() => $this->dispatch( TicketStatus::credential( $order, time() + TicketStatus::TTL + 300 ) ), 403 ],
			'an unknown order'                      => [ fn() => $this->dispatch( '999999.' . ( time() + 600 ) . '.' . str_repeat( 'a', 64 ) ), 403 ],
			'a valid credential in the URL only'    => [ fn() => $this->dispatch( null, [ 'credential' => $credential ], '{}' ), 403 ],
			'a valid credential also in the URL'    => [ fn() => $this->dispatch( $credential, [ 'credential' => $credential ] ), 403 ],
		] as $label => [ $call, $status ] ) {
			$r = $call();
			$this->same( [ $status, [], [], 0 ], [ $r['status'], $hidden( $r ), $r['writes'], $r['payments'] ], "{$label}: refused ({$status}), nothing disclosed, read-only" );
			if ( 403 === $status ) { $this->same( 'bc_ticket_status_unavailable', $r['data']['code'] ?? null, "{$label}: the same neutral refusal as every other credential failure" ); }
		}

		// Payment, then the existing payment-triggered ticket, then terminal states.
		$this->pay( $id, Migrations::tickets_table() );
		$this->same( 'ticket', $this->dispatch( $credential )['data']['state'] ?? null, 'paid, ticket not issued yet: payment confirmed, no reference' );
		$this->same( [ 1, 0, 1 ], $this->counts( $id ), 'checks while the ticket is missing never issue or repair it' );
		do_action( 'woocommerce_payment_complete', $id ); // The existing payment event issues the ticket; the route never does.
		$reference = DigitalTicket::reference_for_order( $id );
		$this->same( [ 1, 1, 1 ], $this->counts( $id ), 'the existing payment event issued exactly one entitlement and one ticket' );
		$rows = $this->tables( $a['site'] );
		foreach ( range( 1, 10 ) as $check ) { $r = $this->dispatch( $credential ); }
		$this->same( [ 200, [ 'scope' => "{$a['site']}:{$id}", 'state' => 'ready', 'reference' => $reference ], [], 0 ], [ $r['status'], $r['data'], $r['writes'], $r['payments'] ], 'ticket issued by the payment event: ready with that exact reference; reads only' );
		$this->same( [ [ 1, 1, 1 ], $rows ], [ $this->counts( $id ), $this->tables( $a['site'] ) ], 'ten more checks created no entitlement or ticket and changed no row' );
		$this->manager( static fn() => wc_get_order( $id )->update_status( 'cancelled' ) );
		$this->same( [ 'closed', false ], [ $this->dispatch( $credential )['data']['state'] ?? null, isset( $this->dispatch( $credential )['data']['reference'] ) ], 'cancelled after issuance: closed, and the voided ticket’s reference is never returned' );
		$refunded = $this->order( $a ); $this->pay( $refunded, Migrations::tickets_table() );
		$this->manager( static fn() => wc_get_order( $refunded )->update_status( 'refunded' ) );
		$this->same( 'closed', $this->dispatch( TicketStatus::credential( wc_get_order( $refunded ) ) )['data']['state'] ?? null, 'refunded: closed' );
		$this->leave();

		// Customer-owned orders keep the signed-in ownership check.
		$this->enter( $a['site'], $owner );
		$mine = $this->order( $a ); $mine_credential = TicketStatus::credential( wc_get_order( $mine ) );
		$this->same( 200, $this->dispatch( $mine_credential )['status'], 'signed-in owner with the credential: answered' );
		$this->leave();
		$this->enter( $a['site'], $stranger );
		$r = $this->dispatch( $mine_credential );
		$this->same( [ 403, [] ], [ $r['status'], $hidden( $r ) ], 'another signed-in customer with the owner’s valid credential: refused, nothing disclosed' );
		$this->leave();
		$this->enter( $a['site'], 0 );
		$r = $this->dispatch( $mine_credential );
		$this->same( [ 403, [] ], [ $r['status'], $hidden( $r ) ], 'signed out with the owner’s valid credential: refused (a guest’s user ID of zero is never the owner)' );
		$this->leave();

		// Cross-Hall: the colliding orders belong to the same customer, yet neither Hall's credential works in the other.
		[ $own, $collide ] = $pair;
		$this->enter( $b['site'], $owner ); $b_credential = TicketStatus::credential( wc_get_order( $collide ) ); $this->leave();
		$this->enter( $a['site'], $owner ); $a_credential = TicketStatus::credential( wc_get_order( $own ) );
		$this->same( [ 403, [] ], [ $this->dispatch( $b_credential )['status'], $hidden( $this->dispatch( $b_credential ) ) ], 'Hall B’s credential in Hall A, for the same customer’s order with the same local ID: refused' );
		$this->same( 200, $this->dispatch( $a_credential )['status'], 'Hall A’s own credential in Hall A: answered' );
		$this->leave();
		$this->enter( $b['site'], $owner );
		$this->same( 403, $this->dispatch( $a_credential )['status'], 'Hall A’s credential in Hall B: refused' );
		$r = $this->dispatch( $b_credential );
		$this->same( [ 200, [ 'scope' => "{$b['site']}:{$collide}", 'state' => 'payment' ] ], [ $r['status'], $r['data'] ], 'Hall B’s credential in Hall B: answered for Hall B’s own order' );
		$this->leave();
	}

	/* ================================================================== *
	 * 4. Email eligibility unchanged
	 * ================================================================== */

	private function email( array $a, int $owner ): void {
		$this->group = 'Email';
		$this->enter( $a['site'], $owner );
		$email = WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order'];
		$link = function ( int $id, bool $admin = false, bool $plain = false ) use ( $email ): string { ob_start(); CustomerTicket::email_order_link( wc_get_order( $id ), $admin, $plain, $email ); return (string) ob_get_clean(); };
		// The button: one link to this Hall's Order Received URL with the complete order key, styled inline in the
		// Hall's email base colour.
		$button = function ( string $html ): array {
			$dom = new DOMDocument(); libxml_use_internal_errors( true ); $dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET ); libxml_clear_errors();
			$anchors = $dom->getElementsByTagName( 'a' ); $a = $anchors->item( 0 );
			$cells = ( new DOMXPath( $dom ) )->query( '//td[@bgcolor]' );
			return [ 'links' => $anchors->length, 'href' => $a ? $a->getAttribute( 'href' ) : null, 'text' => $a ? trim( $a->textContent ) : null, 'style' => $a ? $a->getAttribute( 'style' ) : '',
				'cell' => $cells->length ? $cells->item( 0 )->getAttribute( 'bgcolor' ) : null, 'occurrences' => substr_count( html_entity_decode( $html ), '?key=' ) ];
		};
		$base = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color' ) ) ?: '#720eec';
		$check = function ( int $id, string $label ) use ( $link, $button, $base, $a ): void {
			$url = wc_get_order( $id )->get_checkout_order_received_url(); $b = $button( $link( $id ) );
			$this->same( [ 1, $url, 'View your Bingo order', $base ], [ $b['links'], $b['href'], $b['text'], $b['cell'] ], "{$label}: one “View your Bingo order” button to this order’s own Order Received URL, in the Hall’s email colour" );
			$this->same( [ 1, 1, true ], [ preg_match( '#^https?://' . preg_quote( strtolower( get_site( $a['site'] )->domain ), '#' ) . '/[^?]*order-received/' . $id . '/\?key=wc_order_[A-Za-z0-9]+$#', $url ), substr_count( $b['href'], wc_get_order( $id )->get_order_key() ), 1 === $b['occurrences'] ], "{$label}: this Hall and order, with the complete order key, in the email once (the button)" );
			$this->same( true, str_contains( $b['style'], 'background-color:' . $base ) && str_contains( $b['style'], 'display:inline-block' ) && str_contains( $b['style'], 'padding:14px 28px' ) && str_contains( $b['style'], 'text-decoration:none' ) && str_contains( $b['style'], 'max-width:100%' ), "{$label}: styled inline as a button (filled, padded, no underline, never wider than the email)" );
			$this->same( "\nView your Bingo order: " . esc_url_raw( $url ) . "\n", $link( $id, false, true ), "{$label}: plain-text email keeps the readable label and address" );
		};
		$pending = $this->order( $a );
		$this->same( '', $link( $pending ), 'pending card order: no email link' );
		$paid = $this->order( $a ); $this->pay( $paid );
		$check( $paid, 'paid card order' );
		$this->same( '', $link( $paid, true ), 'admin email: no link' );
		$this->same( '', $link( $paid, true, true ), 'admin plain-text email: no link' );
		$no_ticket = $this->order( $a ); $this->pay( $no_ticket, Migrations::tickets_table() );
		$check( $no_ticket, 'paid card order without a ticket (the link never depended on the ticket)' );
		$bacs = $this->order( $a, 'digital', 'store', 'bacs' ); $this->pay( $bacs );
		$check( $bacs, 'paid bank-transfer order' );
		$paper = $this->order( $a, 'paper' ); $this->pay( $paper );
		$this->same( '', $link( $paper ), 'paid Paper order: no link' );
		$this->same( '', $link( $paper, false, true ), 'paid Paper order, plain text: no link' );
		// Escaping: a filtered URL carrying quotes and markup cannot break out of the attribute or the text.
		$hostile = static fn( $url ) => $url . '&x="><script>alert(1)</script>';
		add_filter( 'woocommerce_get_checkout_order_received_url', $hostile );
		try { $html = $link( $paid ); } finally { remove_filter( 'woocommerce_get_checkout_order_received_url', $hostile ); }
		$this->same( [ false, false, 1 ], [ str_contains( $html, '<script' ), str_contains( $html, '">' . '<script' ), $button( $html )['links'] ], 'a URL with quotes and markup stays escaped: no script element, the link intact' );
		// WooCommerce's own CSS inliner (as used for the real email) keeps the button's styling.
		$email->object = wc_get_order( $paid );
		$inlined = $email->style_inline( '<div id="body_content_inner">' . $link( $paid ) . '</div>' );
		$b = $button( $inlined ); $style = str_replace( ' ', '', $b['style'] );
		$cells = preg_match_all( '#<td align="center"(?: bgcolor="[^"]+")? style="([^"]*)"#', $inlined, $m ) ? array_map( static fn( $c ) => str_contains( str_replace( ' ', '', $c ), 'padding:0' ) && ! str_contains( str_replace( ' ', '', $c ), 'padding:12px' ), $m[1] ) : [];
		$this->same( [ 1, true, true, [ true, true ] ], [ $b['links'], str_contains( $style, 'background-color:' . $base ), str_contains( $style, 'text-decoration:none' ), $cells ], 'after WooCommerce’s email CSS inliner: still one filled button without an underline; its cells keep no added padding' );
		$shots = (string) getenv( 'BC_TICKET_WAIT_SHOTS' );
		if ( '' !== $shots && str_starts_with( (string) realpath( $shots ), '/private/tmp/' ) ) {
			// The complete styled customer email for a disposable fixture order, for a phone-width rendering check.
			$email->setup_locale();
			file_put_contents( $shots . '/processing-email.html', $email->style_inline( $email->get_content_html() ) );
			$email->restore_locale();
		}
		$this->leave();
	}

	/* ================================================================== *
	 * 5. Real requests through php-cgi
	 * ================================================================== */

	private function pages( array $a, array $b, int $owner, int $stranger, array $pair ): void {
		$this->group = 'Real pages';
		$this->enter( $a['site'], $owner );
		$id = $this->order( $a ); $order = wc_get_order( $id );
		$received = $order->get_checkout_order_received_url(); $view = $order->get_view_order_url();
		$this->leave();
		$this->enter( $a['site'], $stranger );
		$theirs = wc_get_order( $this->order( $a ) )->get_view_order_url();
		$this->leave();
		$this->enter( $a['site'], 0 );
		$guest = $this->order( $a, 'hybrid', 'classic' ); $guest_url = wc_get_order( $guest )->get_checkout_order_received_url();
		$aged = $this->order( $a ); $aged_url = wc_get_order( $aged )->get_checkout_order_received_url();
		$this->leave();
		$cookie = [ LOGGED_IN_COOKIE => $this->cookie( $owner ) ]; $other = [ LOGGED_IN_COOKIE => $this->cookie( $stranger ) ];
		$route = $this->route_url( $a );
		$unpaid = $this->tables( $a['site'] );
		$pages = [];
		foreach ( [ 'owner, Order Received' => [ $received, $cookie, $id, true ], 'owner, My Account view' => [ $view, $cookie, $id, true ], 'guest, Order Received' => [ $guest_url, [], $guest, false ] ] as $label => [ $url, $with, $order_id, $signed ] ) {
			$r = $this->cgi( $a, $url, [ 'cookies' => $with ] );
			$s = $pages[ $label ] = $this->section( $r['body'] );
			$this->same( [ 200, 1, $a['site'] . ':' . $order_id, 'payment', self::PAYMENT, self::NOSCRIPT, $route ], [ $r['status'], $s['count'] ?? 0, $s['scope'] ?? null, $s['wait'] ?? null, $s['message'] ?? null, $s['noscript'] ?? null, $s['status'] ?? null ], "{$label}: theme page with the payment-pending area and the status route" );
			$this->same( [ 1, $signed ], [ preg_match( self::CREDENTIAL, (string) ( $s['credential'] ?? '' ) ), null !== ( $s['nonce'] ?? null ) ], "{$label}: a credential; a REST nonce only when signed in" );
			$this->same( true, str_contains( $r['body'], 'woocommerce-table--order-details' ) && str_contains( $r['body'], 'bingocommerce-bingothemev1' ), "{$label}: BingoThemev1 order details page" );
			$this->same( [ 1, 1, true ], [ preg_match_all( '#<script[^>]+src="[^"]*bingocommerce-digital-tickets/assets/customer-ticket\.js\?ver=1\.3\.3"#', $r['body'] ), preg_match_all( '#<script[^>]+src="[^"]*jquery-qrcode/jquery\.qrcode\.min\.js#', $r['body'] ), self::STRINGS === array_keys( $this->strings( $r['body'] ) ) ], "{$label}: production script, local encoder and its strings in the footer" );
			$this->same( false, (bool) preg_match( '/data-bc-ticket="|BC1\.[0-9]+\.|data-bc-ticket-check/', $r['body'] ), "{$label}: no ticket reference and no page-fetch URL anywhere" );
			$this->same( 1, preg_match_all( '#<link[^>]+href=[\'"][^\'"]*bingocommerce-digital-tickets/assets/customer-ticket\.css\?ver=1\.3\.3[\'"]#', $r['body'] ), "{$label}: the ticket area’s stylesheet (status panels and activity indicator)" );
			$n = $this->notice( $r['body'] );
			$expected = str_contains( $label, 'Order Received' ) ? $this->lines( $a, $order_id, 'payment' ) : null;
			$this->same( $expected, $n, "{$label}: " . ( $expected ? 'the thank-you line says payment is being confirmed; WooCommerce’s line kept hidden' : 'no thank-you line (not Order Received)' ) );
		}
		$stripe_return = add_query_arg( [ 'order_id' => $id, 'wc_payment_method' => 'stripe', '_wpnonce' => 'fixture', 'wc_stripe_cs' => '1', 'pay_for_order' => 'no' ], $received );
		$s = $this->section( $this->cgi( $a, $stripe_return, [ 'cookies' => $cookie ] )['body'] );
		$this->same( [ 'payment', $route, 1 ], [ $s['wait'] ?? null, $s['status'] ?? null, preg_match( self::CREDENTIAL, (string) ( $s['credential'] ?? '' ) ) ], 'Stripe return URL shape: the same waiting area and status route; returning from Stripe shows no success or QR' );
		$this->same( 0, $this->section( $this->cgi( $a, $view, [ 'cookies' => $other ] )['body'] )['count'] ?? null, 'another customer’s cookie, My Account view: no ticket area, no credential' );
		$this->same( 0, $this->section( $this->cgi( $a, add_query_arg( 'key', 'wc_order_incorrect', $guest_url ) )['body'] )['count'] ?? null, 'wrong order key: no ticket area, no credential' );
		$this->same( 0, $this->section( $this->cgi( $a, $view )['body'] )['count'] ?? null, 'signed out, My Account view: login form, no ticket area' );
		foreach ( [ 'void-read' => 'void read', 'ticket-read' => 'ticket read' ] as $fault => $label ) {
			$s = $this->section( $this->cgi( $a, $received, [ 'cookies' => $cookie, 'fault' => $fault ] )['body'] );
			$page = $this->cgi( $a, $received, [ 'cookies' => $cookie, 'fault' => $fault ] )['body'];
			$s = $this->section( $page ); $n = $this->notice( $page );
			$this->same( [ 'unavailable', self::UNAVAILABLE, $route, 1 ], [ $s['wait'] ?? null, $s['message'] ?? null, $s['status'] ?? null, preg_match( self::CREDENTIAL, (string) ( $s['credential'] ?? '' ) ) ], "{$label} fails inside the request: neutral unavailable area, still able to check by hand" );
			$this->same( $this->lines( $a, $id, 'received' ), $n, "{$label} fails: WooCommerce’s line stays visible (the page cannot tell); the confirming line is available, hidden, for a recovery" );
		}

		$this->group = 'Status route, real requests';
		$post = fn( array $f, string $credential, array $cookies = [], string $nonce = '', string $fault = '' ): array => $this->cgi( $f, $this->route_url( $f ), [ 'method' => 'POST', 'type' => 'application/json', 'body' => (string) wp_json_encode( [ 'credential' => $credential ] ), 'cookies' => $cookies, 'nonce' => $nonce, 'fault' => $fault, 'observe' => true ] );
		$quiet = function ( array $r, string $label ): void {
			$this->same( [ 'session' => null, 'cart' => null, 'cart_loaded' => 0, 'writes' => [] ], $r['observed'], "{$label}: observed in the real request, no WooCommerce session or cart was started and nothing was written" );
			$this->same( true, str_contains( strtolower( $r['headers']['cache-control'] ?? '' ), 'no-store' ) && str_contains( strtolower( $r['headers']['cache-control'] ?? '' ), 'private' ), "{$label}: Cache-Control no-store, private" );
		};
		$owner_page = $pages['owner, Order Received']; $guest_page = $pages['guest, Order Received'];
		// A new Hall's first REST request of any kind lets WooCommerce Admin cache its onboarding "has products" flag
		// (one transient). Make that a malformed request, refused before any route callback runs, and show it is the only write.
		switch_to_blog( $a['site'] ); $options = $GLOBALS['wpdb']->options; restore_current_blog();
		$r = $post( $a, 'not-a-credential' );
		$this->same( [ 400, [ 'session' => null, 'cart' => null, 'cart_loaded' => 0 ], 1 ], [ $r['status'], array_intersect_key( (array) $r['observed'], [ 'session' => 1, 'cart' => 1, 'cart_loaded' => 1 ] ), count( $r['observed']['writes'] ?? [] ) ], 'the Hall’s first REST request (malformed, refused before any route callback): no session or cart, one write' );
		$this->same( 1, preg_match( '/\AINSERT INTO `' . preg_quote( $options, '/' ) . '` \(`option_name`, `option_value`, `autoload`\) VALUES \(\'_transient_woocommerce_product_task_has_product_transient\'/', (string) ( $r['observed']['writes'][0] ?? '' ) ), 'that write is WooCommerce Admin’s own onboarding transient, not the route’s' );
		$r = $post( $a, $owner_page['credential'], $cookie, $owner_page['nonce'] );
		$this->same( [ 200, [ 'scope' => "{$a['site']}:{$id}", 'state' => 'payment' ] ], [ $r['status'], json_decode( $r['body'], true ) ], 'signed-in owner (cookie and REST nonce from the page): payment pending, only scope and state' );
		$quiet( $r, 'signed-in owner' );
		$r = $post( $a, $owner_page['credential'], $cookie );
		$this->same( [ 403, false ], [ $r['status'], str_contains( $r['body'], '"state"' ) ], 'owner cookie without its REST nonce (as a cross-site request would arrive): treated as signed out and refused' );
		$quiet( $r, 'owner cookie without nonce' );
		$their_nonce = (string) ( $this->section( $this->cgi( $a, $theirs, [ 'cookies' => $other ] )['body'] )['nonce'] ?? '' );
		$r = $post( $a, $owner_page['credential'], $other, $their_nonce );
		$this->same( [ true, 403, false ], [ '' !== $their_nonce, $r['status'], str_contains( $r['body'], '"state"' ) ], 'another signed-in customer (own cookie and nonce) with the owner’s credential: refused' );
		$r = $post( $a, $guest_page['credential'] );
		$this->same( [ 200, [ 'scope' => "{$a['site']}:{$guest}", 'state' => 'payment' ] ], [ $r['status'], json_decode( $r['body'], true ) ], 'guest with the credential from the order page: payment pending' );
		$quiet( $r, 'guest' );
		$sig = substr( $guest_page['credential'], -1 );
		$r = $post( $a, substr( $guest_page['credential'], 0, -1 ) . ( '0' === $sig ? '1' : '0' ) );
		$this->same( [ 403, false ], [ $r['status'], str_contains( $r['body'], '"state"' ) ], 'tampered guest credential: refused' );
		$quiet( $r, 'tampered credential' );
		switch_to_blog( $a['site'] ); $expired = TicketStatus::credential( wc_get_order( $guest ), time() - 5 ); restore_current_blog();
		$this->same( 403, $post( $a, $expired )['status'], 'expired guest credential: refused' );
		[ $own, $collide ] = $pair;
		switch_to_blog( $b['site'] ); $foreign = TicketStatus::credential( wc_get_order( $collide ) ); restore_current_blog();
		$this->same( 403, $post( $a, $foreign, $cookie, $owner_page['nonce'] )['status'], 'Hall B’s credential sent to Hall A by the same signed-in customer: refused' );
		$this->same( 403, $post( $a, $owner_page['credential'], [], '', 'ticket-read' )['status'], 'signed-out request for a customer’s order during a read fault: still refused before any state is read' );
		$r = $post( $a, $owner_page['credential'], $cookie, $owner_page['nonce'], 'ticket-read' );
		$this->same( [ 200, 'unavailable' ], [ $r['status'], json_decode( $r['body'], true )['state'] ?? null ], 'owner during a ticket read fault: unavailable, never a guess' );
		$this->same( $unpaid, $this->tables( $a['site'] ), 'every page view and status request before payment wrote no entitlement, ticket or marker row' );

		$this->group = 'Carts filled after the page loaded';
		$this->seed_cart( $a, $owner );
		$before = $this->cart_row( $a, (string) $owner );
		foreach ( [ 1, 2, 3 ] as $check ) { $post( $a, $owner_page['credential'], $cookie, $owner_page['nonce'] ); }
		$this->same( [ 2, $before ], [ $before['quantity'], $this->cart_row( $a, (string) $owner ) ], 'signed-in owner: three status checks leave the cart from another tab byte-identical (contents and expiry)' );
		$guest_cart = $this->seed_cart( $a, 0 );
		$before = $this->cart_row( $a, $guest_cart['guest'] );
		foreach ( [ 1, 2, 3 ] as $check ) { $r = $post( $a, $guest_page['credential'], [ $guest_cart['name'] => $guest_cart['value'] ] ); }
		$this->same( [ 2, 200, $before ], [ $before['quantity'], $r['status'], $this->cart_row( $a, $guest_cart['guest'] ) ], 'guest (with that cart’s session cookie): three status checks leave it byte-identical' );
		$quiet( $r, 'guest with a session cookie' );
		$this->cgi( $a, $received, [ 'cookies' => $cookie ] );
		$this->same( 0, $this->cart_row( $a, (string) $owner )['quantity'], 'for contrast, loading Order Received itself empties the cart (native WooCommerce), which is why checks never load it' );

		$this->group = 'Guest email verification';
		switch_to_blog( $a['site'] ); $o = wc_get_order( $aged ); $o->set_date_created( time() - 20 * MINUTE_IN_SECONDS ); $o->save(); restore_current_blog();
		$page = $this->cgi( $a, $aged_url );
		$this->same( 0, $this->section( $page['body'] )['count'] ?? null, 'guest after the grace period: WooCommerce asks for the email; no ticket area or credential' );
		$fields = $this->verify_fields( $page['body'] );
		$this->same( true, isset( $fields['check_submission'] ), 'WooCommerce’s own verification form is on the page' );
		$fields['email'] = $this->address['email'];
		$s = $this->section( $this->cgi( $a, $aged_url, [ 'method' => 'POST', 'type' => 'application/x-www-form-urlencoded', 'body' => http_build_query( $fields ) ] )['body'] );
		$this->same( [ 'payment', 1, null ], [ $s['wait'] ?? null, preg_match( self::CREDENTIAL, (string) ( $s['credential'] ?? '' ) ), $s['nonce'] ?? null ], 'after the real form POST: the waiting area with a credential' );
		$r = $post( $a, (string) ( $s['credential'] ?? '' ) );
		$this->same( [ 200, 'payment' ], [ $r['status'], json_decode( $r['body'], true )['state'] ?? null ], 'that credential works for background checks without the form, cookie or session' );
		$wrong = $fields; $wrong['email'] = 'stranger@example.invalid';
		$this->same( 0, $this->section( $this->cgi( $a, $aged_url, [ 'method' => 'POST', 'type' => 'application/x-www-form-urlencoded', 'body' => http_build_query( $wrong ) ] )['body'] )['count'] ?? null, 'a wrong email gets no ticket area and no credential' );

		$this->group = 'No My Account page';
		$s = $this->section( $this->cgi( $a, $received, [ 'cookies' => $cookie, 'no_account' => true ] )['body'] );
		$this->same( [ 'payment', $route, 1 ], [ $s['wait'] ?? null, $s['status'] ?? null, preg_match( self::CREDENTIAL, (string) ( $s['credential'] ?? '' ) ) ], 'signed-in owner in a Hall without a My Account page: the same waiting area and status route' );
		$r = $post( $a, (string) ( $s['credential'] ?? '' ), $cookie, (string) ( $s['nonce'] ?? '' ) );
		$this->same( [ 200, 'payment' ], [ $r['status'], json_decode( $r['body'], true )['state'] ?? null ], 'its automatic checks are answered' );

		$this->group = 'After payment';
		$this->enter( $a['site'], 0 );
		$this->pay( $id ); $this->pay( $guest );
		$references = [ $id => DigitalTicket::reference_for_order( $id ), $guest => DigitalTicket::reference_for_order( $guest ) ];
		$rows = $this->tables( $a['site'] );
		$this->leave();
		foreach ( [ 'owner' => [ $id, $owner_page, $cookie ], 'guest' => [ $guest, $guest_page, [] ] ] as $label => [ $order_id, $page, $with ] ) {
			foreach ( [ 1, 2, 3 ] as $check ) {
				$r = $post( $a, $page['credential'], $with, (string) ( $page['nonce'] ?? '' ) );
				$this->same( [ 200, [ 'scope' => "{$a['site']}:{$order_id}", 'state' => 'ready', 'reference' => $references[ $order_id ] ] ], [ $r['status'], json_decode( $r['body'], true ) ], "{$label}, status check {$check} after payment: the same saved ticket" );
			}
		}
		foreach ( [ 'owner, Order Received' => [ $received, $cookie, $id ], 'owner, My Account view' => [ $view, $cookie, $id ], 'guest, Order Received' => [ $guest_url, [], $guest ] ] as $label => [ $url, $with, $order_id ] ) {
			$page = $this->cgi( $a, $url, [ 'cookies' => $with ] )['body'];
			$s = $this->section( $page );
			$this->same( [ 1, $a['site'] . ':' . $order_id, null, null, 1, $references[ $order_id ] ], [ $s['count'] ?? 0, $s['scope'] ?? null, $s['wait'] ?? null, $s['credential'] ?? null, $s['canvases'] ?? null, $s['reference'] ?? null ], "{$label} after payment: the same saved ticket, no credential" );
			switch_to_blog( $a['site'] ); $expected = $this->validity( $order_id, $order_id === $id ); restore_current_blog();
			$this->same( $expected, $s['validity'] ?? null, "{$label} after payment: the validity message under the QR" . ( $order_id === $id ? ' (Package with add-ons)' : ' (Hybrid Package without add-ons: no add-ons mentioned)' ) );
			if ( str_contains( $label, 'Order Received' ) ) { $n = $this->notice( $page ); $this->same( [ 0, self::RECEIVED ], [ $n['spans'] ?? null, $n['visible'] ?? null ], "{$label} after payment: WooCommerce’s own thank-you line, nothing added" ); }
		}
		$s = $this->section( $this->cgi( $a, $received, [ 'cookies' => $cookie, 'fault' => 'ticket-read' ] )['body'] );
		$this->same( [ 'unavailable', null ], [ $s['wait'] ?? null, $s['reference'] ?? null ], 'paid order whose ticket read fails: neutral unavailable area, not the ticket and not “preparing”' );
		$this->same( $rows, $this->tables( $a['site'] ), 'checks and views after payment (one with a read fault) created no entitlement or ticket and changed none' );
	}

	/* ================================================================== *
	 * 6. Optional browser stage
	 * ================================================================== */

	private function browser( array $a, int $owner ): void {
		$this->group = 'Browser';
		if ( '1' !== getenv( 'BC_TICKET_WAIT_BROWSER' ) ) { echo "NOTE [Browser] browser stage skipped (BC_TICKET_WAIT_BROWSER=1 not set)\n"; return; }
		$shots = (string) getenv( 'BC_TICKET_WAIT_SHOTS' );
		if ( '' !== $shots && ( false === realpath( $shots ) || ! str_starts_with( (string) realpath( $shots ), '/private/tmp/' ) ) ) { throw new RuntimeException( 'Screenshots require an existing /private/tmp directory.' ); }
		$orders = [];
		$this->enter( $a['site'], $owner );
		foreach ( [ 'delayed', 'state', 'budget', 'storage', 'noabort', 'nojs', 'faults', 'hidden', 'focus', 'anchor', 'anchornone', 'anchorpart', 'anchorpartnone', 'unavailable', 'excluded', 'noaccount', 'tampered', 'closed', 'redirect', 'widths', 'preview', 'previewpaid' ] as $name ) {
			$orders[ $name ] = $this->order( $a, 'state' === $name ? 'hybrid' : 'digital', 'state' === $name ? 'classic' : 'store' );
		}
		$this->manager( static fn() => wc_get_order( $orders['excluded'] )->update_status( 'cancelled' ) );
		$this->pay( $orders['previewpaid'] );
		$this->leave();
		$this->enter( $a['site'], 0 );
		foreach ( [ 'guest', 'guestmanual', 'verified' ] as $name ) { $orders[ $name ] = $this->order( $a ); }
		$this->leave();
		switch_to_blog( $a['site'] );
		$config = [ 'host' => strtolower( get_site( $a['site'] )->domain ), 'site' => $a['site'], 'owner' => $this->token, 'orders' => [], 'route' => (string) wp_parse_url( $this->route_url( $a ), PHP_URL_PATH ), 'email' => $this->address['email'] ];
		foreach ( $orders as $name => $order_id ) {
			$order = wc_get_order( $order_id );
			$config['orders'][ $name ] = [ 'id' => $order_id, 'received' => $order->get_checkout_order_received_url(), 'view' => $order->get_view_order_url(), 'guest' => 0 === $order->get_customer_id() ];
		}
		$path = static fn( int $page ): string => (string) wp_parse_url( (string) get_permalink( $page ), PHP_URL_PATH );
		$config += [ 'checkout_path' => $path( wc_get_page_id( 'checkout' ) ), 'account_path' => $path( wc_get_page_id( 'myaccount' ) ) ];
		restore_current_blog();
		$config += [ 'cookie_name' => LOGGED_IN_COOKIE, 'cookie_value' => $this->cookie( $owner ), 'php_cgi' => dirname( PHP_BINARY ) . '/php-cgi', 'php' => PHP_BINARY,
			'worker' => __DIR__ . '/customer-ticket-wait-request.php', 'control' => __DIR__ . '/customer-ticket-wait-control.php', 'root' => dirname( __DIR__ ), 'shots' => $shots ];
		$env = [ 'PATH' => (string) getenv( 'PATH' ), 'HOME' => (string) getenv( 'HOME' ), 'BC_PLAYWRIGHT_MODULE' => (string) getenv( 'BC_PLAYWRIGHT_MODULE' ), 'BC_CHROME_EXECUTABLE' => (string) getenv( 'BC_CHROME_EXECUTABLE' ), 'BC_TW_CONFIG' => (string) wp_json_encode( $config ) ];
		$process = proc_open( [ (string) getenv( 'BC_NODE_EXECUTABLE' ) ?: 'node', __DIR__ . '/customer-ticket-wait-browser.cjs' ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Browser stage could not start.' ); }
		fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		$exit = proc_close( $process );
		$result = json_decode( (string) $out, true );
		$this->same( [ 0, true ], [ $exit, is_array( $result ) && ! isset( $result['error'] ) ], 'browser stage ran' . ( is_array( $result ) && isset( $result['error'] ) ? ': ' . substr( (string) $result['error'], 0, 600 ) : ( '' !== trim( (string) $err ) ? ': ' . substr( trim( (string) $err ), 0, 600 ) : '' ) ) );
		if ( ! is_array( $result ) ) { return; }
		foreach ( $result['checks'] ?? [] as $check ) {
			$this->group = 'Browser: ' . (string) ( $check['group'] ?? '' );
			$this->ok( true === ( $check['ok'] ?? false ), (string) ( $check['label'] ?? '' ) . ( empty( $check['detail'] ) ? '' : ' — ' . substr( (string) $check['detail'], 0, 400 ) ) );
		}
		$this->group = 'Browser';
		$this->same( [], $result['refused'] ?? null, 'every page and status request was served by a real php-cgi request or a stated fixture response' );
	}

	/* ================================================================== *
	 * Helpers
	 * ================================================================== */

	/** CustomerTicket::render on one display, as WooCommerce's order-details hook calls it there. */
	private function view( int $id, string $display, ?string $key, array $get = [], ?WC_Order $object = null, array $post = [] ): array {
		global $wp;
		$saved = [ $_GET, $_POST, $wp->query_vars, $GLOBALS['wp_scripts'] ?? null ];
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$writes = []; $watch = static function ( $sql ) use ( &$writes ) { if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', (string) $sql ) ) { $writes[] = (string) $sql; } return $sql; };
		$payments = did_action( 'woocommerce_payment_complete' );
		add_filter( 'query', $watch, PHP_INT_MAX );
		try {
			$_GET = $get; $_POST = $post;
			if ( null !== $key ) { $_GET['key'] = $key; }
			$wp->query_vars = 'none' === $display ? [] : [ $display => (string) $id ];
			ob_start();
			try { CustomerTicket::render( $object ?? wc_get_order( $id ) ); } finally { $html = (string) ob_get_clean(); }
			$enqueued = wp_script_is( 'bc-customer-ticket', 'enqueued' );
			$data = (string) wp_scripts()->get_data( 'bc-customer-ticket', 'data' );
		} finally {
			remove_filter( 'query', $watch, PHP_INT_MAX );
			[ $_GET, $_POST, $wp->query_vars, $GLOBALS['wp_scripts'] ] = $saved;
		}
		return [ 'html' => $html, 'section' => $this->section( $html ), 'enqueued' => $enqueued, 'strings' => $this->strings( $data ), 'writes' => $writes, 'payments' => did_action( 'woocommerce_payment_complete' ) - $payments ];
	}

	/** One status-route request through the real REST server, as the current user, with its writes and payment events. */
	private function dispatch( ?string $credential, array $query = [], ?string $raw = null ): array {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $raw ?? (string) wp_json_encode( null === $credential ? new stdClass() : [ 'credential' => $credential ] ) );
		if ( $query ) { $request->set_query_params( $query ); }
		$writes = []; $watch = static function ( $sql ) use ( &$writes ) { if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', (string) $sql ) ) { $writes[] = (string) $sql; } return $sql; };
		$payments = did_action( 'woocommerce_payment_complete' );
		add_filter( 'query', $watch, PHP_INT_MAX );
		try { $response = rest_get_server()->dispatch( $request ); } finally { remove_filter( 'query', $watch, PHP_INT_MAX ); }
		$data = $response->get_data();
		return [ 'status' => $response->get_status(), 'data' => is_array( $data ) ? $data : [], 'writes' => $writes, 'payments' => did_action( 'woocommerce_payment_complete' ) - $payments ];
	}

	/** One waiting area, exactly; and its credential gets the same state from the route for this viewer. */
	private function waiting( array $v, array $f, int $id, string $state, string $label, bool $route = true ): void {
		$s = $v['section'];
		$message = [ 'payment' => self::PAYMENT, 'ticket' => self::CONFIRMED, 'unavailable' => self::UNAVAILABLE ][ $state ];
		$this->same( [ 1, $f['site'] . ':' . $id, $state, $message, self::NOSCRIPT, $this->route_url( $f ), 0 ], [ $s['count'] ?? 0, $s['scope'] ?? null, $s['wait'] ?? null, $s['message'] ?? null, $s['noscript'] ?? null, $s['status'] ?? null, $s['canvases'] ?? null ], "{$label}: {$state} area for this Hall and order, with the status route" );
		$this->same( [ 1, get_current_user_id() > 0 ], [ preg_match( self::CREDENTIAL, (string) ( $s['credential'] ?? '' ) ), null !== ( $s['nonce'] ?? null ) ], "{$label}: a fresh credential; a REST nonce only for a signed-in viewer" );
		$this->same( false, (bool) preg_match( '/data-bc-ticket="|BC1\.[0-9]+\./', $v['html'] ), "{$label}: no ticket reference or token in the markup" );
		$this->same( [ true, self::STRINGS, [], 0 ], [ $v['enqueued'], array_keys( $v['strings'] ), $v['writes'], $v['payments'] ], "{$label}: production script and its strings enqueued; reads only" );
		if ( $route ) {
			$r = $this->dispatch( (string) ( $s['credential'] ?? '' ) );
			$this->same( [ 200, [ 'scope' => $f['site'] . ':' . $id, 'state' => $state ], [] ], [ $r['status'], $r['data'], $r['writes'] ], "{$label}: its credential gets the same state from the status route, read-only" );
		}
	}

	/** The expected validity sentence for an order with a legacy (untimed) fixture Session, from its saved facts. */
	private function validity( int $id, bool $addons ): string {
		$facts = wc_get_order( $id )->get_meta( 'bc_purchase', true );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $facts['session_date'] )->format( 'm/d/Y' );
		return 'Valid for one Session only. This QR covers only the digital part of your purchase: one Package (' . $facts['package_name'] . ')' . ( $addons ? ' and its add-ons' : '' )
			. ' for the Bingo Session “' . $facts['session_name'] . '” on ' . $date . '. It can’t be used for any other Session, date or Package.';
	}

	private function route_url( array $f ): string {
		switch_to_blog( $f['site'] );
		try { return rest_url( 'bingocommerce-digital-tickets/v1/ticket-status' ); } finally { restore_current_blog(); }
	}

	/** received_notice() as WooCommerce's thank-you template calls it, for one order and key; $keep leaves its read for render(). */
	private function noticed( int $id, ?string $key, bool $keep = false ): string {
		$saved = $_GET; $_GET = null === $key ? [] : [ 'key' => $key ];
		try { return (string) CustomerTicket::received_notice( self::RECEIVED, wc_get_order( $id ) ); }
		finally { $_GET = $saved; if ( ! $keep ) { ( new ReflectionProperty( CustomerTicket::class, 'noticed' ) )->setValue( null, [] ); } }
	}

	/**
	 * Order Received's thank-you line: how many there are, the text a customer sees (hidden parts left out) and,
	 * when the plugin supplied its two switchable lines, each one's text, hidden state and Hall:order.
	 */
	private function notice( string $html ): ?array {
		$dom = new DOMDocument(); libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$lines = $xpath->query( '//p[contains(concat(" ", normalize-space(@class), " "), " woocommerce-thankyou-order-received ")]' );
		if ( 0 === $lines->length ) { return null; }
		$p = $lines->item( 0 ); $spans = $xpath->query( './/span[@data-bc-ticket-notice]', $p );
		$visible = clone $p;
		foreach ( iterator_to_array( $xpath->query( './/*[@hidden]', $visible ) ) as $hidden ) { $hidden->parentNode->removeChild( $hidden ); }
		$result = [ 'count' => $lines->length, 'spans' => $spans->length, 'visible' => trim( $visible->textContent ) ];
		foreach ( $spans as $span ) {
			$name = 'payment' === $span->getAttribute( 'data-bc-ticket-notice' ) ? 'confirming' : 'received';
			$result[ $name ] = trim( $span->textContent ); $result[ $name . '_hidden' ] = $span->hasAttribute( 'hidden' ); $result[ $name . '_for' ] = $span->getAttribute( 'data-bc-ticket-for' );
		}
		return $result;
	}

	/** The filter's output, parsed as it sits inside WooCommerce's thank-you paragraph. */
	private function line( string $filtered ): ?array {
		return $this->notice( '<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">' . $filtered . '</p>' );
	}

	/** Both switchable lines for this Hall and order, with 'payment' (confirming) or 'received' (WooCommerce's) visible. */
	private function lines( array $f, int $id, string $visible ): array {
		$scope = $f['site'] . ':' . $id; $confirming = 'payment' === $visible;
		return [ 'count' => 1, 'spans' => 2, 'visible' => $confirming ? self::CONFIRMING : self::RECEIVED,
			'confirming' => self::CONFIRMING, 'confirming_hidden' => ! $confirming, 'confirming_for' => $scope,
			'received' => self::RECEIVED, 'received_hidden' => $confirming, 'received_for' => $scope ];
	}

	private function section( string $html ): array {
		if ( '' === trim( $html ) ) { return [ 'count' => 0 ]; }
		$dom = new DOMDocument(); libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$sections = $xpath->query( '//section[contains(concat(" ", normalize-space(@class), " "), " bc-digital-ticket ")]' );
		if ( 1 !== $sections->length ) { return [ 'count' => $sections->length ]; }
		$s = $sections->item( 0 ); $canvases = $xpath->query( './/canvas[@data-bc-ticket]', $s );
		$message = $xpath->query( './/*[@data-bc-ticket-message]', $s ); $noscript = $xpath->query( './/noscript', $s );
		$attribute = static fn( string $name ): ?string => $s->hasAttribute( $name ) ? $s->getAttribute( $name ) : null;
		return [
			'count' => 1, 'scope' => $attribute( 'data-bc-ticket-scope' ), 'wait' => $attribute( 'data-bc-ticket-wait' ), 'status' => $attribute( 'data-bc-ticket-status' ),
			'credential' => $attribute( 'data-bc-ticket-credential' ), 'nonce' => $attribute( 'data-bc-ticket-nonce' ),
			'message' => $message->length ? trim( $message->item( 0 )->textContent ) : null, 'noscript' => $noscript->length ? trim( $noscript->item( 0 )->textContent ) : null,
			'canvases' => $canvases->length, 'validity' => ( $v = $xpath->query( './/*[@data-bc-ticket-validity]', $s ) )->length ? trim( preg_replace( '/\s+/u', ' ', $v->item( 0 )->textContent ) ) : null, 'reference' => $canvases->length ? $canvases->item( 0 )->getAttribute( 'data-bc-ticket' ) : null,
		];
	}

	/** The fields of WooCommerce's guest email-verification form on a page. */
	private function verify_fields( string $html ): array {
		$dom = new DOMDocument(); libxml_use_internal_errors( true ); $dom->loadHTML( $html, LIBXML_NONET ); libxml_clear_errors();
		$fields = [];
		foreach ( ( new DOMXPath( $dom ) )->query( '//form[contains(@class,"woocommerce-verify-email")]//input[@name]' ) as $input ) { $fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' ); }
		return $fields;
	}

	/** The localized bcCustomerTicket strings in a page or a script's data, keyed in order. */
	private function strings( string $html ): array {
		if ( ! preg_match( '/var bcCustomerTicket = (\{.*?\});/s', $html, $m ) ) { return []; }
		$strings = json_decode( $m[1], true );
		return is_array( $strings ) ? $strings : [];
	}

	private function with_fault( callable $match, callable $callback ): mixed {
		global $wpdb;
		$fault = static fn( $sql ) => $match( (string) $sql ) ? 'SELECT * FROM bc_ticket_wait_unreadable' : $sql;
		$quiet = $wpdb->suppress_errors( true ); add_filter( 'query', $fault );
		try { return $callback(); } finally { remove_filter( 'query', $fault ); $wpdb->suppress_errors( $quiet ); }
	}

	/** Native payment_complete(), standing in for the gateway webhook; optionally with one Digital Tickets insert failing. */
	private function pay( int $id, ?string $failing_table = null ): void {
		$complete = fn() => $this->same( true, wc_get_order( $id )->payment_complete( 'fixture-card-' . $id ), 'native payment_complete succeeds without payment transport' );
		if ( null === $failing_table ) { $complete(); return; }
		$this->with_fault( static fn( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO `' . $failing_table . '`' ), $complete );
	}

	private function counts( int $id ): array {
		global $wpdb;
		$entitlements = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', Migrations::table(), $id ) );
		$tickets = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i t JOIN %i e ON e.entitlement_id = t.entitlement_id WHERE e.order_id = %d', Migrations::tickets_table(), Migrations::table(), $id ) );
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id = %d AND status = 'active'", Migrations::table(), $id ) );
		return [ $entitlements, $tickets, $active ];
	}

	private function order( array $f, string $kind = 'digital', string $path = 'store', string $gateway = 'stripe' ): int {
		WC()->cart->empty_cart( false ); wc_clear_notices(); WC()->cart->cart_context = 'shortcode';
		WC()->session->set( 'store_api_draft_order', 0 ); WC()->session->set( 'order_awaiting_payment', 0 );
		WC()->cart->add_to_cart( $f['merch'], 1 );
		if ( 'merch' !== $kind ) {
			$addons = 'digital' === $kind ? [ [ 'package_addon_id' => $f['addon'], 'quantity' => 1 ] ] : [];
			$result = CustomerCart::add( [ 'session_id' => $f['session'], 'package_id' => $f['packages'][ $kind ], 'addons' => $addons ] );
			if ( ! is_string( $result ) ) { throw new RuntimeException( 'Fixture selection failed: ' . wp_json_encode( $result ) ); }
		}
		WC()->cart->calculate_totals();
		if ( 'store' === $path ) {
			$route = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\RoutesController::class )->get( 'checkout' );
			register_rest_route( 'wc/store/v1', '/checkout', $route->get_args(), true );
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
			$request->set_body_params( [ 'billing_address' => $this->address, 'payment_method' => $gateway ] ); $request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
			$response = rest_get_server()->dispatch( $request );
			if ( 200 !== $response->get_status() ) { throw new RuntimeException( 'Store checkout failed: ' . wp_json_encode( $response->get_data() ) ); }
			$id = (int) $response->get_data()['order_id'];
		} else {
			$data = [ 'payment_method' => $gateway ]; foreach ( $this->address as $key => $value ) { $data[ 'billing_' . $key ] = $value; }
			$id = WC()->checkout()->create_order( $data );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
			WC()->payment_gateways()->payment_gateways()[ $gateway ]->process_payment( $id );
		}
		$order = wc_get_order( $id );
		if ( ! $order || ( 'stripe' === $gateway ? 'pending' : 'on-hold' ) !== $order->get_status() || $gateway !== $order->get_payment_method() ) { throw new RuntimeException( "Fixture {$gateway} order has an unexpected state." ); }
		WC()->session->set( 'store_api_draft_order', 0 );
		WC()->cart->empty_cart( false );
		return (int) $id;
	}

	private function bingo_lines( int $id ): array {
		$lines = [];
		foreach ( wc_get_order( $id )->get_items() as $item_id => $item ) {
			if ( is_array( $item->get_meta( 'bc_purchase', true ) ) ) { $lines[ $item_id ] = [ 'qty' => $item->get_quantity(), 'refund_total' => $item->get_total(), 'refund_tax' => [] ]; }
		}
		return $lines;
	}
	private function bingo_total( int $id ): string {
		return wc_format_decimal( array_sum( array_map( static fn( $line ) => (float) $line['refund_total'], $this->bingo_lines( $id ) ) ), 2 );
	}

	/** Order facts and money, which no view or check may change. */
	private function facts( int $id ): array {
		$order = wc_get_order( $id ); $items = [];
		foreach ( $order->get_items( [ 'line_item', 'coupon', 'tax' ] ) as $key => $item ) { $items[ $key ] = $item->get_data(); }
		return [ $order->get_status(), $order->get_meta( 'bc_purchase', true ), serialize( $items ), $order->get_total(), $order->get_payment_method(), $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : null ];
	}

	/** The Hall's entitlement, ticket and marker rows. */
	private function tables( int $site ): array {
		global $wpdb;
		switch_to_blog( $site );
		try {
			$rows = [];
			foreach ( [ Migrations::table(), Migrations::tickets_table(), Migrations::voids_table() ] as $table ) {
				$rows[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A );
				if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Ticket table read failed.' ); }
			}
			return $rows;
		} finally { restore_current_blog(); }
	}

	/**
	 * A WooCommerce session cart of two merchandise items, as another tab leaves it: the signed-in user's
	 * session, or a new guest session whose real cookie (WooCommerce 11's customer|expiry|expiring|hmac) is returned.
	 */
	private function seed_cart( array $f, int $user ): array {
		$this->enter( $f['site'], $user );
		try {
			WC()->cart->empty_cart( false );
			if ( ! WC()->cart->add_to_cart( $f['merch'], 2 ) ) { throw new RuntimeException( 'Cart fixture failed.' ); }
			WC()->session->set( 'cart', ( new WC_Cart_Session( WC()->cart ) )->get_cart_for_session() );
			$handler = WC()->session; $cookie = [];
			if ( 0 === $user ) {
				$read = static fn( string $name ) => ( new ReflectionProperty( $handler, $name ) )->getValue( $handler );
				( new ReflectionProperty( $handler, '_has_cookie' ) )->setValue( $handler, true );
				$guest = $handler->get_customer_id(); $expiration = (string) $read( '_session_expiration' );
				$cookie = [ 'guest' => $guest, 'name' => 'wp_woocommerce_session_' . COOKIEHASH, 'value' => $guest . '|' . $expiration . '|' . (string) $read( '_session_expiring' ) . '|' . hash_hmac( 'md5', $guest . '|' . $expiration, wp_hash( $guest . '|' . $expiration ) ) ];
			}
			$handler->save_data();
			remove_action( 'shutdown', [ $handler, 'save_data' ], 20 );
			WC()->session = null; WC()->cart = null; WC()->customer = null;
			return $cookie;
		} finally { $this->leave(); }
	}

	/** A session row as stored: cart quantity, a hash of the whole raw value and its expiry. */
	private function cart_row( array $f, string $key ): array {
		global $wpdb;
		switch_to_blog( $f['site'] );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT session_value, session_expiry FROM %i WHERE session_key = %s', $wpdb->prefix . 'woocommerce_sessions', $key ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Session read failed.' ); }
		} finally { restore_current_blog(); }
		$session = is_array( $row ) ? (array) maybe_unserialize( $row['session_value'] ) : [];
		$cart = isset( $session['cart'] ) ? (array) maybe_unserialize( $session['cart'] ) : [];
		return [ 'quantity' => array_sum( array_map( static fn( $line ) => (int) ( $line['quantity'] ?? 0 ), $cart ) ), 'hash' => is_array( $row ) ? hash( 'sha256', (string) $row['session_value'] ) : '', 'expiry' => is_array( $row ) ? (int) $row['session_expiry'] : 0 ];
	}

	/** One real php-cgi request for an owned Hall's page or status route. */
	private function cgi( array $f, string $url, array $o = [] ): array {
		$parts = wp_parse_url( $url ); $site = get_site( $f['site'] );
		if ( ! $parts || strtolower( $parts['host'] ?? '' ) !== strtolower( $site->domain ) ) { throw new RuntimeException( 'Request URL is not the owned fixture Hall.' ); }
		switch_to_blog( $f['site'] );
		$path = static fn( int $page ): string => (string) wp_parse_url( (string) get_permalink( $page ), PHP_URL_PATH );
		$checkout = $path( wc_get_page_id( 'checkout' ) ); $account = $path( wc_get_page_id( 'myaccount' ) );
		restore_current_blog();
		$worker = __DIR__ . '/customer-ticket-wait-request.php'; $php_cgi = dirname( PHP_BINARY ) . '/php-cgi';
		if ( ! is_executable( $php_cgi ) ) { throw new RuntimeException( 'php-cgi is required.' ); }
		$body = (string) ( $o['body'] ?? '' );
		$observe = '';
		if ( ! empty( $o['observe'] ) ) {
			if ( '' === $this->observe ) {
				$this->observe = '/private/tmp/bc-ticket-wait-observe.' . strtolower( wp_generate_password( 10, false ) );
				if ( ! mkdir( $this->observe, 0700 ) ) { throw new RuntimeException( 'Observation directory failed.' ); }
			}
			$observe = $this->observe . '/' . ( ++$this->observed ) . '.json';
		}
		$env = [ 'PATH' => (string) getenv( 'PATH' ), 'BC_TEST_DB' => 'bingocommerce_state_recon', 'BC_FIXTURE_CGI' => '1', 'BC_FIXTURE_WOOCOMMERCE' => '1', 'BC_FIXTURE_NETWORK_ACTIVE' => '1',
			'BC_TW_SITE' => (string) $f['site'], 'BC_TW_OWNER' => $this->token, 'BC_TW_CHECKOUT_PATH' => $checkout, 'BC_TW_ACCOUNT_PATH' => $account, 'BC_TW_FAULT' => (string) ( $o['fault'] ?? '' ),
			'BC_TW_NO_ACCOUNT' => empty( $o['no_account'] ) ? '' : '1', 'BC_TW_OBSERVE' => $observe,
			'HTTP_HOST' => $parts['host'], 'SERVER_NAME' => $parts['host'], 'SERVER_PORT' => '80', 'REQUEST_METHOD' => (string) ( $o['method'] ?? 'GET' ), 'REQUEST_URI' => ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
			'QUERY_STRING' => $parts['query'] ?? '', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'SCRIPT_FILENAME' => $worker, 'REDIRECT_STATUS' => '1',
			'CONTENT_TYPE' => (string) ( $o['type'] ?? '' ), 'CONTENT_LENGTH' => (string) strlen( $body ) ];
		$cookies = [];
		foreach ( (array) ( $o['cookies'] ?? [] ) as $name => $value ) { $cookies[] = $name . '=' . rawurlencode( (string) $value ); }
		if ( $cookies ) { $env['HTTP_COOKIE'] = implode( '; ', $cookies ); }
		if ( '' !== (string) ( $o['nonce'] ?? '' ) ) { $env['HTTP_X_WP_NONCE'] = (string) $o['nonce']; }
		$process = proc_open( [ $php_cgi, $worker ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not launch CGI fixture.' ); }
		fwrite( $pipes[0], $body ); fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'CGI fixture failed: ' . substr( (string) $err, 0, 400 ) ); }
		[ $head, $html ] = array_pad( preg_split( '/\r?\n\r?\n/', (string) $out, 2 ), 2, '' );
		$headers = [];
		foreach ( preg_split( '/\r?\n/', (string) $head ) as $line ) { if ( str_contains( $line, ':' ) ) { [ $name, $value ] = explode( ':', $line, 2 ); $headers[ strtolower( trim( $name ) ) ] = trim( $value ); } }
		$observed = null;
		if ( '' !== $observe ) { $observed = is_file( $observe ) ? json_decode( (string) file_get_contents( $observe ), true ) : null; @unlink( $observe ); }
		return [ 'status' => isset( $headers['status'] ) ? (int) substr( $headers['status'], 0, 3 ) : 200, 'headers' => $headers, 'body' => $html, 'observed' => $observed ];
	}

	private function cookie( int $user ): string {
		$expires = time() + HOUR_IN_SECONDS;
		return wp_generate_auth_cookie( $user, $expires, 'logged_in', WP_Session_Tokens::get_instance( $user )->create( $expires ) );
	}

	private function enter( int $site, int $user ): void {
		$this->leave();
		switch_to_blog( $site ); wp_set_current_user( $user );
		WC()->session = null; WC()->cart = null; WC()->customer = null;
		WC()->initialize_session(); WC()->initialize_cart();
		WC()->customer->set_props( array_combine( array_map( static fn( $k ) => 'billing_' . $k, array_keys( $this->address ) ), $this->address ) );
	}

	private function leave(): void {
		if ( WC()->session ) { WC()->session->destroy_session(); remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 ); }
		while ( ms_is_switched() ) { restore_current_blog(); }
		wp_set_current_user( 0 );
	}

	private function manager( callable $callback ): mixed {
		$previous = get_current_user_id(); wp_set_current_user( $this->admin );
		try { return $callback(); } finally { wp_set_current_user( $previous ); }
	}

	private function customer( string $label, array $sites ): int {
		$suffix = strtolower( wp_generate_password( 8, false ) );
		switch_to_blog( $sites[0] );
		try {
			$id = wp_insert_user( [ 'user_login' => "bc_ticket_wait_{$label}_{$suffix}", 'user_pass' => wp_generate_password( 32 ), 'user_email' => "ticket-wait-{$label}-{$suffix}@example.invalid", 'role' => 'customer' ] );
			if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Customer fixture failed.' ); }
		} finally { restore_current_blog(); }
		$this->users[] = (int) $id;
		update_user_meta( (int) $id, self::OWNER, $this->token );
		foreach ( array_slice( $sites, 1 ) as $site ) { add_user_to_blog( $site, (int) $id, 'customer' ); }
		return (int) $id;
	}

	private function fixture( string $label ): array {
		wp_set_current_user( $this->admin );
		$site = wp_insert_site( [ 'domain' => 'ticket-wait-' . strtolower( $label ) . '-' . strtolower( wp_generate_password( 8, false ) ) . '.' . BC_FIXTURE_DOMAIN, 'path' => '/', 'network_id' => get_current_network_id(), 'title' => 'Disposable Ticket Wait Hall ' . $label, 'user_id' => $this->admin ] );
		if ( is_wp_error( $site ) || $site <= 4 ) { throw new RuntimeException( 'Disposable Hall creation failed.' ); }
		$this->sites[] = (int) $site;
		update_blog_option( $site, self::OWNER, $this->token );
		switch_to_blog( $site );
		try {
			WC_Install::create_tables(); WC_Install::create_roles();
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema ) { ( new $schema() )->register_tables( true ); }
			\BingoCommerce\Core\Roles::sync_current_site();
			foreach ( [ 'timezone_string' => 'America/New_York', 'woocommerce_currency' => 'USD', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_ship_to_countries' => 'disabled', 'woocommerce_calc_taxes' => 'no', 'woocommerce_custom_orders_table_enabled' => 'yes', 'woocommerce_custom_orders_table_data_sync_enabled' => 'no' ] as $option => $value ) { update_option( $option, $value ); }
			update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes', 'title' => 'Direct bank transfer' ] );
			foreach ( [ 'background_color' => '#ffffff', 'body_background_color' => '#ffffff', 'base_color' => '#555555', 'text_color' => '#222222', 'footer_text_color' => '#444444' ] as $key => $value ) { update_option( 'woocommerce_email_' . $key, $value ); }
			switch_theme( self::THEME );
			WC_Install::create_pages();
			$this->group = 'Fixture';
			$this->same( true, Migrations::verify_current_site_schema(), "Hall {$label}: Digital Tickets tables provisioned for the new Hall" );
			$audit = static fn( array $e ): int => 1;
			$games = new GameTypeService( $audit );
			$regular = (int) $games->create( 'Regular' )->game_type['game_type_id']; $winner = (int) $games->create( 'Winner' )->game_type['game_type_id'];
			$plays = new BingoPlayProductService( $audit );
			$digital_a = (int) $plays->create( $this->product( 'Digital Regular', '6.00' ), 'digital', 6, $regular )->play_product['bingo_play_product_id'];
			$digital_b = (int) $plays->create( $this->product( 'Digital Winner', '5.00' ), 'digital', 3, $winner )->play_product['bingo_play_product_id'];
			$paper = (int) $plays->create( $this->product( 'Paper sheets', '1.00' ), 'paper', 1, null )->play_product['bingo_play_product_id'];
			$packages = new PackageService( $audit ); $result = [];
			foreach ( [ 'digital' => [ [ $digital_a, 2 ], [ $digital_b, 1 ] ], 'hybrid' => [ [ $digital_a, 1 ], [ $paper, 3 ] ], 'paper' => [ [ $paper, 5 ] ] ] as $kind => $components ) {
				$result[ $kind ] = (int) $packages->create( $this->product( ucfirst( $kind ) . ' Night Pack', '20.00' ), array_map( static fn( $c ) => [ 'bingo_play_product_id' => $c[0], 'quantity' => $c[1] ], $components ) )->package['package_id'];
			}
			$addon = (int) ( new PackageAddonService( $audit ) )->create( $result['digital'], $digital_a, '4.00', 3 )->package_addon['package_addon_id'];
			$sessions = new SessionService( $audit );
			$date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 days' )->format( 'Y-m-d' );
			$session = (int) $sessions->create( $date, 'Ticket wait occurrence', false )->session['session_id'];
			foreach ( $result as $package ) { $sessions->assignPackage( $session, $package ); }
			$sessions->setAvailableForSale( $session, true );
			return [ 'site' => (int) $site, 'packages' => $result, 'addon' => $addon, 'session' => $session, 'merch' => $this->product( 'Bingo dauber, blue', '2.00' ) ];
		} finally { wp_set_current_user( 0 ); restore_current_blog(); }
	}

	private function product( string $name, string $price ): int {
		$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $p->set_virtual( true ); return (int) $p->save();
	}

	private function snapshot( int $site ): array {
		global $wpdb; $result = [];
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->get_blog_prefix( $site ) ) . '%' ) ) as $table ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Snapshot failed.' ); }
			$encoded = array_map( 'serialize', $rows ); sort( $encoded ); $result[ $table ] = hash( 'sha256', serialize( $encoded ) );
		}
		ksort( $result ); return $result;
	}

	private function cleanup(): void {
		global $wpdb;
		$this->group = 'Cleanup';
		$this->leave();
		wp_set_current_user( $this->admin );
		if ( '' !== $this->observe && str_starts_with( $this->observe, '/private/tmp/bc-ticket-wait-observe.' ) ) {
			foreach ( (array) glob( $this->observe . '/*.json' ) as $file ) { @unlink( (string) $file ); }
			@rmdir( $this->observe );
			$this->same( false, is_dir( $this->observe ), 'request-observation directory removed' );
		}
		foreach ( $this->sites as $site ) {
			if ( $site <= 4 || $this->token !== get_blog_option( $site, self::OWNER ) ) { throw new RuntimeException( 'Cleanup ownership guard refused.' ); }
			$prefix = $wpdb->get_blog_prefix( $site );
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			$wpdb->delete( \BingoCommerce\Core\Migrations::audit_log_table(), [ 'blog_id' => $site ], [ '%d' ] );
			$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $site ], [ '%d' ] );
			wp_delete_site( $site );
			foreach ( $tables as $table ) {
				if ( ! str_starts_with( $table, $prefix ) || false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) { throw new RuntimeException( 'Owned table cleanup failed.' ); }
			}
			$this->same( [ null, [] ], [ get_site( $site ), $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) ], 'owned disposable Hall and its tables removed' );
		}
		foreach ( $this->users as $user ) {
			if ( $user === $this->admin || $this->token !== get_user_meta( $user, self::OWNER, true ) ) { throw new RuntimeException( 'Customer ownership guard refused.' ); }
			wpmu_delete_user( $user ); clean_user_cache( $user );
			$this->same( [ null, '0' ], [ $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM %i WHERE ID = %d', $wpdb->users, $user ) ), (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->usermeta, $user ) ) ], 'owned disposable customer and all its metadata deleted' );
		}
		wp_set_current_user( 0 );
	}

	private function same( mixed $expected, mixed $actual, string $label ): void {
		$this->ok( $expected === $actual, $label . ( $expected === $actual ? '' : ' expected=' . wp_json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ' actual=' . wp_json_encode( $actual, JSON_UNESCAPED_UNICODE ) ) );
	}

	private function ok( bool $condition, string $label ): void {
		if ( $condition ) { ++$this->passes; } else { ++$this->failures; }
		echo ( $condition ? 'PASS' : 'FAIL' ) . ' [' . $this->group . '] ' . $label . "\n";
	}
}

( new CustomerTicketWaitTest() )->run();
