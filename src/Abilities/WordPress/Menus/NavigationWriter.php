<?php
/**
 * Saves item edits to a navigation menu.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use Albert\Blocks\BlockTreeEditor;
use WP_Error;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * What the menu item abilities share on a navigation menu: the link
 * attributes, the save through core's REST route, and the read-back.
 *
 * @since 1.6.0
 */
class NavigationWriter {

	/**
	 * A navigation menu's blocks.
	 *
	 * @param WP_Post $menu `wp_navigation` post.
	 *
	 * @return array<int, mixed>
	 * @since 1.6.0
	 */
	public static function tree( WP_Post $menu ): array {
		return array_values( parse_blocks( $menu->post_content ) );
	}

	/**
	 * The attributes of a new link, as the Site Editor writes them.
	 *
	 * A page or term link keeps its `id` and `kind`, so it follows the page when its slug changes.
	 *
	 * @param array<string, mixed> $args Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public static function link_attributes( array $args ): array|WP_Error {
		$type   = is_string( $args['item_type'] ?? null ) ? $args['item_type'] : 'custom';
		$object = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : '';
		$id     = (int) ( $args['object_id'] ?? 0 );

		switch ( $type ) {
			case 'post_type':
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post || ( $object !== '' && $post->post_type !== $object ) ) {
					return self::invalid_object( __( 'No page or post has that "object_id". Find it with albert/find-pages or albert/find-posts.', 'albert-ai-butler' ) );
				}
				$attrs    = [
					'type' => $post->post_type,
					'id'   => (int) $post->ID,
					'url'  => (string) get_permalink( $post ),
					'kind' => 'post-type',
				];
				$fallback = $post->post_title;
				break;

			case 'taxonomy':
				$term = get_term( $id, $object );
				if ( ! $term instanceof WP_Term ) {
					return self::invalid_object( __( 'No category or term has that "object_id". Find it with albert/find-terms.', 'albert-ai-butler' ) );
				}
				$link     = get_term_link( $term );
				$attrs    = [
					// The editor calls tags "tag", every other taxonomy by its name.
					'type' => $term->taxonomy === 'post_tag' ? 'tag' : $term->taxonomy,
					'id'   => (int) $term->term_id,
					'url'  => is_wp_error( $link ) ? '' : $link,
					'kind' => 'taxonomy',
				];
				$fallback = $term->name;
				break;

			case 'post_type_archive':
				$post_type = get_post_type_object( $object );
				$link      = $post_type === null ? false : get_post_type_archive_link( $post_type->name );
				if ( $post_type === null || $link === false ) {
					return new WP_Error(
						'albert_invalid_object_type',
						__( 'That post type has no archive page. Send the slug of a post type with an archive, such as "post".', 'albert-ai-butler' ),
						[ 'status' => 400 ]
					);
				}
				$attrs    = [
					'url'  => $link,
					'kind' => 'custom',
				];
				$fallback = (string) $post_type->labels->name;
				break;

			default:
				$attrs    = [
					'url'  => esc_url_raw( (string) ( $args['url'] ?? '' ) ),
					'kind' => 'custom',
				];
				$fallback = '';
		}

		$label = self::label( $args, $fallback );
		if ( is_wp_error( $label ) ) {
			return $label;
		}

		$attrs = [ 'label' => $label ] + $attrs;
		if ( ! empty( $args['open_in_new_tab'] ) ) {
			$attrs['opensInNewTab'] = true;
		}

		return $attrs;
	}

	/**
	 * The attribute changes an update asks for; null removes an attribute.
	 *
	 * @param array<string, mixed> $attrs The link's current attributes.
	 * @param array<string, mixed> $args  Ability input.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public static function changes( array $attrs, array $args ): array|WP_Error {
		$changes = [];

		if ( isset( $args['title'] ) ) {
			$label = self::label( $args, '' );
			if ( is_wp_error( $label ) ) {
				return $label;
			}
			$changes['label'] = $label;
		}

		if ( ! empty( $args['use_original_title'] ) ) {
			$original = self::linked_title( $attrs );
			if ( $original === null ) {
				return new WP_Error(
					'albert_invalid_use_original_title',
					__( 'This link points at an address, not at a page or term, so it has no title of its own to use. Send "title" instead.', 'albert-ai-butler' ),
					[ 'status' => 400 ]
				);
			}
			$changes['label'] = NavigationTree::label_markup( $original );
		}

		if ( isset( $args['url'] ) ) {
			// A new address replaces the linked page or term, as in the Site Editor.
			$changes += [
				'url'  => esc_url_raw( (string) $args['url'] ),
				'kind' => 'custom',
				'id'   => null,
				'type' => null,
			];
		}

		$text = [
			'attr_title'  => 'title',
			'description' => 'description',
		];
		foreach ( $text as $field => $attr ) {
			if ( isset( $args[ $field ] ) ) {
				$value            = $field === 'description' ? sanitize_textarea_field( (string) $args[ $field ] ) : sanitize_text_field( (string) $args[ $field ] );
				$changes[ $attr ] = $value === '' ? null : $value;
			}
		}

		if ( isset( $args['open_in_new_tab'] ) ) {
			$changes['opensInNewTab'] = $args['open_in_new_tab'] ? true : null;
		}

		return $changes;
	}

	/**
	 * Save a navigation menu's blocks through core's REST route and read the result back.
	 *
	 * Core's route checks the user's capabilities, slashes the content and
	 * keeps a revision; a direct `wp_update_post()` without `wp_slash()` would
	 * corrupt escaped attribute JSON.
	 *
	 * @param WP_Post              $menu    The menu.
	 * @param array<int, mixed>    $tree    Its new blocks.
	 * @param list<int>|null       $path    The edited item's path, null when it was removed.
	 * @param list<string>         $notices What the person should be told about the edit.
	 * @param array<string, mixed> $extra   More result fields.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public static function save( WP_Post $menu, array $tree, ?array $path, array $notices, array $extra = [] ): array|WP_Error {
		$id    = (int) $menu->ID;
		$saved = MenuSupport::rest( 'POST', '/wp/v2/navigation/' . $id, [ 'content' => serialize_blocks( $tree ) ], __( 'The navigation menu could not be saved.', 'albert-ai-butler' ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		clean_post_cache( $id );
		$stored = get_post( $id );
		$items  = $stored instanceof WP_Post ? NavigationTree::items( self::tree( $stored ) ) : [];

		if ( $items !== NavigationTree::items( $tree ) ) {
			return new WP_Error(
				'albert_menu_saved_differently',
				__( 'The navigation menu was saved, but it reads back differently from the change sent: WordPress altered it while saving. Call albert/view-menu to see what it holds now.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'menu_id'   => $id,
			'menu_type' => 'block',
		];

		if ( $path !== null ) {
			$item   = (array) ( ( new BlockTreeEditor() )->find( $tree, $path )['attrs'] ?? [] );
			$result = $result + [
				'path'            => $path,
				'title'           => NavigationTree::plain( (string) ( $item['label'] ?? '' ) ),
				'url'             => (string) ( $item['url'] ?? '' ),
				'open_in_new_tab' => ! empty( $item['opensInNewTab'] ),
			];
		}

		$result = $result + $extra + [ 'items' => $items ] + MenuSupport::navigation_visibility_fields( $id );

		if ( $notices !== [] ) {
			$result['notice'] = trim( implode( ' ', array_merge( $notices, [ (string) ( $result['notice'] ?? '' ) ] ) ) );
		}

		return $result;
	}

	/**
	 * A plain-text label from the input, or the linked item's title.
	 *
	 * @param array<string, mixed> $args    Ability input.
	 * @param string               $fallback The linked item's title.
	 *
	 * @return string|WP_Error Label markup.
	 * @since 1.6.0
	 */
	private static function label( array $args, string $fallback ): string|WP_Error {
		$label = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : $fallback;

		// Core renders nothing for a link without a label.
		if ( $label === '' ) {
			return new WP_Error(
				'albert_invalid_title',
				isset( $args['title'] )
					? __( 'The "title" is empty once HTML and extra spaces are removed. Send the label as plain text.', 'albert-ai-butler' )
					: __( 'The linked item has no title, and a navigation link without a label is not shown. Send "title".', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return NavigationTree::label_markup( $label );
	}

	/**
	 * The current title of the page or term a link points at.
	 *
	 * @param array<string, mixed> $attrs Link attributes.
	 *
	 * @return string|null Null for a custom link or a missing item.
	 * @since 1.6.0
	 */
	private static function linked_title( array $attrs ): ?string {
		$id = (int) ( $attrs['id'] ?? 0 );

		if ( $id > 0 && ( $attrs['kind'] ?? '' ) === 'post-type' ) {
			$post = get_post( $id );
			return $post instanceof WP_Post && $post->post_title !== '' ? $post->post_title : null;
		}

		if ( $id > 0 && ( $attrs['kind'] ?? '' ) === 'taxonomy' ) {
			$term = get_term( $id );
			return $term instanceof WP_Term ? $term->name : null;
		}

		return null;
	}

	/**
	 * An `object_id` that names nothing linkable.
	 *
	 * @param string $message What to do.
	 *
	 * @return WP_Error
	 * @since 1.6.0
	 */
	private static function invalid_object( string $message ): WP_Error {
		return new WP_Error( 'albert_invalid_object_id', $message, [ 'status' => 400 ] );
	}
}
