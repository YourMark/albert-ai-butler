<?php
/**
 * OAuth User Entity
 *
 * @package Albert
 * @subpackage OAuth\Entities
 * @since      1.0.0
 */

namespace Albert\OAuth\Entities;

use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * UserEntity class
 *
 * Represents a WordPress user in the OAuth 2.0 context.
 *
 * @since 1.0.0
 */
class UserEntity implements UserEntityInterface {

	/**
	 * The user identifier (WordPress user ID).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private int $identifier;

	/**
	 * Constructor.
	 *
	 * @param int $identifier The WordPress user ID.
	 *
	 * @since 1.0.0
	 */
	public function __construct( int $identifier ) {
		$this->identifier = $identifier;
	}

	/**
	 * Return the user's identifier.
	 *
	 * @return string The user identifier.
	 * @since 1.0.0
	 */
	public function getIdentifier(): string {
		return (string) $this->identifier;
	}
}
