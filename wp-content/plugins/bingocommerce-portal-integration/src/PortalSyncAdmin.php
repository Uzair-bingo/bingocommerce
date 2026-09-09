<?php

namespace BingoCommerce\PortalIntegration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 3G: the manual, hall-scoped Portal synchronization screen (ADR-041).
 *
 * This class is the AUTHORIZATION BOUNDARY for Portal synchronization.
 * PortalSyncService is deliberately a trusted-caller service: it performs no
 * WordPress-user authorization of its own, because it is also invoked by CLI
 * harnesses that run with no logged-in user. Every WordPress-facing entry point
 * — today only this one — must therefore establish method, tenancy, capability
 * and nonce BEFORE calling it. Nothing here duplicates Phase 3E fetching,
 * validation, locking, transaction, application, ordering or currentness
 * behaviour; it calls PortalSyncService::synchronize() exactly once and
 * translates the outcome.
 *
 * Two properties matter most and are structural rather than incidental:
 *
 *   1. The synchronization target is derived ONLY from the current site. No
 *      blog ID, Portal Hall ID, mapping ID or network ID is ever read from GET
 *      or POST, so a forged request cannot retarget another tenant.
 *   2. Nothing the Portal, the database or a WP_Error says reaches the browser.
 *      Outcomes are reduced to an allowlisted category and rendered from a
 *      fixed message table, so no token, header, endpoint, snapshot fragment,
 *      SQL, errno, table, constraint or lock name can be disclosed.
 */
final class PortalSyncAdmin {

	public const PAGE_SLUG    = 'bingocommerce-portal-sync';
	public const CAPABILITY   = 'bc_sync_portal';
	public const POST_ACTION  = 'bc_portal_sync';
	public const NONCE_ACTION = 'bingocommerce_portal_sync';

	/** Allowlisted redirect outcomes. Anything else is discarded on read. */
	public const OUTCOME_APPLIED   = 'applied';
	public const OUTCOME_NO_CHANGE = 'no_change';
	public const OUTCOME_ERROR     = 'error';

	private const OUTCOMES = [ self::OUTCOME_APPLIED, self::OUTCOME_NO_CHANGE, self::OUTCOME_ERROR ];

	/**
	 * Production error code -> fixed notice category.
	 *
	 * Every key is a code that actually exists in Phase 3B/3D/3E production
	 * source; none is invented here. A code missing from this table is NOT a
	 * reason to show its message — it maps to 'unknown', which is both the
	 * displayed notice and the audited category.
	 *
	 * @var array<string,string>
	 */
	private const ERROR_CATEGORIES = [
		// PortalSyncLock
		'portal_sync_lock_timeout'               => 'lock_busy',
		'portal_sync_lock_reentrant'             => 'lock_busy',
		'portal_sync_lock_failed'                => 'write_failed',
		// HallMappingRepository / PortalSyncService mapping resolution
		'mapping_not_found'                      => 'not_linked',
		'mapping_disabled'                       => 'link_disabled',
		'main_site_not_allowed'                  => 'site_ineligible',
		'site_archived'                          => 'site_ineligible',
		'site_deleted'                           => 'site_ineligible',
		'site_spam'                              => 'site_ineligible',
		'site_not_found'                         => 'site_ineligible',
		'site_wrong_network'                     => 'site_ineligible',
		'multisite_required'                     => 'site_ineligible',
		'invalid_blog_id'                        => 'site_ineligible',
		'mapping_read_failed'                    => 'write_failed',
		'mapping_state_inconsistent'             => 'write_failed',
		'mapping_state_changed'                  => 'write_failed',
		'mapping_schema_unavailable'             => 'schema_unavailable',
		// PortalConfig / PortalHttpClient configuration
		'portal_base_url_missing'                => 'not_configured',
		'portal_integration_token_missing'       => 'not_configured',
		'portal_configuration_invalid'           => 'not_configured',
		'portal_base_url_invalid'                => 'not_configured',
		'portal_integration_token_invalid'       => 'not_configured',
		'portal_configuration_unavailable'       => 'not_configured',
		// PortalHttpClient transport
		'portal_authentication_failed'           => 'credentials_rejected',
		'portal_hall_not_accessible'             => 'hall_inaccessible',
		'invalid_portal_hall_id'                 => 'hall_inaccessible',
		'portal_request_failed'                  => 'portal_unreachable',
		'portal_http_error'                      => 'portal_unexpected',
		'portal_snapshot_unavailable'            => 'portal_unexpected',
		// PortalSnapshotValidator
		'portal_snapshot_invalid_json'           => 'portal_unexpected',
		'portal_snapshot_invalid_contract'       => 'portal_unexpected',
		// PortalSnapshotApplicationPlan
		'portal_snapshot_hall_mismatch'          => 'hall_mismatch',
		'portal_sync_plan_invalid'               => 'hall_mismatch',
		// PortalSnapshotTransactionTransport
		'portal_snapshot_stale'                  => 'snapshot_stale',
		'portal_sync_state_invalid'              => 'state_invalid',
		'portal_game_type_missing'               => 'data_inconsistent',
		'portal_game_type_code_exhausted'        => 'data_inconsistent',
		'portal_sync_mapping_changed'            => 'link_changed',
		'portal_sync_domain_unavailable'         => 'schema_unavailable',
		'portal_sync_schema_unavailable'         => 'schema_unavailable',
		'portal_sync_storage_engine_unsupported' => 'schema_unavailable',
		'portal_sync_persistence_failed'         => 'write_failed',
		'portal_sync_prepare_failed'             => 'write_failed',
		'portal_sync_bind_failed'                => 'write_failed',
		'portal_sync_transaction_start_failed'   => 'write_failed',
		'portal_sync_transaction_state_untrusted' => 'write_failed',
		'portal_sync_transport_nested_use'       => 'write_failed',
		'portal_sync_identifier_rejected'        => 'write_failed',
		'portal_sync_lock_lost'                  => 'write_failed',
		'incompatible_database_handle'           => 'write_failed',
		'invalid_hall_context'                   => 'write_failed',
		'portal_sync_commit_outcome_unknown'     => 'commit_unconfirmed',
	];

	/** Audit action names, following the repository's <domain>.<entity>.<verb> convention. */
	public const AUDIT_SYNCHRONIZED = 'portal.snapshot.synchronized';
	public const AUDIT_FAILED       = 'portal.snapshot.failed';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );

		// Authenticated handler only. A nopriv twin is deliberately NOT
		// registered: an anonymous POST must fall through to WordPress's own
		// "action not supported" path rather than reach any hall logic.
		add_action( 'admin_post_' . self::POST_ACTION, [ self::class, 'handle_post' ] );
	}

	public static function add_menu(): void {
		if ( ! self::is_hall_site() ) {
			return;
		}

		add_menu_page(
			__( 'Portal Sync', 'bingocommerce-portal-integration' ),
			__( 'Portal Sync', 'bingocommerce-portal-integration' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ self::class, 'render' ],
			'dashicons-update',
			57
		);
	}

	/* ------------------------------------------------------------------ *
	 * POST handler
	 * ------------------------------------------------------------------ */

	/**
	 * admin-post.php handler. Never renders; always redirects or dies.
	 *
	 * Synchronization deliberately does NOT run in the page-render callback:
	 * by then WordPress has already emitted admin output, so a redirect would
	 * be impossible and a long Portal round-trip would stall a half-rendered
	 * screen.
	 */
	public static function handle_post(): void {
		// 1. POST only. admin-post.php dispatches GET to the same hook name, so
		// the method gate is what actually makes synchronization unreachable by
		// link, prefetch, or browser history.
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Portal synchronization requires a POST request.', 'bingocommerce-portal-integration' ), '', [ 'response' => 405 ] );
		}

		// 2. Tenancy before authority: the main site and the network admin have
		// no hall to synchronize.
		if ( ! self::is_hall_site() ) {
			wp_die( esc_html__( 'Portal synchronization is only available inside a hall site.', 'bingocommerce-portal-integration' ), '', [ 'response' => 403 ] );
		}

		// 3. Capability. Network Super Admin passes through the same explicit
		// gate the other BingoCommerce screens use.
		if ( ! is_super_admin() && ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not authorized to synchronize this hall with the Portal.', 'bingocommerce-portal-integration' ), '', [ 'response' => 403 ] );
		}

		// 4. Nonce. check_admin_referer() dies on failure.
		check_admin_referer( self::NONCE_ACTION );

		// 5. Trusted target. No request value participates.
		$blog_id = self::site_id();

		// Audit attribution only; the service resolves the mapping itself and
		// remains the single authority for what is synchronized.
		$portal_hall_id = self::mapped_portal_hall_id( $blog_id );

		// 5a. Fail closed if a domain plugin is unavailable, BEFORE any fetch
		// and before a lock is taken. PortalSyncService would also reject this,
		// but only after acquiring the advisory lock.
		if ( [] !== Plugin::missing_sync_dependencies() ) {
			self::finish( $blog_id, self::OUTCOME_ERROR, 'schema_unavailable', null, $portal_hall_id );
		}

		// 6. Phase 3E, exactly once.
		$result = PortalSyncService::synchronize( $blog_id );

		// 7. Reduce to an allowlisted outcome.
		if ( $result instanceof PortalSyncResult ) {
			$outcome = $result->isNoOp() ? self::OUTCOME_NO_CHANGE : self::OUTCOME_APPLIED;
			self::finish( $blog_id, $outcome, '', $result, (int) $result->portal_hall_id );
		}

		$category = self::category_for( $result );
		self::finish( $blog_id, self::OUTCOME_ERROR, $category, null, $portal_hall_id );
	}

	/**
	 * Record the audit event, then redirect and terminate.
	 *
	 * @param PortalSyncResult|null $result Present only on success.
	 */
	private static function finish( int $blog_id, string $outcome, string $category, ?PortalSyncResult $result, ?int $portal_hall_id ): void {
		$audit_ok = self::record_audit( $blog_id, $outcome, $category, $result, $portal_hall_id );

		$args = [ 'page' => self::PAGE_SLUG, 'bc_sync' => $outcome ];
		if ( self::OUTCOME_ERROR === $outcome ) {
			$args['bc_sync_code'] = $category;
		}
		if ( ! $audit_ok ) {
			$args['bc_sync_audit'] = '1';
		}

		// 9/10. PRG. 303 makes the follow-up unambiguously a GET, so a refresh
		// re-renders the notice instead of re-posting the synchronization.
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ), 303 );
		exit;
	}

	/**
	 * @param PortalSyncResult|null $result
	 * @return bool True when the audit event was durably recorded.
	 */
	private static function record_audit( int $blog_id, string $outcome, string $category, ?PortalSyncResult $result, ?int $portal_hall_id ): bool {
		if ( ! class_exists( '\BingoCommerce\Core\AuditLog' ) ) {
			self::log_audit_failure();

			return false;
		}

		$success = self::OUTCOME_ERROR !== $outcome;

		$event = [
			'blog_id'        => $blog_id,                 // trusted current site
			'actor_user_id'  => get_current_user_id(),    // authenticated server context
			'action'         => $success ? self::AUDIT_SYNCHRONIZED : self::AUDIT_FAILED,
			'target_type'    => null === $portal_hall_id ? null : 'portal_hall',
			'target_id'      => $portal_hall_id,
			'previous_value' => null,
			// Counts only: five integers generated by Phase 3E. No Portal name,
			// body, header, credential, message or WP_Error data.
			'new_value'      => $success && null !== $result ? $result->counts() : null,
			'context'        => $success
				? [ 'outcome' => $outcome ]
				: [
					'outcome'        => $outcome,
					'error_category' => $category,
				],
			'operation_id'   => wp_generate_uuid4(),
		];

		// A returned WP_Error and a thrown Throwable are the SAME event here: the
		// synchronization has already committed, so neither may rewrite its
		// outcome or escape to the browser. Reporting a committed sync as a
		// failure would invite a pointless retry, and an uncaught exception would
		// replace the result with a fatal error page. Both paths fall through to
		// the true outcome plus one fixed secondary warning.
		try {
			$recorded = \BingoCommerce\Core\AuditLog::record( $event );
		} catch ( \Throwable $exception ) {
			// The exception object is deliberately never read: its message, class
			// and trace can carry SQL, table names and stored values.
			unset( $exception );
			self::log_audit_failure();

			return false;
		}

		if ( $recorded instanceof \WP_Error ) {
			self::log_audit_failure();

			return false;
		}

		return true;
	}

	/** One fixed message. No error code, data, hall, actor or SQL is logged. */
	private static function log_audit_failure(): void {
		error_log( 'BingoCommerce Portal synchronization completed but its audit event could not be recorded.' );
	}

	/**
	 * Reduce any failure to one allowlisted category.
	 *
	 * @param mixed $result
	 */
	private static function category_for( $result ): string {
		if ( ! $result instanceof \WP_Error ) {
			return 'unknown';
		}

		$code = (string) $result->get_error_code();

		return self::ERROR_CATEGORIES[ $code ] ?? 'unknown';
	}

	/* ------------------------------------------------------------------ *
	 * Page
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		if ( ! self::is_hall_site() || ( ! is_super_admin() && ! current_user_can( self::CAPABILITY ) ) ) {
			wp_die( esc_html__( 'You are not authorized to synchronize this hall with the Portal.', 'bingocommerce-portal-integration' ) );
		}

		$state          = self::readiness();
		$portal_hall_id = $state['portal_hall_id'];
		$last_applied   = self::last_applied_at();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Portal Sync', 'bingocommerce-portal-integration' ); ?></h1>
			<p><?php echo esc_html__( 'Manually pull this hall\'s Game Types and Session templates from the Bingo Innovation Portal. Only this hall is affected.', 'bingocommerce-portal-integration' ); ?></p>

			<?php self::render_notices(); ?>

			<table class="widefat striped" style="max-width:46rem;">
				<caption class="screen-reader-text"><?php echo esc_html__( 'Portal synchronization status for this hall', 'bingocommerce-portal-integration' ); ?></caption>
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Hall', 'bingocommerce-portal-integration' ); ?></th>
						<td><?php echo esc_html( (string) get_option( 'blogname' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Portal Hall', 'bingocommerce-portal-integration' ); ?></th>
						<td>
							<?php
							// "Not linked" is a factual claim about this hall, so it is
							// only made when the mapping was actually read. When the
							// mapping system is unavailable the honest answer is that
							// the link is unknown, not that it is absent.
							if ( null !== $portal_hall_id ) {
								echo esc_html( (string) $portal_hall_id );
							} elseif ( 'absent' === $state['mapping_status'] ) {
								echo esc_html__( 'Not linked', 'bingocommerce-portal-integration' );
							} else {
								echo esc_html__( 'Unavailable', 'bingocommerce-portal-integration' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Last successful synchronization (UTC)', 'bingocommerce-portal-integration' ); ?></th>
						<td>
							<?php
							// Phase 3E stores applied_at via current_time( 'mysql', true ),
							// so the marker value is UTC and is shown as stored rather
							// than being reinterpreted in the hall's timezone.
							echo '' === $last_applied
								? esc_html__( 'Never', 'bingocommerce-portal-integration' )
								/* translators: %s: UTC timestamp of the last successful synchronization. */
								: esc_html( sprintf( __( '%s UTC', 'bingocommerce-portal-integration' ), $last_applied ) );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Status', 'bingocommerce-portal-integration' ); ?></th>
						<td><?php echo esc_html( $state['label'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $state['ready'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>">
					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php echo esc_html__( 'Synchronize now', 'bingocommerce-portal-integration' ); ?>
						</button>
					</p>
				</form>
			<?php else : ?>
				<p><?php echo esc_html( $state['hint'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the fixed notices named by the redirect.
	 *
	 * Every value is allowlisted before use, so a hand-edited query string can
	 * only ever select one of these fixed strings.
	 */
	private static function render_notices(): void {
		$outcome = isset( $_GET['bc_sync'] ) ? sanitize_key( wp_unslash( $_GET['bc_sync'] ) ) : '';
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return;
		}

		if ( self::OUTCOME_ERROR === $outcome ) {
			$code     = isset( $_GET['bc_sync_code'] ) ? sanitize_key( wp_unslash( $_GET['bc_sync_code'] ) ) : '';
			$messages = self::error_messages();
			$message  = $messages[ $code ] ?? $messages['unknown'];
			?>
			<div class="notice notice-error" role="alert"><p><?php echo esc_html( $message ); ?></p></div>
			<?php
		} elseif ( self::OUTCOME_APPLIED === $outcome ) {
			?>
			<div class="notice notice-success" role="status"><p><?php echo esc_html__( 'Portal data synchronized successfully.', 'bingocommerce-portal-integration' ); ?></p></div>
			<?php
		} else {
			?>
			<div class="notice notice-success" role="status"><p><?php echo esc_html__( 'Portal data is already up to date.', 'bingocommerce-portal-integration' ); ?></p></div>
			<?php
		}

		if ( isset( $_GET['bc_sync_audit'] ) && '1' === (string) wp_unslash( $_GET['bc_sync_audit'] ) ) {
			?>
			<div class="notice notice-warning" role="alert"><p><?php echo esc_html__( 'This synchronization attempt could not be recorded in the audit log. Contact Platform Admin.', 'bingocommerce-portal-integration' ); ?></p></div>
			<?php
		}
	}

	/** @return array<string,string> Fixed, category-keyed failure notices. */
	private static function error_messages(): array {
		return [
			'lock_busy'          => __( 'A synchronization is already running for this hall. Try again shortly.', 'bingocommerce-portal-integration' ),
			'not_linked'         => __( 'This hall is not linked to a Portal Hall.', 'bingocommerce-portal-integration' ),
			'link_disabled'      => __( 'The Portal link for this hall is disabled.', 'bingocommerce-portal-integration' ),
			'link_changed'       => __( 'The Portal link changed during synchronization. Nothing was saved.', 'bingocommerce-portal-integration' ),
			'site_ineligible'    => __( 'This site cannot be synchronized with the Portal.', 'bingocommerce-portal-integration' ),
			'not_configured'     => __( 'The Portal connection is not configured. Contact Platform Admin.', 'bingocommerce-portal-integration' ),
			'credentials_rejected' => __( 'The Portal rejected our credentials. Contact Platform Admin.', 'bingocommerce-portal-integration' ),
			'hall_inaccessible'  => __( 'The Portal did not recognize this hall.', 'bingocommerce-portal-integration' ),
			'portal_unreachable' => __( 'The Portal could not be reached. Try again.', 'bingocommerce-portal-integration' ),
			'portal_unexpected'  => __( 'The Portal returned unexpected data. Nothing was saved.', 'bingocommerce-portal-integration' ),
			'hall_mismatch'      => __( 'The Portal data did not belong to this hall. Nothing was saved.', 'bingocommerce-portal-integration' ),
			'snapshot_stale'     => __( 'The Portal returned older data than this hall already has. Nothing changed.', 'bingocommerce-portal-integration' ),
			'state_invalid'      => __( 'This hall\'s synchronization state needs administrator attention.', 'bingocommerce-portal-integration' ),
			'data_inconsistent'  => __( 'The Portal data is inconsistent with this hall. Nothing was saved.', 'bingocommerce-portal-integration' ),
			'schema_unavailable' => __( 'Synchronization is unavailable for this hall. Contact Platform Admin.', 'bingocommerce-portal-integration' ),
			'write_failed'       => __( 'Synchronization failed. Nothing was saved.', 'bingocommerce-portal-integration' ),
			'commit_unconfirmed' => __( 'The synchronization result could not be confirmed. Check this hall before retrying.', 'bingocommerce-portal-integration' ),
			'unknown'            => __( 'Synchronization failed. Nothing was saved.', 'bingocommerce-portal-integration' ),
		];
	}

	/**
	 * Safe readiness state for the current hall.
	 *
	 * @return array{ready:bool,label:string,hint:string,portal_hall_id:int|null,mapping_status:string}
	 */
	private static function readiness(): array {
		$mapping        = self::mapping_state( self::site_id() );
		$portal_hall_id = $mapping['portal_hall_id'];

		$unavailable = [
			'ready'          => false,
			'label'          => __( 'Synchronization unavailable', 'bingocommerce-portal-integration' ),
			'hint'           => __( 'Synchronization is unavailable for this hall. Contact Platform Admin.', 'bingocommerce-portal-integration' ),
			'portal_hall_id' => $portal_hall_id,
			'mapping_status' => $mapping['status'],
		];

		if ( [] !== Plugin::missing_sync_dependencies() ) {
			return $unavailable;
		}

		// A missing schema or a failed mapping read is an infrastructure problem,
		// NOT evidence that this hall has no Portal Hall. Only 'absent' — a clean
		// read that found no active mapping — may say Not linked.
		if ( 'unavailable' === $mapping['status'] ) {
			return $unavailable;
		}

		if ( 'absent' === $mapping['status'] ) {
			return [
				'ready'          => false,
				'label'          => __( 'Not linked', 'bingocommerce-portal-integration' ),
				'hint'           => __( 'This hall is not linked to a Portal Hall.', 'bingocommerce-portal-integration' ),
				'portal_hall_id' => null,
				'mapping_status' => 'absent',
			];
		}

		// Validates the server-side Portal configuration and discards the result
		// object immediately. The credential is never displayed, logged, or
		// persisted here, and integrationToken() is never called.
		if ( PortalConfig::fromConstants() instanceof \WP_Error ) {
			return [
				'ready'          => false,
				'label'          => __( 'Portal connection not configured', 'bingocommerce-portal-integration' ),
				'hint'           => __( 'The Portal connection is not configured. Contact Platform Admin.', 'bingocommerce-portal-integration' ),
				'portal_hall_id' => $portal_hall_id,
				'mapping_status' => $mapping['status'],
			];
		}

		return [
			'ready'          => true,
			'label'          => __( 'Ready', 'bingocommerce-portal-integration' ),
			'hint'           => '',
			'portal_hall_id' => $portal_hall_id,
			'mapping_status' => 'linked',
		];
	}

	/** Last committed synchronization instant for the CURRENT site, or ''. */
	private static function last_applied_at(): string {
		$state = PortalSyncState::readUncached();

		return null === $state ? '' : (string) $state['applied_at'];
	}

	/**
	 * Resolve the active mapping for a TRUSTED blog id.
	 *
	 * Three outcomes are deliberately kept apart, because collapsing them into
	 * "no mapping" would tell a Hall Manager their hall is Not linked during a
	 * database or schema failure — a different problem with a different fix:
	 *
	 *   linked      an active mapping exists; portal_hall_id is set
	 *   absent      the schema is fine and this hall genuinely has no active mapping
	 *   unavailable the mapping schema is missing, or the read itself failed
	 *
	 * Never throws and never fatals. This is a DISPLAY aid only: the POST handler
	 * still lets Phase 3E resolve the mapping authoritatively.
	 *
	 * @return array{status:string,portal_hall_id:int|null}
	 */
	private static function mapping_state( int $blog_id ): array {
		if ( ! Migrations::is_current() ) {
			return [ 'status' => 'unavailable', 'portal_hall_id' => null ];
		}

		$mapping = ( new HallMappingRepository() )->findActiveByBlogId( $blog_id );

		if ( $mapping instanceof \WP_Error ) {
			return [ 'status' => 'unavailable', 'portal_hall_id' => null ];
		}
		if ( null === $mapping ) {
			return [ 'status' => 'absent', 'portal_hall_id' => null ];
		}

		return [ 'status' => 'linked', 'portal_hall_id' => (int) $mapping['portal_hall_id'] ];
	}

	/**
	 * Active mapped Portal Hall ID for a TRUSTED blog id, or null.
	 *
	 * Used only for audit attribution, where an unavailable mapping system and an
	 * absent mapping are equally "no hall id is safely available".
	 */
	private static function mapped_portal_hall_id( int $blog_id ): ?int {
		return self::mapping_state( $blog_id )['portal_hall_id'];
	}

	/**
	 * Hall tenancy, resolved without hard-depending on bingocommerce-core.
	 *
	 * HallContext is the project's canonical helper and is used whenever it is
	 * loaded; the fallback exists only so an activated integration plugin cannot
	 * fatal on a network where Core is missing.
	 */
	private static function is_hall_site(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		if ( class_exists( '\BingoCommerce\Core\HallContext' ) ) {
			return \BingoCommerce\Core\HallContext::is_hall();
		}

		return ! is_main_site();
	}

	private static function site_id(): int {
		if ( class_exists( '\BingoCommerce\Core\HallContext' ) ) {
			return \BingoCommerce\Core\HallContext::site_id();
		}

		return get_current_blog_id();
	}
}
