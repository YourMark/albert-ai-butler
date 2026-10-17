<?php
/**
 * Integration tests for the handlers behind the Connect page and setup links.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\ConnectPage;
use Albert\Admin\Connections;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;
use WPDieException;

/**
 * Who may revoke, renew and email, and what each touches.
 *
 * @covers \Albert\Admin\ConnectPage::handle_revoke
 * @covers \Albert\Admin\Connections::handle_renew_setup_link
 * @covers \Albert\Admin\Connections::handle_email_setup_link
 */
class ConnectHandlersTest extends TestCase {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * An editor on the allowed list, invitation expired.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Fresh tables, an expired invitation for the editor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		foreach ( [ 'clients', 'access_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()[ $key ] ) );
		}

		$this->admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->editor = self::factory()->user->create( [ 'role' => 'editor' ] );

		update_option(
			AllowedUsers::OPTION,
			[
				$this->editor => [
					'added_at'      => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ),
					'authorised_at' => null,
					'expires_at'    => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				],
			]
		);

		add_filter( 'wp_doing_ajax', '__return_true' );
		// wp_send_json() ends in wp_die(); under ajax that handler would exit.
		add_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );
	}

	/**
	 * The handler wp_die() uses here: throw instead of exiting.
	 *
	 * @return callable
	 */
	public function ajax_die_handler(): callable {
		return static function ( $message ): void {
			throw new WPAjaxDieContinueException( is_string( $message ) ? $message : '' );
		};
	}

	/**
	 * Clear the request.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST    = [];
		$_REQUEST = [];
		unset( $_SERVER['HTTP_X_REQUESTED_WITH'] );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', [ $this, 'ajax_die_handler' ] );

		parent::tear_down();
	}

	/**
	 * Post to a handler and return its JSON, or the die message.
	 *
	 * @param callable             $handler The handler.
	 * @param array<string, mixed> $post    The posted fields.
	 *
	 * @return array<string, mixed>
	 */
	private function call( callable $handler, array $post ): array {
		$_POST    = $post;
		$_REQUEST = $post;

		ob_start();
		try {
			$handler();
		} catch ( WPAjaxDieContinueException | WPAjaxDieStopException | WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// wp_send_json() ends the request this way under tests.
		}

		return (array) json_decode( (string) ob_get_clean(), true );
	}

	/**
	 * A live access token for a client and user.
	 *
	 * @param string $client_id The client.
	 * @param int    $user_id   The user.
	 *
	 * @return void
	 */
	private function token( string $client_id, int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['access_tokens'],
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scopes'     => '[]',
				'revoked'    => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);
	}

	/**
	 * Renewing needs the nonce and an administrator, then restarts the window.
	 *
	 * @return void
	 */
	public function test_renew_needs_nonce_and_administrator(): void {
		$handler = [ new Connections(), 'handle_renew_setup_link' ];
		$fields  = [ 'user_id' => $this->editor ];

		wp_set_current_user( $this->admin );
		$this->assertFalse( $this->call( $handler, $fields + [ '_wpnonce' => 'nope' ] )['success'] );
		$this->assertTrue( AllowedUsers::has_expired_invitation( $this->editor ) );

		wp_set_current_user( $this->editor );
		$nonce = wp_create_nonce( Connections::RENEW_SETUP_ACTION . '_' . $this->editor );
		$this->assertFalse( $this->call( $handler, $fields + [ '_wpnonce' => $nonce ] )['success'] );
		$this->assertTrue( AllowedUsers::has_expired_invitation( $this->editor ) );

		wp_set_current_user( $this->admin );
		$nonce  = wp_create_nonce( Connections::RENEW_SETUP_ACTION . '_' . $this->editor );
		$result = $this->call( $handler, $fields + [ '_wpnonce' => $nonce ] );
		$this->assertTrue( $result['success'] );
		$this->assertNotSame( '', $result['data']['expiry'] );
		$this->assertTrue( AllowedUsers::is_allowed( $this->editor ) );
	}

	/**
	 * Emailing someone not on the list sends nothing and renews nothing.
	 *
	 * @return void
	 */
	public function test_email_is_only_for_people_on_the_list(): void {
		$stranger = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $this->admin );
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

		$result = $this->call(
			[ new Connections(), 'handle_email_setup_link' ],
			[
				'user_id'  => $stranger,
				'_wpnonce' => wp_create_nonce( Connections::EMAIL_SETUP_ACTION . '_' . $stranger ),
			]
		);

		$this->assertFalse( $result['success'] );
		$this->assertFalse( AllowedUsers::is_allowed( $stranger ) );
	}

	/**
	 * Revoke drops only the caller's own tokens, and needs its nonce.
	 *
	 * @return void
	 */
	public function test_revoke_drops_only_your_own_tokens(): void {
		global $wpdb;

		AllowedUsers::renew_invitation( $this->editor );
		$client = ( new ClientRepository() )->createClient( 'Claude', 'https://example.test/cb' )['client_id'];
		$this->token( $client, $this->editor );
		$this->token( $client, $this->admin );
		wp_set_current_user( $this->editor );

		add_filter(
			'wp_redirect',
			static function (): void {
				throw new WPDieException( 'redirected' );
			}
		);

		$_POST    = [
			'client_id' => $client,
			'_wpnonce'  => 'nope',
		];
		$_REQUEST = $_POST;
		try {
			( new ConnectPage() )->handle_revoke();
			$this->fail( 'A bad nonce must stop the revoke.' );
		} catch ( WPDieException $e ) {
			$this->assertNotSame( 'redirected', $e->getMessage() );
		}

		$_POST['_wpnonce']    = wp_create_nonce( ConnectPage::REVOKE_ACTION . '_' . $client );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		try {
			( new ConnectPage() )->handle_revoke();
		} catch ( WPDieException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$live = static fn( int $user ): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0', Tables::oauth()['access_tokens'], $user ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.

		$this->assertSame( 0, $live( $this->editor ) );
		$this->assertSame( 1, $live( $this->admin ) );
	}
}
