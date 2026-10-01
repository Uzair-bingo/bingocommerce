<?php
/** Core resolves current-Hall media, destinations and names; the theme only presents them. */
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) { exit; }
$bingothemev1_categories = apply_filters( 'bingocommerce_homepage_categories', [] );
if ( ! is_array( $bingothemev1_categories ) || empty( $bingothemev1_categories['cards'] ) ) { return; }
?>
<section class="bc-home-categories" aria-labelledby="bc-category-heading">
	<?php if ( $bingothemev1_categories['background_id'] ) {
		echo wp_get_attachment_image( $bingothemev1_categories['background_id'], 'full', false, [ 'class' => 'bc-home-categories__background', 'alt' => '', 'loading' => 'lazy', 'sizes' => '100vw' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- native escaped image markup.
	} ?>
	<div class="container">
		<div class="section-heading bc-home-categories__heading">
			<h2 id="bc-category-heading"><?php esc_html_e( 'Shop by', Theme::TEXT_DOMAIN ); ?> <span><?php esc_html_e( 'Category', Theme::TEXT_DOMAIN ); ?></span></h2>
			<p><?php esc_html_e( 'Pick your lane. We’ll take it from there.', Theme::TEXT_DOMAIN ); ?></p>
			<div class="bc-home-categories__accent" aria-hidden="true"><span></span><span></span><span></span></div>
		</div>
		<div class="bc-home-categories__grid">
			<?php foreach ( $bingothemev1_categories['cards'] as $bingothemev1_card ) : ?>
				<a class="bc-category-card" href="<?php echo esc_url( $bingothemev1_card['url'], [ 'http', 'https' ] ); ?>" aria-label="<?php echo esc_attr( $bingothemev1_card['name'] ); ?>">
					<?php if ( $bingothemev1_card['background_id'] ) {
						echo wp_get_attachment_image( $bingothemev1_card['background_id'], 'full', false, [ 'class' => 'bc-category-card__background', 'alt' => '', 'loading' => 'lazy' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- native escaped image markup.
					} ?>
					<?php echo wp_get_attachment_image( $bingothemev1_card['image_id'], 'full', false, [ 'class' => 'bc-category-card__image', 'alt' => '', 'loading' => 'lazy', 'sizes' => '(max-width: 640px) calc(100vw - 48px), (max-width: 1024px) calc((100vw - 68px) / 2), 300px' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link already has its full accessible name; native escaped image markup. ?>
					<?php if ( '' !== $bingothemev1_card['text'] ) : ?><span class="bc-category-card__text"><?php echo esc_html( $bingothemev1_card['text'] ); ?></span><?php endif; ?>
					<span class="bc-category-card__arrow" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M4 10h12M11 5l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
