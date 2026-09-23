<?php
/**
 * Ability-execution interceptor decision.
 *
 * @package Albert
 * @subpackage Execution
 * @since      1.5.0
 */

namespace Albert\Execution;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the MCP double-fire for any interceptor bound to `wp_pre_execute_ability`.
 *
 * A single MCP `tools/call` runs {@see \WP_Ability::execute()} twice. The MCP
 * adapter models "run an ability" as an ability of its own,
 * `mcp-adapter/execute-ability`: the transport executes *that* wrapper, and the
 * wrapper's callback then executes the real target
 * ({@see \WP\MCP\Abilities\ExecuteAbilityAbility::execute()} calls
 * `$ability->execute( $parameters )`). Both invocations fire
 * `wp_pre_execute_ability` — once for the wrapper, once for the real ability.
 *
 * Every consumer that wants to act once per real invocation therefore has to
 * ignore the wrapper firing. Safe mode (issue 04) gates the destructive real
 * ability, not the transport; the rate limiter (issue 05) counts one call, not
 * two. Both ask the same question, so it lives here once rather than being
 * re-derived — and re-derived subtly differently — at each call site.
 *
 * **Act on the real firing, not the wrapper.** The wrapper's `$input` is the
 * raw `{ ability_name, parameters }` envelope the model sent, still unvalidated
 * and still owned by the model. The real ability's firing carries the resolved
 * `parameters` under that ability's own name. An interceptor that staged or
 * counted the wrapper would be working from model-replayable input and the
 * wrong identity; acting on the real firing gives it the resolved input for
 * free, which is exactly what issue 04 must persist server-side.
 *
 * Stateless and side-effect free: it classifies a name, nothing more. It
 * registers no hook and changes no behaviour on its own — the interceptor
 * pipeline (issue 04) consults it. Below WordPress 7.1 `wp_pre_execute_ability`
 * never fires, so a consumer built on this is inert rather than broken; see
 * {@see \Albert\Support\WpCompat::supports_execution_lifecycle()}.
 *
 * @since 1.5.0
 */
class InterceptorDecision {

	/**
	 * The MCP adapter tool that re-enters `execute()` for the real ability.
	 *
	 * Registered by the adapter and shared across every MCP server on the site,
	 * Albert's included ({@see \Albert\MCP\Server::CORE_TOOL_ABILITIES}). This is
	 * the one ability whose own execution nests a second, so it is the only
	 * firing an interceptor skips.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const MCP_EXECUTE_WRAPPER = 'mcp-adapter/execute-ability';

	/**
	 * Whether a `wp_pre_execute_ability` firing is the MCP transport wrapper.
	 *
	 * True only for {@see self::MCP_EXECUTE_WRAPPER}. The wrapper is plumbing:
	 * its execution exists solely to invoke the real target, which fires this
	 * hook again a moment later with resolved input under its own name.
	 *
	 * @since 1.5.0
	 *
	 * @param string $ability_name The name passed to `wp_pre_execute_ability`.
	 *
	 * @return bool True when this firing is the transport wrapper.
	 */
	public function is_transport_wrapper( string $ability_name ): bool {
		return self::MCP_EXECUTE_WRAPPER === $ability_name;
	}

	/**
	 * Whether an interceptor should act on this `wp_pre_execute_ability` firing.
	 *
	 * True for a real ability invocation — the one an interceptor should gate,
	 * count, or stage — and false for the MCP transport wrapper, which is skipped
	 * so the interceptor acts exactly once, on the resolved input of the real
	 * call. A direct (non-MCP) invocation fires the hook once and is never the
	 * wrapper, so it is acted on once as well.
	 *
	 * @since 1.5.0
	 *
	 * @param string $ability_name The name passed to `wp_pre_execute_ability`.
	 *
	 * @return bool True to act on this firing; false to let it pass untouched.
	 */
	public function should_intercept( string $ability_name ): bool {
		return ! $this->is_transport_wrapper( $ability_name );
	}
}
