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
		$this->description = __( 'List the site\'s user roles with a one-line summary of what each can do and how many users have it. Name one role to get its full list of capabilities.', 'albert-ai-butler' );
		$this->category    = 'user';
		$this->group       = 'users';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Check a role here before suggesting it for a user. Giving someone a role with assigning_is_held true is held for the owner\'s approval.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_input_schema(): array {
		// `slug`, not `role`: safe mode's RiskPolicy holds any call whose `role`
		// names an administrator role, which would hold this read.
		return [
			'type'       => 'object',
			'properties' => [
				'slug' => [
					'type'        => 'string',
					'description' => 'A role slug, such as "editor", to return that role only, with every capability it has.',
				],
			],
		];
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
					'items' => [
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
							'capabilities'      => [
								'type'        => 'array',
								'description' => 'Only when one role was asked for.',
								'items'       => [ 'type' => 'string' ],
							],
						],
					],
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
		$wp_roles = wp_roles();
		$only     = isset( $args['slug'] ) ? sanitize_key( (string) $args['slug'] ) : '';

		if ( $only !== '' && ! $wp_roles->is_role( $only ) ) {
			return new WP_Error(
				'role_not_found',
				sprintf(
					/* translators: 1: role slug, 2: comma-separated role slugs */
					__( 'There is no "%1$s" role. Roles on this site: %2$s.', 'albert-ai-butler' ),
					$only,
					implode( ', ', array_keys( $wp_roles->roles ) )
				),
				[ 'status' => 404 ]
			);
		}

		$counts = count_users();
		$roles  = [];
		// Below WordPress 7.1, or with safe mode off, nothing is held.
		$gate_active = WpCompat::supports_execution_lifecycle() && ( new Gate() )->is_enabled();

		foreach ( $wp_roles->role_objects as $slug => $role ) {
			if ( ! $role instanceof WP_Role || ( $only !== '' && $slug !== $only ) ) {
				continue;
			}

			$capabilities = array_keys( array_filter( $role->capabilities ) );
			sort( $capabilities );

			$item = [
				'slug'              => (string) $slug,
				'name'              => translate_user_role( (string) ( $wp_roles->role_names[ $slug ] ?? $slug ) ),
				'summary'           => self::summarise( $role ),
				'user_count'        => (int) ( $counts['avail_roles'][ $slug ] ?? 0 ),
				'capability_count'  => count( $capabilities ),
				'assigning_is_held' => $gate_active && $role->has_cap( 'manage_options' ),
			];

			if ( $only !== '' ) {
				$item['capabilities'] = array_map( 'strval', $capabilities );
			}

			$roles[] = $item;
		}

		return [
			'roles'        => $roles,
			'default_role' => (string) get_option( 'default_role', 'subscriber' ),
		];
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
			$things[] = __( 'edit and publish everyone\'s posts and pages', 'albert-ai-butler' );
		} elseif ( $can( 'edit_others_posts' ) ) {
			$things[] = __( 'edit and publish everyone\'s posts', 'albert-ai-butler' );
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
				_n( 'Has %d capability, not for content, users, settings, plugins or themes; ask for this role by name to see it.', 'Has %d capabilities, none for content, users, settings, plugins or themes; ask for this role by name to see them.', count( $other ), 'albert-ai-butler' ),
				count( $other )
			);
		}

		if ( $can( 'read' ) ) {
			return __( 'Can only read the site and edit their own profile.', 'albert-ai-butler' );
		}

		return __( 'Has no capabilities.', 'albert-ai-butler' );
	}
}
