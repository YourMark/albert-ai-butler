<?php
/**
 * Integration tests for the connect guides and site location.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Connections\ClientSetupGuides;
use Albert\Admin\Connections\SiteLocation;
use Albert\Tests\TestCase;

/**
 * Connect guide and site location tests.
 *
 * @covers \Albert\Admin\Connections\ClientSetupGuides
 * @covers \Albert\Admin\Connections\SiteLocation
 */
class ConnectGuidesTest extends TestCase {

	private const HTTPS = 'https://example.com/wp-json/albert/v1/mcp';
	private const HTTP  = 'http://site.test/wp-json/albert/v1/mcp';

	/**
	 * No saved answer before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( SiteLocation::OPTION );
	}

	/**
	 * The ids of a guide list, in order.
	 *
	 * @param array<int, array<string, mixed>> $guides The guides.
	 *
	 * @return array<int, string>
	 */
	private function ids( array $guides ): array {
		return array_column( $guides, 'id' );
	}

	/**
	 * Add to Claude carries the site title and the endpoint, both encoded.
	 *
	 * @return void
	 */
	public function test_add_to_claude_link_encodes_the_site_title_and_endpoint(): void {
		update_option( 'blogname', 'Tom &amp; Jerry' );

		$guide = ClientSetupGuides::get( 'claude', self::HTTPS, SiteLocation::PUBLIC );
		$url   = $guide['steps'][0]['action']['value'];

		$this->assertStringStartsWith( 'https://claude.ai/customize/connectors?modal=add-custom-connector', $url );
		$this->assertStringContainsString( '&connectorName=Tom%20%26%20Jerry', $url );
		$this->assertStringContainsString( '&connectorUrl=' . rawurlencode( self::HTTPS ), $url );
	}

	/**
	 * On a local site, apps on this computer come first and the two that need
	 * a public site come last, still listed, with a reason.
	 *
	 * @return void
	 */
	public function test_a_local_site_lists_apps_that_need_a_public_site_last(): void {
		$guides = ClientSetupGuides::for_location( self::HTTP, SiteLocation::LOCAL );

		$this->assertSame( [ 'claude-desktop', 'claude-code', 'codex', 'cursor', 'vscode', 'other', 'claude', 'chatgpt' ], $this->ids( $guides ) );

		foreach ( array_slice( $guides, 6 ) as $guide ) {
			$this->assertTrue( $guide['needs_public'] );
			$this->assertNotSame( '', $guide['unavailable'] );
		}

		$this->assertSame( 'Claude on the web and phone', $guides[6]['label'] );
	}

	/**
	 * On a public site nothing is marked as needing a public site.
	 *
	 * @return void
	 */
	public function test_a_public_site_needs_nothing_more(): void {
		$guides = ClientSetupGuides::for_location( self::HTTPS, SiteLocation::PUBLIC );

		$this->assertSame( [ 'claude', 'chatgpt', 'cursor', 'vscode', 'claude-code', 'codex', 'other' ], $this->ids( $guides ) );
		$this->assertSame( [], array_filter( array_column( $guides, 'needs_public' ) ) );
	}

	/**
	 * A plain http address other than localhost gets the https note, and
	 * Claude Desktop's download is withheld: OAuth will not send the token
	 * request there, so the file could never connect.
	 *
	 * @return void
	 */
	public function test_an_http_hostname_blocks_what_cannot_sign_in(): void {
		$claude  = ClientSetupGuides::get( 'claude', self::HTTP, SiteLocation::PUBLIC );
		$desktop = ClientSetupGuides::get( 'claude-desktop', self::HTTP, SiteLocation::LOCAL );
		$other   = ClientSetupGuides::get( 'other', self::HTTP, SiteLocation::LOCAL );
		$bridge  = end( $other['more'] );

		$this->assertSame( 'Claude needs this site on an https address.', $claude['notes'][0]['text'] );
		$this->assertNotSame( '', $desktop['blocked'] );
		$this->assertSame( 'bridge', $bridge['id'] );
		$this->assertStringContainsString( '"mcp-remote@0.15.1"', $bridge['snippet'] );
		$this->assertStringNotContainsString( '--allow-http', $bridge['snippet'] );
	}

	/**
	 * Plain http on localhost is fine for apps on this computer.
	 *
	 * @return void
	 */
	public function test_http_on_localhost_is_not_blocked(): void {
		$desktop = ClientSetupGuides::get( 'claude-desktop', 'http://localhost:8888/wp-json/albert/v1/mcp', SiteLocation::LOCAL );

		$this->assertSame( '', $desktop['blocked'] );
		$this->assertSame( 'download', $desktop['steps'][0]['action']['type'] );
	}

	/**
	 * Local hosts are recognised; public ones are not.
	 *
	 * @return void
	 */
	public function test_local_hosts(): void {
		foreach ( [ 'localhost', 'site.test', 'shop.local', 'x.ddev.site', '127.0.0.1', '192.168.1.20', '10.0.0.5', '::1', 'mysite' ] as $host ) {
			$this->assertTrue( SiteLocation::is_local_host( $host ), $host );
		}

		foreach ( [ 'example.com', 'albertwp.com', 'dev.albertwp.com', '93.184.216.34' ] as $host ) {
			$this->assertFalse( SiteLocation::is_local_host( $host ), $host );
		}
	}

	/**
	 * The saved answer beats the guess; anything else is refused.
	 *
	 * @return void
	 */
	public function test_the_saved_answer_beats_the_guess(): void {
		$this->assertSame( SiteLocation::detect(), SiteLocation::current() );
		$this->assertTrue( SiteLocation::is_guess() );

		SiteLocation::save( SiteLocation::LOCAL );
		$this->assertSame( SiteLocation::LOCAL, SiteLocation::current() );
		$this->assertFalse( SiteLocation::is_guess() );

		$this->assertFalse( SiteLocation::save( 'somewhere' ) );
	}
}
