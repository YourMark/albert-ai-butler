<?php
/**
 * WordPress core abilities that overlap Albert's
 *
 * @package Albert
 * @subpackage Core
 * @since      1.7.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * Gives an assistant one tool per job where WordPress 7.2's abilities overlap Albert's.
 *
 * Per pair (comparison in the 1.7.0 plan, `61-p2-overlap.md`):
 * - Where core's does everything Albert's does, core's replaces it: Albert's is
 *   not registered and core's inherits whether it was on for connections.
 * - Where Albert's does more, both stay. Core's is off for connections by
 *   default, and its description says which of Albert's to use.
 *
 * Decided by whether core's ability is registered, never by version: core
 * registers `core/content-query` only when a post type opts in, and a host can
 * unregister any of them.
 *
 * @since 1.7.0
 */
class CoreAbilityOverlap implements Hookable {

	/**
	 * Albert's abilities that a core ability replaces, when core has it.
	 *
	 * @since 1.7.0
	 * @var array<string, string>
	 */
	public const SUPERSEDED = [
		'albert/view-user' => 'core/users-query',
	];

	/**
	 * Core abilities off for connections unless the owner switches them on.
	 *
	 * Albert's posts and pages abilities search, sort, read terms and blocks,
	 * which core's cannot; two tools for one job make an assistant guess.
	 *
	 * @since 1.7.0
	 * @var list<string>
	 */
	public const OFF_BY_DEFAULT = [ 'core/content-query' ];

	/**
	 * Register the description lines.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_filter( 'wp_register_ability_args', [ $this, 'steer' ], 30, 2 );
	}

	/**
	 * Whether Albert's ability gives way to core's on this site.
	 *
	 * Never on a network: there core's user query withholds email, login and
	 * roles from a site administrator who is not a super admin, which Albert's
	 * returns.
	 *
	 * @param string $albert_id Albert's ability id.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public static function is_superseded( string $albert_id ): bool {
		return self::replacement( $albert_id ) !== null;
	}

	/**
	 * The core ability that replaces Albert's on this site, if any.
	 *
	 * @param string $albert_id Albert's ability id.
	 *
	 * @return string|null
	 * @since 1.7.0
	 */
	public static function replacement( string $albert_id ): ?string {
		$core_id = self::SUPERSEDED[ $albert_id ] ?? null;

		if ( $core_id === null || is_multisite() || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $core_id ) ) {
			return null;
		}

		return $core_id;
	}

	/**
	 * Tell the assistant, and the owner on the Abilities screen, which tool to use.
	 *
	 * @param array<string, mixed> $args The registration arguments.
	 * @param string               $name The ability being registered.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	public function steer( array $args, string $name ): array {
		$line = match ( $name ) {
			'core/users-query'   => 'To search users by name or email, use albert/find-users. Ask for the fields you need: by default only the public profile is returned.',
			'core/content-query' => 'Albert\'s albert/find-posts, albert/view-post, albert/find-pages and albert/view-page do this too, and also search, sort and read blocks and terms. Prefer them.',
			default              => '',
		};

		if ( $line !== '' && is_string( $args['description'] ?? null ) ) {
			$args['description'] = rtrim( $args['description'] ) . ' ' . $line;
		}

		return $args;
	}
}
