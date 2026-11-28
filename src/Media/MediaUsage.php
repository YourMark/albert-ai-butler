<?php
/**
 * Where a media file is used
 *
 * @package Albert
 * @subpackage Media
 * @since      1.7.0
 */

namespace Albert\Media;

defined( 'ABSPATH' ) || exit;

use Albert\Blocks\BlockReader;
use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * Finds each place a media file appears, with what surrounds it there.
 *
 * Good alt text depends on the place: the same image is decorative in one
 * spot, informative in another, and the description of a link in a third.
 * The media library's alt is only a default; a block keeps its own, so an
 * assistant writing alt text needs each placement and its context, not the
 * file alone.
 *
 * Found by a text search for the ways blocks and the classic editor refer to
 * an attachment, then confirmed by parsing, so a match on `"id":12` never
 * counts for attachment 123. Only content the current user may read.
 *
 * @since 1.7.0
 */
class MediaUsage {

	/**
	 * Placements returned at most.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * Characters of surrounding text kept per side.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	private const CONTEXT_CHARS = 300;

	/**
	 * Block attributes that hold an attachment id, per block.
	 *
	 * @since 1.7.0
	 * @var array<string, string>
	 */
	private const ID_ATTRIBUTE = [
		'core/image'      => 'id',
		'core/cover'      => 'id',
		'core/media-text' => 'mediaId',
	];

	/**
	 * Every placement of one attachment, up to {@see self::LIMIT}.
	 *
	 * @param int $attachment_id The media file.
	 *
	 * @return array{placements: list<array<string, mixed>>, more: bool}
	 * @since 1.7.0
	 */
	public static function of( int $attachment_id ): array {
		$placements = [];

		foreach ( self::featured_in( $attachment_id ) as $post ) {
			$placements[] = self::base( $post ) + [
				'kind' => 'featured_image',
				'note' => 'Themes show the media library alt text for a featured image.',
			];
		}

		$reader = new BlockReader();
		foreach ( self::candidates( $attachment_id ) as $post ) {
			$tree = $reader->read( $post->post_content );
			array_push( $placements, ...self::in_blocks( $tree, $attachment_id, $post, [] ) );
		}

		return [
			'placements' => array_slice( $placements, 0, self::LIMIT ),
			'more'       => count( $placements ) > self::LIMIT,
		];
	}

	/**
	 * Placements in one block list, depth first, each with its neighbours' text.
	 *
	 * @param array<int, array<string, mixed>> $blocks        Shaped blocks ({@see BlockReader}).
	 * @param int                              $attachment_id The media file.
	 * @param WP_Post                          $post          The post they belong to.
	 * @param string[]                         $headings      Headings above this list, nearest last.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.7.0
	 */
	private static function in_blocks( array $blocks, int $attachment_id, WP_Post $post, array $headings ): array {
		$found = [];

		foreach ( $blocks as $index => $block ) {
			$name = $block['name'];

			if ( $name === 'core/heading' && trim( (string) $block['plaintext'] ) !== '' ) {
				$headings[] = trim( (string) $block['plaintext'] );
			}

			$html = (string) ( $block['html'] ?? '' );
			$hit  = isset( self::ID_ATTRIBUTE[ $name ] )
				? (int) ( $block['attributes'][ self::ID_ATTRIBUTE[ $name ] ] ?? 0 ) === $attachment_id
				: ( $name === null && preg_match( '/\bwp-image-' . $attachment_id . '\b/', $html ) === 1 );

			if ( $hit ) {
				$found[] = self::base( $post ) + [
					'kind'    => $name === null ? 'classic' : 'block',
					'block'   => $name,
					'path'    => $block['path'],
					'heading' => $headings === [] ? '' : end( $headings ),
					'before'  => self::text_near( $blocks, $index, -1 ),
					'after'   => self::text_near( $blocks, $index, 1 ),
				] + self::image_details( $html, $attachment_id, $block['attributes'] );
			}

			if ( $block['innerBlocks'] !== [] ) {
				array_push( $found, ...self::in_blocks( $block['innerBlocks'], $attachment_id, $post, $headings ) );
			}
		}

		return $found;
	}

	/**
	 * The alt text, link, caption and decorative flag of the image in one block's markup.
	 *
	 * @param string               $html          The block's saved markup.
	 * @param int                  $attachment_id The media file, for classic content holding several images.
	 * @param array<string, mixed> $attributes    The block's attributes.
	 *
	 * @return array{alt: string, decorative: bool, link: string, caption: string}
	 * @since 1.7.0
	 */
	private static function image_details( string $html, int $attachment_id, array $attributes ): array {
		// Classic content can hold several images; only this file's counts there.
		$classic = ! isset( $attributes['id'] ) && ! isset( $attributes['mediaId'] );
		$alt     = '';
		$link    = '';
		$open_a  = '';
		$tags    = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
			$tag = $tags->get_tag();
			if ( $tag === 'A' ) {
				$open_a = $tags->is_tag_closer() ? '' : (string) $tags->get_attribute( 'href' );
				continue;
			}
			if ( $tag !== 'IMG' || ( $classic && preg_match( '/\bwp-image-' . $attachment_id . '\b/', (string) $tags->get_attribute( 'class' ) ) !== 1 ) ) {
				continue;
			}
			$alt  = (string) $tags->get_attribute( 'alt' );
			$link = $open_a;
			break;
		}

		if ( $link === '' && is_string( $attributes['href'] ?? null ) ) {
			$link = $attributes['href'];
		}

		$caption = preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#s', $html, $match ) === 1 ? trim( wp_strip_all_tags( $match[1] ) ) : '';

		return [
			'alt'        => $alt,
			'decorative' => ! empty( $attributes['isDecorative'] ),
			'link'       => $link,
			'caption'    => $caption,
		];
	}

	/**
	 * The nearest sibling with text on one side, cut to {@see self::CONTEXT_CHARS}.
	 *
	 * @param array<int, array<string, mixed>> $blocks    The sibling list.
	 * @param int                              $index     The image block's position.
	 * @param int                              $direction -1 for before, 1 for after.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	private static function text_near( array $blocks, int $index, int $direction ): string {
		for ( $i = $index + $direction; isset( $blocks[ $i ] ); $i += $direction ) {
			$text = trim( BlockReader::plaintext_of( [ $blocks[ $i ] ] ) );
			if ( $text !== '' ) {
				return $direction < 0 ? mb_substr( $text, -self::CONTEXT_CHARS ) : mb_substr( $text, 0, self::CONTEXT_CHARS );
			}
		}

		return '';
	}

	/**
	 * What every placement says about the content it is in.
	 *
	 * @param WP_Post $post The post.
	 *
	 * @return array{post_id: int, post_type: string, title: string, status: string}
	 * @since 1.7.0
	 */
	private static function base( WP_Post $post ): array {
		return [
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
		];
	}

	/**
	 * Readable content whose text may refer to the attachment.
	 *
	 * @param int $attachment_id The media file.
	 *
	 * @return list<WP_Post>
	 * @since 1.7.0
	 */
	private static function candidates( int $attachment_id ): array {
		global $wpdb;

		$like = [
			'%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%',
			'%' . $wpdb->esc_like( '"id":' . $attachment_id ) . '%',
			'%' . $wpdb->esc_like( '"mediaId":' . $attachment_id ) . '%',
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A content search core has no API for; results are confirmed by parsing.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type NOT IN ( 'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'albert_skill' ) AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_modified DESC LIMIT 100",
				$wpdb->posts,
				$like[0],
				$like[1],
				$like[2]
			)
		);

		return self::readable( $ids );
	}

	/**
	 * Readable content with the attachment as its featured image.
	 *
	 * @param int $attachment_id The media file.
	 *
	 * @return list<WP_Post>
	 * @since 1.7.0
	 */
	private static function featured_in( int $attachment_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed meta lookup.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s LIMIT 100', $wpdb->postmeta, '_thumbnail_id', (string) $attachment_id ) );

		return array_values(
			array_filter(
				self::readable( $ids ),
				static fn( WP_Post $post ): bool => ! in_array( $post->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) && $post->post_type !== 'revision'
			)
		);
	}

	/**
	 * The posts the current user may read.
	 *
	 * @param array<int, mixed> $ids Post ids.
	 *
	 * @return list<WP_Post>
	 * @since 1.7.0
	 */
	private static function readable( array $ids ): array {
		$posts = [];
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof WP_Post && current_user_can( 'read_post', $post->ID ) ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}
}
