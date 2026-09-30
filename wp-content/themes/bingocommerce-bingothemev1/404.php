<?php
/**
 * Not found.
 */

use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$bingothemev1_shop = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ? wc_get_page_permalink( 'shop' ) : '';
?>
<div class="container page-body">
	<div class="empty-state">
		<h1 class="page-title"><?php esc_html_e( 'Page not found', Theme::TEXT_DOMAIN ); ?></h1>
		<p><?php esc_html_e( 'The page you were looking for is not here. It may have moved or no longer exist.', Theme::TEXT_DOMAIN ); ?></p>
		<div class="empty-state__actions">
			<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', Theme::TEXT_DOMAIN ); ?></a>
			<?php if ( '' !== $bingothemev1_shop ) : ?>
				<a class="btn btn--ghost" href="<?php echo esc_url( $bingothemev1_shop ); ?>"><?php esc_html_e( 'Go to the shop', Theme::TEXT_DOMAIN ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
get_footer();
