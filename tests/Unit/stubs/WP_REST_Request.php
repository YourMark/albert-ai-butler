<?php
/**
 * Minimal WP_REST_Request stub for unit tests.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub.
	 *
	 * Carries just enough state for unit-level code under test: headers, method,
	 * route. Body/query handling is intentionally omitted — anything that needs
	 * it belongs in the integration suite against the real WordPress class.
	 */
	class WP_REST_Request {

		/**
		 * HTTP method.
		 *
		 * @var string
		 */
		protected string $method;

		/**
		 * Route.
		 *
		 * @var string
		 */
		protected string $route;

		/**
		 * Lower-cased header map.
		 *
		 * @var array<string, string>
		 */
		protected array $headers = [];

		/**
		 * Constructor.
		 *
		 * @param string $method HTTP method.
		 * @param string $route  Route.
		 */
		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		/**
		 * Get a header value.
		 *
		 * @param string $key Header name (case-insensitive).
		 *
		 * @return string|null
		 */
		public function get_header( string $key ): ?string {
			$key = strtolower( $key );

			return $this->headers[ $key ] ?? null;
		}

		/**
		 * Set a header value.
		 *
		 * @param string $key   Header name.
		 * @param string $value Header value.
		 */
		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		/**
		 * Get the request method.
		 *
		 * @return string
		 */
		public function get_method(): string {
			return $this->method;
		}

		/**
		 * Get the route.
		 *
		 * @return string
		 */
		public function get_route(): string {
			return $this->route;
		}
	}
}

// phpcs:enable
