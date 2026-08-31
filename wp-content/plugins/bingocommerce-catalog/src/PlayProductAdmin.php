<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal current-hall WordPress administration for Bingo Play Products.
 *
 * Presentation and request marshalling only. Every domain rule — medium
 * transitions, Game Type validity, WooCommerce eligibility, lifecycle — lives in
 * BingoPlayProductService and is never re-implemented here.
 */
final class PlayProductAdmin {

	private const PAGE_SLUG = 'bingocommerce-play-products';
	private const NONCE_ACTION = 'bingocommerce_play_product_mutation';

	/** Upper bound on the create form's product selector. */
	private const PRODUCT_CHOICE_LIMIT = 200;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
	}

	public static function add_menu(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Bingo Play Products', 'bingocommerce-catalog' ),
			__( 'Bingo Play Products', 'bingocommerce-catalog' ),
			'bc_manage_products',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! HallContext::is_hall() || ( ! is_super_admin() && ! current_user_can( 'bc_manage_products' ) ) ) {
			wp_die( esc_html__( 'You are not authorized to manage Bingo Play Products for this hall.', 'bingocommerce-catalog' ) );
		}

		$service   = new BingoPlayProductService();
		$game_type = new GameTypeService();
		$result    = null;

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$result = self::handle_request( wp_unslash( $_POST ), $service );
		}

		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) );
		if ( ! in_array( $status, [ 'all', 'active', 'archived' ], true ) ) {
			$status = 'all';
		}

		$play_products = $service->listForAdministration( $status );
		$active_types  = $game_type->listActiveForPlayProductSelection();
		$active_types  = is_wp_error( $active_types ) ? [] : $active_types;
		$type_names    = self::game_type_names( $game_type );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bingo Play Products', 'bingocommerce-catalog' ); ?></h1>
			<p><?php echo esc_html__( 'Bingo Play Products are the package-building components of this hall\'s catalog. They are not sold to customers on their own.', 'bingocommerce-catalog' ); ?></p>

			<?php self::render_notice( $result ); ?>

			<h2><?php echo esc_html__( 'Link a WooCommerce Product', 'bingocommerce-catalog' ); ?></h2>
			<?php $choices = self::linkable_products( $service ); ?>
			<?php if ( [] === $choices ) : ?>
				<p><?php echo esc_html__( 'No eligible simple WooCommerce products are available to link. Create a simple product first.', 'bingocommerce-catalog' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<input type="hidden" name="bc_play_product_action" value="create">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bc-pp-product"><?php echo esc_html__( 'WooCommerce product', 'bingocommerce-catalog' ); ?></label></th>
							<td>
								<select id="bc-pp-product" name="wc_product_id" required>
									<?php foreach ( $choices as $id => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'The linked product cannot be changed after the Bingo Play Product is created.', 'bingocommerce-catalog' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bc-pp-medium"><?php echo esc_html__( 'Medium', 'bingocommerce-catalog' ); ?></label></th>
							<td>
								<select id="bc-pp-medium" name="medium">
									<option value="paper"><?php echo esc_html__( 'Paper', 'bingocommerce-catalog' ); ?></option>
									<option value="digital"><?php echo esc_html__( 'Digital', 'bingocommerce-catalog' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bc-pp-faces"><?php echo esc_html__( 'Faces per unit', 'bingocommerce-catalog' ); ?></label></th>
							<td><input id="bc-pp-faces" type="number" name="faces_per_unit" min="1" step="1" value="1" required></td>
						</tr>
						<tr>
							<th scope="row"><label for="bc-pp-game-type"><?php echo esc_html__( 'Game Type', 'bingocommerce-catalog' ); ?></label></th>
							<td>
								<select id="bc-pp-game-type" name="game_type_id">
									<option value="0"><?php echo esc_html__( '— None (Paper only) —', 'bingocommerce-catalog' ); ?></option>
									<?php foreach ( $active_types as $type ) : ?>
										<option value="<?php echo esc_attr( (string) $type['game_type_id'] ); ?>"><?php echo esc_html( $type['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'Digital requires one active Game Type. Paper may have one, but never needs one.', 'bingocommerce-catalog' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Create Bingo Play Product', 'bingocommerce-catalog' ) ); ?>
				</form>
			<?php endif; ?>

			<h2 class="screen-reader-text"><?php echo esc_html__( 'Filter Bingo Play Products', 'bingocommerce-catalog' ); ?></h2>
			<ul class="subsubsub">
				<?php foreach ( [ 'all' => __( 'All', 'bingocommerce-catalog' ), 'active' => __( 'Active', 'bingocommerce-catalog' ), 'archived' => __( 'Archived', 'bingocommerce-catalog' ) ] as $key => $label ) : ?>
					<li>
						<a <?php echo $status === $key ? 'class="current" aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'page' => self::PAGE_SLUG, 'status' => $key ], admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php echo 'archived' === $key ? '' : ' | '; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<br class="clear">

			<?php if ( is_wp_error( $play_products ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $play_products->get_error_message() ); ?></p></div>
			<?php elseif ( [] === $play_products ) : ?>
				<p><?php echo esc_html__( 'No Bingo Play Products match this filter.', 'bingocommerce-catalog' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Linked product', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Medium', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Faces per unit', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Game Type', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Lifecycle', 'bingocommerce-catalog' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $play_products as $row ) : ?>
						<?php $id = (int) $row['bingo_play_product_id']; ?>
						<tr>
							<td>
								<strong><?php echo esc_html( self::product_label( (int) $row['wc_product_id'] ) ); ?></strong><br>
								<code>#<?php echo esc_html( (string) $row['wc_product_id'] ); ?></code>
								<?php if ( 'ok' !== $row['woo_state'] ) : ?>
									<br><span class="dashicons dashicons-warning" aria-hidden="true"></span>
									<em><?php echo esc_html( self::woo_state_label( (string) $row['woo_state'] ) ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="bc_play_product_action" value="update_medium">
									<input type="hidden" name="bingo_play_product_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<label class="screen-reader-text" for="bc-pp-medium-<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Medium', 'bingocommerce-catalog' ); ?></label>
									<select id="bc-pp-medium-<?php echo esc_attr( (string) $id ); ?>" name="medium">
										<option value="paper" <?php selected( 'paper', $row['medium'] ); ?>><?php echo esc_html__( 'Paper', 'bingocommerce-catalog' ); ?></option>
										<option value="digital" <?php selected( 'digital', $row['medium'] ); ?>><?php echo esc_html__( 'Digital', 'bingocommerce-catalog' ); ?></option>
									</select>
									<label class="screen-reader-text" for="bc-pp-medium-gt-<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Game Type for Digital', 'bingocommerce-catalog' ); ?></label>
									<select id="bc-pp-medium-gt-<?php echo esc_attr( (string) $id ); ?>" name="game_type_id">
										<option value="0"><?php echo esc_html__( '— Game Type for Digital —', 'bingocommerce-catalog' ); ?></option>
										<?php foreach ( $active_types as $type ) : ?>
											<option value="<?php echo esc_attr( (string) $type['game_type_id'] ); ?>" <?php selected( (int) $type['game_type_id'], (int) ( $row['game_type_id'] ?? 0 ) ); ?>><?php echo esc_html( $type['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php submit_button( __( 'Save medium', 'bingocommerce-catalog' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
							<td>
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="bc_play_product_action" value="update_faces">
									<input type="hidden" name="bingo_play_product_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<label class="screen-reader-text" for="bc-pp-faces-<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Faces per unit', 'bingocommerce-catalog' ); ?></label>
									<input id="bc-pp-faces-<?php echo esc_attr( (string) $id ); ?>" type="number" name="faces_per_unit" min="1" step="1" value="<?php echo esc_attr( (string) $row['faces_per_unit'] ); ?>" required>
									<?php submit_button( __( 'Save', 'bingocommerce-catalog' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
							<td>
								<?php
								$current_type_id = (int) ( $row['game_type_id'] ?? 0 );
								$is_archived_ref = $current_type_id > 0 && ! isset( self::active_ids( $active_types )[ $current_type_id ] );
								?>
								<?php if ( $is_archived_ref ) : ?>
									<p>
										<?php
										/* translators: %s: Game Type name. */
										echo esc_html( sprintf( __( 'Currently references the archived Game Type "%s". It stays linked until you choose another.', 'bingocommerce-catalog' ), $type_names[ $current_type_id ] ?? (string) $current_type_id ) );
										?>
									</p>
								<?php endif; ?>
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="bc_play_product_action" value="assign_game_type">
									<input type="hidden" name="bingo_play_product_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<label class="screen-reader-text" for="bc-pp-gt-<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html__( 'Game Type', 'bingocommerce-catalog' ); ?></label>
									<select id="bc-pp-gt-<?php echo esc_attr( (string) $id ); ?>" name="game_type_id">
										<option value="0"><?php echo esc_html__( '— Select an active Game Type —', 'bingocommerce-catalog' ); ?></option>
										<?php foreach ( $active_types as $type ) : ?>
											<option value="<?php echo esc_attr( (string) $type['game_type_id'] ); ?>" <?php selected( (int) $type['game_type_id'], $current_type_id ); ?>><?php echo esc_html( $type['name'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php submit_button( __( 'Assign', 'bingocommerce-catalog' ), 'secondary small', 'submit', false ); ?>
								</form>
								<?php if ( 'paper' === $row['medium'] && $current_type_id > 0 ) : ?>
									<form method="post">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="bc_play_product_action" value="clear_game_type">
										<input type="hidden" name="bingo_play_product_id" value="<?php echo esc_attr( (string) $id ); ?>">
										<?php submit_button( __( 'Clear Game Type', 'bingocommerce-catalog' ), 'link-delete small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( 'active' === $row['status'] ? __( 'Active', 'bingocommerce-catalog' ) : __( 'Archived', 'bingocommerce-catalog' ) ); ?></td>
							<td>
								<form method="post"<?php echo 'active' === $row['status'] ? ' onsubmit="return window.confirm(\'' . esc_js( __( 'Archive this Bingo Play Product? Existing references are preserved, but it will no longer be available for new Package selection.', 'bingocommerce-catalog' ) ) . '\');"' : ''; ?>>
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="bc_play_product_action" value="<?php echo esc_attr( 'active' === $row['status'] ? 'archive' : 'restore' ); ?>">
									<input type="hidden" name="bingo_play_product_id" value="<?php echo esc_attr( (string) $id ); ?>">
									<?php submit_button( 'active' === $row['status'] ? __( 'Archive', 'bingocommerce-catalog' ) : __( 'Restore', 'bingocommerce-catalog' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @return PlayProductResult|\WP_Error */
	public static function handle_request( array $posted, ?BingoPlayProductService $service = null ) {
		foreach ( [ 'blog_id', 'actor_user_id', 'status', 'created_at', 'archived_at' ] as $reserved ) {
			if ( array_key_exists( $reserved, $posted ) ) {
				return new \WP_Error(
					'unexpected_scope_field',
					__( 'The Bingo Play Product request contains a reserved scope or identity field.', 'bingocommerce-catalog' )
				);
			}
		}

		$service      = $service ?? new BingoPlayProductService();
		$action       = sanitize_key( $posted['bc_play_product_action'] ?? '' );
		$id           = absint( $posted['bingo_play_product_id'] ?? 0 );
		$product_id   = absint( $posted['wc_product_id'] ?? 0 );
		$faces        = absint( $posted['faces_per_unit'] ?? 0 );
		$medium       = is_string( $posted['medium'] ?? null ) ? $posted['medium'] : '';
		$game_type_id = absint( $posted['game_type_id'] ?? 0 );
		$game_type    = $game_type_id > 0 ? $game_type_id : null;

		switch ( $action ) {
			case 'create':
				return $service->create( $product_id, $medium, $faces, $game_type );
			case 'update_medium':
				// Paper never carries a Game Type argument; the service rejects one.
				return $service->updateMedium( $id, $medium, 'digital' === strtolower( trim( $medium ) ) ? $game_type : null );
			case 'update_faces':
				return $service->updateFacesPerUnit( $id, $faces );
			case 'assign_game_type':
				return $game_type_id > 0
					? $service->assignGameType( $id, $game_type_id )
					: new \WP_Error( 'invalid_game_type_id', __( 'Select a Game Type to assign.', 'bingocommerce-catalog' ) );
			case 'clear_game_type':
				return $service->clearGameType( $id );
			case 'archive':
				return $service->archive( $id );
			case 'restore':
				return $service->restore( $id );
			default:
				return new \WP_Error( 'invalid_action', __( 'The Bingo Play Product action is invalid.', 'bingocommerce-catalog' ) );
		}
	}

	/**
	 * Current-site simple products not claimed by either Catalog identity.
	 *
	 * @return array<int,string>
	 */
	private static function linkable_products( BingoPlayProductService $service ): array {
		$ids = $service->selectableWooProductIds( self::PRODUCT_CHOICE_LIMIT );
		if ( is_wp_error( $ids ) ) {
			return [];
		}

		$choices = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$choices[ $id ] = self::product_label( $id );
		}

		return $choices;
	}

	private static function product_label( int $wc_product_id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_product_id ) : null;

		return $product instanceof \WC_Product
			? sprintf( '%s (#%d)', $product->get_name(), $wc_product_id )
			: sprintf( '#%d', $wc_product_id );
	}

	private static function woo_state_label( string $state ): string {
		switch ( $state ) {
			case 'missing':
				return __( 'The linked WooCommerce product no longer exists. The Bingo Play Product record is preserved.', 'bingocommerce-catalog' );
			case 'trashed':
				return __( 'The linked WooCommerce product is in the trash. The Bingo Play Product record is preserved.', 'bingocommerce-catalog' );
			case 'unsupported_type':
				return __( 'The linked WooCommerce product is no longer a top-level simple product. The Bingo Play Product record is preserved.', 'bingocommerce-catalog' );
			default:
				return __( 'The linked WooCommerce product could not be inspected.', 'bingocommerce-catalog' );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $active_types
	 * @return array<int,true>
	 */
	private static function active_ids( array $active_types ): array {
		$ids = [];
		foreach ( $active_types as $type ) {
			$ids[ (int) $type['game_type_id'] ] = true;
		}

		return $ids;
	}

	/** @return array<int,string> */
	private static function game_type_names( GameTypeService $game_type ): array {
		$all = $game_type->listForAdministration( 'all', 1, 100 );
		if ( is_wp_error( $all ) ) {
			return [];
		}

		$names = [];
		foreach ( $all as $type ) {
			$names[ (int) $type['game_type_id'] ] = (string) $type['name'];
		}

		return $names;
	}

	/**
	 * Localize an error for display.
	 *
	 * CatalogTransactionTransport deliberately builds its errors without __(),
	 * because translate() fires the `gettext` filters and ADR-032 forbids
	 * callbacks inside the critical transaction. Translation therefore happens
	 * here, in the presentation layer, once the boundary has closed.
	 */
	public static function error_message( \WP_Error $error ): string {
		$messages = [
			CatalogTransactionTransport::COMMIT_OUTCOME_UNKNOWN => __( 'The change was submitted but its result could not be confirmed. Reload this screen and check the current state before trying again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::WRITE_CONFLICT         => __( 'The change could not be saved because this hall was busy. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::STATE_CHANGED          => __( 'The Bingo Play Product changed while you were editing it. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::STATE_UNTRUSTED        => __( 'The database session could not be verified, so the change was not attempted. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::NESTED_USE             => __( 'Another Catalog change is already in progress. Reload and try again.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::SCHEMA_INCONSISTENT    => __( 'This hall\'s Catalog schema is inconsistent. Contact Platform Admin.', 'bingocommerce-catalog' ),
			'play_product_already_linked'                       => __( 'That WooCommerce product is already a Bingo Play Product.', 'bingocommerce-catalog' ),
			'package_claim_exists'                              => __( 'That WooCommerce product is already linked to a Bingo Package.', 'bingocommerce-catalog' ),
			'play_product_not_found'                            => __( 'The Bingo Play Product was not found.', 'bingocommerce-catalog' ),
			'game_type_not_found'                               => __( 'The selected Game Type was not found in this hall.', 'bingocommerce-catalog' ),
			'game_type_not_active'                              => __( 'The selected Game Type is archived and cannot be assigned.', 'bingocommerce-catalog' ),
			'woo_product_missing'                               => __( 'The linked WooCommerce product no longer exists in this hall.', 'bingocommerce-catalog' ),
			'woo_product_trashed'                               => __( 'The linked WooCommerce product is in the trash or is an unsaved draft.', 'bingocommerce-catalog' ),
			'woo_product_unsupported'                           => __( 'Only top-level simple WooCommerce products are supported in this release.', 'bingocommerce-catalog' ),
			CatalogTransactionTransport::PACKAGE_REFERENCED     => __( 'This Bingo Play Product is used by one or more Bingo Packages, so its medium cannot change. Remove it from those Packages first.', 'bingocommerce-catalog' ),
			'catalog_identifier_rejected'                       => __( 'This hall\'s Catalog table naming is unsupported. Contact Platform Admin.', 'bingocommerce-catalog' ),
			'persistence_failed'                                => __( 'The Bingo Play Product change could not be saved.', 'bingocommerce-catalog' ),
		];

		// Service-level errors are already translated at their source, outside any
		// transaction, so their own message is the fallback.
		return $messages[ $error->get_error_code() ] ?? $error->get_error_message();
	}

	/** @param PlayProductResult|\WP_Error|null $result */
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
			'play_product_created'             => __( 'Bingo Play Product created.', 'bingocommerce-catalog' ),
			'play_product_medium_changed'      => __( 'Medium updated.', 'bingocommerce-catalog' ),
			'play_product_faces_updated'       => __( 'Faces per unit updated.', 'bingocommerce-catalog' ),
			'play_product_game_type_assigned'  => __( 'Game Type assigned.', 'bingocommerce-catalog' ),
			'play_product_game_type_cleared'   => __( 'Game Type cleared.', 'bingocommerce-catalog' ),
			'play_product_archived'            => __( 'Bingo Play Product archived.', 'bingocommerce-catalog' ),
			'play_product_restored'            => __( 'Bingo Play Product restored.', 'bingocommerce-catalog' ),
			'play_product_medium_unchanged'    => __( 'The medium was unchanged.', 'bingocommerce-catalog' ),
			'play_product_faces_unchanged'     => __( 'Faces per unit were unchanged.', 'bingocommerce-catalog' ),
			'play_product_game_type_unchanged' => __( 'The Game Type was unchanged.', 'bingocommerce-catalog' ),
			'play_product_already_archived'    => __( 'The Bingo Play Product is already archived.', 'bingocommerce-catalog' ),
			'play_product_already_active'      => __( 'The Bingo Play Product is already active.', 'bingocommerce-catalog' ),
		];
		?>
		<div class="notice notice-success"><p><?php echo esc_html( $messages[ $result->status ] ?? __( 'Bingo Play Product updated.', 'bingocommerce-catalog' ) ); ?></p></div>
		<?php foreach ( $result->warnings as $warning ) : ?>
			<?php if ( 'audit_write_failed' === ( $warning['code'] ?? '' ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'The Bingo Play Product change succeeded, but its audit event could not be recorded. Contact Platform Admin.', 'bingocommerce-catalog' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'The Bingo Play Product change succeeded, but its updated state could not be read back. Reload this screen.', 'bingocommerce-catalog' ); ?></p></div>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php
	}
}
