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

use Albert\OAuth\Server\ConnectionContext;

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
 * Bundled skills are Agent Skills folders, `skills/<name>/SKILL.md`, taking
 * their slug and summary from `name` and `description` and Albert's own data
 * (preconditions, linked abilities) from `metadata`. A file is the natural
 * home for a body; the registry is what makes it conditional.
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
		 * {@see Skill::KNOWN_CONDITIONS} or `albert/skills/conditions`,
		 * `when`, a callable for anything that vocabulary cannot express,
		 * `abilities`, the ability ids the skill belongs to, `priority`, its
		 * order among skills linked to one ability, and `source`, the name
		 * shown in the Skills screen's Source column (e.g. an add-on's own
		 * name); omitted, it falls back to a generic "Add-on" label.
		 *
		 * Preconditions are declared, not evaluated here: this filter runs long
		 * before discovery, and a condition answered at registration time would
		 * be answered too early.
		 *
		 * Entries are keyed by slug, so an add-on can replace a bundled skill by
		 * registering the same slug.
		 *
		 * @since 1.4.0
		 * @since 1.7.0 Added the `abilities` and `priority` keys.
		 *
		 * @param array<string, array<string, mixed>> $definitions Skill definitions, keyed by slug.
		 */
		$definitions = apply_filters( 'albert/skills/registry', $definitions );
		$definitions = is_array( $definitions ) ? $definitions : [];

		$definitions += self::from_legacy_files( $definitions );

		$skills = [];

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

		self::$skills = $skills;

		return $skills;
	}

	/**
	 * The skills whose preconditions hold on this site right now.
	 *
	 * And that `albert/skills/available` leaves in, for the current user and connection.
	 *
	 * @return array<string, Skill>
	 * @since 1.4.0
	 */
	public static function available(): array {
		$user_id    = get_current_user_id();
		$client_id  = ConnectionContext::client_id();
		$connection = $client_id === null ? null : [
			'client_id'   => $client_id,
			'client_name' => (string) ConnectionContext::client_name(),
		];

		return array_filter(
			self::all(),
			static function ( Skill $skill ) use ( $user_id, $connection ): bool {
				if ( ! $skill->is_available() ) {
					return false;
				}

				/**
				 * Filters whether a skill is offered to this user and connection.
				 *
				 * Runs after Albert's own checks and can only narrow them: a skill
				 * Albert does not offer is never asked about.
				 *
				 * @since 1.7.0
				 *
				 * @param bool       $available  True.
				 * @param Skill      $skill      The skill.
				 * @param int        $user_id    The current user.
				 * @param array|null $connection `client_id` and `client_name`, or null outside a connection.
				 */
				return apply_filters( 'albert/skills/available', true, $skill, $user_id, $connection ) === true;
			}
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
	 * Read the plugin's own skill folders into definitions.
	 *
	 * The body is passed as text, not as a `file` for {@see Skill::body()} to
	 * read and parse a second time.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.4.0
	 * @since 1.7.0 Reads `skills/<name>/SKILL.md`.
	 */
	private static function bundled(): array {
		if ( ! defined( 'ALBERT_PLUGIN_DIR' ) ) {
			return [];
		}

		$files = glob( ALBERT_PLUGIN_DIR . 'skills/*/SKILL.md' );

		$definitions = [];
		foreach ( is_array( $files ) ? $files : [] as $file ) {
			$definition = self::read_file( $file, __( 'Albert', 'albert-ai-butler' ) );
			if ( $definition !== null ) {
				$definitions[ $definition['slug'] ] = $definition;
			}
		}

		return $definitions;
	}

	/**
	 * Skill files registered the 1.6.0 way, through `albert/skills/files`.
	 *
	 * Read into the registry so they are in the index as well as the
	 * prompts. A file whose skill is not also registered by slug is
	 * deprecated: the registry is the one place a skill is declared.
	 *
	 * @param array<string, mixed> $registered Definitions already registered, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.7.0
	 */
	private static function from_legacy_files( array $registered ): array {
		/**
		 * Filters extra skill files served as MCP prompts.
		 *
		 * @since 1.2.0
		 * @deprecated 1.7.0 Register skills through `albert/skills/registry`, with a `file`.
		 *
		 * @param list<string> $files Absolute paths to skill Markdown files.
		 */
		$files = apply_filters( 'albert/skills/files', [] );

		$bundled     = defined( 'ALBERT_PLUGIN_DIR' ) ? realpath( ALBERT_PLUGIN_DIR . 'skills' ) . DIRECTORY_SEPARATOR : "\0";
		$definitions = [];
		foreach ( is_array( $files ) ? $files : [] as $file ) {
			$path = is_string( $file ) && $file !== '' ? realpath( $file ) : false;
			if ( $path === false || str_starts_with( $path, $bundled ) ) {
				continue;
			}

			$definition = self::read_file( $path, '' );
			if ( $definition !== null && ! isset( $registered[ $definition['slug'] ] ) ) {
				$definitions[ $definition['slug'] ] = $definition;
			}
		}

		if ( $definitions !== [] ) {
			_deprecated_hook( 'albert/skills/files', '1.7.0', 'albert/skills/registry', esc_html__( 'Register the skill with a slug and a file instead.', 'albert-ai-butler' ) );
		}

		return $definitions;
	}

	/**
	 * Read one skill file into a definition.
	 *
	 * @param string $file   Absolute path.
	 * @param string $source Who ships it, or empty for the generic label.
	 *
	 * @return array<string, mixed>|null Null when the file is unreadable, too large, or has no name or body.
	 * @since 1.7.0
	 */
	private static function read_file( string $file, string $source ): ?array {
		// Checked before reading: the body is handed to Skill as text, so
		// Skill::body()'s own size check never runs for these.
		$size = is_readable( $file ) ? filesize( $file ) : false;
		if ( $size === false || $size > Skill::MAX_BODY_BYTES ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local skill file, not a remote resource.
		$contents = file_get_contents( $file );
		if ( $contents === false ) {
			return null;
		}

		$parsed = ( new SkillFileParser() )->parse( $contents );
		if ( empty( $parsed['name'] ) ) {
			return null;
		}

		// Say why it never appeared; Skill::from_array() would drop it silently.
		if ( trim( (string) $parsed['body'] ) === '' ) {
			error_log( sprintf( 'Albert: skipping skill "%s", empty body in %s', $parsed['name'], $file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Concise admin-facing warning for a misconfigured skill file.
			return null;
		}

		return [
			'slug'      => (string) $parsed['name'],
			'summary'   => (string) ( $parsed['description'] ?? '' ),
			'body'      => (string) $parsed['body'],
			'requires'  => $parsed['requires'],
			'abilities' => $parsed['abilities'],
			'priority'  => $parsed['priority'],
			'arguments' => $parsed['arguments'],
			'source'    => $source,
		];
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
		Skill::reset_cache();
	}
}
