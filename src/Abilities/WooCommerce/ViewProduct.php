<?php
/**
 * View Product Ability
 *
 * @package Albert
 * @subpackage Abilities\WooCommerce
 * @since      1.0.0
 */

namespace Albert\Abilities\WooCommerce;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;

/**
 * View Product Ability class
 *
 * Allows AI assistants to view a single WooCommerce product by ID.
 *
 * @since 1.0.0
 */
class ViewProduct extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/woo-view-product';
		$this->label       = __( 'View Product', 'albert' );
		$this->description = __( 'Retrieve a single WooCommerce product by ID.', 'albert' );
		$this->category    = 'woo-products';
		$this->group       = 'products';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema.
	 *
	 * @return array<string, mixed> Input schema.
	 * @since 1.0.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'The product ID to retrieve.',
					'minimum'     => 1,
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * Get the output schema.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.0.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'product' => [
					'type'        => 'object',
					'description' => 'The requested product object.',
				],
			],
		];
	}

	/**
	 * Check permission.
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->require_capability( 'read' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$product_id = absint( $args['id'] ?? 0 );

		if ( ! $product_id ) {
			return new WP_Error( 'missing_product_id', __( 'Product ID is required.', 'albert' ) );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				sprintf(
					/* translators: %d: Product ID */
					__( 'Product with ID %d not found.', 'albert' ),
					$product_id
				)
			);
		}

		// Get category names.
		$category_ids   = $product->get_category_ids();
		$category_names = [];
		foreach ( $category_ids as $cat_id ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$category_names[] = $term->name;
			}
		}

		// Get image URLs.
		$image_id  = (int) $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

		$gallery_ids  = $product->get_gallery_image_ids();
		$gallery_urls = [];
		foreach ( $gallery_ids as $gid ) {
			$url = wp_get_attachment_url( $gid );
			if ( $url ) {
				$gallery_urls[] = $url;
			}
		}

		return [
			'product' => [
				'id'                => $product->get_id(),
				'name'              => $product->get_name(),
				'slug'              => $product->get_slug(),
				'type'              => $product->get_type(),
				'status'            => $product->get_status(),
				'description'       => $product->get_description(),
				'short_description' => $product->get_short_description(),
				'sku'               => $product->get_sku(),
				'price'             => $product->get_price(),
				'regular_price'     => $product->get_regular_price(),
				'sale_price'        => $product->get_sale_price(),
				'stock_status'      => $product->get_stock_status(),
				'stock_quantity'    => $product->get_stock_quantity(),
				'weight'            => $product->get_weight(),
				'categories'        => $category_names,
				'image'             => $image_url,
				'gallery_images'    => $gallery_urls,
				'permalink'         => get_permalink( $product->get_id() ),
				'date_created'      => $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d H:i:s' ) : '',
			],
		];
	}
}
