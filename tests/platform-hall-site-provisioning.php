<?php

/**
 * CLI coverage for `bingocommerce-platform`: the ADR-042 platform provisioning
 * filter, WordPress Multisite Hall site creation, marker-matched recovery, bound
 * resume, and platform-owned site/WooCommerce initialization.
 *
 * Run from the repository root:
 *   BC_TEST_DB=bingocommerce_state_provisioning php tests/platform-hall-site-provisioning.php
 *
 * WordPress boots against the isolated fixture — see
 * tests/fixtures/isolated-multisite.php. The development database is never
 * opened by the bootstrap, its identities are never borrowed, outbound HTTP is
 * blocked before any plugin can run, persistent object caching is off, and
 * outbound mail is short-circuited before the first site is created.
 *
 * NO PORTAL REQUEST, NO REST ROUTE, NO ORCHESTRATION. This harness exercises the
 * platform consumer alone: given a command, what does it create, recover, resume
 * or refuse? Sequencing, classification, mapping, synchronization, Commerce
 * state and responses belong to tests/portal-provisioning-endpoint.php.
 *
 * Every site, table, user and option this run creates is recorded as it is
 * created and removed in teardown, and every destructive statement re-verifies
 * the connected database, the fixture allowlist, the sentinel and the target
 * name before it runs.
 */

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );
putenv( 'BC_FIXTURE_WOOCOMMERCE=1' );

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-core/bingocommerce-core.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-catalog/bingocommerce-catalog.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-sessions/bingocommerce-sessions.php';
require_once ABSPATH . 'wp-content/plugins/bingocommerce-platform/bingocommerce-platform.php';

use BingoCommerce\Platform\HallSiteAddress;
use BingoCommerce\Platform\HallSiteAdminUser;
use BingoCommerce\Platform\HallSiteCommand;
use BingoCommerce\Platform\HallSiteProvisioner;
use BingoCommerce\Platform\Plugin as PlatformPlugin;

final class PlatformHallSiteProvisioningTest {

	private int $assertions = 0;
	private int $failures   = 0;

	/** Sites THIS run created, and may therefore destroy. */
	private array $disposable_sites = [];

	/** Users THIS run created. */
	private array $disposable_users = [];

	private int $super_admin_id = 0;

	/** The network `site_admins` value as found, restored byte-for-byte in teardown. */
	private $original_site_admins = null;

	/** True when THIS run created the fixture Super Admin account. */
	private bool $owns_super_admin = false;

	/** Slugs this run reserves. Distinctive so no other run can collide. */
	private const SLUG_CREATE    = 'pltcreate';
	private const SLUG_RECOVER   = 'pltrecover';
	private const SLUG_FOREIGN   = 'pltforeign';
	private const SLUG_RESUME    = 'pltresume';
	private const SLUG_UNMARKED  = 'pltunmarked';
	private const SLUG_SEED      = 'pltseed';
	private const SLUG_THROW     = 'pltthrow';

	private function abort( string $reason ): void {
		fwrite( STDERR, "HARNESS GUARD: {$reason}\n" );
		fwrite( STDERR, "Stopping before any mutating test.\n" );
		exit( 1 );
	}

	public function run(): void {
		$this->test_environment_is_isolated();
		$this->require_clean_starting_state();

		try {
			$this->prepare_identities();
			$this->test_filter_registration();
			$this->test_seed_rule();
			$this->test_command_validation();
			$this->test_address_resolution();
			$this->test_hall_admin_configuration();
			$this->test_missing_theme_prevents_creation();
			$this->test_create_archived_and_marked();
			$this->test_no_starter_products();
			$this->test_initialization_is_idempotent();
			$this->test_marker_matched_recovery();
			$this->test_unproven_marker_states();
			$this->test_bound_resume_verification();
			$this->test_address_probe_outcomes();
			$this->test_target_validation();
			$this->test_blog_context_is_always_restored();
			$this->test_incomplete_site_does_not_terminate();
			$this->test_result_shape_validation();
			$this->test_throwable_containment();
			$this->test_no_consumer_returns_null();
			$this->test_errors_disclose_nothing();
			$this->test_no_mail_was_sent();
		} finally {
			$this->teardown();
		}

		$this->report();
	}

	/* ------------------------------------------------------------------ *
	 * Isolation evidence
	 * ------------------------------------------------------------------ */

	private function test_environment_is_isolated(): void {
		global $wpdb;

		$this->assert_true( is_multisite(), 'the fixture boots as Multisite' );
		if ( DB_NAME !== BC_Fixture::$database || DB_NAME === BC_Fixture::$live_db ) {
			$this->abort( 'WordPress is not connected to the fixture database.' );
		}
		if ( (string) $wpdb->get_var( 'SELECT DATABASE()' ) !== BC_Fixture::$database ) {
			$this->abort( 'the live connection does not report the fixture database.' );
		}
		/*
		 * The two guards above are the proof, and they ABORT rather than count.
		 * An `assert_true( true, ... )` restating them added a passing assertion
		 * that could never fail, which inflates a total without testing anything.
		 */
		$this->assert_true(
			defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL && '' === WP_ACCESSIBLE_HOSTS,
			'outbound HTTP is blocked with no accessible hosts'
		);
		$this->assert_true( ! wp_using_ext_object_cache(), 'no persistent object cache is in use' );
		$this->assert_true( ! file_exists( WP_CONTENT_DIR . '/db.php' ), 'no database-routing drop-in is present' );
		$this->assert_true( is_subdomain_install(), 'the fixture network is a subdomain installation' );
		$this->assert_true( class_exists( '\WC_Install' ), 'WooCommerce is available for per-site initialization' );

		/*
		 * MAIL IS SHORT-CIRCUITED BEFORE THE FIRST SITE EXISTS. WordPress hooks
		 * newblog_notify_siteadmin() onto `wp_initialize_site`; it only sends
		 * when the network `registrationnotification` option says yes, which this
		 * fixture never sets, but a harness that creates sites must PROVE no mail
		 * left rather than rely on an option's absence.
		 */
		$GLOBALS['bc_platform_mail_attempts'] = 0;
		add_filter(
			'pre_wp_mail',
			static function () {
				++$GLOBALS['bc_platform_mail_attempts'];

				return true;
			},
			0
		);
		$this->assert_true( 0 === $GLOBALS['bc_platform_mail_attempts'], 'no mail has been attempted before the suite starts' );
	}

	private function require_clean_starting_state(): void {
		global $wpdb;

		$sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
		$extra = array_values( array_filter( array_map( 'intval', $sites ), static fn( int $id ): bool => $id > 3 ) );
		if ( [] !== $extra ) {
			$this->abort( 'the fixture already carries sites beyond the seeded three. Refusing to adopt them.' );
		}

		foreach ( [ self::SLUG_CREATE, self::SLUG_RECOVER, self::SLUG_FOREIGN, self::SLUG_RESUME, self::SLUG_UNMARKED, self::SLUG_SEED, self::SLUG_THROW ] as $slug ) {
			$address = HallSiteAddress::forSlug( $slug );
			if ( $address instanceof WP_Error || null !== $address->existingSiteId() ) {
				$this->abort( "the reserved test address for '{$slug}' is not free." );
			}
		}

		unset( $wpdb );
	}

	private function prepare_identities(): void {
		/*
		 * The fixture's network `site_admins` option names `fixture_admin`, so
		 * creating that login is what makes it a Network Super Admin. The account
		 * is disposable and exists only in the fixture database.
		 */
		$this->original_site_admins = get_site_option( 'site_admins', null );

		$existing = username_exists( 'fixture_admin' );
		if ( $existing ) {
			$this->super_admin_id = (int) $existing;
		} else {
			$created = wp_insert_user(
				[
					'user_login' => 'fixture_admin',
					'user_pass'  => wp_generate_password( 32 ),
					'user_email' => 'fixture-admin@example.invalid',
					'role'       => '',
				]
			);
			if ( is_wp_error( $created ) ) {
				$this->abort( 'the disposable Super Admin could not be created.' );
			}
			$this->super_admin_id                     = (int) $created;
			$this->disposable_users[ (int) $created ]  = true;
			$this->owns_super_admin                    = true;
		}

		$this->assert_true( $this->super_admin_id > 0, 'a disposable Network Super Admin exists' );
		$this->assert_true( is_super_admin( $this->super_admin_id ), 'that account is a Network Super Admin' );

		/*
		 * THE HALL SITE ADMINISTRATOR CONSTANT. Defined here, from a disposable
		 * fixture identity, and never parsed or replayed from wp-config.php. Its
		 * value is not printed anywhere in this harness.
		 */
		if ( ! defined( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID' ) ) {
			define( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID', $this->super_admin_id );
		}
		$this->assert_true(
			defined( 'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID' ),
			'the Hall site administrator constant is defined for this process'
		);

		PlatformPlugin::init();
	}

	/* ------------------------------------------------------------------ *
	 * Registration and the seed rule
	 * ------------------------------------------------------------------ */

	private function test_filter_registration(): void {
		$this->assert_same(
			10,
			has_filter( PlatformPlugin::PROVISION_FILTER, [ HallSiteProvisioner::class, 'provision' ] ),
			'the platform consumer is registered at priority 10'
		);
		$this->assert_same(
			'bingocommerce_platform_provision_hall_site',
			PlatformPlugin::PROVISION_FILTER,
			'the hook name is exactly the decided one'
		);

		$hook = $GLOBALS['wp_filter'][ PlatformPlugin::PROVISION_FILTER ] ?? null;
		$this->assert_true( $hook instanceof WP_Hook, 'the filter has a registered hook object' );

		$consumers = 0;
		$args      = 0;
		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				++$consumers;
				$args = (int) $callback['accepted_args'];
			}
		}
		$this->assert_same( 1, $consumers, 'exactly one consumer is registered — no competing consumers' );
		$this->assert_same( 2, $args, 'the consumer accepts exactly two arguments' );

		foreach ( [ 'init', 'admin_init', 'wp_loaded', 'wp_initialize_site', 'rest_api_init', 'shutdown' ] as $hook_name ) {
			$this->assert_true(
				false === has_action( $hook_name, [ HallSiteProvisioner::class, 'provision' ] ),
				"the provisioner is not attached to {$hook_name}"
			);
		}
		$this->assert_true(
			false === wp_next_scheduled( 'bingocommerce_platform_provision_hall_site' ),
			'the platform registers no cron event'
		);
	}

	/**
	 * A non-null seed is returned unchanged, with zero side effects.
	 *
	 * The seed rule is what makes this a filter with ONE owner rather than a
	 * race: the orchestrator always passes null, so a non-null seed means
	 * somebody else already answered.
	 */
	private function test_seed_rule(): void {
		$command = $this->command( self::SLUG_SEED );

		$seeds = [
			'array result'  => [ 'blog_id' => 999, 'site_url' => 'http://x.invalid', 'created' => false, 'managed_marker_present' => true ],
			'WP_Error'      => new WP_Error( 'site_create_failed', 'already decided' ),
			'string'        => 'already-answered',
			'integer'       => 7,
			'false'         => false,
			'empty array'   => [],
		];

		foreach ( $seeds as $label => $seed ) {
			$before = $this->site_count();
			$result = HallSiteProvisioner::provision( $seed, $command );
			$this->assert_true( $seed === $result, "a non-null seed ({$label}) is returned unchanged" );
			$this->assert_same( $before, $this->site_count(), "a non-null seed ({$label}) creates no site" );
		}

		$address = HallSiteAddress::forSlug( self::SLUG_SEED );
		$this->assert_true(
			! $address instanceof WP_Error && null === $address->existingSiteId(),
			'the seeded address is still free after every seed case'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Defensive command validation
	 * ------------------------------------------------------------------ */

	private function test_command_validation(): void {
		$valid = $this->command( self::SLUG_SEED );

		$cases = [
			'not an array'                 => 'string',
			'null command'                 => null,
			'integer command'              => 5,
			'empty array'                  => [],
		];
		foreach ( $cases as $label => $command ) {
			$this->assert_command_refused( $command, $label );
		}

		foreach ( HallSiteCommand::KEYS as $key ) {
			$missing = $valid;
			unset( $missing[ $key ] );
			$this->assert_command_refused( $missing, "missing {$key}" );
		}

		$unknown              = $valid;
		$unknown['extra_key'] = 1;
		$this->assert_command_refused( $unknown, 'an unknown key' );

		$invalid = [
			'portal_hall_id zero'          => [ 'portal_hall_id' => 0 ],
			'portal_hall_id negative'      => [ 'portal_hall_id' => -1 ],
			'portal_hall_id string'        => [ 'portal_hall_id' => '5' ],
			'portal_hall_id float'         => [ 'portal_hall_id' => 5.0 ],
			'portal_hall_id null'          => [ 'portal_hall_id' => null ],
			'operation_id uppercase'       => [ 'operation_id' => strtoupper( '1111111a-2222-4333-8444-55555555555b' ) ],
			'operation_id v1'              => [ 'operation_id' => '11111111-2222-1333-8444-555555555555' ],
			'operation_id v5'              => [ 'operation_id' => '11111111-2222-5333-8444-555555555555' ],
			'operation_id bad variant'     => [ 'operation_id' => '11111111-2222-4333-c444-555555555555' ],
			'operation_id no hyphens'      => [ 'operation_id' => '1111111122224333844455555555555a' ],
			'operation_id padded'          => [ 'operation_id' => ' 11111111-2222-4333-8444-555555555555' ],
			'operation_id not a uuid'      => [ 'operation_id' => 'not-a-uuid' ],
			'operation_id null'            => [ 'operation_id' => null ],
			'ownership id not a uuid'      => [ 'site_ownership_id' => 'nope' ],
			'ownership id null'            => [ 'site_ownership_id' => null ],
			'slug uppercase'               => [ 'site_slug' => 'Upper' ],
			'slug leading hyphen'          => [ 'site_slug' => '-lead' ],
			'slug trailing hyphen'         => [ 'site_slug' => 'trail-' ],
			'slug underscore'              => [ 'site_slug' => 'has_underscore' ],
			'slug empty'                   => [ 'site_slug' => '' ],
			'slug too long'                => [ 'site_slug' => str_repeat( 'a', 64 ) ],
			'slug null'                    => [ 'site_slug' => null ],
			'slug integer'                 => [ 'site_slug' => 5 ],
			'hall name control character'  => [ 'hall_name' => "bad\x01name" ],
			'hall name whitespace only'    => [ 'hall_name' => "   " ],
			'hall name too long'           => [ 'hall_name' => str_repeat( 'a', 256 ) ],
			'hall name integer'            => [ 'hall_name' => 5 ],
			'blog_id zero'                 => [ 'blog_id' => 0 ],
			'blog_id negative'             => [ 'blog_id' => -3 ],
			'blog_id string'               => [ 'blog_id' => '4' ],
			'blog_id float'                => [ 'blog_id' => 4.0 ],
			'fingerprint short'            => [ 'request_fingerprint' => str_repeat( 'a', 63 ) ],
			'fingerprint uppercase'        => [ 'request_fingerprint' => str_repeat( 'A', 64 ) ],
			'fingerprint null'             => [ 'request_fingerprint' => null ],
			'fingerprint non-hex'          => [ 'request_fingerprint' => str_repeat( 'z', 64 ) ],
		];
		foreach ( $invalid as $label => $override ) {
			$this->assert_command_refused( array_merge( $valid, $override ), $label );
		}

		/*
		 * The one substitution ADR-042 forbids outright: the rotating Portal
		 * command UUID used as the permanent ownership marker.
		 */
		$confused                      = $valid;
		$confused['site_ownership_id'] = $confused['operation_id'];
		$this->assert_command_refused( $confused, 'operation_id reused as the ownership id' );

		/* The accepted boundary values are genuinely accepted. */
		$accepted = HallSiteCommand::fromFilterArgument( $valid );
		$this->assert_true( $accepted instanceof HallSiteCommand, 'a well-formed command validates' );

		/*
		 * A RELATIONAL RULE: a CREATE command (blog_id null) must name the Hall,
		 * because there is no bound site to inherit a title from. The same null
		 * Hall name IS correct once a site is bound, which is every
		 * post-ownership command.
		 */
		$null_name              = $valid;
		$null_name['hall_name'] = null;
		$this->assert_command_refused( $null_name, 'a create command with no Hall name' );

		$bound_null_name              = $valid;
		$bound_null_name['hall_name'] = null;
		$bound_null_name['blog_id']   = 2;
		$this->assert_true(
			HallSiteCommand::fromFilterArgument( $bound_null_name ) instanceof HallSiteCommand,
			'a null Hall name is accepted on a BOUND command, which is every post-ownership one'
		);

		$multibyte              = $valid;
		$multibyte['hall_name'] = str_repeat( "\u{00E9}", 255 );
		$accepted_multibyte     = HallSiteCommand::fromFilterArgument( $multibyte );
		$this->assert_true( $accepted_multibyte instanceof HallSiteCommand, 'a 255-character multibyte Hall name is accepted' );
		if ( $accepted_multibyte instanceof HallSiteCommand ) {
			$this->assert_same( 510, strlen( (string) $accepted_multibyte->hall_name ), 'the multibyte name is carried byte-for-byte, untrimmed' );
		}

		$max_slug              = $valid;
		$max_slug['site_slug'] = str_repeat( 'a', 63 );
		$this->assert_true(
			HallSiteCommand::fromFilterArgument( $max_slug ) instanceof HallSiteCommand,
			'a 63-octet slug is accepted'
		);

		/* The title falls back to the slug when the Hall name is null. */
		$titled = HallSiteCommand::fromFilterArgument( $null_name );
		if ( $titled instanceof HallSiteCommand ) {
			$this->assert_same( self::SLUG_SEED, $titled->siteTitle(), 'a null Hall name titles the site from the slug' );
		}
	}

	private function assert_command_refused( $command, string $label ): void {
		$before = $this->site_count();
		$result = HallSiteProvisioner::provision( null, $command );

		$this->assert_true( is_wp_error( $result ), "{$label} is refused" );
		if ( is_wp_error( $result ) ) {
			$this->assert_same( 'internal_error', $result->get_error_code(), "{$label} is a fixed internal_error" );
			$this->assert_true(
				$this->message_is_safe( $result, is_array( $command ) ? $command : [] ),
				"{$label} discloses no raw value"
			);
		}
		$this->assert_same( $before, $this->site_count(), "{$label} creates no site" );
	}

	/* ------------------------------------------------------------------ *
	 * Address resolution
	 * ------------------------------------------------------------------ */

	private function test_address_resolution(): void {
		$address = HallSiteAddress::forSlug( 'resolvecheck' );
		$this->assert_true( $address instanceof HallSiteAddress, 'a valid slug resolves' );
		if ( ! $address instanceof HallSiteAddress ) {
			return;
		}

		$network = get_network();
		$this->assert_same(
			'resolvecheck.' . preg_replace( '|^www\.|', '', (string) $network->domain ),
			$address->domain,
			'a subdomain network resolves the slug as a host label, exactly as wp-admin/network/site-new.php does'
		);
		$this->assert_same( (string) $network->path, $address->path, 'a subdomain network keeps the network path' );
		$this->assert_same( (int) $network->id, $address->network_id, 'the address names the CURRENT network' );
		$this->assert_true( $address->subdomain, 'the resolver reports the installed topology rather than assuming it' );
		$this->assert_true( null === $address->existingSiteId(), 'an unused address reports a verified absence' );

		foreach (
			[
				'uppercase'        => 'Upper',
				'leading hyphen'   => '-lead',
				'trailing hyphen'  => 'trail-',
				'underscore'       => 'has_underscore',
				'empty'            => '',
				'dotted'           => 'a.b',
				'too long'         => str_repeat( 'a', 64 ),
				'integer'          => 5,
				'null'             => null,
				'array'            => [],
			] as $label => $slug
		) {
			$refused = HallSiteAddress::forSlug( $slug );
			$this->assert_true( $refused instanceof WP_Error, "an invalid slug ({$label}) is refused" );
			if ( $refused instanceof WP_Error ) {
				$this->assert_same( 'address_invalid', $refused->get_error_code(), "an invalid slug ({$label}) is address_invalid" );
			}
		}

		/* An over-long resulting HOST is refused even when the label is legal. */
		$long = HallSiteAddress::forSlug( str_repeat( 'a', 63 ) );
		$this->assert_true(
			$long instanceof HallSiteAddress,
			'a 63-octet label still resolves against this short fixture domain'
		);

		/*
		 * A RESERVED name is accepted on THIS network, because reserved names are
		 * a subdirectory-only WordPress restriction and this fixture — like the
		 * development installation and ADR-001 — is a subdomain network. The
		 * subdirectory branch is proven by a worker below, which is the only way
		 * to boot a different topology: is_subdomain_install() is decided by a
		 * constant.
		 */
		$reserved_here = HallSiteAddress::forSlug( 'feed' );
		$this->assert_true(
			$reserved_here instanceof HallSiteAddress,
			'a subdirectory-reserved name is accepted on a SUBDOMAIN network, where it collides with nothing'
		);

		$this->test_subdirectory_topology();

		/* An existing site at an address is reported by id, archived or not. */
		$occupied = HallSiteAddress::forSlug( 'hall-a' );
		$this->assert_true( $occupied instanceof HallSiteAddress, 'the seeded hall address resolves' );
		if ( $occupied instanceof HallSiteAddress ) {
			$this->assert_same( BC_FIXTURE_HALL_A, $occupied->existingSiteId(), 'an occupied address reports the existing site id' );
			$this->assert_true( $occupied->matchesSite( get_site( BC_FIXTURE_HALL_A ) ), 'the resolved address matches that site row' );
			$this->assert_true( ! $occupied->matchesSite( get_site( BC_FIXTURE_HALL_B ) ), 'it does not match a different site' );
		}
	}

	/**
	 * The subdirectory branch, proven in a worker process.
	 *
	 * `is_subdomain_install()` reads a constant, so a process that has already
	 * booted as a subdomain network cannot answer for a subdirectory one. The
	 * worker defines the topology before the fixture does and reports what the
	 * resolver answered.
	 */
	private function test_subdirectory_topology(): void {
		$path = __DIR__ . '/platform-subdirectory-worker.php';
		if ( ! is_file( $path ) ) {
			$this->assert_true( false, 'the subdirectory topology worker exists' );

			return;
		}

		$output = (string) shell_exec(
			sprintf(
				'BC_TEST_DB=%s %s %s 2>/dev/null',
				escapeshellarg( BC_Fixture::$database ),
				escapeshellarg( PHP_BINARY ),
				escapeshellarg( $path )
			)
		);

		$lines  = array_values( array_filter( array_map( 'trim', explode( "\n", $output ) ), static fn( string $l ): bool => '' !== $l && '{' === $l[0] ) );
		$report = $lines ? json_decode( (string) end( $lines ), true ) : null;

		if ( ! is_array( $report ) ) {
			$this->assert_true( false, 'the subdirectory worker reported a usable result' );

			return;
		}

		$this->assert_true( false === ( $report['subdomain_install'] ?? true ), 'the worker booted as a SUBDIRECTORY network' );
		$this->assert_same(
			[ 'domain' => (string) $report['network_domain'], 'path' => '/subdirhall/', 'subdomain' => false ],
			[
				'domain'    => (string) ( $report['valid']['domain'] ?? '' ),
				'path'      => (string) ( $report['valid']['path'] ?? '' ),
				'subdomain' => (bool) ( $report['valid']['subdomain'] ?? true ),
			],
			'a subdirectory network keeps the network domain and appends the slug to the network path'
		);

		$reserved = (array) ( $report['reserved'] ?? [] );
		$this->assert_true( [] !== $reserved, 'WordPress reports at least one subdirectory-reserved name' );
		foreach ( $reserved as $name => $verdict ) {
			$this->assert_same( 'address_invalid', $verdict, "the subdirectory-reserved name '{$name}' is address_invalid" );
		}
	}

	/* ------------------------------------------------------------------ *
	 * The new-site administrator identity
	 * ------------------------------------------------------------------ */

	private function test_hall_admin_configuration(): void {
		$resolved = HallSiteAdminUser::resolve( 0 );
		$this->assert_same(
			$this->super_admin_id,
			$resolved,
			'a configured, existing Network Super Admin resolves'
		);

		/*
		 * THE MACHINE ACCOUNT MUST NOT BECOME HALL ADMINISTRATOR. Checked as a
		 * parameter rather than as a constant, so it can be exercised in this
		 * process: a PHP constant is fixed for the life of the request, and the
		 * remaining constant states are proven by a separate worker below.
		 */
		$same = HallSiteAdminUser::resolve( $this->super_admin_id );
		$this->assert_true( is_wp_error( $same ), 'the authenticated machine account is refused as Hall administrator' );
		if ( is_wp_error( $same ) ) {
			$this->assert_same( 'platform_unavailable', $same->get_error_code(), 'that refusal is platform_unavailable' );
			$this->assert_true(
				false === strpos( $same->get_error_message(), (string) $this->super_admin_id ),
				'the refusal discloses no user id'
			);
		}

		$this->assert_same(
			'BINGOCOMMERCE_HALL_SITE_ADMIN_USER_ID',
			HallSiteAdminUser::CONSTANT,
			'the administrator identity comes from the decided constant name'
		);

		$this->run_worker(
			'platform-hall-admin-worker.php',
			[
				'undefined'         => 'platform_unavailable',
				'empty'             => 'platform_unavailable',
				'zero'              => 'platform_unavailable',
				'negative'          => 'platform_unavailable',
				'float'             => 'platform_unavailable',
				'bool'              => 'platform_unavailable',
				'array'             => 'platform_unavailable',
				'padded'            => 'platform_unavailable',
				'trailing'          => 'platform_unavailable',
				'exponent'          => 'platform_unavailable',
				'leading_zero'      => 'platform_unavailable',
				'nonexistent'       => 'platform_unavailable',
				'not_super_admin'   => 'platform_unavailable',
				'valid'             => 'ok',
			]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Creation
	 * ------------------------------------------------------------------ */

	private function test_missing_theme_prevents_creation(): void {
		global $wp_theme_directories;

		$before      = $this->site_count();
		$directories = $wp_theme_directories;
		$address     = HallSiteAddress::forSlug( self::SLUG_CREATE );
		$hide_theme  = static fn() => [ 'bingocommerce-bingothemev1' => __DIR__ . '/fixtures' ];
		$track_site  = function ( WP_Site $site ) use ( $address ): void {
			// Track an unexpected insertion too, so a failing regression cleans up.
			if ( $address instanceof HallSiteAddress && $address->matchesSite( $site ) && (int) $site->blog_id > 3 ) {
				$this->disposable_sites[ (int) $site->blog_id ] = true;
			}
		};

		// Redirect native theme discovery in this process; never rename theme files.
		$wp_theme_directories = [ WP_CONTENT_DIR . '/themes', __DIR__ . '/fixtures' ];
		add_filter( 'pre_site_transient_theme_roots', $hide_theme );
		add_action( 'wp_insert_site', $track_site );
		try {
			$this->assert_true( ! wp_get_theme( 'bingocommerce-bingothemev1' )->exists(), 'the missing-theme fixture hides BingoThemev1' );
			$result = HallSiteProvisioner::provision( null, $this->command( self::SLUG_CREATE ) );
		} finally {
			remove_action( 'wp_insert_site', $track_site );
			remove_filter( 'pre_site_transient_theme_roots', $hide_theme );
			$wp_theme_directories = $directories;
		}

		$this->assert_true( $result instanceof WP_Error, 'a missing storefront theme refuses fresh creation' );
		if ( $result instanceof WP_Error ) {
			$this->assert_same( 'platform_unavailable', $result->get_error_code(), 'a missing theme uses the existing platform_unavailable category' );
			$this->assert_same( 'The Hall site could not be initialized.', $result->get_error_message(), 'theme validation returns the fixed response without theme diagnostics' );
		}
		$this->assert_same( $before, $this->site_count(), 'a missing theme creates no site' );
		$this->assert_same( 0, $this->created_site( self::SLUG_CREATE ), 'the refused address remains unoccupied' );
		$this->assert_true( wp_get_theme( 'bingocommerce-bingothemev1' )->exists(), 'native theme discovery is restored after the missing-theme test' );
	}

	private function test_create_archived_and_marked(): void {
		$main_site  = get_main_site_id();
		$main_theme = [
			'template'   => get_blog_option( $main_site, 'template' ),
			'stylesheet' => get_blog_option( $main_site, 'stylesheet' ),
		];
		$command  = $this->command( self::SLUG_CREATE );
		$before   = $this->site_count();
		$result   = HallSiteProvisioner::provision( null, $command );

		$this->assert_true( is_array( $result ), 'a first create returns a result array' );
		if ( ! is_array( $result ) ) {
			if ( is_wp_error( $result ) ) {
				$this->assert_true( false, 'create failed: ' . $result->get_error_code() );
			}

			return;
		}

		$blog_id = (int) $result['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$this->assert_same( $before + 1, $this->site_count(), 'exactly one site was created' );
		foreach ( $main_theme as $option => $value ) {
			$this->assert_same( 'bingocommerce-bingothemev1', get_blog_option( $blog_id, $option ), "the new Hall's {$option} is BingoThemev1" );
			$this->assert_same( $value, get_blog_option( $main_site, $option ), "the main site's {$option} is unchanged" );
		}
		$this->assert_same(
			[ 'blog_id', 'created', 'managed_marker_present', 'site_url' ],
			$this->sorted_keys( $result ),
			'the success result carries exactly the four decided keys'
		);
		$this->assert_true( is_int( $result['blog_id'] ) && $result['blog_id'] > 0, 'blog_id is a positive integer' );
		$this->assert_true( is_string( $result['site_url'] ) && '' !== $result['site_url'], 'site_url is a non-empty string' );
		$this->assert_true( true === $result['created'], 'created is exactly true on a first create' );
		$this->assert_true( true === $result['managed_marker_present'], 'the marker read-back reports present' );

		$site = get_site( $blog_id );
		$this->assert_true( $site instanceof WP_Site, 'the created site can be loaded' );
		$this->assert_same( '1', (string) $site->archived, 'the site is ARCHIVED, from insertion' );
		$this->assert_same( '0', (string) $site->deleted, 'the site is not deleted' );
		$this->assert_same( '0', (string) $site->spam, 'the site is not spam' );
		$this->assert_same( get_current_network_id(), (int) $site->site_id, 'the site belongs to the current network' );
		$this->assert_true( ! is_main_site( $blog_id ), 'the site is not the main network site' );
		$this->assert_same(
			'0',
			(string) $site->public,
			'public is left at the supported wpmu_create_blog() default and is not used as an access barrier'
		);

		$address = HallSiteAddress::forSlug( self::SLUG_CREATE );
		$this->assert_true( $address instanceof HallSiteAddress && $address->matchesSite( $site ), 'the site sits at the reserved address' );

		$this->assert_same(
			$command['site_ownership_id'],
			$this->marker( $blog_id ),
			'bc_commerce_managed equals the permanent site_ownership_id exactly'
		);
		$this->assert_true(
			$command['operation_id'] !== $this->marker( $blog_id ),
			'the marker is NOT the rotating Portal operation_id'
		);

		/*
		 * The configured Super Admin — not the machine account — administers it.
		 * Read from the stored capabilities meta rather than through
		 * is_user_member_of_blog(), whose `blogs` user cache was populated for
		 * this account before the site existed.
		 */
		clean_user_cache( $this->super_admin_id );
		$capabilities = get_user_meta( $this->super_admin_id, $GLOBALS['wpdb']->get_blog_prefix( $blog_id ) . 'capabilities', true );
		$this->assert_true(
			is_array( $capabilities ) && ! empty( $capabilities['administrator'] ),
			'the configured recovery Super Admin is the new site administrator'
		);
		/*
		 * Confirmed through WordPress too, with $all = true. get_blogs_of_user()
		 * EXCLUDES archived sites by default and is_user_member_of_blog() uses
		 * that default, so the ordinary call correctly answers "no" for a site
		 * that is deliberately archived — which is a fact about the site's
		 * lifecycle, not about this administrator's membership.
		 */
		$this->assert_true(
			array_key_exists( $blog_id, get_blogs_of_user( $this->super_admin_id, true ) ),
			'that membership is visible through WordPress once archived sites are included'
		);
		$this->assert_true(
			! is_user_member_of_blog( $this->super_admin_id, $blog_id ),
			'and is correctly hidden from the default listing while the site is archived'
		);

		$this->assert_true( ! wp_installing(), 'wp_installing() is restored after creation' );

		/* The site is genuinely ready, by BingoCommerce's own read-only evaluator. */
		$readiness = \BingoCommerce\PortalIntegration\SetupReadiness::evaluate( $blog_id );
		$this->assert_true( is_array( $readiness ), 'setup readiness evaluates' );
		if ( is_array( $readiness ) ) {
			$this->assert_true(
				(bool) $readiness['ready'],
				'the created store is READY: ' . implode( ', ', (array) $readiness['missing'] )
			);
			$this->assert_true(
				(bool) ( $readiness['site_flags']['archived'] ?? false ),
				'readiness reports the site as archived without treating that as a missing prerequisite'
			);
		}

		$this->assert_same(
			$result['site_url'],
			get_site_url( $blog_id ),
			'the advisory site_url agrees with the site\'s own authoritative siteurl'
		);
	}

	/**
	 * ADR-042 narrows ADR-027 / BR-047 for this MVP: a ready but EMPTY store.
	 */
	private function test_no_starter_products(): void {
		$blog_id = $this->created_site( self::SLUG_CREATE );
		if ( 0 === $blog_id ) {
			return;
		}

		global $wpdb;
		switch_to_blog( $blog_id );
		try {
			$products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'" );
			$variations = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation'" );
			$play     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \BingoCommerce\Catalog\Migrations::play_products_table() . '`' );
			$packages = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \BingoCommerce\Catalog\Migrations::packages_table() . '`' );
			$types    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . \BingoCommerce\Catalog\Migrations::game_types_table() . '`' );
		} finally {
			restore_current_blog();
		}

		$this->assert_same( 0, $products, 'no starter WooCommerce Product was copied' );
		$this->assert_same( 0, $variations, 'no starter product variation was copied' );
		$this->assert_same( 0, $play, 'no starter Bingo Play Product was copied' );
		$this->assert_same( 0, $packages, 'no starter Bingo Package was copied' );
		$this->assert_same( 0, $types, 'no Game Type was copied — Game Types synchronize from the Portal' );
	}

	private function test_initialization_is_idempotent(): void {
		$blog_id = $this->created_site( self::SLUG_CREATE );
		if ( 0 === $blog_id ) {
			return;
		}

		$before_sites  = $this->site_count();
		$before_tables = $this->site_table_names( $blog_id );
		$before_marker = $this->marker( $blog_id );

		$command            = $this->command( self::SLUG_CREATE, $this->ownership( self::SLUG_CREATE ) );
		$command['blog_id'] = $blog_id;
		$command['hall_name'] = null;

		$result = HallSiteProvisioner::provision( null, $command );

		$this->assert_true( is_array( $result ), 'a bound resume succeeds' );
		if ( is_array( $result ) ) {
			$this->assert_same( $blog_id, (int) $result['blog_id'], 'the resume returns the same site' );
			$this->assert_true( false === $result['created'], 'created is exactly false on a resume' );
			$this->assert_true( true === $result['managed_marker_present'], 'the marker is still proven present' );
		}
		$this->assert_same( $before_sites, $this->site_count(), 'a resume creates NO second site' );
		$this->assert_same( $before_tables, $this->site_table_names( $blog_id ), 'a resume changes no table in the site' );
		$this->assert_same( $before_marker, $this->marker( $blog_id ), 'a resume never rewrites the ownership marker' );
		$this->assert_same( '1', (string) get_site( $blog_id )->archived, 'a resume does not clear archived — that is the orchestrator\'s step' );
	}

	/* ------------------------------------------------------------------ *
	 * Recovery and ownership refusal
	 * ------------------------------------------------------------------ */

	private function test_marker_matched_recovery(): void {
		$ownership = $this->ownership( self::SLUG_RECOVER );
		$command   = $this->command( self::SLUG_RECOVER, $ownership );

		$first = HallSiteProvisioner::provision( null, $command );
		$this->assert_true( is_array( $first ), 'the partial create succeeds' );
		if ( ! is_array( $first ) ) {
			return;
		}
		$blog_id = (int) $first['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		/*
		 * THE RETRY ARRIVES WITH blog_id STILL NULL — exactly the shape of an
		 * attempt whose answer was lost after the site was created. It must find
		 * its OWN site by MARKER, not be told the address is occupied.
		 */
		$before = $this->site_count();
		$retry  = HallSiteProvisioner::provision( null, $command );

		$this->assert_true( is_array( $retry ), 'the marker-matched partial create is recovered' );
		if ( is_array( $retry ) ) {
			$this->assert_same( $blog_id, (int) $retry['blog_id'], 'recovery returns the SAME site' );
			$this->assert_true( false === $retry['created'], 'recovery reports created=false' );
			$this->assert_true( true === $retry['managed_marker_present'], 'recovery proves the marker' );
		}
		$this->assert_same( $before, $this->site_count(), 'recovery creates no second site' );
		$this->assert_same( $ownership, $this->marker( $blog_id ), 'recovery leaves the marker untouched' );
		$this->assert_same( '1', (string) get_site( $blog_id )->archived, 'the recovered site stays archived' );
		$this->assert_same( 'bingocommerce-bingothemev1', get_blog_option( $blog_id, 'template' ), 'same-site recovery retains the assigned template' );
		$this->assert_same( 'bingocommerce-bingothemev1', get_blog_option( $blog_id, 'stylesheet' ), 'same-site recovery retains the assigned stylesheet' );
	}

	private function test_unproven_marker_states(): void {
		/* A site at the address that this operation does not own. */
		$foreign_owner = strtolower( wp_generate_uuid4() );
		$seeded        = HallSiteProvisioner::provision( null, $this->command( self::SLUG_FOREIGN, $foreign_owner ) );
		$this->assert_true( is_array( $seeded ), 'a site is seeded at the foreign address' );
		if ( ! is_array( $seeded ) ) {
			return;
		}
		$foreign_blog = (int) $seeded['blog_id'];
		$this->disposable_sites[ $foreign_blog ] = true;

		$mine   = $this->command( self::SLUG_FOREIGN, strtolower( wp_generate_uuid4() ) );
		$before = $this->site_count();
		$result = HallSiteProvisioner::provision( null, $mine );

		$this->assert_true( is_wp_error( $result ), 'a DIFFERENT marker at the address is refused' );
		if ( is_wp_error( $result ) ) {
			$this->assert_same( 'site_ownership_unproven', $result->get_error_code(), 'a different marker is site_ownership_unproven' );
		}
		$this->assert_same( $before, $this->site_count(), 'an unproven address creates no site' );
		$this->assert_same( $foreign_owner, $this->marker( $foreign_blog ), 'the foreign marker is never rewritten' );
		$this->assert_true( get_site( $foreign_blog ) instanceof WP_Site, 'the foreign site is never deleted' );

		/* An ABSENT marker is not an invitation to adopt the site. */
		$unmarked = $this->seed_unmarked_site( self::SLUG_UNMARKED );
		if ( $unmarked > 0 ) {
			$before = $this->site_count();
			$result = HallSiteProvisioner::provision( null, $this->command( self::SLUG_UNMARKED, strtolower( wp_generate_uuid4() ) ) );
			$this->assert_true( is_wp_error( $result ), 'an ABSENT marker at the address is refused' );
			if ( is_wp_error( $result ) ) {
				$this->assert_same( 'site_ownership_unproven', $result->get_error_code(), 'an absent marker is site_ownership_unproven' );
			}
			$this->assert_same( $before, $this->site_count(), 'an absent marker creates no site' );
			$this->assert_same( null, $this->marker( $unmarked ), 'no marker is written onto an unmarked site' );

			/* An INVALID stored marker is not an absence either. */
			update_blog_option( $unmarked, HallSiteProvisioner::MARKER_OPTION, 'not-a-uuid' );
			$result = HallSiteProvisioner::provision( null, $this->command( self::SLUG_UNMARKED, strtolower( wp_generate_uuid4() ) ) );
			$this->assert_true( is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(), 'an INVALID marker is site_ownership_unproven' );
			$this->assert_same( 'not-a-uuid', get_blog_option( $unmarked, HallSiteProvisioner::MARKER_OPTION ), 'an invalid marker is never repaired' );

			/*
			 * AN UNREADABLE marker is not "no". The options read is obstructed
			 * through WordPress's own `query` filter, so a storage failure is
			 * simulated without touching a row.
			 */
			$obstruct = $this->marker_read_obstruction( $unmarked );
			add_filter( 'query', $obstruct, 1 );
			try {
				$result = HallSiteProvisioner::provision( null, $this->command( self::SLUG_UNMARKED, strtolower( wp_generate_uuid4() ) ) );
			} finally {
				remove_filter( 'query', $obstruct, 1 );
			}
			$this->assert_true(
				is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
				'an UNREADABLE marker is site_ownership_unproven, never a verified absence'
			);
		}
	}

	private function test_bound_resume_verification(): void {
		$ownership = $this->ownership( self::SLUG_RESUME );
		$created   = HallSiteProvisioner::provision( null, $this->command( self::SLUG_RESUME, $ownership ) );
		$this->assert_true( is_array( $created ), 'the bound-resume fixture site is created' );
		if ( ! is_array( $created ) ) {
			return;
		}
		$blog_id = (int) $created['blog_id'];
		$this->disposable_sites[ $blog_id ] = true;

		$base            = $this->command( self::SLUG_RESUME, $ownership );
		$base['blog_id'] = $blog_id;
		$base['hall_name'] = null;

		$ok = HallSiteProvisioner::provision( null, $base );
		$this->assert_true( is_array( $ok ) && (int) $ok['blog_id'] === $blog_id, 'a correct bound resume verifies' );

		$wrong_marker                      = $base;
		$wrong_marker['site_ownership_id'] = strtolower( wp_generate_uuid4() );
		$result                            = HallSiteProvisioner::provision( null, $wrong_marker );
		$this->assert_true(
			is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
			'a bound resume whose marker disagrees is site_ownership_unproven'
		);
		$this->assert_same( $ownership, $this->marker( $blog_id ), 'that refusal never derives the marker from operation_id' );

		$wrong_slug              = $base;
		$wrong_slug['site_slug'] = self::SLUG_CREATE;
		$result                  = HallSiteProvisioner::provision( null, $wrong_slug );
		$this->assert_true(
			is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
			'a bound resume whose durable address no longer describes the site is refused'
		);

		$missing            = $base;
		$missing['blog_id'] = 987654;
		$before             = $this->site_count();
		$result             = HallSiteProvisioner::provision( null, $missing );
		$this->assert_true(
			is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
			'a bound resume naming a nonexistent site is refused'
		);
		$this->assert_same( $before, $this->site_count(), 'a refused resume creates no site' );

		$main            = $base;
		$main['blog_id'] = 1;
		$result          = HallSiteProvisioner::provision( null, $main );
		$this->assert_true(
			is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
			'a bound resume naming the MAIN network site is refused'
		);
		$this->assert_same( null, $this->marker( 1 ), 'the main network site is never marked' );
	}

	/* ------------------------------------------------------------------ *
	 * Result shape and containment
	 * ------------------------------------------------------------------ */

	private function test_result_shape_validation(): void {
		$this->assert_same(
			[ 'internal_error', 'platform_unavailable', 'site_create_failed', 'site_ownership_unproven' ],
			$this->sorted( HallSiteProvisioner::ERROR_CODES ),
			'the platform error vocabulary is exactly the four decided codes'
		);
		$this->assert_same(
			[ 'blog_id', 'created', 'managed_marker_present', 'site_url' ],
			$this->sorted( HallSiteProvisioner::RESULT_KEYS ),
			'the success result vocabulary is exactly the four decided keys'
		);
	}

	private function test_throwable_containment(): void {
		$command = $this->command( self::SLUG_THROW );

		/*
		 * AN UNREADABLE ADDRESS PROBE. The probe is a prepared read against
		 * `$wpdb->blogs`, so the failure is injected into that statement through
		 * WordPress's own `query` filter. The resolver must report it rather
		 * than answer "no site here", because answering no would mint a
		 * permanent ownership identity for an address nobody could check.
		 */
		global $wpdb;
		$blogs   = (string) $wpdb->blogs;
		$break   = static function ( $query ) use ( $blogs ) {
			$text = (string) $query;

			return ( false !== strpos( $text, $blogs ) && 1 === preg_match( '/^\s*SELECT\s+blog_id\b/i', $text ) )
				? 'SELECT blog_id FROM zz_bc_absent_blogs_for_test LIMIT 2'
				: $query;
		};

		$before = $this->site_count();
		add_filter( 'query', $break, 1 );
		try {
			$result  = HallSiteProvisioner::provision( null, $command );
			$address = HallSiteAddress::forSlug( self::SLUG_THROW );
			$probe   = $address instanceof HallSiteAddress ? $address->existingSiteId() : $address;
		} finally {
			remove_filter( 'query', $break, 1 );
		}

		$this->assert_true( is_wp_error( $result ), 'an unreadable address probe is contained' );
		if ( is_wp_error( $result ) ) {
			$this->assert_same( 'platform_unavailable', $result->get_error_code(), 'an unreadable address probe is platform_unavailable' );
			$this->assert_true(
				false === strpos( $result->get_error_message(), 'SELECT' )
					&& false === strpos( $result->get_error_message(), 'zz_bc_absent' ),
				'the failure discloses no SQL or table name'
			);
		}
		$this->assert_true(
			$probe instanceof WP_Error && 'platform_unavailable' === $probe->get_error_code(),
			'the probe itself reports the failure rather than a VERIFIED ABSENCE'
		);
		$this->assert_same( $before, $this->site_count(), 'an unreadable address probe creates no site' );

		/* A THROWING probe is contained the same way. */
		$thrower = static function ( $query ) use ( $blogs ) {
			if ( false !== strpos( (string) $query, $blogs ) && 1 === preg_match( '/^\s*SELECT\s+blog_id\b/i', (string) $query ) ) {
				throw new RuntimeException( 'the blogs read exploded with SELECT * FROM secret' );
			}

			return $query;
		};
		add_filter( 'query', $thrower, 1 );
		try {
			$address = HallSiteAddress::forSlug( self::SLUG_THROW );
			$thrown  = $address instanceof HallSiteAddress ? $address->existingSiteId() : $address;
		} finally {
			remove_filter( 'query', $thrower, 1 );
		}
		$this->assert_true(
			$thrown instanceof WP_Error && 'platform_unavailable' === $thrown->get_error_code(),
			'a throwing address probe is contained as platform_unavailable'
		);
		if ( $thrown instanceof WP_Error ) {
			$this->assert_true(
				false === strpos( $thrown->get_error_message(), 'exploded' ),
				'the contained exception text never reaches the caller'
			);
		}

		/*
		 * A throw OUTSIDE the inner containment, from the authoritative URL read
		 * that happens after initialization. The consumer's outermost catch turns
		 * it into internal_error rather than letting it cross the plugin boundary
		 * mid-apply_filters().
		 */
		$url_thrower = static function () {
			throw new RuntimeException( 'site_url exploded' );
		};
		add_filter( 'site_url', $url_thrower, 10 );
		try {
			$result = HallSiteProvisioner::provision( null, $command );
		} finally {
			remove_filter( 'site_url', $url_thrower, 10 );
		}

		$this->assert_true( is_wp_error( $result ), 'a throw after initialization is contained' );
		if ( is_wp_error( $result ) ) {
			$this->assert_same( 'internal_error', $result->get_error_code(), 'the outer containment answers internal_error' );
			$this->assert_true(
				false === strpos( $result->get_error_message(), 'exploded' ),
				'the outer containment discloses no exception text'
			);
		}

		/*
		 * That throw happened AFTER creation, so a site now exists at the
		 * address, archived and marked. It is recorded for teardown and — this is
		 * the point — it is NOT deleted by the platform.
		 */
		$address = HallSiteAddress::forSlug( self::SLUG_THROW );
		if ( $address instanceof HallSiteAddress ) {
			$stranded = $address->existingSiteId();
			if ( is_int( $stranded ) ) {
				$this->disposable_sites[ $stranded ] = true;
				$this->assert_same( '1', (string) get_site( $stranded )->archived, 'a site stranded by a contained throw stays archived' );
				$this->assert_same( $command['site_ownership_id'], $this->marker( $stranded ), 'the stranded site keeps its ownership marker, so a retry can recover it' );

				$recovered = HallSiteProvisioner::provision( null, $command );
				$this->assert_true(
					is_array( $recovered ) && (int) $recovered['blog_id'] === $stranded && false === $recovered['created'],
					'the next attempt recovers the stranded site rather than creating a second one'
				);
			}
		}
	}

	/**
	 * The address probe keeps four answers apart.
	 *
	 * Collapsing any two of them would let a failure read as a free address,
	 * which is the one mistake that mints a permanent ownership identity for
	 * somebody else's site.
	 */
	private function test_address_probe_outcomes(): void {
		global $wpdb;

		$blogs = (string) $wpdb->blogs;

		/* 1. VERIFIED ABSENCE. */
		$free = HallSiteAddress::forSlug( 'pltabsent' );
		$this->assert_true( $free instanceof HallSiteAddress, 'the unused address resolves' );
		$this->assert_same( null, $free instanceof HallSiteAddress ? $free->existingSiteId() : false, 'an unused address is a verified absence (null)' );

		/* 2. EXACTLY ONE VALID SITE. */
		$occupied = HallSiteAddress::forSlug( 'hall-a' );
		$this->assert_same(
			BC_FIXTURE_HALL_A,
			$occupied instanceof HallSiteAddress ? $occupied->existingSiteId() : 0,
			'an occupied address returns exactly the existing site id'
		);

		/* 3. CORRUPT: a row that answers with something that is not a site id. */
		foreach (
			[
				'a non-numeric blog id' => "SELECT 'abc' AS blog_id",
				'a zero blog id'        => 'SELECT 0 AS blog_id',
				'a padded blog id'      => "SELECT '007' AS blog_id",
				'two rows at one address' => 'SELECT 2 AS blog_id UNION ALL SELECT 3 AS blog_id',
				'a row with no blog_id column' => "SELECT 'x' AS other",
			] as $label => $replacement
		) {
			$corrupt = static function ( $query ) use ( $blogs, $replacement ) {
				$text = (string) $query;

				return ( false !== strpos( $text, $blogs ) && 1 === preg_match( '/^\s*SELECT\s+blog_id\b/i', $text ) )
					? $replacement
					: $query;
			};

			add_filter( 'query', $corrupt, 1 );
			try {
				$probe = HallSiteAddress::forSlug( 'pltabsent' )->existingSiteId();
			} finally {
				remove_filter( 'query', $corrupt, 1 );
			}

			$this->assert_true(
				$probe instanceof WP_Error && 'platform_unavailable' === $probe->get_error_code(),
				"a corrupt address probe result ({$label}) is platform_unavailable, never a site id and never an absence"
			);
		}

		/*
		 * 4. The probe consults the DATABASE, not a filter and not a cache.
		 *
		 * Both closures are held in variables: remove_filter() matches by
		 * callback IDENTITY, so removing a freshly written `static fn() => ...`
		 * removes nothing and would leave the hijack installed for the rest of
		 * the run.
		 */
		$hijack_query  = static fn() => [ 4242 ];
		$hijack_exists = static fn() => 4242;
		add_filter( 'sites_pre_query', $hijack_query, 10 );
		add_filter( 'domain_exists', $hijack_exists, 10 );
		try {
			$probe = HallSiteAddress::forSlug( 'pltabsent' )->existingSiteId();
		} finally {
			remove_filter( 'sites_pre_query', $hijack_query, 10 );
			remove_filter( 'domain_exists', $hijack_exists, 10 );
		}

		$this->assert_true(
			false === has_filter( 'sites_pre_query', $hijack_query )
				&& false === has_filter( 'domain_exists', $hijack_exists ),
			'both hijack filters were really removed afterwards'
		);
		$this->assert_same(
			null,
			$probe,
			'neither sites_pre_query nor domain_exists can change the address answer: it comes from the blogs table'
		);
	}

	/**
	 * The consumer proves every target fact before it initializes anything.
	 */
	private function test_target_validation(): void {
		$ownership = $this->ownership( self::SLUG_RESUME );
		$blog_id   = $this->created_site( self::SLUG_RESUME );
		if ( 0 === $blog_id ) {
			return;
		}

		$bound            = $this->command( self::SLUG_RESUME, $ownership );
		$bound['blog_id'] = $blog_id;

		foreach (
			[
				'a deleted site' => [ 'deleted' => 1 ],
				'a spam site'    => [ 'spam' => 1 ],
			] as $label => $flags
		) {
			$this->set_site_flags( $blog_id, $flags );
			$result = HallSiteProvisioner::provision( null, $bound );
			$this->assert_true(
				is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
				"{$label} is refused as site_ownership_unproven, without initializing it"
			);
			$this->set_site_flags( $blog_id, array_map( static fn() => 0, $flags ) );
		}

		/* A FOREIGN NETWORK is refused. */
		$this->set_site_flags( $blog_id, [ 'site_id' => 999 ] );
		$result = HallSiteProvisioner::provision( null, $bound );
		$this->assert_true(
			is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
			'a site on another network is refused as site_ownership_unproven'
		);
		$this->set_site_flags( $blog_id, [ 'site_id' => (int) get_current_network_id() ] );

		$this->assert_true(
			is_array( HallSiteProvisioner::provision( null, $bound ) ),
			'the same bound resume succeeds once every target fact holds again'
		);

		/*
		 * AN UNBOUND MARKER-MATCHED PARTIAL SITE THAT IS NOT ARCHIVED. Every
		 * site this consumer creates is archived from insertion, so an
		 * unarchived one at the reserved address has had its lifecycle changed
		 * by something else. It is refused without being initialized,
		 * re-archived, repaired or deleted.
		 */
		$this->set_site_flags( $blog_id, [ 'archived' => 0 ] );
		$unbound = $this->command( self::SLUG_RESUME, $ownership );
		$before  = $this->site_count();
		$result  = HallSiteProvisioner::provision( null, $unbound );

		$this->assert_true(
			is_wp_error( $result ) && 'site_ownership_unproven' === $result->get_error_code(),
			'an UNARCHIVED marker-matched unbound partial site is site_ownership_unproven'
		);
		$this->assert_same( '0', (string) get_site( $blog_id )->archived, 'it is NOT re-archived by the refusal' );
		$this->assert_same( $ownership, $this->marker( $blog_id ), 'its marker is not rewritten' );
		$this->assert_true( get_site( $blog_id ) instanceof WP_Site, 'it is not deleted' );
		$this->assert_same( $before, $this->site_count(), 'and no second site is created' );

		/* A BOUND resume accepts either archived state. */
		$this->assert_true(
			is_array( HallSiteProvisioner::provision( null, $bound ) ),
			'a BOUND resume accepts an unarchived site, which is what a re-enable looks like'
		);

		$this->set_site_flags( $blog_id, [ 'archived' => 1 ] );
	}

	/** Change lifecycle columns on a disposable site, guarded. */
	private function set_site_flags( int $blog_id, array $flags ): void {
		global $wpdb;

		if ( ! isset( $this->disposable_sites[ $blog_id ] ) || $blog_id < 4 ) {
			$this->assert_true( false, 'a site flag change named a site this run does not own' );

			return;
		}

		$formats = array_fill( 0, count( $flags ), '%d' );
		$wpdb->update( $wpdb->blogs, $flags, [ 'blog_id' => $blog_id ], $formats, [ '%d' ] );
		clean_blog_cache( $blog_id );
	}

	/**
	 * THE BLOG CONTEXT IS RESTORED, OR NO SUCCESS IS REPORTED.
	 *
	 * Three facts are checked on every path — switched-stack depth, current blog
	 * id and the database prefix — because the prefix is what every later
	 * statement in the request is written against, and the caller goes on to
	 * write Commerce state and clear `archived`.
	 */
	private function test_blog_context_is_always_restored(): void {
		global $wpdb;

		$ownership = $this->ownership( self::SLUG_RESUME );
		$blog_id   = $this->created_site( self::SLUG_RESUME );
		if ( 0 === $blog_id ) {
			return;
		}

		$bound            = $this->command( self::SLUG_RESUME, $ownership );
		$bound['blog_id'] = $blog_id;

		$entry_blog   = get_current_blog_id();
		$entry_depth  = $this->switch_depth();
		$entry_prefix = (string) $wpdb->prefix;

		/* --- 1. A THROW ON ENTRY, from a `switch_blog` callback. --- */
		$on_entry = static function ( $new_blog_id ) use ( $blog_id ) {
			if ( (int) $new_blog_id === $blog_id ) {
				throw new RuntimeException( 'switch_blog exploded on entry with SELECT secrets' );
			}
		};

		$escaped = null;
		add_action( 'switch_blog', $on_entry, 1, 2 );
		try {
			$result = HallSiteProvisioner::provision( null, $bound );
		} catch ( Throwable $throwable ) {
			$escaped = $throwable;
			$result  = null;
		} finally {
			remove_action( 'switch_blog', $on_entry, 1 );
		}

		$this->assert_same( null, $escaped, 'a throw while ENTERING the site context does not cross the filter boundary' );
		$this->assert_true( is_wp_error( $result ), 'it is contained as a WP_Error' );
		if ( is_wp_error( $result ) ) {
			$this->assert_true(
				in_array( $result->get_error_code(), HallSiteProvisioner::ERROR_CODES, true ),
				'with an allowlisted platform code'
			);
			$this->assert_true(
				false === strpos( $result->get_error_message(), 'exploded' )
					&& false === strpos( $result->get_error_message(), 'SELECT' ),
				'and no exception text or SQL'
			);
		}
		$this->assert_same( $entry_depth, $this->switch_depth(), 'the switched-blog stack is back to its entry depth' );
		$this->assert_same( $entry_blog, get_current_blog_id(), 'the original blog context is restored' );
		$this->assert_same( $entry_prefix, (string) $wpdb->prefix, 'and the database prefix points at the original site' );

		/* --- 2. A THROW DURING RESTORATION never reports success. --- */
		$on_restore = static function ( $new_blog_id, $prev_blog_id, $context = '' ) use ( $blog_id ) {
			if ( 'restore' === $context && (int) $prev_blog_id === $blog_id ) {
				throw new RuntimeException( 'switch_blog exploded on restore' );
			}
		};

		/*
		 * THE CONTEXT IS CAPTURED BEFORE THE HARNESS TOUCHES IT.
		 *
		 * The cleanup below is the harness putting itself back on its feet, and
		 * it necessarily restores the very facts this case exists to measure. A
		 * capture taken after it would assert that the HARNESS can unwind a
		 * stack, which is true whatever the production code did. So depth, blog
		 * id and prefix are read as the first three statements of the `finally`,
		 * before the cleanup — and every assertion uses those values.
		 */
		$escaped = null;
		add_action( 'switch_blog', $on_restore, 1, 3 );
		try {
			$result = HallSiteProvisioner::provision( null, $bound );
		} catch ( Throwable $throwable ) {
			$escaped = $throwable;
			$result  = null;
		} finally {
			$after_depth  = $this->switch_depth();
			$after_blog   = get_current_blog_id();
			$after_prefix = (string) $wpdb->prefix;

			remove_action( 'switch_blog', $on_restore, 1 );
			// Whatever the throw left behind, put the harness back on its feet
			// before asserting anything else.
			while ( $this->switch_depth() > $entry_depth ) {
				if ( ! restore_current_blog() ) {
					break;
				}
			}
		}

		$this->assert_same( null, $escaped, 'a throw while RESTORING does not cross the filter boundary either' );
		$this->assert_true( is_wp_error( $result ), 'and unproven restoration is never reported as success' );
		if ( is_wp_error( $result ) ) {
			$this->assert_same( 'platform_unavailable', $result->get_error_code(), 'it is platform_unavailable' );
		}
		$this->assert_same( $entry_depth, $after_depth, 'the PRODUCTION unwind still reached the entry depth, before any harness cleanup' );
		$this->assert_same( $entry_blog, $after_blog, 'the production code left the harness on the original site' );
		$this->assert_same( $entry_prefix, $after_prefix, 'with the original prefix, measured before cleanup' );
		$this->assert_same( $entry_blog, get_current_blog_id(), 'the harness is back on the original site' );
		$this->assert_same( $entry_prefix, (string) $wpdb->prefix, 'with the original prefix' );

		/* --- 2b. A THROW IN THE MIDDLE OF A NESTED UNWIND. --- */
		$this->nested_restoration_case( $bound, $blog_id, $entry_depth, $entry_blog, $entry_prefix );

		/* --- 3. AN OUTER SWITCHED CONTEXT IS PRESERVED EXACTLY. --- */
		switch_to_blog( BC_FIXTURE_HALL_A );
		$outer_depth  = $this->switch_depth();
		$outer_blog   = get_current_blog_id();
		$outer_prefix = (string) $wpdb->prefix;

		$result = HallSiteProvisioner::provision( null, $bound );

		$this->assert_true( is_array( $result ), 'a successful resume from inside an outer switched context succeeds' );
		$this->assert_same( $outer_depth, $this->switch_depth(), 'the OUTER switch is still on the stack' );
		$this->assert_same( $outer_blog, get_current_blog_id(), 'the caller is still on the site it switched to' );
		$this->assert_same( $outer_prefix, (string) $wpdb->prefix, 'and the prefix is the outer one' );

		restore_current_blog();
		$this->assert_same( $entry_blog, get_current_blog_id(), 'and the harness unwinds cleanly afterwards' );
	}

	/**
	 * A THROW IN THE MIDDLE OF A **NESTED** UNWIND, contained per attempt.
	 *
	 * THIS IS THE CASE A SINGLE `try` AROUND THE WHOLE UNWIND LOOP GOT WRONG,
	 * and it needs more than one switch to show it. A component running inside
	 * the switched context leaves two further switches open — which is exactly
	 * what a `switch_blog` listener that switches and does not restore does —
	 * so three levels have to be unwound. A `switch_blog` callback then throws
	 * on the SECOND of the three restores.
	 *
	 * With the whole loop inside one `try`, that throw exited the loop: the
	 * third level was never popped, and the request continued with `$wpdb`
	 * pointing at the Hall while the method reported only "restoration
	 * unproven". Contained per attempt, the unwind sees valid depth progress,
	 * carries on, and finishes on the entry facts.
	 *
	 * BOTH HALVES ARE ASSERTED, because either alone is satisfiable by the wrong
	 * implementation: the unwind COMPLETES (depth, blog id and prefix, captured
	 * before any harness cleanup), and the operation STILL FAILS, because a
	 * `switch_blog` listener that threw mid-restore is not evidence that the
	 * object cache, WooCommerce and Action Scheduler re-pointed themselves.
	 *
	 * DETERMINISTIC, WITH NO TIMING. Every switch and every throw is driven by
	 * WordPress's own `switch_blog` action and keyed on the blog ids involved.
	 *
	 * @param array<string,mixed> $bound
	 */
	private function nested_restoration_case( array $bound, int $blog_id, int $entry_depth, int $entry_blog, string $entry_prefix ): void {
		global $wpdb;

		/*
		 * ONE-SHOT, so the two switches it makes cannot re-enter it: the second
		 * of them enters `$blog_id` again, which is the condition it fires on.
		 */
		/*
		 * The intermediate site is the network MAIN site, not a seeded hall:
		 * this fixture inserts hall rows without per-site tables, and switching
		 * to one would fill the run with unrelated "table doesn't exist" noise
		 * that has nothing to do with what is being measured.
		 */
		$intermediate = (int) get_main_site_id();

		$leaked = false;
		$leak   = static function ( $new_blog_id, $prev_blog_id, $context = '' ) use ( $blog_id, $intermediate, &$leaked ): void {
			if ( 'switch' !== $context || (int) $new_blog_id !== $blog_id || $leaked ) {
				return;
			}
			$leaked = true;

			// Two MORE switches, neither restored. The production unwind now has
			// three levels of its own to undo.
			switch_to_blog( $intermediate );
			switch_to_blog( $blog_id );
		};

		/*
		 * Throws on the restore whose PREVIOUS blog is the intermediate one — the
		 * middle pop of the three. The `$context` test matters: the entry
		 * sequence above also produces a `switch` whose previous blog is the
		 * intermediate site, and throwing there would test entry containment
		 * again rather than the unwind.
		 */
		$mid = static function ( $new_blog_id, $prev_blog_id, $context = '' ) use ( $intermediate ): void {
			if ( 'restore' === $context && (int) $prev_blog_id === $intermediate ) {
				throw new RuntimeException( 'switch_blog exploded midway through the unwind' );
			}
		};

		$escaped = null;
		$result  = null;

		add_action( 'switch_blog', $leak, 1, 3 );
		add_action( 'switch_blog', $mid, 2, 3 );
		try {
			$result = HallSiteProvisioner::provision( null, $bound );
		} catch ( Throwable $throwable ) {
			$escaped = $throwable;
		} finally {
			// CAPTURED FIRST. See the note on the single-level case above.
			$after_depth  = $this->switch_depth();
			$after_blog   = get_current_blog_id();
			$after_prefix = (string) $wpdb->prefix;

			remove_action( 'switch_blog', $leak, 1 );
			remove_action( 'switch_blog', $mid, 2 );
			while ( $this->switch_depth() > $entry_depth ) {
				if ( ! restore_current_blog() ) {
					break;
				}
			}
		}

		$this->assert_true( $leaked, 'the nested case really did leave two EXTRA switches open inside the operation' );
		$this->assert_same( null, $escaped, 'a throw midway through a nested unwind does not cross the filter boundary' );
		$this->assert_true( is_wp_error( $result ), 'and it is never reported as success' );
		if ( is_wp_error( $result ) ) {
			$this->assert_same( 'platform_unavailable', $result->get_error_code(), 'it is platform_unavailable' );
			$this->assert_true(
				false === strpos( $result->get_error_message(), 'exploded' )
					&& false === strpos( $result->get_error_message(), 'midway' ),
				'and carries no exception text'
			);
		}

		$this->assert_same( $entry_depth, $after_depth, 'EVERY level is unwound despite the throw, not just the ones before it' );
		$this->assert_same( $entry_blog, $after_blog, 'so the blog context is the entry one, measured before harness cleanup' );
		$this->assert_same( $entry_prefix, $after_prefix, 'and $wpdb is NOT left pointing at the Hall' );
	}

	/**
	 * A SITE WITH NO OPTIONS TABLE IS REFUSED, WITHOUT TERMINATING THE PROCESS.
	 *
	 * This is the reason `is_blog_installed()` is not called on a possibly
	 * incomplete site: when the expected tables are missing it ends in
	 * `wpdb::bail()`, which kills the request rather than returning false. The
	 * marker read is error-aware instead, so an unreadable options table is an
	 * ordinary refusal — and the assertions after this call can only run because
	 * the process survived it.
	 */
	private function test_incomplete_site_does_not_terminate(): void {
		global $wpdb;

		$ownership = $this->ownership( self::SLUG_RESUME );
		$blog_id   = $this->created_site( self::SLUG_RESUME );
		if ( 0 === $blog_id ) {
			return;
		}

		$bound            = $this->command( self::SLUG_RESUME, $ownership );
		$bound['blog_id'] = $blog_id;

		$options = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		$missing = static function ( $query ) use ( $options ) {
			$text = (string) $query;

			return ( false !== strpos( $text, $options ) )
				? str_replace( $options, 'zz_bc_absent_options_for_test', $text )
				: $query;
		};

		$before = $this->site_count();
		add_filter( 'query', $missing, 1 );
		try {
			$result = HallSiteProvisioner::provision( null, $bound );
		} finally {
			remove_filter( 'query', $missing, 1 );
		}

		$this->assert_true( is_wp_error( $result ), 'a site whose options table cannot be read is refused' );
		if ( is_wp_error( $result ) ) {
			$this->assert_same(
				'site_ownership_unproven',
				$result->get_error_code(),
				'as site_ownership_unproven: an unreadable marker is never a verified absence'
			);
		}
		$this->assert_same( $before, $this->site_count(), 'no site is created, adopted or re-initialized' );
		/*
		 * THESE THREE ASSERTIONS ARE THEMSELVES THE SURVIVAL PROOF. They run
		 * after the call, so reaching them at all is what shows the refusal
		 * RETURNED instead of ending in wpdb::bail(). A further
		 * `assert_true( true, 'the process survived' )` asserted nothing the
		 * three above had not already established by executing.
		 */
		$this->assert_same( $ownership, $this->marker( $blog_id ), 'and no marker is written onto it' );
	}

	private function switch_depth(): int {
		return is_array( $GLOBALS['_wp_switched_stack'] ?? null )
			? count( $GLOBALS['_wp_switched_stack'] )
			: 0;
	}

	private function test_no_consumer_returns_null(): void {
		remove_filter( PlatformPlugin::PROVISION_FILTER, [ HallSiteProvisioner::class, 'provision' ], 10 );
		try {
			$result = apply_filters( PlatformPlugin::PROVISION_FILTER, null, $this->command( self::SLUG_SEED ) );
		} finally {
			add_filter( PlatformPlugin::PROVISION_FILTER, [ HallSiteProvisioner::class, 'provision' ], 10, 2 );
		}

		$this->assert_true( null === $result, 'with no consumer the filter returns the unchanged null seed' );
		$this->assert_same(
			10,
			has_filter( PlatformPlugin::PROVISION_FILTER, [ HallSiteProvisioner::class, 'provision' ] ),
			'the consumer is restored afterwards'
		);
	}

	private function test_errors_disclose_nothing(): void {
		$forbidden = [ 'SELECT', 'INSERT', 'UPDATE', 'DROP', $GLOBALS['wpdb']->base_prefix, ABSPATH, 'mysqli', 'Stack trace' ];

		$samples = [
			HallSiteProvisioner::provision( null, 'nonsense' ),
			HallSiteProvisioner::provision( null, $this->command( self::SLUG_FOREIGN, strtolower( wp_generate_uuid4() ) ) ),
			HallSiteAddress::forSlug( 'Upper' ),
			HallSiteAdminUser::resolve( $this->super_admin_id ),
		];

		foreach ( $samples as $index => $sample ) {
			if ( ! is_wp_error( $sample ) ) {
				continue;
			}
			$text = $sample->get_error_message() . '|' . wp_json_encode( $sample->get_error_data() );
			foreach ( $forbidden as $needle ) {
				$this->assert_true(
					'' === (string) $needle || false === strpos( $text, (string) $needle ),
					"platform error {$index} discloses no " . substr( (string) $needle, 0, 12 )
				);
			}
			$this->assert_true(
				in_array( $sample->get_error_code(), HallSiteProvisioner::ERROR_CODES, true )
					|| in_array( $sample->get_error_code(), [ 'address_invalid' ], true ),
				"platform error {$index} uses an allowlisted code"
			);
		}
	}

	private function test_no_mail_was_sent(): void {
		$this->assert_same(
			0,
			(int) ( $GLOBALS['bc_platform_mail_attempts'] ?? -1 ),
			'no mail was attempted by any site creation in this run'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** @return array<string,mixed> */
	private function command( string $slug, ?string $ownership = null ): array {
		return [
			'portal_hall_id'      => 99000 + strlen( $slug ),
			'operation_id'        => strtolower( wp_generate_uuid4() ),
			'site_ownership_id'   => $ownership ?? $this->ownership( $slug ),
			'site_slug'           => $slug,
			'hall_name'           => 'Platform Test Hall ' . $slug,
			'blog_id'             => null,
			'request_fingerprint' => hash( 'sha256', 'platform-test:' . $slug ),
		];
	}

	/** A stable per-slug ownership identity, so a retry carries the same one. */
	private function ownership( string $slug ): string {
		static $ids = [];

		if ( ! isset( $ids[ $slug ] ) ) {
			$ids[ $slug ] = strtolower( wp_generate_uuid4() );
		}

		return $ids[ $slug ];
	}

	private function created_site( string $slug ): int {
		$address = HallSiteAddress::forSlug( $slug );
		if ( ! $address instanceof HallSiteAddress ) {
			return 0;
		}
		$id = $address->existingSiteId();

		return is_int( $id ) ? $id : 0;
	}

	/** Create a site at an address WITHOUT any ownership marker. */
	private function seed_unmarked_site( string $slug ): int {
		$address = HallSiteAddress::forSlug( $slug );
		if ( ! $address instanceof HallSiteAddress ) {
			return 0;
		}

		$created = wpmu_create_blog(
			$address->domain,
			$address->path,
			'Unmarked fixture site',
			$this->super_admin_id,
			[ 'archived' => 1 ],
			$address->network_id
		);
		wp_installing( false );

		if ( is_wp_error( $created ) || ! is_int( $created ) ) {
			$this->assert_true( false, 'the unmarked fixture site could not be created' );

			return 0;
		}

		$this->disposable_sites[ $created ] = true;
		$this->assert_same( null, $this->marker( $created ), 'the unmarked fixture site carries no ownership marker' );

		return $created;
	}

	private function marker( int $blog_id ): ?string {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", HallSiteProvisioner::MARKER_OPTION ),
			ARRAY_A
		);

		return is_array( $row ) ? (string) $row['option_value'] : null;
	}

	/** Rewrite the marker read for ONE site into a statement that cannot succeed. */
	private function marker_read_obstruction( int $blog_id ): callable {
		global $wpdb;

		$table = $wpdb->get_blog_prefix( $blog_id ) . 'options';

		return static function ( $query ) use ( $table ) {
			$text = (string) $query;
			if ( false !== strpos( $text, $table ) && false !== strpos( $text, 'bc_commerce_managed' ) ) {
				return 'SELECT option_value FROM zz_bc_absent_options_for_test LIMIT 1';
			}

			return $query;
		};
	}

	private function site_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->blogs}" );
	}

	/** @return array<int,string> */
	private function site_table_names( int $blog_id ): array {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $blog_id );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$tables = is_array( $tables ) ? array_map( 'strval', $tables ) : [];
		sort( $tables );

		return $tables;
	}

	/** @param array<string,mixed> $array @return array<int,string> */
	private function sorted_keys( array $array ): array {
		$keys = array_keys( $array );
		sort( $keys );

		return $keys;
	}

	/** @param array<int,string> $values @return array<int,string> */
	private function sorted( array $values ): array {
		sort( $values );

		return $values;
	}

	/** @param array<string,mixed> $command */
	private function message_is_safe( WP_Error $error, array $command ): bool {
		$text = $error->get_error_message() . '|' . wp_json_encode( $error->get_error_data() );

		foreach ( $command as $value ) {
			if ( is_string( $value ) && '' !== $value && false !== strpos( $text, $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drive one constant state per PROCESS, because a PHP constant cannot be
	 * redefined inside this one.
	 *
	 * @param array<string,string> $cases scenario => expected verdict
	 */
	private function run_worker( string $worker, array $cases ): void {
		$path = __DIR__ . '/' . $worker;
		if ( ! is_file( $path ) ) {
			$this->assert_true( false, "the worker {$worker} exists" );

			return;
		}

		foreach ( $cases as $scenario => $expected ) {
			$command = sprintf(
				'BC_TEST_DB=%s BC_PLATFORM_ADMIN_CASE=%s %s %s 2>&1',
				escapeshellarg( BC_Fixture::$database ),
				escapeshellarg( $scenario ),
				escapeshellarg( PHP_BINARY ),
				escapeshellarg( $path )
			);

			$output = (string) shell_exec( $command );
			$lines  = array_values( array_filter( array_map( 'trim', explode( "\n", $output ) ), static fn( string $l ): bool => '' !== $l ) );
			$verdict = $lines ? (string) end( $lines ) : '(no output)';

			$this->assert_same( $expected, $verdict, "Hall administrator constant case '{$scenario}' resolves to {$expected}" );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Teardown
	 * ------------------------------------------------------------------ */

	private function teardown(): void {
		global $wpdb;

		$connected = (string) $wpdb->get_var( 'SELECT DATABASE()' );
		if ( $connected !== BC_Fixture::$database
			|| ! in_array( $connected, BC_FIXTURE_DB_ALLOWLIST, true )
			|| $connected === BC_Fixture::$live_db ) {
			$this->assert_true( false, 'teardown refused: the connected database is not the fixture' );

			return;
		}

		$identity = $wpdb->get_var( 'SELECT identity FROM `' . BC_FIXTURE_SENTINEL_TABLE . '` LIMIT 1' );
		if ( null === $identity || BC_FIXTURE_IDENTITY !== (string) $identity ) {
			$this->assert_true( false, 'teardown refused: the fixture sentinel is missing or wrong' );

			return;
		}

		foreach ( array_keys( $this->disposable_sites ) as $blog_id ) {
			$blog_id = (int) $blog_id;
			if ( $blog_id <= 3 ) {
				$this->assert_true( false, 'teardown refused: a site id outside the disposable range' );
				continue;
			}

			$prefix = $wpdb->base_prefix . $blog_id . '_';
			$tables = $this->site_table_names( $blog_id );

			wp_delete_site( $blog_id );

			foreach ( $tables as $table ) {
				// Re-derived and re-checked against the disposable prefix
				// immediately before the statement, so the effect and the guard
				// cannot diverge.
				if ( 0 === strpos( $table, $prefix ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
				}
			}

			$wpdb->delete( $wpdb->registration_log, [ 'blog_id' => $blog_id ], [ '%d' ] );

			$this->assert_same(
				0,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->blogs} WHERE blog_id = %d", $blog_id ) ),
				"disposable site {$blog_id} was removed"
			);
			$this->assert_same( [], $this->site_table_names( $blog_id ), "disposable site {$blog_id} left no table behind" );

			unset( $this->disposable_sites[ $blog_id ] );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( array_keys( $this->disposable_users ) as $user_id ) {
			$user_id = (int) $user_id;
			if ( $user_id < 1 ) {
				continue;
			}

			/*
			 * A Network Super Admin cannot be deleted while the network still
			 * lists it, so the grant is revoked first and the ORIGINAL
			 * `site_admins` value is restored immediately afterwards — the
			 * fixture is left exactly as it was found, including for the next
			 * run, which expects that account name to confer Super Admin.
			 */
			if ( is_super_admin( $user_id ) ) {
				revoke_super_admin( $user_id );
			}
			wpmu_delete_user( $user_id );
			$this->assert_true( ! get_userdata( $user_id ) instanceof WP_User, "disposable user {$user_id} was removed" );
			unset( $this->disposable_users[ $user_id ] );
		}

		if ( $this->owns_super_admin && null !== $this->original_site_admins ) {
			update_site_option( 'site_admins', $this->original_site_admins );
			$this->assert_same(
				$this->original_site_admins,
				get_site_option( 'site_admins', null ),
				'the network super-admin list was restored exactly as found'
			);
		}

		$this->assert_same( [], $this->disposable_sites, 'every disposable site created by this run was destroyed' );
		$this->assert_same( [], $this->disposable_users, 'every disposable user created by this run was destroyed' );
	}

	/* ------------------------------------------------------------------ *
	 * Assertions
	 * ------------------------------------------------------------------ */

	private function assert_true( bool $condition, string $message ): void {
		++$this->assertions;
		if ( $condition ) {
			return;
		}
		++$this->failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}

	/** @param mixed $expected @param mixed $actual */
	private function assert_same( $expected, $actual, string $message ): void {
		++$this->assertions;
		if ( $expected === $actual ) {
			return;
		}
		++$this->failures;
		fwrite(
			STDERR,
			sprintf(
				"FAIL: %s\n  expected: %s\n  actual:   %s\n",
				$message,
				var_export( $expected, true ),
				var_export( $actual, true )
			)
		);
	}

	private function report(): void {
		$passed = $this->assertions - $this->failures;
		fwrite( STDOUT, "\nPlatform Hall site provisioning: {$passed}/{$this->assertions} assertions passed.\n" );
		exit( $this->failures > 0 ? 1 : 0 );
	}
}

( new PlatformHallSiteProvisioningTest() )->run();
