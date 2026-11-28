<?php
/**
 * Who and what a recording window is for.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.7.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * The call a window records: which ability, as whom, through which connection.
 *
 * @since 1.7.0
 */
final class WindowContext {

	/**
	 * What opened the window: an MCP tool call, a safe-mode approval, an
	 * upload-link redemption, or a revert, which is recorded like any change.
	 *
	 * @var string
	 */
	public const SOURCE_MCP      = 'mcp';
	public const SOURCE_APPROVAL = 'approval';
	public const SOURCE_UPLOAD   = 'upload_link';
	public const SOURCE_REVERT   = 'revert';

	/**
	 * Longest note kept, in characters.
	 *
	 * @var int
	 */
	public const NOTE_LENGTH = 140;

	/**
	 * The assistant's note, sanitised and cut to {@see self::NOTE_LENGTH}.
	 *
	 * @var string|null
	 */
	public readonly ?string $note;

	/**
	 * What a revert window takes back: `[ { change_id, parts: [key] } ]`.
	 *
	 * @var array<int, array{change_id: int, parts: array<int, string>}>
	 */
	public readonly array $reverts;

	/**
	 * Build a context.
	 *
	 * @param string                           $source            One of the SOURCE_ constants.
	 * @param string                           $ability_name      The ability being run.
	 * @param int                              $user_id           The user it runs as.
	 * @param string                           $client_id         The OAuth client of the connection.
	 * @param string|null                      $client_name       The connection's name, snapshotted.
	 * @param int|null                         $pending_action_id The safe-mode row an approved run belongs to.
	 * @param string|null                      $session_id        The MCP session, to scope a chat's own changes.
	 * @param int|null                         $approved_by       Who approved the run, when safe mode held it.
	 * @param mixed                            $note              The assistant's one-line reason, as it sent it.
	 * @param array<int, array<string, mixed>> $reverts    For a revert: the changes and parts it takes back.
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $ability_name,
		public readonly int $user_id,
		public readonly string $client_id,
		public readonly ?string $client_name = null,
		public readonly ?int $pending_action_id = null,
		public readonly ?string $session_id = null,
		public readonly ?int $approved_by = null,
		$note = null,
		array $reverts = [],
	) {
		$this->note    = self::clean_note( $note );
		$this->reverts = self::clean_reverts( $reverts );
	}

	/**
	 * A note as stored: plain text, one line, at most {@see self::NOTE_LENGTH}
	 * characters. The assistant wrote it, so it is never trusted as markup.
	 *
	 * @param mixed $note The note as sent.
	 *
	 * @return string|null Null when there is none.
	 * @since 1.7.0
	 */
	public static function clean_note( $note ): ?string {
		if ( ! is_string( $note ) ) {
			return null;
		}

		$note = mb_substr( sanitize_text_field( $note ), 0, self::NOTE_LENGTH );

		return $note === '' ? null : $note;
	}

	/**
	 * Reverts in their stored shape, anything else dropped.
	 *
	 * @param array<int, mixed> $reverts As passed.
	 *
	 * @return array<int, array{change_id: int, parts: array<int, string>}>
	 */
	private static function clean_reverts( array $reverts ): array {
		$clean = [];

		foreach ( $reverts as $revert ) {
			$change_id = is_array( $revert ) ? (int) ( $revert['change_id'] ?? 0 ) : 0;
			if ( $change_id <= 0 ) {
				continue;
			}

			$parts   = is_array( $revert['parts'] ?? null ) ? $revert['parts'] : [];
			$clean[] = [
				'change_id' => $change_id,
				'parts'     => array_values( array_map( 'strval', array_filter( $parts, 'is_scalar' ) ) ),
			];
		}

		return $clean;
	}
}
