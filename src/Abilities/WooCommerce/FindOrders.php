<?php
/**
 * Find Orders Ability
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
 * Find Orders Ability class
 *
 * Allows AI assistants to search and list WooCommerce orders.
 *
 * @since 1.0.0
 */
class FindOrders extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/woo-find-orders';
		$this->label       = __( 'Find Orders', 'albert' );
		$this->description = __( 'Search and list WooCommerce orders with optional filtering and pagination.', 'albert' );
		$this->category    = 'woo-orders';
		$this->group       = 'orders';

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
				'page'        => [
					'type'        => 'integer',
					'description' => 'Page number for pagination.',
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page'    => [
					'type'        => 'integer',
					'description' => 'Number of orders per page.',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				],
				'status'      => [
					'type'        => 'string',
					'description' => 'Filter by order status.',
					'enum'        => [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'any' ],
					'default'     => 'any',
				],
				'customer_id' => [
					'type'        => 'integer',
					'description' => 'Filter by customer user ID.',
					'minimum'     => 1,
				],
				'date_after'  => [
					'type'        => 'string',
					'description' => 'Filter orders created after this date (YYYY-MM-DD).',
				],
				'date_before' => [
					'type'        => 'string',
					'description' => 'Filter orders created before this date (YYYY-MM-DD).',
				],
				'orderby'     => [
					'type'        => 'string',
					'description' => 'Sort by field.',
					'enum'        => [ 'date', 'id', 'total' ],
					'default'     => 'date',
				],
				'order'       => [
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
				'orders'      => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'           => [ 'type' => 'integer' ],
							'status'       => [ 'type' => 'string' ],
							'total'        => [ 'type' => 'string' ],
							'currency'     => [ 'type' => 'string' ],
							'customer_id'  => [ 'type' => 'integer' ],
							'date_created' => [ 'type' => 'string' ],
							'item_count'   => [ 'type' => 'integer' ],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'orders', 'total' ],
		];
	}

	/**
	 * Check permission.
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->require_capability( 'edit_shop_orders' );
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
			'paginate' => true,
		];

		if ( ! empty( $args['status'] ) && 'any' !== $args['status'] ) {
			$query_args['status'] = 'wc-' . sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['customer_id'] ) ) {
			$query_args['customer_id'] = absint( $args['customer_id'] );
		}

		if ( ! empty( $args['date_after'] ) ) {
			$query_args['date_created'] = '>' . sanitize_text_field( $args['date_after'] );
		}

		if ( ! empty( $args['date_before'] ) ) {
			$before = '<' . sanitize_text_field( $args['date_before'] );
			if ( isset( $query_args['date_created'] ) ) {
				$query_args['date_created'] .= '...' . sanitize_text_field( $args['date_before'] );
			} else {
				$query_args['date_created'] = $before;
			}
		}

		// Paginate returns stdClass with orders, total, max_num_pages.
		$results = (object) wc_get_orders( $query_args );
		$orders  = [];

		foreach ( $results->orders as $order ) {
			$orders[] = [
				'id'           => $order->get_id(),
				'status'       => $order->get_status(),
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
				'customer_id'  => $order->get_customer_id(),
				'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				'item_count'   => $order->get_item_count(),
			];
		}

		return [
			'orders'      => $orders,
			'total'       => (int) $results->total,
			'total_pages' => (int) $results->max_num_pages,
		];
	}
}
