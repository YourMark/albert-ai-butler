<?php
/**
 * View Order Ability
 *
 * @package Albert
 * @subpackage Abilities\WooCommerce
 * @since      1.0.0
 */

namespace Albert\Abilities\WooCommerce;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WC_Order;
use WC_Order_Item_Product;
use WP_Error;

/**
 * View Order Ability class
 *
 * Allows AI assistants to view a single WooCommerce order by ID.
 *
 * @since 1.0.0
 */
class ViewOrder extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/woo-view-order';
		$this->label       = __( 'View Order', 'albert-ai-butler' );
		$this->description = __( 'Retrieve a single WooCommerce order by ID.', 'albert-ai-butler' );
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
				'id' => [
					'type'        => 'integer',
					'description' => 'The order ID to retrieve.',
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
				'order' => [
					'type'        => 'object',
					'description' => 'The requested order object.',
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
		$order_id = absint( $args['id'] ?? 0 );

		if ( ! $order_id ) {
			return new WP_Error( 'missing_order_id', __( 'Order ID is required.', 'albert-ai-butler' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'order_not_found',
				sprintf(
					/* translators: %d: Order ID */
					__( 'Order with ID %d not found.', 'albert-ai-butler' ),
					$order_id
				)
			);
		}

		// Build line items.
		$items = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$items[] = [
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'subtotal' => $item->get_subtotal(),
				'total'    => $item->get_total(),
				'sku'      => $product instanceof \WC_Product ? $product->get_sku() : '',
			];
		}

		return [
			'order' => [
				'id'             => $order->get_id(),
				'status'         => $order->get_status(),
				'total'          => $order->get_total(),
				'subtotal'       => $order->get_subtotal(),
				'total_tax'      => $order->get_total_tax(),
				'currency'       => $order->get_currency(),
				'payment_method' => $order->get_payment_method_title(),
				'customer_id'    => $order->get_customer_id(),
				'billing'        => [
					'first_name' => $order->get_billing_first_name(),
					'last_name'  => $order->get_billing_last_name(),
					'email'      => $order->get_billing_email(),
					'phone'      => $order->get_billing_phone(),
					'address_1'  => $order->get_billing_address_1(),
					'address_2'  => $order->get_billing_address_2(),
					'city'       => $order->get_billing_city(),
					'state'      => $order->get_billing_state(),
					'postcode'   => $order->get_billing_postcode(),
					'country'    => $order->get_billing_country(),
				],
				'shipping'       => [
					'first_name' => $order->get_shipping_first_name(),
					'last_name'  => $order->get_shipping_last_name(),
					'address_1'  => $order->get_shipping_address_1(),
					'address_2'  => $order->get_shipping_address_2(),
					'city'       => $order->get_shipping_city(),
					'state'      => $order->get_shipping_state(),
					'postcode'   => $order->get_shipping_postcode(),
					'country'    => $order->get_shipping_country(),
				],
				'items'          => $items,
				'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				'date_modified'  => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d H:i:s' ) : '',
				'date_completed' => $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d H:i:s' ) : '',
				'customer_note'  => $order->get_customer_note(),
			],
		];
	}
}
