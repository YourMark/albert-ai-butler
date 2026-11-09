<?php
/**
 * Search Content Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Search
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Search;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Searches published content across post types, or terms across taxonomies, through core's search route.
 *
 * @since 1.6.0
 */
class SearchContent extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/search-content';
		$this->label       = __( 'Search Content', 'albert-ai-butler' );
		$this->description = __( 'Search the site\'s published content across all public post types (posts, pages and custom types) at once, the way the site search does. Can search categories, tags and other terms instead.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'search';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Only published, public content is searched. To find drafts or private posts, use find-posts or find-pages with a status.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'search'   => [
					'type'        => 'string',
					'description' => 'Words to search for.',
					'minLength'   => 1,
				],
				'type'     => [
					'type'        => 'string',
					'description' => '"post" searches content of every public post type; "term" searches categories, tags and other terms.',
					'enum'        => [ 'post', 'term' ],
					'default'     => 'post',
				],
				'subtypes' => [
					'type'        => 'array',
					'description' => 'Limit to these post types (for type "post") or taxonomies (for type "term"), e.g. ["page"]. Default: all.',
					'items'       => [ 'type' => 'string' ],
				],
				'page'     => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				],
				'per_page' => [
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 100,
				],
			],
			'required'   => [ 'search' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'results'     => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'      => [ 'type' => 'integer' ],
							'title'   => [ 'type' => 'string' ],
							'url'     => [ 'type' => 'string' ],
							'type'    => [ 'type' => 'string' ],
							'subtype' => [
								'type'        => 'string',
								'description' => 'The post type or taxonomy.',
							],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'results', 'total' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * Core's search route is public; it returns only what any visitor could find.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'read' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$search = trim( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		if ( $search === '' ) {
			return new WP_Error(
				'search_missing',
				__( 'Say what to search for.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', $search );
		$type = ( $args['type'] ?? 'post' ) === 'term' ? 'term' : 'post';
		$request->set_param( 'type', $type );
		$request->set_param( 'page', max( 1, absint( $args['page'] ?? 1 ) ) );
		$request->set_param( 'per_page', min( 100, max( 1, absint( $args['per_page'] ?? 10 ) ) ) );

		$subtypes = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['subtypes'] ?? [] ) ) ) );
		if ( $subtypes ) {
			$request->set_param( 'subtype', $subtypes );
		}

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( $response->is_error() ) {
			return new WP_Error(
				is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'rest_error',
				is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : __( 'The search failed.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		$results = [];
		foreach ( is_array( $data ) ? $data : [] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			// Core's term handler puts the taxonomy in `type` and sends no `subtype`; posts use both.
			$results[] = [
				'id'      => (int) ( $item['id'] ?? 0 ),
				'title'   => html_entity_decode( (string) ( $item['title'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
				'url'     => (string) ( $item['url'] ?? '' ),
				'type'    => $type,
				'subtype' => (string) ( $type === 'term' ? ( $item['type'] ?? '' ) : ( $item['subtype'] ?? '' ) ),
			];
		}

		$headers = $response->get_headers();

		return [
			'results'     => $results,
			'total'       => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $results ),
			'total_pages' => isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1,
		];
	}
}
