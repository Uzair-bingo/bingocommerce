<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ADR-048 account boundaries; independent of Hall, schema and SSO settings. */
final class SsoAccountGuard {

	private static ?string $planned_username = null;
	private static bool $creation_scope_open = false;

	/** Register at plugin include time, including REST, AJAX and XML-RPC. */
	public static function register(): void {
		add_filter( 'wp_pre_insert_user_data', [ self::class, 'reserve_username' ], PHP_INT_MAX, 2 );
		add_filter( 'authenticate', [ self::class, 'deny_credentials' ], PHP_INT_MAX );
		add_filter( 'allow_password_reset', [ self::class, 'allow_password_reset' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', [ self::class, 'allow_application_passwords' ], PHP_INT_MAX, 2 );
		// Existing reset keys reach completion without asking allow_password_reset again.
		add_action( 'validate_password_reset', [ self::class, 'validate_password_reset' ], PHP_INT_MAX, 2 );
		add_filter( 'show_password_fields', [ self::class, 'show_password_fields' ], PHP_INT_MAX, 2 );
	}

	public static function is_reserved_username( string $username ): bool {
		return 1 === preg_match( '/^portal-[a-z0-9]{1,20}-[1-9][0-9]*$/iD', $username );
	}

	/** @param mixed $user */
	public static function is_portal_user( $user ): bool {
		return $user instanceof \WP_User && self::is_reserved_username( (string) $user->user_login );
	}

	/**
	 * One in-process insertion of one exact planned identity. No request input,
	 * setting or persistent flag can enable this exception. The later SSO
	 * provisioning caller must first prove the Portal identity and save its link.
	 * The allowance is consumed before insertion hooks can create another user.
	 *
	 * @return mixed|\WP_Error
	 */
	public static function with_planned_username( string $username, callable $operation ) {
		if ( self::$creation_scope_open || 1 !== preg_match( '/^portal-[a-z0-9]{1,20}-[1-9][0-9]*$/D', $username ) || strlen( $username ) > 60 ) {
			return new \WP_Error( 'portal_sso_invalid_creation_scope', __( 'Invalid Portal account creation scope.', 'bingocommerce-portal-integration' ) );
		}

		self::$creation_scope_open = true;
		self::$planned_username   = $username;
		try {
			return $operation();
		} finally {
			self::$planned_username   = null;
			self::$creation_scope_open = false;
		}
	}

	/** @param mixed $data @return mixed */
	public static function reserve_username( $data, bool $update ) {
		if ( $update || ! is_array( $data ) || ! self::is_reserved_username( (string) ( $data['user_login'] ?? '' ) ) ) {
			return $data;
		}
		if ( null !== self::$planned_username && self::$planned_username === $data['user_login'] ) {
			self::$planned_username = null;
			return $data;
		}

		// wp_insert_user converts empty data to WP_Error before writing a row.
		return [];
	}

	/** @param mixed $user @return mixed */
	public static function deny_credentials( $user ) {
		return self::is_portal_user( $user ) ? self::error() : $user;
	}

	/** @param bool|\WP_Error $allow @return bool|\WP_Error */
	public static function allow_password_reset( $allow, int $user_id ) {
		return self::is_portal_user( get_userdata( $user_id ) ) ? false : $allow;
	}

	public static function allow_application_passwords( bool $allow, \WP_User $user ): bool {
		return self::is_portal_user( $user ) ? false : $allow;
	}

	/** @param \WP_User|\WP_Error $user */
	public static function validate_password_reset( \WP_Error $errors, $user ): void {
		if ( self::is_portal_user( $user ) ) {
			$errors->add( 'portal_sso_credentials_disabled', self::message() );
		}
	}

	public static function show_password_fields( bool $show, \WP_User $user ): bool {
		return get_current_user_id() === (int) $user->ID && self::is_portal_user( $user ) ? false : $show;
	}

	public static function error(): \WP_Error {
		return new \WP_Error( 'portal_sso_credentials_disabled', self::message(), [ 'status' => 403 ] );
	}

	public static function message(): string {
		return __( 'This account signs in through the Portal. Other credentials are disabled.', 'bingocommerce-portal-integration' );
	}
}
