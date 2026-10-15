<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bind browser, exchange, provision, audit, then create the Hall's SSO session. */
final class SsoLogin {
	public static function register(): void {
		add_action( 'login_form_bc_portal_sso_start', [ self::class, 'start' ] );
		add_action( 'login_form_bc_portal_sso_return', [ self::class, 'returned' ] );
		add_action( 'login_form_bc_portal_sso_message', [ self::class, 'message' ] );
		add_action( 'login_form', [ self::class, 'login_button' ] );
		add_filter( 'wp_auth_check_load', [ self::class, 'auth_check_load' ] );
	}

	/** Handler-independent start preparation; it never accepts a Hall from HTTP. */
	public static function prepare_start( string $remote_addr ) {
		$config = self::configuration();
		if ( is_wp_error( $config ) || false === filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) { return self::failure(); }
		$repository = new SsoRepository();
		$ip = hash_hmac( 'sha256', $remote_addr, wp_salt( 'auth' ) );
		// Cleanup and mapping resolution stay outside the short start-limit lock.
		if ( is_wp_error( $repository->prune() ) ) { return self::failure(); }
		$blog = get_current_blog_id();
		$mapping = self::mapping( $blog );
		if ( ! is_array( $mapping ) ) { return self::failure(); }
		try {
			$reference = bin2hex( random_bytes( 32 ) );
			$proof = bin2hex( random_bytes( 32 ) );
			$browser = bin2hex( random_bytes( 32 ) );
			$sso_reference = wp_generate_uuid4();
			if ( 1 !== $repository->create_attempt( $reference, $browser, $proof, $ip, $blog, $sso_reference ) ) { return self::failure(); }
			return [ 'browser_secret' => $browser, 'url' => add_query_arg( [
				'attempt_reference' => $reference, 'proof_fingerprint' => hash( 'sha256', $proof ),
				'portal_hall_id' => (int) $mapping['portal_hall_id'], 'sso_reference' => $sso_reference,
			], $config->baseUrl() . '/commerce/sso/authorize' ) ];
		} catch ( \Throwable $ignored ) { return self::failure(); }
	}

	public static function start(): void {
		self::headers();
		$result = self::prepare_start( is_string( $_SERVER['REMOTE_ADDR'] ?? null ) ? $_SERVER['REMOTE_ADDR'] : '' );
		if ( is_wp_error( $result ) || ! self::browser_cookie( $result['browser_secret'] ) ) { self::redirect_message( 'general' ); }
		// The destination is solely validated server configuration, never input.
		wp_redirect( $result['url'], 302, 'BingoCommerce SSO' );
		exit;
	}

	public static function returned(): void {
		self::headers();
		$cookie = $_COOKIE[ self::cookie_name() ] ?? null;
		$result = self::complete( $_GET['code'] ?? null, $_GET['attempt_reference'] ?? null, $cookie );
		if ( is_wp_error( $result ) ) { self::redirect_message( $result->get_error_data()['message_key'] ?? 'general' ); }
		wp_safe_redirect( admin_url(), 302, 'BingoCommerce SSO' );
		exit;
	}

	/** One request, one atomic consume before HTTP. Return only a user ID or fixed error. */
	public static function complete( $code, $attempt_reference, $browser_secret ) {
		$config = self::configuration();
		if ( is_wp_error( $config ) || ! SsoExchangeValidator::hex( $code ) || ! SsoExchangeValidator::hex( $attempt_reference ) || ! SsoExchangeValidator::hex( $browser_secret ) ) { return self::failure(); }
		$repository = new SsoRepository();
		$blog = get_current_blog_id();
		$attempt = $repository->attempt( $attempt_reference, $blog );
		if ( ! is_array( $attempt ) || ! hash_equals( $attempt['browser_secret_fingerprint'], hash( 'sha256', $browser_secret ) ) ) { return self::failure(); }
		$mapping = self::mapping( (int) $attempt['blog_id'] );
		if ( ! is_array( $mapping ) || ! SsoExchangeValidator::reference( $attempt['sso_reference'] )
			|| ! SsoExchangeValidator::hex( $attempt['secret_proof'] ) || 1 !== $repository->consume( $attempt_reference, $blog ) ) { return self::failure(); }
		self::browser_cookie( '' );
		// Mapping ownership is immutable. Resolve the Hall from the attempt's
		// saved blog, never the callback or exchange answer.
		$hall = (int) $mapping['portal_hall_id'];
		$reference = $attempt['sso_reference'];
		$identity = null;
		try {
			$body = ( new SsoHttpClient() )->exchange( $config, $code, $attempt_reference, $attempt['secret_proof'] );
			if ( is_wp_error( $body ) ) { return self::audited_failure( $blog, $hall, $reference, 'exchange_failed' ); }
			$identity = SsoExchangeValidator::validate( $body, $config, $hall, $reference );
			if ( is_wp_error( $identity ) ) { return self::audited_failure( $blog, $hall, $reference, 'invalid_response', 0, true ); }
			$person = $identity['portal_user_id'];
			if ( ! SsoAudit::record( 'exchanged', $blog, $hall, $reference, 'verified', $person ) ) { return self::failure(); }
			$user = ( new SsoProvisioningService() )->provision( $identity, $blog, $hall, $reference );
			if ( is_wp_error( $user ) ) {
				$data = $user->get_error_data();
				return self::audited_failure( $blog, $hall, $reference, $user->get_error_code(), $person, (bool) ( $data['support_review'] ?? false ), $data['message_key'] ?? 'general', (int) ( $data['wp_user_id'] ?? 0 ) );
			}
			if ( ! SsoAudit::record( 'account_ready', $blog, $hall, $reference, 'ready', $person, $user ) ) { return self::failure(); }
			if ( ! SsoAudit::record( 'login', $blog, $hall, $reference, 'verified', $person, $user ) ) { return self::failure(); }
			if ( is_wp_error( SsoSession::sign_in( $user ) ) ) { return self::audited_failure( $blog, $hall, $reference, 'session_failed', $person, false, 'general', $user ); }
			return $user;
		} catch ( \Throwable $ignored ) {
			return self::audited_failure( $blog, $hall, $reference, 'unexpected_failure', is_array( $identity ) ? $identity['portal_user_id'] : 0, true );
		}
	}

	private static function configuration() {
		$config = PortalConfig::fromSsoConstants();
		$local = in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
		if ( is_wp_error( $config ) || ( ! $local && ! is_ssl() )
			|| ! class_exists( '\BingoCommerce\Core\HallStaffIdentity' ) || ! SsoMigrations::is_ready() ) { return self::failure(); }
		return $config;
	}

	private static function mapping( int $blog ) {
		if ( HallCommerceAccess::is_blocked( $blog ) ) { return null; }
		return ( new HallMappingRepository() )->findActiveByBlogId( $blog );
	}

	public static function cookie_name(): string { return is_ssl() ? '__Host-bc_portal_sso' : 'bc_portal_sso'; }

	private static function browser_cookie( string $value ): bool {
		return setcookie( self::cookie_name(), $value, [ 'expires' => time() + ( '' === $value ? -3600 : 300 ), 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
	}

	private static function headers(): void {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
	}

	private static function audited_failure( int $blog, int $hall, string $reference, string $reason, int $person = 0, bool $support = false, string $message = 'general', int $user = 0 ): \WP_Error {
		SsoAudit::record( 'refused', $blog, $hall, $reference, $reason, $person, $user, $support );
		return self::failure( $message );
	}

	private static function failure( string $message = 'general' ): \WP_Error {
		return new \WP_Error( 'sso_refused', 'Store SSO could not be completed.', [ 'message_key' => array_key_exists( $message, self::messages() ) ? $message : 'general' ] );
	}

	private static function messages(): array {
		return [
			'general' => "We couldn't sign you in. Please try again from the Portal.",
			'email_conflict' => 'Your email is already used in this store. Please ask Bingo Innovation support to change the email on your Portal account, then try again.',
			'access_removed' => 'Your access to this store has been removed. Please contact Bingo Innovation support.',
			'contact_support' => "We couldn't find your store account. Please contact Bingo Innovation support.",
		];
	}

	private static function redirect_message( string $key ): void {
		if ( ! array_key_exists( $key, self::messages() ) ) { $key = 'general'; }
		wp_safe_redirect( add_query_arg( [ 'action' => 'bc_portal_sso_message', 'message' => $key ], site_url( 'wp-login.php', 'login' ) ), 302, 'BingoCommerce SSO' );
		exit;
	}

	public static function message(): void {
		self::headers();
		$key = is_string( $_GET['message'] ?? null ) ? $_GET['message'] : 'general';
		$text = self::messages()[ $key ] ?? self::messages()['general'];
		wp_die( esc_html( $text ), esc_html__( 'Portal store access', 'bingocommerce-portal-integration' ), [ 'response' => 200 ] );
	}

	/** Only WordPress's staff login form; never hooked to WooCommerce My Account. */
	public static function login_button(): void {
		// Presentation only: start/return retain the schema, mapping and suspension checks.
		if ( ! class_exists( '\\BingoCommerce\\Core\\HallContext' ) || ! \BingoCommerce\Core\HallContext::is_hall() ) { return; }
		$local = in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
		if ( is_wp_error( PortalConfig::fromSsoConstants() ) || ( ! $local && ! is_ssl() ) ) { return; }
		echo '<p><a class="button button-secondary" href="' . esc_url( self::start_url() ) . '">' . esc_html__( 'Sign in with Portal', 'bingocommerce-portal-integration' ) . '</a></p>';
	}

	public static function auth_check_load( bool $show ): bool {
		if ( $show && SsoAccountGuard::is_portal_user( wp_get_current_user() ) ) {
			// Core registers its output after this filter returns. Replace it just
			// before rendering; keep its existing Heartbeat-driven script and CSS.
			add_action( 'admin_print_footer_scripts', [ self::class, 'auth_check_html' ], 4 );
			add_action( 'wp_print_footer_scripts', [ self::class, 'auth_check_html' ], 4 );
		}
		return $show;
	}

	public static function auth_check_html(): void {
		if ( ! SsoAccountGuard::is_portal_user( wp_get_current_user() ) ) { return; }
		remove_action( 'admin_print_footer_scripts', 'wp_auth_check_html', 5 );
		remove_action( 'wp_print_footer_scripts', 'wp_auth_check_html', 5 );
		// The authenticated page already established this Hall. No mapping/table
		// lookup on every admin page. The start endpoint rechecks readiness.
		?>
		<div id="wp-auth-check-wrap" class="hidden fallback">
			<div id="wp-auth-check-bg"></div>
			<div id="wp-auth-check">
				<button type="button" class="wp-auth-check-close button-link"><span class="screen-reader-text"><?php esc_html_e( 'Close dialog' ); ?></span></button>
				<div class="wp-auth-fallback">
					<p class="wp-auth-fallback-expired" tabindex="0"><?php esc_html_e( 'Your session has ended. Sign in with Portal to continue.', 'bingocommerce-portal-integration' ); ?></p>
					<p><a class="button button-primary" target="_top" href="<?php echo esc_url( self::start_url() ); ?>"><?php esc_html_e( 'Sign in with Portal', 'bingocommerce-portal-integration' ); ?></a></p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function start_url(): string {
		return add_query_arg( 'action', 'bc_portal_sso_start', site_url( 'wp-login.php', 'login' ) );
	}
}
