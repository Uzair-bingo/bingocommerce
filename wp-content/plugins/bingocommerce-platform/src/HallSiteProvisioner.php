<?php

namespace BingoCommerce\Platform;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ONE consumer of `bingocommerce_platform_provision_hall_site`.
 *
 * It owns exactly the platform half of ADR-042 and nothing else:
 *
 *   - resolve the reserved network address;
 *   - create ONE WordPress site, archived from insertion, and give it the
 *     permanent `bc_commerce_managed` marker DURING INITIALIZATION — the site
 *     row is inserted first and the marker is supplied as an initial site
 *     option, so it is NOT present from the row's first moment and a crash in
 *     between leaves an ownership-unproven orphan for operator recovery; or
 *     recover the marker-matched partial create from a previous attempt; or
 *     resume a bound one;
 *   - run WooCommerce and BingoCommerce per-site initialization by invoking
 *     the owners of those migrations, never by reissuing their SQL.
 *
 * IT DECIDES NOTHING ABOUT THE PORTAL COMMAND. No classification, no mutex, no
 * fence, no operation row, no Hall mapping, no Commerce state, no `archived`
 * clear, no confirmation, no audit, no response. Those are the orchestrator's,
 * and duplicating any of them here would give one decision two owners.
 *
 * THE MARKER IS THE OWNERSHIP WITNESS AND IT IS NEVER DERIVED. It is written
 * once, from `site_ownership_id`, at insertion; it is read back and compared;
 * and it is NEVER rewritten, repaired, or computed from the rotating Portal
 * `operation_id`. A site whose marker is absent, different or unreadable is an
 * orphan for an operator to resolve, never something to bind or overwrite.
 *
 * IT NEVER DELETES. There is no path here that removes a site, a table, an
 * option or a user, including for a creation that failed halfway.
 *
 * NO STARTER PRODUCTS. ADR-042's first automatic-provisioning release creates a
 * ready but EMPTY store; no WooCommerce Product and no Bingo Play Product is
 * copied, and this class contains no code that could.
 */
final class HallSiteProvisioner {

	/** Site option carrying the permanent BingoCommerce ownership witness. */
	public const MARKER_OPTION = 'bc_commerce_managed';

	/** Shared storefront assigned only when this consumer creates a new site. */
	private const THEME_SLUG = 'bingocommerce-bingothemev1';

	/** The only WP_Error codes ADR-042 permits this filter to return. */
	public const ERROR_CODES = [
		'site_create_failed',
		'site_ownership_unproven',
		'platform_unavailable',
		'internal_error',
	];

	/** The exact key set of a successful result. */
	public const RESULT_KEYS = [ 'blog_id', 'site_url', 'created', 'managed_marker_present' ];

	/**
	 * The filter consumer.
	 *
	 * THE SEED RULE IS THE FIRST THING CHECKED AND IT IS ABSOLUTE. A non-null
	 * seed — including a WP_Error, including another consumer's result — is
	 * returned unchanged with ZERO side effects. That is what makes this a
	 * filter with one owner rather than a race between consumers: the
	 * orchestrator always passes null, so a non-null seed means somebody else
	 * already answered, and answering again would overwrite their decision with
	 * real site creation.
	 *
	 * EVERY Throwable IS CONTAINED. An escaping exception would cross a plugin
	 * boundary mid-`apply_filters()` and abandon a half-created site with no
	 * result at all; the exception object itself is never read, because its
	 * message and trace can quote SQL, paths and stored values.
	 *
	 * @param mixed $seed    Always null from the orchestrator.
	 * @param mixed $command The ADR-042 command array.
	 * @return mixed The seed unchanged, a success array, or a WP_Error.
	 */
	public static function provision( $seed = null, $command = null ) {
		if ( null !== $seed ) {
			return $seed;
		}

		try {
			$validated = HallSiteCommand::fromFilterArgument( $command );
			if ( $validated instanceof \WP_Error ) {
				return $validated;
			}

			return self::run( $validated );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'internal_error', 'The Hall site could not be provisioned.' );
		}
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function run( HallSiteCommand $command ) {
		if ( ! is_multisite() ) {
			return self::error( 'platform_unavailable', 'Hall site provisioning requires WordPress Multisite.' );
		}

		$address = HallSiteAddress::forSlug( $command->site_slug );
		if ( $address instanceof \WP_Error ) {
			/*
			 * The slug reached this plugin already validated by the caller, so a
			 * grammar failure here is an integration mistake, not a client one —
			 * and `address_invalid` is not a code this filter may return.
			 */
			return 'address_invalid' === $address->get_error_code()
				? self::error( 'internal_error', 'The Hall site address is not usable.' )
				: self::error( 'platform_unavailable', 'The Hall site address could not be resolved.' );
		}

		return null === $command->blog_id
			? self::create_or_recover( $command, $address )
			: self::resume_bound( $command, $address );
	}

	/* ------------------------------------------------------------------ *
	 * blog_id IS NULL: create, or recover this operation's partial create
	 * ------------------------------------------------------------------ */

	/**
	 * THE RECOVERABLE BRANCH IS EVALUATED BEFORE ANY CREATE.
	 *
	 * A retry of an attempt that created a site and then lost its answer must
	 * find its OWN site rather than be told the address is occupied. The marker
	 * — not the slug — is what authorizes that: slug equality alone would let
	 * this Hall adopt any site that happens to sit at the address.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function create_or_recover( HallSiteCommand $command, HallSiteAddress $address ) {
		$existing = $address->existingSiteId();
		if ( $existing instanceof \WP_Error ) {
			return self::error( 'platform_unavailable', 'The Hall site address could not be checked.' );
		}

		if ( null !== $existing ) {
			$site = get_site( $existing );
			if ( ! $site instanceof \WP_Site ) {
				// The address query answered, but the site cannot be loaded.
				// Never treat that as an empty address.
				return self::error( 'platform_unavailable', 'The existing site at the Hall address could not be read.' );
			}

			/*
			 * EVERY TARGET FACT IS PROVEN BEFORE ANY INITIALIZATION RUNS.
			 * Marker equality alone says who owns the site; it says nothing
			 * about whether the site is one this consumer may operate on, and
			 * running a WooCommerce install and per-site migrations against a
			 * deleted, spam or foreign-network site would be the same mistake
			 * whether or not the marker matched.
			 */
			$target = self::verify_target( $command, $address, $site );
			if ( $target instanceof \WP_Error ) {
				return $target;
			}

			/*
			 * AN UNBOUND MARKER-MATCHED PARTIAL CREATE MUST STILL BE ARCHIVED.
			 * This branch exists for a create that was interrupted, and every
			 * site this consumer creates is archived from insertion — so an
			 * UNARCHIVED one at this address is a site whose lifecycle something
			 * else has changed, and adopting it would hand a reachable store to
			 * a command that believes it is still building one. Refused without
			 * initializing it, without re-archiving it, without repairing it and
			 * without deleting it: an operator decides what it is.
			 */
			if ( '1' !== (string) $site->archived ) {
				return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
			}

			// PROVEN this operation's own recoverable partial create.
			return self::initialize( $command, $site, false );
		}

		return self::create( $command, $address );
	}

	/**
	 * Create ONE site: archived from insertion, marked during initialization.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function create( HallSiteCommand $command, HallSiteAddress $address ) {
		/*
		 * THE ADMINISTRATOR IDENTITY IS SETTLED BEFORE ANYTHING EXISTS. A bad
		 * constant discovered after creation leaves a site with no usable
		 * administrator, so it must be checked before the site row.
		 */
		$admin_user_id = HallSiteAdminUser::resolve( (int) get_current_user_id() );
		if ( $admin_user_id instanceof \WP_Error ) {
			return $admin_user_id;
		}

		// Fresh creation only: recovery and re-enable preserve the site's theme.
		$theme = wp_get_theme( self::THEME_SLUG );
		if ( ! $theme->exists()
			|| $theme->errors()
			|| self::THEME_SLUG !== $theme->get_template()
			|| self::THEME_SLUG !== $theme->get_stylesheet()
			|| true !== validate_theme_requirements( self::THEME_SLUG ) ) {
			return self::error( 'platform_unavailable', 'The Hall site could not be initialized.' );
		}

		/*
		 * `archived` and the marker are both passed to wpmu_create_blog(), not
		 * applied afterwards:
		 *
		 *   - `archived => 1` is in wpmu_create_blog()'s $allowed_data_fields,
		 *     so it reaches wp_insert_site()'s INSERT. A site created open and
		 *     archived a moment later is a window in which an unfinished Hall is
		 *     reachable; there is no such window here.
		 *   - every remaining option is passed to wp_initialize_site() through
		 *     populate_options(), so `bc_commerce_managed` is written as part of
		 *     INITIALIZING the site rather than by a later separate write.
		 *
		 *     It is NOT present from the site row's first moment, and this code
		 *     does not claim it is: the row is inserted first and the site's
		 *     options table only exists once initialization runs. What the
		 *     ordering does guarantee is that the site is quarantined before it
		 *     exists at all, and that no window is introduced between "the site
		 *     is ready" and "the site is recognisable to its own retry". A crash
		 *     between the INSERT and marker persistence leaves an archived,
		 *     UNMARKED site, which is an ownership-unproven orphan for an
		 *     operator to resolve — never something to adopt or re-mark.
		 *   - `public` is deliberately NOT set. wpmu_create_blog() defaults it,
		 *     and ADR-042 states plainly that `public` is not an access-control
		 *     barrier; HallCommerceGate is.
		 */
		// WordPress defaults new subdomain sites to HTTP, even on an HTTPS
		// network. Set both URLs during initial option creation so the Portal
		// receives the stored HTTPS address. Only local/development requests
		// without TLS retain HTTP; recovery and re-enable keep existing URLs.
		$local    = in_array( wp_get_environment_type(), [ 'local', 'development' ], true );
		$scheme   = $local && ! is_ssl() ? 'http' : 'https';
		$site_url = untrailingslashit( $scheme . '://' . $address->domain . $address->path );
		$options = [
			'archived'          => 1,
			self::MARKER_OPTION => $command->site_ownership_id,
			'template'          => self::THEME_SLUG,
			'stylesheet'        => self::THEME_SLUG,
			'home'              => $site_url,
			'siteurl'           => $site_url,
		];

		$installing = wp_installing();
		try {
			$created = wpmu_create_blog(
				$address->domain,
				$address->path,
				$command->siteTitle(),
				$admin_user_id,
				$options,
				$address->network_id
			);
		} finally {
			/*
			 * wpmu_create_blog() sets wp_installing(true) and never clears it:
			 * wp_initialize_site() restores only the value it found, which by
			 * then is already true. Leaving a REST request in installing mode
			 * disables option caching and changes behaviour for every later step
			 * of this same provisioning attempt, so the entry value is restored
			 * here — including when creation throws.
			 */
			wp_installing( $installing );
		}

		if ( $created instanceof \WP_Error ) {
			/*
			 * `blog_taken` means a site appeared at the address between the
			 * check above and the INSERT. That is not this operation's site
			 * unless its marker says so, and this attempt has not written one,
			 * so ownership is unproven rather than merely unavailable.
			 */
			return 'blog_taken' === $created->get_error_code()
				? self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' )
				: self::error( 'site_create_failed', 'The Hall site could not be created.' );
		}
		if ( ! is_int( $created ) || $created < 1 ) {
			return self::error( 'site_create_failed', 'The Hall site could not be created.' );
		}

		// READ IT BACK FROM STORAGE, never from the return value alone.
		clean_blog_cache( $created );
		$site = get_site( $created );
		if ( ! $site instanceof \WP_Site ) {
			return self::error( 'site_create_failed', 'The created Hall site could not be read back.' );
		}
		if ( ! $address->matchesSite( $site ) ) {
			// Created somewhere other than the reserved address. Never bind it,
			// and never delete it: an operator decides what it is.
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}
		if ( '1' !== (string) $site->archived ) {
			// The site exists but is not archived, so an unfinished Hall is
			// reachable. Refuse rather than "fix" it: the caller must not
			// receive a blog_id it will go on to treat as safely quarantined.
			return self::error( 'site_create_failed', 'The Hall site was not created in the archived state.' );
		}

		return self::initialize( $command, $site, true );
	}

	/* ------------------------------------------------------------------ *
	 * blog_id IS NOT NULL: verify and resume
	 * ------------------------------------------------------------------ */

	/**
	 * Verify a bound site completely, then resume initialization only.
	 *
	 * NEVER CREATES. A bound command describes a site that already exists; if it
	 * does not, or is not the site this operation owns, that is an operator
	 * matter and creating a second one would be the worst possible answer.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function resume_bound( HallSiteCommand $command, HallSiteAddress $address ) {
		$site = get_site( $command->blog_id );
		if ( ! $site instanceof \WP_Site ) {
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}

		/*
		 * The same defensive target validation as the recovery branch, and
		 * deliberately WITHOUT the archived requirement. A bound site is
		 * legitimately archived on a resumed first enable and legitimately
		 * unarchived on a re-enable, so `archived` carries no information here —
		 * but `deleted`, `spam`, the network and the address still do, and they
		 * are all still refused.
		 */
		$target = self::verify_target( $command, $address, $site );
		if ( $target instanceof \WP_Error ) {
			return $target;
		}

		return self::initialize( $command, $site, false );
	}

	/**
	 * Every fact a site must satisfy before this consumer touches it.
	 *
	 * Shared by the recovery branch and the bound-resume branch so the two
	 * cannot drift: a check added for one is a check both get. It proves
	 * identity and lifecycle, not the archived flag — the two branches disagree
	 * about `archived` and each states its own rule.
	 *
	 * Every refusal is the same fixed category and discloses nothing about the
	 * site it refused.
	 *
	 * @return true|\WP_Error
	 */
	private static function verify_target( HallSiteCommand $command, HallSiteAddress $address, \WP_Site $site ) {
		$blog_id = (int) $site->id;

		if ( $blog_id < 1 ) {
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}
		if ( (int) $site->site_id !== $address->network_id ) {
			// A site on another network of this installation is not this
			// network's to initialize.
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}
		if ( ! $address->matchesSite( $site ) ) {
			// The durable slug no longer describes this site's address.
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}
		if ( is_main_site( $blog_id, $address->network_id ) ) {
			// The network's main site is never a Hall.
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}
		if ( '1' === (string) $site->deleted || '1' === (string) $site->spam ) {
			// A deleted or spam-flagged site is not a provisioning target, and
			// initializing one would resurrect a store an operator retired.
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}

		/*
		 * THE ADDRESS MUST RESOLVE UNIQUELY BACK TO THIS SITE. matchesSite()
		 * above proves this site sits at the expected address; it does not prove
		 * that nothing else does. A duplicate, a different winner or an
		 * unreadable answer are all refused, and neither row is named.
		 */
		$resolved = $address->existingSiteId();
		if ( $resolved instanceof \WP_Error || $resolved !== $blog_id ) {
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}

		$marker = self::read_marker( $blog_id );
		if ( 'valid' !== $marker['status']
			|| null === $marker['value']
			|| ! hash_equals( $command->site_ownership_id, (string) $marker['value'] ) ) {
			/*
			 * Absent, different, invalid or unreadable: NEVER bind, rewrite,
			 * delete or create another site. This covers a foreign site, a
			 * legacy site and a storage failure alike, because none of them
			 * proves this operation owns the address. The marker is never
			 * derived or rewritten from `operation_id`.
			 */
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Platform-owned initialization
	 * ------------------------------------------------------------------ */

	/**
	 * Idempotent platform initialization, by INVOKING the owners.
	 *
	 * Every step below belongs to another component, and every one is called
	 * rather than reimplemented: WooCommerce owns its schema, pages and roles;
	 * `bingocommerce-core` owns the Hall role capability model;
	 * `bingocommerce-catalog` and `bingocommerce-sessions` own their per-site
	 * tables. Reissuing any of their SQL here would create a second definition
	 * of the same schema that could only ever drift.
	 *
	 * ORDER MATTERS ONCE: WooCommerce first, because the Hall Manager role is
	 * built from the live Shop Manager baseline and a role sync that runs before
	 * WooCommerce exists records an incomplete one.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function initialize( HallSiteCommand $command, \WP_Site $site, bool $created ) {
		global $wpdb;

		$blog_id = (int) $site->id;

		/*
		 * THE ENTRY CONTEXT IS RECORDED BEFORE ANYTHING MOVES, and all three
		 * facts are recorded, not just the stack depth: the depth says how many
		 * switches to undo, and the blog id and table prefix say whether undoing
		 * them actually worked. `$wpdb->prefix` is the one that matters most —
		 * it is what every later statement in this request will be written
		 * against.
		 */
		$entry_depth   = self::switch_depth();
		$entry_blog_id = get_current_blog_id();
		$entry_prefix  = ( $wpdb instanceof \wpdb ) ? (string) $wpdb->prefix : '';

		/*
		 * THE SWITCH IS INSIDE THE try. switch_to_blog() pushes its stack entry
		 * and moves $wpdb BEFORE it fires `switch_blog`, so a callback on that
		 * action that throws leaves the context ALREADY MOVED — while a `try`
		 * opened after switch_to_blog() returns has not begun, and the rest of
		 * the request would run against the Hall's tables believing it was on
		 * the network site.
		 */
		$restored = false;
		try {
			switch_to_blog( $blog_id );

			$prepared = self::initialize_current_site( $command );
		} catch ( \Throwable $throwable ) {
			// Never read: its message and trace can quote SQL, paths and values.
			unset( $throwable );
			$prepared = self::error( 'platform_unavailable', 'The Hall site could not be initialized.' );
		} finally {
			/*
			 * Restoration unwinds ONLY the switches this method introduced, one
			 * CONTAINED attempt at a time, so an outer switched context a caller
			 * established is preserved exactly. See restore_entry_context().
			 */
			$restored = self::restore_entry_context( $entry_depth, $entry_blog_id, $entry_prefix );
		}

		if ( ! $restored ) {
			/*
			 * NEVER REPORT SUCCESS FROM AN UNPROVEN CONTEXT. Whatever this
			 * method achieved, the caller would go on to attach a blog id, write
			 * Commerce state and clear `archived` against whichever site $wpdb
			 * is now pointing at. Refusing is the only safe answer, and the
			 * refusal says nothing about where the context ended up.
			 */
			return self::error( 'platform_unavailable', 'The Hall site context could not be restored.' );
		}

		if ( $prepared instanceof \WP_Error ) {
			return $prepared;
		}

		/*
		 * THE MARKER READ-BACK IS AFTER INITIALIZATION, NOT BEFORE IT.
		 * wp_initialize_site() calls populate_options(), which writes the whole
		 * option set; reading the witness only beforehand would report a marker
		 * that initialization could still have disturbed.
		 */
		$marker  = self::read_marker( $blog_id );
		$present = 'valid' === $marker['status']
			&& null !== $marker['value']
			&& hash_equals( $command->site_ownership_id, (string) $marker['value'] );

		if ( ! $present ) {
			// Ownership is ambiguous. Never bind, never rewrite, never delete.
			return self::error( 'site_ownership_unproven', 'Site ownership could not be proven.' );
		}

		/*
		 * ADVISORY ONLY, and read from storage rather than through
		 * get_site_url(). The orchestrator establishes the Portal-facing URL
		 * itself and is required to ignore this value; reading it unfiltered
		 * merely keeps the diagnostic honest instead of reporting whatever a
		 * `site_url` filter felt like returning.
		 */
		$site_url = self::read_site_option( $blog_id, 'siteurl' );
		if ( ! is_string( $site_url ) || '' === $site_url ) {
			return self::error( 'platform_unavailable', 'The Hall site address could not be read.' );
		}

		return [
			'blog_id'                => $blog_id,
			// ADVISORY ONLY. ADR-042 requires the orchestrator to read the
			// authoritative URL independently from the verified site; this value
			// exists for diagnostics and must not be trusted on its own.
			'site_url'               => $site_url,
			'created'                => $created,
			'managed_marker_present' => true,
		];
	}

	/**
	 * Runs with the current blog already switched to the Hall site.
	 *
	 * @return true|\WP_Error
	 */
	private static function initialize_current_site( HallSiteCommand $command ) {
		/*
		 * THERE IS NO "SITE ROW WITHOUT TABLES" BRANCH HERE, AND THERE CANNOT BE
		 * ONE — that is a fact about how this method is reached, not an omission.
		 *
		 * Every path into initialize() has already read this site's
		 * `bc_commerce_managed` marker out of `{prefix}options` and matched it
		 * against the persisted `site_ownership_id`:
		 *
		 *   - create() runs wpmu_create_blog(), whose wp_insert_site() fires
		 *     `wp_initialize_site` synchronously, and then verifies the address,
		 *     the archived flag and the marker before calling this;
		 *   - create_or_recover() and resume_bound() both go through
		 *     verify_target(), which requires a VALID marker read from that same
		 *     options table.
		 *
		 * A site whose options table does not exist cannot produce a valid
		 * marker, so it is refused as `site_ownership_unproven` long before this
		 * point. An unmarked orphan is never adopted, initialized or marked; it
		 * is left exactly as found for explicit operator recovery.
		 *
		 * is_blog_installed() was previously called here and is deliberately
		 * gone. It is not a safe probe on a possibly incomplete site: when the
		 * expected tables are missing it ends in wpdb::bail(), which TERMINATES
		 * the process — it does not return false. A provisioning request must not
		 * be able to die inside a filter callback, and the fixture documents the
		 * same hazard.
		 */
		$woocommerce = self::install_woocommerce();
		if ( $woocommerce instanceof \WP_Error ) {
			return $woocommerce;
		}

		/*
		 * THE ROLE OBJECTS MUST BE RE-READ BEFORE THE HALL ROLES ARE BUILT.
		 * WP_Roles::add_cap() updates `$this->roles` and the stored option but
		 * NOT `$this->role_objects`, so the WP_Role that get_role() hands back
		 * after WooCommerce has finished is the one built at init_roles() time —
		 * carrying only the capabilities add_role() was called with. Hall
		 * Manager copies its native baseline from the LIVE Shop Manager role, so
		 * reading a stale object would silently record a Hall Manager missing
		 * every WooCommerce capability, and record it as complete.
		 * WP_Roles::for_site() is core's own re-initialization for exactly this.
		 */
		if ( function_exists( 'wp_roles' ) ) {
			wp_roles()->for_site( get_current_blog_id() );
		}

		/*
		 * The Hall role capability model. Its return value reports whether the
		 * WooCommerce Shop Manager baseline was available, which its own
		 * versioned reconciliation handles on a later pass; an incomplete sync
		 * is not a provisioning failure, and SetupReadiness — not this plugin —
		 * is what decides whether the resulting site is ready.
		 */
		if ( ! class_exists( '\BingoCommerce\Core\Roles' ) ) {
			return self::error( 'platform_unavailable', 'The BingoCommerce Core role model is unavailable.' );
		}
		\BingoCommerce\Core\Roles::sync_current_site();

		foreach (
			[
				'\BingoCommerce\Catalog\Migrations',
				'\BingoCommerce\Sessions\Migrations',
			] as $owner
		) {
			if ( ! class_exists( $owner ) ) {
				return self::error( 'platform_unavailable', 'A required BingoCommerce per-site schema owner is unavailable.' );
			}

			$migrated = call_user_func( [ $owner, 'migrate_current_site' ] );
			if ( $migrated instanceof \WP_Error ) {
				// The owner's own fixed error is not forwarded: it may name
				// tables and columns. One fixed platform category instead.
				return self::error( 'platform_unavailable', 'A required BingoCommerce per-site schema could not be prepared.' );
			}
		}

		/*
		 * NO STARTER PRODUCT COPY. ADR-042 narrows ADR-027 / BR-047 for this
		 * MVP: the store is ready and EMPTY. There is deliberately no call here
		 * that creates a WooCommerce Product or a Bingo Play Product.
		 */

		return true;
	}

	/**
	 * WooCommerce's own installer, invoked idempotently.
	 *
	 * `WC_Install::install()` is the supported entry point WooCommerce itself
	 * uses on activation and upgrade: it takes its own lock, creates its tables,
	 * verifies them, creates options, pages and roles, and is safe to re-run.
	 * Nothing here reimplements any part of it.
	 *
	 * @return true|\WP_Error
	 */
	private static function install_woocommerce() {
		if ( ! class_exists( '\WC_Install' ) || ! is_callable( [ '\WC_Install', 'install' ] ) ) {
			return self::error( 'platform_unavailable', 'WooCommerce is unavailable on this installation.' );
		}

		/*
		 * THE PREFIX-CAPTURED SCHEMAS COME FIRST. WooCommerce's own new-install
		 * follow-up schedules recurring Action Scheduler jobs, and Action
		 * Scheduler cannot store one before its tables exist on this site — it
		 * catches and logs the failure rather than raising it, so the job would
		 * be silently lost rather than noisily missing.
		 */
		$rebound = self::create_request_scoped_schemas();
		if ( $rebound instanceof \WP_Error ) {
			return $rebound;
		}

		try {
			\WC_Install::install();

			/*
			 * THE NEW-INSTALL FOLLOW-UP, WHICH OTHERWISE NEVER RUNS HERE.
			 * install() only RECORDS that this is a new installation; the
			 * `woocommerce_newly_installed` action that acts on it is fired by
			 * WC_Install::newly_installed() from `init` on a LATER request to
			 * that site. A provisioning call finishes long before any such
			 * request exists, so the follow-up is invoked explicitly — it is
			 * WooCommerce's own public entry point, it is idempotent (it clears
			 * its own `woocommerce_newly_installed` flag), and it is what
			 * enables HPOS for a brand-new empty shop, which ADR-001 fixes as
			 * this platform's order storage.
			 */
			if ( is_callable( [ '\WC_Install', 'newly_installed' ] ) ) {
				\WC_Install::newly_installed();
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'platform_unavailable', 'WooCommerce could not be initialized for this Hall.' );
		}

		return true;
	}

	/**
	 * Create the two per-site schemas whose owners captured a table prefix at
	 * REQUEST BOOT rather than at call time.
	 *
	 * WHY THIS IS NEEDED, AND WHY IT IS NOT A REIMPLEMENTATION. Action
	 * Scheduler's schema classes resolve `$wpdb->prefix` inside
	 * get_full_table_name(), but the instances WooCommerce wires up at `init`
	 * belong to the site the request booted on. WooCommerce's product-attributes
	 * lookup table is worse: `DataRegenerator` captures the prefix in its
	 * CONSTRUCTOR and lives in WooCommerce's dependency container as a
	 * per-request singleton, so by the time a provisioning call switches to the
	 * new Hall it still names the main site's table. Both therefore create
	 * tables under the WRONG prefix when WooCommerce is installed for site B
	 * from a request that booted on site A — which is exactly what a network
	 * provisioning endpoint does, in production as well as in a harness.
	 *
	 * The fix is to invoke the SAME OWNERS with FRESH instances, so they resolve
	 * the site this code is actually switched to. No schema is re-declared here:
	 * Action Scheduler's own `ActionScheduler_DBStore::init()` performs exactly
	 * the three calls below, and the lookup table's CREATE statement comes from
	 * the owner's own get_table_creation_sql(). Both operations are idempotent,
	 * so a WooCommerce release that resolves its prefixes later makes this a
	 * no-op rather than a conflict.
	 *
	 * @return true|\WP_Error
	 */
	private static function create_request_scoped_schemas() {
		try {
			foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $schema_class ) {
				if ( ! class_exists( $schema_class ) ) {
					return self::error( 'platform_unavailable', 'The scheduled-action schema owner is unavailable.' );
				}

				$schema = new $schema_class();
				$schema->init();
				// Forced, because the schema-version marker is a SITE option and
				// a fresh site has none to compare against on some paths.
				$schema->register_tables( true );
			}

			$regenerator_class = '\Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator';
			if ( ! class_exists( $regenerator_class ) ) {
				return self::error( 'platform_unavailable', 'The product lookup schema owner is unavailable.' );
			}

			// Constructed HERE so the table name resolves to THIS site, and
			// immediately detached: its constructor registers three request-wide
			// hooks that a second, throwaway instance must not leave behind.
			$regenerator = new $regenerator_class();
			try {
				$sql = $regenerator->get_table_creation_sql();
				if ( is_string( $sql ) && '' !== $sql ) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
					dbDelta( $sql );
				}
			} finally {
				remove_filter( 'woocommerce_debug_tools', [ $regenerator, 'add_initiate_regeneration_entry_to_tools_array' ], 1 );
				remove_action( 'woocommerce_run_product_attribute_lookup_regeneration_callback', [ $regenerator, 'run_regeneration_step_callback' ] );
				remove_action( 'woocommerce_installed', [ $regenerator, 'run_woocommerce_installed_callback' ] );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return self::error( 'platform_unavailable', 'The Hall site schemas could not be prepared.' );
		}

		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Read `bc_commerce_managed` for one site without a cached miss.
	 *
	 * Direct prepared statement rather than get_blog_option(): get_option()
	 * caches a miss in `notoptions` and returns the default for a database
	 * error, absence and an empty value alike, and an OWNERSHIP decision may
	 * only ever be made from storage that actually answered.
	 *
	 * @return array{status:string,value:?string} status: valid|invalid|absent|unreadable
	 */
	private static function read_marker( int $blog_id ): array {
		global $wpdb;

		if ( $blog_id < 1 || ! $wpdb instanceof \wpdb ) {
			return [ 'status' => 'unreadable', 'value' => null ];
		}

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return [ 'status' => 'unreadable', 'value' => null ];
		}

		$previous = $wpdb->suppress_errors( true );
		try {
			// get_row(), not get_var(): get_var() returns null for an empty
			// string as well as for no row, which would turn a marker stored as
			// '' into a verified absence.
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", self::MARKER_OPTION ),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return [ 'status' => 'unreadable', 'value' => null ];
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error ) {
			// A missing options table lands here too: the site could not be
			// asked, which is not the same as answering "no".
			return [ 'status' => 'unreadable', 'value' => null ];
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return [ 'status' => 'absent', 'value' => null ];
		}

		$value = $row['option_value'];
		if ( ! is_string( $value ) || 1 !== preg_match( HallSiteCommand::UUID_PATTERN, $value ) ) {
			return [ 'status' => 'invalid', 'value' => null ];
		}

		return [ 'status' => 'valid', 'value' => $value ];
	}

	/**
	 * Read one option from a site's OWN options table, unfiltered and uncached.
	 *
	 * Same reasoning as read_marker() above: get_blog_option() runs
	 * `pre_option_*` / `option_*` and caches a miss in `notoptions`, so it
	 * cannot answer a question about what storage actually holds. Returns null
	 * for unreadable and for absent alike — both mean "no value this consumer
	 * may report".
	 */
	private static function read_site_option( int $blog_id, string $option ): ?string {
		global $wpdb;

		if ( $blog_id < 1 || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return null;
		}

		$previous = $wpdb->suppress_errors( true );
		try {
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $option ),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return null;
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error || ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return null;
		}

		return is_string( $row['option_value'] ) ? $row['option_value'] : null;
	}

	private static function switch_depth(): int {
		return is_array( $GLOBALS['_wp_switched_stack'] ?? null )
			? count( $GLOBALS['_wp_switched_stack'] )
			: 0;
	}

	/**
	 * Unwind to the recorded entry context, ONE CONTAINED ATTEMPT AT A TIME.
	 *
	 * A single `try` around the whole unwind loop was wrong in a specific way.
	 * restore_current_blog() POPS THE STACK FIRST, re-points `$wpdb`, `$blog_id`
	 * and `$table_prefix` next, and fires `switch_blog` LAST — so a callback on
	 * that action which throws has already consumed one level. Catching that
	 * outside the loop abandoned every remaining level: with two or more
	 * switches open, the request carried on with `$wpdb` still pointing at the
	 * Hall while the method reported only that restoration was unproven.
	 *
	 * So every attempt is contained on its own. The depth is recorded BEFORE
	 * each attempt and re-read AFTER it returns OR throws, and unwinding
	 * continues only while the depth is making valid progress toward the entry
	 * depth. It stops — safely — on no progress, on underflow past the entry
	 * depth, on a stack global that is no longer a list, and on an exhausted
	 * budget of the levels that existed when restoration began. `$budget` is
	 * fixed before the first attempt precisely so a stack that somebody else is
	 * concurrently growing cannot turn this into an unbounded loop.
	 *
	 * A THROW STILL FAILS THE OPERATION, even when the unwind afterwards lands
	 * exactly on the entry facts. `switch_blog` is where the object cache,
	 * WooCommerce and Action Scheduler re-point themselves; one of them raised
	 * an exception mid-restore, and three globals agreeing does not prove that
	 * the rest of the request is safe. Unwinding continues past the throw all
	 * the same, because leaving levels open would be worse than either.
	 *
	 * NOTHING IS EVER ASSIGNED HERE. No stack is replaced, no `$GLOBALS['blog_id']`
	 * or `$GLOBALS['switched']` is written, no `$wpdb->set_blog_id()` is called.
	 * Forcing the globals would manufacture the very agreement this proof exists
	 * to test, and would leave every other subsystem still switched.
	 *
	 * @return bool True only when no attempt threw AND the stack depth, the blog
	 *              id and the table prefix all agree with the entry facts.
	 */
	private static function restore_entry_context( int $entry_depth, int $entry_blog_id, string $entry_prefix ): bool {
		global $wpdb;

		$threw  = false;
		$budget = self::switch_depth() - $entry_depth;

		for ( $attempt = 0; $attempt < $budget; $attempt++ ) {
			if ( ! self::switch_stack_is_list() ) {
				// No longer something restore_current_blog() can pop. Stop
				// rather than call into it again.
				return false;
			}

			$before = self::switch_depth();
			if ( $before <= $entry_depth ) {
				// Already back. Nothing of this operation's is left to pop.
				break;
			}

			try {
				restore_current_blog();
			} catch ( \Throwable $throwable ) {
				// Never read: its message and trace can quote SQL, paths and
				// stored values. It is latched, not re-raised.
				unset( $throwable );
				$threw = true;
			}

			$after = self::switch_depth();
			if ( $after >= $before || $after < $entry_depth ) {
				// No progress at all, or unwound PAST where this operation
				// started — which would be popping a caller's own context.
				return false;
			}
		}

		if ( $threw ) {
			return false;
		}

		return self::switch_depth() === $entry_depth
			&& self::switch_stack_is_list()
			&& get_current_blog_id() === $entry_blog_id
			&& ( ! $wpdb instanceof \wpdb || (string) $wpdb->prefix === $entry_prefix );
	}

	/**
	 * Is WordPress's switched-blog stack still something that can be popped?
	 *
	 * Absent is valid and means depth zero: `switch_to_blog()` creates the
	 * global on its first use, so a request that has never switched has none.
	 * Present-but-not-an-array is NOT valid — restore_current_blog() would call
	 * array_pop() on it.
	 */
	private static function switch_stack_is_list(): bool {
		return ! array_key_exists( '_wp_switched_stack', $GLOBALS )
			|| is_array( $GLOBALS['_wp_switched_stack'] );
	}

	/** Fixed categories only: no SQL, path, trace, slug, marker or user detail. */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-platform' ) );
	}
}
