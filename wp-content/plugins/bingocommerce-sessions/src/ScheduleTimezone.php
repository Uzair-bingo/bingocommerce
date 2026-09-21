<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timezone capture, minute-precision time validation, and the exact-instant
 * algorithm (ADR-043 §5).
 *
 * `capture_hall_zone()` reads the hall's `timezone_string` option directly
 * through `get_option()`. It deliberately bypasses `wp_timezone_string()`,
 * which can synthesize a fixed offset for an unconfigured hall. Everything
 * else takes its inputs as arguments, so the whole of §5.2 is testable without
 * a hall, a site or a row.
 *
 * WHY A NAMED ZONE IS MANDATORY. `wp_timezone_string()`
 * (`wp-includes/functions.php:124-141`) returns `timezone_string` when the hall
 * has one and otherwise SYNTHESISES a fixed `+HH:MM` from `gmt_offset`. A fixed
 * offset has no DST rules at all: every local time in it is unambiguous and
 * always exists, which means an occurrence generated under one would be an hour
 * out for half the year and nothing would detect it. Creation is refused rather
 * than allowed to produce that.
 */
final class ScheduleTimezone {

	/**
	 * Exactly `H:i` on the 24-hour clock.
	 *
	 * Surrounding whitespace is trimmed first, exactly as
	 * `SessionService::canonical_date()` and `canonical_display_name()` already
	 * do, so the validated string and the stored string are the same string.
	 *
	 * The `D` modifier is kept regardless. Without it `$` also matches before a
	 * trailing newline, and this constant is the single definition of a valid
	 * start time — a later caller that reaches it without trimming must not
	 * quietly get a different answer than this one does.
	 */
	private const TIME_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/D';

	/**
	 * Half the window `getTransitions()` is asked for, in seconds.
	 *
	 * 48 hours either side of the naive timestamp. Any UTC offset that could
	 * possibly render the requested wall time lies within one day of it, so two
	 * days of slack covers every real zone while keeping the transition list
	 * short.
	 */
	private const TRANSITION_WINDOW = 172800;

	/**
	 * The hall's scheduling zone, or a WP_Error naming why it cannot be used.
	 *
	 * Captured ONCE, by the provisional INSERT, and immutable for the schedule's
	 * whole life (§5.1). A schedule in the wrong zone is corrected by archiving
	 * it and creating a replacement, never by an UPDATE.
	 *
	 * @return string|\WP_Error
	 */
	public static function capture_hall_zone() {
		$configured = trim( (string) get_option( 'timezone_string', '' ) );

		/*
		 * Read from the OPTION, not from wp_timezone_string(), precisely because
		 * that function hides the distinction this check exists to make: it
		 * returns a synthesised offset for an unconfigured hall, so asking it
		 * "is this a named zone?" always answers yes.
		 */
		if ( '' === $configured ) {
			return self::error(
				'hall_timezone_not_named',
				'This hall has no named time zone. Use Set Hall Timezone on the Add to Store screen to choose a city or region — not a UTC offset — before creating a schedule.'
			);
		}

		return self::validate_zone( $configured );
	}

	/**
	 * A named IANA identifier, or a WP_Error.
	 *
	 * THE AUTHORITY IS `DateTimeZone::listIdentifiers( DateTimeZone::ALL )`, not
	 * a successful `new DateTimeZone()`. Measured against PHP 8.4.24, the
	 * constructor ACCEPTS every one of `+05:30`, `-0500`, `EST` and `GMT` — a
	 * fixed offset and an abbreviation are perfectly valid DateTimeZone objects —
	 * so constructing one proves nothing about whether the value carries
	 * daylight-saving rules. The ALL list contains the 419 named IANA zones and
	 * excludes every offset spelling and every abbreviation, which is exactly the
	 * distinction §5.1 requires. It is also the list WordPress's own Settings
	 * dropdown offers, so a hall configured through the admin screen always
	 * satisfies this.
	 *
	 * `DateTimeZone::getType()` is deliberately not used: it does not exist as a
	 * public method on this runtime, verified by direct probe.
	 *
	 * UTC is accepted. It is a named identifier a hall can legitimately be set
	 * to, not a synthesised offset, and its lack of transitions is a real
	 * property of that zone rather than missing information.
	 *
	 * @return string|\WP_Error
	 */
	public static function validate_zone( string $zone ) {
		$zone = trim( $zone );

		if ( '' === $zone ) {
			return self::error( 'hall_timezone_not_named', 'A named scheduling time zone is required.' );
		}

		if ( ! in_array( $zone, \DateTimeZone::listIdentifiers( \DateTimeZone::ALL ), true ) ) {
			return self::error(
				'hall_timezone_not_named',
				'Scheduling requires a named time zone such as America/New_York. A UTC offset or an abbreviation carries no daylight-saving rules and cannot be used.'
			);
		}

		try {
			$parsed = new \DateTimeZone( $zone );
		} catch ( \Exception $e ) {
			return self::error( 'hall_timezone_not_named', 'The hall time zone is not a recognised named time zone.' );
		}

		if ( mb_strlen( $zone ) > 64 ) {
			// varchar(64) on both bingo_session_schedules and bingo_sessions.
			return self::error( 'hall_timezone_not_named', 'The hall time zone identifier is too long to store.' );
		}

		/*
		 * The INPUT spelling is returned, not getName(). Measured: getName() does
		 * not canonicalise aliases — `new DateTimeZone( 'US/Eastern' )->getName()`
		 * returns `US/Eastern` — so the two are equal for anything in the ALL list
		 * anyway, and returning the validated input keeps the stored value exactly
		 * the string whose membership was proved.
		 */
		unset( $parsed );

		return $zone;
	}

	/**
	 * `H:i` normalized to `HH:MM:00`, or a WP_Error.
	 *
	 * NEVER coerced. `18:00:30` is refused rather than truncated, because MySQL
	 * TIME stores seconds and a stored `18:00:30` would render as "— 18:00" in
	 * the occurrence name (§4.2), silently hiding thirty seconds and letting two
	 * different times produce one name.
	 *
	 * @return string|\WP_Error
	 */
	public static function canonical_start_time( string $time ) {
		$time = trim( $time );

		if ( 1 !== preg_match( self::TIME_PATTERN, $time ) ) {
			return self::error(
				'invalid_start_time',
				'The start time must be given as HH:MM on the 24-hour clock, for example 18:00.'
			);
		}

		return $time . ':00';
	}

	/** The `H:i` form of a stored `HH:MM:00`, for the §4.2 name suffix. */
	public static function display_time( string $stored ): string {
		return substr( trim( $stored ), 0, 5 );
	}

	/**
	 * The exact UTC instant for a local date and time in a named zone (§5.2).
	 *
	 * PHP IS NEVER ALLOWED TO NORMALISE. `new DateTimeImmutable( '2026-03-08
	 * 02:30:00', $tz )` silently yields 03:30 for a time that does not exist,
	 * and for an ambiguous one it silently picks an offset. Neither is a
	 * decision this platform may make on a Manager's behalf: the first would
	 * sell an occurrence an hour from where it was scheduled, and the second
	 * would pick one of two real instants an hour apart with no record of which.
	 * This algorithm therefore never constructs that object. It enumerates the
	 * distinct UTC offsets in force around the requested moment, renders the
	 * instant each one implies, and keeps only those that render back to exactly
	 * the requested wall time.
	 *
	 *   0 matches -> the time does not exist (spring-forward gap)
	 *   2 matches -> the time is ambiguous  (fall-back overlap)
	 *   1 match   -> the single real instant
	 *
	 * @param string $date  `Y-m-d`, already proved a real calendar date.
	 * @param string $time  `HH:MM:00`, already canonicalised.
	 * @param string $zone  A named identifier, already validated.
	 * @return string|\WP_Error `Y-m-d H:i:s` in UTC.
	 */
	public static function exact_instant( string $date, string $time, string $zone ) {
		try {
			$tz = new \DateTimeZone( $zone );
		} catch ( \Exception $e ) {
			return self::error( 'hall_timezone_not_named', 'The scheduling time zone is not a recognised named time zone.' );
		}

		$wall = $date . ' ' . $time;

		// Step 3: the wall-clock literal READ AS IF IT WERE UTC. This is not an
		// instant and is never treated as one; it is the arithmetic origin the
		// candidate offsets are subtracted from.
		$naive = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $wall, new \DateTimeZone( 'UTC' ) );
		if ( ! $naive instanceof \DateTimeImmutable || $naive->format( 'Y-m-d H:i:s' ) !== $wall ) {
			return self::error( 'invalid_start_time', 'The occurrence date and time could not be read as a calendar moment.' );
		}
		$naive_ts = $naive->getTimestamp();

		$transitions = $tz->getTransitions( $naive_ts - self::TRANSITION_WINDOW, $naive_ts + self::TRANSITION_WINDOW );
		if ( ! is_array( $transitions ) || [] === $transitions ) {
			return self::error( 'hall_timezone_not_named', 'The scheduling time zone has no usable transition data.' );
		}

		$offsets = [];
		foreach ( $transitions as $transition ) {
			if ( isset( $transition['offset'] ) ) {
				// Keyed by value so duplicates collapse: a window with no
				// transition in it reports the same offset repeatedly.
				$offsets[ (int) $transition['offset'] ] = (int) $transition['offset'];
			}
		}

		$matches = [];
		foreach ( $offsets as $offset ) {
			$candidate = $naive_ts - $offset;
			$rendered  = ( new \DateTimeImmutable( '@' . $candidate ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
			if ( $rendered === $wall ) {
				$matches[ $candidate ] = $candidate;
			}
		}

		if ( 1 < count( $matches ) ) {
			return self::error(
				'ambiguous_local_time',
				'That local time occurs twice on this date because the clocks go back, so the exact moment is ambiguous. Choose a different time.'
			);
		}

		if ( [] === $matches ) {
			return self::error(
				'nonexistent_local_time',
				'That local time does not exist on this date because the clocks go forward. Choose a different time.'
			);
		}

		return gmdate( 'Y-m-d H:i:s', (int) reset( $matches ) );
	}

	/**
	 * The calendar date it is RIGHT NOW in a named zone.
	 *
	 * §5.3: a generated occurrence's "today" and "strictly future" are decided in
	 * the occurrence's own stored zone, never in the hall's current WordPress
	 * setting, so changing that setting later cannot re-window an occurrence that
	 * already exists.
	 */
	public static function local_today( string $zone ): ?string {
		try {
			return ( new \DateTimeImmutable( 'now', new \DateTimeZone( $zone ) ) )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			// Fail closed: the caller treats null as "no date can be evaluated"
			// rather than substituting a plausible one.
			return null;
		}
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-sessions' ), [ 'status' => $code ] );
	}
}
