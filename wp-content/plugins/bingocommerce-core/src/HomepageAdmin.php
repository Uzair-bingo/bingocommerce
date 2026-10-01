<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Appearance → Homepage. Same current-Hall boundary as Store Details. */
final class HomepageAdmin {
	public const PAGE = 'bingocommerce-homepage';
	private static string $screen = '';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
	}

	public static function menu(): void {
		if ( self::allowed() ) {
			self::$screen = (string) add_theme_page( __( 'Homepage', 'bingocommerce-core' ), __( 'Homepage', 'bingocommerce-core' ), 'bc_manage_hall_settings', self::PAGE, [ self::class, 'render' ] );
		}
	}

	public static function allowed(): bool {
		return is_multisite() && is_admin() && is_user_logged_in() && ! is_network_admin() && HallContext::is_hall()
			&& ( is_super_admin() || current_user_can( 'bc_manage_hall_settings' ) );
	}

	private static function nonce_action(): string {
		return 'bingocommerce_homepage_slider:hall:' . get_current_blog_id();
	}

	public static function tab_url( string $tab ): string {
		return add_query_arg( [ 'page' => self::PAGE, 'tab' => 'categories' === $tab ? 'categories' : 'slider' ], admin_url( 'themes.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( '' === self::$screen || self::$screen !== $hook || ! self::allowed() ) {
			return;
		}
		wp_enqueue_media();
		HomepageCategoriesAdmin::assets();
		$base = plugins_url( 'assets/', BINGOCOMMERCE_CORE_PATH . 'bingocommerce-core.php' );
		wp_enqueue_script( 'bingocommerce-homepage-admin', $base . 'js/homepage-admin.js', [ 'media-views', 'wp-a11y' ], (string) filemtime( BINGOCOMMERCE_CORE_PATH . 'assets/js/homepage-admin.js' ), true );
		wp_enqueue_style( 'bingocommerce-homepage-admin', $base . 'css/homepage-admin.css', [], (string) filemtime( BINGOCOMMERCE_CORE_PATH . 'assets/css/homepage-admin.css' ) );
		wp_localize_script( 'bingocommerce-homepage-admin', 'bcHomepageAdmin', [
			'slide' => __( 'Slide %d', 'bingocommerce-core' ),
			'select' => __( 'Select slide image', 'bingocommerce-core' ),
			'use' => __( 'Use this image', 'bingocommerce-core' ),
			'remove' => __( 'Remove this slide? The Media Library image will not be deleted. Save to apply this change.', 'bingocommerce-core' ),
			'changed' => __( 'Slide list updated. Save the slider to apply changes.', 'bingocommerce-core' ),
		] );
	}

	/** Unslashed POST, allowlisted in full; empty list needs explicit count + end marker. */
	public static function handle_request( array $posted ): array {
		$fail = static fn( string $code, array $values = [], array $errors = [] ): array => [ 'result' => new \WP_Error( 'bc_homepage_slider_' . $code, match ( $code ) {
			'denied' => __( 'You are not authorized to manage this Hall’s homepage.', 'bingocommerce-core' ),
			'nonce' => __( 'Verification failed. Reload this page and try again.', 'bingocommerce-core' ),
			default => __( 'The slider needs correcting. Nothing was saved.', 'bingocommerce-core' ),
		} ), 'values' => $values, 'errors' => $errors ];
		if ( ! self::allowed() ) { return $fail( 'denied' ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return $fail( 'request' ); }
		if ( ! is_string( $posted['_wpnonce'] ?? null ) || ! wp_verify_nonce( $posted['_wpnonce'], self::nonce_action() ) ) { return $fail( 'nonce' ); }
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ '_wpnonce', '_wp_http_referer', HomepageSlider::OPTION, 'bc_slider_count', 'bc_slider_complete', 'submit' ], true ) ) { return $fail( 'request' ); }
		}
		$count = $posted['bc_slider_count'] ?? null;
		$input = $posted[ HomepageSlider::OPTION ] ?? null;
		if ( '1' !== ( $posted['bc_slider_complete'] ?? null ) || ! is_string( $count ) || ! preg_match( '/\A[0-6]\z/', $count ) || ! is_array( $input ) ) { return $fail( 'request' ); }
		// HTML forms cannot transmit an empty array. Only explicit zero permits absence.
		if ( '0' === $count && ! array_key_exists( 'slides', $input ) ) { $input['slides'] = []; }
		if ( ! is_array( $input['slides'] ?? null ) || count( $input['slides'] ) !== (int) $count ) { return $fail( 'request' ); }
		$checked = HomepageSlider::validate( $input );
		if ( [] !== $checked['errors'] ) { return $fail( 'value', $checked['values'], $checked['errors'] ); }
		return [ 'result' => HomepageSlider::save( $checked['values'] ), 'values' => $checked['values'], 'errors' => [] ];
	}

	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not authorized to manage this Hall’s homepage.', 'bingocommerce-core' ), '', [ 'response' => 403 ] );
		}
		$posted = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? wp_unslash( $_POST ) : null;
		$category_request = null !== $posted && HomepageCategoriesAdmin::is_request( $posted );
		$outcome = null !== $posted && ! $category_request ? self::handle_request( $posted ) : null;
		$category_outcome = $category_request ? HomepageCategoriesAdmin::handle_request( $posted ) : null;
		// POST identity owns the active editor, including errors; GET tabs never select a save handler.
		$tab = null !== $posted ? ( $category_request ? 'categories' : 'slider' ) : ( 'categories' === ( $_GET['tab'] ?? null ) ? 'categories' : 'slider' );
		$stored = HomepageSlider::stored();
		$values = null !== $outcome && is_wp_error( $outcome['result'] ) && [] !== $outcome['values'] ? $outcome['values'] : ( is_wp_error( $stored ) ? HomepageSlider::blank() : $stored );
		$errors = $outcome['errors'] ?? [];
		?>
		<div class="wrap bc-homepage">
			<h1><?php esc_html_e( 'Homepage', 'bingocommerce-core' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Homepage settings', 'bingocommerce-core' ); ?>">
				<?php foreach ( [ 'slider' => __( 'Homepage slider', 'bingocommerce-core' ), 'categories' => __( 'Shop by Category', 'bingocommerce-core' ) ] as $key => $label ) : ?>
					<a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::tab_url( $key ) ); ?>"<?php if ( $tab === $key ) : ?> aria-current="page"<?php endif; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( 'slider' === $tab ) : ?>
			<h2><?php esc_html_e( 'Homepage slider', 'bingocommerce-core' ); ?></h2>
			<p><?php esc_html_e( 'Add up to six slides. Images, headings, supporting text and navigation buttons are edited separately. With no usable slides, your Hall name and Shop banner are shown.', 'bingocommerce-core' ); ?></p>
			<?php if ( null !== $outcome ) : ?>
				<div class="notice <?php echo is_wp_error( $outcome['result'] ) ? 'notice-error' : 'notice-success'; ?>" role="<?php echo is_wp_error( $outcome['result'] ) ? 'alert' : 'status'; ?>">
					<p><?php echo esc_html( is_wp_error( $outcome['result'] ) ? $outcome['result']->get_error_message() : __( 'Homepage slider saved.', 'bingocommerce-core' ) ); ?></p>
					<?php if ( $errors ) : ?><ul><?php foreach ( $errors as $key => $message ) : ?>
						<li><a href="<?php echo esc_attr( '#bc-' . $key ); ?>"><?php echo esc_html( $message ); ?></a></li>
					<?php endforeach; ?></ul><?php endif; ?>
				</div>
			<?php elseif ( is_wp_error( $stored ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $stored->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to add, arrange or select slide images. Existing text and buttons can still be edited and saved.', 'bingocommerce-core' ); ?></p></noscript>
			<form method="post" action="<?php echo esc_url( self::tab_url( 'slider' ) ); ?>" novalidate id="bc-form">
				<?php wp_nonce_field( self::nonce_action() ); ?>
				<input type="hidden" name="bc_homepage_slider[version]" value="1">
				<input type="hidden" name="bc_slider_count" value="<?php echo esc_attr( (string) count( $values['slides'] ) ); ?>">
				<div data-slide-list>
					<?php foreach ( $values['slides'] as $i => $slide ) { self::slide( (string) $i, $slide, $errors ); } ?>
				</div>
				<p><button type="button" class="button" data-add-slide hidden><?php esc_html_e( 'Add slide', 'bingocommerce-core' ); ?></button> <span data-slide-count></span></p>
				<!-- Last data field: a truncated request cannot erase later slides. -->
				<input type="hidden" name="bc_slider_complete" value="1">
				<?php submit_button( __( 'Save slider', 'bingocommerce-core' ) ); ?>
			</form>
			<template id="bc-slide-template"><?php self::slide( '__INDEX__', [ 'image_id' => 0, 'image_position' => 'right-low', 'heading' => '', 'text' => '', 'buttons' => [] ], [] ); ?></template>
			<?php else : HomepageCategoriesAdmin::render( $category_outcome ); endif; ?>
		</div>
		<?php
	}

	private static function slide( string $index, array $slide, array $errors ): void {
		$image = HomepageSlider::image( $slide['image_id'] );
		$name = 'bc_homepage_slider[slides][' . $index . ']';
		$key = 'slide-' . $index . '-';
		?>
		<fieldset class="bc-slide" data-slide>
			<legend data-slide-title><?php echo esc_html( sprintf( __( 'Slide %d', 'bingocommerce-core' ), (int) $index + 1 ) ); ?></legend>
			<div class="bc-slide__tools" hidden>
				<button type="button" class="button" data-move="up"><?php esc_html_e( 'Move up', 'bingocommerce-core' ); ?></button>
				<button type="button" class="button" data-move="down"><?php esc_html_e( 'Move down', 'bingocommerce-core' ); ?></button>
				<button type="button" class="button" data-remove-slide><?php esc_html_e( 'Remove slide', 'bingocommerce-core' ); ?></button>
			</div>
			<div class="bc-slide__image">
				<img data-image-preview <?php if ( $image ) : ?>src="<?php echo esc_url( $image[0] ); ?>"<?php else : ?>hidden<?php endif; ?> alt="">
				<input type="hidden" data-image-id name="<?php echo esc_attr( $name . '[image_id]' ); ?>" value="<?php echo esc_attr( (string) $slide['image_id'] ); ?>">
				<button type="button" class="button" data-select-image id="<?php echo esc_attr( 'bc-' . $key . 'image_id' ); ?>" <?php self::error_attributes( $key . 'image_id', $errors ); ?> hidden><?php esc_html_e( 'Select / replace image', 'bingocommerce-core' ); ?></button>
				<?php self::field_error( $key . 'image_id', $errors ); ?>
				<?php if ( $slide['image_id'] > 0 && ! $image ) : ?><p class="description" data-image-warning><?php esc_html_e( 'This image is unavailable. The slide is omitted from the homepage until you replace its image or remove the slide.', 'bingocommerce-core' ); ?></p><?php endif; ?>
				<p class="description"><?php esc_html_e( 'Use a wide image with clear space for white text. Reference size: 1672 × 941. Images are cropped to fill the banner; preview both desktop and mobile.', 'bingocommerce-core' ); ?></p>
			</div>
			<p><label for="<?php echo esc_attr( 'bc-' . $key . 'image_position' ); ?>"><?php esc_html_e( 'Image framing', 'bingocommerce-core' ); ?></label>
				<select id="<?php echo esc_attr( 'bc-' . $key . 'image_position' ); ?>" name="<?php echo esc_attr( $name . '[image_position]' ); ?>" <?php self::error_attributes( $key . 'image_position', $errors ); ?>>
					<option value="right-low" <?php selected( $slide['image_position'], 'right-low' ); ?>><?php esc_html_e( 'Right / lower (90% / 75%)', 'bingocommerce-core' ); ?></option>
					<option value="center" <?php selected( $slide['image_position'], 'center' ); ?>><?php esc_html_e( 'Center (50% / 40%)', 'bingocommerce-core' ); ?></option>
				</select><?php self::field_error( $key . 'image_position', $errors ); ?></p>
			<?php self::input( $name . '[heading]', $key . 'heading', __( 'Heading (required, 80 characters)', 'bingocommerce-core' ), $slide['heading'], 'text', $errors ); ?>
			<?php self::input( $name . '[text]', $key . 'text', __( 'Supporting text (optional, 160 characters)', 'bingocommerce-core' ), $slide['text'], 'text', $errors ); ?>
			<?php for ( $b = 0; $b < 2; ++$b ) : ?>
				<div class="bc-slide__button">
					<h3><?php echo esc_html( sprintf( __( 'Button %d (optional)', 'bingocommerce-core' ), $b + 1 ) ); ?></h3>
					<?php self::input( $name . '[buttons][' . $b . '][label]', $key . 'button-' . $b . '-label', __( 'Label (32 characters)', 'bingocommerce-core' ), $slide['buttons'][ $b ]['label'] ?? '', 'text', $errors ); ?>
					<?php self::input( $name . '[buttons][' . $b . '][url]', $key . 'button-' . $b . '-url', __( 'Destination (full http:// or https:// URL)', 'bingocommerce-core' ), $slide['buttons'][ $b ]['url'] ?? '', 'url', $errors ); ?>
				</div>
			<?php endfor; ?>
			<p class="description"><?php esc_html_e( 'Clear both fields to omit a button. Buttons open pages; add-to-cart and other action URLs are not allowed.', 'bingocommerce-core' ); ?></p>
		</fieldset>
		<?php
	}

	private static function input( string $name, string $key, string $label, string $value, string $type, array $errors ): void {
		?>
		<p><label for="<?php echo esc_attr( 'bc-' . $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input class="large-text" type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( 'bc-' . $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php if ( isset( $errors[ $key ] ) ) : ?>aria-invalid="true" aria-describedby="<?php echo esc_attr( 'bc-' . $key . '-error' ); ?>"<?php endif; ?>>
			<?php self::field_error( $key, $errors ); ?></p>
		<?php
	}

	private static function field_error( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) {
			echo '<span class="bc-slide__error" id="' . esc_attr( 'bc-' . $key . '-error' ) . '">' . esc_html( $errors[ $key ] ) . '</span>';
		}
	}

	private static function error_attributes( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) {
			echo 'aria-invalid="true" aria-describedby="' . esc_attr( 'bc-' . $key . '-error' ) . '"';
		}
	}
}
