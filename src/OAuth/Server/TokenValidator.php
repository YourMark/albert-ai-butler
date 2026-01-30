<?php
/**
 * OAuth Token Validator
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.0.0
 */

namespace Albert\OAuth\Server;

use Exception;
use Albert\OAuth\Endpoints\Psr7Bridge;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_Error;
use WP_REST_Request;
use WP_User;

/**
 * TokenValidator class
 *
 * Validates OAuth 2.0 Bearer tokens and authenticates users.
 *
 * @since 1.0.0
 */
class TokenValidator {

	/**
	 * Validate the Bearer token from a REST request.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request to validate.
	 *
	 * @return WP_User|WP_Error The authenticated user or an error.
	 * @since 1.0.0
	 */
	public static function validate_request( WP_REST_Request $request ): WP_User|WP_Error {
		try {
			$server      = ResourceServerFactory::create();
			$psr_request = Psr7Bridge::to_psr7_request( $request );

			// Validate the request.
			$validated_request = $server->validateAuthenticatedRequest( $psr_request );

			// Get user ID from the token.
			$user_id = $validated_request->getAttribute( 'oauth_user_id' );

			if ( empty( $user_id ) ) {
				return new WP_Error(
					'oauth_invalid_token',
					__( 'Token does not contain a valid user identifier.', 'albert-ai-butler' ),
					[ 'status' => 401 ]
				);
			}

			// Get the WordPress user.
			$user = get_user_by( 'id', $user_id );

			if ( ! $user ) {
				return new WP_Error(
					'oauth_user_not_found',
					__( 'User associated with token not found.', 'albert-ai-butler' ),
					[ 'status' => 401 ]
				);
			}

			return $user;
		} catch ( OAuthServerException $e ) {
			return new WP_Error(
				'oauth_' . $e->getErrorType(),
				$e->getMessage(),
				[ 'status' => 401 ]
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'oauth_error',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Get token metadata from a REST request.
	 *
	 * Returns an array with token details if valid, or WP_Error if invalid.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request to validate.
	 *
	 * @return array<string, mixed>|WP_Error Token metadata or error.
	 * @since 1.0.0
	 */
	public static function get_token_metadata( WP_REST_Request $request ): array|WP_Error {
		try {
			$server      = ResourceServerFactory::create();
			$psr_request = Psr7Bridge::to_psr7_request( $request );

			// Validate the request.
			$validated_request = $server->validateAuthenticatedRequest( $psr_request );

			return [
				'user_id'         => $validated_request->getAttribute( 'oauth_user_id' ),
				'client_id'       => $validated_request->getAttribute( 'oauth_client_id' ),
				'access_token_id' => $validated_request->getAttribute( 'oauth_access_token_id' ),
				'scopes'          => $validated_request->getAttribute( 'oauth_scopes' ),
			];
		} catch ( OAuthServerException $e ) {
			return new WP_Error(
				'oauth_' . $e->getErrorType(),
				$e->getMessage(),
				[ 'status' => 401 ]
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'oauth_error',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Check if a request has a valid Bearer token.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request to check.
	 *
	 * @return bool True if the request has a valid token.
	 * @since 1.0.0
	 */
	public static function has_valid_token( WP_REST_Request $request ): bool {
		$result = self::validate_request( $request );
		return ! is_wp_error( $result );
	}

	/**
	 * Extract Bearer token from Authorization header.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return string|null The token or null if not found.
	 * @since 1.0.0
	 */
	public static function get_bearer_token( WP_REST_Request $request ): ?string {
		$auth_header = $request->get_header( 'authorization' );

		if ( empty( $auth_header ) ) {
			return null;
		}

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Create a permission callback for REST API endpoints.
	 *
	 * This returns a callback function that can be used as a permission_callback
	 * in REST API route registration.
	 *
	 * @return callable The permission callback.
	 * @since 1.0.0
	 */
	public static function permission_callback(): callable {
		return function ( WP_REST_Request $request ) {
			// First try OAuth token validation.
			$user = self::validate_request( $request );

			if ( ! is_wp_error( $user ) ) {
				// Set the current user for the request.
				wp_set_current_user( $user->ID );
				return true;
			}

			// If no OAuth token, fall back to default WordPress authentication.
			if ( is_user_logged_in() ) {
				return true;
			}

			return $user; // Return the WP_Error.
		};
	}

	/**
	 * Create a permission callback that requires specific scopes.
	 *
	 * @param array<int, string> $required_scopes The scopes required for access.
	 *
	 * @return callable The permission callback.
	 * @since 1.0.0
	 */
	public static function require_scopes( array $required_scopes ): callable {
		return function ( WP_REST_Request $request ) use ( $required_scopes ) {
			$metadata = self::get_token_metadata( $request );

			if ( is_wp_error( $metadata ) ) {
				return $metadata;
			}

			$token_scopes = $metadata['scopes'] ?? [];

			foreach ( $required_scopes as $scope ) {
				if ( ! in_array( $scope, $token_scopes, true ) ) {
					return new WP_Error(
						'oauth_insufficient_scope',
						sprintf(
							/* translators: %s: Required scope */
							__( 'This action requires the "%s" scope.', 'albert-ai-butler' ),
							$scope
						),
						[ 'status' => 403 ]
					);
				}
			}

			// Set the current user.
			$user = get_user_by( 'id', $metadata['user_id'] );
			if ( $user ) {
				wp_set_current_user( $user->ID );
			}

			return true;
		};
	}
}
