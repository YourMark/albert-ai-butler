<?php
/**
 * OAuth Client Entity
 *
 * @package Albert
 * @subpackage OAuth\Entities
 * @since      1.0.0
 */

namespace Albert\OAuth\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * ClientEntity class
 *
 * Represents an OAuth 2.0 client application.
 *
 * @since 1.0.0
 */
class ClientEntity implements ClientEntityInterface {

	use ClientTrait;
	use EntityTrait;

	/**
	 * The WordPress user ID who created this client.
	 *
	 * @since 1.0.0
	 * @var int|null
	 */
	private ?int $user_id = null;

	/**
	 * The client secret (hashed).
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private ?string $client_secret = null;

	/**
	 * When the client was created.
	 *
	 * @since 1.0.0
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $created_at = null;

	/**
	 * Set the client's name.
	 *
	 * @param string $name The client name.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setName( string $name ): void {
		$this->name = $name;
	}

	/**
	 * Set the client's redirect URI.
	 *
	 * @param string|string[] $uri The redirect URI(s).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setRedirectUri( $uri ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property defined by league/oauth2-server trait.
		$this->redirectUri = $uri;
	}

	/**
	 * Set whether the client is confidential.
	 *
	 * @param bool $is_confidential Whether the client is confidential.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setConfidential( bool $is_confidential ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property defined by league/oauth2-server trait.
		$this->isConfidential = $is_confidential;
	}

	/**
	 * Set the WordPress user ID who created this client.
	 *
	 * @param int|null $user_id The user ID.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setUserId( ?int $user_id ): void {
		$this->user_id = $user_id;
	}

	/**
	 * Get the WordPress user ID who created this client.
	 *
	 * @return int|null The user ID.
	 * @since 1.0.0
	 */
	public function getUserId(): ?int {
		return $this->user_id;
	}

	/**
	 * Set the client secret (hashed).
	 *
	 * @param string|null $secret The hashed client secret.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setClientSecret( ?string $secret ): void {
		$this->client_secret = $secret;
	}

	/**
	 * Get the client secret (hashed).
	 *
	 * @return string|null The hashed client secret.
	 * @since 1.0.0
	 */
	public function getClientSecret(): ?string {
		return $this->client_secret;
	}

	/**
	 * Set the creation timestamp.
	 *
	 * @param \DateTimeImmutable|null $created_at The creation timestamp.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setCreatedAt( ?\DateTimeImmutable $created_at ): void {
		$this->created_at = $created_at;
	}

	/**
	 * Get the creation timestamp.
	 *
	 * @return \DateTimeImmutable|null The creation timestamp.
	 * @since 1.0.0
	 */
	public function getCreatedAt(): ?\DateTimeImmutable {
		return $this->created_at;
	}
}
