<?php
/**
 * Media file access, shared by the abilities that change one media file.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Media;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The permission check and lookup for an ability that acts on one media file by `id`.
 *
 * @since 1.6.0
 */
trait MediaFileAccess {

	/**
	 * Whether the current user may act on the media file named in the input.
	 *
	 * Asks core's meta capability for that file, so safe mode refuses another user's
	 * file at once instead of holding a call core would refuse after approval. Without
	 * input (listing abilities) only `upload_files` is checked. An ID that is not a media
	 * file passes, so execute() can answer `media_not_found`: core turns any error from
	 * a permission check into "does not have necessary permission", which would tell the
	 * assistant the wrong thing.
	 *
	 * @param mixed  $input      Ability input.
	 * @param string $capability Meta capability core checks for this action, e.g. `edit_post`.
	 * @param string $refusal    Message when the user may not act on this file.
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 * @since 1.6.0
	 */
	private function check_media_permission( mixed $input, string $capability, string $refusal ): bool|WP_Error {
		$allowed  = $this->require_capability( 'upload_files' );
		$media_id = is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;

		if ( $allowed !== true || $media_id === 0 || self::media_not_found( $media_id ) !== null ) {
			return $allowed;
		}

		if ( current_user_can( $capability, $media_id ) ) {
			return true;
		}

		return new WP_Error( 'media_permission_denied', $refusal, [ 'status' => 403 ] );
	}

	/**
	 * A `media_not_found` error when the ID is not a media file, null when it is.
	 *
	 * Checked before core's endpoint so a post ID is never sent to it.
	 *
	 * @param int $media_id Media ID.
	 * @return WP_Error|null
	 * @since 1.6.0
	 */
	private static function media_not_found( int $media_id ): ?WP_Error {
		$media = get_post( $media_id );

		if ( $media && $media->post_type === 'attachment' ) {
			return null;
		}

		return new WP_Error(
			'media_not_found',
			sprintf(
				/* translators: %d: Media ID */
				__( 'Media with ID %d not found.', 'albert-ai-butler' ),
				$media_id
			),
			[ 'status' => 404 ]
		);
	}
}
