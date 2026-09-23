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
		return self::DEFAULT_VALUE === Value::get( self::OPTION, self::DEFAULT_VALUE );
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

		return $this->risk->must_hold( $ability, $input ) || $this->is_gated_ability( $ability );
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
