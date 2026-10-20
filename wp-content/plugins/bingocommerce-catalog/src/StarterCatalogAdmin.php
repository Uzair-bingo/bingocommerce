<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 Network Admin → Starter Catalog page (Super Admins only).
 *
 * Opening the page creates or upgrades the network starter tables (ADR-049
 * section 14); a current schema that fails verification is reported for
 * repair, never recreated. The page shows the included product count, a link
 * to the main site's Products list, the latest published version and the
 * "Allow new Hall imports" switch. The switch blocks new imports only;
 * imports already started may continue (section 15).
 *
 * Same-page POST, as in the other Catalog administration screens. Publish,
 * Resume and Discard are added in a later slice.
 */
final class StarterCatalogAdmin {

	public const PAGE = 'bingocommerce-starter-catalog';

	private const ACTION_FIELD = 'bc_starter_catalog_action';
	private const IMPORTS_ON   = 'imports_on';
	private const IMPORTS_OFF  = 'imports_off';

	public static function register(): void {
		add_action( 'network_admin_menu', [ self::class, 'network_menu' ] );
	}

	public static function network_menu(): void {
		if ( ! self::allowed() ) {
			return;
		}
		add_menu_page(
			__( 'Starter Catalog', 'bingocommerce-catalog' ),
			__( 'Starter Catalog', 'bingocommerce-catalog' ),
			'manage_network_options',
			self::PAGE,
			[ self::class, 'render' ],
			'dashicons-products'
		);
	}

	public static function allowed(): bool {
		return is_multisite() && is_admin() && is_user_logged_in()
			&& is_network_admin() && is_super_admin() && current_user_can( 'manage_network_options' );
	}

	/**
	 * Turn new Hall imports on or off.
	 *
	 * @param array<string,mixed> $posted Unslashed POST fields.
	 * @return true|\WP_Error
	 */
	public static function handle_request( array $posted ) {
		if ( ! self::allowed() ) {
			return self::error( 'denied' );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return self::error( 'request' );
		}
		if ( ! is_string( $posted['_wpnonce'] ?? null ) || ! wp_verify_nonce( $posted['_wpnonce'], self::nonce_action() ) ) {
			return self::error( 'nonce' );
		}
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ '_wpnonce', '_wp_http_referer', self::ACTION_FIELD, 'submit' ], true ) ) {
				return self::error( 'request' );
			}
		}

		$action = $posted[ self::ACTION_FIELD ] ?? null;
		if ( ! in_array( $action, [ self::IMPORTS_ON, self::IMPORTS_OFF ], true ) ) {
			return self::error( 'request' );
		}

		$wanted = self::IMPORTS_ON === $action;
		update_site_option( StarterCatalogVersions::ALLOW_IMPORTS_OPTION, $wanted ? '1' : '0' );

		return StarterCatalogVersions::imports_allowed() === $wanted ? true : self::error( 'storage' );
	}

	/**
	 * Everything the page shows. Prepares the network tables first.
	 *
	 * @return array{schema:true|\WP_Error,included:array<int,int>|\WP_Error,latest:array<string,mixed>|null|\WP_Error,imports_allowed:bool,products_url:string}
	 */
	public static function page_state(): array {
		$schema = StarterImportMigrations::ensure_network();

		return [
			'schema'          => $schema,
			'included'        => StarterCatalogMaster::selected_ids(),
			'latest'          => is_wp_error( $schema ) ? $schema : StarterCatalogVersions::latest_published(),
			'imports_allowed' => StarterCatalogVersions::imports_allowed(),
			'products_url'    => get_admin_url( get_main_site_id(), 'edit.php?post_type=product' ),
		];
	}

	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not authorized to manage the starter catalog.', 'bingocommerce-catalog' ), '', [ 'response' => 403 ] );
		}

		$result = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? self::handle_request( wp_unslash( $_POST ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_request().
		$state  = self::page_state();
		$on     = $state['imports_allowed'];
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Starter Catalog', 'bingocommerce-catalog' ); ?></h1>
			<p><?php echo esc_html__( 'Products on the main site marked "Include in starter catalog" are published as numbered versions. A Hall Manager can import the latest published version once, as draft products.', 'bingocommerce-catalog' ); ?></p>

			<?php if ( null !== $result ) : ?>
				<div class="notice <?php echo is_wp_error( $result ) ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( is_wp_error( $result ) ? $result->get_error_message() : ( $on ? __( 'New Hall imports are turned on.', 'bingocommerce-catalog' ) : __( 'New Hall imports are turned off.', 'bingocommerce-catalog' ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_wp_error( $state['schema'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $state['schema']->get_error_message() ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped"><tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Included products', 'bingocommerce-catalog' ); ?></th>
					<td>
						<?php echo esc_html( is_wp_error( $state['included'] ) ? $state['included']->get_error_message() : (string) count( $state['included'] ) ); ?>
						&nbsp;<a href="<?php echo esc_url( $state['products_url'] ); ?>"><?php echo esc_html__( 'View main site products', 'bingocommerce-catalog' ); ?></a>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Latest published version', 'bingocommerce-catalog' ); ?></th>
					<td><?php echo esc_html( self::describe_version( $state['latest'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'New Hall imports', 'bingocommerce-catalog' ); ?></th>
					<td><?php echo esc_html( $on ? __( 'On', 'bingocommerce-catalog' ) : __( 'Off', 'bingocommerce-catalog' ) ); ?></td>
				</tr>
			</tbody></table>

			<form method="post">
				<?php wp_nonce_field( self::nonce_action() ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::ACTION_FIELD ); ?>" value="<?php echo esc_attr( $on ? self::IMPORTS_OFF : self::IMPORTS_ON ); ?>">
				<?php submit_button( $on ? __( 'Turn off new Hall imports', 'bingocommerce-catalog' ) : __( 'Turn on new Hall imports', 'bingocommerce-catalog' ), $on ? 'secondary' : 'primary' ); ?>
				<p class="description"><?php echo esc_html__( 'Turning this off stops new imports only. Imports already started can continue, and no product is changed or deleted.', 'bingocommerce-catalog' ); ?></p>
			</form>
		</div>
		<?php
	}

	/** @param array<string,mixed>|null|\WP_Error $version */
	private static function describe_version( $version ): string {
		if ( is_wp_error( $version ) ) {
			return $version->get_error_message();
		}
		if ( null === $version ) {
			return __( 'None published yet.', 'bingocommerce-catalog' );
		}

		$user      = get_userdata( (int) ( $version['published_by'] ?? 0 ) );
		$published = '' !== (string) ( $version['published_at'] ?? '' ) ? get_date_from_gmt( (string) $version['published_at'], 'm/d/Y g:i a' ) : '';

		$count = (int) $version['product_count'];

		return sprintf(
			/* translators: 1: version number, 2: product count, 3: date and time (MM/DD/YYYY), 4: user name. */
			_n( 'Version %1$d: %2$d product, published %3$s by %4$s.', 'Version %1$d: %2$d products, published %3$s by %4$s.', $count, 'bingocommerce-catalog' ),
			(int) $version['version_id'],
			$count,
			$published,
			$user instanceof \WP_User ? $user->display_name : __( 'an unknown user', 'bingocommerce-catalog' )
		);
	}

	private static function nonce_action(): string {
		return 'bingocommerce_starter_catalog:network:' . get_current_network_id();
	}

	private static function error( string $code ): \WP_Error {
		$message = match ( $code ) {
			'denied'  => __( 'You are not authorized to manage the starter catalog.', 'bingocommerce-catalog' ),
			'nonce'   => __( 'Verification failed. Reload this page and try again.', 'bingocommerce-catalog' ),
			'storage' => __( 'The setting could not be saved. Reload and check it.', 'bingocommerce-catalog' ),
			default   => __( 'Invalid request. Nothing was changed.', 'bingocommerce-catalog' ),
		};

		return new \WP_Error( 'starter_catalog_' . $code, $message );
	}
}
