<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authoritative current-hall Game Type domain service. */
final class GameTypeService {

	private const CODE_MAX_LENGTH = 64;
	private const NAME_MAX_LENGTH = 160;
	private const CODE_COLLISION_RETRY_LIMIT = 5;

	/** @var callable */
	private $audit_recorder;

	public function __construct( ?callable $audit_recorder = null ) {
		$this->audit_recorder = $audit_recorder ?? [ AuditLog::class, 'record' ];
	}

	/** @return GameTypeResult|\WP_Error */
	public function create( string $name ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		/*
		 * A PORTAL-CONNECTED HALL CANNOT CREATE A HALL-LOCAL GAME TYPE.
		 *
		 * This is the ONE choke point a forged POST cannot go around: every
		 * caller — GameTypeAdmin's form today, anything else tomorrow — reaches
		 * this exact line before a row is written. Hiding the create form in
		 * GameTypeAdmin is a courtesy; this is the enforcement.
		 *
		 * Portal Sync's OWN Game Type writer does not call create() — it inserts
		 * directly through PortalSnapshotTransactionTransport
		 * (`insert_game_type()`), a different, approved transport — so this gate
		 * cannot block synchronization from ever populating a connected hall's
		 * Game Types.
		 */
		if ( self::is_hall_portal_connected() ) {
			return $this->error(
				'game_type_portal_managed',
				'Game Types are managed in Bingo Portal and updated through Portal Sync. This hall cannot create one locally.'
			);
		}

		$canonical_name = $this->canonicalize_name( $name );
		if ( is_wp_error( $canonical_name ) ) {
			return $canonical_name;
		}

		$duplicate = $this->find_by_name( $canonical_name );
		if ( is_wp_error( $duplicate ) ) {
			return $duplicate;
		}
		if ( null !== $duplicate ) {
			return $this->error( 'duplicate_name', 'A Game Type with that name already exists.' );
		}

		$base_code = $this->generate_code( $canonical_name );
		if ( is_wp_error( $base_code ) ) {
			return $base_code;
		}

		global $wpdb;
		$table = Migrations::game_types_table();

		for ( $retry = 0; $retry <= self::CODE_COLLISION_RETRY_LIMIT; ++$retry ) {
			$code = $this->find_available_code( $base_code );
			if ( is_wp_error( $code ) ) {
				return $code;
			}

			$now      = current_time( 'mysql', true );
			$previous = $wpdb->suppress_errors( true );
			$inserted = $wpdb->insert(
				$table,
				[
					'code'        => $code,
					'name'        => $canonical_name,
					'local_name_key' => $canonical_name,
					'status'      => 'active',
					'created_at'  => $now,
					'updated_at'  => $now,
					'archived_at' => null,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s', null ]
			);
			$db_error = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $previous );

			if ( false !== $inserted ) {
				$game_type = $this->read_by_id( (int) $wpdb->insert_id );
				if ( is_wp_error( $game_type ) ) {
					return $game_type;
				}

				$result = new GameTypeResult( 'game_type_created', $game_type );
				return $this->record_audit( $result, 'catalog.game_type.created', $context, null, $game_type );
			}

			if ( $this->is_duplicate_key( $db_error, 'local_name_unique' ) ) {
				return $this->error( 'duplicate_name', 'A Game Type with that name already exists.' );
			}

			if ( $this->is_duplicate_key( $db_error, 'code_unique' ) ) {
				$duplicate = $this->find_by_name( $canonical_name );
				if ( is_wp_error( $duplicate ) ) {
					return $duplicate;
				}
				if ( null !== $duplicate ) {
					return $this->error( 'duplicate_name', 'A Game Type with that name already exists.' );
				}

				continue;
			}

			return $this->persistence_error( $db_error );
		}

		return $this->error( 'code_allocation_conflict', 'The Game Type code could not be allocated after concurrent changes.' );
	}

	/** @return GameTypeResult|\WP_Error */
	public function rename( int $game_type_id, string $name ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( $game_type_id < 1 ) {
			return $this->error( 'invalid_game_type_id', 'The Game Type ID is invalid.' );
		}

		$canonical_name = $this->canonicalize_name( $name );
		if ( is_wp_error( $canonical_name ) ) {
			return $canonical_name;
		}

		$before = $this->read_by_id( $game_type_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( $canonical_name === $before['name'] ) {
			return new GameTypeResult( 'game_type_name_unchanged', $before );
		}

		if ( null === $before['portal_game_type_id'] ) {
			$duplicate = $this->find_by_name( $canonical_name, $game_type_id );
			if ( is_wp_error( $duplicate ) ) {
				return $duplicate;
			}
			if ( null !== $duplicate ) {
				return $this->error( 'duplicate_name', 'A Game Type with that name already exists.' );
			}
		}

		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		// Derive the key from the row's identity in the same statement as the name.
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE %i SET name = %s, local_name_key = CASE WHEN portal_game_type_id IS NULL THEN %s ELSE NULL END, updated_at = %s WHERE game_type_id = %d',
			Migrations::game_types_table(),
			$canonical_name,
			$canonical_name,
			current_time( 'mysql', true ),
			$game_type_id
		) );
		$db_error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous );

		if ( false === $updated ) {
			if ( $this->is_duplicate_key( $db_error, 'local_name_unique' ) ) {
				return $this->error( 'duplicate_name', 'A Game Type with that name already exists.' );
			}

			return $this->persistence_error( $db_error );
		}

		$after = $this->read_by_id( $game_type_id );
		if ( is_wp_error( $after ) ) {
			return $after;
		}

		$result = new GameTypeResult( 'game_type_renamed', $after );
		return $this->record_audit( $result, 'catalog.game_type.renamed', $context, $before, $after );
	}

	/** @return GameTypeResult|\WP_Error */
	public function archive( int $game_type_id ) {
		return $this->change_status( $game_type_id, 'archived' );
	}

	/** @return GameTypeResult|\WP_Error */
	public function restore( int $game_type_id ) {
		return $this->change_status( $game_type_id, 'active' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function getById( int $game_type_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( $game_type_id < 1 ) {
			return $this->error( 'invalid_game_type_id', 'The Game Type ID is invalid.' );
		}

		return $this->read_by_id( $game_type_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function getByCode( string $code ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$code = strtolower( trim( $code ) );
		if ( strlen( $code ) > self::CODE_MAX_LENGTH || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $code ) ) {
			return $this->error( 'invalid_code', 'The Game Type code is invalid.' );
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT game_type_id, portal_game_type_id, code, name, status, created_at, updated_at, archived_at FROM ' . Migrations::game_types_table() . ' WHERE code = %s',
				$code
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->error( 'game_type_not_found', 'The Game Type was not found.' );
		}

		return $this->map_row( $row );
	}

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function listForAdministration( string $status = 'all', int $page = 1, int $per_page = 50 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( ! in_array( $status, [ 'all', 'active', 'archived' ], true ) ) {
			return $this->error( 'invalid_status', 'The Game Type status filter is invalid.' );
		}

		return $this->list_rows( 'all' === $status ? null : $status, $page, $per_page );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function listActiveForPlayProductSelection( int $page = 1, int $per_page = 100 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return $this->list_rows( 'active', $page, $per_page );
	}

	/** @return GameTypeResult|\WP_Error */
	private function change_status( int $game_type_id, string $target_status ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( $game_type_id < 1 ) {
			return $this->error( 'invalid_game_type_id', 'The Game Type ID is invalid.' );
		}

		$before = $this->read_by_id( $game_type_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( $target_status === $before['status'] ) {
			$status = 'active' === $target_status ? 'game_type_already_active' : 'game_type_already_archived';
			return new GameTypeResult( $status, $before );
		}

		$now          = current_time( 'mysql', true );
		$archived_at  = 'archived' === $target_status ? $now : null;
		$action       = 'archived' === $target_status ? 'catalog.game_type.archived' : 'catalog.game_type.restored';
		$result_status = 'archived' === $target_status ? 'game_type_archived' : 'game_type_restored';

		global $wpdb;
		$updated = $wpdb->update(
			Migrations::game_types_table(),
			[
				'status'      => $target_status,
				'updated_at'  => $now,
				'archived_at' => $archived_at,
			],
			[ 'game_type_id' => $game_type_id ],
			[ '%s', '%s', null === $archived_at ? null : '%s' ],
			[ '%d' ]
		);
		if ( false === $updated ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$after = $this->read_by_id( $game_type_id );
		if ( is_wp_error( $after ) ) {
			return $after;
		}

		$result = new GameTypeResult( $result_status, $after );
		return $this->record_audit( $result, $action, $context, $before, $after );
	}

	/**
	 * Whether THIS hall is authoritatively connected to Portal.
	 *
	 * USES THE EXISTING MAPPING AUTHORITY, NOTHING NEW. There is no
	 * "portal managed" option, column or flag anywhere in this codebase, and
	 * this method does not add one — it asks the one place that already knows:
	 * `HallMappingRepository::findActiveByBlogId()` in the Portal Integration
	 * plugin, over the real `bingo_hall_mappings` table. A non-null row means
	 * an ACTIVE mapping exists for this site; that is what "Portal-connected"
	 * means everywhere else in this codebase (see
	 * `PortalSyncAdmin::mapping_state()`, which reads the same call the same
	 * way for its own "linked" display state).
	 *
	 * FAIL-CLOSED ON AN INDETERMINATE READ, FAIL-OPEN ON A STRUCTURALLY ABSENT
	 * PLUGIN. These are different facts and get different defaults:
	 *
	 *   - the Portal Integration plugin not being installed on this network is
	 *     a property of the WHOLE DEPLOYMENT, decided once at setup, not a
	 *     per-request condition — every hall in such a deployment is legacy by
	 *     definition, so this returns false;
	 *   - a `\WP_Error` from the repository is a TRANSIENT, per-request
	 *     uncertainty about ONE specific hall. The risk this gate exists to
	 *     prevent — a hall-local Game Type created in a hall Portal already
	 *     owns Game Types for — is a lasting data-integrity problem, not a
	 *     recoverable availability one, so an indeterminate read is treated AS
	 *     IF the hall were connected, and Game Type creation is refused rather
	 *     than guessed at.
	 *
	 * Public because GameTypeAdmin calls this SAME method to decide whether to
	 * render the create form — the UI-hiding decision and the enforcement
	 * below read one implementation, so they cannot diverge.
	 */
	public static function is_hall_portal_connected(): bool {
		if ( ! class_exists( '\BingoCommerce\PortalIntegration\HallMappingRepository' ) ) {
			return false;
		}

		$repository = new \BingoCommerce\PortalIntegration\HallMappingRepository();
		$mapping    = $repository->findActiveByBlogId( get_current_blog_id() );

		if ( is_wp_error( $mapping ) ) {
			return true;
		}

		return is_array( $mapping );
	}

	/** @return array<string,int|bool>|\WP_Error */
	private function authorize() {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'authentication_required', 'You must be signed in to manage Game Types.' );
		}
		if ( ! HallContext::is_hall() ) {
			return $this->error( 'invalid_hall_context', 'Game Types may be managed only from a hall site.' );
		}

		$actor_id = get_current_user_id();
		$is_super = is_super_admin( $actor_id );
		if ( ! $is_super && ! current_user_can( 'bc_manage_products' ) ) {
			return $this->error( 'game_type_management_denied', 'You are not authorized to manage Game Types for this hall.' );
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'catalog_schema_unavailable', 'The Catalog schema is not ready for this hall.' );
		}

		return [
			'blog_id'       => get_current_blog_id(),
			'actor_user_id' => $actor_id,
			'actor_is_super' => $is_super,
		];
	}

	/** @return string|\WP_Error */
	private function canonicalize_name( string $name ) {
		if (
			! extension_loaded( 'intl' )
			|| ! class_exists( '\Normalizer' )
			|| ! class_exists( '\IntlChar' )
			|| ! function_exists( 'mb_convert_case' )
			|| ! defined( 'MB_CASE_FOLD' )
		) {
			return $this->error( 'unicode_runtime_unavailable', 'The required Unicode runtime is unavailable.' );
		}

		$normalized = \Normalizer::normalize( $name, \Normalizer::FORM_C );
		if ( false === $normalized || 1 === preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $normalized ) ) {
			return $this->error( 'invalid_name', 'Enter a valid Game Type name without control or format characters.' );
		}

		$normalized = preg_replace( '/\p{Z}+/u', ' ', $normalized );
		$normalized = is_string( $normalized ) ? trim( $normalized, " \t\n\r\0\x0B" ) : '';
		$length     = mb_strlen( $normalized, 'UTF-8' );
		if ( 0 === $length ) {
			return $this->error( 'invalid_name', 'Enter a Game Type name.' );
		}
		if ( $length > self::NAME_MAX_LENGTH ) {
			return $this->error( 'name_too_long', 'The Game Type name may not exceed 160 Unicode characters.' );
		}

		return $normalized;
	}

	/** @return string|\WP_Error */
	private function generate_code( string $canonical_name ) {
		$normalized = \Normalizer::normalize( $canonical_name, \Normalizer::FORM_KD );
		if ( false === $normalized ) {
			return $this->error( 'unicode_normalization_failed', 'The Game Type code could not be normalized.' );
		}

		$folded = mb_convert_case( $normalized, MB_CASE_FOLD, 'UTF-8' );
		$chars  = preg_split( '//u', $folded, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return $this->error( 'unicode_normalization_failed', 'The Game Type code could not be normalized.' );
		}

		$parts        = [];
		$ascii_buffer = '';
		$separator    = false;
		$flush_ascii  = static function () use ( &$parts, &$ascii_buffer ): void {
			if ( '' !== $ascii_buffer ) {
				$parts[]       = $ascii_buffer;
				$ascii_buffer = '';
			}
		};

		foreach ( $chars as $char ) {
			if ( 1 === preg_match( '/^\p{M}$/u', $char ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^[a-z0-9]$/D', $char ) ) {
				if ( $separator ) {
					$flush_ascii();
					$separator = false;
				}
				$ascii_buffer .= $char;
				continue;
			}
			if ( 1 === preg_match( '/^[\p{Z}\p{P}\p{S}]$/u', $char ) ) {
				$flush_ascii();
				$separator = true;
				continue;
			}
			if ( 1 === preg_match( '/^[\p{L}\p{N}]$/u', $char ) ) {
				$flush_ascii();
				$parts[]    = 'u' . strtolower( dechex( \IntlChar::ord( $char ) ) );
				$separator = true;
				continue;
			}

			$flush_ascii();
			$separator = true;
		}
		$flush_ascii();

		$code = trim( preg_replace( '/-+/', '-', implode( '-', $parts ) ) ?? '', '-' );
		if ( '' === $code ) {
			$code = 'game-type';
		}

		return rtrim( substr( $code, 0, self::CODE_MAX_LENGTH ), '-' );
	}

	/** @return string|\WP_Error */
	private function find_available_code( string $base_code ) {
		global $wpdb;
		$prefix = substr( $base_code, 0, min( 48, strlen( $base_code ) ) );
		$codes  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT code FROM ' . Migrations::game_types_table() . ' WHERE code = %s OR code LIKE %s',
				$base_code,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		if ( ! is_array( $codes ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$used = array_fill_keys( array_map( 'strval', $codes ), true );
		if ( ! isset( $used[ $base_code ] ) ) {
			return $base_code;
		}

		for ( $suffix = 2; $suffix < PHP_INT_MAX; ++$suffix ) {
			$tail      = '-' . $suffix;
			$base      = rtrim( substr( $base_code, 0, self::CODE_MAX_LENGTH - strlen( $tail ) ), '-' );
			$candidate = $base . $tail;
			if ( ! isset( $used[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return $this->error( 'code_allocation_conflict', 'The Game Type code namespace is exhausted.' );
	}

	/** Local-only duplicate lookup; Portal names are not unique. @return array<string,mixed>|null|\WP_Error */
	private function find_by_name( string $name, int $exclude_id = 0 ) {
		global $wpdb;
		$sql = 'SELECT game_type_id, portal_game_type_id, code, name, status, created_at, updated_at, archived_at FROM ' . Migrations::game_types_table() . ' WHERE portal_game_type_id IS NULL AND local_name_key = %s';
		$args = [ $name ];
		if ( $exclude_id > 0 ) {
			$sql   .= ' AND game_type_id <> %d';
			$args[] = $exclude_id;
		}
		$sql .= ' LIMIT 1';

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return is_array( $row ) ? $this->map_row( $row ) : null;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_by_id( int $game_type_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT game_type_id, portal_game_type_id, code, name, status, created_at, updated_at, archived_at FROM ' . Migrations::game_types_table() . ' WHERE game_type_id = %d',
				$game_type_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->error( 'game_type_not_found', 'The Game Type was not found.' );
		}

		return $this->map_row( $row );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function list_rows( ?string $status, int $page, int $per_page ) {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		global $wpdb;
		$table = Migrations::game_types_table();
		if ( null === $status ) {
			$sql = $wpdb->prepare(
				"SELECT game_type_id, portal_game_type_id, code, name, status, created_at, updated_at, archived_at FROM {$table} ORDER BY name, game_type_id LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT game_type_id, portal_game_type_id, code, name, status, created_at, updated_at, archived_at FROM {$table} WHERE status = %s ORDER BY name, game_type_id LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		return array_map( [ $this, 'map_row' ], $rows );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function map_row( array $row ): array {
		$row['game_type_id'] = (int) $row['game_type_id'];
		$row['portal_game_type_id'] = null === $row['portal_game_type_id'] ? null : (int) $row['portal_game_type_id'];

		return $row;
	}

	/**
	 * @param array<string,int|bool> $context
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed> $after
	 */
	private function record_audit( GameTypeResult $result, string $action, array $context, ?array $before, array $after ): GameTypeResult {
		$event = [
			'blog_id'        => $context['blog_id'],
			'actor_user_id'  => $context['actor_user_id'],
			'action'         => $action,
			'target_type'    => 'game_type',
			'target_id'      => $after['game_type_id'],
			'previous_value' => $before,
			'new_value'      => $after,
			'context'        => [
				'game_type_id' => $after['game_type_id'],
				'code'         => $after['code'],
			],
		];

		$audit_result = call_user_func( $this->audit_recorder, $event );
		if ( is_wp_error( $audit_result ) ) {
			$error_code = $audit_result->get_error_code();
			error_log( sprintf( 'BingoCommerce Catalog audit failure for %s on Game Type %d: %s', $action, $after['game_type_id'], $error_code ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => $error_code,
				'message'    => $audit_result->get_error_message(),
			];
		} elseif ( ! is_int( $audit_result ) || $audit_result < 1 ) {
			error_log( sprintf( 'BingoCommerce Catalog audit returned an invalid result for %s on Game Type %d.', $action, $after['game_type_id'] ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => 'invalid_audit_result',
				'message'    => 'The audit service returned an invalid result.',
			];
		}

		return $result;
	}

	private function is_duplicate_key( string $error, string $key ): bool {
		return false !== stripos( $error, 'duplicate' ) && false !== stripos( $error, $key );
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'The Game Type change could not be persisted.', 'bingocommerce-catalog' ),
			[ 'db_error' => $db_error ]
		);
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-catalog' ), [ 'status' => $code ] );
	}
}
