<?php
/**
 * Skills screen payload.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Context\SkillIndex;
use Albert\Core\AbilitiesState;
use Albert\MCP\Skills\Skill;
use Albert\MCP\Skills\SkillRegistry;
use Albert\MCP\Skills\SkillSwitch;

/**
 * Normalises the doc-21 skill registry into rows the Albert → Skills screen renders.
 *
 * One invariant is test-asserted: every skill this class marks `available` is a
 * skill {@see \Albert\Context\SkillIndex::entries()} also lists, and every skill
 * it marks unavailable is one that index omits. What the site owner sees here is
 * exactly what the connected assistant can read, because both read the same
 * {@see SkillRegistry::all()} call and the same {@see Skill::status()} logic.
 *
 * @since 1.4.0
 */
class SkillsPayload {

	/**
	 * Build the screen payload: every registered skill, regardless of whether
	 * its preconditions currently hold.
	 *
	 * Unavailable skills are included, not filtered out: a skill a site owner
	 * knows exists should never silently disappear from the list
	 * (`test_unavailable_skills_are_listed_with_a_false_flag`). 1.4.0's
	 * screen doesn't render `available`/`status` (every skill it lists ships
	 * from Albert itself, so there's nothing to distinguish yet), but the
	 * reason string is ready for whenever a future version shows it.
	 *
	 * @return array{skills: list<array<string, mixed>>, reachable: bool} The screen payload.
	 * @since 1.4.0
	 */
	public static function build(): array {
		$rows = [];

		foreach ( SkillRegistry::all() as $skill ) {
			$rows[] = self::row( $skill );
		}

		return [
			'skills'    => $rows,
			// Whether any of this is reachable at all. `albert/get-skill` is an
			// ordinary ability an owner can switch off, and doing so suppresses
			// the entire skills index in the discovery response
			// ({@see \Albert\Context\Payload::skills()}) — so every skill below
			// stops reaching every assistant, silently. The screen said nothing
			// about that, which made a full list of skills a lie.
			'reachable' => AbilitiesState::is_enabled( SkillIndex::FETCH_ABILITY ),
		];
	}

	/**
	 * Normalise one skill into a row.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return array<string, mixed> The row.
	 * @since 1.4.0
	 */
	private static function row( Skill $skill ): array {
		$status = $skill->status();

		return [
			'slug'      => $skill->slug(),
			'summary'   => $skill->summary(),
			'source'    => $skill->source(),
			'enabled'   => SkillSwitch::is_on( $skill->slug() ),
			'available' => $status['available'],
			'status'    => $status['label'],
			'body'      => $skill->body(),
		];
	}
}
