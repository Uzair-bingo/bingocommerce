<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Existing Phase 1 tables only. All timestamps use WordPress's PHP UTC clock. */
final class SsoRepository {
	/** Suppress raw SQL errors: attempts contain the server's secret proof. */
	private function read( string $sql, bool $many = false ) {
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			$result = $many ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_row( $sql, ARRAY_A );
			return '' !== (string) $wpdb->last_error ? self::failure() : $result;
		} catch ( \Throwable $ignored ) { return self::failure(); }
		finally { $wpdb->suppress_errors( $old ); }
	}

	private function write( string $sql ) {
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			$result = $wpdb->query( $sql );
			return false === $result ? self::failure() : $result;
		} catch ( \Throwable $ignored ) { return self::failure(); }
		finally { $wpdb->suppress_errors( $old ); }
	}

	public function recent_starts( string $ip ) {
		global $wpdb;
		$row = $this->read( $wpdb->prepare( 'SELECT COUNT(*) AS total FROM %i WHERE ip_fingerprint = %s AND created_at > %s', SsoMigrations::attempts_table(), $ip, gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		return is_array( $row ) ? (int) $row['total'] : self::failure();
	}

	public function prune() {
		global $wpdb;
		return $this->write( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s ORDER BY expires_at LIMIT 500', SsoMigrations::attempts_table(), gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public function create_attempt( string $reference, string $browser, string $proof, string $ip, int $blog, string $sso_reference ) {
		global $wpdb;
		if ( ! SsoExchangeValidator::hex( $ip ) ) { return self::failure(); }
		// $ip is already the keyed fingerprint, never a raw address. The prefix
		// plus 51 hex characters fits MySQL's 64-character advisory-lock limit.
		$lock = 'bc:sso:start:' . substr( $ip, 0, 51 );
		$held = false;
		$old = $wpdb->suppress_errors( true );
		try {
			$held = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 1 ) );
			if ( ! $held || '' !== (string) $wpdb->last_error ) { return self::failure(); }
			$count = $this->recent_starts( $ip );
			if ( ! is_int( $count ) || $count >= 10 ) { return self::failure(); }
			return $this->write( $wpdb->prepare(
				'INSERT INTO %i (attempt_reference_hash,browser_secret_fingerprint,secret_proof,ip_fingerprint,blog_id,sso_reference,created_at,expires_at) VALUES (%s,%s,%s,%s,%d,%s,%s,%s)',
				SsoMigrations::attempts_table(), hash( 'sha256', $reference ), hash( 'sha256', $browser ), $proof, $ip, $blog, $sso_reference, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + 300 )
			) );
		} catch ( \Throwable $ignored ) { return self::failure(); }
		finally {
			try {
				if ( $held ) { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
			} finally { $wpdb->suppress_errors( $old ); }
		}
	}

	public function attempt( string $reference, int $blog ) {
		global $wpdb;
		return $this->read( $wpdb->prepare( 'SELECT * FROM %i WHERE attempt_reference_hash = %s AND blog_id = %d AND used_at IS NULL AND expires_at > %s', SsoMigrations::attempts_table(), hash( 'sha256', $reference ), $blog, gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public function consume( string $reference, int $blog ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		return $this->write( $wpdb->prepare( 'UPDATE %i SET used_at = %s WHERE attempt_reference_hash = %s AND blog_id = %d AND used_at IS NULL AND expires_at > %s', SsoMigrations::attempts_table(), $now, hash( 'sha256', $reference ), $blog, $now ) );
	}

	public function link( string $installation, int $person ) {
		global $wpdb;
		return $this->read( $wpdb->prepare( 'SELECT * FROM %i WHERE portal_installation_id = %s AND portal_user_id = %d', SsoMigrations::links_table(), $installation, $person ) );
	}

	public function create_link( array $identity, string $username, int $blog, string $reference ) {
		global $wpdb;
		return $this->write( $wpdb->prepare(
			'INSERT INTO %i (portal_installation_id,portal_user_id,portal_user_created_at,planned_username,creation_blog_id,creation_operation_id,creation_sso_reference,wp_user_id,created_at) VALUES (%s,%d,%s,%s,%d,%s,%s,NULL,%s)',
			SsoMigrations::links_table(), $identity['installation_id'], $identity['portal_user_id'], $identity['portal_user_created_at'], $username, $blog, wp_generate_uuid4(), $reference, gmdate( 'Y-m-d H:i:s' )
		) );
	}

	public function attach( array $link, int $user ) {
		global $wpdb;
		return $this->write( $wpdb->prepare( 'UPDATE %i SET wp_user_id = %d WHERE portal_installation_id = %s AND portal_user_id = %d AND creation_operation_id = %s AND wp_user_id IS NULL', SsoMigrations::links_table(), $user, $link['portal_installation_id'], $link['portal_user_id'], $link['creation_operation_id'] ) );
	}

	public function delete_empty_link( array $link ) {
		global $wpdb;
		return $this->write( $wpdb->prepare( 'DELETE FROM %i WHERE portal_installation_id = %s AND portal_user_id = %d AND creation_operation_id = %s AND wp_user_id IS NULL', SsoMigrations::links_table(), $link['portal_installation_id'], $link['portal_user_id'], $link['creation_operation_id'] ) );
	}

	public function linked_user( int $user ) {
		global $wpdb;
		return $this->read( $wpdb->prepare( 'SELECT wp_user_id FROM %i WHERE wp_user_id = %d', SsoMigrations::links_table(), $user ) );
	}

	public function accounts( string $username ) {
		global $wpdb;
		return $this->read( $wpdb->prepare( 'SELECT ID,user_login,user_email,user_registered,user_nicename FROM %i WHERE user_login = %s', $wpdb->users, $username ), true );
	}

	public function account( int $user ) {
		global $wpdb;
		return $this->read( $wpdb->prepare( 'SELECT ID,user_login,user_email,user_registered,user_nicename FROM %i WHERE ID = %d', $wpdb->users, $user ) );
	}

	public function current_roles( int $user, int $blog ) {
		global $wpdb;
		return $this->read( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $user, $wpdb->get_blog_prefix( $blog ) . 'capabilities' ), true );
	}

	/** No raw query, DB error, or input is attached to a refusal. */
	private static function failure(): \WP_Error {
		return new \WP_Error( 'storage_failed', 'The SSO request could not be completed.' );
	}
}
