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
