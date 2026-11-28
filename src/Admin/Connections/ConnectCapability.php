<?php
/**
 * Who may see how to connect an assistant.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\AllowedUsers;

/**
 * Grants `albert_connect` to administrators and to everyone on the allowed list.
 *
 * Whatever their role: an agency's client is often an editor, and being on the
 * list is what says they may connect, so it is also what lets them see how. It
 * follows the list live, with no role to configure. Remove someone and the page
 * goes with their tokens; an expired invitation grants nothing.
 *
 * Only the connect page and its dialog are behind this. Logs, settings,
 * abilities and other people's connections stay `manage_options`.
 *
 * @since 1.5.0
 */
class ConnectCapability implements Hookable {

	/**
	 * The capability.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const CAPABILITY = 'albert_connect';

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_filter( 'user_has_cap', [ $this, 'grant' ], 10, 4 );
	}

	/**
	 * Grant the capability when it is asked about.
	 *
	 * Reads `manage_options` from the capabilities WordPress already resolved,
	 * not through current_user_can(), which would call this filter again.
	 *
	 * @param array<string, bool> $allcaps Every capability the user has.
	 * @param array<int, string>  $caps    The primitive capabilities being checked.
	 * @param array<int, mixed>   $args    The original capability check.
	 * @param \WP_User            $user    The user.
	 *
	 * @return array<string, bool>
	 * @since 1.5.0
	 */
	public function grant( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! in_array( self::CAPABILITY, $caps, true ) ) {
			return $allcaps;
		}

		$allcaps[ self::CAPABILITY ] = ! empty( $allcaps['manage_options'] ) || ( $user->ID > 0 && AllowedUsers::is_allowed( $user->ID ) );

		return $allcaps;
	}
}
