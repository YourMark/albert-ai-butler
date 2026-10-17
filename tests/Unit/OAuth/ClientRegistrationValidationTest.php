<?php
/**
 * Unit tests for DCR redirect-URI validation and public-client classification.
 *
 * Covers the 1.3.1 OAuth/DCR hardening: the scheme-aware validator that replaced
 * the permissive `'*'` wildcard — every rejection reason, every accepted scheme,
 * the strict-mode allowlist filter, and the private-use/loopback → public-client
 * classification that drives PKCE enforcement.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\Endpoints\ClientRegistration;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * ClientRegistration validation tests.
 *
 * @covers \Albert\OAuth\Endpoints\ClientRegistration
 */
class ClientRegistrationValidationTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var ClientRegistration
	 */
	private ClientRegistration $registration;

	/**
	 * Reset shared stub state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registration                      = new ClientRegistration();
		$GLOBALS['albert_test_hooks']            = [];
		$GLOBALS['albert_test_filter_returns']   = [];
		$GLOBALS['albert_test_environment_type'] = 'production';
	}

	/**
	 * Clean up stub state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_filter_returns'],
			$GLOBALS['albert_test_environment_type']
		);
		parent::tearDown();
	}

	/**
	 * Invoke the private validate_redirect_uri().
	 *
	 * @param string $uri The redirect URI.
	 *
	 * @return string The reason code ('' when valid).
	 */
	private function validate( string $uri ): string {
		$method = new ReflectionMethod( ClientRegistration::class, 'validate_redirect_uri' );
		$method->setAccessible( true );

		return $method->invoke( $this->registration, $uri );
	}

	/**
	 * Invoke the private requires_public_client().
	 *
	 * @param array<int, string> $uris Redirect URIs.
	 *
	 * @return bool Whether the client must be public.
	 */
	private function requires_public( array $uris ): bool {
		$method = new ReflectionMethod( ClientRegistration::class, 'requires_public_client' );
		$method->setAccessible( true );

		return $method->invoke( $this->registration, $uris );
	}

	// ─── Accepted schemes ────────────────────────────────────────────

	/**
	 * Well-formed private-use and loopback schemes are accepted by default.
	 *
	 * @dataProvider accepted_uris
	 *
	 * @param string $uri         The redirect URI.
	 * @param string $environment Environment type to simulate.
	 *
	 * @return void
	 */
	public function test_accepts_valid_redirect_uris( string $uri, string $environment = 'production' ): void {
		$GLOBALS['albert_test_environment_type'] = $environment;

		$this->assertSame( '', $this->validate( $uri ), sprintf( 'Expected %s to be accepted', $uri ) );
	}

	/**
	 * Accepted redirect URIs (permissive default).
	 *
	 * @return array<string, array{0: string, 1?: string}>
	 */
	public static function accepted_uris(): array {
		return [
			'claude custom scheme'       => [ 'claude://callback' ],
			'cursor custom scheme'       => [ 'cursor://cb' ],
			'unknown well-formed scheme' => [ 'myeditor://cb' ],
			'reverse-dns scheme, one /'  => [ 'com.example.app:/oauth' ],
			'http loopback ip'           => [ 'http://127.0.0.1/cb' ],
			'http localhost with port'   => [ 'http://localhost:8080/callback' ],
			'https public literal ip'    => [ 'https://93.184.216.34/cb' ],
			'https hostname (dev)'       => [ 'https://app.example/cb', 'development' ],
		];
	}

	// ─── Rejected URIs ───────────────────────────────────────────────

	/**
	 * Malformed, dangerous, and internal URIs are rejected with specific codes.
	 *
	 * @dataProvider rejected_uris
	 *
	 * @param string $uri    The redirect URI.
	 * @param string $reason The expected reason code.
	 *
	 * @return void
	 */
	public function test_rejects_invalid_redirect_uris( string $uri, string $reason ): void {
		$this->assertSame( $reason, $this->validate( $uri ), sprintf( 'Expected %s to be rejected as %s', $uri, $reason ) );
	}

	/**
	 * Rejected redirect URIs with their reason codes.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function rejected_uris(): array {
		return [
			'empty'               => [ '', 'redirect_uri_empty' ],
			'fragment'            => [ 'https://app.example/cb#x', 'redirect_uri_has_fragment' ],
			'no scheme'           => [ 'not-a-uri', 'redirect_uri_malformed' ],
			'javascript'          => [ 'javascript://alert(1)', 'redirect_scheme_not_permitted' ],
			'javascript any case' => [ 'JaVaScRiPt://alert(1)', 'redirect_scheme_not_permitted' ],
			'control character'   => [ "\x01javascript://alert(1)", 'redirect_uri_malformed' ],
			'line break inside'   => [ "cursor://a\r\nb/cb", 'redirect_uri_malformed' ],
			'space inside'        => [ 'https://app.example/c b', 'redirect_uri_malformed' ],
			'data'                => [ 'data:text/html,x', 'redirect_scheme_not_permitted' ],
			'vbscript'            => [ 'vbscript:msgbox', 'redirect_scheme_not_permitted' ],
			'file'                => [ 'file:///etc/passwd', 'redirect_scheme_not_permitted' ],
			'blob'                => [ 'blob:https://x/y', 'redirect_scheme_not_permitted' ],
			'about'               => [ 'about:blank', 'redirect_scheme_not_permitted' ],
			'http non-loopback'   => [ 'http://evil.example/cb', 'redirect_scheme_not_permitted' ],
			'https private range' => [ 'https://10.0.0.5/cb', 'redirect_host_not_allowed' ],
			'https loopback host' => [ 'https://localhost/cb', 'redirect_host_not_allowed' ],
		];
	}

	/**
	 * A redirect URI longer than the cap is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_over_length_uri(): void {
		$uri = 'https://app.example/' . str_repeat( 'a', 2100 );

		$this->assertSame( 'redirect_uri_too_long', $this->validate( $uri ) );
	}

	// ─── Strict-mode allowlist ───────────────────────────────────────

	/**
	 * A non-empty allowlist rejects a well-formed scheme outside the list.
	 *
	 * @return void
	 */
	public function test_strict_mode_rejects_scheme_outside_allowlist(): void {
		$GLOBALS['albert_test_filter_returns']['albert/oauth/allowed_redirect_schemes'] = [ 'https' ];

		$this->assertSame( 'redirect_scheme_not_allowed', $this->validate( 'claude://cb' ) );
	}

	/**
	 * With the default empty allowlist, a custom scheme is accepted.
	 *
	 * @return void
	 */
	public function test_permissive_default_accepts_custom_scheme(): void {
		$this->assertSame( '', $this->validate( 'claude://cb' ) );
	}

	/**
	 * The dangerous denylist wins even when the scheme is on the allowlist.
	 *
	 * @return void
	 */
	public function test_dangerous_scheme_rejected_even_when_allowlisted(): void {
		$GLOBALS['albert_test_filter_returns']['albert/oauth/allowed_redirect_schemes'] = [ 'javascript' ];

		$this->assertSame( 'redirect_scheme_not_permitted', $this->validate( 'javascript://x' ) );
	}

	// ─── Public-client classification ────────────────────────────────

	/**
	 * Https-only clients stay confidential; native/loopback force public.
	 *
	 * @return void
	 */
	public function test_public_client_classification(): void {
		$this->assertFalse( $this->requires_public( [ 'https://app.example/cb' ] ) );
		$this->assertTrue( $this->requires_public( [ 'claude://cb' ] ) );
		$this->assertTrue( $this->requires_public( [ 'http://127.0.0.1/cb' ] ) );
		// Mixed: any non-https redirect makes the whole client public.
		$this->assertTrue( $this->requires_public( [ 'https://app.example/cb', 'claude://cb' ] ) );
	}
}
