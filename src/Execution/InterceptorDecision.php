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
 * Tells an interceptor which `wp_pre_execute_ability` firing is the real one.
 *
 * One MCP `tools/call` fires that hook twice: the adapter models "run an
 * ability" as an ability of its own, so the transport executes
 * `mcp-adapter/execute-ability`, whose callback then executes the real target.
 * Act on the wrapper and you get the model's raw `{ability_name, parameters}`
 * envelope under the wrong identity; act on the inner firing and you get the
 * real ability's own input and name.
 *
 * Shared rather than inlined because safe mode and the rate limiter must answer
 * this identically, and "subtly differently" is the failure mode.
 *
 * @since 1.5.0
 */
class InterceptorDecision {

	/**
	 * The MCP adapter tool whose own execution nests a second one.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const MCP_EXECUTE_WRAPPER = 'mcp-adapter/execute-ability';

	/**
	 * Whether an interceptor should act on this firing.
	 *
	 * False only for the transport wrapper. A direct, non-MCP invocation fires
	 * once and is always acted on.
	 *
	 * @since 1.5.0
	 *
	 * @param string $ability_name The name passed to `wp_pre_execute_ability`.
	 *
	 * @return bool
	 */
	public function should_intercept( string $ability_name ): bool {
		return self::MCP_EXECUTE_WRAPPER !== $ability_name;
	}
}
