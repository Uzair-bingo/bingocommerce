<?php
/**
 * Homepage Session calendar: date -> eligible dated Session -> its Packages.
 * The Sessions plugin resolves current-Hall eligibility, the selection and every
 * link; the theme only presents them. Each control is a real link, so the
 * calendar works without JavaScript.
 */

use BingoCommerce\BingoThemeV1\SessionCalendar;
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( is_main_site() ) {
	return; // Not a Hall: there is no Session domain to present.
}

$bingothemev1_calendar = SessionCalendar::model( apply_filters( SessionCalendar::FILTER, null ) );
$bingothemev1_usable   = null !== $bingothemev1_calendar && 'unavailable' !== $bingothemev1_calendar['status'];
?>
<section class="bc-home-calendar" id="calendar" aria-labelledby="bc-calendar-heading"<?php echo $bingothemev1_usable ? ' data-session-calendar' : ''; ?>>
	<div class="container">
		<div class="section-heading bc-home-calendar__heading">
			<span class="eyebrow"><?php esc_html_e( 'Session Calendar', Theme::TEXT_DOMAIN ); ?></span>
			<h2 id="bc-calendar-heading"><?php esc_html_e( 'Find Your Next Session', Theme::TEXT_DOMAIN ); ?></h2>
			<p><?php esc_html_e( 'Choose a highlighted date to see its Sessions.', Theme::TEXT_DOMAIN ); ?></p>
		</div>
		<?php if ( ! $bingothemev1_usable ) : ?>
			<div class="calendar-card">
				<p class="calendar-message"><?php esc_html_e( 'Sessions can’t be shown right now.', Theme::TEXT_DOMAIN ); ?></p>
			</div>
		<?php else : ?>
			<?php $bingothemev1_message = SessionCalendar::message( $bingothemev1_calendar ); ?>
			<div class="calendar-card">
				<?php if ( 'partial' === $bingothemev1_calendar['status'] ) : ?>
					<p class="calendar-notice" data-calendar-notice><?php esc_html_e( 'Some Sessions couldn’t be checked right now.', Theme::TEXT_DOMAIN ); ?></p>
				<?php endif; ?>

				<?php foreach ( $bingothemev1_calendar['months'] as $bingothemev1_month ) : ?>
					<?php $bingothemev1_title = 'bc-calendar-month-' . substr( $bingothemev1_month['month'], 0, 7 ); ?>
					<div class="calendar-month" data-calendar-month="<?php echo esc_attr( $bingothemev1_month['month'] ); ?>"<?php echo $bingothemev1_month['month'] === $bingothemev1_calendar['month'] ? '' : ' hidden'; ?>>
						<div class="calendar-header">
							<?php foreach ( [ 'prev', 'next' ] as $bingothemev1_side ) : ?>
								<?php
								if ( 'next' === $bingothemev1_side ) {
									printf( '<h3 class="calendar-title" id="%1$s">%2$s</h3>', esc_attr( $bingothemev1_title ), esc_html( $bingothemev1_month['label'] ) );
								}
								$bingothemev1_link = $bingothemev1_month[ $bingothemev1_side ];
								?>
								<?php if ( null === $bingothemev1_link ) : ?>
									<span class="calendar-nav calendar-nav--<?php echo esc_attr( $bingothemev1_side ); ?>" aria-hidden="true"></span>
								<?php else : ?>
									<a class="calendar-nav calendar-nav--<?php echo esc_attr( $bingothemev1_side ); ?>" href="<?php echo esc_url( $bingothemev1_link['url'] ); ?>"
										data-calendar-nav="<?php echo esc_attr( $bingothemev1_link['month'] ); ?>" data-calendar-direction="<?php echo esc_attr( $bingothemev1_side ); ?>"
										data-calendar-announce="<?php /* translators: %s: month and year. */ echo esc_attr( sprintf( __( 'Showing %s', Theme::TEXT_DOMAIN ), $bingothemev1_link['label'] ) ); ?>"
										aria-label="<?php echo esc_attr( sprintf( 'prev' === $bingothemev1_side ? /* translators: %s: month and year. */ __( 'Previous month, %s', Theme::TEXT_DOMAIN ) : /* translators: %s: month and year. */ __( 'Next month, %s', Theme::TEXT_DOMAIN ), $bingothemev1_link['label'] ) ); ?>">
										<?php if ( 'prev' === $bingothemev1_side ) : ?>
											<svg width="8" height="14" viewBox="0 0 8 14" fill="none" aria-hidden="true" focusable="false"><path d="M7 1L1 7l6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
										<?php endif; ?>
										<span class="calendar-nav__label" aria-hidden="true"><?php echo esc_html( $bingothemev1_link['label'] ); ?></span>
										<?php if ( 'next' === $bingothemev1_side ) : ?>
											<svg width="8" height="14" viewBox="0 0 8 14" fill="none" aria-hidden="true" focusable="false"><path d="M1 1l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
										<?php endif; ?>
									</a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<table class="calendar-table" aria-labelledby="<?php echo esc_attr( $bingothemev1_title ); ?>">
							<thead>
								<tr>
									<?php foreach ( SessionCalendar::weekdays() as [ $bingothemev1_short, $bingothemev1_full ] ) : ?>
										<th scope="col"><span aria-hidden="true"><?php echo esc_html( $bingothemev1_short ); ?></span><span class="screen-reader-text"><?php echo esc_html( $bingothemev1_full ); ?></span></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( SessionCalendar::weeks( $bingothemev1_month['month'] ) as $bingothemev1_week ) : ?>
									<tr>
										<?php foreach ( $bingothemev1_week as $bingothemev1_cell ) : ?>
											<td>
												<?php
												$bingothemev1_date  = $bingothemev1_cell['date'];
												$bingothemev1_entry = $bingothemev1_calendar['dates'][ $bingothemev1_date ] ?? null;
												$bingothemev1_today = $bingothemev1_date === $bingothemev1_calendar['today'] ? ' is-today' : '';
												$bingothemev1_mine  = $bingothemev1_date === $bingothemev1_calendar['selected_date'];
												?>
												<?php if ( ! $bingothemev1_cell['in_month'] ) : ?>
													<span class="calendar-day is-outside" aria-hidden="true"><span class="calendar-day__num"><?php echo esc_html( (string) $bingothemev1_cell['day'] ); ?></span></span>
												<?php elseif ( null !== $bingothemev1_entry ) : ?>
													<?php $bingothemev1_count = count( $bingothemev1_entry['sessions'] ); ?>
													<a class="calendar-day is-available<?php echo esc_attr( $bingothemev1_today . ( $bingothemev1_mine ? ' is-selected' : '' ) ); ?>" href="<?php echo esc_url( $bingothemev1_entry['url'] ); ?>"
														data-calendar-date="<?php echo esc_attr( $bingothemev1_date ); ?>"
														data-calendar-announce="<?php /* translators: %s: date in MM/DD/YYYY form. */ echo esc_attr( sprintf( __( 'Showing Sessions for %s', Theme::TEXT_DOMAIN ), $bingothemev1_entry['label'] ) ); ?>"
														aria-label="<?php /* translators: 1: date in MM/DD/YYYY form, 2: number of Sessions. */ echo esc_attr( sprintf( _n( '%1$s, %2$d Session', '%1$s, %2$d Sessions', $bingothemev1_count, Theme::TEXT_DOMAIN ), $bingothemev1_entry['label'], $bingothemev1_count ) ); ?>"<?php echo $bingothemev1_mine ? ' aria-current="date"' : ''; ?>>
														<span class="calendar-day__num" aria-hidden="true"><?php echo esc_html( (string) $bingothemev1_cell['day'] ); ?></span><span class="calendar-day__dot" aria-hidden="true"></span>
													</a>
												<?php else : ?>
													<span class="calendar-day is-unavailable<?php echo esc_attr( $bingothemev1_today . ( $bingothemev1_mine ? ' is-requested' : '' ) ); ?>"<?php echo $bingothemev1_mine ? ' aria-current="date"' : ''; ?>><span class="calendar-day__num"><?php echo esc_html( (string) $bingothemev1_cell['day'] ); ?></span></span>
												<?php endif; ?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>

				<div class="calendar-detail">
					<?php if ( SessionCalendar::is_month_only( $bingothemev1_calendar['state'] ) ) : ?>
						<?php // Month-only: both messages are rendered so month arrows can re-word the visible one. ?>
						<div class="calendar-detail__message" data-calendar-message data-calendar-month-only>
							<?php foreach ( SessionCalendar::month_messages( $bingothemev1_calendar ) as $bingothemev1_kind => $bingothemev1_text ) : ?>
								<p class="calendar-message" data-calendar-month-message="<?php echo esc_attr( $bingothemev1_kind ); ?>"<?php echo $bingothemev1_kind === $bingothemev1_calendar['state'] ? '' : ' hidden'; ?>>
									<?php echo esc_html( $bingothemev1_text ); ?>
									<?php if ( SessionCalendar::offers_recovery( $bingothemev1_kind ) ) : ?>
										<a href="<?php echo esc_url( $bingothemev1_calendar['available_url'] ); ?>"><?php esc_html_e( 'See available dates', Theme::TEXT_DOMAIN ); ?></a>
									<?php endif; ?>
								</p>
							<?php endforeach; ?>
						</div>
					<?php elseif ( '' !== $bingothemev1_message ) : ?>
						<div class="calendar-detail__message" data-calendar-message>
							<p class="calendar-message">
								<?php echo esc_html( $bingothemev1_message ); ?>
								<?php if ( SessionCalendar::offers_recovery( $bingothemev1_calendar['state'] ) ) : ?>
									<a href="<?php echo esc_url( $bingothemev1_calendar['available_url'] ); ?>"><?php esc_html_e( 'See available dates', Theme::TEXT_DOMAIN ); ?></a>
								<?php endif; ?>
							</p>
						</div>
					<?php endif; ?>
					<?php foreach ( $bingothemev1_calendar['dates'] as $bingothemev1_date => $bingothemev1_entry ) : ?>
						<div class="calendar-detail__panel" data-calendar-date-panel="<?php echo esc_attr( $bingothemev1_date ); ?>"<?php echo 'date' === $bingothemev1_calendar['state'] && $bingothemev1_date === $bingothemev1_calendar['selected_date'] ? '' : ' hidden'; ?>>
							<h4 class="calendar-detail__date"><?php /* translators: %s: date in MM/DD/YYYY form. */ echo esc_html( sprintf( __( 'Sessions on %s', Theme::TEXT_DOMAIN ), $bingothemev1_entry['label'] ) ); ?></h4>
							<ul class="calendar-detail__list">
								<?php foreach ( $bingothemev1_entry['sessions'] as $bingothemev1_session ) : ?>
									<?php
									$bingothemev1_current = null !== $bingothemev1_calendar['session'] && $bingothemev1_calendar['session']['id'] === $bingothemev1_session['id'];
									$bingothemev1_time    = SessionCalendar::time_line( $bingothemev1_session );
									?>
									<li class="calendar-session-row<?php echo $bingothemev1_current ? ' is-selected' : ''; ?>"<?php echo $bingothemev1_current ? ' aria-current="true"' : ''; ?>>
										<div class="calendar-session-row__text">
											<span class="calendar-session-row__name"><?php echo esc_html( $bingothemev1_session['name'] ); ?></span>
											<?php if ( '' !== $bingothemev1_time ) : ?>
												<span class="calendar-session-row__time"><?php echo esc_html( $bingothemev1_time ); ?></span>
											<?php endif; ?>
										</div>
										<a class="btn btn--ghost btn--sm" href="<?php echo esc_url( $bingothemev1_session['packages_url'] ); ?>"
											aria-label="<?php /* translators: 1: Session name, 2: date in MM/DD/YYYY form. */ echo esc_attr( sprintf( __( 'View Packages for %1$s on %2$s', Theme::TEXT_DOMAIN ), $bingothemev1_session['name'], $bingothemev1_session['date_label'] ) ); ?>"><?php esc_html_e( 'View Packages', Theme::TEXT_DOMAIN ); ?></a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="screen-reader-text" data-calendar-status aria-live="polite" aria-atomic="true"></p>
			</div>
			<?php
			if ( null !== $bingothemev1_calendar['session'] ) {
				get_template_part( 'template-parts/homepage-session-packages', null, [ 'calendar' => $bingothemev1_calendar ] );
			}
			?>
		<?php endif; ?>
	</div>
</section>
