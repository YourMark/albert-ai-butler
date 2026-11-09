<?php
/**
 * Annotation Presenter
 *
 * Maps ability annotation arrays into chip DTOs for the admin UI.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.1.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

/**
 * AnnotationPresenter class
 *
 * Pure helper that converts an ability's WP 6.9 annotations array
 * (read / write / delete) into a list of chip DTOs the
 * AbilitiesPage renders. Keeping the mapping here makes the view thin
 * and the logic unit-testable without a WordPress bootstrap.
 *
 * @since 1.1.0
 */
class AnnotationPresenter {

	/**
	 * Build chip DTOs for an ability.
	 *
	 * Each chip is an array with:
	 *  - 'key'         string — stable identifier ('read', 'write', 'delete', 'undeclared').
	 *  - 'label'       string — short translated label shown on the chip.
	 *  - 'description' string — one-sentence explanation used for tooltips.
	 *  - 'icon'        string — Dashicon class name.
	 *  - 'tone'        string — one of: neutral, warning, danger.
	 *
	 * An ability that declares neither `readonly` nor `destructive` gets one
	 * "Not declared" chip: its name says nothing reliable about what it does.
	 *
	 * @param array<string, mixed> $annotations Raw annotations from get_meta()['annotations'].
	 * @param string               $ability_id  Unused since 1.6.0, kept for callers that pass it.
	 *
	 * @return array<int, array{key: string, label: string, description: string, icon: string, tone: string}>
	 * @since 1.1.0
	 */
	public static function chips_for( array $annotations, string $ability_id = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Kept for callers that pass it.
		if ( ! self::is_declared( $annotations ) ) {
			return [ self::not_declared_chip() ];
		}

		// Strict, as everywhere else (defaults, safe mode): `readonly => 1` is not read-only.
		$readonly = ( $annotations['readonly'] ?? null ) === true;
		$chips    = [];

		if ( $readonly ) {
			$chips[] = self::read_only_chip();
		} elseif ( empty( $annotations['destructive'] ) ) {
			// Non-readonly and non-destructive: ability creates or updates data.
			$chips[] = self::makes_changes_chip();
		}

		if ( ! empty( $annotations['destructive'] ) ) {
			$chips[] = self::deletes_data_chip();
		}

		return $chips;
	}

	/**
	 * Whether an ability says if it changes anything.
	 *
	 * Core fills missing annotations with null, so only a boolean `readonly` or
	 * `destructive` counts as a declaration.
	 *
	 * @param array<string, mixed> $annotations Raw annotations.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public static function is_declared( array $annotations ): bool {
		return is_bool( $annotations['readonly'] ?? null ) || is_bool( $annotations['destructive'] ?? null );
	}

	/**
	 * "Not declared" chip: the ability does not say what it does.
	 *
	 * @return array{key: string, label: string, description: string, icon: string, tone: string}
	 * @since 1.6.0
	 */
	private static function not_declared_chip(): array {
		return [
			'key'         => 'undeclared',
			'label'       => __( 'Not declared', 'albert-ai-butler' ),
			'description' => __( "This ability doesn't say whether it reads, changes or deletes anything, so Albert treats it as one that can make changes.", 'albert-ai-butler' ),
			'icon'        => 'dashicons-editor-help',
			'tone'        => 'neutral',
		];
	}

	/**
	 * "Read" chip — ability only reads data.
	 *
	 * @return array{key: string, label: string, description: string, icon: string, tone: string}
	 * @since 1.1.0
	 */
	private static function read_only_chip(): array {
		return [
			'key'         => 'read',
			'label'       => __( 'Read', 'albert-ai-butler' ),
			'description' => __( 'Only reads data. It never creates, changes, or deletes anything on your site.', 'albert-ai-butler' ),
			'icon'        => 'dashicons-visibility',
			'tone'        => 'neutral',
		];
	}

	/**
	 * "Write" chip — ability creates or updates data.
	 *
	 * @return array{key: string, label: string, description: string, icon: string, tone: string}
	 * @since 1.1.0
	 */
	private static function makes_changes_chip(): array {
		return [
			'key'         => 'write',
			'label'       => __( 'Write', 'albert-ai-butler' ),
			'description' => __( 'Creates or updates content, settings, or files on your site.', 'albert-ai-butler' ),
			'icon'        => 'dashicons-edit',
			'tone'        => 'warning',
		];
	}

	/**
	 * "Delete" chip — ability permanently destroys data.
	 *
	 * @return array{key: string, label: string, description: string, icon: string, tone: string}
	 * @since 1.1.0
	 */
	private static function deletes_data_chip(): array {
		return [
			'key'         => 'delete',
			'label'       => __( 'Delete', 'albert-ai-butler' ),
			'description' => __( 'Permanently removes content. Deleted items may not be recoverable.', 'albert-ai-butler' ),
			'icon'        => 'dashicons-trash',
			'tone'        => 'danger',
		];
	}

	/**
	 * Derive chips from the ability id when no annotations are declared.
	 *
	 * @deprecated 1.6.0 A name is not a declaration; {@see self::chips_for()} labels
	 *             an unannotated ability "Not declared".
	 *
	 * @param string $ability_id Ability id, e.g. 'albert/create-post'.
	 *
	 * @return array<int, array{key: string, label: string, description: string, icon: string, tone: string}>
	 * @since 1.1.0
	 */
	public static function heuristic_chips( string $ability_id ): array {
		_deprecated_function( __METHOD__, '1.6.0', __CLASS__ . '::chips_for' );

		if ( $ability_id === '' ) {
			return [];
		}

		$parts  = explode( '/', $ability_id, 2 );
		$action = $parts[1] ?? $parts[0];

		if ( str_starts_with( $action, 'delete-' ) ) {
			return [ self::deletes_data_chip() ];
		}

		foreach ( [ 'create-', 'update-', 'upload-', 'set-' ] as $prefix ) {
			if ( str_starts_with( $action, $prefix ) ) {
				return [ self::makes_changes_chip() ];
			}
		}

		return [ self::read_only_chip() ];
	}

	/**
	 * Whether the ability should trigger a confirmation prompt when enabled.
	 *
	 * Without a `destructive` flag, only a declared read-only ability is safe;
	 * an undeclared one is treated as destructive.
	 *
	 * @param array<string, mixed> $annotations Raw annotations.
	 * @param string               $ability_id  Unused since 1.6.0, kept for callers that pass it.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public static function is_destructive( array $annotations, string $ability_id = '' ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Kept for callers that pass it.
		if ( isset( $annotations['destructive'] ) ) {
			return (bool) $annotations['destructive'];
		}

		return ( $annotations['readonly'] ?? null ) !== true;
	}
}
