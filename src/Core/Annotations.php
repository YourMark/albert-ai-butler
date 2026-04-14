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

/**
 * Annotations class
 *
 * Static factory for ability annotation arrays. The two boolean flags
 * combine into three behavior categories surfaced in the admin UI:
 *
 * - **Read** — `readonly: true, destructive: false` — only reads data.
 * - **Write** — `readonly: false, destructive: false` — creates or updates data.
 * - **Delete** — `readonly: false, destructive: true` — permanently removes data.
 *
 * @since 1.0.0
 */
class Annotations {
	/**
	 * Read-only ability (e.g. Find, View).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool}
	 */
	public static function read(): array {
		return [
			'readonly'    => true,
			'destructive' => false,
		];
	}

	/**
	 * Create ability (e.g. Create Post, Upload Media).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool}
	 */
	public static function create(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
		];
	}

	/**
	 * Update ability (e.g. Update Post, Set Featured Image).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool}
	 */
	public static function update(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
		];
	}

	/**
	 * Delete ability (e.g. Delete Post, Delete Term).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool}
	 */
	public static function delete(): array {
		return [
			'readonly'    => false,
			'destructive' => true,
		];
	}

	/**
	 * Generic action ability (non-destructive side effect).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool}
	 */
	public static function action(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
		];
	}
}
