<?php
/**
 * Abilities Payload
 *
 * Builds the normalized abilities dataset consumed by the DataViews admin app.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.3.0
 */

namespace Albert\Admin;

use Albert\Core\AbilitiesRegistry;
use Albert\Core\AbilitiesState;
use Albert\Core\AnnotationPresenter;
use Albert\Logging\Repository as LoggingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * AbilitiesPayload class
 *
 * Pure data assembler that turns the live Abilities API registry into the shape
 * the React screen expects: a flat list of ability rows plus the category and
 * source filter options and headline counts. Reused by the REST controller so
 * the screen has a single source for its data.
 *
 * @since 1.3.0
 */
class AbilitiesPayload {

	/**
	 * Build the full payload for the abilities screen.
	 *
	 * @return array{
	 *     abilities: array<int, array<string, mixed>>,
	 *     categories: array<int, array{value: string, label: string}>,
	 *     sources: array<int, array{value: string, label: string}>,
	 *     counts: array{total: int, enabled: int}
	 * }
	 * @since 1.3.0
	 */
	public static function build(): array {
		$categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : [];
		$disabled   = AbilitiesState::disabled();
		$roles      = self::roles();
		// Raw registry, not wp_get_abilities(): the management screen must show every
		// ability the site has, including any a third-party filter hides from the
		// filtered view — otherwise a row the admin can still toggle goes missing.
		$all = AbilitiesRegistry::get_all_raw();

		// The MCP adapter's own tools (discover / get-info / execute) are protocol
		// infrastructure, not user-manageable abilities — never surface them on the
		// screen. They can't be disabled regardless (see
		// AbilitiesState::effective()).
		$protected = AbilitiesRegistry::get_protected_abilities();
		$all       = array_filter(
			$all,
			static fn( \WP_Ability $ability ): bool => ! in_array( $ability->get_name(), $protected, true )
		);

		$ids = [];
		foreach ( $all as $ability ) {
			$ids[] = $ability->get_name();
		}
		$logs = ( new LoggingRepository() )->latest_bulk( $ids );

		$abilities     = [];
		$category_opts = [];
		$source_opts   = [];
		$enabled_count = 0;

		foreach ( $all as $ability ) {
			$row         = self::row_from_ability( $ability, $categories, $disabled, $roles, $logs[ $ability->get_name() ] ?? null );
			$abilities[] = $row;

			if ( $row['enabled'] ) {
				++$enabled_count;
			}
			if ( $row['category'] !== '' && ! isset( $category_opts[ $row['category'] ] ) ) {
				$category_opts[ $row['category'] ] = $row['categoryLabel'];
			}
			if ( $row['source'] !== '' && ! isset( $source_opts[ $row['source'] ] ) ) {
				$source_opts[ $row['source'] ] = $row['sourceLabel'];
			}
		}

		usort(
			$abilities,
			static function ( array $a, array $b ): int {
				$by_category = strcasecmp( $a['categoryLabel'], $b['categoryLabel'] );
				return $by_category !== 0 ? $by_category : strcasecmp( $a['label'], $b['label'] );
			}
		);
		asort( $category_opts, SORT_NATURAL | SORT_FLAG_CASE );
		asort( $source_opts, SORT_NATURAL | SORT_FLAG_CASE );

		return [
			'abilities'  => $abilities,
			'categories' => self::to_options( $category_opts ),
			'sources'    => self::to_options( $source_opts ),
			'roles'      => self::role_options( $roles ),
			'counts'     => [
				'total'   => count( $abilities ),
				'enabled' => $enabled_count,
			],
		];
	}

	/**
	 * Build a single ability row by ID, or null if it is not registered.
	 *
	 * Used by the REST controller to return just the row it changed.
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.3.0
	 */
	public static function row( string $ability_id ): ?array {
		// `wp_has_ability()` is the quiet existence check; `wp_get_ability()`
		// raises _doing_it_wrong() before returning null for anything the
		// registry does not hold, so probing with it turns "no such row" into a
		// notice.
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_id ) ) {
			return null;
		}

		$ability = wp_get_ability( $ability_id );
		if ( $ability === null ) {
			return null;
		}

		$categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : [];
		$log        = ( new LoggingRepository() )->latest_for_ability( $ability_id );

		return self::row_from_ability( $ability, $categories, AbilitiesState::disabled(), self::roles(), $log );
	}

	/**
	 * Normalize one ability into a row for the screen.
	 *
	 * @param \WP_Ability          $ability    The ability.
	 * @param array<string, mixed> $categories Map from wp_get_ability_categories().
	 * @param array<int, string>   $disabled   Disabled ability IDs.
	 * @param array<string, mixed> $roles      Map from WP_Roles::roles (slug => role data).
	 * @param object|null          $log        Latest log row for this ability, or null.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.0
	 */
	private static function row_from_ability( \WP_Ability $ability, array $categories, array $disabled, array $roles, ?object $log = null ): array {
		$id          = $ability->get_name();
		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];
		$chips       = AnnotationPresenter::chips_for( $annotations, $id );
		$source      = AbilitiesRegistry::get_ability_source( $id );
		$capability  = AbilitiesRegistry::resolve_required_capability( $ability );

		$row = [
			'id'              => $id,
			'label'           => $ability->get_label(),
			'description'     => $ability->get_description(),
			'category'        => $ability->get_category(),
			'categoryLabel'   => self::category_label( $ability->get_category(), $categories ),
			'source'          => $source['slug'],
			'sourceLabel'     => $source['label'],
			// Deprecated: use `source`/`sourceLabel` above. Kept, unchanged,
			// because docs/extending-the-abilities-screen.md documents these
			// two as part of the `albert/abilities/payload_row` row contract;
			// an add-on already reading them must keep working.
			'supplier'        => $source['slug'],
			'supplierLabel'   => $source['label'],
			'operation'       => $chips[0]['key'] ?? 'undeclared',
			'enabled'         => ! in_array( $id, $disabled, true ),
			'capability'      => $capability,
			'capabilityRoles' => self::roles_with_capability( $capability, $roles ),
			'lastUsed'        => self::last_used( $log ),
			'badges'          => [],
			'inputs'          => self::inputs( $ability->get_input_schema() ),
			'output'          => self::output( $ability->get_output_schema() ),
			'annotations'     => array_map(
				static fn( array $chip ): array => [
					'key'         => $chip['key'],
					'label'       => $chip['label'],
					'description' => $chip['description'],
					'tone'        => $chip['tone'],
				],
				$chips
			),
		];

		/**
		 * Filters a single normalized ability row before it reaches the screen.
		 *
		 * Fires for every row on both the bulk build path (the full screen
		 * dataset) and the single-row path (after a toggle), so an add-on can
		 * augment rows consistently — for example to append a "badges" entry that
		 * surfaces a row indicator in the overview cell and the detail fly-in.
		 * Each badge is `{ id, label, tone, title? }`, where `tone` is one of
		 * `info`, `warning`, or `neutral`.
		 *
		 * Social contract: add-ons may APPEND keys to `$row` but must not remove
		 * or overwrite the core keys Albert sets — Core (and other add-ons) rely
		 * on them being present and unchanged.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string, mixed> $row     The normalized ability row.
		 * @param \WP_Ability          $ability The ability the row was built from.
		 */
		$row = apply_filters( 'albert/abilities/payload_row', $row, $ability );

		return $row;
	}

	/**
	 * Flatten an input JSON schema into a list of field descriptors.
	 *
	 * @param array<string, mixed> $schema Input schema.
	 *
	 * @return array<int, array{name: string, type: string, required: bool, description: string}>
	 * @since 1.3.0
	 */
	private static function inputs( array $schema ): array {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
		$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : [];

		$fields = [];
		foreach ( $properties as $name => $definition ) {
			$type = $definition['type'] ?? '';
			if ( is_array( $type ) ) {
				$type = implode( '|', $type );
			}

			$fields[] = [
				'name'        => (string) $name,
				'type'        => (string) $type,
				'required'    => in_array( $name, $required, true ),
				'description' => (string) ( $definition['description'] ?? '' ),
			];
		}

		return $fields;
	}

	/**
	 * Extract a human-readable description from an output JSON schema.
	 *
	 * @param array<string, mixed> $schema Output schema.
	 *
	 * @return string
	 * @since 1.3.0
	 */
	private static function output( array $schema ): string {
		return (string) ( $schema['description'] ?? '' );
	}

	/**
	 * Resolve a category slug to its human label.
	 *
	 * @param string               $slug       Category slug.
	 * @param array<string, mixed> $categories Map from wp_get_ability_categories().
	 *
	 * @return string
	 * @since 1.3.0
	 */
	private static function category_label( string $slug, array $categories ): string {
		if ( $slug === '' ) {
			return '';
		}

		$category = $categories[ $slug ] ?? null;
		if ( is_object( $category ) && method_exists( $category, 'get_label' ) ) {
			return (string) $category->get_label();
		}
		if ( is_array( $category ) && isset( $category['label'] ) ) {
			return (string) $category['label'];
		}

		return ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	/**
	 * Convert a slug => label map into a value/label option list.
	 *
	 * @param array<string, string> $map Slug => label.
	 *
	 * @return array<int, array{value: string, label: string}>
	 * @since 1.3.0
	 */
	private static function to_options( array $map ): array {
		$options = [];
		foreach ( $map as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => (string) $label,
			];
		}

		return $options;
	}

	/**
	 * Get the editable roles map (slug => role data) for capability lookups.
	 *
	 * @return array<string, mixed>
	 * @since 1.3.0
	 */
	private static function roles(): array {
		return function_exists( 'wp_roles' ) ? wp_roles()->roles : [];
	}

	/**
	 * Convert the roles map into a value/label option list for the screen.
	 *
	 * Exposed in the payload so add-on panel sections (e.g. Premium's advanced
	 * permission rule builder) have role options without re-deriving them.
	 *
	 * @param array<string, mixed> $roles Map from WP_Roles::roles.
	 *
	 * @return array<int, array{value: string, label: string}>
	 * @since 1.3.0
	 */
	private static function role_options( array $roles ): array {
		$options = [];
		foreach ( $roles as $slug => $role ) {
			$options[] = [
				'value' => (string) $slug,
				'label' => (string) ( $role['name'] ?? $slug ),
			];
		}

		return $options;
	}

	/**
	 * Normalize a log row into the "last used" descriptor for the screen.
	 *
	 * Handles both shapes returned by the repository: latest_bulk() includes a
	 * `created_ts` epoch, latest_for_ability() only a `created_at` datetime.
	 *
	 * @param object|null $log Latest log row, or null when never run.
	 *
	 * @return array{status: string, timestamp: int, human: string}|null
	 * @since 1.3.0
	 */
	private static function last_used( ?object $log ): ?array {
		if ( $log === null ) {
			return null;
		}

		if ( isset( $log->created_ts ) ) {
			$timestamp = (int) $log->created_ts;
		} elseif ( isset( $log->created_at ) ) {
			$timestamp = (int) strtotime( (string) $log->created_at . ' UTC' );
		} else {
			$timestamp = 0;
		}

		return [
			'status'    => (string) ( $log->status ?? '' ),
			'timestamp' => $timestamp,
			'human'     => $timestamp > 0
				/* translators: %s: human-readable time difference, e.g. "2 hours". */
				? sprintf( __( '%s ago', 'albert-ai-butler' ), human_time_diff( $timestamp ) )
				: '',
		];
	}

	/**
	 * List the display names of roles that grant a capability.
	 *
	 * @param string               $capability Capability slug.
	 * @param array<string, mixed> $roles      Map from WP_Roles::roles.
	 *
	 * @return array<int, string>
	 * @since 1.3.0
	 */
	private static function roles_with_capability( string $capability, array $roles ): array {
		if ( $capability === '' ) {
			return [];
		}

		$names = [];
		foreach ( $roles as $role ) {
			if ( ! empty( $role['capabilities'][ $capability ] ) ) {
				$names[] = (string) ( $role['name'] ?? '' );
			}
		}

		return array_values( array_filter( $names ) );
	}
}
