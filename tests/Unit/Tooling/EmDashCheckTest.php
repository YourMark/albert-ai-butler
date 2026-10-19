<?php
/**
 * Tests for bin/check-em-dashes.php.
 *
 * @package Albert\Tests\Unit\Tooling
 */

namespace Albert\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Runs the checker against throwaway trees, since CI trusts its exit code.
 */
class EmDashCheckTest extends TestCase {

	/**
	 * Fixture root for the current test.
	 *
	 * @var string
	 */
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/albert-em-dash-' . uniqid();
		mkdir( $this->root . '/src', 0777, true );
		mkdir( $this->root . '/assets/src', 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
		parent::tearDown();
	}

	public function test_the_plugin_itself_passes(): void {
		[ $code, $output ] = $this->run_check( dirname( __DIR__, 3 ) );

		$this->assertSame( 0, $code, $output );
	}

	public function test_comments_are_exempt(): void {
		$this->write( 'src/A.php', "<?php\n/** Docblock \u{2014} fine. */\n// Comment \u{2014} fine.\n# Hash \u{2014} fine.\n\$a = 'plain';\n" );
		$this->write( 'assets/src/a.jsx', "// Line \u{2014} fine.\n/* Block \u{2014} fine. */\nconst url = 'https://example.com'; // Trailing \u{2014} fine.\n" );

		[ $code, $output ] = $this->run_check( $this->root );

		$this->assertSame( 0, $code, $output );
	}

	public function test_a_php_string_fails_with_its_line(): void {
		$this->write( 'src/A.php', "<?php\n// Fine \u{2014} here.\n\$a = __( 'Reads data \u{2014} never writes.', 'albert-ai-butler' );\n" );

		[ $code, $output ] = $this->run_check( $this->root );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'src/A.php:3:', $output );
	}

	public function test_jsx_text_fails_even_outside_quotes(): void {
		$this->write( 'assets/src/a.jsx', "const a = 'x // not a comment';\nconst b = <p>Hello \u{2014} world</p>;\n" );

		[ $code, $output ] = $this->run_check( $this->root );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'assets/src/a.jsx:2:', $output );
	}

	public function test_readmes_fail_anywhere(): void {
		$this->write( 'readme.txt', "Line one.\nLine two \u{2014} oops.\n" );

		[ $code, $output ] = $this->run_check( $this->root );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'readme.txt:2:', $output );
	}

	/**
	 * @param string $path    Path relative to the fixture root.
	 * @param string $content File content.
	 */
	private function write( string $path, string $content ): void {
		file_put_contents( $this->root . '/' . $path, $content );
	}

	/**
	 * @param string $root Tree to check.
	 * @return array{0: int, 1: string} Exit code and combined output.
	 */
	private function run_check( string $root ): array {
		$script = dirname( __DIR__, 3 ) . '/bin/check-em-dashes.php';
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $root ) . ' 2>&1', $lines, $code );

		return [ $code, implode( "\n", $lines ) ];
	}
}
