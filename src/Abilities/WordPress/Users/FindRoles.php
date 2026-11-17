<?php
/**
 * Find Roles Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Users
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Users;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use Albert\SafeMode\Gate;
use Albert\Support\WpCompat;
use WP_Error;
use WP_Role;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the site's roles with a one-line summary of what each can do, so role changes are informed.
 *
 * @since 1.6.0
 */
class FindRoles extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-roles';
		$this->label       = __( 'Find Roles', 'albert-ai-butler' );
		$this->description = __( 'List the site\'s user roles with a one-line summary of what each can do; use it before suggesting a role for someone, then albert/get-role for one role\'s full capabilities.', 'albert-ai-butler' );
		$this->category    = 'user';
		$this->group       = 'users';

		// Takes no input; albert/get-role returns one role. The MCP adapter sends empty properties as {}.
		$this->input_schema  = [
			'type'       => 'object',
			'properties' => [],
		];
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Each role has its user_count and capability_count. Giving someone a role with assigning_is_held true is held for the owner\'s approval.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'roles'        => [
					'type'  => 'array',
					'items' => self::role_schema(),
				],
				'default_role' => [
					'type'        => 'string',
					'description' => 'The role new users get.',
				],
			],
			'required'   => [ 'roles' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * `list_users`, as for find-users: anyone who may list users already sees
	 * every role's name there, and on the Users screen with its user count.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'list_users' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$wp_roles    = wp_roles();
		$counts      = count_users();
		$gate_active = self::gate_active();
		$roles       = [];

		foreach ( $wp_roles->role_objects as $slug => $role ) {
			if ( $role instanceof WP_Role ) {
				$roles[] = self::describe( (string) $slug, $role, $counts, $gate_active );
			}
		}

		return [
			'roles'        => $roles,
			'default_role' => (string) get_option( 'default_role', 'subscriber' ),
		];
	}

	/**
	 * The output schema of one role, shared with albert/get-role.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function role_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'slug'              => [ 'type' => 'string' ],
				'name'              => [ 'type' => 'string' ],
				'summary'           => [ 'type' => 'string' ],
				'user_count'        => [ 'type' => 'integer' ],
				'capability_count'  => [ 'type' => 'integer' ],
				'assigning_is_held' => [
					'type'        => 'boolean',
					'description' => 'Giving this role to someone over Albert waits for approval: it can manage the site and safe mode is on.',
				],
			],
		];
	}

	/**
	 * One role as the role abilities return it.
	 *
	 * @param string               $slug        Role slug.
	 * @param WP_Role              $role        The role.
	 * @param array<string, mixed> $counts      count_users() result.
	 * @param bool                 $gate_active Whether safe mode would hold an admin role grant.
	 * @return array{slug: string, name: string, summary: string, user_count: int, capability_count: int, assigning_is_held: bool}
	 * @since 1.6.0
	 */
	public static function describe( string $slug, WP_Role $role, array $counts, bool $gate_active ): array {
		$names = wp_roles()->role_names;

		return [
			'slug'              => $slug,
			'name'              => translate_user_role( (string) ( $names[ $slug ] ?? $slug ) ),
			'summary'           => self::summarise( $role ),
			'user_count'        => (int) ( $counts['avail_roles'][ $slug ] ?? 0 ),
			'capability_count'  => count( self::capabilities( $role ) ),
			'assigning_is_held' => $gate_active && $role->has_cap( 'manage_options' ),
		];
	}

	/**
	 * The capabilities a role grants, sorted.
	 *
	 * @param WP_Role $role The role.
	 * @return array<int, string>
	 * @since 1.6.0
	 */
	public static function capabilities( WP_Role $role ): array {
		$capabilities = array_map( 'strval', array_keys( array_filter( $role->capabilities ) ) );
		sort( $capabilities );
		return $capabilities;
	}

	/**
	 * Whether safe mode would hold giving someone an administrator role.
	 *
	 * Below WordPress 7.1, or with safe mode off, nothing is held.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public static function gate_active(): bool {
		return WpCompat::supports_execution_lifecycle() && ( new Gate() )->is_enabled();
	}

	/**
	 * One line on what a role can do, from the capabilities that matter most.
	 *
	 * @param WP_Role $role The role.
	 * @return string
	 * @since 1.6.0
	 */
	public static function summarise( WP_Role $role ): string {
		$can    = static fn( string $cap ): bool => $role->has_cap( $cap );
		$things = [];

		if ( $can( 'manage_options' ) ) {
			$things[] = __( 'change site settings', 'albert-ai-butler' );
		}
		if ( $can( 'activate_plugins' ) || $can( 'install_plugins' ) ) {
			$things[] = __( 'manage plugins', 'albert-ai-butler' );
		}
		if ( $can( 'switch_themes' ) || $can( 'edit_theme_options' ) ) {
			$things[] = __( 'change the theme and menus', 'albert-ai-butler' );
		}
		if ( $can( 'promote_users' ) || $can( 'edit_users' ) || $can( 'create_users' ) ) {
			$things[] = __( 'manage users and their roles', 'albert-ai-butler' );
		}

		if ( $can( 'edit_others_posts' ) && $can( 'edit_others_pages' ) ) {
			$things[] = __( 'edit everyone\'s posts and pages', 'albert-ai-butler' );
		} elseif ( $can( 'edit_others_posts' ) ) {
			$things[] = __( 'edit everyone\'s posts', 'albert-ai-butler' );
		} elseif ( $can( 'publish_posts' ) ) {
			$things[] = __( 'write and publish their own posts', 'albert-ai-butler' );
		} elseif ( $can( 'edit_posts' ) ) {
			$things[] = __( 'write posts, but not publish them', 'albert-ai-butler' );
		}

		if ( $can( 'upload_files' ) ) {
			$things[] = __( 'upload media', 'albert-ai-butler' );
		}
		if ( $can( 'moderate_comments' ) ) {
			$things[] = __( 'moderate comments', 'albert-ai-butler' );
		}

		if ( $things ) {
			/* translators: %l: list of things the role can do, e.g. "upload media and moderate comments" */
			return wp_sprintf( __( 'Can %l.', 'albert-ai-butler' ), $things );
		}

		// Plugin roles (a shop manager, say) often hold none of the caps above.
		$other = array_filter(
			array_keys( array_filter( $role->capabilities ) ),
			static fn( $cap ): bool => $cap !== 'read' && ! str_starts_with( (string) $cap, 'level_' )
		);
		if ( $other ) {
			return sprintf(
				/* translators: %d: number of capabilities */
				_n( 'Has %d capability, not for content, users, settings, plugins or themes; use albert/get-role to see it.', 'Has %d capabilities, none for content, users, settings, plugins or themes; use albert/get-role to see them.', count( $other ), 'albert-ai-butler' ),
				count( $other )
			);
		}

		if ( $can( 'read' ) ) {
			return __( 'Can only read the site and edit their own profile.', 'albert-ai-butler' );
		}

		return __( 'Has no capabilities.', 'albert-ai-butler' );
	}
}
