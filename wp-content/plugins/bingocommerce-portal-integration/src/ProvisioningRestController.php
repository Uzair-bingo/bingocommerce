<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ADR-042 machine endpoint:
 * `PUT /wp-json/bingocommerce/v1/portal-halls/{portal_hall_id}/commerce`.
 *
 * WHAT WORDPRESS CORE OWNS, AND IS LEFT ALONE. Core authenticates before
 * dispatch, matches the route and the method, parses JSON, and runs any
 * registered-argument checks. Malformed JSON stays core `rest_invalid_json`; an
 * unmatched route or wrong method stays core's own response; an Application
 * Password rejected by core stays core's 401. ADR-042 is explicit that the
 * custom schema applies only to requests that REACH BingoCommerce callbacks, and
 * manufacturing it for the others would mean intercepting core's own error
 * handling.
 *
 * NO REST `args` FOR THIS CONTRACT — DELIBERATELY. ADR-042 requires the path and
 * every body field to be validated MANUALLY, after `permission_callback`.
 * Registering `validate_callback`/`sanitize_callback` would move those failures
 * BEFORE the permission check (core runs `has_valid_params()` /
 * `sanitize_params()` in `dispatch()`, ahead of `respond_to_request()`), turning
 * them into core-shaped bodies and, worse, letting an unauthenticated caller
 * learn which fields exist. A sanitizer would also rewrite values — and a Hall
 * name that is trimmed or a slug that is lowercased is a DIFFERENT canonical
 * request with a different fingerprint.
 *
 * PUT ONLY, SPELLED OUT. `WP_REST_Server::EDITABLE` is the string
 * 'POST, PUT, PATCH', so using it would silently admit two methods this contract
 * does not define.
 *
 * WHAT IT NEVER DOES: no cron, no scheduler, no webhook, no Portal UI, no SSO,
 * no Portal user copy, and no outbound request of its own.
 *
 * AND NO NETWORK PROVISIONING-SCHEMA DDL. That is the precise claim: this
 * controller never migrates or repairs
 * `{base_prefix}bingo_portal_provisioning_operations`,
 * `{base_prefix}bingo_hall_commerce_state` or their version option — an unready
 * network schema is the reduced 503 `platform_unavailable` below, repaired by an
 * explicit operator migration. It is NOT a claim that no table is ever created
 * by a request that reaches this route: approved platform-owned initialization
 * of a NEW Hall site necessarily creates that site's own WordPress, WooCommerce,
 * Catalog and Session tables, which is what ADR-042's "ready but empty store"
 * means. Those are site-owned schemas, created by invoking their owners, and
 * scoped to the site being provisioned.
 */
final class ProvisioningRestController {

	public const NAMESPACE  = 'bingocommerce/v1';
	public const ROUTE      = '/portal-halls/(?P<portal_hall_id>[^/]+)/commerce';
	public const FULL_ROUTE = '/' . self::NAMESPACE . self::ROUTE;

	/** The dedicated machine capability. Granted to no role by this plugin. */
	public const CAPABILITY = 'bc_receive_portal_provisioning';

	/**
	 * Private marker on THIS controller's own permission failures.
	 *
	 * The `rest_request_after_callbacks` conversion below rewrites a WP_Error
	 * ONLY when it carries this key. Core's authentication errors,
	 * `rest_invalid_json`, route/method failures, registered-argument failures
	 * and every other route's errors carry no such key, so none of them can be
	 * reached by the conversion even though the filter itself is global.
	 */
	private const MARKER = 'bc_provisioning_permission_failure';

	/** The exact body keys a provisioning command may carry. */
	public const BODY_KEYS = [
		'operation_id',
		'intent_sequence',
		'desired_state',
		'site_slug',
		'hall_name',
		'portal_actor_id',
	];

	/** Guards against a double registration inside one request. */
	private static bool $registered = false;

	/**
	 * Guards the ROUTE itself.
	 *
	 * Separate from $registered on purpose: register_rest_route() APPENDS to a
	 * route's handler list, so registering the same route twice in one request
	 * leaves two handlers behind it — and the second is the one core would have
	 * to disambiguate. A plugin file can be included twice, and `rest_api_init`
	 * can be fired directly, so the route guards itself rather than trusting
	 * every caller to fire the action exactly once.
	 */
	private static bool $routes_registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		if ( self::$routes_registered ) {
			return;
		}
		self::$routes_registered = true;

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				[
					// The literal method, never a constant that also admits
					// POST and PATCH.
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'handle' ],
					'permission_callback' => [ self::class, 'authorize' ],
					/*
					 * No `args`. The path segment and every body field are
					 * validated manually in handle(), AFTER authorize() has
					 * succeeded, exactly as ADR-042 requires.
					 */
				],
			]
		);

		/*
		 * Registered alongside the route rather than at plugin boot, so the
		 * conversion exists only for requests that are already inside the REST
		 * server. It is scoped three ways — this route, this handler callback,
		 * and this controller's own private marker — and it runs at the default
		 * priority so it sees the permission WP_Error before
		 * WP_REST_Server::error_to_response() converts it.
		 */
		add_filter( 'rest_request_after_callbacks', [ self::class, 'convert_permission_failure' ], 10, 3 );
	}

	/* ------------------------------------------------------------------ *
	 * Authentication and authorization
	 * ------------------------------------------------------------------ */

	/**
	 * Three independent facts, all required (ADR-042 / ADR-011).
	 *
	 * 1. THIS REQUEST AUTHENTICATED WITH AN APPLICATION PASSWORD.
	 *    `rest_get_authenticated_app_password()` returns the UUID core recorded
	 *    through `rest_application_password_collect_status()`. It is the only
	 *    fact that proves the mechanism, as opposed to proving that somebody is
	 *    logged in somehow.
	 * 2. THE RECORDED STATUS IS A WP_User AND IT IS THE CURRENT USER. The UUID
	 *    alone would be satisfied by a stale global; requiring the recorded user
	 *    to BE `get_current_user_id()` ties the Application Password to the
	 *    identity the rest of the request will act as.
	 * 3. THE AUTHENTICATED ACCOUNT IS NOT A NETWORK SUPER ADMIN. This is a
	 *    REFUSAL, checked before the capability and never satisfied by one.
	 *    WP_User::has_cap() answers true for EVERY capability when
	 *    is_super_admin() is true (wp-includes/class-wp-user.php), so a
	 *    capability check alone admits every Super Admin on the network — which
	 *    is exactly the authority BR-076 says does not initiate automatic
	 *    Enable/Disable. ADR-042's account rule is a dedicated nonhuman Portal
	 *    account that is NOT Network Super Admin, and this is where that rule is
	 *    enforced rather than assumed.
	 * 4. THE DEDICATED CAPABILITY. Not Hall Manager, not Game Operator, not
	 *    `manage_network` — one capability that exists for this endpoint, so
	 *    Portal authority can be granted and revoked without moving anything
	 *    else with it.
	 *
	 * EXPLICITLY NOT PROOF, and none of them is consulted: an Authorization
	 * header being present, the header LOOKING like Basic auth,
	 * `wp_is_application_passwords_in_use()`, a login cookie, or a capability on
	 * its own.
	 *
	 * @return true|\WP_Error
	 */
	public static function authorize( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! self::has_application_password_proof() ) {
			return self::permission_failure( 'authentication_failed', 401 );
		}

		$current = (int) get_current_user_id();

		/*
		 * REFUSED BEFORE THE CAPABILITY, AND BEFORE CLASSIFICATION. A Super
		 * Admin reaching this point has valid Application Password proof and
		 * would pass any capability test ever written, so the capability cannot
		 * be what keeps one out. Refusing here means no operation row, no Hall
		 * mutex, no site and no provisioning audit results from it.
		 */
		if ( is_multisite() && is_super_admin( $current ) ) {
			return self::permission_failure( 'authorization_failed', 403 );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			// Authenticated, but not permitted. Distinguished from 401 so a
			// correctly configured Portal can tell a credential problem from a
			// permission one.
			return self::permission_failure( 'authorization_failed', 403 );
		}

		return true;
	}

	/** All three proofs, or none. */
	public static function has_application_password_proof(): bool {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return false;
		}

		$uuid = rest_get_authenticated_app_password();
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return false;
		}

		$status = $GLOBALS['wp_rest_application_password_status'] ?? null;
		if ( ! $status instanceof \WP_User ) {
			return false;
		}

		$current = (int) get_current_user_id();

		return $current > 0 && (int) $status->ID === $current;
	}

	/** A permission failure carrying this controller's private marker. */
	private static function permission_failure( string $category, int $status ): \WP_Error {
		return new \WP_Error(
			$category,
			// Fixed text. The body Portal receives is built from the category,
			// not from this message, which never reaches the client.
			__( 'The provisioning request was refused.', 'bingocommerce-portal-integration' ),
			[
				'status'      => $status,
				self::MARKER  => $category,
			]
		);
	}

	/**
	 * Convert THIS controller's own permission failures — and nothing else.
	 *
	 * `rest_request_after_callbacks` is a global filter, so the conversion is
	 * scoped by FOUR independent facts, every one of which must hold:
	 *
	 *   - the error carries this controller's PRIVATE marker, which only
	 *     permission_failure() sets;
	 *   - the matched handler's callback is this controller's own;
	 *   - the method is exactly PUT; and
	 *   - the REQUESTED ROUTE matches this contract's own pattern.
	 *
	 * The route test is not redundant with the callback test. These callbacks
	 * are public static methods, so anything on the installation can register
	 * another route against them — deliberately or by copying this file — and
	 * that route's refusals are not this contract's to reshape. Requiring the
	 * ADR-042 route pattern means the conversion applies to the one endpoint the
	 * decision describes and to nothing else.
	 *
	 * Core Application Password failures never reach this filter at all (core
	 * skips it for requests that fail to authenticate or match a route), and
	 * `rest_invalid_json` and registered-argument failures, which DO reach it,
	 * carry no marker and belong to no handler of ours.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @return mixed
	 */
	public static function convert_permission_failure( $response, $handler, $request ) {
		if ( ! $response instanceof \WP_Error ) {
			return $response;
		}
		if ( ! $request instanceof \WP_REST_Request || 'PUT' !== strtoupper( (string) $request->get_method() ) ) {
			return $response;
		}
		if ( ! is_array( $handler )
			|| ( $handler['callback'] ?? null ) !== [ self::class, 'handle' ] ) {
			return $response;
		}
		if ( ! self::is_provisioning_route( (string) $request->get_route() ) ) {
			return $response;
		}

		$data = $response->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data[ self::MARKER ] ) ) {
			// Not ours. Core's own shape is preserved exactly.
			return $response;
		}

		$category = (string) $data[ self::MARKER ];
		if ( ! in_array( $category, [ 'authentication_failed', 'authorization_failed' ], true ) ) {
			return $response;
		}

		// The path ID is deliberately NOT echoed: it has not been manually
		// validated at this point, and a reduced body may carry it only once it
		// has been.
		return ProvisioningResponse::reduced( $category, 'authentication_failed' === $category ? 401 : 403 );
	}

	/**
	 * Is this the exact ADR-042 provisioning route?
	 *
	 * Anchored at both ends and built from the same namespace and pattern the
	 * route is registered with, so the two can never drift apart.
	 */
	public static function is_provisioning_route( string $route ): bool {
		return 1 === preg_match( '#\A' . str_replace( '#', '\\#', self::FULL_ROUTE ) . '\z#', $route );
	}

	/* ------------------------------------------------------------------ *
	 * The endpoint callback
	 * ------------------------------------------------------------------ */

	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			return self::dispatch( $request );
		} catch ( \Throwable $throwable ) {
			/*
			 * The outermost containment. Nothing has been classified if a throw
			 * reaches here from validation or the schema gate; the orchestrator
			 * contains its own throws and captures its own outcome, so this can
			 * only be a failure of the boundary itself. The exception is never
			 * read.
			 */
			unset( $throwable );

			return ProvisioningResponse::reduced( 'internal_error', 503 );
		}
	}

	private static function dispatch( \WP_REST_Request $request ): \WP_REST_Response {
		// 1. THE PATH, validated on its ORIGINAL TEXT before any cast.
		$portal_hall_id = self::parse_path_id( $request );
		if ( null === $portal_hall_id ) {
			return ProvisioningResponse::reduced( 'validation_failed', 400 );
		}

		// 2. THE BODY, decoded and shape-checked manually.
		$body = self::parse_body( $request );
		if ( null === $body ) {
			return ProvisioningResponse::reduced( 'validation_failed', 400, $portal_hall_id );
		}

		// 3. The closed field contract and the Canonical Request v1 fingerprint.
		$canonical = ProvisioningCanonicalRequest::validate(
			array_merge( [ 'portal_hall_id' => $portal_hall_id ], $body )
		);
		if ( $canonical instanceof \WP_Error ) {
			return ProvisioningResponse::reduced( 'validation_failed', 400, $portal_hall_id );
		}

		/*
		 * 4. THE RUNTIME SCHEMA GATE. Structural readiness, never the recorded
		 * version alone — the option records what a migration once installed,
		 * not what the database currently holds. No DDL and no repair runs from
		 * a REST request, and the per-request cache is flushed first so the
		 * answer is derived from storage rather than from an earlier call in
		 * this same process.
		 *
		 * Before the Hall mutex, before any row access, and with no audit: a
		 * request that cannot reach classification has no operation identity to
		 * attribute.
		 */
		ProvisioningMigrations::flush_ready_cache();
		if ( ! ProvisioningMigrations::is_ready() ) {
			return ProvisioningResponse::reduced( 'platform_unavailable', 503, $portal_hall_id );
		}

		return ( new ProvisioningOrchestrator() )->handle( $canonical, (int) get_current_user_id() );
	}

	/* ------------------------------------------------------------------ *
	 * Manual validation
	 * ------------------------------------------------------------------ */

	/**
	 * The path ID: digits only, canonical decimal, no leading zero, 1..PHP_INT_MAX.
	 *
	 * VALIDATED AS TEXT, NEVER CAST FIRST. `(int)` would turn "007", " 7 ",
	 * "7x", "+7", "0x7" and "1e3" into plausible-looking Hall identifiers, and a
	 * non-canonical spelling that resolved to a real Hall would make two
	 * different request URLs address the same tenant.
	 */
	public static function parse_path_id( \WP_REST_Request $request ): ?int {
		/*
		 * get_url_params(), NEVER get_param().
		 *
		 * WP_REST_Request::get_parameter_order() resolves JSON, then POST, then
		 * GET, and only THEN URL, so get_param( 'portal_hall_id' ) lets a query
		 * string, a form field or a JSON property REPLACE the Hall named in the
		 * path. `PUT /portal-halls/5/commerce?portal_hall_id=9` would then lock,
		 * classify and mutate Hall 9 while the URL says 5 — the whole tenancy
		 * decision decided by the part of the request the caller controls most
		 * freely. The matched URL parameters are the only source of Hall
		 * identity here, and nothing merges anything else into them.
		 */
		$url_params = $request->get_url_params();
		$raw        = is_array( $url_params ) ? ( $url_params['portal_hall_id'] ?? null ) : null;

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $raw ) ) {
			// The pattern rejects an empty string, a leading zero (including a
			// bare "0"), a sign, whitespace and every non-digit in one step.
			return null;
		}

		$parsed = (int) $raw;

		// The exact round-trip is what rejects an overflow: PHP clamps a decimal
		// beyond PHP_INT_MAX rather than failing, and the clamped value spells
		// differently from the text that produced it.
		return ( (string) $parsed === $raw && $parsed >= 1 ) ? $parsed : null;
	}

	/**
	 * Decode the body and prove it is a JSON OBJECT with exactly the six keys.
	 *
	 * DECODED FROM THE RAW BODY, NOT THROUGH get_json_params(). Core decodes
	 * with `json_decode( $body, true )`, under which `{}` and `[]` both become
	 * the same empty PHP array — and ADR-042 requires an empty object and an
	 * empty list to remain distinguishable. Decoding to objects keeps them
	 * apart: `{}` is a stdClass and `[]` is an array.
	 *
	 * A non-JSON content type is refused outright before the body is read at
	 * all, so the raw decode below only ever runs on a body core has already
	 * agreed is JSON.
	 *
	 * @return array<string,mixed>|null Null on ANY invalid shape.
	 */
	public static function parse_body( \WP_REST_Request $request ): ?array {
		/*
		 * THE CONTENT TYPE IS PART OF THE CONTRACT, checked with WordPress's own
		 * test rather than a string comparison so `application/json`,
		 * `application/ld+json` and a charset parameter are all judged the way
		 * core judges them.
		 *
		 * Without this, a syntactically valid JSON object sent as `text/plain`
		 * or `application/x-www-form-urlencoded` would be accepted here while
		 * core had never parsed it as JSON — two different readings of one
		 * request body, and the difference decides what a form-encoded caller
		 * can make this endpoint do. It is refused as a callback-reached invalid
		 * shape: the reduced `validation_failed` body, no row, no audit.
		 */
		if ( ! $request->is_json_content_type() ) {
			return null;
		}

		$raw = $request->get_body();
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$decoded = json_decode( $raw, false, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			/*
			 * Defensive only. A JSON-content-type request with a malformed body
			 * is answered by core with `rest_invalid_json` before the permission
			 * callback ever runs, and that is deliberately not intercepted; this
			 * branch exists so a depth limit or a future core change cannot turn
			 * an unparsed body into an accepted one.
			 */
			return null;
		}

		// A JSON object and nothing else. A list, a string, a number, a boolean
		// and a literal null are all rejected, and `[]` cannot masquerade as
		// `{}` because only the latter decodes to an object.
		if ( ! $decoded instanceof \stdClass ) {
			return null;
		}

		$body = get_object_vars( $decoded );

		/*
		 * THE KEY SET IS CLOSED IN BOTH DIRECTIONS. A missing field cannot be
		 * defaulted, because every one of them is hashed into the request
		 * fingerprint; an unknown field cannot be ignored, because a field this
		 * build does not hash is a field Portal could change without changing
		 * the fingerprint. JSON null is a PRESENT key with a null value, never a
		 * missing one — array_keys() sees it, which is exactly the distinction
		 * `isset()` would have lost.
		 */
		$actual = array_keys( $body );
		sort( $actual );
		$expected = self::BODY_KEYS;
		sort( $expected );

		return $actual === $expected ? $body : null;
	}
}
