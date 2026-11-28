<?php
/**
 * Admin Notices
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The one place every Albert screen renders its queued `add_settings_error()`
 * notices, so a confirmation or warning looks and behaves identically wherever
 * it appears.
 *
 * What this does that a bare `settings_errors()` call does not:
 *
 * **It stays where the screen put it.** `wp-admin/js/common.js` runs
 * `$( 'div.updated, div.error, div.notice' ).not( '.inline, .below-h2' )
 * .insertAfter( $headerEnd )` on load, which hoists any notice out of its
 * container and drops it after the page header. A first version of this class
 * wrapped `settings_errors()` and was silently defeated by that: the notice
 * rendered inside the wrapper server-side and core moved it out before anyone
 * saw it. Emitting the markup here, with `inline`, is what opts out of the
 * hoist.
 *
 * **No live region, deliberately.** A live region only announces content that
 * changes *after* the region is in the accessibility tree, and every notice here
 * is server-rendered, present at parse time, on the page a redirect landed on —
 * so `aria-live` here would assert behaviour the markup does not have.
 * Announcing a post-redirect confirmation needs focus moved to it, which is a
 * change to how the screens handle their own save flow rather than something
 * this class can do alone; until then Albert is exactly where core's
 * `settings_errors()` is, and says so.
 *
 * The markup is core's, from `wp_admin_notice()` (WP 6.4+), which is what
 * `settings_errors()` itself calls. An admin notice should look and behave like
 * every other WordPress admin notice, `is-dismissible` is wired by core's JS
 * wherever the node sits, and anything core changes about that markup arrives
 * here for free. All this class decides is *where* the notice goes; hand-rolling
 * the `<div>` would be one more copy of core's markup than anybody needs.
 *
 * @since 1.4.0
 */
class Notices {

	/**
	 * Render the queued notices for one settings-error group.
	 *
	 * @since 1.4.0
	 *
	 * @param string $group The `add_settings_error()` slug to render (e.g.
	 *                       `albert_settings`, `albert_connections`).
	 *
	 * @return void
	 */
	public static function render( string $group ): void {
		// Reads the transient the redirect left behind when `settings-updated`
		// is present, exactly as settings_errors() would.
		$errors = get_settings_errors( $group );

		if ( $errors === [] ) {
			return;
		}

		echo '<div class="albert-notices">';

		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) || empty( $error['message'] ) ) {
				continue;
			}

			// Core maps its own 'updated' alias onto the success style; do the
			// same so a caller can use either spelling.
			$type = isset( $error['type'] ) && is_string( $error['type'] ) ? $error['type'] : 'error';
			if ( $type === 'updated' ) {
				$type = 'success';
			}

			$code = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '';

			wp_admin_notice(
				'<strong>' . esc_html( (string) $error['message'] ) . '</strong>',
				[
					'id'                 => 'setting-error-' . sanitize_html_class( $code ),
					'type'               => $type,
					'dismissible'        => true,
					// `inline` is the whole reason this class exists: it is what
					// opts the notice out of core's hoist. `settings-error` keeps
					// the class settings_errors() would have emitted, so anything
					// selecting on it still finds these.
					'additional_classes' => [ 'settings-error', 'inline' ],
					'paragraph_wrap'     => true,
				]
			);
		}

		echo '</div>';
	}
}
