<?php
/**
 * REST + sanitisation stubs for the block write-ability gate tests.
 *
 * Records every WP_REST_Request the abilities dispatch into
 * $GLOBALS['albert_test_rest_calls'] (each entry: method, route) and returns
 * canned responses driven by $GLOBALS['albert_test_rest_responses']:
 *   - ['get']   → response for GET existence checks (Update abilities)
 *   - ['write'] → response for the mutating POST create/update
 * A response entry is [ 'is_error' => bool, 'data' => array, 'status' => int ].
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! function_exists( 'get_post_statuses' ) ) {
	/**
	 * Stub get_post_statuses().
	 *
	 * @return array<string, string>
	 */
	function get_post_statuses() {
		return [
			'draft'   => 'Draft',
			'publish' => 'Publish',
			'pending' => 'Pending',
			'private' => 'Private',
		];
	}
}

if ( ! function_exists( 'get_post_format_slugs' ) ) {
	/**
	 * Stub get_post_format_slugs().
	 *
	 * @return array<string, string>
	 */
	function get_post_format_slugs() {
		return [
			'standard' => 'standard',
			'aside'    => 'aside',
			'gallery'  => 'gallery',
		];
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Stub sanitize_textarea_field().
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub sanitize_key().
	 *
	 * @param string $key Input.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub admin_url().
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Stub get_permalink().
	 *
	 * Accepts a post ID or a post object (the view abilities pass the object).
	 *
	 * @param object|int $post Post object or ID.
	 * @return string
	 */
	function get_permalink( $post = 0 ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return 'https://example.com/?p=' . (int) $id;
	}
}

if ( ! class_exists( 'Albert_Test_REST_Response' ) ) {
	/**
	 * Minimal REST response double.
	 */
	class Albert_Test_REST_Response {

		/**
		 * Whether the response represents an error.
		 *
		 * @var bool
		 */
		public $error;

		/**
		 * HTTP status code.
		 *
		 * @var int
		 */
		public $status;

		/**
		 * Response payload.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param bool  $error  Error flag.
		 * @param mixed $data   Payload.
		 * @param int   $status Status code.
		 */
		public function __construct( $error, $data, $status = 200 ) {
			$this->error  = (bool) $error;
			$this->data   = $data;
			$this->status = (int) $status;
		}

		/**
		 * Whether this is an error response.
		 *
		 * @return bool
		 */
		public function is_error() {
			return $this->error;
		}

		/**
		 * Get the HTTP status code.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Get response headers (pagination headers for list endpoints).
		 *
		 * Driven by $GLOBALS['albert_test_rest_headers'] when set.
		 *
		 * @return array<string, mixed>
		 */
		public function get_headers() {
			return (array) ( $GLOBALS['albert_test_rest_headers'] ?? [] );
		}
	}
}

if ( ! class_exists( 'Albert_Test_REST_Server' ) ) {
	/**
	 * Minimal REST server double.
	 */
	class Albert_Test_REST_Server {

		/**
		 * Convert a response to data (returns the canned payload).
		 *
		 * @param Albert_Test_REST_Response $response Response double.
		 * @param bool                      $embed    Unused.
		 * @return mixed
		 */
		public function response_to_data( $response, $embed = false ) {
			return $response->data;
		}

		/**
		 * No routes, so a REST permission check falls back to its capability.
		 *
		 * @return array<string, mixed>
		 */
		public function get_routes() {
			return [];
		}
	}
}

if ( ! function_exists( 'rest_get_server' ) ) {
	/**
	 * Stub rest_get_server().
	 *
	 * @return Albert_Test_REST_Server
	 */
	function rest_get_server() {
		return new Albert_Test_REST_Server();
	}
}

if ( ! function_exists( 'rest_do_request' ) ) {
	/**
	 * Stub rest_do_request().
	 *
	 * Records the request and returns a canned response. GET requests use the
	 * ['get'] response (default: success with empty data); POST requests use the
	 * ['write'] response (default: a 400 error so a missing queue is obvious).
	 *
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return Albert_Test_REST_Response
	 */
	function rest_do_request( $request ) {
		$method = $request->get_method();
		$route  = $request->get_route();

		if ( ! isset( $GLOBALS['albert_test_rest_calls'] ) || ! is_array( $GLOBALS['albert_test_rest_calls'] ) ) {
			$GLOBALS['albert_test_rest_calls'] = [];
		}

		$GLOBALS['albert_test_rest_calls'][] = [
			'method' => $method,
			'route'  => $route,
			'params' => method_exists( $request, 'get_params' ) ? $request->get_params() : [],
		];

		$responses = (array) ( $GLOBALS['albert_test_rest_responses'] ?? [] );
		$key       = $method === 'POST' ? 'write' : 'get';

		$default = $method === 'POST'
			? [
				'is_error' => true,
				'data'     => [
					'code'    => 'rest_error',
					'message' => 'No queued write response.',
				],
				'status'   => 400,
			]
			: [
				'is_error' => false,
				'data'     => [],
				'status'   => 200,
			];

		$config = $responses[ $key ] ?? $default;

		return new Albert_Test_REST_Response(
			$config['is_error'] ?? false,
			$config['data'] ?? [],
			$config['status'] ?? ( ( $config['is_error'] ?? false ) ? 400 : 200 )
		);
	}
}

// phpcs:enable
