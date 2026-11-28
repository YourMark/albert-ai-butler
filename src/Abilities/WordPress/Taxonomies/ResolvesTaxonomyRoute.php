<?php
/**
 * Shared taxonomy route resolution for the term abilities.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Taxonomies
 * @since      1.4.0
 */

namespace Albert\Abilities\WordPress\Taxonomies;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Resolves a taxonomy slug to the REST route WordPress serves its terms on.
 *
 * FindTerms, CreateTerm, UpdateTerm and DeleteTerm all reach their taxonomy
 * through `rest_do_request()`, and each carried its own byte-identical copy of
 * this resolution. One trait instead of four copies, so the next correction
 * happens once.
 *
 * @since 1.4.0
 */
trait ResolvesTaxonomyRoute {

	/**
	 * Get the REST route for a taxonomy's terms.
	 *
	 * The answer is `rest_get_route_for_taxonomy_items()`. WordPress has
	 * resolved its own routes since 5.9, and this plugin requires 7.1, so
	 * asking core is both available and the only way to be right: it reads
	 * `show_in_rest`, `rest_namespace` and `rest_base` in the same breath the
	 * route registration does, and passes the result through the
	 * `rest_route_for_taxonomy_items` filter that a site uses to move a route.
	 * A hand-rolled copy is a second answer to a question that already has one,
	 * and it drifts.
	 *
	 * `rest_base` in particular is the route *name*, not an answer to "is this
	 * taxonomy in the REST API": `register_taxonomy()` leaves it `false` unless
	 * the caller sets one, and WordPress then serves the taxonomy under its own
	 * slug. A check built on it refuses every REST-enabled taxonomy without an
	 * explicit base, which is the common case — WooCommerce registers
	 * `product_cat` with `show_in_rest => true` and no `rest_base`.
	 *
	 * No slug aliases either, `post_tags => post_tag` included. A tool is
	 * reachable only through what it advertises, and quietly accepting a second
	 * spelling makes the advertised contract false: the next caller guesses
	 * `tags`, then `posttag`, and there is no version of that list that ends.
	 * The refusals below name the taxonomy and point at `find-taxonomies`, so a
	 * wrong guess costs one round trip and teaches the right slug.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 *
	 * @return string|WP_Error Route with a leading slash, or an error naming the taxonomy.
	 * @since 1.4.0
	 */
	private function get_taxonomy_route( string $taxonomy ): string|WP_Error {
		// Core returns '' for both "no such taxonomy" and "not in the REST API",
		// and the caller can act on one but not the other, so they are separated
		// here before core is asked.
		if ( ! get_taxonomy( $taxonomy ) ) {
			return new WP_Error(
				'invalid_taxonomy',
				sprintf(
					/* translators: 1: taxonomy slug that was requested, 2: the ability to call instead. */
					__( 'No taxonomy named "%1$s" is registered on this site. Use %2$s to list the ones that are.', 'albert-ai-butler' ),
					$taxonomy,
					'albert/find-taxonomies'
				),
				[ 'status' => 404 ]
			);
		}

		$route = rest_get_route_for_taxonomy_items( $taxonomy );

		if ( $route === '' ) {
			return new WP_Error(
				'taxonomy_not_rest_enabled',
				sprintf(
					/* translators: %s: taxonomy slug that was requested. */
					__( 'The "%s" taxonomy is registered on this site but is not exposed over the REST API, so Albert cannot read or change its terms.', 'albert-ai-butler' ),
					$taxonomy
				),
				[ 'status' => 400 ]
			);
		}

		return $route;
	}
}
