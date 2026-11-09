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
 * (read / write / destructive / not declared) into a list of chip DTOs the
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
	 *  - 'key'         string — stable identifier ('read', 'write', 'destructive', 'undeclared').
	 *  - 'label'       string — short translated label shown on the chip.
	 *  - 'description' string — one-sentence explanation used for tooltips.
	 *  - 'icon'        string — Dashicon class name.
	 *  - 'tone'        string — one of: neutral, warning, danger.
	 *
	 * An ability that declares neither `readonly` nor `destructive` gets one
	 * "Not declared" chip: its name says nothing reliable about what it does.
	 *
	 * A destructive flag or a name on safe mode's high-risk list wins over
	 * `readonly`: a contradictory declaration reads the safe way.
	 *
	 * @param array<string, mixed> $annotations Raw annotations from get_meta()['annotations'].
	 * @param string               $ability_id  Ability id, for the high-risk name list.
	 *
	 * @return array<int, array{key: string, label: string, description: string, icon: string, tone: string}>
	 * @since 1.1.0
	 */
	public static function chips_for( array $annotations, string $ability_id = '' ): array {
		if ( RiskPolicy::is_high_risk_ability( $ability_id ) || ! empty( $annotations['destructive'] ) ) {
			return [ self::destructive_chip() ];
		}

		if ( ! self::is_declared( $annotations ) ) {
			return [ self::not_declared_chip() ];
		}

		// Strict, as everywhere else (defaults, safe mode): `readonly => 1` is not read-only.
		return [ ( $annotations['readonly'] ?? null ) === true ? self::read_only_chip() : self::makes_changes_chip() ];
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
	 * "Destructive" chip: may remove or overwrite beyond recovery, or reaches far.
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
	 * Whether an ability may be destructive: the one answer for the environment policy.
	 *
	 * Fail-safe: a name on safe mode's high-risk list is destructive whatever it
	 * declares, and without a `destructive` flag only a declared read-only
	 * ability is safe.
	 *
	 * @param array<string, mixed> $annotations Raw annotations.
	 * @param string               $ability_id  Ability id, for the high-risk name list.
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

		return ( $annotations['readonly'] ?? null ) !== true;
	}

	/**
	 * Whether an ability declares itself outside the Destructive group.
	 *
	 * Strict: only a boolean `destructive: false`, or a boolean `readonly: true`
	 * without a `destructive` flag, counts.
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
