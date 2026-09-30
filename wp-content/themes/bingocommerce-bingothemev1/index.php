<?php
/**
 * Fallback for posts, archives and search results.
 */

use BingoCommerce\BingoThemeV1\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="container page-body">
	<header class="page-header">
		<h1 class="page-title">
			<?php
			if ( is_search() ) {
				/* translators: %s: search terms. */
				printf( esc_html__( 'Search results for “%s”', Theme::TEXT_DOMAIN ), esc_html( get_search_query() ) );
			} elseif ( is_archive() ) {
				echo wp_kses_post( get_the_archive_title() );
			} elseif ( is_singular() ) {
				single_post_title();
			} else {
				esc_html_e( 'Latest news', Theme::TEXT_DOMAIN );
			}
			?>
		</h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<?php if ( is_singular() ) : ?>
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
					<div class="entry-content"><?php the_content(); ?></div>
				</article>
			<?php endwhile; ?>
		<?php else : ?>
			<div class="post-list">
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<article id="post-<?php the_ID(); ?>" <?php post_class( 'post-card' ); ?>>
						<h2 class="post-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<div class="post-card__excerpt"><?php the_excerpt(); ?></div>
					</article>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php endif; ?>
	<?php else : ?>
		<div class="empty-state">
			<p><?php esc_html_e( 'Nothing to show here yet.', Theme::TEXT_DOMAIN ); ?></p>
			<a class="btn btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', Theme::TEXT_DOMAIN ); ?></a>
		</div>
	<?php endif; ?>
</div>
<?php
get_footer();
