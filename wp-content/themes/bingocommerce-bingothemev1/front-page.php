<?php
/**
 * Front page — first-slice foundation (BR-084). Hall name, a Shop entry point
 * and the Hall's public contact details. Session, Package and merchandising
 * sections belong to later slices and are not simulated here.
 */

use BingoCommerce\BingoThemeV1\Template;
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$bingothemev1_shop = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ? wc_get_page_permalink( 'shop' ) : '';
?>
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

<?php if ( Template::has_contact() ) : ?>
	<section class="visit-section" aria-labelledby="visit-title">
		<div class="container section-heading">
			<h2 id="visit-title"><?php esc_html_e( 'Visit us', Theme::TEXT_DOMAIN ); ?></h2>
			<?php Template::contact(); ?>
		</div>
	</section>
<?php endif; ?>
<?php
get_footer();
