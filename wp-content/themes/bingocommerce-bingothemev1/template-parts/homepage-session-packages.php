<?php
/**
 * Informational Package cards for the Session selected through its server link.
 * Names, images, types and prices come from the Sessions plugin, which applies
 * BR-080 eligibility plus public visibility and WooCommerce purchasability.
 * There is no purchase, add-on or "What's Included" control in this increment.
 */

use BingoCommerce\BingoThemeV1\SessionCalendar;
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bingothemev1_calendar = $args['calendar'] ?? null;
if ( ! is_array( $bingothemev1_calendar ) || ! is_array( $bingothemev1_calendar['session'] ?? null ) || ! is_array( $bingothemev1_calendar['packages'] ?? null ) ) {
	return;
}
$bingothemev1_session  = $bingothemev1_calendar['session'];
$bingothemev1_packages = $bingothemev1_calendar['packages'];
$bingothemev1_time     = SessionCalendar::time_line( $bingothemev1_session );
?>
<div class="bc-session-packages" id="session-packages" data-session-packages="<?php echo esc_attr( $bingothemev1_session['date'] ); ?>" tabindex="-1" aria-labelledby="bc-session-packages-heading">
	<div class="bc-session-packages__header">
		<h3 id="bc-session-packages-heading"><?php /* translators: %s: Session name. */ echo esc_html( sprintf( __( 'Packages for %s', Theme::TEXT_DOMAIN ), $bingothemev1_session['name'] ) ); ?></h3>
		<p class="bc-session-packages__session"><?php echo esc_html( $bingothemev1_session['date_label'] . ( '' !== $bingothemev1_time ? ' · ' . $bingothemev1_time : '' ) ); ?></p>
	</div>
	<?php if ( 'unavailable' === $bingothemev1_packages['status'] ) : ?>
		<p class="bc-session-packages__message"><?php esc_html_e( 'Packages can’t be shown right now. Please try again.', Theme::TEXT_DOMAIN ); ?></p>
	<?php else : ?>
		<?php if ( 'partial' === $bingothemev1_packages['status'] ) : ?>
			<p class="calendar-notice"><?php esc_html_e( 'Some Packages for this Session couldn’t be checked right now.', Theme::TEXT_DOMAIN ); ?></p>
		<?php endif; ?>
		<?php if ( [] !== $bingothemev1_packages['items'] ) : ?>
			<ul class="bc-session-packages__grid">
				<?php foreach ( $bingothemev1_packages['items'] as $bingothemev1_item ) : ?>
					<li class="session-package">
						<?php
						$bingothemev1_image = $bingothemev1_item['image_id'] > 0
							? wp_get_attachment_image( $bingothemev1_item['image_id'], 'medium_large', false, [ 'class' => 'session-package__image', 'alt' => '', 'loading' => 'lazy', 'sizes' => '(max-width: 640px) calc(100vw - 80px), 400px' ] )
							: '';
						?>
						<?php if ( '' !== $bingothemev1_image ) : ?>
							<div class="session-package__thumb"><?php echo $bingothemev1_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- native escaped image markup; the name below labels the card. ?></div>
						<?php endif; ?>
						<span class="eyebrow session-package__type"><?php echo esc_html( SessionCalendar::type_label( $bingothemev1_item['type'] ) ); ?></span>
						<h4 class="session-package__name"><?php echo esc_html( $bingothemev1_item['name'] ); ?></h4>
						<p class="session-package__price"><?php echo wp_kses_post( $bingothemev1_item['price_html'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="bc-session-packages__message"><?php esc_html_e( 'No Packages are currently available for this Session.', Theme::TEXT_DOMAIN ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
