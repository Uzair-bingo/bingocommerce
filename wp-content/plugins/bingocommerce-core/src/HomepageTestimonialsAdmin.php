<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Independent form within Appearance → Homepage; shares the Hall settings boundary. */
final class HomepageTestimonialsAdmin {
	private static function nonce_action(): string {
		return 'bingocommerce_homepage_testimonials:hall:' . get_current_blog_id();
	}

	public static function is_request( array $posted ): bool {
		foreach ( [ 'bc_testimonials_action', 'bc_testimonials_nonce', HomepageTestimonials::OPTION, 'bc_testimonials_count', 'bc_testimonials_complete' ] as $key ) {
			if ( array_key_exists( $key, $posted ) ) { return true; }
		}
		return false;
	}

	public static function handle_request( array $posted ): array {
		$fail = static fn( string $code, array $values = [], array $errors = [] ): array => [ 'result' => new \WP_Error( 'bc_homepage_testimonials_' . $code, match ( $code ) {
			'denied' => __( 'You are not authorized to manage this Hall’s testimonials.', 'bingocommerce-core' ),
			'nonce' => __( 'Testimonials verification failed. Reload this page and try again.', 'bingocommerce-core' ),
			default => __( 'Testimonials need correcting. Nothing was saved.', 'bingocommerce-core' ),
		} ), 'values' => $values, 'errors' => $errors ];
		if ( ! HomepageAdmin::allowed() ) { return $fail( 'denied' ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return $fail( 'request' ); }
		if ( ! is_string( $posted['bc_testimonials_nonce'] ?? null ) || ! wp_verify_nonce( $posted['bc_testimonials_nonce'], self::nonce_action() ) ) { return $fail( 'nonce' ); }
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ 'bc_testimonials_nonce', '_wp_http_referer', 'bc_testimonials_action', HomepageTestimonials::OPTION, 'bc_testimonials_count', 'bc_testimonials_complete', 'submit' ], true ) ) { return $fail( 'request' ); }
		}
		$count = $posted['bc_testimonials_count'] ?? null; $input = $posted[ HomepageTestimonials::OPTION ] ?? null;
		if ( 'save' !== ( $posted['bc_testimonials_action'] ?? null ) || '1' !== ( $posted['bc_testimonials_complete'] ?? null )
			|| ! is_string( $count ) || ! preg_match( '/\A[0-6]\z/', $count ) || ! is_array( $input ) ) { return $fail( 'request' ); }
		// HTML forms cannot transmit an empty array. Only explicit zero permits absence.
		if ( '0' === $count && ! array_key_exists( 'items', $input ) ) { $input['items'] = []; }
		if ( ! is_array( $input['items'] ?? null ) || count( $input['items'] ) !== (int) $count ) { return $fail( 'request' ); }
		$checked = HomepageTestimonials::validate( $input );
		if ( $checked['errors'] ) { return $fail( 'value', $checked['values'], $checked['errors'] ); }
		return [ 'result' => HomepageTestimonials::save( $checked['values'] ), 'values' => $checked['values'], 'errors' => [] ];
	}

	public static function render( ?array $outcome = null ): void {
		if ( ! HomepageAdmin::allowed() ) { return; }
		$stored = HomepageTestimonials::stored();
		$errors = $outcome['errors'] ?? [];
		// Redisplay submitted testimonials only when every row was read; otherwise show what is saved.
		$values = null !== $outcome && is_wp_error( $outcome['result'] ) && $outcome['values'] && ! isset( $errors['form'] ) ? $outcome['values'] : ( is_wp_error( $stored ) ? HomepageTestimonials::blank() : $stored );
		?>
		<section class="bc-list-editor" aria-labelledby="bc-testimonials-title">
			<h2 id="bc-testimonials-title"><?php esc_html_e( 'Testimonials', 'bingocommerce-core' ); ?></h2>
			<p><?php esc_html_e( 'Add up to six customer testimonials. Publish only real customer words you have permission to use. With no testimonials, the section is not shown.', 'bingocommerce-core' ); ?></p>
			<?php if ( null !== $outcome ) : ?>
				<div class="notice <?php echo is_wp_error( $outcome['result'] ) ? 'notice-error' : 'notice-success'; ?>" role="<?php echo is_wp_error( $outcome['result'] ) ? 'alert' : 'status'; ?>">
					<p><?php echo esc_html( is_wp_error( $outcome['result'] ) ? $outcome['result']->get_error_message() : __( 'Testimonials saved.', 'bingocommerce-core' ) ); ?></p>
					<?php if ( $errors ) : ?><ul><?php foreach ( $errors as $key => $error ) : ?><li><a href="<?php echo esc_attr( '#bc-testimonials-' . $key ); ?>"><?php echo esc_html( $error ); ?></a></li><?php endforeach; ?></ul><?php endif; ?>
				</div>
			<?php elseif ( is_wp_error( $stored ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $stored->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to add or remove testimonials. Existing testimonials can still be edited and saved.', 'bingocommerce-core' ); ?></p></noscript>
			<form method="post" action="<?php echo esc_url( HomepageAdmin::tab_url( 'testimonials' ) ); ?>" novalidate id="bc-testimonials-form" data-bc-list data-max="<?php echo esc_attr( (string) HomepageTestimonials::MAX_ITEMS ); ?>"
				data-legend="<?php esc_attr_e( 'Testimonial %d', 'bingocommerce-core' ); ?>" data-changed="<?php esc_attr_e( 'Testimonials updated. Save testimonials to apply changes.', 'bingocommerce-core' ); ?>">
				<input type="hidden" name="bc_testimonials_action" value="save">
				<?php wp_nonce_field( self::nonce_action(), 'bc_testimonials_nonce', false ); ?>
				<input type="hidden" name="bc_homepage_testimonials[version]" value="1">
				<input type="hidden" name="bc_testimonials_count" data-list-count value="<?php echo esc_attr( (string) count( $values['items'] ) ); ?>">
				<div data-list><?php foreach ( $values['items'] as $i => $item ) { self::item( (string) $i, $item, $errors ); } ?></div>
				<p><button type="button" class="button" data-list-add hidden><?php esc_html_e( 'Add testimonial', 'bingocommerce-core' ); ?></button> <span data-list-status></span></p>
				<!-- Last data field: a truncated request cannot erase later testimonials. -->
				<input type="hidden" name="bc_testimonials_complete" value="1">
				<?php submit_button( __( 'Save testimonials', 'bingocommerce-core' ), 'primary', 'submit', true, [ 'id' => 'bc-testimonials-save' ] ); ?>
			</form>
			<template data-list-template><?php self::item( '__INDEX__', HomepageTestimonials::item(), [] ); ?></template>
		</section>
		<?php
	}

	private static function item( string $index, array $item, array $errors ): void {
		$name = 'bc_homepage_testimonials[items][' . $index . ']'; $key = 'item-' . $index . '-';
		?>
		<fieldset class="bc-list-item" data-list-item>
			<legend data-list-legend><?php echo esc_html( sprintf( __( 'Testimonial %d', 'bingocommerce-core' ), (int) $index + 1 ) ); ?></legend>
			<p><button type="button" class="button" data-list-remove hidden><?php esc_html_e( 'Remove testimonial', 'bingocommerce-core' ); ?></button></p>
			<p><label for="<?php echo esc_attr( 'bc-testimonials-' . $key . 'quote' ); ?>"><?php esc_html_e( 'Quote (required, 420 characters, one paragraph, no quotation marks)', 'bingocommerce-core' ); ?></label>
			<textarea class="large-text" rows="3" id="<?php echo esc_attr( 'bc-testimonials-' . $key . 'quote' ); ?>" name="<?php echo esc_attr( $name . '[quote]' ); ?>" <?php self::attributes( $key . 'quote', $errors ); ?>><?php echo esc_textarea( $item['quote'] ); ?></textarea>
			<?php self::error( $key . 'quote', $errors ); ?></p>
			<?php self::input( $name . '[name]', $key . 'name', __( 'Name (required, 60 characters)', 'bingocommerce-core' ), $item['name'], $errors ); ?>
			<?php self::input( $name . '[role]', $key . 'role', __( 'Role (optional, 60 characters), for example Hall Organizer', 'bingocommerce-core' ), $item['role'], $errors ); ?>
		</fieldset>
		<?php
	}

	private static function input( string $name, string $key, string $label, string $value, array $errors ): void {
		printf( '<p><label for="%1$s">%2$s</label><input class="large-text" type="text" id="%1$s" name="%3$s" value="%4$s" ', esc_attr( 'bc-testimonials-' . $key ), esc_html( $label ), esc_attr( $name ), esc_attr( $value ) );
		self::attributes( $key, $errors ); echo '>'; self::error( $key, $errors ); echo '</p>';
	}

	private static function attributes( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) { echo 'aria-invalid="true" aria-describedby="' . esc_attr( 'bc-testimonials-' . $key . '-error' ) . '"'; }
	}

	private static function error( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) { echo '<span class="bc-list-error" id="' . esc_attr( 'bc-testimonials-' . $key . '-error' ) . '">' . esc_html( $errors[ $key ] ) . '</span>'; }
	}
}
