<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses every request to a Hall whose stored Commerce state blocks access.
 *
 * THE BOUNDARY IS `plugins_loaded`, AND THAT CHOICE WAS VERIFIED AGAINST THE
 * INSTALLED SOURCE, not assumed. In wp-settings.php (WordPress 7.0.3) the order
 * is: ms-settings.php resolves the site -> must-use and network plugins are
 * included -> `muplugins_loaded` -> cookie constants -> ordinary plugins are
 * included -> pluggable.php -> `plugins_loaded` -> wp_magic_quotes() -> `init`
 * -> `wp_loaded` -> parse_request -> `wp` -> `template_redirect`.
 *
 * `muplugins_loaded` is earlier but unusable: pluggable.php has not loaded, so
 * the current user cannot be resolved and Network Super Admin recovery access
 * could not be preserved, and the cookie constants that authentication depends
 * on are not defined yet. `plugins_loaded` is therefore the EARLIEST boundary
 * at which this decision can be made correctly, and it sits ahead of every
 * WooCommerce entry point that can mutate anything:
 *
 *   - WC_Form_Handler::init() registers add_to_cart_action, update_cart_action,
 *     checkout_action, process_login, process_registration,
 *     process_lost_password, process_reset_password and cancel_order on
 *     `wp_loaded` at priority 20; pay_action and the payment-method actions on
 *     `wp` at 20; save_address, save_account_details,
 *     redirect_reset_password_link and resend_set_password on
 *     `template_redirect`.
 *   - WC_AJAX::do_wc_ajax runs on `template_redirect` at 0.
 *   - The `wc-api` payment-callback dispatch
 *     (LegacyRestApiStub::parse_legacy_rest_api_request) runs on `parse_request`
 *     at 0 — which is AFTER `wp_loaded`. That ordering is why a callback request
 *     carrying `add-to-cart` would add to the cart BEFORE the gateway handler
 *     ever saw the request, and why exempt requests are screened for shopping
 *     and account parameters here, before any of it runs.
 *
 * EXEMPTIONS ARE BOUND TO WHAT WILL ACTUALLY HAPPEN, not to what a parameter
 * claims. `parse_request` only runs inside WP::main(), which only the front
 * controller calls, so an approved `wc-api` identifier on `/wp-admin/`,
 * `admin-post.php`, `admin-ajax.php` or `wp-login.php` dispatches nothing and
 * buys nothing — see dispatches_payment_callback(). The Action Scheduler runner
 * must present the same valid nonce its own handler checks — see
 * is_scheduler_runner(). Neither check replaces the handler's own
 * authentication; both run before it, so a forged request is refused instead of
 * being handed to it.
 *
 * WordPress has not yet rebuilt and normalised `$_REQUEST` at this point:
 * `wp_magic_quotes()` runs after this hook and is what assigns
 * `array_merge( $_GET, $_POST )` to it and slashes the superglobals. This gate
 * therefore reads `$_GET` and `$_POST` directly, does not unslash them, and
 * mirrors the POST-over-GET precedence WordPress establishes later.
 *
 * WHAT THIS CLASS DOES NOT DO. It never writes: no order, cart, product, user,
 * role, setting, mapping, marker, state row or token grant is created, changed
 * or deleted on any path. It does not hide menus, swap templates, or rely on a
 * maintenance page. It does not enrol, suspend, provision or synchronize
 * anything, and it schedules nothing.
 *
 * CLI IS OUT OF SCOPE. This is a REQUEST boundary; a CLI process has no HTTP
 * response to refuse with, and every verification harness in tests/ boots this
 * plugin in-process. Server-local shell access is a separate trust boundary.
 */
final class HallCommerceGate {

	/** The one message a refused request displays. */
	public const MESSAGE = 'This store is temporarily unavailable. Please contact the Hall administrator.';

	/** Seconds. A suspension is an operator-paced state, not a momentary blip. */
	public const RETRY_AFTER = 900;

	/** HTTP status a refusal returns. */
	public const STATUS = 503;

	/*
	 * Decisions. EXEMPT and RECOVERY both continue the request; they are kept
	 * distinct because only EXEMPT detaches WooCommerce's incidental shopping
	 * handlers, and only RECOVERY means an authenticated Super Admin.
	 */
	public const REFUSE   = 'refuse';
	public const EXEMPT   = 'exempt';
	public const RECOVERY = 'recovery';

	/* Fixed reason categories. */
	public const REASON_BLOCKED        = 'blocked';
	public const REASON_MUTATION       = 'mutation_on_exempt_path';
	public const REASON_CRON           = 'wp_cron';
	public const REASON_SCHEDULER      = 'action_scheduler_runner';
	public const REASON_PAYMENT        = 'payment_notification';
	public const REASON_AUTHENTICATION = 'authentication_path';
	public const REASON_SUPER_ADMIN    = 'network_super_admin';

	/**
	 * The Action Scheduler async queue-runner identifier, verified in the
	 * installed source rather than invented.
	 *
	 * ActionScheduler_AsyncRequest_QueueRunner sets $prefix = 'as' and
	 * $action = 'async_request_queue_runner'; WP_Async_Request::__construct()
	 * joins them with '_' and registers wp_ajax_/wp_ajax_nopriv_ handlers for
	 * the result. Its maybe_handle() calls check_ajax_referer() with that same
	 * identifier, so the runner keeps authenticating itself — this gate only
	 * declines to refuse the path.
	 */
	public const SCHEDULER_ACTION = 'as_async_request_queue_runner';

	/**
	 * Payment-notification callbacks admitted by identifier.
	 *
	 * ONE entry, and it was read out of the installed gateway:
	 * WC_Gateway_Paypal_IPN_Handler::__construct() registers
	 * `woocommerce_api_wc_gateway_paypal`, and WC_Gateway_Paypal instantiates
	 * that handler whenever the gateway is valid for use — INDEPENDENTLY of
	 * whether `enabled` is 'yes'. That is exactly why a pending notification
	 * still arrives after the gateway has been switched off for new checkouts.
	 *
	 * Any other identifier gets nothing. This is an allowlist, not a framework:
	 * a new gateway requires a new reviewed entry here.
	 */
	public const PAYMENT_CALLBACKS = [ 'wc_gateway_paypal' ];

	/** wp-login.php actions that constitute the authentication path itself. */
	private const AUTHENTICATION_ACTIONS = [ '', 'login', 'logout' ];

	/**
	 * The only $pagenow that reaches WP::main(), and therefore `parse_request`.
	 *
	 * wp-settings.php maps both a front-end request and `/wp-admin/` itself to
	 * 'index.php', so this is necessary but NOT sufficient — is_admin() settles
	 * the difference.
	 */
	private const FRONT_CONTROLLER = 'index.php';

	/**
	 * Shopping and account MUTATION triggers, taken from the installed
	 * WooCommerce 11.0.1 source.
	 *
	 * Every name is a parameter whose presence causes WC_Form_Handler or
	 * WC_AJAX to act. Presence alone is enough to refuse: this gate does not
	 * try to decide whether the mutation would have succeeded.
	 */
	private const MUTATION_PARAMS = [
		// WC_Form_Handler::add_to_cart_action()
		'add-to-cart',
		// WC_Form_Handler::update_cart_action()
		'apply_coupon',
		'remove_coupon',
		'remove_item',
		'undo_item',
		'update_cart',
		'proceed',
		'woocommerce-cart-nonce',
		// WC_Form_Handler::checkout_action()
		'woocommerce_checkout_place_order',
		'woocommerce_checkout_update_totals',
		'woocommerce-process-checkout-nonce',
		// WC_Form_Handler::pay_action() and the payment-method actions
		'woocommerce_pay',
		'woocommerce-pay-nonce',
		'woocommerce_add_payment_method',
		'woocommerce-add-payment-method-nonce',
		// WC_Form_Handler::cancel_order() and re-ordering
		'cancel_order',
		'order_again',
		// WC_Form_Handler account flows
		'login',
		'woocommerce-login-nonce',
		'register',
		'woocommerce-register-nonce',
		'wc_reset_password',
		'woocommerce-lost-password-nonce',
		'woocommerce-reset-password-nonce',
		'woocommerce-edit-address-nonce',
		'save-account-details-nonce',
		'wc-resend-set-password',
		// WC_AJAX endpoint dispatch
		'wc-ajax',
	];

	/**
	 * WooCommerce callbacks detached on an exempt request.
	 *
	 * Hook, method and priority are copied verbatim from
	 * WC_Form_Handler::init() in the installed WooCommerce. They are removed by
	 * their string callable, which works whether or not the class has loaded.
	 *
	 * @var array<int,array{0:string,1:string,2:int}>
	 */
	private const DETACH_FORM_HANDLER = [
		[ 'wp_loaded', 'checkout_action', 20 ],
		[ 'wp_loaded', 'process_login', 20 ],
		[ 'wp_loaded', 'process_registration', 20 ],
		[ 'wp_loaded', 'process_lost_password', 20 ],
		[ 'wp_loaded', 'process_reset_password', 20 ],
		[ 'wp_loaded', 'cancel_order', 20 ],
		[ 'wp_loaded', 'update_cart_action', 20 ],
		[ 'wp_loaded', 'add_to_cart_action', 20 ],
		[ 'wp', 'pay_action', 20 ],
		[ 'wp', 'add_payment_method_action', 20 ],
		[ 'wp', 'delete_payment_method_action', 20 ],
		[ 'wp', 'set_default_payment_method_action', 20 ],
		[ 'template_redirect', 'redirect_reset_password_link', 10 ],
		[ 'template_redirect', 'resend_set_password', 10 ],
		[ 'template_redirect', 'save_address', 10 ],
		[ 'template_redirect', 'save_account_details', 10 ],
	];

	/**
	 * The enforcement callback for one request.
	 *
	 * Returns silently for every Hall that is not blocked, which is the only
	 * path an ordinary installation ever takes.
	 */
	public static function enforce(): void {
		if ( self::is_cli() ) {
			return;
		}

		self::handle_request();
	}

	/**
	 * The enforcement path itself, with no SAPI guard.
	 *
	 * Separated from enforce() for one reason: under the CLI SAPI there is no
	 * HTTP response to refuse with, but the decision, the database reads, the
	 * refusal body and the termination are all still real and must be testable.
	 * A harness drives THIS method with real superglobals against a real
	 * fixture; enforce() adds only the four-line SAPI guard above it.
	 */
	public static function handle_request(): void {
		try {
			if ( ! is_multisite() ) {
				return;
			}

			$blog_id = (int) get_current_blog_id();
			if ( $blog_id < 1 || is_main_site( $blog_id ) ) {
				return;
			}

			if ( HallCommerceAccess::BLOCK !== HallCommerceAccess::evaluate( $blog_id )['access'] ) {
				return;
			}

			$decision = self::decide( self::snapshot() );
		} catch ( \Throwable $throwable ) {
			/*
			 * Never read: its message and trace can quote SQL and stored values.
			 * A Hall is only reachable here after evaluate() already said BLOCK,
			 * so failing closed refuses a Hall that was blocked anyway.
			 */
			unset( $throwable );
			self::refuse();

			return;
		}

		if ( self::REFUSE === $decision['action'] ) {
			self::refuse();

			return;
		}

		if ( self::EXEMPT === $decision['action'] ) {
			self::detach_on_exempt_request();
		}
	}

	/**
	 * The whole policy, as a function of one request snapshot.
	 *
	 * Pure and side-effect free so it can be exercised directly for paths a
	 * rendered page never reaches. `super_admin` is a callable rather than a
	 * boolean on purpose: resolving the current user has request-wide
	 * consequences, so it happens only on the one branch that needs it, and a
	 * test can observe that an exempt or authentication request never asked.
	 *
	 * @param array<string,mixed> $request
	 * @return array{action:string,reason:string}
	 */
	public static function decide( array $request ): array {
		$exemption = self::exemption( $request );
		$is_login  = self::is_authentication_path( $request );

		if ( null !== $exemption || $is_login ) {
			/*
			 * An exempt path is exempt for its OWN purpose only. WooCommerce's
			 * shopping and account handlers run on `wp_loaded`, ahead of the
			 * `parse_request` callback dispatch, so a callback, cron or runner
			 * request carrying one of those parameters would mutate the store on
			 * the way past. Refused here, before any of it runs.
			 */
			if ( self::has_mutation_parameter( $request ) ) {
				return [ 'action' => self::REFUSE, 'reason' => self::REASON_MUTATION ];
			}

			return [
				'action' => self::EXEMPT,
				'reason' => null !== $exemption ? $exemption : self::REASON_AUTHENTICATION,
			];
		}

		if ( self::resolves_super_admin( $request ) ) {
			return [ 'action' => self::RECOVERY, 'reason' => self::REASON_SUPER_ADMIN ];
		}

		return [ 'action' => self::REFUSE, 'reason' => self::REASON_BLOCKED ];
	}

	/**
	 * Which background or callback exception, if any, this request qualifies for.
	 *
	 * @param array<string,mixed> $request
	 */
	private static function exemption( array $request ): ?string {
		// WP-Cron. wp-cron.php defines DOING_CRON before wp-load.php runs, and
		// refuses to proceed at all when $_POST is non-empty.
		if ( ! empty( $request['doing_cron'] ) ) {
			return self::REASON_CRON;
		}

		if ( self::is_scheduler_runner( $request ) ) {
			return self::REASON_SCHEDULER;
		}

		if ( self::dispatches_payment_callback( $request ) ) {
			return self::REASON_PAYMENT;
		}

		return null;
	}

	/**
	 * Is this the Action Scheduler async queue runner, WITH a valid nonce?
	 *
	 * The action name alone proves nothing — anyone can type it. The runner's
	 * own handler, WP_Async_Request::maybe_handle(), calls
	 * `check_ajax_referer( 'as_async_request_queue_runner', 'nonce' )`, so the
	 * same nonce is verified here, against the same action, before the
	 * exemption is granted. The nonce is located exactly as check_ajax_referer()
	 * locates it: the `nonce` parameter first, then `_ajax_nonce`, then
	 * `_wpnonce`.
	 *
	 * The USER CONTEXT matches too, and that is not incidental:
	 * WP_Async_Request::get_post_args() forwards `$_COOKIE` with the dispatch,
	 * so the runner request arrives carrying the dispatching user's session and
	 * resolves to the same user here as it will inside the handler. A nonce
	 * minted for a different action, for a different user, or not at all,
	 * therefore fails here exactly as it would fail there — except that here it
	 * fails into the maintenance refusal, so no queued action runs at all.
	 *
	 * @param array<string,mixed> $request
	 */
	private static function is_scheduler_runner( array $request ): bool {
		if ( empty( $request['doing_ajax'] ) ) {
			return false;
		}
		if ( self::SCHEDULER_ACTION !== (string) ( $request['action'] ?? '' ) ) {
			return false;
		}

		$nonce = (string) ( $request['nonce'] ?? '' );
		if ( '' === $nonce ) {
			return false;
		}

		$verifier = $request['nonce_verifier'] ?? null;

		return is_callable( $verifier ) && (bool) $verifier( $nonce, self::SCHEDULER_ACTION );
	}

	/**
	 * Will this request ACTUALLY dispatch the approved payment callback?
	 *
	 * The presence of an approved `wc-api` identifier is not the question — the
	 * question is whether WooCommerce will reach
	 * LegacyRestApiStub::maybe_process_wc_api_query_var() and fire
	 * `woocommerce_api_wc_gateway_paypal`. That dispatch hangs off
	 * `parse_request`, which ONLY runs inside WP::main(), which only the front
	 * controller calls. So `/wp-admin/?wc-api=wc_gateway_paypal`,
	 * `admin-post.php`, `admin-ajax.php` and `wp-login.php` never dispatch it,
	 * no matter what identifier they carry, and none of them may buy an
	 * exemption with one.
	 *
	 * Every condition below is a way the request would NOT dispatch, or would
	 * dispatch something else:
	 *
	 *   - is_admin() is true for /wp-admin/*, admin-ajax.php and admin-post.php,
	 *     all of which define WP_ADMIN before wp-load.php and none of which call
	 *     WP::main(). ($pagenow alone is not enough: wp-settings maps /wp-admin/
	 *     to 'index.php' too.)
	 *   - $pagenow must be the front controller. wp-login.php, wp-signup.php,
	 *     wp-activate.php, xmlrpc.php, wp-cron.php and wp-trackback.php all
	 *     bootstrap WordPress without ever calling WP::main().
	 *   - an AJAX, cron or XML-RPC request is a different lifecycle entirely.
	 *   - a REST route is a competing routing instruction. WooCommerce would in
	 *     fact win that race — the stub sits on `parse_request` at 0 and
	 *     rest_api_loaded at 10 — but a notification never arrives addressed to
	 *     two routers, so this refuses rather than picks.
	 *   - the path form only routes through the `wc-api` rewrite endpoint, which
	 *     does not exist without a permalink structure.
	 *   - the identifier must be EXACTLY one approved callback, computed the way
	 *     WooCommerce computes it, with query and path forms agreeing.
	 *
	 * The gateway's own authentication is untouched: this decides only whether
	 * to refuse the request, never whether the notification is genuine.
	 * WC_Gateway_Paypal_IPN_Handler still posts the payload back to PayPal for
	 * validation, and still runs for a gateway disabled for new purchases.
	 *
	 * @param array<string,mixed> $request
	 */
	private static function dispatches_payment_callback( array $request ): bool {
		if ( empty( $request['woocommerce'] ) ) {
			return false;
		}

		/*
		 * A NOTIFICATION CARRIES A BODY. The installed handler's very first
		 * condition is `if ( ! empty( $_POST ) && $this->validate_ipn() )`, and
		 * anything else falls straight through to
		 * `wp_die( 'PayPal IPN Request Failure', ..., 500 )` — so a request with
		 * no POST body cannot be a notification this callback can act on, and
		 * admitting one would only run WooCommerce's whole stack on a blocked
		 * Hall to reach a guaranteed failure. This mirrors the handler's own
		 * precondition rather than inventing a method check: a GET cannot
		 * populate $_POST, and an empty POST fails the handler identically.
		 */
		$post = $request['post'] ?? [];
		if ( ! is_array( $post ) || [] === $post ) {
			return false;
		}
		if ( ! empty( $request['admin'] ) || ! empty( $request['doing_ajax'] )
			|| ! empty( $request['doing_cron'] ) || ! empty( $request['xmlrpc'] ) ) {
			return false;
		}
		if ( self::FRONT_CONTROLLER !== (string) ( $request['pagenow'] ?? '' ) ) {
			return false;
		}
		if ( '' !== (string) ( $request['rest_route'] ?? '' ) || ! empty( $request['rest_path'] ) ) {
			return false;
		}

		$query = (string) ( $request['wc_api_query'] ?? '' );
		$path  = (string) ( $request['wc_api_path'] ?? '' );

		// Conflicting routing instructions are refused rather than resolved,
		// even though WooCommerce itself would let the query form win.
		if ( '' !== $query && '' !== $path && $query !== $path ) {
			return false;
		}

		// The path form cannot route without the rewrite endpoint behind it.
		if ( '' === $query && '' !== $path && empty( $request['permalinks'] ) ) {
			return false;
		}

		$effective = '' !== $query ? $query : $path;

		return '' !== $effective && in_array( $effective, self::PAYMENT_CALLBACKS, true );
	}

	/**
	 * Is this the authentication path that Network Super Admin recovery needs?
	 *
	 * Only wp-login.php, and only its login and logout actions. Password
	 * recovery, registration and the other wp-login.php actions are customer
	 * account flows and stay refused; a Super Admin recovers those on the main
	 * network site, whose login is untouched by this gate.
	 *
	 * @param array<string,mixed> $request
	 */
	private static function is_authentication_path( array $request ): bool {
		$file = (string) ( $request['pagenow'] ?? '' );
		if ( '' === $file ) {
			$file = (string) ( $request['script'] ?? '' );
		}
		if ( 'wp-login.php' !== $file ) {
			return false;
		}

		// wp-login.php reads $_REQUEST['action'] and defaults to 'login'; the
		// snapshot mirrors that precedence with POST ahead of GET.
		return in_array( strtolower( trim( (string) ( $request['action'] ?? '' ) ) ), self::AUTHENTICATION_ACTIONS, true );
	}

	/**
	 * Does this request carry a shopping or account mutation trigger?
	 *
	 * @param array<string,mixed> $request
	 */
	private static function has_mutation_parameter( array $request ): bool {
		foreach ( [ 'get', 'post' ] as $source ) {
			$params = $request[ $source ] ?? [];
			if ( ! is_array( $params ) ) {
				continue;
			}
			foreach ( self::MUTATION_PARAMS as $name ) {
				if ( array_key_exists( $name, $params ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param array<string,mixed> $request */
	private static function resolves_super_admin( array $request ): bool {
		$resolver = $request['super_admin'] ?? null;

		return is_callable( $resolver ) ? (bool) $resolver() : false;
	}

	/**
	 * Capture everything the policy needs from the live request.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		$get  = is_array( $_GET ) ? $_GET : [];      // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post = is_array( $_POST ) ? $_POST : [];    // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$path = self::request_path();

		return [
			'pagenow'        => isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '',
			'script'         => self::script_name(),
			'path'           => $path,
			'get'            => $get,
			'post'           => $post,
			// WordPress rebuilds $_REQUEST later, in wp_magic_quotes(), as
			// array_merge( $_GET, $_POST ). POST-over-GET precedence is
			// mirrored here rather than read from it.
			'action'         => self::request_value( $get, $post, 'action' ),
			// Located exactly as check_ajax_referer( $action, 'nonce' ) does.
			'nonce'          => self::nonce_value( $get, $post ),
			'wc_api_query'   => self::query_callback_identifier( $get ),
			'wc_api_path'    => self::path_callback_identifier( $path ),
			'doing_cron'     => defined( 'DOING_CRON' ) && DOING_CRON,
			'doing_ajax'     => defined( 'DOING_AJAX' ) && DOING_AJAX,
			'xmlrpc'         => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
			// WP_ADMIN is defined by wp-admin/admin.php, admin-ajax.php and
			// admin-post.php BEFORE wp-load.php, so this is already settled.
			// current_screen does not exist yet, so is_admin() is exactly that.
			'admin'          => is_admin(),
			'rest_route'     => self::request_value( $get, $post, 'rest_route' ),
			'rest_path'      => self::is_rest_path( $path ),
			'permalinks'     => '' !== (string) get_option( 'permalink_structure', '' ),
			// Defined at woocommerce.php include time, so it is already set by
			// the time this hook fires.
			'woocommerce'    => defined( 'WC_PLUGIN_FILE' ),
			'super_admin'    => static function (): bool {
				return function_exists( 'is_super_admin' ) && is_super_admin();
			},
			'nonce_verifier' => static function ( string $nonce, string $action ): bool {
				// wp_verify_nonce() returns false, 1 or 2; check_ajax_referer()
				// accepts 1 and 2 alike, and so does this.
				return function_exists( 'wp_verify_nonce' ) && false !== wp_verify_nonce( $nonce, $action );
			},
		];
	}

	/**
	 * The value $_REQUEST will carry once WordPress has rebuilt it.
	 *
	 * wp_magic_quotes() runs after this hook and assigns
	 * array_merge( $_GET, $_POST ) to $_REQUEST, so POST wins — which is the
	 * precedence mirrored here rather than taken from a $_REQUEST that
	 * WordPress has not normalised yet.
	 *
	 * @param array<string,mixed> $get
	 * @param array<string,mixed> $post
	 */
	private static function request_value( array $get, array $post, string $key ): string {
		return self::scalar( $post, $key, self::scalar( $get, $key, '' ) );
	}

	/**
	 * The nonce check_ajax_referer( $action, 'nonce' ) would read.
	 *
	 * Same order as the installed pluggable.php: the named parameter first,
	 * then `_ajax_nonce`, then `_wpnonce`.
	 *
	 * @param array<string,mixed> $get
	 * @param array<string,mixed> $post
	 */
	private static function nonce_value( array $get, array $post ): string {
		foreach ( [ 'nonce', '_ajax_nonce', '_wpnonce' ] as $key ) {
			if ( array_key_exists( $key, $post ) || array_key_exists( $key, $get ) ) {
				return self::request_value( $get, $post, $key );
			}
		}

		return '';
	}

	/**
	 * The identifier the QUERY form would dispatch.
	 *
	 * LegacyRestApiStub::maybe_process_wc_api_query_var() assigns
	 * `sanitize_key( wp_unslash( $_GET['wc-api'] ) )` and then dispatches
	 * `strtolower( wc_clean( ... ) )` of it. sanitize_key() already lowercases
	 * and keeps only [a-z0-9_-], so the second step is a no-op on its output and
	 * sanitize_key() alone reproduces the dispatched name.
	 *
	 * Only $_GET is read, because that is the only superglobal WooCommerce
	 * consults here — a POSTed `wc-api` routes nothing.
	 *
	 * @param array<string,mixed> $get
	 */
	private static function query_callback_identifier( array $get ): string {
		$raw = self::scalar( $get, 'wc-api', '' );

		return '' === $raw ? '' : sanitize_key( $raw );
	}

	/**
	 * The identifier the PATH form would dispatch.
	 *
	 * The `wc-api` rewrite endpoint (add_rewrite_endpoint, EP_ALL) captures
	 * EVERYTHING after `wc-api/`, not just the first segment, and parse_str()
	 * percent-decodes it into the query var. WooCommerce then dispatches
	 * `strtolower( wc_clean( ... ) )` — wc_clean is sanitize_text_field, which
	 * keeps the slash — so `/wc-api/wc_gateway_paypal/extra/` names a hook
	 * nothing is listening on. Taking the whole remainder rather than one
	 * segment is what keeps this from exempting such a request.
	 */
	private static function path_callback_identifier( string $path ): string {
		// `~` delimiter, because `#` is a character this pattern must match.
		if ( 1 !== preg_match( '~(?:^|/)wc-api/(.*)$~', $path, $matches ) ) {
			return '';
		}

		/*
		 * EXACTLY ONE trailing slash is removed, because that is all the rewrite
		 * rule absorbs. add_rewrite_endpoint() generates `wc-api(/(.*))?/?$`, so
		 * `/wc-api/WC_Gateway_Paypal//` gives WordPress the query var
		 * `WC_Gateway_Paypal/` and it dispatches
		 * `woocommerce_api_wc_gateway_paypal/` — a hook nothing listens on.
		 * rtrim() would have collapsed every trailing slash and exempted a
		 * request that never reaches the handler.
		 */
		$raw = preg_replace( '~/$~', '', urldecode( $matches[1] ) );

		return '' === $raw ? '' : strtolower( sanitize_text_field( (string) $raw ) );
	}

	/** Does this path address the REST API rather than the front controller? */
	private static function is_rest_path( string $path ): bool {
		$prefix = function_exists( 'rest_get_url_prefix' ) ? (string) rest_get_url_prefix() : 'wp-json';
		if ( '' === $prefix ) {
			return false;
		}

		$normalised = '/' . ltrim( urldecode( $path ), '/' );
		if ( 0 === strpos( $normalised, '/index.php/' ) ) {
			$normalised = substr( $normalised, strlen( '/index.php' ) );
		}

		return 0 === strpos( $normalised, '/' . $prefix . '/' ) || rtrim( $normalised, '/' ) === '/' . $prefix;
	}

	/**
	 * @param array<string,mixed> $source
	 */
	private static function scalar( array $source, string $key, string $default ): string {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return $default;
		}

		return (string) $source[ $key ];
	}

	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$cut = strpos( $uri, '?' );

		return false === $cut ? $uri : substr( $uri, 0, $cut );
	}

	private static function script_name(): string {
		foreach ( [ 'SCRIPT_NAME', 'PHP_SELF' ] as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
				return basename( $_SERVER[ $key ] );
			}
		}

		return '';
	}

	private static function is_cli(): bool {
		return 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Second line of defence on an exempt request.
	 *
	 * The parameter screen above already refused anything carrying a shopping or
	 * account trigger. This detaches the handlers themselves so an exempt
	 * request cannot run one incidentally through a parameter this list does not
	 * name. It only ever REMOVES callbacks, and only WooCommerce's own.
	 */
	private static function detach_on_exempt_request(): void {
		// WC_Form_Handler::init() runs when WooCommerce's files are included,
		// which is after this hook; the removals are therefore scheduled ahead
		// of each hook they target rather than performed now.
		add_action( 'wp_loaded', [ self::class, 'detach_shopping_handlers' ], 0 );
		add_action( 'wp', [ self::class, 'detach_shopping_handlers' ], 0 );
		add_action( 'template_redirect', [ self::class, 'detach_shopping_handlers' ], -1 );
	}

	/** Idempotent. Public only so the three hooks above can reach it. */
	public static function detach_shopping_handlers(): void {
		foreach ( self::DETACH_FORM_HANDLER as [ $hook, $method, $priority ] ) {
			remove_action( $hook, [ 'WC_Form_Handler', $method ], $priority );
		}

		// WC_AJAX::do_wc_ajax, registered on template_redirect at 0.
		remove_action( 'template_redirect', [ 'WC_AJAX', 'do_wc_ajax' ], 0 );
	}

	/**
	 * Refuse the request and stop.
	 *
	 * 503 with no-store and a Retry-After, because this is a temporary
	 * operational state and no intermediary should retain the page. Nothing is
	 * read from the request and nothing is written anywhere.
	 */
	private static function refuse(): void {
		if ( ! headers_sent() ) {
			$headers = self::refusal_headers();
			// Status on the first call, so a bare 200 can never escape even if a
			// later header() is rejected.
			header( array_shift( $headers ), true, self::STATUS );
			foreach ( $headers as $header ) {
				header( $header );
			}
		}

		echo self::body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below from fixed, escaped strings.
		exit;
	}

	/**
	 * The exact header set a refusal sends, in order.
	 *
	 * Extracted so it can be asserted directly: under the CLI SAPI header() is a
	 * no-op and headers_list() is empty, so the emitted set would otherwise be
	 * unobservable to an automated test.
	 *
	 * @return array<int,string>
	 */
	public static function refusal_headers(): array {
		return [
			'Content-Type: text/html; charset=utf-8',
			'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
			'Pragma: no-cache',
			'Expires: Wed, 11 Jan 1984 05:00:00 GMT',
			'Retry-After: ' . self::RETRY_AFTER,
			'X-Robots-Tag: noindex, nofollow',
		];
	}

	/** The refusal page. One fixed sentence; no request data reaches it. */
	public static function body(): string {
		$message = esc_html( __( self::MESSAGE, 'bingocommerce-portal-integration' ) );

		return '<!doctype html><html><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow">'
			. '<title>' . $message . '</title>'
			. '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
			. 'font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
			. 'background:#f6f7f7;color:#1d2327}main{max-width:34rem;padding:2rem;text-align:center}</style>'
			. '</head><body><main><p>' . $message . '</p></main></body></html>';
	}
}
