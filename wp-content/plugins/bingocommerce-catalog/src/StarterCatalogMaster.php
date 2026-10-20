<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 master catalog selection on the network main site.
 *
 * A product is in the master catalog when its `bc_starter_catalog_include`
 * metadata is "yes". Only Network Super Admins may change that key on the
 * main site (ADR-049 section 5):
 *
 *   - the "Include in starter catalog" checkbox is shown to Super Admins on
 *     the main site only, and its own nonce protects that form;
 *   - every other write path (the Custom Fields box, the WordPress and
 *     WooCommerce REST APIs, WooCommerce metadata saves and plain code) is
 *     refused by a guard on WordPress's metadata write filters for this one
 *     key, on the main site, for anyone who is not a Super Admin. WooCommerce
 *     writes product metadata with add_metadata(), update_metadata_by_mid()
 *     and delete_metadata_by_mid() (class-wc-data-store-wp.php:132–155).
 *
 * MySQL, not PHP, decides which spellings are this key. WordPress finds
 * metadata rows with SQL `meta_key = %s`, and the table collation
 * (utf8mb4_unicode_520_ci) treats case, accents, trailing spaces, zero-width
 * characters and full-width letters as equal. So the guard asks the database
 * whether a key matches, and the selection reads the key and value byte for
 * byte, so no other spelling can ever count as included.
 *
 * The one exception: when a product is permanently deleted, WordPress deletes
 * all of its metadata before the `delete_post` action (post.php:3874–3905);
 * that removal is allowed so no orphan row is left. Hall sites are not
 * guarded: the key means nothing there and the importer never copies it.
 * WooCommerce's Duplicate never copies the key, so a copy starts excluded.
 * WP-CLI must run as a Super Admin.
 */
final class StarterCatalogMaster {

	public const META_KEY = 'bc_starter_catalog_include';
	public const INCLUDED = 'yes';

	private const FIELD        = 'bc_starter_catalog_include';
	private const NONCE_FIELD  = '_bc_starter_catalog_include_nonce';
	private const NONCE_PREFIX = 'bingocommerce_starter_catalog_include:';

	/** Products being permanently deleted in this request. */
	private static array $deleting = [];

	public static function register(): void {
		add_action( 'post_submitbox_misc_actions', [ self::class, 'render_checkbox' ] );
		add_action( 'save_post_product', [ self::class, 'save_checkbox' ], 10, 2 );

		add_filter( 'add_post_metadata', [ self::class, 'guard_add' ], 10, 3 );
		add_filter( 'update_post_metadata', [ self::class, 'guard_update' ], 10, 3 );
		add_filter( 'delete_post_metadata', [ self::class, 'guard_delete' ], 10, 5 );
		add_filter( 'update_post_metadata_by_mid', [ self::class, 'guard_update_by_mid' ], 10, 4 );
		add_filter( 'delete_post_metadata_by_mid', [ self::class, 'guard_delete_by_mid' ], 10, 2 );
		add_action( 'before_delete_post', [ self::class, 'deleting_post' ] );
		// Its metadata is gone by `delete_post`; `deleted_post` never fires
		// when the post row itself fails to delete.
		add_action( 'delete_post', [ self::class, 'deleted_post' ] );
		add_action( 'deleted_post', [ self::class, 'deleted_post' ] );

		add_filter( 'woocommerce_duplicate_product_exclude_meta', [ self::class, 'exclude_from_duplicate' ] );
	}

	/* ------------------------------------------------------------------ *
	 * Checkbox
	 * ------------------------------------------------------------------ */

	/** The "Include in starter catalog" checkbox in the product's Publish box. */
	public static function render_checkbox( $post ): void {
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type || ! self::may_change() ) {
			return;
		}

		$included = self::INCLUDED === get_post_meta( $post->ID, self::META_KEY, true );
		?>
		<div class="misc-pub-section bc-starter-catalog-include">
			<?php wp_nonce_field( self::NONCE_PREFIX . (int) $post->ID, self::NONCE_FIELD ); ?>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( self::INCLUDED ); ?>" <?php checked( $included ); ?>>
				<?php echo esc_html__( 'Include in starter catalog', 'bingocommerce-catalog' ); ?>
			</label>
		</div>
		<?php
	}

	/** Saves the checkbox, only from its own form (its nonce present and valid). */
	public static function save_checkbox( $post_id, $post ): void {
		$post_id = (int) $post_id;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_PREFIX . $post_id ) || ! self::may_change() || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$wanted = isset( $_POST[ self::FIELD ] ) && self::INCLUDED === sanitize_key( wp_unslash( (string) $_POST[ self::FIELD ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $wanted ) {
			update_post_meta( $post_id, self::META_KEY, self::INCLUDED );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Write guard (main site, this key only)
	 * ------------------------------------------------------------------ */

	/** @param mixed $check */
	public static function guard_add( $check, $object_id, $meta_key ) {
		return self::refused() && self::is_flag_key( $meta_key ) ? false : $check;
	}

	/** @param mixed $check */
	public static function guard_update( $check, $object_id, $meta_key ) {
		return self::refused() && self::is_flag_key( $meta_key ) ? false : $check;
	}

	/** @param mixed $check */
	public static function guard_delete( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
		if ( ! self::refused() || ! self::is_flag_key( $meta_key ) ) {
			return $check;
		}

		return ! $delete_all && self::is_being_deleted( (int) $object_id ) ? $check : false;
	}

	/**
	 * A row is changed by its ID: refuse when it is this key's row or would
	 * become one.
	 *
	 * @param mixed $check
	 */
	public static function guard_update_by_mid( $check, $meta_id, $meta_value, $meta_key ) {
		if ( ! self::refused() ) {
			return $check;
		}
		if ( self::is_flag_key( $meta_key ) ) {
			return false;
		}

		$row = get_metadata_by_mid( 'post', (int) $meta_id );

		return is_object( $row ) && self::is_flag_key( $row->meta_key ) ? false : $check;
	}

	/** @param mixed $check */
	public static function guard_delete_by_mid( $check, $meta_id ) {
		if ( ! self::refused() ) {
			return $check;
		}

		$row = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( ! is_object( $row ) || ! self::is_flag_key( $row->meta_key ) ) {
			return $check;
		}

		return self::is_being_deleted( (int) $row->post_id ) ? $check : false;
	}

	/**
	 * WooCommerce's Duplicate copies a product's metadata except these keys.
	 *
	 * @param mixed $keys
	 * @return array<int,string>
	 */
	public static function exclude_from_duplicate( $keys ): array {
		$keys   = is_array( $keys ) ? $keys : [];
		$keys[] = self::META_KEY;

		return $keys;
	}

	public static function deleting_post( $post_id ): void {
		if ( self::on_main_site() ) {
			self::$deleting[ self::deleting_key( (int) $post_id ) ] = true;
		}
	}

	public static function deleted_post( $post_id ): void {
		unset( self::$deleting[ self::deleting_key( (int) $post_id ) ] );
	}

	/* ------------------------------------------------------------------ *
	 * Selection
	 * ------------------------------------------------------------------ */

	/**
	 * The main site's included products, ascending by ID. A product in any
	 * status except trash is listed, so Publish can name one that is not
	 * Draft, Pending or Private. The key and value must match byte for byte;
	 * the indexed `meta_key = %s` only narrows the rows.
	 *
	 * @return array<int,int>|\WP_Error
	 */
	public static function selected_ids() {
		global $wpdb;

		if ( ! is_multisite() ) {
			return self::db_error();
		}
		$prefix = $wpdb->get_blog_prefix( get_main_site_id() );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i m ON m.post_id = p.ID
				WHERE m.meta_key = %s AND CAST( m.meta_key AS BINARY ) = CAST( %s AS BINARY ) AND CAST( m.meta_value AS BINARY ) = CAST( %s AS BINARY )
				AND p.post_type = %s AND p.post_status NOT IN ( %s, %s )
				ORDER BY p.ID ASC',
				$prefix . 'posts',
				$prefix . 'postmeta',
				self::META_KEY,
				self::META_KEY,
				self::INCLUDED,
				'product',
				'trash',
				'auto-draft'
			)
		);
		if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
			return self::db_error();
		}

		return array_map( 'intval', $ids );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** May the current user change the flag here? */
	private static function may_change(): bool {
		return self::on_main_site() && is_super_admin();
	}

	/** Must this write be refused? */
	private static function refused(): bool {
		return self::on_main_site() && ! is_super_admin();
	}

	/**
	 * Would MySQL treat this key as the Include flag? Asked only on the
	 * refused path (main site, not a Super Admin). It compares in the
	 * connection's collation, the one WordPress creates its tables with, and
	 * also against the real flag rows in the column's own collation, in case
	 * a table was created with another one. A failed check refuses.
	 *
	 * @param mixed $meta_key
	 */
	private static function is_flag_key( $meta_key ): bool {
		global $wpdb;

		if ( ! is_string( $meta_key ) || '' === $meta_key ) {
			return false;
		}
		if ( self::META_KEY === $meta_key ) {
			return true;
		}

		$match = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ( %s = %s ) OR EXISTS ( SELECT 1 FROM %i WHERE meta_key = %s AND CAST( meta_key AS BINARY ) = CAST( %s AS BINARY ) )',
				$meta_key,
				self::META_KEY,
				$wpdb->get_blog_prefix( get_main_site_id() ) . 'postmeta',
				$meta_key,
				self::META_KEY
			)
		);

		return '' !== (string) $wpdb->last_error || '0' !== (string) $match;
	}

	private static function on_main_site(): bool {
		return is_multisite() && (int) get_current_blog_id() === (int) get_main_site_id();
	}

	private static function is_being_deleted( int $post_id ): bool {
		return isset( self::$deleting[ self::deleting_key( $post_id ) ] );
	}

	private static function deleting_key( int $post_id ): string {
		return (int) get_current_blog_id() . ':' . $post_id;
	}

	private static function db_error(): \WP_Error {
		return new \WP_Error( 'starter_catalog_db_error', __( 'The starter catalog selection could not be read. Please try again.', 'bingocommerce-catalog' ) );
	}
}
