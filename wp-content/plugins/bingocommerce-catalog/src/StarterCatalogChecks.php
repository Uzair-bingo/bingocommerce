<?php

namespace BingoCommerce\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ADR-049 section 6: cleans and checks one main-site product for Publish.
 *
 * The Start pre-check and every capture use the same inspection, so a product
 * that changed between them is checked again. Problems block Publish; warnings
 * (content WordPress's cleaning removed) do not. Inspection only reads: it
 * never writes or copies a file.
 *
 * Cleaning uses WordPress's own rule for each field, whoever publishes: the
 * name gets the filter WordPress applies to a title for users without
 * `unfiltered_html` (wp_filter_kses on title_save_pre, kses.php:2468), and
 * descriptions get wp_kses_post(), the call WooCommerce's own product form
 * uses (class-wc-meta-box-product-data.php:584).
 */
final class StarterCatalogChecks {

	private const STATUSES           = [ 'draft', 'pending', 'private' ];
	private const VARIATION_STATUSES = [ 'publish', 'private' ];

	/** The frozen file's extension for each accepted image type. */
	public const EXTENSIONS = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	];

	/**
	 * Inspect one main-site product.
	 *
	 * @return array{name:string,type:string,modified:string,problems:array<int,string>,warnings:array<int,string>,data:array<string,mixed>,featured:int,gallery:array<int,int>,images:array<int,array<string,mixed>>,variations:array<int,array<string,mixed>>}
	 */
	public static function inspect( int $product_id ): array {
		switch_to_blog( get_main_site_id() );
		try {
			return self::inspect_here( $product_id );
		} finally {
			restore_current_blog();
		}
	}

	private static function inspect_here( int $product_id ): array {
		global $wpdb;

		$result = [
			'name'       => sprintf( 'Product #%d', $product_id ),
			'type'       => '',
			'modified'   => '',
			'problems'   => [],
			'warnings'   => [],
			'data'       => [],
			'featured'   => 0,
			'gallery'    => [],
			'images'     => [],
			'variations' => [],
		];

		clean_post_cache( $product_id );
		$post = get_post( $product_id );
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type || 'trash' === $post->post_status ) {
			/* translators: %d: product ID. */
			$result['problems'][] = sprintf( __( 'Product #%d is missing.', 'bingocommerce-catalog' ), $product_id );

			return $result;
		}

		$label          = wp_strip_all_tags( wp_kses( $post->post_title, 'title_save_pre' ) );
		$label          = '' !== $label ? $label : $result['name'];
		$result['name'] = $label;
		$product        = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			$result['problems'][] = self::say( $label, __( 'could not be read.', 'bingocommerce-catalog' ) );

			return $result;
		}

		$type               = (string) $product->get_type();
		$result['type']     = $type;
		$result['modified'] = (string) $post->post_modified_gmt;
		if ( ! in_array( $type, [ 'simple', 'variable' ], true ) ) {
			$result['problems'][] = self::say( $label, __( 'is not a simple or variable product.', 'bingocommerce-catalog' ) );

			return $result;
		}

		$problems = [];
		$warnings = [];

		if ( ! in_array( $post->post_status, self::STATUSES, true ) ) {
			$status     = get_post_status_object( $post->post_status );
			/* translators: %s: post status label, for example Published. */
			$problems[] = self::say( $label, sprintf( __( 'is %s; it must be Draft, Pending or Private.', 'bingocommerce-catalog' ), $status ? $status->label : $post->post_status ) );
		}
		if ( $product->get_downloadable( 'edit' ) ) {
			$problems[] = self::say( $label, __( 'is downloadable.', 'bingocommerce-catalog' ) );
		}

		$raw_name = (string) $product->get_name( 'edit' );
		$name     = wp_kses( $raw_name, 'title_save_pre' );
		if ( '' === trim( $name ) ) {
			$problems[] = self::say( $label, __( 'has no usable name.', 'bingocommerce-catalog' ) );
		} elseif ( $name !== $raw_name ) {
			$warnings[] = self::say( $label, __( 'name lost content WordPress does not allow.', 'bingocommerce-catalog' ) );
		}

		$description = self::clean_text( $label, __( 'description', 'bingocommerce-catalog' ), (string) $product->get_description( 'edit' ), $problems, $warnings );
		$short       = self::clean_text( $label, __( 'short description', 'bingocommerce-catalog' ), (string) $product->get_short_description( 'edit' ), $problems, $warnings );

		$price = 'simple' === $type ? (string) $product->get_regular_price( 'edit' ) : '';
		if ( ! self::valid_price( $price ) ) {
			/* translators: %s: the stored price. */
			$problems[] = self::say( $label, sprintf( __( 'has the price "%s", which is not a valid price.', 'bingocommerce-catalog' ), $price ) );
		}

		$attributes = [];
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				/* translators: %s: attribute name. */
				$problems[] = self::say( $label, sprintf( __( 'uses the global attribute "%s". Only product-level attributes are supported.', 'bingocommerce-catalog' ), wc_attribute_label( $attribute->get_name() ) ) );
				continue;
			}
			$attributes[] = [
				'name'      => (string) $attribute->get_name(),
				'options'   => array_values( array_map( 'strval', (array) $attribute->get_options() ) ),
				'visible'   => (bool) $attribute->get_visible(),
				'variation' => (bool) $attribute->get_variation(),
				'position'  => (int) $attribute->get_position(),
			];
		}

		$featured = (int) $product->get_image_id( 'edit' );
		$gallery  = array_values( array_filter( array_map( 'intval', (array) $product->get_gallery_image_ids( 'edit' ) ) ) );
		foreach ( array_unique( array_filter( array_merge( [ $featured ], $gallery ) ) ) as $attachment_id ) {
			$info = self::image( $label, $attachment_id, $problems );
			if ( null !== $info ) {
				$result['images'][ $attachment_id ] = $info;
			}
		}

		$terms = self::terms( $label, $product_id, $problems );

		// ADR-049 section 6: the source slug, else one made from the cleaned
		// name, else starter-product-<source product ID>.
		$slug = (string) $post->post_name;
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		if ( '' === $slug ) {
			$slug = 'starter-product-' . $product_id;
		}

		$data = [
			'name'              => $name,
			'description'       => $description,
			'short_description' => $short,
			'slug'              => $slug,
			'regular_price'     => $price,
			'sku'               => (string) $product->get_sku( 'edit' ),
			'categories'        => $terms['categories'],
			'tags'              => $terms['tags'],
			'tax_status'        => (string) $product->get_tax_status( 'edit' ),
			'tax_class'         => (string) $product->get_tax_class( 'edit' ),
			'manage_stock'      => (bool) $product->get_manage_stock( 'edit' ),
			'attributes'        => $attributes,
			'_terms'            => $terms['entries'],
		];

		if ( 'variable' === $type ) {
			$data['default_attributes'] = self::string_map( $product->get_default_attributes( 'edit' ) );

			$children = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ID, post_status, post_modified_gmt FROM %i WHERE post_parent = %d AND post_type = %s AND post_status NOT IN ( %s, %s ) ORDER BY menu_order ASC, ID ASC',
					$wpdb->posts,
					$product_id,
					'product_variation',
					'trash',
					'auto-draft'
				),
				ARRAY_A
			);
			if ( ! is_array( $children ) || '' !== (string) $wpdb->last_error ) {
				$problems[] = self::say( $label, __( 'variations could not be read.', 'bingocommerce-catalog' ) );
				$children   = [];
			} elseif ( [] === $children ) {
				$problems[] = self::say( $label, __( 'has no variations.', 'bingocommerce-catalog' ) );
			}

			foreach ( $children as $index => $child ) {
				$variation = self::variation( $label, $child, $index + 1, $problems, $warnings );
				if ( null === $variation ) {
					continue;
				}
				if ( null !== $variation['image_info'] ) {
					$result['images'][ $variation['image'] ] = $variation['image_info'];
				}
				unset( $variation['image_info'] );
				$result['variations'][] = $variation;
			}
		}

		$result['problems'] = $problems;
		$result['warnings'] = $warnings;
		$result['data']     = $data;
		$result['featured'] = $featured;
		$result['gallery']  = $gallery;

		return $result;
	}

	/**
	 * One variation's checks and captured values.
	 *
	 * @param array<string,mixed> $child
	 * @param array<int,string>   $problems
	 * @param array<int,string>   $warnings
	 * @return array<string,mixed>|null
	 */
	private static function variation( string $parent_label, array $child, int $position, array &$problems, array &$warnings ): ?array {
		$id        = (int) $child['ID'];
		$variation = wc_get_product( $id );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			/* translators: %d: variation ID. */
			$problems[] = self::say( $parent_label, sprintf( __( 'variation #%d could not be read.', 'bingocommerce-catalog' ), $id ) );

			return null;
		}

		$attributes = self::string_map( $variation->get_attributes( 'edit' ) );
		$values     = array_filter( $attributes, static fn( string $value ): bool => '' !== $value );
		/* translators: 1: product name, 2: variation attribute values or ID. */
		$label = sprintf( __( '%1$s, variation %2$s', 'bingocommerce-catalog' ), $parent_label, [] !== $values ? implode( ' / ', $values ) : '#' . $id );

		$status = (string) $child['post_status'];
		if ( ! in_array( $status, self::VARIATION_STATUSES, true ) ) {
			$problems[] = self::say( $label, __( 'is neither enabled nor disabled.', 'bingocommerce-catalog' ) );
		}
		if ( $variation->get_downloadable( 'edit' ) ) {
			$problems[] = self::say( $label, __( 'is downloadable.', 'bingocommerce-catalog' ) );
		}

		$price = (string) $variation->get_regular_price( 'edit' );
		if ( ! self::valid_price( $price ) ) {
			/* translators: %s: the stored price. */
			$problems[] = self::say( $label, sprintf( __( 'has the price "%s", which is not a valid price.', 'bingocommerce-catalog' ), $price ) );
		}

		$description = self::clean_text( $label, __( 'description', 'bingocommerce-catalog' ), (string) $variation->get_description( 'edit' ), $problems, $warnings );
		$image       = (int) $variation->get_image_id( 'edit' );
		$image_info  = $image > 0 ? self::image( $label, $image, $problems ) : null;

		return [
			'id'         => $id,
			'position'   => $position,
			'modified'   => (string) $child['post_modified_gmt'],
			'image'      => $image,
			'image_info' => $image_info,
			'data'       => [
				'attributes'    => $attributes,
				'regular_price' => $price,
				'sku'           => (string) $variation->get_sku( 'edit' ),
				'manage_stock'  => (bool) $variation->get_manage_stock( 'edit' ),
				'enabled'       => 'publish' === $status,
				'tax_class'     => (string) $variation->get_tax_class( 'edit' ),
				'description'   => $description,
			],
		];
	}

	/**
	 * A referenced image: present, an accepted type, within the limits.
	 *
	 * @param array<int,string> $problems
	 * @return array<string,mixed>|null
	 */
	private static function image( string $label, int $attachment_id, array &$problems ): ?array {
		$post = get_post( $attachment_id );
		$file = $post instanceof \WP_Post && 'attachment' === $post->post_type && 'trash' !== $post->post_status
			? ( wp_get_original_image_path( $attachment_id ) ?: get_attached_file( $attachment_id ) )
			: false;
		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			/* translators: %d: image (attachment) ID. */
			$problems[] = self::say( $label, sprintf( __( 'uses image #%d, which is missing.', 'bingocommerce-catalog' ), $attachment_id ) );

			return null;
		}

		$name = wp_basename( $file );
		$size = filesize( $file );
		$info = wp_getimagesize( $file );
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
		if ( ! isset( self::EXTENSIONS[ $mime ] ) || (int) ( $info[0] ?? 0 ) < 1 || (int) ( $info[1] ?? 0 ) < 1 ) {
			/* translators: %s: image file name. */
			$problems[] = self::say( $label, sprintf( __( 'uses the image "%s", which is not a JPEG, PNG, WebP or GIF image.', 'bingocommerce-catalog' ), $name ) );

			return null;
		}
		if ( false === $size || $size < 1 || $size > StarterCatalogStorage::MAX_FILE_BYTES ) {
			/* translators: %s: image file name. */
			$problems[] = self::say( $label, sprintf( __( 'uses the image "%s", which is larger than 5 MB.', 'bingocommerce-catalog' ), $name ) );

			return null;
		}
		if ( (int) $info[0] > StarterCatalogStorage::MAX_DIMENSION || (int) $info[1] > StarterCatalogStorage::MAX_DIMENSION ) {
			/* translators: %s: image file name. */
			$problems[] = self::say( $label, sprintf( __( 'uses the image "%s", which is larger than 4,000 pixels on a side.', 'bingocommerce-catalog' ), $name ) );

			return null;
		}

		$base = preg_replace( '/[^A-Za-z0-9._-]+/', '-', sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) ) );
		$base = substr( trim( (string) $base, '.-' ), 0, 100 );

		return [
			'attachment_id' => $attachment_id,
			'file'          => $file,
			'name'          => '' !== $base ? $base : 'image',
			'extension'     => self::EXTENSIONS[ $mime ],
			'mime'          => $mime,
			'width'         => (int) $info[0],
			'height'        => (int) $info[1],
			'size'          => (int) $size,
			'alt'           => sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
		];
	}

	/**
	 * Cleaned description text, with its warning and content problems.
	 *
	 * @param array<int,string> $problems
	 * @param array<int,string> $warnings
	 */
	private static function clean_text( string $label, string $field, string $raw, array &$problems, array &$warnings ): string {
		$clean = wp_kses_post( $raw );
		if ( $clean !== $raw ) {
			/* translators: %s: field name, for example description. */
			$warnings[] = self::say( $label, sprintf( __( '%s lost content WordPress does not allow.', 'bingocommerce-catalog' ), $field ) );
		}

		if ( 1 === preg_match( '/<img\b/i', $clean ) ) {
			/* translators: %s: field name. */
			$problems[] = self::say( $label, sprintf( __( '%s contains an embedded image.', 'bingocommerce-catalog' ), $field ) );
		}
		if ( preg_match_all( '#(?:https?:)?//([a-z0-9.\-]+)#i', $clean, $hosts ) ) {
			if ( [] !== array_intersect( array_map( 'strtolower', $hosts[1] ), self::main_hosts() ) ) {
				/* translators: %s: field name. */
				$problems[] = self::say( $label, sprintf( __( '%s links to the main site.', 'bingocommerce-catalog' ), $field ) );
			}
		}
		global $shortcode_tags;
		if ( ! empty( $shortcode_tags ) && 1 === preg_match( '/' . get_shortcode_regex() . '/', $clean ) ) {
			/* translators: %s: field name. */
			$problems[] = self::say( $label, sprintf( __( '%s contains a shortcode.', 'bingocommerce-catalog' ), $field ) );
		}

		return $clean;
	}

	/**
	 * The product's category and tag slugs, and the term entries the version
	 * needs (each category with its ancestors).
	 *
	 * Each category's whole parent chain is checked the way the importer
	 * resolves it (StarterImporter::resolve_category()): the category is depth
	 * 0 and the deepest accepted ancestor is StarterImporter::MAX_CATEGORY_DEPTH.
	 * A deeper chain, a loop or a parent that cannot be read is a problem,
	 * never truncated or flattened. Ancestors shared by several categories are
	 * fine.
	 *
	 * @param array<int,string> $problems
	 * @return array{categories:array<int,string>,tags:array<int,string>,entries:array{product_cat:array<int,array<string,string>>,product_tag:array<int,array<string,string>>}}
	 */
	private static function terms( string $label, int $product_id, array &$problems ): array {
		$out = [
			'categories' => [],
			'tags'       => [],
			'entries'    => [ 'product_cat' => [], 'product_tag' => [] ],
		];

		$categories = wp_get_post_terms( $product_id, 'product_cat' );
		$tags       = wp_get_post_terms( $product_id, 'product_tag' );
		if ( is_wp_error( $categories ) || is_wp_error( $tags ) ) {
			$problems[] = self::say( $label, __( 'categories or tags could not be read.', 'bingocommerce-catalog' ) );

			return $out;
		}

		$entries = [];
		foreach ( $categories as $category ) {
			$out['categories'][] = (string) $category->slug;

			$chain = [];
			$seen  = [];
			$term  = $category;
			while ( true ) {
				if ( isset( $seen[ (int) $term->term_id ] ) ) {
					/* translators: %s: category slug. */
					$problems[] = self::say( $label, sprintf( __( 'is in the category "%s", whose parent categories form a loop.', 'bingocommerce-catalog' ), $category->slug ) );
					$chain      = null;
					break;
				}
				if ( count( $chain ) > StarterImporter::MAX_CATEGORY_DEPTH ) {
					/* translators: 1: category slug, 2: deepest accepted level. */
					$problems[] = self::say( $label, sprintf( __( 'is in the category "%1$s", which is nested more than %2$d levels deep.', 'bingocommerce-catalog' ), $category->slug, StarterImporter::MAX_CATEGORY_DEPTH ) );
					$chain      = null;
					break;
				}
				$seen[ (int) $term->term_id ] = true;

				$parent = (int) $term->parent > 0 ? get_term( (int) $term->parent, 'product_cat' ) : null;
				if ( (int) $term->parent > 0 && ! $parent instanceof \WP_Term ) {
					/* translators: 1: category slug, 2: parent category ID. */
					$problems[] = self::say( $label, sprintf( __( 'is in the category "%1$s", whose parent category #%2$d could not be read.', 'bingocommerce-catalog' ), $category->slug, (int) $term->parent ) );
					$chain      = null;
					break;
				}

				$chain[] = [
					'slug'   => (string) $term->slug,
					'name'   => (string) $term->name,
					'parent' => $parent instanceof \WP_Term ? (string) $parent->slug : '',
				];
				if ( ! $parent instanceof \WP_Term ) {
					break;
				}
				$term = $parent;
			}

			foreach ( (array) $chain as $entry ) {
				if ( ! isset( $entries[ $entry['slug'] ] ) ) {
					$entries[ $entry['slug'] ] = $entry;
				}
			}
		}
		$out['entries']['product_cat'] = array_values( $entries );

		foreach ( $tags as $term ) {
			$out['tags'][]                   = (string) $term->slug;
			$out['entries']['product_tag'][] = [
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
			];
		}

		return $out;
	}

	/** @return array<int,string> Lowercase host names of the main site. */
	private static function main_hosts(): array {
		$main  = get_main_site_id();
		$hosts = [
			wp_parse_url( get_home_url( $main ), PHP_URL_HOST ),
			wp_parse_url( get_site_url( $main ), PHP_URL_HOST ),
		];
		$site  = get_site( $main );
		if ( $site instanceof \WP_Site ) {
			$hosts[] = $site->domain;
		}

		return array_values( array_unique( array_filter( array_map( static fn( $host ): string => strtolower( (string) $host ), $hosts ) ) ) );
	}

	/** Blank, or a non-negative decimal such as 10 or 36.15. */
	private static function valid_price( string $price ): bool {
		return '' === $price || 1 === preg_match( '/\A\d+(?:\.\d+)?\z/', $price );
	}

	/**
	 * @param mixed $value
	 * @return array<string,string>
	 */
	private static function string_map( $value ): array {
		$map = [];
		foreach ( (array) $value as $key => $item ) {
			$map[ (string) $key ] = (string) $item;
		}

		return $map;
	}

	private static function say( string $label, string $message ): string {
		/* translators: 1: product name, 2: what is wrong. */
		return sprintf( __( '"%1$s" %2$s', 'bingocommerce-catalog' ), $label, $message );
	}
}
