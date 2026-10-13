<?php
/**
 * Who and what a recording window is for.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.5.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * The call a window records: which ability, as whom, through which connection.
 *
 * @since 1.5.0
 */
final class WindowContext {

	/**
	 * What opened the window: an MCP tool call, a safe-mode approval, or an
	 * upload-link redemption.
	 *
	 * @var string
	 */
	public const SOURCE_MCP      = 'mcp';
	public const SOURCE_APPROVAL = 'approval';
	public const SOURCE_UPLOAD   = 'upload_link';

	/**
	 * Build a context.
	 *
	 * @param string      $source            One of the SOURCE_ constants.
	 * @param string      $ability_name      The ability being run.
	 * @param int         $user_id           The user it runs as.
	 * @param string      $client_id         The OAuth client of the connection.
	 * @param string|null $client_name       The connection's name, snapshotted.
	 * @param int|null    $pending_action_id The safe-mode row an approved run belongs to.
	 * @param string|null $session_id        The MCP session, to scope a chat's own changes.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $ability_name,
		public readonly int $user_id,
		public readonly string $client_id,
		public readonly ?string $client_name = null,
		public readonly ?int $pending_action_id = null,
		public readonly ?string $session_id = null,
	) {}
}
