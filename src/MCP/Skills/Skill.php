<?php
/**
 * Skill: one task guide, with the conditions under which it is worth reading.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.4.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

use Albert\Core\AbilitiesState;

/**
 * A single registered skill.
 *
 * A skill is a playbook: a Markdown body that teaches an assistant how to do one
 * kind of work on this site properly. Two things make it more than a file.
 *
 * **Preconditions.** A skill declares when it is relevant, and the discovery
 * response lists it only when that holds. The WooCommerce skill on a site with no shop is
 * not merely useless but misleading, because it tells the model about tools
 * that are not there. Conditions are declared, not computed at registration time, because
 * a registration that ran on `plugins_loaded` would be asking questions before
 * the answers exist.
 *
 * **Lazy body, mostly.** The index costs one line per skill; the body costs
 * hundreds of tokens and matters only when the assistant asks for it through
 * `albert/get-skill`. A skill registered with a `file` reads and parses it
 * only then. Bundled skills are the one exception: {@see
 * SkillRegistry::bundled()} already has to read and parse every bundled
 * file's frontmatter to build the registry at all, so it hands the body it
 * already parsed straight to the constructor rather than discarding it and
 * paying that cost again later — a few kilobytes held in memory for every
 * request is a fair trade against reading and parsing the same file twice.
 *
 * @since 1.4.0
 */
class Skill {

	/**
	 * Largest skill body we will read from disk, in bytes.
	 *
	 * Bundled skills are a few kilobytes. This guards against a registered path
	 * pointing at something that is not a skill at all.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	public const MAX_BODY_BYTES = 524288;

	/**
	 * Preconditions this class knows how to answer itself.
	 *
	 * A vocabulary rather than a callback for the common cases, so a skill can
	 * be registered from a data array, which is what lets an add-on ship one
	 * without writing a closure, and what lets a bundled skill declare its
	 * condition in its own frontmatter.
	 *
	 * Must list exactly the keys {@see self::condition_definitions()} defines.
	 * A literal array, not derived from that method, because PHP class
	 * constants cannot be initialised from a method call; nothing in this
	 * codebase reads this constant to validate a condition name (that
	 * happens in {@see self::condition_holds()} against the method instead),
	 * so it exists purely as the documented vocabulary and must be kept in
	 * sync by hand when a condition is added or removed.
	 *
	 * @since 1.4.0
	 * @var list<string>
	 */
	public const KNOWN_CONDITIONS = [ 'woocommerce', 'block_editor', 'classic_editor', 'multisite' ];

	/**
	 * Construct a skill.
	 *
	 * Promoted `readonly` properties: the registry hands the same instance to
	 * several callers, and none of them may change it.
	 *
	 * @param string                           $slug     Stable identifier, and the argument `albert/get-skill` takes.
	 * @param string                           $summary  One sentence for the discovery response's skills index.
	 * @param string                           $file     Absolute path to the Markdown body. Empty when `$body` is given.
	 * @param string                           $body     Literal body, for skills not backed by a file.
	 * @param list<string>                     $requires Named preconditions from {@see self::KNOWN_CONDITIONS}.
	 * @param callable|null                    $when     Extra precondition for anything the vocabulary cannot express.
	 * @param string                           $source   Who ships this skill, shown in the Skills screen's Source column.
	 *                                                   Empty when the registration did not say, the Skills screen falls
	 *                                                   back to a generic "Add-on" label rather than guessing a name.
	 * @param list<string>                     $abilities Ability ids this skill belongs to (its maker's links).
	 * @param int|null                         $priority  Order among skills linked to one ability, lower first.
	 * @param array<int, array<string, mixed>> $arguments MCP prompt arguments.
	 *
	 * @since 1.4.0
	 * @since 1.7.0 Added `$abilities`, `$priority` and `$arguments`.
	 */
	public function __construct(
		private readonly string $slug,
		private readonly string $summary = '',
		private readonly string $file = '',
		private readonly string $body = '',
		private readonly array $requires = [],
		private readonly mixed $when = null,
		private readonly string $source = '',
		private readonly array $abilities = [],
		private readonly ?int $priority = null,
		private readonly array $arguments = []
	) {
	}

	/**
	 * Build a skill from a plain array, as an add-on registers it.
	 *
	 * Returns null when the array cannot make a usable skill: no slug, or
	 * neither a body nor a file, so one malformed registration is skipped
	 * rather than breaking the whole index.
	 *
	 * @param array<string, mixed> $definition Registration array.
	 *
	 * @return self|null The skill, or null when the definition cannot make a usable one.
	 * @since 1.4.0
	 */
	public static function from_array( array $definition ): ?self {
		$slug = isset( $definition['slug'] ) ? (string) $definition['slug'] : '';

		if ( $slug === '' ) {
			return null;
		}

		$file = isset( $definition['file'] ) ? (string) $definition['file'] : '';
		$body = isset( $definition['body'] ) ? (string) $definition['body'] : '';

		if ( $file === '' && trim( $body ) === '' ) {
			return null;
		}

		$requires = isset( $definition['requires'] ) ? SkillFileParser::words( $definition['requires'] ) : [];

		$when = isset( $definition['when'] ) && is_callable( $definition['when'] ) ? $definition['when'] : null;

		return new self(
			$slug,
			isset( $definition['summary'] ) ? (string) $definition['summary'] : '',
			$file,
			$body,
			$requires,
			$when,
			isset( $definition['source'] ) ? (string) $definition['source'] : '',
			SkillFileParser::words( $definition['abilities'] ?? [] ),
			isset( $definition['priority'] ) && is_numeric( $definition['priority'] ) ? (int) $definition['priority'] : null,
			isset( $definition['arguments'] ) && is_array( $definition['arguments'] ) ? array_values( array_filter( $definition['arguments'], 'is_array' ) ) : []
		);
	}

	/**
	 * The skill's stable identifier.
	 *
	 * @return string The slug, as `albert/get-skill` takes it.
	 * @since 1.4.0
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * The one-line summary shown in the discovery response.
	 *
	 * @return string The summary, or an empty string.
	 * @since 1.4.0
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * The declared preconditions.
	 *
	 * @return list<string> Declared precondition names, unvalidated.
	 * @since 1.4.0
	 */
	public function requires(): array {
		return $this->requires;
	}

	/**
	 * The abilities this skill belongs to, as its maker linked them.
	 *
	 * @return list<string> Ability ids.
	 * @since 1.7.0
	 */
	public function abilities(): array {
		return $this->abilities;
	}

	/**
	 * Order among skills linked to the same ability, lower first; null when unset.
	 *
	 * @return int|null
	 * @since 1.7.0
	 */
	public function priority(): ?int {
		return $this->priority;
	}

	/**
	 * The MCP prompt arguments this skill takes.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.7.0
	 */
	public function arguments(): array {
		return $this->arguments;
	}

	/**
	 * Whether Albert itself ships this skill.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function is_albert(): bool {
		return $this->source === __( 'Albert', 'albert-ai-butler' );
	}

	/**
	 * Who ships this skill, shown in the Skills screen's Source column.
	 *
	 * Bundled skills declare `'Albert'` explicitly (see {@see
	 * SkillRegistry::bundled()}); a skill added through the registry filter
	 * without a `source` falls back to a generic label rather than being
	 * mislabelled as one Albert did not ship.
	 *
	 * @return string The source label.
	 * @since 1.4.0
	 */
	public function source(): string {
		return $this->source !== '' ? $this->source : __( 'Add-on', 'albert-ai-butler' );
	}

	/**
	 * Whether this skill is worth listing on this site right now.
	 *
	 * @return bool True when every declared precondition holds.
	 * @since 1.4.0
	 */
	public function is_available(): bool {
		return $this->status()['available'];
	}

	/**
	 * The live precondition status: whether the skill is offered, and why.
	 *
	 * One place for the boolean {@see self::is_available()} returns and the
	 * reason the Skills screen shows, so they cannot drift apart. An unknown
	 * condition fails closed. Every unmet reason is reported at once. A linked
	 * skill with none of its abilities usable is unavailable. "Always enabled."
	 * is only for a skill with no `requires` and no `when`.
	 *
	 * @return array{available: bool, label: string}
	 * @since 1.4.0
	 * @since 1.7.0 A linked skill needs one usable ability.
	 */
	public function status(): array {
		$unmet = [];

		foreach ( $this->requires as $condition ) {
			if ( ! $this->condition_holds( $condition ) ) {
				$unmet[] = self::requirement_label( $condition );
			}
		}

		$when_unmet = is_callable( $this->when ) && ! call_user_func( $this->when );

		if ( $unmet === [] && ! $when_unmet && ! $this->has_usable_ability() ) {
			return [
				'available' => false,
				'label'     => __( 'None of the abilities it is for is switched on.', 'albert-ai-butler' ),
			];
		}

		if ( $unmet !== [] || $when_unmet ) {
			$label = $unmet !== []
				? sprintf(
					/* translators: %s: the unmet preconditions, comma-separated, e.g. "WooCommerce, the block editor". */
					__( 'Requires %s.', 'albert-ai-butler' ),
					implode( ', ', $unmet )
				)
				: '';

			if ( $when_unmet ) {
				$label = $label !== ''
					? $label . ' ' . __( "A site condition isn't met.", 'albert-ai-butler' )
					: __( "A site condition isn't met.", 'albert-ai-butler' );
			}

			return [
				'available' => false,
				'label'     => $label,
			];
		}

		if ( $this->requires === [] ) {
			return [
				'available' => true,
				'label'     => is_callable( $this->when )
					? __( 'A site condition is currently met.', 'albert-ai-butler' )
					: __( 'Always enabled.', 'albert-ai-butler' ),
			];
		}

		return [
			'available' => true,
			'label'     => sprintf(
				/* translators: %s: the met preconditions, e.g. "WooCommerce is active". */
				__( '%s.', 'albert-ai-butler' ),
				implode( ', ', array_map( [ self::class, 'active_label' ], $this->requires ) )
			),
		];
	}

	/**
	 * The full Markdown body.
	 *
	 * Returns the literal body when the skill was constructed with one
	 * (bundled skills, and any add-on that registered a literal `body`).
	 * Otherwise reads `$file` from disk on demand, returning an empty string
	 * when it is missing, unreadable or implausibly large, the caller turns
	 * that into an error the assistant can act on, rather than a blank
	 * success.
	 *
	 * @return string The Markdown body, or an empty string when it cannot be read.
	 * @since 1.4.0
	 */
	public function body(): string {
		if ( $this->body !== '' ) {
			return trim( $this->body );
		}

		if ( $this->file === '' || ! is_file( $this->file ) || ! is_readable( $this->file ) ) {
			return '';
		}

		$size = filesize( $this->file );
		if ( $size === false || $size > self::MAX_BODY_BYTES ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a registered local skill file, not a remote resource.
		$contents = file_get_contents( $this->file );

		if ( $contents === false ) {
			return '';
		}

		return trim( ( new SkillFileParser() )->parse( $contents )['body'] );
	}

	/**
	 * Whether an assistant can use at least one ability this skill is for.
	 *
	 * A guide to tools nobody can call costs every conversation tokens for
	 * nothing. A skill without links is about the site, not a tool, and always
	 * passes.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private function has_usable_ability(): bool {
		if ( $this->abilities === [] || ! function_exists( 'wp_has_ability' ) ) {
			return true;
		}

		foreach ( $this->abilities as $ability ) {
			if ( wp_has_ability( $ability ) && AbilitiesState::is_enabled( $ability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a precondition is defined, by Albert or an add-on.
	 *
	 * @param string $condition The condition slug.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public static function is_known_condition( string $condition ): bool {
		return isset( self::condition_definitions()[ $condition ] );
	}

	/**
	 * Drop the memoised conditions, so a test can add one.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function reset_cache(): void {
		self::$condition_definitions = null;
	}

	/**
	 * Per-request memo of {@see self::condition_definitions()}.
	 *
	 * @since 1.4.0
	 * @var array<string, array{check: callable(): bool, requirement: string, active: string}>|null
	 */
	private static ?array $condition_definitions = null;

	/**
	 * Everything this class knows about each named precondition.
	 *
	 * @return array<string, array{check: callable(): bool, requirement: string, active: string}>
	 * @since 1.4.0
	 * @since 1.7.0 Add-ons add their own through `albert/skills/conditions`.
	 */
	private static function condition_definitions(): array {
		if ( self::$condition_definitions !== null ) {
			return self::$condition_definitions;
		}

		$definitions = [
			'woocommerce'    => [
				'check'       => static fn (): bool => class_exists( 'WooCommerce' ),
				'requirement' => __( 'WooCommerce', 'albert-ai-butler' ),
				'active'      => __( 'WooCommerce is active', 'albert-ai-butler' ),
			],
			// Site-wide rather than per-post: the index is assembled once at
			// discovery, before any target is known.
			'block_editor'   => [
				'check'       => static fn (): bool => \Albert\Blocks\EditorMode::editor( 'post' ) === 'block'
					|| \Albert\Blocks\EditorMode::editor( 'page' ) === 'block',
				'requirement' => __( 'the block editor', 'albert-ai-butler' ),
				'active'      => __( 'the block editor is active', 'albert-ai-butler' ),
			],
			'classic_editor' => [
				'check'       => static fn (): bool => \Albert\Blocks\EditorMode::editor( 'post' ) === 'classic'
					|| \Albert\Blocks\EditorMode::editor( 'page' ) === 'classic',
				'requirement' => __( 'the classic editor', 'albert-ai-butler' ),
				'active'      => __( 'the classic editor is active', 'albert-ai-butler' ),
			],
			'multisite'      => [
				'check'       => static fn (): bool => function_exists( 'is_multisite' ) && is_multisite(),
				'requirement' => __( 'a multisite network', 'albert-ai-butler' ),
				'active'      => __( 'this is a multisite network', 'albert-ai-butler' ),
			],
		];

		/**
		 * Filters the preconditions a skill may declare in `requires`.
		 *
		 * An add-on adds its own (for example `woocommerce_subscriptions`) as
		 * `slug => [ 'check' => callable, 'requirement' => 'WooCommerce Subscriptions', 'active' => 'WooCommerce Subscriptions is active' ]`.
		 * It can add, never replace one of Albert's. A condition nobody
		 * defines still fails closed.
		 *
		 * @since 1.7.0
		 *
		 * @param array<string, array<string, mixed>> $conditions Added conditions, keyed by slug.
		 */
		$added = apply_filters( 'albert/skills/conditions', [] );

		foreach ( is_array( $added ) ? $added : [] as $slug => $condition ) {
			if ( ! is_string( $slug ) || isset( $definitions[ $slug ] ) || ! is_array( $condition ) || ! is_callable( $condition['check'] ?? null ) ) {
				continue;
			}

			$definitions[ $slug ] = [
				'check'       => $condition['check'],
				'requirement' => is_string( $condition['requirement'] ?? null ) ? $condition['requirement'] : $slug,
				'active'      => is_string( $condition['active'] ?? null ) ? $condition['active'] : $slug,
			];
		}

		self::$condition_definitions = $definitions;

		return self::$condition_definitions;
	}

	/**
	 * Answer one named precondition.
	 *
	 * @param string $condition Condition name.
	 *
	 * @return bool True when the condition holds. False for a name this class does not know.
	 * @since 1.4.0
	 */
	private function condition_holds( string $condition ): bool {
		$definition = self::condition_definitions()[ $condition ] ?? null;

		return $definition !== null && ( $definition['check'] )();
	}

	/**
	 * A condition's name, phrased as a requirement ("Requires %s.").
	 *
	 * @param string $condition Condition name.
	 *
	 * @return string Human-readable phrase. The condition name itself for one this class does not know.
	 * @since 1.4.0
	 */
	private static function requirement_label( string $condition ): string {
		return self::condition_definitions()[ $condition ]['requirement'] ?? $condition;
	}

	/**
	 * A condition's name, phrased as the reason it currently holds ("%s.").
	 *
	 * @param string $condition Condition name.
	 *
	 * @return string Human-readable phrase. The condition name itself for one this class does not know.
	 * @since 1.4.0
	 */
	private static function active_label( string $condition ): string {
		return self::condition_definitions()[ $condition ]['active'] ?? $condition;
	}
}
