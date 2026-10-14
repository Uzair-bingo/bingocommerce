<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** ADR-048: WordPress sessions only; never reads the SSO tables or the Portal. */
final class SsoSession {
	public const MARKER = 'bc_portal_sso';
	public const LIFETIME = 3600;
	public const IDLE = 1800;
	private static ?array $cookie = null;
	private static ?array $accepted = null;
	private static ?bool $automatic_rest = null;

	public static function register(): void {
		add_action( 'auth_cookie_valid', [ self::class, 'validated_cookie' ], 10, 2 );
		add_action( 'auth_cookie_expired', [ self::class, 'clean_expired_cookie' ] );
		add_action( 'auth_cookie_bad_session_token', [ self::class, 'clean_expired_cookie' ] );
		add_filter( 'determine_current_user', [ self::class, 'determine_user' ], PHP_INT_MAX );
		add_filter( 'rest_authentication_errors', [ self::class, 'rest_authentication' ], PHP_INT_MAX );
		add_filter( 'rest_post_dispatch', [ self::class, 'rest_activity' ], PHP_INT_MAX, 3 );
		// Wait for REST's actual route/nonce decision, rather than guessing from a URL.
		add_action( 'shutdown', [ self::class, 'save_activity' ], 5 );
	}

	/** Called only after the successful exchange, provisioning and mandatory login audit. */
	public static function sign_in( int $id ) {
		$user = get_userdata( $id );
		if ( ! SsoAccountGuard::is_portal_user( $user ) || ! self::hall_manager( $user )
			|| is_wp_error( PortalConfig::fromSsoConstants() ) || headers_sent() ) { return self::failure(); }
		$marker = [];
		$token = '';
		$sent = false;
		$expiration = static function ( $length, $target ) use ( $id, &$marker ) {
			if ( $id !== (int) $target ) { return $length; }
			$now = time();
			$marker = [ 'blog_id' => get_current_blog_id(), 'login_at' => $now, 'last_activity_at' => $now ];
			return self::LIFETIME;
		};
		$information = static function ( $session, $target ) use ( $id, &$marker ) {
			if ( $id === (int) $target ) { $session[ self::MARKER ] = $marker; }
			return $session;
		};
		$secure = static fn( $value, $target ) => $id === (int) $target ? is_ssl() : $value;
		// wp_set_auth_cookie() has no return value, and WP's session writer can fail.
		// Refuse cookie emission unless the new, marked session actually exists.
		$send = static function ( $allow, $expires, $until, $target, $scheme, $value ) use ( $id, &$marker, &$token, &$sent ) {
			if ( $id !== (int) $target ) { return $allow; }
			$token = $value;
			$session = \WP_Session_Tokens::get_instance( $id )->get( $token );
			$sent = $allow && 0 === $expires && is_array( $session )
				&& ( $session[ self::MARKER ] ?? null ) === $marker
				&& ( $session['expiration'] ?? 0 ) === $until && $until <= $marker['login_at'] + self::LIFETIME;
			return $sent;
		};
		add_filter( 'auth_cookie_expiration', $expiration, PHP_INT_MAX, 2 );
		add_filter( 'attach_session_information', $information, PHP_INT_MAX, 2 );
		add_filter( 'secure_auth_cookie', $secure, PHP_INT_MAX, 2 );
		add_filter( 'secure_logged_in_cookie', $secure, PHP_INT_MAX, 2 );
		add_filter( 'send_auth_cookies', $send, PHP_INT_MAX, 6 );
		try {
			wp_set_auth_cookie( $id, false, is_ssl() );
			if ( ! $sent ) {
				if ( '' !== $token ) { \WP_Session_Tokens::get_instance( $id )->destroy( $token ); }
				return self::failure();
			}
		} catch ( \Throwable $ignored ) {
			if ( '' !== $token ) { \WP_Session_Tokens::get_instance( $id )->destroy( $token ); }
			return self::failure();
		} finally {
			remove_filter( 'auth_cookie_expiration', $expiration, PHP_INT_MAX );
			remove_filter( 'attach_session_information', $information, PHP_INT_MAX );
			remove_filter( 'secure_auth_cookie', $secure, PHP_INT_MAX );
			remove_filter( 'secure_logged_in_cookie', $secure, PHP_INT_MAX );
			remove_filter( 'send_auth_cookies', $send, PHP_INT_MAX );
		}
		try {
			wp_set_current_user( $id );
			// The same post-login notification used by WP; WC updates its normal bookkeeping.
			do_action( 'wp_login', $user->user_login, $user );
			return true;
		} catch ( \Throwable $ignored ) {
			\WP_Session_Tokens::get_instance( $id )->destroy( $token );
			wp_clear_auth_cookie();
			wp_set_current_user( 0 );
			return self::failure();
		}
	}

	public static function validated_cookie( array $cookie, \WP_User $user ): void {
		if ( SsoAccountGuard::is_portal_user( $user ) ) { self::$cookie = $cookie + [ 'user_id' => (int) $user->ID ]; }
	}

	/** @param int|false $id @return int|false */
	public static function determine_user( $id ) {
		if ( ! $id ) { return $id; }
		$user = get_userdata( (int) $id ); // Already cached by WordPress authentication.
		if ( ! SsoAccountGuard::is_portal_user( $user ) ) { return $id; }
		self::$accepted = null;
		$cookie = self::$cookie;
		if ( ! $cookie || (int) $id !== $cookie['user_id'] || $cookie['username'] !== $user->user_login ) { return 0; }
		$sessions = get_user_meta( (int) $id, 'session_tokens', true ); // WP has already loaded usermeta.
		$session = is_array( $sessions ) ? ( $sessions[ hash( 'sha256', $cookie['token'] ) ] ?? null ) : null;
		$marker = is_array( $session ) ? ( $session[ self::MARKER ] ?? null ) : null;
		if ( ! self::valid_marker( $marker ) ) { return 0; }
		if ( self::expired( $session, time() ) ) {
			\WP_Session_Tokens::get_instance( (int) $id )->destroy( $cookie['token'] );
			return 0;
		}
		if ( $marker['blog_id'] !== get_current_blog_id() || ! self::hall_manager( $user )
			|| is_wp_error( PortalConfig::fromSsoConstants() ) ) { return 0; }
		self::$accepted = [ 'id' => (int) $id, 'token' => $cookie['token'], 'blog_id' => $marker['blog_id'] ];
		return $id;
	}

	private static function hall_manager( \WP_User $user ): bool {
		// Match the provisioning rule, including extra capabilities/roles, without a query.
		return $user->get_site_id() === get_current_blog_id() && [ 'bc_hall_manager' => true ] === $user->caps;
	}

	private static function valid_marker( $marker ): bool {
		return is_array( $marker ) && is_int( $marker['blog_id'] ?? null ) && $marker['blog_id'] > 0
			&& is_int( $marker['login_at'] ?? null ) && $marker['login_at'] > 0
			&& is_int( $marker['last_activity_at'] ?? null ) && $marker['last_activity_at'] >= $marker['login_at']
			&& $marker['last_activity_at'] <= time();
	}

	private static function expired( array $session, int $now ): bool {
		$marker = $session[ self::MARKER ];
		return ! is_int( $session['expiration'] ?? null ) || $now >= $session['expiration']
			|| $now - $marker['login_at'] >= self::LIFETIME || $now - $marker['last_activity_at'] >= self::IDLE;
	}

	/** Core rejects expired cookies before determine_current_user; remove only a proven expired token. */
	public static function clean_expired_cookie( array $cookie ): void {
		if ( ! is_string( $cookie['username'] ?? null ) || ! SsoAccountGuard::is_reserved_username( $cookie['username'] )
			|| ! is_string( $cookie['token'] ?? null ) || '' === $cookie['token'] ) { return; }
		$user = get_user_by( 'login', $cookie['username'] );
		if ( ! $user ) { return; }
		$sessions = get_user_meta( $user->ID, 'session_tokens', true );
		$session = is_array( $sessions ) ? ( $sessions[ hash( 'sha256', $cookie['token'] ) ] ?? null ) : null;
		// The cookie's unverified expiry/HMAC never authorizes deletion. A matching
		// stored token AND the server's own SSO timestamps must establish expiry.
		if ( is_array( $session ) && self::valid_marker( $session[ self::MARKER ] ?? null ) && self::expired( $session, time() ) ) {
			\WP_Session_Tokens::get_instance( $user->ID )->destroy( $cookie['token'] );
		}
	}

	/** Reuse the same decision after REST's cookie nonce and WC authentication fallback. */
	public static function rest_authentication( $result ) {
		if ( is_wp_error( $result ) ) { self::$accepted = null; return $result; }
		$user = wp_get_current_user();
		if ( ! SsoAccountGuard::is_portal_user( $user ) ) { return $result; }
		if ( ! self::determine_user( $user->ID ) ) {
			wp_set_current_user( 0 );
			return new \WP_Error( 'portal_sso_session_required', 'Please sign in with Portal.', [ 'status' => 401 ] );
		}
		return $result;
	}

	/** Classify the HTTP response, not WooCommerce's internal rest_do_request calls. */
	public static function rest_activity( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( ! self::$accepted || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) { return $response; }
		$route = rtrim( $request->get_route(), '/' );
		$method = $request->get_method();
		$automatic = ( 'GET' === $method && in_array( $route, [ '/wc-analytics/imports/status', '/wc-analytics/reports/import/status' ], true ) )
			|| ( 'POST' === $method && in_array( $route, [ '/wc-admin/mobile-app/qr-login-status', '/wc-admin/settings/payments/woopayments/onboarding/step/test_account/check' ], true ) );
		if ( 'PUT' === $method && preg_match( '#\A/wc-analytics/admin/notes/[1-9][0-9]*\z#', $route ) ) {
			$body = $request->is_json_content_type() ? $request->get_json_params() : $request->get_body_params();
			$automatic = [ 'is_read' => true ] === $body;
		}
		// Autosaves and user-initiated REST batches deliberately count.
		self::$automatic_rest = $automatic;
		return $response;
	}

	private static function automatic_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return false !== self::$automatic_rest; }
		if ( wp_doing_ajax() ) {
			$action = $_REQUEST['action'] ?? null;
			if ( 'heartbeat' === $action ) { return ! isset( $_POST['data']['wp_autosave'] ); }
			return in_array( $action, [ 'woocommerce_load_status_widget', 'woocommerce_load_recent_reviews_widget' ], true )
				|| ( 'dashboard-widgets' === $action && 'dashboard_primary' === ( $_REQUEST['widget'] ?? null ) );
		}
		return 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'admin.php' === ( $GLOBALS['pagenow'] ?? '' )
			&& 'wc-status' === ( $_GET['page'] ?? null ) && 'tools' === ( $_GET['tab'] ?? null )
			&& isset( $_GET['wc_status_tools_poll'] ) && is_scalar( $_GET['wc_status_tools_poll'] );
	}

	public static function save_activity(): void {
		$accepted = self::$accepted;
		self::$accepted = null; // At most one save, including nested/early shutdowns.
		if ( ! $accepted || self::automatic_request() || $accepted['blog_id'] !== get_current_blog_id() ) { return; }
		$user = wp_get_current_user();
		if ( (int) $user->ID !== $accepted['id'] || ! self::hall_manager( $user ) ) { return; }
		$sessions = get_user_meta( $user->ID, 'session_tokens', true );
		$key = hash( 'sha256', $accepted['token'] );
		$session = is_array( $sessions ) ? ( $sessions[ $key ] ?? null ) : null;
		$now = time();
		if ( ! is_array( $session ) || ! self::valid_marker( $session[ self::MARKER ] ?? null )
			|| self::expired( $session, $now ) || $now - $session[ self::MARKER ]['last_activity_at'] < 60 ) { return; }
		$updated = $sessions;
		$updated[ $key ][ self::MARKER ]['last_activity_at'] = $now;
		// Compare-and-set via WordPress metadata API: a concurrent logout or login
		// cannot be overwritten by this request's older cached list of sessions.
		update_user_meta( $user->ID, 'session_tokens', $updated, $sessions );
	}

	private static function failure(): \WP_Error {
		return new \WP_Error( 'session_failed', 'Store SSO could not be completed.' );
	}
}
