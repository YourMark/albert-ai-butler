<?php
/**
 * OAuth Client Entity
 *
 * @package Albert
 * @subpackage OAuth\Entities
 * @since      1.0.0
 */

namespace Albert\OAuth\Entities;

defined( 'ABSPATH' ) || exit;

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
	 * When the client was last used (a token was validated for it).
	 *
	 * @since 1.3.1
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $last_used_at = null;

	/**
	 * How the client was created (e.g. 'dcr'), or null when unknown.
	 *
	 * @since 1.3.1
	 * @var string|null
	 */
	private ?string $origin = null;

	/**
	 * The site owner's own name for this connection ("Mark's laptop").
	 *
	 * Separate from `name`, which the client chooses for itself and Albert must
	 * not overwrite: two people can both connect "Claude", and the only way to
	 * tell those rows apart on the Connections screen is a name the owner wrote.
	 *
	 * @since 1.4.0
	 * @var string|null
	 */
	private ?string $label = null;

	/**
	 * Who wrote the current label.
	 *
	 * A label is a display name one administrator writes onto somebody else's
	 * connection, on the very screen an owner uses to decide what is
	 * trustworthy. Recording the author is what keeps a misleading rename
	 * traceable on sight rather than only in a log nobody reads.
	 *
	 * @since 1.4.0
	 * @var int|null
	 */
	private ?int $label_set_by = null;

	/**
	 * When the current label was written.
	 *
	 * @since 1.4.0
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $label_set_at = null;

	/**
	 * The `home_url()` host this client was authorised against.
	 *
	 * Null on a client authorised before this was recorded, which is treated as
	 * "never suspended". See {@see \Albert\OAuth\Server\DomainGuard}.
	 *
	 * @since 1.4.0
	 * @var string|null
	 */
	private ?string $connect_host = null;

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

	/**
	 * Set the last-used timestamp.
	 *
	 * @param \DateTimeImmutable|null $last_used_at The last-used timestamp.
	 *
	 * @return void
	 * @since 1.3.1
	 */
	public function setLastUsedAt( ?\DateTimeImmutable $last_used_at ): void {
		$this->last_used_at = $last_used_at;
	}

	/**
	 * Get the last-used timestamp.
	 *
	 * @return \DateTimeImmutable|null The last-used timestamp.
	 * @since 1.3.1
	 */
	public function getLastUsedAt(): ?\DateTimeImmutable {
		return $this->last_used_at;
	}

	/**
	 * Set how the client was created.
	 *
	 * @param string|null $origin The origin (e.g. 'dcr').
	 *
	 * @return void
	 * @since 1.3.1
	 */
	public function setOrigin( ?string $origin ): void {
		$this->origin = $origin;
	}

	/**
	 * Get how the client was created.
	 *
	 * @return string|null The origin (e.g. 'dcr'), or null when unknown.
	 * @since 1.3.1
	 */
	public function getOrigin(): ?string {
		return $this->origin;
	}

	/**
	 * Set the site owner's own name for this connection.
	 *
	 * @param string|null $label The label, or null to clear it.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function setLabel( ?string $label ): void {
		$this->label = $label;
	}

	/**
	 * Get the site owner's own name for this connection.
	 *
	 * @return string|null The label, or null when none was set.
	 * @since 1.4.0
	 */
	public function getLabel(): ?string {
		return $this->label;
	}

	/**
	 * Set who wrote the current label.
	 *
	 * @param int|null $user_id The author's user ID, or null when unrecorded.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function setLabelSetBy( ?int $user_id ): void {
		$this->label_set_by = $user_id;
	}

	/**
	 * Get who wrote the current label.
	 *
	 * @return int|null The author's user ID, or null when unrecorded.
	 * @since 1.4.0
	 */
	public function getLabelSetBy(): ?int {
		return $this->label_set_by;
	}

	/**
	 * Set when the current label was written.
	 *
	 * @param \DateTimeImmutable|null $moment The moment, or null when unrecorded.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function setLabelSetAt( ?\DateTimeImmutable $moment ): void {
		$this->label_set_at = $moment;
	}

	/**
	 * Get when the current label was written.
	 *
	 * @return \DateTimeImmutable|null The moment, or null when unrecorded.
	 * @since 1.4.0
	 */
	public function getLabelSetAt(): ?\DateTimeImmutable {
		return $this->label_set_at;
	}

	/**
	 * Set the host this client was authorised against.
	 *
	 * @param string|null $connect_host The host, or null when unrecorded.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function setConnectHost( ?string $connect_host ): void {
		$this->connect_host = $connect_host;
	}

	/**
	 * Get the host this client was authorised against.
	 *
	 * @return string|null The host, or null when unrecorded.
	 * @since 1.4.0
	 */
	public function getConnectHost(): ?string {
		return $this->connect_host;
	}
}
