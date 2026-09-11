<?php

/**
 * Isolated WordPress Multisite fixture.
 *
 * Boots WordPress against a dedicated, allowlisted, fixture-owned database.
 * wp-config.php is deliberately NOT loaded: this file supplies the constants
 * WordPress needs and requires wp-settings.php directly, so the development
 * database is never opened by the bootstrap and no development row can be
 * written by it.
 *
 * OWNERSHIP IS ESTABLISHED BEFORE ANYTHING IS WRITTEN.
 *
 *   - An EXISTING database must already carry this fixture's sentinel, with the
 *     exact fixture identity, before a single statement modifies it. An unmarked
 *     or foreign database is refused untouched.
 *   - A database this run CREATES is proven empty first, and only then stamped.
 *     Creation is what establishes ownership; the stamp records it.
 *
 * Table STRUCTURE is copied from the development installation with
 * SHOW CREATE TABLE — a read — so the fixture cannot drift from the schema the
 * code actually runs against. No development ROW is ever read or copied, and no
 * secret is written into the fixture: the database credentials are read from
 * wp-config.php only to reach the same MySQL server.
 */

/*
 * SAPI guard.
 *
 * CLI is the normal case. `cgi-fcgi` is additionally permitted, because request
 * enforcement cannot be proven under CLI — header() is a no-op there and a
 * gate that skips CLI would never run — so one worker drives a real non-CLI
 * SAPI from the command line via php-cgi.
 *
 * That permission is deliberately narrow and cannot be reached from the web:
 * it requires an environment variable, which an HTTP client cannot set, AND the
 * absence of the two variables every real web server supplies. A request
 * arriving through a server therefore still hits the refusal below.
 */
$bc_fixture_sapi_allowed = 'cli' === PHP_SAPI
	|| (
		'cgi-fcgi' === PHP_SAPI
		&& '1' === (string) getenv( 'BC_FIXTURE_CGI' )
		&& ! isset( $_SERVER['SERVER_SOFTWARE'] )
		&& ! isset( $_SERVER['REMOTE_ADDR'] )
	);

if ( ! $bc_fixture_sapi_allowed ) {
	fwrite( STDERR, "The isolated fixture is command-line only.\n" );
	exit( 1 );
}
unset( $bc_fixture_sapi_allowed );

/** Databases this fixture may create, own, write and drop tables in. */
const BC_FIXTURE_DB_ALLOWLIST = [ 'bingocommerce_state_test', 'bingocommerce_state_test_scratch' ];

/** Sentinel table and the exact identity this fixture claims. */
const BC_FIXTURE_SENTINEL_TABLE = 'bc_fixture_sentinel';
const BC_FIXTURE_IDENTITY       = 'bingocommerce:hall-commerce-state:v1';

/** Network/site identities the fixture owns. Not development identities. */
const BC_FIXTURE_DOMAIN  = 'fixture.bingocommerce.test';
const BC_FIXTURE_HALL_A  = 2;
const BC_FIXTURE_HALL_B  = 3;

final class BC_Fixture {

	public static string $database   = '';
	public static string $live_db    = '';
	public static bool   $created_by_this_run = false;

	private static string $user   = '';
	private static string $pass   = '';
	private static string $host   = '';
	private static string $prefix = 'bc_';

	/** Tables whose STRUCTURE the fixture mirrors from the development schema. */
	private const STRUCTURE = [
		// Network-global.
		'site', 'sitemeta', 'blogs', 'blogmeta', 'users', 'usermeta',
		// Per-blog set for the fixture's main site. is_blog_installed() verifies
		// the full set exists, so a partial copy makes WordPress refuse to boot.
		'options', 'posts', 'postmeta', 'comments', 'commentmeta',
		'links', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
	];

	public static function abort( string $reason ): void {
		fwrite( STDERR, "FIXTURE GUARD: {$reason}\n" );
		fwrite( STDERR, "Refusing to run. There is no fallback to the development database.\n" );
		exit( 1 );
	}

	/**
	 * Guard, build and seed the fixture, then define WordPress's constants.
	 *
	 * Deliberately does NOT require wp-settings.php: that file declares dozens of
	 * globals with plain `$var = ...` statements, so requiring it from inside a
	 * method would make every one of them a method local and WordPress would
	 * bootstrap into a broken state. The caller requires it at global scope.
	 */
	public static function prepare(): void {
		$root = dirname( __DIR__, 2 );

		self::read_credentials( $root );
		self::select_database();
		self::refuse_incompatible_dropins( $root );
		$created = self::ensure_database_exists();

		$db = self::connect( self::$database );
		self::establish_ownership( $db, $created );
		self::build_schema( $db, $root );
		self::seed( $db );
		$db->close();

		self::define_constants( $root );
	}

	public static function prefix(): string {
		return self::$prefix;
	}

	/* ------------------------------------------------------------------ *
	 * Guard
	 * ------------------------------------------------------------------ */

	private static function read_credentials( string $root ): void {
		$config = (string) file_get_contents( $root . '/wp-config.php' );
		$read   = static function ( string $name ) use ( $config ): string {
			return preg_match( "/define\(\s*'" . $name . "'\s*,\s*'([^']*)'/", $config, $m ) ? $m[1] : '';
		};

		self::$user    = $read( 'DB_USER' );
		self::$pass    = $read( 'DB_PASSWORD' );
		self::$host    = $read( 'DB_HOST' );
		self::$live_db = $read( 'DB_NAME' );

		if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([^\'"]+)/', $config, $m ) ) {
			self::$prefix = $m[1];
		}

		if ( '' === self::$live_db ) {
			self::abort( 'the development database name could not be determined.' );
		}
	}

	private static function select_database(): void {
		$target = (string) getenv( 'BC_TEST_DB' );

		if ( '' === $target ) {
			self::abort( 'BC_TEST_DB is not set. Name the isolated database explicitly.' );
		}
		if ( ! in_array( $target, BC_FIXTURE_DB_ALLOWLIST, true ) ) {
			self::abort( "'{$target}' is not in the fixture allowlist." );
		}
		if ( $target === self::$live_db ) {
			self::abort( 'the fixture database must differ from the development database.' );
		}

		self::$database = $target;
	}

	private static function connect( ?string $database ): mysqli {
		mysqli_report( MYSQLI_REPORT_OFF );
		$db = @new mysqli( self::$host, self::$user, self::$pass, $database ?? '' );
		if ( $db->connect_errno ) {
			self::abort( 'could not connect to the database server.' );
		}

		return $db;
	}

	/**
	 * Prove ownership BEFORE any write.
	 *
	 * Existing database: the sentinel must already be there and must carry this
	 * fixture's identity. Newly created: prove it is empty, then stamp it.
	 */
	private static function establish_ownership( mysqli $db, bool $created ): void {
		$tables = self::table_names( $db );

		if ( ! $created ) {
			/*
			 * PRE-EXISTING database. It must already carry this fixture's
			 * sentinel — an empty one is refused too, because "empty" is not
			 * ownership and the next run would inherit whatever this one wrote.
			 */
			if ( ! in_array( BC_FIXTURE_SENTINEL_TABLE, $tables, true ) ) {
				self::abort( 'the database exists but carries no fixture sentinel. Refusing to modify it.' );
			}

			$stmt = $db->prepare( 'SELECT identity FROM `' . BC_FIXTURE_SENTINEL_TABLE . '` LIMIT 1' );
			if ( ! $stmt || ! $stmt->execute() ) {
				self::abort( 'the fixture sentinel could not be read.' );
			}
			$result = $stmt->get_result();
			if ( ! $result instanceof mysqli_result ) {
				$stmt->close();
				self::abort( 'the fixture sentinel query returned no result.' );
			}
			$identity = (string) ( $result->fetch_row()[0] ?? '' );
			$stmt->close();

			if ( BC_FIXTURE_IDENTITY !== $identity ) {
				self::abort( 'the database carries a different fixture identity. Refusing to modify it.' );
			}

			return;
		}

		/*
		 * CREATED BY THIS RUN. Ownership comes from having created it, not from
		 * the stamp; the stamp merely records it for later runs.
		 */
		if ( [] !== $tables ) {
			self::abort( 'a freshly created database was not empty. Refusing to modify it.' );
		}

		if ( ! $db->query( 'CREATE TABLE `' . BC_FIXTURE_SENTINEL_TABLE . '` ( identity varchar(191) NOT NULL, PRIMARY KEY (identity) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' ) ) {
			self::abort( 'the fixture sentinel could not be created.' );
		}
		$stmt     = $db->prepare( 'INSERT INTO `' . BC_FIXTURE_SENTINEL_TABLE . '` (identity) VALUES (?)' );
		$identity = BC_FIXTURE_IDENTITY;
		$stmt->bind_param( 's', $identity );
		if ( ! $stmt->execute() ) {
			$stmt->close();
			self::abort( 'the fixture sentinel could not be stamped.' );
		}
		$stmt->close();

		self::$created_by_this_run = true;
	}

	/**
	 * Refuse an environment whose drop-ins could reach development.
	 *
	 * A db.php drop-in replaces wpdb entirely and could route writes anywhere,
	 * so the fixture will not boot alongside one. object-cache.php is handled by
	 * the supported enable_loading_object_cache_dropin filter seeded below, but
	 * a routing drop-in has no such switch.
	 */
	private static function refuse_incompatible_dropins( string $root ): void {
		if ( file_exists( $root . '/wp-content/db.php' ) ) {
			self::abort( 'a db.php database-routing drop-in is present. Refusing to boot the fixture.' );
		}
	}

	/**
	 * Create the database only if absent.
	 *
	 * @return bool True only when THIS run created it.
	 */
	private static function ensure_database_exists(): bool {
		$server = self::connect( null );
		$stmt   = $server->prepare( 'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?' );
		$stmt->bind_param( 's', self::$database );
		$stmt->execute();
		$found = (string) ( $stmt->get_result()->fetch_row()[0] ?? '' );
		$stmt->close();

		$created = false;
		if ( '' === $found ) {
			if ( ! $server->query( 'CREATE DATABASE `' . self::$database . '` DEFAULT CHARACTER SET utf8mb4' ) ) {
				self::abort( 'the fixture database could not be created.' );
			}
			$created = true;
		}
		$server->close();

		return $created;
	}

	/** @return array<int,string> */
	private static function table_names( mysqli $db ): array {
		$out = [];
		$res = $db->query( 'SHOW TABLES' );
		if ( ! $res instanceof mysqli_result ) {
			self::abort( 'the fixture tables could not be listed.' );
		}
		while ( $row = $res->fetch_row() ) {
			$out[] = (string) $row[0];
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Schema and seed
	 * ------------------------------------------------------------------ */

	private static function build_schema( mysqli $db, string $root ): void {
		/*
		 * The development tables were created under a looser sql_mode and carry
		 * '0000-00-00 00:00:00' defaults, which this server rejects under
		 * NO_ZERO_DATE. Relaxing the mode for THIS connection lets the fixture
		 * mirror the real structure exactly instead of quietly altering it.
		 * Only the fixture connection is affected; no server or development
		 * setting is changed.
		 */
		$db->query( "SET SESSION sql_mode = ''" );

		$dev = self::connect( self::$live_db );

		foreach ( self::STRUCTURE as $suffix ) {
			$table = self::$prefix . $suffix;

			$res = $dev->query( 'SHOW CREATE TABLE `' . $table . '`' );
			if ( ! $res instanceof mysqli_result ) {
				self::abort( "the development structure for {$table} could not be read." );
			}
			$create = (string) ( $res->fetch_row()[1] ?? '' );
			if ( '' === $create ) {
				self::abort( "the development structure for {$table} was empty." );
			}

			// Structure only. AUTO_INCREMENT counters are stripped so the fixture
			// does not inherit development row identifiers.
			$create = preg_replace( '/ AUTO_INCREMENT=\d+/', '', $create );
			$create = preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', (string) $create );

			if ( ! $db->query( (string) $create ) ) {
				self::abort( "the fixture table {$table} could not be created." );
			}
		}

		$dev->close();
	}

	/**
	 * Seed the fixture WITHOUT destroying state a run already depends on.
	 *
	 * Deliberately not DELETE-then-INSERT. Worker processes boot this same
	 * fixture mid-run, and wiping sitemeta would erase the schema version the
	 * parent had just recorded — the workers would then find the schema
	 * "unavailable" and the concurrency evidence would be meaningless. Every
	 * insert below is conditional, so booting is idempotent and additive.
	 */
	private static function seed( mysqli $db ): void {
		$p   = self::$prefix;
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( 0 === self::count( $db, "SELECT COUNT(*) FROM `{$p}site`" ) ) {
			$stmt   = $db->prepare( "INSERT INTO `{$p}site` (id, domain, path) VALUES (1, ?, '/')" );
			$domain = BC_FIXTURE_DOMAIN;
			$stmt->bind_param( 's', $domain );
			$stmt->execute();
			$stmt->close();
		}

		$blogs = [
			[ 1, BC_FIXTURE_DOMAIN ],
			[ BC_FIXTURE_HALL_A, 'hall-a.' . BC_FIXTURE_DOMAIN ],
			[ BC_FIXTURE_HALL_B, 'hall-b.' . BC_FIXTURE_DOMAIN ],
		];
		foreach ( $blogs as [ $blog_id, $blog_domain ] ) {
			$stmt = $db->prepare( "SELECT COUNT(*) FROM `{$p}blogs` WHERE blog_id = ?" );
			$stmt->bind_param( 'i', $blog_id );
			$stmt->execute();
			$exists = (int) ( $stmt->get_result()->fetch_row()[0] ?? 0 );
			$stmt->close();
			if ( $exists > 0 ) {
				continue;
			}

			$stmt = $db->prepare(
				"INSERT INTO `{$p}blogs` (blog_id, site_id, domain, path, registered, last_updated, public, archived, mature, spam, deleted, lang_id)
				 VALUES (?, 1, ?, '/', ?, ?, 1, 0, 0, 0, 0, 0)"
			);
			$stmt->bind_param( 'isss', $blog_id, $blog_domain, $now, $now );
			$stmt->execute();
			$stmt->close();
		}

		$db_version = self::wp_db_version( dirname( __DIR__, 2 ) );

		/*
		 * Network-active plugin list, set on EVERY boot rather than only when
		 * absent, so it is deterministic in both directions and one run cannot
		 * inherit the other's choice.
		 *
		 * With BC_FIXTURE_NETWORK_ACTIVE=1 the Portal Integration plugin is
		 * loaded by wp-settings.php itself, exactly as a network-activated
		 * plugin is in production — which is the only way its `plugins_loaded`
		 * hook can be observed firing in its real lifecycle position. Without
		 * it, the list is empty and callers require the plugin themselves.
		 * BC_FIXTURE_WOOCOMMERCE=1 adds the installed WooCommerce alongside it,
		 * so its real payment and Action Scheduler handlers can be executed.
		 */
		// The activation timestamp is fixed rather than time(): WordPress only
		// records it, and a moving value would make the fixture's own sitemeta
		// differ between two boots inside one test run.
		$plugins = [];
		if ( '1' === (string) getenv( 'BC_FIXTURE_NETWORK_ACTIVE' ) ) {
			$plugins['bingocommerce-portal-integration/bingocommerce-portal-integration.php'] = 1;
		}
		if ( '1' === (string) getenv( 'BC_FIXTURE_WOOCOMMERCE' ) ) {
			// Opt-in, because WooCommerce's installer creates ~40 tables per site
			// and only the installed-handler integration needs them.
			$plugins['woocommerce/woocommerce.php'] = 1;
		}
		$network_active = serialize( $plugins );

		/*
		 * DELETE then INSERT, not ON DUPLICATE KEY UPDATE: WordPress's sitemeta
		 * table carries no unique key on (site_id, meta_key), so an upsert would
		 * silently accumulate a second row on every boot.
		 */
		$db->query( "DELETE FROM `{$p}sitemeta` WHERE site_id = 1 AND meta_key = 'active_sitewide_plugins'" );
		$stmt = $db->prepare( "INSERT INTO `{$p}sitemeta` (site_id, meta_key, meta_value) VALUES (1, 'active_sitewide_plugins', ?)" );
		$stmt->bind_param( 's', $network_active );
		$stmt->execute();
		$stmt->close();

		foreach (
			[
				'site_admins'             => serialize( [ 'fixture_admin' ] ),
				'siteurl'                 => 'http://' . BC_FIXTURE_DOMAIN . '/',
				'db_version'              => $db_version,
				'initial_db_version'      => $db_version,
				'WPLANG'                  => '',
			] as $key => $value
		) {
			$stmt = $db->prepare( "SELECT COUNT(*) FROM `{$p}sitemeta` WHERE site_id = 1 AND meta_key = ?" );
			$stmt->bind_param( 's', $key );
			$stmt->execute();
			$exists = (int) ( $stmt->get_result()->fetch_row()[0] ?? 0 );
			$stmt->close();
			if ( $exists > 0 ) {
				continue;
			}

			$stmt = $db->prepare( "INSERT INTO `{$p}sitemeta` (site_id, meta_key, meta_value) VALUES (1, ?, ?)" );
			$stmt->bind_param( 'ss', $key, $value );
			$stmt->execute();
			$stmt->close();
		}

		foreach (
			[
				'siteurl'            => 'http://' . BC_FIXTURE_DOMAIN,
				'home'               => 'http://' . BC_FIXTURE_DOMAIN,
				'blogname'           => 'Commerce State Fixture',
				'admin_email'        => 'fixture@example.invalid',
				'active_plugins'     => serialize( [] ),
				'template'           => 'twentytwentyfive',
				'stylesheet'         => 'twentytwentyfive',
				'db_version'         => $db_version,
				'initial_db_version' => $db_version,
				'blog_charset'       => 'UTF-8',
				'timezone_string'    => '',
				'gmt_offset'         => '0',
				'date_format'        => 'Y-m-d',
				'time_format'        => 'H:i',
				'cron'               => serialize( [] ),
				'default_role'       => 'subscriber',
				'WPLANG'             => '',
			] as $key => $value
		) {
			$stmt = $db->prepare( "SELECT COUNT(*) FROM `{$p}options` WHERE option_name = ?" );
			$stmt->bind_param( 's', $key );
			$stmt->execute();
			$exists = (int) ( $stmt->get_result()->fetch_row()[0] ?? 0 );
			$stmt->close();
			if ( $exists > 0 ) {
				continue;
			}

			$stmt = $db->prepare( "INSERT INTO `{$p}options` (option_name, option_value, autoload) VALUES (?, ?, 'yes')" );
			$stmt->bind_param( 'ss', $key, $value );
			$stmt->execute();
			$stmt->close();
		}
	}

	private static function count( mysqli $db, string $sql ): int {
		$res = $db->query( $sql );

		return $res instanceof mysqli_result ? (int) ( $res->fetch_row()[0] ?? 0 ) : 0;
	}

	private static function wp_db_version( string $root ): string {
		$version = 0;
		include $root . '/wp-includes/version.php';

		return (string) ( $wp_db_version ?? 0 );
	}

	/* ------------------------------------------------------------------ *
	 * Constants
	 * ------------------------------------------------------------------ */

	private static function define_constants( string $root ): void {
		/*
		 * Defaults only. A caller that has already described the request it wants
		 * to simulate keeps its own values — vars.php derives $pagenow from
		 * PHP_SELF while wp-settings.php loads, so clobbering these here would
		 * make every request look like the fixture's own placeholder.
		 */
		foreach (
			[
				'HTTP_HOST'      => BC_FIXTURE_DOMAIN,
				'REQUEST_URI'    => '/',
				'REQUEST_METHOD' => 'GET',
				'REMOTE_ADDR'    => '127.0.0.1',
			] as $key => $value
		) {
			if ( ! isset( $_SERVER[ $key ] ) || '' === $_SERVER[ $key ] ) {
				$_SERVER[ $key ] = $value;
			}
		}

		define( 'DB_NAME', self::$database );
		define( 'DB_USER', self::$user );
		define( 'DB_PASSWORD', self::$pass );
		define( 'DB_HOST', self::$host );
		define( 'DB_CHARSET', 'utf8mb4' );
		define( 'DB_COLLATE', '' );

		/*
		 * Fixture-only switch used by one negative control: it reproduces a
		 * connection carrying CLIENT_FOUND_ROWS, which HallCommerceState must
		 * refuse before writing. wpdb reads exactly this constant.
		 */
		if ( '1' === (string) getenv( 'BC_FIXTURE_FOUND_ROWS' ) && defined( 'MYSQLI_CLIENT_FOUND_ROWS' ) ) {
			define( 'MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_FOUND_ROWS );
		}

		// Fixture-only values. No development secret is reused here.
		foreach (
			[ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
				'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $salt
		) {
			define( $salt, 'fixture-' . strtolower( $salt ) );
		}

		define( 'WP_ALLOW_MULTISITE', true );
		define( 'MULTISITE', true );
		define( 'SUBDOMAIN_INSTALL', true );
		define( 'DOMAIN_CURRENT_SITE', BC_FIXTURE_DOMAIN );
		define( 'PATH_CURRENT_SITE', '/' );
		define( 'SITE_ID_CURRENT_SITE', 1 );
		define( 'BLOG_ID_CURRENT_SITE', 1 );

		// No outbound HTTP from this process, established before any plugin can
		// run. WP_ACCESSIBLE_HOSTS is deliberately left empty: nothing is allowed.
		define( 'WP_HTTP_BLOCK_EXTERNAL', true );
		define( 'WP_ACCESSIBLE_HOSTS', '' );

		/*
		 * WP_CACHE alone does NOT stop object-cache.php loading — that drop-in is
		 * required by wp_start_object_cache() regardless. The supported switch is
		 * the enable_loading_object_cache_dropin filter, seeded below before
		 * WordPress builds its hooks.
		 */
		define( 'WP_CACHE', false );

		define( 'DISABLE_WP_CRON', true );
		define( 'WP_DEBUG', false );

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $root . '/' );
		}

		self::seed_pre_bootstrap_hooks();
	}

	/**
	 * Install protections BEFORE WordPress builds its hook objects.
	 *
	 * wp-settings.php converts a pre-existing $wp_filter array through
	 * WP_Hook::build_preinitialized_hooks(), which is the documented way to have
	 * a filter in place before any plugin can run.
	 *
	 *   - enable_loading_object_cache_dropin (WP 5.8+) returning false means
	 *     object-cache.php is NEVER loaded, so the fixture cannot join a shared
	 *     cache namespace. Its docblock states it is intended for exactly this:
	 *     non-web runtimes.
	 *   - pre_http_request short-circuits every outbound request with a WP_Error.
	 *     WP_HTTP_BLOCK_EXTERNAL is not enough on its own: it still permits
	 *     localhost and the site's own host.
	 */
	private static function seed_pre_bootstrap_hooks(): void {
		$GLOBALS['wp_filter'] = $GLOBALS['wp_filter'] ?? [];

		$GLOBALS['wp_filter']['enable_loading_object_cache_dropin'][10]['bc_fixture_no_object_cache'] = [
			'function'       => static function () {
				return false;
			},
			'accepted_args'  => 1,
		];

		$GLOBALS['wp_filter']['pre_http_request'][1]['bc_fixture_block_http'] = [
			'function'      => static function ( $preempt, $args, $url ) {
				return new WP_Error(
					'bc_fixture_http_blocked',
					'Outbound HTTP is blocked in the isolated fixture.'
				);
			},
			'accepted_args' => 3,
		];
	}
}

/*
 * Global scope from here down. wp-settings.php must be required where its
 * globals really are globals.
 */
BC_Fixture::prepare();

$table_prefix = BC_Fixture::prefix();

require_once ABSPATH . 'wp-settings.php';
