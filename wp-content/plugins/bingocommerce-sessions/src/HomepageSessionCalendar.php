<?php

declare(strict_types=1);

namespace BingoCommerce\Sessions;

use BingoCommerce\Catalog\CustomerCartCatalog;
use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The homepage Session calendar: date -> eligible dated Session -> its Packages
 * (ADR-045 / BR-084 addition, 2026-10-01; BR-052, BR-080).
 *
 * ONE ELIGIBILITY RESULT PER REQUEST. The calendar's selectable dates and every
 * date's Session rows are built from the same current-Hall result, so the two
 * can never disagree. `SessionEligibility::is_sellable()` alone decides each
 * Session: the candidate query below only narrows rows by conditions every
 * eligible Session must already satisfy, and it never decides one. The sales
 * window, Available for Sale, schedule membership and the per-occurrence saved
 * time zone are therefore exactly the existing rules, unchanged.
 *
 * RESULT CLASSIFICATION. `true` from `is_sellable()` is the only "verified
 * eligible" answer and `false` is an ordinary ineligibility. Anything else is an
 * eligibility error. No errors -> `ok`; at least one verified Session and at
 * least one error -> `partial`; errors and nothing verified -> `unavailable`.
 * Guard failures, a failed query and exceeding the candidate cap are also
 * `unavailable`. Only a successful empty `ok` result may say that there are no
 * Sessions. The Package list is classified the same way.
 *
 * PACKAGES. Each assigned Package must pass the existing BR-080 customer
 * eligibility (`CustomerPackageEligibility::is_available()`), then its linked
 * product must be publicly viewable (viewer-independent) and purchasable under
 * WooCommerce's own rule. Catalog visibility, stock and electronic limits are
 * deliberately NOT listing filters; cart and checkout keep enforcing them.
 *
 * ADD-ONS AND PURCHASE (ADR-045 / BR-084 addition, 2026-10-02). Each listed
 * Package carries its saved base composition ("What's included", from the same
 * Catalog read: names, configured quantities, Paper/Digital; `ok` or
 * `unavailable`, never partial) and its currently eligible add-on
 * relationships, decided by the
 * Catalog's own CustomerCartCatalog::addon() predicate, then by the linked
 * product's public visibility and WooCommerce purchasability AT THE
 * RELATIONSHIP PRICE (an in-memory copy, never saved), exactly as the cart
 * prices it (CartSelection::relation_priced()). The add-on list is classified
 * like the Package list. A selected
 * Session with listed Packages also carries the current Hall's existing
 * WooCommerce cart-extension endpoint, cart read endpoint, namespace, Store API
 * nonce and cart URL. No purchase happens here: the browser posts to that
 * existing endpoint and CustomerCart validates everything again.
 *
 * NO AUTHORIZATION, BY DESIGN: a signed-out customer asks these questions, as
 * with the predicates composed here. Nothing here writes, audits, caches across
 * requests or Halls, or changes sales state. Request values are claims: every
 * supplied parameter is validated before any selection is resolved.
 */
final class HomepageSessionCalendar {

	/** Read-only theme contract. */
	public const FILTER = 'bingocommerce_homepage_session_calendar';

	public const VERSION = 1;

	/*
	 * Unregistered homepage GET parameters. Registering them as query vars would
	 * make WordPress treat `/?bc_date=...` as the posts index rather than a static
	 * front page (WP_Query::parse_query()), so they are read here instead.
	 */
	public const PARAM_SESSION = 'bc_session';
	public const PARAM_DATE    = 'bc_date';
	public const PARAM_MONTH   = 'bc_month';

	/** Fragment targets rendered by the theme. */
	public const ANCHOR_CALENDAR = 'calendar';
	public const ANCHOR_PACKAGES = 'session-packages';

	public const STATUS_OK          = 'ok';
	public const STATUS_PARTIAL     = 'partial';
	public const STATUS_UNAVAILABLE = 'unavailable';

	/** Display states after request resolution. */
	public const STATES = [ 'date', 'date_empty', 'month', 'month_empty', 'none', 'invalid', 'month_unavailable', 'session_unavailable', 'mismatch' ];

	/** More candidates than this are never silently truncated: the read is unavailable. */
	private const CANDIDATE_CAP = 500;

	/*
	 * Superset bounds around the inclusive 30-day window, from the server's UTC
	 * date. Every saved or WordPress zone is within one calendar day of UTC
	 * (offsets run from -12:00 to +14:00), so an eligible date D lies within
	 * UTC-1 .. UTC+31; one more day on each side absorbs the clock moving during
	 * the request. These bounds only narrow candidates.
	 */
	private const LOWER_SLACK_DAYS = 2;
	private const UPPER_SLACK_DAYS = 32;

	private const PACKAGE_TYPES = [ 'paper', 'digital', 'hybrid' ];

	/** A component's only media (BR-039); paper is never inferred from "not digital". */
	private const COMPONENT_MEDIUMS = [ 'paper', 'digital' ];

	/** The linked-product statuses the Catalog itself accepts for a Bingo Play Product. */
	private const COMPONENT_PRODUCT_STATUSES = [ 'publish', 'draft', 'private', 'pending' ];

	/** CustomerCart's existing Store API cart-extension namespace. */
	private const CART_NAMESPACE = 'bingocommerce-selection';

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'provide' ] );
	}

	/**
	 * Filter callback: the current request's calendar. The incoming value is
	 * ignored, so a missing provider leaves the theme's own "unavailable" default.
	 *
	 * @param mixed $ignored
	 * @return array<string,mixed>
	 */
	public static function provide( $ignored = null ): array {
		// Read-only navigation: no state changes, so no nonce applies.
		return self::model( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * @param array<string,mixed> $query Raw request parameters, as `$_GET` holds them.
	 * @return array<string,mixed>
	 */
	public static function model( array $query ): array {
		$eligible = self::eligible();
		if ( self::STATUS_UNAVAILABLE === $eligible['status'] ) {
			return self::unavailable();
		}

		$model = self::resolve( $eligible, $query, SessionService::hall_local_today() );
		if ( is_array( $model['session'] ?? null ) ) {
			$model['packages'] = self::packages( (int) $model['session']['id'] );
			if ( [] !== $model['packages']['items'] ) {
				$model['purchase'] = self::purchase();
			}
		}

		return $model;
	}

	/* ------------------------------------------------------------------ *
	 * Eligible Sessions (one current-Hall result)
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{status:string,dates:array<string,array<int,array<string,mixed>>>}
	 */
	private static function eligible(): array {
		$unavailable = [ 'status' => self::STATUS_UNAVAILABLE, 'dates' => [] ];
		if ( ! class_exists( HallContext::class ) || ! HallContext::is_hall() || ! Migrations::is_current() || ! PackageGateway::is_available() ) {
			return $unavailable;
		}

		global $wpdb;

		$utc  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// Necessary conditions only. `available_for_sale <> 0` matches the
				// predicate's own (bool) reading of this tinyint exactly.
				'SELECT session_id, session_date, display_name, start_time_local, timezone_name
				   FROM %i
				  WHERE status = %s AND available_for_sale <> 0 AND session_date BETWEEN %s AND %s
				  ORDER BY session_date ASC, starts_at_utc IS NULL ASC, starts_at_utc ASC, display_name ASC, session_id ASC
				  LIMIT %d',
				Migrations::sessions_table(),
				SessionService::STATUS_ACTIVE,
				$utc->modify( '-' . self::LOWER_SLACK_DAYS . ' days' )->format( 'Y-m-d' ),
				$utc->modify( '+' . self::UPPER_SLACK_DAYS . ' days' )->format( 'Y-m-d' ),
				self::CANDIDATE_CAP + 1
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error || count( $rows ) > self::CANDIDATE_CAP ) {
			return $unavailable;
		}

		$dates    = [];
		$verified = 0;
		$errors   = 0;
		foreach ( $rows as $row ) {
			$result = SessionEligibility::is_sellable( (int) $row['session_id'] );
			if ( true === $result ) {
				++$verified;
				$session                      = self::session_row( $row );
				$dates[ $session['date'] ][] = $session;
			} elseif ( false !== $result ) {
				// A WP_Error, or any non-boolean answer, is never coerced.
				++$errors;
			}
		}

		if ( $errors > 0 && 0 === $verified ) {
			return $unavailable;
		}

		return [ 'status' => 0 === $errors ? self::STATUS_OK : self::STATUS_PARTIAL, 'dates' => $dates ];
	}

	/**
	 * Presentation fields only. Time comes from the structured columns, never
	 * from the display name; a legacy Session has none.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private static function session_row( array $row ): array {
		$time = null === $row['start_time_local'] ? null : ScheduleTimezone::display_time( (string) $row['start_time_local'] );

		return [
			'id'           => (int) $row['session_id'],
			'date'         => (string) $row['session_date'],
			'date_label'   => self::date_label( (string) $row['session_date'] ),
			'name'         => (string) $row['display_name'],
			'time'         => $time,
			'timezone'     => null === $time ? null : (string) $row['timezone_name'],
			'packages_url' => self::url( [ self::PARAM_SESSION => (string) (int) $row['session_id'] ], self::ANCHOR_PACKAGES ),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Request resolution (no domain reads)
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve the request against ONE eligible result. Validation of every
	 * supplied parameter precedes precedence, so a valid parameter never
	 * bypasses an invalid one.
	 *
	 * @param array{status:string,dates:array<string,array<int,array<string,mixed>>>} $eligible
	 * @param array<string,mixed>                                                   $query
	 * @param string                                                                $today Hall-local `Y-m-d`; marker and empty-result month only.
	 * @return array<string,mixed>
	 */
	public static function resolve( array $eligible, array $query, string $today ): array {
		$status = $eligible['status'] ?? '';
		$dates  = $eligible['dates'] ?? [];
		if ( ! in_array( $status, [ self::STATUS_OK, self::STATUS_PARTIAL ], true ) || ! is_array( $dates )
			|| ( self::STATUS_PARTIAL === $status && [] === $dates ) || null === self::canonical_date( $today ) ) {
			return self::unavailable();
		}
		ksort( $dates );

		$reachable = self::reachable_months( array_keys( $dates ), $today );
		$request   = self::request( $query );

		$state    = 'date';
		$selected = null;
		$session  = null;
		$month    = $reachable[0];

		if ( $request['invalid'] ) {
			$state = 'invalid';
		} elseif ( null !== $request['month'] && ! in_array( $request['month'], $reachable, true ) ) {
			$state = 'month_unavailable';
		} elseif ( null !== $request['session'] ) {
			$session = self::find_session( $dates, $request['session'] );
			if ( null === $session ) {
				$state = 'session_unavailable';
			} elseif ( null !== $request['date'] && $request['date'] !== $session['date'] ) {
				$state   = 'mismatch';
				$session = null;
			} else {
				$selected = $session['date'];
				$month    = self::month_of( $selected );
			}
		} elseif ( null !== $request['date'] ) {
			$selected = $request['date'];
			$month    = self::month_of( $selected );
			$state    = isset( $dates[ $selected ] ) ? 'date' : 'date_empty';
		} elseif ( null !== $request['month'] ) {
			$month = $request['month'];
			if ( [] === $dates ) {
				$state = 'none';
			} else {
				$state = self::month_has_dates( $month, $dates ) ? 'month' : 'month_empty';
			}
		} elseif ( [] !== $dates ) {
			$selected = (string) array_key_first( $dates );
			$month    = self::month_of( $selected );
		} else {
			$state = 'none';
		}

		$entries = [];
		foreach ( $dates as $date => $sessions ) {
			$entries[ $date ] = [
				'date'     => $date,
				'label'    => self::date_label( $date ),
				'url'      => self::url( [ self::PARAM_DATE => $date ], self::ANCHOR_CALENDAR ),
				'sessions' => array_values( $sessions ),
			];
		}

		return [
			'version'        => self::VERSION,
			'status'         => $status,
			'today'          => $today,
			'month'          => $month,
			'months'         => self::month_entries( $reachable, $month ),
			'dates'          => $entries,
			'state'          => $state,
			'selected_date'  => $selected,
			'selected_label' => null === $selected ? '' : self::date_label( $selected ),
			'session'        => $session,
			'packages'       => null,
			'purchase'       => null,
			'available_url'  => self::url( [], self::ANCHOR_CALENDAR ),
		];
	}

	/**
	 * A supplied parameter is validated even when empty; only an absent key is
	 * absent. Arrays and every malformed value are invalid.
	 *
	 * @param array<string,mixed> $query
	 * @return array{invalid:bool,session:?int,date:?string,month:?string}
	 */
	private static function request( array $query ): array {
		$request = [ 'invalid' => false, 'session' => null, 'date' => null, 'month' => null ];

		if ( array_key_exists( self::PARAM_SESSION, $query ) ) {
			$raw = self::raw( $query[ self::PARAM_SESSION ] );
			$id  = null === $raw ? 0 : CartSelection::integer( $raw );
			if ( $id < 1 ) {
				$request['invalid'] = true;
			} else {
				$request['session'] = $id;
			}
		}

		foreach ( [ self::PARAM_DATE => 'date', self::PARAM_MONTH => 'month' ] as $key => $field ) {
			if ( ! array_key_exists( $key, $query ) ) {
				continue;
			}
			$raw  = self::raw( $query[ $key ] );
			$date = null === $raw ? null : self::canonical_date( $raw );
			if ( null === $date || ( 'month' === $field && '01' !== substr( $date, 8, 2 ) ) ) {
				$request['invalid'] = true;
			} else {
				$request[ $field ] = $date;
			}
		}

		return $request;
	}

	/** @param mixed $value */
	private static function raw( $value ): ?string {
		return is_string( $value ) ? (string) wp_unslash( $value ) : null;
	}

	/** Exact `Y-m-d` only: `parse_date()` trims, so the round trip must equal the raw value. */
	private static function canonical_date( string $value ): ?string {
		$parsed = ScheduleExpansion::parse_date( $value );

		return null !== $parsed && $parsed->format( 'Y-m-d' ) === $value ? $value : null;
	}

	/**
	 * Every month from the first through the last VERIFIED eligible date,
	 * including intermediate months without one. With no verified date, only
	 * the Hall-local current month.
	 *
	 * @param array<int,string> $dates `Y-m-d` values.
	 * @return array<int,string> `Y-m-01` values, ascending.
	 */
	public static function reachable_months( array $dates, string $today ): array {
		$valid = [];
		foreach ( $dates as $date ) {
			if ( is_string( $date ) && null !== self::canonical_date( $date ) ) {
				$valid[] = $date;
			}
		}
		if ( [] === $valid ) {
			return [ self::month_of( $today ) ];
		}
		sort( $valid );

		$cursor = ScheduleExpansion::parse_date( self::month_of( $valid[0] ) );
		$last   = ScheduleExpansion::parse_date( self::month_of( $valid[ count( $valid ) - 1 ] ) );
		$months = [];
		// Object comparison, not string comparison, so a year boundary cannot
		// end (or fail to end) the walk early.
		while ( null !== $cursor && null !== $last && $cursor <= $last ) {
			$months[] = $cursor->format( 'Y-m-d' );
			$cursor   = $cursor->modify( 'first day of next month' );
		}

		return $months;
	}

	/**
	 * Months to render: every reachable month, plus a requested date's month
	 * when it lies outside them. Previous/next links only ever lead to a
	 * reachable month.
	 *
	 * @param array<int,string> $reachable
	 * @return array<int,array<string,mixed>>
	 */
	private static function month_entries( array $reachable, string $display ): array {
		$render = $reachable;
		if ( ! in_array( $display, $render, true ) ) {
			$render[] = $display;
			sort( $render );
		}

		$entries = [];
		foreach ( $render as $month ) {
			$parsed = ScheduleExpansion::parse_date( $month );
			if ( null === $parsed ) {
				continue;
			}
			$previous  = $parsed->modify( 'first day of previous month' )->format( 'Y-m-d' );
			$next      = $parsed->modify( 'first day of next month' )->format( 'Y-m-d' );
			$entries[] = [
				'month' => $month,
				'label' => self::month_label( $month ),
				'prev'  => in_array( $previous, $reachable, true ) ? self::month_link( $previous ) : null,
				'next'  => in_array( $next, $reachable, true ) ? self::month_link( $next ) : null,
			];
		}

		return $entries;
	}

	/** @return array{month:string,label:string,url:string} */
	private static function month_link( string $month ): array {
		return [ 'month' => $month, 'label' => self::month_label( $month ), 'url' => self::url( [ self::PARAM_MONTH => $month ], self::ANCHOR_CALENDAR ) ];
	}

	/** @param array<string,array<int,array<string,mixed>>> $dates */
	private static function find_session( array $dates, int $id ): ?array {
		foreach ( $dates as $sessions ) {
			foreach ( $sessions as $session ) {
				if ( $id === (int) ( $session['id'] ?? 0 ) ) {
					return $session;
				}
			}
		}

		return null;
	}

	/** @param array<string,mixed> $dates */
	private static function month_has_dates( string $month, array $dates ): bool {
		$prefix = substr( $month, 0, 8 );
		foreach ( array_keys( $dates ) as $date ) {
			if ( str_starts_with( (string) $date, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private static function month_of( string $date ): string {
		return substr( $date, 0, 8 ) . '01';
	}

	/** `MM/DD/YYYY` for a canonical `Y-m-d`; no time zone participates. */
	public static function date_label( string $date ): string {
		$parsed = ScheduleExpansion::parse_date( $date );

		return null !== $parsed && $parsed->format( 'Y-m-d' ) === $date ? $parsed->format( 'm/d/Y' ) : '';
	}

	/** Localized month name for a calendar month, formatted in UTC so it cannot shift. */
	private static function month_label( string $month ): string {
		$parsed = ScheduleExpansion::parse_date( $month );

		return null === $parsed ? '' : (string) wp_date( 'F Y', $parsed->getTimestamp(), new \DateTimeZone( 'UTC' ) );
	}

	/** @param array<string,string> $args */
	private static function url( array $args, string $anchor ): string {
		$base = home_url( '/' );

		return ( [] === $args ? $base : add_query_arg( $args, $base ) ) . '#' . $anchor;
	}

	/** @return array{version:int,status:string} */
	private static function unavailable(): array {
		return [ 'version' => self::VERSION, 'status' => self::STATUS_UNAVAILABLE ];
	}

	/* ------------------------------------------------------------------ *
	 * Packages for one verified Session
	 * ------------------------------------------------------------------ */

	/**
	 * Only for a Session already in this request's eligible result.
	 *
	 * @return array{status:string,items:array<int,array<string,mixed>>}
	 */
	private static function packages( int $session_id ): array {
		$unavailable = [ 'status' => self::STATUS_UNAVAILABLE, 'items' => [] ];
		if ( ! function_exists( 'wc_get_product' ) || ! PackageGateway::is_available() ) {
			return $unavailable;
		}

		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT package_id FROM %i WHERE session_id = %d ORDER BY package_id', Migrations::session_packages_table(), $session_id )
		);
		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			return $unavailable;
		}

		$gateway = new PackageGateway();
		$items   = [];
		$errors  = 0;
		foreach ( $ids as $package_id ) {
			$package_id = (int) $package_id;

			// Existing assignment + BR-080 eligibility, unchanged.
			$available = CustomerPackageEligibility::is_available( $session_id, $package_id );
			if ( false === $available ) {
				continue;
			}
			if ( true !== $available ) {
				++$errors;
				continue;
			}

			$package = $gateway->getForCustomerPackageEligibility( $package_id );
			$product = is_array( $package ) ? wc_get_product( (int) ( $package['wc_product_id'] ?? 0 ) ) : null;
			$type    = is_array( $package ) ? ( $package['package_type'] ?? null ) : null;
			if ( ! $product instanceof \WC_Product || ! in_array( $type, self::PACKAGE_TYPES, true ) ) {
				++$errors;
				continue;
			}

			// Required public visibility (viewer-independent, so a signed-in
			// Manager sees what a customer sees) and WooCommerce's own
			// purchasability. Catalog visibility is intentionally not checked.
			if ( ! is_post_publicly_viewable( $product->get_id() ) || ! $product->is_purchasable() ) {
				continue;
			}

			$image   = (int) $product->get_image_id();
			$items[] = [
				'id'         => $package_id,
				'type'       => $type,
				'name'       => self::product_name( $product ),
				'image_id'   => $image > 0 && wp_attachment_is_image( $image ) ? $image : 0,
				'price_html' => (string) $product->get_price_html(),
				'contents'   => self::contents( $package ),
				'addons'     => self::addons( $package_id ),
			];
		}

		if ( $errors > 0 && [] === $items ) {
			return $unavailable;
		}

		return [ 'status' => 0 === $errors ? self::STATUS_OK : self::STATUS_PARTIAL, 'items' => $items ];
	}

	/**
	 * "What's included": the listed Package's saved base composition, from the
	 * same Catalog read that listed it (ADR-045 / BR-084 addition 2026-10-02).
	 * Each component is its Bingo Play Product's linked product name, the
	 * configured component quantity (units of that item, never digital faces)
	 * and its Paper or Digital medium, in the Catalog's own order. Optional
	 * add-ons are never part of it. A list that cannot be verified completely —
	 * missing, empty, an unrecognised medium or lifecycle, an invalid quantity, an
	 * unresolvable or trashed product, an empty name — is `unavailable`, so a
	 * partial list never looks like the whole Package.
	 *
	 * @param array<string,mixed> $package The Catalog's customer eligibility read.
	 * @return array{status:string,items:array<int,array{name:string,quantity:int,medium:string}>}
	 */
	private static function contents( array $package ): array {
		$unavailable = [ 'status' => self::STATUS_UNAVAILABLE, 'items' => [] ];
		$components  = $package['components'] ?? null;
		if ( ! is_array( $components ) || [] === $components || ! array_is_list( $components ) ) {
			return $unavailable;
		}

		$items = [];
		foreach ( $components as $component ) {
			$quantity = is_array( $component ) ? ( $component['quantity'] ?? null ) : null;
			$medium   = is_array( $component ) ? ( $component['medium'] ?? null ) : null;
			$product  = is_array( $component ) ? wc_get_product( (int) ( $component['play_product_wc_id'] ?? 0 ) ) : null;
			// BR-080 already refuses an archived component for sale; anything else is unverifiable here.
			if ( ! is_int( $quantity ) || $quantity < 1 || ! in_array( $medium, self::COMPONENT_MEDIUMS, true )
				|| 'active' !== ( $component['play_product_status'] ?? null )
				|| ! $product instanceof \WC_Product || ! in_array( $product->get_status(), self::COMPONENT_PRODUCT_STATUSES, true ) ) {
				return $unavailable;
			}
			$name = self::product_name( $product );
			if ( '' === $name ) {
				return $unavailable;
			}
			$items[] = [ 'name' => $name, 'quantity' => $quantity, 'medium' => $medium ];
		}

		return [ 'status' => self::STATUS_OK, 'items' => $items ];
	}

	/**
	 * Currently eligible add-on relationships of one listed Package. `id` is the
	 * Package add-on RELATIONSHIP ID (what the cart request submits), never a
	 * Play Product or WooCommerce product ID. Prices are the relationship's own.
	 *
	 * @return array{status:string,items:array<int,array<string,mixed>>}
	 */
	private static function addons( int $package_id ): array {
		$unavailable = [ 'status' => self::STATUS_UNAVAILABLE, 'items' => [] ];
		if ( ! class_exists( CustomerCartCatalog::class ) ) {
			return $unavailable;
		}

		$read = CustomerCartCatalog::addons( $package_id );
		if ( is_wp_error( $read ) ) {
			return $unavailable;
		}

		$items  = [];
		$errors = (int) $read['errors'];
		foreach ( $read['items'] as $addon ) {
			$product = wc_get_product( (int) $addon['wc_product_id'] );
			// The cart accepts simple products only (CartSelection::inspect()).
			if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
				++$errors;
				continue;
			}

			// The relationship price on an in-memory copy, exactly as the cart applies
			// it; the stored WooCommerce prices are never changed or saved.
			$priced = CartSelection::relation_priced( clone $product, (string) $addon['unit_price'] );
			if ( ! is_post_publicly_viewable( $product->get_id() ) || ! $priced->is_purchasable() ) {
				continue;
			}

			$image   = (int) $product->get_image_id();
			$items[] = [
				'id'           => (int) $addon['package_addon_id'],
				'name'         => self::product_name( $product ),
				'image_id'     => $image > 0 && wp_attachment_is_image( $image ) ? $image : 0,
				'max_quantity' => (int) $addon['max_quantity'],
				// Not get_price_html(): its sale branch would compare the product's own
				// stored sale and regular prices, which do not apply to this price.
				'price_html'   => wc_price( (float) wc_get_price_to_display( $priced, [ 'price' => (string) $addon['unit_price'] ] ) ) . $priced->get_price_suffix(),
			];
		}

		if ( $errors > 0 && [] === $items ) {
			return $unavailable;
		}

		return [ 'status' => 0 === $errors ? self::STATUS_OK : self::STATUS_PARTIAL, 'items' => $items ];
	}

	/** A product's display name as plain text. */
	private static function product_name( \WC_Product $product ): string {
		return trim( wp_strip_all_tags( html_entity_decode( (string) $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * The current Hall's existing purchase boundary: WooCommerce's Store API
	 * cart-extension route (CustomerCart's namespace) and its cart read, with a
	 * Store API nonce for this viewer. Request authority stays server-side.
	 *
	 * @return array<string,string>|null
	 */
	private static function purchase(): ?array {
		if ( ! function_exists( 'wc_get_cart_url' ) || ! function_exists( 'rest_url' ) ) {
			return null;
		}

		return [
			'endpoint'      => rest_url( 'wc/store/v1/cart/extensions' ),
			'cart_endpoint' => rest_url( 'wc/store/v1/cart' ),
			'namespace'     => self::CART_NAMESPACE,
			'nonce'         => wp_create_nonce( 'wc_store_api' ),
			'cart_url'      => (string) wc_get_cart_url(),
		];
	}
}
