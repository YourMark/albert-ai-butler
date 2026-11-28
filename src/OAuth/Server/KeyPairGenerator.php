<?php
/**
 * RSA key pair generator
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.7.0
 */

namespace Albert\OAuth\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the RSA key pair that signs sign-ins, also where OpenSSL has no default config.
 *
 * Without a readable default `openssl.cnf`, `openssl_pkey_new()` fails, so a
 * second attempt points OpenSSL at the minimal config shipped next to this
 * class rather than guessing an OS-specific path.
 *
 * @since 1.7.0
 */
class KeyPairGenerator {

	/**
	 * The bundled config used when OpenSSL's default cannot be read.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const CONFIG_FILE = __DIR__ . '/openssl.cnf';

	/**
	 * Options for every attempt.
	 *
	 * @since 1.7.0
	 * @var array<string, mixed>
	 */
	private const OPTIONS = [
		'digest_alg'       => 'sha256',
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	];

	/**
	 * Create a key pair, falling back to the bundled config.
	 *
	 * @since 1.7.0
	 *
	 * @return array{private: string, public: string} PEM-encoded keys.
	 * @throws KeyGenerationFailed When neither attempt produces both keys.
	 */
	public function generate(): array {
		$errors = [];

		foreach ( [ self::OPTIONS, self::OPTIONS + [ 'config' => self::CONFIG_FILE ] ] as $options ) {
			$pair = $this->attempt( $options );
			if ( $pair !== null ) {
				// Errors from a failed first attempt stay queued and would leak into later OpenSSL calls.
				self::drain_errors();

				return $pair;
			}
			$errors = array_merge( $errors, self::drain_errors() );
		}

		throw KeyGenerationFailed::because( implode( '; ', array_unique( $errors ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped where it is rendered.
	}

	/**
	 * One attempt, with the export given the same options so it reads the same config.
	 *
	 * @since 1.7.0
	 *
	 * @param array<string, mixed> $options Options for `openssl_pkey_new()` and `openssl_pkey_export()`.
	 *
	 * @return array{private: string, public: string}|null Both keys, or null when either is missing.
	 */
	protected function attempt( array $options ): ?array {
		$resource = openssl_pkey_new( $options );
		if ( $resource === false ) {
			return null;
		}

		$private = '';
		if ( ! openssl_pkey_export( $resource, $private, null, $options ) || ! is_string( $private ) || $private === '' ) {
			return null;
		}

		$details = openssl_pkey_get_details( $resource );
		if ( $details === false || empty( $details['key'] ) || ! is_string( $details['key'] ) ) {
			return null;
		}

		return [
			'private' => $private,
			'public'  => $details['key'],
		];
	}

	/**
	 * Empty OpenSSL's error queue.
	 *
	 * @since 1.7.0
	 *
	 * @return list<string> The errors it held, oldest first.
	 */
	private static function drain_errors(): array {
		$errors = [];
		while ( ( $error = openssl_error_string() ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- The documented way to read the queue.
			$errors[] = $error;
		}

		return $errors;
	}
}
