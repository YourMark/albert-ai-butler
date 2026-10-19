<?php
/**
 * Skill registry: every task guide this site can offer, and when.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.4.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that knows which skills exist.
 *
 * Three surfaces read from here and they must agree: the skills index in the
 * discovery response, the `albert/get-skill` ability that returns a body, and the
 * MCP prompt registration that predates both. A skill listed in the index but
 * unknown to `get-skill` is worse than an unlisted one, the assistant asks for
 * it and is told it does not exist.
 *
 * Registration is data, not code: an add-on hands over an array and the registry
 * builds the {@see Skill}. That is what lets the WooCommerce skill ship from
 * `albert-woocommerce` without that plugin knowing anything about how the index
 * is rendered or how the body is fetched.
 *
 * Bundled skills are read from the plugin's own `skills/` directory, taking
 * their slug, summary and preconditions from the file's frontmatter. A file is
 * the natural home for a body; the registry is what makes it conditional.
 *
 * @since 1.4.0
 */
class SkillRegistry {

	/**
	 * Per-request memo of the built registry.
	 *
	 * @since 1.4.0
	 * @var array<string, Skill>|null
	 */
	private static ?array $skills = null;

	/**
	 * Every registered skill, keyed by slug, regardless of preconditions.
	 *
	 * @return array<string, Skill>
	 * @since 1.4.0
	 */
	public static function all(): array {
		if ( self::$skills !== null ) {
			return self::$skills;
		}

		$definitions = self::bundled();

		/**
		 * Filters the registered skills.
		 *
		 * Each entry is an array with a `slug`, a one-sentence `summary`, and
		 * either a `file` (absolute path to a Markdown body) or a literal
		 * `body`. Optionally `requires`, a list of named preconditions from
		 * {@see Skill::KNOWN_CONDITIONS}, `when`, a callable for anything
		 * that vocabulary cannot express, and `source`, the name shown in the
		 * Skills screen's Source column (e.g. an add-on's own name); omitted,
		 * it falls back to a generic "Add-on" label.
		 *
		 * Preconditions are declared, not evaluated here: this filter runs long
		 * before discovery, and a condition answered at registration time would
		 * be answered too early.
		 *
		 * Entries are keyed by slug, so an add-on can replace a bundled skill by
		 * registering the same slug.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string, array<string, mixed>> $definitions Skill definitions, keyed by slug.
		 */
		$definitions = apply_filters( 'albert/skills/registry', $definitions );

		$skills = [];

		if ( is_array( $definitions ) ) {
			foreach ( $definitions as $slug => $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}

				// The array key is authoritative, so a definition whose inner
				// slug disagrees with its key cannot register itself twice.
				$definition['slug'] = (string) $slug;

				$skill = Skill::from_array( $definition );

				if ( $skill instanceof Skill ) {
					$skills[ $skill->slug() ] = $skill;
				}
			}
		}

		self::$skills = $skills;

		return $skills;
	}

	/**
	 * The skills whose preconditions hold on this site right now.
	 *
	 * @return array<string, Skill>
	 * @since 1.4.0
	 */
	public static function available(): array {
		return array_filter(
			self::all(),
			static fn( Skill $skill ): bool => $skill->is_available()
		);
	}

	/**
	 * Look up one skill by slug.
	 *
	 * Returns registered-but-unavailable skills too. `get-skill` answering "not
	 * found" for a skill that exists but does not apply here would send the
	 * assistant looking for a spelling mistake; the caller decides what to do
	 * with an unavailable one.
	 *
	 * @param string $slug The skill slug.
	 *
	 * @return Skill|null The skill, or null when no such slug is registered.
	 * @since 1.4.0
	 */
	public static function get( string $slug ): ?Skill {
		return self::all()[ $slug ] ?? null;
	}

	/**
	 * Read the plugin's own `skills/` directory into definitions.
	 *
	 * The frontmatter's `name` is the slug, its `description` is the summary,
	 * and an optional `requires` line carries the preconditions. The body is
	 * passed along as the literal `body` this parse already produced, rather
	 * than a `file` path {@see Skill::body()} would have to read and parse a
	 * second time: this method has already paid that cost once, for every
	 * bundled skill, on every request that builds the registry at all.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.4.0
	 */
	private static function bundled(): array {
		if ( ! defined( 'ALBERT_PLUGIN_DIR' ) ) {
			return [];
		}

		$files = glob( ALBERT_PLUGIN_DIR . 'skills/*.md' );

		if ( ! is_array( $files ) ) {
			return [];
		}

		$parser      = new SkillFileParser();
		$definitions = [];

		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}

			// Guard against an implausibly large file before reading it: since
			// bundled() now hands the body straight to Skill (see the docblock
			// above), Skill::body()'s own MAX_BODY_BYTES check never runs for
			// these, this is the only place that still can.
			$size = filesize( $file );
			if ( $size === false || $size > Skill::MAX_BODY_BYTES ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file, not a remote resource.
			$contents = file_get_contents( $file );

			if ( $contents === false ) {
				continue;
			}

			$parsed = $parser->parse( $contents );

			if ( empty( $parsed['name'] ) ) {
				continue;
			}

			// A named skill with an empty body would register a useless entry
			// that Skill::from_array() rejects anyway (it requires a non-empty
			// file or body); say so, the same way SkillLoader does for the
			// same file when building it as an MCP prompt, so a site owner (or
			// their error log) has a trace of why the skill never appeared.
			if ( trim( (string) $parsed['body'] ) === '' ) {
				error_log( sprintf( 'Albert: skipping skill "%s", empty body in %s', $parsed['name'], $file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Concise admin-facing warning for a misconfigured skill file.
				continue;
			}

			$definitions[ (string) $parsed['name'] ] = [
				'slug'     => (string) $parsed['name'],
				'summary'  => (string) ( $parsed['description'] ?? '' ),
				'body'     => (string) $parsed['body'],
				'requires' => $parsed['requires'],
				'source'   => __( 'Albert', 'albert-ai-butler' ),
			];
		}

		return $definitions;
	}

	/**
	 * Drop the per-request memo.
	 *
	 * Intended for tests; production builds the registry once per request.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function reset_cache(): void {
		self::$skills = null;
	}
}
