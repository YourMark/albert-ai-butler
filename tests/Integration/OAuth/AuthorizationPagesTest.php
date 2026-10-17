<?php
/**
 * Integration tests for the refusal and approval pages.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Endpoints\AuthorizationPage;
use Albert\Tests\TestCase;

/**
 * AuthorizationPage refusal and approval markup.
 *
 * @covers \Albert\OAuth\Endpoints\AuthorizationPage
 */
class AuthorizationPagesTest extends TestCase {

	/**
	 * A client registered an hour ago.
	 *
	 * @param string $created How long ago, as a DateTimeImmutable modifier.
	 *
	 * @return ClientEntity
	 */
	private function client( string $created = '-1 hour' ): ClientEntity {
		$client = new ClientEntity();
		$client->setIdentifier( 'client-1' );
		$client->setName( 'Cursor' );
		$client->setRedirectUri( 'https://app.example.com/callback' );
		$client->setCreatedAt( new \DateTimeImmutable( $created ) );

		return $client;
	}

	/**
	 * A state holding `&` and `#` stays one encoded value.
	 *
	 * @return void
	 */
	public function test_error_redirect_encodes_the_state(): void {
		$url = AuthorizationPage::error_redirect_url( 'https://app.example.com/cb?x=1', 'access_denied', 'No.', 'a&code=evil#x' );

		$this->assertSame( 'https://app.example.com/cb?x=1&error=access_denied&error_description=No.&state=a%26code%3Devil%23x', $url );
	}

	/**
	 * A state of "0" is sent back; an empty one is left out.
	 *
	 * @return void
	 */
	public function test_error_redirect_keeps_a_zero_state(): void {
		$this->assertStringEndsWith( '&state=0', AuthorizationPage::error_redirect_url( 'https://a.test/cb', 'access_denied', 'No.', '0' ) );
		$this->assertStringNotContainsString( 'state=', AuthorizationPage::error_redirect_url( 'https://a.test/cb', 'access_denied', 'No.', '' ) );
	}

	/**
	 * A non-administrator is told who can fix it and where the way back goes.
	 *
	 * @return void
	 */
	public function test_refusal_for_a_non_administrator(): void {
		$editor = self::factory()->user->create_and_get( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor->ID );

		$html = ( new AuthorizationPage() )->access_denied_html( $editor, $this->client(), 'https://app.example.com/callback', 's&t' );

		$this->assertStringContainsString( 'Ask an administrator to add you', $html );
		$this->assertStringNotContainsString( 'You can add yourself', $html );
		$this->assertStringContainsString( 'This link goes to app.example.com.', $html );
		$this->assertStringContainsString( 'state=s%26t', $html );
		$this->assertStringNotContainsString( 'registered', $html );
	}

	/**
	 * An administrator gets the way to add themselves; a new app is flagged.
	 *
	 * @return void
	 */
	public function test_refusal_for_an_administrator_flags_a_new_app(): void {
		$admin = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin->ID );

		$html = ( new AuthorizationPage() )->access_denied_html( $admin, $this->client( '-2 minutes' ), 'https://app.example.com/callback', '' );

		$this->assertStringContainsString( 'You can add yourself', $html );
		$this->assertStringContainsString( 'page=albert-connections', $html );
		$this->assertStringContainsString( 'registered 2 minutes ago', $html );
	}

	/**
	 * The approval page says approved, not connected, and links back.
	 *
	 * @return void
	 */
	public function test_success_page(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$html = ( new AuthorizationPage() )->success_html( 'https://app.example.com/callback?code=abc&state=xyz', 'Cursor' );

		$this->assertStringContainsString( 'Approved', $html );
		$this->assertStringContainsString( 'Taking you back to Cursor', $html );
		$this->assertStringContainsString( 'href="https://app.example.com/callback?code=abc&#038;state=xyz"', $html );
		$this->assertStringNotContainsString( 'revoke this connection', $html );
	}

	/**
	 * An app's own scheme survives on the way back; a script scheme never does.
	 *
	 * @return void
	 */
	public function test_links_back_keep_an_app_scheme_but_never_a_script_scheme(): void {
		$page = new AuthorizationPage();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertStringContainsString( 'href="cursor://anysphere.cursor-mcp/oauth/callback?code=abc"', $page->success_html( 'cursor://anysphere.cursor-mcp/oauth/callback?code=abc', 'Cursor' ) );

		foreach ( [ 'javascript:alert(1)', "java\tscript:alert(1)", ' JavaScript:alert(1)' ] as $url ) {
			$this->assertStringNotContainsString( 'alert(1)', $page->success_html( $url, 'Evil' ), $url );
			$this->assertStringNotContainsString( 'location', AuthorizationPage::redirect_script( $url ), $url );
		}
	}

	/**
	 * The redirect script hands over after a delay that any interaction cancels.
	 *
	 * @return void
	 */
	public function test_redirect_script(): void {
		$script = AuthorizationPage::redirect_script( 'https://app.example.com/cb?code=a&state=</script>' );

		$this->assertStringContainsString( 'setTimeout', $script );
		$this->assertStringContainsString( '"keydown","pointerdown","focusin"', $script );
		$this->assertStringNotContainsString( '</script>', $script );
		$this->assertStringContainsString( 'BroadcastChannel', $script );
	}
}
