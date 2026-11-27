<?php
/**
 * Unit tests for AuthorizationServerFactory's token TTLs.
 *
 * Covers the 1.4.0 fix making the three hardcoded TTLs (access token, refresh
 * token, auth code) filterable. A one-hour access token is the right default,
 * but was previously the only option — including for a site relying on a
 * static bearer token because its client can't complete the OAuth dance.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth\Server;

require_once dirname( __DIR__, 2 ) . '/stubs/wordpress.php';

use Albert\OAuth\Server\AuthorizationServerFactory;
use DateInterval;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AuthorizationServerFactory unit tests.
 *
 * @covers \Albert\OAuth\Server\AuthorizationServerFactory
 */
class AuthorizationServerFactoryTest extends TestCase {

	/**
	 * Reset shared stub state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_filter_returns'] = [];
	}

	/**
	 * Clean up stub state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_filter_returns'] );
		parent::tearDown();
	}

	/**
	 * Invoke the private static ttl().
	 *
	 * @param string $filter_name   Filter name.
	 * @param string $default_value Default ISO 8601 duration.
	 *
	 * @return DateInterval
	 */
	private function ttl( string $filter_name, string $default_value ): DateInterval {
		$method = new ReflectionMethod( AuthorizationServerFactory::class, 'ttl' );

		return $method->invoke( null, $filter_name, $default_value );
	}

	/**
	 * With no filter callback registered, the default TTL is used.
	 *
	 * @return void
	 */
	public function test_uses_the_default_when_unfiltered(): void {
		$interval = $this->ttl( 'albert/oauth/access_token_ttl', AuthorizationServerFactory::ACCESS_TOKEN_TTL );

		$this->assertSame( 1, $interval->h );
		$this->assertSame( 0, $interval->d );
	}

	/**
	 * A site can now tune the TTL — this is the whole point of the fix. A
	 * static-bearer-token workaround (the only option a client that can't
	 * complete the OAuth dance previously had) no longer has to live with a
	 * fixed one-hour expiry.
	 *
	 * @return void
	 */
	public function test_honours_a_filtered_override(): void {
		$GLOBALS['albert_test_filter_returns']['albert/oauth/access_token_ttl'] = 'PT6H';

		$interval = $this->ttl( 'albert/oauth/access_token_ttl', AuthorizationServerFactory::ACCESS_TOKEN_TTL );

		$this->assertSame( 6, $interval->h );
	}

	/**
	 * A malformed override (e.g. a site's filter callback returning garbage)
	 * falls back to the default rather than fataling on the DateInterval
	 * constructor.
	 *
	 * @return void
	 */
	public function test_falls_back_to_default_on_an_invalid_override(): void {
		$GLOBALS['albert_test_filter_returns']['albert/oauth/access_token_ttl'] = 'not-a-duration';

		$interval = $this->ttl( 'albert/oauth/access_token_ttl', AuthorizationServerFactory::ACCESS_TOKEN_TTL );

		$this->assertSame( 1, $interval->h );
	}
}
