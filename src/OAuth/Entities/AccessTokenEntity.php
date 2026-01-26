<?php
/**
 * OAuth Access Token Entity
 *
 * @package Albert
 * @subpackage OAuth\Entities
 * @since      1.0.0
 */

namespace Albert\OAuth\Entities;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * AccessTokenEntity class
 *
 * Represents an OAuth 2.0 access token.
 *
 * @since 1.0.0
 */
class AccessTokenEntity implements AccessTokenEntityInterface {

	use AccessTokenTrait;
	use EntityTrait;
	use TokenEntityTrait;
}
