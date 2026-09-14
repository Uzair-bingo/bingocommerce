<?php

namespace BingoCommerce\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The network address one Hall slug resolves to, and whether it is usable.
 *
 * READ-ONLY AND SIDE-EFFECT FREE. Nothing here creates, updates, reserves,
 * caches or writes. It answers two questions — "where on THIS network does this
 * slug live?" and "is a WordPress site already there?" — from supported
 * Multisite APIs, and it answers them the same way for every caller.
 *
 * THE TOPOLOGY IS RESOLVED, NEVER ASSUMED. `is_subdomain_install()` and the
 * current `WP_Network`'s own domain and path decide the answer, following the
 * installed `wp-admin/network/site-new.php` exactly, including its `^www.`
 * strip on the subdomain branch. Nothing here hard-codes a parent domain, a
 * scheme, or a path, and nothing here builds the Portal-facing final URL: that
 * is read back from the created site's own `siteurl` option by the caller.
 *
 * WHY THIS LIVES IN `bingocommerce-platform`. WordPress network topology is
 * platform-owned (ADR-042 plugin ownership), and ADR-042 requires BOTH the
 * orchestrator (before an ownership ID is minted for a slug) and the platform
 * consumer (before it creates) to resolve the reserved address. Duplicating the
 * resolution in two plugins is exactly what "Business logic MUST NOT be
 * duplicated between plugins" forbids, so there is one resolver and the
 * orchestrator reads it. It stays a READ; the only cross-plugin MUTATING
 * contract remains `bingocommerce_platform_provision_hall_site`. An installation
 * without this plugin therefore cannot resolve an address at all, which the
 * orchestrator reports as `platform_unavailable` — the same answer a missing
 * filter consumer produces.
 */
final class HallSiteAddress {

	/**
	 * A single DNS label.
	 *
	 * Mirrors the ADR-042 `site_slug` field contract. It is repeated here rather
	 * than imported because ADR-042 requires the platform consumer to validate
	 * its command DEFENSIVELY: a grammar this plugin borrowed from its caller
	 * could not defend against that caller.
	 */
	public const SLUG_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	/** Maximum slug length in octets. The pattern already bounds it; both hold. */
	public const SLUG_MAX_OCTETS = 63;

	/** Maximum total host length in octets (RFC 1035 presentation limit). */
	public const HOST_MAX_OCTETS = 253;

	private function __construct(
		public readonly string $slug,
		public readonly string $domain,
		public readonly string $path,
		public readonly int $network_id,
		public readonly bool $subdomain
	) {}

	/**
	 * Resolve one slug against the CURRENT network, or explain why it cannot be.
	 *
	 * Deliberately untyped: the slug arrives from a caller this class does not
	 * trust, and a scalar type declaration would let a weakly typed caller turn
	 * 5 into "5" and an object with __toString into a hostname before the
	 * grammar above ever saw it.
	 *
	 * @param mixed $slug
	 * @return self|\WP_Error `address_invalid` or `platform_unavailable`.
	 */
	public static function forSlug( $slug ) {
		try {
			return self::resolve( $slug );
		} catch ( \Throwable $throwable ) {
			// Never read: its message and trace can quote SQL and server text.
			unset( $throwable );

			return self::error( 'platform_unavailable', 'The network address could not be resolved.' );
		}
	}

	/**
	 * @param mixed $slug
	 * @return self|\WP_Error
	 */
	private static function resolve( $slug ) {
		if ( ! is_multisite() ) {
			return self::error( 'platform_unavailable', 'Hall site addresses require WordPress Multisite.' );
		}
		if ( ! is_string( $slug )
			|| strlen( $slug ) > self::SLUG_MAX_OCTETS
			|| 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			return self::error( 'address_invalid', 'The site slug is not a valid DNS label.' );
		}

		$network = get_network();
		if ( ! $network instanceof \WP_Network ) {
			return self::error( 'platform_unavailable', 'The current network could not be resolved.' );
		}

		$network_id      = (int) $network->id;
		$network_domain  = (string) $network->domain;
		$network_path    = (string) $network->path;
		if ( $network_id < 1 || '' === $network_domain || '' === $network_path ) {
			return self::error( 'platform_unavailable', 'The current network is not usable.' );
		}

		$subdomain = (bool) is_subdomain_install();

		if ( $subdomain ) {
			// The `^www.` strip is core's own, in wp-admin/network/site-new.php.
			$parent = (string) preg_replace( '|^www\.|', '', $network_domain );
			if ( '' === $parent ) {
				return self::error( 'platform_unavailable', 'The current network domain is not usable.' );
			}

			$domain = $slug . '.' . $parent;
			$path   = $network_path;

			if ( strlen( $domain ) > self::HOST_MAX_OCTETS ) {
				return self::error( 'address_invalid', 'The resulting host name is too long.' );
			}
		} else {
			/*
			 * Reserved names are a SUBDIRECTORY-ONLY restriction in WordPress:
			 * on a subdirectory network `example.com/feed/` would collide with a
			 * core rewrite endpoint, while `feed.example.com` collides with
			 * nothing. Core applies the check in exactly the same place.
			 */
			$reserved = get_subdirectory_reserved_names();
			if ( is_array( $reserved ) && in_array( $slug, $reserved, true ) ) {
				return self::error( 'address_invalid', 'The site slug is reserved by WordPress.' );
			}

			$domain = $network_domain;
			$path   = $network_path . $slug . '/';
		}

		return new self( $slug, $domain, $path, $network_id, $subdomain );
	}

	/**
	 * Is a WordPress site already at this address? Answered from `$wpdb->blogs`.
	 *
	 * NOT get_sites(), NOT WP_Site_Query, NOT domain_exists(), and NOT a cache.
	 * This answer decides whether a Hall may be given a PERMANENT ownership
	 * identity for an address, and every one of those routes can be changed by
	 * something other than the database:
	 *
	 *   - `domain_exists()` ends in `apply_filters( 'domain_exists', ... )`;
	 *   - WP_Site_Query fires `pre_get_sites` and `sites_pre_query`, either of
	 *     which can replace the result outright;
	 *   - both consult the object cache, where a stale or poisoned entry would
	 *     report an occupied address as free.
	 *
	 * A minted ownership identity cannot be taken back, so this reads the rows
	 * directly, through one prepared statement, with wpdb diagnostics suppressed
	 * only for the duration of that statement and restored on every path.
	 *
	 * FOUR OUTCOMES ARE KEPT APART, because collapsing any two of them turns a
	 * failure into a licence to create a second site at an occupied address:
	 *
	 *   - VERIFIED ABSENCE — the statement succeeded and matched no row;
	 *   - ONE VALID SITE — exactly one row, whose id validates;
	 *   - CORRUPT — rows that answered but cannot be read as a site id, or more
	 *     than one row at a single address;
	 *   - UNREADABLE — the statement failed, errored or threw.
	 *
	 * The last two are `platform_unavailable`: the question could not be
	 * answered, which is never the same as answering "no".
	 *
	 * Archived, spam and deleted sites are deliberately NOT excluded. An
	 * archived partial create from an earlier attempt IS the site marker-matched
	 * recovery exists to find, and a deleted-flagged row still occupies the
	 * address as far as `wpmu_create_blog()` is concerned.
	 *
	 * @return int|null|\WP_Error Null is a VERIFIED absence; WP_Error means the
	 *                            question could not be answered.
	 */
	public function existingSiteId() {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return self::error( 'platform_unavailable', 'The network address could not be checked.' );
		}

		$table = (string) $wpdb->blogs;
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) {
			return self::error( 'platform_unavailable', 'The network address could not be checked.' );
		}

		$previous = $wpdb->suppress_errors( true );
		try {
			/*
			 * Two rows are requested although one is expected. LIMIT 1 would
			 * hide a duplicated address behind a plausible single answer, and a
			 * duplicated address is exactly the corrupt state that must not be
			 * resolved by picking whichever row sorted first.
			 */
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT blog_id FROM {$table} WHERE site_id = %d AND domain = %s AND path = %s LIMIT 2",
					$this->network_id,
					$this->domain,
					$this->path
				),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			// Never read: its message and trace can quote SQL and server text.
			unset( $throwable );

			return self::error( 'platform_unavailable', 'The network address could not be checked.' );
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error || ! is_array( $rows ) ) {
			// get_results() returns an empty array both for "no rows" and for a
			// failed query, so absence is never inferred from it alone.
			return self::error( 'platform_unavailable', 'The network address could not be checked.' );
		}
		if ( [] === $rows ) {
			// VERIFIED absence: the statement answered and matched nothing.
			return null;
		}
		if ( 1 !== count( $rows ) ) {
			return self::error( 'platform_unavailable', 'The network address is not usable.' );
		}

		$row = $rows[0];
		if ( ! is_array( $row ) || ! array_key_exists( 'blog_id', $row ) ) {
			return self::error( 'platform_unavailable', 'The network address is not usable.' );
		}

		$blog_id = self::site_identity( $row['blog_id'] );
		if ( null === $blog_id ) {
			// The row answered with something that is not a site id. Refuse
			// rather than cast: (int) would manufacture a plausible-looking 0.
			return self::error( 'platform_unavailable', 'The network address is not usable.' );
		}

		return $blog_id;
	}

	/**
	 * A stored `blog_id`, read strictly.
	 *
	 * Representation before value: the driver hands back a decimal string, and a
	 * cast on a missing, padded or non-numeric value would produce 0 or a
	 * truncated number that later compares equal to a real site.
	 *
	 * @param mixed $value
	 */
	private static function site_identity( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 1 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A\d+\z/', $value ) ) {
			return null;
		}

		$parsed = (int) $value;

		return ( (string) $parsed === $value && $parsed >= 1 ) ? $parsed : null;
	}

	/**
	 * Does this resolved address match the site WordPress currently holds for
	 * `$blog_id`?
	 *
	 * Compared field by field against the site row rather than against a
	 * constructed URL, so a scheme, a trailing slash or a port can never make a
	 * mismatch look like a match.
	 */
	public function matchesSite( \WP_Site $site ): bool {
		return (int) $site->site_id === $this->network_id
			&& (string) $site->domain === $this->domain
			&& (string) $site->path === $this->path;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-platform' ) );
	}
}
