<?php
/**
 * One fixture-state change for tests/homepage-session-purchase-browser.cjs,
 * between browser steps, so a page that has already rendered meets changed
 * server state:
 *
 *   sales <site> <session> on|off   the Session's sales, through the real guarded
 *                                   SessionService writer (as the fixture admin)
 *   limit <site> none|<n>           the network electronic-face maximum (the
 *                                   suite restores the original value itself)
 *   minicart <site> pages on|off    a Mini-Cart block appended to (or removed from)
 *                                   the Hall's own Cart and Checkout pages, so its
 *                                   Cart/Checkout fallback renders there
 *
 * BC_TEST_DB=bingocommerce_state_recon BC_HSP_OWNER=<token> \
 *   php tests/homepage-session-purchase-control.php <action> <site> <value> [<state>]
 */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) {
	fwrite( STDERR, "FIXTURE GUARD: the isolated bingocommerce_state_recon database is required.\n" );
	exit( 1 );
}
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
require __DIR__ . '/fixtures/wordpress-bootstrap.php';

[ , $bc_action, $bc_site, $bc_value, $bc_state ] = array_pad( $argv, 5, '' );
$bc_site = (int) $bc_site;
$bc_ok   = ( 'sales' === $bc_action && (int) $bc_value > 0 && in_array( $bc_state, [ 'on', 'off' ], true ) )
	|| ( 'limit' === $bc_action && ( 'none' === $bc_value || 1 === preg_match( '/\A[1-9][0-9]{0,5}\z/', $bc_value ) ) )
	|| ( 'minicart' === $bc_action && 'pages' === $bc_value && in_array( $bc_state, [ 'on', 'off' ], true ) );
if ( ! $bc_ok || $bc_site <= 4 || ! wp_is_uuid( (string) getenv( 'BC_HSP_OWNER' ), 4 )
	|| (string) getenv( 'BC_HSP_OWNER' ) !== (string) get_blog_option( $bc_site, 'bc_session_purchase_test_owner' ) ) {
	fwrite( STDERR, "Purchase control refused.\n" );
	exit( 1 );
}

// Acting as the network administrator would let WooCommerce save that user's main-site session at
// shutdown; this helper changes Session or limit state only, so nothing else is persisted.
if ( function_exists( 'WC' ) && WC()->session ) {
	remove_action( 'shutdown', [ WC()->session, 'save_data' ], 20 );
}
if ( 'limit' === $bc_action ) {
	$bc_option = \BingoCommerce\Catalog\ElectronicLimit::PLATFORM_OPTION;
	$bc_done   = 'none' === $bc_value ? delete_site_option( $bc_option ) || null === get_site_option( $bc_option, null ) : update_site_option( $bc_option, (int) $bc_value ) || (string) get_site_option( $bc_option ) === $bc_value;
	if ( ! $bc_done ) {
		fwrite( STDERR, "Purchase control failed.\n" );
		exit( 1 );
	}
	echo "ok\n";
	exit( 0 );
}
if ( 'minicart' === $bc_action ) {
	// Direct content writes on the owned Hall's two pages only: no revisions, no save hooks.
	switch_to_blog( $bc_site );
	$bc_block = "\n\n<!-- wp:woocommerce/mini-cart /-->";
	$bc_done  = true;
	foreach ( [ wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ) ] as $bc_page ) {
		$bc_content = (string) get_post_field( 'post_content', $bc_page, 'raw' );
		$bc_new     = 'on' === $bc_state ? ( str_ends_with( $bc_content, $bc_block ) ? $bc_content : $bc_content . $bc_block ) : ( str_ends_with( $bc_content, $bc_block ) ? substr( $bc_content, 0, -strlen( $bc_block ) ) : $bc_content );
		$bc_done    = $bc_done && $bc_page > 0 && false !== $wpdb->update( $wpdb->posts, [ 'post_content' => $bc_new ], [ 'ID' => $bc_page ] );
		clean_post_cache( $bc_page );
	}
	restore_current_blog();
	if ( ! $bc_done ) {
		fwrite( STDERR, "Purchase control failed.\n" );
		exit( 1 );
	}
	echo "ok\n";
	exit( 0 );
}
switch_to_blog( $bc_site );
wp_set_current_user( (int) username_exists( 'fixture_admin' ) );
$bc_result = ( new \BingoCommerce\Sessions\SessionService( static fn( array $event ): int => 1 ) )->setAvailableForSale( (int) $bc_value, 'on' === $bc_state );
restore_current_blog();
if ( ! $bc_result instanceof \BingoCommerce\Sessions\SessionResult ) {
	fwrite( STDERR, "Purchase control failed.\n" );
	exit( 1 );
}
echo "ok\n";
