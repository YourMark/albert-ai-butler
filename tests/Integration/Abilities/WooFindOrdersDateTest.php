<?php
/**
 * Integration tests for the date filters of albert/woo-find-orders.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WooCommerce\FindOrders;
use Albert\Tests\TestCase;

/**
 * Date filters, alone and together.
 *
 * @since 1.6.0
 */
class WooFindOrdersDateTest extends TestCase {

	/**
	 * Order IDs keyed by creation date.
	 *
	 * @var array<string, int>
	 */
	private array $orders = [];

	/**
	 * Creates one order on each of three dates, as an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		foreach ( [ '2026-01-10', '2026-02-10', '2026-03-10' ] as $date ) {
			$order = wc_create_order();
			$order->set_date_created( $date . ' 12:00:00' );
			$order->save();
			$this->orders[ $date ] = $order->get_id();
		}
	}

	/**
	 * Run the ability and return the IDs it found.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<int, int>
	 */
	private function found( array $args ): array {
		$result = ( new FindOrders() )->execute( $args );

		$this->assertIsArray( $result );

		$ids = array_map( static fn ( array $order ): int => $order['id'], $result['orders'] );
		sort( $ids );

		return $ids;
	}

	/**
	 * Both dates together return the orders between them.
	 *
	 * @return void
	 */
	public function test_both_dates_find_the_orders_between(): void {
		$this->assertSame(
			[ $this->orders['2026-02-10'] ],
			$this->found(
				[
					'date_after'  => '2026-02-01',
					'date_before' => '2026-02-28',
				]
			)
		);
	}

	/**
	 * Each date alone still filters one side.
	 *
	 * @return void
	 */
	public function test_one_date_filters_one_side(): void {
		$this->assertSame( [ $this->orders['2026-02-10'], $this->orders['2026-03-10'] ], $this->found( [ 'date_after' => '2026-02-01' ] ) );
		$this->assertSame( [ $this->orders['2026-01-10'], $this->orders['2026-02-10'] ], $this->found( [ 'date_before' => '2026-02-28' ] ) );
	}
}
