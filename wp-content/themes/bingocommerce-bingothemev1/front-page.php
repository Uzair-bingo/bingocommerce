<?php
/**
 * Front page — Core-owned slider display configuration (BR-084), with the
 * original Hall-name/Shop fallback and public contact details. Other homepage
 * sections belong to later slices. No purchase or persistence logic here.
 */

use BingoCommerce\BingoThemeV1\Template;
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$bingothemev1_shop = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ? wc_get_page_permalink( 'shop' ) : '';
$bingothemev1_slides = apply_filters( 'bingocommerce_homepage_slider', [] );
$bingothemev1_slides = is_array( $bingothemev1_slides ) ? $bingothemev1_slides : [];
?>
<?php if ( $bingothemev1_slides ) : ?>
<section class="bc-home-slider" data-homepage-slider role="region" aria-roledescription="<?php esc_attr_e( 'carousel', Theme::TEXT_DOMAIN ); ?>" aria-label="<?php esc_attr_e( 'Hall highlights', Theme::TEXT_DOMAIN ); ?>">
	<?php if ( count( $bingothemev1_slides ) > 1 ) : ?>
		<button type="button" class="bc-home-slider__rotation" data-rotation hidden data-pause="<?php esc_attr_e( 'Pause slideshow', Theme::TEXT_DOMAIN ); ?>" data-resume="<?php esc_attr_e( 'Resume slideshow', Theme::TEXT_DOMAIN ); ?>"><?php esc_html_e( 'Pause slideshow', Theme::TEXT_DOMAIN ); ?></button>
	<?php endif; ?>
	<div class="bc-home-slider__slides">
		<?php foreach ( $bingothemev1_slides as $bingothemev1_i => $bingothemev1_slide ) : ?>
			<div class="bc-home-slider__slide<?php echo 0 === $bingothemev1_i ? ' is-active' : ''; ?>" data-slide role="group" aria-roledescription="<?php esc_attr_e( 'slide', Theme::TEXT_DOMAIN ); ?>"
				aria-label="<?php echo esc_attr( sprintf( __( '%1$d of %2$d', Theme::TEXT_DOMAIN ), $bingothemev1_i + 1, count( $bingothemev1_slides ) ) ); ?>" <?php if ( 0 !== $bingothemev1_i ) : ?>hidden inert aria-hidden="true"<?php endif; ?>>
				<?php
				echo wp_get_attachment_image( $bingothemev1_slide['image_id'], 'full', false, [
					'class' => 'bc-home-slider__image bc-home-slider__image--' . ( 'center' === $bingothemev1_slide['image_position'] ? 'center' : 'right-low' ),
					'alt' => '', // Decorative background: all promotional information is separate text.
					'loading' => 0 === $bingothemev1_i ? 'eager' : 'lazy',
					'fetchpriority' => 0 === $bingothemev1_i ? 'high' : 'auto',
					'sizes' => '100vw',
				] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- native escaped image markup.
				?>
				<div class="container bc-home-slider__content">
					<h1><?php echo esc_html( $bingothemev1_slide['heading'] ); ?></h1>
					<?php if ( '' !== $bingothemev1_slide['text'] ) : ?><p class="bc-home-slider__text"><?php echo esc_html( $bingothemev1_slide['text'] ); ?></p><?php endif; ?>
					<?php if ( $bingothemev1_slide['buttons'] ) : ?>
						<div class="bc-home-slider__actions">
							<?php foreach ( $bingothemev1_slide['buttons'] as $bingothemev1_b => $bingothemev1_button ) : ?>
								<a class="btn btn--lg <?php echo 0 === $bingothemev1_b ? 'btn--light' : 'bc-home-slider__outline'; ?>" href="<?php echo esc_url( $bingothemev1_button['url'], [ 'http', 'https' ] ); ?>"><?php echo esc_html( $bingothemev1_button['label'] ); ?></a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php if ( count( $bingothemev1_slides ) > 1 ) : ?>
		<div data-slider-controls hidden>
			<button type="button" class="bc-home-slider__arrow bc-home-slider__arrow--prev" data-prev aria-label="<?php esc_attr_e( 'Previous slide', Theme::TEXT_DOMAIN ); ?>"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L4 8l6 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
			<button type="button" class="bc-home-slider__arrow bc-home-slider__arrow--next" data-next aria-label="<?php esc_attr_e( 'Next slide', Theme::TEXT_DOMAIN ); ?>"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 3l6 5-6 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
			<div class="bc-home-slider__dots" role="group" aria-label="<?php esc_attr_e( 'Choose a slide', Theme::TEXT_DOMAIN ); ?>">
				<?php foreach ( $bingothemev1_slides as $bingothemev1_i => $bingothemev1_slide ) : ?>
					<button type="button" data-dot="<?php echo esc_attr( (string) $bingothemev1_i ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Go to slide %1$d: %2$s', Theme::TEXT_DOMAIN ), $bingothemev1_i + 1, $bingothemev1_slide['heading'] ) ); ?>" <?php if ( 0 === $bingothemev1_i ) : ?>aria-current="true"<?php endif; ?>></button>
				<?php endforeach; ?>
			</div>
		</div>
		<p class="screen-reader-text" data-slider-status aria-live="polite" aria-atomic="true"></p>
	<?php endif; ?>
</section>
<?php else : ?>
<section class="hero" aria-labelledby="hero-title">
	<div class="container hero__content">
		<h1 id="hero-title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
		<?php if ( '' !== get_bloginfo( 'description' ) ) : ?>
			<p class="hero__tagline"><?php bloginfo( 'description' ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $bingothemev1_shop ) : ?>
			<div class="hero__actions">
				<a class="btn btn--light btn--lg" href="<?php echo esc_url( $bingothemev1_shop ); ?>"><?php esc_html_e( 'Shop now', Theme::TEXT_DOMAIN ); ?></a>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php endif; ?>

<?php $bingothemev1_map_url = Template::map_url(); ?>
<?php if ( Template::has_contact() || '' !== $bingothemev1_map_url ) : ?>
	<section class="visit-section<?php echo '' !== $bingothemev1_map_url ? ' visit-section--map' : ''; ?>" aria-labelledby="visit-title">
		<div class="container section-heading">
			<h2 id="visit-title"><?php esc_html_e( 'Visit us', Theme::TEXT_DOMAIN ); ?></h2>
			<?php Template::contact(); ?>
		</div>
		<?php if ( '' !== $bingothemev1_map_url ) : ?>
			<div class="visit-map">
				<iframe src="<?php echo esc_url( $bingothemev1_map_url ); ?>" width="100%" height="100%" loading="lazy"
					referrerpolicy="no-referrer-when-downgrade" title="<?php
					/* translators: %s: Hall name. */
					echo esc_attr( sprintf( __( '%s location', Theme::TEXT_DOMAIN ), get_bloginfo( 'name' ) ) );
					?>"></iframe>
			</div>
		<?php endif; ?>
	</section>
<?php endif; ?>
<?php
get_footer();
