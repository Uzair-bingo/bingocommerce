<?php

namespace BingoCommerce\Sessions;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Manager's "Manage Session Dates" screen (§15 workflow items 4-9), reached
 * from Sessions from Portal's "Add to Store" / "Manage Dates" links rather than
 * from the normal navigation menu — see `add_menu()`.
 *
 * PRESENTATION AND REQUEST MARSHALLING ONLY. Every scheduling rule —
 * tomorrow-or-later dates, minute precision, timezone capture and immutability,
 * weekday bounds, Package assignability, the template-guarded compare-and-swap,
 * superseded-token cleanup and the whole of reconciliation — lives in
 * SessionScheduleService and is never re-implemented, re-validated or
 * second-guessed here. This class reads request values, hands them to the
 * service, and renders whatever the service says.
 *
 * EVERY MUTATION IS AN `admin_post_` HANDLER, AND THE RENDER CALLBACK MUTATES
 * NOTHING. This follows the precedent recorded at
 * `…/PortalSyncAdmin.php:150-158`: by the time a page-render callback runs,
 * WordPress has already emitted admin output, so a redirect from there is
 * impossible. An earlier version handled create, edit, archive and restore in
 * the render callback, which left the browser sitting on a POST result with no
 * way to redirect away from it — so a refresh re-submitted the mutation and
 * could create a SECOND schedule from one Manager's F5. Both handlers now
 * finish with a 303 redirect, and the screen renders its notices from
 * allowlisted query arguments.
 *
 * HANDLER ORDER IS FIXED (§13.1, §16 M106): POST-only, then tenancy, then
 * capability, then nonce. The order is not cosmetic. The method gate comes
 * first because `admin-post.php` dispatches GET to the same hook name, so it is
 * what actually makes generation unreachable by link, prefetch or history. The
 * tenancy gate comes before the capability gate because a network-admin or
 * main-site context has no hall whose capability could be meaningful.
 *
 * HALL ISOLATION (§13.1, §16 M105). Every id in a request is read through
 * `SessionAdmin::request_int()` — never `absint()`, which would turn `-5` into
 * `5` and hand a lookup a different, valid-looking id. No id is trusted: each is
 * resolved through the service, whose reads are scoped to this site's own
 * `$wpdb->prefix` tables and gated by `authorize()`. This screen holds no
 * `blog_id`, composes no cross-site query, and cannot reach another hall's
 * schedule even when handed its id.
 */
final class SessionScheduleAdmin {

	public const PAGE_SLUG = 'bingocommerce-session-schedules';

	public const POST_ACTION = 'bc_session_schedule_generate';

	public const POST_ACTION_SAVE = 'bc_session_schedule_save';

	public const POST_ACTION_TIMEZONE = 'bc_session_schedule_timezone';

	private const NONCE_ACTION          = 'bingocommerce_session_schedule_mutation';
	private const NONCE_ACTION_GENERATE = 'bingocommerce_session_schedule_generate';
	private const NONCE_ACTION_TIMEZONE = 'bingocommerce_session_schedule_timezone_';

	/** The §8.6-style outcomes a save may report back through the redirect. */
	private const SAVE_OUTCOMES = [ 'created', 'updated', 'archived', 'restored' ];

	/**
	 * The ONLY refusal codes this screen ever puts in a URL or renders.
	 *
	 * Fixed and owned here, so nothing from the service's own — larger and
	 * changing — error vocabulary can reach the query string.
	 */
	private const SAVE_ERRORS = [
		'not_saved',
		'unknown_action',
		'not_eligible',
		'template_unavailable',
		'state_changed',
		'not_found',
		'not_active',
		'invalid_time',
		'date_in_past',
		'invalid_date',
		'invalid_mode',
		'no_weekdays',
		'end_before_start',
		'range_too_long',
		'no_timezone',
		'package_unavailable',
		'write_failed',
	];

	/** Service error code -> this screen's fixed code. Unmapped becomes not_saved. */
	private const UI_ERROR_MAP = [
		'template_not_commerce_eligible' => 'not_eligible',
		'schedule_state_changed'         => 'state_changed',
		'schedule_not_found'             => 'not_found',
		'schedule_not_active'            => 'not_active',
		'invalid_start_time'             => 'invalid_time',
		'date_must_be_tomorrow_or_later' => 'date_in_past',
		'historical_date_not_addable'    => 'date_in_past',
		'package_not_assignable'         => 'package_unavailable',
		'package_not_found'              => 'package_unavailable',
		'package_not_active'             => 'package_unavailable',
		'persistence_failed'             => 'write_failed',
		'invalid_weekday_selection'      => 'no_weekdays',
		'invalid_recurrence_range'       => 'end_before_start',
		'recurrence_range_too_long'      => 'range_too_long',
		'invalid_schedule_date'          => 'invalid_date',
		'invalid_schedule_mode'          => 'invalid_mode',
		'hall_timezone_not_named'        => 'no_timezone',
		'invalid_portal_session_id'      => 'not_eligible',
	];

	/**
	 * Which control a refusal belongs BESIDE. '' means the form as a whole.
	 *
	 * A message printed only at the top of a long form makes the Manager hunt
	 * for the control it is about; this is what lets the same message sit next
	 * to the field that produced it.
	 */
	private const UI_ERROR_FIELD = [
		'invalid_time'     => 'start_time',
		'date_in_past'     => 'dates',
		'no_weekdays'      => 'weekdays',
		'end_before_start' => 'recurrence_end',
		'range_too_long'   => 'recurrence_end',
		'package_unavailable' => 'packages',
		'not_eligible'     => 'template',
		'no_timezone'      => '',
	];

	/** The controls an error may be attached to. Anything else renders form-wide. */
	private const SAVE_FIELDS = [ 'start_time', 'dates', 'weekdays', 'recurrence_start', 'recurrence_end', 'packages', 'template', 'mode' ];

	/** How long a rejected form is held so it can be rendered back, in seconds. */
	private const INPUT_TTL = 120;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ], 10 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_date_picker' ], 20 );

		/*
		 * Authenticated handlers only. A `nopriv` twin is deliberately NOT
		 * registered for either: an anonymous POST must fall through to
		 * WordPress's own "action not supported" path rather than reach any
		 * hall logic.
		 *
		 * EVERY mutation is an admin-post handler, not just generation. A page
		 * render callback cannot redirect — by the time it runs WordPress has
		 * already emitted admin output — so a mutation handled there leaves the
		 * browser sitting on a POST result, and a refresh re-submits it. That is
		 * how a single Manager refresh could create a SECOND schedule. Handling
		 * saves here is what makes the 303 below possible at all.
		 */
		add_action( 'admin_post_' . self::POST_ACTION, [ self::class, 'handle_generate' ] );
		add_action( 'admin_post_' . self::POST_ACTION_SAVE, [ self::class, 'handle_save' ] );
		add_action( 'admin_post_' . self::POST_ACTION_TIMEZONE, [ self::class, 'handle_timezone' ] );
	}

	/** Use the bundled admin picker only on this hall's schedule screen. */
	public static function enqueue_date_picker(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page || ! HallContext::is_hall() || ! current_user_can( 'bc_manage_sessions' ) ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-datepicker' );
		// WooCommerce is already a dependency; reuse its local admin picker skin.
		if ( function_exists( 'WC' ) ) {
			wp_enqueue_style( 'jquery-ui-style', WC()->plugin_url() . '/assets/css/jquery-ui/jquery-ui.min.css', [], WC_VERSION );
		}
	}

	/**
	 * Registered, but NOT in the normal Manager navigation.
	 *
	 * The approved flow (§15) reaches this screen through "Add to Store" /
	 * "Manage Dates" on Sessions from Portal, and generation happens
	 * automatically when a schedule is created — a first-time Manager never
	 * needs to find this page in a menu at all. `add_submenu_page()` still
	 * registers the page hook, the capability check and the render callback
	 * exactly as before, so `admin.php?page=…` keeps working, authorized
	 * exactly as it always was; `remove_submenu_page()` only strips the menu
	 * ROW WordPress would otherwise print. This is the standard WordPress
	 * technique for a fully-authorized page that is reachable by direct link
	 * but not advertised in navigation — nothing about capability checking,
	 * nonce verification or the service layer beneath it changes.
	 */
	public static function add_menu(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		add_submenu_page(
			SessionAdmin::PAGE_SLUG,
			__( 'Manage Session Dates', 'bingocommerce-sessions' ),
			__( 'Manage Session Dates', 'bingocommerce-sessions' ),
			'bc_manage_sessions',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);

		/*
		 * UNCONDITIONALLY removing this entry from $submenu broke direct
		 * access ("Sorry, you are not allowed to access this page."), because
		 * WordPress's own gate for admin.php?page=X does not use a fixed
		 * capability list — it RE-DERIVES the page's parent and required
		 * capability by searching $submenu for the entry at request time.
		 *
		 * `wp-admin/includes/menu.php` calls user_can_access_admin_page()
		 * after do_action( 'admin_menu' ) has already run. That function
		 * calls get_admin_page_parent(), which resolves a submenu page's
		 * parent SOLELY by walking $submenu looking for
		 * `$submenu_array[2] === $plugin_page`. With the entry already
		 * removed, that search fails, get_admin_page_parent() returns '',
		 * and the hookname user_can_access_admin_page() then looks up in
		 * $_registered_pages never matches the one add_submenu_page() above
		 * actually registered — so the request is refused before render()
		 * ever runs, for every visitor, including one with
		 * bc_manage_sessions. There is no later hook between admin_menu and
		 * that check to defer the removal to, so removal must be skipped
		 * for exactly the one request that needs the entry present to
		 * resolve itself: this page's own.
		 *
		 * Keeping the entry only on this page's own request means the
		 * Manager sees it highlighted in the sidebar while actually on
		 * Manage Session Dates (harmless — they are already there) and it
		 * stays absent on every other screen, which is the only case
		 * "hidden from navigation" was ever about.
		 */
		$requested_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $requested_page ) {
			remove_submenu_page( SessionAdmin::PAGE_SLUG, self::PAGE_SLUG );
		}
	}

	/** Set only the current hall's option; never create or rewrite a schedule. */
	public static function handle_timezone(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Saving the hall timezone requires a POST request.', 'bingocommerce-sessions' ), '', [ 'response' => 405 ] );
		}
		if ( is_network_admin() || ! HallContext::is_hall() ) {
			wp_die( esc_html__( 'The hall timezone can only be set inside a hall site.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}
		if ( ! current_user_can( 'bc_manage_sessions' ) ) {
			wp_die( esc_html__( 'You are not authorized to set the timezone for this hall.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}
		// Bind the dedicated nonce to this site as well as the current user.
		check_admin_referer( self::NONCE_ACTION_TIMEZONE . get_current_blog_id() );

		$portal_session_id = SessionAdmin::request_int( wp_unslash( $_POST['portal_session_id'] ?? null ) );
		$template = $portal_session_id > 0 ? ( new PortalTemplateGateway() )->get( $portal_session_id ) : null;
		if ( ! is_array( $template ) || ! PortalTemplateGateway::is_commerce_eligible( $template ) ) {
			wp_die( esc_html__( 'Choose an eligible session from this hall\'s Sessions from Portal screen.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}

		$submitted = wp_unslash( $_POST['timezone_string'] ?? '' );
		$zone = ScheduleTimezone::validate_zone( is_string( $submitted ) ? $submitted : '' );
		if ( is_wp_error( $zone ) ) {
			wp_die( esc_html__( 'Choose a named timezone such as America/New_York, not a UTC offset.', 'bingocommerce-sessions' ), '', [ 'response' => 400 ] );
		}

		// update_option() returns false for an unchanged value, too. A repeated
		// valid POST may continue, but a failed write must never report success.
		if ( ( $zone !== get_option( 'timezone_string', '' ) && ! update_option( 'timezone_string', $zone ) )
			|| $zone !== get_option( 'timezone_string', '' ) ) {
			wp_die( esc_html__( 'The hall timezone could not be saved. Please try again.', 'bingocommerce-sessions' ), '', [ 'response' => 500 ] );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::PAGE_SLUG, 'action' => 'new', 'portal_session_id' => $portal_session_id ],
				admin_url( 'admin.php' )
			),
			303
		);
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Generation — admin-post.php handler. Never renders; redirects or dies.
	 * ------------------------------------------------------------------ */

	public static function handle_generate(): void {
		// 1. POST only.
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Generating occurrences requires a POST request.', 'bingocommerce-sessions' ), '', [ 'response' => 405 ] );
		}

		// 2. Tenancy before authority.
		if ( ! HallContext::is_hall() ) {
			wp_die( esc_html__( 'Commerce schedules are only available inside a hall site.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}

		// 3. Capability.
		if ( ! is_super_admin() && ! current_user_can( 'bc_manage_sessions' ) ) {
			wp_die( esc_html__( 'You are not authorized to generate occurrences for this hall.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}

		// 4. Nonce. check_admin_referer() dies on failure.
		check_admin_referer( self::NONCE_ACTION_GENERATE );

		// 5. Only now is a request value read, and it is resolved by the
		// service against this hall's own tables.
		$schedule_id = SessionAdmin::request_int( wp_unslash( $_POST['schedule_id'] ?? null ) );

		$result = ( new SessionScheduleService() )->generate( $schedule_id );

		$args = [ 'page' => self::PAGE_SLUG ];
		if ( $schedule_id > 0 ) {
			$args['edit'] = $schedule_id;
		}
		if ( is_wp_error( $result ) ) {
			$args['bc_generated'] = 'error';
			$args['bc_code']      = $result->get_error_code();
		} else {
			$args['bc_generated'] = $result->status;
			if ( null !== $result->failure_category ) {
				$args['bc_code'] = $result->failure_category;
				if ( 'preflight_conflict' === $result->failure_category && in_array( 'protected_time_conflict', array_column( $result->blockers, 'kind' ), true ) ) {
					$args['bc_code'] = 'protected_time_conflict';
				}
			}
			$args['bc_counts'] = self::encode_counts( $result->reported_counts() );
		}

		// PRG with 303, so a refresh re-renders the notice instead of
		// re-posting the generation.
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 303 );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Create / edit / archive / restore — admin-post.php handler.
	 * ------------------------------------------------------------------ */

	public static function handle_save(): void {
		// The same four gates, in the same order, as generation (§13.1).
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Saving a schedule requires a POST request.', 'bingocommerce-sessions' ), '', [ 'response' => 405 ] );
		}
		if ( ! HallContext::is_hall() ) {
			wp_die( esc_html__( 'Session schedules are only available inside a hall site.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}
		if ( ! is_super_admin() && ! current_user_can( 'bc_manage_sessions' ) ) {
			wp_die( esc_html__( 'You are not authorized to manage session schedules for this hall.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION );

		$post   = wp_unslash( $_POST );
		$action = sanitize_key( (string) ( $post['bc_action'] ?? '' ) );
		$result = self::handle_request( $post, new SessionScheduleService() );

		/*
		 * SUCCESS IS `status === ok`, AND NOTHING ELSE.
		 *
		 * A refusal does not have to be a WP_Error. `create()` returns a
		 * SessionScheduleResult carrying the INERT header when its
		 * template-guarded CAS affects zero rows (§3.8 step 5) — status `failed`
		 * or `schedule_state_changed`, a schedule that generates nothing. An
		 * earlier version treated every SessionScheduleResult as success and
		 * told the Manager the schedule was `created`, which is the one thing it
		 * was not. Anything that is not STATUS_OK is an error here.
		 *
		 * ONLY ALLOWLISTED NOTICE DATA SURVIVES THE REDIRECT. The outcome is one
		 * of THIS FILE's fixed words, and the error is one of its fixed UI
		 * codes — never a service error code passed through, which would put a
		 * vocabulary this screen does not own into a URL and then onto a page.
		 */
		$args = [ 'page' => self::PAGE_SLUG ];

		if ( $result instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $result->status ) {
			if ( 'create' === $action ) {
				// §7 orchestration lives HERE, not as a second implementation.
				$args += self::create_and_generate( $result );
			} else {
				$args['bc_saved'] = self::outcome_for( $action );
			}
			if ( in_array( $action, [ 'create', 'edit' ], true ) && ! empty( $result->schedule['schedule_id'] ) ) {
				// The success landing must show committed values, not an older rejected draft.
				delete_transient( self::input_key() );
				$args['edit'] = (int) $result->schedule['schedule_id'];
			}
		} else {
			$args['bc_saved'] = 'error';
			$args['bc_error'] = self::ui_error_code( $result );
			$args['bc_field'] = self::ui_error_field( $result, $post );
			$args            += self::return_context( $action, $post, $result );

			// The rejected input is held for ONE render so the Manager gets
			// their form back rather than an empty one (requirement 11). It is
			// user-scoped, short-lived, and never travels in the URL.
			self::remember_input( $post );
		}

		/*
		 * 303, so the follow-up is unambiguously a GET. This is the whole point
		 * of the handler: a refresh re-runs the redirected GET and re-renders
		 * the notice, and CANNOT re-submit the create that produced it.
		 */
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 303 );
		exit;
	}

	/**
	 * §7 create orchestration: creation already succeeded when this runs, so
	 * — and ONLY because it did — generation is now invoked once, automatically,
	 * using the schedule id the CREATE call itself returned.
	 *
	 * NOT A SECOND GENERATION IMPLEMENTATION. This calls the exact same
	 * `SessionScheduleService::generate()` that `handle_generate()` calls for a
	 * manual retry, and nothing here duplicates a single line of
	 * OccurrenceReconciler's phases, its run fence, or its idempotence — a
	 * later manual retry converges the SAME schedule through the SAME service
	 * call, whether this automatic first attempt fully succeeded or not.
	 *
	 * A generation failure or partial result does NOT roll back or hide the
	 * schedule that was just saved: the schedule row is real, and telling the
	 * Manager otherwise would send them looking for something the screen had
	 * just told them did not exist (the same mistake `render_save_notice()`'s
	 * removed "nothing was changed" claim made for a failed create).
	 *
	 * @return array<string,int|string>
	 */
	private static function create_and_generate( SessionScheduleResult $created ): array {
		$schedule_id = (int) ( $created->schedule['schedule_id'] ?? 0 );

		if ( $schedule_id < 1 ) {
			// Cannot happen for a genuine STATUS_OK create, but this screen
			// never assumes a shape it has not checked.
			return [ 'bc_saved' => 'created_partial', 'bc_schedule' => 0 ];
		}

		$generated = ( new SessionScheduleService() )->generate( $schedule_id );

		if ( $generated instanceof SessionScheduleResult && SessionScheduleResult::STATUS_OK === $generated->status ) {
			return [ 'bc_saved' => 'created', 'bc_schedule' => $schedule_id ];
		}

		// Partial or failed generation. The schedule stands; only the notice
		// changes, and it carries safe counts plus the schedule id. The editor
		// offers Update Sessions after successfully reading this definition.
		$counts = $generated instanceof SessionScheduleResult ? $generated->reported_counts() : [];

		return [
			'bc_saved'   => 'created_partial',
			'bc_schedule' => $schedule_id,
			'bc_counts'  => self::encode_counts( $counts ),
		];
	}

	/**
	 * Where a FAILED save puts the Manager, so the retry is one step and cannot
	 * pile up wreckage.
	 *
	 * A FAILED CREATE NEVER GOES BACK TO THE CREATE FORM. When the CAS affects
	 * zero rows the provisional header SURVIVES, inert, holding its
	 * `schedule_id` (§3.8 step 5). Offering Create again would commit a SECOND
	 * inert header for the same template every time the Manager retried.
	 * `edit()` is the documented recovery path for exactly this state — it
	 * fences on `active_definition_token IS NULL` and commits against the
	 * EXISTING header — so the redirect goes to that schedule's Edit screen and
	 * the retry reuses the same id.
	 *
	 * When a failed create left no id to point at, the list is the safe
	 * destination. Not the create form: this screen cannot tell whether an
	 * unidentified header exists, and guessing "it does not" is what produces
	 * duplicates.
	 *
	 * @param array<string,mixed>                   $post
	 * @param SessionScheduleResult|\WP_Error|null  $result
	 * @return array<string,int|string>
	 */
	private static function return_context( string $action, array $post, $result ): array {
		if ( 'edit' === $action ) {
			return [ 'edit' => SessionAdmin::request_int( $post['schedule_id'] ?? null ) ];
		}

		if ( 'create' === $action ) {
			$inert_id = $result instanceof SessionScheduleResult
				? (int) ( $result->schedule['schedule_id'] ?? 0 )
				: 0;

			return $inert_id > 0 ? [ 'edit' => $inert_id ] : [];
		}

		return [];
	}

	/**
	 * The refusal, as one of THIS SCREEN's fixed codes.
	 *
	 * Service error codes are deliberately NOT passed through. They are a
	 * different, larger and changing vocabulary; putting one in a URL makes it
	 * this screen's problem to render, and a code it has never heard of would
	 * either leak into the page or be silently swallowed. Everything unmapped
	 * becomes `not_saved`, which is true of every refusal here.
	 *
	 * @param SessionScheduleResult|\WP_Error|null $result
	 */
	private static function ui_error_code( $result ): string {
		if ( null === $result ) {
			return 'unknown_action';
		}

		if ( $result instanceof SessionScheduleResult ) {
			if ( SessionScheduleResult::STATUS_SCHEDULE_STATE_CHANGED === $result->status ) {
				return 'state_changed';
			}

			// A failed result names its cause in a blocker when it has one.
			foreach ( $result->blockers as $blocker ) {
				$kind = (string) ( $blocker['kind'] ?? '' );
				if ( 'template_not_commerce_eligible' === $kind ) {
					return 'not_eligible';
				}
				if ( 'template_unavailable' === $kind ) {
					return 'template_unavailable';
				}
			}

			return 'eligibility_failed' === (string) $result->failure_category ? 'not_eligible' : 'not_saved';
		}

		if ( is_wp_error( $result ) ) {
			// A refusal this screen raised itself already carries its own code.
			$data = $result->get_error_data();
			$own  = is_array( $data ) ? (string) ( $data['bc_ui_code'] ?? '' ) : '';
			if ( '' !== $own && in_array( $own, self::SAVE_ERRORS, true ) ) {
				return $own;
			}

			$mapped = self::UI_ERROR_MAP[ (string) $result->get_error_code() ] ?? 'not_saved';

			return in_array( $mapped, self::SAVE_ERRORS, true ) ? $mapped : 'not_saved';
		}

		return 'not_saved';
	}

	private static function outcome_for( string $action ): string {
		switch ( $action ) {
			case 'create':
				return 'created';
			case 'edit':
				return 'updated';
			case 'archive':
				return 'archived';
			case 'restore':
				return 'restored';
		}

		return 'updated';
	}

	/* ------------------------------------------------------------------ *
	 * Screen
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! HallContext::is_hall() ) {
			wp_die( esc_html__( 'Session schedules are only available inside a hall site.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}
		if ( ! is_super_admin() && ! current_user_can( 'bc_manage_sessions' ) ) {
			wp_die( esc_html__( 'You are not authorized to manage session schedules for this hall.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}

		/*
		 * NO MUTATION RUNS HERE. Every save goes through handle_save(), which
		 * redirects; this callback only ever renders, so a refresh of whatever
		 * the browser is showing can never repeat a create, edit, archive or
		 * restore.
		 */
		$service = new SessionScheduleService();

		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) );
		if ( ! in_array( $status, [ 'all', 'active', 'archived' ], true ) ) {
			$status = 'all';
		}

		$action  = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		$editing = SessionAdmin::request_int( wp_unslash( $_GET['edit'] ?? null ) );
		$is_form = $editing > 0 || 'new' === $action;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Manage Session Dates', 'bingocommerce-sessions' ); ?></h1>
			<p>
				<?php echo esc_html__( 'The dates, time and Bingo Packages for one Portal session. New Bingo Sessions are created OFF; turn each one on from Bingo Sessions when it is ready to sell.', 'bingocommerce-sessions' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( add_query_arg( [ 'page' => SessionTemplateAdmin::PAGE_SLUG ], admin_url( 'admin.php' ) ) ); ?>">
					<?php echo esc_html__( '&laquo; Back to Sessions from Portal', 'bingocommerce-sessions' ); ?>
				</a>
			</p>
			<?php if ( $is_form ) : ?>
				<p>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html__( 'Back to all schedules', 'bingocommerce-sessions' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php self::render_generation_notice(); ?>
			<?php self::render_save_notice(); ?>

			<?php if ( $editing > 0 ) : ?>
				<?php self::render_edit_form( $service, $editing ); ?>
			<?php elseif ( 'new' === $action ) : ?>
				<?php self::render_create_form( SessionAdmin::request_int( wp_unslash( $_GET['portal_session_id'] ?? null ) ) ); ?>
			<?php else : ?>
				<?php $schedules = $service->list_for_administration( $status ); ?>
				<h2><?php echo esc_html__( 'Every session schedule in this hall', 'bingocommerce-sessions' ); ?></h2>
				<?php if ( is_wp_error( $schedules ) ) : ?>
					<div class="notice notice-error">
						<p><?php echo esc_html__( 'This hall\'s commerce schedules could not be read. Do not assume it is empty.', 'bingocommerce-sessions' ); ?></p>
						<p><?php echo esc_html( SessionAdmin::error_message( $schedules ) ); ?></p>
					</div>
				<?php else : ?>
					<?php self::render_table( $schedules ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Route one posted mutation to the service.
	 *
	 * NO SCHEDULING RULE IS APPLIED HERE. The only judgement this method makes
	 * is about the SCREEN'S OWN INPUT FORMAT — the US dates it displays, which
	 * the service has never heard of — and about which mode's fields are live.
	 * Everything else is the service's.
	 *
	 * @param array<string,mixed> $post
	 * @return SessionScheduleResult|\WP_Error|null
	 */
	private static function handle_request( array $post, SessionScheduleService $service ) {
		$action      = sanitize_key( (string) ( $post['bc_action'] ?? '' ) );
		$schedule_id = SessionAdmin::request_int( $post['schedule_id'] ?? null );

		if ( 'archive' === $action ) {
			return $service->archive( $schedule_id );
		}
		if ( 'restore' === $action ) {
			return $service->restore( $schedule_id );
		}
		if ( 'create' !== $action && 'edit' !== $action ) {
			return null;
		}

		$definition = self::marshal_definition( $post );
		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		if ( 'create' === $action ) {
			return $service->create(
				SessionAdmin::request_int( $post['portal_session_id'] ?? null ),
				$definition['mode'],
				$definition['start_time'],
				$definition['dates'],
				$definition['packages'],
				$definition['recurrence_start'],
				$definition['recurrence_end'],
				$definition['weekday_mask']
			);
		}

		return $service->edit(
			$schedule_id,
			$definition['mode'],
			$definition['start_time'],
			$definition['dates'],
			$definition['packages'],
			$definition['recurrence_start'],
			$definition['recurrence_end'],
			$definition['weekday_mask']
		);
	}

	/**
	 * Turn one posted form into the service's own vocabulary.
	 *
	 * ONLY THE ACTIVE MODE'S FIELDS ARE PASSED ON. A form carries controls for
	 * both modes, and the hidden one keeps whatever was last typed into it.
	 * Sending those values anyway would have the service validate — and refuse
	 * on — a field the Manager cannot even see. So `dates` is empty in weekly
	 * mode, and the weekday mask and bounds are empty in specific-dates mode.
	 *
	 * @param array<string,mixed> $post
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function marshal_definition( array $post ) {
		$mode = self::posted_mode( $post );

		$definition = [
			'mode'             => $mode,
			'start_time'       => trim( (string) ( $post['start_time'] ?? '' ) ),
			'packages'         => self::posted_packages( $post ),
			'dates'            => [],
			'recurrence_start' => '',
			'recurrence_end'   => '',
			'weekday_mask'     => 0,
		];

		if ( ScheduleExpansion::MODE_WEEKLY === $mode ) {
			$start = self::storage_date( $post['recurrence_start'] ?? '', 'recurrence_start' );
			if ( is_wp_error( $start ) ) {
				return $start;
			}
			$end = self::storage_date( $post['recurrence_end'] ?? '', 'recurrence_end' );
			if ( is_wp_error( $end ) ) {
				return $end;
			}

			$definition['recurrence_start'] = $start;
			$definition['recurrence_end']   = $end;
			$definition['weekday_mask']     = self::posted_weekday_mask( $post );

			return $definition;
		}

		$dates = self::posted_dates( $post );
		if ( is_wp_error( $dates ) ) {
			return $dates;
		}
		$definition['dates'] = $dates;

		return $definition;
	}

	/* ------------------------------------------------------------------ *
	 * Request readers. Shape and display format only — never meaning.
	 * ------------------------------------------------------------------ */

	/** @param array<string,mixed> $post */
	private static function posted_mode( array $post ): string {
		// Passed through as text. The service decides whether it is a mode;
		// narrowing it here would silently turn a rejected value into an
		// accepted one.
		return sanitize_key( (string) ( $post['mode'] ?? '' ) );
	}

	/**
	 * The definition's explicit dates: the kept past ones PLUS the new ones,
	 * every one converted from the screen's US format to storage form.
	 *
	 * §8.3 KEEP/DROP is expressed as presence or absence: an unchecked past
	 * date is simply not in `past_dates`, so it never reaches the service.
	 *
	 * @param array<string,mixed> $post
	 * @return array<int,string>|\WP_Error
	 */
	private static function posted_dates( array $post ) {
		$converted = [];

		foreach ( [ 'past_dates', 'future_dates' ] as $field ) {
			foreach ( self::value_list( $post[ $field ] ?? [] ) as $value ) {
				$storage = self::storage_date( $value, 'dates' );
				if ( is_wp_error( $storage ) ) {
					return $storage;
				}
				if ( '' !== $storage ) {
					$converted[] = $storage;
				}
			}
		}

		// Deduplicated AFTER the merge, so a date present in both inputs is one
		// date rather than two, and the service sees the set the Manager sees.
		return array_values( array_unique( $converted ) );
	}

	/**
	 * One input's raw values, trimmed, with blanks removed.
	 *
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	private static function value_list( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\r\n,]+/', $raw ) ?: [];
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$values = [];
		foreach ( $raw as $value ) {
			if ( is_array( $value ) ) {
				// Malformed input, not a date. Dropped rather than stringified
				// into something the service would then have to interpret.
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array<int,int>
	 */
	private static function posted_packages( array $post ): array {
		$raw = $post['package_ids'] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$ids = [];
		foreach ( $raw as $value ) {
			$id = SessionAdmin::request_int( $value );
			if ( 0 !== $id ) {
				// A negative id is KEPT, not dropped: the service must be the
				// thing that refuses it, and a screen that silently discarded
				// it would hide a malformed request instead of failing on it.
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** @param array<string,mixed> $post */
	private static function posted_weekday_mask( array $post ): int {
		$raw = $post['weekdays'] ?? [];
		if ( ! is_array( $raw ) ) {
			return SessionAdmin::request_int( $raw );
		}

		$mask = 0;
		foreach ( $raw as $value ) {
			$bit = SessionAdmin::request_int( $value );
			if ( $bit > 0 ) {
				$mask |= $bit;
			}
		}

		return $mask;
	}

	/* ------------------------------------------------------------------ *
	 * US dates in, canonical dates out (requirement 10)
	 *
	 * The Hall Manager reads and types MM/DD/YYYY. The database stores
	 * YYYY-MM-DD, and every scheduling rule in the service is written against
	 * that form. This boundary is the ONLY place the two meet.
	 * ------------------------------------------------------------------ */

	/**
	 * The example shown in every label and placeholder on this screen.
	 *
	 * Tomorrow, so the example is always a date the screen accepts. A fixed
	 * date would silently become a past one this screen refuses.
	 */
	private static function date_example(): string {
		$tomorrow = self::tomorrow_display();

		return '' === $tomorrow ? 'MM/DD/YYYY' : $tomorrow;
	}

	/**
	 * Basic client-side hinting only — the SAME m/d/Y or n/j/Y shapes
	 * `storage_date()` actually parses, expressed as an HTML `pattern`. This
	 * rejects nothing `storage_date()` would not also reject; it exists so an
	 * obviously malformed value (letters, wrong separators) is flagged before
	 * a round trip, not instead of the server-side parse.
	 */
	private const US_DATE_PATTERN = '\d{1,2}/\d{1,2}/\d{4}';

	/**
	 * One displayed US date as its stored form, or a field error.
	 *
	 * PARSING IS NEVER AMBIGUOUS. The month always comes first, and the parse
	 * is confirmed by formatting the result back and requiring it to match what
	 * was typed. That round-trip is what rejects a date the calendar quietly
	 * rolls over: `02/31/2026` parses to 3 March and would otherwise be stored
	 * as a day the Manager never chose. `strtotime()` is deliberately not used
	 * anywhere here — it reads `01/02/2026` by locale guesswork.
	 *
	 * @param mixed $value
	 * @return string|\WP_Error '' when nothing was typed.
	 */
	private static function storage_date( $value, string $field ) {
		if ( is_array( $value ) ) {
			return self::field_error( 'invalid_date', $field );
		}

		$display = trim( (string) $value );
		if ( '' === $display ) {
			return '';
		}

		// Retain canonical-input compatibility for existing callers. The screen
		// itself renders/submits US dates, including the past keep/drop values.
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $display ) ) {
			$canonical = \DateTimeImmutable::createFromFormat( '!Y-m-d', $display, new \DateTimeZone( 'UTC' ) );

			return ( $canonical instanceof \DateTimeImmutable && $canonical->format( 'Y-m-d' ) === $display )
				? $display
				: self::field_error( 'invalid_date', $field );
		}

		if ( 1 !== preg_match( '#^\d{1,2}/\d{1,2}/\d{4}$#', $display ) ) {
			return self::field_error( 'invalid_date', $field );
		}

		foreach ( [ 'm/d/Y', 'n/j/Y' ] as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $display, new \DateTimeZone( 'UTC' ) );
			if ( $parsed instanceof \DateTimeImmutable && $parsed->format( $format ) === $display ) {
				return $parsed->format( 'Y-m-d' );
			}
		}

		return self::field_error( 'invalid_date', $field );
	}

	/**
	 * One stored date as MM/DD/YYYY for display, or '' when it is not a date.
	 *
	 * Never guesses. A value that is not a real stored date renders as empty
	 * rather than as something that looks like a date and is not.
	 */
	private static function display_date( string $stored ): string {
		$stored = trim( $stored );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stored ) ) {
			return '';
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $stored, new \DateTimeZone( 'UTC' ) );

		return ( $parsed instanceof \DateTimeImmutable && $parsed->format( 'Y-m-d' ) === $stored )
			? $parsed->format( 'm/d/Y' )
			: '';
	}

	/**
	 * Which control the refusal belongs beside.
	 *
	 * A refusal this screen raised itself already names its own field. A
	 * service refusal is attributed through the fixed map — except for
	 * `invalid_date`, which the service raises for BOTH the explicit-date list
	 * and the weekly bounds, so the posted mode decides which control it is
	 * about.
	 *
	 * @param SessionScheduleResult|\WP_Error|null $result
	 * @param array<string,mixed>                  $post
	 */
	private static function ui_error_field( $result, array $post ): string {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$own  = is_array( $data ) ? (string) ( $data['bc_ui_field'] ?? '' ) : '';
			if ( '' !== $own ) {
				return in_array( $own, self::SAVE_FIELDS, true ) ? $own : '';
			}
		}

		$code = self::ui_error_code( $result );

		if ( 'invalid_date' === $code ) {
			return ScheduleExpansion::MODE_WEEKLY === self::posted_mode( $post ) ? 'recurrence_start' : 'dates';
		}

		$field = self::UI_ERROR_FIELD[ $code ] ?? '';

		return in_array( $field, self::SAVE_FIELDS, true ) ? $field : '';
	}

	/* ------------------------------------------------------------------ *
	 * Holding a rejected form, for exactly one render
	 * ------------------------------------------------------------------ */

	private static function input_key(): string {
		return 'bc_schedule_input_' . get_current_user_id();
	}

	/** @param array<string,mixed> $post */
	private static function remember_input( array $post ): void {
		$held = [
			'mode'             => self::posted_mode( $post ),
			'start_time'       => trim( (string) ( $post['start_time'] ?? '' ) ),
			'future_dates'     => self::value_list( $post['future_dates'] ?? [] ),
			'past_dates'       => self::value_list( $post['past_dates'] ?? [] ),
			'recurrence_start' => trim( (string) ( $post['recurrence_start'] ?? '' ) ),
			'recurrence_end'   => trim( (string) ( $post['recurrence_end'] ?? '' ) ),
			'weekday_mask'     => self::posted_weekday_mask( $post ),
			'package_ids'      => self::posted_packages( $post ),
		];

		set_transient( self::input_key(), $held, self::INPUT_TTL );
	}

	/**
	 * The held form, consumed. Reading it clears it, so a later reload shows
	 * the schedule as it actually is rather than a stale rejected draft.
	 *
	 * @return array<string,mixed>
	 */
	private static function take_input(): array {
		$held = get_transient( self::input_key() );
		if ( ! is_array( $held ) ) {
			return [];
		}
		delete_transient( self::input_key() );

		return $held;
	}

	/** A refusal this screen raised itself, carrying the field it belongs beside. */
	private static function field_error( string $code, string $field ): \WP_Error {
		return new \WP_Error(
			'bc_ui_' . $code,
			__( 'That value could not be read.', 'bingocommerce-sessions' ),
			[ 'bc_ui_code' => $code, 'bc_ui_field' => $field ]
		);
	}

	/* ------------------------------------------------------------------ *
	 * Forms
	 * ------------------------------------------------------------------ */

	private static function render_create_form( int $portal_session_id ): void {
		$gateway  = new PortalTemplateGateway();
		$template = $portal_session_id > 0 ? $gateway->get( $portal_session_id ) : null;

		if ( is_wp_error( $template ) || ! is_array( $template ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'Add this schedule from Sessions from Portal, so it is attached to a Portal session this hall actually has.', 'bingocommerce-sessions' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( ! PortalTemplateGateway::is_commerce_eligible( $template ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'That Portal session is not ready to sell yet, so it cannot be added to the store. Only active, current Portal sessions can be.', 'bingocommerce-sessions' ); ?></p>
			</div>
			<?php
			return;
		}

		/*
		 * NO TIME ZONE, NO FORM.
		 *
		 * A schedule's zone is captured at creation and fixed for its life, so
		 * a hall without a named zone cannot create one at all. Rendering the
		 * form anyway would let the Manager fill the whole thing in, submit,
		 * and be refused — after the work. The refusal belongs here, before it.
		 */
		$zone = ScheduleTimezone::capture_hall_zone();
		if ( is_wp_error( $zone ) ) {
			self::render_timezone_block( $portal_session_id );

			return;
		}

		$held = self::take_input();
		?>
		<h2><?php echo esc_html__( 'Schedule This Session', 'bingocommerce-sessions' ); ?></h2>
		<p><?php echo esc_html__( 'Choose when this session will run and which Packages customers can purchase.', 'bingocommerce-sessions' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bc-schedule-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION_SAVE ); ?>" />
			<input type="hidden" name="bc_action" value="create" />
			<input type="hidden" name="portal_session_id" value="<?php echo esc_attr( (string) $portal_session_id ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Portal session', 'bingocommerce-sessions' ); ?></th>
					<td><strong><?php echo esc_html( (string) ( $template['name'] ?? '' ) ); ?></strong></td>
				</tr>
				<?php self::render_timezone_row( $zone ); ?>
				<?php self::render_time_row( (string) ( $held['start_time'] ?? '' ) ); ?>
				<?php
				self::render_mode_rows(
					(string) ( $held['mode'] ?? ScheduleExpansion::MODE_DATES ),
					[],
					isset( $held['future_dates'] ) ? (array) $held['future_dates'] : [],
					(string) ( $held['recurrence_start'] ?? '' ),
					(string) ( $held['recurrence_end'] ?? '' ),
					(int) ( $held['weekday_mask'] ?? 0 )
				);
				?>
				<?php self::render_package_row( isset( $held['package_ids'] ) ? array_map( 'intval', (array) $held['package_ids'] ) : [] ); ?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Create Sessions', 'bingocommerce-sessions' ); ?></button>
			</p>
		</form>
		<?php self::render_mode_script(); ?>
		<?php
	}

	/** Complete timezone setup here, before exposing any schedule inputs. */
	private static function render_timezone_block( int $portal_session_id ): void {
		?>
		<h2><?php echo esc_html__( 'Set Hall Timezone', 'bingocommerce-sessions' ); ?></h2>
		<p><?php echo esc_html__( 'The hall timezone controls session dates and sales windows. Choose a named city or region, not a UTC offset. Existing schedules keep their saved timezones.', 'bingocommerce-sessions' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bc-timezone-form">
			<?php wp_nonce_field( self::NONCE_ACTION_TIMEZONE . get_current_blog_id() ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION_TIMEZONE ); ?>" />
			<input type="hidden" name="portal_session_id" value="<?php echo esc_attr( (string) $portal_session_id ); ?>" />
			<p>
				<label for="bc-hall-timezone"><?php echo esc_html__( 'Hall timezone', 'bingocommerce-sessions' ); ?></label><br />
				<select id="bc-hall-timezone" name="timezone_string" required>
					<option value=""><?php echo esc_html__( 'Select a timezone', 'bingocommerce-sessions' ); ?></option>
					<?php foreach ( \DateTimeZone::listIdentifiers( \DateTimeZone::ALL ) as $zone ) : ?>
						<option value="<?php echo esc_attr( $zone ); ?>"><?php echo esc_html( $zone ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><button type="submit" class="button button-primary"><?php echo esc_html__( 'Save Timezone and Continue', 'bingocommerce-sessions' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_edit_form( SessionScheduleService $service, int $schedule_id ): void {
		$schedule = $service->get( $schedule_id );
		if ( is_wp_error( $schedule ) ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html( SessionAdmin::error_message( $schedule ) ); ?></p>
			</div>
			<?php
			return;
		}

		$template_name = self::template_name( (int) ( $schedule['portal_session_id'] ?? 0 ) );
		?>
		<h2><?php /* translators: %d: selected schedule ID. */ echo esc_html( sprintf( __( 'Schedule #%d', 'bingocommerce-sessions' ), $schedule_id ) ); ?></h2>
		<p class="bc-selected-schedule">
			<strong><?php echo esc_html__( 'Portal session:', 'bingocommerce-sessions' ); ?></strong> <?php echo esc_html( $template_name ); ?>
			&mdash; <strong><?php echo esc_html__( 'Saved time:', 'bingocommerce-sessions' ); ?></strong> <?php echo esc_html( ScheduleTimezone::display_time( (string) $schedule['start_time_local'] ) ); ?>
		</p>
		<?php
		$token = (string) ( $schedule['active_definition_token'] ?? '' );

		$dates    = '' === $token ? [] : $service->definition_dates( $schedule_id, $token );
		$packages = '' === $token ? [] : $service->definition_packages( $schedule_id, $token );

		$read_error = null;
		if ( is_wp_error( $dates ) ) {
			$read_error = $dates;
			$dates      = [];
		}
		if ( is_wp_error( $packages ) ) {
			$read_error = $read_error ?? $packages;
			$packages   = [];
		}

		/*
		 * A FAILED DEFINITION READ RENDERS NO FORM AT ALL.
		 *
		 * An edit COMMITS THE WHOLE DEFINITION, not a diff: whatever dates and
		 * Packages the form carries become the new definition, and the ones it
		 * omits are gone. So a form built on a read that failed is a form whose
		 * empty date list and empty Package list are indistinguishable from a
		 * Manager's deliberate choice to remove them — and one Save would
		 * commit that loss under a fresh token.
		 *
		 * Warning someone next to a working Save button is not a safeguard;
		 * withholding the Save is.
		 */
		if ( null !== $read_error ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'This schedule\'s current definition could not be read, so it cannot be edited right now. Editing commits the whole definition, and saving from a partial read would discard the dates and Packages that could not be loaded.', 'bingocommerce-sessions' ); ?></p>
				<p><?php echo esc_html( SessionAdmin::error_message( $read_error ) ); ?></p>
				<p><?php echo esc_html__( 'Opening this page made no changes. Use Back to all schedules to access Archive, or try editing again once the read succeeds.', 'bingocommerce-sessions' ); ?></p>
			</div>
			<?php
			return;
		}

		$held  = self::take_input();
		$today = self::hall_today();

		// Past dates keep their own control (§8.3 keep/drop). The date-entry UI
		// below holds ONLY future dates, and is never prefilled with a past one.
		$past   = [];
		$future = [];
		foreach ( (array) $dates as $date ) {
			$date = (string) $date;
			if ( '' !== $today && ! ScheduleExpansion::is_tomorrow_or_later( $date, $today ) ) {
				$past[] = $date;
			} else {
				$future[] = $date;
			}
		}

		$future_values = isset( $held['future_dates'] )
			? array_map( 'strval', (array) $held['future_dates'] )
			: array_map( [ self::class, 'display_date' ], $future );
		?>
		<p><?php echo esc_html__( 'Choose when this session will run and which Packages customers can purchase.', 'bingocommerce-sessions' ); ?></p>
		<p class="description"><?php echo esc_html__( 'A Bingo Session previously offered for sale keeps its original time and Package assignments. Removing one of its future dates stops purchases for that occurrence but keeps its record. To offer that date at a different time, create a separate schedule.', 'bingocommerce-sessions' ); ?></p>

		<?php if ( '' === $token ) : ?>
			<div class="notice notice-warning">
				<p><?php echo esc_html__( 'This schedule is incomplete: its last save did not commit, so it generates nothing. Save it again below, or archive it.', 'bingocommerce-sessions' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bc-schedule-form">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION_SAVE ); ?>" />
			<input type="hidden" name="bc_action" value="edit" />
			<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $schedule_id ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Portal session', 'bingocommerce-sessions' ); ?></th>
					<td>
						<strong><?php echo esc_html( $template_name ); ?></strong>
						<p class="description">
							<?php echo esc_html__( 'Read-only. A schedule stays with the Portal session it was created from; to schedule a different one, add it from Sessions from Portal.', 'bingocommerce-sessions' ); ?>
						</p>
					</td>
				</tr>
				<?php self::render_timezone_row( (string) ( $schedule['timezone_name'] ?? '' ), true ); ?>
				<?php
				self::render_time_row(
					isset( $held['start_time'] )
						? (string) $held['start_time']
						: ScheduleTimezone::display_time( (string) ( $schedule['start_time_local'] ?? '' ) )
				);
				?>
				<?php
				self::render_mode_rows(
					(string) ( $held['mode'] ?? ( $schedule['mode'] ?? ScheduleExpansion::MODE_DATES ) ),
					$past,
					$future_values,
					isset( $held['recurrence_start'] )
						? (string) $held['recurrence_start']
						: self::display_date( (string) ( $schedule['recurrence_start'] ?? '' ) ),
					isset( $held['recurrence_end'] )
						? (string) $held['recurrence_end']
						: self::display_date( (string) ( $schedule['recurrence_end'] ?? '' ) ),
					isset( $held['weekday_mask'] ) ? (int) $held['weekday_mask'] : (int) ( $schedule['weekday_mask'] ?? 0 )
				);
				?>
				<?php
				self::render_package_row(
					isset( $held['package_ids'] )
						? array_map( 'intval', (array) $held['package_ids'] )
						: ( is_array( $packages ) ? $packages : [] )
				);
				?>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo esc_html__( 'Save Schedule', 'bingocommerce-sessions' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'admin.php' ) ) ); ?>">
					<?php echo esc_html__( 'Cancel', 'bingocommerce-sessions' ); ?>
				</a>
			</p>
		</form>
		<?php if ( '' !== $token && SessionScheduleService::STATUS_ACTIVE === $schedule['status'] ) : ?>
			<p><?php echo esc_html__( 'Save schedule changes first. Update Sessions applies the saved schedule to Bingo Sessions.', 'bingocommerce-sessions' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION_GENERATE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>" />
				<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $schedule_id ); ?>" />
				<button type="submit" class="button"><?php echo esc_html__( 'Update Sessions', 'bingocommerce-sessions' ); ?></button>
			</form>
		<?php endif; ?>
		<?php self::render_mode_script(); ?>
		<?php
	}

	/** The message for a field, when this render is showing that field's refusal. */
	private static function field_message( string $field ): string {
		if ( 'error' !== sanitize_key( wp_unslash( $_GET['bc_saved'] ?? '' ) ) ) {
			return '';
		}
		if ( sanitize_key( wp_unslash( $_GET['bc_field'] ?? '' ) ) !== $field ) {
			return '';
		}

		return self::save_error_message( sanitize_key( wp_unslash( $_GET['bc_error'] ?? '' ) ) );
	}

	/** A refusal rendered BESIDE the control that produced it. */
	private static function render_field_error( string $field ): void {
		$message = self::field_message( $field );
		if ( '' === $message ) {
			return;
		}
		?>
		<p class="bc-field-error" style="color:#d63638;margin:4px 0 0;">
			<strong><?php echo esc_html( $message ); ?></strong>
		</p>
		<?php
	}

	private static function render_timezone_row( $zone, bool $fixed = false ): void {
		$zone_name = is_wp_error( $zone ) ? '' : (string) $zone;
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Time zone', 'bingocommerce-sessions' ); ?></th>
			<td>
				<strong><?php echo esc_html( '' === $zone_name ? __( 'Not set', 'bingocommerce-sessions' ) : $zone_name ); ?></strong>
				<p class="description">
					<?php
					echo esc_html(
						$fixed
							? __( 'Fixed for this schedule\'s life. To change it, archive this schedule and create a replacement.', 'bingocommerce-sessions' )
							: __( 'Taken from this hall\'s WordPress time zone setting and FIXED for the schedule\'s life. To change it later, archive the schedule and create a replacement.', 'bingocommerce-sessions' )
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	private static function render_time_row( string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="bc-start-time"><?php echo esc_html__( 'Start time', 'bingocommerce-sessions' ); ?></label></th>
			<td>
				<input type="text" id="bc-start-time" name="start_time" value="<?php echo esc_attr( $value ); ?>" placeholder="18:00" class="regular-text" />
				<p class="description"><?php echo esc_html__( 'Minute precision, 24-hour HH:MM — for example 18:00. Seconds are not accepted.', 'bingocommerce-sessions' ); ?></p>
				<?php self::render_field_error( 'start_time' ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Mode, and the fields belonging to each mode.
	 *
	 * BOTH GROUPS ARE RENDERED, AND ONLY ONE IS ACTIVE. The inactive group is
	 * hidden AND its inputs are `disabled`, which is what stops a browser
	 * submitting them at all — display alone would still post the values. The
	 * server does not trust that either: marshal_definition() passes on only
	 * the active mode's fields whatever arrives.
	 *
	 * @param array<int,string> $past_dates    Stored form, already committed.
	 * @param array<int,string> $future_values Display form, for the entry UI.
	 */
	private static function render_mode_rows( string $mode, array $past_dates, array $future_values, string $recurrence_start, string $recurrence_end, int $weekday_mask ): void {
		$weekly     = ScheduleExpansion::MODE_WEEKLY === $mode;
		$dates_mode = ! $weekly;
		$tomorrow   = self::tomorrow_display();
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'How often', 'bingocommerce-sessions' ); ?></th>
			<td>
				<label>
					<input type="radio" name="mode" value="<?php echo esc_attr( ScheduleExpansion::MODE_DATES ); ?>" class="bc-mode-choice" <?php checked( true, $dates_mode ); ?> />
					<?php echo esc_html__( 'Specific dates', 'bingocommerce-sessions' ); ?>
				</label><br />
				<label>
					<input type="radio" name="mode" value="<?php echo esc_attr( ScheduleExpansion::MODE_WEEKLY ); ?>" class="bc-mode-choice" <?php checked( true, $weekly ); ?> />
					<?php echo esc_html__( 'Repeating weekly', 'bingocommerce-sessions' ); ?>
				</label>
				<?php self::render_field_error( 'mode' ); ?>
			</td>
		</tr>

		<?php if ( [] !== $past_dates ) : ?>
			<tr class="bc-mode-dates"<?php echo $dates_mode ? '' : ' style="display:none"'; ?>>
				<th scope="row"><?php echo esc_html__( 'Dates already scheduled', 'bingocommerce-sessions' ); ?></th>
				<td>
					<p class="description">
						<?php echo esc_html__( 'These dates have already passed. Keep them to leave this schedule as it was, or uncheck one to drop it. Dropping a past date changes no session that already exists.', 'bingocommerce-sessions' ); ?>
					</p>
					<?php foreach ( $past_dates as $date ) : ?>
						<label>
							<input type="checkbox" name="past_dates[]" value="<?php echo esc_attr( self::display_date( (string) $date ) ); ?>" checked="checked" <?php disabled( true, ! $dates_mode ); ?> />
							<?php echo esc_html( self::display_date( (string) $date ) ); ?>
						</label><br />
					<?php endforeach; ?>
				</td>
			</tr>
		<?php endif; ?>

		<tr class="bc-mode-dates"<?php echo $dates_mode ? '' : ' style="display:none"'; ?>>
			<th scope="row"><?php echo esc_html__( 'Dates this session runs', 'bingocommerce-sessions' ); ?></th>
			<td>
				<div class="bc-date-list">
					<?php
					$rows = $future_values;
					if ( [] === $rows ) {
						$rows = [ '' ];
					}
					foreach ( $rows as $index => $value ) :
						?>
						<p class="bc-date-row">
							<label class="screen-reader-text" for="bc-date-<?php echo esc_attr( (string) $index ); ?>">
								<?php echo esc_html__( 'Date', 'bingocommerce-sessions' ); ?>
							</label>
							<input
								type="text"
								inputmode="numeric"
								id="bc-date-<?php echo esc_attr( (string) $index ); ?>"
								class="bc-date-input"
								name="future_dates[]"
								value="<?php echo esc_attr( (string) $value ); ?>"
								pattern="<?php echo esc_attr( self::US_DATE_PATTERN ); ?>"
								data-min="<?php echo esc_attr( $tomorrow ); ?>"
								placeholder="<?php echo esc_attr( self::date_example() ); ?>"
								<?php disabled( true, ! $dates_mode ); ?>
							/>
							<button type="button" class="button bc-remove-date" <?php disabled( true, ! $dates_mode ); ?>><?php echo esc_html__( 'Remove date', 'bingocommerce-sessions' ); ?></button>
						</p>
					<?php endforeach; ?>
				</div>
				<p>
					<button type="button" class="button bc-add-date"><?php echo esc_html__( 'Add another date', 'bingocommerce-sessions' ); ?></button>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: an example date in MM/DD/YYYY form. */
						esc_html__( 'Dates are MM/DD/YYYY — for example %s. Each new date must be tomorrow or later.', 'bingocommerce-sessions' ),
						esc_html( self::date_example() )
					);
					?>
				</p>
				<?php self::render_field_error( 'dates' ); ?>
			</td>
		</tr>

		<tr class="bc-mode-weekly"<?php echo $weekly ? '' : ' style="display:none"'; ?>>
			<th scope="row"><?php echo esc_html__( 'Days of the week', 'bingocommerce-sessions' ); ?></th>
			<td>
				<?php
				foreach (
					[
						1  => __( 'Monday', 'bingocommerce-sessions' ),
						2  => __( 'Tuesday', 'bingocommerce-sessions' ),
						4  => __( 'Wednesday', 'bingocommerce-sessions' ),
						8  => __( 'Thursday', 'bingocommerce-sessions' ),
						16 => __( 'Friday', 'bingocommerce-sessions' ),
						32 => __( 'Saturday', 'bingocommerce-sessions' ),
						64 => __( 'Sunday', 'bingocommerce-sessions' ),
					] as $bit => $label
				) :
					?>
					<label>
						<input type="checkbox" name="weekdays[]" value="<?php echo esc_attr( (string) $bit ); ?>" <?php checked( true, 0 !== ( $weekday_mask & $bit ) ); ?> <?php disabled( true, ! $weekly ); ?> />
						<?php echo esc_html( $label ); ?>
					</label><br />
				<?php endforeach; ?>
				<?php self::render_field_error( 'weekdays' ); ?>
			</td>
		</tr>

		<tr class="bc-mode-weekly"<?php echo $weekly ? '' : ' style="display:none"'; ?>>
			<th scope="row"><label for="bc-recurrence-start"><?php echo esc_html__( 'Start date', 'bingocommerce-sessions' ); ?></label></th>
			<td>
				<input
					type="text"
					inputmode="numeric"
					id="bc-recurrence-start"
					class="bc-date-input"
					name="recurrence_start"
					value="<?php echo esc_attr( $recurrence_start ); ?>"
					pattern="<?php echo esc_attr( self::US_DATE_PATTERN ); ?>"
					data-min="<?php echo esc_attr( $tomorrow ); ?>"
					placeholder="<?php echo esc_attr( self::date_example() ); ?>"
					<?php disabled( true, ! $weekly ); ?>
				/>
				<p class="description">
					<?php
					printf(
						/* translators: %s: an example date in MM/DD/YYYY form. */
						esc_html__( 'MM/DD/YYYY — for example %s.', 'bingocommerce-sessions' ),
						esc_html( self::date_example() )
					);
					?>
				</p>
				<?php self::render_field_error( 'recurrence_start' ); ?>
			</td>
		</tr>
		<tr class="bc-mode-weekly"<?php echo $weekly ? '' : ' style="display:none"'; ?>>
			<th scope="row"><label for="bc-recurrence-end"><?php echo esc_html__( 'End date', 'bingocommerce-sessions' ); ?></label></th>
			<td>
				<input
					type="text"
					inputmode="numeric"
					id="bc-recurrence-end"
					class="bc-date-input"
					name="recurrence_end"
					value="<?php echo esc_attr( $recurrence_end ); ?>"
					pattern="<?php echo esc_attr( self::US_DATE_PATTERN ); ?>"
					data-min="<?php echo esc_attr( '' !== $recurrence_start ? $recurrence_start : $tomorrow ); ?>"
					placeholder="<?php echo esc_attr( self::date_example() ); ?>"
					<?php disabled( true, ! $weekly ); ?>
				/>
				<p class="description">
					<?php echo esc_html__( 'The same as or later than the start date.', 'bingocommerce-sessions' ); ?>
				</p>
				<?php self::render_field_error( 'recurrence_end' ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Tomorrow in the hall's zone, MM/DD/YYYY, for the date controls' `data-min`
	 * hint and their placeholder context.
	 *
	 * DISPLAY FORM, NOT `<input type="date">`'s ISO ONE. An earlier version of
	 * this screen rendered every editable date with `type="date"` and a `min`
	 * attribute, on the strength of a comment claiming this "guarantees" a
	 * MM/DD/YYYY reading for the Manager. It does not: a native date input's
	 * VISIBLE, TYPED format follows the browser's own locale — a browser or OS
	 * set to en-GB shows DD/MM/YYYY in the same control, silently, with no
	 * signal to this screen that it happened. `value`/`min` are always
	 * YYYY-MM-DD (an HTML requirement the browser enforces regardless of
	 * display), which is why the bug was invisible in `esc_attr()` output and
	 * only visible in a browser. Every editable date on this screen is a plain
	 * text input instead, displayed and typed as MM/DD/YYYY unconditionally,
	 * parsed by `storage_date()` — never `strtotime()`, never the browser.
	 */
	private static function tomorrow_display(): string {
		$today = self::hall_today();
		if ( '' === $today ) {
			return '';
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $today, new \DateTimeZone( 'UTC' ) );

		return $parsed instanceof \DateTimeImmutable ? $parsed->modify( '+1 day' )->format( 'm/d/Y' ) : '';
	}

	/**
	 * Bundled calendar assistance and future-row controls. Text inputs remain
	 * usable manually, and the server remains authoritative for every date rule.
	 *
	 * PROGRESSIVE ENHANCEMENT ONLY. Disabling the inactive group is what stops
	 * it being submitted, and the SERVER re-applies both rules regardless —
	 * marshal_definition() ignores the inactive mode's fields, and
	 * ScheduleExpansion::canonical_weekly() is what actually refuses an end
	 * before its start (§8.4's `invalid_recurrence_range`, surfaced beside End
	 * date as `end_before_start`). Nothing here is a validation.
	 *
	 * The picker uses an explicit per-control format, unaffected by WordPress's
	 * locale defaults. Its minimum comes from hall-local server output, never
	 * the browser's idea of today. An invalid manual value is never corrected
	 * silently: server-side parsing/range validation must still see it.
	 */
	private static function render_mode_script(): void {
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var form = document.querySelector( '.bc-schedule-form' );
			if ( ! form ) { return; }
			var $ = window.jQuery;
			var hasPicker = $ && $.fn.datepicker;
			var list = form.querySelector( '.bc-date-list' );
			// Clone BEFORE datepicker attaches generated buttons, ids and state.
			var template = list.querySelector( '.bc-date-row' ).cloneNode( true );
			var nextId = list.querySelectorAll( '.bc-date-row' ).length;
			var start = form.querySelector( '#bc-recurrence-start' );

			function parseUs( value ) {
				try { return $.datepicker.parseDate( 'mm/dd/yy', value ); }
				catch ( error ) { return null; }
			}

			function attachPicker( input ) {
				if ( ! hasPicker ) { return; }
				$( input ).datepicker( {
					dateFormat: 'mm/dd/yy',
					showOn: 'both',
					buttonText: <?php echo wp_json_encode( __( 'Choose date', 'bingocommerce-sessions' ) ); ?>,
					disabled: input.disabled,
					beforeShow: function () {
						var minimum = parseUs( input.dataset.min );
						if ( 'bc-recurrence-end' === input.id ) {
							minimum = parseUs( start.value ) || parseUs( start.dataset.min );
						}
						return { minDate: minimum };
					}
				} );
			}

			form.querySelectorAll( '.bc-date-input' ).forEach( attachPicker );

			function setGroup( selector, active ) {
				form.querySelectorAll( selector ).forEach( function ( row ) {
					row.style.display = active ? '' : 'none';
					row.querySelectorAll( 'input, button' ).forEach( function ( input ) {
						input.disabled = ! active;
						if ( hasPicker && input.classList.contains( 'hasDatepicker' ) ) {
							$( input ).datepicker( active ? 'enable' : 'disable' );
						}
					} );
				} );
			}

			function applyMode() {
				var chosen = form.querySelector( '.bc-mode-choice:checked' );
				var weekly = chosen && 'weekly' === chosen.value;
				setGroup( '.bc-mode-dates', ! weekly );
				setGroup( '.bc-mode-weekly', weekly );
			}

			form.querySelectorAll( '.bc-mode-choice' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', applyMode );
			} );

			var adder = form.querySelector( '.bc-add-date' );
			if ( adder && list ) {
				adder.addEventListener( 'click', function () {
					var row = template.cloneNode( true );
					var input = row.querySelector( 'input' );
					input.value = '';
					input.id = 'bc-date-' + nextId++;
					row.querySelector( 'label' ).htmlFor = input.id;
					list.appendChild( row );
					attachPicker( input );
					applyMode();
					input.focus();
				} );
				list.addEventListener( 'click', function ( event ) {
					var button = event.target.closest( '.bc-remove-date' );
					if ( ! button || button.disabled ) { return; }
					var row = button.closest( '.bc-date-row' );
					var input = row.querySelector( 'input' );
					if ( hasPicker ) { $( input ).datepicker( 'destroy' ); }
					if ( list.children.length > 1 ) {
						row.remove();
					} else {
						input.value = '';
						attachPicker( input );
					}
					list.querySelector( 'input' ).focus();
				} );
			}

			applyMode();
		} );
		</script>
		<?php
	}

	/**
	 * Package selection.
	 *
	 * EVERY CHECKBOX IS LABELLED, WITHOUT EXCEPTION. A Package whose name
	 * cannot be read is rendered as a DISABLED option saying so and carrying
	 * its id — never as a bare box the Manager would have to guess at, and
	 * never silently omitted, which would look like the Package does not exist.
	 * Whether an option can be chosen is PackageGateway's existing
	 * assignability predicate, not a rule restated here.
	 *
	 * @param array<int,int> $selected
	 */
	private static function render_package_row( array $selected ): void {
		$packages = ( new PackageGateway() )->map();
		$error    = is_wp_error( $packages ) ? $packages : null;
		$packages = is_wp_error( $packages ) ? [] : $packages;
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Packages customers can purchase', 'bingocommerce-sessions' ); ?></th>
			<td>
				<?php if ( null !== $error ) : ?>
					<p class="description">
						<?php echo esc_html__( 'Bingo Packages could not be read, so none can be offered safely right now. Saving without a Package is refused.', 'bingocommerce-sessions' ); ?>
					</p>
				<?php elseif ( [] === $packages ) : ?>
					<p class="description">
						<?php echo esc_html__( 'This hall has no Bingo Packages yet. A schedule needs at least one before customers can buy anything.', 'bingocommerce-sessions' ); ?>
					</p>
				<?php endif; ?>

				<?php foreach ( $packages as $package ) : ?>
					<?php
					$package_id = (int) ( $package['package_id'] ?? 0 );
					$name       = self::package_name( $package );
					$assignable = PackageGateway::assert_assignable( $package );
					$usable     = ! is_wp_error( $assignable ) && '' !== $name && $package_id > 0;
					$status     = (string) ( $package['status'] ?? '' );
					?>
					<label style="display:block">
						<input
							type="checkbox"
							name="package_ids[]"
							value="<?php echo esc_attr( (string) $package_id ); ?>"
							<?php checked( true, in_array( $package_id, $selected, true ) ); ?>
							<?php disabled( true, ! $usable ); ?>
						/>
						<?php if ( '' !== $name ) : ?>
							<?php echo esc_html( $name ); ?>
							<em><?php echo esc_html( self::package_status_label( $status, $assignable ) ); ?></em>
						<?php else : ?>
							<?php
							printf(
								/* translators: %d: the Bingo Package id. */
								esc_html__( 'Package #%d — name unavailable, so it cannot be selected', 'bingocommerce-sessions' ),
								(int) $package_id
							);
							?>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>

				<p class="description"><?php echo esc_html__( 'Choose at least one. The same Package may be sold on several schedules.', 'bingocommerce-sessions' ); ?></p>
				<?php self::render_field_error( 'packages' ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A Bingo Package's customer-facing name.
	 *
	 * IT IS NOT A COLUMN. `bingo_packages` stores `package_id`,
	 * `wc_product_id`, `package_type`, `status` and timestamps — and no name at
	 * all (`…/catalog/src/PackageService.php:66`). The name belongs to the
	 * linked WooCommerce product, which is exactly where SessionAdmin's own
	 * `describe_package()` reads it from. Reading `$package['name']` — as an
	 * earlier version of this screen did — therefore yielded an empty string
	 * for EVERY Package, which is how the Manager ended up looking at a column
	 * of unlabelled checkboxes.
	 *
	 * @param array<string,mixed> $package
	 */
	private static function package_name( array $package ): string {
		$title = get_the_title( (int) ( $package['wc_product_id'] ?? 0 ) );

		return trim( (string) $title );
	}

	/**
	 * What a Package's state means for choosing it.
	 *
	 * @param true|\WP_Error $assignable
	 */
	private static function package_status_label( string $status, $assignable ): string {
		if ( ! is_wp_error( $assignable ) ) {
			return __( '(available)', 'bingocommerce-sessions' );
		}

		if ( 'archived' === $status ) {
			return __( '(archived — restore it before selling it)', 'bingocommerce-sessions' );
		}

		return __( '(not available to sell yet)', 'bingocommerce-sessions' );
	}

	/* ------------------------------------------------------------------ *
	 * List table
	 * ------------------------------------------------------------------ */

	/** @param array<int,array<string,mixed>> $schedules */
	private static function render_table( array $schedules ): void {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Schedule', 'bingocommerce-sessions' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Portal session', 'bingocommerce-sessions' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Time', 'bingocommerce-sessions' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'How often', 'bingocommerce-sessions' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Status', 'bingocommerce-sessions' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Actions', 'bingocommerce-sessions' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( [] === $schedules ) : ?>
				<tr><td colspan="6"><?php echo esc_html__( 'No session schedules yet. Start one from Sessions from Portal.', 'bingocommerce-sessions' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $schedules as $schedule ) : ?>
				<?php
				$schedule_id = (int) ( $schedule['schedule_id'] ?? 0 );
				$archived    = SessionScheduleService::STATUS_ARCHIVED === (string) ( $schedule['status'] ?? '' );
				$inert       = '' === (string) ( $schedule['active_definition_token'] ?? '' );
				?>
				<tr>
					<td>
						<strong>#<?php echo esc_html( (string) $schedule_id ); ?></strong>
						<div class="row-actions">
							<span><?php echo esc_html( (string) ( $schedule['timezone_name'] ?? '' ) ); ?></span>
						</div>
					</td>
					<td><?php echo esc_html( self::template_name( (int) ( $schedule['portal_session_id'] ?? 0 ) ) ); ?></td>
					<td><?php echo esc_html( ScheduleTimezone::display_time( (string) ( $schedule['start_time_local'] ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( self::mode_label( (string) ( $schedule['mode'] ?? '' ) ) ); ?></td>
					<td>
						<?php echo esc_html( $archived ? __( 'Archived', 'bingocommerce-sessions' ) : __( 'Active', 'bingocommerce-sessions' ) ); ?>
						<?php if ( $inert ) : ?>
							<br /><span><?php echo esc_html__( 'Incomplete — generates nothing', 'bingocommerce-sessions' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<div class="bc-schedule-actions" style="display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;">
						<?php if ( ! $archived ) : ?>
							<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'edit' => $schedule_id ], admin_url( 'admin.php' ) ) ); ?>">
								<?php echo esc_html__( 'Edit schedule', 'bingocommerce-sessions' ); ?>
							</a>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION_SAVE ); ?>" />
								<input type="hidden" name="bc_action" value="archive" />
								<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $schedule_id ); ?>" />
								<button type="submit" class="button-link"><?php echo esc_html__( 'Archive', 'bingocommerce-sessions' ); ?></button>
							</form>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( self::NONCE_ACTION_GENERATE ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>" />
								<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $schedule_id ); ?>" />
								<button type="submit" class="button-link"><?php echo esc_html__( 'Update Sessions', 'bingocommerce-sessions' ); ?></button>
							</form>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION_SAVE ); ?>" />
								<input type="hidden" name="bc_action" value="restore" />
								<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $schedule_id ); ?>" />
								<button type="submit" class="button-link"><?php echo esc_html__( 'Restore', 'bingocommerce-sessions' ); ?></button>
							</form>
						<?php endif; ?>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * The save result, read back from the PRG redirect.
	 *
	 * Reads only allowlisted values: the outcome must be one of the four fixed
	 * words, and the error code is mapped to a message THIS FILE owns rather
	 * than echoed. Nothing a caller puts in the query string reaches the page.
	 */
	private static function render_save_notice(): void {
		$saved = sanitize_key( wp_unslash( $_GET['bc_saved'] ?? '' ) );
		if ( '' === $saved ) {
			return;
		}

		if ( in_array( $saved, self::SAVE_OUTCOMES, true ) ) {
			?>
			<div class="notice notice-success">
				<p><?php echo esc_html( self::save_message( $saved ) ); ?></p>
				<?php if ( 'created' === $saved ) : ?>
					<p>
						<a href="<?php echo esc_url( add_query_arg( [ 'page' => SessionAdmin::PAGE_SLUG ], admin_url( 'admin.php' ) ) ); ?>">
							<?php echo esc_html__( 'View Bingo Sessions', 'bingocommerce-sessions' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		if ( 'created_partial' === $saved ) {
			/*
			 * CREATION SUCCEEDED; GENERATION DID NOT FULLY COMPLETE. The
			 * schedule is real and stays exactly as saved — this notice is
			 * DELIBERATELY not styled as an error for that reason. §16 M105/M106
			 * ownership and isolation are untouched by this branch. The selected
			 * editor offers Update Sessions only after its definition reads succeed;
			 * this notice must not bypass that safeguard with another action form.
			 */
			?>
		<div class="notice notice-warning">
			<p><strong><?php echo esc_html__( 'The schedule was saved, but sessions were not fully created.', 'bingocommerce-sessions' ); ?></strong></p>
			<p><?php echo esc_html__( 'When this schedule\'s definition is readable, use Update Sessions for this schedule to finish creating Bingo Sessions. If the definition cannot be read, reload this page once the read succeeds; Update Sessions is unavailable until then.', 'bingocommerce-sessions' ); ?></p>
			<?php $summary = self::decode_counts(); ?>
				<?php if ( '' !== $summary ) : ?>
					<p><?php echo esc_html( $summary ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		if ( 'error' !== $saved ) {
			// An outcome outside the vocabulary is not displayed at all.
			return;
		}
		/*
		 * A database error may occur AFTER an edit committed its definition,
		 * while turning excluded occurrences OFF. Do not claim that definition
		 * was not saved when the mapped result cannot prove it. Definite
		 * validation refusals can still say so.
		 */
		$code      = sanitize_key( wp_unslash( $_GET['bc_error'] ?? '' ) );
		$uncertain = 'write_failed' === $code;
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $uncertain
				? __( 'The schedule save did not finish cleanly. Reload and check the schedule and sales state before trying again.', 'bingocommerce-sessions' )
				: __( 'The schedule definition was not saved.', 'bingocommerce-sessions' ) ); ?></p>
			<p><?php echo esc_html( self::save_error_message( $code ) ); ?></p>
		</div>
		<?php
	}

	private static function save_message( string $outcome ): string {
		switch ( $outcome ) {
			case 'created':
				return __( 'Sessions created. New Bingo Sessions are OFF until you turn each one on.', 'bingocommerce-sessions' );
			case 'updated':
				return __( 'Schedule saved. Updating Bingo Sessions is a separate step; choose Update Sessions below. Removed future dates stop sales as soon as the schedule is saved.', 'bingocommerce-sessions' );
			case 'archived':
				return __( 'Schedule archived. Existing Bingo Sessions are untouched.', 'bingocommerce-sessions' );
			case 'restored':
				return __( 'Schedule restored.', 'bingocommerce-sessions' );
		}

		return __( 'Schedule saved.', 'bingocommerce-sessions' );
	}

	/** One of this screen's OWN fixed codes, in the Manager's terms. Never echoed raw. */
	private static function save_error_message( string $code ): string {
		switch ( $code ) {
			case 'not_eligible':
				return __( 'That Portal session is no longer active and current, so it cannot carry a schedule. Make it current in the Portal, run Portal Sync, then save again.', 'bingocommerce-sessions' );
			case 'template_unavailable':
				return __( 'That Portal session is not present in this hall\'s synced copy. Run Portal Sync, then save again.', 'bingocommerce-sessions' );
			case 'state_changed':
				return __( 'The schedule changed while this save was running. Reload, check what it says now, and save again.', 'bingocommerce-sessions' );
			case 'not_found':
				return __( 'That schedule could not be found in this hall.', 'bingocommerce-sessions' );
			case 'not_active':
				return __( 'An archived schedule cannot be edited. Restore it first.', 'bingocommerce-sessions' );
			case 'invalid_time':
				return __( 'The start time must be a 24-hour HH:MM value with no seconds — for example 18:00.', 'bingocommerce-sessions' );
			case 'date_in_past':
				return __( 'New dates must be tomorrow or later in this schedule\'s time zone. A date that has already passed can be kept if it was already committed, but never added.', 'bingocommerce-sessions' );
			case 'package_unavailable':
				return __( 'A selected Bingo Package is not assignable. Choose another, or fix the Package first.', 'bingocommerce-sessions' );
			case 'unknown_action':
				return __( 'That action was not recognised, so nothing was attempted.', 'bingocommerce-sessions' );
			case 'write_failed':
				return __( 'A database operation failed. Check the current state before saving again.', 'bingocommerce-sessions' );
			case 'invalid_date':
				/* translators: %s: an example date in MM/DD/YYYY form. */
				return sprintf( __( 'Enter the date as MM/DD/YYYY — for example %s.', 'bingocommerce-sessions' ), self::date_example() );
			case 'invalid_mode':
				return __( 'Choose either Specific dates or Repeating weekly.', 'bingocommerce-sessions' );
			case 'no_weekdays':
				return __( 'Choose at least one day of the week.', 'bingocommerce-sessions' );
			case 'end_before_start':
				return __( 'End date must be the same as or later than the start date.', 'bingocommerce-sessions' );
			case 'range_too_long':
				return __( 'That range is too long. Shorten it, or create a second schedule for the later dates.', 'bingocommerce-sessions' );
			case 'no_timezone':
				return __( 'Set the Hall timezone before creating schedules.', 'bingocommerce-sessions' );
		}

		/*
		 * The fallback names the ONE thing that is certainly true and tells the
		 * Manager where to look. It replaces a bare "The change was refused",
		 * which said nothing they could act on.
		 */
		return __( 'Check the highlighted field and try again.', 'bingocommerce-sessions' );
	}

	/**
	 * The generation result, read back from the PRG redirect.
	 *
	 * Reads only allowlisted values: the status is matched against the §8.6
	 * vocabulary and the counts are re-parsed as integers, so nothing from the
	 * query string is echoed as given.
	 */
	private static function render_generation_notice(): void {
		$generated = sanitize_key( wp_unslash( $_GET['bc_generated'] ?? '' ) );
		if ( '' === $generated ) {
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['bc_code'] ?? '' ) );

		if ( SessionScheduleResult::STATUS_OK === $generated ) {
			?>
			<div class="notice notice-success">
				<p><?php echo esc_html__( 'Bingo Sessions updated.', 'bingocommerce-sessions' ); ?></p>
				<?php $summary = self::decode_counts(); ?>
				<?php if ( '' !== $summary ) : ?>
					<p><?php echo esc_html( $summary ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html__( 'Bingo Sessions were not fully updated. Changes already applied remain. Review the cause before using Update Sessions again.', 'bingocommerce-sessions' ); ?></p>
			<p><?php echo esc_html( self::generation_message( $generated, $code ) ); ?></p>
			<?php $summary = self::decode_counts(); ?>
			<?php if ( '' !== $summary ) : ?>
				<p><?php echo esc_html( $summary ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function generation_message( string $status, string $code ): string {
		if ( SessionScheduleResult::STATUS_SCHEDULE_STATE_CHANGED === $status ) {
			return __( 'Someone else changed this schedule at the same time. Reload it before using Update Sessions.', 'bingocommerce-sessions' );
		}

		switch ( $code ) {
			case 'protected_time_conflict':
				return __( 'An existing Bingo Session was offered for sale, so its time and Package assignments are protected. It did not move. Keep this schedule at its original time, or remove that future date and create a separate schedule at the new time. Then use Update Sessions.', 'bingocommerce-sessions' );
			case 'preflight_conflict':
				return __( 'A conflict prevented this update. Review the schedule and existing Bingo Sessions, resolve the cause, then use Update Sessions. Repeating it without a change will not resolve the conflict.', 'bingocommerce-sessions' );
			case 'eligibility_failed':
				return __( 'The Portal session is no longer ready to sell, or a selected Bingo Package is not assignable. Fix that, then use Update Sessions.', 'bingocommerce-sessions' );
			case 'template_renamed':
				return __( 'The Portal session was renamed while this was running. Use Update Sessions to pick up the new name.', 'bingocommerce-sessions' );
			case 'database_contention':
				return __( 'The database was busy. Use Update Sessions again.', 'bingocommerce-sessions' );
			case 'duplicate_conflict':
				return __( 'Another session already uses one of these dates and names. Resolve the clash, then use Update Sessions.', 'bingocommerce-sessions' );
			case 'final_verification_failed':
				return __( 'The final check found a problem, so nothing is reported as finished. Review the schedule before using Update Sessions again.', 'bingocommerce-sessions' );
		}

		return __( 'A change could not be written. Review the schedule before using Update Sessions again.', 'bingocommerce-sessions' );
	}

	/**
	 * @param array<string,int> $counts
	 */
	private static function encode_counts( array $counts ): string {
		$parts = [];
		foreach ( $counts as $name => $value ) {
			if ( in_array( $name, SessionScheduleResult::COUNTS, true ) ) {
				$parts[] = $name . ':' . (int) $value;
			}
		}

		return implode( ',', $parts );
	}

	private static function decode_counts(): string {
		$raw = (string) wp_unslash( $_GET['bc_counts'] ?? '' );
		if ( '' === $raw ) {
			return '';
		}

		$parts = [];
		foreach ( explode( ',', $raw ) as $pair ) {
			$bits = explode( ':', $pair, 2 );
			if ( 2 !== count( $bits ) ) {
				continue;
			}
			$name = sanitize_key( $bits[0] );
			// Allowlisted against the §8.6 vocabulary. A name that is not in it
			// is dropped rather than displayed.
			if ( ! in_array( $name, SessionScheduleResult::COUNTS, true ) ) {
				continue;
			}
			$parts[] = $name . ': ' . SessionAdmin::request_int( $bits[1] );
		}

		return implode( ' | ', $parts );
	}

	private static function mode_label( string $mode ): string {
		if ( ScheduleExpansion::MODE_WEEKLY === $mode ) {
			return __( 'Weekly', 'bingocommerce-sessions' );
		}
		if ( ScheduleExpansion::MODE_DATES === $mode ) {
			return __( 'Explicit dates', 'bingocommerce-sessions' );
		}

		return __( 'Unknown', 'bingocommerce-sessions' );
	}

	/**
	 * The Portal template's name, for READ-ONLY display beside a schedule.
	 *
	 * Read through PortalTemplateGateway — the only supported read path into
	 * the replica — never by querying the table from a screen.
	 *
	 * NEVER BLOCKS ANYTHING. A schedule whose template row cannot be read is
	 * still listed, still archivable and still shown in full; the name simply
	 * falls back to a form that carries the Portal id, so the Manager can still
	 * identify and act on it. A screen that hid or disabled a schedule because
	 * a DISPLAY STRING was unavailable would make a replica read failure look
	 * like a missing schedule, and would take Archive away at exactly the
	 * moment a Manager most needs it.
	 */
	private static function template_name( int $portal_session_id ): string {
		if ( $portal_session_id < 1 ) {
			return __( 'No Portal session', 'bingocommerce-sessions' );
		}

		$template = ( new PortalTemplateGateway() )->get( $portal_session_id );

		if ( is_wp_error( $template ) || ! is_array( $template ) ) {
			/* translators: %d is the Portal Session template id. */
			return sprintf( __( 'Portal session #%d (name unavailable)', 'bingocommerce-sessions' ), $portal_session_id );
		}

		$name = trim( (string) ( $template['name'] ?? '' ) );

		return '' === $name
			/* translators: %d is the Portal Session template id. */
			? sprintf( __( 'Portal session #%d (unnamed)', 'bingocommerce-sessions' ), $portal_session_id )
			: $name;
	}

	/** Today in the hall's own zone, or '' when it has no named zone. */
	private static function hall_today(): string {
		$zone = ScheduleTimezone::capture_hall_zone();
		if ( is_wp_error( $zone ) ) {
			return '';
		}

		return (string) ScheduleTimezone::local_today( (string) $zone );
	}
}
