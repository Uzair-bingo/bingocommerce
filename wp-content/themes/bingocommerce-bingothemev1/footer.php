<?php
/**
 * Fixed footer: logo, social links, Store Details contact, footer menu, copyright.
 */

use BingoCommerce\BingoThemeV1\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</main>

<footer class="site-footer">
	<div class="container site-footer__brand">
		<?php Template::logo( 'footer' ); ?>
		<?php if ( '' !== get_bloginfo( 'description' ) ) : ?>
			<p><?php bloginfo( 'description' ); ?></p>
		<?php endif; ?>
		<?php Template::contact( 'visit-contact visit-contact--footer' ); ?>
		<?php Template::social_links(); ?>
	</div>

	<div class="container site-footer__bottom">
		<p>&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?></p>
		<?php Template::footer_menu(); ?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
