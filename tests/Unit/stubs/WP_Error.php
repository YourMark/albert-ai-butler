<?php
/**
 * Minimal WP_Error stub for unit tests.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub WP_Error for unit tests.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		protected string $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		protected string $message;

		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		protected mixed $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Get the error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Get the error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Get the error data.
		 *
		 * @return mixed
		 */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

// phpcs:enable
