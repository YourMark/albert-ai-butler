<?php
/**
 * Integration test: the Abilities screen shows no guessed capability.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\AbilitiesPayload;
use Albert\Tests\TestCase;

/**
 * The payload row keeps its capability keys empty, and the old filter is deprecated.
 *
 * @covers \Albert\Admin\AbilitiesPayload
 */
class AbilitiesPayloadCapabilityTest extends TestCase {

	/**
	 * Every row carries empty capability keys, so an add-on reading them gets no guess.
	 *
	 * @return void
	 */
	public function test_rows_carry_no_capability(): void {
		$rows = AbilitiesPayload::build()['abilities'];

		$this->assertNotEmpty( $rows );

		foreach ( $rows as $row ) {
			$this->assertSame( '', $row['capability'], $row['id'] );
			$this->assertSame( [], $row['capabilityRoles'], $row['id'] );
		}
	}

	/**
	 * Hooking the removed filter triggers a deprecation notice and changes nothing.
	 *
	 * @return void
	 */
	public function test_required_capability_filter_is_deprecated(): void {
		$this->setExpectedDeprecated( 'albert/abilities/required_capability' );

		$override = static fn(): string => 'manage_options';
		add_filter( 'albert/abilities/required_capability', $override );

		$rows = AbilitiesPayload::build()['abilities'];

		remove_filter( 'albert/abilities/required_capability', $override );

		$this->assertSame( '', $rows[0]['capability'] );
	}
}
