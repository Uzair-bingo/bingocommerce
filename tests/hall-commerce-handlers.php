<?php

/**
 * Installed-handler integration for the two accepted Commerce access exceptions.
 *
 * Run from the repository root:
 *   BC_TEST_DB=bingocommerce_state_test php tests/hall-commerce-handlers.php
 *
 * WHAT THIS SUITE ADDS THAT THE ACCESS SUITE CANNOT. `hall-commerce-access.php`
 * proves that the gate refuses a blocked Hall and that an approved callback or
 * runner request is ADMITTED. Admission is not completion: it says the request
 * was not refused, not that the handler it was admitted for actually ran. This
 * suite boots the REAL installed WooCommerce into the isolated fixture and
 * drives both exceptions through their own entry points, so the distinction is
 * evidenced rather than argued.
 *
 *   PayPal      WP::main() -> parse_request -> LegacyRestApiStub ->
 *               WC()->payment_gateways() -> woocommerce_api_wc_gateway_paypal ->
 *               WC_Gateway_Paypal_IPN_Handler::check_response(), including its
 *               own post-back validation.
 *   Scheduler   admin-ajax's own dispatch -> wp_ajax_as_async_request_queue_runner
 *               -> WP_Async_Request::maybe_handle() -> its own
 *               check_ajax_referer() -> handle() -> action_scheduler_run_queue.
 *
 * NOTHING LEAVES THE MACHINE. The fixture rejects every outbound request before
 * any plugin loads; the worker answers exactly one URL — PayPal's IPN
 * verification post-back — with a canned body, and counts every attempt inside
 * the child process so the count is observed where it happens.
 *
 * This suite OWNS Hall A's per-blog tables, including every WooCommerce table
 * the installer creates for it, and drops exactly those again. It refuses to
 * start if any of them already exists.
 */

putenv( 'BC_FIXTURE_NETWORK_ACTIVE=1' );

require __DIR__ . '/fixtures/isolated-multisite.php';

require_once ABSPATH . 'wp-content/plugins/bingocommerce-portal-integration/bingocommerce-portal-integration.php';

use BingoCommerce\PortalIntegration\HallCommerceAccess;
use BingoCommerce\PortalIntegration\HallCommerceGate;
use BingoCommerce\PortalIntegration\HallCommerceState;
use BingoCommerce\PortalIntegration\ProvisioningMigrations;

final class HallCommerceHandlersTest {

	private int $assertions = 0;
	private int $failures   = 0;

	private int $hall = BC_FIXTURE_HALL_A;

	/** A well-formed managed-Hall witness. */
	private const MARKER = '3f2a91c4-5d6b-4e7a-9c81-2b4d6e8f0a13';

	private const PAYPAL_EMAIL = 'hall-receiver@example.invalid';

	private string $php_cgi = '';
	private bool $owns_state_table = false;
	private bool $owns_hall_tables = false;

	/** Pre-seeded pending orders, and the ones already consumed. */
	private array $orders      = [];
	private array $orders_used = [];

	/** A second fixture user, for the other-session nonce case. */
	private int $other_user = 0;

	/** The fixture-only queued action pair, and the token they carry. */
	private string $canary_token = '';
	private int $action_due      = 0;
	private int $action_future   = 0;

	/** Fixture-global values this suite must put back exactly as it found them. */
	private array $baseline = [];

	/** The per-blog tables this suite creates before WooCommerce adds its own. */
	private const PER_BLOG = [
		'options', 'posts', 'postmeta', 'comments', 'commentmeta',
		'links', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
	];

	public function run(): void {
		$this->php_cgi = $this->locate_php_cgi();

		$this->test_environment_is_isolated();
		$this->require_clean_starting_state();

		try {
			$this->capture_baseline();
			$this->prepare_hall();
			$this->create_users();
			$this->install_woocommerce();
			$this->seed_started_orders( 5 );
			$this->seed_queued_actions();
			$this->prepare_state();

			$this->test_fixture_host_matching();
			$this->test_paypal_ipn_completes_a_started_payment();
			$this->test_paypal_validation_is_still_required();
			$this->test_unapproved_callbacks_and_new_purchases();
			$this->test_callback_route_parity();
			$this->test_bodyless_callback_is_refused();
			$this->test_action_scheduler_runner();
			$this->test_child_termination_is_bounded();
			$this->test_all_seeded_orders_final_state();
		} finally {
			$this->teardown();
		}

		$this->report();
	}

	/* ------------------------------------------------------------------ *
	 * Guards
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
		$this->assert_true( true, 'WordPress is connected to the fixture database, not development' );
		$this->assert_true(
			defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL && '' === WP_ACCESSIBLE_HOSTS,
			'outbound HTTP is blocked with no accessible hosts'
		);
		$this->assert_true(
			is_readable( dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/woocommerce.php' ),
			'the installed WooCommerce is present to be exercised'
		);
		if ( '' === $this->php_cgi ) {
			$this->abort( 'php-cgi is required: these checks run the gate in its real non-CLI lifecycle position.' );
		}
		$this->assert_true( true, 'php-cgi is available, so the gate runs under a real non-CLI SAPI' );
	}

	private function require_clean_starting_state(): void {
		$leftovers = [];
		foreach ( $this->hall_tables() as $table ) {
			$leftovers[] = $table;
		}
		if ( [] !== $leftovers ) {
			$this->abort( 'Hall tables already exist in the fixture (' . implode( ', ', array_slice( $leftovers, 0, 5 ) ) . '...). Refusing to adopt them.' );
		}
		if ( $this->table_exists( ProvisioningMigrations::state_table() )
			|| (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ) > 0 ) {
			$this->abort( 'a Commerce state table or version is already present. Refusing to adopt it.' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Guarded fixture operations
	 * ------------------------------------------------------------------ */

	private const OP_CREATE_HALL_TABLES = 'create_hall_tables';
	private const OP_DROP_HALL_TABLES   = 'drop_hall_tables';
	private const OP_DROP_STATE_TABLE   = 'drop_state_table';

	/**
	 * The ADR-042 provisioning-operations table.
	 *
	 * `ProvisioningMigrations::migrate()` installs the whole v2 schema, which is
	 * BOTH the commerce-state table and this one, so a run that migrates owns
	 * both and must remove both. Leaving it behind makes the next harness to
	 * start — `portal-provisioning-foundation.php`, which correctly refuses to
	 * adopt a schema it did not create — abort, and the suite order-dependent.
	 */
	private const OP_DROP_OPERATIONS_TABLE = 'drop_operations_table';
	private const OP_DELETE_VERSION     = 'delete_version_option';
	private const OP_SET_MARKER         = 'set_marker';
	private const OP_DELETE_USER        = 'delete_fixture_user';
	private const OP_RESTORE_SITEMETA   = 'restore_sitemeta_value';
	private const OP_DELETE_FIXTURE_ROWS = 'delete_fixture_rows';

	private const PERMITTED_OPERATIONS = [
		self::OP_CREATE_HALL_TABLES,
		self::OP_DROP_HALL_TABLES,
		self::OP_DROP_STATE_TABLE,
		self::OP_DROP_OPERATIONS_TABLE,
		self::OP_DELETE_VERSION,
		self::OP_SET_MARKER,
		self::OP_DELETE_USER,
		self::OP_RESTORE_SITEMETA,
		self::OP_DELETE_FIXTURE_ROWS,
	];

	/**
	 * Verify ownership, then perform one named fixture operation.
	 *
	 * @param array<string,mixed> $args
	 */
	private function fixture_operation( string $operation, array $args = [], bool $expect_refusal = false ): bool {
		global $wpdb;

		$refuse = function ( string $reason ) use ( $operation, $expect_refusal ): bool {
			if ( ! $expect_refusal ) {
				$this->assert_true( false, "{$operation}: refused, {$reason}" );
			}

			return false;
		};

		if ( ! in_array( $operation, self::PERMITTED_OPERATIONS, true ) ) {
			return $refuse( 'the operation is not permitted' );
		}

		$connected = (string) $wpdb->get_var( 'SELECT DATABASE()' );
		if ( $connected !== BC_Fixture::$database
			|| ! in_array( $connected, BC_FIXTURE_DB_ALLOWLIST, true )
			|| $connected === BC_Fixture::$live_db ) {
			return $refuse( 'the connected database is not the fixture' );
		}

		$identity = $wpdb->get_var( 'SELECT identity FROM `' . BC_FIXTURE_SENTINEL_TABLE . '` LIMIT 1' );
		if ( null === $identity || BC_FIXTURE_IDENTITY !== (string) $identity ) {
			return $refuse( 'the fixture sentinel is missing or wrong' );
		}

		$prefix = $wpdb->base_prefix . $this->hall . '_';

		switch ( $operation ) {
			case self::OP_CREATE_HALL_TABLES:
				foreach ( self::PER_BLOG as $suffix ) {
					// Structure copied from the fixture's own main-site tables.
					if ( false === $wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . $prefix . $suffix . '` LIKE `' . $wpdb->base_prefix . $suffix . '`' ) ) {
						return $refuse( "the {$suffix} table could not be created" );
					}
				}
				$result = 1;
				break;

			case self::OP_DROP_HALL_TABLES:
				/*
				 * Every table whose name begins with this Hall's per-blog prefix,
				 * discovered from information_schema rather than assumed: the
				 * WooCommerce installer creates roughly forty of them and this
				 * suite must leave none behind. The prefix is built from
				 * base_prefix and an integer, never from input.
				 */
				$dropped = 0;
				foreach ( $this->hall_tables() as $table ) {
					if ( 0 !== strpos( $table, $prefix ) ) {
						return $refuse( 'a discovered table did not carry the Hall prefix' );
					}
					if ( false === $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ) ) {
						return $refuse( "the table {$table} could not be dropped" );
					}
					$dropped++;
				}
				$result = $dropped;
				break;

			case self::OP_DROP_STATE_TABLE:
				$table = ProvisioningMigrations::state_table();
				if ( $table !== $wpdb->base_prefix . ProvisioningMigrations::STATE_TABLE ) {
					return $refuse( 'the state table name did not resolve as expected' );
				}
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
				break;

			case self::OP_DROP_OPERATIONS_TABLE:
				$operations = ProvisioningMigrations::operations_table();
				if ( $operations !== $wpdb->base_prefix . ProvisioningMigrations::OPERATIONS_TABLE ) {
					return $refuse( 'the operations table name did not resolve as expected' );
				}
				$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . $operations . '`' );
				break;

			case self::OP_DELETE_VERSION:
				$result = delete_site_option( ProvisioningMigrations::VERSION_OPTION ) ? 1 : 0;
				break;

			case self::OP_SET_MARKER:
				if ( ! isset( $args['value'] ) || ! is_string( $args['value'] ) ) {
					return $refuse( 'the marker value is not a string' );
				}
				$table  = $prefix . 'options';
				$result = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO `{$table}` (option_name, option_value, autoload)
						 VALUES (%s, %s, 'no')
						 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
						HallCommerceAccess::MARKER_OPTION,
						$args['value']
					)
				);
				break;

			case self::OP_DELETE_USER:
				// Ownership re-proven at deletion time, against the database this
				// connection is actually on: this run recorded the id, the stored
				// login still matches it, and it still carries the fixture prefix.
				if ( ! isset( $args['user_id'] ) || ! is_int( $args['user_id'] ) || $args['user_id'] < 1
					|| $args['user_id'] !== $this->other_user ) {
					return $refuse( 'that user was not created by this run' );
				}
				$stored = $wpdb->get_var( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", $args['user_id'] ) );
				if ( '' !== (string) $wpdb->last_error ) {
					return $refuse( 'the stored login could not be read' );
				}
				if ( 'fixture_other' !== (string) $stored ) {
					return $refuse( 'the stored login does not match this run\'s fixture user' );
				}
				$meta = $wpdb->delete( $wpdb->usermeta, [ 'user_id' => $args['user_id'] ], [ '%d' ] );
				$user = $wpdb->delete( $wpdb->users, [ 'ID' => $args['user_id'] ], [ '%d' ] );
				clean_user_cache( $args['user_id'] );
				$result = ( false !== $meta && 1 === (int) $user ) ? 1 : false;
				break;

			case self::OP_RESTORE_SITEMETA:
				/*
				 * Puts back a value this suite CHANGED, by name, to the exact
				 * bytes captured before it ran. Only keys this suite is known to
				 * disturb are permitted, and only when a baseline exists — an
				 * unidentified or unbaselined row is never written.
				 */
				if ( ! isset( $args['key'] ) || ! is_string( $args['key'] )
					|| ! in_array( $args['key'], [ 'active_sitewide_plugins', 'user_count' ], true ) ) {
					return $refuse( 'that sitemeta key is not one this suite may restore' );
				}
				if ( ! isset( $args['value'] ) || ! is_string( $args['value'] ) ) {
					return $refuse( 'no captured baseline value was supplied' );
				}
				$current = $wpdb->get_var(
					$wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1", get_current_network_id(), $args['key'] )
				);
				if ( '' !== (string) $wpdb->last_error ) {
					return $refuse( 'the current value could not be read' );
				}
				if ( null === $current ) {
					return $refuse( 'the row is absent, so this suite did not change it' );
				}
				if ( (string) $current === $args['value'] ) {
					// Already as we found it: nothing to do, and nothing written.
					$result = 1;
					break;
				}
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->sitemeta} SET meta_value = %s WHERE site_id = %d AND meta_key = %s",
						$args['value'],
						get_current_network_id(),
						$args['key']
					)
				);
				break;

			case self::OP_DELETE_FIXTURE_ROWS:
				/*
				 * The queued actions and the canary this suite created, removed
				 * deliberately rather than incidentally by the table drop that
				 * follows. Both are matched on fixture-only names.
				 */
				$actions = $prefix . 'actionscheduler_actions';
				$options = $prefix . 'options';
				$removed = 0;

				if ( $this->table_exists( $actions ) ) {
					$deleted = $wpdb->query(
						$wpdb->prepare(
							"DELETE FROM `{$actions}` WHERE hook IN (%s, %s)",
							'bc_fixture_canary_action',
							'bc_fixture_control_action'
						)
					);
					if ( false === $deleted ) {
						return $refuse( 'the fixture action rows could not be removed' );
					}
					$removed += (int) $deleted;
				}

				if ( $this->table_exists( $options ) ) {
					$deleted = $wpdb->query(
						$wpdb->prepare( "DELETE FROM `{$options}` WHERE option_name = %s", 'bc_fixture_canary_runs' )
					);
					if ( false === $deleted ) {
						return $refuse( 'the canary option could not be removed' );
					}
					$removed += (int) $deleted;
				}

				$result = $removed;
				break;

			default:
				return $refuse( 'unreachable operation' );
		}

		if ( ! $expect_refusal ) {
			$this->assert_true( false !== $result, "{$operation}: statement succeeded" );
		}

		return false !== $result;
	}

	/* ------------------------------------------------------------------ *
	 * Fixture preparation
	 * ------------------------------------------------------------------ */

	private function prepare_hall(): void {
		global $wpdb;

		$this->fixture_operation( self::OP_CREATE_HALL_TABLES );
		$this->owns_hall_tables = true;

		$prefix = $wpdb->base_prefix . $this->hall . '_';
		$host   = 'hall-a.' . BC_FIXTURE_DOMAIN;

		$version = 0;
		include dirname( __DIR__ ) . '/wp-includes/version.php';

		$options = [
			'siteurl'             => 'http://' . $host,
			'home'                => 'http://' . $host,
			'blogname'            => 'Fixture Hall A',
			'admin_email'         => 'hall@example.invalid',
			'template'            => 'twentytwentyfive',
			'stylesheet'          => 'twentytwentyfive',
			'blog_charset'        => 'UTF-8',
			'permalink_structure' => '/%postname%/',
			'active_plugins'      => serialize( [] ),
			'cron'                => serialize( [] ),
			'timezone_string'     => '',
			'gmt_offset'          => '0',
			'date_format'         => 'Y-m-d',
			'time_format'         => 'H:i',
			'default_role'        => 'subscriber',
			'WPLANG'              => '',
			'db_version'          => (string) ( $wp_db_version ?? 0 ),
			'initial_db_version'  => (string) ( $wp_db_version ?? 0 ),
			'woocommerce_currency' => 'USD',
			// PayPal Standard: DISABLED for new purchases, but still loadable —
			// exactly the state a Hall is in when a notification for an earlier
			// order arrives after the gateway has been switched off.
			'woocommerce_paypal_settings' => serialize(
				[
					'enabled'        => 'no',
					'_should_load'   => 'yes',
					'title'          => 'PayPal',
					'email'          => self::PAYPAL_EMAIL,
					'receiver_email' => self::PAYPAL_EMAIL,
					'testmode'       => 'no',
					'debug'          => 'no',
					'identity_token' => '',
					'invoice_prefix' => 'WC-',
				]
			),
		];

		foreach ( $options as $name => $value ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `{$prefix}options` (option_name, option_value, autoload)
					 VALUES (%s, %s, 'yes')
					 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
					$name,
					$value
				)
			);
			if ( false === $ok ) {
				$this->abort( "the Hall option {$name} could not be seeded." );
			}
		}

		$this->assert_true( true, 'the Hall has a complete per-blog table set and minimal options' );
	}

	private function prepare_state(): void {
		$this->assert_true( true === ProvisioningMigrations::migrate(), 'the Commerce state schema migrates in the fixture' );
		$this->owns_state_table = true;

		$written = HallCommerceState::write( $this->hall, HallCommerceState::SUSPENDED, 500, HallCommerceState::PHASE_SETTLED );
		$this->assert_true(
			is_array( $written ) && HallCommerceState::APPLIED === $written['outcome'],
			'the Hall is recorded as SUSPENDED'
		);
		$this->fixture_operation( self::OP_SET_MARKER, [ 'value' => self::MARKER ] );

		$verdict = HallCommerceAccess::evaluate( $this->hall );
		$this->assert_true(
			HallCommerceAccess::BLOCK === $verdict['access'] && HallCommerceAccess::REASON_SUSPENDED === $verdict['reason'],
			'the Hall is blocked before any request is made'
		);
	}

	/* ------------------------------------------------------------------ *
	 * PayPal IPN
	 * ------------------------------------------------------------------ */

	private function test_paypal_ipn_completes_a_started_payment(): void {
		$order = $this->next_order( 'paypal_verified' );

		$run = $this->worker(
			[
				'path'        => '/wc-api/WC_Gateway_Paypal/',
				'body'        => $this->ipn_body( $order ),
				'dispatch'    => 'wp',
				'woocommerce' => true,
				'paypal'      => 'VERIFIED',
			]
		);

		$this->assert_worker_clean( $run, 'the verified IPN request' );
		$this->assert_true( false === $run['report']['refused'], 'a suspended Hall admits an approved payment callback' );
		$this->assert_true(
			in_array( 'woocommerce_api_wc_gateway_paypal', (array) $run['report']['handlers'], true ),
			'  and the request REACHES the installed woocommerce_api_wc_gateway_paypal handler'
		);
		$this->assert_true(
			in_array( 'valid-paypal-standard-ipn-request', (array) $run['report']['handlers'], true ),
			'  the installed handler accepted the notification as valid'
		);
		$this->assert_true(
			true === ( $run['report']['paypal_verification'] ?? false ),
			'  the handler performed its own post-back validation with PayPal'
		);
		$this->assert_same(
			[ 'https://www.paypal.com/cgi-bin/webscr' ],
			(array) $run['report']['http_urls'],
			'  and that was the ONLY outbound request the child attempted'
		);

		$after = $this->order_state( $order['id'] );
		$this->assert_same( 'completed', $after['status'], 'the already-started payment COMPLETED while the Hall is suspended' );
		$this->assert_same( 'IPN-TXN-VERIFIED', $after['transaction_id'], '  with the transaction id the notification carried' );

		$this->assert_true(
			'no' === $this->hall_option( 'woocommerce_paypal_settings' )['enabled'],
			'  while PayPal remained DISABLED for new purchases throughout'
		);
	}

	private function test_paypal_validation_is_still_required(): void {
		$order = $this->next_order( 'paypal_invalid' );

		$run = $this->worker(
			[
				'path'        => '/wc-api/WC_Gateway_Paypal/',
				'body'        => $this->ipn_body( $order ),
				'dispatch'    => 'wp',
				'woocommerce' => true,
				'paypal'      => 'INVALID',
			]
		);

		$this->assert_worker_clean( $run, 'the unverified IPN request' );
		$this->assert_true( false === $run['report']['refused'], 'the gate admits the request on routing grounds alone' );
		$this->assert_true(
			in_array( 'woocommerce_api_wc_gateway_paypal', (array) $run['report']['handlers'], true ),
			'  the installed handler still ran'
		);
		$this->assert_true(
			true === ( $run['report']['paypal_verification'] ?? false ),
			'  and still posted back to PayPal to validate'
		);
		$this->assert_true(
			! in_array( 'valid-paypal-standard-ipn-request', (array) $run['report']['handlers'], true ),
			'  but PayPal answered INVALID, so the handler refused the notification'
		);

		$after = $this->order_state( $order['id'] );
		$this->assert_same( 'pending', $after['status'], "the handler's OWN validation is what protects the order, and it held" );
		$this->assert_same( '', $after['transaction_id'], '  no transaction id was recorded' );
	}

	private function test_unapproved_callbacks_and_new_purchases(): void {
		$order = $this->next_order( 'rejected_callbacks' );

		// An unapproved gateway identifier is refused by the gate, so the
		// dispatcher is never reached at all.
		$run = $this->worker(
			[
				'path'        => '/wc-api/WC_Gateway_Stripe/',
				'body'        => $this->ipn_body( $order ),
				'dispatch'    => 'wp',
				'woocommerce' => true,
				'paypal'      => 'VERIFIED',
			]
		);
		$this->assert_worker_clean( $run, 'the unapproved callback request', true );
		$this->assert_true( true === $run['report']['refused'], 'an unapproved callback identifier is refused' );
		$this->assert_same( 503, $run['status'], '  with HTTP 503' );
		$this->assert_same( [], (array) $run['report']['handlers'], '  and no installed handler ran' );
		$this->assert_same( 0, (int) $run['report']['http_attempts'], '  and the child attempted no HTTP at all' );

		// A NEW purchase is still refused while the Hall is suspended.
		foreach (
			[
				'the checkout'      => [ 'path' => '/checkout/', 'body' => 'woocommerce_checkout_place_order=1' ],
				'an add-to-cart'    => [ 'path' => '/', 'query' => 'add-to-cart=5' ],
				'the Store API'     => [ 'path' => '/wp-json/wc/store/v1/cart/add-item', 'body' => 'id=5' ],
			] as $label => $spec
		) {
			$run = $this->worker( $spec + [ 'dispatch' => 'wp', 'woocommerce' => true ] );
			$this->assert_worker_clean( $run, $label, true );
			$this->assert_true( true === $run['report']['refused'], "a new purchase through {$label} is still refused" );
			$this->assert_same( [], (array) $run['report']['handlers'], "  and {$label} reached no handler" );
		}

		$after = $this->order_state( $order['id'] );
		$this->assert_same( 'pending', $after['status'], 'the untouched order was not altered by any refused request' );
	}

	/**
	 * ROUTE PARITY: exempt if and only if the installed dispatcher arrives.
	 *
	 * Each form below is put through the REAL dispatcher and two facts are
	 * recorded — whether the gate admitted it, and whether
	 * `woocommerce_api_wc_gateway_paypal` actually fired. Admitting a form that
	 * the dispatcher never routes would mean running WooCommerce's whole stack
	 * on a blocked Hall for nothing; refusing a form it does route would break a
	 * real notification. The assertion is the biconditional, so either mistake
	 * fails here.
	 */
	private function test_callback_route_parity(): void {
		$order = $this->next_order( 'route_parity' );
		$body  = $this->ipn_body( $order );

		$forms = [
			'the query form'               => [ 'path' => '/', 'query' => 'wc-api=WC_Gateway_Paypal' ],
			'the permalink form'           => [ 'path' => '/wc-api/WC_Gateway_Paypal/' ],
			'the permalink form unslashed' => [ 'path' => '/wc-api/WC_Gateway_Paypal' ],
			'an extra path segment'        => [ 'path' => '/wc-api/WC_Gateway_Paypal/extra/' ],
			'a repeated trailing slash'    => [ 'path' => '/wc-api/WC_Gateway_Paypal//' ],
			'three trailing slashes'       => [ 'path' => '/wc-api/WC_Gateway_Paypal///' ],
			'conflicting identifiers'      => [ 'path' => '/wc-api/WC_Gateway_Paypal/', 'query' => 'wc-api=WC_Gateway_Stripe' ],
			'conflicting, approved query'  => [ 'path' => '/wc-api/WC_Gateway_Stripe/', 'query' => 'wc-api=WC_Gateway_Paypal' ],
		];

		foreach ( $forms as $label => $spec ) {
			$run = $this->worker(
				$spec + [
					'body'        => $body,
					'dispatch'    => 'wp',
					'woocommerce' => true,
					// Never VERIFIED here: this test is about routing, and the
					// order must not be completed by it.
					'paypal'      => 'INVALID',
				]
			);
			$this->assert_worker_clean( $run, "route parity: {$label}", true === ( $run['report']['refused'] ?? false ) );

			$admitted = false === ( $run['report']['refused'] ?? true );
			$arrived  = in_array( 'woocommerce_api_wc_gateway_paypal', (array) ( $run['report']['handlers'] ?? [] ), true );

			$this->assert_true(
				$admitted === $arrived,
				"route parity: {$label} is admitted IF AND ONLY IF the installed dispatcher reaches the handler"
					. ( $admitted === $arrived ? ' (' . ( $admitted ? 'both' : 'neither' ) . ')' : " (admitted=" . var_export( $admitted, true ) . ", reached=" . var_export( $arrived, true ) . ')' )
			);
		}

		$state = $this->order_state( $order['id'] );
		$this->assert_same( 'pending', $state['status'], 'route parity: no form completed the order' );
		$this->assert_same( '', $state['transaction_id'], '  and none recorded a transaction id' );
	}

	/**
	 * A notification without a POST body cannot be one.
	 *
	 * The installed handler opens with
	 * `if ( ! empty( $_POST ) && $this->validate_ipn() )` and otherwise
	 * `wp_die( 'PayPal IPN Request Failure', ..., 500 )`. The gate mirrors that
	 * precondition rather than inventing a method rule — and this proves the
	 * handler really does refuse the bodyless case, so the restriction is
	 * evidenced by the installed source AND by the handler's behaviour.
	 */
	private function test_bodyless_callback_is_refused(): void {
		$run = $this->worker(
			[
				'path'        => '/wc-api/WC_Gateway_Paypal/',
				'dispatch'    => 'wp',
				'woocommerce' => true,
				'paypal'      => 'VERIFIED',
			]
		);
		$this->assert_worker_clean( $run, 'a bodyless payment callback', true );
		$this->assert_true( true === $run['report']['refused'], 'a payment callback with no POST body is refused by the gate' );
		$this->assert_same( 503, $run['status'], '  with HTTP 503' );
		$this->assert_same( [], (array) $run['report']['handlers'], '  and reaches no handler' );
		$this->assert_same( 0, (int) $run['report']['http_attempts'], '  and attempts no verification post-back' );

		$source = (string) file_get_contents(
			dirname( __DIR__ ) . '/wp-content/plugins/woocommerce/includes/gateways/paypal/includes/class-wc-gateway-paypal-ipn-handler.php'
		);
		$this->assert_true(
			false !== strpos( $source, 'if ( ! empty( $_POST ) && $this->validate_ipn() )' )
				&& false !== strpos( $source, "wp_die( 'PayPal IPN Request Failure'" ),
			'  the installed handler itself requires a non-empty POST body and dies otherwise'
		);
	}

	/* ------------------------------------------------------------------ *
	 * Action Scheduler
	 * ------------------------------------------------------------------ */

	private function test_action_scheduler_runner(): void {
		$runner = HallCommerceGate::SCHEDULER_ACTION;

		// --- the starting point, stated rather than assumed -----------------
		$this->assert_same( 'pending', $this->action_status( $this->action_due ), 'the queued canary action starts pending' );
		$this->assert_same( 'pending', $this->action_status( $this->action_future ), 'the control action starts pending' );
		$this->assert_same( [], $this->canary_runs(), 'the canary has not been written' );

		$base = [
			'path'        => '/wp-admin/admin-ajax.php',
			'php_self'    => '/wp-admin/admin-ajax.php',
			'constants'   => [ 'WP_ADMIN', 'DOING_AJAX' ],
			'dispatch'    => 'ajax',
			'woocommerce' => true,
		];

		/*
		 * REJECTED FIRST, while the action is still pending and due. That
		 * ordering is what makes these controls meaningful: if any of them ran
		 * the queue, the canary would exist and the action would be complete
		 * before the valid request is ever made.
		 */
		$rejected = [
			'no nonce'                      => [ 'query' => 'action=' . $runner ],
			'an empty nonce'                => [ 'query' => 'action=' . $runner . '&nonce=' ],
			'a forged nonce'                => [ 'query' => 'action=' . $runner . '&nonce=forged-value' ],
			'a nonce for another action'    => [ 'query' => 'action=' . $runner, 'nonce_action' => 'some_other_action', 'nonce_param' => 'nonce' ],
			"another session's nonce"       => [ 'query' => 'action=' . $runner, 'nonce_action' => $runner, 'nonce_param' => 'nonce', 'nonce_user' => 'fixture_other' ],
			'a valid nonce plus add-to-cart' => [ 'query' => 'action=' . $runner . '&add-to-cart=5', 'nonce_action' => $runner, 'nonce_param' => 'nonce' ],
			'an unrelated action: heartbeat' => [ 'query' => 'action=heartbeat', 'nonce_action' => $runner, 'nonce_param' => 'nonce' ],
			'an unrelated action: fragments' => [ 'query' => 'action=woocommerce_get_refreshed_fragments', 'nonce_action' => $runner, 'nonce_param' => 'nonce' ],
		];

		foreach ( $rejected as $label => $spec ) {
			$run = $this->worker( $base + $spec );
			$this->assert_worker_clean( $run, "the queue runner with {$label}", true );
			$this->assert_true( true === $run['report']['refused'], "the queue runner with {$label} is refused" );
			$this->assert_same( 503, $run['status'], "  {$label}: HTTP 503" );
			$this->assert_same( [], (array) $run['report']['handlers'], "  {$label}: no handler ran" );
			$this->assert_same( 'pending', $this->action_status( $this->action_due ), "  {$label}: the queued action is still pending" );
			$this->assert_same( 'pending', $this->action_status( $this->action_future ), "  {$label}: the control action is unchanged" );
			$this->assert_same( [], $this->canary_runs(), "  {$label}: nothing was written to the canary" );
		}

		// --- the authenticated runner ---------------------------------------
		$run = $this->worker(
			$base + [
				'query'        => 'action=' . $runner,
				'nonce_action' => $runner,
				'nonce_param'  => 'nonce',
			]
		);
		$this->assert_worker_clean( $run, 'the authenticated queue runner' );
		$this->assert_true( false === $run['report']['refused'], 'the queue runner with a valid nonce is admitted' );
		$this->assert_true(
			'' !== (string) ( $run['report']['ajax_nopriv'] ?? '' ),
			'  the installed runner registered its own admin-ajax handler'
		);
		$this->assert_true(
			in_array( 'action_scheduler_run_queue:Async Request', (array) $run['report']['handlers'], true ),
			'  the installed runner handler RAN, declaring the Async Request context'
		);
		$this->assert_true(
			in_array( 'queued:' . $this->canary_token, (array) $run['report']['handlers'], true ),
			'  and the QUEUED CALLBACK itself executed inside that run'
		);
		$this->assert_no_external_http( $run, '  the queue runner' );

		// --- what the queue actually did, read from storage ------------------
		$runs = $this->canary_runs();
		$this->assert_same( 1, count( $runs ), 'the queued callback executed EXACTLY ONCE' );
		$this->assert_same(
			$this->canary_token,
			(string) ( $runs[0]['token'] ?? '' ),
			'  and wrote the canary this suite seeded, with its own token'
		);
		$this->assert_same( 'complete', $this->action_status( $this->action_due ), 'the queued action reached the completed state' );
		$this->assert_same( 'pending', $this->action_status( $this->action_future ), 'no unrelated queued action executed' );
		$this->assert_true(
			! in_array( 'control-executed:' . $this->canary_token, (array) $run['report']['handlers'], true ),
			'  the control callback was never invoked'
		);

		// A second authenticated run must not re-execute a completed action.
		$run = $this->worker(
			$base + [
				'query'        => 'action=' . $runner,
				'nonce_action' => $runner,
				'nonce_param'  => 'nonce',
			]
		);
		$this->assert_worker_clean( $run, 'a second authenticated queue runner' );
		$this->assert_same( 1, count( $this->canary_runs() ), 'running the queue again did not re-execute the completed action' );
		$this->assert_same( 'pending', $this->action_status( $this->action_future ), '  and still left the control action alone' );
	}

	/**
	 * The escalation path must finish, and must leave nothing running.
	 *
	 * A fixture-only worker mode ignores SIGTERM and stalls, so the parent is
	 * forced all the way through terminate -> bounded wait -> SIGKILL ->
	 * CONFIRMED exit. Without that confirmation, proc_close() is the call that
	 * would hang, which is exactly what this proves cannot happen.
	 */
	private function test_child_termination_is_bounded(): void {
		if ( ! extension_loaded( 'pcntl' ) ) {
			$this->assert_true( true, 'the SIGTERM-ignoring control is SKIPPED: pcntl is unavailable' );

			return;
		}

		$started = microtime( true );
		$run     = $this->worker(
			[
				'path'     => '/wp-login.php',
				'php_self' => '/wp-login.php',
				'stall'    => 60,
				'deadline' => 2.0,
			]
		);
		$elapsed = microtime( true ) - $started;

		$this->assert_true( true === $run['timed_out'], 'a stalling worker is detected as overrunning its deadline' );
		$this->assert_true( true === $run['exit_confirmed'], '  its exit was CONFIRMED before the handle was reaped' );
		$this->assert_true(
			false !== strpos( (string) $run['stderr'] . (string) $run['raw'], 'BC-STALLING' ),
			'  the worker really did reach its stall, so the control is not vacuous'
		);
		$this->assert_same( 0, (int) $run['unreaped_pid'], '  no child was left unreaped' );

		// 2s deadline + up to 5s ignored-SIGTERM grace + SIGKILL, with margin.
		$this->assert_true(
			$elapsed < 20.0,
			sprintf( '  and the whole escalation finished in %.1fs, inside its stated bound', $elapsed )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Queued work
	 * ------------------------------------------------------------------ */

	/**
	 * Enqueue the fixture-only action pair through the installed API.
	 *
	 * Seeded BEFORE suspension, like the orders: work that was already on the
	 * queue when the store closed.
	 */
	private function seed_queued_actions(): void {
		$this->canary_token = 'bcfx' . bin2hex( random_bytes( 8 ) );

		$run = $this->worker(
			[
				'path'         => '/',
				'woocommerce'  => true,
				'seed_actions' => true,
				'canary'       => $this->canary_token,
			]
		);
		$this->assert_worker_clean( $run, 'the action seeding request' );

		$this->action_due    = (int) ( $run['report']['actions']['due'] ?? 0 );
		$this->action_future = (int) ( $run['report']['actions']['future'] ?? 0 );

		if ( $this->action_due < 1 || $this->action_future < 1 || $this->action_due === $this->action_future ) {
			$this->abort( 'the fixture-only queued actions could not be enqueued.' );
		}

		$this->assert_true( true, "one due and one future fixture-only action are queued ({$this->action_due}, {$this->action_future})" );
		$this->assert_same(
			'bc_fixture_canary_action',
			$this->action_hook( $this->action_due ),
			'  the due action is the fixture canary hook and nothing else'
		);
		$this->assert_same(
			'bc_fixture_control_action',
			$this->action_hook( $this->action_future ),
			'  the control action is the fixture control hook and nothing else'
		);
		$this->assert_same(
			2,
			$this->fixture_action_count(),
			'  and the suite queued exactly two actions in its own group'
		);
	}

	private function action_status( int $action_id ): string {
		return (string) $this->action_column( $action_id, 'status' );
	}

	private function action_hook( int $action_id ): string {
		return (string) $this->action_column( $action_id, 'hook' );
	}

	/** @return mixed */
	private function action_column( int $action_id, string $column ) {
		global $wpdb;

		if ( ! in_array( $column, [ 'status', 'hook' ], true ) ) {
			$this->abort( 'an unexpected action column was requested.' );
		}

		$table = $wpdb->base_prefix . $this->hall . '_actionscheduler_actions';
		if ( ! $this->table_exists( $table ) ) {
			$this->abort( 'the Action Scheduler actions table is missing.' );
		}

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM `{$table}` WHERE action_id = %d", $action_id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			$this->abort( 'the queued action could not be read.' );
		}
		if ( null === $value ) {
			$this->abort( "queued action {$action_id} no longer exists." );
		}

		return $value;
	}

	private function fixture_action_count(): int {
		global $wpdb;

		$table = $wpdb->base_prefix . $this->hall . '_actionscheduler_actions';
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE hook IN (%s, %s)", 'bc_fixture_canary_action', 'bc_fixture_control_action' )
		);
		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			$this->abort( 'the fixture action count could not be read.' );
		}

		return (int) $count;
	}

	/** @return array<int,array<string,mixed>> */
	private function canary_runs(): array {
		global $wpdb;

		$table = $wpdb->base_prefix . $this->hall . '_options';
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s", 'bc_fixture_canary_runs' ) );
		if ( '' !== (string) $wpdb->last_error ) {
			$this->abort( 'the canary option could not be read.' );
		}
		if ( null === $value ) {
			return [];
		}

		$runs = maybe_unserialize( (string) $value );

		return is_array( $runs ) ? array_values( $runs ) : [];
	}

	/* ------------------------------------------------------------------ *
	 * Orders
	 * ------------------------------------------------------------------ */

	/**
	 * Let WooCommerce install itself for this Hall, once.
	 *
	 * The installer runs on the first boot and creates roughly forty tables. A
	 * few of WooCommerce's own `init` callbacks query those tables during that
	 * same boot, before the installer has finished creating them, so the FIRST
	 * boot legitimately emits database errors that are WooCommerce's own
	 * first-run behaviour and nothing to do with this increment. That boot is
	 * therefore run on its own and its output is not held to the clean-run
	 * standard — but the very next boot is, which is what proves the install
	 * actually completed rather than merely being tolerated.
	 */
	private function install_woocommerce(): void {
		$first = $this->worker( [ 'path' => '/', 'woocommerce' => true ] );
		$this->assert_true( false === $first['timed_out'], 'the WooCommerce install boot finished within its deadline' );
		$this->assert_same( 0, (int) $first['exit'], '  and exited 0' );

		$tables = $this->hall_tables();
		$this->assert_true(
			count( $tables ) > count( self::PER_BLOG ),
			'the installer created WooCommerce tables for the Hall (' . count( $tables ) . ' in total)'
		);

		$second = $this->worker( [ 'path' => '/', 'woocommerce' => true ] );
		$this->assert_worker_clean( $second, 'the boot after WooCommerce installed' );
		$this->assert_true(
			false === strpos( (string) $second['raw'], 'WordPress database error' ),
			'  and it reports no database error, so the install completed'
		);
	}

	/**
	 * Create pending WooCommerce orders through the installed API.
	 *
	 * Seeded BEFORE the Hall is suspended, which is the situation being modelled:
	 * a payment that was already under way when the store closed. Done in a
	 * worker because WooCommerce is only loaded there — this parent deliberately
	 * boots without it, so the suite's own bookkeeping never depends on
	 * WooCommerce being installable.
	 */
	private function seed_started_orders( int $count ): void {
		$run = $this->worker(
			[
				'path'        => '/',
				'woocommerce' => true,
				'seed_order'  => true,
				'seed_count'  => $count,
			]
		);
		$this->assert_worker_clean( $run, 'the order seeding request' );

		foreach ( (array) ( $run['report']['orders'] ?? [] ) as $order ) {
			if ( is_array( $order ) && (int) ( $order['id'] ?? 0 ) > 0 && '' !== (string) ( $order['key'] ?? '' ) ) {
				$this->orders[] = [ 'id' => (int) $order['id'], 'key' => (string) $order['key'] ];
			}
		}

		if ( count( $this->orders ) < $count ) {
			$this->abort( 'the started orders could not be created in the fixture.' );
		}
		$this->assert_true( true, "{$count} started (pending) orders exist before the Hall is suspended" );

		foreach ( $this->orders as $order ) {
			$state = $this->order_state( $order['id'] );
			$this->assert_same( 'pending', $state['status'], "  order #{$order['id']} starts pending" );
		}
	}

	/**
	 * Take the next unused pre-seeded order and record what it is being used for.
	 *
	 * @return array{id:int,key:string}
	 */
	private function next_order( string $role ): array {
		$order = array_shift( $this->orders );
		if ( null === $order ) {
			$this->abort( 'the suite ran out of pre-seeded orders.' );
		}
		$this->orders_used[ $role ] = $order;

		return $order;
	}

	/**
	 * Every seeded order's FINAL state, asserted together.
	 *
	 * Individually each test already checks the order it touched; taken together
	 * this is the statement that matters — exactly one order moved, and only in
	 * the way the verified notification called for.
	 */
	private function test_all_seeded_orders_final_state(): void {
		$expected = [
			'paypal_verified'    => [ 'completed', 'IPN-TXN-VERIFIED' ],
			'paypal_invalid'     => [ 'pending', '' ],
			'rejected_callbacks' => [ 'pending', '' ],
			'route_parity'       => [ 'pending', '' ],
		];

		foreach ( $expected as $role => [ $status, $txn ] ) {
			if ( ! isset( $this->orders_used[ $role ] ) ) {
				$this->assert_true( false, "final state: no order was recorded for {$role}" );
				continue;
			}
			$state = $this->order_state( $this->orders_used[ $role ]['id'] );
			$this->assert_same( $status, $state['status'], "final state: the {$role} order is {$status}" );
			$this->assert_same( $txn, $state['transaction_id'], "final state: the {$role} order's transaction id is " . ( '' === $txn ? 'empty' : $txn ) );
		}

		// The order no test ever touched.
		$this->assert_same( 1, count( $this->orders ), 'final state: one control order was never used' );
		foreach ( $this->orders as $order ) {
			$state = $this->order_state( $order['id'] );
			$this->assert_same( 'pending', $state['status'], 'final state: the untouched control order is still pending' );
			$this->assert_same( '', $state['transaction_id'], '  with no transaction id' );
		}

		// Exactly one order completed, across every order this suite seeded.
		$completed = 0;
		foreach ( array_merge( array_values( $this->orders_used ), $this->orders ) as $order ) {
			if ( 'completed' === $this->order_state( $order['id'] )['status'] ) {
				$completed++;
			}
		}
		$this->assert_same( 1, $completed, 'final state: exactly ONE of the five seeded orders completed' );

		// And no refused request quietly created another.
		$this->assert_same( 5, $this->order_count(), 'final state: no additional order was created by any request' );
	}

	/**
	 * How many DISTINCT orders exist in this Hall.
	 *
	 * Counted across both storages and de-duplicated by id, because the
	 * installed build may use the HPOS tables, the posts table, or both in
	 * compatibility mode — and this suite must not depend on which.
	 */
	private function order_count(): int {
		global $wpdb;

		$prefix = $wpdb->base_prefix . $this->hall . '_';
		$ids    = [];

		if ( $this->table_exists( $prefix . 'wc_orders' ) ) {
			$rows = $wpdb->get_col( "SELECT id FROM `{$prefix}wc_orders`" );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
				$this->abort( 'the HPOS order ids could not be read.' );
			}
			foreach ( $rows as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM `{$prefix}posts` WHERE post_type = %s", 'shop_order' )
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			$this->abort( 'the post-storage order ids could not be read.' );
		}
		foreach ( $rows as $id ) {
			$ids[ (int) $id ] = true;
		}

		return count( $ids );
	}

	private function create_users(): void {
		if ( get_user_by( 'login', 'fixture_other' ) instanceof WP_User ) {
			$this->abort( 'the fixture user fixture_other already exists. Refusing to adopt it.' );
		}

		$user_id = wp_insert_user(
			[
				'user_login' => 'fixture_other',
				'user_email' => 'fixture-other@example.invalid',
				'user_pass'  => wp_generate_password( 24, true, true ),
				'role'       => '',
			]
		);
		if ( is_wp_error( $user_id ) ) {
			$this->abort( 'the fixture user could not be created.' );
		}
		$this->other_user = (int) $user_id;
		$this->assert_true( true, 'a second fixture user exists, so a nonce can be minted in another session' );
	}

	/** @param array{id:int,key:string} $order */
	private function ipn_body( array $order ): string {
		return http_build_query(
			[
				'custom'         => wp_json_encode( [ 'order_id' => $order['id'], 'order_key' => $order['key'] ] ),
				'payment_status' => 'Completed',
				'txn_type'       => 'web_accept',
				'txn_id'         => 'IPN-TXN-VERIFIED',
				'mc_gross'       => '25.00',
				'mc_currency'    => 'USD',
				'receiver_email' => self::PAYPAL_EMAIL,
				'payer_email'    => 'buyer@example.invalid',
			]
		);
	}

	/**
	 * Read an order's status and transaction id straight out of storage.
	 *
	 * @return array{status:string,transaction_id:string}
	 */
	private function order_state( int $order_id ): array {
		global $wpdb;

		$prefix = $wpdb->base_prefix . $this->hall . '_';
		$table  = $prefix . 'wc_orders';

		if ( $this->table_exists( $table ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT status, transaction_id FROM `{$table}` WHERE id = %d", $order_id ),
				ARRAY_A
			);
			if ( '' !== (string) $wpdb->last_error ) {
				$this->abort( 'the order could not be read back from storage.' );
			}
			if ( is_array( $row ) ) {
				return [
					'status'         => (string) preg_replace( '/^wc-/', '', (string) $row['status'] ),
					'transaction_id' => (string) $row['transaction_id'],
				];
			}
		}

		// Post-based order storage.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT post_status FROM `{$prefix}posts` WHERE ID = %d", $order_id ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			$this->abort( 'the order could not be read back from storage.' );
		}
		$txn = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_value FROM `{$prefix}postmeta` WHERE post_id = %d AND meta_key = '_transaction_id'", $order_id )
		);

		return [
			'status'         => (string) preg_replace( '/^wc-/', '', (string) $row['post_status'] ),
			'transaction_id' => $txn,
		];
	}

	/** @return array<string,mixed> */
	private function hall_option( string $name ): array {
		global $wpdb;

		$table = $wpdb->base_prefix . $this->hall . '_options';
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s", $name ) );
		if ( null === $value || '' !== (string) $wpdb->last_error ) {
			$this->abort( "the Hall option {$name} could not be read." );
		}

		$decoded = maybe_unserialize( (string) $value );

		return is_array( $decoded ) ? $decoded : [];
	}

	/* ------------------------------------------------------------------ *
	 * Worker
	 * ------------------------------------------------------------------ */

	/**
	 * Run one real request in its own process, under php-cgi.
	 *
	 * @param array<string,mixed> $spec
	 * @return array{status:int,headers:array<string,string>,report:array<string,mixed>,exit:int,stderr:string,raw:string,timed_out:bool}
	 */
	private function worker( array $spec ): array {
		$path  = (string) ( $spec['path'] ?? '/' );
		$query = (string) ( $spec['query'] ?? '' );
		$body  = (string) ( $spec['body'] ?? '' );

		$env = [
			'PATH'                      => (string) getenv( 'PATH' ),
			'BC_TEST_DB'                => BC_Fixture::$database,
			'BC_FIXTURE_NETWORK_ACTIVE' => '1',
			'BC_FIXTURE_WOOCOMMERCE'    => ! empty( $spec['woocommerce'] ) ? '1' : '',
			'BC_FIXTURE_CGI'            => '1',
			'REDIRECT_STATUS'           => '1',
			'BC_REQUEST_USER'           => (string) ( $spec['user'] ?? '' ),
			'BC_REQUEST_CONSTANTS'      => implode( ',', (array) ( $spec['constants'] ?? [] ) ),
			'BC_REQUEST_NONCE_ACTION'   => (string) ( $spec['nonce_action'] ?? '' ),
			'BC_REQUEST_NONCE_PARAM'    => (string) ( $spec['nonce_param'] ?? '' ),
			'BC_REQUEST_NONCE_USER'     => (string) ( $spec['nonce_user'] ?? '' ),
			'BC_REQUEST_DISPATCH'       => (string) ( $spec['dispatch'] ?? '' ),
			'BC_REQUEST_SEED_ORDER'     => ! empty( $spec['seed_order'] ) ? '1' : '',
			'BC_REQUEST_SEED_COUNT'     => (string) ( (int) ( $spec['seed_count'] ?? 1 ) ),
			'BC_PAYPAL_REPLY'           => (string) ( $spec['paypal'] ?? '' ),
			'BC_REQUEST_STALL'          => (string) ( (int) ( $spec['stall'] ?? 0 ) ),
			'BC_REQUEST_SEED_ACTIONS'   => ! empty( $spec['seed_actions'] ) ? '1' : '',
			'BC_REQUEST_CANARY'         => (string) ( $spec['canary'] ?? '' ),
			'HTTP_HOST'                 => 'hall-a.' . BC_FIXTURE_DOMAIN,
			'REQUEST_URI'               => $path . ( '' === $query ? '' : '?' . $query ),
			'QUERY_STRING'              => $query,
			'REQUEST_METHOD'            => '' === $body ? 'GET' : 'POST',
			'SCRIPT_NAME'               => (string) ( $spec['php_self'] ?? '/index.php' ),
			'PHP_SELF'                  => (string) ( $spec['php_self'] ?? '/index.php' ),
			'SCRIPT_FILENAME'           => dirname( __DIR__ ) . '/tests/hall-commerce-access-request.php',
		];
		if ( '' !== $body ) {
			$env['CONTENT_TYPE']   = 'application/x-www-form-urlencoded';
			$env['CONTENT_LENGTH'] = (string) strlen( $body );
		}

		return $this->run_process(
			[ $this->php_cgi, dirname( __DIR__ ) . '/tests/hall-commerce-access-request.php' ],
			$env,
			$body,
			(float) ( $spec['deadline'] ?? 120.0 )
		);
	}

	/**
	 * Start, feed, drain, bound and reap one child process.
	 *
	 * Non-blocking reads with a deadline, because a hung child must fail the
	 * suite rather than hang it: stream_get_contents() on a blocking pipe would
	 * wait forever, and so would proc_close() on a process that never exits.
	 * Pipes and the process handle are released on every path.
	 *
	 * @param array<int,string>    $command
	 * @param array<string,string> $env
	 * @return array{status:int,headers:array<string,string>,report:array<string,mixed>,exit:int,stderr:string,raw:string,timed_out:bool}
	 */
	private function run_process( array $command, array $env, string $stdin, float $seconds = 120.0 ): array {
		$descriptors = [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$process     = proc_open( $command, $descriptors, $pipes, dirname( __DIR__ ), $env );
		if ( ! is_resource( $process ) ) {
			$this->abort( 'a request worker could not be started.' );
		}

		$out            = '';
		$err            = '';
		$timed_out      = false;
		$exit_confirmed = true;
		$unreaped_pid   = 0;

		try {
			if ( '' !== $stdin ) {
				fwrite( $pipes[0], $stdin );
			}
			fclose( $pipes[0] );
			unset( $pipes[0] );

			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );

			$deadline = microtime( true ) + $seconds;
			while ( microtime( true ) < $deadline ) {
				$out .= (string) stream_get_contents( $pipes[1] );
				$err .= (string) stream_get_contents( $pipes[2] );

				$status = proc_get_status( $process );
				if ( is_array( $status ) && false === $status['running'] ) {
					// Drain whatever landed between the last read and the exit.
					$out .= (string) stream_get_contents( $pipes[1] );
					$err .= (string) stream_get_contents( $pipes[2] );
					break;
				}
				usleep( 10000 );
			}

			$status = proc_get_status( $process );
			if ( is_array( $status ) && true === $status['running'] ) {
				$timed_out = true;
				$pid       = (int) $status['pid'];

				/*
				 * Escalate, then CONFIRM. SIGKILL is uncatchable but not
				 * instantaneous, and proc_close() waits for a child that has not
				 * exited — so the wait happens here, bounded, and the reap only
				 * happens once exit is confirmed. A child that still cannot be
				 * confirmed is recorded as a failure and deliberately NOT
				 * proc_close()d, because that call is the unbounded one.
				 */
				foreach ( [ defined( 'SIGTERM' ) ? SIGTERM : 15, defined( 'SIGKILL' ) ? SIGKILL : 9 ] as $signal ) {
					proc_terminate( $process, $signal );
					$grace = microtime( true ) + 5.0;
					while ( microtime( true ) < $grace ) {
						$out .= (string) stream_get_contents( $pipes[1] );
						$err .= (string) stream_get_contents( $pipes[2] );
						$status = proc_get_status( $process );
						if ( is_array( $status ) && false === $status['running'] ) {
							break 2;
						}
						usleep( 20000 );
					}
				}

				$status = proc_get_status( $process );
				$exit_confirmed = is_array( $status ) && false === $status['running'];
				if ( ! $exit_confirmed ) {
					$unreaped_pid = $pid;
				}
			}

			// Final drain, after the process has stopped producing.
			$out .= (string) stream_get_contents( $pipes[1] );
			$err .= (string) stream_get_contents( $pipes[2] );
		} finally {
			foreach ( $pipes as $pipe ) {
				if ( is_resource( $pipe ) ) {
					fclose( $pipe );
				}
			}
			// Reap ONLY after confirmed exit; otherwise the handle is left for
			// the operator named in the failure rather than blocking here.
			$exit = $exit_confirmed ? (int) proc_close( $process ) : -1;
		}

		$http_status = 200;
		$headers     = [];
		$split       = preg_split( "/\r?\n\r?\n/", $out, 2 );
		foreach ( preg_split( "/\r?\n/", (string) ( $split[0] ?? '' ) ) as $line ) {
			if ( 1 === preg_match( '/^([A-Za-z0-9\-]+):\s*(.*)$/', (string) $line, $m ) ) {
				$headers[ strtolower( $m[1] ) ] = $m[2];
			}
		}
		if ( isset( $headers['status'] ) && 1 === preg_match( '/^(\d{3})/', $headers['status'], $m ) ) {
			$http_status = (int) $m[1];
		}

		$report = [];
		if ( 1 === preg_match( '/--BC-REPORT--(.*?)--END--/s', $out, $m ) ) {
			$decoded = json_decode( $m[1], true );
			$report  = is_array( $decoded ) ? $decoded : [];
		}

		return [
			'status'    => $http_status,
			'headers'   => $headers,
			'report'    => $report,
			'exit'      => (int) $exit,
			'stderr'    => $err,
			'raw'       => $out,
			'timed_out' => $timed_out,
			'exit_confirmed' => $exit_confirmed,
			'unreaped_pid'   => $unreaped_pid,
		];
	}

	/**
	 * No child request may reach a host outside the fixture.
	 *
	 * Action Scheduler legitimately dispatches a follow-up request to the site's
	 * OWN admin-ajax when it finishes a queue, so "zero attempts" would be the
	 * wrong assertion for the runner. What must hold is that nothing addressed a
	 * third party, and the fixture blocked every attempt regardless.
	 *
	 * @param array<string,mixed> $run
	 */
	private function assert_no_external_http( array $run, string $label ): void {
		$external = [];
		foreach ( (array) ( $run['report']['http_urls'] ?? [] ) as $url ) {
			if ( ! $this->is_fixture_host( (string) $url ) ) {
				$external[] = (string) $url;
			}
		}

		$this->assert_true(
			[] === $external,
			"{$label} addressed no host outside the fixture" . ( [] === $external ? '' : ': ' . implode( ', ', $external ) )
		);
	}

	/**
	 * Does this URL address one of the fixture's own hosts, EXACTLY?
	 *
	 * A substring test would accept
	 * `hall-a.fixture.bingocommerce.test.attacker.example`, which is a different
	 * registrable domain entirely. The comparison is therefore against an exact
	 * allowlist of the two hosts this fixture serves, case-folded, with no
	 * userinfo and no port.
	 */
	private function is_fixture_host( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		return in_array( $host, [ 'hall-a.' . BC_FIXTURE_DOMAIN, BC_FIXTURE_DOMAIN ], true );
	}

	/** The host test must reject a lookalike, not merely accept the real thing. */
	private function test_fixture_host_matching(): void {
		$accepted = [
			'http://hall-a.' . BC_FIXTURE_DOMAIN . '/wp-admin/admin-ajax.php',
			'https://HALL-A.' . strtoupper( BC_FIXTURE_DOMAIN ) . '/x',
			'http://' . BC_FIXTURE_DOMAIN . '/',
		];
		foreach ( $accepted as $url ) {
			$this->assert_true( $this->is_fixture_host( $url ), "the fixture's own host is recognised: {$url}" );
		}

		$rejected = [
			'https://hall-a.' . BC_FIXTURE_DOMAIN . '.attacker.example/x',
			'https://attacker.example/hall-a.' . BC_FIXTURE_DOMAIN . '/x',
			'https://evil.example/?h=hall-a.' . BC_FIXTURE_DOMAIN,
			'https://hall-a.' . BC_FIXTURE_DOMAIN . '@attacker.example/x',
			'https://attacker.example/',
			'https://www.paypal.com/cgi-bin/webscr',
			'not a url at all',
		];
		foreach ( $rejected as $url ) {
			$this->assert_true( ! $this->is_fixture_host( $url ), "a lookalike host is NOT treated as the fixture: {$url}" );
		}
	}

	/**
	 * A worker result is only usable if the process behaved.
	 *
	 * @param array<string,mixed> $run
	 */
	private function assert_worker_clean( array $run, string $label, bool $refused = false ): void {
		$this->assert_true( false === $run['timed_out'], "{$label}: the worker finished within its deadline" );
		$this->assert_true( true === $run['exit_confirmed'], "{$label}: the worker's exit was confirmed before reaping" );

		// A refusal ends with exit(); an admitted request ends with exit(0).
		$this->assert_true(
			0 === (int) $run['exit'],
			"{$label}: the worker exited 0 (got {$run['exit']})"
		);

		$this->assert_true(
			'' === trim( (string) $run['stderr'] ),
			"{$label}: the worker wrote nothing to stderr" . ( '' === trim( (string) $run['stderr'] ) ? '' : ' — ' . substr( trim( (string) $run['stderr'] ), 0, 300 ) )
		);

		$noise = [];
		foreach ( [ 'Fatal error', 'Parse error', 'Warning:', 'Notice:', 'Deprecated:', 'WordPress database error' ] as $needle ) {
			if ( false !== strpos( (string) $run['raw'], $needle ) ) {
				$noise[] = $needle;
			}
		}
		$this->assert_true( [] === $noise, "{$label}: the worker produced no PHP or database errors (" . implode( ', ', $noise ) . ')' );

		$this->assert_true( [] !== $run['report'], "{$label}: the worker returned a structured report" );

		if ( ! $refused ) {
			$this->assert_true(
				'continued' === (string) ( $run['report']['outcome'] ?? '' )
					|| 'dispatched_wp' === (string) ( $run['report']['outcome'] ?? '' )
					|| 'dispatched_ajax' === (string) ( $run['report']['outcome'] ?? '' ),
				"{$label}: the worker ran to completion (outcome " . (string) ( $run['report']['outcome'] ?? '?' ) . ')'
			);
		}
	}

	/* ------------------------------------------------------------------ *
	 * Fixture-global baseline
	 * ------------------------------------------------------------------ */

	/**
	 * Everything outside this Hall that the suite must hand back unchanged.
	 *
	 * `active_sitewide_plugins` needs particular care. The fixture REWRITES that
	 * row on every boot from its environment, so each WooCommerce-enabled child
	 * leaves WooCommerce in it — residue this suite created and must undo. The
	 * baseline is therefore the raw stored value as it stands once THIS process
	 * has booted, which is also the value any later non-WooCommerce boot
	 * produces; teardown writes exactly that back.
	 */
	private function capture_baseline(): void {
		$this->baseline = [
			'active_sitewide_plugins' => $this->raw_sitemeta( 'active_sitewide_plugins' ),
			/*
			 * This suite creates and deletes one fixture user, and WordPress
			 * recomputes the network user count on its own throttled schedule —
			 * so the stored row may or may not have caught up by teardown. Once
			 * the user is gone the baseline IS the true count again, so it is
			 * restored rather than excluded from the comparison.
			 */
			'user_count'              => $this->raw_sitemeta( 'user_count' ),
			'sitemeta'                => $this->capture_sitemeta(),
			'users'                   => $this->capture(
				"SELECT ID, user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name FROM {$GLOBALS['wpdb']->users} ORDER BY ID"
			),
			'usermeta'                => $this->capture(
				"SELECT user_id, meta_key, meta_value FROM {$GLOBALS['wpdb']->usermeta} ORDER BY user_id, meta_key, meta_value"
			),
			'main_options'            => $this->capture(
				"SELECT option_name, option_value, autoload FROM {$GLOBALS['wpdb']->base_prefix}options ORDER BY option_name"
			),
		];

		foreach ( $this->baseline as $key => $entry ) {
			if ( in_array( $key, [ 'active_sitewide_plugins', 'user_count' ], true ) ) {
				$this->assert_true( is_string( $entry ) || null === $entry, "baseline: {$key} was readable" );
				continue;
			}
			$this->assert_true( true === ( $entry['ok'] ?? false ), "baseline: {$key} was readable" );
		}
	}

	/**
	 * Run one baseline query, recording whether it actually succeeded.
	 *
	 * A failed read must never compare equal to another failed read, so the
	 * success flag travels with the data.
	 *
	 * @return array{ok:bool,data:string}
	 */
	private function capture( string $sql ): array {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		try {
			$rows  = $wpdb->get_results( $sql, ARRAY_A );
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return [ 'ok' => false, 'data' => '' ];
		} finally {
			$wpdb->suppress_errors( $previous );
		}

		if ( '' !== $error || ! is_array( $rows ) ) {
			return [ 'ok' => false, 'data' => '' ];
		}

		return [ 'ok' => true, 'data' => (string) wp_json_encode( $rows ) ];
	}

	/** @return array{ok:bool,data:string} */
	private function capture_sitemeta(): array {
		global $wpdb;

		return $this->capture(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->sitemeta}
				 WHERE meta_key NOT IN (%s)
				   AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s
				 ORDER BY meta_key, meta_value",
				ProvisioningMigrations::VERSION_OPTION,
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);
	}

	/** The raw stored sitemeta value, or null when it cannot be read. */
	private function raw_sitemeta( string $key ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1", get_current_network_id(), $key )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return null === $value ? null : (string) $value;
	}

	/** Compare a captured surface against its baseline, failing on a bad read. */
	private function assert_baseline_restored( string $key, callable $now ): void {
		$before = $this->baseline[ $key ] ?? null;
		$after  = $now();

		if ( ! is_array( $before ) || ! is_array( $after ) ) {
			$this->assert_true( false, "teardown: the {$key} capture is missing" );

			return;
		}
		if ( true !== ( $before['ok'] ?? false ) || true !== ( $after['ok'] ?? false ) ) {
			$this->assert_true( false, "teardown: the {$key} capture could not be read, so restoration is UNPROVEN" );

			return;
		}

		$this->assert_true(
			$before['data'] === $after['data'],
			"teardown: {$key} is byte-identical to the baseline"
				. ( $before['data'] === $after['data'] ? '' : ' — ' . $this->describe_difference( $before['data'], $after['data'] ) )
		);
	}

	/**
	 * Name what changed between two captures, so a failure identifies itself.
	 *
	 * A bare "not byte-identical" tells the next reader nothing; the rows that
	 * appeared, vanished or changed are what they need.
	 */
	private function describe_difference( string $before, string $after ): string {
		$decode = static function ( string $json ): array {
			$rows = json_decode( $json, true );
			$out  = [];
			foreach ( is_array( $rows ) ? $rows : [] as $i => $row ) {
				$key         = is_array( $row ) ? implode( '|', array_slice( array_values( $row ), 0, 1 ) ) : (string) $i;
				$out[ $key ] = is_array( $row ) ? (string) wp_json_encode( $row ) : '';
			}

			return $out;
		};

		$a       = $decode( $before );
		$b       = $decode( $after );
		$changes = [];

		foreach ( $b as $key => $value ) {
			if ( ! array_key_exists( $key, $a ) ) {
				$changes[] = "+{$key}";
			} elseif ( $a[ $key ] !== $value ) {
				$changes[] = "~{$key}";
			}
		}
		foreach ( $a as $key => $value ) {
			if ( ! array_key_exists( $key, $b ) ) {
				$changes[] = "-{$key}";
			}
		}

		return [] === $changes ? 'row ORDER or count differs' : implode( ', ', array_slice( $changes, 0, 8 ) );
	}

	/* ------------------------------------------------------------------ *
	 * Cleanup and helpers
	 * ------------------------------------------------------------------ */

	private function teardown(): void {
		// Rows this suite created, removed deliberately and verified gone BEFORE
		// the table drop would have taken them along anyway.
		if ( $this->owns_hall_tables ) {
			$this->fixture_operation( self::OP_DELETE_FIXTURE_ROWS );
			$this->assert_same( 0, $this->fixture_action_count(), 'the fixture-only queued actions were removed' );
			$this->assert_same( [], $this->canary_runs(), 'the canary this suite wrote was removed' );
		}

		if ( $this->other_user > 0 ) {
			$removed = $this->fixture_operation( self::OP_DELETE_USER, [ 'user_id' => $this->other_user ] );
			$this->assert_true( $removed, 'the fixture user was deleted through the guard' );
			if ( $removed ) {
				$this->other_user = 0;
			}
		}

		if ( $this->owns_hall_tables ) {
			$this->fixture_operation( self::OP_DROP_HALL_TABLES );
		}
		if ( $this->owns_state_table ) {
			$this->fixture_operation( self::OP_DROP_STATE_TABLE );
			// The v2 migration installs BOTH tables, so a run that owns one owns
			// the other; the version goes with them.
			$this->fixture_operation( self::OP_DROP_OPERATIONS_TABLE );
			$this->fixture_operation( self::OP_DELETE_VERSION );

			$this->assert_true(
				! $this->table_exists( ProvisioningMigrations::operations_table() ),
				'teardown: the provisioning operations table was removed'
			);
			$this->assert_true(
				! $this->table_exists( ProvisioningMigrations::state_table() ),
				'teardown: the commerce-state table was removed'
			);
		}

		// The fixture rewrites this row on every child boot, so the last
		// WooCommerce-enabled child left WooCommerce in it. Put it back.
		if ( is_string( $this->baseline['active_sitewide_plugins'] ?? null ) ) {
			$this->fixture_operation(
				self::OP_RESTORE_SITEMETA,
				[ 'key' => 'active_sitewide_plugins', 'value' => $this->baseline['active_sitewide_plugins'] ]
			);
			$this->assert_same(
				$this->baseline['active_sitewide_plugins'],
				$this->raw_sitemeta( 'active_sitewide_plugins' ),
				'teardown: active_sitewide_plugins is byte-identical to the baseline'
			);
			$this->assert_true(
				false === strpos( (string) $this->raw_sitemeta( 'active_sitewide_plugins' ), 'woocommerce/woocommerce.php' ),
				'  and no longer lists WooCommerce'
			);
		} else {
			$this->assert_true( false, 'teardown: no readable baseline for active_sitewide_plugins, so restoration is UNPROVEN' );
		}

		// The network user count, put back now that this suite's user is gone.
		if ( is_string( $this->baseline['user_count'] ?? null ) ) {
			$this->fixture_operation(
				self::OP_RESTORE_SITEMETA,
				[ 'key' => 'user_count', 'value' => $this->baseline['user_count'] ]
			);
			$this->assert_same(
				$this->baseline['user_count'],
				$this->raw_sitemeta( 'user_count' ),
				'teardown: the network user count is back to its baseline'
			);
		}

		$leftovers = $this->hall_tables();
		$this->assert_true( [] === $leftovers, 'cleanup left no Hall table behind: ' . implode( ', ', array_slice( $leftovers, 0, 5 ) ) );
		$this->assert_true(
			! $this->table_exists( ProvisioningMigrations::state_table() )
				&& 0 === (int) get_site_option( ProvisioningMigrations::VERSION_OPTION, 0 ),
			'cleanup left no Commerce state schema behind'
		);

		global $wpdb;
		$remaining = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'fixture\\_%'" );
		$this->assert_true(
			null !== $remaining && '' === (string) $wpdb->last_error && 0 === (int) $remaining,
			'cleanup left no fixture user behind, and the check itself was readable'
		);

		// Fixture-global surfaces, compared with VALUES against the baseline.
		$this->assert_baseline_restored( 'sitemeta', fn(): array => $this->capture_sitemeta() );
		$this->assert_baseline_restored(
			'users',
			fn(): array => $this->capture( "SELECT ID, user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name FROM {$GLOBALS['wpdb']->users} ORDER BY ID" )
		);
		$this->assert_baseline_restored(
			'usermeta',
			fn(): array => $this->capture( "SELECT user_id, meta_key, meta_value FROM {$GLOBALS['wpdb']->usermeta} ORDER BY user_id, meta_key, meta_value" )
		);
		$this->assert_baseline_restored(
			'main_options',
			fn(): array => $this->capture( "SELECT option_name, option_value, autoload FROM {$GLOBALS['wpdb']->base_prefix}options ORDER BY option_name" )
		);
	}

	/**
	 * Every table belonging to this Hall, discovered rather than assumed.
	 *
	 * @return array<int,string>
	 */
	private function hall_tables(): array {
		global $wpdb;

		$prefix = $wpdb->base_prefix . $this->hall . '_';
		$rows   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s ORDER BY table_name',
				BC_Fixture::$database,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			$this->abort( 'the Hall table list could not be read.' );
		}

		return array_map( 'strval', $rows );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== (string) $wpdb->last_error ) {
			$this->abort( "table discovery for {$table} could not be read." );
		}

		return null !== $found;
	}

	private function locate_php_cgi(): string {
		$candidates = [ dirname( PHP_BINARY ) . '/php-cgi' ];
		$found      = trim( (string) shell_exec( 'command -v php-cgi 2>/dev/null' ) );
		if ( '' !== $found ) {
			$candidates[] = $found;
		}
		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	private function abort( string $reason ): void {
		fwrite( STDERR, "HARNESS GUARD: {$reason}\n" );
		exit( 1 );
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	private function assert_same( $expected, $actual, string $label ): void {
		$this->assert_true(
			$expected === $actual,
			$label . ( $expected === $actual ? '' : ' (got ' . var_export( $actual, true ) . ')' )
		);
	}

	private function assert_true( bool $condition, string $label ): void {
		$this->assertions++;
		if ( ! $condition ) {
			$this->failures++;
			echo "FAIL: {$label}\n";

			return;
		}
		// Opt-in, because a passing run should be quiet by default; set
		// BC_VERBOSE=1 to have each satisfied assertion state itself.
		if ( '1' === (string) getenv( 'BC_VERBOSE' ) ) {
			echo "pass: {$label}\n";
		}
	}

	private function report(): void {
		$passed = $this->assertions - $this->failures;
		echo "\n{$passed}/{$this->assertions} assertions passed";
		echo 0 === $this->failures ? " — PASS\n" : " — {$this->failures} FAILED\n";
		exit( 0 === $this->failures ? 0 : 1 );
	}
}

( new HallCommerceHandlersTest() )->run();
