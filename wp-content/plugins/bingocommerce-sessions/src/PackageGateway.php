<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ONLY place `bingocommerce-sessions` talks to the Catalog domain.
 *
 * ADR-026 keeps Session and Catalog ownership separate. This gateway therefore
 * consumes supported `PackageService` reads exclusively: it never issues SQL
 * against `bingo_packages` or `bingo_package_components`, and it never mutates
 * Catalog state.
 *
 * It is fail-closed by construction. If the Catalog plugin is absent, or a
 * supported read returns a WP_Error, callers receive that error. A missing or
 * failed dependency is NEVER interpreted as "no Packages exist, therefore this
 * assignment is fine".
 */
final class PackageGateway {

	/** Upper bound WooCommerce's own administrative read supports per page. */
	private const ADMIN_READ_PAGE_SIZE = 200;

	private ?object $service = null;

	public static function is_available(): bool {
		return class_exists( '\BingoCommerce\Catalog\PackageService' );
	}

	/** @return object|\WP_Error */
	private function service() {
		if ( ! self::is_available() ) {
			return new \WP_Error(
				'catalog_unavailable',
				__( 'The BingoCommerce Catalog plugin is not available, so Bingo Packages cannot be read.', 'bingocommerce-sessions' )
			);
		}

		if ( null === $this->service ) {
			$class         = '\BingoCommerce\Catalog\PackageService';
			$this->service = new $class();
		}

		return $this->service;
	}

	/**
	 * One supported administrative read, mapped by package_id.
	 *
	 * This is the N+1 avoidance path: an administrative screen resolves every
	 * Package it needs from this single map. `listForAdministration()` caps a
	 * page at 200, so callers that need an id outside the first page use
	 * getById() for those specific ids rather than silently misclassifying them.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error Keyed by package_id.
	 */
	public function map( int $page = 1 ) {
		$service = $this->service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}

		$rows = $service->listForAdministration( 'all', max( 1, $page ), self::ADMIN_READ_PAGE_SIZE );
		if ( is_wp_error( $rows ) ) {
			// Propagate. An unreadable Catalog is not an empty Catalog.
			return $rows;
		}

		$by_id = [];
		foreach ( (array) $rows as $row ) {
			$by_id[ (int) $row['package_id'] ] = $row;
		}

		return $by_id;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function get( int $package_id ) {
		$service = $this->service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}

		return $service->getById( $package_id );
	}

	/**
	 * The anonymous-safe Catalog read for ADR-043 §6.4 only.
	 *
	 * `get()` and `map()` remain administrative reads. Keeping this separate
	 * prevents the storefront predicate from weakening any management boundary.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function getForSessionEligibility( int $package_id ) {
		$service = $this->service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		if ( ! method_exists( $service, 'getForSessionEligibility' ) ) {
			return new \WP_Error(
				'catalog_unavailable',
				__( 'The BingoCommerce Catalog eligibility read is not available, so Bingo Packages cannot be checked.', 'bingocommerce-sessions' )
			);
		}

		return $service->getForSessionEligibility( $package_id );
	}

	/**
	 * Anonymous-safe Catalog read for one exact-occurrence customer Package
	 * eligibility decision (BR-080). Administrative reads remain separate.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function getForCustomerPackageEligibility( int $package_id ) {
		$service = $this->service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		if ( ! method_exists( $service, 'getForCustomerPackageEligibility' ) ) {
			return new \WP_Error(
				'catalog_unavailable',
				__( 'The BingoCommerce customer Package eligibility read is not available, so the Package cannot be checked.', 'bingocommerce-sessions' )
			);
		}

		return $service->getForCustomerPackageEligibility( $package_id );
	}

	/** The only values a Bingo Package type may hold (BR-061). */
	private const PACKAGE_TYPES = [ 'paper', 'digital', 'hybrid' ];

	/** The only values a component's medium may hold (BR-039). */
	private const MEDIUMS = [ 'paper', 'digital' ];

	/** The only lifecycle values a Package or Bingo Play Product may hold. */
	private const LIFECYCLE = [ 'active', 'archived' ];

	/**
	 * Whether a Package may be NEWLY assigned to a Session.
	 *
	 * Fail-closed against corrupt authoritative state, using ONLY what supported
	 * PackageService reads return. This never queries a Catalog table and never
	 * mutates Catalog state.
	 *
	 * Deliberately NOT rejected: `has_archived_component`. BR-067 preserves an
	 * existing archived component inside an otherwise valid active Package, so
	 * such a Package remains legitimately assignable.
	 *
	 * `has_unresolved_component` remains a fail-closed signal, but it is not the
	 * only component check: each component's own medium, lifecycle, quantity and
	 * identity are validated, and the persisted `package_type` is re-derived from
	 * the component media and required to agree.
	 *
	 * @param array<string,mixed> $package
	 * @return true|\WP_Error
	 */
	public static function assert_assignable( array $package ) {
		if ( (int) ( $package['package_id'] ?? 0 ) < 1 ) {
			return self::fault( 'The Bingo Package identity is missing or invalid.' );
		}

		$status = (string) ( $package['status'] ?? '' );
		if ( ! in_array( $status, self::LIFECYCLE, true ) ) {
			return new \WP_Error(
				'package_state_inconsistent',
				__( 'The Bingo Package holds an unrecognised lifecycle value and cannot be assigned.', 'bingocommerce-sessions' )
			);
		}

		if ( 'active' !== $status ) {
			return new \WP_Error(
				'package_not_active',
				__( 'An archived Bingo Package cannot be assigned to a Session. Restore it first.', 'bingocommerce-sessions' )
			);
		}

		if ( ! empty( $package['has_unresolved_component'] ) ) {
			return self::fault( 'The Bingo Package has a component that does not resolve to a usable Bingo Play Product.' );
		}

		if ( ! in_array( (string) ( $package['package_type'] ?? '' ), self::PACKAGE_TYPES, true ) ) {
			return self::fault( 'The Bingo Package holds an unrecognised Package type.' );
		}

		$components = $package['components'] ?? null;
		if ( ! is_array( $components ) || [] === $components || ! array_is_list( $components ) ) {
			// BR-067 forbids an active zero-component Package, so observing one is
			// an integrity fault rather than an ordinary state.
			return self::fault( 'The Bingo Package has no usable base composition.' );
		}

		$seen        = [];
		$has_paper   = false;
		$has_digital = false;

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				return self::fault( 'A Bingo Package component could not be read.' );
			}

			$play_product_id = (int) ( $component['bingo_play_product_id'] ?? 0 );
			if ( $play_product_id < 1 ) {
				return self::fault( 'A Bingo Package component references an invalid Bingo Play Product.' );
			}
			if ( isset( $seen[ $play_product_id ] ) ) {
				// BR-067: one relationship row per Bingo Play Product.
				return self::fault( 'A Bingo Package lists the same Bingo Play Product more than once.' );
			}
			$seen[ $play_product_id ] = true;

			if ( (int) ( $component['quantity'] ?? 0 ) < 1 ) {
				return self::fault( 'A Bingo Package component holds an invalid quantity.' );
			}

			$medium = $component['medium'] ?? null;
			if ( ! in_array( $medium, self::MEDIUMS, true ) ) {
				// Paper is never inferred from "not digital".
				return self::fault( 'A Bingo Package component holds an unrecognised medium.' );
			}

			// An ARCHIVED component is allowed; an unrecognised one is not.
			if ( ! in_array( $component['play_product_status'] ?? null, self::LIFECYCLE, true ) ) {
				return self::fault( 'A Bingo Package component holds an unrecognised lifecycle value.' );
			}

			if ( 'digital' === $medium ) {
				$has_digital = true;
			} else {
				$has_paper = true;
			}
		}

		// BR-061 re-derived from the authoritative component media. A persisted
		// type that disagrees means the Package row and its composition are out of
		// step, which a Session must never build on.
		$derived = $has_paper && $has_digital ? 'hybrid' : ( $has_digital ? 'digital' : 'paper' );
		if ( $derived !== (string) $package['package_type'] ) {
			return self::fault( 'The Bingo Package type does not match its base composition.' );
		}

		// PackageService exposes the linked product's resolvability as woo_state.
		if ( 'ok' !== (string) ( $package['woo_state'] ?? '' ) ) {
			return new \WP_Error(
				'package_woo_product_unusable',
				__( 'The Bingo Package\'s linked WooCommerce product is missing, trashed, or unsupported.', 'bingocommerce-sessions' )
			);
		}

		return true;
	}

	private static function fault( string $detail ): \WP_Error {
		return new \WP_Error(
			'package_integrity_fault',
			__( 'That Bingo Package has a Catalog integrity fault and cannot be assigned. Contact Platform Admin.', 'bingocommerce-sessions' ),
			[ 'detail' => $detail ]
		);
	}

	/** @param array<string,mixed> $package */
	public static function is_assignable( array $package ): bool {
		return true === self::assert_assignable( $package );
	}
}
