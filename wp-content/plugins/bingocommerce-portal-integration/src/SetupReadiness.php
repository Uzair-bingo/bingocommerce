<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only evaluation of a hall site's SETUP PREREQUISITES.
 *
 * This class answers one question: does this site already carry the platform
 * prerequisites a hall needs before any commerce work is meaningful? It is
 * deliberately the smallest of the readiness concerns:
 *
 *   - it does NOT consult the Portal Hall mapping (a later concern), and
 *   - it does NOT consult any commerce/enablement state (none exists yet).
 *
 * STRICTLY READ-ONLY. Nothing here installs, migrates, provisions, repairs,
 * synchronizes roles, or writes an option. That is a correctness requirement,
 * not a style preference: this evaluator is intended to be safe to run against
 * live halls, and a "check" that silently repairs would destroy the evidence it
 * was called to gather. The accompanying harness proves the property by
 * recording every statement issued through the `query` filter.
 *
 * Helper selection was made against the installed source rather than by name:
 *
 *   - `WC_Install::verify_base_tables()` is NOT used. It is a WRITER — it calls
 *     update_option( 'woocommerce_schema_missing_tables' ),
 *     update_option( 'woocommerce_schema_version' ), delete_option(), and
 *     WC_Admin_Notices::remove_notice() (includes/class-wc-install.php).
 *   - `WC_Install::get_missing_base_tables()` IS used, and is the authority for
 *     which WooCommerce tables are required. It is read-only: it reaches
 *     DatabaseUtil::get_missing_tables(), which calls dbdelta( $queries, false )
 *     — core dbDelta with $execute = false, a dry run that issues no DDL. It is
 *     also HPOS-conditional, adding the wc_orders* schema only when HPOS is
 *     enabled, so it reports against the store's actual configuration.
 *   - `WC_Install::get_tables()` is NOT used as the required inventory. It is a
 *     SUPERSET of what WooCommerce creates: wc_order_fulfillments,
 *     wc_order_fulfillment_meta, wc_stock_notifications and
 *     wc_stock_notificationmeta appear in get_tables() but nowhere in
 *     get_schema(), so treating that list as required reports four permanently
 *     missing tables on a perfectly healthy store.
 *   - `Catalog\Migrations::verify_current_site_schema()` and
 *     `Sessions\Migrations::verify_current_site_schema()` ARE used. Both were
 *     confirmed read-only against the installed source: every write in those
 *     files (dbDelta / update_option / ALTER) lives inside
 *     `migrate_current_site()` and `migrate_game_type_names()`, neither of
 *     which is reachable from the verify path. Reusing them keeps this class
 *     from drifting away from the shipped definition of Catalog v5 and
 *     Sessions v2.
 *   - `Roles::sync_current_site()` is NOT used. It is a writer (add_role,
 *     add_cap, update_option). The role capability sets are read directly from
 *     the site's own `user_roles` option instead.
 *
 * Options are read with direct prepared statements against the site's own
 * options table rather than get_option(), following PortalSyncState::
 * readUncached(). get_option() caches misses in the `notoptions` set and
 * returns false for "absent", "empty" and "unreadable" alike, which would let a
 * failed read masquerade as a clean absence.
 *
 * Findings are structured, not prose. `detail` names the schema objects and
 * option keys involved because the consumers are CLI harnesses and services.
 * Nothing read here is a credential. Any browser-facing surface must still
 * reduce these to a fixed category before display, exactly as PortalSyncAdmin
 * already does for synchronization outcomes.
 */
final class SetupReadiness {

	public const CHECK_WOOCOMMERCE       = 'woocommerce';
	public const CHECK_HPOS              = 'hpos';
	public const CHECK_WC_TABLES         = 'woocommerce_tables';
	public const CHECK_ACTION_SCHEDULER  = 'action_scheduler';
	public const CHECK_WC_PAGES          = 'woocommerce_pages';
	public const CHECK_HALL_ROLES        = 'hall_roles';
	public const CHECK_CATALOG_SCHEMA    = 'catalog_schema';
	public const CHECK_SESSIONS_SCHEMA   = 'sessions_schema';

	public const CHECKS = [
		self::CHECK_WOOCOMMERCE,
		self::CHECK_HPOS,
		self::CHECK_WC_TABLES,
		self::CHECK_ACTION_SCHEDULER,
		self::CHECK_WC_PAGES,
		self::CHECK_HALL_ROLES,
		self::CHECK_CATALOG_SCHEMA,
		self::CHECK_SESSIONS_SCHEMA,
	];

	/**
	 * All four Action Scheduler tables.
	 *
	 * Three come from ActionScheduler_StoreSchema (ACTIONS_TABLE, CLAIMS_TABLE,
	 * GROUPS_TABLE) and the fourth from ActionScheduler_LoggerSchema
	 * (LOG_TABLE). The names are written out rather than read from those
	 * classes because instantiating a schema object and calling its
	 * register_tables() CREATES the tables — the exact side effect this
	 * evaluator must never cause.
	 */
	private const ACTION_SCHEDULER_TABLES = [
		'actionscheduler_actions',
		'actionscheduler_claims',
		'actionscheduler_groups',
		'actionscheduler_logs',
	];

	/** Source: wc_get_page_id() reads `woocommerce_{$page}_page_id`. */
	private const WC_PAGE_OPTIONS = [
		'cart'      => 'woocommerce_cart_page_id',
		'checkout'  => 'woocommerce_checkout_page_id',
		'myaccount' => 'woocommerce_myaccount_page_id',
	];

	/** Source: CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION. */
	private const HPOS_OPTION = 'woocommerce_custom_orders_table_enabled';

	/** Source: CustomOrdersTableController::custom_orders_table_usage_is_enabled(). */
	private const HPOS_ENABLED_VALUE = 'yes';

	/**
	 * The HPOS order tables, verified directly against the hall's own catalogue.
	 *
	 * Names taken from WC_Install::get_tables()'s "// HPOS." group. They are
	 * checked here, prefix-correctly, rather than relying solely on WooCommerce's
	 * dry run — see check_woocommerce_tables() for why that is necessary.
	 */
	private const HPOS_TABLES = [
		'wc_orders',
		'wc_order_addresses',
		'wc_order_operational_data',
		'wc_orders_meta',
	];

	/**
	 * Tables WooCommerce's dry run demonstrably does NOT evaluate for the hall.
	 *
	 * `wc_product_attributes_lookup` is bound on container singletons
	 * (LookupDataStore / DataRegenerator assign
	 * `$wpdb->prefix . 'wc_product_attributes_lookup'` at construction) that are
	 * built once in the ORIGINAL blog context and are not rebuilt by
	 * switch_to_blog(). Verified on this installation: evaluating a hall issues
	 * `DESCRIBE {base}wc_product_attributes_lookup` — the MAIN SITE's table — and
	 * never describes the hall's own copy.
	 *
	 * A healthy main-site table must not satisfy the hall's prerequisite, so this
	 * is checked directly against the hall-scoped catalogue, where the prefix is
	 * correct by construction.
	 *
	 * This list is the mitigation for an OBSERVED defect, not a general guarantee
	 * that the dry run is hall-correct for every table. If a future WooCommerce
	 * version binds further table names on singletons, the same symptom must be
	 * re-tested and the name added here; the harness fails on any unexpected
	 * foreign probe precisely so that such a regression surfaces.
	 */
	private const UNSCOPED_BY_WOOCOMMERCE = [
		'wc_product_attributes_lookup',
	];

	/**
	 * Evaluate one hall site.
	 *
	 * @return array{blog_id:int,ready:bool,checks:array<string,array{ok:bool,code:string,detail:array}>,missing:array<int,string>,site_flags:array<string,bool>}|\WP_Error
	 */
	public static function evaluate( int $blog_id ) {
		$site = self::validate_site( $blog_id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}

		$entry_blog_id = get_current_blog_id();

		/*
		 * Unwinding is driven by the DEPTH of WordPress's own switch stack, not
		 * by a boolean we set after switch_to_blog() returns.
		 *
		 * switch_to_blog() pushes onto $GLOBALS['_wp_switched_stack'] as its
		 * FIRST action, then changes $wpdb/$blog_id/$table_prefix, and only then
		 * fires the `switch_blog` action (wp-includes/ms-blogs.php). A callback
		 * on that action that throws therefore leaves the context already moved
		 * and the stack already pushed, while a "did we switch?" flag set after
		 * the call would still be false — stranding the caller on the wrong site
		 * with a corrupted stack.
		 *
		 * Comparing depth also preserves any OUTER switched context: we pop down
		 * to the depth we entered at and never below it.
		 */
		$entry_depth = is_array( $GLOBALS['_wp_switched_stack'] ?? null )
			? count( $GLOBALS['_wp_switched_stack'] )
			: 0;

		try {
			if ( $entry_blog_id !== $blog_id ) {
				switch_to_blog( $blog_id );
			}

			$checks = self::run_checks();
		} finally {
			$depth = static function (): int {
				return is_array( $GLOBALS['_wp_switched_stack'] ?? null )
					? count( $GLOBALS['_wp_switched_stack'] )
					: 0;
			};

			// restore_current_blog() returns false on an empty stack, so the
			// depth guard is what terminates this loop, not the return value.
			while ( $depth() > $entry_depth ) {
				if ( ! restore_current_blog() ) {
					break;
				}
			}
		}

		if ( get_current_blog_id() !== $entry_blog_id ) {
			return self::error(
				'setup_readiness_context_not_restored',
				'The site context could not be restored after evaluation.'
			);
		}

		$missing = [];
		$ready   = true;
		foreach ( $checks as $name => $result ) {
			if ( ! $result['ok'] ) {
				$ready     = false;
				$missing[] = $name . '.' . $result['code'];
			}
		}

		return [
			'blog_id'    => $blog_id,
			'ready'      => $ready,
			'checks'     => $checks,
			'missing'    => $missing,
			// Reported, never used to fail setup readiness: a site is
			// deliberately archived while it is being provisioned, so a
			// lifecycle flag is not a missing setup prerequisite.
			'site_flags' => [
				'archived' => '1' === (string) $site->archived,
				'spam'     => '1' === (string) $site->spam,
				'deleted'  => '1' === (string) $site->deleted,
				'public'   => '1' === (string) $site->public,
			],
		];
	}

	/** @return array<string,array{ok:bool,code:string,detail:array}> */
	private static function run_checks(): array {
		$tables = self::existing_tables();
		$hpos   = self::check_hpos();

		return [
			self::CHECK_WOOCOMMERCE      => self::check_woocommerce(),
			self::CHECK_HPOS             => $hpos,
			self::CHECK_WC_TABLES        => self::check_woocommerce_tables( $tables, (bool) $hpos['ok'] ),
			self::CHECK_ACTION_SCHEDULER => self::check_action_scheduler( $tables ),
			self::CHECK_WC_PAGES         => self::check_woocommerce_pages(),
			self::CHECK_HALL_ROLES       => self::check_hall_roles(),
			self::CHECK_CATALOG_SCHEMA   => self::check_catalog_schema(),
			self::CHECK_SESSIONS_SCHEMA  => self::check_sessions_schema(),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Checks
	 * ------------------------------------------------------------------ */

	private static function check_woocommerce(): array {
		if ( ! class_exists( '\WC_Install' ) || ! function_exists( 'WC' ) ) {
			return self::fail( 'woocommerce_not_loaded', [] );
		}

		$installed = self::read_option( 'woocommerce_version' );
		if ( null === $installed ) {
			return self::fail( 'woocommerce_version_unreadable', [ 'option' => 'woocommerce_version' ] );
		}
		if ( '' === $installed ) {
			return self::fail( 'woocommerce_not_installed_on_site', [ 'option' => 'woocommerce_version' ] );
		}

		$code = (string) WC()->version;

		// Compatibility is EQUALITY, not "at least". A stored version behind the
		// code means an update routine has not run; a stored version AHEAD of the
		// code means the plugin was downgraded under an already-migrated database,
		// which is the more dangerous of the two and must not pass silently.
		if ( version_compare( $installed, $code, '<' ) ) {
			return self::fail(
				'woocommerce_version_behind_code',
				[ 'installed' => $installed, 'code' => $code ]
			);
		}
		if ( version_compare( $installed, $code, '>' ) ) {
			return self::fail(
				'woocommerce_version_ahead_of_code',
				[ 'installed' => $installed, 'code' => $code ]
			);
		}

		return self::pass( [ 'installed' => $installed, 'code' => $code ] );
	}

	private static function check_hpos(): array {
		$value = self::read_option( self::HPOS_OPTION );
		if ( null === $value ) {
			return self::fail( 'hpos_state_unreadable', [ 'option' => self::HPOS_OPTION ] );
		}
		/*
		 * The installed controller is the only authority on interpretation:
		 * CustomOrdersTableController::custom_orders_table_usage_is_enabled()
		 * is `get_option( ... ) === 'yes'`. Strict identity with that one string.
		 * '1', 'true' and 'on' are NOT enabled values, and accepting them here
		 * would report a store as HPOS-enabled that WooCommerce itself treats as
		 * disabled.
		 */
		if ( self::HPOS_ENABLED_VALUE !== $value ) {
			return self::fail(
				'hpos_not_enabled',
				[ 'option' => self::HPOS_OPTION, 'value' => '' === $value ? '(absent)' : $value ]
			);
		}

		return self::pass( [ 'option' => self::HPOS_OPTION ] );
	}

	/**
	 * Required WooCommerce base tables.
	 *
	 * The inventory catalogue is consulted first for one reason only: to tell a
	 * DATABASE-LEVEL read failure apart from a genuine absence. WooCommerce's own
	 * dry run would report every table as missing if the schema could not be
	 * read, and "all 39 tables are missing" is a misleading way to say "the
	 * database did not answer".
	 *
	 * OBSERVED LIMITATION, and why the HPOS tables are ALSO checked directly.
	 * WC_Install::get_missing_base_tables() is not fully hall-scoped under
	 * switch_to_blog(). Verified on this installation: while switched to a hall,
	 * its dry run issues `DESCRIBE bc_wc_product_attributes_lookup` — the MAIN
	 * SITE's table — because the name is cached on a container singleton built in
	 * the original blog context (LookupDataStore/DataRegenerator assign
	 * `$wpdb->prefix . 'wc_product_attributes_lookup'` at construction, and
	 * WooCommerce re-points only `$wpdb->wc_*` on `switch_blog`). The hall's own
	 * copy of that table is therefore never examined by the dry run.
	 *
	 * That leak is read-only and cannot corrupt anything, but it means the dry
	 * run alone must not be trusted to have covered this hall's order storage.
	 * The HPOS tables — the ones that actually hold orders — are consequently
	 * verified against the hall-scoped catalogue, where the prefix is correct by
	 * construction. Any table the dry run reports that does NOT belong to this
	 * hall is surfaced separately rather than being silently mixed in.
	 *
	 * @param array<string,bool>|null $tables
	 */
	private static function check_woocommerce_tables( ?array $tables, bool $hpos_enabled ): array {
		global $wpdb;

		if ( null === $tables ) {
			return self::fail( 'table_inventory_unreadable', [] );
		}
		if ( ! class_exists( '\WC_Install' ) ) {
			return self::fail( 'woocommerce_not_loaded', [] );
		}

		$reported = \WC_Install::get_missing_base_tables();
		if ( ! is_array( $reported ) ) {
			return self::fail( 'table_inventory_unreadable', [] );
		}

		$prefix         = strtolower( $wpdb->prefix );
		$missing        = [];
		$out_of_scope   = [];
		$superseded     = [];
		foreach ( array_unique( array_map( 'strval', $reported ) ) as $table ) {
			if ( 0 === strpos( strtolower( $table ), $prefix ) ) {
				$missing[] = $table;
			} elseif ( self::is_known_unscoped_table( $table ) ) {
				/*
				 * A foreign result for a table we ALREADY verify directly against
				 * this hall's catalogue. The dry run is reading a cached
				 * out-of-hall name, so its verdict on that name — present OR
				 * absent — says nothing about this hall.
				 *
				 * Rejecting it would fail a perfectly healthy hall merely because
				 * some other site is missing the table. The direct check below is
				 * authoritative for these, so the foreign verdict is recorded and
				 * set aside rather than treated as a scope violation.
				 */
				$superseded[] = $table;
			} else {
				$out_of_scope[] = $table;
			}
		}

		/*
		 * Checked against this hall's OWN catalogue: the order storage, plus every
		 * table the dry run is known not to scope to the hall. Without this, a
		 * healthy main-site table satisfies the hall's prerequisite and the hall
		 * is reported ready while its own table is absent.
		 */
		$direct = self::UNSCOPED_BY_WOOCOMMERCE;
		if ( $hpos_enabled ) {
			$direct = array_merge( $direct, self::HPOS_TABLES );
		}

		$hpos_missing = [];
		foreach ( $direct as $suffix ) {
			$name = $wpdb->prefix . $suffix;
			if ( ! isset( $tables[ strtolower( $name ) ] ) ) {
				$hpos_missing[] = $name;
			}
		}

		$missing = array_values( array_unique( array_merge( $missing, $hpos_missing ) ) );
		sort( $missing );

		sort( $superseded );

		// Any OTHER foreign result is still a hard failure: it means the dry run
		// reported on a site this evaluation is not about, for a table we do not
		// independently verify, so its answer cannot be trusted for this hall.
		if ( [] !== $out_of_scope ) {
			sort( $out_of_scope );

			return self::fail(
				'woocommerce_table_scope_mismatch',
				[ 'foreign' => $out_of_scope, 'missing' => $missing, 'superseded' => $superseded ]
			);
		}

		if ( [] !== $missing ) {
			return self::fail(
				'woocommerce_tables_missing',
				[
					'missing'         => $missing,
					'hpos_verified'   => $hpos_enabled,
					'direct_verified' => count( $direct ),
					'superseded'      => $superseded,
				]
			);
		}

		return self::pass(
			[
				'missing'         => 0,
				'hpos_verified'   => $hpos_enabled,
				'direct_verified' => count( $direct ),
				'superseded'      => $superseded,
			]
		);
	}

	/**
	 * Is this an out-of-hall name for a table we verify directly ourselves?
	 *
	 * Matched on the table-name SUFFIX rather than an exact string, because the
	 * cached reference carries whichever prefix was current when WooCommerce's
	 * container was built — the main site's on this installation, but not
	 * necessarily on another.
	 */
	private static function is_known_unscoped_table( string $table ): bool {
		$table = strtolower( $table );

		foreach ( self::UNSCOPED_BY_WOOCOMMERCE as $suffix ) {
			$suffix = strtolower( $suffix );
			if ( $suffix === substr( $table, -strlen( $suffix ) ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,bool>|null $tables */
	private static function check_action_scheduler( ?array $tables ): array {
		if ( null === $tables ) {
			return self::fail( 'table_inventory_unreadable', [] );
		}

		global $wpdb;

		$absent = [];
		foreach ( self::ACTION_SCHEDULER_TABLES as $suffix ) {
			$name = $wpdb->prefix . $suffix;
			if ( ! isset( $tables[ strtolower( $name ) ] ) ) {
				$absent[] = $name;
			}
		}

		if ( [] !== $absent ) {
			return self::fail(
				'action_scheduler_tables_missing',
				[ 'expected' => count( self::ACTION_SCHEDULER_TABLES ), 'missing' => $absent ]
			);
		}

		return self::pass( [ 'expected' => count( self::ACTION_SCHEDULER_TABLES ) ] );
	}

	private static function check_woocommerce_pages(): array {
		global $wpdb;

		$problems = [];
		foreach ( self::WC_PAGE_OPTIONS as $page => $option ) {
			$raw = self::read_option( $option );
			if ( null === $raw ) {
				$problems[ $page ] = 'unreadable';
				continue;
			}
			$page_id = (int) $raw;
			if ( $page_id < 1 ) {
				$problems[ $page ] = 'not_configured';
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT post_status, post_type FROM {$wpdb->posts} WHERE ID = %d",
					$page_id
				),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				$problems[ $page ] = 'unreadable';
				continue;
			}
			if ( ! is_array( $row ) ) {
				$problems[ $page ] = 'page_missing';
				continue;
			}
			if ( 'page' !== (string) $row['post_type'] ) {
				$problems[ $page ] = 'not_a_page';
				continue;
			}
			if ( 'publish' !== (string) $row['post_status'] ) {
				$problems[ $page ] = 'not_published';
			}
		}

		if ( [] !== $problems ) {
			return self::fail( 'woocommerce_pages_unusable', [ 'pages' => $problems ] );
		}

		return self::pass( [ 'pages' => array_keys( self::WC_PAGE_OPTIONS ) ] );
	}

	/**
	 * Hall role capability sets, read from the site's own `user_roles` option.
	 *
	 * get_role() is avoided on purpose: it resolves through the global
	 * $wp_roles object, which is request-scoped shared state. Reading the
	 * option keeps the answer specific to the switched site and observable.
	 *
	 * The applied schema version is reported ALONGSIDE the capability sets, and
	 * never instead of them. Roles::sync_current_site() stamps that version only
	 * when WooCommerce's Shop Manager baseline was available, so a stale or
	 * missing version is real evidence — but it is a marker, and a marker alone
	 * cannot show which capabilities a role actually holds.
	 */
	private static function check_hall_roles(): array {
		global $wpdb;

		if ( ! class_exists( '\\BingoCommerce\\Core\\Roles' ) ) {
			return self::fail( 'core_roles_unavailable', [] );
		}

		$raw = self::read_option( $wpdb->prefix . 'user_roles' );
		if ( null === $raw ) {
			return self::fail( 'roles_unreadable', [ 'option' => $wpdb->prefix . 'user_roles' ] );
		}
		if ( '' === $raw ) {
			return self::fail( 'roles_absent', [ 'option' => $wpdb->prefix . 'user_roles' ] );
		}

		$roles = maybe_unserialize( $raw );
		if ( ! is_array( $roles ) ) {
			return self::fail( 'roles_unreadable', [ 'option' => $wpdb->prefix . 'user_roles' ] );
		}

		$hall_manager  = \BingoCommerce\Core\Roles::HALL_MANAGER;
		$game_operator = \BingoCommerce\Core\Roles::GAME_OPERATOR;

		$detail = [];
		$failed = false;

		/*
		 * The expected sets are RECONSTRUCTED from the shipped provisioning
		 * contract rather than approximated by the bc_* bundle alone, because
		 * Roles::apply_capabilities() converges each role to EXACTLY its desired
		 * set — it removes anything not desired. So an extra capability is as
		 * much a deviation as a missing one, and checking only for presence of
		 * the bc_* caps would miss both the Hall Manager's WooCommerce-derived
		 * permissions and any widening of the Game Operator.
		 *
		 * Hall Manager  = read + granted shop_manager caps - PROHIBITED + HALL_MANAGER_CAPS
		 * Game Operator = read + GAME_OPERATOR_CAPS, and nothing else
		 */
		$shop_manager_caps      = self::granted_capabilities( $roles, 'shop_manager' );
		$native_baseline_present = null !== $shop_manager_caps;

		$expected_hm = [ 'read' => true ];
		if ( $native_baseline_present ) {
			foreach ( $shop_manager_caps as $cap => $granted ) {
				$expected_hm[ $cap ] = true;
			}
		}
		foreach ( \BingoCommerce\Core\Roles::PROHIBITED_NATIVE_CAPS as $cap ) {
			unset( $expected_hm[ $cap ] );
		}
		foreach ( \BingoCommerce\Core\Roles::HALL_MANAGER_CAPS as $cap ) {
			$expected_hm[ $cap ] = true;
		}

		$expected_go = [ 'read' => true ];
		foreach ( \BingoCommerce\Core\Roles::GAME_OPERATOR_CAPS as $cap ) {
			$expected_go[ $cap ] = true;
		}

		// Roles::sync_current_site() returns false when shop_manager is absent,
		// so the Hall Manager baseline is provably incomplete in that state.
		$detail['native_baseline'] = $native_baseline_present ? 'present' : 'shop_manager_absent';
		if ( ! $native_baseline_present ) {
			$failed = true;
		}

		foreach ( [ $hall_manager => $expected_hm, $game_operator => $expected_go ] as $slug => $expected ) {
			$granted = self::granted_capabilities( $roles, $slug );
			if ( null === $granted ) {
				$detail[ $slug ] = [ 'state' => 'role_absent' ];
				$failed          = true;
				continue;
			}

			$missing_caps = array_values( array_diff( array_keys( $expected ), array_keys( $granted ) ) );
			$extra_caps   = array_values( array_diff( array_keys( $granted ), array_keys( $expected ) ) );

			$prohibited = [];
			foreach ( \BingoCommerce\Core\Roles::PROHIBITED_NATIVE_CAPS as $cap ) {
				if ( isset( $granted[ $cap ] ) ) {
					$prohibited[] = $cap;
				}
			}

			$entry = [ 'state' => 'present', 'expected' => count( $expected ), 'granted' => count( $granted ) ];
			if ( [] !== $missing_caps ) {
				sort( $missing_caps );
				$entry['missing_capabilities'] = $missing_caps;
				$failed                        = true;
			}
			if ( [] !== $extra_caps ) {
				sort( $extra_caps );
				$entry['unexpected_capabilities'] = $extra_caps;
				$failed                           = true;
			}
			if ( [] !== $prohibited ) {
				$entry['prohibited_capabilities'] = $prohibited;
				$failed                           = true;
			}
			$detail[ $slug ] = $entry;
		}

		// Reported ALONGSIDE the capability sets, never instead of them: the
		// stamp records that a sync ran, not what the roles actually hold.
		$applied = self::read_option( \BingoCommerce\Core\Roles::SITE_VERSION_OPTION );
		$detail['schema_version'] = [
			'applied'  => null === $applied ? 'unreadable' : ( '' === $applied ? '(absent)' : $applied ),
			'expected' => \BingoCommerce\Core\Roles::SCHEMA_VERSION,
		];
		if ( null === $applied || (int) $applied < \BingoCommerce\Core\Roles::SCHEMA_VERSION ) {
			$detail['schema_version']['current'] = false;
			$failed                              = true;
		} else {
			$detail['schema_version']['current'] = true;
		}

		return $failed
			? self::fail( 'hall_roles_incomplete', $detail )
			: self::pass( $detail );
	}

	/**
	 * Granted capabilities for one role slug, or null when the role is absent.
	 *
	 * @param array<string,mixed> $roles
	 * @return array<string,true>|null
	 */
	private static function granted_capabilities( array $roles, string $slug ): ?array {
		if ( ! isset( $roles[ $slug ] ) || ! is_array( $roles[ $slug ] ) ) {
			return null;
		}

		$caps = isset( $roles[ $slug ]['capabilities'] ) && is_array( $roles[ $slug ]['capabilities'] )
			? $roles[ $slug ]['capabilities']
			: [];

		$granted = [];
		foreach ( $caps as $cap => $enabled ) {
			if ( $enabled ) {
				$granted[ (string) $cap ] = true;
			}
		}

		return $granted;
	}

	private static function check_catalog_schema(): array {
		if ( ! class_exists( '\BingoCommerce\Catalog\Migrations' ) ) {
			return self::fail( 'catalog_plugin_unavailable', [] );
		}

		return self::check_domain_schema(
			\BingoCommerce\Catalog\Migrations::class,
			'catalog_schema_invalid'
		);
	}

	private static function check_sessions_schema(): array {
		if ( ! class_exists( '\BingoCommerce\Sessions\Migrations' ) ) {
			return self::fail( 'sessions_plugin_unavailable', [] );
		}

		return self::check_domain_schema(
			\BingoCommerce\Sessions\Migrations::class,
			'sessions_schema_invalid'
		);
	}

	/**
	 * Structure first, version marker second.
	 *
	 * The shipped verifier is the authority on structure; the version option is
	 * reported as a separate sub-finding so a site whose tables are correct but
	 * whose marker is stale is distinguishable from one whose tables are wrong.
	 *
	 * @param class-string $migrations
	 */
	private static function check_domain_schema( string $migrations, string $fail_code ): array {
		$verified = $migrations::verify_current_site_schema();

		$applied = self::read_option( $migrations::VERSION_OPTION );
		$detail  = [
			'version_option' => $migrations::VERSION_OPTION,
			'applied'        => null === $applied ? 'unreadable' : ( '' === $applied ? '(absent)' : $applied ),
			'expected'       => $migrations::DB_VERSION,
		];

		if ( is_wp_error( $verified ) ) {
			$detail['structure'] = 'invalid';
			// The verifier's own code, not its message: the code is a stable
			// identifier, while messages are prose that may change.
			$detail['verifier_code'] = (string) $verified->get_error_code();

			return self::fail( $fail_code, $detail );
		}

		$detail['structure'] = 'valid';

		if ( null === $applied || (int) $applied < $migrations::DB_VERSION ) {
			$detail['version_current'] = false;

			return self::fail( $fail_code, $detail );
		}

		$detail['version_current'] = true;

		return self::pass( $detail );
	}

	/* ------------------------------------------------------------------ *
	 * Primitives
	 * ------------------------------------------------------------------ */

	/**
	 * Lowercased set of every table in the current database.
	 *
	 * SCOPED TO THIS HALL. A bare SHOW TABLES would pull back every table on the
	 * network — every other hall's and the network's own — which is both wasteful
	 * and a tenancy smell in a per-hall evaluator. The LIKE pattern is built from
	 * the switched prefix, so it re-targets with the site.
	 *
	 * Deliberately not an information_schema query: this repository already
	 * carries a recorded information_schema false-negative follow-up, so the
	 * simpler catalogue is preferred where it suffices.
	 *
	 * @return array<string,bool>|null Null when the inventory could not be read.
	 */
	private static function existing_tables(): ?array {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' )
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$set = [];
		foreach ( $rows as $name ) {
			$set[ strtolower( (string) $name ) ] = true;
		}

		return $set;
	}

	/**
	 * Read one option from the CURRENT site's options table.
	 *
	 * Returns '' for a verified-absent option and null when the read itself
	 * failed. get_option() collapses those two into false, which would let an
	 * unreadable option be reported as a clean absence.
	 */
	private static function read_option( string $name ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return null === $value ? '' : (string) $value;
	}

	/** @return \WP_Site|\WP_Error */
	private static function validate_site( int $blog_id ) {
		if ( ! is_multisite() ) {
			return self::error( 'multisite_required', 'Setup readiness requires WordPress Multisite.' );
		}
		if ( $blog_id < 1 ) {
			return self::error( 'invalid_blog_id', 'The WordPress site ID is invalid.' );
		}

		$site = get_site( $blog_id );
		if ( ! $site instanceof \WP_Site ) {
			return self::error( 'site_not_found', 'The target WordPress site does not exist.' );
		}

		$network_id = get_current_network_id();
		if ( (int) $site->site_id !== $network_id ) {
			return self::error( 'site_wrong_network', 'The target WordPress site does not belong to the current network.' );
		}
		if ( is_main_site( $blog_id, $network_id ) ) {
			return self::error( 'main_site_not_allowed', 'The main network site is not a hall.' );
		}

		return $site;
	}

	/** @param array<string,mixed> $detail */
	private static function pass( array $detail ): array {
		return [ 'ok' => true, 'code' => 'ok', 'detail' => $detail ];
	}

	/** @param array<string,mixed> $detail */
	private static function fail( string $code, array $detail ): array {
		return [ 'ok' => false, 'code' => $code, 'detail' => $detail ];
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, __( $message, 'bingocommerce-portal-integration' ) );
	}
}
