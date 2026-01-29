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
 * Static factory for ability annotation arrays describing behavior characteristics:
 * - readonly:   The ability only reads data and never modifies state.
 * - destructive: The ability permanently destroys or removes data.
 * - idempotent:  Repeated calls with the same input produce the same result.
 *
 * @since 1.0.0
 */
class Annotations {
	/**
	 * Read-only ability (e.g. Find, View).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public static function read(): array {
		return [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];
	}

	/**
	 * Create ability (e.g. Create Post, Upload Media).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public static function create(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		];
	}

	/**
	 * Update ability (e.g. Update Post, Set Featured Image).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public static function update(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		];
	}

	/**
	 * Delete ability (e.g. Delete Post, Delete Term).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public static function delete(): array {
		return [
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => true,
		];
	}

	/**
	 * Generic action ability (non-idempotent, non-destructive side effect).
	 *
	 * @since 1.0.0
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public static function action(): array {
		return [
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		];
	}
}
