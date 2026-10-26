<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Hall-entered customer quotes, display only. Nothing is seeded. */
final class HomepageTestimonials {
	public const OPTION = 'bc_homepage_testimonials';
	public const FILTER = 'bingocommerce_homepage_testimonials';
	public const MAX_ITEMS = 6;
	public const LIMITS = [ 'quote' => 420, 'name' => 60, 'role' => 60 ];

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'provide' ] );
	}

	public static function blank(): array {
		return [ 'version' => 1, 'items' => [] ];
	}

	public static function item(): array {
		return [ 'quote' => '', 'name' => '', 'role' => '' ];
	}

	/** No repair on read; native current-site option/cache context follows Hall switches. */
	public static function stored(): array|\WP_Error {
		$value = get_option( self::OPTION, self::blank() );
		if ( is_array( $value ) ) {
			$checked = self::validate( $value );
			if ( [] === $checked['errors'] && $checked['values'] === $value ) { return $value; }
		}
		return self::storage_error();
	}

	/** Ordered testimonials as plain text; none hides the section. */
	public static function provide( $ignored = [] ): array {
		if ( ! HallContext::is_hall() ) { return []; }
		$stored = self::stored();
		return is_wp_error( $stored ) ? [] : $stored['items'];
	}

	/** @return array{values:array,errors:array<string,string>} */
	public static function validate( array $input ): array {
		$values = self::blank(); $errors = [];
		if ( ! HomepageSlider::keys( $input, [ 'version', 'items' ] ) || ! in_array( $input['version'], [ 1, '1' ], true )
			|| ! is_array( $input['items'] ) || ! array_is_list( $input['items'] ) || count( $input['items'] ) > self::MAX_ITEMS ) {
			return [ 'values' => $values, 'errors' => [ 'form' => __( 'Submit a complete list of up to six testimonials. Nothing was saved.', 'bingocommerce-core' ) ] ];
		}
		$messages = [
			'quote' => __( 'Enter the quote as one paragraph of plain text, up to 420 characters.', 'bingocommerce-core' ),
			'name' => __( 'Enter a plain-text name of up to 60 characters.', 'bingocommerce-core' ),
			'role' => __( 'Use plain text of up to 60 characters, or leave it blank.', 'bingocommerce-core' ),
		];
		foreach ( $input['items'] as $i => $row ) {
			$key = 'item-' . $i . '-';
			if ( ! is_array( $row ) || ! HomepageSlider::keys( $row, array_keys( self::item() ) ) ) {
				$errors['form'] = __( 'A testimonial could not be read. Reload the form and try again.', 'bingocommerce-core' ); continue;
			}
			$item = self::item();
			foreach ( self::LIMITS as $field => $max ) {
				$item[ $field ] = is_string( $row[ $field ] ) ? trim( $row[ $field ] ) : '';
				if ( ! HomepageSlider::text_valid( $row[ $field ], $max ) || ( 'role' !== $field && '' === $item[ $field ] ) ) {
					$errors[ $key . $field ] = $messages[ $field ];
				}
			}
			$values['items'][] = $item;
		}
		return [ 'values' => $values, 'errors' => $errors ];
	}

	/** Controller owns authorization; the writer revalidates even internal callers. */
	public static function save( array $values ): bool|\WP_Error {
		$checked = self::validate( $values );
		if ( ! HallContext::is_hall() || $checked['errors'] ) { return new \WP_Error( 'bc_homepage_testimonials_value', __( 'Invalid testimonials. Nothing was saved.', 'bingocommerce-core' ) ); }
		$values = $checked['values'];
		if ( self::stored() === $values ) { return true; }
		update_option( self::OPTION, $values, false );
		return self::stored() === $values ? true : self::storage_error();
	}

	private static function storage_error(): \WP_Error {
		return new \WP_Error( 'bc_homepage_testimonials_storage', __( 'The saved testimonials could not be read or their save confirmed. Reload and check the saved values.', 'bingocommerce-core' ) );
	}
}
