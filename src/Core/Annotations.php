<?php
/**
 * Ability Annotations
 *
 * Provides standard annotation presets for the WP 6.9 ability meta annotations field.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Annotations class
 *
 * Static factory for ability annotation arrays. The two boolean flags
 * combine into three behavior categories surfaced in the admin UI:
 *
 * - **Read**: `readonly: true, destructive: false`, only reads data.
 * - **Write**: `readonly: false, destructive: false`, creates or updates data.
 * - **Delete**: `readonly: false, destructive: true`, permanently removes data.
 *
 * Every factory takes an optional `instructions` string. It is the guidance that
 * only matters once a model is about to call this particular ability: the
 * argument that is easy to get wrong, the call that has to come first, the thing
 * that looks like it will work and will not.
 *
 * It rides in `meta.annotations.instructions`, which `get-ability-info` returns
 * verbatim. That call is made only when a model is about to use the ability, so
 * the guidance costs nothing until the moment it is worth reading. This is why it
 * lives on the ability rather than in the discovery response, which is read once
 * per conversation whether or not it is needed, or in a long playbook that
 * nothing fetches on its own.
 *
 * Write it as one or two sentences addressed to the caller. It is read at the
 * moment of use, so lead with what to do, not with what the ability is.
 *
 * @since 1.0.0
 */
class Annotations {
	/**
	 * Read-only ability (e.g. Find, View).
	 *
	 * @since 1.0.0
	 *
	 * @param string $instructions Optional guidance, surfaced through `get-ability-info`.
	 *
	 * @return array{readonly: bool, destructive: bool, instructions?: string}
	 */
	public static function read( string $instructions = '' ): array {
		return self::with_instructions(
			[
				'readonly'    => true,
				'destructive' => false,
			],
			$instructions
		);
	}

	/**
	 * Create ability (e.g. Create Post, Upload Media).
	 *
	 * @since 1.0.0
	 *
	 * @param string $instructions Optional guidance, surfaced through `get-ability-info`.
	 *
	 * @return array{readonly: bool, destructive: bool, instructions?: string}
	 */
	public static function create( string $instructions = '' ): array {
		return self::with_instructions(
			[
				'readonly'    => false,
				'destructive' => false,
			],
			$instructions
		);
	}

	/**
	 * Update ability (e.g. Update Post, Set Featured Image).
	 *
	 * @since 1.0.0
	 *
	 * @param string $instructions Optional guidance, surfaced through `get-ability-info`.
	 *
	 * @return array{readonly: bool, destructive: bool, instructions?: string}
	 */
	public static function update( string $instructions = '' ): array {
		return self::with_instructions(
			[
				'readonly'    => false,
				'destructive' => false,
			],
			$instructions
		);
	}

	/**
	 * Delete ability (e.g. Delete Post, Delete Term).
	 *
	 * @since 1.0.0
	 *
	 * @param string $instructions Optional guidance, surfaced through `get-ability-info`.
	 *
	 * @return array{readonly: bool, destructive: bool, instructions?: string}
	 */
	public static function delete( string $instructions = '' ): array {
		return self::with_instructions(
			[
				'readonly'    => false,
				'destructive' => true,
			],
			$instructions
		);
	}

	/**
	 * Generic action ability (non-destructive side effect).
	 *
	 * @since 1.0.0
	 *
	 * @param string $instructions Optional guidance, surfaced through `get-ability-info`.
	 *
	 * @return array{readonly: bool, destructive: bool, instructions?: string}
	 */
	public static function action( string $instructions = '' ): array {
		return self::with_instructions(
			[
				'readonly'    => false,
				'destructive' => false,
			],
			$instructions
		);
	}

	/**
	 * Attach guidance to an annotation set, when there is any.
	 *
	 * An empty `instructions` key would be a promise of guidance that is not
	 * there, and `get-ability-info` returns the meta verbatim, so the key is
	 * absent rather than empty.
	 *
	 * @since 1.4.0
	 *
	 * @param array{readonly: bool, destructive: bool} $annotations  The behaviour flags.
	 * @param string                                   $instructions Optional guidance.
	 *
	 * @return array{readonly: bool, destructive: bool, instructions?: string}
	 */
	private static function with_instructions( array $annotations, string $instructions ): array {
		$instructions = trim( $instructions );

		if ( $instructions !== '' ) {
			$annotations['instructions'] = $instructions;
		}

		return $annotations;
	}
}
