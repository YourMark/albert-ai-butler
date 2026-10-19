<?php
/**
 * The authorize endpoint's pages cannot be framed.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

use Albert\OAuth\Endpoints\AuthorizationPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reads the source for the render paths because each ends in `exit`; see
 * ConsentFormContractTest.
 *
 * @covers \Albert\OAuth\Endpoints\AuthorizationPage
 */
class FrameHeadersTest extends TestCase {

	public function test_framing_is_refused_by_both_headers(): void {
		$headers = AuthorizationPage::page_headers();

		$this->assertContains( 'X-Frame-Options: DENY', $headers );
		$this->assertContains( "Content-Security-Policy: frame-ancestors 'none'", $headers );
	}

	public function test_the_helper_sends_every_page_header(): void {
		$body = $this->body( 'send_page_headers' );

		$this->assertMatchesRegularExpression( '/foreach \( self::page_headers\(\) as \$(\w+) \) \{\s*header\( \$\1 \);/', $body );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function pages(): array {
		return [
			'consent'       => [ 'render_consent_page' ],
			'error'         => [ 'render_error_page' ],
			'access denied' => [ 'render_access_denied_page' ],
			'success'       => [ 'render_success_page' ],
		];
	}

	/**
	 * Headers sent after the first byte of output are dropped.
	 *
	 * @dataProvider pages
	 *
	 * @param string $method Render method.
	 */
	public function test_every_page_sends_them_before_any_output( string $method ): void {
		$body    = $this->body( $method );
		$headers = strpos( $body, '$this->send_page_headers(' );

		$this->assertNotFalse( $headers, "$method does not send the page headers." );

		foreach ( [ '?>', 'echo ', 'print ', 'printf(' ] as $output ) {
			$at = strpos( $body, $output );

			if ( false !== $at ) {
				$this->assertLessThan( $at, $headers, "$method outputs before sending the page headers." );
			}
		}
	}

	public function test_no_page_sends_headers_of_its_own(): void {
		$source = (string) file_get_contents( (string) ( new ReflectionClass( AuthorizationPage::class ) )->getFileName() );

		$this->assertSame( 1, substr_count( $source, 'nocache_headers()' ), 'Only send_page_headers() may start a page.' );
		$this->assertSame( 1, substr_count( $source, 'status_header(' ), 'Only send_page_headers() may set the status.' );
	}

	/**
	 * @param string $method Method name.
	 * @return string The method's source.
	 */
	private function body( string $method ): string {
		$reflection = new ReflectionMethod( AuthorizationPage::class, $method );
		$lines      = file( (string) $reflection->getFileName() );

		return implode( '', array_slice( (array) $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	}
}
