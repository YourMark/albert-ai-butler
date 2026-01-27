<?php
/**
 * OAuth Scope Entity
 *
 * @package Albert
 * @subpackage OAuth\Entities
 * @since      1.0.0
 */

namespace Albert\OAuth\Entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * ScopeEntity class
 *
 * Represents an OAuth 2.0 scope.
 * Note: This plugin uses full access based on user capabilities,
 * so scopes are minimal (just a default scope).
 *
 * @since 1.0.0
 */
class ScopeEntity implements ScopeEntityInterface {

	use EntityTrait;
	use ScopeTrait;

	/**
	 * Constructor.
	 *
	 * @param non-empty-string $identifier The scope identifier.
	 *
	 * @since 1.0.0
	 */
	public function __construct( string $identifier = 'default' ) {
		$this->setIdentifier( $identifier );
	}

	/**
	 * Serialize the scope to JSON.
	 *
	 * @return mixed Data for JSON serialization.
	 * @since 1.0.0
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->getIdentifier();
	}
}
