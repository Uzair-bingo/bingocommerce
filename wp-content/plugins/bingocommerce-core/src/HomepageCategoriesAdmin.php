<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Independent form within Appearance → Homepage; shares the Hall settings boundary. */
final class HomepageCategoriesAdmin {
	private static function nonce_action(): string {
		return 'bingocommerce_homepage_categories:hall:' . get_current_blog_id();
	}

	public static function is_request( array $posted ): bool {
		foreach ( [ 'bc_category_action', 'bc_category_nonce', HomepageCategories::OPTION, 'bc_category_count', 'bc_category_complete' ] as $key ) {
			if ( array_key_exists( $key, $posted ) ) { return true; }
		}
		return false;
	}

	public static function handle_request( array $posted ): array {
		$fail = static fn( string $code, array $values = [], array $errors = [] ): array => [ 'result' => new \WP_Error( 'bc_homepage_categories_' . $code, match ( $code ) {
			'denied' => __( 'You are not authorized to manage this Hall’s category cards.', 'bingocommerce-core' ),
			'nonce' => __( 'Category card verification failed. Reload this page and try again.', 'bingocommerce-core' ),
			default => __( 'Shop by Category needs correcting. Nothing was saved.', 'bingocommerce-core' ),
		} ), 'values' => $values, 'errors' => $errors ];
		if ( ! HomepageAdmin::allowed() ) { return $fail( 'denied' ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return $fail( 'request' ); }
		if ( ! is_string( $posted['bc_category_nonce'] ?? null ) || ! wp_verify_nonce( $posted['bc_category_nonce'], self::nonce_action() ) ) { return $fail( 'nonce' ); }
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ 'bc_category_nonce', '_wp_http_referer', 'bc_category_action', HomepageCategories::OPTION, 'bc_category_count', 'bc_category_complete', 'submit' ], true ) ) { return $fail( 'request' ); }
		}
		$count = $posted['bc_category_count'] ?? null; $input = $posted[ HomepageCategories::OPTION ] ?? null;
		if ( 'save' !== ( $posted['bc_category_action'] ?? null ) || '1' !== ( $posted['bc_category_complete'] ?? null )
			|| ! is_string( $count ) || ! preg_match( '/\A[0-5]\z/', $count ) || ! is_array( $input ) ) { return $fail( 'request' ); }
		if ( '0' === $count && ! array_key_exists( 'cards', $input ) ) { $input['cards'] = []; }
		if ( ! is_array( $input['cards'] ?? null ) || count( $input['cards'] ) !== (int) $count ) { return $fail( 'request' ); }
		$checked = HomepageCategories::validate( $input );
		if ( $checked['errors'] ) { return $fail( 'value', $checked['values'], $checked['errors'] ); }
		return [ 'result' => HomepageCategories::save( $checked['values'] ), 'values' => $checked['values'], 'errors' => [] ];
	}

	/** Called only by the guarded Homepage screen's asset hook. */
	public static function assets(): void {
		$base = plugins_url( 'assets/', BINGOCOMMERCE_CORE_PATH . 'bingocommerce-core.php' );
		wp_enqueue_script( 'bingocommerce-homepage-categories-admin', $base . 'js/homepage-categories-admin.js', [ 'media-views', 'wp-a11y' ], (string) filemtime( BINGOCOMMERCE_CORE_PATH . 'assets/js/homepage-categories-admin.js' ), true );
		wp_enqueue_style( 'bingocommerce-homepage-categories-admin', $base . 'css/homepage-categories-admin.css', [], (string) filemtime( BINGOCOMMERCE_CORE_PATH . 'assets/css/homepage-categories-admin.css' ) );
		wp_localize_script( 'bingocommerce-homepage-categories-admin', 'bcHomepageCategories', [
			'card' => __( 'Card %d', 'bingocommerce-core' ), 'select' => __( 'Select image', 'bingocommerce-core' ), 'use' => __( 'Use this image', 'bingocommerce-core' ),
			'remove' => __( 'Remove this card? Its Media Library attachments will not be deleted. Save category cards to apply.', 'bingocommerce-core' ),
			'changed' => __( 'Category cards updated. Save category cards to apply changes.', 'bingocommerce-core' ),
			'backgroundRemoved' => __( 'Existing card background marked for removal. Save category cards to apply.', 'bingocommerce-core' ),
		] );
	}

	public static function render( ?array $outcome = null ): void {
		if ( ! HomepageAdmin::allowed() ) { return; }
		$stored = HomepageCategories::stored();
		$values = null !== $outcome && is_wp_error( $outcome['result'] ) && $outcome['values'] ? $outcome['values'] : ( is_wp_error( $stored ) ? HomepageCategories::blank() : $stored );
		$errors = $outcome['errors'] ?? [];
		$warnings = ! is_wp_error( $stored ) ? HomepageCategories::validate( $stored )['errors'] : [];
		$terms = taxonomy_exists( 'product_cat' ) ? get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] ) : [];
		$terms = is_array( $terms ) ? $terms : [];
		?>
		<section class="bc-category-editor" aria-labelledby="bc-category-title">
			<h2 id="bc-category-title"><?php esc_html_e( 'Shop by Category', 'bingocommerce-core' ); ?></h2>
			<p><?php esc_html_e( 'Add up to five navigation cards. No cards hides the section. Main artwork, the optional section background and visible text are separate; transparent main artwork is supported.', 'bingocommerce-core' ); ?></p>
			<?php if ( null !== $outcome ) : ?>
				<div class="notice <?php echo is_wp_error( $outcome['result'] ) ? 'notice-error' : 'notice-success'; ?>" role="<?php echo is_wp_error( $outcome['result'] ) ? 'alert' : 'status'; ?>">
					<p><?php echo esc_html( is_wp_error( $outcome['result'] ) ? $outcome['result']->get_error_message() : __( 'Shop by Category saved.', 'bingocommerce-core' ) ); ?></p>
					<?php if ( $errors ) : ?><ul><?php foreach ( $errors as $key => $error ) : ?><li><a href="<?php echo esc_attr( '#bc-category-' . $key ); ?>"><?php echo esc_html( $error ); ?></a></li><?php endforeach; ?></ul><?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( is_wp_error( $stored ) || $warnings ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( is_wp_error( $stored ) ? $stored->get_error_message() : __( 'Some saved category content is unavailable. Cards without a main image, destination or accessible name are omitted; missing optional backgrounds use the theme styling. Replace or remove unavailable selections before saving.', 'bingocommerce-core' ) ); ?></p>
					<?php foreach ( $warnings as $key => $warning ) : ?><p><a href="<?php echo esc_attr( '#bc-category-' . $key ); ?>"><?php echo esc_html( $warning ); ?></a></p><?php endforeach; ?>
				</div>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to add, remove or arrange cards and select images. Existing text and destinations can still be saved. Clear the unused destination field when changing destination type.', 'bingocommerce-core' ); ?></p></noscript>
			<form method="post" action="<?php echo esc_url( HomepageAdmin::tab_url( 'categories' ) ); ?>" novalidate id="bc-category-form">
				<input type="hidden" name="bc_category_action" value="save">
				<?php wp_nonce_field( self::nonce_action(), 'bc_category_nonce', false ); ?>
				<input type="hidden" name="bc_homepage_categories[version]" value="1">
				<input type="hidden" name="bc_category_count" value="<?php echo esc_attr( (string) count( $values['cards'] ) ); ?>">
				<?php self::media( 'bc_homepage_categories[background_id]', 'background_id', __( 'Section background (optional)', 'bingocommerce-core' ), $values['background_id'], $errors ); ?>
				<div data-category-list><?php foreach ( $values['cards'] as $i => $card ) { self::card( (string) $i, $card, $terms, $errors ); } ?></div>
				<p><button type="button" class="button" data-add-card hidden><?php esc_html_e( 'Add card', 'bingocommerce-core' ); ?></button> <span data-category-count></span></p>
				<input type="hidden" name="bc_category_complete" value="1">
				<?php submit_button( __( 'Save category cards', 'bingocommerce-core' ), 'primary', 'submit', true, [ 'id' => 'bc-category-save' ] ); ?>
			</form>
			<template id="bc-category-template"><?php self::card( '__INDEX__', HomepageCategories::card(), $terms, [] ); ?></template>
		</section>
		<?php
	}

	private static function card( string $index, array $card, array $terms, array $errors ): void {
		$name = 'bc_homepage_categories[cards][' . $index . ']'; $key = 'card-' . $index . '-';
		?>
		<fieldset class="bc-category-card" data-category-card>
			<legend data-category-title><?php echo esc_html( sprintf( __( 'Card %d', 'bingocommerce-core' ), (int) $index + 1 ) ); ?></legend>
			<div data-category-tools hidden>
				<button type="button" class="button" data-category-move="up"><?php esc_html_e( 'Move up', 'bingocommerce-core' ); ?></button>
				<button type="button" class="button" data-category-move="down"><?php esc_html_e( 'Move down', 'bingocommerce-core' ); ?></button>
				<button type="button" class="button" data-remove-card><?php esc_html_e( 'Remove card', 'bingocommerce-core' ); ?></button>
			</div>
			<?php self::media( $name . '[image_id]', $key . 'image_id', __( 'Main image (required)', 'bingocommerce-core' ), $card['image_id'], $errors ); ?>
			<div data-category-legacy-background id="<?php echo esc_attr( 'bc-category-' . $key . 'background_id' ); ?>" tabindex="-1">
				<input type="hidden" data-category-background name="<?php echo esc_attr( $name . '[background_id]' ); ?>" value="<?php echo esc_attr( (string) $card['background_id'] ); ?>">
				<?php if ( $card['background_id'] > 0 ) : ?>
					<button type="button" class="button" data-remove-existing-background hidden aria-describedby="<?php echo esc_attr( 'bc-category-' . $key . 'background-help' ); ?>"><?php esc_html_e( 'Remove existing card background', 'bingocommerce-core' ); ?></button>
					<p class="description" id="<?php echo esc_attr( 'bc-category-' . $key . 'background-help' ); ?>"><?php esc_html_e( 'Removal applies only when you save category cards. The Media Library attachment will not be deleted.', 'bingocommerce-core' ); ?></p>
					<p data-background-removal-pending hidden><?php esc_html_e( 'Existing card background marked for removal. Save category cards to apply.', 'bingocommerce-core' ); ?></p>
				<?php endif; ?>
				<?php self::error( $key . 'background_id', $errors ); ?>
			</div>
			<p class="description"><?php esc_html_e( 'Main images fit without cropping. Reference artwork is portrait. For image-only custom links, set descriptive Alt Text in the Media Library; filenames are never link labels.', 'bingocommerce-core' ); ?></p>
			<?php self::input( $name . '[text]', $key . 'text', __( 'Visible text (optional, 80 characters)', 'bingocommerce-core' ), $card['text'], 'text', $errors ); ?>
			<p><label for="<?php echo esc_attr( 'bc-category-' . $key . 'type' ); ?>"><?php esc_html_e( 'Destination type', 'bingocommerce-core' ); ?></label>
			<select data-destination-type id="<?php echo esc_attr( 'bc-category-' . $key . 'type' ); ?>" name="<?php echo esc_attr( $name . '[destination][type]' ); ?>" <?php self::attributes( $key . 'type', $errors ); ?>>
				<option value="url" <?php selected( $card['destination']['type'], 'url' ); ?>><?php esc_html_e( 'Custom URL', 'bingocommerce-core' ); ?></option>
				<option value="category" <?php selected( $card['destination']['type'], 'category' ); ?>><?php esc_html_e( 'Product category', 'bingocommerce-core' ); ?></option>
			</select><?php self::error( $key . 'type', $errors ); ?></p>
			<div data-destination="url"><?php self::input( $name . '[destination][url]', $key . 'url', __( 'Full HTTP(S) navigation URL', 'bingocommerce-core' ), $card['destination']['url'], 'url', $errors ); ?></div>
			<p data-destination="category"><label for="<?php echo esc_attr( 'bc-category-' . $key . 'category_id' ); ?>"><?php esc_html_e( 'Product category (includes empty categories)', 'bingocommerce-core' ); ?></label>
			<select id="<?php echo esc_attr( 'bc-category-' . $key . 'category_id' ); ?>" name="<?php echo esc_attr( $name . '[destination][category_id]' ); ?>" <?php self::attributes( $key . 'category_id', $errors ); ?>>
				<option value="0"><?php esc_html_e( 'Select a category', 'bingocommerce-core' ); ?></option>
				<?php foreach ( $terms as $term ) : ?><option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( $card['destination']['category_id'], $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
				<?php if ( $card['destination']['category_id'] > 0 && ! in_array( $card['destination']['category_id'], array_column( $terms, 'term_id' ), true ) ) : ?>
					<option value="<?php echo esc_attr( (string) $card['destination']['category_id'] ); ?>" selected><?php esc_html_e( 'Unavailable category — select another', 'bingocommerce-core' ); ?></option>
				<?php endif; ?>
			</select><?php self::error( $key . 'category_id', $errors ); ?></p>
		</fieldset>
		<?php
	}

	private static function media( string $name, string $key, string $label, int $id, array $errors ): void {
		$image = HomepageSlider::image( $id );
		?>
		<div class="bc-category-media" data-category-media>
			<p id="<?php echo esc_attr( 'bc-category-' . $key . '-label' ); ?>"><strong><?php echo esc_html( $label ); ?></strong></p>
			<img data-category-preview alt="" <?php if ( $image ) : ?>src="<?php echo esc_url( $image[0] ); ?>"<?php else : ?>hidden<?php endif; ?>>
			<input type="hidden" data-category-image name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $id ); ?>">
			<button type="button" class="button" data-category-select hidden id="<?php echo esc_attr( 'bc-category-' . $key ); ?>" aria-describedby="<?php echo esc_attr( 'bc-category-' . $key . '-label' . ( isset( $errors[ $key ] ) ? ' bc-category-' . $key . '-error' : '' ) ); ?>"><?php esc_html_e( 'Select / replace image', 'bingocommerce-core' ); ?></button>
			<button type="button" class="button" data-category-remove-image hidden aria-describedby="<?php echo esc_attr( 'bc-category-' . $key . '-label' ); ?>"><?php esc_html_e( 'Remove image', 'bingocommerce-core' ); ?></button>
			<?php self::error( $key, $errors ); ?>
		</div>
		<?php
	}

	private static function input( string $name, string $key, string $label, string $value, string $type, array $errors ): void {
		printf( '<p><label for="%1$s">%2$s</label><input class="large-text" type="%3$s" id="%1$s" name="%4$s" value="%5$s" ', esc_attr( 'bc-category-' . $key ), esc_html( $label ), esc_attr( $type ), esc_attr( $name ), esc_attr( $value ) );
		self::attributes( $key, $errors ); echo '>'; self::error( $key, $errors ); echo '</p>';
	}

	private static function attributes( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) { echo 'aria-invalid="true" aria-describedby="' . esc_attr( 'bc-category-' . $key . '-error' ) . '"'; }
	}

	private static function error( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) { echo '<span class="bc-category-error" id="' . esc_attr( 'bc-category-' . $key . '-error' ) . '">' . esc_html( $errors[ $key ] ) . '</span>'; }
	}
}
