<?php
/** Core provides the current Hall's trust items as plain text; the theme only presents them. */
use BingoCommerce\BingoThemeV1\Template;

if ( ! defined( 'ABSPATH' ) ) { exit; }
$bingothemev1_trust = apply_filters( 'bingocommerce_homepage_trust', [] );
if ( ! is_array( $bingothemev1_trust ) || ! $bingothemev1_trust ) { return; }
?>
<section class="bc-home-trust">
	<div class="container">
		<ul class="bc-home-trust__list">
			<?php foreach ( $bingothemev1_trust as $bingothemev1_item ) : ?>
				<li class="bc-home-trust__item">
					<span class="bc-home-trust__icon"><?php echo Template::icon( $bingothemev1_item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static theme SVG. ?></span>
					<div class="bc-home-trust__body">
						<strong><?php echo esc_html( $bingothemev1_item['title'] ); ?></strong>
						<?php if ( '' !== $bingothemev1_item['text'] ) : ?><p><?php echo esc_html( $bingothemev1_item['text'] ); ?></p><?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
