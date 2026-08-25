<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal current-hall WordPress administration for Game Types. */
final class GameTypeAdmin {

	private const PAGE_SLUG = 'bingocommerce-game-types';
	private const NONCE_ACTION = 'bingocommerce_game_type_mutation';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
	}

	public static function add_menu(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Game Types', 'bingocommerce-catalog' ),
			__( 'Game Types', 'bingocommerce-catalog' ),
			'bc_manage_products',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! HallContext::is_hall() || ( ! is_super_admin() && ! current_user_can( 'bc_manage_products' ) ) ) {
			wp_die( esc_html__( 'You are not authorized to manage Game Types for this hall.', 'bingocommerce-catalog' ) );
		}

		$service = new GameTypeService();
		$result  = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$result = self::handle_request( wp_unslash( $_POST ), $service );
		}

		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'all' ) );
		if ( ! in_array( $status, [ 'all', 'active', 'archived' ], true ) ) {
			$status = 'all';
		}
		$game_types = $service->listForAdministration( $status );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Game Types', 'bingocommerce-catalog' ); ?></h1>
			<p><?php echo esc_html__( 'Manage the hall-local Game Types used when building Bingo Play Products.', 'bingocommerce-catalog' ); ?></p>

			<?php self::render_notice( $result ); ?>

			<h2><?php echo esc_html__( 'Create Game Type', 'bingocommerce-catalog' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="bc_game_type_action" value="create">
				<label for="bc-game-type-name" class="screen-reader-text"><?php echo esc_html__( 'Game Type name', 'bingocommerce-catalog' ); ?></label>
				<input id="bc-game-type-name" class="regular-text" type="text" name="name" maxlength="160" required>
				<?php submit_button( __( 'Create Game Type', 'bingocommerce-catalog' ), 'primary', 'submit', false ); ?>
			</form>

			<h2 class="screen-reader-text"><?php echo esc_html__( 'Filter Game Types', 'bingocommerce-catalog' ); ?></h2>
			<ul class="subsubsub">
				<?php foreach ( [ 'all' => __( 'All', 'bingocommerce-catalog' ), 'active' => __( 'Active', 'bingocommerce-catalog' ), 'archived' => __( 'Archived', 'bingocommerce-catalog' ) ] as $key => $label ) : ?>
					<li>
						<a <?php echo $status === $key ? 'class="current" aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'product', 'page' => self::PAGE_SLUG, 'status' => $key ], admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php echo 'archived' === $key ? '' : ' | '; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<br class="clear">

			<?php if ( is_wp_error( $game_types ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $game_types->get_error_message() ); ?></p></div>
			<?php elseif ( [] === $game_types ) : ?>
				<p><?php echo esc_html__( 'No Game Types match this filter.', 'bingocommerce-catalog' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Name', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Code', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'bingocommerce-catalog' ); ?></th>
							<th><?php echo esc_html__( 'Actions', 'bingocommerce-catalog' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $game_types as $game_type ) : ?>
						<tr>
							<td>
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="bc_game_type_action" value="rename">
									<input type="hidden" name="game_type_id" value="<?php echo esc_attr( (string) $game_type['game_type_id'] ); ?>">
									<label class="screen-reader-text" for="bc-game-type-<?php echo esc_attr( (string) $game_type['game_type_id'] ); ?>"><?php echo esc_html__( 'Game Type name', 'bingocommerce-catalog' ); ?></label>
									<input id="bc-game-type-<?php echo esc_attr( (string) $game_type['game_type_id'] ); ?>" type="text" name="name" value="<?php echo esc_attr( $game_type['name'] ); ?>" maxlength="160" required>
									<?php submit_button( __( 'Rename', 'bingocommerce-catalog' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
							<td><code><?php echo esc_html( $game_type['code'] ); ?></code></td>
							<td><?php echo esc_html( 'active' === $game_type['status'] ? __( 'Active', 'bingocommerce-catalog' ) : __( 'Archived', 'bingocommerce-catalog' ) ); ?></td>
							<td>
								<form method="post"<?php echo 'active' === $game_type['status'] ? ' onsubmit="return window.confirm(\'' . esc_js( __( 'Archive this Game Type? Existing references are preserved, but it will no longer be available for new Play Product selection.', 'bingocommerce-catalog' ) ) . '\');"' : ''; ?>>
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="bc_game_type_action" value="<?php echo esc_attr( 'active' === $game_type['status'] ? 'archive' : 'restore' ); ?>">
									<input type="hidden" name="game_type_id" value="<?php echo esc_attr( (string) $game_type['game_type_id'] ); ?>">
									<?php submit_button( 'active' === $game_type['status'] ? __( 'Archive', 'bingocommerce-catalog' ) : __( 'Restore', 'bingocommerce-catalog' ), 'secondary small', 'submit', false ); ?>
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

	/** @return GameTypeResult|\WP_Error */
	public static function handle_request( array $posted, ?GameTypeService $service = null ) {
		foreach ( [ 'blog_id', 'actor_user_id', 'code' ] as $reserved ) {
			if ( array_key_exists( $reserved, $posted ) ) {
				return new \WP_Error(
					'unexpected_scope_field',
					__( 'The Game Type request contains a reserved scope or identity field.', 'bingocommerce-catalog' )
				);
			}
		}

		$service = $service ?? new GameTypeService();
		$action  = sanitize_key( $posted['bc_game_type_action'] ?? '' );
		$name    = is_string( $posted['name'] ?? null ) ? $posted['name'] : '';
		$id      = absint( $posted['game_type_id'] ?? 0 );

		switch ( $action ) {
			case 'create':
				return $service->create( $name );
			case 'rename':
				return $service->rename( $id, $name );
			case 'archive':
				return $service->archive( $id );
			case 'restore':
				return $service->restore( $id );
			default:
				return new \WP_Error( 'invalid_action', __( 'The Game Type action is invalid.', 'bingocommerce-catalog' ) );
		}
	}

	/** @param GameTypeResult|\WP_Error|null $result */
	private static function render_notice( $result ): void {
		if ( null === $result ) {
			return;
		}
		if ( is_wp_error( $result ) ) {
			?>
			<div class="notice notice-error"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
			<?php
			return;
		}

		$messages = [
			'game_type_created'          => __( 'Game Type created.', 'bingocommerce-catalog' ),
			'game_type_renamed'          => __( 'Game Type renamed.', 'bingocommerce-catalog' ),
			'game_type_archived'         => __( 'Game Type archived.', 'bingocommerce-catalog' ),
			'game_type_restored'         => __( 'Game Type restored.', 'bingocommerce-catalog' ),
			'game_type_name_unchanged'   => __( 'The Game Type name was unchanged.', 'bingocommerce-catalog' ),
			'game_type_already_archived' => __( 'The Game Type is already archived.', 'bingocommerce-catalog' ),
			'game_type_already_active'   => __( 'The Game Type is already active.', 'bingocommerce-catalog' ),
		];
		?>
		<div class="notice notice-success"><p><?php echo esc_html( $messages[ $result->status ] ?? __( 'Game Type updated.', 'bingocommerce-catalog' ) ); ?></p></div>
		<?php foreach ( $result->warnings as $warning ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html__( 'The Game Type change succeeded, but its audit event could not be recorded. Contact Platform Admin.', 'bingocommerce-catalog' ); ?></p></div>
		<?php endforeach; ?>
		<?php
	}
}
