<?php
/**
 * Which skills are switched off
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.7.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

use Albert\Settings\Value;

/**
 * One list of switched-off skill slugs, whatever ships the skill.
 *
 * A switched-off skill leaves the index, the prompts and the ability links,
 * and `get-skill` refuses it by name. Read through {@see Value}, so a site can
 * pin it in code. An assistant can never change it ({@see \Albert\SafeMode\ConnectionGuard}).
 *
 * @since 1.7.0
 */
final class SkillSwitch {

	/**
	 * Option holding the switched-off slugs.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const OPTION = 'albert_disabled_skills';

	/**
	 * The switched-off slugs.
	 *
	 * @return list<string>
	 * @since 1.7.0
	 */
	public static function off(): array {
		$value = Value::get( self::OPTION, [] );

		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * Whether a skill is switched on.
	 *
	 * @param string $slug The skill.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public static function is_on( string $slug ): bool {
		return ! in_array( $slug, self::off(), true );
	}

	/**
	 * Switch a skill on or off.
	 *
	 * @param string $slug The skill.
	 * @param bool   $on   Whether it is on.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function set( string $slug, bool $on ): void {
		$off = array_values( array_diff( self::off(), [ $slug ] ) );
		if ( ! $on ) {
			$off[] = $slug;
		}

		update_option( self::OPTION, $off, false );
	}
}
