<?php
/**
 * Unit tests for AuthorizationPage's OAuth parameter source selection.
 *
 * Covers the 1.4.0 fix for the consent form silently depending on having no
 * `action` attribute (so a POST landed back on the same query string) to keep
 * client_id/redirect_uri/state/scope/code_challenge/code_challenge_method
 * readable from $_GET. read_oauth_param() makes that explicit instead: GET
 * on the initial request, POST on the consent decision.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\Endpoints\AuthorizationPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AuthorizationPage unit tests.
 *
 * @covers \Albert\OAuth\Endpoints\AuthorizationPage
 */
class AuthorizationPageTest extends TestCase {

	/**
	 * Reset superglobals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$_GET    = [];
		$_POST   = [];
		$_SERVER = [];
	}

	/**
	 * Clean up superglobals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET    = [];
		$_POST   = [];
		$_SERVER = [];
		parent::tearDown();
	}

	/**
	 * Invoke the private read_oauth_param().
	 *
	 * @param string $key           Parameter name.
	 * @param string $default_value Default value.
	 * @param bool   $verbatim      Whether to keep the value as sent.
	 *
	 * @return string
	 */
	private function read( string $key, string $default_value = '', bool $verbatim = false ): string {
		$method = new ReflectionMethod( AuthorizationPage::class, 'read_oauth_param' );
		$method->setAccessible( true );

		return $method->invoke( new AuthorizationPage(), $key, $default_value, $verbatim );
	}

	/**
	 * On a GET request, values come from $_GET even when $_POST also has a
	 * (stale, irrelevant) value under the same key.
	 *
	 * @return void
	 */
	public function test_reads_from_get_on_a_get_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['client_id']         = 'from_get';
		$_POST['client_id']        = 'from_post';

		$this->assertSame( 'from_get', $this->read( 'client_id' ) );
	}

	/**
	 * On a POST request (the consent decision), values come from $_POST — the
	 * consent form's hidden fields — not from $_GET. This is the actual fix:
	 * previously every read came from $_GET regardless of method, which only
	 * worked because the form has no `action` attribute.
	 *
	 * @return void
	 */
	public function test_reads_from_post_on_a_post_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['client_id']         = 'from_get';
		$_POST['client_id']        = 'from_post';

		$this->assertSame( 'from_post', $this->read( 'client_id' ) );
	}

	/**
	 * A missing key returns the given default rather than an empty string,
	 * regardless of method.
	 *
	 * @return void
	 */
	public function test_returns_default_when_key_is_absent(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertSame( 'default', $this->read( 'scope', 'default' ) );
	}

	/**
	 * An app's own callback scheme survives, so it can match what it registered.
	 *
	 * @return void
	 */
	public function test_verbatim_keeps_a_private_use_scheme(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['redirect_uri']      = ' cursor://anysphere.cursor-mcp/oauth/callback ';

		$this->assertSame( 'cursor://anysphere.cursor-mcp/oauth/callback', $this->read( 'redirect_uri', '', true ) );
	}

	/**
	 * Only the exact registered string matches.
	 *
	 * @return void
	 */
	public function test_redirect_uri_must_match_exactly(): void {
		$registered = [ 'cursor://anysphere.cursor-mcp/oauth/callback', 'https://app.example/cb' ];

		$this->assertTrue( AuthorizationPage::is_registered_redirect_uri( $registered, 'cursor://anysphere.cursor-mcp/oauth/callback' ) );
		$this->assertTrue( AuthorizationPage::is_registered_redirect_uri( 'https://app.example/cb', 'https://app.example/cb' ) );

		foreach ( [ 'CURSOR://anysphere.cursor-mcp/oauth/callback', 'https://app.example/cb/', 'https://app.example/cb?x=1', 'https://evil.example/cb', '' ] as $uri ) {
			$this->assertFalse( AuthorizationPage::is_registered_redirect_uri( $registered, $uri ), $uri );
		}
	}

	/**
	 * Script schemes are caught however a browser would still read them.
	 *
	 * @return void
	 */
	public function test_dangerous_schemes(): void {
		foreach ( [ 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", "\x01javascript:alert(1)", ' data:text/html,x', 'no-scheme' ] as $url ) {
			$this->assertTrue( AuthorizationPage::is_dangerous_scheme( $url ), $url );
		}

		foreach ( [ 'https://app.example/cb', 'cursor://anysphere.cursor-mcp/oauth/callback', 'com.example.app:/cb' ] as $url ) {
			$this->assertFalse( AuthorizationPage::is_dangerous_scheme( $url ), $url );
		}
	}

	/**
	 * Other parameters are still sanitised as text.
	 *
	 * @return void
	 */
	public function test_other_parameters_are_sanitised_as_text(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['scope']             = ' <b>default</b> ';

		$this->assertSame( 'default', $this->read( 'scope' ) );
	}

	/**
	 * An array where a string belongs falls back to the default.
	 *
	 * @return void
	 */
	public function test_a_non_string_value_reads_as_absent(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['redirect_uri']      = [ 'https://example.test/cb' ];

		$this->assertSame( '', $this->read( 'redirect_uri', '', true ) );
	}
}
