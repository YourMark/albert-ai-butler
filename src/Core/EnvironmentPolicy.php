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
 * unless one of them says otherwise. An unknown policy is ignored with a
 * `_doing_it_wrong()`, so a typo cannot pass for a working policy.
 *
 * The environment comes from `wp_get_environment_type()`, so anything that sets
 * it works, Bedrock's `WP_ENV` included. Core reads any value outside its four
 * types as `production`.
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
	 * Switch on every ability that declares itself read or write, not destructive.
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
	 * Sources already reported this request; current() runs on every effective() call.
	 *
	 * @since 1.6.0
	 * @var array<string, true>
	 */
	private static array $reported = [];

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
		$entry       = is_array( $map ) ? ( $map[ $environment ] ?? null ) : null;

		if ( self::is_known( $entry ) ) {
			return [
				'environment' => $environment,
				'policy'      => $entry,
				'source'      => 'constant',
			];
		}

		if ( $entry !== null ) {
			self::report_unknown( self::CONSTANT, $environment );
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

		if ( ! self::is_known( $policy ) ) {
			self::report_unknown( 'albert/abilities/environment_policy', $environment );
			$policy = self::TOGGLES;
		}

		if ( $policy === self::TOGGLES ) {
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
				static function ( string $id ) use ( $registered ): bool {
					$ability = $registered[ $id ] ?? null;
					if ( ! $ability instanceof \WP_Ability ) {
						return true;
					}

					$annotations = $ability->get_meta_item( 'annotations', [] );

					return ! AnnotationPresenter::declares_not_destructive( is_array( $annotations ) ? $annotations : [], $id );
				}
			)
		);
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

	/**
	 * Report an unknown policy, once per source per request.
	 *
	 * @param string $source      The constant or filter that set it.
	 * @param string $environment The environment it was set for.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private static function report_unknown( string $source, string $environment ): void {
		if ( isset( self::$reported[ $source ] ) ) {
			return;
		}

		self::$reported[ $source ] = true;

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: 1: constant or filter name, 2: environment type */
				esc_html__( '%1$s sets an unknown policy for the %2$s environment, so it is ignored. Use toggles, read-write or all.', 'albert-ai-butler' ),
				esc_html( $source ),
				esc_html( $environment )
			),
			'1.6.0'
		);
	}
}
