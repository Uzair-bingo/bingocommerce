<?php

namespace BingoCommerce\Catalog;

use BingoCommerce\Core\HallContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 section 8: Products → Starter Products on a Hall site.
 *
 * Hall Managers (`bc_manage_products`) and Super Admins import the latest
 * published starter catalog once, as drafts. Opening the page creates or
 * upgrades the Hall's import tables (section 14). The page shows the saved
 * receipt: unavailable (with the reason), not started, importing, failed
 * (a plain message for the saved error code and the affected product),
 * needs attention (a support reference; Super Admins also get Resolve,
 * section 13) or complete (date, counts, notes).
 *
 * Import, Continue, Retry and Resolve run through one admin-ajax action,
 * one request at a time (assets/starter-import-admin.js). Every request
 * re-checks the method, the user's rights, that this is a Hall site, the
 * nonce and every field; StarterImporter then checks Hall access and takes
 * the Hall lock. Without JavaScript the buttons stay disabled.
 *
 * An unfinished imported product's edit screen warns that changes may be
 * replaced; nothing is locked (section 8).
 */
final class StarterImportAdmin {

	public const PAGE        = 'bingocommerce-starter-products';
	public const AJAX_ACTION = 'bc_starter_import';
	public const SCRIPT      = 'bingocommerce-starter-import-admin';
	public const HIDDEN_CSS  = '#bc-starter-import [hidden] { display: none !important; }';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'hall_menu' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ self::class, 'ajax' ] );
		add_action( 'admin_notices', [ self::class, 'edit_screen_warning' ] );
	}

	/** A Hall site's admin, for a Hall Manager or a Super Admin. */
	public static function allowed(): bool {
		return is_multisite() && is_admin() && is_user_logged_in() && ! is_network_admin()
			&& class_exists( HallContext::class ) && HallContext::is_hall()
			&& ( is_super_admin() || current_user_can( 'bc_manage_products' ) );
	}

	public static function hall_menu(): void {
		if ( ! self::allowed() ) {
			return;
		}
		$hook = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Starter Products', 'bingocommerce-catalog' ),
			__( 'Starter Products', 'bingocommerce-catalog' ),
			'bc_manage_products',
			self::PAGE,
			[ self::class, 'render' ]
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'load_page' ] );
		}
	}

	/** Only this page loads the import script, its settings and its style. */
	public static function load_page(): void {
		if ( ! self::allowed() ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT, plugins_url( '../assets/starter-import-admin.js', __FILE__ ), [], BINGOCOMMERCE_CATALOG_VERSION, true );
		$config = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::nonce_action() ),
			'strings' => [
				'continue' => __( 'Continue Import', 'bingocommerce-catalog' ),
				'retry'    => __( 'Retry Remaining Products', 'bingocommerce-catalog' ),
				'failed'   => __( 'The request did not complete. Reload the page and try again.', 'bingocommerce-catalog' ),
				'reason'   => __( 'Enter a reason before resolving.', 'bingocommerce-catalog' ),
			],
		];
		wp_add_inline_script( self::SCRIPT, 'window.bcStarterImport = ' . wp_json_encode( $config ) . ';', 'before' );

		// WordPress's button rule (.wp-core-ui .button { display: inline-block })
		// would show buttons this page marks hidden.
		wp_register_style( self::SCRIPT, false, [], BINGOCOMMERCE_CATALOG_VERSION );
		wp_enqueue_style( self::SCRIPT );
		wp_add_inline_style( self::SCRIPT, self::HIDDEN_CSS );
	}

	/* ------------------------------------------------------------------ *
	 * admin-ajax
	 * ------------------------------------------------------------------ */

	/** admin-ajax: one Import, Continue, Retry or Resolve step. */
	public static function ajax(): void {
		$result = self::handle_ajax( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_ajax().
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error(
				[
					'code'          => $result->get_error_code(),
					'message'       => $result->get_error_message(),
					'import_status' => is_array( $data ) ? ( $data['import_status'] ?? null ) : null,
				],
				is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 409
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * One import step, after every check.
	 *
	 * @param array<string,mixed> $posted Unslashed POST fields.
	 * @return array{status:string,text:string}|\WP_Error
	 */
	public static function handle_ajax( array $posted ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return self::error( 'method' );
		}
		if ( ! is_multisite() || ! is_user_logged_in() || ! ( is_super_admin() || current_user_can( 'bc_manage_products' ) ) ) {
			return self::error( 'denied' );
		}
		if ( ! class_exists( HallContext::class ) || ! HallContext::is_hall() ) {
			return self::error( 'site' );
		}
		if ( ! is_string( $posted['_ajax_nonce'] ?? null ) || ! wp_verify_nonce( $posted['_ajax_nonce'], self::nonce_action() ) ) {
			return self::error( 'nonce' );
		}
		// Fields each step accepts besides action, _ajax_nonce and step.
		$fields = [
			'start'    => [],
			'continue' => [],
			'resolve'  => [ 'target', 'choice', 'found', 'reason' ],
		];

		$step = $posted['step'] ?? null;
		if ( ! is_string( $step ) || ! isset( $fields[ $step ] ) ) {
			return self::error( 'request' );
		}
		foreach ( array_keys( $posted ) as $key ) {
			if ( ! in_array( $key, array_merge( [ 'action', '_ajax_nonce', 'step' ], $fields[ $step ] ), true ) ) {
				return self::error( 'request' );
			}
		}
		foreach ( $fields[ $step ] as $key ) {
			if ( ! is_string( $posted[ $key ] ?? null ) ) {
				return self::error( 'request' );
			}
		}

		$importer = new StarterImporter();
		if ( 'resolve' === $step ) {
			// Super Admins only (section 15); the importer checks again.
			if ( ! is_super_admin() ) {
				return self::error( 'denied' );
			}
			if ( 1 !== preg_match( '/^(0|[1-9][0-9]{0,18})$/', $posted['found'] ) ) {
				return self::error( 'request' );
			}
			$result = $importer->resolve( $posted['target'], $posted['choice'], (int) $posted['found'], $posted['reason'] );
		} else {
			$result = 'start' === $step ? $importer->start() : $importer->process_next();
		}
		if ( is_wp_error( $result ) ) {
			// Where a receipt exists the importer has already recorded the
			// failure; its status tells the page whether to offer Retry.
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[
					'status'        => 409,
					'import_status' => self::receipt_status(),
				]
			);
		}

		return [
			'status' => (string) $result['status'],
			'text'   => self::status_text( $result ),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * What the page shows. Prepares the Hall's import tables first.
	 *
	 * @return array{kind:string,text:string,counts:string,notes:array<int,array{label:string,note:string}>,resolve:array<string,mixed>|null}
	 */
	public static function page_state(): array {
		$view = static fn( string $kind, string $text, string $counts = '', array $notes = [], ?array $resolve = null ): array => [
			'kind'    => $kind,
			'text'    => $text,
			'counts'  => $counts,
			'notes'   => $notes,
			'resolve' => $resolve,
		];

		$schema = StarterImportMigrations::ensure_current_hall();
		if ( is_wp_error( $schema ) ) {
			return $view( 'unavailable', $schema->get_error_message() );
		}

		$state = ( new StarterImporter() )->state();
		if ( is_wp_error( $state ) ) {
			return $view( 'unavailable', $state->get_error_message() );
		}

		if ( null === $state ) {
			if ( '' !== (string) get_option( StarterImportRepository::START_MARKER_OPTION, '' ) ) {
				return $view( 'unavailable', __( 'The starter import history is missing. Please contact Bingo Innovation support.', 'bingocommerce-catalog' ) );
			}
			if ( ! StarterImportMigrations::is_network_current() ) {
				return $view( 'unavailable', __( 'Starter products are not available yet.', 'bingocommerce-catalog' ) );
			}
			$version = StarterCatalogVersions::latest_published();
			if ( is_wp_error( $version ) ) {
				return $view( 'unavailable', $version->get_error_message() );
			}
			if ( null === $version ) {
				return $view( 'unavailable', __( 'Starter products are not available yet.', 'bingocommerce-catalog' ) );
			}
			if ( ! StarterCatalogVersions::imports_allowed() ) {
				return $view( 'unavailable', __( 'Starter product imports are turned off right now.', 'bingocommerce-catalog' ) );
			}
			$count = StarterCatalogVersions::count_top_level_products( (int) $version['version_id'] );
			if ( is_wp_error( $count ) ) {
				return $view( 'unavailable', $count->get_error_message() );
			}

			/* translators: %d: number of starter products. */
			return $view( 'not_started', sprintf( _n( '%d starter product is available.', '%d starter products are available.', $count, 'bingocommerce-catalog' ), $count ) );
		}

		$counts = sprintf(
			/* translators: 1: products imported, 2: products skipped. */
			__( 'So far: %1$d imported, %2$d skipped.', 'bingocommerce-catalog' ),
			(int) $state['imported_products'],
			(int) $state['skipped_products']
		);

		switch ( $state['status'] ) {
			case StarterImportRepository::RECEIPT_COMPLETE:
				$items = StarterImportRepository::labelled_items( (int) $state['import_id'] );

				return $view( 'complete', self::status_text( $state ), '', is_wp_error( $items ) ? [] : self::display_notes( $items ) );
			case StarterImportRepository::RECEIPT_FAILED:
				return $view( 'failed', self::failure_text( $state ), $counts );
			case StarterImportRepository::RECEIPT_NEEDS_ATTENTION:
				return $view( 'needs_attention', self::status_text( $state ), $counts, [], is_super_admin() ? self::resolve_view() : null );
			default:
				return $view( 'importing', self::status_text( $state ), $counts );
		}
	}

	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You are not allowed to import starter products for this Hall.', 'bingocommerce-catalog' ), '', [ 'response' => 403 ] );
		}

		$state      = self::page_state();
		$kind       = $state['kind'];
		$unfinished = in_array( $kind, [ 'importing', 'failed', 'needs_attention' ], true );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Starter Products', 'bingocommerce-catalog' ); ?></h1>
			<p><?php echo esc_html__( 'Bingo Innovation\'s starter products can be copied into this store once, as draft products. You can then edit, publish or delete them like any other product.', 'bingocommerce-catalog' ); ?></p>
			<?php if ( $unfinished ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Products that are still being imported may be replaced if you edit them before the import finishes.', 'bingocommerce-catalog' ); ?></p></div>
			<?php endif; ?>

			<div id="bc-starter-import">
				<noscript><p><?php echo esc_html__( 'Importing needs JavaScript in your browser.', 'bingocommerce-catalog' ); ?></p></noscript>
				<div data-bc-intro>
					<p><?php echo esc_html( $state['text'] ); ?></p>
					<?php if ( '' !== $state['counts'] ) : ?>
						<p><?php echo esc_html( $state['counts'] ); ?></p>
					<?php endif; ?>
					<?php if ( 'unavailable' === $kind ) : ?>
						<p><?php echo esc_html__( 'You can still add products by hand.', 'bingocommerce-catalog' ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( is_array( $state['resolve'] ) ) : ?>
					<?php self::render_resolve( $state['resolve'] ); ?>
				<?php endif; ?>
				<p>
					<button type="button" class="button button-primary" data-bc-import="start" disabled<?php echo 'not_started' === $kind ? '' : ' hidden'; ?>><?php echo esc_html__( 'Import Starter Products', 'bingocommerce-catalog' ); ?></button>
					<button type="button" class="button button-primary" data-bc-import="continue" disabled<?php echo in_array( $kind, [ 'importing', 'failed' ], true ) ? '' : ' hidden'; ?>><?php echo esc_html( 'failed' === $kind ? __( 'Retry Remaining Products', 'bingocommerce-catalog' ) : __( 'Continue Import', 'bingocommerce-catalog' ) ); ?></button>
				</p>
				<p role="status" aria-live="polite" data-bc-status></p>
				<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=' . self::PAGE ) ); ?>" data-bc-reload hidden><?php echo esc_html__( 'Reload this page', 'bingocommerce-catalog' ); ?></a></p>
			</div>

			<?php if ( 'complete' === $kind ) : ?>
				<?php if ( [] !== $state['notes'] ) : ?>
					<h2><?php echo esc_html__( 'Notes', 'bingocommerce-catalog' ); ?></h2>
					<ul class="ul-disc">
						<?php foreach ( $state['notes'] as $note ) : ?>
							<li><strong><?php echo esc_html( $note['label'] ); ?>:</strong> <?php echo esc_html( $note['note'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&post_status=draft' ) ); ?>"><?php echo esc_html__( 'View Products', 'bingocommerce-catalog' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resolve, for a Super Admin: what stopped the import, what was found, a
	 * required reason, Use (only when the match qualifies) and Skip. Both
	 * buttons send the offer the page shows, so the server refuses a click
	 * once anything has changed.
	 *
	 * @param array<string,mixed> $resolve From resolve_view().
	 */
	private static function render_resolve( array $resolve ): void {
		?>
		<div data-bc-resolve-box>
			<h2><?php echo esc_html__( 'Resolve', 'bingocommerce-catalog' ); ?></h2>
			<?php if ( isset( $resolve['error'] ) ) : ?>
				<p><?php echo esc_html( $resolve['error'] ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( $resolve['what'] ); ?></p>
				<p><?php echo esc_html( $resolve['finding'] ); ?></p>
				<p>
					<label for="bc-starter-resolve-reason"><?php echo esc_html__( 'Reason (required, saved in the audit log)', 'bingocommerce-catalog' ); ?></label><br>
					<textarea id="bc-starter-resolve-reason" class="large-text" rows="3" maxlength="<?php echo (int) StarterImporter::MAX_RESOLVE_REASON; ?>" data-bc-resolve-reason></textarea>
				</p>
				<p>
					<?php if ( $resolve['found'] > 0 ) : ?>
						<button type="button" class="button button-primary" data-bc-resolve="use" data-bc-target="<?php echo esc_attr( $resolve['target'] ); ?>" data-bc-found="<?php echo (int) $resolve['found']; ?>" disabled><?php echo esc_html( $resolve['use'] ); ?></button>
					<?php endif; ?>
					<button type="button" class="button" data-bc-resolve="skip" data-bc-target="<?php echo esc_attr( $resolve['target'] ); ?>" data-bc-found="<?php echo (int) $resolve['found']; ?>" disabled><?php echo esc_html( $resolve['skip'] ); ?></button>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** On an unfinished imported product's edit screen: changes may be replaced. */
	public static function edit_screen_warning(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || 'post' !== $screen->base || 'product' !== $screen->post_type ) {
			return;
		}
		if ( ! class_exists( HallContext::class ) || ! HallContext::is_hall() || ! StarterImportMigrations::is_hall_current() ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.
		if ( $post_id < 1 || true !== StarterImportRepository::is_unfinished_product( $post_id ) ) {
			return;
		}
		?>
		<div class="notice notice-warning"><p><?php echo esc_html__( 'This product is still being imported. Changes may be replaced.', 'bingocommerce-catalog' ); ?></p></div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Texts
	 * ------------------------------------------------------------------ */

	/** @param array<string,mixed> $state The importer's state. */
	private static function status_text( array $state ): string {
		switch ( $state['status'] ) {
			case StarterImportRepository::RECEIPT_COMPLETE:
				$imported = (int) $state['imported_products'];
				$date     = null !== $state['completed_at'] ? get_date_from_gmt( (string) $state['completed_at'], 'm/d/Y' ) : '';

				return sprintf(
					/* translators: 1: date (MM/DD/YYYY), 2: products imported, 3: products skipped. */
					_n( 'Import finished on %1$s: %2$d product imported, %3$d skipped.', 'Import finished on %1$s: %2$d products imported, %3$d skipped.', $imported, 'bingocommerce-catalog' ),
					$date,
					$imported,
					(int) $state['skipped_products']
				);
			case StarterImportRepository::RECEIPT_FAILED:
				// For example a Start from a second tab after the import failed.
				return self::failure_text( $state );
			case StarterImportRepository::RECEIPT_NEEDS_ATTENTION:
				return sprintf(
					/* translators: %s: support reference. */
					__( 'The import needs attention from Bingo Innovation support. Reference: %s.', 'bingocommerce-catalog' ),
					sprintf( 'SI-%d-%d-%d', (int) get_current_blog_id(), (int) $state['import_id'], (int) ( $state['last_error_item_id'] ?? 0 ) )
				);
			default:
				$progress = StarterImportRepository::progress( (int) $state['import_id'], (int) $state['version_id'] );
				if ( ! is_array( $progress ) ) {
					return __( 'Importing starter products.', 'bingocommerce-catalog' );
				}
				if ( $progress['variations'] > 0 ) {
					/* translators: 1: product number, 2: products in total, 3: variation number, 4: variations of this product. */
					return sprintf( __( 'Importing product %1$d of %2$d (variation %3$d of %4$d).', 'bingocommerce-catalog' ), $progress['position'], $progress['total'], $progress['variation'], $progress['variations'] );
				}

				/* translators: 1: product number, 2: products in total. */
				return sprintf( __( 'Importing product %1$d of %2$d.', 'bingocommerce-catalog' ), $progress['position'], $progress['total'] );
		}
	}

	/**
	 * The Resolve box's texts for the importer's current offer, or the error
	 * that means nothing is offered.
	 *
	 * @return array{error:string}|array{target:string,found:int,what:string,finding:string,use:string,skip:string}
	 */
	private static function resolve_view(): array {
		$offer = ( new StarterImporter() )->resolution();
		if ( is_wp_error( $offer ) ) {
			return [ 'error' => $offer->get_error_message() ];
		}
		if ( null === $offer ) {
			return [ 'error' => __( 'Nothing needs to be resolved. Reload this page.', 'bingocommerce-catalog' ) ];
		}

		$found = (int) $offer['found'];
		switch ( $offer['kind'] ) {
			case 'image':
				/* translators: %s: image file name. */
				$what  = sprintf( __( 'Image %s: its creation started, but its media ID was not saved.', 'bingocommerce-catalog' ), $offer['label'] );
				/* translators: %d: attachment ID. */
				$match = __( 'One matching image was found: #%d.', 'bingocommerce-catalog' );
				$use   = __( 'Use this image', 'bingocommerce-catalog' );
				$skip  = __( 'Skip this image', 'bingocommerce-catalog' );
				break;
			case 'variation':
				/* translators: %s: product and variation, for example "Dab O Ink, variation Blue". */
				$what  = sprintf( __( '%s: its creation started, but its variation ID was not saved.', 'bingocommerce-catalog' ), $offer['label'] );
				/* translators: %d: variation ID. */
				$match = __( 'One matching variation was found: #%d.', 'bingocommerce-catalog' );
				$use   = __( 'Use it', 'bingocommerce-catalog' );
				$skip  = __( 'Skip this variation', 'bingocommerce-catalog' );
				break;
			default:
				/* translators: %s: product name. */
				$what  = sprintf( __( '%s: its creation started, but its product ID was not saved.', 'bingocommerce-catalog' ), $offer['label'] );
				/* translators: %d: product ID. */
				$match = __( 'One matching draft was found: #%d.', 'bingocommerce-catalog' );
				$use   = __( 'Use it and continue', 'bingocommerce-catalog' );
				$skip  = __( 'Skip this item', 'bingocommerce-catalog' );
		}

		return [
			'target'  => (string) $offer['target'],
			'found'   => $found,
			'what'    => $what,
			'finding' => $found > 0 ? sprintf( $match, $found ) : self::skip_only_text( (string) $offer['why'] ),
			'use'     => $use,
			'skip'    => $skip,
		];
	}

	/** Why only Skip is offered. */
	private static function skip_only_text( string $why ): string {
		$text = match ( $why ) {
			'none'         => __( 'No match was found.', 'bingocommerce-catalog' ),
			'many'         => __( 'More than one match was found.', 'bingocommerce-catalog' ),
			'trashed'      => __( 'The match is in the Trash.', 'bingocommerce-catalog' ),
			'name'         => __( 'The match\'s slug is not exactly the one the import saved.', 'bingocommerce-catalog' ),
			'status'       => __( 'The match\'s status has changed.', 'bingocommerce-catalog' ),
			'type'         => __( 'The match is no longer the same kind of product.', 'bingocommerce-catalog' ),
			'catalog'      => __( 'The match is used by a Bingo Play Product or Package.', 'bingocommerce-catalog' ),
			'parent'       => __( 'The match belongs to a different product.', 'bingocommerce-catalog' ),
			'parent_state' => __( 'Its product is no longer this import\'s draft.', 'bingocommerce-catalog' ),
			'file'         => __( 'The match\'s file is not the one this import saved.', 'bingocommerce-catalog' ),
			default        => __( 'The match cannot be confirmed.', 'bingocommerce-catalog' ),
		};

		return $text . ' ' . __( 'Only Skip is possible; Skip leaves any match unchanged.', 'bingocommerce-catalog' );
	}

	/**
	 * Saved notes for display, one bullet per line. Display only: saved
	 * notes are unchanged. Within a product and its variations an identical
	 * line is shown once, because the importer copies a skipped product's note
	 * to its unfinished variations and adds a skipped variation's note to its
	 * product. Distinct lines (a variation's SKU conflict, say) all stay, and
	 * other products keep their own lines even when the wording matches.
	 *
	 * @param array<int,array{item_id:int,group:int,label:string,note:string}> $items In display order.
	 * @return array<int,array{label:string,note:string}>
	 */
	private static function display_notes( array $items ): array {
		$notes = [];
		$seen  = [];
		foreach ( $items as $item ) {
			foreach ( (array) preg_split( '/\r\n|\r|\n/', $item['note'] ) as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line || isset( $seen[ $item['group'] ][ $line ] ) ) {
					continue;
				}
				$seen[ $item['group'] ][ $line ] = true;
				$notes[]                         = [
					'label' => $item['label'],
					'note'  => $line,
				];
			}
		}

		return $notes;
	}

	/**
	 * The saved error code as a plain message, with the affected product.
	 *
	 * @param array<string,mixed> $state
	 */
	private static function failure_text( array $state ): string {
		$text    = self::failure_message( (string) ( $state['last_error_code'] ?? '' ) );
		$item_id = (int) ( $state['last_error_item_id'] ?? 0 );
		if ( $item_id > 0 ) {
			$items = StarterImportRepository::labelled_items( (int) $state['import_id'] );
			foreach ( is_wp_error( $items ) ? [] : $items as $item ) {
				if ( $item['item_id'] === $item_id && '' !== $item['label'] ) {
					/* translators: %s: product name. */
					$text .= ' ' . sprintf( __( 'Affected product: %s.', 'bingocommerce-catalog' ), $item['label'] );
					break;
				}
			}
		}

		return $text;
	}

	/** Every error code the importer can save on a failed import. */
	public static function failure_message( string $code ): string {
		switch ( $code ) {
			case 'starter_import_currency_mismatch':
				return __( 'This store\'s currency does not match the starter products\' currency.', 'bingocommerce-catalog' );
			case 'starter_import_tax_setting_mismatch':
				return __( 'This store and the starter products disagree on whether prices include tax.', 'bingocommerce-catalog' );
			case 'starter_import_tax_class_missing':
				return __( 'A tax class the starter products need is missing. Add it in WooCommerce > Settings > Tax, then try again.', 'bingocommerce-catalog' );
			case 'starter_import_storage_low':
				return __( 'There is not enough upload space in this store for the starter product images.', 'bingocommerce-catalog' );
			case 'starter_import_image_missing':
			case 'starter_import_version_missing':
			case 'starter_import_version_invalid':
				return __( 'Part of the starter catalog could not be found. Please contact Bingo Innovation support.', 'bingocommerce-catalog' );
			case 'starter_import_image_unsupported':
				return __( 'A starter image has a type or size this store does not accept. Please contact Bingo Innovation support.', 'bingocommerce-catalog' );
			case 'starter_import_image_support_missing':
				return __( 'This store\'s server cannot process this type of image. Please ask Bingo Innovation support to fix the server\'s image support, then select Retry.', 'bingocommerce-catalog' );
			case 'starter_import_image_failed':
				return __( 'A starter image could not be added to this store. Please try again.', 'bingocommerce-catalog' );
			case 'starter_import_save_failed':
			case 'starter_import_term_failed':
				return __( 'A starter product could not be saved. Please try again.', 'bingocommerce-catalog' );
			case 'starter_import_verify_failed':
			case 'starter_import_text_mismatch':
				return __( 'An imported product was not saved as expected. Please try again.', 'bingocommerce-catalog' );
			case 'starter_import_name_empty':
				return __( 'A starter product has no usable name. Please contact Bingo Innovation support.', 'bingocommerce-catalog' );
			case 'starter_import_unsupported_item':
				return __( 'This kind of starter product cannot be imported. Please contact Bingo Innovation support.', 'bingocommerce-catalog' );
			default:
				return __( 'The import stopped. Please try again.', 'bingocommerce-catalog' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/** The saved receipt's status, or null when there is none or it cannot be read. */
	private static function receipt_status(): ?string {
		if ( ! StarterImportMigrations::is_hall_current() ) {
			return null;
		}
		$receipt = StarterImportRepository::receipt();

		return is_array( $receipt ) ? (string) $receipt['status'] : null;
	}

	private static function nonce_action(): string {
		return 'bingocommerce_starter_import:hall:' . get_current_blog_id();
	}

	private static function error( string $code ): \WP_Error {
		$message = match ( $code ) {
			'denied' => __( 'You are not allowed to import starter products for this Hall.', 'bingocommerce-catalog' ),
			'nonce'  => __( 'Verification failed. Reload this page and try again.', 'bingocommerce-catalog' ),
			'site'   => __( 'Starter products are imported on a Hall site.', 'bingocommerce-catalog' ),
			default  => __( 'Invalid request. Nothing was changed.', 'bingocommerce-catalog' ),
		};
		$status  = match ( $code ) {
			'denied', 'nonce', 'site' => 403,
			'method'                  => 405,
			default                   => 400,
		};

		return new \WP_Error( 'starter_import_' . $code, $message, [ 'status' => $status ] );
	}
}
