<?php
/**
 * Fixed header: logo, primary menu, Store Details phone, account and cart.
 */

use BingoCommerce\BingoThemeV1\Template;
use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', Theme::TEXT_DOMAIN ); ?></a>

<header class="site-header" id="siteHeader">
	<div class="container site-header__inner">
		<?php Template::logo( 'header' ); ?>

		<nav class="main-nav" id="mainNav" aria-label="<?php esc_attr_e( 'Primary', Theme::TEXT_DOMAIN ); ?>">
			<?php Template::primary_menu(); ?>
		</nav>

		<div class="site-header__actions">
			<?php
			Template::support_line();
			Template::account_link();
			echo Template::cart_link(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			?>
			<button type="button" class="icon-btn menu-toggle" id="menuToggle" aria-controls="mainNav" aria-expanded="false"
				aria-label="<?php esc_attr_e( 'Open menu', Theme::TEXT_DOMAIN ); ?>"
				data-label-open="<?php esc_attr_e( 'Open menu', Theme::TEXT_DOMAIN ); ?>"
				data-label-close="<?php esc_attr_e( 'Close menu', Theme::TEXT_DOMAIN ); ?>">
				<span></span><span></span><span></span>
			</button>
		</div>
	</div>
</header>
<div class="mobile-nav-backdrop" id="mobileNavBackdrop" hidden></div>

<main id="main" class="site-main" tabindex="-1">
