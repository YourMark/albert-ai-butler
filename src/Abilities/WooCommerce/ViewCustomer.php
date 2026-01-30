<?php
/**
 * View Customer Ability
 *
 * @package Albert
 * @subpackage Abilities\WooCommerce
 * @since      1.0.0
 */

namespace Albert\Abilities\WooCommerce;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WC_Customer;
use WP_Error;

/**
 * View Customer Ability class
 *
 * Allows AI assistants to view a single WooCommerce customer by ID.
 *
 * @since 1.0.0
 */
class ViewCustomer extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/woo-view-customer';
		$this->label       = __( 'View Customer', 'albert-ai-butler' );
		$this->description = __( 'Retrieve a single WooCommerce customer by user ID.', 'albert-ai-butler' );
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
				'id' => [
					'type'        => 'integer',
					'description' => 'The customer user ID to retrieve.',
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
				'customer' => [
					'type'        => 'object',
					'description' => 'The requested customer object.',
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
		$customer_id = absint( $args['id'] ?? 0 );

		if ( ! $customer_id ) {
			return new WP_Error( 'missing_customer_id', __( 'Customer ID is required.', 'albert-ai-butler' ) );
		}

		try {
			$customer = new WC_Customer( $customer_id );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'customer_not_found',
				sprintf(
					/* translators: %d: Customer ID */
					__( 'Customer with ID %d not found.', 'albert-ai-butler' ),
					$customer_id
				)
			);
		}

		if ( ! $customer->get_id() ) {
			return new WP_Error(
				'customer_not_found',
				sprintf(
					/* translators: %d: Customer ID */
					__( 'Customer with ID %d not found.', 'albert-ai-butler' ),
					$customer_id
				)
			);
		}

		return [
			'customer' => [
				'id'           => $customer->get_id(),
				'email'        => $customer->get_email(),
				'first_name'   => $customer->get_first_name(),
				'last_name'    => $customer->get_last_name(),
				'display_name' => $customer->get_display_name(),
				'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				'order_count'  => $customer->get_order_count(),
				'total_spent'  => $customer->get_total_spent(),
				'billing'      => [
					'first_name' => $customer->get_billing_first_name(),
					'last_name'  => $customer->get_billing_last_name(),
					'email'      => $customer->get_billing_email(),
					'phone'      => $customer->get_billing_phone(),
					'address_1'  => $customer->get_billing_address_1(),
					'address_2'  => $customer->get_billing_address_2(),
					'city'       => $customer->get_billing_city(),
					'state'      => $customer->get_billing_state(),
					'postcode'   => $customer->get_billing_postcode(),
					'country'    => $customer->get_billing_country(),
				],
				'shipping'     => [
					'first_name' => $customer->get_shipping_first_name(),
					'last_name'  => $customer->get_shipping_last_name(),
					'address_1'  => $customer->get_shipping_address_1(),
					'address_2'  => $customer->get_shipping_address_2(),
					'city'       => $customer->get_shipping_city(),
					'state'      => $customer->get_shipping_state(),
					'postcode'   => $customer->get_shipping_postcode(),
					'country'    => $customer->get_shipping_country(),
				],
			],
		];
	}
}
