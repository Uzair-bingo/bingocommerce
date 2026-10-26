<?php
/** Core provides the current Hall's testimonials as plain text; with none, nothing is rendered. */
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) { exit; }
$bingothemev1_testimonials = apply_filters( 'bingocommerce_homepage_testimonials', [] );
if ( ! is_array( $bingothemev1_testimonials ) || ! $bingothemev1_testimonials ) { return; }
$bingothemev1_count = count( $bingothemev1_testimonials );
?>
<section class="bc-home-testimonials" id="about" aria-labelledby="bc-testimonials-heading"<?php echo $bingothemev1_count > 1 ? ' data-homepage-testimonials' : ''; ?>>
	<div class="container">
		<div class="section-heading bc-home-testimonials__heading">
			<h2 id="bc-testimonials-heading"><?php esc_html_e( 'Loved by Bingo Communities', Theme::TEXT_DOMAIN ); ?></h2>
			<p><?php esc_html_e( 'Don’t take our word for it.', Theme::TEXT_DOMAIN ); ?></p>
		</div>
		<div class="bc-home-testimonials__slides">
			<?php foreach ( $bingothemev1_testimonials as $bingothemev1_i => $bingothemev1_item ) : ?>
				<figure class="bc-testimonial<?php echo 0 === $bingothemev1_i ? ' is-active' : ''; ?>" data-testimonial <?php if ( 0 !== $bingothemev1_i ) : ?>hidden<?php endif; ?>>
					<blockquote class="bc-testimonial__quote"><p>&#8220;<?php echo esc_html( $bingothemev1_item['quote'] ); ?>&#8221;</p></blockquote>
					<figcaption class="bc-testimonial__author">
						<strong><?php echo esc_html( $bingothemev1_item['name'] ); ?></strong>
						<?php if ( '' !== $bingothemev1_item['role'] ) : ?><span><?php echo esc_html( $bingothemev1_item['role'] ); ?></span><?php endif; ?>
					</figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
		<?php if ( $bingothemev1_count > 1 ) : ?>
			<div class="bc-home-testimonials__dots" data-testimonial-dots hidden role="group" aria-label="<?php esc_attr_e( 'Choose a testimonial', Theme::TEXT_DOMAIN ); ?>">
				<?php for ( $bingothemev1_i = 0; $bingothemev1_i < $bingothemev1_count; ++$bingothemev1_i ) : ?>
					<button type="button" data-testimonial-dot aria-label="<?php echo esc_attr( sprintf( /* translators: 1: testimonial number, 2: total. */ __( 'Show testimonial %1$d of %2$d', Theme::TEXT_DOMAIN ), $bingothemev1_i + 1, $bingothemev1_count ) ); ?>" <?php if ( 0 === $bingothemev1_i ) : ?>aria-current="true"<?php endif; ?>></button>
				<?php endfor; ?>
			</div>
			<p class="screen-reader-text" data-testimonial-status aria-live="polite" aria-atomic="true"></p>
		<?php endif; ?>
	</div>
</section>
