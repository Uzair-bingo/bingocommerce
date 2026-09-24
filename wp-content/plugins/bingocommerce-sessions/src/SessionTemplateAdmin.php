<?php

namespace BingoCommerce\Sessions;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read-only "Sessions from Portal" screen (§15 workflow items 4-5).
 *
 * Manager-facing label ONLY. The page slug, the class name, the underlying
 * `bingo_portal_session_templates` table and `PortalTemplateGateway` are all
 * unchanged — this screen was called "Synced Templates" and is now called
 * "Sessions from Portal" in every string a Manager reads, and nothing else
 * about it moved.
 *
 * WHAT THIS SCREEN IS. A window onto THIS hall's Portal Session replica, and
 * nothing else. It lists every replicated Portal session with its status and
 * whether it is the current revision, offers "Add to Store" for the ones
 * ready to sell, and — since a Portal session may already have one or more
 * session schedules — surfaces "Manage Dates" for each of those rather than
 * assuming a one-to-one relationship.
 *
 * WHAT IT IS NOT. It has no form, no POST handler and no nonce, because it
 * performs no mutation of any kind — not even a refresh. The replica is owned
 * by Portal synchronization (ADR-035/ADR-040); this screen never writes to it,
 * never creates a Portal session, and never edits one. A Manager who needs new
 * ones runs Portal Sync, which is a different screen owned by a different
 * plugin.
 *
 * ELIGIBILITY IS NOT RE-IMPLEMENTED HERE. Whether a Portal session may start a
 * schedule is `PortalTemplateGateway::is_commerce_eligible()` — the same
 * predicate the create commit proves again in SQL (§3.5). This screen calls it
 * to decide whether to render a link; it does not restate the rule, and a
 * Portal session that passes here is still proved eligible at commit time,
 * because a link is not an authorization.
 *
 * SCHEDULES ARE READ THROUGH THE SERVICE LAYER, NOT SQL. Listing which
 * schedules already exist for a Portal session calls
 * `SessionScheduleService::list_for_administration()` — the same read the
 * Manage Session Dates screen uses — and filters the returned rows by
 * `portal_session_id` in PHP. No query is composed here.
 */
final class SessionTemplateAdmin {

	public const PAGE_SLUG = 'bingocommerce-session-templates';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ], 11 );
	}

	public static function add_menu(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		add_submenu_page(
			SessionAdmin::PAGE_SLUG,
			__( 'Sessions from Portal', 'bingocommerce-sessions' ),
			__( 'Sessions from Portal', 'bingocommerce-sessions' ),
			'bc_manage_sessions',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		/*
		 * Tenancy before authority, the same order the mutating handlers use.
		 * A render callback has no nonce step because it changes nothing.
		 */
		if ( ! HallContext::is_hall() ) {
			wp_die( esc_html__( 'Sessions from Portal is only available inside a hall site.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}
		if ( ! is_super_admin() && ! current_user_can( 'bc_manage_sessions' ) ) {
			wp_die( esc_html__( 'You are not authorized to view this hall\'s Portal sessions.', 'bingocommerce-sessions' ), '', [ 'response' => 403 ] );
		}

		$gateway = new PortalTemplateGateway();
		$page    = SessionAdmin::request_int( wp_unslash( $_GET['paged'] ?? 1 ) );
		$page    = $page > 0 ? $page : 1;

		$templates = $gateway->map( $page );

		// A FAILED READ IS NOT AN EMPTY REPLICA, and is never rendered as one.
		$read_error = is_wp_error( $templates ) ? $templates : null;
		$templates  = is_wp_error( $templates ) ? [] : $templates;

		// One service read, filtered in PHP — never a second call per row.
		$schedules       = ( new SessionScheduleService() )->list_for_administration( 'all' );
		$schedules_error = is_wp_error( $schedules ) ? $schedules : null;
		$schedules_by_portal_session = is_wp_error( $schedules ) ? [] : self::group_by_portal_session( $schedules );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Sessions from Portal', 'bingocommerce-sessions' ); ?></h1>
			<p>
				<?php echo esc_html__( 'The bingo sessions synchronized from Bingo Portal for this hall. This list is read-only: sessions are created and renamed in the Portal, and arrive here through Portal Sync.', 'bingocommerce-sessions' ); ?>
			</p>

			<?php if ( null !== $read_error ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'This hall\'s Portal sessions could not be read, so the list below is incomplete. Do not assume it is empty.', 'bingocommerce-sessions' ); ?></p>
					<p><?php echo esc_html( SessionAdmin::error_message( $read_error ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( null !== $schedules_error ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'Existing session schedules could not be read, so "Manage Dates" links below may be missing. Do not assume none exist.', 'bingocommerce-sessions' ); ?></p>
					<p><?php echo esc_html( SessionAdmin::error_message( $schedules_error ) ); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Portal session', 'bingocommerce-sessions' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'bingocommerce-sessions' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Revision', 'bingocommerce-sessions' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Actions', 'bingocommerce-sessions' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( [] === $templates ) : ?>
					<tr>
						<td colspan="4">
							<?php
							echo esc_html(
								null !== $read_error
									? __( 'No Portal sessions can be listed while the read is failing.', 'bingocommerce-sessions' )
									: __( 'No sessions have been synchronized from Bingo Portal to this hall yet. Run Portal Sync.', 'bingocommerce-sessions' )
							);
							?>
						</td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $templates as $template ) : ?>
					<?php
					$portal_session_id = (int) ( $template['portal_session_id'] ?? 0 );
					$is_current        = ! empty( $template['is_current'] );
					$eligible          = PortalTemplateGateway::is_commerce_eligible( $template );
					$existing          = $schedules_by_portal_session[ $portal_session_id ] ?? [];
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) ( $template['name'] ?? '' ) ); ?></strong>
							<div class="row-actions"><span>#<?php echo esc_html( (string) $portal_session_id ); ?></span></div>
						</td>
						<td><?php echo esc_html( self::status_label( (string) ( $template['portal_status'] ?? '' ) ) ); ?></td>
						<td>
							<?php
							echo esc_html(
								$is_current
									? __( 'Current', 'bingocommerce-sessions' )
									: __( 'Superseded', 'bingocommerce-sessions' )
							);
							?>
						</td>
						<td>
							<?php if ( $eligible ) : ?>
								<a href="<?php echo esc_url( self::create_schedule_url( $portal_session_id ) ); ?>">
									<?php echo esc_html__( 'Add to Store', 'bingocommerce-sessions' ); ?>
								</a>
							<?php else : ?>
								<span><?php echo esc_html( self::ineligible_reason( $template ) ); ?></span>
							<?php endif; ?>

							<?php if ( [] !== $existing ) : ?>
								<?php foreach ( $existing as $schedule ) : ?>
									<br />
									<?php echo esc_html( self::schedule_summary( $schedule ) ); ?>
									<a href="<?php echo esc_url( self::manage_dates_url( (int) $schedule['schedule_id'] ) ); ?>">
										<?php echo esc_html__( 'Manage Dates', 'bingocommerce-sessions' ); ?>
									</a>
									<?php if ( self::needs_retry( $schedule ) ) : ?>
										&mdash;
										<span><?php echo esc_html__( 'Incomplete — open and save this schedule first.', 'bingocommerce-sessions' ); ?></span>
									<?php endif; ?>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( [] !== $templates || $page > 1 ) : ?>
				<p>
					<?php if ( $page > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'paged' => $page - 1 ], admin_url( 'admin.php' ) ) ); ?>">
							<?php echo esc_html__( '&laquo; Previous', 'bingocommerce-sessions' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( [] !== $templates ) : ?>
						<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'paged' => $page + 1 ], admin_url( 'admin.php' ) ) ); ?>">
							<?php echo esc_html__( 'Next &raquo;', 'bingocommerce-sessions' ); ?>
						</a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Every existing schedule, keyed by the Portal session it belongs to.
	 *
	 * MORE THAN ONE PER KEY IS EXPECTED. Nothing here assumes a Portal session
	 * has at most one schedule; a hall may legitimately run several — for
	 * example a weekday schedule and a separate weekend one for the same
	 * Portal session — and every one of them is listed.
	 *
	 * @param array<int,array<string,mixed>> $schedules
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private static function group_by_portal_session( array $schedules ): array {
		$grouped = [];
		foreach ( $schedules as $schedule ) {
			$portal_session_id = (int) ( $schedule['portal_session_id'] ?? 0 );
			$grouped[ $portal_session_id ][] = $schedule;
		}

		return $grouped;
	}

	/** @param array<string,mixed> $schedule */
	private static function schedule_summary( array $schedule ): string {
		$schedule_id = (int) $schedule['schedule_id'];
		$time = ScheduleTimezone::display_time( (string) ( $schedule['start_time_local'] ?? '' ) );
		$archived = SessionScheduleService::STATUS_ARCHIVED === (string) ( $schedule['status'] ?? '' );

		if ( $archived ) {
			/* translators: 1: schedule ID, 2: saved start time. */
			return sprintf( __( 'Archived schedule #%1$d (saved time: %2$s) —', 'bingocommerce-sessions' ), $schedule_id, $time );
		}

		/* translators: 1: schedule ID, 2: saved start time. */
		return sprintf( __( 'Schedule #%1$d (saved time: %2$s) —', 'bingocommerce-sessions' ), $schedule_id, $time );
	}

	/**
	 * Whether this schedule needs a save reminder on Sessions from Portal.
	 *
	 * READS ONLY WHAT THE LIST ROW ALREADY CARRIES. An inert schedule — no
	 * committed definition, because its create CAS affected zero rows (§3.8
	 * step 5) — plainly needs a save, and `active_definition_token` being
	 * empty is exactly that fact, already present on the row this method is
	 * handed. This does NOT re-derive reconciliation state, count occurrences,
	 * or ask whether a fully-converged schedule has "changed since" — that
	 * would duplicate OccurrenceReconciler's own job. The existing Manage Dates
	 * link opens the edit form; this reminder does not trigger generation.
	 *
	 * @param array<string,mixed> $schedule
	 */
	private static function needs_retry( array $schedule ): bool {
		if ( SessionScheduleService::STATUS_ARCHIVED === (string) ( $schedule['status'] ?? '' ) ) {
			return false;
		}

		return '' === (string) ( $schedule['active_definition_token'] ?? '' );
	}

	private static function create_schedule_url( int $portal_session_id ): string {
		return add_query_arg(
			[
				'page'              => SessionScheduleAdmin::PAGE_SLUG,
				'action'            => 'new',
				'portal_session_id' => $portal_session_id,
			],
			admin_url( 'admin.php' )
		);
	}

	private static function manage_dates_url( int $schedule_id ): string {
		return add_query_arg(
			[
				'page' => SessionScheduleAdmin::PAGE_SLUG,
				'edit' => $schedule_id,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Why a listed Portal session cannot be added to the store, in the
	 * Manager's terms.
	 *
	 * @param array<string,mixed> $template
	 */
	private static function ineligible_reason( array $template ): string {
		if ( empty( $template['is_current'] ) ) {
			return __( 'Superseded revisions cannot be added to the store.', 'bingocommerce-sessions' );
		}

		return __( 'Only active Portal sessions can be added to the store.', 'bingocommerce-sessions' );
	}

	private static function status_label( string $status ): string {
		switch ( $status ) {
			case 'active':
				return __( 'Active', 'bingocommerce-sessions' );
			case 'draft':
				return __( 'Draft', 'bingocommerce-sessions' );
			case 'archived':
				return __( 'Archived', 'bingocommerce-sessions' );
		}

		// Never echoed raw: an unrecognised Portal status is named as unknown
		// rather than presented as if this screen understood it.
		return __( 'Unknown', 'bingocommerce-sessions' );
	}
}
