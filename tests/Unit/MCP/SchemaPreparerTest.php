<?php
/**
 * Unit tests for SchemaPreparer — client-facing JSON Schema preparation.
 *
 * SchemaPreparer runs outgoing schemas through wp_prepare_json_schema_for_client()
 * on WordPress 7.1+. That function does not exist on the test runner, so it is
 * shadowed here inside the Albert\MCP namespace (a faithful stand-in that strips
 * the same server-only keys), while WpCompat's 7.1 detection is toggled through
 * the shared function_exists shadow.
 *
 * @package Albert\Tests\Unit\MCP
 */

namespace Albert\MCP;

/**
 * Namespaced shadow of the 7.1-only wp_prepare_json_schema_for_client().
 *
 * Mirrors the real behaviour the live 7.1 check confirmed: recursively remove
 * the server-only keys, leaving client-relevant ones in place.
 *
 * @param array<string, mixed> $schema Schema to prepare.
 * @return array<string, mixed>
 */
function wp_prepare_json_schema_for_client( array $schema ): array {
	$server_only = [ 'sanitize_callback', 'validate_callback', 'arg_options', 'readonly' ];

	$walk = static function ( $node ) use ( &$walk, $server_only ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		foreach ( $server_only as $key ) {
			unset( $node[ $key ] );
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = $walk( $value );
			}
		}
		return $node;
	};

	return $walk( $schema );
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- The block above declares a stub inside the vendored MCP namespace, which has to be in the same file as the test that needs it: the stub must exist before the class under test is autoloaded.
namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/function-exists-shadow.php';

use Albert\MCP\SchemaPreparer;
use Albert\MCP\Server;
use WP\MCP\Core\McpServer;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * SchemaPreparer tests.
 *
 * @covers \Albert\MCP\SchemaPreparer
 */
class SchemaPreparerTest extends TestCase {

	/**
	 * A schema whose input parameter carries server-only keys.
	 *
	 * @return array<string, mixed>
	 */
	private function schema_with_server_keys(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'type'              => 'string',
					'description'       => 'The title',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_value_from_schema',
					'arg_options'       => [ 'sanitize_callback' => 'x' ],
				],
			],
			'required'   => [ 'title' ],
		];
	}

	/**
	 * Another plugin's MCP server is left alone.
	 *
	 * WooCommerce and others bundle the same adapter under the same namespace, so
	 * every server is an `instanceof McpServer` and only the id tells them apart.
	 * Matching on the class alone meant Albert rewrote their results.
	 *
	 * @return void
	 */
	public function test_another_plugins_server_is_untouched(): void {
		$this->set_71( true );

		$result   = [ 'input_schema' => $this->schema_with_server_keys() ];
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter-get-ability-info', null, $this->server( 'woo' ) );

		$this->assertSame( $result, $prepared );
	}

	/**
	 * Make WpCompat report the 7.1 schema-prep capability as present/absent.
	 *
	 * @param bool $available Whether the 7.1 function should be seen.
	 * @return void
	 */
	private function set_71( bool $available ): void {
		$GLOBALS['albert_test_fn_exists']['wp_prepare_json_schema_for_client'] = $available;
	}

	/**
	 * A stand-in for Albert's own MCP server, which the filters require before
	 * acting (so they never touch another plugin's MCP server).
	 *
	 * @return McpServer
	 */
	private function server( string $id = Server::SERVER_ID ): McpServer {
		$server = $this->createMock( McpServer::class );
		$server->method( 'get_server_id' )->willReturn( $id );

		return $server;
	}

	/**
	 * Reset capability overrides before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_fn_exists'] = [];
	}

	/**
	 * Clear overrides after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_fn_exists'] );
		parent::tearDown();
	}




	/**
	 * On 7.1 the get-ability-info result has both embedded schemas stripped.
	 *
	 * @return void
	 */
	public function test_ability_info_strips_both_schemas_on_71(): void {
		$this->set_71( true );

		$result = [
			'name'          => 'albert/demo',
			'input_schema'  => $this->schema_with_server_keys(),
			'output_schema' => $this->schema_with_server_keys(),
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info', null, $this->server() );

		$this->assertArrayNotHasKey( 'sanitize_callback', $prepared['input_schema']['properties']['title'] );
		$this->assertArrayNotHasKey( 'validate_callback', $prepared['output_schema']['properties']['title'] );
	}

	/**
	 * A tool other than get-ability-info is passed through untouched.
	 *
	 * @return void
	 */
	public function test_ability_info_ignores_other_tools(): void {
		$this->set_71( true );

		$result   = [ 'input_schema' => $this->schema_with_server_keys() ];
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'albert/find-posts', null, $this->server() );

		$this->assertSame( $result, $prepared );
	}

	/**
	 * A WP_Error result (a failed call) is passed through untouched.
	 *
	 * @return void
	 */
	public function test_ability_info_passes_through_wp_error(): void {
		$this->set_71( true );

		$error    = new WP_Error( 'nope', 'No.' );
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $error, [], 'mcp-adapter/get-ability-info', null, $this->server() );

		$this->assertSame( $error, $prepared );
	}

	/**
	 * Below 7.1 the get-ability-info schemas keep their server-only keys.
	 *
	 * @return void
	 */
	public function test_ability_info_keeps_server_keys_below_71(): void {
		$this->set_71( false );

		$result   = [ 'input_schema' => $this->schema_with_server_keys() ];
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter-get-ability-info', null, $this->server() );

		$this->assertArrayHasKey( 'sanitize_callback', $prepared['input_schema']['properties']['title'] );
	}

	/**
	 * The get-ability-info tool is recognised by the name a client actually sends.
	 *
	 * A slash is not legal in an MCP tool name, so the adapter advertises the
	 * meta-tool as `mcp-adapter-get-ability-info` and that is the name that
	 * arrives on the filter. Matching only the slash spelling matched nothing.
	 *
	 * @return void
	 */
	public function test_ability_info_matches_the_sanitised_tool_name(): void {
		$this->set_71( true );

		$result   = [ 'input_schema' => $this->schema_with_server_keys() ];
		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter-get-ability-info', null, $this->server() );

		$this->assertArrayNotHasKey( 'sanitize_callback', $prepared['input_schema']['properties']['title'] );
	}

	/**
	 * An object schema's empty default is emitted as `{}`, not `[]`.
	 *
	 * BaseAbility gives every object-typed input schema a top-level default of
	 * an empty PHP array so a no-argument call is rescued rather than rejected.
	 * Encoded, that read as `"default": []` on a schema declaring
	 * `"type": "object"` — a contradiction for a strict consumer.
	 *
	 * @return void
	 */
	public function test_object_default_is_emitted_as_an_object(): void {
		$this->set_71( false );

		$schema              = $this->schema_with_server_keys();
		$schema['default']   = [];
		$result              = [ 'input_schema' => $schema ];
		$prepared            = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter-get-ability-info', null, $this->server() );
		$encoded             = json_decode( (string) wp_json_encode( $prepared['input_schema'] ), true );
		$encoded_default_raw = json_decode( (string) wp_json_encode( $prepared['input_schema'] ) );

		$this->assertSame( [], $encoded['default'] );
		$this->assertInstanceOf( \stdClass::class, $encoded_default_raw->default );
	}

	/**
	 * An array-typed default of `[]` is genuinely a list and is left alone.
	 *
	 * @return void
	 */
	public function test_array_default_stays_an_array(): void {
		$this->set_71( false );

		$result = [
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'fields' => [
						'type'    => 'array',
						'default' => [],
					],
				],
			],
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter-get-ability-info', null, $this->server() );
		$decoded  = json_decode( (string) wp_json_encode( $prepared['input_schema'] ) );

		$this->assertIsArray( $decoded->properties->fields->default );
	}

	/**
	 * A non-empty object default is left exactly as declared.
	 *
	 * @return void
	 */
	public function test_non_empty_object_default_is_untouched(): void {
		$this->set_71( false );

		$result = [
			'input_schema' => [
				'type'    => 'object',
				'default' => [ 'taxonomy' => 'category' ],
			],
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter-get-ability-info', null, $this->server() );

		$this->assertSame( [ 'taxonomy' => 'category' ], $prepared['input_schema']['default'] );
	}

	/**
	 * A call from something that is not an MCP server (or none) is left alone.
	 *
	 * @return void
	 */
	public function test_ignores_foreign_or_missing_server(): void {
		$this->set_71( true );

		$result = [ 'input_schema' => $this->schema_with_server_keys() ];

		$this->assertSame( $result, ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info', null, new \stdClass() ) );
		$this->assertSame( $result, ( new SchemaPreparer() )->prepare_ability_info_result( $result, [], 'mcp-adapter/get-ability-info', null, null ) );
	}

	/**
	 * A schema value that cannot be JSON-encoded (e.g. a callback closure) must
	 * not make preparation fail open and return the raw, unstripped schema.
	 *
	 * @return void
	 */
	public function test_does_not_fail_open_on_unencodable_value(): void {
		$this->set_71( true );

		$schema = [
			'type'       => 'object',
			'properties' => [
				'title' => [
					'type'              => 'string',
					'sanitize_callback' => static function () {},
				],
			],
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result(
			[ 'input_schema' => $schema ],
			[],
			'mcp-adapter/get-ability-info',
			null,
			$this->server()
		);

		$this->assertArrayNotHasKey( 'sanitize_callback', $prepared['input_schema']['properties']['title'] );
		$this->assertSame( 'string', $prepared['input_schema']['properties']['title']['type'] );
	}

	/**
	 * A nested empty object map (`properties: {}`) must survive as an object,
	 * not collapse to an array that would serialise as invalid `[]`.
	 *
	 * @return void
	 */
	public function test_empty_nested_object_map_stays_object(): void {
		$this->set_71( true );

		$schema = [
			'type'       => 'object',
			'properties' => [
				'meta' => [
					'type'       => 'object',
					'properties' => [],
				],
			],
		];

		$prepared = ( new SchemaPreparer() )->prepare_ability_info_result(
			[ 'input_schema' => $schema ],
			[],
			'mcp-adapter/get-ability-info',
			null,
			$this->server()
		);

		$meta_properties = $prepared['input_schema']['properties']['meta']['properties'];
		$this->assertInstanceOf( \stdClass::class, $meta_properties );
		$this->assertStringContainsString(
			'"properties":{}',
			(string) wp_json_encode( $prepared['input_schema']['properties']['meta'] )
		);
	}


}
