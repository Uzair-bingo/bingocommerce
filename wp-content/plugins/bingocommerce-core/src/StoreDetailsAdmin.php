<?php

declare(strict_types=1);

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appearance → Store Details: the current Hall's public storefront profile.
 *
 * Same boundary shape as the Hall Electronic Limits screen: existing
 * `bc_manage_hall_settings` capability, a nonce scoped to this Hall, an
 * allow-listed same-page POST, all-or-nothing server-side validation, and the
 * native option API for persistence. The Hall is never submitted.
 */
final class StoreDetailsAdmin {

	public const PAGE = 'bingocommerce-store-details';

	private const FORM_KEY = 'bc_store_details';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
	}

	public static function menu(): void {
		if ( ! self::allowed() ) {
			return;
		}
		add_theme_page(
			__( 'Store Details', 'bingocommerce-core' ),
			__( 'Store Details', 'bingocommerce-core' ),
			'bc_manage_hall_settings',
			self::PAGE,
			[ self::class, 'render' ]
		);
	}

	private static function allowed(): bool {
		return is_multisite() && is_admin() && is_user_logged_in() && ! is_network_admin() && HallContext::is_hall()
			&& ( is_super_admin() || current_user_can( 'bc_manage_hall_settings' ) );
	}

	private static function nonce_action(): string {
		return 'bingocommerce_store_details:hall:' . get_current_blog_id();
	}

	/**
	 * Same-page POST boundary.
	 *
	 * @param array<string,mixed> $posted Unslashed request body.
	 * @return array{result:true|\WP_Error,values:array<string,string>,errors:array<string,string>}
	 */
	public static function handle_request( array $posted ): array {
		$failed = static fn( \WP_Error $error, array $values = [], array $errors = [] ): array => [ 'result' => $error, 'values' => $values, 'errors' => $errors ];
		if ( ! self::allowed() ) { return $failed( self::error( 'denied' ) ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return $failed( self::error( 'request' ) ); }
		if ( ! is_string( $posted['_wpnonce'] ?? null ) || ! wp_verify_nonce( $posted['_wpnonce'], self::nonce_action() ) ) { return $failed( self::error( 'nonce' ) ); }
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ '_wpnonce', '_wp_http_referer', self::FORM_KEY, 'submit' ], true ) ) { return $failed( self::error( 'request' ) ); }
		}
		$fields = $posted[ self::FORM_KEY ] ?? null;
		if ( ! is_array( $fields ) ) { return $failed( self::error( 'request' ) ); }
		$expected = array_keys( StoreDetails::FIELDS );
		$received = array_keys( $fields );
		sort( $expected );
		sort( $received );
		if ( $expected !== $received ) { return $failed( self::error( 'request' ) ); }

		$checked = StoreDetails::validate( $fields );
		if ( [] !== $checked['errors'] ) {
			return $failed( self::error( 'value' ), $checked['values'], $checked['errors'] );
		}
		$saved = StoreDetails::save( $checked['values'] );
		return [ 'result' => $saved, 'values' => $checked['values'], 'errors' => [] ];
	}

	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not authorized to manage Store Details for this Hall.', 'bingocommerce-core' ), '', [ 'response' => 403 ] );
		}
		$outcome = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? self::handle_request( wp_unslash( $_POST ) ) : null;
		$stored  = StoreDetails::stored();
		$errors  = $outcome['errors'] ?? [];
		// A rejected submission is shown back as entered; otherwise show what is saved.
		$values = ( null !== $outcome && is_wp_error( $outcome['result'] ) && [] !== $outcome['values'] )
			? $outcome['values']
			: ( is_wp_error( $stored ) ? StoreDetails::blank() : $stored );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Store Details', 'bingocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'The public address, phone, email and social links shown on your storefront. They are separate from the WooCommerce store address used for taxes and shipping, and they do not change your Hall name or Portal records.', 'bingocommerce-core' ); ?></p>
			<?php if ( null !== $outcome ) : ?>
				<?php if ( is_wp_error( $outcome['result'] ) ) : ?>
					<div class="notice notice-error" role="alert"><p><?php echo esc_html( $outcome['result']->get_error_message() ); ?></p>
						<?php if ( [] !== $errors ) : ?>
							<ul>
								<?php foreach ( $errors as $key => $message ) : ?>
									<li><a href="<?php echo esc_attr( '#bc-store-' . $key ); ?>"><?php echo esc_html( self::label( $key ) . ': ' . $message ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="notice notice-success" role="status"><p><?php echo esc_html__( 'Store Details saved.', 'bingocommerce-core' ); ?></p></div>
				<?php endif; ?>
			<?php elseif ( is_wp_error( $stored ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $stored->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<form method="post" novalidate>
				<?php wp_nonce_field( self::nonce_action() ); ?>
				<?php
				self::fieldset( __( 'Public address', 'bingocommerce-core' ), [ 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country' ], $values, $errors );
				self::fieldset( __( 'Contact', 'bingocommerce-core' ), [ 'phone', 'email' ], $values, $errors );
				self::fieldset( __( 'Social links', 'bingocommerce-core' ), StoreDetails::SOCIAL_FIELDS, $values, $errors, __( 'Enter the full address of each profile. Leave a link blank to hide that icon on the storefront.', 'bingocommerce-core' ) );
				submit_button( __( 'Save Store Details', 'bingocommerce-core' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<int,string>    $keys
	 * @param array<string,string> $values
	 * @param array<string,string> $errors
	 */
	private static function fieldset( string $legend, array $keys, array $values, array $errors, string $description = '' ): void {
		?>
		<h2><?php echo esc_html( $legend ); ?></h2>
		<?php if ( '' !== $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<table class="form-table" role="presentation"><tbody>
			<?php foreach ( $keys as $key ) : ?>
				<?php
				$id    = 'bc-store-' . $key;
				$error = $errors[ $key ] ?? '';
				$type  = match ( StoreDetails::FIELDS[ $key ] ) { 'phone' => 'tel', 'email' => 'email', 'url' => 'url', default => 'text' };
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( self::label( $key ) ); ?></label></th>
					<td>
						<input class="regular-text" type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>"
							name="<?php echo esc_attr( self::FORM_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $values[ $key ] ?? '' ); ?>"
							<?php if ( 'phone' === StoreDetails::FIELDS[ $key ] ) : ?>autocomplete="tel"<?php elseif ( 'email' === StoreDetails::FIELDS[ $key ] ) : ?>autocomplete="email"<?php endif; ?>
							<?php if ( '' !== $error ) : ?>aria-invalid="true" aria-describedby="<?php echo esc_attr( $id . '-error' ); ?>"<?php endif; ?>>
						<?php if ( '' !== $error ) : ?>
							<p class="description" id="<?php echo esc_attr( $id . '-error' ); ?>" style="color:#b32d2e;"><?php echo esc_html( $error ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	private static function label( string $key ): string {
		return match ( $key ) {
			'address_line_1' => __( 'Address line 1', 'bingocommerce-core' ),
			'address_line_2' => __( 'Address line 2', 'bingocommerce-core' ),
			'city'           => __( 'City', 'bingocommerce-core' ),
			'state'          => __( 'State / province', 'bingocommerce-core' ),
			'postal_code'    => __( 'Postal code', 'bingocommerce-core' ),
			'country'        => __( 'Country', 'bingocommerce-core' ),
			'phone'          => __( 'Phone', 'bingocommerce-core' ),
			'email'          => __( 'Email', 'bingocommerce-core' ),
			'facebook_url'   => __( 'Facebook', 'bingocommerce-core' ),
			'instagram_url'  => __( 'Instagram', 'bingocommerce-core' ),
			'youtube_url'    => __( 'YouTube', 'bingocommerce-core' ),
			'tiktok_url'     => __( 'TikTok', 'bingocommerce-core' ),
			default          => $key,
		};
	}

	private static function error( string $code ): \WP_Error {
		$message = match ( $code ) {
			'denied' => __( 'You are not authorized to manage Store Details for this Hall.', 'bingocommerce-core' ),
			'nonce'  => __( 'Verification failed. Reload this page and try again.', 'bingocommerce-core' ),
			'value'  => __( 'Some Store Details need correcting. Nothing was saved.', 'bingocommerce-core' ),
			default  => __( 'Invalid Store Details request. Nothing was saved.', 'bingocommerce-core' ),
		};
		return new \WP_Error( 'bc_store_details_' . $code, $message );
	}
}
