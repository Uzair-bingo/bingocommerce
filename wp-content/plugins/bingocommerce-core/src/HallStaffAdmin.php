<?php

namespace BingoCommerce\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Current-hall administration screen for the canonical Hall Staff service. */
final class HallStaffAdmin {

	private const PAGE_SLUG = 'bingocommerce-hall-staff';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
	}

	public static function add_menu(): void {
		if ( ! HallContext::is_hall() ) {
			return;
		}

		add_users_page(
			__( 'Hall Staff', 'bingocommerce-core' ),
			__( 'Hall Staff', 'bingocommerce-core' ),
			'bc_manage_hall_staff',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'bc_manage_hall_staff' ) || ! HallContext::is_hall() ) {
			wp_die( esc_html__( 'You are not authorized to manage Hall Staff for this hall.', 'bingocommerce-core' ) );
		}

		$result = null;
		$posted = [];

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			check_admin_referer( 'bingocommerce_hall_staff_mutation' );
			$posted = wp_unslash( $_POST );
			$result = self::handle_request( $posted );
		}

		$staff = HallStaffService::list_current_hall_staff();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Hall Staff', 'bingocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Manage existing network users for this hall only.', 'bingocommerce-core' ); ?></p>

			<?php self::render_notice( $result, $posted ); ?>

			<h2><?php echo esc_html__( 'Assign an existing user', 'bingocommerce-core' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'bingocommerce_hall_staff_mutation' ); ?>
				<input type="hidden" name="bc_hall_staff_action" value="assign">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bc-hall-staff-email"><?php echo esc_html__( 'Email address', 'bingocommerce-core' ); ?></label></th>
						<td><input class="regular-text" id="bc-hall-staff-email" type="email" name="email" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="bc-hall-staff-role"><?php echo esc_html__( 'Role', 'bingocommerce-core' ); ?></label></th>
						<td><?php self::role_select( 'bc-hall-staff-role', Roles::GAME_OPERATOR ); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Assign staff', 'bingocommerce-core' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Current hall staff', 'bingocommerce-core' ); ?></h2>
			<?php if ( is_wp_error( $staff ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $staff->get_error_message() ); ?></p></div>
			<?php elseif ( [] === $staff ) : ?>
				<p><?php echo esc_html__( 'No Hall Staff are assigned.', 'bingocommerce-core' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php echo esc_html__( 'Name', 'bingocommerce-core' ); ?></th><th><?php echo esc_html__( 'Email', 'bingocommerce-core' ); ?></th><th><?php echo esc_html__( 'Role', 'bingocommerce-core' ); ?></th><th><?php echo esc_html__( 'Actions', 'bingocommerce-core' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $staff as $member ) : ?>
						<tr>
							<td><?php echo esc_html( $member['display_name'] ); ?></td>
							<td><?php echo esc_html( $member['email'] ); ?></td>
							<td><?php echo esc_html( self::role_label( $member['role'] ) ); ?></td>
							<td>
								<form method="post" style="display:inline-block;margin-right:8px">
									<?php wp_nonce_field( 'bingocommerce_hall_staff_mutation' ); ?>
									<input type="hidden" name="bc_hall_staff_action" value="change">
									<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $member['id'] ); ?>">
									<?php self::role_select( 'bc-role-' . $member['id'], $member['role'] ); ?>
									<?php submit_button( __( 'Change role', 'bingocommerce-core' ), 'secondary small', 'submit', false ); ?>
								</form>
								<form method="post" style="display:inline-block">
									<?php wp_nonce_field( 'bingocommerce_hall_staff_mutation' ); ?>
									<input type="hidden" name="bc_hall_staff_action" value="remove">
									<input type="hidden" name="target_user_id" value="<?php echo esc_attr( (string) $member['id'] ); ?>">
									<?php submit_button( __( 'Remove', 'bingocommerce-core' ), 'delete small', 'submit', false ); ?>
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

	/**
	 * Validate and dispatch one nonce-verified Hall Staff form payload.
	 *
	 * Scope and identity always come from WordPress server context. Reserved
	 * client fields are rejected before HallStaffService is called.
	 *
	 * @param array<string,mixed> $posted
	 * @return HallStaffResult|\WP_Error
	 */
	public static function handle_request( array $posted ) {
		foreach ( [ 'blog_id', 'actor_user_id', 'operation_id' ] as $reserved ) {
			if ( array_key_exists( $reserved, $posted ) ) {
				return new \WP_Error(
					'unexpected_scope_field',
					__( 'The Hall Staff request contains a reserved scope or identity field.', 'bingocommerce-core' )
				);
			}
		}

		$action = sanitize_key( $posted['bc_hall_staff_action'] ?? '' );
		$token  = isset( $posted['retry_token'] ) ? sanitize_text_field( $posted['retry_token'] ) : null;

		if ( 'assign' === $action ) {
			return HallStaffService::assign_existing_user(
				sanitize_email( $posted['email'] ?? '' ),
				sanitize_key( $posted['role'] ?? '' ),
				$token
			);
		}

		if ( 'change' === $action ) {
			return HallStaffService::change_role(
				absint( $posted['target_user_id'] ?? 0 ),
				sanitize_key( $posted['role'] ?? '' ),
				$token
			);
		}

		if ( 'remove' === $action ) {
			return HallStaffService::remove_staff(
				absint( $posted['target_user_id'] ?? 0 ),
				$token
			);
		}

		return new \WP_Error( 'invalid_action', __( 'Choose a valid Hall Staff action.', 'bingocommerce-core' ) );
	}

	/** @param HallStaffResult|\WP_Error|null $result @param array<string,mixed> $posted */
	private static function render_notice( $result, array $posted ): void {
		if ( $result instanceof HallStaffResult ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Hall Staff was updated and its audit witness was verified.', 'bingocommerce-core' ) . '</p></div>';
			return;
		}

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		$data = $result->get_error_data();
		if ( ! is_array( $data ) || empty( $data['retry_token'] ) || empty( $posted['bc_hall_staff_action'] ) ) {
			return;
		}

		?>
		<form method="post">
			<?php wp_nonce_field( 'bingocommerce_hall_staff_mutation' ); ?>
			<input type="hidden" name="bc_hall_staff_action" value="<?php echo esc_attr( sanitize_key( $posted['bc_hall_staff_action'] ) ); ?>">
			<input type="hidden" name="retry_token" value="<?php echo esc_attr( $data['retry_token'] ); ?>">
			<?php foreach ( [ 'email', 'role', 'target_user_id' ] as $key ) : ?>
				<?php if ( isset( $posted[ $key ] ) ) : ?><input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( sanitize_text_field( (string) $posted[ $key ] ) ); ?>"><?php endif; ?>
			<?php endforeach; ?>
			<?php submit_button( __( 'Retry the same operation', 'bingocommerce-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function role_select( string $id, string $selected ): void {
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="role">
			<option value="<?php echo esc_attr( Roles::GAME_OPERATOR ); ?>" <?php selected( $selected, Roles::GAME_OPERATOR ); ?>><?php echo esc_html__( 'Game Operator', 'bingocommerce-core' ); ?></option>
			<option value="<?php echo esc_attr( Roles::HALL_MANAGER ); ?>" <?php selected( $selected, Roles::HALL_MANAGER ); ?>><?php echo esc_html__( 'Hall Manager', 'bingocommerce-core' ); ?></option>
		</select>
		<?php
	}

	private static function role_label( string $role ): string {
		return Roles::HALL_MANAGER === $role
			? __( 'Hall Manager', 'bingocommerce-core' )
			: __( 'Game Operator', 'bingocommerce-core' );
	}
}
