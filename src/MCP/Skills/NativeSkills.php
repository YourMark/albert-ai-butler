<?php
/**
 * Native MCP skills: the `io.modelcontextprotocol/skills` extension on Albert's route.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.7.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\MCP\Server;
use Albert\OAuth\Server\ConnectionContext;
use WP\MCP\Core\McpAdapter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves the registry's skills natively to clients on MCP 2026-07-28.
 *
 * The bundled adapter routes a fixed list of methods, so this answers on the
 * REST route after the adapter has: it adds the extension to `server/discover`
 * for a client that declares it, answers `skills/list` and `skills/get` where
 * the adapter said "method not found", and answers `resources/read` for an
 * Albert skill's `skill://` URI where the adapter found no resource. Whatever
 * the adapter answers itself is left alone, so a copy that serves skills makes
 * this stand down without a version check. Every other client keeps the
 * index in `discover-abilities`, `get-skill` and the prompts. A bridge: delete
 * it when the adapter routes the extension (mcp-adapter#428).
 *
 * @since 1.7.0
 */
class NativeSkills implements Hookable {

	/**
	 * The extension's identifier.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const EXTENSION = 'io.modelcontextprotocol/skills';

	/**
	 * The only protocol revision the extension exists in.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const REVISION = '2026-07-28';

	/**
	 * How long a client may treat a listing or file as current, in milliseconds.
	 *
	 * Short: an owner can switch a skill off or edit it at any time.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const TTL_MS = 60000;

	/**
	 * Skills per `skills/list` page.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const PAGE_SIZE = 50;

	/**
	 * JSON-RPC: invalid params, also an unknown skill or file.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private const INVALID_PARAMS = -32602;

	/**
	 * JSON-RPC: method not found.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private const METHOD_NOT_FOUND = -32601;

	/**
	 * JSON-RPC: internal error.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private const INTERNAL_ERROR = -32603;

	/**
	 * Register the hooks.
	 *
	 * After the callbacks: the permission check has set the user and the
	 * connection, and the adapter's answer shows what it serves itself.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_filter( 'rest_request_after_callbacks', [ $this, 'respond' ], 10, 3 );
	}

	/**
	 * Add to or replace the adapter's answer for a native-skills request.
	 *
	 * @param mixed                                 $response The adapter's response.
	 * @param mixed                                 $handler  The route handler.
	 * @param WP_REST_Request<array<string, mixed>> $request  The request.
	 *
	 * @return mixed The response, changed only for a native-skills request.
	 * @since 1.7.0
	 */
	public function respond( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$message = self::message( $request );
		if ( $message === null || ! self::enabled() ) {
			return $response;
		}

		$id     = $message['id'];
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : [];
		$answer = $this->decode( $response->get_data() );

		switch ( $message['method'] ) {
			case 'server/discover':
				if ( self::client_declares_extension( $params ) && isset( $answer->result->capabilities ) ) {
					$this->declare_extension( $response, $answer );
				}
				return $response;

			case 'skills/list':
			case 'skills/get':
				if ( self::error_code( $answer ) !== self::METHOD_NOT_FOUND ) {
					return $response;
				}
				return $this->reply( $response, $id, fn(): array => $message['method'] === 'skills/list' ? $this->list_skills( $params ) : $this->get_skill( $params ) );

			case 'resources/read':
				$uri   = is_string( $params['uri'] ?? null ) ? $params['uri'] : '';
				$skill = self::skill_at( $uri );
				if ( $skill === null || ! self::adapter_found_no_resource( $answer, $uri ) ) {
					return $response;
				}
				return $this->reply( $response, $id, fn(): array => $this->read( $skill, $uri ) );
		}

		return $response;
	}

	/**
	 * The skill entry clients receive: its SKILL.md URI, frontmatter and file manifest.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return array{uri: string, frontmatter: array{name: string, description: string}, resources: list<array{uri: string, digest: string, size: int}>}|null Null when the skill cannot be served.
	 * @since 1.7.0
	 */
	public static function entry( Skill $skill ): ?array {
		$document = self::document( $skill );
		if ( $document === null ) {
			return null;
		}

		$uri = self::uri( $skill );

		return [
			'uri'         => $uri,
			'frontmatter' => self::frontmatter( $skill ),
			'resources'   => [
				[
					'uri'    => $uri,
					'digest' => 'sha256:' . hash( 'sha256', $document ),
					'size'   => strlen( $document ),
				],
			],
		];
	}

	/**
	 * The SKILL.md clients read: spec frontmatter and the body.
	 *
	 * Built rather than read from disk so the frontmatter is exactly the
	 * entry's, without Albert's `metadata` keys, which mean nothing to a client.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return string|null Null when the skill has no valid name, description or body.
	 * @since 1.7.0
	 */
	public static function document( Skill $skill ): ?string {
		$body = $skill->body();
		if ( ! self::is_servable( $skill ) || $body === '' ) {
			return null;
		}

		$frontmatter = self::frontmatter( $skill );

		// A JSON string is a valid YAML double-quoted scalar, so any description survives.
		return "---\nname: " . $frontmatter['name']
			. "\ndescription: " . wp_json_encode( $frontmatter['description'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. "\n---\n\n" . $body . "\n";
	}

	/**
	 * The `skill://` URI of a skill's SKILL.md.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public static function uri( Skill $skill ): string {
		return 'skill://' . $skill->slug() . '/SKILL.md';
	}

	/**
	 * Whether native skills are on for this request.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private static function enabled(): bool {
		$client_id = ConnectionContext::client_id();

		/**
		 * Filters whether Albert serves skills natively over MCP.
		 *
		 * Only clients on protocol 2026-07-28 that declare the skills extension
		 * are affected; return false and they get skills the way every other
		 * client does. The index in the discovery response stays either way.
		 *
		 * @since 1.7.0
		 *
		 * @param bool       $enabled    Default true.
		 * @param int        $user_id    The current user.
		 * @param array|null $connection `client_id` and `client_name`, or null outside a connection.
		 */
		return apply_filters(
			'albert/skills/native',
			true,
			get_current_user_id(),
			$client_id === null ? null : [
				'client_id'   => $client_id,
				'client_name' => (string) ConnectionContext::client_name(),
			]
		) === true;
	}

	/**
	 * The JSON-RPC request, when it is one this class may answer.
	 *
	 * Albert's route, an OAuth connection, and revision 2026-07-28 in both the
	 * header and the body. Anything malformed is the adapter's to reject.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 *
	 * @return array{id: string|int, method: string, params: mixed}|null
	 * @since 1.7.0
	 */
	private static function message( WP_REST_Request $request ): ?array {
		if (
			$request->get_method() !== 'POST'
			|| $request->get_route() !== '/' . Plugin::rest_namespace() . '/' . Server::ROUTE
			|| ConnectionContext::client_id() === null
			|| $request->get_header( 'mcp-protocol-version' ) !== self::REVISION
		) {
			return null;
		}

		$message = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $message ) || array_is_list( $message ) || ! is_string( $message['method'] ?? null ) ) {
			return null;
		}

		$id   = $message['id'] ?? null;
		$meta = $message['params']['_meta'] ?? null;
		if ( ( ! is_string( $id ) && ! is_int( $id ) ) || ! is_array( $meta ) || ( $meta['io.modelcontextprotocol/protocolVersion'] ?? null ) !== self::REVISION ) {
			return null;
		}

		return [
			'id'     => $id,
			'method' => $message['method'],
			'params' => $message['params'],
		];
	}

	/**
	 * Whether the request's client capabilities name the extension.
	 *
	 * @param array<string, mixed> $params Request params.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private static function client_declares_extension( array $params ): bool {
		$extensions = $params['_meta']['io.modelcontextprotocol/clientCapabilities']['extensions'] ?? null;

		return is_array( $extensions ) && array_key_exists( self::EXTENSION, $extensions );
	}

	/**
	 * Add the extension to the adapter's discovery result.
	 *
	 * Left alone when the adapter declares it already: it serves skills itself.
	 * Not declared when no skill is servable.
	 *
	 * @param WP_REST_Response $response The response to change.
	 * @param \stdClass        $answer   Its decoded body.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	private function declare_extension( WP_REST_Response $response, \stdClass $answer ): void {
		$capabilities = $answer->result->capabilities;

		if ( isset( $capabilities->extensions->{self::EXTENSION} ) || self::servable() === [] ) {
			return;
		}

		if ( ! isset( $capabilities->resources ) ) {
			$capabilities->resources = new \stdClass();
		}
		$extensions = isset( $capabilities->extensions ) && $capabilities->extensions instanceof \stdClass ? $capabilities->extensions : new \stdClass();

		// An empty object: supported, without `directoryRead`.
		$extensions->{self::EXTENSION} = new \stdClass();
		$capabilities->extensions      = $extensions;

		$response->set_data( $answer );
	}

	/**
	 * The `skills/list` result.
	 *
	 * @param array<string, mixed> $params Request params.
	 *
	 * @return array<string, mixed> A result, or an `error`.
	 * @since 1.7.0
	 */
	private function list_skills( array $params ): array {
		$cursor = $params['cursor'] ?? null;
		if ( $cursor !== null && ( ! is_string( $cursor ) || ! ctype_digit( $cursor ) ) ) {
			return self::error( self::INVALID_PARAMS, __( 'The cursor is not one this server issued.', 'albert-ai-butler' ) );
		}

		$entries = array_values( self::servable() );
		$offset  = (int) $cursor;
		$result  = [
			'resultType' => 'complete',
			'skills'     => array_slice( $entries, $offset, self::PAGE_SIZE ),
		];

		if ( $offset + self::PAGE_SIZE < count( $entries ) ) {
			$result['nextCursor'] = (string) ( $offset + self::PAGE_SIZE );
		}

		return self::cacheable( $result );
	}

	/**
	 * The `skills/get` result.
	 *
	 * @param array<string, mixed> $params Request params.
	 *
	 * @return array<string, mixed> A result, or an `error`.
	 * @since 1.7.0
	 */
	private function get_skill( array $params ): array {
		$uri   = is_string( $params['uri'] ?? null ) ? $params['uri'] : '';
		$skill = self::skill_at( $uri );
		$entry = $skill !== null && str_ends_with( $uri, '/SKILL.md' ) ? self::entry( $skill ) : null;

		if ( $entry === null ) {
			return self::not_served( $uri );
		}

		return self::cacheable(
			[
				'resultType' => 'complete',
				'skill'      => $entry,
			]
		);
	}

	/**
	 * The `resources/read` result for a skill file.
	 *
	 * Reading is not loading, the spec says, but it is the moment the body
	 * reaches the client, so it counts as served.
	 *
	 * @param Skill  $skill The skill.
	 * @param string $uri   The file asked for.
	 *
	 * @return array<string, mixed> A result, or an `error`.
	 * @since 1.7.0
	 */
	private function read( Skill $skill, string $uri ): array {
		$document = self::document( $skill );
		if ( $document === null ) {
			return self::not_served( $uri );
		}

		self::served( $skill );

		return self::cacheable(
			[
				'resultType' => 'complete',
				'contents'   => [
					[
						'uri'      => $uri,
						'mimeType' => 'text/markdown',
						'text'     => $document,
					],
				],
			]
		);
	}

	/**
	 * Entries for every available skill that can be served, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.7.0
	 */
	private static function servable(): array {
		$entries = [];

		foreach ( SkillRegistry::available() as $slug => $skill ) {
			$entry = self::entry( $skill );
			if ( $entry !== null ) {
				$entries[ $slug ] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * The available skill a URI belongs to.
	 *
	 * Only `skill://<name>/SKILL.md`: Albert's skills are one file each.
	 *
	 * @param string $uri A resource URI.
	 *
	 * @return Skill|null
	 * @since 1.7.0
	 */
	private static function skill_at( string $uri ): ?Skill {
		if ( ! preg_match( '#^skill://([^/]+)/SKILL\.md$#D', $uri, $match ) ) {
			return null;
		}

		return SkillRegistry::available()[ $match[1] ] ?? null;
	}

	/**
	 * Whether a skill meets the Agent Skills rules for `name` and `description`.
	 *
	 * A skill that does not still reaches clients through the index.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private static function is_servable( Skill $skill ): bool {
		$description = trim( $skill->summary() );

		return preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $skill->slug() ) === 1
			&& strlen( $skill->slug() ) <= 64
			&& $description !== ''
			&& mb_strlen( $description ) <= 1024;
	}

	/**
	 * The frontmatter clients see: the spec's two fields, none of Albert's.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return array{name: string, description: string}
	 * @since 1.7.0
	 */
	private static function frontmatter( Skill $skill ): array {
		return [
			'name'        => $skill->slug(),
			'description' => trim( $skill->summary() ),
		];
	}

	/**
	 * Tell listeners a skill reached an assistant natively.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	private static function served( Skill $skill ): void {
		/**
		 * Fires when a skill reaches an assistant.
		 *
		 * For usage insight. Not fired for the Skills screen or its preview.
		 *
		 * @since 1.7.0
		 *
		 * @param string               $slug    The skill.
		 * @param string               $route   How it reached the assistant; `native` here.
		 * @param int                  $user_id The current user.
		 * @param array<string, mixed> $context `client_id` and `client_name`; `digest`, the sha256 of the body.
		 */
		do_action(
			'albert/skills/served',
			$skill->slug(),
			'native',
			get_current_user_id(),
			[
				'client_id'   => ConnectionContext::client_id(),
				'client_name' => ConnectionContext::client_name(),
				'digest'      => hash( 'sha256', $skill->body() ),
			]
		);
	}

	/**
	 * Whether the adapter answered "no such resource" for this URI.
	 *
	 * @param \stdClass|null $answer The adapter's decoded answer.
	 * @param string         $uri    The URI asked for.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private static function adapter_found_no_resource( ?\stdClass $answer, string $uri ): bool {
		return in_array( self::error_code( $answer ), [ self::INVALID_PARAMS, -32002 ], true )
			&& ( $answer->error->data->uri ?? null ) === $uri;
	}

	/**
	 * Put a result or error into the response, as the adapter would send it.
	 *
	 * @param WP_REST_Response                 $response The response to replace.
	 * @param string|int                       $id       The request id.
	 * @param callable(): array<string, mixed> $build    Builds the result or an `error`.
	 *
	 * @return WP_REST_Response
	 * @since 1.7.0
	 */
	private function reply( WP_REST_Response $response, $id, callable $build ): WP_REST_Response {
		try {
			$result = $build();
		} catch ( \Throwable $e ) {
			$result = self::error( self::INTERNAL_ERROR, __( 'The skill could not be served.', 'albert-ai-butler' ) );
		}

		if ( isset( $result['error'] ) ) {
			$response->set_data(
				[
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => $result['error'],
				]
			);
			// The adapter's 2026-07-28 statuses: invalid params is 400.
			$response->set_status( $result['error']['code'] === self::INVALID_PARAMS ? 400 : 500 );

			return $response;
		}

		$result['_meta'] = [ 'io.modelcontextprotocol/serverInfo' => self::server_info() ];

		$response->set_data(
			[
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			]
		);
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Add the cache hints every skills result carries.
	 *
	 * @param array<string, mixed> $result The result.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	private static function cacheable( array $result ): array {
		return $result + [
			'ttlMs'      => self::TTL_MS,
			'cacheScope' => 'private',
		];
	}

	/**
	 * The error for a URI that names no skill or file Albert serves.
	 *
	 * @param string $uri The URI asked for.
	 *
	 * @return array{error: array<string, mixed>}
	 * @since 1.7.0
	 */
	private static function not_served( string $uri ): array {
		$error = self::error(
			self::INVALID_PARAMS,
			/* translators: %s: a skill URI, e.g. skill://menus/SKILL.md */
			sprintf( __( 'No skill is served at %s', 'albert-ai-butler' ), $uri )
		);
		$error['error']['data'] = [ 'uri' => $uri ];

		return $error;
	}

	/**
	 * A JSON-RPC error.
	 *
	 * @param int    $code    The code.
	 * @param string $message What went wrong.
	 *
	 * @return array{error: array<string, mixed>}
	 * @since 1.7.0
	 */
	private static function error( int $code, string $message ): array {
		return [
			'error' => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}

	/**
	 * The server name and version, as the adapter puts them on its own results.
	 *
	 * @return array{name: string, version: string}
	 * @since 1.7.0
	 */
	private static function server_info(): array {
		$server = class_exists( McpAdapter::class ) ? McpAdapter::instance()->get_server( Server::SERVER_ID ) : null;

		return [
			'name'    => $server !== null ? $server->get_server_name() : 'Albert',
			'version' => $server !== null ? $server->get_server_version() : ALBERT_VERSION,
		];
	}

	/**
	 * The adapter's response body as JSON objects, so empty objects stay objects.
	 *
	 * @param mixed $data The response data.
	 *
	 * @return \stdClass|null
	 * @since 1.7.0
	 */
	private function decode( $data ): ?\stdClass {
		$encoded = wp_json_encode( $data );
		$decoded = is_string( $encoded ) ? json_decode( $encoded ) : null;

		return $decoded instanceof \stdClass ? $decoded : null;
	}

	/**
	 * The JSON-RPC error code of a decoded answer, or 0 for a result.
	 *
	 * @param \stdClass|null $answer The decoded answer.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	private static function error_code( ?\stdClass $answer ): int {
		$code = $answer->error->code ?? 0;

		return is_int( $code ) ? $code : 0;
	}
}
