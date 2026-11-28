<?php
/**
 * What each connected client says about itself
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.7.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\OAuth\Server\ConnectionContext;
use WP_REST_Request;

/**
 * Records, per connection, the protocol version, client capabilities and client info a client sends.
 *
 * Which clients would see native MCP skills is not knowable in advance: it
 * takes protocol 2026-07-28 and the `io.modelcontextprotocol/skills`
 * capability, declared per request in `_meta` or at `initialize`. This keeps
 * the latest of each per connection so that decision rests on real clients.
 * Shown in Site Health > Info. Holds no personal data: client info is the
 * app's own name and version.
 *
 * @since 1.7.0
 */
class ClientDiagnostics implements Hookable {

	/**
	 * Option holding the records, keyed by client id.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const OPTION = 'albert_client_diagnostics';

	/**
	 * Connections kept; the least recently seen go first.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const MAX_CLIENTS = 50;

	/**
	 * How often an unchanged record has its last-seen time refreshed, in seconds.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private const TOUCH_INTERVAL = 3600;

	/**
	 * The `_meta` keys MCP 2026-07-28 puts on every request.
	 *
	 * @since 1.7.0
	 * @var array<string, string>
	 */
	private const META_KEYS = [
		'protocol'     => 'io.modelcontextprotocol/protocolVersion',
		'capabilities' => 'io.modelcontextprotocol/clientCapabilities',
		'client'       => 'io.modelcontextprotocol/clientInfo',
	];

	/**
	 * Register the hooks.
	 *
	 * After the permission check, so `ConnectionContext` names the connection.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_filter( 'rest_request_before_callbacks', [ $this, 'observe' ], 10, 3 );
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );
	}

	/**
	 * Note what a request to Albert's MCP route says about its client.
	 *
	 * @param mixed                                 $response The response so far, passed through.
	 * @param mixed                                 $handler  The route handler.
	 * @param WP_REST_Request<array<string, mixed>> $request  The request.
	 *
	 * @return mixed The response, unchanged.
	 * @since 1.7.0
	 */
	public function observe( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		$client_id = ConnectionContext::client_id();

		if ( $client_id === null || ! $request instanceof WP_REST_Request || $request->get_route() !== '/' . Plugin::rest_namespace() . '/' . Server::ROUTE ) {
			return $response;
		}

		$body = json_decode( (string) $request->get_body(), true );
		$seen = self::read( is_array( $body ) ? $body : [], (string) $request->get_header( 'mcp-protocol-version' ) );

		if ( $seen !== [] ) {
			$this->remember( $client_id, (string) ConnectionContext::client_name(), $seen );
		}

		return $response;
	}

	/**
	 * What one JSON-RPC message says about its client.
	 *
	 * @param array<mixed> $message JSON-RPC message (a batch takes its first).
	 * @param string       $header  The `MCP-Protocol-Version` header, or empty.
	 *
	 * @return array{protocol?: string, capabilities?: list<string>, skills?: bool, client?: string}
	 * @since 1.7.0
	 */
	public static function read( array $message, string $header = '' ): array {
		if ( array_is_list( $message ) ) {
			$message = is_array( $message[0] ?? null ) ? $message[0] : [];
		}

		$params = is_array( $message['params'] ?? null ) ? $message['params'] : [];
		$meta   = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : [];
		$seen   = [];

		$protocol = $meta[ self::META_KEYS['protocol'] ] ?? ( ( $message['method'] ?? '' ) === 'initialize' ? ( $params['protocolVersion'] ?? null ) : null ) ?? ( $header !== '' ? $header : null );
		if ( is_string( $protocol ) && $protocol !== '' ) {
			$seen['protocol'] = sanitize_text_field( $protocol );
		}

		$capabilities = $meta[ self::META_KEYS['capabilities'] ] ?? ( ( $message['method'] ?? '' ) === 'initialize' ? ( $params['capabilities'] ?? null ) : null );
		if ( is_array( $capabilities ) ) {
			$names                = self::capability_names( $capabilities );
			$seen['capabilities'] = $names;
			$seen['skills']       = in_array( 'extensions.io.modelcontextprotocol/skills', $names, true ) || in_array( 'io.modelcontextprotocol/skills', $names, true );
		}

		$client = $meta[ self::META_KEYS['client'] ] ?? ( ( $message['method'] ?? '' ) === 'initialize' ? ( $params['clientInfo'] ?? null ) : null );
		if ( is_array( $client ) && is_string( $client['name'] ?? null ) ) {
			$seen['client'] = sanitize_text_field( trim( $client['name'] . ' ' . ( is_string( $client['version'] ?? null ) ? $client['version'] : '' ) ) );
		}

		return $seen;
	}

	/**
	 * The records, most recently seen first.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.7.0
	 */
	public static function all(): array {
		$records = get_option( self::OPTION, [] );
		if ( ! is_array( $records ) ) {
			return [];
		}

		uasort( $records, static fn( $a, $b ): int => (int) ( $b['seen_at'] ?? 0 ) <=> (int) ( $a['seen_at'] ?? 0 ) );

		return $records;
	}

	/**
	 * List the records in Site Health > Info.
	 *
	 * @param mixed $info Debug information sections.
	 *
	 * @return mixed
	 * @since 1.7.0
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$lines = [];
		foreach ( self::all() as $record ) {
			$lines[] = sprintf(
				/* translators: 1: connection name, 2: app name and version, 3: protocol version, 4: yes or no, 5: date. */
				__( '%1$s (%2$s): protocol %3$s, supports skills: %4$s, last seen %5$s', 'albert-ai-butler' ),
				$record['name'] ?? '?',
				$record['client'] ?? '?',
				$record['protocol'] ?? '?',
				! empty( $record['skills'] ) ? __( 'yes', 'albert-ai-butler' ) : __( 'no', 'albert-ai-butler' ),
				wp_date( 'Y-m-d H:i', (int) ( $record['seen_at'] ?? 0 ) )
			);
		}

		$info['albert']['fields']['client_diagnostics'] = [
			'label' => __( 'What connected assistants report', 'albert-ai-butler' ),
			'value' => $lines === [] ? __( 'No assistant has connected yet.', 'albert-ai-butler' ) : implode( "\n", $lines ),
		];

		if ( ! isset( $info['albert']['label'] ) ) {
			$info['albert']['label'] = __( 'Albert', 'albert-ai-butler' );
		}

		return $info;
	}

	/**
	 * Store what was seen, writing only when it changed or the record is old.
	 *
	 * A capability set is only replaced by another one: most requests carry
	 * none, and must not erase what `initialize` declared.
	 *
	 * @param string               $client_id The connection.
	 * @param string               $name      Its name.
	 * @param array<string, mixed> $seen      What this request said.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	private function remember( string $client_id, string $name, array $seen ): void {
		$records = get_option( self::OPTION, [] );
		$records = is_array( $records ) ? $records : [];
		$old     = is_array( $records[ $client_id ] ?? null ) ? $records[ $client_id ] : [];
		$now     = time();

		$record            = array_merge( $old, $seen );
		$record['name']    = $name !== '' ? $name : ( $old['name'] ?? $client_id );
		$record['seen_at'] = $old['seen_at'] ?? $now;

		$changed = array_diff_key( $old, [ 'seen_at' => 0 ] ) !== array_diff_key( $record, [ 'seen_at' => 0 ] );
		if ( ! $changed && $now - (int) $record['seen_at'] < self::TOUCH_INTERVAL ) {
			return;
		}

		$record['seen_at']     = $now;
		$records[ $client_id ] = $record;

		if ( count( $records ) > self::MAX_CLIENTS ) {
			uasort( $records, static fn( $a, $b ): int => (int) ( $b['seen_at'] ?? 0 ) <=> (int) ( $a['seen_at'] ?? 0 ) );
			$records = array_slice( $records, 0, self::MAX_CLIENTS, true );
		}

		update_option( self::OPTION, $records, false );
	}

	/**
	 * Dotted names of the capabilities a client declares, without their settings.
	 *
	 * `extensions` is listed one level deeper, where each extension is named.
	 *
	 * @param array<mixed> $capabilities The capabilities object.
	 *
	 * @return list<string>
	 * @since 1.7.0
	 */
	private static function capability_names( array $capabilities ): array {
		$names = [];
		foreach ( $capabilities as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$names[] = $key;
			if ( $key === 'extensions' && is_array( $value ) ) {
				foreach ( array_keys( $value ) as $extension ) {
					if ( is_string( $extension ) ) {
						$names[] = 'extensions.' . $extension;
					}
				}
			}
		}

		sort( $names );

		return $names;
	}
}
