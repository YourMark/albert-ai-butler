<?php
/**
 * Resolves the retention policy in force.
 *
 * @package Albert
 * @subpackage Undo\Retention
 * @since      1.5.0
 */

namespace Albert\Undo\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Where an add-on registers a {@see RetentionPolicy} in place of Free's.
 *
 * An action handing over this object rather than a filter over numbers:
 * `register()` accepts only a class implementing the interface, so changing the
 * limits means writing one, not publishing a `__return_` one-liner.
 *
 * ```php
 * add_action( 'albert/undo/register_retention_policy', function ( $registrar ) {
 *     $registrar->register( new My_Retention_Policy() );
 * } );
 * ```
 *
 * @since 1.5.0
 */
final class Registrar {

	/**
	 * The registered policy, if any.
	 *
	 * @var RetentionPolicy|null
	 */
	private ?RetentionPolicy $policy = null;

	/**
	 * Register the policy to use. The last registration wins.
	 *
	 * @param RetentionPolicy $policy The policy.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register( RetentionPolicy $policy ): void {
		$this->policy = $policy;
	}

	/**
	 * The policy in force: a registered one, or Free's.
	 *
	 * @return RetentionPolicy
	 * @since 1.5.0
	 */
	public static function current(): RetentionPolicy {
		$registrar = new self();

		/**
		 * Fires when the change journal resolves its retention policy.
		 *
		 * Call `$registrar->register()` with a {@see RetentionPolicy} to replace
		 * Free's fixed limits.
		 *
		 * @since 1.5.0
		 *
		 * @param Registrar $registrar The registrar.
		 */
		do_action( 'albert/undo/register_retention_policy', $registrar );

		return $registrar->policy ?? new FreeRetentionPolicy();
	}
}
