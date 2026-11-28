<?php
/**
 * Entry-point check for skill files
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.7.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

/**
 * Says what is wrong with a skill file, so a test fails instead of the skill quietly disappearing.
 *
 * For Albert's own tests and for add-ons': call it on each of your
 * `skills/<name>/SKILL.md` files after your abilities are registered and
 * assert the list is empty.
 *
 * @api
 * @since 1.7.0
 */
final class SkillCheck {

	/**
	 * The problems with one skill file.
	 *
	 * @param string $file Absolute path to a `SKILL.md`.
	 *
	 * @return list<string> Problems, empty when the file is valid.
	 * @since 1.7.0
	 */
	public static function problems( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return [ 'The file cannot be read.' ];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local skill file.
		$parsed   = ( new SkillFileParser() )->parse( (string) file_get_contents( $file ) );
		$name     = (string) $parsed['name'];
		$problems = [];

		if ( $name === '' ) {
			$problems[] = 'It has no name.';
		} elseif ( preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $name ) !== 1 || strlen( $name ) > 64 ) {
			$problems[] = "Its name \"{$name}\" is not lowercase letters, digits and single hyphens, at most 64 characters.";
		} elseif ( basename( $file ) === 'SKILL.md' && basename( dirname( $file ) ) !== $name ) {
			$problems[] = "Its name \"{$name}\" does not match its folder.";
		}

		$description = (string) $parsed['description'];
		if ( $description === '' ) {
			$problems[] = 'It has no description.';
		} elseif ( strlen( $description ) > 1024 ) {
			$problems[] = 'Its description is over 1024 characters.';
		}

		if ( trim( $parsed['body'] ) === '' ) {
			$problems[] = 'Its body is empty.';
		} elseif ( strlen( $parsed['body'] ) > Skill::MAX_BODY_BYTES ) {
			$problems[] = 'Its body is over the size limit.';
		}

		foreach ( $parsed['requires'] as $condition ) {
			if ( ! Skill::is_known_condition( $condition ) ) {
				$problems[] = "It requires \"{$condition}\", which no plugin defines.";
			}
		}

		foreach ( $parsed['abilities'] as $ability ) {
			if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $ability ) ) {
				$problems[] = "It links to \"{$ability}\", which is not registered.";
			}
		}

		return $problems;
	}
}
