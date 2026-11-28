<?php
/**
 * Skill loader — exposes Albert skills as MCP prompts.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.2.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

use WP\MCP\Domain\Prompts\McpPrompt;

/**
 * Turns every available skill into an {@see McpPrompt}.
 *
 * Built from {@see SkillRegistry::available()}, so the prompts list and the
 * skills index in `discover-abilities` always name the same skills. Before
 * 1.7.0 prompts came from a separate file list, and a skill could be in one
 * and not the other. The body is returned verbatim as a single user message.
 *
 * @since 1.2.0
 * @since 1.7.0 Built from the registry instead of `albert/skills/files`.
 */
class SkillLoader {

	/**
	 * Build the prompts.
	 *
	 * A skill whose prompt cannot be built is skipped, so one bad skill never
	 * breaks the prompts channel.
	 *
	 * @return list<McpPrompt>
	 * @since 1.2.0
	 */
	public function prompts(): array {
		$prompts = [];

		foreach ( SkillRegistry::available() as $skill ) {
			$prompt = self::build_prompt( $skill );
			if ( $prompt instanceof McpPrompt ) {
				$prompts[] = $prompt;
			}
		}

		return $prompts;
	}

	/**
	 * Build one prompt.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return McpPrompt|null
	 * @since 1.7.0
	 */
	private static function build_prompt( Skill $skill ): ?McpPrompt {
		$body = $skill->body();
		if ( $body === '' ) {
			return null;
		}

		$slug   = $skill->slug();
		$config = [
			'name'        => $slug,
			'description' => $skill->summary(),
			'handler'     => static function () use ( $slug, $body ): array {
				AbilitySkills::served( $slug, 'prompt' );

				return [
					'messages' => [
						[
							'role'    => 'user',
							'content' => [
								'type' => 'text',
								'text' => $body,
							],
						],
					],
				];
			},
			// Static guidance; the transport's OAuth gate already authenticates.
			'permission'  => static fn(): bool => true,
		];

		if ( $skill->arguments() !== [] ) {
			$config['arguments'] = $skill->arguments();
		}

		$prompt = McpPrompt::fromArray( $config );

		return $prompt instanceof McpPrompt ? $prompt : null;
	}
}
