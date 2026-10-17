<?php
/**
 * Integration tests for the setup-link email.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\ConnectPage;
use Albert\Admin\Connections\SetupInvite;
use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;

/**
 * SetupInvite tests.
 *
 * @covers \Albert\Admin\Connections\SetupInvite
 */
class SetupInviteTest extends TestCase {

	/**
	 * The administrator sending it.
	 *
	 * @var \WP_User
	 */
	private \WP_User $admin;

	/**
	 * A clean list and mailer.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( AllowedUsers::OPTION );
		reset_phpmailer_instance();

		$this->admin = self::factory()->user->create_and_get(
			[
				'role'         => 'administrator',
				'display_name' => 'Mark Jansen',
			]
		);
	}

	/**
	 * An editor on the list gets an email with their setup link and who sent it.
	 *
	 * @return void
	 */
	public function test_an_allowed_editor_gets_the_link(): void {
		$editor = self::factory()->user->create_and_get(
			[
				'role'       => 'editor',
				'user_email' => 'jan@example.test',
			]
		);
		AllowedUsers::add( $editor->ID );

		$this->assertTrue( SetupInvite::send( $editor, $this->admin ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'jan@example.test', $mail->to[0][0] );
		$this->assertStringContainsString( esc_url( ConnectPage::setup_url( $editor ) ), $mail->body );
		$this->assertStringContainsString( 'Mark Jansen', $mail->body );
	}

	/**
	 * Nobody off the list is emailed.
	 *
	 * @return void
	 */
	public function test_nobody_off_the_list_is_emailed(): void {
		$stranger = self::factory()->user->create_and_get( [ 'role' => 'editor' ] );

		$this->assertFalse( SetupInvite::send( $stranger, $this->admin ) );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * An administrator's link goes to Connections, where they connect.
	 *
	 * @return void
	 */
	public function test_an_administrators_link_goes_to_connections(): void {
		$other = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
		AllowedUsers::add( $other->ID );

		$this->assertTrue( SetupInvite::send( $other, $this->admin ) );
		$this->assertStringContainsString( 'page=albert-connections', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}
}
