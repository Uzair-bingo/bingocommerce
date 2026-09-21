<?php

namespace BingoCommerce\Sessions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Result of a schedule mutation or a reconciliation run (ADR-043 §8.6).
 *
 * Mirrors SessionResult's shape — a status, the affected row, and non-fatal
 * settlement warnings — and adds the two things a generation run must never be
 * able to hide: whether it actually finished, and if not, which category of
 * failure stopped it.
 *
 * `complete` IS TRUE IF AND ONLY IF `status` IS `ok`. A run that did not finish
 * is never described as finished, always carries a `failure_category`, and its
 * counts describe exactly what was done before it stopped — phase-1 withdrawals
 * in particular stand and are counted accurately even when a later phase fails
 * (§10). The constructor enforces the invariant rather than trusting callers,
 * because "reported as complete when it was not" is the one failure mode here
 * that a Manager cannot detect from the screen.
 */
final class SessionScheduleResult {

	public const STATUS_OK                     = 'ok';
	public const STATUS_FAILED                 = 'failed';
	public const STATUS_SCHEDULE_STATE_CHANGED = 'schedule_state_changed';

	public const STATUSES = [
		self::STATUS_OK,
		self::STATUS_FAILED,
		self::STATUS_SCHEDULE_STATE_CHANGED,
	];

	/** The fixed §8.6 vocabulary. A category outside it is never reported. */
	public const FAILURE_CATEGORIES = [
		'preflight_conflict',
		'eligibility_failed',
		'write_failed',
		'duplicate_conflict',
		'template_renamed',
		'database_contention',
		'final_verification_failed',
	];

	/** Every count §8.6 names, in its documented order. All default to zero. */
	public const COUNTS = [
		'withdrawn',
		'created',
		'completed',
		'repaired',
		'retimed',
		'restored',
		'existing',
		'incomplete',
		'conflicted',
		'protected_time_conflict',
		'incomplete_integrity_conflict',
		'retained_outside_pattern',
		'retained_historical',
		'retained_historical_incomplete',
		'skipped_past',
		'withdraw_lost_race',
		'restore_lost_race',
		'not_attempted',
	];

	public string $status;

	public bool $complete;

	public ?string $failure_category;

	/** @var array<string,mixed> */
	public array $schedule;

	/** @var array<string,int> */
	public array $counts;

	/** @var array<int,array<string,mixed>> */
	public array $warnings;

	/** @var array<int,array<string,mixed>> Blockers and conflicts, each with its kind. */
	public array $blockers;

	/**
	 * @param array<string,mixed>             $schedule
	 * @param array<string,int>               $counts
	 * @param array<int,array<string,mixed>>  $warnings
	 * @param array<int,array<string,mixed>>  $blockers
	 */
	public function __construct(
		string $status,
		array $schedule = [],
		?string $failure_category = null,
		array $counts = [],
		array $warnings = [],
		array $blockers = []
	) {
		$this->status = in_array( $status, self::STATUSES, true ) ? $status : self::STATUS_FAILED;

		// The invariant, enforced rather than asserted.
		$this->complete = self::STATUS_OK === $this->status;

		if ( $this->complete ) {
			// A finished run has nothing to categorise, and a stale category
			// carried into a success would be read as a warning that is not real.
			$this->failure_category = null;
		} else {
			$this->failure_category = in_array( (string) $failure_category, self::FAILURE_CATEGORIES, true )
				? (string) $failure_category
				// A run that did not finish ALWAYS carries a category. An unset or
				// unrecognised one becomes write_failed rather than null, so the
				// screen can never show an unexplained incomplete run.
				: 'write_failed';
		}

		$this->schedule = $schedule;
		$this->warnings = $warnings;
		$this->blockers = $blockers;

		$normalized = [];
		foreach ( self::COUNTS as $name ) {
			$normalized[ $name ] = max( 0, (int) ( $counts[ $name ] ?? 0 ) );
		}
		// Every documented count is always present, so a caller reading one that
		// this run had no occasion to set gets 0 rather than a notice.
		$this->counts = $normalized;
	}

	/** A successful mutation that is not a generation run. */
	public static function ok( array $schedule, array $warnings = [] ): self {
		return new self( self::STATUS_OK, $schedule, null, [], $warnings );
	}

	/**
	 * The counts that are non-zero, for a notice that names what happened
	 * instead of printing eighteen zeroes.
	 *
	 * @return array<string,int>
	 */
	public function reported_counts(): array {
		return array_filter(
			$this->counts,
			static function ( int $value ): bool {
				return 0 !== $value;
			}
		);
	}
}
