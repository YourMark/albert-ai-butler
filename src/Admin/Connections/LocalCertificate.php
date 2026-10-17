<?php
/**
 * The root certificate this computer's local sites are signed with.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the certificate an app built on Node needs to trust a local https site.
 *
 * Local tools sign their sites with their own root certificate, which Node
 * does not trust by default, so the connection fails without a clear message.
 * On a local site PHP runs on the same computer as the app, so the common
 * tools' files can be looked for directly. Docker and remote setups find
 * nothing, and say so rather than guessing.
 *
 * @since 1.5.0
 */
class LocalCertificate {

	/**
	 * The certificate's path, or null when none of the known tools have one here.
	 *
	 * @return string|null
	 * @since 1.5.0
	 */
	public static function path(): ?string {
		$home = (string) getenv( 'HOME' );

		// PHP-FPM often runs without HOME; the account's own record still has it.
		if ( $home === '' && function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$account = posix_getpwuid( posix_geteuid() );
			$home    = is_array( $account ) ? (string) $account['dir'] : '';
		}

		if ( $home === '' ) {
			return null;
		}

		$candidates = [
			// Laravel Herd, then Valet.
			$home . '/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem',
			$home . '/.config/valet/CA/LaravelValetCASelfSigned.pem',
			// mkcert, on macOS and Linux.
			$home . '/Library/Application Support/mkcert/rootCA.pem',
			$home . '/.local/share/mkcert/rootCA.pem',
		];

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	}
}
