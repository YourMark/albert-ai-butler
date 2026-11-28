<?php
/**
 * Skill-file parser — reads a skill file's frontmatter and body.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.2.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

/**
 * SkillFileParser class
 *
 * Parses a skill Markdown file into its YAML-ish frontmatter fields and body.
 * Intentionally small and dependency-free so it can be unit-tested without
 * WordPress. It understands the leading `---` fenced block at the top of a
 * file, top-level `key: value` pairs, and the Agent Skills `metadata` map one
 * level down, whose values may be strings or `- item` lists. Anything after
 * the closing fence is returned verbatim as the body.
 *
 * @since 1.2.0
 */
class SkillFileParser {

	/**
	 * Parse raw skill-file contents into frontmatter fields and body.
	 *
	 * Recognises `name`, `description`, an optional `arguments` block, and
	 * Albert's own keys under `metadata` (the spec's extension point):
	 * `requires`, `abilities` and `priority`. A top-level `requires` still
	 * works. When no valid frontmatter fence is present, the whole input is
	 * treated as the body and the field keys are returned empty.
	 *
	 * @param string $contents The raw file contents.
	 *
	 * @return array{name: ?string, description: ?string, requires: list<string>, abilities: list<string>, priority: ?int, arguments: array<int, array<string, mixed>>, body: string}
	 * @since 1.2.0
	 * @since 1.7.0 Reads `metadata`: `requires`, `abilities` and `priority`.
	 */
	public function parse( string $contents ): array {
		$result = [
			'name'        => null,
			'description' => null,
			'requires'    => [],
			'abilities'   => [],
			'priority'    => null,
			'arguments'   => [],
			'body'        => $contents,
		];

		// Normalise line endings so the fence regex is reliable.
		$normalised = str_replace( [ "\r\n", "\r" ], "\n", $contents );

		// Require the frontmatter fence at the very start of the file. The
		// opening fence tolerates trailing horizontal whitespace (e.g. "--- \n")
		// so a stray space never makes the whole file parse as body-only.
		if ( ! preg_match( '/\A---[^\S\n]*\n(.*?)\n---\n?(.*)\z/s', $normalised, $matches ) ) {
			$result['body'] = trim( $normalised );
			return $result;
		}

		$frontmatter    = $matches[1];
		$result['body'] = trim( $matches[2] );

		$parsed                = $this->parse_frontmatter( $frontmatter );
		$result['name']        = $parsed['name'];
		$result['description'] = $parsed['description'];
		$result['requires']    = $parsed['requires'];
		$result['abilities']   = $parsed['abilities'];
		$result['priority']    = $parsed['priority'];
		$result['arguments']   = $parsed['arguments'];

		return $result;
	}

	/**
	 * Parse the frontmatter block.
	 *
	 * @param string $frontmatter The text between the opening and closing fences.
	 *
	 * @return array{name: ?string, description: ?string, requires: list<string>, abilities: list<string>, priority: ?int, arguments: array<int, array<string, mixed>>}
	 * @since 1.2.0
	 * @since 1.7.0 Reads the `metadata` map and lists.
	 */
	private function parse_frontmatter( string $frontmatter ): array {
		$top      = [];
		$metadata = [];
		$lists    = [];
		$key      = null;
		$sub      = null;

		foreach ( explode( "\n", $frontmatter ) as $line ) {
			// Skip blank lines and YAML comments.
			if ( trim( $line ) === '' || str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}

			// A trailing comment, outside quotes: `requires: woocommerce # note`.
			$line = (string) preg_replace( '/\s+#(?=(?:[^"\']|"[^"]*"|\'[^\']*\')*$).*$/', '', $line );

			if ( preg_match( '/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair ) ) {
				$key         = strtolower( $pair[1] );
				$sub         = null;
				$top[ $key ] = $this->unquote( trim( $pair[2] ) );
				continue;
			}

			// A `- item` belongs to the metadata key above it, or to the top-level one.
			if ( preg_match( '/^\s*-\s+(.*)$/', $line, $item ) && $key !== null ) {
				$owner             = $key === 'metadata' ? ( $sub === null ? null : 'metadata.' . $sub ) : $key;
				$lists[ $owner ][] = $this->unquote( trim( $item[1] ) );
				continue;
			}

			if ( $key === 'metadata' && preg_match( '/^\s+([A-Za-z0-9_.-]+)\s*:\s*(.*)$/', $line, $pair ) ) {
				$sub              = strtolower( $pair[1] );
				$metadata[ $sub ] = $this->unquote( trim( $pair[2] ) );
			}
		}

		$value = static fn( string $name, array $map, string $prefix ): mixed => $lists[ $prefix . $name ] ?? ( isset( $map[ $name ] ) && $map[ $name ] !== '' ? $map[ $name ] : null );

		$requires = $value( 'requires', $metadata, 'metadata.' ) ?? $value( 'requires', $top, '' ) ?? [];

		$name        = is_string( $top['name'] ?? null ) && $top['name'] !== '' ? $top['name'] : null;
		$description = is_string( $top['description'] ?? null ) && $top['description'] !== '' ? $top['description'] : null;
		$priority    = $metadata['priority'] ?? null;

		return [
			'name'        => $name,
			'description' => $description,
			'requires'    => array_map( 'strtolower', self::words( $requires ) ),
			'abilities'   => self::words( $value( 'abilities', $metadata, 'metadata.' ) ?? [] ),
			'priority'    => is_string( $priority ) && is_numeric( $priority ) ? (int) $priority : null,
			'arguments'   => is_string( $top['arguments'] ?? null ) ? $this->parse_arguments( $top['arguments'] ) : [],
		];
	}

	/**
	 * A list from a YAML list, or from a string separated by spaces or commas.
	 *
	 * The spec makes `metadata` values strings, so a list there is written
	 * `"a b"`; makers who write a YAML list get the same result.
	 *
	 * @param mixed $value A string or a list of strings.
	 *
	 * @return list<string> Unique, in order.
	 * @since 1.7.0
	 */
	public static function words( mixed $value ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', trim( (string) $value, " \t[]" ) );
		$words = [];

		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item, " \t\"'" );
			if ( $item !== '' ) {
				$words[ $item ] = $item;
			}
		}

		return array_values( $words );
	}

	/**
	 * Parse an inline JSON array of argument definitions.
	 *
	 * Arguments are optional and only accepted as a single-line JSON array,
	 * e.g. `arguments: [{"name":"slug","required":true}]`. Invalid or
	 * non-array JSON yields an empty list so a malformed value never fatals.
	 *
	 * @param string $value The raw value following `arguments:`.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.2.0
	 */
	private function parse_arguments( string $value ): array {
		if ( $value === '' ) {
			return [];
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$arguments = [];
		foreach ( $decoded as $argument ) {
			if ( is_array( $argument ) && isset( $argument['name'] ) && is_string( $argument['name'] ) ) {
				$arguments[] = $argument;
			}
		}

		return $arguments;
	}

	/**
	 * Strip a single pair of matching surrounding quotes from a value.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private function unquote( string $value ): string {
		if ( strlen( $value ) >= 2 ) {
			$first = $value[0];
			$last  = $value[ strlen( $value ) - 1 ];
			if ( ( $first === '"' && $last === '"' ) || ( $first === "'" && $last === "'" ) ) {
				return substr( $value, 1, -1 );
			}
		}

		return $value;
	}
}
