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
 * ConsentFormContractTest. Tokenised, so comments and strings never count.
 *
 * @covers \Albert\OAuth\Endpoints\AuthorizationPage
 */
class FrameHeadersTest extends TestCase {

	/**
	 * Calls that print, besides the echo, print and inline HTML tokens.
	 */
	private const OUTPUT_FUNCTIONS = [ 'printf', 'vprintf', 'readfile', 'wp_print_styles', 'wp_print_scripts' ];

	public function test_framing_is_refused_by_both_headers(): void {
		$headers = AuthorizationPage::page_headers();

		$this->assertContains( 'X-Frame-Options: DENY', $headers );
		$this->assertContains( "Content-Security-Policy: frame-ancestors 'none'", $headers );
	}

	public function test_the_helper_sends_every_page_header(): void {
		$code = implode( ' ', array_column( $this->tokens( $this->body( 'send_page_headers' ) ), 1 ) );

		$this->assertMatchesRegularExpression( '/foreach \( self :: page_headers \( \) as (\$\w+) \) \{ header \( \1 \) ;/', $code );
	}

	/**
	 * Every render_* method, so a new page is covered without being listed.
	 *
	 * @return array<string, array{string}>
	 */
	public static function pages(): array {
		$pages = [];

		foreach ( ( new ReflectionClass( AuthorizationPage::class ) )->getMethods() as $method ) {
			if ( str_starts_with( $method->getName(), 'render_' ) ) {
				$pages[ $method->getName() ] = [ $method->getName() ];
			}
		}

		return $pages;
	}

	public function test_the_known_pages_are_found(): void {
		foreach ( [ 'render_consent_page', 'render_error_page', 'render_access_denied_page', 'render_success_page' ] as $page ) {
			$this->assertArrayHasKey( $page, self::pages() );
		}
	}

	/**
	 * Headers sent after the first byte of output are dropped.
	 *
	 * @dataProvider pages
	 *
	 * @param string $method Render method.
	 */
	public function test_every_page_sends_them_before_any_output( string $method ): void {
		$headers = null;
		$output  = null;

		foreach ( $this->tokens( $this->body( $method ) ) as $i => [ $id, $text ] ) {
			if ( null === $headers && T_STRING === $id && 'send_page_headers' === $text ) {
				$headers = $i;
			}

			$prints = in_array( $id, [ T_ECHO, T_PRINT, T_INLINE_HTML, T_CLOSE_TAG, T_OPEN_TAG_WITH_ECHO ], true )
				|| ( T_STRING === $id && in_array( $text, self::OUTPUT_FUNCTIONS, true ) );

			if ( null === $output && $prints ) {
				$output = $i;
			}
		}

		$this->assertNotNull( $headers, "$method does not send the page headers." );

		if ( null !== $output ) {
			$this->assertLessThan( $output, $headers, "$method outputs before sending the page headers." );
		}
	}

	public function test_no_page_sends_headers_of_its_own(): void {
		$source = (string) file_get_contents( (string) ( new ReflectionClass( AuthorizationPage::class ) )->getFileName() );
		$calls  = array_count_values( array_column( $this->tokens( $source, false ), 1 ) );

		$this->assertSame( 1, $calls['nocache_headers'] ?? 0, 'Only send_page_headers() may start a page.' );
		$this->assertSame( 1, $calls['status_header'] ?? 0, 'Only send_page_headers() may set the status.' );
	}

	/**
	 * PHP tokens without whitespace, comments or docblocks.
	 *
	 * @param string $code       PHP source.
	 * @param bool   $open_first Whether $code is a method body that needs an open tag.
	 * @return array<int, array{int, string}> Token id and text; single characters get id 0.
	 */
	private function tokens( string $code, bool $open_first = true ): array {
		$tokens = [];

		foreach ( token_get_all( $open_first ? "<?php\n" . $code : $code ) as $token ) {
			// A leading backslash names the same global function.
			$token = is_array( $token ) ? [ $token[0], ltrim( $token[1], '\\' ) ] : [ 0, $token ];
			$token[0] = T_NAME_FULLY_QUALIFIED === $token[0] ? T_STRING : $token[0];

			if ( ! in_array( $token[0], [ T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				$tokens[] = $token;
			}
		}

		return $tokens;
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
