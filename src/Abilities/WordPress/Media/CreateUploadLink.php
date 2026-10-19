<?php
/**
 * Create Upload Link Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.4.0
 */

namespace Albert\Abilities\WordPress\Media;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use Albert\Media\UploadLinks\UploadLinkService;
use WP_Error;

/**
 * Mints a short-lived, single-use HTTP endpoint an assistant can PUT/POST
 * bytes to directly, for when it has the file itself rather than a URL to
 * fetch — avoiding the base64 size penalty of pushing bytes through MCP.
 *
 * @since 1.4.0
 */
class CreateUploadLink extends BaseAbility {

	/**
	 * The upload link service.
	 *
	 * @since 1.4.0
	 * @var UploadLinkService
	 */
	private UploadLinkService $links;

	/**
	 * Constructor.
	 *
	 * @param UploadLinkService|null $links Optional service override (tests).
	 *
	 * @since 1.4.0
	 */
	public function __construct( ?UploadLinkService $links = null ) {
		$this->links = $links ?? new UploadLinkService();

		$this->id          = 'albert/create-upload-link';
		$this->label       = __( 'Create Upload Link', 'albert-ai-butler' );
		$this->description = __( 'Mint a short-lived, single-use link that accepts an uploaded file and adds it to the media library.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		// The token is the credential. The caller needs it; the execution log
		// does not, and would keep it in the clear long after it expired.
		$this->sensitive_output_keys = [ 'upload_token', 'curl_example' ];

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::action(
				'Use this when you have the file\'s bytes rather than a URL; otherwise `albert/upload-media` is simpler. '
				. 'The returned `upload_token` must be sent in the `token_header` header, never in the URL: URLs end up '
				. 'in logs and referrers. Send the file either as POST with a multipart `file` field, or as PUT with the '
				. 'raw bytes as the body plus a `filename` query parameter (the raw body carries no filename of its own, '
				. 'and one with a real extension is required). The link expires in minutes and works exactly once.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> Input schema.
	 * @since 1.4.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'accepted_types' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Optional MIME types to narrow the upload to (e.g. ["image/jpeg", "image/png"]). Defaults to everything the current user is allowed to upload.',
					'default'     => [],
				],
				'max_bytes'      => [
					'type'        => 'integer',
					'description' => 'Optional byte cap for the upload. Defaults to a conservative cap; always limited to what the server itself accepts.',
					'default'     => 0,
				],
				'post_id'        => [
					'type'        => 'integer',
					'description' => 'Optional post ID to attach the resulting media item to.',
					'default'     => 0,
				],
			],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.4.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'upload_url'     => [
					'type'        => 'string',
					'description' => 'The endpoint to send the file to.',
				],
				'upload_token'   => [
					'type'        => 'string',
					'description' => 'Single-use credential. Send it in the token_header header, never as part of the URL.',
				],
				'token_header'   => [
					'type'        => 'string',
					'description' => 'The header name to send upload_token in.',
				],
				'methods'        => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'HTTP methods the endpoint accepts. POST takes a multipart "file" field; PUT takes the raw bytes as the body and needs a "filename" query parameter.',
				],
				'expires_at'     => [
					'type'        => 'string',
					'description' => 'UTC datetime the link stops working.',
				],
				'max_bytes'      => [
					'type'        => 'integer',
					'description' => 'The byte cap enforced on the upload.',
				],
				'accepted_types' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'MIME types this link will accept.',
				],
				'post_id'        => [
					'type'        => 'integer',
					'description' => 'The post the resulting media item will be attached to, if any.',
				],
				'curl_example'   => [
					'type'        => 'string',
					'description' => 'A ready-to-run curl command that redeems the link.',
				],
			],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.4.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( UploadLinkService::REQUIRED_CAPABILITY );
	}

	/**
	 * Execute the ability — mint an upload link.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error Link data on success, WP_Error on failure.
	 * @since 1.4.0
	 */
	public function execute( array $args ): array|WP_Error {
		return $this->links->mint( get_current_user_id(), $args );
	}
}
