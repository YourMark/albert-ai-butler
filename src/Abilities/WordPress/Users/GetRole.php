<?php
/**
 * Get Role Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Users
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Users;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_Role;

defined( 'ABSPATH' ) || exit;

/**
 * Returns one role with every capability it grants.
 *
 * @since 1.6.0
 */
class GetRole extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/get-role';
		$this->label       = __( 'Get Role', 'albert-ai-butler' );
		$this->description = __( 'Show one user role with every capability it grants; use albert/find-roles first to see which roles the site has.', 'albert-ai-butler' );
		$this->category    = 'user';
		$this->group       = 'users';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Giving someone a role with assigning_is_held true is held for the owner\'s approval.'
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
		// `role_slug`, not `role`: RiskPolicy holds any call whose `role` names an administrator role.
		return [
			'type'       => 'object',
			'properties' => [
				'role_slug' => [
					'type'        => 'string',
					'description' => 'The role slug, such as "editor", from albert/find-roles.',
					'minLength'   => 1,
				],
			],
			'required'   => [ 'role_slug' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		$role = FindRoles::role_schema();

		$role['properties']['capabilities'] = [
			'type'        => 'array',
			'description' => 'Every capability the role grants, sorted.',
			'items'       => [ 'type' => 'string' ],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'role' => $role,
			],
			'required'   => [ 'role' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * `list_users`, as for albert/find-roles.
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
		$slug = is_string( $args['role_slug'] ?? null ) ? $args['role_slug'] : '';
		$role = $slug !== '' ? wp_roles()->get_role( $slug ) : null;

		if ( ! $role instanceof WP_Role ) {
			return new WP_Error(
				'albert_role_not_found',
				sprintf(
					/* translators: 1: role slug, 2: comma-separated role slugs */
					__( 'There is no "%1$s" role. Roles on this site: %2$s. Use albert/find-roles to compare them.', 'albert-ai-butler' ),
					$slug,
					implode( ', ', array_keys( wp_roles()->roles ) )
				),
				[ 'status' => 404 ]
			);
		}

		return [
			'role' => FindRoles::describe( $slug, $role, count_users(), FindRoles::gate_active() ) + [
				'capabilities' => FindRoles::capabilities( $role ),
			],
		];
	}
}
