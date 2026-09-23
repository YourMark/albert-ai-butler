<?php
/**
 * Safe-mode approval link builder.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of the approvals screen's slug and its deep links.
 *
 * The domain owns the slug because the domain has to link to it — the "awaiting
 * approval" response an assistant receives carries a deep link to the exact
 * staged action. The admin page ({@see \Albert\Admin\Approvals}) consumes
 * {@see self::PAGE_SLUG} from here rather than the other way round, so nothing
 * in the request-time interception path depends on the admin namespace.
 *
 * A deep link is a URL to a page, never an approval token: following it approves
 * nothing. Approval is a capability-checked, nonce-protected action on the
 * screen it leads to.
 *
 * @since 1.5.0
 */
class ApprovalUrl {

	/**
	 * The approvals admin page slug.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const PAGE_SLUG = 'albert-approvals';

	/**
	 * The approvals queue URL.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function queue(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * A deep link to one staged action within the queue.
	 *
	 * @param string $action_id The action's public reference.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function for_action( string $action_id ): string {
		return add_query_arg( 'pending', rawurlencode( $action_id ), self::queue() );
	}
}
