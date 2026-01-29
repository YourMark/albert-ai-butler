<?php
/**
 * Find Customers Ability
 *
 * @package Albert
 * @subpackage Abilities\WooCommerce
 * @since      1.0.0
 */

namespace Albert\Abilities\WooCommerce;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_User_Query;

/**
 * Find Customers Ability class
 *
 * Allows AI assistants to search and list WooCommerce customers.
 *
 * @since 1.0.0
 */
class FindCustomers extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/woo-find-customers';
		$this->label       = __( 'Find Customers', 'albert' );
		$this->description = __( 'Search and list WooCommerce customers with optional filtering and pagination.', 'albert' );
		$this->category    = 'woo-customers';
		$this->group       = 'customers';

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
				'page'     => [
					'type'        => 'integer',
					'description' => 'Page number for pagination.',
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Number of customers per page.',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				],
				'search'   => [
					'type'        => 'string',
					'description' => 'Search customers by name or email.',
				],
				'orderby'  => [
					'type'        => 'string',
					'description' => 'Sort by field.',
					'enum'        => [ 'registered', 'display_name', 'email', 'id' ],
					'default'     => 'registered',
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
				'customers'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'              => [ 'type' => 'integer' ],
							'email'           => [ 'type' => 'string' ],
							'first_name'      => [ 'type' => 'string' ],
							'last_name'       => [ 'type' => 'string' ],
							'display_name'    => [ 'type' => 'string' ],
							'date_registered' => [ 'type' => 'string' ],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'customers', 'total' ],
		];
	}

	/**
	 * Check permission.
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->require_capability( 'list_users' );
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
		$offset   = ( $page - 1 ) * $per_page;

		$query_args = [
			'role'    => 'customer',
			'number'  => $per_page,
			'offset'  => $offset,
			'orderby' => sanitize_key( $args['orderby'] ?? 'registered' ),
			'order'   => strtoupper( sanitize_key( $args['order'] ?? 'DESC' ) ),
		];

		if ( ! empty( $args['search'] ) ) {
			$query_args['search']         = '*' . sanitize_text_field( $args['search'] ) . '*';
			$query_args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
		}

		$user_query = new WP_User_Query( $query_args );
		$users      = $user_query->get_results();
		$total      = $user_query->get_total();
		$customers  = [];

		foreach ( $users as $user ) {
			$customers[] = [
				'id'              => $user->ID,
				'email'           => $user->user_email,
				'first_name'      => get_user_meta( $user->ID, 'first_name', true ),
				'last_name'       => get_user_meta( $user->ID, 'last_name', true ),
				'display_name'    => $user->display_name,
				'date_registered' => $user->user_registered,
			];
		}

		return [
			'customers'   => $customers,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		];
	}
}
