<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The per-Hall record of the last SUCCESSFULLY COMMITTED snapshot application.
 *
 * This class owns the option name, the stored shape, the ordering comparison,
 * and the post-commit cache invalidation. It deliberately owns no write path:
 * the marker row is created or updated by PortalSnapshotTransactionTransport
 * inside the same transaction as the Game Type and Session-template changes, so
 * a committed application and its marker can never diverge. update_option()
 * cannot be used there — it fires filters and touches the object cache, and the
 * captured mysqli session must stay callback-free between BEGIN and COMMIT.
 *
 * The marker is per-site: it lives in the hall's own `{$wpdb->prefix}options`
 * table, so the site prefix is the isolation boundary and no `blog_id` column is
 * needed or permitted.
 *
 * Ordering uses ONLY the envelope `generated_at` that Portal v1 already sends.
 * No Portal field is invented, and per-record `updated_at` is deliberately not
 * persisted: the snapshot is complete and non-delta, so per-record timestamps
 * contribute nothing to ordering.
 */
final class PortalSyncState {

	/** Non-autoloaded per-site option holding the last committed application. */
	public const OPTION_NAME = 'bc_portal_snapshot_sync_state';

	/**
	 * WordPress 6.6+ stores an explicit non-autoload choice as 'off'. Verified
	 * against this installation's wp-includes/option.php, where
	 * wp_determine_option_autoload_value() returns 'off' for a false autoload and
	 * wp_load_alloptions() selects only 'yes', 'on', 'auto-on', and 'auto'.
	 *
	 * The transport writes this literal, so a WordPress upgrade that changed the
	 * vocabulary would need this constant revisited rather than silently
	 * autoloading a marker on every request.
	 */
	public const AUTOLOAD_VALUE = 'off';

	/** The exact Portal Atom serialization PortalSnapshotValidator accepts. */
	private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:sP';

	/**
	 * Encode a marker for storage.
	 *
	 * `generated_at` is stored VERBATIM as the Portal sent it. Only the ordering
	 * comparison converts it to an instant, and that conversion never round-trips
	 * back into storage.
	 *
	 * @return string|\WP_Error
	 */
	public static function encode( int $portal_hall_id, string $generated_at, string $applied_at ) {
		$encoded = wp_json_encode(
			[
				'portal_hall_id' => $portal_hall_id,
				'generated_at'   => $generated_at,
				'applied_at'     => $applied_at,
			]
		);

		if ( ! is_string( $encoded ) ) {
			return new \WP_Error(
				'portal_sync_state_unencodable',
				'The Portal synchronization marker could not be encoded.',
				[ 'status' => 'portal_sync_state_unencodable' ]
			);
		}

		return $encoded;
	}

	/**
	 * Decode a stored marker into its parts, or null when it is unusable.
	 *
	 * null here means UNUSABLE, never "no previous state". Only an ABSENT row is
	 * "no previous state". A marker that exists but cannot be decoded, carries an
	 * unparseable timestamp, or names a different Portal Hall is a hard error at
	 * the transport, rejected with zero writes.
	 *
	 * Treating an unusable marker as absent would silently bypass stale-order
	 * protection exactly when the ordering record is least trustworthy, letting an
	 * arbitrarily old snapshot overwrite current Portal-owned names. Recovery is
	 * an operator decision — deleting or repairing the marker row — not something
	 * this class may assume.
	 *
	 * @return array{portal_hall_id:int,generated_at:string,applied_at:string}|null
	 */
	public static function decode( ?string $stored ): ?array {
		if ( null === $stored || '' === $stored ) {
			return null;
		}

		$decoded = json_decode( $stored, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$portal_hall_id = $decoded['portal_hall_id'] ?? null;
		$generated_at   = $decoded['generated_at'] ?? null;
		$applied_at     = $decoded['applied_at'] ?? null;
		if ( ! is_int( $portal_hall_id ) || $portal_hall_id < 1 || ! is_string( $generated_at ) || ! is_string( $applied_at ) ) {
			return null;
		}

		return [
			'portal_hall_id' => $portal_hall_id,
			'generated_at'   => $generated_at,
			'applied_at'     => $applied_at,
		];
	}

	/**
	 * Is the incoming snapshot STRICTLY older than the last committed one?
	 *
	 * Equal instants apply. Portal `generated_at` has one-second resolution, so
	 * two genuinely different snapshots can share a value; applying a duplicate is
	 * a no-op by construction, while skipping a real change is data loss. Only a
	 * strictly older instant is rejected.
	 *
	 * Both operands are validated Atom strings with explicit numeric offsets, so
	 * comparison happens on the absolute instant rather than on the text: a
	 * snapshot generated at 13:00-05:00 is NEWER than one at 17:30+00:00, and
	 * string ordering would get that backwards.
	 *
	 * Callers MUST have established that both operands are Portal timestamps
	 * first — the transport rejects an unusable marker before it gets here. The
	 * defensive branch below therefore fails CLOSED, treating an unparseable
	 * operand as older rather than waving it through, because the alternative is
	 * to bypass ordering protection on exactly the input that could not be read.
	 *
	 * A null stored value means the marker row is ABSENT, which is the only
	 * genuine "no previous state" case.
	 */
	public static function isStrictlyOlder( string $incoming_generated_at, ?string $stored_generated_at ): bool {
		if ( null === $stored_generated_at ) {
			return false;
		}

		$incoming = self::instant( $incoming_generated_at );
		$stored   = self::instant( $stored_generated_at );
		if ( null === $incoming || null === $stored ) {
			return true;
		}

		return $incoming < $stored;
	}

	/** Is this the exact Portal Atom serialization the v1 contract accepts? */
	public static function isPortalTimestamp( string $value ): bool {
		return null !== self::instant( $value );
	}

	/**
	 * Drop the object-cache view of the marker AFTER a confirmed commit.
	 *
	 * Both keys matter. A prior get_option() miss records the name in the
	 * 'notoptions' set, and a marker row created inside the transaction would stay
	 * invisible behind that negative cache entry for the rest of the request.
	 */
	public static function invalidateCache(): void {
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Read the current marker for the CURRENT site, bypassing the object cache.
	 *
	 * Used by callers and tests that need the durable value rather than whatever a
	 * previous read primed. The transport does not use this: it reads the row
	 * under `FOR UPDATE` on the captured mysqli session instead.
	 *
	 * @return array{portal_hall_id:int,generated_at:string,applied_at:string}|null
	 */
	public static function readUncached(): ?array {
		global $wpdb;

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::OPTION_NAME
			)
		);

		return self::decode( null === $stored ? null : (string) $stored );
	}

	/** @return int|null UNIX instant, or null when the value is not a Portal timestamp. */
	private static function instant( string $value ): ?int {
		$parsed = \DateTimeImmutable::createFromFormat( self::TIMESTAMP_FORMAT, $value );
		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return null;
		}

		// createFromFormat() rolls impossible calendar values forward instead of
		// failing, so an exact round-trip is required before the instant is trusted.
		if ( $value !== $parsed->format( self::TIMESTAMP_FORMAT ) ) {
			return null;
		}

		return $parsed->getTimestamp();
	}
}
