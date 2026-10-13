<?php
/**
 * OAuth JSON Web Key Set
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.4.2
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\OAuth\Server\KeyManager;

/**
 * Jwks class
 *
 * Builds the RFC 7517 JSON Web Key Set that publishes the OAuth signing key's
 * public half. The document is served at the `jwks_uri` named in the RFC 8414
 * authorization server metadata.
 *
 * This exists because a conforming client validates the metadata document
 * before it authenticates, and some clients — Claude Code's MCP SDK among them —
 * require `jwks_uri` to be a string even though RFC 8414 marks it optional. A
 * missing key fails discovery outright ("expected string, received undefined")
 * before a single token is exchanged, so the field, and a real key set behind
 * it, have to exist.
 *
 * The key set is derived from {@see KeyManager}'s RSA public key, the same key
 * that verifies the JWT access tokens this server issues, so a client that does
 * verify signatures against it gets the right answer.
 *
 * @since 1.4.2
 */
class Jwks {

	/**
	 * The JSON Web Key Set document.
	 *
	 * Always returns the `keys` envelope. When the public key cannot be read or
	 * parsed the array is empty rather than absent: a well-formed, empty key set
	 * still satisfies a client validating the document's shape, where a 500 or a
	 * bare `{}` would not.
	 *
	 * @return array{keys: array<int, array<string, string>>} The key set.
	 * @since 1.4.2
	 */
	public static function document(): array {
		$key = self::signing_key();

		return [ 'keys' => $key === null ? [] : [ $key ] ];
	}

	/**
	 * The RSA public key as a single JWK, or null when it cannot be built.
	 *
	 * @return array<string, string>|null The JWK, or null on failure.
	 * @since 1.4.2
	 */
	private static function signing_key(): ?array {
		$pem = KeyManager::get_public_key();

		if ( $pem === '' ) {
			return null;
		}

		// openssl_pkey_get_public() emits a warning on a malformed key. The false
		// return is handled right below, so the warning is silenced rather than
		// left to leak into the JSON response of a live endpoint.
		$resource = @openssl_pkey_get_public( $pem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( $resource === false ) {
			return null;
		}

		$details = openssl_pkey_get_details( $resource );

		if ( $details === false || ! isset( $details['rsa']['n'], $details['rsa']['e'] ) ) {
			return null;
		}

		$modulus  = self::base64url( (string) $details['rsa']['n'] );
		$exponent = self::base64url( (string) $details['rsa']['e'] );

		return [
			'kty' => 'RSA',
			'use' => 'sig',
			'alg' => 'RS256',
			'kid' => self::thumbprint( $modulus, $exponent ),
			'n'   => $modulus,
			'e'   => $exponent,
		];
	}

	/**
	 * The RFC 7638 thumbprint of the RSA key, used as its stable `kid`.
	 *
	 * The thumbprint is a hash of the key's own required members in canonical
	 * form, so it stays the same for the life of the key and changes only when
	 * the key is regenerated. That is exactly the identity a `kid` names.
	 *
	 * @param string $modulus  The base64url-encoded modulus.
	 * @param string $exponent The base64url-encoded exponent.
	 *
	 * @return string The base64url-encoded SHA-256 thumbprint.
	 * @since 1.4.2
	 */
	private static function thumbprint( string $modulus, string $exponent ): string {
		// Canonical JSON per RFC 7638: the required members only, lexically
		// ordered by key, no whitespace. Built as a literal so member order and
		// escaping cannot drift with a change to wp_json_encode()'s flags.
		$canonical = '{"e":"' . $exponent . '","kty":"RSA","n":"' . $modulus . '"}';

		return self::base64url( hash( 'sha256', $canonical, true ) );
	}

	/**
	 * Encode raw bytes as unpadded base64url (RFC 7515 §2).
	 *
	 * @param string $bytes The raw bytes.
	 *
	 * @return string The base64url-encoded string.
	 * @since 1.4.2
	 */
	private static function base64url( string $bytes ): string {
		// base64url-encoding public key material, the encoding RFC 7515 requires.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
