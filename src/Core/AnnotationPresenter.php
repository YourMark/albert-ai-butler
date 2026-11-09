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

use Albert\SafeMode\RiskPolicy;

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
	 *  - 'key'         string — stable identifier ('read', 'write', 'destructive').
	 *  - 'label'       string — short translated label shown on the chip.
	 *  - 'description' string — one-sentence explanation used for tooltips.
	 *  - 'icon'        string — Dashicon class name.
	 *  - 'tone'        string — one of: neutral, warning, danger.
	 *
	 * If the ability has no annotations the presenter falls back to the
	 * slug heuristic ({@see self::heuristic_chips()}) so older custom
	 * abilities still display something sensible.
	 *
	 * @param array<string, mixed> $annotations Raw annotations from get_meta()['annotations'].
	 * @param string               $ability_id  Ability id, used only for the fallback heuristic.
	 *
	 * @return array<int, array{key: string, label: string, description: string, icon: string, tone: string}>
	 * @since 1.1.0
	 */
	public static function chips_for( array $annotations, string $ability_id = '' ): array {
		if ( self::is_destructive( $annotations, $ability_id ) ) {
			return [ self::destructive_chip() ];
		}

		if ( empty( $annotations ) ) {
			return self::heuristic_chips( $ability_id );
		}

		return [ empty( $annotations['readonly'] ) ? self::makes_changes_chip() : self::read_only_chip() ];
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
	 * "Destructive" chip.
	 *
	 * @return array{key: string, label: string, description: string, icon: string, tone: string}
	 * @since 1.1.0
	 */
	private static function destructive_chip(): array {
		return [
			'key'         => 'destructive',
			'label'       => __( 'Destructive', 'albert-ai-butler' ),
			'description' => __( 'Can remove or overwrite data in a way that may not be recoverable, or has a wide impact on your site.', 'albert-ai-butler' ),
			'icon'        => 'dashicons-trash',
			'tone'        => 'danger',
		];
	}

	/**
	 * Derive chips from the ability id when no annotations are declared.
	 *
	 * Uses the same write-prefix heuristic as the previous AbstractAbilitiesPage,
	 * so existing third-party abilities without annotations still look sensible.
	 *
	 * @param string $ability_id Ability id, e.g. 'albert/create-post'.
	 *
	 * @return array<int, array{key: string, label: string, description: string, icon: string, tone: string}>
	 * @since 1.1.0
	 */
	public static function heuristic_chips( string $ability_id ): array {
		if ( $ability_id === '' ) {
			return [];
		}

		$parts  = explode( '/', $ability_id, 2 );
		$action = $parts[1] ?? $parts[0];

		if ( str_starts_with( $action, 'delete-' ) ) {
			return [ self::destructive_chip() ];
		}

		foreach ( [ 'create-', 'update-', 'upload-', 'set-' ] as $prefix ) {
			if ( str_starts_with( $action, $prefix ) ) {
				return [ self::makes_changes_chip() ];
			}
		}

		return [ self::read_only_chip() ];
	}

	/**
	 * Whether an ability belongs in the Destructive group.
	 *
	 * The one answer for the chips and the environment policy. Safe mode holds
	 * more than this: it also holds what does not declare itself.
	 *
	 * @param array<string, mixed> $annotations Raw annotations.
	 * @param string               $ability_id  Ability id for the name list and the fallback heuristic.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public static function is_destructive( array $annotations, string $ability_id = '' ): bool {
		if ( RiskPolicy::is_high_risk_ability( $ability_id ) ) {
			return true;
		}

		if ( isset( $annotations['destructive'] ) ) {
			return (bool) $annotations['destructive'];
		}

		$parts  = explode( '/', $ability_id, 2 );
		$action = $parts[1] ?? $parts[0];

		return str_starts_with( $action, 'delete-' );
	}

	/**
	 * Whether an ability declares itself outside the Destructive group.
	 *
	 * Strict: an undeclared ability, or a write that leaves `destructive` unset,
	 * declares nothing.
	 *
	 * @param \WP_Ability $ability The registered ability.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public static function declares_not_destructive( \WP_Ability $ability ): bool {
		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];

		if ( self::is_destructive( $annotations, $ability->get_name() ) ) {
			return false;
		}

		$destructive = $annotations['destructive'] ?? null;

		return $destructive === false || ( $destructive === null && ( $annotations['readonly'] ?? null ) === true );
	}
}
