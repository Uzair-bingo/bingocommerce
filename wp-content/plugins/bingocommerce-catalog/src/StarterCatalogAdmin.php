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
 * The imports switch is a same-page POST, as in the other Catalog
 * administration screens. Publish New Version, Resume, Discard and Finish
 * Discard run through one admin-ajax action, one small request at a time
 * (assets/starter-catalog-admin.js). Every request re-checks the method,
 * Super Admin rights, the main site, the nonce and every field; hiding a
 * button never enforces anything. Without JavaScript the buttons stay
 * disabled.
 */
final class StarterCatalogAdmin {

	public const PAGE        = 'bingocommerce-starter-catalog';
	public const AJAX_ACTION = 'bc_starter_catalog_publish';
	public const SCRIPT      = 'bingocommerce-starter-catalog-admin';
	public const HIDDEN_CSS  = '#bc-starter-publish [hidden] { display: none !important; }';

	private const ACTION_FIELD = 'bc_starter_catalog_action';
	private const IMPORTS_ON   = 'imports_on';
	private const IMPORTS_OFF  = 'imports_off';

	public static function register(): void {
		add_action( 'network_admin_menu', [ self::class, 'network_menu' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax' ] );
	}

	public static function network_menu(): void {
		if ( ! self::allowed() ) {
			return;
		}
		$hook = add_menu_page(
			__( 'Starter Catalog', 'bingocommerce-catalog' ),
			__( 'Starter Catalog', 'bingocommerce-catalog' ),
			'manage_network_options',
			self::PAGE,
			[ self::class, 'render' ],
			'dashicons-products'
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'load_page' ] );
		}
	}

	/** Only this page loads the publishing script and its settings. */
	public static function load_page(): void {
		if ( ! self::allowed() ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT, plugins_url( '../assets/starter-catalog-admin.js', __FILE__ ), [], BINGOCOMMERCE_CATALOG_VERSION, true );
		// WordPress's button rule (.wp-core-ui .button { display: inline-block },
		// wp-includes/css/buttons.css:43) would show buttons this page marks
		// hidden; this rule applies to this page's Publish section only.
		wp_register_style( self::SCRIPT, false, [], BINGOCOMMERCE_CATALOG_VERSION );
		wp_enqueue_style( self::SCRIPT );
		wp_add_inline_style( self::SCRIPT, self::HIDDEN_CSS );
		$config = [
			'ajaxUrl' => get_admin_url( get_main_site_id(), 'admin-ajax.php' ),
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::publish_nonce_action() ),
			'strings' => [
				/* translators: 1: products captured so far, 2: products in the version. */
				'progress'     => __( 'Capturing products: %1$s of %2$s done.', 'bingocommerce-catalog' ),
				/* translators: 1: version number, 2: product count (one). */
				'publishedOne' => _n( 'Version %1$s is published with %2$s product.', 'Version %1$s is published with %2$s products.', 1, 'bingocommerce-catalog' ),
				/* translators: 1: version number, 2: product count. */
				'published'    => _n( 'Version %1$s is published with %2$s product.', 'Version %1$s is published with %2$s products.', 2, 'bingocommerce-catalog' ),
				'blocked'      => __( 'Publishing stopped. Fix these on the main site, then try again:', 'bingocommerce-catalog' ),
				'changed'      => __( 'Some products changed during publication. Select Resume to capture them again:', 'bingocommerce-catalog' ),
				'discarded'    => __( 'The version was discarded.', 'bingocommerce-catalog' ),
				'idle'         => __( 'Nothing is being published.', 'bingocommerce-catalog' ),
				'failed'       => __( 'The request did not complete. Reload the page and try again.', 'bingocommerce-catalog' ),
				'confirm'      => __( 'Discard this version? Its captured products and image copies are removed. Published versions are not affected.', 'bingocommerce-catalog' ),
			],
		];
		wp_add_inline_script( self::SCRIPT, 'window.bcStarterCatalog = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/** admin-ajax: one Publish, Resume or Discard step. */
	public static function ajax(): void {
		$result = self::handle_ajax( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_ajax().
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error(
				[
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
				is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 409
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * One Publish step, after every check.
	 *
	 * @param array<string,mixed> $posted Unslashed POST fields.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function handle_ajax( array $posted ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return self::error( 'method' );
		}
		if ( ! is_multisite() || ! is_user_logged_in() || ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) {
			return self::error( 'denied' );
		}
		if ( (int) get_current_blog_id() !== (int) get_main_site_id() ) {
			return self::error( 'site' );
		}
		if ( ! is_string( $posted['_ajax_nonce'] ?? null ) || ! wp_verify_nonce( $posted['_ajax_nonce'], self::publish_nonce_action() ) ) {
			return self::error( 'nonce' );
		}
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, [ 'action', '_ajax_nonce', 'step', 'version' ], true ) ) {
				return self::error( 'request' );
			}
		}

		$step    = $posted['step'] ?? null;
		$version = $posted['version'] ?? null;
		if ( ! in_array( $step, [ 'start', 'continue', 'discard' ], true ) ) {
			return self::error( 'request' );
		}
		if ( 'discard' === $step ) {
			if ( ! is_string( $version ) || 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/', $version ) ) {
				return self::error( 'request' );
			}
		} elseif ( null !== $version ) {
			return self::error( 'request' );
		}

		$publisher = new StarterCatalogPublisher();
		$outcome   = match ( $step ) {
			'start'    => $publisher->start(),
			'continue' => $publisher->continue_build(),
			'discard'  => $publisher->discard( (int) $version ),
		};
		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		return [
			'status'     => (string) $outcome['status'],
			'version_id' => null !== $outcome['version_id'] ? (int) $outcome['version_id'] : null,
			'total'      => (int) $outcome['total'],
			'captured'   => (int) $outcome['captured'],
			'problems'   => array_values( array_map( 'strval', (array) $outcome['problems'] ) ),
			'warnings'   => array_values( array_map( 'strval', (array) $outcome['warnings'] ) ),
		];
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
			'publish'         => is_wp_error( $schema ) ? $schema : StarterCatalogPublisher::state(),
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

			<h2><?php echo esc_html__( 'Publish', 'bingocommerce-catalog' ); ?></h2>
			<?php self::render_publish( $state['publish'] ); ?>

			<h2><?php echo esc_html__( 'New Hall imports', 'bingocommerce-catalog' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::nonce_action() ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::ACTION_FIELD ); ?>" value="<?php echo esc_attr( $on ? self::IMPORTS_OFF : self::IMPORTS_ON ); ?>">
				<?php submit_button( $on ? __( 'Turn off new Hall imports', 'bingocommerce-catalog' ) : __( 'Turn on new Hall imports', 'bingocommerce-catalog' ), $on ? 'secondary' : 'primary' ); ?>
				<p class="description"><?php echo esc_html__( 'Turning this off stops new imports only. Imports already started can continue, and no product is changed or deleted.', 'bingocommerce-catalog' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * The Publish buttons for the current state. All start disabled; the
	 * script enables them, so without JavaScript nothing half-works.
	 *
	 * @param array{building:array<string,mixed>|null,cleanup:array<int,int>}|\WP_Error $publish
	 */
	private static function render_publish( $publish ): void {
		if ( is_wp_error( $publish ) ) {
			echo '<p>' . esc_html( $publish->get_error_message() ) . '</p>';

			return;
		}

		$building  = $publish['building'];
		$resumable = is_array( $building ) && ! empty( $building['resumable'] );
		$version   = is_array( $building ) ? (int) $building['version_id'] : 0;
		if ( $resumable ) {
			/* translators: 1: version number, 2: products captured, 3: products in the version. */
			$intro = sprintf( _n( 'Version %1$d is being published: %2$d of %3$d product captured. Select Resume to continue, or Discard to remove it.', 'Version %1$d is being published: %2$d of %3$d products captured. Select Resume to continue, or Discard to remove it.', (int) $building['total'], 'bingocommerce-catalog' ), $version, (int) $building['captured'], (int) $building['total'] );
		} elseif ( is_array( $building ) ) {
			/* translators: %d: version number. */
			$intro = sprintf( __( 'Version %d was started, but its product selection was not saved. Discard it, then publish again.', 'bingocommerce-catalog' ), $version );
		} else {
			$intro = __( 'Publish makes a new numbered version from the included products. Halls keep importing the current version until the new one is published.', 'bingocommerce-catalog' );
		}
		?>
		<div id="bc-starter-publish">
			<noscript><p><?php echo esc_html__( 'Publishing needs JavaScript in your browser.', 'bingocommerce-catalog' ); ?></p></noscript>
			<p data-bc-intro><?php echo esc_html( $intro ); ?></p>
			<p>
				<button type="button" class="button button-primary" data-bc-publish="start" disabled<?php echo null !== $building ? ' hidden' : ''; ?>><?php echo esc_html__( 'Publish New Version', 'bingocommerce-catalog' ); ?></button>
				<button type="button" class="button button-primary" data-bc-publish="continue" disabled<?php echo $resumable ? '' : ' hidden'; ?>><?php echo esc_html__( 'Resume', 'bingocommerce-catalog' ); ?></button>
				<button type="button" class="button" data-bc-publish="discard" data-bc-confirm data-bc-version="<?php echo esc_attr( (string) $version ); ?>" disabled<?php echo null !== $building ? '' : ' hidden'; ?>><?php echo esc_html__( 'Discard', 'bingocommerce-catalog' ); ?></button>
			</p>
			<?php foreach ( $publish['cleanup'] as $discarded ) : ?>
				<p data-bc-cleanup="<?php echo esc_attr( (string) (int) $discarded ); ?>">
					<?php
					/* translators: %d: version number. */
					echo esc_html( sprintf( __( 'Version %d was discarded, but some of its rows or image files remain.', 'bingocommerce-catalog' ), (int) $discarded ) );
					?>
					<button type="button" class="button" data-bc-publish="discard" data-bc-version="<?php echo esc_attr( (string) (int) $discarded ); ?>" disabled><?php echo esc_html__( 'Finish Discard', 'bingocommerce-catalog' ); ?></button>
				</p>
			<?php endforeach; ?>
			<p role="status" aria-live="polite" data-bc-status></p>
			<ul class="ul-disc" data-bc-problems hidden></ul>
			<p data-bc-warnings-title hidden><?php echo esc_html__( 'WordPress removed content it does not allow from these products (publishing continues):', 'bingocommerce-catalog' ); ?></p>
			<ul class="ul-disc" data-bc-warnings hidden></ul>
			<p><a href="<?php echo esc_url( network_admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" data-bc-reload hidden><?php echo esc_html__( 'Reload this page', 'bingocommerce-catalog' ); ?></a></p>
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

	private static function publish_nonce_action(): string {
		return 'bingocommerce_starter_catalog_publish:network:' . get_current_network_id();
	}

	private static function error( string $code ): \WP_Error {
		$message = match ( $code ) {
			'denied'  => __( 'You are not authorized to manage the starter catalog.', 'bingocommerce-catalog' ),
			'nonce'   => __( 'Verification failed. Reload this page and try again.', 'bingocommerce-catalog' ),
			'site'    => __( 'Publishing runs only from the main site\'s Network Admin.', 'bingocommerce-catalog' ),
			'storage' => __( 'The setting could not be saved. Reload and check it.', 'bingocommerce-catalog' ),
			default   => __( 'Invalid request. Nothing was changed.', 'bingocommerce-catalog' ),
		};
		$status  = match ( $code ) {
			'denied', 'nonce', 'site' => 403,
			'method'                  => 405,
			default                   => 400,
		};

		return new \WP_Error( 'starter_catalog_' . $code, $message, [ 'status' => $status ] );
	}
}
