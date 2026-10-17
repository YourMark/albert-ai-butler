<?php
/**
 * Integration tests for the Claude Desktop install file.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Connections\ClaudeDesktopBundle;
use Albert\Tests\TestCase;

/**
 * ClaudeDesktopBundle tests.
 *
 * @covers \Albert\Admin\Connections\ClaudeDesktopBundle
 */
class ClaudeDesktopBundleTest extends TestCase {

	/**
	 * The manifest starts the bundled bridge with this site's address and no secret.
	 *
	 * @return void
	 */
	public function test_manifest_points_the_bridge_at_this_site(): void {
		$manifest = ClaudeDesktopBundle::manifest();
		$env      = $manifest['server']['mcp_config']['env'];

		$this->assertSame( '0.3', $manifest['manifest_version'] );
		$this->assertSame( 'server/bridge.mjs', $manifest['server']['entry_point'] );
		$this->assertSame( rest_url( 'albert/v1/mcp' ), $env['ALBERT_MCP_URL'] );
		$this->assertSame( '1', $env['NODE_USE_SYSTEM_CA'] );

		foreach ( array_keys( $env ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/token|secret|password|key/i', $key );
		}
	}

	/**
	 * The zip holds the manifest and the bridge where the manifest says.
	 *
	 * @return void
	 */
	public function test_build_writes_a_zip_with_manifest_and_bridge(): void {
		if ( ! ClaudeDesktopBundle::is_available() ) {
			$this->markTestSkipped( 'Needs ZipArchive and the built bridge.' );
		}

		$file = wp_tempnam( 'albert-test.mcpb' );
		$this->assertTrue( ClaudeDesktopBundle::build( $file ) );

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $file ) );
		$this->assertNotFalse( $zip->locateName( 'manifest.json' ) );
		$this->assertNotFalse( $zip->locateName( 'server/bridge.mjs' ) );
		$this->assertSame( ClaudeDesktopBundle::manifest(), json_decode( (string) $zip->getFromName( 'manifest.json' ), true ) );
		$zip->close();

		wp_delete_file( $file );
	}

	/**
	 * The file is named after the site's host.
	 *
	 * @return void
	 */
	public function test_filename_uses_the_host(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$this->assertSame( 'albert-' . sanitize_title( str_replace( '.', '-', $host ) ) . '.mcpb', ClaudeDesktopBundle::filename() );
	}

	/**
	 * A public site's file never carries a certificate path from the server.
	 *
	 * @return void
	 */
	public function test_a_public_site_gets_no_certificate_path(): void {
		\Albert\Admin\Connections\SiteLocation::save( \Albert\Admin\Connections\SiteLocation::PUBLIC );

		$env = ClaudeDesktopBundle::manifest()['server']['mcp_config']['env'];

		delete_option( \Albert\Admin\Connections\SiteLocation::OPTION );
		$this->assertArrayNotHasKey( 'NODE_EXTRA_CA_CERTS', $env );
	}

	/**
	 * With plain permalinks the bridge keeps its own page: it refuses an
	 * address with a query string.
	 *
	 * @return void
	 */
	public function test_done_url_is_empty_on_plain_permalinks(): void {
		$this->set_permalink_structure( '' );
		$plain = ClaudeDesktopBundle::done_url();
		$this->set_permalink_structure( '/%postname%/' );

		$this->assertSame( '', $plain );
		$this->assertStringEndsWith( '/connect/done', ClaudeDesktopBundle::done_url() );
	}

}
