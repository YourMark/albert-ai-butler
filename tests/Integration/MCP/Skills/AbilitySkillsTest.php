<?php
/**
 * Integration tests for skills delivered with their abilities.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP\Skills;

use Albert\MCP\Server;
use Albert\MCP\Skills\AbilitySkills;
use Albert\MCP\Skills\SkillRegistry;
use Albert\Tests\TestCase;
use WP\MCP\Core\McpServer;

/**
 * Inline under the threshold, a pointer over it, names past the budget, in a fixed order.
 *
 * @covers \Albert\MCP\Skills\AbilitySkills
 */
class AbilitySkillsTest extends TestCase {

	/**
	 * The ability every test skill links to.
	 *
	 * @var string
	 */
	private const ABILITY = 'albert/find-posts';

	/**
	 * Skills each test registers.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $skills = [];

	/**
	 * Register the test skills through the registry filter.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		SkillRegistry::reset_cache();
		add_filter( 'albert/skills/registry', fn( array $skills ): array => $skills + $this->skills );
	}

	/**
	 * Reset the registry.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		SkillRegistry::reset_cache();
		parent::tear_down();
	}

	/**
	 * A short skill comes inline; a long one as one line pointing at get-skill.
	 *
	 * @return void
	 */
	public function test_short_inline_long_as_a_pointer(): void {
		$this->link( 'short-guide', str_repeat( 'a', 100 ) );
		$this->link( 'long-guide', str_repeat( 'b', AbilitySkills::INLINE_MAX + 1 ) );

		$text = AbilitySkills::render( self::ABILITY );

		$this->assertStringContainsString( "## Skill: short-guide\n\n" . str_repeat( 'a', 100 ), $text );
		$this->assertStringContainsString( 'Read the `long-guide` skill with albert/get-skill before calling this.', $text );
		$this->assertStringNotContainsString( 'bbbb', $text );
	}

	/**
	 * Past the budget only names are listed, and the order is the same on every call.
	 *
	 * @return void
	 */
	public function test_past_the_budget_only_names(): void {
		$this->link( 'c-third', str_repeat( 'c', 1900 ) );
		$this->link( 'b-second', str_repeat( 'b', 1900 ) );
		$this->link( 'a-first', str_repeat( 'a', 1900 ), 1 );

		$text = AbilitySkills::render( self::ABILITY );

		$this->assertLessThan( strpos( $text, 'b-second' ), strpos( $text, 'a-first' ), 'Priority first, then slug.' );
		$this->assertStringContainsString( '## Skill: b-second', $text );
		$this->assertStringNotContainsString( '## Skill: c-third', $text );
		$this->assertStringContainsString( 'Read the `c-third` skill', $text );
		$this->assertSame( $text, AbilitySkills::render( self::ABILITY ) );
	}

	/**
	 * A skill for other abilities, or one that is not available, is not delivered; the filter cannot bring it back.
	 *
	 * @return void
	 */
	public function test_only_available_linked_skills(): void {
		$this->link( 'mine', 'Mine.' );
		$this->skills['elsewhere'] = [
			'body'      => 'Other.',
			'abilities' => 'albert/find-pages',
		];
		$this->skills['unmet']     = [
			'body'      => 'Unmet.',
			'abilities' => self::ABILITY,
			'requires'  => 'nobody_defines_this',
		];
		add_filter( 'albert/skills/ability_links', static fn( array $slugs ): array => array_merge( $slugs, [ 'unmet', 'elsewhere' ] ) );

		$slugs = array_map( static fn( $skill ): string => $skill->slug(), AbilitySkills::for_ability( self::ABILITY ) );

		$this->assertContains( 'mine', $slugs );
		$this->assertContains( 'elsewhere', $slugs, 'The filter may add an available skill.' );
		$this->assertNotContains( 'unmet', $slugs );
	}

	/**
	 * The info result gains `skills` on Albert's server only, and each delivery is announced.
	 *
	 * @return void
	 */
	public function test_info_result_gains_skills(): void {
		$this->link( 'mine', 'Mine.' );
		$served = [];
		add_action(
			'albert/skills/served',
			static function ( string $slug, string $route ) use ( &$served ): void {
				$served[] = "{$route}:{$slug}";
			},
			10,
			2
		);

		$albert = $this->createMock( McpServer::class );
		$albert->method( 'get_server_id' )->willReturn( Server::SERVER_ID );
		$other = $this->createMock( McpServer::class );
		$other->method( 'get_server_id' )->willReturn( 'someone-else' );

		$args    = [ 'ability_name' => self::ABILITY ];
		$result  = apply_filters( 'mcp_adapter_tool_call_result', [ 'name' => self::ABILITY ], $args, 'mcp-adapter-get-ability-info', null, $albert );
		$foreign = apply_filters( 'mcp_adapter_tool_call_result', [ 'name' => self::ABILITY ], $args, 'mcp-adapter-get-ability-info', null, $other );

		$this->assertStringContainsString( 'Mine.', $result['skills'] );
		$this->assertArrayNotHasKey( 'skills', $foreign );
		$this->assertContains( 'ability_info:mine', $served );
	}

	/**
	 * Link a test skill to the test ability.
	 *
	 * @param string   $slug     The slug.
	 * @param string   $body     The body.
	 * @param int|null $priority Its priority.
	 *
	 * @return void
	 */
	private function link( string $slug, string $body, ?int $priority = null ): void {
		$this->skills[ $slug ] = [
			'body'      => $body,
			'abilities' => self::ABILITY,
			'priority'  => $priority,
			'source'    => 'Albert',
		];
	}
}
