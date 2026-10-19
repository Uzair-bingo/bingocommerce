<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 database-backed locks: one import lock per Hall, and one network
 * publish lock.
 *
 * Same MySQL GET_LOCK pattern as PortalSyncLock, with separate names so it
 * never shares or blocks a Portal lock. A lock is taken with a zero timeout:
 * a second click or tab gets "already running" at once instead of waiting.
 *
 * A lock belongs to one database connection and ends when that connection
 * does, so a request that dies releases it. is_still_held() lets a long
 * request confirm it still owns the lock before each write; a lost or
 * uncertain lock means stop.
 */
final class StarterImportLock {

	public const ALREADY_RUNNING = 'starter_import_already_running';
	public const LOCK_FAILED     = 'starter_import_lock_failed';
	public const REENTRANT       = 'starter_import_lock_reentrant';

	private const RELEASE_FAILED_MESSAGE = 'BingoCommerce starter import lock release failed on a live connection.';

	/** Lock names held in this request, so a second acquire is refused here too. */
	private static array $held_names = [];

	private string $name;
	private int $connection_id;
	private bool $held = false;

	private function __construct( string $name, int $connection_id ) {
		$this->name          = $name;
		$this->connection_id = $connection_id;
		$this->held          = true;
	}

	/** @return self|\WP_Error */
	public static function acquire_hall( int $blog_id ) {
		if ( $blog_id < 1 ) {
			return self::error( self::LOCK_FAILED, 'The Hall could not be identified.' );
		}

		return self::acquire( self::lock_name( 'si', $blog_id ) );
	}

	/** @return self|\WP_Error */
	public static function acquire_publish() {
		return self::acquire( self::lock_name( 'sp', (int) get_current_network_id() ) );
	}

	/** Does this connection still own the lock? Any doubt answers false. */
	public function is_still_held(): bool {
		global $wpdb;

		if ( ! $this->held ) {
			return false;
		}

		try {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT IS_USED_LOCK(%s) AS holder, CONNECTION_ID() AS connection_id', $this->name ),
				ARRAY_A
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return false;
		}

		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			return false;
		}

		$holder        = (int) ( $row['holder'] ?? 0 );
		$connection_id = (int) ( $row['connection_id'] ?? 0 );

		return $holder > 0 && $holder === $this->connection_id && $connection_id === $this->connection_id;
	}

	public function release(): void {
		global $wpdb;

		if ( ! $this->held ) {
			return;
		}
		$this->held = false;

		$clear = false;
		try {
			$connection_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			if ( '' !== (string) $wpdb->last_error || $connection_id < 1 ) {
				error_log( self::RELEASE_FAILED_MESSAGE );
				return;
			}
			if ( $connection_id !== $this->connection_id ) {
				// The connection that held the lock is gone, and with it the lock.
				$clear = true;
				return;
			}
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
			if ( 1 === (int) $released && '' === (string) $wpdb->last_error ) {
				$clear = true;
				return;
			}
			error_log( self::RELEASE_FAILED_MESSAGE );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			error_log( self::RELEASE_FAILED_MESSAGE );
		} finally {
			if ( $clear ) {
				unset( self::$held_names[ $this->name ] );
			}
		}
	}

	/** @return self|\WP_Error */
	private static function acquire( string $name ) {
		global $wpdb;

		if ( isset( self::$held_names[ $name ] ) ) {
			return self::error( self::REENTRANT, 'The starter import is already running in this request.' );
		}

		self::$held_names[ $name ] = true;
		$acquired                  = false;
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT GET_LOCK(%s, 0) AS acquired, CONNECTION_ID() AS connection_id', $name ),
				ARRAY_A
			);
			if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
				return self::error( self::LOCK_FAILED, 'The starter import lock could not be taken.' );
			}
			if ( 1 !== (int) ( $row['acquired'] ?? 0 ) ) {
				return self::error( self::ALREADY_RUNNING, 'The starter import is already running. Please wait a moment.' );
			}

			$connection_id = (int) ( $row['connection_id'] ?? 0 );
			if ( $connection_id < 1 ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );

				return self::error( self::LOCK_FAILED, 'The starter import lock could not be confirmed.' );
			}

			$acquired = true;

			return new self( $name, $connection_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( self::LOCK_FAILED, 'The starter import lock could not be taken.' );
		} finally {
			if ( ! $acquired ) {
				unset( self::$held_names[ $name ] );
			}
		}
	}

	/**
	 * GET_LOCK names are shared by every database on the MySQL server, so the
	 * name includes this installation's database, table prefix and network.
	 * At most 6 + 32 + 1 + 20 characters, inside MySQL's 64-character limit.
	 */
	private static function lock_name( string $kind, int $id ): string {
		global $wpdb;

		$discriminator = hash( 'sha256', DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id() );

		return 'bc:' . $kind . ':' . substr( $discriminator, 0, 32 ) . ':' . $id;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-catalog' ) );
	}
}
