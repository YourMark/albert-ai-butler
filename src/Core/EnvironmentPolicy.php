<?php
/**
 * Environment Policy
 *
 * @package Albert
 * @subpackage Core
 * @since      1.6.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Switches abilities on for assistants by `WP_ENVIRONMENT_TYPE`, without writing the toggles.
 *
 * The toggles live in the database, so a staging copy refreshed from production
 * inherits production's locked-down set. A policy set in code survives that, and
 * removing it brings the site's own toggles back. It only ever widens what is
 * switched on; safe mode still holds what it would hold.
 *
 * Resolution: `ALBERT_ENVIRONMENT_ABILITIES` (an array of environment type to
 * policy) wins when it names this environment with a known policy; otherwise
 * `albert/abilities/environment_policy` decides. Production uses the toggles
 * unless one of them says otherwise.
 *
 * @since 1.6.0
 */
class EnvironmentPolicy {

	/**
	 * The saved toggles decide. Today's behaviour, and the default everywhere.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const TOGGLES = 'toggles';

	/**
	 * Switch on every ability that declares it deletes nothing.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const READ_WRITE = 'read-write';

	/**
	 * Switch on every ability.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const ALL = 'all';

	/**
	 * The constant that maps environment types to policies.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const CONSTANT = 'ALBERT_ENVIRONMENT_ABILITIES';

	/**
	 * The policy in force on this site, and where it came from.
	 *
	 * @return array{environment: string, policy: string, source: string} Source is
	 *         `constant`, `filter`, or `default` when nothing set one.
	 * @since 1.6.0
	 */
	public static function current(): array {
		$environment = wp_get_environment_type();
		$map         = defined( self::CONSTANT ) ? constant( self::CONSTANT ) : null;

		if ( is_array( $map ) && isset( $map[ $environment ] ) && self::is_known( $map[ $environment ] ) ) {
			return [
				'environment' => $environment,
				'policy'      => $map[ $environment ],
				'source'      => 'constant',
			];
		}

		/**
		 * Filters the ability policy for this site's environment type.
		 *
		 * Not called when `ALBERT_ENVIRONMENT_ABILITIES` sets a policy for this
		 * environment. The saved toggles are never written; return `toggles`
		 * to use them again.
		 *
		 * @since 1.6.0
		 *
		 * @param string $policy      `toggles` (default), `read-write` or `all`.
		 * @param string $environment The value of wp_get_environment_type().
		 */
		$policy = apply_filters( 'albert/abilities/environment_policy', self::TOGGLES, $environment );

		if ( ! self::is_known( $policy ) || $policy === self::TOGGLES ) {
			return [
				'environment' => $environment,
				'policy'      => self::TOGGLES,
				'source'      => 'default',
			];
		}

		return [
			'environment' => $environment,
			'policy'      => $policy,
			'source'      => 'filter',
		];
	}

	/**
	 * Whether a policy other than the toggles is in force.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public static function is_active(): bool {
		return self::current()['policy'] !== self::TOGGLES;
	}

	/**
	 * Narrow a disabled list to what the policy leaves switched off.
	 *
	 * @param array<int, string> $disabled Ability IDs switched off by the toggles.
	 *
	 * @return array<int, string>
	 * @since 1.6.0
	 */
	public static function apply( array $disabled ): array {
		$policy = self::current()['policy'];

		if ( $policy === self::ALL ) {
			return [];
		}

		if ( $policy !== self::READ_WRITE ) {
			return $disabled;
		}

		$registered = AbilitiesRegistry::get_all_raw();

		return array_values(
			array_filter(
				$disabled,
				static fn( string $id ): bool => ! ( ( $registered[ $id ] ?? null ) instanceof \WP_Ability && self::deletes_nothing( $registered[ $id ] ) )
			)
		);
	}

	/**
	 * Whether an ability declares it is read-only or not destructive.
	 *
	 * Annotations only, strict booleans: an undeclared ability, or a write that
	 * leaves `destructive` unset, keeps its toggle.
	 *
	 * @param \WP_Ability $ability The registered ability.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private static function deletes_nothing( \WP_Ability $ability ): bool {
		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];

		$destructive = $annotations['destructive'] ?? null;

		return $destructive === false || ( $destructive === null && ( $annotations['readonly'] ?? null ) === true );
	}

	/**
	 * Whether a value names a policy.
	 *
	 * @param mixed $policy Candidate policy.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private static function is_known( mixed $policy ): bool {
		return in_array( $policy, [ self::TOGGLES, self::READ_WRITE, self::ALL ], true );
	}
}
