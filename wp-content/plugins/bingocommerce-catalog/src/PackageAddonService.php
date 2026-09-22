<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ADR-044 current-Hall authority. No UI, candidate discovery, price prefill or bulk API. */
final class PackageAddonService {
	public const RELATION_META = 'bc_package_relation';
	private const PROJECTION_ATTEMPTS = 3;
	private const COLUMNS = 'package_addon_id, package_id, bingo_play_product_id, unit_price, max_quantity, enabled, sort_order, created_at, updated_at';
	/** @var callable */
	private $audit_recorder;

	public function __construct( ?callable $audit_recorder = null ) {
		$this->audit_recorder = $audit_recorder ?? [ AuditLog::class, 'record' ];
	}

	/** @return array<int,array<string,mixed>>|\WP_Error All configured rows, not candidates. */
	public function listForPackage( int $package_id ) {
		$context = $this->authorize();
		if ( $context instanceof \WP_Error ) { return $context; }
		$package = $this->package( $package_id );
		if ( $package instanceof \WP_Error ) { return $package; }
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE package_id = %d ORDER BY sort_order, package_addon_id', Migrations::package_addons_table(), $package_id ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { return $this->read_error(); }
		$result = [];
		foreach ( $rows as $row ) {
			if ( ! CatalogPackageAddonPlan::validRelationship( $row ) || (int) $row['package_id'] !== $package_id ) { return $this->inconsistent(); }
			$product = $this->play_product( (int) $row['bingo_play_product_id'] );
			$mapped = $this->map_row( $row );
			$mapped['package_status'] = $package['status'];
			if ( $product instanceof \WP_Error ) {
				if ( 'play_product_not_found' !== $product->get_error_code() ) { return $product; }
				$mapped['play_product_status'] = null;
				$mapped['medium'] = null;
				$mapped['medium_compatible'] = false;
				$mapped['eligible'] = false;
				$mapped['resolution_error'] = 'play_product_not_found';
				$result[] = $mapped;
				continue;
			}
			$mapped['play_product_status'] = $product['status'];
			$mapped['medium'] = $product['medium'];
			$mapped['medium_compatible'] = CatalogPackageAddonPlan::compatible( $package['package_type'], $product['medium'] );
			$mapped['eligible'] = 'active' === $package['status'] && 'active' === $product['status'] && 1 === $mapped['enabled'] && $mapped['medium_compatible'];
			$mapped['resolution_error'] = null;
			$result[] = $mapped;
		}
		return $result;
	}

	/** Raw mixed boundaries prevent non-strict PHP callers from silently coercing floats. */
	public function create( int $package_id, int $bingo_play_product_id, mixed $unit_price, mixed $max_quantity = 1 ) {
		$context = $this->authorize();
		if ( $context instanceof \WP_Error ) { return $context; }
		$values = $this->validate_values( $unit_price, $max_quantity );
		if ( $values instanceof \WP_Error ) { return $values; }
		if ( $package_id < 1 ) { return $this->error( 'invalid_package_id', 'The Bingo Package ID is invalid.' ); }
		if ( $bingo_play_product_id < 1 ) { return $this->error( 'invalid_play_product_id', 'The Bingo Play Product ID is invalid.' ); }
		// Submitted canonical price only: never consult WooCommerce before BEGIN.
		$plan = CatalogPackageAddonPlan::forCreate( $context['blog_id'], $package_id, $bingo_play_product_id, $unit_price, $max_quantity, current_time( 'mysql', true ) );
		$outcome = CatalogTransactionTransport::createPackageAddon( $plan );
		return $outcome instanceof \WP_Error ? $outcome : $this->settle( $outcome, $plan, $context );
	}

	public function update( int $package_addon_id, string $expected_fingerprint, mixed $unit_price, mixed $max_quantity, bool $enabled ) {
		$context = $this->authorize();
		if ( $context instanceof \WP_Error ) { return $context; }
		$values = $this->validate_values( $unit_price, $max_quantity );
		if ( $values instanceof \WP_Error ) { return $values; }
		$row = $this->relationship( $package_addon_id );
		if ( $row instanceof \WP_Error ) { return $row; }
		// Advisory identity read only. The transport rechecks parents, row and token under locks.
		$plan = CatalogPackageAddonPlan::forUpdate( $context['blog_id'], $package_addon_id, (int) $row['package_id'], (int) $row['bingo_play_product_id'], $expected_fingerprint, $unit_price, $max_quantity, $enabled, current_time( 'mysql', true ) );
		$outcome = CatalogTransactionTransport::updatePackageAddon( $plan );
		return $outcome instanceof \WP_Error ? $outcome : $this->settle( $outcome, $plan, $context );
	}

	public function remove( int $package_addon_id, string $expected_fingerprint ) {
		$context = $this->authorize();
		if ( $context instanceof \WP_Error ) { return $context; }
		$row = $this->relationship( $package_addon_id );
		if ( $row instanceof \WP_Error ) { return $row; }
		$plan = CatalogPackageAddonPlan::forRemove( $context['blog_id'], $package_addon_id, (int) $row['package_id'], (int) $row['bingo_play_product_id'], $expected_fingerprint, current_time( 'mysql', true ) );
		$outcome = CatalogTransactionTransport::removePackageAddon( $plan );
		return $outcome instanceof \WP_Error ? $outcome : $this->settle( $outcome, $plan, $context );
	}

	/** Targeted, current-Hall Catalog identity only; never accepts a WooCommerce product ID. */
	public function reconcileProjection( int $bingo_play_product_id ) {
		$context = $this->authorize();
		if ( $context instanceof \WP_Error ) { return $context; }
		$product = $this->play_product( $bingo_play_product_id );
		if ( $product instanceof \WP_Error ) { return $product; }
		$projection = $this->project( $bingo_play_product_id, $context['blog_id'] );
		if ( $projection instanceof \WP_Error ) { return $projection; }
		return new PackageAddonResult( $projection ? 'package_addon_projection_synchronized' : 'package_addon_projection_unchanged', [ 'bingo_play_product_id' => $bingo_play_product_id ] );
	}

	private function validate_values( mixed $unit_price, mixed $max_quantity ) {
		if ( ! is_string( $unit_price ) || 1 !== preg_match( CatalogPackageAddonPlan::PRICE_PATTERN, $unit_price ) ) {
			return $this->error( 'package_addon_price_invalid', 'The add-on price must be a canonical non-negative two-decimal string.' );
		}
		if ( ! is_int( $max_quantity ) || $max_quantity < 1 || $max_quantity > 99 ) {
			return $this->error( 'package_addon_max_quantity_invalid', 'The add-on maximum quantity must be an integer from 1 through 99.' );
		}
		return true;
	}

	private function authorize() {
		if ( ! is_user_logged_in() ) { return $this->error( 'authentication_required', 'You must be signed in to manage Package add-ons.' ); }
		if ( ! HallContext::is_hall() ) { return $this->error( 'invalid_hall_context', 'Package add-ons may be managed only from a hall site.' ); }
		$actor = get_current_user_id();
		if ( ! is_super_admin( $actor ) && ! current_user_can( 'bc_manage_products' ) ) {
			return $this->error( 'package_addon_management_denied', 'You are not authorized to manage Package add-ons for this hall.' );
		}
		if ( ! Migrations::is_current() ) { return $this->error( 'catalog_schema_unavailable', 'The Catalog schema is not ready for this hall.' ); }
		return [ 'blog_id' => get_current_blog_id(), 'actor_user_id' => $actor ];
	}

	private function package( int $id ) {
		if ( $id < 1 ) { return $this->error( 'invalid_package_id', 'The Bingo Package ID is invalid.' ); }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT package_id, wc_product_id, package_type, status FROM %i WHERE package_id = %d', Migrations::packages_table(), $id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return $this->read_error(); }
		if ( ! is_array( $row ) ) { return $this->error( 'package_not_found', 'The Bingo Package was not found.' ); }
		return CatalogPackageAddonPlan::validPackage( $row ) ? $row : $this->inconsistent();
	}

	private function play_product( int $id ) {
		if ( $id < 1 ) { return $this->error( 'invalid_play_product_id', 'The Bingo Play Product ID is invalid.' ); }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT bingo_play_product_id, wc_product_id, medium, faces_per_unit, game_type_id, status FROM %i WHERE bingo_play_product_id = %d', Migrations::play_products_table(), $id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return $this->read_error(); }
		if ( ! is_array( $row ) ) { return $this->error( 'play_product_not_found', 'The Bingo Play Product was not found.' ); }
		return CatalogPackageAddonPlan::validPlayProduct( $row ) ? $row : $this->inconsistent();
	}

	private function relationship( int $id, bool $allow_missing = false ) {
		if ( $id < 1 ) { return $this->error( 'invalid_package_addon_id', 'The Package add-on ID is invalid.' ); }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM %i WHERE package_addon_id = %d', Migrations::package_addons_table(), $id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) { return $this->read_error(); }
		if ( ! is_array( $row ) ) { return $allow_missing ? null : $this->error( 'package_addon_not_found', 'The Package add-on relationship was not found.' ); }
		return CatalogPackageAddonPlan::validRelationship( $row ) ? $row : $this->inconsistent();
	}

	private function map_row( array $row ): array {
		$row['fingerprint'] = CatalogPackageAddonPlan::fingerprint( $row );
		foreach ( [ 'package_addon_id', 'package_id', 'bingo_play_product_id', 'max_quantity', 'enabled', 'sort_order' ] as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	/** Only confirmed outcomes arrive here. Audit uses locked before/after, not a later writer's row. */
	private function settle( CatalogWriteOutcome $outcome, CatalogPackageAddonPlan $plan, array $context ): PackageAddonResult {
		$before = $outcome->before_row['before'];
		$after = $outcome->before_row['after'];
		if ( ! $outcome->mutated ) {
			return new PackageAddonResult( 'package_addon_unchanged', $this->map_row( $after ) );
		}
		$verb = match ( $plan->mutation ) {
			CatalogPackageAddonPlan::CREATE => 'created',
			CatalogPackageAddonPlan::UPDATE => 'updated',
			CatalogPackageAddonPlan::REMOVE => 'removed',
		};
		$result = new PackageAddonResult( 'package_addon_' . $verb, $this->map_row( $after ?? $before ) );
		$confirmed = $this->relationship( $outcome->bingo_play_product_id, true );
		if ( $confirmed instanceof \WP_Error || ( null === $after ? null !== $confirmed : null === $confirmed ) ) {
			$result->warnings[] = [ 'code' => 'state_read_failed', 'message' => 'The Catalog change committed, but its current state could not be confirmed.' ];
		}
		$projection = $this->project( $plan->bingo_play_product_id, $context['blog_id'] );
		if ( $projection instanceof \WP_Error ) {
			$result->warnings[] = [ 'code' => 'projection_reconciliation_required', 'message' => $projection->get_error_message() ];
		}
		$event = [
			'blog_id' => $context['blog_id'], 'actor_user_id' => $context['actor_user_id'],
			'action' => 'catalog.package_addon.' . $verb, 'target_type' => 'bingo_package_addon',
			'target_id' => $outcome->bingo_play_product_id,
			'previous_value' => null === $before ? null : $this->audit_values( $before ),
			'new_value' => null === $after ? null : $this->audit_values( $after ),
			'context' => [ 'package_id' => $plan->package_id, 'bingo_play_product_id' => $plan->bingo_play_product_id ],
		];
		try {
			$audit = call_user_func( $this->audit_recorder, $event );
			if ( $audit instanceof \WP_Error || ! is_int( $audit ) || $audit < 1 ) {
				$result->warnings[] = [ 'code' => 'audit_write_failed', 'audit_code' => $audit instanceof \WP_Error ? (string) $audit->get_error_code() : 'invalid_audit_result', 'message' => 'The Catalog change committed, but its audit record could not be written.' ];
			}
		} catch ( \Throwable $error ) {
			$result->warnings[] = [ 'code' => 'audit_write_failed', 'message' => 'The Catalog change committed, but its audit record could not be written.' ];
		}
		return $result;
	}

	private function audit_values( array $row ): array {
		$row = $this->map_row( $row );
		unset( $row['fingerprint'] );
		return $row;
	}

	/** bool = whether synchronization wrote metadata; error = targeted retry required. */
	private function project( int $bingo_play_product_id, int $blog_id ) {
		$wrote = false;
		try {
			for ( $attempt = 0; $attempt < self::PROJECTION_ATTEMPTS; ++$attempt ) {
				if ( get_current_blog_id() !== $blog_id || ! function_exists( 'wc_get_product' ) ) { return $this->projection_error(); }
				$row = $this->play_product( $bingo_play_product_id );
				if ( $row instanceof \WP_Error ) { return $this->projection_error(); }
				$expected = $this->expected_projection( $bingo_play_product_id );
				if ( $expected instanceof \WP_Error ) { return $this->projection_error(); }
				$product = \wc_get_product( (int) $row['wc_product_id'] );
				if ( ! $product instanceof \WC_Product || get_current_blog_id() !== $blog_id ) { return $this->projection_error(); }
				global $wpdb;
				$product->read_meta_data( true );
				if ( '' !== $wpdb->last_error ) { return $this->projection_error(); }
				if ( $expected !== $product->get_meta( self::RELATION_META, true, 'edit' ) ) {
					$product->update_meta_data( self::RELATION_META, $expected );
					$product->save_meta_data();
					$wrote = true;
				}
				if ( get_current_blog_id() !== $blog_id ) { return $this->projection_error(); }
				$product->read_meta_data( true );
				if ( '' !== $wpdb->last_error ) { return $this->projection_error(); }
				$actual = $product->get_meta( self::RELATION_META, true, 'edit' );
				$current = $this->expected_projection( $bingo_play_product_id );
				if ( $current instanceof \WP_Error ) { return $this->projection_error(); }
				if ( $current !== $expected ) { continue; }
				return $actual === $expected ? $wrote : $this->projection_error();
			}
		} catch ( \Throwable $error ) {
			return $this->projection_error();
		}
		return $this->projection_error();
	}

	/** Archive does not alter this count. No metadata or request payload is an authority. */
	private function expected_projection( int $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(enabled = 1), 0) AS enabled_count, COALESCE(SUM(enabled NOT IN (0, 1)), 0) AS invalid_count FROM %i WHERE bingo_play_product_id = %d', Migrations::package_addons_table(), $id ), ARRAY_A );
		if ( ! is_array( $row ) || '' !== $wpdb->last_error || (int) $row['invalid_count'] > 0 ) { return $this->projection_error(); }
		return (int) $row['enabled_count'] > 0 ? 'package_only_addon' : 'none';
	}

	private function projection_error(): \WP_Error {
		return $this->error( 'projection_reconciliation_required', 'The Catalog state is saved, but WooCommerce synchronization could not be verified. Retry synchronization for this Bingo Play Product.' );
	}
	private function inconsistent(): \WP_Error {
		return $this->error( 'package_addon_state_inconsistent', 'The Package add-on or its parent holds inconsistent stored state.' );
	}
	private function read_error(): \WP_Error {
		return $this->error( 'persistence_failed', 'The Package add-on state could not be read.' );
	}
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-catalog' ), [ 'status' => $code ] );
	}
}
