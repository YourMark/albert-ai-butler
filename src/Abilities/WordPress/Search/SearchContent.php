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
use WP_Query;
use WP_REST_Post_Search_Handler;
use WP_REST_Request;
use WP_REST_Term_Search_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Searches content, synced patterns and template parts, or terms, through core's search route.
 *
 * @since 1.6.0
 */
class SearchContent extends BaseAbility {

	/**
	 * Post type of synced patterns, which core's search leaves out.
	 *
	 * @var string
	 */
	private const PATTERN_TYPE = 'wp_block';

	/**
	 * Post type of template parts, searched through core's template-parts route.
	 *
	 * @var string
	 */
	private const TEMPLATE_PART_TYPE = 'wp_template_part';

	/**
	 * Statuses searched besides `publish`; each result is still checked with `read_post`.
	 *
	 * @var string[]
	 */
	private const EXTRA_STATUSES = [ 'future', 'draft', 'pending', 'private' ];

	/**
	 * Query var that marks the search query, so the status clause touches no other query.
	 *
	 * @var string
	 */
	private const QUERY_FLAG = 'albert_search_content';

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/search-content';
		$this->label       = __( 'Search Content', 'albert-ai-butler' );
		$this->description = __( 'Search the whole site for words in one call: posts, pages, other public content types, synced patterns and template parts such as the header and footer, including drafts, scheduled and private items you may read. Each result has a kind that says which ability changes it: post (update-post, replace-post-text), page (update-page, replace-page-text), pattern (update-pattern), term (update-term); a template part can only be changed in the Site Editor. With type "term" it searches categories, tags and other terms instead. Media files are not searched: use find-media.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'search';

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
					'description' => 'Words to search for; every word must match. Put - before a word to leave out results containing it, e.g. "hours -holiday". Use quotes for an exact phrase.',
					'pattern'     => '\\S',
				],
				'type'     => [
					'type'        => 'string',
					'description' => '"post" searches content, synced patterns and template parts; "term" searches categories, tags and other terms.',
					'enum'        => [ 'post', 'term' ],
					'default'     => 'post',
				],
				'subtypes' => [
					'type'        => 'array',
					'description' => 'Limit to these post types (for type "post", including "wp_block" for synced patterns and "wp_template_part" for template parts) or taxonomies (for type "term"), e.g. ["page"]. Default: all.',
					'items'       => [ 'type' => 'string' ],
				],
				'page'     => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Results per page. Matching template parts are few and all come on page 1, on top of this number.',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
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
							'id'      => [
								'type'        => [ 'integer', 'string' ],
								'description' => 'Post or term ID; for a template part its "theme//slug" ID.',
							],
							'title'   => [ 'type' => 'string' ],
							'url'     => [
								'type'        => 'string',
								'description' => 'Empty for patterns and template parts, which have no page of their own.',
							],
							'kind'    => [
								'type'        => 'string',
								'description' => 'What the result is: post, page, pattern, template_part, term, or other (another content type, see subtype).',
								'enum'        => [ 'post', 'page', 'pattern', 'template_part', 'term', 'other' ],
							],
							'subtype' => [
								'type'        => 'string',
								'description' => 'The post type or taxonomy.',
							],
							'status'  => [
								'type'        => 'string',
								'description' => 'Post status, such as publish, draft or private. Not set for terms.',
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
	 * Each result is limited to what the user may read. As in core, a search also
	 * matches inside password-protected posts; only their title and URL come back.
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
		$search   = (string) ( $args['search'] ?? '' );
		$type     = ( $args['type'] ?? 'post' ) === 'term' ? 'term' : 'post';
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ?? 10 ) ) );
		$subtypes = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['subtypes'] ?? [] ) ) ) );

		// Core's handlers hold the allowed values; core itself silently drops a taxonomy sent for type "post".
		$allowed = $type === 'term'
			? ( new WP_REST_Term_Search_Handler() )->get_subtypes()
			: array_merge( ( new WP_REST_Post_Search_Handler() )->get_subtypes(), [ self::PATTERN_TYPE, self::TEMPLATE_PART_TYPE ] );
		$unknown = array_diff( $subtypes, $allowed );
		if ( $unknown ) {
			return new WP_Error(
				'invalid_subtypes',
				sprintf(
					/* translators: 1: type, 2: the unknown subtypes, 3: the allowed subtypes */
					__( 'For type "%1$s", "subtypes" cannot include: %2$s. Allowed: %3$s.', 'albert-ai-butler' ),
					$type,
					implode( ', ', $unknown ),
					implode( ', ', $allowed )
				),
				[ 'status' => 400 ]
			);
		}

		$wants         = static fn( string $subtype ): bool => ! $subtypes || in_array( $subtype, $subtypes, true );
		$core_subtypes = array_values( array_diff( $subtypes, [ self::PATTERN_TYPE, self::TEMPLATE_PART_TYPE ] ) );
		$with_patterns = $type === 'post' && $wants( self::PATTERN_TYPE );
		$with_parts    = $type === 'post' && $wants( self::TEMPLATE_PART_TYPE );

		$results     = [];
		$total       = 0;
		$total_pages = 1;

		// Only template parts asked for: core's search has nothing to add.
		if ( ! $subtypes || $core_subtypes || $with_patterns ) {
			$only_patterns = $subtypes && ! $core_subtypes;
			$found         = $this->search_core( $search, $type, $core_subtypes, $with_patterns, $only_patterns, $page, $per_page );
			if ( $found instanceof WP_Error ) {
				return $found;
			}
			[ $results, $total, $total_pages ] = $found;
		}

		if ( $with_parts ) {
			$parts  = $this->search_template_parts( $search );
			$total += count( $parts );
			if ( $page === 1 ) {
				$results = array_merge( $results, $parts );
			}
		}

		return [
			'results'     => $results,
			'total'       => $total,
			'total_pages' => max( 1, $total_pages ),
		];
	}

	/**
	 * Run core's search route; for posts, widened to the statuses and patterns the user may read.
	 *
	 * @param string   $search        Search words.
	 * @param string   $type          "post" or "term".
	 * @param string[] $subtypes      Core subtypes to search; empty means all.
	 * @param bool     $with_patterns Whether synced patterns are searched too.
	 * @param bool     $only_patterns Whether synced patterns are the only post type searched.
	 * @param int      $page          Page number.
	 * @param int      $per_page      Results per page.
	 * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int}|WP_Error Results, total, total pages.
	 */
	private function search_core( string $search, string $type, array $subtypes, bool $with_patterns, bool $only_patterns, int $page, int $per_page ): array|WP_Error {
		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', $search );
		$request->set_param( 'type', $type );
		$request->set_param( 'page', $page );
		$request->set_param( 'per_page', $per_page );
		// Core refuses wp_block as a subtype, so patterns alone are sent as "any" and narrowed in the query.
		$request->set_param( 'subtype', $subtypes ? $subtypes : [ 'any' ] );

		$widen_query = static function ( array $query_args ) use ( $with_patterns, $only_patterns ): array {
			$post_types = $only_patterns ? [] : (array) $query_args['post_type'];
			if ( $with_patterns ) {
				$post_types[] = self::PATTERN_TYPE;
			}
			$query_args['post_type']        = array_values( array_unique( $post_types ) );
			$query_args['post_status']      = array_merge( [ 'publish' ], self::EXTRA_STATUSES );
			$query_args[ self::QUERY_FLAG ] = true;
			return $query_args;
		};

		if ( $type === 'post' ) {
			add_filter( 'rest_post_search_query', $widen_query );
			add_filter( 'posts_where', [ $this, 'limit_to_readable' ], 10, 2 );
		}

		try {
			$response = rest_do_request( $request );
		} finally {
			remove_filter( 'rest_post_search_query', $widen_query );
			remove_filter( 'posts_where', [ $this, 'limit_to_readable' ], 10 );
		}

		$data = rest_get_server()->response_to_data( $response, false );

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
			$id    = (int) ( $item['id'] ?? 0 );
			$title = html_entity_decode( (string) ( $item['title'] ?? '' ), ENT_QUOTES, 'UTF-8' );

			if ( $type === 'term' ) {
				// Core's term handler puts the taxonomy in `type` and sends no `subtype`.
				$results[] = [
					'id'      => $id,
					'title'   => $title,
					'url'     => (string) ( $item['url'] ?? '' ),
					'kind'    => 'term',
					'subtype' => (string) ( $item['type'] ?? '' ),
				];
				continue;
			}

			// The query already limits statuses per post type; this is the final word, as core's own caps.
			if ( ! current_user_can( 'read_post', $id ) ) {
				continue;
			}

			$post_type = (string) ( $item['subtype'] ?? '' );
			$results[] = [
				'id'      => $id,
				'title'   => $title,
				'url'     => is_post_type_viewable( $post_type ) ? (string) ( $item['url'] ?? '' ) : '',
				'kind'    => $this->kind_of( $post_type ),
				'subtype' => $post_type,
				'status'  => (string) get_post_status( $id ),
			];
		}

		$headers = $response->get_headers();

		return [
			$results,
			isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $results ),
			isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1,
		];
	}
	/**
	 * Limit the marked search query to the statuses the user may read, per post type.
	 *
	 * Mirrors core's `read_post` map: published if the type is readable, own items in any
	 * status, others' private items with `read_private_posts`, others' drafts, pending and
	 * scheduled items with `edit_others_posts`. Public so it can be a filter callback.
	 *
	 * @param string   $where The WHERE clause.
	 * @param WP_Query $query The query.
	 * @return string The WHERE clause.
	 */
	public function limit_to_readable( string $where, WP_Query $query ): string {
		global $wpdb;

		if ( ! $query->get( self::QUERY_FLAG ) ) {
			return $where;
		}

		$own     = $wpdb->prepare( "{$wpdb->posts}.post_author = %d", get_current_user_id() );
		$hidden  = "{$wpdb->posts}.post_status IN ('" . implode( "','", self::EXTRA_STATUSES ) . "')";
		$clauses = [];

		foreach ( (array) $query->get( 'post_type' ) as $post_type ) {
			$object = get_post_type_object( (string) $post_type );
			if ( ! $object || ! current_user_can( $object->cap->read ) ) {
				continue;
			}

			$readable = [ "{$wpdb->posts}.post_status = 'publish'", "({$own} AND {$hidden})" ];
			if ( current_user_can( $object->cap->read_private_posts ) ) {
				$readable[] = "{$wpdb->posts}.post_status = 'private'";
			}
			if ( current_user_can( $object->cap->edit_others_posts ) ) {
				$readable[] = "{$wpdb->posts}.post_status IN ('future','draft','pending')";
			}

			$clauses[] = $wpdb->prepare( "({$wpdb->posts}.post_type = %s", $object->name ) . ' AND (' . implode( ' OR ', $readable ) . '))';
		}

		return $where . ( $clauses ? ' AND (' . implode( ' OR ', $clauses ) . ')' : ' AND 1=0' );
	}

	/**
	 * Search template parts, from the theme's files and the database, through core's route.
	 *
	 * Core's route checks the user may see templates and has no search, so the words are
	 * matched here against the title and the text, the way WordPress search matches posts.
	 *
	 * @param string $search Search words.
	 * @return array<int, array<string, mixed>> Matching template parts.
	 */
	private function search_template_parts( string $search ): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/template-parts' ) );
		if ( $response->is_error() ) {
			return [];
		}

		$terms = $this->parse_search( $search );
		$found = [];
		foreach ( (array) $response->get_data() as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}
			$title = (string) ( $part['title']['rendered'] ?? $part['title']['raw'] ?? '' );
			$text  = $title . ' ' . wp_strip_all_tags( (string) ( $part['content']['raw'] ?? '' ) );

			if ( ! $this->matches( $text, $terms ) ) {
				continue;
			}

			$found[] = [
				'id'      => (string) ( $part['id'] ?? '' ),
				'title'   => html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ),
				'url'     => '',
				'kind'    => 'template_part',
				'subtype' => self::TEMPLATE_PART_TYPE,
				'status'  => (string) ( $part['status'] ?? 'publish' ),
			];
		}

		return $found;
	}

	/**
	 * Split search words as WP_Query does: quoted phrases kept whole, a leading - excludes.
	 *
	 * @param string $search Search words.
	 * @return array{include: string[], exclude: string[]} Words to match and to leave out.
	 */
	private function parse_search( string $search ): array {
		$terms  = [
			'include' => [],
			'exclude' => [],
		];
		$prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, read so template parts honour it too.

		preg_match_all( '/".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/', stripslashes( $search ), $matches );
		foreach ( $matches[0] as $term ) {
			$term = trim( $term, "\"' \n\r" );
			if ( $term === '' ) {
				continue;
			}
			if ( $prefix && str_starts_with( $term, $prefix ) && strlen( $term ) > strlen( $prefix ) ) {
				$terms['exclude'][] = substr( $term, strlen( $prefix ) );
			} else {
				$terms['include'][] = $term;
			}
		}

		return $terms;
	}

	/**
	 * Whether the text holds every included word and none of the excluded ones.
	 *
	 * @param string                                      $text  Text to search.
	 * @param array{include: string[], exclude: string[]} $terms Parsed search words.
	 * @return bool
	 */
	private function matches( string $text, array $terms ): bool {
		if ( ! $terms['include'] ) {
			return false;
		}
		foreach ( $terms['include'] as $term ) {
			if ( mb_stripos( $text, $term ) === false ) {
				return false;
			}
		}
		foreach ( $terms['exclude'] as $term ) {
			if ( mb_stripos( $text, $term ) !== false ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The kind of a post type, which tells the assistant which ability changes it.
	 *
	 * @param string $post_type Post type.
	 * @return string post, page, pattern, or other.
	 */
	private function kind_of( string $post_type ): string {
		return match ( $post_type ) {
			'post', 'page'     => $post_type,
			self::PATTERN_TYPE => 'pattern',
			default            => 'other',
		};
	}
}
