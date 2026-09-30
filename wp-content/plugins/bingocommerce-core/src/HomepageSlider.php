<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hall-local display configuration only. ADR-045 addition, 2026-09-30. */
final class HomepageSlider {
	public const OPTION = 'bc_homepage_slider';
	public const FILTER = 'bingocommerce_homepage_slider';
	public const MAX_SLIDES = 6;
	public const POSITIONS = [ 'right-low', 'center' ];
	public const LIMITS = [ 'heading' => 80, 'text' => 160, 'label' => 32, 'url' => 2048 ];

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'provide' ] );
	}

	public static function blank(): array {
		return [ 'version' => 1, 'slides' => [] ];
	}

	/** Never repairs storage on read, and never caches across switch_to_blog(). */
	public static function stored(): array|\WP_Error {
		$value = get_option( self::OPTION, self::blank() );
		if ( ! is_array( $value ) ) {
			return self::storage_error();
		}
		$checked = self::validate( $value, false );
		return [] === $checked['errors'] && $checked['values'] === $value ? $value : self::storage_error();
	}

	/** Read-only storefront contract: ordered usable slides, no markup. */
	public static function provide( $ignored = [] ): array {
		if ( ! HallContext::is_hall() ) {
			return [];
		}
		$stored = self::stored();
		if ( is_wp_error( $stored ) ) {
			return [];
		}
		return array_values( array_filter( $stored['slides'], static fn( array $slide ): bool => false !== self::image( $slide['image_id'] ) ) );
	}

	/** Resolve ONLY against the current Hall's attachment table and upload context. */
	public static function image( int $id ): array|false {
		if ( ! HallContext::is_hall() || $id < 1 ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type
			|| ! in_array( $post->post_status, [ 'inherit', 'publish' ], true )
			|| ! wp_attachment_is_image( $id ) ) {
			return false;
		}
		$image = wp_get_attachment_image_src( $id, 'full' );
		return is_array( $image ) && '' !== $image[0] && $image[1] > 0 && $image[2] > 0 ? $image : false;
	}

	/**
	 * Validate every field before any write. False is used only to inspect stored
	 * configuration: deleted images remain visible to the administrator for repair.
	 * @return array{values:array,errors:array<string,string>}
	 */
	public static function validate( array $input, bool $resolve_images = true ): array {
		$values = self::blank();
		$errors = [];
		if ( ! self::keys( $input, [ 'version', 'slides' ] ) || ! in_array( $input['version'], [ 1, '1' ], true )
			|| ! is_array( $input['slides'] ) || ! array_is_list( $input['slides'] ) || count( $input['slides'] ) > self::MAX_SLIDES ) {
			return [ 'values' => $values, 'errors' => [ 'form' => __( 'Submit a complete list of up to six slides. Nothing was saved.', 'bingocommerce-core' ) ] ];
		}
		foreach ( $input['slides'] as $index => $row ) {
			$prefix = 'slide-' . $index . '-';
			if ( ! is_array( $row ) || ! self::keys( $row, [ 'image_id', 'image_position', 'heading', 'text', 'buttons' ] ) ) {
				$errors['form'] = __( 'A slide could not be read. Reload the form and try again.', 'bingocommerce-core' );
				continue;
			}
			$id = ( is_int( $row['image_id'] ) || ( is_string( $row['image_id'] ) && preg_match( '/\A[1-9][0-9]*\z/', $row['image_id'] ) ) )
				? filter_var( $row['image_id'], FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] ) : false;
			$slide = [ 'image_id' => false === $id ? 0 : $id, 'image_position' => $row['image_position'], 'heading' => '', 'text' => '', 'buttons' => [] ];
			if ( false === $id || ( $resolve_images && false === self::image( $id ) ) ) {
				$errors[ $prefix . 'image_id' ] = __( 'Select an available image from this Hall’s Media Library.', 'bingocommerce-core' );
			}
			if ( ! in_array( $row['image_position'], self::POSITIONS, true ) ) {
				$errors[ $prefix . 'image_position' ] = __( 'Choose one of the two image framing presets.', 'bingocommerce-core' );
				$slide['image_position'] = 'right-low';
			}
			foreach ( [ 'heading', 'text' ] as $field ) {
				$slide[ $field ] = is_string( $row[ $field ] ) ? trim( $row[ $field ] ) : '';
				if ( ! self::text_valid( $row[ $field ], self::LIMITS[ $field ] ) || ( 'heading' === $field && '' === $slide[ $field ] ) ) {
					$errors[ $prefix . $field ] = 'heading' === $field
						? __( 'Enter a plain-text heading of up to 80 characters.', 'bingocommerce-core' )
						: __( 'Use plain supporting text of up to 160 characters, or leave it blank.', 'bingocommerce-core' );
				}
			}
			if ( ! is_array( $row['buttons'] ) || ! array_is_list( $row['buttons'] ) || count( $row['buttons'] ) > 2 ) {
				$errors['form'] = __( 'Each slide may have up to two buttons.', 'bingocommerce-core' );
			} else {
				foreach ( $row['buttons'] as $button_index => $button ) {
					$key = $prefix . 'button-' . $button_index;
					if ( ! is_array( $button ) || ! self::keys( $button, [ 'label', 'url' ] ) ) {
						$errors['form'] = __( 'A button could not be read. Reload the form and try again.', 'bingocommerce-core' );
						continue;
					}
					$label = is_string( $button['label'] ) ? trim( $button['label'] ) : '';
					$url = is_string( $button['url'] ) ? trim( $button['url'] ) : '';
					if ( ! self::text_valid( $button['label'], self::LIMITS['label'] ) || ( '' === $label && '' !== $url ) ) {
						$errors[ $key . '-label' ] = __( 'Enter a plain-text label of up to 32 characters, or clear both button fields.', 'bingocommerce-core' );
					}
					if ( ! is_string( $button['url'] ) || ( '' !== $label && '' === $url ) || ( '' !== $url && ! self::url_valid( $url ) ) ) {
						$errors[ $key . '-url' ] = __( 'Enter a full http:// or https:// navigation URL, up to 2,048 characters. Cart actions are not allowed.', 'bingocommerce-core' );
					}
					// Keep blank slots until validation succeeds, so errors stay with their fields.
					$slide['buttons'][] = [ 'label' => $label, 'url' => $url ];
				}
			}
			$values['slides'][] = $slide;
		}
		if ( [] === $errors ) {
			foreach ( $values['slides'] as &$slide ) {
				$slide['buttons'] = array_values( array_filter( $slide['buttons'], static fn( array $b ): bool => '' !== $b['label'] ) );
			}
			unset( $slide );
		}
		return [ 'values' => $values, 'errors' => $errors ];
	}

	/** Controller owns authorization; the writer revalidates even internal callers. */
	public static function save( array $values ): bool|\WP_Error {
		$checked = self::validate( $values );
		if ( ! HallContext::is_hall() || [] !== $checked['errors'] ) {
			return new \WP_Error( 'bc_homepage_slider_value', __( 'Invalid slider configuration. Nothing was saved.', 'bingocommerce-core' ) );
		}
		$values = $checked['values'];
		if ( self::stored() === $values ) {
			return true;
		}
		update_option( self::OPTION, $values, false );
		return self::stored() === $values ? true : self::storage_error();
	}

	public static function keys( array $input, array $expected ): bool {
		$received = array_keys( $input );
		sort( $received ); sort( $expected );
		return $received === $expected;
	}

	private static function text_valid( mixed $value, int $max ): bool {
		return is_string( $value ) && 1 === preg_match( '//u', $value ) && mb_strlen( trim( $value ) ) <= $max
			&& 0 === preg_match( '/[\x00-\x1F\x7F<>]/u', $value );
	}

	private static function url_valid( string $url ): bool {
		if ( ! self::text_valid( $url, self::LIMITS['url'] ) || preg_match( '/[\s"\'\\\\]|%0[0-9a-f]|%1[0-9a-f]|%7f/i', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true )
			|| empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || false === filter_var( $url, FILTER_VALIDATE_URL )
			|| esc_url_raw( $url, [ 'http', 'https' ] ) !== $url ) {
			return false;
		}
		// Navigation only; never accept WooCommerce/cart or WordPress action endpoints.
		wp_parse_str( $parts['query'] ?? '', $query );
		foreach ( array_keys( $query ) as $key ) {
			if ( in_array( strtolower( (string) $key ), [ 'add-to-cart', 'wc-ajax', 'rest_route', 'action', 'remove_item', 'undo_item', 'apply_coupon' ], true ) ) {
				return false;
			}
		}
		return ! preg_match( '~/(?:wp-admin|wp-json)(?:/|$)|/(?:wp-login|xmlrpc)\.php(?:/|$)~i', rawurldecode( $parts['path'] ?? '' ) );
	}

	private static function storage_error(): \WP_Error {
		return new \WP_Error( 'bc_homepage_slider_storage', __( 'The saved slider could not be read or its save confirmed. Reload and check the saved values.', 'bingocommerce-core' ) );
	}
}
