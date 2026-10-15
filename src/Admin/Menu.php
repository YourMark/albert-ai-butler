<?php
/**
 * Albert admin menu structure and page navigation.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * Owns the shape of the Albert admin menu, and renders the page navigation.
 *
 * Two jobs that belong together because both answer "what pages does Albert
 * have, and in what order":
 *
 * 1. **Menu order.** Each screen registers its submenu on `admin_menu` at the
 *    priority named here, so ordering is declared in one place instead of being
 *    an accident of which class happened to call `add_submenu_page()` first.
 *    The gaps are deliberate: they are where later screens land without
 *    renumbering the ones already shipped.
 * 2. **The page navigation.** WordPress's own submenu in the sidebar stays
 *    exactly as it is — it is the native path and users expect it. This adds a
 *    second, page-level row so you stay oriented once you are inside Albert,
 *    which the sidebar cannot do when the menu is collapsed or the screen is
 *    narrow.
 *
 * @since 1.4.0
 */
class Menu implements Hookable {

	/**
	 * Parent menu slug every Albert screen hangs off.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const PARENT_SLUG = 'albert';

	/**
	 * `admin_menu` priority for each screen, in display order.
	 *
	 * Add-ons registering their own page should use {@see self::POSITION_ADDONS}
	 * or later, so a core screen added in a future release cannot be pushed
	 * below third-party entries.
	 *
	 * @since 1.4.0
	 */
	public const POSITION_DASHBOARD   = 9;
	public const POSITION_ABILITIES   = 10;
	public const POSITION_SKILLS      = 11;
	public const POSITION_CONTEXT     = 12;
	public const POSITION_CONNECTIONS = 13;
	public const POSITION_APPROVALS   = 14;
	public const POSITION_ADDONS      = 15;
	public const POSITION_SETTINGS    = 20;

	/**
	 * Register the navigation renderer.
	 *
	 * `in_admin_header` fires after the admin header opens but before
	 * `#wpbody-content` renders the screen's own markup. That is what lets the
	 * strip run edge to edge without negative margins fighting `.wrap`'s
	 * gutters — the alternative the design brief warned could "fail on contact
	 * with core".
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'in_admin_header', [ $this, 'render_navigation' ] );
	}

	/**
	 * Whether the current screen belongs to Albert.
	 *
	 * Matched on the screen id's PREFIX, because WordPress builds a submenu
	 * screen id as `{parent_slug}_page_{own_slug}`. The prefix is therefore the
	 * parent, and everything after it is the page's own slug.
	 *
	 * Two failure modes this avoids, both of which a substring search hits:
	 *
	 *  - An add-on page registered under Albert with a slug of its own —
	 *    `my-addon-settings`, the example in our extension docs — has the id
	 *    `albert_page_my-addon-settings`. Searching for `_page_albert` finds no
	 *    second "albert" and the page silently loses the navigation and the
	 *    shared stylesheet, which is exactly what they exist to provide.
	 *  - An unrelated plugin whose own slug starts with "albert"
	 *    (`tools_page_albert-tunnel`) contains `_page_albert` and would have
	 *    Albert's navigation injected into a page that is nothing to do with us.
	 *
	 * `WP_Screen` has no `parent_slug` property, and `parent_base` /
	 * `parent_file` are populated from the menu globals rather than the screen
	 * itself, so they are unset in contexts that build a screen directly. The id
	 * is the one thing always present.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function is_albert_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen === null ) {
			return false;
		}

		return $screen->id === 'toplevel_page_' . self::PARENT_SLUG
			|| str_starts_with( $screen->id, self::PARENT_SLUG . '_page_' );
	}

	/**
	 * The navigation items, in order, filtered for the current user.
	 *
	 * Read from the registered submenu rather than a hardcoded list, so an
	 * add-on page appears here for free and a page the user cannot access never
	 * appears at all.
	 *
	 * @return array<int, array{slug: string, label: string, url: string}>
	 * @since 1.4.0
	 */
	public static function items(): array {
		global $submenu;

		$entries = $submenu[ self::PARENT_SLUG ] ?? [];

		if ( ! is_array( $entries ) ) {
			return [];
		}

		$items = [];

		foreach ( $entries as $entry ) {
			// WordPress submenu shape: [ 0 => menu title, 1 => capability, 2 => slug ].
			if ( ! is_array( $entry ) || ! isset( $entry[0], $entry[1], $entry[2] ) ) {
				continue;
			}

			if ( ! current_user_can( (string) $entry[1] ) ) {
				continue;
			}

			$slug = (string) $entry[2];

			$items[] = [
				'slug'  => $slug,
				'label' => wp_strip_all_tags( (string) $entry[0] ),
				'url'   => menu_page_url( $slug, false ),
			];
		}

		return $items;
	}

	/**
	 * Render the page navigation above the screen content.
	 *
	 * Real links with `aria-current="page"`, not `role="tablist"`. Each entry is
	 * a separate admin page load, and tab semantics promise something else
	 * entirely — in-page panels and arrow-key navigation. Announcing tabs and
	 * then navigating away is a worse experience for a screen-reader user than
	 * plain links, which is exactly what these are.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function render_navigation(): void {
		if ( ! self::is_albert_screen() ) {
			return;
		}

		$items = self::items();

		// One item is not a navigation; it is a label pretending to be a choice.
		if ( count( $items ) < 2 ) {
			return;
		}

		$current = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen identification, no state change.

		?>
		<nav class="albert-nav" aria-label="<?php esc_attr_e( 'Albert', 'albert-ai-butler' ); ?>">
			<div class="albert-nav__inner">
				<span class="albert-nav__brand"><?php esc_html_e( 'Albert', 'albert-ai-butler' ); ?></span>
				<ul class="albert-nav__list">
					<?php foreach ( $items as $item ) : ?>
						<?php $is_current = ( $item['slug'] === $current ); ?>
						<li class="albert-nav__item">
							<a
								class="albert-nav__link"
								href="<?php echo esc_url( $item['url'] ); ?>"
								<?php echo $is_current ? 'aria-current="page"' : ''; ?>
							><?php echo esc_html( $item['label'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</nav>
		<?php
	}
}
