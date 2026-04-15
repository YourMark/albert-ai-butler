<?php
/**
 * Concrete ability stub for testing hooks.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit;

use Albert\Abstracts\BaseAbility;
use WP_Error;

/**
 * Concrete ability stub for testing hooks.
 *
 * Returns a configurable result from execute().
 */
class StubAbility extends BaseAbility {

	/**
	 * Value to return from execute().
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private array|WP_Error $return_value;

	/**
	 * Constructor.
	 *
	 * @param string               $id           Ability identifier.
	 * @param array<string, mixed>|WP_Error $return_value Value returned by execute().
	 */
	public function __construct( string $id = 'test/my-ability', array|WP_Error $return_value = [] ) {
		$this->id           = $id;
		$this->label        = 'Test Ability';
		$this->description  = 'A stub ability for testing hooks.';
		$this->category     = 'test';
		$this->return_value = ! empty( $return_value ) || $return_value instanceof WP_Error
			? $return_value
			: [ 'ok' => true ];

		parent::__construct();
	}

	/**
	 * Check permission (always allowed in tests).
	 *
	 * @return true|WP_Error
	 */
	public function check_permission(): true|WP_Error {
		return true;
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $args ): array|WP_Error {
		return $this->return_value;
	}
}
