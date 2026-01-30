<?php
/**
 * OAuth Authorization Code Entity
 *
 * @package Albert
 * @subpackage OAuth\Entities
 * @since      1.0.0
 */

namespace Albert\OAuth\Entities;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * AuthCodeEntity class
 *
 * Represents an OAuth 2.0 authorization code.
 *
 * @since 1.0.0
 */
class AuthCodeEntity implements AuthCodeEntityInterface {

	use AuthCodeTrait;
	use EntityTrait;
	use TokenEntityTrait;
}
