<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-025's purpose-built transaction boundary for Hall Staff mutations.
 *
 * This class deliberately accepts no callable. It owns every statement that
 * may execute between BEGIN and COMMIT/ROLLBACK, using one captured mysqli
 * session for exact role DML and AuditLog's mandatory insert.
 */
final class HallStaffTransactionTransport {

	public const NOT_COMMITTED_VERIFIED = 'NOT_COMMITTED_VERIFIED';
	public const OUTCOME_UNKNOWN        = 'OUTCOME_UNKNOWN';
	public const CONSISTENCY_VIOLATION  = 'CONSISTENCY_VIOLATION';

	private const LOCK_TIMEOUT_SECONDS = 5;
	private const PREFLIGHT_WITNESS_READ_LIMIT = 1;
	private const RECONCILIATION_WITNESS_READ_LIMIT = 2;

	private \mysqli $connection;
	private int $connection_id;
	private string $database_name;
	private string $users_table;
	private string $usermeta_table;
	private string $sitemeta_table;
	private string $options_table;
	private string $capability_key;
	private string $user_level_key;
	private string $roles_option_name;
	private bool $lock_acquired = false;
	private string $lock_name = '';
	private int $preflight_witness_reads = 0;
	private int $reconciliation_witness_reads = 0;

	/**
	 * Execute or reconcile one canonical Hall Staff operation.
	 *
	 * @return string|\WP_Error One HallStaffResult success status, or failure.
	 */
	public static function execute( HallStaffIntent $intent, array $audit_event, bool $is_retry ) {
		$transport = new self();
		$captured  = $transport->capture( $intent );

		if ( is_wp_error( $captured ) ) {
			return $captured;
		}

		return $transport->run( $intent, $audit_event, $is_retry );
	}

	/** @return true|\WP_Error */
	private function capture( HallStaffIntent $intent ) {
		global $wpdb;

		if ( ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof \mysqli ) {
			return $this->failure( 'incompatible_database_handle', 'Hall Staff mutations require the approved mysqli transport.', self::OUTCOME_UNKNOWN );
		}

		$this->connection       = $wpdb->dbh;
		$this->users_table      = self::quote_identifier( $wpdb->users );
		$this->usermeta_table   = self::quote_identifier( $wpdb->usermeta );
		$this->sitemeta_table   = self::quote_identifier( $wpdb->sitemeta );
		$this->options_table    = self::quote_identifier( $wpdb->get_blog_prefix( $intent->blog_id ) . 'options' );
		$this->capability_key   = $wpdb->get_blog_prefix( $intent->blog_id ) . 'capabilities';
		$this->user_level_key   = $wpdb->get_blog_prefix( $intent->blog_id ) . 'user_level';
		$this->roles_option_name = $wpdb->get_blog_prefix( $intent->blog_id ) . 'user_roles';

		$identity = $this->connection_identity( $this->connection );

		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		if ( 1 === $identity['in_transaction'] ) {
			return $this->failure( 'transaction_already_open', 'Hall Staff cannot enter an existing database transaction.', self::OUTCOME_UNKNOWN );
		}

		if ( DB_NAME !== $identity['database_name'] || $identity['read_only'] || $identity['super_read_only'] ) {
			return $this->failure( 'authoritative_writer_unavailable', 'The authoritative database writer is unavailable.', self::OUTCOME_UNKNOWN );
		}

		$this->connection_id = $identity['connection_id'];
		$this->database_name = $identity['database_name'];

		$discriminator = hash(
			'sha256',
			$this->database_name . '|' . $wpdb->base_prefix . '|' . $intent->network_id
		);
		$this->lock_name = 'bc:hs:' . substr( $discriminator, 0, 32 ) . ':' . $intent->blog_id;

		return true;
	}

	/** @return string|\WP_Error */
	private function run( HallStaffIntent $intent, array $audit_event, bool $is_retry ) {
		$lock = $this->acquire_lock();

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			if ( $is_retry ) {
				$witness = $this->read_preflight_witness( $intent->operation_id );

				if ( is_wp_error( $witness ) ) {
					return $this->failure( 'witness_read_failed', 'The operation witness could not be read.', self::OUTCOME_UNKNOWN );
				}

				if ( is_array( $witness ) ) {
					return $intent->matches_witness( $witness )
						? HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS
						: $this->failure( 'operation_identity_conflict', 'The operation ID is bound to different immutable intent.', self::CONSISTENCY_VIOLATION );
				}
			}

			$prepared_audit = AuditLog::prepare_transactional_event( $audit_event, $intent->operation_id );
			if ( is_wp_error( $prepared_audit ) ) {
				return $prepared_audit;
			}

			$started = $this->begin();

			if ( is_wp_error( $started ) ) {
				return $started;
			}

			$state = $this->lock_and_validate_state( $intent );

			if ( is_wp_error( $state ) ) {
				if ( ! $this->rollback() ) {
					$this->disconnect_uncertain_session();
				}
				return $state;
			}

			$mutated = $this->mutate( $intent, $state );

			if ( is_wp_error( $mutated ) ) {
				if ( ! $this->rollback() ) {
					$this->disconnect_uncertain_session();
				}
				return $this->reconcile_failure( $intent, $prepared_audit, $mutated );
			}

			$audit_id = AuditLog::insert_transactional( $prepared_audit, $this->connection );

			if ( is_wp_error( $audit_id ) ) {
				if ( ! $this->rollback() ) {
					$this->disconnect_uncertain_session();
				}
				return $this->reconcile_failure( $intent, $prepared_audit, $audit_id );
			}

			$committed = $this->commit();
			if ( is_wp_error( $committed ) ) {
				// Never read a witness through a session that may still expose its
				// own uncommitted INSERT. Closing proves rollback if COMMIT did not
				// land, while an already-landed COMMIT remains durable for the
				// authoritative reconnect to observe.
				$this->disconnect_uncertain_session();
			}

			// Acknowledged and ambiguous COMMIT use the exact same witness
			// authority. Current role state is never consulted here.
			return $this->reconcile( $intent, $prepared_audit, is_wp_error( $committed ) ? 'commit_failed' : null );
		} finally {
			$this->release_lock();
		}
	}

	/** @return true|\WP_Error */
	private function acquire_lock() {
		$result = $this->select_one( 'SELECT GET_LOCK(?, ?)', 'si', [ $this->lock_name, self::LOCK_TIMEOUT_SECONDS ] );

		if ( is_wp_error( $result ) ) {
			return $this->failure( 'hall_staff_lock_failed', 'The Hall Staff lock could not be acquired.', self::OUTCOME_UNKNOWN );
		}

		$value = array_values( $result )[0] ?? null;

		if ( 1 !== (int) $value ) {
			return $this->failure( 'hall_staff_lock_timeout', 'Hall Staff is busy for this hall. Retry shortly.', self::NOT_COMMITTED_VERIFIED );
		}

		$this->lock_acquired = true;
		return true;
	}

	private function release_lock(): void {
		if ( ! $this->lock_acquired ) {
			return;
		}

		$identity = $this->connection_identity( $this->connection );

		if ( is_wp_error( $identity ) || $identity['connection_id'] !== $this->connection_id ) {
			// A lost MySQL session releases its advisory locks automatically.
			$this->lock_acquired = false;
			return;
		}

		$released = $this->select_one( 'SELECT RELEASE_LOCK(?)', 's', [ $this->lock_name ] );

		if ( is_wp_error( $released ) || 1 !== (int) ( array_values( $released )[0] ?? null ) ) {
			error_log( 'BingoCommerce Hall Staff advisory lock release failed on a live connection.' );
		}

		$this->lock_acquired = false;
	}

	/** @return true|\WP_Error */
	private function begin() {
		$isolation = $this->execute_without_result( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );

		if ( is_wp_error( $isolation ) ) {
			return $this->failure( 'transaction_start_failed', 'The transaction isolation level could not be set.', self::NOT_COMMITTED_VERIFIED );
		}

		try {
			return $this->connection->begin_transaction()
				? true
				: $this->failure( 'transaction_start_failed', 'The Hall Staff transaction could not start.', self::NOT_COMMITTED_VERIFIED );
		} catch ( \Throwable $throwable ) {
			return $this->failure( 'transaction_start_failed', 'The Hall Staff transaction could not start.', self::NOT_COMMITTED_VERIFIED );
		}
	}

	private function rollback(): bool {
		try {
			return $this->connection->rollback();
		} catch ( \Throwable $throwable ) {
			return false;
		}
	}

	private function disconnect_uncertain_session(): void {
		global $wpdb;

		try {
			$this->connection->close();
		} catch ( \Throwable $throwable ) {
			// A dead/closed session has already released locks and rolled back
			// any uncommitted work.
		}

		$this->lock_acquired = false;
		if ( isset( $wpdb->dbh ) && $wpdb->dbh === $this->connection ) {
			$wpdb->dbh = null;
		}
	}

	/** @return true|\WP_Error */
	private function commit() {
		try {
			return $this->connection->commit()
				? true
				: new \WP_Error( 'commit_failed', 'The Hall Staff commit acknowledgement failed.' );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'commit_failed', 'The Hall Staff commit acknowledgement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/**
	 * Lock only the exact authoritative rows needed by this operation.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function lock_and_validate_state( HallStaffIntent $intent ) {
		$user_ids = array_values( array_unique( [ $intent->actor_user_id, $intent->target_user_id ] ) );
		sort( $user_ids, SORT_NUMERIC );
		$users = $this->select_all(
			"SELECT ID, user_login FROM {$this->users_table} WHERE ID IN (" . self::placeholders( count( $user_ids ) ) . ') ORDER BY ID FOR UPDATE',
			str_repeat( 'i', count( $user_ids ) ),
			$user_ids
		);

		if ( is_wp_error( $users ) || count( $users ) !== count( $user_ids ) ) {
			return $this->failure( 'target_not_eligible', 'The requested Hall Staff target is not eligible.', self::NOT_COMMITTED_VERIFIED );
		}

		$user_logins = [];
		foreach ( $users as $user ) {
			$user_logins[ (int) $user['ID'] ] = (string) $user['user_login'];
		}

		$site_admin_row = $this->select_one(
			"SELECT meta_value FROM {$this->sitemeta_table} WHERE site_id = ? AND meta_key = ? FOR UPDATE",
			'is',
			[ $intent->network_id, 'site_admins' ]
		);
		$roles_row = $this->select_one(
			"SELECT option_value FROM {$this->options_table} WHERE option_name = ? FOR UPDATE",
			's',
			[ $this->roles_option_name ]
		);

		if ( is_wp_error( $site_admin_row ) || is_wp_error( $roles_row ) || [] === $roles_row ) {
			return $this->failure( 'authorization_state_unavailable', 'Authoritative authorization state is unavailable.', self::CONSISTENCY_VIOLATION );
		}

		$super_admins = [] === $site_admin_row ? [] : self::safe_unserialize( $site_admin_row['meta_value'] ?? '' );
		$roles        = self::safe_unserialize( $roles_row['option_value'] ?? '' );

		if ( ! is_array( $super_admins ) || ! is_array( $roles ) ) {
			return $this->failure( 'authorization_state_invalid', 'Authoritative authorization state is invalid.', self::CONSISTENCY_VIOLATION );
		}

		$actor_is_super  = in_array( $user_logins[ $intent->actor_user_id ], $super_admins, true );
		$target_is_super = in_array( $user_logins[ $intent->target_user_id ], $super_admins, true );

		$manager_caps = $roles[ Roles::HALL_MANAGER ]['capabilities'] ?? null;
		if ( ! is_array( $manager_caps ) || empty( $manager_caps['bc_manage_hall_staff'] ) ) {
			return $this->failure( 'authorization_state_invalid', 'The Hall Manager authorization bundle is invalid.', self::CONSISTENCY_VIOLATION );
		}

		$meta = $this->select_all(
			"SELECT umeta_id, user_id, meta_key, meta_value FROM {$this->usermeta_table} WHERE (user_id IN (" . self::placeholders( count( $user_ids ) ) . ') AND meta_key = ?) OR (user_id = ? AND meta_key = ?) ORDER BY umeta_id',
			str_repeat( 'i', count( $user_ids ) ) . 'sis',
			array_merge( $user_ids, [ $this->capability_key, $intent->target_user_id, $this->user_level_key ] )
		);

		$manager_value = serialize( [ Roles::HALL_MANAGER => true ] );
		$manager_rows  = $this->select_all(
			"SELECT umeta_id, user_id, meta_key, meta_value FROM {$this->usermeta_table} WHERE meta_key = ? AND meta_value = ? ORDER BY umeta_id",
			'ss',
			[ $this->capability_key, $manager_value ]
		);

		if ( is_wp_error( $meta ) || is_wp_error( $manager_rows ) ) {
			return $this->failure( 'staff_state_unavailable', 'Authoritative Hall Staff state could not be read.', self::OUTCOME_UNKNOWN );
		}

		$meta_ids = [];
		foreach ( array_merge( $meta, $manager_rows ) as $row ) {
			$meta_ids[] = (int) $row['umeta_id'];
		}
		$meta_ids = array_values( array_unique( $meta_ids ) );
		sort( $meta_ids, SORT_NUMERIC );

		$locked_meta = [];
		if ( $meta_ids ) {
			$locked_meta = $this->select_all(
				"SELECT umeta_id, user_id, meta_key, meta_value FROM {$this->usermeta_table} WHERE umeta_id IN (" . self::placeholders( count( $meta_ids ) ) . ') ORDER BY umeta_id FOR UPDATE',
				str_repeat( 'i', count( $meta_ids ) ),
				$meta_ids
			);

			if ( is_wp_error( $locked_meta ) || count( $locked_meta ) !== count( $meta_ids ) ) {
				return $this->failure( 'staff_state_changed', 'Hall Staff state changed while rows were being locked.', self::CONSISTENCY_VIOLATION );
			}
		}

		$actor_caps = [];
		$target_caps = [];
		$target_levels = [];
		$hall_managers = [];

		foreach ( $locked_meta as $row ) {
			$user_id = (int) $row['user_id'];
			$key     = (string) $row['meta_key'];
			$value   = (string) $row['meta_value'];

			if ( $this->capability_key === $key && $intent->actor_user_id === $user_id ) {
				$actor_caps[] = $row;
			}
			if ( $this->capability_key === $key && $intent->target_user_id === $user_id ) {
				$target_caps[] = $row;
			}
			if ( $this->user_level_key === $key && $intent->target_user_id === $user_id ) {
				$target_levels[] = $row;
			}
			if ( $this->capability_key === $key && $manager_value === $value ) {
				$hall_managers[ $user_id ] = (int) $row['umeta_id'];
			}
		}

		if ( count( $actor_caps ) > 1 || count( $target_caps ) > 1 || count( $target_levels ) > 1 ) {
			return $this->failure( 'duplicate_membership_rows', 'Duplicate Hall Staff membership rows violate the canonical state model.', self::CONSISTENCY_VIOLATION );
		}

		if ( ! $actor_is_super ) {
			$actor_value = $actor_caps[0]['meta_value'] ?? null;
			if ( $manager_value !== $actor_value ) {
				return $this->failure( 'actor_not_authorized', 'The current actor is no longer authorized to manage this hall.', self::NOT_COMMITTED_VERIFIED );
			}
		}

		if ( in_array( $intent->action, [ 'hall_staff.assigned', 'hall_staff.role_changed' ], true ) && $target_is_super ) {
			return $actor_is_super
				? $this->failure( 'super_admin_target_denied', 'Protected hall roles cannot be assigned to or changed on a Super Admin.', self::NOT_COMMITTED_VERIFIED )
				: $this->failure( 'target_not_eligible', 'The requested Hall Staff target is not eligible.', self::NOT_COMMITTED_VERIFIED );
		}

		if ( 'hall_staff.removed' === $intent->action && $target_is_super && ! $actor_is_super ) {
			return $this->failure( 'target_not_eligible', 'The requested Hall Staff target is not eligible.', self::NOT_COMMITTED_VERIFIED );
		}

		if (
			! $actor_is_super
			&& $intent->actor_user_id === $intent->target_user_id
			&& in_array( $intent->action, [ 'hall_staff.role_changed', 'hall_staff.removed' ], true )
		) {
			return $this->failure( 'self_change_denied', 'A Hall Manager cannot change or remove their own hall role.', self::NOT_COMMITTED_VERIFIED );
		}

		$current_role = null;
		if ( $target_caps ) {
			$current_role = self::exact_protected_role( (string) $target_caps[0]['meta_value'] );
			if ( false === $current_role ) {
				return $actor_is_super
					? $this->failure( 'role_conflict', 'The target has a non-canonical role or capability state on this hall.', self::NOT_COMMITTED_VERIFIED )
					: $this->failure( 'target_not_eligible', 'The requested Hall Staff target is not eligible.', self::NOT_COMMITTED_VERIFIED );
			}
		}

		if ( $current_role !== $intent->before_role ) {
			return $this->failure( 'state_conflict', 'Current Hall Staff state no longer matches the operation intent.', self::NOT_COMMITTED_VERIFIED );
		}

		$override_exercised = $actor_is_super
			&& (
				( 'hall_staff.removed' === $intent->action && $target_is_super )
				|| (
					$intent->actor_user_id === $intent->target_user_id
					&& in_array( $intent->action, [ 'hall_staff.role_changed', 'hall_staff.removed' ], true )
				)
				|| (
					Roles::HALL_MANAGER === $intent->before_role
					&& Roles::HALL_MANAGER !== $intent->after_role
					&& count( $hall_managers ) <= 1
				)
			);

		if ( $intent->safeguard_override !== $override_exercised ) {
			return $this->failure( 'state_conflict', 'Authoritative safeguard state no longer matches the signed operation intent.', self::NOT_COMMITTED_VERIFIED );
		}

		if (
			Roles::HALL_MANAGER === $intent->before_role
			&& Roles::HALL_MANAGER !== $intent->after_role
			&& ! $actor_is_super
			&& count( $hall_managers ) <= 1
		) {
			return $this->failure( 'last_hall_manager', 'The last Hall Manager cannot be demoted or removed.', self::NOT_COMMITTED_VERIFIED );
		}

		return [
			'target_capability_id' => $target_caps ? (int) $target_caps[0]['umeta_id'] : null,
			'target_level_id'      => $target_levels ? (int) $target_levels[0]['umeta_id'] : null,
		];
	}

	/** @param array<string,mixed> $state @return true|\WP_Error */
	private function mutate( HallStaffIntent $intent, array $state ) {
		$capability_id = $state['target_capability_id'];
		$level_id      = $state['target_level_id'];

		if ( 'hall_staff.removed' === $intent->action ) {
			if ( null === $capability_id ) {
				return new \WP_Error( 'state_conflict', 'The target membership no longer exists.' );
			}

			$deleted = $this->execute_affected(
				"DELETE FROM {$this->usermeta_table} WHERE umeta_id = ?",
				'i',
				[ $capability_id ],
				1
			);
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}

			if ( null !== $level_id ) {
				$deleted = $this->execute_affected(
					"DELETE FROM {$this->usermeta_table} WHERE umeta_id = ?",
					'i',
					[ $level_id ],
					1
				);
				if ( is_wp_error( $deleted ) ) {
					return $deleted;
				}
			}

			return true;
		}

		$capability_value = serialize( [ $intent->after_role => true ] );
		$level_value      = (string) self::role_level( $intent->after_role );

		if ( null === $capability_id ) {
			$inserted = $this->execute_affected(
				"INSERT INTO {$this->usermeta_table} (user_id, meta_key, meta_value) VALUES (?, ?, ?)",
				'iss',
				[ $intent->target_user_id, $this->capability_key, $capability_value ],
				1
			);
		} else {
			$inserted = $this->execute_affected(
				"UPDATE {$this->usermeta_table} SET meta_value = ? WHERE umeta_id = ?",
				'si',
				[ $capability_value, $capability_id ],
				1
			);
		}

		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		if ( null === $level_id ) {
			return $this->execute_affected(
				"INSERT INTO {$this->usermeta_table} (user_id, meta_key, meta_value) VALUES (?, ?, ?)",
				'iss',
				[ $intent->target_user_id, $this->user_level_key, $level_value ],
				1
			);
		}

		return $this->execute_affected(
			"UPDATE {$this->usermeta_table} SET meta_value = ? WHERE umeta_id = ?",
			'si',
			[ $level_value, $level_id ],
			1,
			true
		);
	}

	/** @return string|\WP_Error */
	private function reconcile_failure( HallStaffIntent $intent, PreparedAuditEvent $audit_event, \WP_Error $cause ) {
		$result = $this->reconcile( $intent, $audit_event, $cause->get_error_code() );

		if ( HallStaffResult::COMMITTED_VERIFIED === $result ) {
			return $result;
		}

		if ( HallStaffResult::IDEMPOTENT_REPLAY_SUCCESS === $result ) {
			return $result;
		}

		return $result;
	}

	/** @return string|\WP_Error */
	private function reconcile( HallStaffIntent $intent, PreparedAuditEvent $audit_event, ?string $cause ) {
		$witness = $this->read_reconciliation_witness( $intent->operation_id );

		if ( is_wp_error( $witness ) ) {
			if ( $this->reconciliation_witness_reads >= self::RECONCILIATION_WITNESS_READ_LIMIT ) {
				return $this->failure( 'outcome_unknown', 'The operation outcome could not be verified.', self::OUTCOME_UNKNOWN, $intent, $cause );
			}

			$reconnected = $this->reconnect_authoritative_writer();
			if ( is_wp_error( $reconnected ) ) {
				return $this->failure( 'outcome_unknown', 'The operation outcome could not be verified.', self::OUTCOME_UNKNOWN, $intent, $cause );
			}
			$witness = $this->read_reconciliation_witness( $intent->operation_id );
		}

		if ( is_wp_error( $witness ) ) {
			return $this->failure( 'outcome_unknown', 'The operation outcome could not be verified.', self::OUTCOME_UNKNOWN, $intent, $cause );
		}

		if ( null === $witness ) {
			return $this->failure( $cause ?: 'not_committed', 'The operation is verified as not committed.', self::NOT_COMMITTED_VERIFIED, $intent, $cause );
		}

		if ( ! $audit_event->matches_witness( $witness ) ) {
			return $this->failure( 'consistency_violation', 'The operation witness contradicts the immutable operation intent.', self::CONSISTENCY_VIOLATION, $intent, $cause );
		}

		return HallStaffResult::COMMITTED_VERIFIED;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function read_preflight_witness( string $operation_id ) {
		if ( $this->preflight_witness_reads >= self::PREFLIGHT_WITNESS_READ_LIMIT ) {
			return new \WP_Error( 'witness_read_limit', 'The preflight witness read limit was reached.' );
		}

		++$this->preflight_witness_reads;
		return AuditLog::read_operation_witness( $this->connection, $operation_id );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function read_reconciliation_witness( string $operation_id ) {
		if ( $this->reconciliation_witness_reads >= self::RECONCILIATION_WITNESS_READ_LIMIT ) {
			return new \WP_Error( 'witness_read_limit', 'The reconciliation witness read limit was reached.' );
		}

		++$this->reconciliation_witness_reads;
		return AuditLog::read_operation_witness( $this->connection, $operation_id );
	}

	/** @return true|\WP_Error */
	private function reconnect_authoritative_writer() {
		global $wpdb;

		if ( ! $wpdb->check_connection( false ) || ! $wpdb->dbh instanceof \mysqli ) {
			return $this->failure( 'authoritative_writer_unavailable', 'The authoritative writer could not be reconnected.', self::OUTCOME_UNKNOWN );
		}

		$identity = $this->connection_identity( $wpdb->dbh );
		if (
			is_wp_error( $identity )
			|| $this->database_name !== $identity['database_name']
			|| $identity['read_only']
			|| $identity['super_read_only']
		) {
			return $this->failure( 'authoritative_writer_unavailable', 'The reconnected database is not the authoritative writer.', self::OUTCOME_UNKNOWN );
		}

		$this->connection = $wpdb->dbh;
		return true;
	}

	/** @return array{connection_id:int,database_name:string,in_transaction:int,read_only:bool,super_read_only:bool}|\WP_Error */
	private function connection_identity( \mysqli $connection ) {
		$original = $this->connection ?? null;
		$this->connection = $connection;
		$row = $this->select_one(
			'SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name, @@global.read_only AS read_only, @@global.super_read_only AS super_read_only, EXISTS(SELECT 1 FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()) AS in_transaction'
		);
		if ( $original instanceof \mysqli ) {
			$this->connection = $original;
		}

		if ( is_wp_error( $row ) || [] === $row ) {
			return new \WP_Error( 'database_identity_failed', 'The database connection identity could not be verified.' );
		}

		return [
			'connection_id'  => (int) $row['connection_id'],
			'database_name'  => (string) $row['database_name'],
			'in_transaction' => (int) $row['in_transaction'],
			'read_only'      => (bool) $row['read_only'],
			'super_read_only' => (bool) $row['super_read_only'],
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	private function select_one( string $sql, string $types = '', array $params = [] ) {
		$rows = $this->select_all( $sql, $types, $params );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return $rows[0] ?? [];
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function select_all( string $sql, string $types = '', array $params = [] ) {
		try {
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement ) {
				return new \WP_Error( 'mysqli_prepare_failed', 'A required database statement could not be prepared.', [ 'db_errno' => $this->connection->errno ] );
			}

			$bound = self::bind( $statement, $types, $params );
			if ( is_wp_error( $bound ) ) {
				$statement->close();
				return $bound;
			}

			if ( ! $statement->execute() ) {
				$error = new \WP_Error( 'mysqli_execute_failed', 'A required database statement failed.', [ 'db_errno' => $statement->errno ] );
				$statement->close();
				return $error;
			}

			$result = $statement->get_result();
			if ( false === $result ) {
				$error = new \WP_Error( 'mysqli_result_failed', 'A required database result could not be read.', [ 'db_errno' => $statement->errno ] );
				$statement->close();
				return $error;
			}

			$rows = $result->fetch_all( MYSQLI_ASSOC );
			$result->free();
			$statement->close();
			return $rows;
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'mysqli_execute_failed', 'A required database statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/** @return true|\WP_Error */
	private function execute_without_result( string $sql, string $types = '', array $params = [] ) {
		try {
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement ) {
				return new \WP_Error( 'mysqli_prepare_failed', 'A required database statement could not be prepared.', [ 'db_errno' => $this->connection->errno ] );
			}
			$bound = self::bind( $statement, $types, $params );
			if ( is_wp_error( $bound ) ) {
				$statement->close();
				return $bound;
			}
			$success = $statement->execute();
			$error   = $statement->errno;
			$statement->close();
			return $success ? true : new \WP_Error( 'mysqli_execute_failed', 'A required database statement failed.', [ 'db_errno' => $error ] );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'mysqli_execute_failed', 'A required database statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/** @return true|\WP_Error */
	private function execute_affected( string $sql, string $types, array $params, int $expected, bool $allow_unchanged = false ) {
		try {
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement ) {
				return new \WP_Error( 'mutation_prepare_failed', 'A Hall Staff mutation statement could not be prepared.', [ 'db_errno' => $this->connection->errno ] );
			}
			$bound = self::bind( $statement, $types, $params );
			if ( is_wp_error( $bound ) ) {
				$statement->close();
				return $bound;
			}
			if ( ! $statement->execute() ) {
				$error = new \WP_Error( 'mutation_failed', 'A Hall Staff mutation statement failed.', [ 'db_errno' => $statement->errno ] );
				$statement->close();
				return $error;
			}
			$affected = $statement->affected_rows;
			$statement->close();
			if ( $expected !== $affected && ! ( $allow_unchanged && 0 === $affected ) ) {
				return new \WP_Error( 'mutation_row_count_mismatch', 'A Hall Staff mutation affected an unexpected number of rows.' );
			}
			return true;
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'mutation_failed', 'A Hall Staff mutation statement failed.', [ 'db_errno' => (int) $throwable->getCode() ] );
		}
	}

	/** @return true|\WP_Error */
	private static function bind( \mysqli_stmt $statement, string $types, array $params ) {
		if ( '' === $types ) {
			return [] === $params ? true : new \WP_Error( 'mysqli_bind_failed', 'Database parameter metadata is invalid.' );
		}

		if ( strlen( $types ) !== count( $params ) ) {
			return new \WP_Error( 'mysqli_bind_failed', 'Database parameter metadata is invalid.' );
		}

		$references = [];
		foreach ( $params as $key => $value ) {
			$params[ $key ]     = $value;
			$references[ $key ] =& $params[ $key ];
		}

		return $statement->bind_param( $types, ...$references )
			? true
			: new \WP_Error( 'mysqli_bind_failed', 'Database parameters could not be bound.' );
	}

	/** @return string|false|null */
	private static function exact_protected_role( string $serialized ) {
		$value = self::safe_unserialize( $serialized );
		if ( ! is_array( $value ) || 1 !== count( $value ) ) {
			return false;
		}
		$role = array_key_first( $value );
		return true === $value[ $role ] && in_array( $role, [ Roles::HALL_MANAGER, Roles::GAME_OPERATOR ], true ) ? $role : false;
	}

	/** @return mixed */
	private static function safe_unserialize( string $serialized ) {
		if ( 'N;' === $serialized ) {
			return null;
		}

		$value = @unserialize( $serialized, [ 'allowed_classes' => false ] );
		return false === $value && 'b:0;' !== $serialized ? false : $value;
	}

	private static function role_level( ?string $role ): int {
		return Roles::HALL_MANAGER === $role ? 9 : 0;
	}

	private static function placeholders( int $count ): string {
		return implode( ',', array_fill( 0, $count, '?' ) );
	}

	private static function quote_identifier( string $identifier ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $identifier ) ) {
			throw new \InvalidArgumentException( 'Unsafe database identifier.' );
		}

		return '`' . $identifier . '`';
	}

	private function failure(
		string $code,
		string $message,
		string $status,
		?HallStaffIntent $intent = null,
		?string $cause = null
	): \WP_Error {
		$data = [ 'status' => $status ];
		if ( null !== $intent ) {
			$data['operation_id'] = $intent->operation_id;
		}
		if ( null !== $cause ) {
			$data['cause'] = $cause;
		}

		return new \WP_Error( $code, $message, $data );
	}
}
