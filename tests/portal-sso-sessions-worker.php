<?php
/** Isolated request worker. The clock override exists ONLY in this test process. */
namespace BingoCommerce\PortalIntegration {
	function time(): int { return $GLOBALS['bc_sso_test_now'] ?? \time(); }
}
namespace {
	if ( ! in_array( PHP_SAPI, [ 'cli', 'cgi-fcgi' ], true ) || 'bingocommerce_state_recon' !== getenv( 'BC_TEST_DB' ) ) { exit( 1 ); }
	$job = json_decode( 'cli' === PHP_SAPI ? ( $argv[1] ?? '{}' ) : ( getenv( 'BC_SSO_SESSION_JOB' ) ?: '{}' ), true, 32, JSON_THROW_ON_ERROR );
	$mode = $job['mode'] ?? 'request';
	if ( ! in_array( $mode, [ 'request', 'rest', 'ajax', 'login-button', 'popup', 'logout', 'complete', 'return', 'gate' ], true ) ) { exit( 1 ); }
	putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
	putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );
	putenv( 'BC_FIXTURE_CGI=1' );
	define( 'BINGOCOMMERCE_PORTAL_BASE_URL', 'https://portal.example.invalid' );
	if ( ! ( $job['off'] ?? false ) ) { define( 'BINGOCOMMERCE_PORTAL_SSO_TOKEN', $job['sso_token'] ?? 'fixture-phase4b-secret' ); }
	if ( ! ( $job['no_installation'] ?? false ) ) { define( 'BINGOCOMMERCE_PORTAL_INSTALLATION_ID', $job['installation'] ?? 'phase4b' ); }
	if ( 'ajax' === $mode ) { define( 'DOING_AJAX', true ); }
	if ( 'rest' === $mode ) { define( 'REST_REQUEST', true ); }
	require __DIR__ . '/fixtures/wordpress-bootstrap.php';
	if ( DB_NAME !== 'bingocommerce_state_recon' || DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db
		|| DB_NAME !== $wpdb->get_var( $wpdb->prepare( 'SELECT DATABASE() WHERE %d = %d', 1, 1 ) ) ) { throw new \RuntimeException( 'Isolated binding not proven.' ); }
	use BingoCommerce\PortalIntegration\SsoSession;
	use BingoCommerce\PortalIntegration\SsoLogin;
	use BingoCommerce\PortalIntegration\HallCommerceGate;
	use BingoCommerce\Core\Migrations;
	$http = 0;
	add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $job, &$http ) {
		++$http;
		if ( empty( $job['identity'] ) || 'https://portal.example.invalid/integration/v1/sso/exchange' !== $url ) { throw new \RuntimeException( 'Unmocked HTTP forbidden.' ); }
		if ( isset( $job['remove_role_after_portal_approval'] ) ) {
			// Fixture-only simulation of the accepted v1 race: the Portal has
			// approved this response, but local removal happens before provisioning.
			global $wpdb;
			$removed = (int) $job['remove_role_after_portal_approval'];
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $removed, $wpdb->get_blog_prefix() . 'capabilities' ) );
			clean_user_cache( $removed );
		}
		return [ 'response' => [ 'code' => $job['http_status'] ?? 200 ], 'headers' => [], 'body' => json_encode( $job['identity'] ) ];
	}, 10, 3 );
	add_filter( 'pre_wp_mail', '__return_true' );
	switch_to_blog( $job['blog'] ?? 2 );
	$_SERVER['HTTPS'] = 'on';
	$_SERVER['HTTP_HOST'] = wp_parse_url( home_url(), PHP_URL_HOST );
	$_SERVER['REQUEST_METHOD'] = $job['method'] ?? 'GET';
	$_SERVER['REMOTE_ADDR'] = '192.0.2.250';
	$_GET = $job['get'] ?? [];
	$_POST = $job['post'] ?? [];
	$_REQUEST = array_merge( $_GET, $_POST );
	$GLOBALS['pagenow'] = $job['pagenow'] ?? ( 'ajax' === $mode ? 'admin-ajax.php' : 'index.php' );
	if ( isset( $job['now'] ) ) { $GLOBALS['bc_sso_test_now'] = $job['now']; }
	$id = (int) ( $job['id'] ?? 0 );
	$token = $job['token'] ?? '';
	if ( $id && $token ) {
		$expiry = $job['cookie_expiry'] ?? time() + 3600;
		$_COOKIE[ SECURE_AUTH_COOKIE ] = wp_generate_auth_cookie( $id, $expiry, 'secure_auth', $token );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $id, $expiry, 'logged_in', $token );
		if ( $job['bad_cookie'] ?? false ) { $_COOKIE[ SECURE_AUTH_COOKIE ] .= 'x'; $_COOKIE[ LOGGED_IN_COOKIE ] .= 'x'; }
	}
	// A real REST nonce for the browser session, generated before measuring auth.
	if ( 'rest' === $mode && $id ) {
		wp_set_current_user( $id );
		$_SERVER['HTTP_X_WP_NONCE'] = ( $job['bad_nonce'] ?? false ) ? 'invalid' : wp_create_nonce( 'wp_rest' );
	}
	if ( ! empty( $job['disable_guard'] ) ) {
		remove_filter( 'determine_current_user', [ SsoSession::class, 'determine_user' ], PHP_INT_MAX );
		remove_action( 'auth_cookie_valid', [ SsoSession::class, 'validated_cookie' ], 10 );
		remove_action( 'auth_cookie_expired', [ SsoSession::class, 'clean_expired_cookie' ] );
		remove_action( 'auth_cookie_bad_session_token', [ SsoSession::class, 'clean_expired_cookie' ] );
		remove_filter( 'rest_authentication_errors', [ SsoSession::class, 'rest_authentication' ], PHP_INT_MAX );
	}
	if ( $id && ( $job['cold'] ?? false ) ) { clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); }
	unset( $GLOBALS['current_user'] );
	$queries = [];
	add_filter( 'query', static function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; } );
	$before = $wpdb->num_queries;
	$user = wp_get_current_user();
	$auth_queries = $wpdb->num_queries - $before;
	$status = 200;
	$html = '';
	$cookies = [];
	$login_actions = 0;
	$audit_before_cookie = true;
	add_action( 'set_auth_cookie', static function ( $cookie, $expire, $until, $target, $scheme, $value ) use ( &$cookies, &$audit_before_cookie ) {
		global $wpdb;
		$cookies[] = [ 'id' => $target, 'token' => $value, 'expire' => $expire, 'until' => $until, 'scheme' => $scheme ];
		$audit_before_cookie = $audit_before_cookie && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s AND target_id = %s', Migrations::audit_log_table(), 'portal_sso.login', (string) $target ) ) > 0;
	}, 10, 6 );
	add_action( 'wp_login', static function () use ( &$login_actions ) { ++$login_actions; } );
	if ( ! empty( $job['fail_login_audit'] ) ) {
		add_filter( 'query', static fn( $sql ) => str_starts_with( $sql, 'INSERT INTO `' . Migrations::audit_log_table() . '`' ) && str_contains( $sql, 'portal_sso.login' ) ? 'SELECT * FROM bc_phase4b_absent_audit' : $sql, 20 );
	}
	if ( ! empty( $job['fail_session'] ) ) { add_filter( 'update_user_metadata', static fn( $check, $target, $key ) => 'session_tokens' === $key ? false : $check, 10, 3 ); }
	if ( ! empty( $job['unreadable_tables'] ) ) {
		add_filter( 'query', static fn( $sql ) => str_contains( $sql, 'bingo_portal_sso_' ) || str_contains( $sql, 'bingo_portal_user_links' ) ? 'SELECT * FROM bc_phase4b_absent_sso_table' : $sql, 20 );
	}
	$result = null;
	$routes = [];
	add_filter( 'rest_request_before_callbacks', static function ( $response, $handler, $request ) use ( &$routes ) {
		$routes[] = [ $request->get_method(), $request->get_route() ];
		return $response;
	}, 1, 3 );
	if ( in_array( $mode, [ 'complete', 'return' ], true ) ) {
		$_GET = [ 'code' => str_repeat( 'a', 64 ), 'attempt_reference' => $job['attempt'] ];
		$_COOKIE[ SsoLogin::cookie_name() ] = $job['browser'];
		if ( 'return' === $mode ) { SsoLogin::returned(); }
		$result = SsoLogin::complete( $_GET['code'], $_GET['attempt_reference'], $job['browser'] );
	}
	if ( 'rest' === $mode ) {
		$server = rest_get_server();
		$error = $server->check_authentication();
		$request = new \WP_REST_Request( $_SERVER['REQUEST_METHOD'], $job['route'] ?? '/wp/v2/users/me' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( json_encode( $job['body'] ?? [] ) );
		if ( ! empty( $job['fixture_route'] ) ) {
			// Real REST dispatch/permission hooks; fixture callback avoids executing
			// payment, import or editor writes merely to test activity classification.
			$route = $request->get_route();
			$namespace = str_starts_with( $route, '/wp/v2/' ) ? 'wp/v2' : explode( '/', trim( $route, '/' ) )[0];
			register_rest_route( $namespace, substr( $route, strlen( $namespace ) + 1 ), [
				'methods' => $request->get_method(), 'callback' => static fn() => [ 'fixture' => true ],
				'permission_callback' => static fn() => current_user_can( 'manage_woocommerce' ),
			], true );
			// Put the literal fixture ahead of installed parameterized routes such
			// as posts/(?P<id>\d+)/autosaves, which otherwise match first.
			add_filter( 'rest_endpoints', static fn( $endpoints ) => [ $route => $endpoints[ $route ] ] + $endpoints );
		}
		$response = is_wp_error( $error ) ? rest_convert_error_to_response( $error ) : $server->dispatch( $request );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request );
		$status = $response->get_status();
	}
	if ( 'ajax' === $mode ) {
		// Exercise WP's actual authenticated/nopriv dispatcher boundary. The
		// fixture callback records authority without executing a production mutation.
		$called = false;
		$action = $_REQUEST['action'] ?? 'bc_phase4b_fixture';
		remove_all_actions( 'wp_ajax_' . $action ); // Only the disposable worker's fixture handler runs.
		add_action( 'wp_ajax_' . $action, static function () use ( &$called ) { $called = current_user_can( 'manage_woocommerce' ); }, PHP_INT_MIN );
		if ( is_user_logged_in() ) { do_action( 'wp_ajax_' . $action ); } // Core's dispatch condition.
		$status = $called ? 200 : 403;
	}
	if ( 'login-button' === $mode ) { ob_start(); do_action( 'login_form' ); $html = ob_get_clean(); }
	if ( 'popup' === $mode ) {
		apply_filters( 'wp_auth_check_load', true, null );
		add_action( 'admin_print_footer_scripts', 'wp_auth_check_html', 5 );
		ob_start(); do_action( 'admin_print_footer_scripts' ); $html = ob_get_clean();
	}
	if ( 'logout' === $mode ) { wp_logout(); }
	if ( 'gate' === $mode ) { HallCommerceGate::handle_request(); }
	$before_save = $wpdb->num_queries;
	SsoSession::save_activity();
	$save_queries = $wpdb->num_queries - $before_save;
	$session_writes = array_values( array_filter( $queries, static fn( $sql ) => str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'session_tokens' ) ) );
	$filter_user = is_int( $result ) ? $result : $id;
	echo json_encode( [
		'user' => get_current_user_id(), 'status' => $status, 'html' => $html, 'http' => $http,
		'routes' => $routes,
		'auth_queries' => $auth_queries, 'save_queries' => $save_queries, 'session_writes' => count( $session_writes ),
		'cookies' => $cookies, 'login_actions' => $login_actions, 'audit_before_cookie' => $audit_before_cookie,
		'result' => is_wp_error( $result ) ? $result->get_error_code() : $result,
		'filters_removed' => 2 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $filter_user, false )
			&& ! isset( apply_filters( 'attach_session_information', [], $filter_user )[ SsoSession::MARKER ] ),
		'session_table_queries' => count( array_filter( $queries, static fn( $sql ) => str_contains( $sql, 'bingo_portal_sso_' ) || str_contains( $sql, 'bingo_portal_user_links' ) ) ),
	] );
}
