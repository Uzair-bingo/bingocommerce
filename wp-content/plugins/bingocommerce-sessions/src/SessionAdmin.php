<?php

namespace BingoCommerce\Sessions;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal current-hall WordPress administration for Bingo Sessions.
 *
 * Presentation and request marshalling only. Every domain rule — validation,
 * lifecycle, Package eligibility, the BR-051/BR-063 window — lives in
 * SessionService and is never re-implemented here.
 *
 * There is deliberately NO structured time control: BR-050 makes `session_date`
 * the only structured schedule value, and time exists only as human text inside
 * the display name.
 */
final class SessionAdmin {

	/** Public because the two schedule screens attach as submenus of this page. */
	public const PAGE_SLUG = 'bingocommerce-sessions';

	private const NONCE_ACTION = 'bingocommerce_session_mutation';

	/** Rows per administrative page. One unbounded table does not scale past a few schedules. */
	private const PER_PAGE = 20;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_date_picker' ], 20 );
	}

	/** Use WordPress's bundled calendar picker only on the Bingo Sessions page. */
	public static function enqueue_date_picker(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page || ! HallContext::is_hall() || ! current_user_can( 'bc_manage_sessions' ) ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-datepicker' );
		if ( function_exists( 'WC' ) ) {
			wp_enqueue_style( 'jquery-ui-style', WC()->plugin_url() . '/assets/css/jquery-ui/jquery-ui.min.css', [], WC_VERSION );
		}
	}

	public static function add_menu(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		add_menu_page(
			__( 'Bingo Sessions', 'bingocommerce-sessions' ),
			__( 'Bingo Sessions', 'bingocommerce-sessions' ),
			'bc_manage_sessions',
			self::PAGE_SLUG,
			[ self::class, 'render' ],
			'dashicons-calendar-alt',
			56
		);
	}

	public static function render(): void {
		if ( ! HallContext::is_hall() || ( ! is_super_admin() && ! current_user_can( 'bc_manage_sessions' ) ) ) {
			wp_die( esc_html__( 'You are not authorized to manage Bingo Sessions for this hall.', 'bingocommerce-sessions' ) );
		}

		$service = new SessionService();
		$result  = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$result = self::handle_request( wp_unslash( $_POST ), $service );
		}

		// 'upcoming' — active AND not before hall-local today — is the
		// DEFAULT landing view. 'all', 'active' and 'archived' are unchanged
		// and remain how a Manager reaches historical or archived Sessions.
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? SessionService::ADMIN_STATUS_UPCOMING ) );
		if ( ! in_array( $status, [ SessionService::ADMIN_STATUS_UPCOMING, 'all', 'active', 'archived' ], true ) ) {
			$status = SessionService::ADMIN_STATUS_UPCOMING;
		}
		$page = isset( $_GET['paged'] ) ? self::request_int( wp_unslash( $_GET['paged'] ) ) : 1;
		$page = max( 1, $page );

		/*
		 * One extra row is the "is there a next page" peek: reading PER_PAGE
		 * rows' worth of OFFSET but one extra row of LIMIT avoids a separate
		 * COUNT query while still deciding whether Next appears. $per_page
		 * (PER_PAGE) alone decides the offset; $limit is the one thing that
		 * differs from it.
		 */
		$sessions       = $service->listForAdministration( $status, $page, self::PER_PAGE, self::PER_PAGE + 1 );
		$sessions_error = is_wp_error( $sessions ) ? $sessions : null;
		$sessions       = is_wp_error( $sessions ) ? [] : $sessions;
		$has_next_page  = count( $sessions ) > self::PER_PAGE;
		$sessions       = array_slice( $sessions, 0, self::PER_PAGE );

		$packages       = self::package_map( $sessions );
		$packages_error = is_wp_error( $packages ) ? $packages : null;
		$packages       = is_wp_error( $packages ) ? [] : $packages;

		$editing = isset( $_GET['edit'] ) ? self::request_int( wp_unslash( $_GET['edit'] ) ) : 0;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bingo Sessions', 'bingocommerce-sessions' ); ?></h1>
			<p><?php echo esc_html__( 'A Bingo Session is one dated calendar occurrence for this hall. Put any time in the display name — the date is the only scheduled value.', 'bingocommerce-sessions' ); ?></p>

			<?php self::render_notice( $result ); ?>

			<?php if ( null !== $sessions_error ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'This hall\'s Bingo Sessions could not be read, so the list below is incomplete. Do not assume it is empty. Contact Platform Admin.', 'bingocommerce-sessions' ); ?></p>
					<p><?php echo esc_html( self::error_message( $sessions_error ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( null !== $packages_error ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'Bingo Packages could not be read, so no Package assignment can be offered safely right now. Contact Platform Admin.', 'bingocommerce-sessions' ); ?></p>
					<p><?php echo esc_html( self::error_message( $packages_error ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $editing > 0 ) : ?>
				<?php self::render_edit_form( $service, $editing, $packages, null !== $packages_error ); ?>
			<?php else : ?>
				<?php self::render_create_form(); ?>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Sessions in this hall', 'bingocommerce-sessions' ); ?></h2>
			<ul class="subsubsub">
				<?php
				$status_tabs = [
					SessionService::ADMIN_STATUS_UPCOMING => __( 'Upcoming', 'bingocommerce-sessions' ),
					'all'                                 => __( 'All', 'bingocommerce-sessions' ),
					'active'                               => __( 'Active', 'bingocommerce-sessions' ),
					'archived'                             => __( 'Archived', 'bingocommerce-sessions' ),
				];
				?>
				<?php foreach ( $status_tabs as $key => $label ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'status' => $key ], admin_url( 'admin.php' ) ) ); ?>"
							<?php echo $status === $key ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Session', 'bingocommerce-sessions' ); ?></th>
						<th><?php echo esc_html__( 'Date', 'bingocommerce-sessions' ); ?></th>
						<th><?php echo esc_html__( 'Enabled by manager', 'bingocommerce-sessions' ); ?></th>
						<th><?php echo esc_html__( 'Available to customers today', 'bingocommerce-sessions' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'bingocommerce-sessions' ); ?></th>
						<th><?php echo esc_html__( 'Bingo Packages', 'bingocommerce-sessions' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'bingocommerce-sessions' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( [] === $sessions && null === $sessions_error ) : ?>
						<tr><td colspan="7"><?php echo esc_html__( 'No Bingo Sessions yet.', 'bingocommerce-sessions' ); ?></td></tr>
					<?php elseif ( [] === $sessions ) : ?>
						<tr><td colspan="7"><?php echo esc_html__( 'The Bingo Session list is unavailable.', 'bingocommerce-sessions' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $sessions as $session ) : ?>
						<?php
						$known       = self::is_known_status( (string) $session['status'] );
						$is_active   = SessionService::STATUS_ACTIVE === $session['status'];
						$is_archived = SessionService::STATUS_ARCHIVED === $session['status'];
						?>
						<?php $generated = ! empty( $session['is_schedule_owned'] ); ?>
						<tr>
							<td>#<?php echo esc_html( (string) $session['session_id'] ); ?> — <?php echo esc_html( (string) $session['display_name'] ); ?><?php if ( $generated ) : ?> <span class="description">(<?php echo esc_html__( 'schedule-managed', 'bingocommerce-sessions' ); ?>)</span><?php endif; ?></td>
							<td><?php echo esc_html( self::display_date( (string) $session['session_date'] ) ); ?></td>
							<td><?php echo $session['available_for_sale'] ? esc_html__( 'ON', 'bingocommerce-sessions' ) : esc_html__( 'OFF', 'bingocommerce-sessions' ); ?></td>
							<td>
								<strong><?php echo $session['available_for_sale_today'] ? esc_html__( 'Yes', 'bingocommerce-sessions' ) : esc_html__( 'No', 'bingocommerce-sessions' ); ?></strong>
								<br><span class="description"><?php echo esc_html( self::window_explanation( $session ) ); ?></span>
							</td>
							<td>
								<?php echo esc_html( self::status_label( (string) $session['status'] ) ); ?>
								<?php if ( ! $known ) : ?>
									<br><em><?php echo esc_html__( 'Contact Platform Admin. No lifecycle action is offered for an inconsistent Session.', 'bingocommerce-sessions' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( self::assignment_summary( $session, $packages, null !== $packages_error ) ); ?></td>
							<td>
								<?php if ( $generated ) : ?>
									<?php
									/*
									 * ADR-043 §9: rename(), reschedule(), updateDetails(),
									 * archive() and restore() are ALL refused for a
									 * schedule-owned row with schedule_owned_occurrence, so
									 * Edit/Archive/Restore are never offered for one. This is
									 * presentation only — the service refusal above is the
									 * enforcement, and both are tested. Enable/Disable Sales
									 * is the one action a generated row DOES offer, and it
									 * goes through the SAME setAvailableForSale() guarded
									 * path — turn_on()/turn_off() — that proves ownership,
									 * template eligibility, completion and Package
									 * assignability, and it does not bypass the sales window.
									 * "Schedule-managed" is stated once per row, in the Session
									 * column next to the name — not repeated here too.
									 */
									?>
									<?php if ( $is_active ) : ?>
										<form method="post" style="display:inline">
											<?php wp_nonce_field( self::NONCE_ACTION ); ?>
											<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session['session_id'] ); ?>">
											<?php if ( $session['available_for_sale'] ) : ?>
												<input type="hidden" name="bc_session_action" value="disable_sales">
												<button type="submit" class="button button-small"><?php echo esc_html__( 'Disable Sales', 'bingocommerce-sessions' ); ?></button>
											<?php elseif ( ! empty( $session['belongs_to_current_definition'] ) ) : ?>
												<input type="hidden" name="bc_session_action" value="enable_sales">
												<button type="submit" class="button button-small"><?php echo esc_html__( 'Enable Sales', 'bingocommerce-sessions' ); ?></button>
											<?php endif; ?>
										</form>
									<?php endif; ?>
								<?php else : ?>
									<?php if ( $is_active ) : ?>
										<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'status' => $status, 'edit' => (int) $session['session_id'] ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Edit', 'bingocommerce-sessions' ); ?></a>
										&nbsp;
									<?php endif; ?>
									<?php if ( $is_active || $is_archived ) : ?>
										<form method="post" style="display:inline">
											<?php wp_nonce_field( self::NONCE_ACTION ); ?>
											<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session['session_id'] ); ?>">
											<?php if ( $is_active ) : ?>
												<input type="hidden" name="bc_session_action" value="archive">
												<button type="submit" class="button button-small"><?php echo esc_html__( 'Archive', 'bingocommerce-sessions' ); ?></button>
											<?php else : ?>
												<input type="hidden" name="bc_session_action" value="restore">
												<button type="submit" class="button button-small"><?php echo esc_html__( 'Restore', 'bingocommerce-sessions' ); ?></button>
											<?php endif; ?>
										</form>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $page > 1 || $has_next_page ) : ?>
				<p class="tablenav-pages">
					<?php if ( $page > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'status' => $status, 'paged' => $page - 1 ], admin_url( 'admin.php' ) ) ); ?>">&laquo; <?php echo esc_html__( 'Previous', 'bingocommerce-sessions' ); ?></a>
					<?php endif; ?>
					<?php printf( esc_html__( 'Page %d', 'bingocommerce-sessions' ), (int) $page ); ?>
					<?php if ( $has_next_page ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'status' => $status, 'paged' => $page + 1 ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Next', 'bingocommerce-sessions' ); ?> &raquo;</a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<script>
		jQuery( function ( $ ) {
			if ( ! $.fn.datepicker ) { return; }
			$( '.bc-session-date' ).datepicker( {
				dateFormat: 'mm/dd/yy',
				showOn: 'both',
				buttonText: <?php echo wp_json_encode( __( 'Choose date', 'bingocommerce-sessions' ) ); ?>
			} );
		} );
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Forms
	 * ------------------------------------------------------------------ */

	private static function render_create_form(): void {
		?>
		<h2><?php echo esc_html__( 'Schedule a Session', 'bingocommerce-sessions' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="bc_session_action" value="create">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bc-session-date"><?php echo esc_html__( 'Session date', 'bingocommerce-sessions' ); ?></label></th>
					<td>
						<input id="bc-session-date" type="text" class="regular-text bc-session-date" name="session_date" placeholder="MM/DD/YYYY" maxlength="10" required>
						<p class="description"><?php echo esc_html__( 'Enter the date as MM/DD/YYYY. The calendar date is the only scheduled value. Sales open 30 calendar days before it, in this hall\'s timezone.', 'bingocommerce-sessions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bc-session-name"><?php echo esc_html__( 'Display name', 'bingocommerce-sessions' ); ?></label></th>
					<td>
						<input id="bc-session-name" type="text" class="regular-text" name="display_name" maxlength="191" required>
						<p class="description"><?php echo esc_html__( 'For example "Friday Evening – 7:00 PM". Any time here is shown to people only and is never used by the system.', 'bingocommerce-sessions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Available for Sale', 'bingocommerce-sessions' ); ?></th>
					<td>
						<label><input type="checkbox" name="available_for_sale" value="1"> <?php echo esc_html__( 'On', 'bingocommerce-sessions' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Turning this on does not bypass the 30-day window.', 'bingocommerce-sessions' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Create Session', 'bingocommerce-sessions' ) ); ?>
		</form>
		<?php
	}

	/** @param array<int,array<string,mixed>> $packages */
	private static function render_edit_form( SessionService $service, int $session_id, array $packages, bool $packages_unavailable ): void {
		$session = $service->getById( $session_id );
		if ( is_wp_error( $session ) ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( self::error_message( $session ) ); ?></p></div>
			<?php
			return;
		}

		if ( SessionService::STATUS_ARCHIVED === $session['status'] ) {
			?>
			<div class="notice notice-warning"><p><?php echo esc_html__( 'This Session is archived. Its Package assignments are preserved, but it cannot be edited. Restore it first.', 'bingocommerce-sessions' ); ?></p></div>
			<?php
			return;
		}

		if ( SessionService::STATUS_ACTIVE !== $session['status'] ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'This Session holds an unrecognised lifecycle value. No edit or lifecycle action is offered. Contact Platform Admin.', 'bingocommerce-sessions' ); ?></p></div>
			<?php
			return;
		}

		$generated = ! empty( $session['is_schedule_owned'] );
		$complete  = null !== ( $session['generation_completed_at'] ?? null );

		?>
		<h2><?php printf( esc_html__( 'Edit Session #%d', 'bingocommerce-sessions' ), (int) $session_id ); ?></h2>

		<?php if ( $generated ) : ?>
			<?php
			/*
			 * ADR-043 §9: reached ONLY by direct navigation to ?edit=<id> for a
			 * schedule-owned row, since the list table above no longer links to
			 * it. rename(), reschedule(), updateDetails(), archive() and
			 * restore() are ALL refused with schedule_owned_occurrence, so no
			 * form that would submit any of them is offered — a read-only
			 * summary is shown instead. This is presentation only: the service
			 * refusal is what actually enforces it, and both are tested.
			 */
			?>
			<div class="notice notice-info">
				<p><?php echo esc_html__( 'This Session was generated by a commerce schedule. Its name, date, time and lifecycle are managed through the schedule, not edited directly.', 'bingocommerce-sessions' ); ?></p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Session date', 'bingocommerce-sessions' ); ?></th>
					<td><?php echo esc_html( self::display_date( (string) $session['session_date'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Display name', 'bingocommerce-sessions' ); ?></th>
					<td><?php echo esc_html( (string) $session['display_name'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Generation', 'bingocommerce-sessions' ); ?></th>
					<td><?php echo $complete ? esc_html__( 'Complete', 'bingocommerce-sessions' ) : esc_html__( 'Still being generated', 'bingocommerce-sessions' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Available for Sale', 'bingocommerce-sessions' ); ?></th>
					<td>
						<?php echo $session['available_for_sale'] ? esc_html__( 'On', 'bingocommerce-sessions' ) : esc_html__( 'Off', 'bingocommerce-sessions' ); ?>
						<p class="description"><?php echo esc_html( self::window_explanation( $session ) ); ?></p>
					</td>
				</tr>
			</table>
			<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Back to Sessions', 'bingocommerce-sessions' ); ?></a>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="bc_session_action" value="update">
				<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bc-edit-date"><?php echo esc_html__( 'Session date', 'bingocommerce-sessions' ); ?></label></th>
						<td>
							<input id="bc-edit-date" type="text" class="regular-text bc-session-date" name="session_date" value="<?php echo esc_attr( self::display_date( (string) $session['session_date'] ) ); ?>" placeholder="MM/DD/YYYY" maxlength="10" required>
							<p class="description"><?php echo esc_html__( 'Enter the date as MM/DD/YYYY.', 'bingocommerce-sessions' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bc-edit-name"><?php echo esc_html__( 'Display name', 'bingocommerce-sessions' ); ?></label></th>
						<td><input id="bc-edit-name" type="text" class="regular-text" name="display_name" maxlength="191" value="<?php echo esc_attr( (string) $session['display_name'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Available for Sale', 'bingocommerce-sessions' ); ?></th>
						<td>
							<label><input type="checkbox" name="available_for_sale" value="1" <?php checked( (bool) $session['available_for_sale'] ); ?>> <?php echo esc_html__( 'On', 'bingocommerce-sessions' ); ?></label>
							<p class="description"><?php echo esc_html( self::window_explanation( $session ) ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Session', 'bingocommerce-sessions' ) ); ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Back to Sessions', 'bingocommerce-sessions' ); ?></a>
			</form>
		<?php endif; ?>

		<?php if ( $generated && ! $complete ) : ?>
			<h3><?php echo esc_html__( 'Assigned Bingo Packages', 'bingocommerce-sessions' ); ?></h3>
			<p><?php echo esc_html__( 'This occurrence is still being generated. Its Bingo Packages cannot be changed until generation completes.', 'bingocommerce-sessions' ); ?></p>
			<?php
			return;
		endif;

		// A failed read is NOT "no Packages available". Keep the error and
		// withhold the selector entirely rather than inviting a choice from a
		// list that could not be built.
		$assignable       = $service->assignablePackages( $session_id );
		$assignable_error = is_wp_error( $assignable ) ? $assignable : null;
		$assignable       = is_wp_error( $assignable ) ? [] : $assignable;
		?>

		<h3><?php echo esc_html__( 'Assigned Bingo Packages', 'bingocommerce-sessions' ); ?></h3>
		<?php if ( $packages_unavailable ) : ?>
			<p><?php echo esc_html__( 'Bingo Packages could not be read, so assignments cannot be shown or changed safely.', 'bingocommerce-sessions' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:52em">
				<tbody>
					<?php if ( [] === $session['assignments'] ) : ?>
						<tr><td><?php echo esc_html__( 'No Bingo Packages assigned yet.', 'bingocommerce-sessions' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $session['assignments'] as $assignment ) : ?>
						<tr>
							<td><?php echo esc_html( self::package_label( (int) $assignment['package_id'], $packages ) ); ?></td>
							<td style="width:8em">
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="bc_session_action" value="remove_package">
									<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
									<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $assignment['package_id'] ); ?>">
									<button type="submit" class="button button-small"><?php echo esc_html__( 'Remove', 'bingocommerce-sessions' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Assign a Bingo Package', 'bingocommerce-sessions' ); ?></h3>
			<?php if ( null !== $assignable_error ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'The list of assignable Bingo Packages could not be built, so no assignment is offered. This is NOT the same as having none available. Contact Platform Admin.', 'bingocommerce-sessions' ); ?></p>
					<p><?php echo esc_html( self::error_message( $assignable_error ) ); ?></p>
				</div>
			<?php elseif ( [] === $assignable ) : ?>
				<p><?php echo esc_html__( 'No further active Bingo Packages are available to assign. Archived Packages and Packages with an integrity fault are not offered.', 'bingocommerce-sessions' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="bc_session_action" value="assign_package">
					<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
					<select name="package_id" required>
						<?php foreach ( $assignable as $package ) : ?>
							<option value="<?php echo esc_attr( (string) $package['package_id'] ); ?>"><?php echo esc_html( self::describe_package( $package ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Assign Package', 'bingocommerce-sessions' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Request handling
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $post
	 * @return SessionResult|\WP_Error
	 */
	private static function handle_request( array $post, SessionService $service ) {
		$action     = sanitize_key( $post['bc_session_action'] ?? '' );
		$session_id = self::request_int( $post['session_id'] ?? null );

		switch ( $action ) {
			case 'create':
				$session_date = self::input_date( (string) ( $post['session_date'] ?? '' ) );
				if ( is_wp_error( $session_date ) ) {
					return $session_date;
				}

				return $service->create(
					$session_date,
					(string) ( $post['display_name'] ?? '' ),
					! empty( $post['available_for_sale'] )
				);

			case 'update':
				$session_date = self::input_date( (string) ( $post['session_date'] ?? '' ) );
				if ( is_wp_error( $session_date ) ) {
					return $session_date;
				}

				// ONE atomic domain mutation. Chaining three writers here could
				// commit an earlier field and then fail, leaving the screen
				// reporting a Save that partly happened.
				return $service->updateDetails(
					$session_id,
					$session_date,
					(string) ( $post['display_name'] ?? '' ),
					! empty( $post['available_for_sale'] )
				);

			case 'archive':
				return $service->archive( $session_id );

			case 'restore':
				return $service->restore( $session_id );

			case 'enable_sales':
				return $service->setAvailableForSale( $session_id, true );

			case 'disable_sales':
				return $service->setAvailableForSale( $session_id, false );

			case 'assign_package':
				return $service->assignPackage( $session_id, self::request_int( $post['package_id'] ?? null ) );

			case 'remove_package':
				return $service->removePackage( $session_id, self::request_int( $post['package_id'] ?? null ) );
		}

		return new \WP_Error( 'unsupported_action', __( 'That Bingo Session action is not supported.', 'bingocommerce-sessions' ) );
	}

	/**
	 * Parse a request value as an integer WITHOUT changing what it means.
	 *
	 * `absint()` is deliberately not used: it maps -5 to 5, converting invalid
	 * input into a different, valid-looking identity. Anything that is not a
	 * plain optionally-signed integer becomes 0, which the domain rejects.
	 */
	/**
	 * Request integers, per §13.1 — deliberately NOT `absint()`.
	 *
	 * `absint( '-5' )` is 5, which silently turns a malformed or hostile id into
	 * a DIFFERENT, valid-looking one. This returns -5, which no lookup matches.
	 * Public because §13.1 names it as the ONE helper every Sessions admin
	 * surface uses, so the schedule and template screens cannot drift onto a
	 * laxer rule.
	 *
	 * @param mixed $value
	 */
	public static function request_int( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d+\s*$/', $value ) ) {
			return (int) trim( $value );
		}

		return 0;
	}

	/* ------------------------------------------------------------------ *
	 * Presentation helpers
	 * ------------------------------------------------------------------ */

	/** Valid stored Y-m-d values are shown as MM/DD/YYYY; invalid values stay visible. */
	private static function display_date( string $date ): string {
		if ( 1 !== preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts ) ) {
			return $date;
		}

		$year  = (int) $parts[1];
		$month = (int) $parts[2];
		$day   = (int) $parts[3];

		return checkdate( $month, $day, $year ) ? sprintf( '%02d/%02d/%04d', $month, $day, $year ) : $date;
	}

	/** @return string|\WP_Error Canonical Y-m-d or a strict input refusal. */
	private static function input_date( string $date ) {
		if ( 1 !== preg_match( '/^([0-9]{2})\/([0-9]{2})\/([0-9]{4})$/D', $date, $parts ) ) {
			return new \WP_Error( 'invalid_session_date', __( 'Enter a real calendar date in MM/DD/YYYY form.', 'bingocommerce-sessions' ) );
		}

		$month = (int) $parts[1];
		$day   = (int) $parts[2];
		$year  = (int) $parts[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return new \WP_Error( 'invalid_session_date', __( 'Enter a real calendar date in MM/DD/YYYY form.', 'bingocommerce-sessions' ) );
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * ONE supported Package administration read, mapped by package_id, plus a
	 * narrow fallback for assigned ids outside that page. This is the N+1
	 * avoidance path: a screen with many Sessions issues one Catalog read, not
	 * one per assignment.
	 *
	 * @param array<int,array<string,mixed>> $sessions
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private static function package_map( array $sessions ) {
		$gateway = new PackageGateway();
		$map     = $gateway->map();
		if ( is_wp_error( $map ) ) {
			return $map;
		}

		$needed = [];
		foreach ( $sessions as $session ) {
			foreach ( (array) ( $session['assigned_package_ids'] ?? [] ) as $package_id ) {
				$needed[ (int) $package_id ] = true;
			}
		}

		foreach ( array_keys( $needed ) as $package_id ) {
			if ( isset( $map[ $package_id ] ) ) {
				continue;
			}

			// Outside the first administrative page. Resolve it explicitly.
			$package = $gateway->get( (int) $package_id );

			if ( ! is_wp_error( $package ) ) {
				$map[ (int) $package_id ] = $package;
				continue;
			}

			// A relationship whose Package genuinely no longer resolves is a real,
			// displayable state: the assignment exists and the Package does not.
			// It is rendered as UNRESOLVED by package_label().
			if ( 'package_not_found' === $package->get_error_code() ) {
				continue;
			}

			// Anything else — a persistence failure, an unavailable dependency —
			// is NOT evidence about this Package. Propagate so the screen enters
			// its fail-closed "Package details unavailable" state.
			return $package;
		}

		return $map;
	}

	/** @param array<int,array<string,mixed>> $packages */
	private static function package_label( int $package_id, array $packages ): string {
		if ( ! isset( $packages[ $package_id ] ) ) {
			return sprintf(
				/* translators: %d: Bingo Package ID */
				__( 'Bingo Package #%d — UNRESOLVED, contact Platform Admin', 'bingocommerce-sessions' ),
				$package_id
			);
		}

		return self::describe_package( $packages[ $package_id ] );
	}

	/** @param array<string,mixed> $package */
	private static function describe_package( array $package ): string {
		$title  = get_the_title( (int) $package['wc_product_id'] );
		$label  = '' === (string) $title
			? sprintf( __( 'Bingo Package #%d', 'bingocommerce-sessions' ), (int) $package['package_id'] )
			: sprintf( '%s (#%d)', $title, (int) $package['package_id'] );
		$suffix = [];

		$type = (string) ( $package['package_type'] ?? '' );
		if ( in_array( $type, [ 'paper', 'digital', 'hybrid' ], true ) ) {
			$suffix[] = ucfirst( $type );
		}
		if ( SessionService::STATUS_ARCHIVED === ( $package['status'] ?? '' ) ) {
			$suffix[] = __( 'archived Package', 'bingocommerce-sessions' );
		} elseif ( ! in_array( $package['status'] ?? '', [ 'active', 'archived' ], true ) ) {
			$suffix[] = __( 'INCONSISTENT STATE', 'bingocommerce-sessions' );
		}
		if ( ! empty( $package['has_unresolved_component'] ) ) {
			$suffix[] = __( 'INTEGRITY FAULT', 'bingocommerce-sessions' );
		} elseif ( ! empty( $package['has_archived_component'] ) ) {
			$suffix[] = __( 'contains an archived component', 'bingocommerce-sessions' );
		}

		return [] === $suffix ? $label : $label . ' — ' . implode( ', ', $suffix );
	}

	/**
	 * @param array<string,mixed>            $session
	 * @param array<int,array<string,mixed>> $packages
	 */
	private static function assignment_summary( array $session, array $packages, bool $packages_unavailable ): string {
		if ( [] === $session['assignments'] ) {
			return __( '—', 'bingocommerce-sessions' );
		}
		if ( $packages_unavailable ) {
			return sprintf(
				/* translators: %d: number of assignments */
				__( '%d assigned (Package details unavailable)', 'bingocommerce-sessions' ),
				count( $session['assignments'] )
			);
		}

		$parts = [];
		foreach ( $session['assignments'] as $assignment ) {
			$parts[] = self::package_label( (int) $assignment['package_id'], $packages );
		}

		return implode( '; ', $parts );
	}

	/**
	 * Explain the sales window WITHOUT parsing an authoritative stored date here.
	 *
	 * Lifecycle is handled as three explicit cases, and a corrupt stored date is
	 * reported as unavailable rather than thrown on or silently normalised into a
	 * plausible-looking window.
	 *
	 * @param array<string,mixed> $session
	 */
	private static function window_explanation( array $session ): string {
		$status = (string) $session['status'];

		if ( SessionService::STATUS_ARCHIVED === $status ) {
			return __( 'Archived Sessions are never on sale.', 'bingocommerce-sessions' );
		}
		if ( SessionService::STATUS_ACTIVE !== $status ) {
			return __( 'This Session holds an unrecognised lifecycle value; sales eligibility is unavailable. Contact Platform Admin.', 'bingocommerce-sessions' );
		}

		$opens = SessionService::salesWindowStartDate( (string) $session['session_date'] );
		if ( null === $opens ) {
			return __( 'Session date is invalid; sales eligibility is unavailable. Contact Platform Admin.', 'bingocommerce-sessions' );
		}

		if ( empty( $session['available_for_sale'] ) ) {
			return __( 'Available for Sale is OFF.', 'bingocommerce-sessions' );
		}

		return sprintf(
			/* translators: 1: window open date, 2: session date, 3: hall-local today */
			__( 'Sales window %1$s to %2$s; hall-local today is %3$s.', 'bingocommerce-sessions' ),
			self::display_date( $opens ),
			self::display_date( (string) $session['session_date'] ),
			self::display_date( SessionService::hall_local_today() )
		);
	}

	private static function is_known_status( string $status ): bool {
		return in_array( $status, [ SessionService::STATUS_ACTIVE, SessionService::STATUS_ARCHIVED ], true );
	}

	/** Three explicit cases. An unrecognised value is NEVER coerced into Archived. */
	private static function status_label( string $status ): string {
		if ( SessionService::STATUS_ACTIVE === $status ) {
			return __( 'Active', 'bingocommerce-sessions' );
		}
		if ( SessionService::STATUS_ARCHIVED === $status ) {
			return __( 'Archived', 'bingocommerce-sessions' );
		}

		return __( 'Inconsistent state', 'bingocommerce-sessions' );
	}

	private static function render_notice( $result ): void {
		if ( null === $result ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( self::error_message( $result ) ); ?></p></div>
			<?php
			return;
		}

		$messages = [
			'session_created'                  => __( 'Bingo Session created.', 'bingocommerce-sessions' ),
			'session_updated'                  => __( 'Session saved.', 'bingocommerce-sessions' ),
			'session_unchanged'                => __( 'Nothing changed, so nothing was saved.', 'bingocommerce-sessions' ),
			'session_renamed'                  => __( 'Display name updated.', 'bingocommerce-sessions' ),
			'session_rescheduled'              => __( 'Session date updated.', 'bingocommerce-sessions' ),
			'session_availability_changed'     => __( 'Available for Sale updated.', 'bingocommerce-sessions' ),
			'session_archived'                 => __( 'Bingo Session archived. Its Package assignments were preserved.', 'bingocommerce-sessions' ),
			'session_restored'                 => __( 'Bingo Session restored with its Package assignments intact.', 'bingocommerce-sessions' ),
			'session_package_assigned'         => __( 'Bingo Package assigned to the Session.', 'bingocommerce-sessions' ),
			'session_package_removed'          => __( 'Bingo Package removed from the Session.', 'bingocommerce-sessions' ),
			'session_name_unchanged'           => __( 'The display name was unchanged.', 'bingocommerce-sessions' ),
			'session_date_unchanged'           => __( 'The Session date was unchanged.', 'bingocommerce-sessions' ),
			'session_availability_unchanged'   => __( 'Available for Sale was unchanged.', 'bingocommerce-sessions' ),
			'session_already_archived'         => __( 'The Session is already archived.', 'bingocommerce-sessions' ),
			'session_already_active'           => __( 'The Session is already active.', 'bingocommerce-sessions' ),
			'session_package_already_assigned' => __( 'That Bingo Package is already assigned to this Session.', 'bingocommerce-sessions' ),
			'session_package_not_assigned'     => __( 'That Bingo Package was not assigned to this Session.', 'bingocommerce-sessions' ),
		];
		?>
		<div class="notice notice-success"><p><?php echo esc_html( $messages[ $result->status ] ?? __( 'Bingo Session updated.', 'bingocommerce-sessions' ) ); ?></p></div>
		<?php foreach ( $result->warnings as $warning ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html( self::warning_message( (string) ( $warning['code'] ?? '' ) ) ); ?></p></div>
		<?php endforeach; ?>
		<?php
	}

	private static function warning_message( string $code ): string {
		$messages = [
			'audit_write_failed' => __( 'The Session change succeeded, but its audit event could not be recorded. Contact Platform Admin.', 'bingocommerce-sessions' ),
			'state_read_failed'  => __( 'The Session change succeeded, but its updated state could not be read back. Reload this screen.', 'bingocommerce-sessions' ),
		];

		return $messages[ $code ] ?? __( 'The Session change succeeded with a warning. Reload this screen.', 'bingocommerce-sessions' );
	}

	public static function error_message( \WP_Error $error ): string {
		$messages = [
			'session_management_denied'    => __( 'You are not authorized to manage Bingo Sessions for this hall.', 'bingocommerce-sessions' ),
			'authentication_required'      => __( 'You must be signed in to manage Bingo Sessions.', 'bingocommerce-sessions' ),
			'invalid_hall_context'         => __( 'Bingo Sessions may be managed only from a hall site.', 'bingocommerce-sessions' ),
			'sessions_schema_unavailable'  => __( 'This hall\'s Session schema is not ready. Contact Platform Admin.', 'bingocommerce-sessions' ),
			'session_not_found'            => __( 'The Bingo Session was not found.', 'bingocommerce-sessions' ),
			'session_not_active'           => __( 'The Session is archived. Restore it before making changes.', 'bingocommerce-sessions' ),
			'session_state_changed'        => __( 'The Session changed while you were editing it. Reload and try again.', 'bingocommerce-sessions' ),
			'session_state_inconsistent'   => __( 'This Session holds an unrecognised lifecycle value. Contact Platform Admin.', 'bingocommerce-sessions' ),
			'duplicate_session'            => __( 'A Session with that date and display name already exists in this hall.', 'bingocommerce-sessions' ),
			'invalid_session_date'         => __( 'Enter a real calendar date in MM/DD/YYYY form.', 'bingocommerce-sessions' ),
			'invalid_display_name'         => __( 'Enter a display name of up to 191 characters.', 'bingocommerce-sessions' ),
			'invalid_package_id'           => __( 'Choose a Bingo Package.', 'bingocommerce-sessions' ),
			'package_not_found'            => __( 'That Bingo Package was not found in this hall.', 'bingocommerce-sessions' ),
			'package_not_active'           => __( 'An archived Bingo Package cannot be assigned. Restore it first.', 'bingocommerce-sessions' ),
			'package_integrity_fault'      => __( 'That Bingo Package has an integrity fault and cannot be assigned. Contact Platform Admin.', 'bingocommerce-sessions' ),
			'package_state_inconsistent'   => __( 'That Bingo Package holds an unrecognised lifecycle value. Contact Platform Admin.', 'bingocommerce-sessions' ),
			'package_woo_product_unusable' => __( 'That Bingo Package\'s linked WooCommerce product is missing, trashed, or unsupported.', 'bingocommerce-sessions' ),
			'catalog_unavailable'          => __( 'The BingoCommerce Catalog plugin is unavailable, so Bingo Packages cannot be read.', 'bingocommerce-sessions' ),
			'persistence_failed'           => __( 'The Bingo Session change could not be saved.', 'bingocommerce-sessions' ),
			// Enable/Disable Sales on a schedule-generated Session, from the
			// §6.1/§6.2 guarded ON/OFF path (OccurrenceAvailability).
			'occurrence_not_schedule_owned'    => __( 'That Session is not owned by a commerce schedule.', 'bingocommerce-sessions' ),
			'occurrence_generation_incomplete' => __( 'This Session is still being generated and cannot be made available for sale yet.', 'bingocommerce-sessions' ),
			'template_not_commerce_eligible'   => __( 'The Portal session for this Session is no longer active and current, so it cannot be sold.', 'bingocommerce-sessions' ),
			'schedule_state_changed'           => __( 'The commerce schedule for this Session changed. Reload and try again.', 'bingocommerce-sessions' ),
			'package_became_unassignable'      => __( 'A Bingo Package for this Session stopped being assignable, so it was not made available for sale.', 'bingocommerce-sessions' ),
		];

		return $messages[ $error->get_error_code() ] ?? __( 'The Bingo Session change could not be completed.', 'bingocommerce-sessions' );
	}
}
