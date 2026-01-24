<?php
/**
 * OAuth Refresh Token Entity
 *
 * @package    AIBridge
 * @subpackage OAuth\Entities
 * @since      1.0.0
 */

namespace AIBridge\OAuth\Entities;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * RefreshTokenEntity class
 *
 * Represents an OAuth 2.0 refresh token.
 *
 * @since 1.0.0
 */
class RefreshTokenEntity implements RefreshTokenEntityInterface {

	use EntityTrait;
	use RefreshTokenTrait;
}
