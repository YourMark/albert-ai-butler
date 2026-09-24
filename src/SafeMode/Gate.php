<?php
/**
 * Safe-mode gate rule.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use Albert\Settings\Value;
use WP_Ability;

/**
 * Decides whether a given ability call must be held for approval.
 *
 * Two questions, both of which must be yes: is safe mode on, and is this call
 * one safe mode gates? The second is answered on two axes — the annotation
 * ({@see self::is_gated_ability()}) and the risk of the call itself
 * ({@see RiskPolicy}) — because the annotation alone lets privilege changes and
 * foundational-option writes through under a `destructive: false` preset.
 *
 * @since 1.5.0
 */
class Gate {

	/**
	 * The setting that turns safe mode on or off.
	 *
	 * Read through {@see Value} so a `wp-config.php` constant
	 * (`ALBERT_SAFE_MODE`) or the `albert/settings/value/albert_safe_mode`
	 * filter can pin it across a fleet, exactly like the privacy mode.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const OPTION = 'albert_safe_mode';

	/**
	 * On unless a site says otherwise.
	 *
	 * Gating destructive calls is the point of the feature, and a safety default
	 * that ships off protects nobody who never finds the setting.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const DEFAULT_VALUE = 'on';

	/**
	 * Classifies a call's risk beyond its annotation.
	 *
	 * @since 1.5.0
	 * @var RiskPolicy
	 */
	private RiskPolicy $risk;

	/**
	 * Wire the gate to its risk policy.
	 *
	 * @param RiskPolicy|null $risk Risk classifier; defaults to a fresh one.
	 */
	public function __construct( ?RiskPolicy $risk = null ) {
		$this->risk = $risk ?? new RiskPolicy();
	}

	/**
	 * Whether safe mode is switched on.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function is_enabled(): bool {
		// Asks "did somebody turn this off", never "is it exactly the string
		// on". A boolean true from a constant is not the string `on`, and a
		// straight equality check read it as off, which is the wrong direction
		// to be wrong in for a safety switch.
		return ! self::means_off( Value::get( self::OPTION, self::DEFAULT_VALUE ) );
	}

	/**
	 * Coerce a stored value to `on` or `off`.
	 *
	 * The setting's `sanitize_callback`, so an unrecognised value is stored as the
	 * secure default rather than as itself. A malformed value would otherwise read
	 * as "not on" and silently disable the gate.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return string `on` or `off`.
	 * @since 1.5.0
	 */
	public static function sanitize( $value ): string {
		return self::means_off( $value ) ? 'off' : self::DEFAULT_VALUE;
	}

	/**
	 * Whether a value asks for safe mode to be off.
	 *
	 * Booleans count, because `define( 'ALBERT_SAFE_MODE', false )` is the
	 * obvious spelling. Only an explicit off wins: anything unrecognised means
	 * on, so a typo fails towards the gate.
	 *
	 * @param mixed $value The submitted or configured value.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function means_off( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value === false;
		}

		if ( is_int( $value ) ) {
			return $value === 0;
		}

		return is_string( $value ) && in_array( strtolower( trim( $value ) ), [ 'off', 'false', 'no', '0' ], true );
	}

	/**
	 * Whether a value is a recognised setting for this option.
	 *
	 * The override validator, so a constant or filter Albert cannot read is
	 * skipped rather than pinning the site to something it did not mean. Shares
	 * {@see self::means_off()} with the sanitiser so the two cannot drift.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_valid( $value ): bool {
		if ( is_bool( $value ) || is_int( $value ) ) {
			return true;
		}

		return is_string( $value )
			&& in_array( strtolower( trim( $value ) ), [ 'on', 'off', 'true', 'false', 'yes', 'no', '1', '0' ], true );
	}

	/**
	 * Whether this ability call must be staged for approval instead of run.
	 *
	 * Held when safe mode is on and the call is either high-risk by its input
	 * ({@see RiskPolicy}) or destructive/unannotated by its annotation. The risk
	 * axis is what catches a privilege change or an option write that the
	 * annotation preset marks `destructive: false`.
	 *
	 * @param WP_Ability           $ability The ability about to execute.
	 * @param array<string, mixed> $input   Its resolved input.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function must_gate( WP_Ability $ability, array $input = [] ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( $this->is_exempt( $ability->get_name() ) ) {
			return false;
		}

		return $this->risk->must_hold( $ability, $input ) || $this->is_gated_ability( $ability );
	}

	/**
	 * Whether an owner has excused this ability from the gate in code.
	 *
	 * The relief valve: without one, an owner with a noisy ability they trust
	 * switches safe mode off entirely.
	 *
	 * Excuses *staging* and nothing else. Permission checks still run, and
	 * {@see ConnectionGuard} still refuses writes to Albert's own control
	 * options whatever is listed, so this cannot open the gate from inside.
	 *
	 * @param string $ability_name The ability id.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function is_exempt( string $ability_name ): bool {
		/**
		 * Filters the abilities safe mode never holds.
		 *
		 * Wins over `albert/safe_mode/high_risk_abilities`: naming an ability
		 * here is a statement about this site, where the risk list is a general
		 * default.
		 *
		 * @since 1.5.0
		 *
		 * @param list<string> $abilities Ability ids to let through ungated.
		 */
		$exempt = apply_filters( 'albert/safe_mode/exempt_abilities', [] );

		return is_array( $exempt ) && in_array( $ability_name, $exempt, true );
	}

	/**
	 * Whether an ability is destructive, or does not say.
	 *
	 * The rule is deliberately `destructive !== false`, never `=== true`: an
	 * ability that has not declared itself is gated, not waved through. A
	 * third-party ability with no annotations is an unknown, and an unknown
	 * that might delete data is exactly what a person should get to see first.
	 * Only an explicit `destructive: false` — which every read, create and
	 * update ability sets — is trusted to run unattended.
	 *
	 * **Do not weaken this to `=== true`.** That inverts the safety property
	 * from fail-safe to fail-open: every unannotated ability would then run
	 * without approval, which is the one outcome this gate exists to prevent.
	 *
	 * `readonly: true` is **not** an escape either, and that is deliberate. It is
	 * tempting, since a read cannot be destructive and some plugins annotate only
	 * that key, but it makes a single self-reported flag sufficient to skip the
	 * gate. `destructive: false` is the one claim this trusts, and an ability
	 * that means "read" can make it.
	 *
	 * The MCP transport wrapper (`mcp-adapter/execute-ability`) is itself
	 * annotated `destructive: true`, so it would gate here — but it never
	 * reaches this method, because {@see \Albert\Execution\InterceptorDecision}
	 * skips that firing and lets the real ability it wraps arrive instead.
	 *
	 * @param WP_Ability $ability The ability to classify.
	 *
	 * @return bool True when destructive or unannotated.
	 * @since 1.5.0
	 */
	public function is_gated_ability( WP_Ability $ability ): bool {
		$annotations = $ability->get_meta_item( 'annotations', [] );
		$destructive = is_array( $annotations ) ? ( $annotations['destructive'] ?? null ) : null;

		return $destructive !== false;
	}
}
