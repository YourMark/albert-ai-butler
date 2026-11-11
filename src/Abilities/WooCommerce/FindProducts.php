<?php
/**
 * Find Products Ability
 *
 * @package Albert
 * @subpackage Abilities\WooCommerce
 * @since      1.0.0
 */

namespace Albert\Abilities\WooCommerce;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;

/**
 * Find Products Ability class
 *
 * Allows AI assistants to search and list WooCommerce products.
 *
 * @since 1.0.0
 */
class FindProducts extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/woo-find-products';
		$this->label       = __( 'Find Products', 'albert-ai-butler' );
		$this->description = __( 'Search and list WooCommerce products with optional filtering and pagination.', 'albert-ai-butler' );
		$this->category    = 'woo-products';
		$this->group       = 'products';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Prices come back in the shop\'s own currency and follow its tax display setting, both of '
				. 'which are in the discovery response, quote a price as the shop states it rather than '
				. 'recalculating. A variable product\'s price is a range across its variations.'
			),
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
				'page'     => [
					'type'        => 'integer',
					'description' => 'Page number for pagination.',
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Number of products per page.',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				],
				'search'   => [
					'type'        => 'string',
					'description' => 'Search products by name or description.',
				],
				'sku'      => [
					'type'        => 'string',
					'description' => 'Search by exact SKU.',
				],
				'status'   => [
					'type'        => 'string',
					'description' => 'Filter by product status.',
					'enum'        => [ 'publish', 'draft', 'pending', 'private', 'any' ],
					'default'     => 'any',
				],
				'type'     => [
					'type'        => 'string',
					'description' => 'Filter by product type.',
					'enum'        => [ 'simple', 'variable', 'grouped', 'external' ],
				],
				'orderby'  => [
					'type'        => 'string',
					'description' => 'Sort by field.',
					'enum'        => [ 'date', 'title', 'id', 'price', 'popularity', 'rating' ],
					'default'     => 'date',
				],
				'order'    => [
					'type'        => 'string',
					'description' => 'Order direction.',
					'enum'        => [ 'asc', 'desc' ],
					'default'     => 'desc',
				],
			],
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
				'products'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'            => [ 'type' => 'integer' ],
							'name'          => [ 'type' => 'string' ],
							'slug'          => [ 'type' => 'string' ],
							'type'          => [ 'type' => 'string' ],
							'status'        => [ 'type' => 'string' ],
							'sku'           => [ 'type' => 'string' ],
							'price'         => [ 'type' => 'string' ],
							'regular_price' => [ 'type' => 'string' ],
							'sale_price'    => [ 'type' => 'string' ],
							'stock_status'  => [ 'type' => 'string' ],
							'permalink'     => [ 'type' => 'string' ],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'products', 'total' ],
		];
	}

	/**
	 * Check permission.
	 *
	 * @return bool|WP_Error
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'edit_products' );
	}

	/**
	 * The product statuses the current user may list.
	 *
	 * Mirrors the capabilities WooCommerce's own product REST checks: others'
	 * unpublished products need `edit_others_products`, private ones
	 * `read_private_products`.
	 *
	 * @return list<string>
	 * @since 1.5.1
	 */
	private static function readable_statuses(): array {
		$statuses = [ 'publish' ];

		if ( current_user_can( 'edit_others_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			array_push( $statuses, 'draft', 'pending', 'future' );
		}

		if ( current_user_can( 'read_private_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			$statuses[] = 'private';
		}

		return $statuses;
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
		$page     = absint( $args['page'] ?? 1 );
		$per_page = absint( $args['per_page'] ?? 10 );

		$query_args = [
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => sanitize_key( $args['orderby'] ?? 'date' ),
			'order'    => strtoupper( sanitize_key( $args['order'] ?? 'DESC' ) ),
			'return'   => 'objects',
			'paginate' => true,
		];

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		if ( ! empty( $args['sku'] ) ) {
			$query_args['sku'] = sanitize_text_field( $args['sku'] );
		}

		// wc_get_products() returns every status by default, so the statuses are
		// narrowed to what the user may read, before paging so totals stay true.
		$readable  = self::readable_statuses();
		$requested = ! empty( $args['status'] ) && $args['status'] !== 'any' ? [ sanitize_key( $args['status'] ) ] : $readable;
		$statuses  = array_values( array_intersect( $requested, $readable ) );
		if ( $statuses === [] ) {
			return [
				'products'    => [],
				'total'       => 0,
				'total_pages' => 0,
			];
		}
		$query_args['status'] = $statuses;

		if ( ! empty( $args['type'] ) ) {
			$query_args['type'] = sanitize_key( $args['type'] );
		}

		// Paginate returns stdClass with products, total, max_num_pages.
		$results  = (object) wc_get_products( $query_args );
		$products = [];

		foreach ( $results->products as $product ) {
			// Status alone does not cover another author's draft for a user who
			// may edit only their own products.
			if ( ! current_user_can( 'read_post', $product->get_id() ) ) {
				continue;
			}

			$products[] = [
				'id'            => $product->get_id(),
				'name'          => $product->get_name(),
				'slug'          => $product->get_slug(),
				'type'          => $product->get_type(),
				'status'        => $product->get_status(),
				'sku'           => $product->get_sku(),
				'price'         => $product->get_price(),
				'regular_price' => $product->get_regular_price(),
				'sale_price'    => $product->get_sale_price(),
				'stock_status'  => $product->get_stock_status(),
				'permalink'     => get_permalink( $product->get_id() ),
			];
		}

		return [
			'products'    => $products,
			'total'       => (int) $results->total,
			'total_pages' => (int) $results->max_num_pages,
		];
	}
}
