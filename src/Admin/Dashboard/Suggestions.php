<?php
/**
 * Dashboard prompt suggestions
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin\Dashboard;

defined( 'ABSPATH' ) || exit;

use Albert\Core\AbilitiesRegistry;
use Albert\Core\AbilitiesState;

/**
 * Things worth asking an assistant on this particular site.
 *
 * The hard part of Albert is not connecting it, it is the moment after: an
 * assistant is attached to the site and nobody knows what to say to it. No
 * other screen answers that, and unlike a count of abilities it cannot be
 * derived from the Abilities screen either.
 *
 * **Every suggestion is checked before it is shown.** A prompt names the
 * abilities it needs, and it is listed only when all of them are *available on
 * this site*: registered, and switched on. So the card never recommends
 * something that would fail. That is also what makes it site-specific for free:
 * a shop with the WooCommerce abilities enabled is offered order questions, and
 * a site without them never sees them.
 *
 * **Registered, not merely enabled**, and the distinction is the whole check.
 * `AbilitiesState` answers from a blocklist, so an ability that does not exist
 * on this site has never been switched off and reads as enabled. Asking it
 * alone put "Which products sold best last month?" at the top of the card on
 * every WordPress site in the world, shop or not: Albert only registers the
 * WooCommerce abilities when WooCommerce is active, so on a plain site
 * `albert/woo-find-orders` was simultaneously absent and "enabled".
 *
 * **Name the ability that does the work, not one that is merely nearby.** The
 * check is only as honest as the ids a prompt declares, and the top-sellers
 * prompt is the case that shows it: it named Free's `woo-find-orders` and
 * `woo-find-products`, which between them can list orders and list products
 * and cannot join the two, so it appeared on any shop and answered properly on
 * none. It names `albert-woocommerce/view-top-sellers` now. A Free default
 * naming an add-on's id is not a dependency on the add-on: `requires` is a
 * string checked against the registry, so without the add-on the prompt is
 * simply never listed, which is the whole mechanism working.
 *
 * Registered as data so an add-on contributes a prompt without touching markup.
 *
 * @since 1.4.0
 */
class Suggestions {

	/**
	 * How many to show. Enough to suggest range, few enough to read.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const LIMIT = 3;

	/**
	 * Prompts that work on this site right now.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{text: string, requires: array<int, string>}>
	 */
	public function all(): array {
		/**
		 * Filters the prompt suggestions offered on the Dashboard.
		 *
		 * Each entry is `[ 'text' => string, 'requires' => string[] ]`, where
		 * `requires` lists the ability ids the prompt depends on. An entry is
		 * shown only when every one of them is enabled, so a suggestion never
		 * sends somebody to an assistant that will refuse.
		 *
		 * Write them as a person would speak, not as a feature list.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array{text: string, requires: array<int, string>}> $prompts Registered prompts.
		 */
		$prompts = apply_filters( 'albert/dashboard/suggestions', $this->defaults() );

		if ( ! is_array( $prompts ) ) {
			return [];
		}

		// Fetched once, outside the filter: this is the raw registry, and asking
		// it per prompt would walk it several times over for one page render.
		$registered = AbilitiesRegistry::get_all_raw();

		$available = array_values(
			array_filter(
				$prompts,
				static function ( $prompt ) use ( $registered ): bool {
					if ( ! is_array( $prompt ) || empty( $prompt['text'] ) || ! is_string( $prompt['text'] ) ) {
						return false;
					}

					$requires = isset( $prompt['requires'] ) && is_array( $prompt['requires'] ) ? $prompt['requires'] : [];

					foreach ( $requires as $ability_id ) {
						$ability_id = (string) $ability_id;

						if ( ! isset( $registered[ $ability_id ] ) ) {
							return false;
						}

						if ( ! AbilitiesState::is_enabled( $ability_id ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);

		return array_slice( $available, 0, self::LIMIT );
	}

	/**
	 * The prompts Free ships.
	 *
	 * **A prompt may not presume a fact about the site.** "Find every page that
	 * still mentions our old address" reads well and is useless: it assumes
	 * there was a move, that the old address is still somewhere, and that the
	 * reader knows what it was. Somebody who tries it gets nothing back and
	 * learns that the card makes things up. Every prompt here asks a question
	 * that has a real answer on any site with the abilities it names, whether
	 * that answer is a list or "none".
	 *
	 * Ordered so the first one that survives filtering is the most
	 * characteristic thing this site can do: commerce on a shop, content
	 * everywhere else.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{text: string, requires: array<int, string>}>
	 */
	private function defaults(): array {
		return [
			[
				// Needs the WooCommerce add-on, not Free's own read-only
				// abilities. Ranking products by what actually sold means
				// reading the line items of every order in a period and summing
				// them, which is `view-top-sellers`; Free's `woo-find-orders`
				// and `woo-find-products` between them can list orders and list
				// products, and cannot join the two. Gated on the id that does
				// the work, so a shop running Free alone is not shown an example
				// that would come back thin.
				'text'     => __( 'Which products sold best last month?', 'albert-ai-butler' ),
				'requires' => [ 'albert-woocommerce/view-top-sellers' ],
			],
			[
				// The commerce prompt a shop on Free alone does get. Answerable
				// from `woo-find-orders` by itself: it filters on a date range
				// and returns each order's status and total.
				'text'     => __( 'How many orders came in this week, and what did they add up to?', 'albert-ai-butler' ),
				'requires' => [ 'albert/woo-find-orders' ],
			],
			[
				'text'     => __( 'Which posts have not been touched in over a year?', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-posts' ],
			],
			[
				'text'     => __( 'Give me an overview of the pages on this site and how they are organised.', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-pages' ],
			],
			// Third on a site with nothing switched off, and deliberately so:
			// the two above it are questions, and three questions in a row
			// suggest an assistant that can only read. This is the one that
			// shows it can make something. "Leave it as a draft" is part of the
			// example rather than decoration, because the first thing anybody
			// wants to know about a writing assistant is whether it publishes.
			[
				'text'     => __( 'Turn my three most recent posts into a round-up, and leave it as a draft.', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-posts', 'albert/create-post' ],
			],
			[
				'text'     => __( 'Which images are missing alt text? Suggest alt text for each, and add it once I agree.', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-media', 'albert/update-media' ],
			],
			[
				'text'     => __( 'Who has administrator access to this site?', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-users' ],
			],
			[
				'text'     => __( 'Summarise what this site is about.', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-pages' ],
			],
		];
	}
}
