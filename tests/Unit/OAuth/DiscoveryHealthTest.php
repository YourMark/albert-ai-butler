<?php
/**
 * Unit tests for OAuth discovery reachability classification.
 *
 * The interception this catches is invisible from inside WordPress, so the one
 * piece of judgement (turning an HTTP outcome into "assistants can sign in",
 * "the host is intercepting discovery", or "could not tell") is a pure static
 * method, exercised here without a network.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\DiscoveryHealth;
use PHPUnit\Framework\TestCase;

/**
 * DiscoveryHealth classification tests.
 *
 * @covers \Albert\OAuth\DiscoveryHealth::classify
 */
class DiscoveryHealthTest extends TestCase {

	/**
	 * A 200 carrying a real authorization-server document is the only "ok".
	 *
	 * @return void
	 */
	public function test_a_valid_metadata_document_is_ok(): void {
		$json = [
			'issuer'                => 'https://example.test',
			'registration_endpoint' => 'https://example.test/wp-json/albert/v1/oauth/register',
		];

		$this->assertSame( DiscoveryHealth::OK, DiscoveryHealth::classify( false, 200, $json ) );
	}

	/**
	 * A request that never completed is inconclusive, not a fault.
	 *
	 * A blocked loopback is not evidence the host is intercepting discovery, so
	 * it must not raise the same alarm.
	 *
	 * @return void
	 */
	public function test_a_failed_request_is_inconclusive(): void {
		$this->assertSame( DiscoveryHealth::INCONCLUSIVE, DiscoveryHealth::classify( true, 0, null ) );
	}

	/**
	 * A 404 is the interception case: the host answered, but not with Albert's data.
	 *
	 * @return void
	 */
	public function test_a_404_is_unreachable(): void {
		$this->assertSame( DiscoveryHealth::UNREACHABLE, DiscoveryHealth::classify( false, 404, null ) );
	}

	/**
	 * A 200 that is not the expected document is still unreachable.
	 *
	 * Some hosts return their own 200 page for a missing .well-known file, or a
	 * login redirect resolves to one. A 200 alone proves nothing; the fields do.
	 *
	 * @return void
	 */
	public function test_a_200_without_the_expected_fields_is_unreachable(): void {
		$this->assertSame( DiscoveryHealth::UNREACHABLE, DiscoveryHealth::classify( false, 200, [ 'foo' => 'bar' ] ) );
		$this->assertSame( DiscoveryHealth::UNREACHABLE, DiscoveryHealth::classify( false, 200, null ) );
	}

	/**
	 * A partial document (issuer but no registration endpoint) is not accepted.
	 *
	 * @return void
	 */
	public function test_a_partial_document_is_unreachable(): void {
		$this->assertSame(
			DiscoveryHealth::UNREACHABLE,
			DiscoveryHealth::classify( false, 200, [ 'issuer' => 'https://example.test' ] )
		);
	}
}
