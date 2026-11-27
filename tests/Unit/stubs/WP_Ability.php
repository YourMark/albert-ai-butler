<?php
/**
 * Minimal WP_Ability stub for unit tests.
 *
 * Only the accessors Albert reads are implemented: get_name, get_meta and
 * get_input_schema for MCP\ToolCallObserver, plus an execute() a test fills in.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_Ability' ) ) {
	/**
	 * Stub WP_Ability for unit tests.
	 */
	class WP_Ability {

		/**
		 * Ability name (ID).
		 *
		 * @var string
		 */
		private string $name;

		/**
		 * Ability meta.
		 *
		 * @var array<string, mixed>
		 */
		private array $meta;

		/**
		 * Input schema.
		 *
		 * @var array<string, mixed>
		 */
		private array $input_schema;

		/**
		 * Constructor.
		 *
		 * @param string               $name         Ability name (ID).
		 * @param array<string, mixed> $meta         Ability meta.
		 * @param array<string, mixed> $input_schema Input schema.
		 */
		public function __construct( string $name, array $meta = [], array $input_schema = [] ) {
			$this->name         = $name;
			$this->meta         = $meta;
			$this->input_schema = $input_schema;
		}

		/**
		 * Get the ability name.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * Get the ability meta.
		 *
		 * @return array<string, mixed>
		 */
		public function get_meta(): array {
			return $this->meta;
		}

		/**
		 * Get the input schema.
		 *
		 * @return array<string, mixed>
		 */
		public function get_input_schema(): array {
			return $this->input_schema;
		}

		/**
		 * Get a single meta item by key.
		 *
		 * @param string $key           Meta key.
		 * @param mixed  $default_value Value when the key is absent.
		 *
		 * @return mixed
		 */
		public function get_meta_item( string $key, $default_value = null ) {
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : $default_value;
		}

		/**
		 * What execute() runs, given the ability and the input; stands in for core's execute() body.
		 *
		 * @var callable|null
		 */
		public $runner = null;

		/**
		 * Run the configured body, so WP_Ability::execute is on the call stack as in core.
		 *
		 * @param mixed $input Ability input.
		 *
		 * @return mixed
		 */
		public function execute( $input = null ) {
			return is_callable( $this->runner ) ? ( $this->runner )( $this, $input ) : null;
		}

		/**
		 * Permission result the stub returns; set to a WP_Error or false to deny.
		 *
		 * @var mixed
		 */
		public $permission = true;

		/**
		 * Stub permission check returning the configured result.
		 *
		 * @param mixed $input Ability input (ignored by the stub).
		 *
		 * @return mixed
		 */
		public function check_permissions( $input = null ) {
			return $this->permission;
		}
	}
}

// phpcs:enable
