<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** BR-064 administration only; the purchase resolver remains read-only. */
final class ElectronicLimitSettings {

	public const PAGE = 'bingocommerce-electronic-limits';

	public static function register(): void {
		add_action( 'network_admin_menu', [ self::class, 'network_menu' ] );
		add_action( 'admin_menu', [ self::class, 'hall_menu' ] );
	}

	public static function network_menu(): void {
		if ( ! self::allowed( true ) ) { return; }
		add_submenu_page( 'settings.php', __( 'Electronic Limits', 'bingocommerce-catalog' ), __( 'Electronic Limits', 'bingocommerce-catalog' ), 'manage_network_options', self::PAGE, [ self::class, 'render_network' ] );
	}

	public static function hall_menu(): void {
		if ( ! self::allowed( false ) ) { return; }
		add_submenu_page( 'woocommerce', __( 'Electronic Limits', 'bingocommerce-catalog' ), __( 'Electronic Limits', 'bingocommerce-catalog' ), 'bc_manage_hall_settings', self::PAGE, [ self::class, 'render_hall' ] );
	}

	public static function render_network(): void { self::render( true ); }
	public static function render_hall(): void { self::render( false ); }

	private static function allowed( bool $network ): bool {
		if ( ! is_multisite() || ! is_admin() || ! is_user_logged_in() ) { return false; }
		if ( $network ) { return is_network_admin() && is_super_admin() && current_user_can( 'manage_network_options' ); }
		return ! is_network_admin() && HallContext::is_hall()
			&& ( is_super_admin() || current_user_can( 'bc_manage_hall_settings' ) );
	}

	private static function nonce_action( bool $network ): string {
		return 'bingocommerce_electronic_limit:' . ( $network ? 'network:' . get_current_network_id() : 'hall:' . get_current_blog_id() );
	}

	/** Same-page POST boundary, as in Catalog administration. Scope is never submitted. */
	public static function handle_request( array $posted, bool $network ): true|\WP_Error {
		if ( ! self::allowed( $network ) ) { return self::error( 'denied' ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return self::error( 'request' ); }
		if ( ! is_string( $posted['_wpnonce'] ?? null ) || ! wp_verify_nonce( $posted['_wpnonce'], self::nonce_action( $network ) ) ) { return self::error( 'nonce' ); }
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ '_wpnonce', '_wp_http_referer', 'bc_limit_action', 'limit', 'submit' ], true ) ) { return self::error( 'request' ); }
		}
		$action = $posted['bc_limit_action'] ?? null;
		if ( 'save' !== $action && ( $network || 'remove' !== $action ) ) { return self::error( 'request' ); }
		$value = 'remove' === $action ? null : ( $posted['limit'] ?? null );
		if ( 'save' === $action && ! self::positive( $value ) ) { return self::error( 'value' ); }
		$value = null === $value ? null : (string) $value;
		$before = self::stored( $network );
		if ( is_wp_error( $before ) ) { return $before; }
		if ( $before === $value ) { return true; }
		// Native option APIs own persistence/cache invalidation. Never write an
		// effective value, clamp a saved override, initialize a default or switch Hall.
		if ( $network ) { update_network_option( get_current_network_id(), ElectronicLimit::PLATFORM_OPTION, $value ); }
		elseif ( null === $value ) { delete_option( ElectronicLimit::HALL_OPTION ); }
		else { update_option( ElectronicLimit::HALL_OPTION, $value, false ); }
		return self::stored( $network ) === $value ? true : self::error( 'storage' );
	}

	/** Fresh stored value, null for absence, error for unreadable/duplicate storage. */
	private static function stored( bool $network ): string|null|\WP_Error {
		global $wpdb;
		$site = get_site( get_current_blog_id() );
		if ( ! $site instanceof \WP_Site ) { return self::error( 'storage' ); }
		$network_id = is_network_admin() ? get_current_network_id() : (int) $site->network_id;
		$rows = $network
			? $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE site_id = %d AND meta_key = %s LIMIT 2', $wpdb->sitemeta, $network_id, ElectronicLimit::PLATFORM_OPTION ) )
			: $wpdb->get_col( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 2', $wpdb->options, ElectronicLimit::HALL_OPTION ) );
		if ( '' !== $wpdb->last_error || count( $rows ) > 1 ) { return self::error( 'storage' ); }
		return $rows[0] ?? null;
	}

	private static function positive( mixed $value ): bool {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return false; }
		$string = (string) $value;
		return 1 === preg_match( '/\A[1-9][0-9]*\z/', $string ) && strlen( $string ) <= strlen( (string) PHP_INT_MAX )
			&& (string) (int) $string === $string;
	}

	private static function render( bool $network ): void {
		if ( ! self::allowed( $network ) ) { wp_die( esc_html__( 'You are not authorized to manage these electronic limits.', 'bingocommerce-catalog' ), '', [ 'response' => 403 ] ); }
		$result = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? self::handle_request( wp_unslash( $_POST ), $network ) : null;
		$platform = self::stored( true );
		$saved = $network ? $platform : self::stored( false );
		$effective = $network ? ( self::positive( $platform ) ? (int) $platform : self::error( 'storage' ) ) : ElectronicLimit::effective_limit();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Electronic Limits', 'bingocommerce-catalog' ); ?></h1>
			<p><?php echo esc_html__( 'Maximum digital faces per Game Type. Paper and ordinary merchandise are unaffected. This is not a Session, order or customer capacity.', 'bingocommerce-catalog' ); ?></p>
			<?php if ( null !== $result ) : ?>
				<div class="notice <?php echo is_wp_error( $result ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( is_wp_error( $result ) ? $result->get_error_message() : __( 'Electronic-limit setting saved.', 'bingocommerce-catalog' ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $network && self::positive( $saved ) && self::positive( $platform ) && (int) $saved > (int) $platform && ! is_wp_error( $effective ) ) : ?>
				<div class="notice notice-info"><p><?php
					/* translators: 1: Saved Hall override, 2: Current platform maximum, 3: Effective Hall limit. */
					echo esc_html( sprintf( __( 'Your Hall override is saved as %1$s, but the platform maximum is %2$s. This Hall is currently limited to %3$d digital faces per Game Type in a customer selection.', 'bingocommerce-catalog' ), $saved, $platform, $effective ) );
				?></p></div>
			<?php endif; ?>
			<table class="widefat striped"><tbody>
				<tr><th><?php echo esc_html__( 'Saved platform maximum', 'bingocommerce-catalog' ); ?></th><td><?php echo esc_html( self::display( $platform ) ); ?></td></tr>
				<?php if ( ! $network ) : ?>
					<tr><th><?php echo esc_html__( 'Saved Hall override', 'bingocommerce-catalog' ); ?></th><td><?php echo esc_html( null === $saved ? __( 'Absent — use platform maximum', 'bingocommerce-catalog' ) : self::display( $saved ) ); ?></td></tr>
				<?php endif; ?>
				<tr><th><?php echo esc_html( $network ? __( 'Current platform ceiling', 'bingocommerce-catalog' ) : __( 'Effective Hall limit', 'bingocommerce-catalog' ) ); ?></th><td><?php echo esc_html( is_wp_error( $effective ) ? __( 'Unavailable — digital purchases require valid configuration', 'bingocommerce-catalog' ) : (string) $effective ); ?></td></tr>
			</tbody></table>
			<p><?php echo esc_html__( 'A Hall with an override uses the smaller of its saved override and the current platform maximum. Lowering the platform maximum does not rewrite Hall overrides. No default is created.', 'bingocommerce-catalog' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::nonce_action( $network ) ); ?>
				<input type="hidden" name="bc_limit_action" value="save">
				<label for="bc-electronic-limit"><?php echo esc_html( $network ? __( 'Platform maximum', 'bingocommerce-catalog' ) : __( 'Hall override', 'bingocommerce-catalog' ) ); ?></label>
				<input id="bc-electronic-limit" type="text" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="<?php echo esc_attr( (string) strlen( (string) PHP_INT_MAX ) ); ?>" name="limit" value="<?php echo esc_attr( self::positive( $saved ) ? (string) $saved : '' ); ?>" required>
				<p class="description"><?php echo esc_html__( 'Enter a positive whole number (at least 1). Blank, zero, negative, decimal and malformed values are not saved.', 'bingocommerce-catalog' ); ?></p>
				<?php submit_button( __( 'Save electronic limit', 'bingocommerce-catalog' ) ); ?>
			</form>
			<?php if ( ! $network ) : ?>
				<form method="post">
					<?php wp_nonce_field( self::nonce_action( false ) ); ?>
					<input type="hidden" name="bc_limit_action" value="remove">
					<?php submit_button( __( 'Remove Hall override — use platform maximum', 'bingocommerce-catalog' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function display( string|null|\WP_Error $value ): string {
		if ( is_wp_error( $value ) ) { return __( 'Unreadable', 'bingocommerce-catalog' ); }
		if ( null === $value ) { return __( 'Not configured', 'bingocommerce-catalog' ); }
		return self::positive( $value ) ? $value : sprintf( __( 'Invalid saved value: %s', 'bingocommerce-catalog' ), $value );
	}

	private static function error( string $code ): \WP_Error {
		$message = match ( $code ) {
			'denied' => __( 'You are not authorized to manage these electronic limits.', 'bingocommerce-catalog' ),
			'nonce' => __( 'Verification failed. Reload this settings page and try again.', 'bingocommerce-catalog' ),
			'value' => __( 'Enter a positive whole number within the supported integer range. The saved setting was not changed.', 'bingocommerce-catalog' ),
			'storage' => __( 'The electronic-limit setting could not be read or its save confirmed. Reload and check the saved value.', 'bingocommerce-catalog' ),
			default => __( 'Invalid electronic-limit request. The saved setting was not changed.', 'bingocommerce-catalog' ),
		};
		return new \WP_Error( 'bc_electronic_settings_' . $code, $message );
	}
}
