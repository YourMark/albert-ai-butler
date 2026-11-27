<?php
/**
 * Unit tests for OAuthController's discovery metadata and failure logging.
 *
 * Covers two of the 1.4.0 interop-hardening fixes: the token endpoint's
 * previously-silent failure reason, and the metadata/registration mismatch
 * where every loopback client was issued 'none' as its auth method while the
 * metadata declared only client_secret_post/basic.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\Endpoints\OAuthController;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * OAuthController unit tests.
 *
 * @covers \Albert\OAuth\Endpoints\OAuthController
 */
class OAuthControllerTest extends TestCase {

	/**
	 * Reset shared stub state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks'] = [];
	}

	// ─── Server metadata ────────────────────────────────────────────

	/**
	 * 'none' must be declared: every loopback/native client (RFC 8252 — every
	 * local MCP bridge) is registered as a public client using this auth
	 * method (see ClientRegistration::register()), so metadata omitting it
	 * contradicted what registration actually issued.
	 *
	 * @return void
	 */
	public function test_metadata_declares_none_as_a_supported_auth_method(): void {
		$response = ( new OAuthController() )->handle_authorization_server_metadata();
		$data     = $response->get_data();

		$this->assertContains( 'none', $data['token_endpoint_auth_methods_supported'] );
		// Existing confidential-client methods must not have been dropped.
		$this->assertContains( 'client_secret_post', $data['token_endpoint_auth_methods_supported'] );
		$this->assertContains( 'client_secret_basic', $data['token_endpoint_auth_methods_supported'] );
	}

	// ─── Token failure logging ──────────────────────────────────────

	/**
	 * Invoke the private log_token_request_failure().
	 *
	 * @param OAuthServerException $e The rejection.
	 *
	 * @return void
	 */
	private function log_failure( OAuthServerException $e ): void {
		$method = new ReflectionMethod( OAuthController::class, 'log_token_request_failure' );

		$method->invoke( new OAuthController(), $e );
	}

	/**
	 * The specific failure reason must be recoverable server-side.
	 *
	 * Previously nothing recorded which of decrypt failure / expiry / revocation
	 * / client mismatch / redirect mismatch / PKCE mismatch actually happened —
	 * the client only ever saw a generic invalid_grant. This is what closes
	 * that gap: the do_action carries the library's specific error type,
	 * message, and hint.
	 *
	 * @return void
	 */
	public function test_logs_the_specific_failure_reason(): void {
		$exception = OAuthServerException::invalidGrant( 'the PKCE code verifier does not match' );

		$this->log_failure( $exception );

		$fired = array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( $call ) => $call['type'] === 'action' && $call['hook'] === 'albert/oauth/token_request_failed'
			)
		);

		$this->assertCount( 1, $fired );
		$this->assertSame( 'invalid_grant', $fired[0]['args'][0] );
		// args[1] is getMessage() — league's generic, fixed RFC text for this
		// error type. args[2] is getHint() — the actual specific reason, which
		// is the whole point of this fix.
		$this->assertSame( 'the PKCE code verifier does not match', $fired[0]['args'][2] );
	}
}
