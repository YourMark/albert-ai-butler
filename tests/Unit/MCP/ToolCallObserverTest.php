<?php
/**
 * Unit tests for ToolCallObserver: guidance for a rejected execute-ability call.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\MCP\Server;
use Albert\MCP\ToolCallObserver;
use PHPUnit\Framework\TestCase;
use WP\MCP\Core\McpServer;
use WP_Error;

/**
 * ToolCallObserver unit tests.
 *
 * @covers \Albert\MCP\ToolCallObserver
 */
class ToolCallObserverTest extends TestCase {

	/**
	 * Observer under test.
	 *
	 * @var ToolCallObserver
	 */
	private ToolCallObserver $observer;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_abilities'] = [];

		$this->observer = new ToolCallObserver();
	}

	/**
	 * Build a mocked MCP server with a given id.
	 *
	 * Every plugin's server is an `instanceof McpServer` — they share one class —
	 * so a foreign server must be a real mock with a different id, not a stand-in
	 * object of another type, or the test passes against a guard that only checks
	 * the class.
	 *
	 * @param string $id The server id.
	 *
	 * @return McpServer
	 */
	private function server( string $id = Server::SERVER_ID ): McpServer {
		$server = $this->createMock( McpServer::class );
		$server->method( 'get_server_id' )->willReturn( $id );

		return $server;
	}

	/**
	 * Call the observer as the adapter does, on Albert's own server.
	 *
	 * @param mixed  $result    The tool result.
	 * @param mixed  $args      The tool arguments.
	 * @param string $tool_name The tool name.
	 *
	 * @return mixed
	 */
	private function handle( $result, $args, string $tool_name ): mixed {
		return $this->observer->handle( $result, $args, $tool_name, null, $this->server() );
	}

	/**
	 * The error execute-ability reports after the guidance is applied.
	 *
	 * @param string               $ability_name The ability the meta-tool was asked to run.
	 * @param array<string, mixed> $parameters   The input the caller sent.
	 * @param string               $error        The adapter's own error text.
	 *
	 * @return string
	 */
	private function proxied( string $ability_name, array $parameters, string $error = 'Invalid input.' ): string {
		$out = $this->handle(
			[
				'success' => false,
				'error'   => $error,
			],
			[
				'ability_name' => $ability_name,
				'parameters'   => $parameters,
			],
			'mcp-adapter-execute-ability'
		);

		return $out['error'];
	}

	/**
	 * A single missing required field becomes a concise message.
	 *
	 * @return void
	 */
	public function test_single_missing_required(): void {
		$this->register_create_post();

		$this->assertStringStartsWith(
			'Missing required parameter: `title`.',
			$this->proxied( 'albert/create-post', [ 'content' => 'x' ] )
		);
	}

	/**
	 * Multiple missing required fields are pluralised and listed.
	 *
	 * @return void
	 */
	public function test_multiple_missing_required(): void {
		$this->register_create_post( [ 'title', 'status' ] );

		$this->assertStringStartsWith(
			'Missing required parameters: `title`, `status`.',
			$this->proxied( 'albert/create-post', [] )
		);
	}

	/**
	 * Non-required validation reasons are surfaced directly.
	 *
	 * @return void
	 */
	public function test_other_validation_reason(): void {
		$this->register_create_post();

		$this->assertStringStartsWith(
			'Invalid parameters: input[title] is not of type string.',
			$this->proxied( 'albert/create-post', [ 'title' => 123 ] )
		);
	}

	/**
	 * A tool other than the meta-tools is not Albert's server's, so it passes through.
	 *
	 * @return void
	 */
	public function test_a_non_meta_tool_result_passes_through(): void {
		$this->register_create_post();
		$error = new WP_Error( 'ability_invalid_input', 'raw' );

		$this->assertSame( $error, $this->handle( $error, [ 'content' => 'x' ], 'albert/create-post' ) );
	}

	/**
	 * A meta-tool's own WP_Error is left untouched.
	 *
	 * @return void
	 */
	public function test_meta_tool_passthrough(): void {
		$error = new WP_Error( 'whatever', 'raw' );
		$out   = $this->handle( $error, [], 'mcp-adapter/execute-ability' );

		$this->assertSame( $error, $out );
	}

	/**
	 * Register a double shaped like albert/create-post.
	 *
	 * @param array<int, string> $required Required property names.
	 *
	 * @return void
	 */
	private function register_create_post( array $required = [ 'title' ] ): void {
		$this->register_ability(
			'albert/create-post',
			[
				'type'                 => 'object',
				'properties'           => [
					'title'   => [ 'type' => 'string' ],
					'status'  => [ 'type' => 'string' ],
					'content' => [ 'type' => 'string' ],
				],
				'required'             => $required,
				'additionalProperties' => false,
			]
		);
	}

	/**
	 * Register a test double for an ability with the given input schema.
	 *
	 * @param string               $name   Ability id.
	 * @param array<string, mixed> $schema Input schema the double reports.
	 *
	 * @return void
	 */
	private function register_ability( string $name, array $schema ): void {
		$GLOBALS['albert_test_abilities'][] = new \WP_Ability( $name, [], $schema );
	}

	/**
	 * Register a double shaped like albert/view-term.
	 *
	 * @return void
	 */
	private function register_view_term(): void {
		$this->register_ability(
			'albert/view-term',
			[
				'type'                 => 'object',
				'properties'           => [
					'id'       => [ 'type' => 'integer' ],
					'taxonomy' => [ 'type' => 'string' ],
				],
				'required'             => [ 'id' ],
				// As registered: BaseAbility::prepare_input_schema() closes
				// every object-typed input schema, so a double that leaves it
				// open is not the schema the observer meets in production.
				'additionalProperties' => false,
			]
		);
	}

	/**
	 * Input the schema does not recognise is named, not silently dropped.
	 *
	 * The reported case: `term_id` is what a caller reaches for after using an
	 * ability that spells it that way, and the old message named only `id`.
	 *
	 * @return void
	 */
	public function test_unrecognised_parameters_are_named(): void {
		$this->register_view_term();

		$out = $this->proxied(
			'albert/view-term',
			[
				'term_id'  => 76,
				'taxonomy' => 'category',
				'fields'   => 'all',
			]
		);

		$this->assertSame(
			'Missing required parameter: `id`. Unrecognised parameters: `term_id`, `fields`. '
			. 'Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out
		);
	}

	/**
	 * With nothing unrecognised, only the accepted list is added.
	 *
	 * @return void
	 */
	public function test_accepted_parameters_listed_without_unrecognised_line(): void {
		$this->register_view_term();

		$this->assertSame(
			'Missing required parameter: `id`. Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$this->proxied( 'albert/view-term', [ 'taxonomy' => 'category' ] )
		);
	}

	/**
	 * An ability that is not registered keeps the adapter's own message.
	 *
	 * The guidance is read off the registered schema, so without one there is
	 * nothing to say that is not a guess.
	 *
	 * @return void
	 */
	public function test_unregistered_ability_keeps_the_message(): void {
		$this->assertSame( 'raw', $this->proxied( 'albert/view-term', [ 'term_id' => 76 ], 'raw' ) );
	}

	/**
	 * A non-required validation reason still gains the parameter guidance.
	 *
	 * @return void
	 */
	public function test_other_reason_gains_parameter_guidance(): void {
		$this->register_view_term();

		$out = $this->proxied(
			'albert/view-term',
			[
				'id'     => 'seventy-six',
				'fields' => 'all',
			]
		);

		$this->assertStringContainsString( 'Invalid parameters: input[id] is not of type integer.', $out );
		$this->assertStringContainsString( 'Unrecognised parameters: `fields`.', $out );
	}

	/**
	 * A meta-tool failure that is not a validation rejection is left alone.
	 *
	 * @return void
	 */
	public function test_meta_tool_result_non_validation_error_untouched(): void {
		$result = [
			'success' => false,
			'error'   => "Ability 'albert/view-term' not found",
		];

		$out = $this->handle( $result, [ 'ability_name' => 'albert/view-term' ], 'mcp-adapter-execute-ability' );

		$this->assertSame( $result, $out );
	}

	/**
	 * A successful meta-tool result is passed through.
	 *
	 * @return void
	 */
	public function test_meta_tool_success_passthrough(): void {
		$result = [
			'success' => true,
			'data'    => [ 'term' => [ 'id' => 76 ] ],
		];

		$out = $this->handle( $result, [], 'mcp-adapter-execute-ability' );

		$this->assertSame( $result, $out );
	}

	/**
	 * A call whose only fault is an unrecognised parameter says just that.
	 *
	 * Core stops at the first property it does not recognise and phrases it as
	 * "fields is not a valid property of Object", which names one of them and
	 * offers no alternative. The message names them all, and what is accepted.
	 *
	 * @return void
	 */
	public function test_only_unrecognised_parameters(): void {
		$this->register_view_term();

		$out = $this->proxied(
			'albert/view-term',
			[
				'id'     => 76,
				'fields' => 'all',
				'depth'  => 2,
			]
		);

		$this->assertSame(
			'Unrecognised parameters: `fields`, `depth`. '
			. 'Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out
		);
	}

	/**
	 * The guidance does not depend on the error message at all.
	 *
	 * Everything it says is read off the schema and the input, so two calls that
	 * differ only in the text WordPress happened to produce must be identical.
	 * This is the regression test for building the message by matching core's
	 * prose: core translates every string that was once matched, so on a Dutch
	 * site the guidance disappeared entirely.
	 *
	 * @return void
	 */
	public function test_guidance_ignores_the_error_message_text(): void {
		$this->register_view_term();

		$messages = [
			'Ability "albert/view-term" has invalid input. Reason: id is a required property of input.',
			'Ability "albert/view-term" heeft ongeldige invoer. Reden: id is een vereiste eigenschap van invoer.',
			'',
		];

		$guidance = array_map(
			fn( string $message ): string => $this->proxied( 'albert/view-term', [ 'term_id' => 76 ], $message ),
			$messages
		);

		$this->assertCount( 1, array_unique( $guidance ) );
		$this->assertStringContainsString( 'Unrecognised parameters: `term_id`.', $guidance[0] );
	}

	/**
	 * The reported case, on a Dutch site, gets the full guidance.
	 *
	 * Whether it was an input rejection used to be decided by matching English
	 * text, so it improved nothing once that text was translated.
	 *
	 * @return void
	 */
	public function test_meta_tool_result_ignores_the_error_message_text(): void {
		$this->register_view_term();

		$out = $this->handle(
			[
				'success' => false,
				'error'   => 'Ability "albert/view-term" heeft ongeldige invoer. Reden: id is een vereiste eigenschap van invoer.',
			],
			[
				'ability_name' => 'albert/view-term',
				'parameters'   => [ 'term_id' => 76 ],
			],
			'mcp-adapter-execute-ability'
		);

		$this->assertSame(
			'Missing required parameter: `id`. Unrecognised parameters: `term_id`. '
			. 'Accepted parameters: `id` (integer, required), `taxonomy` (string).',
			$out['error']
		);
	}

	/**
	 * An undeclared name on an open schema is not called unrecognised.
	 *
	 * Naming a parameter unrecognised is a claim about the contract. A schema
	 * that opts back into extra input accepts it, so the claim would be false.
	 *
	 * @return void
	 */
	public function test_open_schema_does_not_name_extra_input_unrecognised(): void {
		$this->register_ability(
			'albert/open-term',
			[
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'required'             => [ 'id' ],
				'additionalProperties' => true,
			]
		);

		$out = $this->proxied( 'albert/open-term', [ 'term_id' => 76 ] );

		$this->assertStringNotContainsString( 'Unrecognised', $out );
		$this->assertStringContainsString( 'Missing required parameter: `id`.', $out );
	}

	/**
	 * An enum is named by its values, not by the type it narrows.
	 *
	 * The value a caller gets wrong is `published` for `publish`, and being told
	 * the parameter is a `string` does nothing to correct that.
	 *
	 * @return void
	 */
	public function test_accepted_parameters_name_enum_values(): void {
		$this->register_ability(
			'albert/create-thing',
			[
				'type'                 => 'object',
				'properties'           => [
					'title'  => [ 'type' => 'string' ],
					'status' => [
						'type' => 'string',
						'enum' => [ 'draft', 'publish' ],
					],
				],
				'required'             => [ 'title' ],
				'additionalProperties' => false,
			]
		);

		$out = $this->proxied( 'albert/create-thing', [ 'status' => 'published' ] );

		$this->assertStringContainsString( '`status` (draft|publish)', $out );
		$this->assertStringNotContainsString( '`status` (string', $out );
	}

	/**
	 * An ability that takes nothing says so, rather than saying nothing.
	 *
	 * A no-parameter ability declares `properties` as an empty map so it encodes
	 * as `{}` — which may reach us as a stdClass, and used to be read as "no
	 * schema to describe". That silenced the guidance in the one case where it
	 * is the entire answer: everything the caller sent is unrecognised.
	 *
	 * @return void
	 */
	public function test_an_ability_with_no_parameters_says_so(): void {
		$GLOBALS['albert_test_abilities'][] = new \WP_Ability(
			'albert/view-sessions',
			[],
			[
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			]
		);

		$this->assertSame(
			'Unrecognised parameters: `limit`. This ability accepts no parameters.',
			$this->proxied( 'albert/view-sessions', [ 'limit' => 10 ] )
		);
	}

	/**
	 * A key refused by a nested object is named with the path to it.
	 *
	 * Core's message drops the path — `company is not a valid property of
	 * Object` — so a caller cannot tell which object refused it, nor that the
	 * top level would refuse it too.
	 *
	 * @return void
	 */
	public function test_nested_unrecognised_keys_are_named_with_their_path(): void {
		$this->register_ability(
			'albert/update-order',
			[
				'type'                 => 'object',
				'properties'           => [
					'order_id' => [ 'type' => 'integer' ],
					'billing'  => [
						'type'                 => 'object',
						'properties'           => [
							'city'  => [ 'type' => 'string' ],
							'phone' => [ 'type' => 'string' ],
						],
						'additionalProperties' => false,
					],
				],
				'required'             => [ 'order_id' ],
				'additionalProperties' => false,
			]
		);

		$out = $this->proxied(
			'albert/update-order',
			[
				'order_id' => 1,
				'billing'  => [
					'company' => 'Acme BV',
					'city'    => 'Utrecht',
				],
			]
		);

		$this->assertSame(
			'Unrecognised parameters: `billing.company`. '
			. 'Accepted parameters for `billing`: `city` (string), `phone` (string).',
			$out
		);
	}

	/**
	 * A root-level offender gets the root's list, not a nested one.
	 *
	 * Naming only the nested object's parameters would leave the root-level
	 * mistake named but unexplained.
	 *
	 * @return void
	 */
	public function test_offenders_at_two_levels_fall_back_to_the_root_list(): void {
		$this->register_ability(
			'albert/update-order',
			[
				'type'                 => 'object',
				'properties'           => [
					'order_id' => [ 'type' => 'integer' ],
					'billing'  => [
						'type'                 => 'object',
						'properties'           => [ 'city' => [ 'type' => 'string' ] ],
						'additionalProperties' => false,
					],
				],
				'additionalProperties' => false,
			]
		);

		$message = $this->proxied(
			'albert/update-order',
			[
				'orderid' => 1,
				'billing' => [ 'company' => 'Acme BV' ],
			]
		);
		$this->assertStringContainsString( '`orderid`', $message );
		$this->assertStringContainsString( '`billing.company`', $message );
		$this->assertStringContainsString( 'Accepted parameters: `order_id` (integer), `billing` (object).', $message );
		$this->assertStringNotContainsString( 'Accepted parameters for', $message );
	}

	/**
	 * A key refused inside an array of objects carries its index.
	 *
	 * @return void
	 */
	public function test_unrecognised_keys_in_a_list_carry_their_index(): void {
		$this->register_ability(
			'albert/create-thing',
			[
				'type'                 => 'object',
				'properties'           => [
					'blocks' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [ 'name' => [ 'type' => 'string' ] ],
							'additionalProperties' => false,
						],
					],
				],
				'additionalProperties' => false,
			]
		);

		$out = $this->proxied(
			'albert/create-thing',
			[
				'blocks' => [
					[ 'name' => 'core/paragraph' ],
					[
						'name'    => 'core/heading',
						'content' => 'Hi',
					],
				],
			]
		);

		$this->assertStringContainsString( 'Unrecognised parameters: `blocks[1].content`.', $out );
	}

	/**
	 * Offenders under different indices of one array share one accepted list.
	 *
	 * `blocks[0]` and `blocks[1]` are different inputs but the same schema node,
	 * so one list explains both. Grouping on the literal path treated them as two
	 * parents and fell back to the root's list, which names neither offending key.
	 *
	 * @return void
	 */
	public function test_offenders_under_different_indices_share_one_list(): void {
		$this->register_ability(
			'albert/create-thing',
			[
				'type'                 => 'object',
				'properties'           => [
					'title'  => [ 'type' => 'string' ],
					'blocks' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [ 'name' => [ 'type' => 'string' ] ],
							'additionalProperties' => false,
						],
					],
				],
				'additionalProperties' => false,
			]
		);

		$message = $this->proxied(
			'albert/create-thing',
			[
				'title'  => 'T',
				'blocks' => [
					[
						'name' => 'core/paragraph',
						'x'    => 1,
					],
					[
						'name' => 'core/heading',
						'y'    => 2,
					],
				],
			]
		);

		$this->assertStringContainsString( 'Unrecognised parameters: `blocks[0].x`, `blocks[1].y`.', $message );
		$this->assertStringContainsString( 'Accepted parameters for `blocks[]`: `name` (string).', $message );
		$this->assertStringNotContainsString( '`title`', $message );
	}

	/**
	 * A foreign server's proxied meta-tool failure keeps its own error text.
	 *
	 * @return void
	 */
	public function test_a_foreign_servers_proxied_result_is_not_rewritten(): void {
		$result = [
			'success' => false,
			'error'   => 'Ability "someplugin/do-thing" has invalid input. Reason: whatever.',
		];

		$this->assertSame(
			$result,
			$this->observer->handle(
				$result,
				[ 'ability_name' => 'someplugin/do-thing' ],
				'mcp-adapter-execute-ability',
				null,
				$this->server( 'woocommerce' )
			)
		);
	}

	/**
	 * A server the adapter did not pass at all is treated as foreign.
	 *
	 * Albert cannot tell whose call it is, so it does not touch it.
	 *
	 * @return void
	 */
	public function test_an_unidentified_server_is_left_alone(): void {
		$this->register_create_post();

		$result = [
			'success' => false,
			'error'   => 'raw',
		];
		$args   = [
			'ability_name' => 'albert/create-post',
			'parameters'   => [],
		];

		$this->assertSame( $result, $this->observer->handle( $result, $args, 'mcp-adapter-execute-ability' ) );
	}
}
