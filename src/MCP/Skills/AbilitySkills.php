<?php
/**
 * Skills delivered with the abilities they belong to
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.7.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

use Albert\Context\SkillIndex;
use Albert\Core\AbilitiesState;
use Albert\MCP\Server;
use Albert\OAuth\Server\ConnectionContext;

/**
 * Adds a linked skill to `get-ability-info` for each ability it belongs to.
 *
 * An assistant reads the index once and then forgets it; it reads an
 * ability's info right before calling it. That is when the guide matters.
 * A short skill goes inline, a long one becomes one line pointing at
 * `get-skill`, and past a budget per ability only the names are listed, so
 * a heavily linked ability never drowns its own schema. The order is fixed,
 * so the same call returns the same text.
 *
 * Text, not an array: like the rest of the agent context ({@see \Albert\Context\PayloadRenderer}).
 *
 * @since 1.7.0
 */
class AbilitySkills {

	/**
	 * Bodies up to this many characters go inline.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const INLINE_MAX = 2000;

	/**
	 * Characters of skill text per ability before only names are listed.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const BUDGET = 4000;

	/**
	 * The adapter's ability info tool.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private const INFO_ABILITY = 'mcp-adapter/get-ability-info';

	/**
	 * Register the hooks.
	 *
	 * After {@see \Albert\MCP\SchemaPreparer}, which reshapes the same result.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'add_skills' ], 20, 5 );
		add_filter( 'wp_register_ability_args', [ $this, 'describe_field' ], 10, 2 );
	}

	/**
	 * Add the linked skills to an ability's info.
	 *
	 * @param mixed  $result    The tool result.
	 * @param mixed  $args      The tool arguments: `ability_name`.
	 * @param string $tool_name The tool called.
	 * @param mixed  $mcp_tool  Unused.
	 * @param mixed  $server    The MCP server firing the filter.
	 *
	 * @return mixed The result, with `skills` when any skill applies.
	 * @since 1.7.0
	 */
	public function add_skills( $result, $args, string $tool_name, $mcp_tool = null, $server = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( ! Server::is_albert_server( $server ) || str_replace( '/', '-', $tool_name ) !== str_replace( '/', '-', self::INFO_ABILITY ) ) {
			return $result;
		}

		$ability = is_array( $args ) && is_string( $args['ability_name'] ?? null ) ? $args['ability_name'] : '';
		if ( ! is_array( $result ) || $ability === '' || isset( $result['error'] ) ) {
			return $result;
		}

		$text = self::render( $ability );
		if ( $text !== '' ) {
			$result['skills'] = $text;
		}

		return $result;
	}

	/**
	 * Declare the field in the info tool's output schema, as discovery does for its own.
	 *
	 * @param array<string, mixed> $args Registration arguments.
	 * @param string               $name The ability.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	public function describe_field( array $args, string $name ): array {
		if ( $name !== self::INFO_ABILITY || ! isset( $args['output_schema']['properties'] ) || ! is_array( $args['output_schema']['properties'] ) ) {
			return $args;
		}

		$args['output_schema']['properties']['skills'] = [
			'type'        => 'string',
			'description' => 'Task guides for this ability, from this site. Follow them when calling it.',
		];

		return $args;
	}

	/**
	 * The skills linked to one ability, in the order they are delivered.
	 *
	 * @param string $ability The ability id.
	 *
	 * @return list<Skill>
	 * @since 1.7.0
	 */
	public static function for_ability( string $ability ): array {
		$available = SkillRegistry::available();

		$slugs = [];
		foreach ( $available as $skill ) {
			if ( in_array( $ability, $skill->abilities(), true ) ) {
				$slugs[] = $skill->slug();
			}
		}

		/**
		 * Filters which skills come with an ability's info.
		 *
		 * Albert drops any slug that is not available afterwards, so this can
		 * reorder, remove or add a linked skill but never bring back one that is
		 * switched off or whose preconditions fail. The budget applies after.
		 *
		 * @since 1.7.0
		 *
		 * @param list<string> $slugs      Skill slugs linked to the ability.
		 * @param string       $ability_id The ability.
		 * @param int          $user_id    The current user.
		 */
		$filtered = apply_filters( 'albert/skills/ability_links', $slugs, $ability, get_current_user_id() );
		$filtered = is_array( $filtered ) ? $filtered : $slugs;

		$skills = [];
		foreach ( $filtered as $slug ) {
			if ( is_string( $slug ) && isset( $available[ $slug ] ) ) {
				$skills[ $slug ] = $available[ $slug ];
			}
		}

		uasort( $skills, [ self::class, 'compare' ] );

		return array_values( $skills );
	}

	/**
	 * The text for one ability: inline bodies, then pointers, then names.
	 *
	 * @param string $ability The ability id.
	 *
	 * @return string Empty when no skill applies.
	 * @since 1.7.0
	 */
	public static function render( string $ability ): string {
		$fetchable = AbilitiesState::is_enabled( SkillIndex::FETCH_ABILITY );
		$used      = 0;
		$parts     = [];
		$names     = [];

		foreach ( self::for_ability( $ability ) as $skill ) {
			$body = $skill->body();
			if ( $body === '' ) {
				continue;
			}

			if ( strlen( $body ) <= self::INLINE_MAX && $used + strlen( $body ) <= self::BUDGET ) {
				$parts[] = "## Skill: {$skill->slug()}\n\n{$body}";
				$used   += strlen( $body );
				self::served( $skill->slug(), 'ability_info' );
				continue;
			}

			$pointer = "Read the `{$skill->slug()}` skill with " . SkillIndex::FETCH_ABILITY . ' before calling this.';
			if ( $fetchable && $used + strlen( $pointer ) <= self::BUDGET ) {
				$parts[] = $pointer;
				$used   += strlen( $pointer );
				continue;
			}

			if ( $fetchable ) {
				$names[] = $skill->slug();
			}
		}

		if ( $names !== [] ) {
			$parts[] = 'More skills for this ability, read with ' . SkillIndex::FETCH_ABILITY . ': ' . implode( ', ', $names ) . '.';
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Tell listeners a skill reached an assistant.
	 *
	 * @param string $slug  The skill.
	 * @param string $route `index`, `get_skill`, `ability_info`, `prompt` or `native`.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function served( string $slug, string $route ): void {
		/**
		 * Fires when a skill reaches an assistant.
		 *
		 * For usage insight. Not fired for the Skills screen or its preview.
		 *
		 * @since 1.7.0
		 *
		 * @param string               $slug    The skill.
		 * @param string               $route   `index`, `get_skill`, `ability_info`, `prompt` or `native`.
		 * @param int                  $user_id The current user.
		 * @param array<string, mixed> $context `client_id` and `client_name`; `digest`, the sha256 of the body.
		 */
		do_action(
			'albert/skills/served',
			$slug,
			$route,
			get_current_user_id(),
			[
				'client_id'   => ConnectionContext::client_id(),
				'client_name' => ConnectionContext::client_name(),
				'digest'      => hash( 'sha256', (string) SkillRegistry::get( $slug )?->body() ),
			]
		);
	}

	/**
	 * Order: the skill's `priority` (lower first, unset last), Albert's before an add-on's, then slug.
	 *
	 * @param Skill $a A skill.
	 * @param Skill $b Another.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	private static function compare( Skill $a, Skill $b ): int {
		return [ $a->priority() ?? PHP_INT_MAX, $a->is_albert() ? 0 : 1, $a->slug() ]
			<=> [ $b->priority() ?? PHP_INT_MAX, $b->is_albert() ? 0 : 1, $b->slug() ];
	}
}
