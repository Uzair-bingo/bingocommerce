<?php

/**
 * Runs ONE real Phase 3G admin-post invocation in its own process.
 *
 * A subprocess is required rather than an in-process call: the production
 * handler ends in exit() after wp_safe_redirect(), and every denial path ends
 * in wp_die(). Both terminate the request, which is exactly the behaviour under
 * test, so it cannot be exercised inside the parent harness.
 *
 * The Portal is never contacted. `pre_http_request` is short-circuited with a
 * fixture, so PortalHttpClient, PortalSnapshotValidator, the application plan
 * and the transaction transport all run for real against synthetic bytes. The
 * Portal constants defined here are throwaway test values and are never the
 * operator's credential.
 *
 * Invoked only by tests/portal-sync-admin.php.
 * Args: <base64 json config>
 *
 * Prints machine-readable lines and exits:
 *   FETCHES=<n>          Portal snapshot requests that reached the boundary
 *   REDIRECT=<url>       the PRG target, when one was issued
 *   STATUS=<code>        the HTTP status passed to wp_safe_redirect()
 *   WPDIE=<text>         the wp_die message, when the request was refused
 *   HANDLER_REGISTERED=<0|1>  whether the real admin-post hook is attached
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This worker is CLI-only.\n" );
	exit( 1 );
}

$config = json_decode( base64_decode( $argv[1] ?? '', true ), true );
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "Worker configuration is unreadable.\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = 'bingocommerce.test';
$_SERVER['REQUEST_URI']    = '/wp-admin/admin-post.php';
$_SERVER['REQUEST_METHOD'] = (string) ( $config['method'] ?? 'POST' );
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

// Throwaway fixture configuration. Defined BEFORE wp-load so PortalConfig sees
// exactly what this scenario intends. `configure=false` proves the
// not-configured path without touching any real credential.
if ( ! empty( $config['configure'] ) ) {
	define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'http://portal-3g-fixture.invalid' );
	define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', 'phase-3g-fixture-token' );
}

require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

if ( ! class_exists( '\BingoCommerce\PortalIntegration\PortalSyncAdmin' ) ) {
	require dirname( __DIR__ ) . '/wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}
if ( false === has_action( 'admin_post_' . \BingoCommerce\PortalIntegration\PortalSyncAdmin::POST_ACTION ) ) {
	\BingoCommerce\PortalIntegration\Plugin::init();
}

use BingoCommerce\PortalIntegration\PortalSyncAdmin;

$fetches = 0;

/* --------------------------------------------------------------------- *
 * Boundaries: HTTP, wp_die, and wp_redirect are all intercepted so the
 * worker can report what the real handler did before it terminated.
 * --------------------------------------------------------------------- */

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$fetches, $config ) {
		// Every outbound request is blocked so a test can never touch a real
		// network, but only Portal snapshot calls are COUNTED — WordPress and
		// WooCommerce make unrelated requests of their own during bootstrap,
		// and counting those would make "zero Portal fetches" meaningless.
		if ( ! is_string( $url ) || ! str_contains( $url, '/integration/v1/halls/' ) ) {
			return new WP_Error( 'phase_3g_outbound_http_blocked', 'Unrelated outbound HTTP is blocked in this worker.' );
		}

		++$fetches;
		echo "FETCHES={$fetches}\n";

		$mode = (string) ( $config['http'] ?? 'ok' );
		if ( 'wp_error' === $mode ) {
			return new WP_Error( 'http_request_failed', 'fixture transport failure' );
		}
		if ( 'status' === $mode ) {
			$code = (int) ( $config['http_status'] ?? 500 );

			return [
				'headers'  => [],
				'body'     => '',
				'response' => [ 'code' => $code, 'message' => 'Fixture' ],
				'cookies'  => [],
				'filename' => null,
			];
		}

		return [
			'headers'  => [],
			'body'     => (string) ( $config['body'] ?? '' ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message, $title = '', $args = [] ): void {
			$text = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;
			echo 'WPDIE=' . trim( wp_strip_all_tags( $text ) ) . "\n";
			exit( 0 );
		};
	}
);

add_filter(
	'wp_redirect',
	static function ( $location ) {
		echo 'REDIRECT=' . (string) $location . "\n";

		return $location;
	},
	PHP_INT_MAX
);

// wp_redirect_status carries the code wp_safe_redirect() was called with, so the
// PRG contract (303, not a default 302) is observable rather than assumed.
add_filter(
	'wp_redirect_status',
	static function ( $status ) {
		echo 'STATUS=' . (int) $status . "\n";

		return $status;
	},
	PHP_INT_MAX
);

// Force AuditLog::record() to THROW, proving an exception cannot escape the
// handler or replace a committed synchronization result. Distinct from the
// audit_fail fixture below, which makes record() RETURN a WP_Error.
if ( ! empty( $config['audit_throw'] ) ) {
	add_filter(
		'query',
		static function ( $sql ) {
			global $wpdb;
			if ( is_string( $sql ) && false !== stripos( $sql, 'INSERT INTO `' . $wpdb->base_prefix . 'audit_log`' ) ) {
				throw new \RuntimeException(
					'phase 3g fixture: SECRET-audit-explosion at bc_audit_log INSERT INTO SELECT errno 1146'
				);
			}

			return $sql;
		}
	);
}

// Force the audit write to fail, to prove the synchronization outcome is not
// rewritten. Scoped to this worker process only.
if ( ! empty( $config['audit_fail'] ) ) {
	global $wpdb;
	// The rewritten statement must genuinely fail, because AuditLog::insert()
	// only takes its failure path when $wpdb->insert() returns false. Errors are
	// suppressed so the forced failure cannot print HTML into this worker's
	// machine-readable output.
	$wpdb->suppress_errors( true );
	add_filter(
		'query',
		static function ( $sql ) {
			global $wpdb;
			if ( is_string( $sql ) && false !== stripos( $sql, 'INSERT INTO `' . $wpdb->base_prefix . 'audit_log`' ) ) {
				return 'SELECT 1 FROM `' . $wpdb->base_prefix . 'phase3g_audit_failure_fixture_missing`';
			}

			return $sql;
		}
	);
}

/* --------------------------------------------------------------------- *
 * Identity, tenancy and request payload
 * --------------------------------------------------------------------- */

$site_id = (int) ( $config['site_id'] ?? 0 );
if ( $site_id > 0 ) {
	switch_to_blog( $site_id );
}

$user_id = (int) ( $config['user_id'] ?? 0 );
wp_set_current_user( $user_id );

$_POST    = [];
$_GET     = [];
$_REQUEST = [];

$_POST['action']    = PortalSyncAdmin::POST_ACTION;
$_REQUEST['action'] = PortalSyncAdmin::POST_ACTION;

$nonce_mode = (string) ( $config['nonce'] ?? 'valid' );
if ( 'valid' === $nonce_mode ) {
	$nonce = wp_create_nonce( PortalSyncAdmin::NONCE_ACTION );
} elseif ( 'invalid' === $nonce_mode ) {
	$nonce = 'not-a-valid-nonce';
} else {
	$nonce = null;
}
if ( null !== $nonce ) {
	$_POST['_wpnonce']    = $nonce;
	$_REQUEST['_wpnonce'] = $nonce;
}

// Forged tenant identifiers. The handler must ignore every one of these.
foreach ( (array) ( $config['forged'] ?? [] ) as $key => $value ) {
	$_POST[ $key ]    = $value;
	$_GET[ $key ]     = $value;
	$_REQUEST[ $key ] = $value;
}

/* --------------------------------------------------------------------- *
 * Invoke the REAL registered handler through its real hook.
 * --------------------------------------------------------------------- */

// Registration of the real hook, NOT a count of PortalSyncService invocations.
echo 'HANDLER_REGISTERED=' . ( has_action( 'admin_post_' . PortalSyncAdmin::POST_ACTION ) ? '1' : '0' ) . "\n";

do_action( 'admin_post_' . PortalSyncAdmin::POST_ACTION );

// Reached only when the handler neither redirected nor died.
echo "NOEXIT=1\n";
exit( 0 );
