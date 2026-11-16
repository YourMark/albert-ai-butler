<?php
/**
 * Ability invocation relay.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.4.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use WP_Ability;

/**
 * Relays WordPress 7.1's `wp_ability_invoked` action onto Albert's own hook.
 *
 * WordPress 7.1 fires `wp_ability_invoked` at the very top of
 * {@see \WP_Ability::execute()} — before input normalisation, before validation,
 * before the permission check — for *every* call, whatever its outcome. That is
 * a strictly wider net than Albert's existing `albert/abilities/after_execute`,
 * which only fires for abilities that reach
 * {@see \Albert\Abstracts\BaseAbility::guarded_execute()}: it also sees denied
 * calls, calls rejected by schema validation, calls short-circuited by
 * `wp_pre_execute_ability`, and calls to abilities Albert does not own.
 *
 * This class is deliberately a relay and nothing more. It records nothing,
 * decides nothing and blocks nothing — it re-emits the core action under
 * Albert's `albert/{module}/{action}` convention so subscribers bind to a stable
 * Albert hook rather than to a core action whose availability depends on the
 * WordPress version. The consumer is Premium Service's activity log, from
 * Premium 1.2.0; there are no consumers in Free today, and that is
 * intentional. Doc 20 §5.
 *
 * @since 1.4.0
 */
class InvocationRelay {

	/**
	 * Bind the relay to the core action.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'wp_ability_invoked', [ $this, 'relay' ], 10, 3 );
	}

	/**
	 * Re-emit a core invocation as `albert/abilities/invoked`.
	 *
	 * Passes core's arguments through untouched, in core's order, so the Albert
	 * hook stays a faithful mirror: renaming or reshaping them here would mean
	 * subscribers have to learn two vocabularies for one event.
	 *
	 * @param string     $ability_name The ability being invoked.
	 * @param mixed      $input        Raw input, before normalisation. May be null.
	 * @param WP_Ability $ability      The ability instance.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function relay( string $ability_name, $input, WP_Ability $ability ): void {
		// Wrapped for the same reason BaseAbility::guarded_execute() wraps its own
		// observer hooks: an observer must never break the call it observes. This
		// one needs it most. It fires from the very top of WP_Ability::execute(),
		// outside any Albert try/catch, for every ability on the site including
		// ones Albert does not own — so a subscriber that throws (a logger whose
		// insert fails, a JSON encode that chokes on the raw pre-normalisation
		// input this hook uniquely exposes) would take the whole tool call down
		// with it. Enforced, not merely documented.
		try {
			/**
			 * Fires when any registered ability is invoked, before any processing.
			 *
			 * Mirrors WordPress 7.1's `wp_ability_invoked` and fires for every call
			 * regardless of outcome — including calls that go on to fail validation,
			 * be denied by a permission check, or be short-circuited. Covers every
			 * registered ability, not only Albert's own.
			 *
			 * Observers should not block: this runs on the hot path of every ability
			 * call. Use `albert/abilities/after_execute` when the result matters. A
			 * Throwable from an observer is caught and swallowed rather than allowed
			 * to break the call being observed.
			 *
			 * @since 1.4.0
			 *
			 * @param string     $ability_name The ability being invoked.
			 * @param mixed      $input        Raw input, before normalisation.
			 * @param WP_Ability $ability      The ability instance.
			 */
			do_action( 'albert/abilities/invoked', $ability_name, $input, $ability );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// An observer must never break the ability it observes.
		}
	}
}
