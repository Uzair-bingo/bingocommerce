<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\AuditLog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative current-hall Bingo Package domain service (ADR-031, BR-040).
 *
 * `bingo_packages` and `bingo_package_components` are the authority for Package
 * identity, composition and derived type. The linked WooCommerce product is the
 * commerce shell, and its NATIVE PRICE remains the sole authority for the
 * Package base price (ADR-031, BR-049). This service never stores a second base
 * price and never sums component prices into one.
 *
 * Transaction ownership is deliberately split:
 *
 *   - creation enters the ADR-032 Package-create profile, because a Package and
 *     its initial composition are one valid object (BR-067);
 *   - composition replacement enters the existing ADR-033 boundary — this
 *     service marshals the request and never reimplements that sequence;
 *   - archive and restore are ordinary conditional single-row $wpdb writes with
 *     no WooCommerce revalidation (BR-070).
 *
 * Audit events and the BR-065 `bc_product_kind` projection are ordinary
 * post-commit settlement. Neither ever runs inside a Catalog transaction, and
 * neither can roll back an already committed authoritative mutation.
 */
final class PackageService {

	public const TYPE_PAPER   = 'paper';
	public const TYPE_DIGITAL = 'digital';
	public const TYPE_HYBRID  = 'hybrid';

	private const TYPES = [ self::TYPE_PAPER, self::TYPE_DIGITAL, self::TYPE_HYBRID ];

	public const MEDIUM_PAPER   = 'paper';
	public const MEDIUM_DIGITAL = 'digital';

	/** The only values bingo_play_products.medium may hold (BR-039). */
	private const MEDIUMS = [ self::MEDIUM_PAPER, self::MEDIUM_DIGITAL ];

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_ARCHIVED = 'archived';

	private const STATUSES = [ self::STATUS_ACTIVE, self::STATUS_ARCHIVED ];

	/** Bounded by the bingo_package_components.quantity column. Not a business maximum. */
	private const QUANTITY_MAX = 4294967295;

	/** BR-065's derived Package classification projection. */
	public const PRODUCT_KIND_META = 'bc_product_kind';
	public const PRODUCT_KIND_PACKAGE = 'package';

	/** Statuses a linked WooCommerce product may hold and remain eligible. */
	private const WOO_ALLOWED_STATUSES = [ 'publish', 'draft', 'private', 'pending' ];

	/** Only top-level simple products are supported in Increment 1. */
	private const WOO_SUPPORTED_TYPES = [ 'simple' ];

	private const COLUMNS = 'package_id, wc_product_id, package_type, status, created_at, updated_at, archived_at';

	/** @var callable */
	private $audit_recorder;

	public function __construct( ?callable $audit_recorder = null ) {
		$this->audit_recorder = $audit_recorder ?? [ AuditLog::class, 'record' ];
	}

	/* ------------------------------------------------------------------ *
	 * Writers
	 * ------------------------------------------------------------------ */

	/**
	 * Create a Bingo Package together with its INITIAL composition.
	 *
	 * There is no "create empty, compose later" path: BR-067 forbids an active
	 * zero-component Package, so both halves commit together or neither does.
	 *
	 * `package_type` is never accepted from the caller. It is derived inside the
	 * transaction from the `medium` values read while the component rows are
	 * locked (BR-061).
	 *
	 * @param array<int,array{bingo_play_product_id:int,quantity:int}> $components
	 * @return PackageResult|\WP_Error
	 */
	public function create( int $wc_product_id, array $components ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( $wc_product_id < 1 ) {
			return $this->error( 'invalid_wc_product_id', 'The WooCommerce product ID is invalid.' );
		}

		$normalized = $this->normalize_components( $components );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		// WooCommerce CRUD validation happens here, before BEGIN. Only the minimal
		// posts-row revalidation ADR-032 permits happens under the lock.
		$eligible = $this->assert_woo_product_eligible( $wc_product_id );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}

		$outcome = CatalogTransactionTransport::createPackageWithComposition(
			CatalogPackagePlan::forCreate( $context['blog_id'], $wc_product_id, $normalized, current_time( 'mysql', true ) )
		);
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		// create() can never be a verified no-op, so it settles as a mutation.
		// CatalogWriteOutcome's subject id carries the new package_id here; the
		// property name is historical (see CatalogWriteOutcome).
		return $this->settle( (int) $outcome->bingo_play_product_id, 'package_created', 'catalog.package.created', $context, null, true );
	}

	/**
	 * Replace a Package's base composition through the existing ADR-033 boundary.
	 *
	 * This method marshals the request and settles the result. It does NOT
	 * reimplement ADR-033's statement sequence, its fingerprint comparison, its
	 * archived-component rules, or its dependency-union integrity check.
	 *
	 * @param array<int,array{bingo_play_product_id:int,quantity:int}> $components
	 * @return PackageResult|\WP_Error
	 */
	public function replaceComponents( int $package_id, string $expected_fingerprint, array $components ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $package_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$normalized = $this->normalize_components( $components );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$before_components = $this->read_components( $package_id );
		if ( is_wp_error( $before_components ) ) {
			return $before_components;
		}

		$outcome = CatalogTransactionTransport::replacePackageComposition(
			CatalogPackageCompositionPlan::forReplaceComposition(
				$context['blog_id'],
				$package_id,
				$expected_fingerprint,
				$normalized,
				current_time( 'mysql', true )
			)
		);
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		if ( ! $outcome->mutated ) {
			// A verified no-op under the parent Package lock: no component DML ran,
			// bingo_packages.updated_at was not rewritten, and nothing is audited.
			$current = $this->read_by_id( $package_id );

			return is_wp_error( $current )
				? $current
				: new PackageResult( 'package_composition_unchanged', $current );
		}

		return $this->settle(
			$package_id,
			'package_composition_updated',
			'catalog.package.components_updated',
			$context,
			$before + [ 'components' => $before_components ],
			false
		);
	}

	/**
	 * BR-070 — ordinary conditional single-row write. No WooCommerce
	 * revalidation, no ADR-032 boundary, no composition rederivation.
	 *
	 * @return PackageResult|\WP_Error
	 */
	public function archive( int $package_id ) {
		return $this->transition(
			$package_id,
			self::STATUS_ARCHIVED,
			'package_archived',
			'package_already_archived',
			'catalog.package.archived'
		);
	}

	/**
	 * BR-070 — ordinary conditional single-row write. Restore reacquires no
	 * WooCommerce claim, because the Package row kept its UNIQUE wc_product_id
	 * claim throughout, and never rederives composition.
	 *
	 * @return PackageResult|\WP_Error
	 */
	public function restore( int $package_id ) {
		return $this->transition(
			$package_id,
			self::STATUS_ACTIVE,
			'package_restored',
			'package_already_active',
			'catalog.package.restored'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Reads
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed>|\WP_Error */
	public function getById( int $package_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		return $this->read_by_id( $package_id );
	}

	/**
	 * Current-hall read used only by Sessions' existing storefront-eligibility
	 * predicate (ADR-043 §6.4).
	 *
	 * This is intentionally narrower than a customer purchase validator. It
	 * returns the same Package shape the existing assignability check already
	 * consumes, makes no sale-policy decision of its own, and never writes,
	 * audits or synchronizes a projection. Signed-out callers are expected here;
	 * every existing management read and writer remains authorization-gated.
	 *
	 * Hall isolation is the current site's verified per-site schema and `$wpdb`
	 * prefix. An absent Hall context or unavailable schema fails closed.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function getForSessionEligibility( int $package_id ) {
		return $this->read_for_anonymous_eligibility( $package_id );
	}

	/**
	 * Current-hall read used only by the exact-occurrence customer Package
	 * eligibility boundary (BR-080).
	 *
	 * This deliberately exposes the same read shape as Session eligibility, but
	 * through a separately named contract. It makes no purchase-policy decision;
	 * Sessions owns the occurrence assignment and composes the customer predicate.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function getForCustomerPackageEligibility( int $package_id ) {
		return $this->read_for_anonymous_eligibility( $package_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_for_anonymous_eligibility( int $package_id ) {
		if ( ! HallContext::is_hall() ) {
			return $this->error( 'invalid_hall_context', 'Bingo Packages may be read for customer eligibility only from a hall site.' );
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'catalog_schema_unavailable', 'The Catalog schema is not ready for this hall.' );
		}

		return $this->read_by_id( $package_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function getByWooProductId( int $wc_product_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . Migrations::packages_table() . ' WHERE wc_product_id = %d', $wc_product_id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->error( 'package_not_found', 'The Bingo Package was not found.' );
		}

		return $this->read_by_id( (int) $row['package_id'] );
	}

	/**
	 * Current-hall administrative listing, including each Package's composition
	 * and whether it contains an archived component (BR-067 item 4 requires that
	 * to be surfaced so a Hall Manager can correct it deliberately).
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function listForAdministration( string $status = 'all', int $page = 1, int $per_page = 50 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		global $wpdb;
		$table    = Migrations::packages_table();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . self::COLUMNS . " FROM {$table} WHERE status = %s ORDER BY package_id DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . self::COLUMNS . " FROM {$table} ORDER BY package_id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$packages = [];
		foreach ( $rows as $row ) {
			$decorated = $this->decorate( $this->map_row( $row ) );
			if ( is_wp_error( $decorated ) ) {
				// Propagate rather than returning a short list: a partial listing
				// is indistinguishable from "this hall has fewer Packages".
				return $decorated;
			}
			$packages[] = $decorated;
		}

		return $packages;
	}

	/**
	 * The canonical composition fingerprint for a Package, computed from the same
	 * canonicalization the ADR-033 boundary recomputes under lock. An edit form
	 * carries this value; a stale one is rejected as `package_composition_stale`.
	 *
	 * @return string|\WP_Error
	 */
	public function compositionFingerprint( int $package_id ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$components = $this->read_components( $package_id );
		if ( is_wp_error( $components ) ) {
			return $components;
		}

		return CatalogTransactionTransport::compositionFingerprint( $components );
	}

	/**
	 * WooCommerce products this hall may still link as a Package commerce shell.
	 *
	 * Convenience only. Claim exclusivity is enforced inside the serialized
	 * writer and is never guaranteed by this selector (ADR-031).
	 *
	 * @return array<int,int>|\WP_Error
	 */
	public function selectableWooProductIds( int $limit = 200 ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$ids = wc_get_products(
			[
				'type'   => 'simple',
				'status' => self::WOO_ALLOWED_STATUSES,
				'limit'  => max( 1, min( 500, $limit ) ),
				'return' => 'ids',
				'parent' => 0,
			]
		);
		if ( ! is_array( $ids ) ) {
			return [];
		}

		global $wpdb;
		$selectable = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;

			$package_claim = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::packages_table() . ' WHERE wc_product_id = %d', $id )
			);
			$play_claim = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Migrations::play_products_table() . ' WHERE wc_product_id = %d', $id )
			);
			if ( $package_claim > 0 || $play_claim > 0 ) {
				continue;
			}

			$selectable[] = $id;
		}

		return $selectable;
	}

	/**
	 * The linked product's NATIVE WooCommerce price. ADR-031 and BR-049 make this
	 * the sole Package base-price authority: this service stores no price of its
	 * own and never sums component prices.
	 *
	 * @return array{price:string,formatted:string,available:bool}
	 */
	public function wooBasePrice( int $wc_product_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [ 'price' => '', 'formatted' => '', 'available' => false ];
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof \WC_Product ) {
			return [ 'price' => '', 'formatted' => '', 'available' => false ];
		}

		return [
			'price'     => (string) $product->get_price(),
			'formatted' => (string) wp_strip_all_tags( (string) $product->get_price_html() ),
			'available' => true,
		];
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/** @return array<string,int|bool>|\WP_Error */
	private function authorize() {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'authentication_required', 'You must be signed in to manage Bingo Packages.' );
		}
		if ( ! HallContext::is_hall() ) {
			return $this->error( 'invalid_hall_context', 'Bingo Packages may be managed only from a hall site.' );
		}

		$actor_id = get_current_user_id();
		$is_super = is_super_admin( $actor_id );
		if ( ! $is_super && ! current_user_can( 'bc_manage_products' ) ) {
			return $this->error( 'package_management_denied', 'You are not authorized to manage Bingo Packages for this hall.' );
		}
		if ( ! Migrations::is_current() ) {
			return $this->error( 'catalog_schema_unavailable', 'The Catalog schema is not ready for this hall.' );
		}

		return [
			'blog_id'        => get_current_blog_id(),
			'actor_user_id'  => $actor_id,
			'actor_is_super' => $is_super,
		];
	}

	/**
	 * Presentation-layer component validation. Authoritative existence,
	 * lifecycle and current medium are validated under lock inside the transport
	 * and are deliberately NOT checked here.
	 *
	 * @param array<int,mixed> $components
	 * @return array<int,array{bingo_play_product_id:int,quantity:int}>|\WP_Error
	 */
	private function normalize_components( array $components ) {
		if ( ! array_is_list( $components ) ) {
			return $this->error( 'invalid_component_set', 'The Bingo Package composition must be a plain list of components.' );
		}

		$normalized = [];
		$seen       = [];
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || ! array_key_exists( 'bingo_play_product_id', $component ) || ! array_key_exists( 'quantity', $component ) ) {
				return $this->error( 'invalid_component_set', 'Each Bingo Package component needs a Bingo Play Product and a quantity.' );
			}

			$id       = $component['bingo_play_product_id'];
			$quantity = $component['quantity'];
			if ( ! is_int( $id ) || ! is_int( $quantity ) ) {
				return $this->error( 'invalid_component_set', 'Each Bingo Package component needs whole-number values.' );
			}
			if ( $id < 1 ) {
				return $this->error( 'invalid_component_set', 'A Bingo Package component references an invalid Bingo Play Product.' );
			}
			if ( $quantity < 1 || $quantity > self::QUANTITY_MAX ) {
				return $this->error( 'invalid_component_quantity', 'A Bingo Package component quantity must be a whole number between 1 and 4294967295.' );
			}
			if ( isset( $seen[ $id ] ) ) {
				// BR-067: quantities are never silently merged.
				return $this->error( 'duplicate_component', 'A Bingo Play Product may appear only once in a Package composition.' );
			}

			$seen[ $id ]  = true;
			$normalized[] = [ 'bingo_play_product_id' => $id, 'quantity' => $quantity ];
		}

		if ( [] === $normalized ) {
			return $this->error( 'package_requires_component', 'A Bingo Package must contain at least one Bingo Play Product.' );
		}

		return $normalized;
	}

	/**
	 * WooCommerce-side eligibility, matching the Bingo Play Product rules: only
	 * top-level simple products; missing, trashed and auto-draft products are
	 * rejected.
	 *
	 * @return true|\WP_Error
	 */
	private function assert_woo_product_eligible( int $wc_product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $this->error( 'woocommerce_unavailable', 'WooCommerce is not available for this hall.' );
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof \WC_Product ) {
			return $this->error( 'woo_product_missing', 'The WooCommerce product was not found in this hall.' );
		}

		if ( $product instanceof \WC_Product_Variation || (int) $product->get_parent_id() > 0 ) {
			return $this->error( 'woo_product_unsupported', 'Product variations cannot be linked as Bingo Packages.' );
		}

		if ( ! in_array( (string) $product->get_status(), self::WOO_ALLOWED_STATUSES, true ) ) {
			return $this->error( 'woo_product_trashed', 'The WooCommerce product is in the trash or is an unsaved draft.' );
		}

		if ( ! in_array( (string) $product->get_type(), self::WOO_SUPPORTED_TYPES, true ) ) {
			return $this->error( 'woo_product_unsupported', 'Only simple WooCommerce products are supported in this release.' );
		}

		return true;
	}

	/**
	 * BR-070's shared lifecycle write. One conditional single-row UPDATE, then a
	 * verified read that distinguishes a genuine no-op from a corrupt row.
	 *
	 * @return PackageResult|\WP_Error
	 */
	private function transition( int $package_id, string $target, string $status, string $noop_status, string $action ) {
		$context = $this->authorize();
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$before = $this->require_row( $package_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		if ( $target === $before['status'] ) {
			// Verified no-op. No mutation, and therefore no audit event.
			return new PackageResult( $noop_status, $before );
		}

		global $wpdb;
		$table = Migrations::packages_table();
		$now   = current_time( 'mysql', true );

		if ( self::STATUS_ARCHIVED === $target ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'archived', archived_at = %s, updated_at = %s"
					. " WHERE package_id = %d AND status = 'active'",
					$now,
					$now,
					$package_id
				)
			);
		} else {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'active', archived_at = NULL, updated_at = %s"
					. " WHERE package_id = %d AND status = 'archived'",
					$now,
					$package_id
				)
			);
		}

		if ( false === $affected || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		if ( 0 === (int) $affected ) {
			// The row moved underneath the conditional write. Re-read and decide
			// from current authoritative state rather than guessing.
			$current = $this->read_by_id( $package_id );
			if ( is_wp_error( $current ) ) {
				return $current;
			}

			return $target === $current['status']
				? new PackageResult( $noop_status, $current )
				: $this->error( CatalogTransactionTransport::STATE_CHANGED, 'The Bingo Package changed while it was being updated. Reload and try again.' );
		}

		return $this->settle( $package_id, $status, $action, $context, $before, false );
	}

	/**
	 * Post-commit settlement: confirming read, BR-065 projection, ordinary audit.
	 *
	 * Every step here runs OUTSIDE the Catalog transaction and after a confirmed
	 * commit. None of them can roll back the authoritative mutation; each failure
	 * is surfaced as a warning on an otherwise successful result.
	 *
	 * @param array<string,mixed>|null $before
	 * @return PackageResult
	 */
	private function settle( int $package_id, string $status, string $action, array $context, ?array $before, bool $project_kind ): PackageResult {
		$after  = $this->read_by_id( $package_id );
		$result = new PackageResult(
			$status,
			is_wp_error( $after ) ? ( $before ?? [ 'package_id' => $package_id ] ) : $after
		);

		if ( is_wp_error( $after ) ) {
			// The mutation is durably committed; only the confirming read failed.
			$result->warnings[] = [
				'code'    => 'state_read_failed',
				'message' => $after->get_error_message(),
			];
		}

		if ( $project_kind ) {
			$wc_product_id = (int) ( ( is_wp_error( $after ) ? ( $before ?? [] ) : $after )['wc_product_id'] ?? 0 );
			$this->project_product_kind( $result, $package_id, $wc_product_id );
		}

		return $this->record_audit( $result, $action, $context, $before, is_wp_error( $after ) ? null : $after, $package_id );
	}

	/**
	 * BR-065's deterministic Package projection:
	 *
	 *     if an authoritative bingo_packages row exists: bc_product_kind = package
	 *
	 * Derived compatibility metadata only — never an authority, never inside the
	 * Catalog transaction, and never able to roll back a committed Package.
	 *
	 * Archive and restore deliberately do NOT rewrite it: the authoritative row
	 * still exists in both states, so the projection's condition is unchanged
	 * (BR-070). `bc_package_relation` is add-on relationship work and is NOT
	 * written here.
	 */
	private function project_product_kind( PackageResult $result, int $package_id, int $wc_product_id ): void {
		if ( $wc_product_id < 1 ) {
			$result->warnings[] = [
				'code'    => 'projection_write_failed',
				'message' => 'The Bingo Package classification projection could not be written: the linked product is unknown.',
			];

			return;
		}

		$written = update_post_meta( $wc_product_id, self::PRODUCT_KIND_META, self::PRODUCT_KIND_PACKAGE );
		if ( false === $written && self::PRODUCT_KIND_PACKAGE !== (string) get_post_meta( $wc_product_id, self::PRODUCT_KIND_META, true ) ) {
			error_log( sprintf( 'BingoCommerce Catalog projection failure for bc_product_kind on Bingo Package %d (product %d).', $package_id, $wc_product_id ) );
			$result->warnings[] = [
				'code'    => 'projection_write_failed',
				'message' => 'The Bingo Package was created, but its WooCommerce classification metadata could not be written. Reconcile it before selling.',
			];
		}
	}

	/**
	 * Ordinary ADR-023 Catalog audit, after a confirmed commit.
	 *
	 * This is NOT ADR-025: no operation_id, no commit witness, no retry token, no
	 * mandatory-audit atomicity, no reconciliation protocol. A failed audit
	 * leaves the mutation committed and surfaces a warning.
	 *
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>|null $after
	 */
	private function record_audit( PackageResult $result, string $action, array $context, ?array $before, ?array $after, int $package_id ): PackageResult {
		$event = [
			'blog_id'        => $context['blog_id'],
			'actor_user_id'  => $context['actor_user_id'],
			'action'         => $action,
			'target_type'    => 'bingo_package',
			'target_id'      => $package_id,
			'previous_value' => null === $before ? null : $this->audit_payload( $before ),
			'new_value'      => null === $after ? null : $this->audit_payload( $after ),
			'context'        => [
				'package_id'    => $package_id,
				'wc_product_id' => (int) ( ( $after ?? $before )['wc_product_id'] ?? 0 ),
			],
		];

		$audit_result = call_user_func( $this->audit_recorder, $event );
		if ( is_wp_error( $audit_result ) ) {
			$error_code = $audit_result->get_error_code();
			error_log( sprintf( 'BingoCommerce Catalog audit failure for %s on Bingo Package %d: %s', $action, $package_id, $error_code ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => $error_code,
				'message'    => $audit_result->get_error_message(),
			];
		} elseif ( ! is_int( $audit_result ) || $audit_result < 1 ) {
			error_log( sprintf( 'BingoCommerce Catalog audit returned an invalid result for %s on Bingo Package %d.', $action, $package_id ) );
			$result->warnings[] = [
				'code'       => 'audit_write_failed',
				'audit_code' => 'invalid_audit_result',
				'message'    => 'The audit service returned an invalid result.',
			];
		}

		return $result;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function audit_payload( array $row ): array {
		$payload = [
			'package_id'    => (int) ( $row['package_id'] ?? 0 ),
			'wc_product_id' => (int) ( $row['wc_product_id'] ?? 0 ),
			'package_type'  => (string) ( $row['package_type'] ?? '' ),
			'status'        => (string) ( $row['status'] ?? '' ),
		];

		if ( isset( $row['components'] ) && is_array( $row['components'] ) ) {
			$payload['components'] = array_map(
				static fn( array $component ): array => [
					'bingo_play_product_id' => (int) $component['bingo_play_product_id'],
					'quantity'              => (int) $component['quantity'],
				],
				$row['components']
			);
		}

		return $payload;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function require_row( int $package_id ) {
		$row = $this->read_by_id( $package_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( ! in_array( $row['status'], self::STATUSES, true ) ) {
			// Fail closed rather than coercing an unrecognised lifecycle value
			// into either state (BR-070).
			return $this->error( CatalogTransactionTransport::SCHEMA_INCONSISTENT, 'The Bingo Package holds an unrecognised lifecycle value.' );
		}

		return $row;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_by_id( int $package_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . Migrations::packages_table() . ' WHERE package_id = %d', $package_id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}
		if ( ! is_array( $row ) ) {
			return $this->error( 'package_not_found', 'The Bingo Package was not found.' );
		}

		return $this->decorate( $this->map_row( $row ) );
	}

	/**
	 * The Package's components in canonical order, joined to their Bingo Play
	 * Product state so administrative reads can surface an archived component
	 * (BR-067 item 4).
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function read_components( int $package_id ) {
		global $wpdb;

		$components = Migrations::package_components_table();
		$products   = Migrations::play_products_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.package_component_id, c.bingo_play_product_id, c.quantity, c.sort_order,"
				. ' pp.medium, pp.faces_per_unit, pp.status AS play_product_status, pp.wc_product_id AS play_product_wc_id'
				. " FROM {$components} c"
				. " LEFT JOIN {$products} pp ON pp.bingo_play_product_id = c.bingo_play_product_id"
				. ' WHERE c.package_id = %d ORDER BY c.bingo_play_product_id',
				$package_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->persistence_error( (string) $wpdb->last_error );
		}

		$mapped = [];
		foreach ( $rows as $row ) {
			$mapped[] = [
				'package_component_id'  => (int) $row['package_component_id'],
				'bingo_play_product_id' => (int) $row['bingo_play_product_id'],
				'quantity'              => (int) $row['quantity'],
				'sort_order'            => (int) $row['sort_order'],
				'medium'                => null === $row['medium'] ? null : (string) $row['medium'],
				'faces_per_unit'        => null === $row['faces_per_unit'] ? null : (int) $row['faces_per_unit'],
				'play_product_status'   => null === $row['play_product_status'] ? null : (string) $row['play_product_status'],
				'play_product_wc_id'    => null === $row['play_product_wc_id'] ? null : (int) $row['play_product_wc_id'],
			];
		}

		return $mapped;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function map_row( array $row ): array {
		$row['package_id']    = (int) $row['package_id'];
		$row['wc_product_id'] = (int) $row['wc_product_id'];

		return $row;
	}

	/**
	 * Attach the derived, non-authoritative view data an administrative screen
	 * needs: composition, WooCommerce state, and the native Woo price.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>|\WP_Error
	 */
	private function decorate( array $row ) {
		// A failed component read is NOT an empty composition. Swallowing it would
		// present a Package as having no components, which is a state BR-067 says
		// can never exist, and would invite an edit built on a false picture.
		$components = $this->read_components( (int) $row['package_id'] );
		if ( is_wp_error( $components ) ) {
			return $components;
		}
		$row['components'] = $components;

		$has_archived = false;
		$unresolved   = false;
		foreach ( $row['components'] as $component ) {
			if ( null === $component['play_product_status'] ) {
				// The authoritative Play Product row is GONE. That is an integrity
				// fault, not an archived component, and ADR-033 makes it
				// unrepairable through ordinary composition editing.
				$unresolved = true;
				continue;
			}
			if ( ! in_array( $component['play_product_status'], self::STATUSES, true ) ) {
				// An unrecognised lifecycle value is never coerced to archived.
				$unresolved = true;
				continue;
			}
			if ( ! in_array( $component['medium'], self::MEDIUMS, true ) ) {
				// A RESOLVED row holding a medium that is neither exactly paper nor
				// exactly digital is equally a Catalog integrity fault. Paper is
				// never inferred from "not digital": BR-061 derives Package type
				// from these values, and the ADR-033 writer already fails closed on
				// them, so administration must not present one as a normal
				// component either.
				$unresolved = true;
				continue;
			}
			if ( self::STATUS_ARCHIVED === $component['play_product_status'] ) {
				$has_archived = true;
			}
		}

		$row['has_archived_component']   = $has_archived;
		$row['has_unresolved_component'] = $unresolved;
		$row['woo_state']                = $this->woo_state( (int) $row['wc_product_id'] );
		$row['woo_price']                = $this->wooBasePrice( (int) $row['wc_product_id'] );

		return $row;
	}

	private function woo_state( int $wc_product_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'unknown';
		}

		$product = wc_get_product( $wc_product_id );
		if ( ! $product instanceof \WC_Product ) {
			return 'missing';
		}
		if ( ! in_array( (string) $product->get_status(), self::WOO_ALLOWED_STATUSES, true ) ) {
			return 'trashed';
		}
		if ( ! in_array( (string) $product->get_type(), self::WOO_SUPPORTED_TYPES, true ) ) {
			return 'unsupported';
		}

		return 'ok';
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-catalog' ), [ 'status' => $code ] );
	}

	private function persistence_error( string $db_error ): \WP_Error {
		return new \WP_Error(
			'persistence_failed',
			__( 'The Bingo Package change could not be persisted.', 'bingocommerce-catalog' ),
			[ 'db_error' => $db_error ]
		);
	}
}
