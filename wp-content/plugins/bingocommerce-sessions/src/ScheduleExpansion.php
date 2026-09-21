<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Date expansion, limits and the date-boundary rules (ADR-043 §3.9, §8.1-§8.3).
 *
 * PURE. No database, no WordPress, no clock. "Today" always arrives as an
 * argument, because §5.3 decides it from the SCHEDULE's own stored zone rather
 * than from the hall's current WordPress setting, and a class that read the
 * clock itself could not honour that.
 *
 * WHY EXPANSION IGNORES TIMEZONES ENTIRELY. A date is walked as a date: whole
 * calendar days on a date-only `DateTimeImmutable` in UTC, the same pattern
 * `SessionService::parse_exact_date()` already uses. If a zone participated,
 * a DST transition could shift, drop or duplicate a DATE — which is a different
 * and much worse failure than the instant-level ambiguity §5.2 handles. The
 * instant is computed later, per date, by ScheduleTimezone.
 */
final class ScheduleExpansion {

	public const MODE_DATES  = 'dates';
	public const MODE_WEEKLY = 'weekly';

	public const MODES = [ self::MODE_DATES, self::MODE_WEEKLY ];

	/** Maximum unique dates in ONE active definition (§2.2). Not a lifetime cap. */
	public const MAX_DATES = 400;

	/** Maximum inclusive span of a weekly recurrence, in calendar days (§3.9). */
	public const MAX_WEEKLY_SPAN_DAYS = 366;

	/** Bit 0 = Monday ... bit 6 = Sunday (ISO-8601). All seven set. */
	public const WEEKDAY_MASK_MAX = 127;

	/**
	 * A real calendar date, or null.
	 *
	 * Deliberately a private re-statement rather than a call into
	 * SessionService: this class is pure and must stay loadable and testable
	 * without the service, and the rule it encodes — strict `Y-m-d`, a real
	 * calendar day, an exact round-trip — is small enough that duplicating it
	 * is safer than coupling to a service whose constructor authorizes.
	 */
	public static function parse_date( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts ) ) {
			return null;
		}

		// checkdate() rejects year 0, month 13 and 30 February.
		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return null;
		}

		$parsed = $parsed->setTime( 0, 0, 0 );

		// createFromFormat() normalises 2030-02-30 into 2030-03-02, so the object
		// existing is not proof the input was real. Require the round-trip.
		return $parsed->format( 'Y-m-d' ) === $value ? $parsed : null;
	}

	/** Whether `$date` is strictly after `$today`, both `Y-m-d`. Fail-closed on garbage. */
	public static function is_tomorrow_or_later( string $date, string $today ): bool {
		$d = self::parse_date( $date );
		$t = self::parse_date( $today );

		return null !== $d && null !== $t && $d > $t;
	}

	/**
	 * Normalize, validate and de-duplicate an explicit date selection (§8.1, §8.3).
	 *
	 * `$previously_committed` is the set of dates in the definition being
	 * REPLACED. It is what makes an edit different from a create: a date already
	 * in the active definition may be kept even though it has since become today
	 * or historical (§8.3), because its occurrence already exists and is the
	 * durable record. A historical date that was NEVER committed cannot be added,
	 * because doing so would ask generation to create an occurrence in the past.
	 *
	 * `$is_edit` picks the REFUSAL, not the rule. The rule is the same either
	 * way — a past date that was never committed is not acceptable — but the two
	 * cases mean different things to the Manager and §8.1/§8.3 name them
	 * differently. On a create there is no previous definition at all, so a past
	 * date is simply the tomorrow-or-later rule being broken. On an edit the
	 * Manager is looking at a list that legitimately contains past dates they may
	 * keep, so adding a DIFFERENT past date is the specific thing §8.3 forbids.
	 *
	 * @param array<int,string> $dates
	 * @param array<int,string> $previously_committed
	 * @return array<int,string>|\WP_Error Sorted ascending, unique, `Y-m-d`.
	 */
	public static function canonical_dates( array $dates, string $today, array $previously_committed = [], bool $is_edit = false ) {
		$kept     = [];
		$existing = [];
		foreach ( $previously_committed as $committed ) {
			$existing[ trim( (string) $committed ) ] = true;
		}

		foreach ( $dates as $raw ) {
			$value  = trim( (string) $raw );
			$parsed = self::parse_date( $value );

			if ( null === $parsed ) {
				return self::error( 'invalid_schedule_date', 'A selected date is not a real calendar date in YYYY-MM-DD form.' );
			}

			/*
			 * A date already in the definition being replaced is EXEMPT (§8.3):
			 * its occurrence exists, is the durable record, and is untouchable.
			 * Keeping it changes nothing; dropping it also changes nothing. Only
			 * a date that is new to this schedule has to be in the future.
			 */
			if ( isset( $existing[ $value ] ) ) {
				$kept[ $value ] = $value;
				continue;
			}

			if ( ! self::is_tomorrow_or_later( $value, $today ) ) {
				return $is_edit
					? self::error(
						'historical_date_not_addable',
						'A date that has already passed cannot be added to a schedule. Existing dates may be kept, but a new one must be tomorrow or later.'
					)
					: self::error(
						'date_must_be_tomorrow_or_later',
						'Every date must be tomorrow or later in this hall\'s scheduling time zone.'
					);
			}

			// Keyed by the date itself, so a duplicated selection collapses
			// rather than consuming two of the 400 available entries.
			$kept[ $value ] = $value;
		}

		if ( [] === $kept ) {
			return self::error( 'schedule_requires_a_date', 'Select at least one date for this schedule.' );
		}

		if ( count( $kept ) > self::MAX_DATES ) {
			return self::error(
				'too_many_schedule_dates',
				sprintf(
					/* translators: %d: maximum number of dates in one schedule definition. */
					__( 'A schedule definition may hold at most %d dates. Drop dates that have already passed to make room.', 'bingocommerce-sessions' ),
					self::MAX_DATES
				)
			);
		}

		ksort( $kept, SORT_STRING );

		return array_values( $kept );
	}

	/**
	 * Validate a weekly recurrence (§8.2) and return its normalized bounds.
	 *
	 * `$require_future_start` is false for an edit: §8.2 preserves a
	 * `recurrence_start` that has since passed exactly as stored, and only a
	 * start the Manager actually SUPPLIES must be tomorrow-or-later. An edit
	 * whose whole range is already past is a legitimate, successful commit that
	 * simply generates nothing.
	 *
	 * @return array{recurrence_start:string,recurrence_end:string,weekday_mask:int}|\WP_Error
	 */
	public static function canonical_weekly( string $start, string $end, int $weekday_mask, string $today, bool $require_future_start ) {
		if ( $weekday_mask < 1 || $weekday_mask > self::WEEKDAY_MASK_MAX ) {
			// A weekly schedule with no weekday selected would expand to nothing
			// and silently generate nothing, which is indistinguishable on screen
			// from a schedule that is simply not running yet.
			return self::error( 'invalid_weekday_selection', 'Select at least one weekday for a weekly schedule.' );
		}

		$start  = trim( $start );
		$end    = trim( $end );
		$parsed = self::parse_date( $start );
		$ends   = self::parse_date( $end );

		if ( null === $parsed || null === $ends ) {
			return self::error( 'invalid_schedule_date', 'The recurrence start and end must be real calendar dates in YYYY-MM-DD form.' );
		}

		if ( $ends < $parsed ) {
			return self::error( 'invalid_recurrence_range', 'The recurrence end date cannot be before its start date.' );
		}

		// Inclusive span, so a start equal to its end is one day, not zero.
		$span = (int) $parsed->diff( $ends )->days + 1;
		if ( $span > self::MAX_WEEKLY_SPAN_DAYS ) {
			return self::error(
				'recurrence_range_too_long',
				sprintf(
					/* translators: %d: maximum span of a weekly recurrence in days. */
					__( 'A weekly recurrence may span at most %d days.', 'bingocommerce-sessions' ),
					self::MAX_WEEKLY_SPAN_DAYS
				)
			);
		}

		if ( $require_future_start && ! self::is_tomorrow_or_later( $start, $today ) ) {
			return self::error(
				'date_must_be_tomorrow_or_later',
				'The recurrence start date must be tomorrow or later in this hall\'s scheduling time zone.'
			);
		}

		return [
			'recurrence_start' => $start,
			'recurrence_end'   => $end,
			'weekday_mask'     => $weekday_mask,
		];
	}

	/**
	 * Every date a weekly recurrence covers, inclusive of both bounds.
	 *
	 * Walks whole calendar days and emits each one whose ISO weekday bit is set.
	 * `format('N')` is 1 for Monday through 7 for Sunday, so bit `N - 1` is the
	 * one to test — the mapping the schema documents.
	 *
	 * NOT filtered to the future: the caller decides that, because the same
	 * expansion feeds both the committed definition (which may legitimately
	 * contain past dates after an edit, §8.2) and reconciliation (which emits
	 * only dates from tomorrow onward).
	 *
	 * @return array<int,string>
	 */
	public static function expand_weekly( string $start, string $end, int $weekday_mask ): array {
		$cursor = self::parse_date( $start );
		$last   = self::parse_date( $end );

		if ( null === $cursor || null === $last || $last < $cursor || $weekday_mask < 1 ) {
			return [];
		}

		$dates = [];
		$guard = 0;
		while ( $cursor <= $last ) {
			if ( 0 !== ( $weekday_mask & ( 1 << ( (int) $cursor->format( 'N' ) - 1 ) ) ) ) {
				$dates[] = $cursor->format( 'Y-m-d' );
			}
			$cursor = $cursor->modify( '+1 day' );

			// The span is already capped at 366 by canonical_weekly(), but
			// expand_weekly() is also reachable from stored rows, and an
			// unbounded loop over corrupt bounds is not an acceptable outcome.
			if ( ++$guard > self::MAX_WEEKLY_SPAN_DAYS ) {
				break;
			}
		}

		return $dates;
	}

	/**
	 * The dates a definition covers, from the stored schedule row.
	 *
	 * @param array<int,string> $explicit_dates Rows at the active token; ignored for weekly.
	 * @return array<int,string>
	 */
	public static function expand( string $mode, array $explicit_dates, string $recurrence_start, string $recurrence_end, int $weekday_mask ): array {
		if ( self::MODE_WEEKLY === $mode ) {
			return self::expand_weekly( $recurrence_start, $recurrence_end, $weekday_mask );
		}

		$dates = [];
		foreach ( $explicit_dates as $date ) {
			$value = trim( (string) $date );
			if ( null !== self::parse_date( $value ) ) {
				$dates[ $value ] = $value;
			}
		}
		ksort( $dates, SORT_STRING );

		return array_values( $dates );
	}

	/**
	 * Only the dates strictly after `$today` (§8.2: reconciliation emits from
	 * tomorrow onward).
	 *
	 * @param array<int,string> $dates
	 * @return array<int,string>
	 */
	public static function strictly_future( array $dates, string $today ): array {
		$future = [];
		foreach ( $dates as $date ) {
			if ( self::is_tomorrow_or_later( (string) $date, $today ) ) {
				$future[] = (string) $date;
			}
		}

		return $future;
	}

	/**
	 * The dates in `$dates` that are NOT strictly future — reported as
	 * `skipped_past` by a run rather than silently dropped (§8.2).
	 *
	 * @param array<int,string> $dates
	 * @return array<int,string>
	 */
	public static function already_past( array $dates, string $today ): array {
		$past = [];
		foreach ( $dates as $date ) {
			if ( ! self::is_tomorrow_or_later( (string) $date, $today ) ) {
				$past[] = (string) $date;
			}
		}

		return $past;
	}

	/**
	 * The §4.2 stored occurrence display name.
	 *
	 * `canonical( template name )` truncated BY CHARACTERS to 183, then the
	 * fixed eight-character suffix: space, EM DASH (U+2014), space, `HH:MM`.
	 * 183 + 8 = 191, so the suffix always survives and the result always fits
	 * `varchar(191)` and `SessionService::DISPLAY_NAME_MAX`.
	 *
	 * The suffix is NEVER built from the WordPress `time_format` option, which a
	 * Manager can change at any moment; a stored name whose shape depended on a
	 * mutable setting could not be re-derived later to prove a rename.
	 */
	public static function occurrence_display_name( string $template_name, string $start_time_local ): string {
		// The same collapse-and-trim SessionService::canonical_display_name()
		// applies, so a name this class composes is already canonical when the
		// service validates it and cannot be silently altered at the boundary.
		$name = trim( preg_replace( '/\s+/u', ' ', $template_name ) ?? '' );

		return mb_substr( $name, 0, 183 ) . ' — ' . ScheduleTimezone::display_time( $start_time_local );
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-sessions' ), [ 'status' => $code ] );
	}
}
