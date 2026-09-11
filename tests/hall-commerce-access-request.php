<?php

/**
 * One real request against a fixture Hall, for Commerce access enforcement.
 *
 * NOT a fabricated request array. The caller describes the request entirely
 * through the environment — REQUEST_URI, PHP_SELF, QUERY_STRING, the method,
 * the POST body — and the SAPI populates `$_GET`, `$_POST` and `$_SERVER` the
 * way a web server would. WordPress then boots against the isolated fixture,
 * vars.php derives `$pagenow` from PHP_SELF for real, and the Portal
 * Integration plugin is loaded by wp-settings.php as a NETWORK-ACTIVE plugin,
 * so HallCommerceGate::enforce() runs on the genuine `plugins_loaded` hook in
 * its genuine lifecycle position.
 *
 * Invoked two ways:
 *
 *   php-cgi  SAPI `cgi-fcgi`, so enforce() does NOT take its CLI exit and real
 *            response headers are emitted to stdout ahead of the body. This is
 *            the only way the 503, Retry-After and no-store headers can be
 *            observed without a browser.
 *   php      SAPI `cli`, where enforce() returns immediately by design. The
 *            worker then calls handle_request() itself — the same path minus
 *            that four-line SAPI guard.
 *
 * The canaries below prove what did NOT happen. They are seeded into
 * `$wp_filter` before WordPress builds its hook objects, so any firing of
 * `init`, `wp_loaded`, `wp` or `template_redirect` is reported. A refused
 * request must reach none of them — which is what makes "no cart or order
 * mutation" a structural fact rather than an inspection: every WC_Form_Handler
 * and WC_AJAX entry point hangs off those four hooks.
 *
 * Environment:
 *   BC_TEST_DB                  fixture database (required by the fixture)
 *   BC_FIXTURE_CGI=1            required to boot under php-cgi
 *   BC_FIXTURE_NETWORK_ACTIVE=1 load the plugin as a network plugin
 *   BC_REQUEST_USER             user_login to authenticate as, or empty
 *   BC_REQUEST_CONSTANTS        comma-separated: DOING_AJAX, WP_ADMIN, ...
 *   BC_REQUEST_NONCE_ACTION     mint a nonce for this action
 *   BC_REQUEST_NONCE_PARAM      request parameter to mint it into
 *   BC_FIXTURE_WOOCOMMERCE=1    network-activate the installed WooCommerce
 *   BC_REQUEST_DISPATCH         '' | 'wp' (WP::main) | 'ajax' (admin-ajax)
 *   BC_REQUEST_NONCE_USER       mint the nonce in this user's session instead
 *   BC_REQUEST_SEED_ORDER=1     create pending WooCommerce orders and report them
 *   BC_REQUEST_SEED_COUNT       how many (default 1)
 *   BC_PAYPAL_REPLY             body to answer PayPal's IPN verification with
 *   BC_REQUEST_SEED_ACTIONS=1   enqueue the fixture-only canary + control actions
 *   BC_REQUEST_CANARY           token those actions carry
 *   BC_REQUEST_STALL            fixture-only: ignore SIGTERM and stall N seconds
 */

/**
 * Write to standard error.
 *
 * STDERR is a CLI-only constant; under the CGI SAPI it is not defined at all,
 * so the stream is opened explicitly and this works on both.
 */
function bc_request_stderr( string $message ): void {
	$handle = fopen( 'php://stderr', 'w' );
	if ( is_resource( $handle ) ) {
		fwrite( $handle, $message );
		fclose( $handle );
	}
}

if ( 'cli' !== PHP_SAPI && 'cgi-fcgi' !== PHP_SAPI ) {
	bc_request_stderr( "This worker is command-line only.\n" );
	exit( 1 );
}

/** Local helpers: WordPress may not be loaded when the shutdown handler runs. */
function bc_request_plain( string $html ): string {
	// strip_tags() removes the tags but keeps what is inside <style>, <script>
	// and <title>, so those blocks go first and the visible text is what remains.
	$html = (string) preg_replace( '#<(style|script|title)\b[^>]*>.*?</\1>#is', '', $html );

	return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $html ) ) );
}

/** @param mixed $value */
function bc_request_json( $value ): string {
	return (string) json_encode( $value );
}

/*
 * Request-shaping constants, defined BEFORE WordPress boots because that is
 * where the real entry points define them: wp-admin/admin-ajax.php sets
 * DOING_AJAX and WP_ADMIN, wp-admin/admin.php sets WP_ADMIN, wp-cron.php sets
 * DOING_CRON and xmlrpc.php sets XMLRPC_REQUEST — all ahead of wp-load.php.
 */
$bc_allowed_constants = [ 'DOING_AJAX', 'DOING_CRON', 'WP_ADMIN', 'XMLRPC_REQUEST' ];
foreach ( array_filter( array_map( 'trim', explode( ',', (string) getenv( 'BC_REQUEST_CONSTANTS' ) ) ) ) as $bc_constant ) {
	if ( in_array( $bc_constant, $bc_allowed_constants, true ) && ! defined( $bc_constant ) ) {
		define( $bc_constant, true );
	}
}
unset( $bc_constant, $bc_allowed_constants );

/*
 * "WooCommerce is installed" without booting WooCommerce.
 *
 * The gate's test is literally `defined( 'WC_PLUGIN_FILE' )`, which
 * woocommerce.php defines at include time. Loading WooCommerce itself into the
 * fixture would require its full table set and its installer, and would write
 * far more to the fixture than this suite is willing to own — so the constant
 * is defined to the real plugin file instead, which is exactly the fact the
 * gate consults.
 */
if ( '1' === (string) getenv( 'BC_REQUEST_WOOCOMMERCE' ) && ! defined( 'WC_PLUGIN_FILE' ) ) {
	define( 'WC_PLUGIN_FILE', dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/woocommerce.php' );
}

/*
 * FIXTURE-ONLY FORCED STALL, for the parent's escalation negative control.
 *
 * Installed before WordPress boots and before any output, so the parent is
 * exercising its own termination path rather than anything WordPress does.
 * SIGTERM is ignored deliberately: the point is to prove that the parent
 * escalates to SIGKILL, CONFIRMS the exit, and finishes inside its deadline.
 */
$bc_stall = (int) getenv( 'BC_REQUEST_STALL' );
if ( $bc_stall > 0 ) {
	if ( function_exists( 'pcntl_signal' ) ) {
		pcntl_signal( SIGTERM, SIG_IGN );
		pcntl_signal( SIGINT, SIG_IGN );
	}
	// Standard error, not stdout: the CGI SAPI buffers stdout behind its header
	// block and a SIGKILLed process may never flush it. The parent needs to see
	// that the stall was genuinely reached, so the marker must survive the kill.
	bc_request_stderr( "BC-STALLING\n" );
	$bc_until = microtime( true ) + $bc_stall;
	while ( microtime( true ) < $bc_until ) {
		usleep( 50000 );
	}
	exit( 0 );
}

/** Reported back to the parent by the shutdown handler. */
$GLOBALS['bc_request_report'] = [
	'sapi'            => PHP_SAPI,
	'reached'         => [],
	'outcome'         => 'continued',
	'blog_id'         => 0,
	'user'            => 0,
	'pagenow'         => '',
	'gate_registered' => 'false',
	'identity_set'    => false,
	'http_attempts'   => 0,
	'http_urls'       => [],
	'handlers'        => [],
	'paypal_verification' => false,
];

$GLOBALS['wp_filter'] = $GLOBALS['wp_filter'] ?? [];

/*
 * OUTBOUND HTTP, COUNTED IN THIS PROCESS.
 *
 * The parent harness cannot see what a child process attempts, so the count is
 * taken here and returned in the report. Priority 0 runs before the fixture's
 * own blanket `pre_http_request` rejection at priority 1, and passes `$preempt`
 * through untouched, so counting cannot itself permit a request.
 */
$GLOBALS['bc_http_urls'] = [];
$GLOBALS['wp_filter']['pre_http_request'][0]['bc_http_counter'] = [
	'function'      => static function ( $preempt, $args, $url ) {
		$GLOBALS['bc_http_urls'][] = (string) $url;

		return $preempt;
	},
	'accepted_args' => 3,
];

/*
 * The ONE outbound request this worker may answer: PayPal's IPN verification
 * post-back, and only when a test explicitly asks for it.
 *
 * Priority 5 is deliberately AFTER the fixture's blanket rejection, so the
 * default stays deny-everything and this can only ever replace that rejection
 * for a URL matched here. Nothing reaches PayPal or any other host.
 */
$GLOBALS['wp_filter']['pre_http_request'][5]['bc_paypal_mock'] = [
	'function'      => static function ( $preempt, $args, $url ) {
		$reply = (string) getenv( 'BC_PAYPAL_REPLY' );
		if ( '' === $reply ) {
			return $preempt;
		}

		/*
		 * EXACT match on scheme, host and path — not a substring. A substring
		 * test would also answer `https://evil.example/paypal.com/cgi-bin/webscr`
		 * and `https://paypal.com.attacker.example/cgi-bin/webscr`. The one URL
		 * answered here is the live endpoint the installed handler builds when
		 * `testmode` is off, which is how this fixture configures the gateway.
		 */
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts )
			|| 'https' !== ( $parts['scheme'] ?? '' )
			|| 'www.paypal.com' !== ( $parts['host'] ?? '' )
			|| '/cgi-bin/webscr' !== ( $parts['path'] ?? '' )
			|| isset( $parts['port'] )
			|| isset( $parts['user'] ) ) {
			return $preempt;
		}

		$GLOBALS['bc_request_report']['paypal_verification'] = true;

		return [
			'headers'  => [],
			'body'     => $reply,
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	'accepted_args' => 3,
];

/*
 * Canaries, installed before wp-settings.php converts $wp_filter through
 * WP_Hook::build_preinitialized_hooks().
 */
foreach ( [ 'init', 'wp_loaded', 'wp', 'template_redirect' ] as $bc_hook ) {
	$GLOBALS['wp_filter'][ $bc_hook ][ PHP_INT_MIN ][ 'bc_canary_' . $bc_hook ] = [
		'function'      => static function () use ( $bc_hook ) {
			$GLOBALS['bc_request_report']['reached'][] = $bc_hook;
		},
		'accepted_args' => 1,
	];
}
unset( $bc_hook );

/*
 * Identity, established on `plugins_loaded` AHEAD of the gate.
 *
 * Seeded here rather than set after boot because under php-cgi the gate has
 * already decided by the time this file regains control. Pre-initialised hooks
 * are registered before any plugin loads, so at the shared PHP_INT_MIN priority
 * this callback is inserted first and therefore runs first. pluggable.php is
 * loaded immediately before `plugins_loaded`, so the current user can be set
 * and a nonce minted in exactly the user context the gate will verify it in.
 */
$GLOBALS['wp_filter']['plugins_loaded'][ PHP_INT_MIN ]['bc_request_identity'] = [
	'function'      => static function (): void {
		/*
		 * Recorded HERE, not after the boot: a refused request never returns
		 * from `plugins_loaded`, so anything captured later would be missing
		 * from exactly the cases that matter most. By this point wp-settings.php
		 * has already included the network-active plugin, so Plugin::boot() has
		 * run and the registration is observable.
		 */
		$GLOBALS['bc_request_report']['gate_registered'] = var_export(
			has_action( 'plugins_loaded', [ 'BingoCommerce\\PortalIntegration\\HallCommerceGate', 'enforce' ] ),
			true
		);

		$login = trim( (string) getenv( 'BC_REQUEST_USER' ) );
		if ( '' !== $login ) {
			$user = get_user_by( 'login', $login );
			if ( ! $user instanceof WP_User ) {
				$GLOBALS['bc_request_report']['outcome'] = 'user_missing';

				return;
			}
			wp_set_current_user( $user->ID );
			$GLOBALS['bc_request_report']['user'] = (int) $user->ID;
		}

		$action = trim( (string) getenv( 'BC_REQUEST_NONCE_ACTION' ) );
		$param  = trim( (string) getenv( 'BC_REQUEST_NONCE_PARAM' ) );
		if ( '' !== $action && '' !== $param ) {
			/*
			 * Optionally minted in ANOTHER session. Nonces are user-bound, so
			 * this produces a well-formed nonce for the right action that still
			 * must not verify for the requester.
			 */
			$mint_as = trim( (string) getenv( 'BC_REQUEST_NONCE_USER' ) );
			$restore = get_current_user_id();
			if ( '' !== $mint_as ) {
				$other = get_user_by( 'login', $mint_as );
				if ( ! $other instanceof WP_User ) {
					$GLOBALS['bc_request_report']['outcome'] = 'nonce_user_missing';

					return;
				}
				wp_set_current_user( $other->ID );
			}

			$_GET[ $param ] = wp_create_nonce( $action );

			if ( '' !== $mint_as ) {
				wp_set_current_user( $restore );
				$GLOBALS['bc_request_report']['nonce_minted_as'] = $mint_as;
			}
			$GLOBALS['bc_request_report']['nonce_minted_for'] = $action;
		}

		$GLOBALS['bc_request_report']['identity_set'] = true;
	},
	'accepted_args' => 1,
];

/*
 * The report is printed from a shutdown function, because a refusal ends the
 * request with exit() and must still be observable. Output buffering captures
 * the refusal body so it can be reported alongside it.
 */
ob_start();
register_shutdown_function(
	static function (): void {
		$body   = (string) ob_get_contents();
		$report = $GLOBALS['bc_request_report'];

		$report['body_bytes']   = strlen( $body );
		$report['body_has_msg'] = false !== strpos(
			$body,
			'This store is temporarily unavailable. Please contact the Hall administrator.'
		);
		$report['refused']       = $report['body_has_msg'];
		$report['body_sample']   = substr( bc_request_plain( $body ), 0, 160 );
		$report['http_attempts'] = count( (array) ( $GLOBALS['bc_http_urls'] ?? [] ) );
		$report['http_urls']     = array_values( array_unique( (array) ( $GLOBALS['bc_http_urls'] ?? [] ) ) );

		ob_end_clean();
		echo "\n--BC-REPORT--" . bc_request_json( $report ) . "--END--\n";
	}
);

require __DIR__ . '/fixtures/isolated-multisite.php';

/*
 * From here WordPress has fully booted. Under php-cgi, reaching this line at
 * all means the gate did not refuse.
 */
$GLOBALS['bc_request_report']['blog_id'] = get_current_blog_id();
$GLOBALS['bc_request_report']['pagenow'] = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

if ( ! class_exists( '\BingoCommerce\PortalIntegration\HallCommerceGate' ) ) {
	require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';
}

// Re-read for a request that survived, as a string: PHP_INT_MIN does not
// survive a JSON round-trip as an integer, and the exact priority is the point.
$GLOBALS['bc_request_report']['gate_registered'] = var_export(
	has_action( 'plugins_loaded', [ \BingoCommerce\PortalIntegration\HallCommerceGate::class, 'enforce' ] ),
	true
);

if ( 'cli' === PHP_SAPI ) {
	// enforce() returned without acting, by design. Drive the same path.
	$GLOBALS['bc_request_report']['outcome'] = 'ran_directly';
	\BingoCommerce\PortalIntegration\HallCommerceGate::handle_request();
}

/*
 * The gate admitted this request. What happens next is WordPress's and
 * WooCommerce's own code, driven exactly as their real entry points drive it,
 * so an "admitted" request can be shown to reach the handler it was admitted
 * FOR — not merely to have escaped the refusal.
 *
 * Probes record which installed handler ran. They only observe: nothing is
 * unhooked, replaced or short-circuited by them.
 */
/*
 * Order seeding. Runs only when asked, and only in a worker where WooCommerce
 * is loaded — orders are created through WooCommerce's own API, never by
 * hand-writing rows, so the storage layout is whatever the installed build
 * actually uses.
 */
if ( '1' === (string) getenv( 'BC_REQUEST_SEED_ORDER' ) && function_exists( 'wc_create_order' ) ) {
	$bc_orders = [];
	for ( $bc_i = 0; $bc_i < max( 1, (int) getenv( 'BC_REQUEST_SEED_COUNT' ) ); $bc_i++ ) {
		$bc_order = wc_create_order();
		if ( is_wp_error( $bc_order ) ) {
			$GLOBALS['bc_request_report']['outcome'] = 'order_seed_failed';
			break;
		}
		$bc_order->set_currency( 'USD' );
		$bc_order->set_payment_method( 'paypal' );
		$bc_order->set_billing_email( 'buyer@example.invalid' );
		$bc_order->set_total( '25.00' );
		$bc_order->set_status( 'pending' );
		$bc_order->save();
		$bc_orders[] = [ 'id' => (int) $bc_order->get_id(), 'key' => (string) $bc_order->get_order_key() ];
	}
	$GLOBALS['bc_request_report']['orders'] = $bc_orders;
	unset( $bc_i, $bc_order );
}

/*
 * The fixture-only queued action and its callback.
 *
 * The callback writes ONE dedicated, fixture-owned option in this Hall's own
 * options table, appending a run record each time it is called — so "executed
 * exactly once" is a fact about the stored value, not an inference. It touches
 * nothing else and schedules nothing.
 */
const BC_CANARY_HOOK   = 'bc_fixture_canary_action';
const BC_CONTROL_HOOK  = 'bc_fixture_control_action';
const BC_CANARY_OPTION = 'bc_fixture_canary_runs';

add_action(
	BC_CANARY_HOOK,
	static function ( $token = '' ): void {
		$runs = get_option( BC_CANARY_OPTION, [] );
		if ( ! is_array( $runs ) ) {
			$runs = [];
		}
		$runs[] = [ 'token' => (string) $token, 'at' => microtime( true ), 'pid' => getmypid() ];
		update_option( BC_CANARY_OPTION, $runs, false );
		$GLOBALS['bc_request_report']['handlers'][] = 'queued:' . (string) $token;
	},
	10,
	1
);

// Deliberately registered so an unintended execution WOULD be visible. The
// control action is scheduled far enough ahead that the runner must not claim
// it; if it ever did, this records the fact instead of hiding it.
add_action(
	BC_CONTROL_HOOK,
	static function ( $token = '' ): void {
		$GLOBALS['bc_request_report']['handlers'][] = 'control-executed:' . (string) $token;
	},
	10,
	1
);

if ( '1' === (string) getenv( 'BC_REQUEST_SEED_ACTIONS' ) && function_exists( 'as_schedule_single_action' ) ) {
	$bc_token = (string) getenv( 'BC_REQUEST_CANARY' );

	// Due already, so the very next queue run must claim it.
	$bc_due = as_schedule_single_action( time() - 60, BC_CANARY_HOOK, [ $bc_token ], 'bc-fixture' );

	// Far in the future, so no queue run may claim it: the control for "nothing
	// else ran".
	$bc_future = as_schedule_single_action( time() + 7 * DAY_IN_SECONDS, BC_CONTROL_HOOK, [ $bc_token ], 'bc-fixture' );

	$GLOBALS['bc_request_report']['actions'] = [
		'due'    => (int) $bc_due,
		'future' => (int) $bc_future,
	];
	unset( $bc_token, $bc_due, $bc_future );
}

$bc_dispatch = trim( (string) getenv( 'BC_REQUEST_DISPATCH' ) );

if ( 'ajax' === $bc_dispatch || 'wp' === $bc_dispatch ) {
	add_action(
		'woocommerce_api_wc_gateway_paypal',
		static function (): void {
			$GLOBALS['bc_request_report']['handlers'][] = 'woocommerce_api_wc_gateway_paypal';
		},
		-PHP_INT_MAX
	);
	add_action(
		'action_scheduler_run_queue',
		static function ( $context = '' ): void {
			$GLOBALS['bc_request_report']['handlers'][] = 'action_scheduler_run_queue:' . (string) $context;
		},
		-PHP_INT_MAX
	);
	add_action(
		'valid-paypal-standard-ipn-request',
		static function (): void {
			$GLOBALS['bc_request_report']['handlers'][] = 'valid-paypal-standard-ipn-request';
		},
		-PHP_INT_MAX
	);
}

if ( 'wp' === $bc_dispatch ) {
	/*
	 * WP::main() — the front controller's own call — which fires `parse_request`
	 * and therefore WooCommerce's LegacyRestApiStub, which instantiates the
	 * gateways and fires `woocommerce_api_<id>`. The installed dispatcher, not a
	 * stand-in.
	 */
	$GLOBALS['bc_request_report']['outcome'] = 'dispatched_wp';
	wp();
}

if ( 'ajax' === $bc_dispatch ) {
	/*
	 * Exactly what wp-admin/admin-ajax.php does after its bootstrap: resolve the
	 * action and fire the priv or nopriv hook. That reaches the installed
	 * WP_Async_Request::maybe_handle(), whose own check_ajax_referer() then runs
	 * for real.
	 */
	$GLOBALS['bc_request_report']['outcome'] = 'dispatched_ajax';
	$bc_action = '';
	if ( isset( $_POST['action'] ) && is_scalar( $_POST['action'] ) ) {
		$bc_action = (string) $_POST['action'];
	} elseif ( isset( $_GET['action'] ) && is_scalar( $_GET['action'] ) ) {
		$bc_action = (string) $_GET['action'];
	}
	$bc_action = sanitize_text_field( $bc_action );

	$GLOBALS['bc_request_report']['ajax_action']   = $bc_action;
	$GLOBALS['bc_request_report']['ajax_hooked']   = has_action( 'wp_ajax_' . $bc_action ) ? 'priv' : '';
	$GLOBALS['bc_request_report']['ajax_nopriv']   = has_action( 'wp_ajax_nopriv_' . $bc_action ) ? 'nopriv' : '';

	if ( '' !== $bc_action ) {
		if ( is_user_logged_in() ) {
			do_action( 'wp_ajax_' . $bc_action );
		} else {
			do_action( 'wp_ajax_nopriv_' . $bc_action );
		}
	}
}

$GLOBALS['bc_request_report']['outcome'] = 'continued';
exit( 0 );
