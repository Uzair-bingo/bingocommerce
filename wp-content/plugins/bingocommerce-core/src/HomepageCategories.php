<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Hall-local display links only; never owns or mutates WooCommerce categories. */
final class HomepageCategories {
	public const OPTION = 'bc_homepage_categories';
	public const FILTER = 'bingocommerce_homepage_categories';
	public const MAX_CARDS = 5;

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'provide' ] );
	}

	public static function blank(): array {
		return [ 'version' => 1, 'background_id' => 0, 'cards' => [] ];
	}

	public static function card(): array {
		return [ 'image_id' => 0, 'background_id' => 0, 'text' => '', 'destination' => [ 'type' => 'url', 'url' => '', 'category_id' => 0 ] ];
	}

	/** No repair on read; native current-site option/cache context follows Hall switches. */
	public static function stored(): array|\WP_Error {
		$value = get_option( self::OPTION, self::blank() );
		if ( is_array( $value ) ) {
			$checked = self::validate( $value, false );
			if ( [] === $checked['errors'] && $checked['values'] === $value ) { return $value; }
		}
		return self::storage_error();
	}

	/** Ordered usable cards plus optional background; plain display values, no markup. */
	public static function provide( $ignored = [] ): array {
		if ( ! HallContext::is_hall() ) { return []; }
		$stored = self::stored();
		if ( is_wp_error( $stored ) ) { return []; }
		$result = [ 'background_id' => self::background( $stored['background_id'] ), 'cards' => [] ];
		foreach ( $stored['cards'] as $card ) {
			$link = self::destination( $card['destination'] );
			$name = self::name( $card, $link['category_name'] ?? '' );
			if ( ! HomepageSlider::image( $card['image_id'] ) || false === $link || '' === $name ) { continue; }
			$result['cards'][] = [ 'image_id' => $card['image_id'], 'background_id' => self::background( $card['background_id'] ), 'text' => $card['text'], 'url' => $link['url'], 'name' => $name ];
		}
		return $result['cards'] ? $result : [];
	}

	private static function background( int $id ): int {
		return $id > 0 && HomepageSlider::image( $id ) ? $id : 0;
	}

	/** A numeric ID has meaning only in this Hall's product_cat taxonomy. */
	public static function category( int $id ): \WP_Term|false {
		if ( ! HallContext::is_hall() || $id < 1 || ! taxonomy_exists( 'product_cat' ) ) { return false; }
		$term = get_term( $id, 'product_cat' );
		return $term instanceof \WP_Term && 'product_cat' === $term->taxonomy ? $term : false;
	}

	private static function destination( array $destination ): array|false {
		if ( 'url' === $destination['type'] ) {
			return HomepageSlider::url_valid( $destination['url'] ) ? [ 'url' => $destination['url'], 'category_name' => '' ] : false;
		}
		$term = self::category( $destination['category_id'] );
		if ( ! $term ) { return false; }
		$url = get_term_link( $term, 'product_cat' );
		return is_string( $url ) && HomepageSlider::url_valid( $url ) ? [ 'url' => $url, 'category_name' => self::plain_name( $term->name ) ] : false;
	}

	private static function plain_name( mixed $value ): string {
		// Names come only from explicit text, term names or attachment ALT metadata,
		// never attachment titles, filenames, URLs or a generic "image" fallback.
		if ( ! is_string( $value ) ) { return ''; }
		$value = trim( wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[\x00-\x1F\x7F]/u', $value ) ? $value : '';
	}

	private static function name( array $card, string $category_name ): string {
		if ( '' !== $card['text'] ) { return $card['text']; }
		if ( '' !== $category_name ) { return $category_name; }
		return self::plain_name( get_post_meta( $card['image_id'], '_wp_attachment_image_alt', true ) );
	}

	private static function id( mixed $value ): int|false {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) ) { return false; }
		return filter_var( $value, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] );
	}

	/** False checks only storage shape; deleted references remain editable for repair. */
	public static function validate( array $input, bool $resolve = true ): array {
		$values = self::blank(); $errors = [];
		if ( ! HomepageSlider::keys( $input, [ 'version', 'background_id', 'cards' ] ) || ! in_array( $input['version'], [ 1, '1' ], true )
			|| ! is_array( $input['cards'] ) || ! array_is_list( $input['cards'] ) || count( $input['cards'] ) > self::MAX_CARDS ) {
			return [ 'values' => $values, 'errors' => [ 'form' => __( 'Submit a complete list of up to five cards. Nothing was saved.', 'bingocommerce-core' ) ] ];
		}
		$validate_image = static function ( mixed $raw, string $key, bool $required ) use ( &$errors, $resolve ): int {
			$id = self::id( $raw );
			if ( false === $id || ( $required && 0 === $id ) || ( $resolve && $id > 0 && ! HomepageSlider::image( $id ) ) ) {
				$errors[ $key ] = $required ? __( 'Select an available main image from this Hall’s Media Library.', 'bingocommerce-core' ) : __( 'Select an available background from this Hall’s Media Library, or remove it.', 'bingocommerce-core' );
			}
			return false === $id ? 0 : $id;
		};
		$values['background_id'] = $validate_image( $input['background_id'], 'background_id', false );
		foreach ( $input['cards'] as $i => $row ) {
			$key = 'card-' . $i . '-';
			if ( ! is_array( $row ) || ! HomepageSlider::keys( $row, array_keys( self::card() ) ) ) {
				$errors['form'] = __( 'A card could not be read. Reload the form and try again.', 'bingocommerce-core' ); continue;
			}
			$card = self::card();
			$card['image_id'] = $validate_image( $row['image_id'], $key . 'image_id', true );
			$card['background_id'] = $validate_image( $row['background_id'], $key . 'background_id', false );
			$card['text'] = is_string( $row['text'] ) ? trim( $row['text'] ) : '';
			if ( ! HomepageSlider::text_valid( $row['text'], HomepageSlider::LIMITS['heading'] ) ) {
				$errors[ $key . 'text' ] = __( 'Use plain visible text of up to 80 characters, or leave it blank.', 'bingocommerce-core' );
			}
			$d = $row['destination']; $link = false;
			if ( ! is_array( $d ) || ! HomepageSlider::keys( $d, [ 'type', 'url', 'category_id' ] ) ) {
				$errors[ $key . 'type' ] = __( 'Choose one complete destination.', 'bingocommerce-core' );
			} else {
				$id = self::id( $d['category_id'] );
				$card['destination'] = [ 'type' => in_array( $d['type'], [ 'url', 'category' ], true ) ? $d['type'] : 'url', 'url' => is_string( $d['url'] ) ? trim( $d['url'] ) : '', 'category_id' => false === $id ? 0 : $id ];
				if ( ! in_array( $d['type'], [ 'url', 'category' ], true ) ) { $errors[ $key . 'type' ] = __( 'Choose Custom URL or Product category.', 'bingocommerce-core' ); }
				if ( 'url' === $d['type'] ) {
					if ( ! is_string( $d['url'] ) || ! HomepageSlider::url_valid( $card['destination']['url'] ) ) { $errors[ $key . 'url' ] = __( 'Enter a full HTTP(S) navigation URL, up to 2,048 characters. Cart actions are not allowed.', 'bingocommerce-core' ); }
					if ( 0 !== $id ) { $errors[ $key . 'category_id' ] = __( 'Clear the unused product category for a Custom URL destination.', 'bingocommerce-core' ); }
				} elseif ( 'category' === $d['type'] ) {
					if ( false === $id || 0 === $id ) { $errors[ $key . 'category_id' ] = __( 'Select a product category from this Hall.', 'bingocommerce-core' ); }
					if ( '' !== $d['url'] ) { $errors[ $key . 'url' ] = __( 'Clear the unused custom URL for a Product category destination.', 'bingocommerce-core' ); }
				}
				if ( $resolve ) {
					$link = self::destination( $card['destination'] );
					if ( false === $link && 'category' === $d['type'] ) { $errors[ $key . 'category_id' ] = __( 'This Hall’s product category or its archive link is unavailable. Choose another destination.', 'bingocommerce-core' ); }
				}
			}
			if ( $resolve && '' === self::name( $card, $link['category_name'] ?? '' ) ) {
				$errors[ $key . 'text' ] = __( 'Give this link a descriptive name: enter visible text, select a named category, or add descriptive main-image Alt Text in the Media Library. Filenames are not used.', 'bingocommerce-core' );
			}
			$values['cards'][] = $card;
		}
		return [ 'values' => $values, 'errors' => $errors ];
	}

	public static function save( array $values ): bool|\WP_Error {
		$checked = self::validate( $values );
		if ( ! HallContext::is_hall() || $checked['errors'] ) { return new \WP_Error( 'bc_homepage_categories_value', __( 'Invalid category cards. Nothing was saved.', 'bingocommerce-core' ) ); }
		$values = $checked['values'];
		if ( self::stored() === $values ) { return true; }
		update_option( self::OPTION, $values, false );
		return self::stored() === $values ? true : self::storage_error();
	}

	private static function storage_error(): \WP_Error {
		return new \WP_Error( 'bc_homepage_categories_storage', __( 'The saved category cards could not be read or their save confirmed. Reload and check the saved values.', 'bingocommerce-core' ) );
	}
}
