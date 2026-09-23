<?php

declare(strict_types=1);

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal current-hall WordPress administration for Bingo Packages.
 *
 * Presentation and request marshalling only. Every domain rule — claim
 * exclusivity, composition integrity, BR-061 type derivation, lifecycle — lives
 * in PackageService and is never re-implemented here.
 *
 * Two things are deliberately absent from this screen:
 *
 *   - a Package type control, because BR-061 derives the type from the
 *     authoritative composition and a caller may never set it; and
 *   - a Package price field, because ADR-031 and BR-049 make the linked
 *     WooCommerce product's native price the only base-price authority. The
 *     price shown here is READ from WooCommerce.
 *
 * `sort_order` is not exposed and no reordering surface exists in Increment 1.
 */
final class PackageAdmin {

	private const PAGE_SLUG = 'bingocommerce-packages';
	private const NONCE_ACTION = 'bingocommerce_package_mutation';
	private const ADDONS_NONCE = 'bingocommerce_package_addons';

	/** One request-local response; render_addons() verifies Hall, actor and Package. Never persisted. */
	private static ?array $addons_response = null;

	/** Upper bound on the create form's product selector. */
	private const PRODUCT_CHOICE_LIMIT = 200;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		// The native My Sites toolbar temporarily switches blogs between load_editor()
		// and render(). Reset on editor load, not switch_blog; scope at notice rendering.
	}

	public static function add_menu(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		$hook = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Bingo Packages', 'bingocommerce-catalog' ),
			__( 'Bingo Packages', 'bingocommerce-catalog' ),
			'bc_manage_products',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, [ self::class, 'load_editor' ] );
		}
	}

	public static function clear_addons_response(): void {
		self::$addons_response = null;
	}

	/** Runs before admin-header.php emits output. No redirect or retained notice store. */
	public static function load_editor(): void {
		self::clear_addons_response();
		if ( isset( $_POST['bc_addons_form'] ) || isset( $_POST['bc_addons_action'] ) ) {
			self::$addons_response = [
				'blog' => get_current_blog_id(),
				'actor' => get_current_user_id(),
				'package' => self::addon_id( wp_unslash( $_GET['edit'] ?? '' ) ),
				'action' => is_string( $_POST['bc_addons_action'] ?? null ) ? wp_unslash( $_POST['bc_addons_action'] ) : '',
				'result' => self::handle_addons_request( wp_unslash( $_POST ) ),
			];
		}
		if ( self::addon_id( wp_unslash( $_GET['edit'] ?? '' ) ) > 0 && HallContext::is_hall()
			&& is_user_logged_in() && ( is_super_admin() || current_user_can( 'bc_manage_products' ) ) ) {
			wp_enqueue_script( 'bingocommerce-package-addons-admin', plugins_url( '../assets/package-addons-admin.js', __FILE__ ), [], BINGOCOMMERCE_CATALOG_VERSION, true );
		}
	}

	public static function render(): void {
		if ( ! HallContext::is_hall() || ( ! is_super_admin() && ! current_user_can( 'bc_manage_products' ) ) ) {
			wp_die( esc_html__( 'You are not authorized to manage Bingo Packages for this hall.', 'bingocommerce-catalog' ) );
		}

		$service = new PackageService();
		$play    = new BingoPlayProductService();
		$result  = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! isset( $_POST['bc_addons_form'] ) && ! isset( $_POST['bc_addons_action'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$result = self::handle_request( wp_unslash( $_POST ), $service );
		}

		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) );
		if ( ! in_array( $status, [ 'all', 'active', 'archived' ], true ) ) {
			$status = 'all';
		}

		// A failed read is NOT an empty hall. Presenting one as the other would
		// tell a Hall Manager their Packages are gone.
		$packages       = $service->listForAdministration( $status );
		$packages_error = is_wp_error( $packages ) ? $packages : null;
		$packages       = is_wp_error( $packages ) ? [] : $packages;

		$selectable       = $play->listActiveForPackageSelection();
		$selectable_error = is_wp_error( $selectable ) ? $selectable : null;
		$selectable       = is_wp_error( $selectable ) ? [] : $selectable;

		// A Package id, so it is parsed without absolute-value coercion too.
		$editing = isset( $_GET['edit'] ) ? self::request_int( wp_unslash( $_GET['edit'] ) ) : 0;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bingo Packages', 'bingocommerce-catalog' ); ?></h1>
			<p><?php echo esc_html__( 'A Bingo Package is sold through a linked WooCommerce product. Its price comes from that product; its type is derived from the Bingo Play Products it contains.', 'bingocommerce-catalog' ); ?></p>

			<?php self::render_notice( $result ); ?>

			<?php if ( null !== $packages_error ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'This hall\'s Bingo Packages could not be read, so the list below is incomplete. Do not assume it is empty. Contact Platform Admin.', 'bingocommerce-catalog' ); ?></p>
					<p><?php echo esc_html( self::error_message( $packages_error ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( null !== $selectable_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html__( 'This hall\'s Bingo Play Products could not be read, so no composition can be built safely right now. Contact Platform Admin.', 'bingocommerce-catalog' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $editing > 0 ) : ?>
				<?php self::render_edit_form( $service, $selectable, $editing ); ?>
				<?php self::render_addons( $editing ); ?>
			<?php else : ?>
				<?php self::render_create_form( $service, $selectable ); ?>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Bingo Packages in this hall', 'bingocommerce-catalog' ); ?></h2>
			<ul class="subsubsub">
				<?php foreach ( [ 'all' => __( 'All', 'bingocommerce-catalog' ), 'active' => __( 'Active', 'bingocommerce-catalog' ), 'archived' => __( 'Archived', 'bingocommerce-catalog' ) ] as $key => $label ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'page' => self::PAGE_SLUG, 'status' => $key ], admin_url( 'edit.php' ) ) ); ?>"
							<?php echo $status === $key ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Package', 'bingocommerce-catalog' ); ?></th>
						<th><?php echo esc_html__( 'WooCommerce product', 'bingocommerce-catalog' ); ?></th>
						<th><?php echo esc_html__( 'Price (WooCommerce)', 'bingocommerce-catalog' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'bingocommerce-catalog' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'bingocommerce-catalog' ); ?></th>
						<th><?php echo esc_html__( 'Composition', 'bingocommerce-catalog' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'bingocommerce-catalog' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( [] === $packages && null === $packages_error ) : ?>
						<tr><td colspan="7"><?php echo esc_html__( 'No Bingo Packages yet.', 'bingocommerce-catalog' ); ?></td></tr>
					<?php elseif ( [] === $packages ) : ?>
						<tr><td colspan="7"><?php echo esc_html__( 'The Bingo Package list is unavailable.', 'bingocommerce-catalog' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $packages as $package ) : ?>
						<tr>
							<td>#<?php echo esc_html( (string) $package['package_id'] ); ?></td>
							<td>
								<?php echo esc_html( self::product_label( (int) $package['wc_product_id'] ) ); ?>
								<?php if ( 'ok' !== $package['woo_state'] ) : ?>
									<br><em><?php echo esc_html( self::woo_state_label( (string) $package['woo_state'] ) ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php
								// The service's formatted value is plain text; preserve native sale/a11y markup here only.
								$product = $package['woo_price']['available'] ? wc_get_product( (int) $package['wc_product_id'] ) : false;
								$price_html = $product instanceof \WC_Product ? (string) $product->get_price_html() : '';
								echo '' !== $price_html ? wp_kses_post( $price_html ) : esc_html__( 'Unavailable', 'bingocommerce-catalog' );
								?>
							</td>
							<td><strong><?php echo esc_html( self::type_label( (string) $package['package_type'] ) ); ?></strong></td>
							<td>
								<?php echo esc_html( self::status_label( (string) $package['status'] ) ); ?>
								<?php if ( ! self::is_known_status( (string) $package['status'] ) ) : ?>
									<br><em><?php echo esc_html__( 'Contact Platform Admin. No lifecycle action is offered for an inconsistent Package.', 'bingocommerce-catalog' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( self::composition_summary( $package ) ); ?>
								<?php if ( ! empty( $package['has_archived_component'] ) ) : ?>
									<br><em><?php echo esc_html__( 'Contains an archived Bingo Play Product. Correct it deliberately.', 'bingocommerce-catalog' ); ?></em>
								<?php endif; ?>
								<?php if ( ! empty( $package['has_unresolved_component'] ) ) : ?>
									<br><em><?php echo esc_html__( 'INTEGRITY FAULT: a component does not resolve to a usable Bingo Play Product. Composition editing is unavailable and cannot repair it. Contact Platform Admin.', 'bingocommerce-catalog' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php
								/*
								 * Lifecycle is handled EXPLICITLY. An unrecognised status is
								 * never coerced to Archived, and offers no action at all: the
								 * domain writer fails closed on it, so presenting a button
								 * would promise something the service will refuse.
								 */
								$editable   = PackageService::STATUS_ACTIVE === $package['status'] && empty( $package['has_unresolved_component'] );
								$can_archive = PackageService::STATUS_ACTIVE === $package['status'];
								$can_restore = PackageService::STATUS_ARCHIVED === $package['status'];
								?>
								<?php if ( $editable ) : ?>
									<a href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'page' => self::PAGE_SLUG, 'status' => $status, 'edit' => (int) $package['package_id'] ], admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html__( 'Edit composition', 'bingocommerce-catalog' ); ?></a>
									&nbsp;
								<?php endif; ?>
								<?php if ( $can_restore || ( $can_archive && ! $editable ) ) : ?>
									<a href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'page' => self::PAGE_SLUG, 'edit' => (int) $package['package_id'] ], admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html__( 'View Package', 'bingocommerce-catalog' ); ?></a>
								<?php endif; ?>
								<?php if ( $can_archive || $can_restore ) : ?>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $package['package_id'] ); ?>">
										<?php if ( $can_archive ) : ?>
											<input type="hidden" name="bc_package_action" value="archive">
											<button type="submit" class="button button-small"><?php echo esc_html__( 'Archive', 'bingocommerce-catalog' ); ?></button>
										<?php else : ?>
											<input type="hidden" name="bc_package_action" value="restore">
											<button type="submit" class="button button-small"><?php echo esc_html__( 'Restore', 'bingocommerce-catalog' ); ?></button>
										<?php endif; ?>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * ADR-044 Optional Add-ons: current-Hall reads and per-row orchestration.
	 * No business writes, price derivation or transaction boundaries live here.
	 * ------------------------------------------------------------------ */

	private static function addon_id( mixed $value ): int {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value )
			|| strlen( $value ) > strlen( (string) PHP_INT_MAX )
			|| ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			return 0;
		}
		return (int) $value;
	}

	private static function addons_nonce( int $package_id ): string {
		return self::ADDONS_NONCE . ':' . get_current_blog_id() . ':' . $package_id;
	}

	private static function addon_error( string $code ): \WP_Error {
		return new \WP_Error( $code, self::addon_message( $code ) );
	}

	/** @return array|\WP_Error All configured rows precede never-configured candidates. */
	private static function addons_model( int $package_id, bool $prefill ): array|\WP_Error {
		$package = ( new PackageService() )->getById( $package_id );
		if ( $package instanceof \WP_Error ) { return $package; }
		if ( ! CatalogPackageAddonPlan::validPackage( $package ) ) { return self::addon_error( 'package_addon_state_inconsistent' ); }
		$configured = ( new PackageAddonService() )->listForPackage( $package_id );
		if ( $configured instanceof \WP_Error ) { return $configured; }
		if ( ! did_action( 'woocommerce_init' ) || ! function_exists( 'wc_get_product' ) ) { return self::addon_error( 'persistence_failed' ); }

		$play = new BingoPlayProductService();
		$rows = [];
		$used = [];
		foreach ( $configured as $row ) {
			$id = $row['bingo_play_product_id'];
			$used[ $id ] = true;
			$product = $play->getById( $id );
			if ( $product instanceof \WP_Error && 'play_product_not_found' !== $product->get_error_code() ) { return $product; }
			$woo = $product instanceof \WP_Error ? false : self::addon_woo_product( $product['wc_product_id'] );
			if ( $woo instanceof \WP_Error ) { return $woo; }
			$row['unresolved'] = $product instanceof \WP_Error;
			$row['woo_missing'] = ! $row['unresolved'] && ! $woo instanceof \WC_Product;
			$row['label'] = $woo instanceof \WC_Product ? $woo->get_name( 'edit' ) : sprintf( __( 'Bingo Play Product #%d', 'bingocommerce-catalog' ), $id );
			$row['configured'] = true;
			$rows[ 'r' . $row['package_addon_id'] ] = $row;
		}
		if ( 'active' === $package['status'] ) {
			// A narrow admin read; never add candidate discovery to the domain service.
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT bingo_play_product_id FROM %i WHERE status = %s ORDER BY bingo_play_product_id', Migrations::play_products_table(), 'active' ) );
			if ( ! is_array( $ids ) || '' !== $wpdb->last_error ) { return self::addon_error( 'persistence_failed' ); }
			foreach ( $ids as $id ) {
				if ( isset( $used[ (int) $id ] ) ) { continue; }
				$product = $play->getById( (int) $id );
				if ( $product instanceof \WP_Error ) {
					if ( 'play_product_not_found' === $product->get_error_code() ) { continue; }
					return $product;
				}
				if ( ! CatalogPackageAddonPlan::validPlayProduct( $product ) ) { return self::addon_error( 'package_addon_state_inconsistent' ); }
				if ( 'active' !== $product['status'] || ! CatalogPackageAddonPlan::compatible( $package['package_type'], $product['medium'] ) ) { continue; }
				$woo = self::addon_woo_product( $product['wc_product_id'] );
				if ( $woo instanceof \WP_Error ) { return $woo; }
				if ( ! $woo instanceof \WC_Product ) { continue; }
				$rows[ 'p' . $id ] = [
					'configured' => false, 'bingo_play_product_id' => (int) $id,
					'label' => $woo->get_name( 'edit' ), 'medium' => $product['medium'],
					'play_product_status' => 'active', 'medium_compatible' => true,
					'unresolved' => false, 'woo_missing' => false, 'enabled' => 0,
					'unit_price' => $prefill ? self::addon_prefill( $woo->get_regular_price( 'edit' ) ) : '',
					'max_quantity' => 1, 'fingerprint' => '',
				];
			}
		}
		return [ 'package' => $package, 'rows' => $rows ];
	}

	/** Normal current-Hall WooCommerce API; never called inside a Catalog transaction. */
	private static function addon_woo_product( int $wc_product_id ): \WC_Product|false|\WP_Error {
		global $wpdb;
		try {
			$product = \wc_get_product( $wc_product_id );
			if ( '' !== $wpdb->last_error ) { return self::addon_error( 'persistence_failed' ); }
			return $product instanceof \WC_Product ? $product : false;
		} catch ( \Throwable $error ) {
			return self::addon_error( 'persistence_failed' );
		}
	}

	/** UI convenience only. Submitted values never pass through this padding. */
	private static function addon_prefill( mixed $price ): string {
		if ( ! is_string( $price ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,16})(?:\.[0-9]{1,2})?\z/', $price ) ) { return ''; }
		$parts = explode( '.', $price );
		return $parts[0] . '.' . str_pad( $parts[1] ?? '', 2, '0' );
	}

	/** @return array|\WP_Error Global boundaries precede every independent row. */
	private static function handle_addons_request( array $request ): array|\WP_Error {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return self::addon_error( 'invalid_request' ); }
		if ( ! HallContext::is_hall() ) { return self::addon_error( 'invalid_hall_context' ); }
		if ( ! is_user_logged_in() || ( ! is_super_admin() && ! current_user_can( 'bc_manage_products' ) ) ) { return self::addon_error( 'package_addon_management_denied' ); }
		$package_id = self::addon_id( wp_unslash( $_GET['edit'] ?? '' ) );
		if ( ! is_string( $request['_wpnonce'] ?? null ) || ! wp_verify_nonce( $request['_wpnonce'], self::addons_nonce( $package_id ) ) ) { return self::addon_error( 'invalid_nonce' ); }
		$package = ( new PackageService() )->getById( $package_id );
		if ( $package instanceof \WP_Error ) { return $package; }
		if ( self::addon_id( $request['package_id'] ?? null ) !== $package_id || '1' !== ( $request['bc_addons_form'] ?? null )
			|| ! is_string( $request['bc_addons_action'] ?? null ) ) { return self::addon_error( 'invalid_request' ); }
		$model = self::addons_model( $package_id, false );
		if ( $model instanceof \WP_Error ) { return $model; }
		$service = new PackageAddonService();
		$action = $request['bc_addons_action'];
		$results = [];
		if ( str_starts_with( $action, 'retry:' ) ) {
			$parts = explode( ':', $action );
			$id = self::addon_id( $parts[1] ?? '' );
			// Only rendered after a projection warning, scoped to this actor/Hall/Package/product.
			if ( 3 !== count( $parts ) || ! $id || ! wp_verify_nonce( $parts[2], self::addons_nonce( $package_id ) . ':retry:' . $id ) ) { return self::addon_error( 'invalid_request' ); }
			$result = ( new BingoPlayProductService() )->getById( $id );
			if ( ! $result instanceof \WP_Error ) { $result = $service->reconcileProjection( $id ); }
			return [ 'retry' => [ 'result' => $result, 'submitted' => [], 'product' => $id ] ];
		}
		if ( str_starts_with( $action, 'remove:' ) ) {
			$key = substr( $action, 7 );
			$row = $model['rows'][ $key ] ?? null;
			$input = is_array( $request['addons'] ?? null ) ? ( $request['addons'][ $key ] ?? null ) : null;
			if ( ! is_array( $row ) || ! $row['configured'] ) { $result = self::addon_error( 'package_addon_not_found' ); }
			elseif ( ! is_array( $input ) || ! is_string( $input['fingerprint'] ?? null ) ) { $result = self::addon_error( 'package_addon_stale' ); }
			else { $result = $service->remove( $row['package_addon_id'], $input['fingerprint'] ); }
			return [ $key => [ 'result' => $result, 'submitted' => [], 'product' => $row['bingo_play_product_id'] ?? 0 ] ];
		}
		if ( 'save' !== $action || '1' !== ( $request['bc_addons_complete'] ?? null ) || ! is_array( $request['addons'] ?? [] ) ) { return self::addon_error( 'invalid_request' ); }
		foreach ( $request['addons'] ?? [] as $key => $input ) {
			$key = (string) $key;
			// Unchecked never-configured rows are ignored BEFORE touching price or maximum.
			if ( str_starts_with( $key, 'p' ) && is_array( $input ) && ! isset( $input['enabled'] ) ) { continue; }
			$row = $model['rows'][ $key ] ?? null;
			$result = null;
			$id = self::addon_id( substr( $key, 1 ) );
			if ( ! $id || ! in_array( $key[0] ?? '', [ 'r', 'p' ], true ) || ! is_array( $input ) ) { $result = self::addon_error( 'invalid_request' ); }
			elseif ( 'r' === $key[0] && ( ! is_array( $row ) || ! $row['configured'] ) ) { $result = self::addon_error( 'package_addon_not_found' ); }
			elseif ( 'p' === $key[0] && null === $row ) {
				// Identical resubmission of a just-created candidate still uses create's safe no-op.
				foreach ( $model['rows'] as $existing ) { if ( $id === $existing['bingo_play_product_id'] ) { $row = $existing; break; } }
				if ( null === $row ) { $result = self::addon_error( 'play_product_not_found' ); }
			}
			if ( null === $result ) {
				$price = $input['unit_price'] ?? null;
				$maximum = $input['max_quantity'] ?? null;
				$enabled = isset( $input['enabled'] );
				if ( $enabled && '1' !== $input['enabled'] ) { $result = self::addon_error( 'invalid_request' ); }
				elseif ( ! is_string( $price ) || 1 !== preg_match( CatalogPackageAddonPlan::PRICE_PATTERN, $price ) ) { $result = self::addon_error( 'package_addon_price_invalid' ); }
				elseif ( ! is_string( $maximum ) || 1 !== preg_match( '/\A0*[1-9][0-9]?\z/', $maximum ) ) { $result = self::addon_error( 'package_addon_max_quantity_invalid' ); }
				elseif ( 'r' === $key[0] && ! is_string( $input['fingerprint'] ?? null ) ) { $result = self::addon_error( 'package_addon_stale' ); }
				else {
					$maximum = (int) $maximum;
					$result = $row['woo_missing'] && ( $price !== $row['unit_price'] || $maximum !== $row['max_quantity'] || ( $enabled && ! $row['enabled'] ) )
						? self::addon_error( 'play_product_not_found' ) : ( 'r' === $key[0]
						? $service->update( $id, $input['fingerprint'], $price, $maximum, $enabled )
						: $service->create( $package_id, $id, $price, $maximum ) );
				}
			}
			$results[ $key ] = [ 'result' => $result, 'submitted' => is_array( $input ) ? $input : [], 'product' => $row['bingo_play_product_id'] ?? 0 ];
		}
		return $results;
	}

	private static function render_addons( int $package_id ): void {
		$response = self::$addons_response;
		$result = is_array( $response ) && $response['blog'] === get_current_blog_id() && $response['actor'] === get_current_user_id() && $response['package'] === $package_id ? $response['result'] : [];
		$model = self::addons_model( $package_id, true );
		echo '<section id="bc-package-addons"><h2>' . esc_html__( 'Optional Add-ons', 'bingocommerce-catalog' ) . '</h2>';
		if ( $result instanceof \WP_Error ) { self::addon_notice( $result ); }
		$results = is_array( $result ) ? $result : [];
		$is_save = 'save' === ( $response['action'] ?? '' );
		if ( $is_save ) { self::addon_save_summary( $results ); }
		// Even a confirming read failure must not erase a committed row's success.
		foreach ( $results as $key => $outcome ) {
			if ( $model instanceof \WP_Error || ! $outcome['result'] instanceof \WP_Error || 'retry' === $key ) { self::addon_notice( $outcome['result'], ! $is_save ); }
		}
		if ( $model instanceof \WP_Error ) {
			echo '<p class="notice notice-error">' . esc_html__( 'Optional Add-ons could not be read safely. The list is unavailable, not empty. Contact Platform Admin.', 'bingocommerce-catalog' ) . '</p>';
			self::addon_notice( $model );
			// Retain only targeted recovery from this scoped response, never unverified rows.
			self::render_addon_retries( $package_id, $results, true );
			echo '</section>';
			return;
		}
		$readonly = 'active' !== $model['package']['status'];
		$rows = $model['rows'];
		foreach ( $results as $key => $outcome ) {
			if ( ! $outcome['result'] instanceof \WP_Error || 'retry' === $key ) { continue; }
			if ( ! isset( $rows[ $key ] ) ) {
				// No unverified product name/ID is resolved for a forged or no-longer-eligible row.
				$rows[ $key ] = [ 'configured' => false, 'unresolved' => true, 'woo_missing' => false, 'label' => __( 'Submitted row — unavailable in this Package', 'bingocommerce-catalog' ), 'medium' => null, 'play_product_status' => null, 'medium_compatible' => false, 'unit_price' => '', 'max_quantity' => '', 'fingerprint' => '', 'enabled' => 0 ];
			}
			$rows[ $key ]['saved_enabled'] = $rows[ $key ]['enabled'];
			foreach ( [ 'unit_price', 'max_quantity', 'fingerprint' ] as $field ) {
				if ( is_string( $outcome['submitted'][ $field ] ?? null ) ) { $rows[ $key ][ $field ] = $outcome['submitted'][ $field ]; }
			}
			if ( [] !== $outcome['submitted'] ) { $rows[ $key ]['enabled'] = isset( $outcome['submitted']['enabled'] ) ? 1 : 0; }
		}
		?>
		<p><?php echo esc_html__( 'Configured relationships first, including disabled relationships. Uncheck to disable and preserve configuration. Prices are specific to this Package.', 'bingocommerce-catalog' ); ?></p>
		<?php if ( $readonly ) : ?><p><?php echo esc_html__( 'Archived Package — restore it before changing add-ons.', 'bingocommerce-catalog' ); ?></p><?php endif; ?>
		<label for="bc-addon-search"><?php echo esc_html__( 'Filter add-ons', 'bingocommerce-catalog' ); ?></label>
		<input type="search" id="bc-addon-search" autocomplete="off">
		<form method="post" id="bc-addons-form">
			<?php wp_nonce_field( self::addons_nonce( $package_id ) ); ?>
			<input type="hidden" name="bc_addons_form" value="1">
			<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $package_id ); ?>">
			<?php if ( ! $readonly ) : ?><p><button type="submit" class="button button-primary" name="bc_addons_action" value="save"><?php echo esc_html__( 'Save Add-ons', 'bingocommerce-catalog' ); ?></button></p><?php endif; ?>
			<table class="widefat striped"><thead><tr>
				<th><?php echo esc_html__( 'Enabled', 'bingocommerce-catalog' ); ?></th><th><?php echo esc_html__( 'Bingo Play Product', 'bingocommerce-catalog' ); ?></th>
				<th><?php echo esc_html__( 'Package-specific unit price', 'bingocommerce-catalog' ); ?></th><th><?php echo esc_html__( 'Maximum quantity', 'bingocommerce-catalog' ); ?></th><th><?php echo esc_html__( 'Action', 'bingocommerce-catalog' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $rows as $key => $row ) :
				$locked = $readonly || $row['unresolved'];
				$restricted = 'archived' === $row['play_product_status'] || $row['woo_missing'];
				$name = 'addons[' . $key . ']';
				?>
				<tr data-addon-row="<?php echo esc_attr( $key ); ?>" data-configured="<?php echo $row['configured'] ? '1' : '0'; ?>">
					<td><input type="checkbox" <?php if ( ! $locked ) : ?>name="<?php echo esc_attr( $name . '[enabled]' ); ?>"<?php endif; ?> value="1" <?php checked( (bool) $row['enabled'] ); ?> <?php disabled( $locked || ( ( $restricted || ! $row['medium_compatible'] ) && ! $row['enabled'] ) ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Enable %s', 'bingocommerce-catalog' ), $row['label'] ) ); ?>"></td>
					<td><strong><?php echo esc_html( $row['label'] ); ?></strong><br>
						<?php echo esc_html( $row['configured'] ? ( ( $row['saved_enabled'] ?? $row['enabled'] ) ? __( 'Configured — enabled', 'bingocommerce-catalog' ) : __( 'Configured — disabled', 'bingocommerce-catalog' ) ) : __( 'Not configured', 'bingocommerce-catalog' ) ); ?>
						<?php if ( null !== $row['medium'] ) : ?><br><?php echo esc_html( self::medium_label( $row['medium'] ) ); ?><?php endif; ?>
						<?php if ( $row['unresolved'] ) : ?><br><?php echo esc_html__( 'Unresolved Catalog Play Product — read-only. Contact Platform Admin.', 'bingocommerce-catalog' ); ?><?php endif; ?>
						<?php if ( $row['woo_missing'] ) : ?><br><?php echo esc_html__( 'Unresolved WooCommerce product — only disable or Remove is available.', 'bingocommerce-catalog' ); ?><?php endif; ?>
						<?php if ( 'archived' === $row['play_product_status'] ) : ?><br><?php echo esc_html__( 'Archived Play Product — only disable or Remove is available.', 'bingocommerce-catalog' ); ?><?php endif; ?>
						<?php if ( ! $row['medium_compatible'] && ! $row['unresolved'] ) : ?><br><?php echo esc_html__( 'Medium incompatible — disable or Remove; enabling is not permitted.', 'bingocommerce-catalog' ); ?><?php endif; ?>
						<?php if ( isset( $results[ $key ] ) && $results[ $key ]['result'] instanceof \WP_Error ) : self::addon_notice( $results[ $key ]['result'] ); endif; ?>
					</td>
					<td><input type="text" inputmode="decimal" <?php if ( ! $locked ) : ?>name="<?php echo esc_attr( $name . '[unit_price]' ); ?>"<?php endif; ?> value="<?php echo esc_attr( (string) $row['unit_price'] ); ?>" <?php wp_readonly( $locked || $restricted ); ?> aria-label="<?php echo esc_attr__( 'Unit price, exactly two decimal digits', 'bingocommerce-catalog' ); ?>"></td>
					<td><input type="text" inputmode="numeric" size="3" <?php if ( ! $locked ) : ?>name="<?php echo esc_attr( $name . '[max_quantity]' ); ?>"<?php endif; ?> value="<?php echo esc_attr( (string) $row['max_quantity'] ); ?>" <?php wp_readonly( $locked || $restricted ); ?> aria-label="<?php echo esc_attr__( 'Maximum quantity, 1–99', 'bingocommerce-catalog' ); ?>"></td>
					<td><?php if ( ! $locked && $row['configured'] ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name . '[fingerprint]' ); ?>" value="<?php echo esc_attr( $row['fingerprint'] ); ?>">
						<button type="submit" class="button button-small" name="bc_addons_action" value="<?php echo esc_attr( 'remove:' . $key ); ?>" data-addon-remove="<?php echo esc_attr__( 'Permanently remove this add-on relationship? Unchecking instead preserves its configuration.', 'bingocommerce-catalog' ); ?>" disabled><?php echo esc_html__( 'Remove', 'bingocommerce-catalog' ); ?></button>
					<?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( [] === $rows ) : ?><tr><td colspan="5"><?php echo esc_html__( 'No configured add-ons or eligible Play Products.', 'bingocommerce-catalog' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<input type="hidden" name="bc_addons_complete" value="1">
			<noscript><p><?php echo esc_html__( 'Saving works without JavaScript. Enable JavaScript to filter the list or confirm permanent Remove.', 'bingocommerce-catalog' ); ?></p></noscript>
			<?php self::render_addon_retries( $package_id, $results ); ?>
		</form></section>
		<?php
	}

	/** Same targeted controls, with a recovery-only form when the list cannot be rendered. */
	private static function render_addon_retries( int $package_id, array $results, bool $standalone = false ): void {
		$retries = [];
		foreach ( $results as $outcome ) {
			$value = $outcome['result'];
			$warning = $value instanceof PackageAddonResult && in_array( 'projection_reconciliation_required', array_column( $value->warnings, 'code' ), true );
			if ( ( $warning || ( $value instanceof \WP_Error && 'projection_reconciliation_required' === $value->get_error_code() ) ) && $outcome['product'] > 0 ) { $retries[ $outcome['product'] ] = true; }
		}
		if ( [] === $retries ) { return; }
		if ( $standalone ) : ?>
			<form method="post">
				<?php wp_nonce_field( self::addons_nonce( $package_id ) ); ?>
				<input type="hidden" name="bc_addons_form" value="1">
				<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $package_id ); ?>">
		<?php endif; ?>
		<?php foreach ( array_keys( $retries ) as $id ) : ?>
			<p><button type="submit" class="button" name="bc_addons_action" value="<?php echo esc_attr( 'retry:' . $id . ':' . wp_create_nonce( self::addons_nonce( $package_id ) . ':retry:' . $id ) ); ?>"><?php echo esc_html( sprintf( __( 'Retry synchronization — Play Product #%d', 'bingocommerce-catalog' ), $id ) ); ?></button></p>
		<?php endforeach; ?>
		<?php if ( $standalone ) : ?></form><?php endif; ?>
		<?php
	}

	/** Count confirmed Save outcomes and rows needing attention without claiming unknown commits failed. */
	private static function addon_save_summary( array $results ): void {
		$counts = [ 'package_addon_created' => 0, 'package_addon_updated' => 0, 'package_addon_unchanged' => 0 ];
		$attention = 0;
		foreach ( $results as $outcome ) {
			$value = $outcome['result'];
			if ( $value instanceof PackageAddonResult && isset( $counts[ $value->status ] ) ) { ++$counts[ $value->status ]; }
			elseif ( $value instanceof \WP_Error ) { ++$attention; }
		}
		if ( 0 === array_sum( $counts ) + $attention ) { return; }
		$notice = $attention > 0 ? ( $attention === count( $results ) ? 'error' : 'warning' ) : 'success';
		if ( $counts['package_addon_unchanged'] === count( $results ) ) {
			$message = __( 'Add-ons unchanged.', 'bingocommerce-catalog' );
			$notice = 'info';
		} else {
			$parts = [];
			if ( $counts['package_addon_created'] > 0 ) {
				/* translators: %d: number of created add-ons. */
				$parts[] = sprintf( _n( '%d add-on created', '%d add-ons created', $counts['package_addon_created'], 'bingocommerce-catalog' ), $counts['package_addon_created'] );
			}
			if ( $counts['package_addon_updated'] > 0 ) {
				/* translators: %d: number of updated add-ons. */
				$parts[] = sprintf( _n( '%d add-on updated', '%d add-ons updated', $counts['package_addon_updated'], 'bingocommerce-catalog' ), $counts['package_addon_updated'] );
			}
			if ( $counts['package_addon_unchanged'] > 0 ) {
				/* translators: %d: number of unchanged add-ons in a partially changed or failed submission. */
				$parts[] = sprintf( __( '%d unchanged', 'bingocommerce-catalog' ), $counts['package_addon_unchanged'] );
			}
			if ( $attention > 0 ) {
				/* translators: %d: number of rows needing review, including unknown commit outcomes. */
				$parts[] = sprintf( _n( '%d row needs attention', '%d rows need attention', $attention, 'bingocommerce-catalog' ), $attention );
			}
			$message = implode( '; ', $parts ) . '.';
			if ( $attention > 0 ) {
				$message .= ' ' . _n( 'Review the row below.', 'Review the rows below.', $attention, 'bingocommerce-catalog' );
			}
		}
		echo '<p class="notice notice-' . esc_attr( $notice ) . '">' . esc_html( $message ) . '</p>';
	}

	private static function addon_notice( mixed $result, bool $show_success = true ): void {
		if ( $result instanceof \WP_Error ) {
			echo '<p class="notice notice-error">' . esc_html( self::addon_message( $result->get_error_code() ) ) . '</p>';
		} elseif ( $result instanceof PackageAddonResult ) {
			if ( $show_success ) { echo '<p class="notice notice-success">' . esc_html( self::addon_message( $result->status ) ) . '</p>'; }
			foreach ( $result->warnings as $warning ) {
				echo '<p class="notice notice-warning">' . esc_html( self::addon_message( $warning['code'] ?? '' ) ) . '</p>';
			}
		}
	}

	/** Fixed safe text only: never render service/database exception messages or details. */
	private static function addon_message( string $code ): string {
		return match ( $code ) {
			'package_addon_created' => __( 'Add-on saved and enabled.', 'bingocommerce-catalog' ),
			'package_addon_updated' => __( 'Add-on updated.', 'bingocommerce-catalog' ),
			'package_addon_unchanged' => __( 'Add-on unchanged.', 'bingocommerce-catalog' ),
			'package_addon_removed' => __( 'Add-on relationship removed.', 'bingocommerce-catalog' ),
			'package_addon_projection_synchronized', 'package_addon_projection_unchanged' => __( 'Synchronization verified.', 'bingocommerce-catalog' ),
			'package_addon_price_invalid' => __( 'Enter a non-negative price with exactly two decimal digits, such as 5.00, without signs or leading zeros.', 'bingocommerce-catalog' ),
			'package_addon_max_quantity_invalid' => __( 'Enter a whole maximum quantity from 1 through 99.', 'bingocommerce-catalog' ),
			'package_addon_stale' => __( 'This add-on changed after the form was loaded. Reload and review it before saving again.', 'bingocommerce-catalog' ),
			'package_addon_not_found', 'play_product_not_found' => __( 'This row is unavailable in the current Package or Hall. Reload and review it; nothing was detached.', 'bingocommerce-catalog' ),
			'package_addon_already_exists' => __( 'This Play Product is already configured differently. Reload to review its saved configuration.', 'bingocommerce-catalog' ),
			'package_addon_medium_incompatible' => __( 'This Play Product medium is incompatible with the Package. Disable or remove the relationship.', 'bingocommerce-catalog' ),
			'package_archived' => __( 'Restore the Package before changing its add-ons.', 'bingocommerce-catalog' ),
			'play_product_not_active' => __( 'An archived Play Product permits only disable or Remove, without changing its configuration.', 'bingocommerce-catalog' ),
			'catalog_commit_outcome_unknown' => __( 'The save outcome is unknown. No automatic retry was made. Reload and inspect Catalog state before deciding what to do next.', 'bingocommerce-catalog' ),
			'state_read_failed' => __( 'The change committed, but its refreshed state could not be read. Reload before further changes.', 'bingocommerce-catalog' ),
			'audit_write_failed' => __( 'The change committed, but its audit record could not be written. Contact Platform Admin.', 'bingocommerce-catalog' ),
			'projection_reconciliation_required' => __( 'Catalog remains authoritative. WooCommerce synchronization needs attention; use the targeted Retry synchronization action.', 'bingocommerce-catalog' ),
			'invalid_nonce' => __( 'The form expired or could not be verified. Reload before trying again.', 'bingocommerce-catalog' ),
			'package_addon_management_denied', 'invalid_hall_context' => __( 'You are not authorized to manage add-ons in this Hall.', 'bingocommerce-catalog' ),
			'package_addon_state_inconsistent', 'package_state_inconsistent' => __( 'Catalog state is inconsistent. Nothing was silently repaired. Contact Platform Admin.', 'bingocommerce-catalog' ),
			default => __( 'This add-on request could not be completed safely. Reload and review the row; contact Platform Admin if the problem continues.', 'bingocommerce-catalog' ),
		};
	}

	/* ------------------------------------------------------------------ *
	 * Forms
	 * ------------------------------------------------------------------ */

	/** @param array<int,array<string,mixed>> $selectable */
	private static function render_create_form( PackageService $service, array $selectable ): void {
		$choices = self::linkable_products( $service );
		?>
		<h2><?php echo esc_html__( 'Create a Bingo Package', 'bingocommerce-catalog' ); ?></h2>
		<?php if ( [] === $choices ) : ?>
			<p><?php echo esc_html__( 'No eligible simple WooCommerce products are available to link. Create a simple product first, and make sure it is not already a Bingo Play Product or Bingo Package.', 'bingocommerce-catalog' ); ?></p>
		<?php elseif ( [] === $selectable ) : ?>
			<p><?php echo esc_html__( 'No active Bingo Play Products are available. Create at least one before building a Package.', 'bingocommerce-catalog' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="bc_package_action" value="create">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bc-pkg-product"><?php echo esc_html__( 'WooCommerce product', 'bingocommerce-catalog' ); ?></label></th>
						<td>
							<select id="bc-pkg-product" name="wc_product_id" required>
								<?php foreach ( $choices as $id => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'This product carries the Package price and cannot be changed after the Package is created.', 'bingocommerce-catalog' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Initial composition', 'bingocommerce-catalog' ); ?></th>
						<td>
							<?php self::render_component_rows( $selectable, [] ); ?>
							<p class="description"><?php echo esc_html__( 'Tick each Bingo Play Product to include and give it a quantity. A Package needs at least one. Its type is derived from what you choose.', 'bingocommerce-catalog' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Create Bingo Package', 'bingocommerce-catalog' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/** @param array<int,array<string,mixed>> $selectable */
	private static function render_edit_form( PackageService $service, array $selectable, int $package_id ): void {
		$package = $service->getById( $package_id );
		if ( is_wp_error( $package ) ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( self::error_message( $package ) ); ?></p></div>
			<?php
			return;
		}

		$fingerprint = $service->compositionFingerprint( $package_id );
		if ( is_wp_error( $fingerprint ) ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( self::error_message( $fingerprint ) ); ?></p></div>
			<?php
			return;
		}

		$current = [];
		foreach ( $package['components'] as $component ) {
			$current[ (int) $component['bingo_play_product_id'] ] = $component;
		}
		?>
		<h2><?php printf( esc_html__( 'Edit composition — Package #%d', 'bingocommerce-catalog' ), (int) $package_id ); ?></h2>
		<?php if ( ! empty( $package['has_unresolved_component'] ) ) : ?>
			<?php
			/*
			 * ADR-033 fails closed on a component whose authoritative Bingo Play
			 * Product row no longer resolves, INCLUDING an edit that would remove
			 * it. Offering the ordinary save workflow here would present a repair
			 * the domain will refuse, so it is withheld entirely.
			 */
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'This Bingo Package references a Bingo Play Product whose authoritative record does not resolve, or which holds an unrecognised medium or lifecycle value. That is a Catalog integrity fault, not an archived component.', 'bingocommerce-catalog' ); ?></p>
				<p><?php echo esc_html__( 'Composition editing is unavailable for this Package and cannot repair it. Contact Platform Admin.', 'bingocommerce-catalog' ); ?></p>
			</div>
			<?php self::render_unresolved_components( $package ); ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'page' => self::PAGE_SLUG ], admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html__( 'Back to Bingo Packages', 'bingocommerce-catalog' ); ?></a>
		<?php elseif ( PackageService::STATUS_ARCHIVED === $package['status'] ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html__( 'This Bingo Package is archived. Restore it before editing its composition.', 'bingocommerce-catalog' ); ?></p></div>
		<?php elseif ( PackageService::STATUS_ACTIVE !== $package['status'] ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'This Bingo Package holds an unrecognised lifecycle value. No composition edit and no lifecycle action is offered. Contact Platform Admin.', 'bingocommerce-catalog' ); ?></p></div>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="bc_package_action" value="replace_components">
				<input type="hidden" name="package_id" value="<?php echo esc_attr( (string) $package_id ); ?>">
				<input type="hidden" name="expected_fingerprint" value="<?php echo esc_attr( (string) $fingerprint ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Current type', 'bingocommerce-catalog' ); ?></th>
						<td><strong><?php echo esc_html( self::type_label( (string) $package['package_type'] ) ); ?></strong>
							<p class="description"><?php echo esc_html__( 'Derived from the composition below. It updates automatically when you save.', 'bingocommerce-catalog' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Composition', 'bingocommerce-catalog' ); ?></th>
						<td><?php self::render_component_rows( $selectable, $current ); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save composition', 'bingocommerce-catalog' ) ); ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'page' => self::PAGE_SLUG ], admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html__( 'Cancel', 'bingocommerce-catalog' ); ?></a>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $selectable
	 * @param array<int,array<string,mixed>> $current
	 */
	private static function render_component_rows( array $selectable, array $current ): void {
		// An archived component already in the composition must stay selectable so
		// a Hall Manager can retain or remove it (BR-067). It is never offered as
		// a NEW choice, because the selectable list contains active rows only.
		$rows = [];
		foreach ( $selectable as $play_product ) {
			// A row whose authoritative medium is unrecognised is never offered as
			// a valid new component. Presenting it would let a Hall Manager pick
			// what BR-061 cannot classify and what the writer will refuse.
			if ( ! self::has_valid_medium( $play_product['medium'] ?? null ) ) {
				continue;
			}

			$rows[ (int) $play_product['bingo_play_product_id'] ] = [
				'label'    => self::play_product_label( $play_product ),
				'archived' => false,
			];
		}
		foreach ( $current as $id => $component ) {
			if ( isset( $rows[ $id ] ) ) {
				continue;
			}

			// Absence from the ACTIVE selection list is not evidence of archival.
			// The component's own joined authoritative state decides, so an
			// unresolved row is never labelled "Archived — may be kept or removed".
			$rows[ $id ] = [
				'label'    => self::component_label( $id, $component ),
				'archived' => PackageService::STATUS_ARCHIVED === ( $component['play_product_status'] ?? null ),
			];
		}
		?>
		<table class="widefat striped" style="max-width:52em">
			<thead>
				<tr>
					<th style="width:4em"><?php echo esc_html__( 'Include', 'bingocommerce-catalog' ); ?></th>
					<th><?php echo esc_html__( 'Bingo Play Product', 'bingocommerce-catalog' ); ?></th>
					<th style="width:9em"><?php echo esc_html__( 'Quantity', 'bingocommerce-catalog' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $id => $row ) : ?>
					<?php $selected = isset( $current[ $id ] ); ?>
					<tr>
						<td><input type="checkbox" name="components[<?php echo esc_attr( (string) $id ); ?>][include]" value="1" <?php checked( $selected ); ?>></td>
						<td>
							<?php echo esc_html( $row['label'] ); ?>
							<?php if ( $row['archived'] ) : ?>
								<br><em><?php echo esc_html__( 'Archived — may be kept at its current quantity or removed.', 'bingocommerce-catalog' ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<input type="number" min="1" step="1"
								name="components[<?php echo esc_attr( (string) $id ); ?>][quantity]"
								value="<?php echo esc_attr( (string) ( $selected ? (int) $current[ $id ]['quantity'] : 1 ) ); ?>">
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Request handling
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $post
	 * @return PackageResult|\WP_Error
	 */
	private static function handle_request( array $post, PackageService $service ) {
		$action     = sanitize_key( $post['bc_package_action'] ?? '' );
		$package_id = self::request_int( $post['package_id'] ?? null );

		switch ( $action ) {
			case 'create':
				return $service->create( self::request_int( $post['wc_product_id'] ?? null ), self::components_from_request( $post ) );

			case 'replace_components':
				$fingerprint = (string) ( $post['expected_fingerprint'] ?? '' );
				if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $fingerprint ) ) {
					return new \WP_Error( 'package_composition_stale', __( 'The composition form was not submitted with a valid fingerprint. Reload and try again.', 'bingocommerce-catalog' ) );
				}

				return $service->replaceComponents( $package_id, $fingerprint, self::components_from_request( $post ) );

			case 'archive':
				return $service->archive( $package_id );

			case 'restore':
				return $service->restore( $package_id );
		}

		return new \WP_Error( 'unsupported_action', __( 'That Bingo Package action is not supported.', 'bingocommerce-catalog' ) );
	}

	/**
	 * Marshal the checkbox/quantity grid into the service's component shape.
	 *
	 * `sort_order` is never accepted from the request: no reorder surface exists
	 * in Increment 1, and exposing it would be an unreviewed mutation.
	 *
	 * @param array<string,mixed> $post
	 * @return array<int,array{bingo_play_product_id:int,quantity:int}>
	 */
	private static function components_from_request( array $post ): array {
		$raw = $post['components'] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$components = [];
		foreach ( $raw as $id => $fields ) {
			if ( ! is_array( $fields ) || empty( $fields['include'] ) ) {
				continue;
			}

			// NOT absint(). Absolute value would turn a crafted -5 into a valid 5
			// and silently mutate the Hall Manager's Package with business input
			// nobody submitted. An invalid value is passed through unchanged so
			// PackageService rejects it deterministically.
			$components[] = [
				'bingo_play_product_id' => self::request_int( $id ),
				'quantity'              => self::request_int( $fields['quantity'] ?? null ),
			];
		}

		return $components;
	}

	/**
	 * Parse a request value as an integer WITHOUT changing what it means.
	 *
	 * `absint()` is deliberately not used for identities or business quantities:
	 * it maps -5 to 5, converting invalid input into a different, valid-looking
	 * mutation. Anything that is not a plain optionally-signed integer becomes 0,
	 * which every domain validator already rejects; a negative stays negative so
	 * it reaches the same deterministic rejection.
	 */
	private static function request_int( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d+\s*$/', $value ) ) {
			return (int) trim( $value );
		}

		return 0;
	}

	/* ------------------------------------------------------------------ *
	 * Presentation helpers
	 * ------------------------------------------------------------------ */

	/** @return array<int,string> */
	private static function linkable_products( PackageService $service ): array {
		$ids = $service->selectableWooProductIds( self::PRODUCT_CHOICE_LIMIT );
		if ( is_wp_error( $ids ) ) {
			return [];
		}

		$choices = [];
		foreach ( $ids as $id ) {
			$choices[ (int) $id ] = self::product_label( (int) $id );
		}

		return $choices;
	}

	private static function product_label( int $wc_product_id ): string {
		$title = get_the_title( $wc_product_id );

		return '' === (string) $title
			? sprintf( __( 'Product #%d', 'bingocommerce-catalog' ), $wc_product_id )
			: sprintf( '%s (#%d)', $title, $wc_product_id );
	}

	/**
	 * Paper is NEVER inferred from "not digital". A medium that is neither
	 * exactly `paper` nor exactly `digital` is a Catalog integrity fault and is
	 * labelled as one.
	 */
	private static function medium_label( $medium ): string {
		if ( PackageService::MEDIUM_DIGITAL === $medium ) {
			return __( 'Digital', 'bingocommerce-catalog' );
		}
		if ( PackageService::MEDIUM_PAPER === $medium ) {
			return __( 'Paper', 'bingocommerce-catalog' );
		}

		return __( 'UNRECOGNISED MEDIUM', 'bingocommerce-catalog' );
	}

	private static function has_valid_medium( $medium ): bool {
		return in_array( $medium, [ PackageService::MEDIUM_PAPER, PackageService::MEDIUM_DIGITAL ], true );
	}

	/** @param array<string,mixed> $play_product */
	private static function play_product_label( array $play_product ): string {
		return sprintf(
			/* translators: 1: product name, 2: medium, 3: faces per unit */
			__( '%1$s — %2$s, %3$d faces per unit', 'bingocommerce-catalog' ),
			self::product_label( (int) $play_product['wc_product_id'] ),
			self::medium_label( $play_product['medium'] ?? null ),
			(int) $play_product['faces_per_unit']
		);
	}

	/**
	 * Label a component that is not in the ACTIVE selection list, using its own
	 * authoritative joined state rather than that absence.
	 *
	 * @param array<string,mixed> $component
	 */
	private static function component_label( int $id, array $component ): string {
		$status = $component['play_product_status'] ?? null;
		if ( null === $status || null === $component['medium'] ) {
			return sprintf( __( 'Bingo Play Product #%d — UNRESOLVED, no authoritative record exists', 'bingocommerce-catalog' ), $id );
		}
		if ( ! in_array( $status, [ PackageService::STATUS_ACTIVE, PackageService::STATUS_ARCHIVED ], true ) ) {
			return sprintf( __( 'Bingo Play Product #%d — UNRESOLVED, unrecognised lifecycle value', 'bingocommerce-catalog' ), $id );
		}
		if ( ! self::has_valid_medium( $component['medium'] ) ) {
			return sprintf( __( 'Bingo Play Product #%d — INTEGRITY FAULT, unrecognised medium', 'bingocommerce-catalog' ), $id );
		}

		return sprintf(
			/* translators: 1: product name, 2: medium */
			__( '%1$s — %2$s', 'bingocommerce-catalog' ),
			self::product_label( (int) ( $component['play_product_wc_id'] ?? 0 ) ),
			self::medium_label( $component['medium'] )
		);
	}

	/** Read-only detail for a Package whose composition cannot be edited. @param array<string,mixed> $package */
	private static function render_unresolved_components( array $package ): void {
		?>
		<table class="widefat striped" style="max-width:52em">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Bingo Play Product', 'bingocommerce-catalog' ); ?></th>
					<th style="width:9em"><?php echo esc_html__( 'Quantity', 'bingocommerce-catalog' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $package['components'] as $component ) : ?>
					<tr>
						<td><?php echo esc_html( self::component_label( (int) $component['bingo_play_product_id'], $component ) ); ?></td>
						<td><?php echo esc_html( (string) (int) $component['quantity'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string,mixed> $package */
	private static function composition_summary( array $package ): string {
		$parts = [];
		foreach ( $package['components'] as $component ) {
			$parts[] = sprintf(
				'%s x%d',
				self::product_label( (int) ( $component['play_product_wc_id'] ?? 0 ) ),
				(int) $component['quantity']
			);
		}

		return [] === $parts ? __( '—', 'bingocommerce-catalog' ) : implode( ', ', $parts );
	}

	private static function type_label( string $type ): string {
		$labels = [
			PackageService::TYPE_PAPER   => __( 'Paper', 'bingocommerce-catalog' ),
			PackageService::TYPE_DIGITAL => __( 'Digital', 'bingocommerce-catalog' ),
			PackageService::TYPE_HYBRID  => __( 'Hybrid', 'bingocommerce-catalog' ),
		];

		return $labels[ $type ] ?? $type;
	}

	private static function is_known_status( string $status ): bool {
		return in_array( $status, [ PackageService::STATUS_ACTIVE, PackageService::STATUS_ARCHIVED ], true );
	}

	/**
	 * Three explicit cases. An unrecognised value is reported as inconsistent
	 * state, NEVER coerced into Archived.
	 */
	private static function status_label( string $status ): string {
		if ( PackageService::STATUS_ACTIVE === $status ) {
			return __( 'Active', 'bingocommerce-catalog' );
		}
		if ( PackageService::STATUS_ARCHIVED === $status ) {
			return __( 'Archived', 'bingocommerce-catalog' );
		}

		return __( 'Inconsistent state', 'bingocommerce-catalog' );
	}

	private static function woo_state_label( string $state ): string {
		$labels = [
			'missing'     => __( 'The linked WooCommerce product no longer exists.', 'bingocommerce-catalog' ),
			'trashed'     => __( 'The linked WooCommerce product is trashed or an unsaved draft.', 'bingocommerce-catalog' ),
			'unsupported' => __( 'The linked WooCommerce product is no longer a supported simple product.', 'bingocommerce-catalog' ),
			'unknown'     => __( 'WooCommerce is unavailable, so the linked product could not be read.', 'bingocommerce-catalog' ),
		];

		return $labels[ $state ] ?? $state;
	}

	private static function render_notice( $result ): void {
		if ( null === $result ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( self::error_message( $result ) ); ?></p></div>
			<?php
			return;
		}

		$messages = [
			'package_created'                => __( 'Bingo Package created.', 'bingocommerce-catalog' ),
			'package_composition_updated'    => __( 'Composition updated. The Package type was rederived from it.', 'bingocommerce-catalog' ),
			'package_composition_unchanged'  => __( 'The composition was unchanged.', 'bingocommerce-catalog' ),
			'package_archived'               => __( 'Bingo Package archived.', 'bingocommerce-catalog' ),
			'package_restored'               => __( 'Bingo Package restored.', 'bingocommerce-catalog' ),
			'package_already_archived'       => __( 'The Bingo Package is already archived.', 'bingocommerce-catalog' ),
			'package_already_active'         => __( 'The Bingo Package is already active.', 'bingocommerce-catalog' ),
		];
		?>
		<div class="notice notice-success"><p><?php echo esc_html( $messages[ $result->status ] ?? __( 'Bingo Package updated.', 'bingocommerce-catalog' ) ); ?></p></div>
		<?php foreach ( $result->warnings as $warning ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html( self::warning_message( (string) ( $warning['code'] ?? '' ) ) ); ?></p></div>
		<?php endforeach; ?>
		<?php
	}

	private static function warning_message( string $code ): string {
		$messages = [
			'audit_write_failed'      => __( 'The Bingo Package change succeeded, but its audit event could not be recorded. Contact Platform Admin.', 'bingocommerce-catalog' ),
			'projection_write_failed' => __( 'The Bingo Package was saved, but its WooCommerce classification metadata could not be written. Contact Platform Admin before selling it.', 'bingocommerce-catalog' ),
			'state_read_failed'       => __( 'The Bingo Package change succeeded, but its updated state could not be read back. Reload this screen.', 'bingocommerce-catalog' ),
		];

		return $messages[ $code ] ?? __( 'The Bingo Package change succeeded with a warning. Reload this screen.', 'bingocommerce-catalog' );
	}

	/**
	 * Localization happens HERE, never inside a Catalog transaction: the
	 * transport builds callback-free errors on purpose.
	 */
	public static function error_message( \WP_Error $error ): string {
		$messages = [
			'package_management_denied'                        => __( 'You are not authorized to manage Bingo Packages for this hall.', 'bingocommerce-catalog' ),
			'invalid_hall_context'                             => __( 'Bingo Packages may be managed only from a hall site.', 'bingocommerce-catalog' ),
			'catalog_schema_unavailable'                       => __( 'This hall\'s Catalog schema is not ready. Contact Platform Admin.', 'bingocommerce-catalog' ),
			'package_not_found'                                => __( 'The Bingo Package was not found.', 'bingocommerce-catalog' ),
			'package_already_linked'                           => __( 'That WooCommerce product is already linked to a Bingo Package.', 'bingocommerce-catalog' ),
			'play_product_claim_exists'                        => __( 'That WooCommerce product is already a Bingo Play Product and cannot also be a Package.', 'bingocommerce-catalog' ),
			'package_requires_component'                       => __( 'A Bingo Package must contain at least one Bingo Play Product.', 'bingocommerce-catalog' ),
			'duplicate_component'                              => __( 'Each Bingo Play Product may appear only once in a Package.', 'bingocommerce-catalog' ),
			'invalid_component_quantity'                       => __( 'Each quantity must be a whole number of at least 1.', 'bingocommerce-catalog' ),
			'invalid_component_set'                            => __( 'The composition you submitted could not be read. Reload and try again.', 'bingocommerce-catalog' ),
			'play_product_not_found'                           => __( 'A selected Bingo Play Product was not found in this hall.', 'bingocommerce-catalog' ),
			'play_product_not_active'                          => __( 'An archived Bingo Play Product cannot be added, and its quantity cannot be changed. Restore it first.', 'bingocommerce-catalog' ),
			'package_archived'                                 => __( 'Restore the Bingo Package before editing its composition.', 'bingocommerce-catalog' ),
			'invalid_wc_product_id'                            => __( 'Choose a WooCommerce product for this Bingo Package.', 'bingocommerce-catalog' ),
			'woo_product_missing'                              => __( 'The linked WooCommerce product no longer exists in this hall.', 'bingocommerce-catalog' ),
			'woo_product_trashed'                              => __( 'The linked WooCommerce product is in the trash or is an unsaved draft.', 'bingocommerce-catalog' ),
			'woo_product_unsupported'                          => __( 'Only top-level simple WooCommerce products are supported in this release.', 'bingocommerce-catalog' ),
			'woocommerce_unavailable'                          => __( 'WooCommerce is not available for this hall.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::COMPOSITION_STALE     => __( 'The composition changed while you were editing it. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::COMMIT_OUTCOME_UNKNOWN => __( 'The change was submitted but its result could not be confirmed. Reload this screen and check the current state before trying again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::WRITE_CONFLICT        => __( 'The change could not be saved because this hall was busy. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::STATE_CHANGED         => __( 'The Bingo Package changed while you were editing it. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::STATE_UNTRUSTED       => __( 'The database session could not be verified, so the change was not attempted. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::NESTED_USE            => __( 'Another Catalog change is already in progress. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::SCHEMA_INCONSISTENT   => __( 'This hall\'s Catalog state is inconsistent. Contact Platform Admin.', 'bingocommerce-catalog' ),
			'catalog_transport_plan_mismatch'                  => __( 'The change could not be prepared. Reload and try again.', 'bingocommerce-catalog' ),
			'catalog_identifier_rejected'                      => __( 'This hall\'s Catalog table naming is unsupported. Contact Platform Admin.', 'bingocommerce-catalog' ),
			'persistence_failed'                               => __( 'The Bingo Package change could not be saved.', 'bingocommerce-catalog' ),
		];

		return $messages[ $error->get_error_code() ] ?? __( 'The Bingo Package change could not be completed.', 'bingocommerce-catalog' );
	}
}
