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
 * and the transaction transport all run for real against synthetic bytes.
 *
 * WHAT IS AND IS NOT CLAIMED ABOUT THE OPERATOR'S CONFIGURATION.
 *
 * wp-config.php executes in this process and evaluates its own definitions,
 * including the Portal ones. No claim is made that the operator's values go
 * unread or unevaluated. What holds is narrower and checkable:
 *
 *   - this worker's earlier definitions remain authoritative, because PHP keeps
 *     the FIRST definition of a constant and ignores every later one;
 *   - the operator's values therefore never become the effective runtime Portal
 *     constants, which are the only Portal configuration production code reads;
 *   - they are consequently not used to build an Authorization header or a
 *     Portal request, since PortalHttpClient derives both from PortalConfig,
 *     which reads those effective runtime constants;
 *   - every outbound HTTP attempt is short-circuited at `pre_http_request`,
 *     which WP_Http::request() consults before selecting a transport, so no
 *     header of any kind reaches a socket;
 *   - and no disclosure was observed in this worker's output, the redirect URL
 *     or the audit context: configuration is reported here as counts, a boolean
 *     and an error code, never as a value, and the parent asserts equality
 *     against its fixture values rather than displaying either one.
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
 *   PORTAL_DEFINED=<n>        how many Portal constants exist in this process
 *   PORTAL_EMPTY=<n>          how many of them are the empty string
 *   PORTAL_MATCHES_FIXTURE=<0|1>  both equal the disposable fixture values
 *   PORTAL_SUPPRESSED=<n>     Portal redefinition warnings swallowed (0..2)
 *   PORTAL_DECLINED=<n>       other diagnostics handed back to PHP untouched
 *   PORTAL_HANDLER_RESTORED=<0|1>  the handler was removed after bootstrap
 *   PORTAL_PREDICATE_OK=<0|1> the suppression rule matches only those two
 *   PORTAL_CONFIG=<code>      what PortalConfig::fromConstants() reported
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

/** The two Portal constants this worker decides, and the only ones it touches. */
const BC_WORKER_PORTAL_CONSTANTS = [
	'BINGOCOMMERCE_PORTAL_BASE_URL',
	'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN',
];

/** Disposable fixture configuration. Never an operator value. */
const BC_WORKER_FIXTURE_BASE_URL = 'http://portal-3g-fixture.invalid';
const BC_WORKER_FIXTURE_TOKEN    = 'phase-3g-fixture-token';

/*
 * BOTH SCENARIOS ARE DECIDED HERE, BEFORE WORDPRESS EXISTS.
 *
 * PHP takes the FIRST definition of a constant and ignores every later one, so
 * defining both names now settles what PortalConfig will see no matter what
 * wp-config.php goes on to say. `configure=false` defines them as empty
 * strings rather than leaving them undefined, because an undefined constant
 * cannot be arranged once wp-config.php has run — and PortalConfig rejects an
 * empty base URL, which reaches the same `not_configured` category through the
 * same production code.
 */
if ( ! empty( $config['configure'] ) ) {
	define( 'BINGOCOMMERCE_PORTAL_BASE_URL', BC_WORKER_FIXTURE_BASE_URL );
	define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', BC_WORKER_FIXTURE_TOKEN );
} else {
	define( 'BINGOCOMMERCE_PORTAL_BASE_URL', '' );
	define( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN', '' );
}

/**
 * Is this diagnostic the duplicate definition of one of OUR two constants?
 *
 * The whole suppression rule, in one testable place, and it takes BOTH halves
 * of the diagnostic. Severity is part of the identity of what we are willing to
 * swallow: PHP raises constant redefinition as E_WARNING, so anything arriving
 * at another severity is a different event even when the text coincides, and a
 * message-only rule would swallow a deliberately crafted E_USER_WARNING. Exact
 * string equality on the complete message, for exactly the two names above.
 */
function bc_worker_is_portal_redefinition( int $errno, string $message ): bool {
	if ( E_WARNING !== $errno ) {
		return false;
	}

	foreach ( BC_WORKER_PORTAL_CONSTANTS as $bc_name ) {
		if ( "Constant {$bc_name} already defined" === $message ) {
			return true;
		}
	}

	return false;
}

/*
 * If wp-config.php defines these names too, it will now raise one "already
 * defined" warning each — expected noise from a condition this worker created
 * on purpose. Those are counted and swallowed; NOTHING else is.
 *
 * HOW MANY there are is deliberately NOT asserted as a fixed number. It depends
 * entirely on whether the local, git-ignored wp-config.php happens to define
 * these constants, so pinning it to two would make this suite pass only on
 * machines configured like one developer's. The invariants that matter are
 * portable: what is suppressed is only ever a Portal redefinition, everything
 * else is declined, and the runtime constants are whatever this worker
 * predefined.
 */
$bc_suppressed = 0;
$bc_declined   = 0;
set_error_handler(
	static function ( int $errno, string $message ) use ( &$bc_suppressed, &$bc_declined ): bool {
		if ( bc_worker_is_portal_redefinition( $errno, $message ) ) {
			++$bc_suppressed;

			return true;
		}

		// False hands the diagnostic back to PHP's own reporting, untouched.
		++$bc_declined;

		return false;
	}
);

try {
	require dirname( __DIR__ ) . '/wp-load.php';
} finally {
	// Restored immediately, on every path, so the rest of this process reports
	// warnings and errors exactly as it would have.
	restore_error_handler();
}

/*
 * Prove the handler is really gone, rather than assuming restore_error_handler()
 * did its job: raise a diagnostic and check an independent probe sees it.
 */
$bc_probe_saw = false;
set_error_handler(
	static function ( int $errno, string $message ) use ( &$bc_probe_saw ): bool {
		$bc_probe_saw = ( 'bc-worker-handler-restoration-probe' === $message );

		return true;
	}
);
@trigger_error( 'bc-worker-handler-restoration-probe', E_USER_WARNING );
restore_error_handler();

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

/*
 * What configuration this process actually has — reported as COUNTS, a boolean
 * and an error code, never as a value. This is what makes each scenario
 * self-evidencing rather than merely asserted downstream.
 */
$portal_defined = 0;
$portal_empty   = 0;
foreach ( BC_WORKER_PORTAL_CONSTANTS as $portal_constant ) {
	if ( ! defined( $portal_constant ) ) {
		continue;
	}
	++$portal_defined;
	if ( '' === constant( $portal_constant ) ) {
		++$portal_empty;
	}
}
echo "PORTAL_DEFINED={$portal_defined}\n";
echo "PORTAL_EMPTY={$portal_empty}\n";

// Equality, not disclosure: the parent learns whether the runtime constants are
// the disposable fixture values without either value being emitted.
$portal_matches = (int) (
	defined( 'BINGOCOMMERCE_PORTAL_BASE_URL' )
	&& defined( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' )
	&& BC_WORKER_FIXTURE_BASE_URL === constant( 'BINGOCOMMERCE_PORTAL_BASE_URL' )
	&& BC_WORKER_FIXTURE_TOKEN === constant( 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' )
);
echo "PORTAL_MATCHES_FIXTURE={$portal_matches}\n";
echo "PORTAL_SUPPRESSED={$bc_suppressed}\n";
echo "PORTAL_DECLINED={$bc_declined}\n";
echo 'PORTAL_HANDLER_RESTORED=' . ( $bc_probe_saw ? '1' : '0' ) . "\n";

/*
 * The suppression rule, exercised here so the WORKER's own selectivity is
 * evidenced and not merely inherited from the parent's copy of the same rule.
 * Every message below that is not one of the two exact redefinitions must be
 * declined.
 */
$bc_base_msg  = 'Constant BINGOCOMMERCE_PORTAL_BASE_URL already defined';
$bc_token_msg = 'Constant BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN already defined';

$portal_predicate_ok = (int) (
	// Positive: E_WARNING plus each exact approved message.
	bc_worker_is_portal_redefinition( E_WARNING, $bc_base_msg )
	&& bc_worker_is_portal_redefinition( E_WARNING, $bc_token_msg )
	// Negative on SEVERITY: the same exact messages at any other severity.
	&& ! bc_worker_is_portal_redefinition( E_USER_WARNING, $bc_base_msg )
	&& ! bc_worker_is_portal_redefinition( E_USER_WARNING, $bc_token_msg )
	&& ! bc_worker_is_portal_redefinition( E_NOTICE, $bc_base_msg )
	&& ! bc_worker_is_portal_redefinition( E_NOTICE, $bc_token_msg )
	&& ! bc_worker_is_portal_redefinition( E_DEPRECATED, $bc_base_msg )
	&& ! bc_worker_is_portal_redefinition( E_DEPRECATED, $bc_token_msg )
	// Negative on MESSAGE: right severity, wrong text.
	&& ! bc_worker_is_portal_redefinition( E_WARNING, 'Constant WP_DEBUG already defined' )
	&& ! bc_worker_is_portal_redefinition( E_WARNING, 'Constant DB_PASSWORD already defined' )
	&& ! bc_worker_is_portal_redefinition( E_WARNING, $bc_base_msg . '.' )
	&& ! bc_worker_is_portal_redefinition( E_WARNING, 'Undefined variable $BINGOCOMMERCE_PORTAL_BASE_URL' )
	&& ! bc_worker_is_portal_redefinition( E_WARNING, 'BINGOCOMMERCE_PORTAL_INTEGRATION_TOKEN' )
	&& ! bc_worker_is_portal_redefinition( E_WARNING, 'Constant  already defined' )
	&& ! bc_worker_is_portal_redefinition( E_WARNING, '' )
);
unset( $bc_base_msg, $bc_token_msg );
echo "PORTAL_PREDICATE_OK={$portal_predicate_ok}\n";
unset( $portal_predicate_ok );

$portal_config = \BingoCommerce\PortalIntegration\PortalConfig::fromConstants();
echo 'PORTAL_CONFIG=' . ( is_wp_error( $portal_config ) ? $portal_config->get_error_code() : 'configured' ) . "\n";
unset( $portal_config, $portal_constant, $portal_defined, $portal_empty, $portal_matches );

do_action( 'admin_post_' . PortalSyncAdmin::POST_ACTION );

// Reached only when the handler neither redirected nor died.
echo "NOEXIT=1\n";
exit( 0 );
