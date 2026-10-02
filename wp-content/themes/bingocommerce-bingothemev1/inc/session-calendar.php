<?php
/**
 * Presentation helpers for the homepage Session calendar. The Sessions plugin
 * decides eligibility, selection, months and links through the read-only
 * `bingocommerce_homepage_session_calendar` filter; this class only checks that
 * the response is usable, lays out month grids and supplies the visible text.
 */

declare(strict_types=1);

namespace BingoCommerce\BingoThemeV1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SessionCalendar {

	public const FILTER = 'bingocommerce_homepage_session_calendar';

	private const STATES   = [ 'date', 'date_empty', 'month', 'month_empty', 'none', 'invalid', 'month_unavailable', 'session_unavailable', 'mismatch' ];
	private const RECOVERY = [ 'invalid', 'month_unavailable', 'session_unavailable', 'mismatch' ];
	private const TYPES    = [ 'paper', 'digital', 'hybrid' ];

	/**
	 * The provider's response when it is usable, `[ 'status' => 'unavailable' ]`
	 * when the provider says so, and null for anything else — no provider, a
	 * non-array, an unknown status or missing fields. Null and unavailable both
	 * render "Sessions can't be shown right now."; neither is "no Sessions".
	 *
	 * @param mixed $value
	 * @return array<string,mixed>|null
	 */
	public static function model( $value ): ?array {
		if ( ! is_array( $value ) || 1 !== ( $value['version'] ?? null ) ) {
			return null;
		}
		$status = $value['status'] ?? null;
		if ( 'unavailable' === $status ) {
			return [ 'status' => 'unavailable' ];
		}
		if ( ! in_array( $status, [ 'ok', 'partial' ], true )
			|| ! self::is_date( $value['today'] ?? null ) || ! self::is_month( $value['month'] ?? null )
			|| ! in_array( $value['state'] ?? null, self::STATES, true )
			|| ! is_string( $value['available_url'] ?? null ) || ! is_string( $value['selected_label'] ?? null ) ) {
			return null;
		}

		$months = $value['months'] ?? null;
		if ( ! is_array( $months ) || [] === $months || ! array_is_list( $months ) ) {
			return null;
		}
		$shown = false;
		foreach ( $months as $month ) {
			if ( ! is_array( $month ) || ! self::is_month( $month['month'] ?? null ) || ! is_string( $month['label'] ?? null )
				|| ! self::is_link( $month['prev'] ?? null ) || ! self::is_link( $month['next'] ?? null ) ) {
				return null;
			}
			$shown = $shown || $month['month'] === $value['month'];
		}

		$dates = $value['dates'] ?? null;
		if ( ! $shown || ! is_array( $dates ) ) {
			return null;
		}
		foreach ( $dates as $date => $entry ) {
			$sessions = is_array( $entry ) ? ( $entry['sessions'] ?? null ) : null;
			if ( ! self::is_date( $date ) || $date !== ( $entry['date'] ?? null ) || ! is_string( $entry['label'] ?? null ) || ! is_string( $entry['url'] ?? null )
				|| ! is_array( $sessions ) || [] === $sessions || ! array_is_list( $sessions ) ) {
				return null;
			}
			foreach ( $sessions as $session ) {
				if ( ! self::is_session( $session ) || $date !== $session['date'] ) {
					return null;
				}
			}
		}

		$state    = $value['state'];
		$selected = $value['selected_date'] ?? null;
		$session  = $value['session'] ?? null;
		$packages = $value['packages'] ?? null;
		if ( ( null !== $selected && ! self::is_date( $selected ) )
			|| ( 'date' === $state && ( null === $selected || ! isset( $dates[ $selected ] ) ) )
			|| ( 'date_empty' === $state && ( null === $selected || isset( $dates[ $selected ] ) ) )
			|| ( ! in_array( $state, [ 'date', 'date_empty' ], true ) && null !== $selected ) ) {
			return null;
		}
		if ( null !== $session ) {
			if ( 'date' !== $state || ! self::is_session( $session ) || $session['date'] !== $selected || ! self::is_packages( $packages ) ) {
				return null;
			}
		} elseif ( null !== $packages ) {
			return null;
		}

		return $value;
	}

	/**
	 * Monday-first weeks for one calendar month. Pure date arithmetic on a fixed
	 * UTC calendar: no clock, no time zone, no timestamps reach the page.
	 *
	 * @return array<int,array<int,array{date:string,day:int,in_month:bool}>>
	 */
	public static function weeks( string $month ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$first = \DateTimeImmutable::createFromFormat( '!Y-m-d', $month, $utc );
		if ( ! $first instanceof \DateTimeImmutable || $first->format( 'Y-m-d' ) !== $month ) {
			return [];
		}
		$offset = (int) $first->format( 'N' ) - 1;
		$cells  = (int) ( ceil( ( $offset + (int) $first->format( 't' ) ) / 7 ) * 7 );
		$start  = $first->modify( '-' . $offset . ' days' );
		$weeks  = [];
		for ( $i = 0; $i < $cells; $i++ ) {
			$day                          = $start->modify( '+' . $i . ' days' );
			$weeks[ intdiv( $i, 7 ) ][] = [ 'date' => $day->format( 'Y-m-d' ), 'day' => (int) $day->format( 'j' ), 'in_month' => $day->format( 'Y-m' ) === $first->format( 'Y-m' ) ];
		}

		return $weeks;
	}

	/** @return array<int,array{0:string,1:string}> Short and full weekday names, Monday first. */
	public static function weekdays(): array {
		return [
			[ __( 'Mo', Theme::TEXT_DOMAIN ), __( 'Monday', Theme::TEXT_DOMAIN ) ],
			[ __( 'Tu', Theme::TEXT_DOMAIN ), __( 'Tuesday', Theme::TEXT_DOMAIN ) ],
			[ __( 'We', Theme::TEXT_DOMAIN ), __( 'Wednesday', Theme::TEXT_DOMAIN ) ],
			[ __( 'Th', Theme::TEXT_DOMAIN ), __( 'Thursday', Theme::TEXT_DOMAIN ) ],
			[ __( 'Fr', Theme::TEXT_DOMAIN ), __( 'Friday', Theme::TEXT_DOMAIN ) ],
			[ __( 'Sa', Theme::TEXT_DOMAIN ), __( 'Saturday', Theme::TEXT_DOMAIN ) ],
			[ __( 'Su', Theme::TEXT_DOMAIN ), __( 'Sunday', Theme::TEXT_DOMAIN ) ],
		];
	}

	/** Structured start time and its saved zone, or '' for a Session without one. */
	public static function time_line( array $session ): string {
		if ( null === $session['time'] ) {
			return '';
		}

		return trim( $session['time'] . ' ' . (string) $session['timezone'] );
	}

	public static function type_label( string $type ): string {
		$labels = [
			'paper'   => __( 'Paper Package', Theme::TEXT_DOMAIN ),
			'digital' => __( 'Digital Package', Theme::TEXT_DOMAIN ),
			'hybrid'  => __( 'Hybrid Package', Theme::TEXT_DOMAIN ),
		];

		return $labels[ $type ] ?? '';
	}

	/** Whether this state offers the "See available dates" recovery link. */
	public static function offers_recovery( string $state ): bool {
		return in_array( $state, self::RECOVERY, true ) || in_array( $state, [ 'date_empty', 'month_empty' ], true );
	}

	/**
	 * The request-state message, or '' when the state needs none. A partial
	 * result never claims that nothing exists: it says nothing could be confirmed.
	 *
	 * @param array<string,mixed> $calendar
	 */
	public static function message( array $calendar ): string {
		$partial = 'partial' === $calendar['status'];
		switch ( $calendar['state'] ) {
			case 'date_empty':
				return $partial
					/* translators: %s: date in MM/DD/YYYY form. */
					? sprintf( __( 'No Sessions could be confirmed for %s.', Theme::TEXT_DOMAIN ), $calendar['selected_label'] )
					/* translators: %s: date in MM/DD/YYYY form. */
					: sprintf( __( 'No Sessions are available for purchase on %s.', Theme::TEXT_DOMAIN ), $calendar['selected_label'] );
			case 'month':
				return self::month_messages( $calendar )['month'];
			case 'month_empty':
				return self::month_messages( $calendar )['month_empty'];
			case 'none':
				return __( 'No Sessions are currently available for purchase.', Theme::TEXT_DOMAIN );
			case 'invalid':
				return __( 'That selection isn’t valid.', Theme::TEXT_DOMAIN );
			case 'month_unavailable':
				return __( 'That month can’t be shown.', Theme::TEXT_DOMAIN );
			case 'session_unavailable':
				return __( 'That Session is no longer available for purchase.', Theme::TEXT_DOMAIN );
			case 'mismatch':
				return __( 'That date and Session don’t match.', Theme::TEXT_DOMAIN );
		}

		return '';
	}

	/** Whether the request was month-only: no date or Session selected, so arrows may re-word the message. */
	public static function is_month_only( string $state ): bool {
		return in_array( $state, [ 'month', 'month_empty' ], true );
	}

	/**
	 * Both month-only messages, so month arrows can switch between them using
	 * only server-rendered dates. A partial result keeps the "could be confirmed"
	 * wording.
	 *
	 * @param array<string,mixed> $calendar
	 * @return array{month:string,month_empty:string}
	 */
	public static function month_messages( array $calendar ): array {
		return [
			'month'       => __( 'Choose a highlighted date.', Theme::TEXT_DOMAIN ),
			'month_empty' => 'partial' === $calendar['status'] ? __( 'No Sessions could be confirmed this month.', Theme::TEXT_DOMAIN ) : __( 'No Sessions are available this month.', Theme::TEXT_DOMAIN ),
		];
	}

	/** The largest integer a browser can carry exactly (Number.MAX_SAFE_INTEGER). */
	private const MAX_SAFE_ID = 9007199254740991;

	/**
	 * One listed Package's add-ons as a display state: `ok` (with items), `empty`
	 * (a successful read that found none), `partial` (verified items only) or
	 * `unavailable`. Anything missing or malformed is `unavailable`, so a failed
	 * or incomplete read never looks like a Package without add-ons.
	 *
	 * @param array<string,mixed> $item One Package item from the provider.
	 * @return array{state:string,items:array<int,array<string,mixed>>}
	 */
	public static function addons( array $item ): array {
		$unavailable = [ 'state' => 'unavailable', 'items' => [] ];
		$value       = $item['addons'] ?? null;
		if ( ! is_array( $value ) || ! in_array( $value['status'] ?? null, [ 'ok', 'partial' ], true )
			|| ! is_array( $value['items'] ?? null ) || ! array_is_list( $value['items'] ) ) {
			return $unavailable;
		}
		$seen = [];
		foreach ( $value['items'] as $addon ) {
			if ( ! is_array( $addon ) || ! self::is_id( $addon['id'] ?? null ) || isset( $seen[ $addon['id'] ] )
				|| ! is_string( $addon['name'] ?? null ) || '' === trim( $addon['name'] )
				|| ! is_int( $addon['image_id'] ?? null ) || $addon['image_id'] < 0
				|| ! is_int( $addon['max_quantity'] ?? null ) || $addon['max_quantity'] < 1 || $addon['max_quantity'] > 99
				|| ! is_string( $addon['price_html'] ?? null ) || '' === trim( $addon['price_html'] ) ) {
				return $unavailable;
			}
			$seen[ $addon['id'] ] = true;
		}
		if ( [] === $value['items'] ) {
			// Only a successful read may say there are none.
			return 'ok' === $value['status'] ? [ 'state' => 'empty', 'items' => [] ] : $unavailable;
		}

		return [ 'state' => $value['status'], 'items' => $value['items'] ];
	}

	/** The largest component quantity the Catalog stores (an unsigned 32-bit column). */
	private const MAX_COMPONENT_QUANTITY = 4294967295;

	/**
	 * One listed Package's "What's included" as a display state: `ok` with every
	 * saved base component (name, configured quantity, Paper or Digital), or
	 * `unavailable` for anything missing, partial or malformed, which renders
	 * "Package contents can't be shown right now." Optional add-ons are never part
	 * of it, and a component quantity is never a digital-face count.
	 *
	 * @param array<string,mixed> $item One Package item from the provider.
	 * @return array{state:string,items:array<int,array{name:string,quantity:int,medium:string}>}
	 */
	public static function contents( array $item ): array {
		$unavailable = [ 'state' => 'unavailable', 'items' => [] ];
		$value       = $item['contents'] ?? null;
		if ( ! is_array( $value ) || 'ok' !== ( $value['status'] ?? null ) || ! is_array( $value['items'] ?? null )
			|| [] === $value['items'] || ! array_is_list( $value['items'] ) ) {
			return $unavailable;
		}
		foreach ( $value['items'] as $component ) {
			if ( ! is_array( $component ) || ! is_string( $component['name'] ?? null ) || '' === trim( $component['name'] )
				|| ! is_int( $component['quantity'] ?? null ) || $component['quantity'] < 1 || $component['quantity'] > self::MAX_COMPONENT_QUANTITY
				|| ! in_array( $component['medium'] ?? null, [ 'paper', 'digital' ], true ) ) {
				return $unavailable;
			}
		}

		return [ 'state' => 'ok', 'items' => $value['items'] ];
	}

	public static function medium_label( string $medium ): string {
		$labels = [
			'paper'   => __( 'Paper', Theme::TEXT_DOMAIN ),
			'digital' => __( 'Digital', Theme::TEXT_DOMAIN ),
		];

		return $labels[ $medium ] ?? '';
	}

	/**
	 * The selected Session's purchase settings when every value is usable, the
	 * Session and Package IDs survive the browser exactly, and every URL is on
	 * this site's own host; otherwise null and the cards stay informational.
	 *
	 * @param array<string,mixed> $calendar A response accepted by model().
	 * @return array{endpoint:string,cart_endpoint:string,namespace:string,nonce:string,cart_url:string}|null
	 */
	public static function purchase( array $calendar ): ?array {
		$value    = $calendar['purchase'] ?? null;
		$session  = $calendar['session'] ?? null;
		$packages = $calendar['packages']['items'] ?? null;
		if ( ! is_array( $value ) || ! is_array( $session ) || ! self::is_id( $session['id'] ?? null ) || ! is_array( $packages ) || [] === $packages ) {
			return null;
		}
		foreach ( $packages as $package ) {
			if ( ! self::is_id( $package['id'] ?? null ) ) {
				return null;
			}
		}
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		foreach ( [ 'endpoint', 'cart_endpoint', 'cart_url' ] as $key ) {
			$url = $value[ $key ] ?? null;
			if ( ! is_string( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true )
				|| '' === $host || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
				return null;
			}
		}
		if ( ! is_string( $value['namespace'] ?? null ) || 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]*\z/', $value['namespace'] )
			|| ! is_string( $value['nonce'] ?? null ) || 1 !== preg_match( '/\A[^\s\x00-\x1F\x7F]{1,128}\z/', $value['nonce'] ) ) {
			return null;
		}

		return [
			'endpoint'      => $value['endpoint'],
			'cart_endpoint' => $value['cart_endpoint'],
			'namespace'     => $value['namespace'],
			'nonce'         => $value['nonce'],
			'cart_url'      => $value['cart_url'],
		];
	}

	/**
	 * Visible purchase wording for the optional script, through the theme's own
	 * text domain. Known cart refusal codes are worded here; any other refusal's
	 * own message is shown as text only.
	 *
	 * @return array<string,string>
	 */
	public static function purchase_strings(): array {
		return [
			'notChosen'        => __( 'Not chosen yet', Theme::TEXT_DOMAIN ),
			'noPackage'        => __( 'No Package chosen yet', Theme::TEXT_DOMAIN ),
			/* translators: %s: the add-on search text. */
			'noMatches'        => __( 'No add-ons match “%s”. Clear the search to see every add-on.', Theme::TEXT_DOMAIN ),
			'none'             => __( 'None', Theme::TEXT_DOMAIN ),
			'noneEmpty'        => __( 'None. This Package has no add-ons.', Theme::TEXT_DOMAIN ),
			'noneUnavailable'  => __( 'None. Add-ons can’t be shown right now, so this adds the Package only.', Theme::TEXT_DOMAIN ),
			/* translators: 1: add-on name, 2: quantity. */
			'addonQuantity'    => __( '%1$s × %2$d', Theme::TEXT_DOMAIN ),
			'each'             => __( 'each', Theme::TEXT_DOMAIN ),
			/* translators: %s: add-on name. */
			'checkQuantity'    => __( '%s: check the quantity', Theme::TEXT_DOMAIN ),
			'choosePackage'    => __( 'Choose a Package.', Theme::TEXT_DOMAIN ),
			/* translators: 1: add-on name, 2: largest permitted quantity. */
			'quantityRange'    => __( 'Enter a whole number from 1 to %2$d for %1$s.', Theme::TEXT_DOMAIN ),
			'addToCart'        => __( 'Add to cart', Theme::TEXT_DOMAIN ),
			'adding'           => __( 'Adding…', Theme::TEXT_DOMAIN ),
			/* translators: 1: Package name, 2: Session name, 3: Session date (and time). */
			'added'            => __( 'Added %1$s for %2$s on %3$s to your cart.', Theme::TEXT_DOMAIN ),
			/* translators: %s: comma-separated add-ons with quantities. */
			'addedAddons'      => __( 'Add-ons: %s.', Theme::TEXT_DOMAIN ),
			'locked'           => __( 'This selection is in your cart. To choose a different Package, remove it from your cart first.', Theme::TEXT_DOMAIN ),
			'viewCart'         => __( 'View cart', Theme::TEXT_DOMAIN ),
			'refresh'          => __( 'Refresh this Session’s Packages', Theme::TEXT_DOMAIN ),
			'expired'          => __( 'This page has expired. Reload it and try again.', Theme::TEXT_DOMAIN ),
			'uncertain'        => __( 'We couldn’t confirm your selection was added. Check your cart before trying again.', Theme::TEXT_DOMAIN ),
			'unavailable'      => __( 'Purchasing isn’t available for this store right now. Please try again later.', Theme::TEXT_DOMAIN ),
			'packageLimit'     => __( 'Only one Bingo Package is allowed in your cart. To choose a different Package, open your cart and remove the current Package first.', Theme::TEXT_DOMAIN ),
			'selectionInvalid' => __( 'This selection couldn’t be added to your cart. It may no longer be available, or something already in your cart may need attention. Refresh this Session’s Packages or check your cart.', Theme::TEXT_DOMAIN ),
			'addonIneligible'  => __( 'One of the add-ons you chose is no longer available with this Package. Refresh this Session’s Packages to see its current add-ons.', Theme::TEXT_DOMAIN ),
			'electronicLimit'  => __( 'Your selection exceeds this Hall’s digital bingo face limit. Reduce the digital add-ons or choose a different Package.', Theme::TEXT_DOMAIN ),
			'unchecked'        => __( 'This selection couldn’t be checked right now, so it wasn’t added to your cart. Please try again later.', Theme::TEXT_DOMAIN ),
			'notSent'          => __( 'This selection couldn’t be sent. Reload the page and try again.', Theme::TEXT_DOMAIN ),
			'fallback'         => __( 'This selection couldn’t be added to your cart.', Theme::TEXT_DOMAIN ),
		];
	}

	/** @param mixed $value A positive integer ID the browser can carry exactly. */
	private static function is_id( $value ): bool {
		return is_int( $value ) && $value > 0 && $value <= self::MAX_SAFE_ID;
	}

	/** @param mixed $value */
	private static function is_date( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/** @param mixed $value */
	private static function is_month( $value ): bool {
		return self::is_date( $value ) && '01' === substr( $value, 8, 2 );
	}

	/** @param mixed $value Null (no link) or a complete reachable-month link. */
	private static function is_link( $value ): bool {
		return null === $value || ( is_array( $value ) && self::is_month( $value['month'] ?? null ) && is_string( $value['label'] ?? null ) && is_string( $value['url'] ?? null ) );
	}

	/** @param mixed $value */
	private static function is_session( $value ): bool {
		return is_array( $value ) && is_int( $value['id'] ?? null ) && $value['id'] > 0 && self::is_date( $value['date'] ?? null )
			&& is_string( $value['date_label'] ?? null ) && is_string( $value['name'] ?? null ) && is_string( $value['packages_url'] ?? null )
			&& array_key_exists( 'time', $value ) && ( null === $value['time'] || is_string( $value['time'] ) )
			&& array_key_exists( 'timezone', $value ) && ( null === $value['timezone'] || is_string( $value['timezone'] ) );
	}

	/** @param mixed $value */
	private static function is_packages( $value ): bool {
		if ( ! is_array( $value ) || ! in_array( $value['status'] ?? null, [ 'ok', 'partial', 'unavailable' ], true ) || ! is_array( $value['items'] ?? null ) || ! array_is_list( $value['items'] ) ) {
			return false;
		}
		foreach ( $value['items'] as $item ) {
			if ( ! is_array( $item ) || ! is_int( $item['id'] ?? null ) || $item['id'] < 1 || ! in_array( $item['type'] ?? null, self::TYPES, true )
				|| ! is_string( $item['name'] ?? null ) || ! is_int( $item['image_id'] ?? null ) || $item['image_id'] < 0 || ! is_string( $item['price_html'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}
}
