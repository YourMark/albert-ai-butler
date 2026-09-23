<?php
/**
 * Safe-mode risk classification.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use WP_Ability;

/**
 * A second axis for the gate, because `annotations.destructive` is not enough.
 *
 * The annotation catches deletes but misses what actually compromises a
 * WordPress site: changing an administrator's email or role (account takeover),
 * creating an administrator, or writing a foundational option like `siteurl`.
 * Several of those ride on abilities annotated `destructive: false` — Albert's
 * own `update-user`/`create-user`, and any third-party ability that reuses the
 * "update"/"create" annotation preset for something that grants privileges.
 *
 * Two layers, because a name list alone cannot see abilities it has never heard
 * of:
 *
 * 1. **A name list** for Albert's own high-risk abilities, extensible by add-ons
 *    that know their own surface.
 * 2. **Input-pattern rules** that hold *any* call — third-party included — whose
 *    resolved input grants an administrator-capability role or writes a
 *    high-risk option. This is the axis that survives a plugin registering
 *    `foo/update-option` tomorrow.
 *
 * The hard resource guard ({@see ConnectionGuard}) is the backstop for Albert's
 * own gate-controlling options; this decides what a person gets to *approve*.
 *
 * @since 1.5.0
 */
class RiskPolicy {

	/**
	 * Albert's own abilities held regardless of their annotation.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private const HELD_ABILITIES = [
		'albert/update-user',
		'albert/create-user',
		'albert/delete-user',
	];

	/**
	 * Option names whose value being written is high-risk.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private const HIGH_RISK_OPTIONS = [
		'siteurl',
		'home',
		'admin_email',
		'default_role',
		'users_can_register',
		'template',
		'stylesheet',
	];

	/**
	 * Input keys an option-writing ability tends to name its target option with.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private const OPTION_KEYS = [ 'option', 'option_name', 'name', 'key', 'setting' ];

	/**
	 * Whether this call must be held regardless of its annotation.
	 *
	 * @param WP_Ability           $ability The ability about to execute.
	 * @param array<string, mixed> $input   Its resolved input.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function must_hold( WP_Ability $ability, array $input ): bool {
		return $this->is_held_ability( $ability->get_name() )
			|| $this->grants_admin_role( $input )
			|| $this->writes_high_risk_option( $input );
	}

	/**
	 * Whether an ability is on the high-risk name list.
	 *
	 * @param string $ability_name The ability id.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private function is_held_ability( string $ability_name ): bool {
		/**
		 * Filters the abilities safe mode always holds, regardless of annotation.
		 *
		 * Add-ons append the ids of their own high-risk abilities.
		 *
		 * @since 1.5.0
		 *
		 * @param list<string> $abilities Ability ids.
		 */
		$held = apply_filters( 'albert/safe_mode/high_risk_abilities', self::HELD_ABILITIES );

		return is_array( $held ) && in_array( $ability_name, $held, true );
	}

	/**
	 * Whether the input assigns a role that can manage the site.
	 *
	 * Reads the conventional `role` / `roles` keys and resolves each slug to a
	 * WordPress role, holding when any grants `manage_options`. That is the
	 * privilege-escalation vector the annotation axis misses.
	 *
	 * @param array<string, mixed> $input Resolved input.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private function grants_admin_role( array $input ): bool {
		$candidates = [];

		if ( isset( $input['role'] ) && is_string( $input['role'] ) ) {
			$candidates[] = $input['role'];
		}

		if ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
			foreach ( $input['roles'] as $role ) {
				if ( is_string( $role ) ) {
					$candidates[] = $role;
				}
			}
		}

		foreach ( $candidates as $slug ) {
			$role = get_role( $slug );

			if ( $role !== null && $role->has_cap( 'manage_options' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the input names a high-risk option to write.
	 *
	 * Checks the values of the option-naming keys an option-writing ability
	 * typically uses, so a third-party `foo/update-option` targeting `siteurl` is
	 * held without Albert knowing that ability exists.
	 *
	 * @param array<string, mixed> $input Resolved input.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private function writes_high_risk_option( array $input ): bool {
		/**
		 * Filters the option names safe mode treats as high-risk to write.
		 *
		 * @since 1.5.0
		 *
		 * @param list<string> $options Option names.
		 */
		$high_risk = apply_filters( 'albert/safe_mode/high_risk_options', self::HIGH_RISK_OPTIONS );

		if ( ! is_array( $high_risk ) ) {
			return false;
		}

		foreach ( self::OPTION_KEYS as $key ) {
			if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && in_array( $input[ $key ], $high_risk, true ) ) {
				return true;
			}
		}

		return false;
	}
}
