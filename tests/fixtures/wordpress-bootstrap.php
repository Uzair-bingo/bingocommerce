<?php

/**
 * WordPress bootstrap for the CLI harnesses that must run against the isolated
 * fixture. FAIL-CLOSED: it has no path to the development database.
 *
 * WHY THERE IS NO FALLBACK. An earlier revision of this file fell back to
 * `require wp-load.php` when `BC_TEST_DB` was absent, so that routing a harness
 * through it was behaviour-preserving. That fallback is the exact mechanism by
 * which a site-creating suite, run without the variable, applied Sessions v3
 * structure to the persistent development installation: `bingocommerce-sessions`
 * is network-active there, so every wp_insert_site() fires its
 * `wp_initialize_site` handler and provisions the CURRENT schema into whichever
 * database is booted. A default that is correct only when the operator
 * remembers an environment variable is not a guard. This file now refuses to
 * boot without one, and the refusal is unconditional.
 *
 * Consequently only harnesses that MUST run against the fixture are routed
 * here. Every other harness still requires `wp-load.php` directly and is
 * unchanged; routing one of those through this file would have been a change
 * with no reason behind it.
 *
 * The fixture itself (tests/fixtures/isolated-multisite.php) is guarded by its
 * own allowlist, its own sentinel, and an explicit refusal of the development
 * database name. It opens ONE connection to the development database, which
 * issues `SHOW CREATE TABLE` and nothing else, to mirror table STRUCTURE into a
 * newly created fixture; WordPress is never pointed at that connection and no
 * statement on it writes.
 *
 * WHY THE PLUGINS ARE INITIALIZED EXPLICITLY HERE. The fixture network-activates
 * `bingocommerce-portal-integration` and, on request, WooCommerce; the other
 * BingoCommerce plugins are required by the harness AFTER `plugins_loaded` has
 * already fired, so the `add_action( 'plugins_loaded', ... )` inside each
 * Plugin::boot() can never run. Their `wp_initialize_site` handlers would then
 * be absent and a newly created disposable hall would come up with no Catalog
 * or Session tables and no Hall roles — a fixture artefact, not a defect in the
 * code under test. Calling each Plugin::init() puts those handlers back exactly
 * where a network-activated plugin would have registered them.
 *
 * A disposable Network Super Admin is also ensured, because the fixture seeds
 * the network's `site_admins` list with `fixture_admin` but does not create that
 * account, and every site-creating harness needs one.
 */

if ( defined( 'BC_TEST_BOOTSTRAPPED' ) ) {
	return;
}
define( 'BC_TEST_BOOTSTRAPPED', true );

/*
 * THE FAIL-CLOSED GATE. Enforced before anything is loaded, so a harness that
 * reaches this file without naming its database stops here rather than
 * discovering the development installation underneath it.
 *
 * The name is only accepted, not trusted: tests/fixtures/isolated-multisite.php
 * checks it against the fixture allowlist, refuses it if it equals the
 * development database name from wp-config.php, and proves sentinel ownership
 * before it issues a single write.
 */
if ( '' === (string) getenv( 'BC_TEST_DB' ) ) {
	fwrite(
		STDERR,
		"FIXTURE GUARD: BC_TEST_DB is not set.\n"
		. "This harness boots the isolated fixture and has no development-database fallback.\n"
		. "Re-run it as: BC_TEST_DB=bingocommerce_state_test_suites php " . ( $_SERVER['argv'][0] ?? 'tests/<harness>.php' ) . "\n"
	);
	exit( 1 );
}

// Load the Portal Integration plugin the way production does, so its
// `plugins_loaded` hooks are observed in their real lifecycle position.
if ( '' === (string) getenv( 'BC_FIXTURE_NETWORK_ACTIVE' ) ) {
	putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
}

/*
 * WooCommerce is network-active on the development installation, and the Hall
 * Manager role copies its native baseline from the LIVE Shop Manager role, so a
 * fixture without WooCommerce would give every hall a Hall Manager that is
 * missing every WooCommerce capability — a difference in the fixture, reported
 * as a difference in the code.
 */
/*
 * WooCommerce is loaded only when the HARNESS asked the fixture for it. It is
 * network-active on the development installation, and the Hall Manager role
 * copies its native baseline from the LIVE Shop Manager role — so a suite that
 * asserts on that baseline must opt in. Forcing it on every suite would
 * network-activate WooCommerce against a fixture MAIN site that has none of its
 * tables, which is a fixture problem rather than a property of the code under
 * test.
 */

/*
 * THE DEVELOPMENT HOSTNAME IS DROPPED IN FIXTURE MODE.
 *
 * These harnesses set `$_SERVER['HTTP_HOST']` to the DEVELOPMENT installation's
 * host before bootstrapping, which is correct against wp-load.php and wrong
 * here: the fixture network answers for its own domain, and Multisite resolves
 * the current network and site from HTTP_HOST. Booting the fixture while
 * claiming a hostname it does not own sends WordPress down
 * ms_load_current_site_and_network()'s not-installed path, which terminates the
 * process before the harness runs a single assertion — silently, because it
 * happens during the bootstrap.
 *
 * Unsetting it lets the fixture supply its own value through the "defaults
 * only" rule it already applies to the rest of $_SERVER. Nothing else is
 * touched: REQUEST_URI, REQUEST_METHOD and REMOTE_ADDR are the same values the
 * fixture would have chosen anyway.
 */
unset( $_SERVER['HTTP_HOST'] );

require __DIR__ . '/isolated-multisite.php';

/*
 * PROOF, NOT TRUST. The fixture has already checked the allowlist, refused the
 * development database by name and verified sentinel ownership. This asserts
 * the OUTCOME of those checks — the database WordPress is actually bound to —
 * so a future change that weakens one of them fails here instead of running.
 *
 * It covers every harness routed through this file, workers included, which is
 * why it lives in the bootstrap rather than being repeated in each suite.
 */
if ( ! defined( 'DB_NAME' ) || ! in_array( DB_NAME, BC_FIXTURE_DB_ALLOWLIST, true ) ) {
	fwrite(
		STDERR,
		'FIXTURE GUARD: WordPress is bound to ' . ( defined( 'DB_NAME' ) ? "'" . DB_NAME . "'" : 'an undetermined database' )
		. ", which is not an isolated fixture database.\n"
	);
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Load and initialize the BingoCommerce plugins the fixture does not
 * network-activate, in dependency order.
 */
foreach (
	[
		[ '\BingoCommerce\Core\Plugin', 'bingocommerce-core/bingocommerce-core.php' ],
		[ '\BingoCommerce\Catalog\Plugin', 'bingocommerce-catalog/bingocommerce-catalog.php' ],
		[ '\BingoCommerce\Sessions\Plugin', 'bingocommerce-sessions/bingocommerce-sessions.php' ],
	] as $bc_plugin
) {
	if ( ! class_exists( $bc_plugin[0] ) ) {
		require_once ABSPATH . 'wp-content/plugins/' . $bc_plugin[1];
	}
	if ( is_callable( [ $bc_plugin[0], 'init' ] ) ) {
		// `plugins_loaded` has already fired, so Plugin::boot()'s deferred
		// registration would never run. This is the same registration, at the
		// only moment still available.
		call_user_func( [ $bc_plugin[0], 'init' ] );
	}
}

/*
 * Ensure the disposable Network Super Admin the fixture's `site_admins` option
 * already names. Created only when absent, and left in place: it is fixture
 * infrastructure, like the seeded sites, not a per-run artefact.
 */
if ( ! username_exists( 'fixture_admin' ) ) {
	wp_insert_user(
		[
			'user_login' => 'fixture_admin',
			'user_pass'  => wp_generate_password( 32 ),
			'user_email' => 'fixture-admin@example.invalid',
			'role'       => '',
		]
	);
}

/*
 * MIRROR THE DEVELOPMENT INSTALLATION'S LEGACY SITE RANGE.
 *
 * The suites written against wp-load.php guard their disposable halls with
 * `blog_id > 4`, because the development installation's legacy sites are 1-4
 * (ADR-042: "Sites 1-4 and Portal Hall 2 -> site 2 remain legacy/untouched").
 * The fixture seeds three, so its first disposable hall would be blog 4 and
 * those guards would refuse it. One more seeded hall makes the fixture's legacy
 * range the same shape as the installation the guards describe.
 *
 * Created once and left in place: it is fixture infrastructure, like the sites
 * the fixture itself seeds, not a per-run artefact.
 */
if ( ! get_site( 4 ) instanceof WP_Site ) {
	$bc_fixture_network = get_network();
	if ( $bc_fixture_network instanceof WP_Network ) {
		$bc_fixture_seed = wp_insert_site(
			[
				'blog_id'    => 4,
				'domain'     => 'hall-c.' . BC_FIXTURE_DOMAIN,
				'path'       => '/',
				'network_id' => (int) $bc_fixture_network->id,
				'title'      => 'Fixture legacy hall C',
				'user_id'    => (int) ( username_exists( 'fixture_admin' ) ?: 0 ),
			]
		);
		unset( $bc_fixture_seed );
	}
	unset( $bc_fixture_network );
}

/*
 * ENSURE THE NETWORK SCHEMAS THE DEVELOPMENT INSTALLATION ALREADY HAS.
 *
 * Suites written against wp-load.php assert that the Hall mapping schema is
 * already current, because on the development installation it has been for
 * months. On a freshly created fixture database nothing is installed, so each
 * owner's own idempotent migration is run once here.
 *
 * The ADR-042 provisioning schema is deliberately NOT installed: the harnesses
 * that use it create it themselves and refuse to adopt one they did not create,
 * and installing it here would make those refusals unreachable.
 */
if ( class_exists( '\BingoCommerce\Core\Migrations' ) ) {
	\BingoCommerce\Core\Migrations::maybe_migrate_network();
}
if ( class_exists( '\BingoCommerce\PortalIntegration\Migrations' )
	&& ! \BingoCommerce\PortalIntegration\Migrations::is_current() ) {
	\BingoCommerce\PortalIntegration\Migrations::migrate_network();
}

/*
 * BRING THE SEEDED FIXTURE HALLS UP TO A DEVELOPMENT HALL'S SHAPE.
 *
 * The fixture seeds two hall SITES but provisions nothing on them, while the
 * development installation's halls have long since been through WooCommerce
 * installation and BingoCommerce role synchronization. A suite that asserts
 * "an ALREADY-PROVISIONED hall converges without disturbing anything else" is
 * describing that shape, and against a bare fixture hall it would be measuring
 * first-time provisioning instead.
 *
 * Each step is the owner's own idempotent entry point, gated on whether the
 * work is already done, so this costs one option read per hall on later runs.
 */
foreach ( [ BC_FIXTURE_HALL_A, BC_FIXTURE_HALL_B, 4 ] as $bc_fixture_hall ) {
	if ( ! get_site( $bc_fixture_hall ) instanceof WP_Site ) {
		continue;
	}

	/*
	 * THE SEEDED HALLS ARE SITE ROWS WITH NO TABLES. The fixture inserts them
	 * into `blogs` directly and mirrors only the MAIN site's per-site tables, so
	 * switching to one lands on a site whose options table does not exist —
	 * which WordPress reports as "Error establishing a database connection" the
	 * moment anything asks it a question. Core's own initializer is run once to
	 * give the hall the tables a real site has.
	 */
	/*
	 * Probed with a direct table lookup rather than is_blog_installed(): that
	 * function ends in `wpdb::bail()` when a site's tables are absent, which
	 * terminates the process with "Error establishing a database connection"
	 * instead of returning false — and "absent" is exactly the case this probe
	 * exists to detect. The name is composed from $wpdb->get_blog_prefix() and
	 * is re-checked against the identifier grammar before it reaches a
	 * statement.
	 */
	$bc_fixture_options   = $GLOBALS['wpdb']->get_blog_prefix( $bc_fixture_hall ) . 'options';
	$bc_fixture_installed = 1 === preg_match( '/^[A-Za-z0-9_]+$/', $bc_fixture_options )
		&& $bc_fixture_options === $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $GLOBALS['wpdb']->esc_like( $bc_fixture_options ) )
		);

	if ( ! $bc_fixture_installed ) {
		wp_initialize_site(
			$bc_fixture_hall,
			[
				'title'   => 'Fixture hall ' . $bc_fixture_hall,
				'user_id' => (int) ( username_exists( 'fixture_admin' ) ?: 0 ),
			]
		);
	}

	switch_to_blog( $bc_fixture_hall );
	try {
		/*
		 * Only when the harness opted into WooCommerce. `WC_Install::install()`
		 * is WooCommerce's own idempotent installer and is skipped entirely once
		 * a version is recorded for the hall.
		 */
		if ( class_exists( '\WC_Install' ) && ! get_option( 'woocommerce_version' ) ) {
			\WC_Install::install();
		}

		/*
		 * THE HALL'S OWN PRODUCT-ATTRIBUTES LOOKUP TABLE.
		 *
		 * WC_Install::get_schema() takes this table's name from
		 * DataRegenerator, and DataRegenerator is a DI-container SINGLETON that
		 * freezes `$lookup_table_name` from `$wpdb->prefix` in its constructor.
		 * On a real installation each hall's WooCommerce is installed in its own
		 * web request, so the container is built with that hall's prefix and the
		 * name is right. A CLI fixture installs three halls in ONE process: the
		 * container is built once, while the main site is current, and every
		 * later switch_to_blog() still emits the MAIN site's name — so all three
		 * halls share `bc_wc_product_attributes_lookup` and none of them has its
		 * own. tests/setup-readiness.php then reports `woocommerce_tables_missing`
		 * for halls 2, 3 and 4, which is a fixture artefact and not a property of
		 * the code under test.
		 *
		 * The remedy re-points WooCommerce's OWN schema string at the current
		 * hall rather than restating it here, so the fixture cannot drift from
		 * the definition WC_Install uses. dbDelta is idempotent and issues
		 * nothing once the table matches.
		 */
		if ( function_exists( 'wc_get_container' )
			&& class_exists( '\Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator' ) ) {
			$bc_fixture_regen  = wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator::class );
			$bc_fixture_frozen = (string) $bc_fixture_regen->get_lookup_table_name();
			$bc_fixture_wanted = $GLOBALS['wpdb']->prefix . 'wc_product_attributes_lookup';
			$bc_fixture_sql    = (string) $bc_fixture_regen->get_table_creation_sql();

			if ( '' !== $bc_fixture_frozen && $bc_fixture_frozen !== $bc_fixture_wanted ) {
				$bc_fixture_sql = str_replace( $bc_fixture_frozen, $bc_fixture_wanted, $bc_fixture_sql );
			}
			dbDelta( $bc_fixture_sql );
			unset( $bc_fixture_regen, $bc_fixture_frozen, $bc_fixture_wanted, $bc_fixture_sql );
		}

		/*
		 * MATCH THE DEVELOPMENT INSTALLATION'S HPOS CONFIGURATION.
		 *
		 * Every development hall runs WooCommerce 11.0.1 with
		 * `woocommerce_custom_orders_table_enabled = yes`. The fixture's
		 * WC_Install run CREATES the HPOS tables — it records
		 * `woocommerce_custom_orders_table_created = yes` — but leaves usage
		 * switched off, because WooCommerce only turns it on by itself for a shop
		 * it judges to be new. A suite that asserts on hall readiness would then
		 * be reading a configuration no hall in this platform actually has.
		 *
		 * This sets a site OPTION to the value the development halls carry. It
		 * does not stub, bypass or reimplement anything: the tables are
		 * WooCommerce's, created by WooCommerce's own installer.
		 */
		if ( class_exists( '\WC_Install' ) && 'yes' === get_option( 'woocommerce_custom_orders_table_created' )
			&& 'yes' !== get_option( 'woocommerce_custom_orders_table_enabled' ) ) {
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		}

		/*
		 * Action Scheduler's own per-site tables.
		 *
		 * WC_Install::install() does not create them: on a real installation
		 * Action Scheduler registers its schema on `init` and creates them the
		 * first time it runs, which a CLI harness never reaches. Without them
		 * every WC_Product->save() emits "table doesn't exist" errors from the
		 * product-attribute lookup's scheduled callback, and the product write
		 * that a Package fixture depends on fails — a fixture artefact, not a
		 * defect in the code under test. The development installation has these
		 * tables, so creating them here matches the shape the suites were
		 * written against.
		 *
		 * Both schema classes are Action Scheduler's own, and register_tables()
		 * is idempotent: it is a dbDelta wrapper that issues nothing once the
		 * tables match.
		 */
		foreach ( [ '\ActionScheduler_StoreSchema', '\ActionScheduler_LoggerSchema' ] as $bc_fixture_as_schema ) {
			if ( class_exists( $bc_fixture_as_schema ) ) {
				( new $bc_fixture_as_schema() )->register_tables( true );
			}
		}
		unset( $bc_fixture_as_schema );
		if ( class_exists( '\BingoCommerce\Core\Roles' )
			&& (int) get_option( \BingoCommerce\Core\Roles::SITE_VERSION_OPTION, 0 ) < \BingoCommerce\Core\Roles::SCHEMA_VERSION ) {
			// add_cap() updates the stored role option but not the cached
			// WP_Role objects, so the roles are re-read before the Hall roles
			// copy their native baseline from the live Shop Manager role.
			if ( function_exists( 'wp_roles' ) ) {
				wp_roles()->for_site( $bc_fixture_hall );
			}
			\BingoCommerce\Core\Roles::sync_current_site();
		}
		if ( class_exists( '\BingoCommerce\Catalog\Migrations' ) && ! \BingoCommerce\Catalog\Migrations::is_current() ) {
			\BingoCommerce\Catalog\Migrations::migrate_current_site();
		}
		if ( class_exists( '\BingoCommerce\Sessions\Migrations' ) && ! \BingoCommerce\Sessions\Migrations::is_current() ) {
			\BingoCommerce\Sessions\Migrations::migrate_current_site();
		}
	} finally {
		restore_current_blog();
	}
}
unset( $bc_fixture_hall, $bc_fixture_installed, $bc_fixture_options );
