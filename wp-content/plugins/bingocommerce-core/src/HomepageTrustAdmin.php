<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Independent form within Appearance → Homepage; shares the Hall settings boundary. */
final class HomepageTrustAdmin {
	private static function nonce_action(): string {
		return 'bingocommerce_homepage_trust:hall:' . get_current_blog_id();
	}

	public static function is_request( array $posted ): bool {
		foreach ( [ 'bc_trust_action', 'bc_trust_nonce', HomepageTrust::OPTION, 'bc_trust_count', 'bc_trust_complete' ] as $key ) {
			if ( array_key_exists( $key, $posted ) ) { return true; }
		}
		return false;
	}

	public static function handle_request( array $posted ): array {
		$fail = static fn( string $code, array $values = [], array $errors = [] ): array => [ 'result' => new \WP_Error( 'bc_homepage_trust_' . $code, match ( $code ) {
			'denied' => __( 'You are not authorized to manage this Hall’s trust row.', 'bingocommerce-core' ),
			'nonce' => __( 'Trust row verification failed. Reload this page and try again.', 'bingocommerce-core' ),
			default => __( 'The trust row needs correcting. Nothing was saved.', 'bingocommerce-core' ),
		} ), 'values' => $values, 'errors' => $errors ];
		if ( ! HomepageAdmin::allowed() ) { return $fail( 'denied' ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return $fail( 'request' ); }
		if ( ! is_string( $posted['bc_trust_nonce'] ?? null ) || ! wp_verify_nonce( $posted['bc_trust_nonce'], self::nonce_action() ) ) { return $fail( 'nonce' ); }
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ 'bc_trust_nonce', '_wp_http_referer', 'bc_trust_action', HomepageTrust::OPTION, 'bc_trust_count', 'bc_trust_complete', 'submit' ], true ) ) { return $fail( 'request' ); }
		}
		$count = $posted['bc_trust_count'] ?? null; $input = $posted[ HomepageTrust::OPTION ] ?? null;
		if ( 'save' !== ( $posted['bc_trust_action'] ?? null ) || '1' !== ( $posted['bc_trust_complete'] ?? null )
			|| ! is_string( $count ) || ! preg_match( '/\A[0-4]\z/', $count ) || ! is_array( $input ) ) { return $fail( 'request' ); }
		// HTML forms cannot transmit an empty array. Only explicit zero permits absence.
		if ( '0' === $count && ! array_key_exists( 'items', $input ) ) { $input['items'] = []; }
		if ( ! is_array( $input['items'] ?? null ) || count( $input['items'] ) !== (int) $count ) { return $fail( 'request' ); }
		$checked = HomepageTrust::validate( $input );
		if ( $checked['errors'] ) { return $fail( 'value', $checked['values'], $checked['errors'] ); }
		return [ 'result' => HomepageTrust::save( $checked['values'] ), 'values' => $checked['values'], 'errors' => [] ];
	}

	public static function render( ?array $outcome = null ): void {
		if ( ! HomepageAdmin::allowed() ) { return; }
		$stored = HomepageTrust::stored();
		$errors = $outcome['errors'] ?? [];
		// Redisplay submitted items only when every row was read; otherwise show what is saved.
		$values = null !== $outcome && is_wp_error( $outcome['result'] ) && $outcome['values'] && ! isset( $errors['form'] ) ? $outcome['values'] : ( is_wp_error( $stored ) ? HomepageTrust::blank() : $stored );
		?>
		<section class="bc-list-editor" aria-labelledby="bc-trust-title">
			<h2 id="bc-trust-title"><?php esc_html_e( 'Trust row', 'bingocommerce-core' ); ?></h2>
			<p><?php esc_html_e( 'Up to four short items shown below the Session calendar. Remove every item and save to hide the row.', 'bingocommerce-core' ); ?></p>
			<?php if ( false === get_option( HomepageTrust::OPTION, false ) ) : ?>
				<p class="description"><?php esc_html_e( 'Your homepage shows these default items until you save your own.', 'bingocommerce-core' ); ?></p>
			<?php endif; ?>
			<?php if ( null !== $outcome ) : ?>
				<div class="notice <?php echo is_wp_error( $outcome['result'] ) ? 'notice-error' : 'notice-success'; ?>" role="<?php echo is_wp_error( $outcome['result'] ) ? 'alert' : 'status'; ?>">
					<p><?php echo esc_html( is_wp_error( $outcome['result'] ) ? $outcome['result']->get_error_message() : __( 'Trust row saved.', 'bingocommerce-core' ) ); ?></p>
					<?php if ( $errors ) : ?><ul><?php foreach ( $errors as $key => $error ) : ?><li><a href="<?php echo esc_attr( '#bc-trust-' . $key ); ?>"><?php echo esc_html( $error ); ?></a></li><?php endforeach; ?></ul><?php endif; ?>
				</div>
			<?php elseif ( is_wp_error( $stored ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $stored->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to add or remove items. Existing items can still be edited and saved.', 'bingocommerce-core' ); ?></p></noscript>
			<form method="post" action="<?php echo esc_url( HomepageAdmin::tab_url( 'trust' ) ); ?>" novalidate id="bc-trust-form" data-bc-list data-max="<?php echo esc_attr( (string) HomepageTrust::MAX_ITEMS ); ?>"
				data-legend="<?php esc_attr_e( 'Item %d', 'bingocommerce-core' ); ?>" data-changed="<?php esc_attr_e( 'Trust row updated. Save the trust row to apply changes.', 'bingocommerce-core' ); ?>">
				<input type="hidden" name="bc_trust_action" value="save">
				<?php wp_nonce_field( self::nonce_action(), 'bc_trust_nonce', false ); ?>
				<input type="hidden" name="bc_homepage_trust[version]" value="1">
				<input type="hidden" name="bc_trust_count" data-list-count value="<?php echo esc_attr( (string) count( $values['items'] ) ); ?>">
				<div data-list><?php foreach ( $values['items'] as $i => $item ) { self::item( (string) $i, $item, $errors ); } ?></div>
				<p><button type="button" class="button" data-list-add hidden><?php esc_html_e( 'Add item', 'bingocommerce-core' ); ?></button> <span data-list-status></span></p>
				<!-- Last data field: a truncated request cannot erase later items. -->
				<input type="hidden" name="bc_trust_complete" value="1">
				<?php submit_button( __( 'Save trust row', 'bingocommerce-core' ), 'primary', 'submit', true, [ 'id' => 'bc-trust-save' ] ); ?>
			</form>
			<template data-list-template><?php self::item( '__INDEX__', HomepageTrust::item(), [] ); ?></template>
		</section>
		<?php
	}

	private static function item( string $index, array $item, array $errors ): void {
		$name = 'bc_homepage_trust[items][' . $index . ']'; $key = 'item-' . $index . '-';
		$icons = [ 'lock' => __( 'Lock', 'bingocommerce-core' ), 'star' => __( 'Star', 'bingocommerce-core' ), 'refresh' => __( 'Refresh arrows', 'bingocommerce-core' ), 'shield' => __( 'Shield with check', 'bingocommerce-core' ) ];
		?>
		<fieldset class="bc-list-item" data-list-item>
			<legend data-list-legend><?php echo esc_html( sprintf( __( 'Item %d', 'bingocommerce-core' ), (int) $index + 1 ) ); ?></legend>
			<p><button type="button" class="button" data-list-remove hidden><?php esc_html_e( 'Remove item', 'bingocommerce-core' ); ?></button></p>
			<p><label for="<?php echo esc_attr( 'bc-trust-' . $key . 'icon' ); ?>"><?php esc_html_e( 'Icon', 'bingocommerce-core' ); ?></label>
			<select id="<?php echo esc_attr( 'bc-trust-' . $key . 'icon' ); ?>" name="<?php echo esc_attr( $name . '[icon]' ); ?>" <?php self::attributes( $key . 'icon', $errors ); ?>>
				<?php foreach ( $icons as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $item['icon'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
			</select><?php self::error( $key . 'icon', $errors ); ?></p>
			<?php self::input( $name . '[title]', $key . 'title', __( 'Title (required, 40 characters)', 'bingocommerce-core' ), $item['title'], $errors ); ?>
			<?php self::input( $name . '[text]', $key . 'text', __( 'Text (optional, 80 characters)', 'bingocommerce-core' ), $item['text'], $errors ); ?>
		</fieldset>
		<?php
	}

	private static function input( string $name, string $key, string $label, string $value, array $errors ): void {
		printf( '<p><label for="%1$s">%2$s</label><input class="large-text" type="text" id="%1$s" name="%3$s" value="%4$s" ', esc_attr( 'bc-trust-' . $key ), esc_html( $label ), esc_attr( $name ), esc_attr( $value ) );
		self::attributes( $key, $errors ); echo '>'; self::error( $key, $errors ); echo '</p>';
	}

	private static function attributes( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) { echo 'aria-invalid="true" aria-describedby="' . esc_attr( 'bc-trust-' . $key . '-error' ) . '"'; }
	}

	private static function error( string $key, array $errors ): void {
		if ( isset( $errors[ $key ] ) ) { echo '<span class="bc-list-error" id="' . esc_attr( 'bc-trust-' . $key . '-error' ) . '">' . esc_html( $errors[ $key ] ) . '</span>'; }
	}
}
