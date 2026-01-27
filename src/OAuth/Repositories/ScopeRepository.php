<?php
/**
 * OAuth Scope Repository
 *
 * @package Albert
 * @subpackage OAuth\Repositories
 * @since      1.0.0
 */

namespace Albert\OAuth\Repositories;

use Albert\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * ScopeRepository class
 *
 * Minimal scope implementation - this plugin uses user capabilities
 * instead of OAuth scopes for authorization.
 *
 * @since 1.0.0
 */
class ScopeRepository implements ScopeRepositoryInterface {

	/**
	 * Return information about a scope.
	 *
	 * @param string $identifier The scope identifier to search for.
	 *
	 * @return ScopeEntityInterface|null The scope entity or null.
	 * @since 1.0.0
	 */
	public function getScopeEntityByIdentifier( $identifier ): ?ScopeEntityInterface {
		// We only support a default scope.
		if ( $identifier === 'default' ) {
			return new ScopeEntity( 'default' );
		}

		return null;
	}

	/**
	 * Given a client, grant type and optional user identifier validate the set of scopes requested.
	 *
	 * @param ScopeEntityInterface[] $scopes          The scopes requested.
	 * @param string                 $grant_type      The grant type used.
	 * @param ClientEntityInterface  $client_entity   The client entity.
	 * @param string|null            $user_identifier The user identifier (optional).
	 * @param string|null            $auth_code_id    The auth code ID (optional).
	 *
	 * @return ScopeEntityInterface[] The validated scopes.
	 * @since 1.0.0
	 */
	public function finalizeScopes(
		array $scopes, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		string $grant_type, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		ClientEntityInterface $client_entity, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		?string $user_identifier = null, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		?string $auth_code_id = null // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	): array {
		// Always return the default scope.
		// Actual authorization is based on WordPress user capabilities.
		return [ new ScopeEntity( 'default' ) ];
	}
}
