<?php

namespace BingoCommerce\PortalIntegration;

use BingoCommerce\Core\HallStaffEligibility;
use BingoCommerce\Core\HallStaffIdentity;
use BingoCommerce\Core\HallStaffProvisioningService;
use BingoCommerce\Core\HallStaffSsoGrant;
use BingoCommerce\Core\HallStaffResult;
use BingoCommerce\Core\Roles;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Account/link recovery only; this class cannot create an authenticated session. */
final class SsoProvisioningService {
	private SsoRepository $repository;

	public function __construct() { $this->repository = new SsoRepository(); }

	/** Trusted caller supplies the already-validated exchange identity. @return int|\WP_Error */
	public function provision( array $identity, int $blog, int $hall, string $reference ) {
		if ( ! class_exists( HallStaffProvisioningService::class ) || ! class_exists( HallStaffSsoGrant::class )
			|| $blog !== get_current_blog_id() ) { return self::refuse( 'configuration_unavailable' ); }
		$username = 'portal-' . $identity['installation_id'] . '-' . $identity['portal_user_id'];
		if ( ! HallStaffIdentity::is_portal_sso_username( $username ) ) { return self::refuse( 'invalid_response' ); }
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			$result = HallStaffProvisioningService::with_portal_sso_creation_lock( $username, function () use ( $identity, $username, $blog, $hall, $reference ) {
				// No HTTP occurs under this lock. Re-read after acquiring it, so a
				// second first-login request sees the first request's committed link.
				$mapping = ( new HallMappingRepository() )->findActiveByBlogId( $blog );
				if ( ! is_array( $mapping ) || (int) $mapping['portal_hall_id'] !== $hall || HallCommerceAccess::is_blocked( $blog ) ) {
					return self::refuse( 'mapping_unavailable' );
				}
				return $this->under_lock( $identity, $username, $blog, $reference );
			} );
			// Core errors may carry SQL/reconciliation detail. Never propagate it.
			if ( is_wp_error( $result ) ) {
				if ( ! isset( $result->get_error_data()['message_key'] ) ) { return self::refuse( 'storage_failed' ); }
				$link = $this->repository->link( $identity['installation_id'], $identity['portal_user_id'] );
				$data = $result->get_error_data();
				$data['wp_user_id'] = is_array( $link ) ? (int) $link['wp_user_id'] : 0;
				$result->add_data( $data );
			}
			return $result;
		} catch ( \Throwable $ignored ) { return self::refuse( 'unexpected_failure', true ); }
		finally { $wpdb->suppress_errors( $old ); }
	}

	private function under_lock( array $identity, string $username, int $blog, string $reference ) {
		$link = $this->repository->link( $identity['installation_id'], $identity['portal_user_id'] );
		if ( is_wp_error( $link ) ) { return self::refuse( 'storage_failed' ); }
		if ( null === $link ) {
			$accounts = $this->repository->accounts( $username );
			if ( is_wp_error( $accounts ) ) { return self::refuse( 'storage_failed' ); }
			if ( [] !== $accounts ) { return self::refuse( 'username_occupied', true ); }
			$email = $this->unused_email( $identity['email'] );
			if ( is_wp_error( $email ) ) { return $email; }
			if ( 1 !== $this->repository->create_link( $identity, $username, $blog, $reference ) ) { return self::refuse( 'storage_failed' ); }
			$link = $this->repository->link( $identity['installation_id'], $identity['portal_user_id'] );
			if ( ! is_array( $link ) ) { return self::refuse( 'storage_failed' ); }
		}

		$account = null;
		if ( null !== $link['wp_user_id'] ) {
			$account = $this->repository->account( (int) $link['wp_user_id'] );
			if ( is_wp_error( $account ) ) { return self::refuse( 'storage_failed' ); }
			// ADR-048's explicit per-login order: Super Admin before restore check.
			if ( is_array( $account ) && is_super_admin( (int) $account['ID'] ) ) { return self::refuse( 'super_admin', true ); }
		}
		if ( $link['portal_user_created_at'] !== $identity['portal_user_created_at'] ) { return self::refuse( 'restore_mismatch', true ); }
		if ( $link['planned_username'] !== $username ) { return self::refuse( 'recovery_unproven', true ); }

		if ( null === $link['wp_user_id'] ) {
			$account = $this->finish_creation( $link, $identity );
			if ( is_wp_error( $account ) ) { return $account; }
		} elseif ( ! is_array( $account ) || $account['user_login'] !== $link['planned_username'] ) {
			return self::refuse( 'account_missing', true, 'contact_support' );
		}
		$user = (int) $account['ID'];
		$roles = $this->repository->current_roles( $user, $blog );
		if ( is_wp_error( $roles ) ) { return self::refuse( 'storage_failed' ); }
		if ( [ [ 'meta_value' => serialize( [ Roles::HALL_MANAGER => true ] ) ] ] === $roles ) {
			return true === HallStaffEligibility::assert_operational( $user ) ? $user : self::refuse( 'access_removed', false, 'access_removed' );
		}

		// Always use the original creation facts and the account's own email.
		$creation = HallStaffProvisioningService::record_portal_sso_creation( $user, (int) $link['creation_blog_id'], $link['planned_username'], $link['creation_operation_id'], $link['creation_sso_reference'] );
		if ( is_wp_error( $creation ) ) { return self::refuse( 'creation_audit_failed', true ); }
		if ( [] !== $roles ) { return self::refuse( 'access_removed', false, 'access_removed' ); }
		$grant = HallStaffSsoGrant::grant( $creation, $blog, $reference );
		if ( ! $grant instanceof HallStaffResult || ! in_array( $grant->status, [ HallStaffResult::COMMITTED_VERIFIED, HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS ], true ) ) {
			return self::refuse( 'grant_failed', true );
		}
		return $user;
	}

	private function unused_email( string $email ) {
		if ( strlen( $email ) > 100 || ! is_email( $email ) ) { return self::refuse( 'invalid_email', true ); }
		$match = HallStaffIdentity::resolve_unique( $email );
		if ( is_wp_error( $match ) ) { return self::refuse( 'storage_failed', true ); }
		return null === $match ? true : self::refuse( 'email_conflict', false, 'email_conflict' );
	}

	/** Pending-link recovery; no unrelated existing account is ever adopted. */
	private function finish_creation( array $link, array $identity ) {
		$accounts = $this->repository->accounts( $link['planned_username'] );
		if ( ! is_array( $accounts ) ) { return self::refuse( 'storage_failed' ); }
		$site = get_site( (int) $link['creation_blog_id'] );
		if ( ! $site || (int) $site->network_id !== get_current_network_id() || is_main_site( $site->blog_id ) ) { return self::refuse( 'recovery_unproven', true ); }
		if ( [] === $accounts ) {
			$email = $this->unused_email( $identity['email'] );
			if ( is_wp_error( $email ) ) {
				if ( 'email_conflict' === $email->get_error_code() && 1 !== $this->repository->delete_empty_link( $link ) ) { return self::refuse( 'storage_failed' ); }
				return $email;
			}
			// A retry on another Hall must not leave temporary roles on that Hall.
			switch_to_blog( (int) $link['creation_blog_id'] );
			try {
				$created = SsoAccountGuard::with_planned_username( $link['planned_username'], static fn() => wpmu_create_user( $link['planned_username'], wp_generate_password( 64, true, true ), $identity['email'] ) );
			} finally { restore_current_blog(); }
			$accounts = $this->repository->accounts( $link['planned_username'] );
			if ( ! is_array( $accounts ) ) { return self::refuse( 'storage_failed' ); }
			if ( ! is_int( $created ) || $created < 1 ) {
				// A concurrent ordinary writer may have taken the email. Delete
				// only an empty link with no account bearing our planned username.
				if ( [] === $accounts ) {
					$email = $this->unused_email( $identity['email'] );
					if ( is_wp_error( $email ) && 'email_conflict' === $email->get_error_code() ) {
						return 1 === $this->repository->delete_empty_link( $link ) ? $email : self::refuse( 'storage_failed' );
					}
				}
				return self::refuse( 'creation_failed', true );
			}
		}
		if ( 1 !== count( $accounts ) ) { return self::refuse( 'recovery_unproven', true ); }
		$account = $accounts[0];
		$user = (int) $account['ID'];
		$other_link = $this->repository->linked_user( $user );
		if ( $account['user_login'] !== $link['planned_username'] || $account['user_registered'] < $link['created_at']
			|| null !== $other_link || is_super_admin( $user )
			|| $user !== HallStaffIdentity::resolve_unique( $account['user_email'] ) ) { return self::refuse( 'recovery_unproven', true ); }
		if ( ! $this->clear_temporary_records( $user, (int) $link['creation_blog_id'] ) ) { return self::refuse( 'recovery_unproven', true ); }
		if ( true !== HallStaffEligibility::assert_operational( $user ) || 0 !== HallStaffEligibility::count_memberships( $user ) ) { return self::refuse( 'recovery_unproven', true ); }
		$names = [ 'ID' => $user, 'display_name' => $identity['name'] ];
		if ( $account['user_nicename'] === sanitize_title( $link['planned_username'] ) ) { $names['user_nicename'] = bin2hex( random_bytes( 16 ) ); }
		if ( is_wp_error( wp_update_user( $names ) ) ) { return self::refuse( 'creation_failed', true ); }
		if ( 1 !== $this->repository->attach( $link, $user ) ) { return self::refuse( 'storage_failed' ); }
		return $account;
	}

	/** Remove only proven temporary rows, matching their exact stored values. */
	private function clear_temporary_records( int $user, int $creation_blog ): bool {
		global $wpdb;
		$pattern = '^' . preg_quote( $wpdb->base_prefix, '/' ) . '([1-9][0-9]*_)?(capabilities|user_level)$';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT umeta_id,meta_key,meta_value FROM %i WHERE user_id = %d AND meta_key REGEXP %s', $wpdb->usermeta, $user, $pattern ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { return false; }
		$prefix = $wpdb->get_blog_prefix( $creation_blog );
		$default = (string) get_blog_option( $creation_blog, 'default_role', 'subscriber' );
		switch_to_blog( $creation_blog );
		try {
			$default_role = get_role( $default );
			$level = 0;
			for ( $candidate = 10; $candidate >= 0; --$candidate ) {
				if ( $default_role && $default_role->has_cap( 'level_' . $candidate ) ) { $level = $candidate; break; }
			}
		} finally { restore_current_blog(); }
		$seen = [];
		foreach ( $rows as $row ) {
			$key = $row['meta_key'];
			if ( isset( $seen[ $key ] ) ) { return false; }
			$seen[ $key ] = true;
			if ( $key === $prefix . 'capabilities' ) {
				if ( ! in_array( $row['meta_value'], [ serialize( [] ), serialize( [ $default => true ] ) ], true ) ) { return false; }
			} elseif ( $key !== $prefix . 'user_level' || (string) $level !== $row['meta_value'] ) { return false; }
		}
		foreach ( $rows as $row ) {
			if ( 1 !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE umeta_id = %d AND user_id = %d AND meta_key = %s AND BINARY meta_value = %s', $wpdb->usermeta, $row['umeta_id'], $user, $row['meta_key'], $row['meta_value'] ) ) ) { return false; }
		}
		if ( $rows ) { clean_user_cache( $user ); }
		$left = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND meta_key REGEXP %s', $wpdb->usermeta, $user, $pattern ) );
		return '' === $wpdb->last_error && '0' === (string) $left;
	}

	private static function refuse( string $reason, bool $support = false, string $message = 'general' ): \WP_Error {
		return new \WP_Error( $reason, 'Store SSO could not be completed.', [ 'message_key' => $message, 'support_review' => $support ] );
	}
}
