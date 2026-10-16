<?php
/**
 * REST routes behind the connect dialog.
 *
 * @package Albert
 * @subpackage Admin\Rest
 * @since      1.5.0
 */

namespace Albert\Admin\Rest;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Connections\ConnectCapability;
use Albert\Admin\Connections\SiteLocation;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\OAuth\Repositories\ClientRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Two routes: has my new connection arrived yet, and where is this site.
 *
 * Both act on the signed-in person only. Status reports their own
 * connections, never anyone else's. Saving the location writes the site's
 * answer for someone who may manage options, and only the person's own answer
 * for anyone else on the allowed list: a wrong site-wide answer would mislead
 * everybody else.
 *
 * @since 1.5.0
 */
class ConnectController implements Hookable {

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::rest_namespace(),
			'/connect/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'can_connect' ],
				'args'                => [
					'since' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					],
				],
			]
		);

		register_rest_route(
			Plugin::rest_namespace(),
			'/connect/location',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_location' ],
				'permission_callback' => [ $this, 'can_connect' ],
				'args'                => [
					'location' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ SiteLocation::PUBLIC, SiteLocation::LOCAL ],
					],
				],
			]
		);
	}

	/**
	 * Who may use the dialog.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function can_connect(): bool {
		return current_user_can( ConnectCapability::CAPABILITY );
	}

	/**
	 * Whether the current user approved a new connection since the dialog opened.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 *
	 * @return WP_REST_Response
	 * @since 1.5.0
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		$user       = wp_get_current_user();
		$connection = ( new ClientRepository() )->firstConnectionSince( $user->ID, (int) $request->get_param( 'since' ) );

		if ( $connection === null ) {
			return new WP_REST_Response( [ 'connected' => false ] );
		}

		return new WP_REST_Response(
			[
				'connected' => true,
				'client'    => $connection['client_id'],
				'name'      => $connection['label'] !== '' ? $connection['label'] : $connection['name'],
				'user'      => $user->display_name,
			]
		);
	}

	/**
	 * Save where this site runs.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 *
	 * @return WP_REST_Response
	 * @since 1.5.0
	 */
	public function save_location( WP_REST_Request $request ): WP_REST_Response {
		$location = (string) $request->get_param( 'location' );
		$user_id  = get_current_user_id();

		if ( current_user_can( 'manage_options' ) ) {
			SiteLocation::save( $location );
		} else {
			SiteLocation::save_for_user( $user_id, $location );
		}

		return new WP_REST_Response( [ 'location' => SiteLocation::current( $user_id ) ] );
	}
}
