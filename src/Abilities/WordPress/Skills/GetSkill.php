<?php
/**
 * Get Skill Ability
 *
 * Returns one skill's full Markdown body, so an assistant can read the guidance
 * the discovery response only names.
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Skills
 * @since      1.4.0
 */

namespace Albert\Abilities\WordPress\Skills;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use Albert\MCP\Skills\Skill;
use Albert\MCP\Skills\SkillRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Get Skill ability class.
 *
 * Skills have been registered as MCP prompts since 1.2.0, and in practice that
 * made them unreachable: prompts surface in clients as user-triggered slash
 * commands, and a model working on its own never pulls one. A 301-line playbook
 * that is never read is a silent failure, not a theoretical one, this ability
 * is what turns the skills channel into something autonomous work can actually
 * use. Prompt registration stays alongside it for clients that do expose
 * prompts.
 *
 * Read-only, and gated on `edit_posts`: a skill is written guidance about how to
 * work on this site, so the people who may read it are the people who may work
 * on it.
 *
 * @since 1.4.0
 */
class GetSkill extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 */
	public function __construct() {
		$this->id          = 'albert/get-skill';
		$this->label       = __( 'Get Skill', 'albert-ai-butler' );
		$this->description = __( 'Read the full text of one of this site\'s task guides. The discovery response lists which guides apply here; fetch the relevant one before starting that kind of work.', 'albert-ai-butler' );
		// `site` is one of WordPress's own built-in categories. A task guide is
		// site-level guidance, and reusing a core category beats registering a
		// new one for a single ability.
		$this->category = 'site';
		$this->group    = 'skills';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Call this once, before the work it covers, and keep what it says for the rest of the task, the body does not change mid-conversation. Pass the slug exactly as the discovery response listed it.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> Input schema.
	 * @since 1.4.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug' => [
					'type'        => 'string',
					'description' => 'The skill to read, exactly as named in the discovery response (e.g. "block-editor").',
				],
			],
			'required'   => [ 'slug' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.4.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'    => [ 'type' => 'string' ],
				'summary' => [
					'type'        => 'string',
					'description' => 'The one-line summary shown in the discovery response.',
				],
				'body'    => [
					'type'        => 'string',
					'description' => 'The full guide, in Markdown.',
				],
			],
			'required'   => [ 'slug', 'body' ],
		];
	}

	/**
	 * Check if the current user may read this site's task guides.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 * @since 1.4.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * Execute the ability: return one skill's body.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $slug Skill slug to read.
	 * }
	 *
	 * @return array<string, mixed>|WP_Error The skill on success, WP_Error otherwise.
	 * @since 1.4.0
	 */
	public function execute( array $args ): array|WP_Error {
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? trim( $args['slug'] ) : '';

		if ( $slug === '' ) {
			return new WP_Error(
				'skill_slug_required',
				__( 'A skill slug is required.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$skill = SkillRegistry::get( $slug );

		if ( ! $skill instanceof Skill ) {
			return new WP_Error(
				'skill_not_found',
				sprintf(
					/* translators: 1: requested skill slug, 2: comma-separated list of available slugs, or a fallback phrase. */
					__( 'There is no skill called "%1$s" on this site. Available: %2$s.', 'albert-ai-butler' ),
					$slug,
					$this->available_slugs()
				),
				[ 'status' => 404 ]
			);
		}

		$body = $skill->body();

		if ( $body === '' ) {
			// Registered but unreadable: a deleted file, or one too large to
			// read. Say which of the two states this is, because "not found"
			// would send the assistant hunting for a spelling mistake in a slug
			// that is perfectly correct.
			return new WP_Error(
				'skill_unreadable',
				sprintf(
					/* translators: %s: skill slug */
					__( 'The skill "%s" is registered but its text could not be read. This is a problem with the site, not with your request, carry on without it.', 'albert-ai-butler' ),
					$slug
				),
				[ 'status' => 500 ]
			);
		}

		return [
			'slug'    => $skill->slug(),
			'summary' => $skill->summary(),
			'body'    => $body,
		];
	}

	/**
	 * The slugs an assistant could have asked for instead.
	 *
	 * Every registered skill, not only the ones whose preconditions hold: a slug
	 * that exists but does not apply here is a different mistake from a slug that
	 * does not exist, and the message should let the assistant tell them apart.
	 *
	 * @return string Comma-separated slugs, or a phrase saying none are registered.
	 * @since 1.4.0
	 */
	private function available_slugs(): string {
		$slugs = array_keys( SkillRegistry::all() );

		if ( $slugs === [] ) {
			return __( 'none are registered on this site', 'albert-ai-butler' );
		}

		return implode( ', ', $slugs );
	}
}
