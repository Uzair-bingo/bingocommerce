<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Defense-in-depth controls for supported WordPress mutation paths. */
final class HallStaffWriterGuard {
	/** @var array<string,true> Capability/user-level pairs blocked in this request. */
	private static array $blocked_pairs = [];

	public static function register(): void {
		add_filter( 'editable_roles', [ self::class, 'remove_protected_roles' ], PHP_INT_MAX );
		add_filter( 'map_meta_cap', [ self::class, 'deny_native_user_mutation' ], PHP_INT_MAX, 4 );
		add_filter( 'add_user_metadata', [ self::class, 'guard_add_metadata' ], PHP_INT_MAX, 5 );
		add_filter( 'update_user_metadata', [ self::class, 'guard_update_metadata' ], PHP_INT_MAX, 5 );
		add_filter( 'delete_user_metadata', [ self::class, 'guard_delete_metadata' ], PHP_INT_MAX, 5 );
		add_filter( 'update_user_metadata_by_mid', [ self::class, 'guard_update_by_mid' ], PHP_INT_MAX, 4 );
		add_filter( 'delete_user_metadata_by_mid', [ self::class, 'guard_delete_by_mid' ], PHP_INT_MAX, 2 );
	}

	/** @param array<string,array<string,mixed>> $roles @return array<string,array<string,mixed>> */
	public static function remove_protected_roles( array $roles ): array {
		unset( $roles[ Roles::HALL_MANAGER ], $roles[ Roles::GAME_OPERATOR ] );
		return $roles;
	}

	/** @param array<int,string> $caps @param array<int,mixed> $args @return array<int,string> */
	public static function deny_native_user_mutation( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, [ 'promote_user', 'remove_user' ], true ) || empty( $args[0] ) ) {
			return $caps;
		}

		if ( self::user_has_protected_role( (int) $args[0], get_current_blog_id() ) ) {
			self::report_attempt( 'native_' . $cap, (int) $args[0] );
			return [ 'do_not_allow' ];
		}

		return $caps;
	}

	/** @param mixed $check @param mixed $value @return mixed */
	public static function guard_add_metadata( $check, int $user_id, string $key, $value, bool $unique ) {
		unset( $unique );
		return self::should_block( $user_id, $key, $value ) ? self::blocked( 'add_metadata', $user_id ) : $check;
	}

	/** @param mixed $check @param mixed $value @param mixed $previous @return mixed */
	public static function guard_update_metadata( $check, int $user_id, string $key, $value, $previous ) {
		unset( $previous );
		return self::should_block( $user_id, $key, $value ) ? self::blocked( 'update_metadata', $user_id ) : $check;
	}

	/** @param mixed $check @param mixed $value @return mixed */
	public static function guard_delete_metadata( $check, int $user_id, string $key, $value, bool $delete_all ) {
		unset( $value );
		if ( ! self::is_protected_meta_key( $key ) ) {
			return $check;
		}

		if ( $delete_all || self::should_block_delete( $user_id, $key ) ) {
			return self::blocked( 'delete_metadata', $user_id );
		}

		return $check;
	}

	/** @param mixed $check @param mixed $value @param string|false $key @return mixed */
	public static function guard_update_by_mid( $check, int $meta_id, $value, $key ) {
		$meta = get_metadata_by_mid( 'user', $meta_id );
		if ( ! $meta ) {
			return $check;
		}

		$effective_key         = false === $key ? (string) $meta->meta_key : (string) $key;
		$original_key          = (string) $meta->meta_key;
		$original_is_protected = self::is_protected_meta_key( $original_key )
			&& self::should_block_delete( (int) $meta->user_id, $original_key );

		return ( $original_is_protected || self::should_block( (int) $meta->user_id, $effective_key, $value ) )
			? self::blocked( 'update_metadata_by_mid', (int) $meta->user_id )
			: $check;
	}

	/** @param mixed $check @return mixed */
	public static function guard_delete_by_mid( $check, int $meta_id ) {
		$meta = get_metadata_by_mid( 'user', $meta_id );
		if ( ! $meta || ! self::is_protected_meta_key( (string) $meta->meta_key ) ) {
			return $check;
		}

		return self::should_block_delete( (int) $meta->user_id, (string) $meta->meta_key )
			? self::blocked( 'delete_metadata_by_mid', (int) $meta->user_id )
			: $check;
	}

	/** @param mixed $value */
	private static function should_block( int $user_id, string $key, $value ): bool {
		if ( ! self::is_protected_meta_key( $key ) ) {
			return false;
		}

		if ( str_ends_with( $key, 'capabilities' ) ) {
			$block = self::value_has_protected_role( $value ) || self::user_has_protected_role_for_key( $user_id, $key );
			if ( $block ) {
				self::remember_blocked_pair( $user_id, $key );
			}

			return $block;
		}

		$capability_key = self::capability_key_from_level_key( $key );
		return self::pair_was_blocked( $user_id, $capability_key )
			|| self::user_has_protected_role_for_key( $user_id, $capability_key );
	}

	private static function should_block_delete( int $user_id, string $key ): bool {
		$capability_key = self::capability_key_from_level_key( $key );
		$block = self::pair_was_blocked( $user_id, $capability_key )
			|| self::user_has_protected_role_for_key( $user_id, $capability_key );

		if ( $block && str_ends_with( $key, 'capabilities' ) ) {
			self::remember_blocked_pair( $user_id, $capability_key );
		}

		return $block;
	}

	private static function is_protected_meta_key( string $key ): bool {
		global $wpdb;

		$prefix = preg_quote( $wpdb->base_prefix, '/' );
		return 1 === preg_match( '/^' . $prefix . '(?:[1-9][0-9]*_)?(?:capabilities|user_level)$/D', $key );
	}

	private static function capability_key_from_level_key( string $key ): string {
		return str_ends_with( $key, 'user_level' )
			? substr( $key, 0, -strlen( 'user_level' ) ) . 'capabilities'
			: $key;
	}

	private static function remember_blocked_pair( int $user_id, string $capability_key ): void {
		self::$blocked_pairs[ $user_id . ':' . $capability_key ] = true;
	}

	private static function pair_was_blocked( int $user_id, string $capability_key ): bool {
		return isset( self::$blocked_pairs[ $user_id . ':' . $capability_key ] );
	}

	/** @param mixed $value */
	private static function value_has_protected_role( $value ): bool {
		$value = maybe_unserialize( $value );
		return is_array( $value ) && (
			! empty( $value[ Roles::HALL_MANAGER ] )
			|| ! empty( $value[ Roles::GAME_OPERATOR ] )
		);
	}

	private static function user_has_protected_role( int $user_id, int $blog_id ): bool {
		global $wpdb;
		return self::user_has_protected_role_for_key( $user_id, $wpdb->get_blog_prefix( $blog_id ) . 'capabilities' );
	}

	private static function user_has_protected_role_for_key( int $user_id, string $capability_key ): bool {
		$values = get_user_meta( $user_id, $capability_key, false );
		foreach ( $values as $value ) {
			if ( self::value_has_protected_role( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function blocked( string $channel, int $target_user_id ): bool {
		self::report_attempt( $channel, $target_user_id );
		return false;
	}

	private static function report_attempt( string $channel, int $target_user_id ): void {
		error_log(
			sprintf(
				'BingoCommerce blocked unsupported Hall Staff writer channel=%s blog_id=%d target_user_id=%d.',
				sanitize_key( $channel ),
				get_current_blog_id(),
				$target_user_id
			)
		);
	}
}
