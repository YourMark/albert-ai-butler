<?php
/**
 * Key generation failure
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.7.0
 */

namespace Albert\OAuth\Server;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * OpenSSL could not create the key pair that signs sign-ins.
 *
 * Its message is written for the site owner and includes OpenSSL's error.
 *
 * @since 1.7.0
 */
class KeyGenerationFailed extends RuntimeException {

	/**
	 * Build the owner-facing message from what OpenSSL reported.
	 *
	 * @since 1.7.0
	 *
	 * @param string $reason OpenSSL's own error, or empty when it gave none.
	 *
	 * @return self
	 */
	public static function because( string $reason ): self {
		$message = __( 'Albert could not create the keys that sign assistant sign-ins, because this server\'s OpenSSL refused to make them. Ask your host to check PHP\'s OpenSSL setup.', 'albert-ai-butler' );

		if ( $reason !== '' ) {
			/* translators: %s: the error OpenSSL reported. */
			$message .= ' ' . sprintf( __( 'OpenSSL said: %s', 'albert-ai-butler' ), $reason );
		}

		return new self( $message );
	}
}
