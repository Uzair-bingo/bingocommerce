<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Hall-local trust row text only; makes no payment, security or quality decision. */
final class HomepageTrust {
	public const OPTION = 'bc_homepage_trust';
	public const FILTER = 'bingocommerce_homepage_trust';
	public const MAX_ITEMS = 4;
	/** Theme-owned icon names; the theme draws each one. */
	public const ICONS = [ 'lock', 'star', 'refresh', 'shield' ];
	public const LIMITS = [ 'title' => 40, 'text' => 80 ];

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'provide' ] );
	}

	public static function blank(): array {
		return [ 'version' => 1, 'items' => [] ];
	}

	public static function item(): array {
		return [ 'icon' => 'lock', 'title' => '', 'text' => '' ];
	}

	/** The reference row, shown until the Hall saves its own. Never written on read. */
	public static function defaults(): array {
		return [ 'version' => 1, 'items' => [
			[ 'icon' => 'lock', 'title' => __( 'Secure Checkout', 'bingocommerce-core' ), 'text' => __( 'Encrypted, PCI-compliant payments', 'bingocommerce-core' ) ],
			[ 'icon' => 'star', 'title' => __( 'Trusted by Bingo Halls', 'bingocommerce-core' ), 'text' => __( 'A community favorite for game nights', 'bingocommerce-core' ) ],
			[ 'icon' => 'refresh', 'title' => __( 'Easy Reordering', 'bingocommerce-core' ), 'text' => __( 'Restock daubers & cards in seconds', 'bingocommerce-core' ) ],
			[ 'icon' => 'shield', 'title' => __( 'Quality Guaranteed', 'bingocommerce-core' ), 'text' => __( 'Built to last through every session', 'bingocommerce-core' ) ],
		] ];
	}

	/** No repair on read; native current-site option/cache context follows Hall switches. */
	public static function stored(): array|\WP_Error {
		$value = get_option( self::OPTION, false );
		if ( false === $value ) { return self::defaults(); }
		if ( is_array( $value ) ) {
			$checked = self::validate( $value );
			if ( [] === $checked['errors'] && $checked['values'] === $value ) { return $value; }
		}
		return self::storage_error();
	}

	/** Ordered items as plain text; an empty saved list hides the row. */
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
			return [ 'values' => $values, 'errors' => [ 'form' => __( 'Submit a complete list of up to four items. Nothing was saved.', 'bingocommerce-core' ) ] ];
		}
		foreach ( $input['items'] as $i => $row ) {
			$key = 'item-' . $i . '-';
			if ( ! is_array( $row ) || ! HomepageSlider::keys( $row, array_keys( self::item() ) ) ) {
				$errors['form'] = __( 'An item could not be read. Reload the form and try again.', 'bingocommerce-core' ); continue;
			}
			$item = self::item();
			if ( in_array( $row['icon'], self::ICONS, true ) ) { $item['icon'] = $row['icon']; } else { $errors[ $key . 'icon' ] = __( 'Choose one of the four icons.', 'bingocommerce-core' ); }
			$item['title'] = is_string( $row['title'] ) ? trim( $row['title'] ) : '';
			if ( ! HomepageSlider::text_valid( $row['title'], self::LIMITS['title'] ) || '' === $item['title'] ) {
				$errors[ $key . 'title' ] = __( 'Enter a plain-text title of up to 40 characters.', 'bingocommerce-core' );
			}
			$item['text'] = is_string( $row['text'] ) ? trim( $row['text'] ) : '';
			if ( ! HomepageSlider::text_valid( $row['text'], self::LIMITS['text'] ) ) {
				$errors[ $key . 'text' ] = __( 'Use plain text of up to 80 characters, or leave it blank.', 'bingocommerce-core' );
			}
			$values['items'][] = $item;
		}
		return [ 'values' => $values, 'errors' => $errors ];
	}

	/** Controller owns authorization; the writer revalidates even internal callers. */
	public static function save( array $values ): bool|\WP_Error {
		$checked = self::validate( $values );
		if ( ! HallContext::is_hall() || $checked['errors'] ) { return new \WP_Error( 'bc_homepage_trust_value', __( 'Invalid trust row. Nothing was saved.', 'bingocommerce-core' ) ); }
		$values = $checked['values'];
		if ( false !== get_option( self::OPTION, false ) && self::stored() === $values ) { return true; }
		update_option( self::OPTION, $values, false );
		return self::stored() === $values ? true : self::storage_error();
	}

	private static function storage_error(): \WP_Error {
		return new \WP_Error( 'bc_homepage_trust_storage', __( 'The saved trust row could not be read or its save confirmed. Reload and check the saved values.', 'bingocommerce-core' ) );
	}
}
