<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hall public storefront profile ("Store Details", ADR-045 / BR-084).
 *
 * Display configuration only: the public address, phone, email and social links
 * a Hall shows customers. It is NOT WooCommerce's store address (tax/shipping
 * origin), NOT the site title, and NOT Portal-owned Hall identity; nothing here
 * reads or writes any of those. One per-site option, so the resolved site's own
 * options table is the tenant boundary.
 *
 * The storefront reads it through the read-only `bingocommerce_store_details`
 * filter and does all rendering and escaping itself.
 */
final class StoreDetails {

	public const OPTION = 'bc_store_details';
	public const FILTER = 'bingocommerce_store_details';

	/** Field key => validation kind. Order is the form and storage order. */
	public const FIELDS = [
		'address_line_1' => 'text',
		'address_line_2' => 'text',
		'city'           => 'text',
		'state'          => 'text',
		'postal_code'    => 'text',
		'country'        => 'text',
		'phone'          => 'phone',
		'email'          => 'email',
		'facebook_url'   => 'url',
		'instagram_url'  => 'url',
		'youtube_url'    => 'url',
		'tiktok_url'     => 'url',
	];

	public const SOCIAL_FIELDS = [ 'facebook_url', 'instagram_url', 'youtube_url', 'tiktok_url' ];

	private const MAX_LENGTH = [
		'text'  => 100,
		'phone' => 32,
		'email' => 100,
		'url'   => 2048,
	];

	public static function register(): void {
		add_filter( self::FILTER, [ self::class, 'provide' ] );
	}

	/**
	 * Filter callback for the storefront. Returns every field as a plain,
	 * unescaped string plus the derived `phone_href`, or an empty array outside
	 * a Hall. Unreadable storage renders as blank rather than failing the page.
	 *
	 * @param mixed $details Value from earlier callbacks; ignored.
	 * @return array<string,string>
	 */
	public static function provide( $details = [] ): array {
		unset( $details );
		if ( ! HallContext::is_hall() ) {
			return [];
		}
		$stored = self::stored();
		$values = is_wp_error( $stored ) ? self::blank() : $stored;
		$values['phone_href'] = self::tel_uri( $values['phone'] );
		return $values;
	}

	/** @return array<string,string> Every field, blank. */
	public static function blank(): array {
		return array_fill_keys( array_keys( self::FIELDS ), '' );
	}

	/**
	 * Current saved values for this Hall. Absence is a valid blank profile;
	 * a stored value of the wrong shape is reported, never silently repaired.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function stored(): array|\WP_Error {
		$missing = new \stdClass();
		$value   = get_option( self::OPTION, $missing );
		if ( $value === $missing ) {
			return self::blank();
		}
		if ( ! is_array( $value ) || array_keys( $value ) !== array_keys( self::FIELDS ) ) {
			return self::storage_error();
		}
		foreach ( $value as $field_value ) {
			if ( ! is_string( $field_value ) ) {
				return self::storage_error();
			}
		}
		return $value;
	}

	/**
	 * Validate a complete submission. Surrounding whitespace is trimmed; nothing
	 * else is rewritten. Blank is valid for every field.
	 *
	 * @param array<string,mixed> $input Exactly the keys of FIELDS.
	 * @return array{values:array<string,string>,errors:array<string,string>}
	 */
	public static function validate( array $input ): array {
		$values = self::blank();
		$errors = [];
		foreach ( self::FIELDS as $key => $kind ) {
			$raw = $input[ $key ] ?? null;
			if ( ! is_string( $raw ) ) {
				$errors[ $key ] = __( 'This value could not be read. Enter it again.', 'bingocommerce-core' );
				continue;
			}
			$value          = trim( $raw );
			$values[ $key ] = $value;
			if ( '' === $value ) {
				continue;
			}
			$error = self::field_error( $kind, $value );
			if ( null !== $error ) {
				$errors[ $key ] = $error;
			}
		}
		return [ 'values' => $values, 'errors' => $errors ];
	}

	/**
	 * Replace the saved profile with already-validated values.
	 *
	 * @param array<string,string> $values Output of validate() with no errors.
	 * @return bool|\WP_Error True when saved or already identical.
	 */
	public static function save( array $values ): bool|\WP_Error {
		if ( array_keys( $values ) !== array_keys( self::FIELDS ) ) {
			return self::storage_error();
		}
		$before = self::stored();
		if ( ! is_wp_error( $before ) && $before === $values ) {
			return true;
		}
		// Autoloaded: the storefront header and footer read it on every page.
		update_option( self::OPTION, $values, true );
		return self::stored() === $values ? true : self::storage_error();
	}

	/**
	 * `tel:` URI for a validated phone number: its digits, keeping one leading
	 * `+` when the number was entered with one. Blank for a blank or unusable value.
	 */
	public static function tel_uri( string $phone ): string {
		$phone = trim( $phone );
		if ( '' === $phone || null !== self::field_error( 'phone', $phone ) ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', $phone );
		return 'tel:' . ( str_starts_with( $phone, '+' ) ? '+' : '' ) . $digits;
	}

	private static function field_error( string $kind, string $value ): ?string {
		// Invalid UTF-8 fails every /u pattern below; reject it outright.
		if ( 1 !== preg_match( '//u', $value ) ) {
			return __( 'This value contains unreadable characters.', 'bingocommerce-core' );
		}
		$max = self::MAX_LENGTH[ $kind ];
		if ( mb_strlen( $value ) > $max ) {
			/* translators: %d: maximum number of characters. */
			return sprintf( __( 'Use %d characters or fewer.', 'bingocommerce-core' ), $max );
		}
		switch ( $kind ) {
			case 'text':
				if ( 1 === preg_match( '/[\x00-\x1F\x7F<>]/u', $value ) ) {
					return __( 'Use plain text on one line, without < or > characters.', 'bingocommerce-core' );
				}
				return null;
			case 'phone':
				$digits = strlen( (string) preg_replace( '/\D+/', '', $value ) );
				if ( 1 !== preg_match( '/\A\+?[0-9 ().\-]+\z/', $value ) || $digits < 7 || $digits > 15 ) {
					return __( 'Enter a phone number of 7 to 15 digits. You may start with + and use spaces, brackets, dots or hyphens.', 'bingocommerce-core' );
				}
				return null;
			case 'email':
				if ( false === is_email( $value ) || sanitize_email( $value ) !== $value ) {
					return __( 'Enter a valid email address, such as info@example.com.', 'bingocommerce-core' );
				}
				return null;
			case 'url':
				return self::url_error( $value );
		}
		return __( 'This value is not supported.', 'bingocommerce-core' );
	}

	private static function url_error( string $value ): ?string {
		$message = __( 'Enter a full web address starting with https:// or http://, such as https://www.facebook.com/yourhall.', 'bingocommerce-core' );
		if ( 1 === preg_match( '/[\x00-\x20\x7F<>"\'\\\\]/u', $value ) ) {
			return $message;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
			|| '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $message;
		}
		if ( esc_url_raw( $value, [ 'http', 'https' ] ) !== $value ) {
			return $message;
		}
		return null;
	}

	private static function storage_error(): \WP_Error {
		return new \WP_Error(
			'bc_store_details_storage',
			__( 'The saved Store Details could not be read or their save confirmed. Reload this page and check the saved values.', 'bingocommerce-core' )
		);
	}
}
