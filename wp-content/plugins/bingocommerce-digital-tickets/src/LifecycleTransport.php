<?php

declare(strict_types=1);

namespace BingoCommerce\DigitalTickets;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Fixed safe codes only. Driver messages, SQL and credentials never leave here. */
final class LifecycleFailure extends \RuntimeException {
	public function __construct( string $code = 'verification_unavailable' ) {
		parent::__construct( in_array( $code, [ 'verification_unavailable', 'write_outcome_unknown', 'request_id_conflict', 'already_redeemed' ], true ) ? $code : 'verification_unavailable' );
	}
}

/**
 * ADR-047's fixed captured-session profiles; not a general transaction facility.
 *
 * No BEGIN/COMMIT: acceptance is one autocommit UPDATE of a complete ledger row.
 * Voiding is a durable autocommit marker followed by independent convergence.
 * Every value uses native preparation; no WordPress callbacks execute here.
 */
final class LifecycleTransport {
	private const WAIT_SECONDS = 5;
	private const LOCK_MARGIN_SECONDS = 1;
	private const BUDGET_SECONDS = 25;
	private static ?self $active = null;
	private static bool $refused = false;
	private object $wpdb;
	private \mysqli $connection;
	private int $connection_id;
	private int $site_id;
	private int $network_id;
	private int $actor_id;
	private int $switch_count;
	private string $prefix;
	private string $base_prefix;
	private string $database;
	private string $name;
	private bool $ledger;
	private bool $poisoned = false;
	private bool $closed = false;
	private bool $held = false;
	private bool $acquisition_attempted = false;
	private bool $acquisition_unknown = false;
	private bool $lock_released = true;
	private bool $bounds_installed = false;
	private float $deadline;
	private array $tables = [];
	private array $original_bounds = [];
	private array $current_bounds = [];

	private function __construct( bool $ledger ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof \mysqli || ! defined( 'DB_NAME' ) ) { throw new LifecycleFailure(); }
		$this->wpdb = $wpdb;
		$this->connection = $wpdb->dbh;
		$this->connection_id = $this->connection->thread_id;
		$this->site_id = (int) get_current_blog_id();
		$this->network_id = (int) get_current_network_id();
		$this->actor_id = (int) ( $GLOBALS['current_user']->ID ?? 0 );
		$this->switch_count = (int) ( $GLOBALS['wp_actions']['switch_blog'] ?? 0 );
		$this->prefix = (string) $wpdb->prefix;
		$this->base_prefix = (string) $wpdb->base_prefix;
		$this->database = (string) DB_NAME;
		$this->ledger = $ledger;
		$this->deadline = self::clock() + self::BUDGET_SECONDS;
		$expected = $this->base_prefix . ( $this->site_id > 1 ? $this->site_id . '_' : '' );
		if ( $this->site_id < 1 || $this->network_id < 1 || $this->connection_id < 1 || $this->prefix !== $expected ) { throw new LifecycleFailure(); }
		foreach ( [ 'entitlements' => 'bingo_entitlements', 'voids' => 'bingo_entitlement_voids', 'tickets' => 'bingo_tickets', 'ledger' => 'bingo_redemptions' ] as $key => $suffix ) {
			$name = $this->prefix . $suffix;
			if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $name ) ) { throw new LifecycleFailure(); }
			$this->tables[ $key ] = '`' . $name . '`';
		}
		$this->name = 'bc:dt:' . substr( hash( 'sha256', json_encode( [ $this->database, $this->base_prefix, $this->network_id, $this->site_id ], JSON_THROW_ON_ERROR ) ), 0, 56 );
	}

	/** Capture before callback-capable schema, Woo or Catalog preparation. */
	public static function capture( bool $ledger ): self {
		if ( self::refuse_reentry() ) { throw new LifecycleFailure(); }
		$guard = new self( $ledger );
		self::$active = $guard;
		try {
			$guard->prove( false );
			// Bounds also cover native preliminary lookup and callback preparation.
			$guard->installBounds();
			return $guard;
		} catch ( \Throwable $failure ) {
			$guard->close();
			throw new LifecycleFailure();
		}
	}

	/** Public entries call this before validation can reject a nested attempt. */
	public static function refuse_reentry(): bool {
		if ( null !== self::$active ) { self::$active->poisoned = true; return true; }
		return self::$refused;
	}

	public function acquire(): void {
		if ( $this->acquisition_attempted ) { $this->poisoned = true; throw new LifecycleFailure(); }
		$this->prove( false );
		$this->tightenBounds();
		$this->acquisition_attempted = true;
		// Size the wait before acquisition becomes uncertain: refusing for lack of
		// time dispatches nothing, so cleanup has no lock state left to prove.
		$wait = $this->lockWait();
		$this->acquisition_unknown = true;
		$this->lock_released = false;
		$row = $this->one( 'SELECT GET_LOCK(?,?) AS acquired,CONNECTION_ID() AS connection_id', 'si', [ $this->name, $wait ] );
		if ( null !== $row && '0' === $row['acquired'] ) {
			$this->acquisition_unknown = false;
			$this->lock_released = true;
			throw new LifecycleFailure();
		}
		if ( null === $row || '1' !== $row['acquired'] || (string) $this->connection_id !== $row['connection_id'] ) { throw new LifecycleFailure(); }
		$this->acquisition_unknown = false;
		$this->held = true;
		$this->prove();
		$this->verifySchema();
	}

	/** Prove captured context, writer, no outer transaction and optional lock. */
	public function prove( bool $locked = true ): void {
		$this->context();
		if ( $locked && ! $this->held ) { throw new LifecycleFailure(); }
		$row = $this->one(
			'SELECT DATABASE() AS database_name,CONNECTION_ID() AS connection_id,@@autocommit AS autocommit,'
			. ' @@global.read_only AS read_only,@@global.super_read_only AS super_read_only,@@performance_schema AS ps_enabled,'
			. " (SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME='events_transactions_current' AND ENABLED='YES') AS ps_consumer,"
			. " (SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME='transaction' AND ENABLED='YES') AS ps_instrument,"
			. " COALESCE((SELECT state FROM performance_schema.events_transactions_current WHERE thread_id=PS_CURRENT_THREAD_ID() LIMIT 1),'UNAVAILABLE') AS trx_state,"
			. ' IS_USED_LOCK(?) AS holder,@@SESSION.innodb_lock_wait_timeout AS row_wait,@@SESSION.lock_wait_timeout AS metadata_wait,@@SESSION.max_execution_time AS select_ms',
			's', [ $this->name ]
		);
		if ( null === $row || $this->database !== $row['database_name'] || (string) $this->connection_id !== $row['connection_id']
			|| '1' !== $row['autocommit'] || '0' !== $row['read_only'] || '0' !== $row['super_read_only']
			|| '1' !== $row['ps_enabled'] || '1' !== $row['ps_consumer'] || '1' !== $row['ps_instrument']
			|| ! in_array( $row['trx_state'], [ 'COMMITTED', 'ROLLED BACK', 'NONE' ], true )
			|| ( $locked && (string) $this->connection_id !== $row['holder'] ) ) { $this->poisoned = true; throw new LifecycleFailure(); }
		if ( $this->bounds_installed ) {
			foreach ( [ 'row_wait', 'metadata_wait', 'select_ms' ] as $key ) {
				if ( (string) $this->current_bounds[ $key ] !== $row[ $key ] ) { $this->poisoned = true; throw new LifecycleFailure(); }
			}
		}
	}

	/** Native preliminary location; raw QR credentials never enter WP query hooks. */
	public function locate( string $token ): ?array {
		$this->prove( false );
		$this->tightenBounds();
		return $this->readTicket( $token );
	}

	public function ticket( string $token ): ?array {
		$this->readable();
		return $this->readTicket( $token );
	}

	private function readTicket( string $token ): ?array {
		$row = $this->one( 'SELECT e.*,t.entitlement_id AS ticket_entitlement_id FROM ' . $this->tables['tickets'] . ' t LEFT JOIN '
			. $this->tables['entitlements'] . ' e ON e.entitlement_id=t.entitlement_id WHERE t.token=?', 's', [ $token ] );
		if ( null === $row ) { return null; }
		if ( null === $row['entitlement_id'] || (int) $row['entitlement_id'] < 1 || $row['entitlement_id'] !== $row['ticket_entitlement_id'] ) { throw new LifecycleFailure(); }
		unset( $row['ticket_entitlement_id'] );
		return $row;
	}

	public function marker( int $order ): ?array {
		$this->readable();
		return $this->one( 'SELECT * FROM ' . $this->tables['voids'] . ' WHERE order_id=?', 'i', [ $order ] );
	}

	public function entitlement( int $order ): ?array {
		$this->readable();
		return $this->one( 'SELECT * FROM ' . $this->tables['entitlements'] . ' WHERE order_id=?', 'i', [ $order ] );
	}

	public function request( string $id ): ?array {
		$this->readable( true );
		return $this->one( 'SELECT * FROM ' . $this->tables['ledger'] . ' WHERE request_id=?', 's', [ $id ] );
	}

	public function accepted( int $entitlement ): ?array {
		$this->readable( true );
		$row = $this->one( 'SELECT * FROM ' . $this->tables['ledger'] . ' WHERE accepted_entitlement_id=?', 'i', [ $entitlement ] );
		if ( null !== $row && 'accepted' !== $row['state'] ) { throw new LifecycleFailure(); }
		return $row;
	}

	public function usage( int $session, string $tablet ): array {
		$this->readable( true );
		return $this->rows( 'SELECT contributions_json FROM ' . $this->tables['ledger'] . ' WHERE state=? AND session_id=? AND player_tablet_id=?', 'sis', [ 'accepted', $session, $tablet ] );
	}

	public function claim( array $command, string $intent, string $redemption ): void {
		$this->writable( true );
		$this->write( 'INSERT INTO ' . $this->tables['ledger'] . ' (request_id,intent_hash,player_tablet_id,caller_id,state,redemption_id,created_at) VALUES (?,?,?,?,?,?,?)',
			'sssssss', [ $command['request_id'], $intent, $command['player_tablet_id'], $command['caller_id'], 'pending', $redemption, gmdate( 'Y-m-d H:i:s' ) ] );
	}

	/** Complete response, contribution and binding become durable in one statement. */
	public function accept( string $request, string $intent, int $entitlement, int $session, string $faces, string $response ): void {
		$this->writable( true );
		$this->write( 'UPDATE ' . $this->tables['ledger'] . ' SET state=?,accepted_entitlement_id=?,session_id=?,contributions_json=?,response_json=?,accepted_at=?'
			. ' WHERE request_id=? AND intent_hash=? AND state=? AND accepted_entitlement_id IS NULL',
			'siissssss', [ 'accepted', $entitlement, $session, $faces, $response, gmdate( 'Y-m-d H:i:s' ), $request, $intent, 'pending' ] );
	}

	public function insertMarker( int $order, array $facts, string $cause, int $refund ): void {
		if ( ! in_array( $cause, [ 'package_refund', 'cancellation' ], true ) || $order < 1 || $refund < 0 ) { throw new LifecycleFailure(); }
		$existing = $this->marker( $order );
		if ( null !== $existing ) {
			if ( ! self::matches( $existing, $facts ) ) { throw new LifecycleFailure(); }
			return;
		}
		$this->writable();
		$this->write( 'INSERT INTO ' . $this->tables['voids'] . ' (order_id,session_id,package_id,selection_id,cause,refund_id,voided_at) VALUES (?,?,?,?,?,?,?)',
			'iiissis', [ $order, $facts['session_id'], $facts['package_id'], $facts['selection_id'], $cause, $refund, gmdate( 'Y-m-d H:i:s' ) ] );
		$after = $this->marker( $order );
		if ( null === $after || ! self::matches( $after, $facts ) ) { throw new LifecycleFailure( 'write_outcome_unknown' ); }
	}

	/** Never removes a marker or creates a snapshot; false means no marker exists. */
	public function converge( int $order, array $facts ): bool {
		$marker = $this->marker( $order );
		if ( null === $marker ) { return false; }
		if ( ! self::matches( $marker, $facts ) ) { throw new LifecycleFailure(); }
		$row = $this->entitlement( $order );
		if ( null === $row ) { return true; }
		if ( ! self::matches( $row, $facts ) || ! in_array( $row['status'], [ 'active', 'void' ], true ) ) { throw new LifecycleFailure(); }
		if ( 'void' === $row['status'] ) { return true; }
		$this->writable();
		$this->write( 'UPDATE ' . $this->tables['entitlements'] . ' SET status=? WHERE entitlement_id=? AND order_id=? AND session_id=? AND package_id=? AND selection_id=? AND status=?',
			'siiiiss', [ 'void', (int) $row['entitlement_id'], $order, (int) $row['session_id'], (int) $row['package_id'], $row['selection_id'], 'active' ] );
		$row['status'] = 'void';
		if ( $row !== $this->entitlement( $order ) ) { throw new LifecycleFailure( 'write_outcome_unknown' ); }
		return true;
	}

	private static function matches( array $row, array $facts ): bool {
		foreach ( [ 'session_id', 'package_id', 'selection_id' ] as $key ) {
			if ( ! isset( $facts[ $key ], $row[ $key ] ) || (string) $facts[ $key ] !== $row[ $key ] ) { return false; }
		}
		return true;
	}

	private function readable( bool $ledger = false ): void {
		if ( $ledger && ! $this->ledger ) { throw new LifecycleFailure(); }
		$this->prove();
		$this->tightenBounds();
	}

	private function writable( bool $ledger = false ): void { $this->readable( $ledger ); }

	private function verifySchema(): void {
		$names = [];
		foreach ( $this->tables as $key => $table ) { if ( 'ledger' !== $key || $this->ledger ) { $names[] = trim( $table, '`' ); } }
		$slots = implode( ',', array_fill( 0, count( $names ), '?' ) );
		$rows = $this->rows( 'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $slots . ')', str_repeat( 's', count( $names ) ), $names );
		if ( count( $rows ) !== count( $names ) ) { throw new LifecycleFailure(); }
		foreach ( $rows as $row ) { if ( ! in_array( $row['TABLE_NAME'], $names, true ) || 'InnoDB' !== $row['ENGINE'] ) { throw new LifecycleFailure(); } }
		// Native verification is part of the write boundary. Callback-capable
		// migration readers cannot establish the unique database arbiters for it.
		$expected = [
			'entitlements' => [ 'PRIMARY' => [ [ 'entitlement_id', '0', null ] ], 'order_unique' => [ [ 'order_id', '0', null ] ] ],
			'voids' => [ 'PRIMARY' => [ [ 'order_id', '0', null ] ] ],
			'tickets' => [ 'PRIMARY' => [ [ 'entitlement_id', '0', null ] ], 'token_unique' => [ [ 'token', '0', null ] ] ],
		];
		if ( $this->ledger ) {
			$expected['ledger'] = [
				'PRIMARY' => [ [ 'request_id', '0', null ] ],
				'accepted_entitlement_unique' => [ [ 'accepted_entitlement_id', '0', null ] ],
				'redemption_unique' => [ [ 'redemption_id', '0', null ] ],
				'tablet_session' => [ [ 'state', '1', null ], [ 'session_id', '1', null ], [ 'player_tablet_id', '1', null ] ],
			];
		}
		$indexes = $this->rows( 'SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $slots . ')', str_repeat( 's', count( $names ) ), $names );
		$actual = [];
		foreach ( $indexes as $index ) {
			$actual[ $index['TABLE_NAME'] ][ $index['INDEX_NAME'] ][ (int) $index['SEQ_IN_INDEX'] ] = [ $index['COLUMN_NAME'], $index['NON_UNIQUE'], $index['SUB_PART'] ];
		}
		foreach ( $expected as $key => $required ) {
			$table = trim( $this->tables[ $key ], '`' );
			foreach ( $required as $index => $columns ) {
				$found = $actual[ $table ][ $index ] ?? [];
				ksort( $found );
				if ( array_values( $found ) !== $columns ) { throw new LifecycleFailure(); }
			}
		}
		$bigint = '/\Abigint(?:\(20\))? unsigned\z/';
		$columns = [
			'entitlements' => [
				'entitlement_id' => [ $bigint, 'NO', null, 'auto_increment' ],
				'order_id' => [ $bigint, 'NO', null, '' ],
				'selection_id' => [ '/\Achar\(36\)\z/', 'NO', 'ascii_bin', '' ],
			],
			'voids' => [ 'order_id' => [ $bigint, 'NO', null, '' ], 'selection_id' => [ '/\Achar\(36\)\z/', 'NO', 'ascii_bin', '' ] ],
			'tickets' => [ 'entitlement_id' => [ $bigint, 'NO', null, '' ], 'token' => [ '/\Achar\(64\)\z/', 'NO', 'ascii_bin', '' ] ],
		];
		if ( $this->ledger ) {
			$columns['ledger'] = [
				'request_id' => [ '/\Achar\(36\)\z/', 'NO', 'ascii_bin', '' ],
				'intent_hash' => [ '/\Achar\(64\)\z/', 'NO', 'ascii_bin', '' ],
				'player_tablet_id' => [ '/\Avarbinary\(128\)\z/', 'NO', null, '' ],
				'caller_id' => [ '/\Avarbinary\(191\)\z/', 'NO', null, '' ],
				'accepted_entitlement_id' => [ $bigint, 'YES', null, '' ],
				'session_id' => [ $bigint, 'YES', null, '' ],
				'redemption_id' => [ '/\Achar\(36\)\z/', 'NO', 'ascii_bin', '' ],
				'contributions_json' => [ '/\Alongtext\z/', 'YES', null, '' ],
				'response_json' => [ '/\Alongtext\z/', 'YES', null, '' ],
			];
		}
		$metadata = $this->rows( 'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $slots . ')', str_repeat( 's', count( $names ) ), $names );
		$actual_columns = [];
		foreach ( $metadata as $column ) { $actual_columns[ $column['TABLE_NAME'] ][ $column['COLUMN_NAME'] ] = $column; }
		foreach ( $columns as $key => $required ) {
			foreach ( $required as $name => [ $type, $nullable, $collation, $extra ] ) {
				$column = $actual_columns[ trim( $this->tables[ $key ], '`' ) ][ $name ] ?? null;
				if ( null === $column || 1 !== preg_match( $type, $column['COLUMN_TYPE'] ) || $nullable !== $column['IS_NULLABLE']
					|| $extra !== $column['EXTRA'] || ( null !== $collation && $collation !== $column['COLLATION_NAME'] )
					|| ( str_contains( $type, 'varbinary' ) && null !== $column['COLLATION_NAME'] ) ) { throw new LifecycleFailure(); }
			}
		}
	}

	private function installBounds(): void {
		$row = $this->one( 'SELECT @@SESSION.innodb_lock_wait_timeout AS row_wait,@@SESSION.lock_wait_timeout AS metadata_wait,@@SESSION.max_execution_time AS select_ms' );
		if ( null === $row ) { throw new LifecycleFailure(); }
		foreach ( $row as $key => $value ) {
			if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9]+\z/', $value ) || (string) (int) $value !== $value ) { throw new LifecycleFailure(); }
			$this->original_bounds[ $key ] = (int) $value;
		}
		// Mark restoration necessary before the first setting, even on partial failure.
		$this->bounds_installed = true;
		$this->current_bounds = [ 'row_wait' => self::WAIT_SECONDS, 'metadata_wait' => self::WAIT_SECONDS, 'select_ms' => self::WAIT_SECONDS * 1000 ];
		$this->setBounds( $this->current_bounds );
		$this->prove( false );
	}

	private function tightenBounds(): void {
		// One DML statement may first wait for metadata and then for a row lock.
		// Divide the remaining budget between those distinct waits; SELECT's
		// execution limit is not represented as a DML execution guarantee.
		$seconds = min( self::WAIT_SECONDS, (int) floor( ( $this->deadline - self::clock() ) / 2 ) );
		if ( $seconds < 1 ) { throw new LifecycleFailure(); }
		if ( $seconds < $this->current_bounds['row_wait'] ) {
			$this->current_bounds = [ 'row_wait' => $seconds, 'metadata_wait' => $seconds, 'select_ms' => $seconds * 1000 ];
			$this->setBounds( $this->current_bounds );
		}
	}

	private function setBounds( array $bounds, bool $cleanup = false ): void {
		$this->statement( 'SET SESSION innodb_lock_wait_timeout=?', 'i', [ $bounds['row_wait'] ], false, false, $cleanup );
		$this->statement( 'SET SESSION lock_wait_timeout=?', 'i', [ $bounds['metadata_wait'] ], false, false, $cleanup );
		$this->statement( 'SET SESSION max_execution_time=?', 'i', [ $bounds['select_ms'] ], false, false, $cleanup );
	}

	/**
	 * MySQL applies the session max_execution_time to this SELECT. A cancelled
	 * GET_LOCK is an unknown acquisition that rightly poisons the request, so the
	 * lock's own timeout must end the wait first: keep a full second inside both
	 * the active SELECT bound and the remaining budget, or refuse to wait at all.
	 */
	private function lockWait(): int {
		$seconds = min( self::WAIT_SECONDS, intdiv( $this->current_bounds['select_ms'], 1000 ) - self::LOCK_MARGIN_SECONDS,
			(int) floor( $this->deadline - self::clock() ) - self::LOCK_MARGIN_SECONDS );
		if ( $seconds < 1 ) { throw new LifecycleFailure(); }
		return $seconds;
	}

	private static function clock(): float { return hrtime( true ) / 1000000000; }

	private function context(): void {
		global $wpdb;
		if ( $this->closed || $this->poisoned || self::$refused || self::$active !== $this || self::clock() >= $this->deadline
			|| $wpdb !== $this->wpdb || ! isset( $wpdb->dbh ) || $wpdb->dbh !== $this->connection
			|| get_current_blog_id() !== $this->site_id || get_current_network_id() !== $this->network_id
			|| (int) ( $GLOBALS['current_user']->ID ?? 0 ) !== $this->actor_id
			// Switching away and restoring is still a context change during a
			// callback. Read WordPress's counter directly without adding hooks.
			|| (int) ( $GLOBALS['wp_actions']['switch_blog'] ?? 0 ) !== $this->switch_count
			|| $wpdb->prefix !== $this->prefix || $wpdb->base_prefix !== $this->base_prefix ) { $this->poisoned = true; throw new LifecycleFailure(); }
	}

	/** A failed/unknown cleanup never changes an already acknowledged result. */
	public static function diagnostics_safe(): bool {
		return null === self::$active || ( self::$active->closed && self::$active->lock_released );
	}

	public function close(): void {
		if ( $this->closed ) { return; }
		$clean = true;
		try {
			// Use ONLY the original handle. Never release a lock or change settings
			// on a replacement/reconnected WordPress session.
			$row = $this->one( 'SELECT CONNECTION_ID() AS connection_id,IS_USED_LOCK(?) AS holder', 's', [ $this->name ], true );
			if ( null === $row || (string) $this->connection_id !== $row['connection_id'] ) { throw new LifecycleFailure(); }
			if ( $this->held || $this->acquisition_unknown ) {
				if ( (string) $this->connection_id !== $row['holder'] ) { throw new LifecycleFailure(); }
				$release = $this->one( 'SELECT RELEASE_LOCK(?) AS released', 's', [ $this->name ], true );
				if ( null === $release || '1' !== $release['released'] ) { throw new LifecycleFailure(); }
				$this->lock_released = true;
			}
			$this->held = false;
			$this->acquisition_unknown = false;
		} catch ( \Throwable $ignored ) { $clean = false; }
		// A failed release must not skip restoration on a still-provable original
		// connection. Both failures independently keep this request unavailable.
		try {
			if ( $this->bounds_installed ) {
				$this->setBounds( $this->original_bounds, true );
				$restored = $this->one( 'SELECT @@SESSION.innodb_lock_wait_timeout AS row_wait,@@SESSION.lock_wait_timeout AS metadata_wait,@@SESSION.max_execution_time AS select_ms', '', [], true );
				foreach ( $this->original_bounds as $key => $value ) { if ( null === $restored || (string) $value !== $restored[ $key ] ) { throw new LifecycleFailure(); } }
			}
		} catch ( \Throwable $ignored ) { $clean = false; }
		$this->closed = true;
		if ( $clean ) { if ( self::$active === $this ) { self::$active = null; } }
		else {
			self::$refused = true;
			try { error_log( 'BingoCommerce lifecycle session cleanup could not be verified.' ); } catch ( \Throwable $ignored ) {}
		}
	}

	private function one( string $sql, string $types = '', array $values = [], bool $cleanup = false ): ?array {
		$rows = $this->statement( $sql, $types, $values, true, false, $cleanup );
		if ( count( $rows ) > 1 ) { throw new LifecycleFailure(); }
		return $rows[0] ?? null;
	}

	private function rows( string $sql, string $types = '', array $values = [] ): array { return $this->statement( $sql, $types, $values, true ); }

	private function write( string $sql, string $types, array $values ): void {
		if ( 1 !== $this->statement( $sql, $types, $values, false, true ) ) { throw new LifecycleFailure( 'write_outcome_unknown' ); }
	}

	/** No reconnect, wpdb error output, raw diagnostics, filters or callbacks. */
	private function statement( string $sql, string $types, array $values, bool $rows, bool $write = false, bool $cleanup = false ): array|int {
		$statement = null;
		$executed = false;
		try {
			if ( ! $cleanup ) { $this->context(); }
			if ( $this->connection->thread_id !== $this->connection_id ) { throw new LifecycleFailure(); }
			$statement = $this->connection->prepare( $sql );
			if ( false === $statement || count( $values ) !== strlen( $types ) ) { throw new LifecycleFailure(); }
			if ( '' !== $types && ! $statement->bind_param( $types, ...$values ) ) { throw new LifecycleFailure(); }
			// Any failure after dispatching a write has an uncertain result. Do not
			// parse driver text or infer rollback from the error/connection state.
			$executed = true;
			if ( ! $statement->execute() ) { throw new LifecycleFailure(); }
			if ( ! $rows ) { return $statement->affected_rows; }
			$result = $statement->get_result();
			if ( false === $result ) { throw new LifecycleFailure(); }
			$all = $result->fetch_all( MYSQLI_ASSOC );
			$result->free();
			foreach ( $all as &$row ) { foreach ( $row as &$value ) { if ( null !== $value ) { $value = (string) $value; } } unset( $value ); }
			unset( $row );
			return $all;
		} catch ( \Throwable $ignored ) {
			throw new LifecycleFailure( $write && $executed ? 'write_outcome_unknown' : 'verification_unavailable' );
		} finally {
			if ( $statement instanceof \mysqli_stmt ) { try { $statement->close(); } catch ( \Throwable $ignored ) {} }
		}
	}
}
