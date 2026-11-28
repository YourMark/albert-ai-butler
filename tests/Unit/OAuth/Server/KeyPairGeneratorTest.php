<?php
/**
 * Unit tests for the RSA key pair generator.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth\Server;

use Albert\OAuth\Server\KeyGenerationFailed;
use Albert\OAuth\Server\KeyPairGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Key generation with and without OpenSSL's default config.
 *
 * @covers \Albert\OAuth\Server\KeyPairGenerator
 * @covers \Albert\OAuth\Server\KeyGenerationFailed
 */
class KeyPairGeneratorTest extends TestCase {

	/**
	 * Skip where PHP has no OpenSSL.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL is not available.' );
		}
	}

	/**
	 * A PHP whose OpenSSL config file is missing still gets a matching pair.
	 *
	 * Run in a child process: OpenSSL reads `OPENSSL_CONF` when PHP starts.
	 *
	 * @return void
	 */
	public function test_creates_a_pair_when_the_default_config_is_missing(): void {
		$script = sprintf(
			'define( "ABSPATH", "/" ); function __( $t ) { return $t; } require %s; require %s; $p = ( new Albert\OAuth\Server\KeyPairGenerator() )->generate(); $s = ""; openssl_sign( "x", $s, $p["private"], OPENSSL_ALGO_SHA256 ); echo openssl_verify( "x", $s, $p["public"], OPENSSL_ALGO_SHA256 );',
			json_encode( dirname( __DIR__, 4 ) . '/src/OAuth/Server/KeyGenerationFailed.php', JSON_UNESCAPED_SLASHES ),
			json_encode( dirname( __DIR__, 4 ) . '/src/OAuth/Server/KeyPairGenerator.php', JSON_UNESCAPED_SLASHES )
		);

		$output = $this->run_php( $script, [ 'OPENSSL_CONF' => '/nonexistent/openssl.cnf' ] );

		$this->assertSame( '1', $output );
	}

	/**
	 * Without that fallback the same PHP fails, so the test above proves the fallback.
	 *
	 * @return void
	 */
	public function test_openssl_fails_there_without_the_bundled_config(): void {
		$script = 'echo openssl_pkey_new( [ "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA ] ) === false ? "failed" : "created";';

		$this->assertSame( 'failed', $this->run_php( $script, [ 'OPENSSL_CONF' => '/nonexistent/openssl.cnf' ] ) );
	}

	/**
	 * The second attempt points OpenSSL at the bundled config, for the export too.
	 *
	 * @return void
	 */
	public function test_retries_with_the_bundled_config(): void {
		$generator = new class() extends KeyPairGenerator {
			/**
			 * Options of each attempt.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $tried = [];

			/**
			 * Fail unless the bundled config is given.
			 *
			 * @param array<string, mixed> $options Options.
			 *
			 * @return array{private: string, public: string}|null
			 */
			protected function attempt( array $options ): ?array {
				$this->tried[] = $options;

				return isset( $options['config'] ) ? parent::attempt( $options ) : null;
			}
		};

		$pair = $generator->generate();

		$this->assertCount( 2, $generator->tried );
		$this->assertSame( KeyPairGenerator::CONFIG_FILE, $generator->tried[1]['config'] );
		$this->assertFileExists( KeyPairGenerator::CONFIG_FILE );
		$this->assertStringContainsString( 'PRIVATE KEY', $pair['private'] );
		$this->assertStringContainsString( 'PUBLIC KEY', $pair['public'] );
	}

	/**
	 * When both attempts fail, the error says so in words an owner can act on.
	 *
	 * @return void
	 */
	public function test_fails_with_a_clear_message(): void {
		$generator = new class() extends KeyPairGenerator {
			/**
			 * Always fail, as OpenSSL does, leaving an error queued.
			 *
			 * @param array<string, mixed> $options Options.
			 *
			 * @return array{private: string, public: string}|null
			 */
			protected function attempt( array $options ): ?array {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Only queues an OpenSSL error.
				@openssl_pkey_get_private( 'not a key' );

				return null;
			}
		};

		try {
			$generator->generate();
			$this->fail( 'Expected a failure.' );
		} catch ( KeyGenerationFailed $e ) {
			$this->assertStringContainsString( 'could not create the keys', $e->getMessage() );
			$this->assertStringContainsString( 'OpenSSL said:', $e->getMessage() );
			$this->assertStringNotContainsString( 'Invalid key supplied', $e->getMessage() );
		}
	}

	/**
	 * Run PHP code in a child process with extra environment variables.
	 *
	 * @param string                $code PHP code, without the opening tag.
	 * @param array<string, string> $env  Environment variables to add.
	 *
	 * @return string Its output, trimmed.
	 */
	private function run_php( string $code, array $env ): string {
		$command = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1';
		$prefix  = '';
		foreach ( $env as $name => $value ) {
			$prefix .= $name . '=' . escapeshellarg( $value ) . ' ';
		}

		return trim( (string) shell_exec( $prefix . $command ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.DiscouragedPHPFunctions -- Test-only child process.
	}
}
