<?php
/**
 * Block Converter
 *
 * Converts HTML into Gutenberg block markup. Lightweight replacement for
 * alleyinteractive/wp-block-converter with zero external dependencies.
 *
 * @package Albert
 * @subpackage Utilities
 * @since      1.1.0
 */

namespace Albert\Utilities;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;

/**
 * Block Converter class.
 *
 * Parses an HTML string into a DOMDocument and converts each top-level
 * element into the corresponding Gutenberg block comment-delimited markup.
 *
 * Usage:
 *   $blocks = ( new BlockConverter( $html ) )->convert();
 *
 * @since 1.1.0
 */
class BlockConverter {

	/**
	 * IDs of attachments created during image sideloading.
	 *
	 * @var int[]
	 */
	protected array $created_attachment_ids = [];

	/**
	 * Whether to sideload remote images into the media library.
	 *
	 * @var bool
	 */
	protected bool $sideload_images;

	/**
	 * Constructor.
	 *
	 * @param string $html            The HTML to convert.
	 * @param bool   $sideload_images Whether to sideload remote images. Default false.
	 *
	 * @since 1.1.0
	 */
	public function __construct(
		protected string $html,
		bool $sideload_images = false
	) {
		$this->sideload_images = $sideload_images;
	}

	/**
	 * Convert the HTML into Gutenberg block markup.
	 *
	 * @return string Block-comment-delimited content.
	 *
	 * @since 1.1.0
	 */
	public function convert(): string {
		if ( empty( trim( $this->html ) ) ) {
			return '';
		}

		$this->created_attachment_ids = [];

		$nodes  = $this->parse_html( $this->html );
		$blocks = [];

		if ( ! $nodes instanceof DOMNodeList || $nodes->length === 0 ) {
			return '';
		}

		$body = $nodes->item( 0 );
		if ( ! $body || ! $body->hasChildNodes() ) {
			return '';
		}

		foreach ( $body->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// Skip plain text nodes at the top level.
			if ( $node->nodeName === '#text' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$text = trim( $node->nodeValue ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $text !== '' ) {
					$block = $this->render_block( 'paragraph', [], '<p>' . esc_html( $text ) . '</p>' );
					if ( $block !== null ) {
						$blocks[] = $block;
					}
				}
				continue;
			}

			// Skip comment nodes.
			if ( $node->nodeName === '#comment' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$block = $this->convert_node( $node );
			if ( $block !== null ) {
				$blocks[] = $this->minify_block( $block );
			}
		}

		$output = implode( "\n\n", $blocks );
		$output = $this->remove_empty_blocks( $output );

		return $output;
	}

	/**
	 * Get IDs of attachments created during image sideloading.
	 *
	 * @return int[] Attachment IDs.
	 *
	 * @since 1.1.0
	 */
	public function get_created_attachment_ids(): array {
		return $this->created_attachment_ids;
	}

	/**
	 * Assign a parent post to all attachments created during conversion.
	 *
	 * @param int $post_id The parent post ID.
	 *
	 * @since 1.1.0
	 */
	public function assign_parent_to_attachments( int $post_id ): void {
		foreach ( $this->created_attachment_ids as $attachment_id ) {
			wp_update_post(
				[
					'ID'          => $attachment_id,
					'post_parent' => $post_id,
				]
			);
		}
	}

	/**
	 * Convert a single DOM node into block markup.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup or null to skip.
	 *
	 * @since 1.1.0
	 */
	protected function convert_node( DOMNode $node ): ?string {
		$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return match ( $tag ) {
			'p', 'a', 'abbr', 'b', 'code', 'em', 'i', 'strong', 'sub', 'sup', 'span', 'u' => $this->paragraph( $node ),
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->heading( $node ),
			'ul'         => $this->unordered_list( $node ),
			'ol'         => $this->ordered_list( $node ),
			'img'        => $this->image( $node ),
			'figure'     => $this->figure( $node ),
			'blockquote' => $this->blockquote( $node ),
			'hr'         => $this->separator(),
			'br', 'cite', 'source' => null,
			default      => $this->html_block( $node ),
		};
	}

	/**
	 * Convert a paragraph-level element.
	 *
	 * Checks whether the text content is a standalone URL that can be
	 * converted to an embed block, otherwise wraps in a paragraph block.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function paragraph( DOMNode $node ): ?string {
		$text = trim( $node->textContent ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Check if the paragraph contains only a URL that can be oEmbedded.
		if ( ! empty( $text ) && filter_var( $text, FILTER_VALIDATE_URL ) !== false ) {
			$embed = $this->maybe_embed( $text );
			if ( $embed !== null ) {
				return $embed;
			}
		}

		$content = $this->get_node_html( $node );

		// Wrap non-paragraph inline elements in <p> tags.
		if ( strtolower( $node->nodeName ) !== 'p' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$content = '<p>' . $content . '</p>';
		}

		return $this->render_block( 'paragraph', [], $content );
	}

	/**
	 * Convert a heading element.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function heading( DOMNode $node ): ?string {
		$level   = absint( str_replace( 'h', '', strtolower( $node->nodeName ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$content = $this->get_node_html( $node );

		return $this->render_block( 'heading', [ 'level' => $level ], $content );
	}

	/**
	 * Convert an unordered list element.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function unordered_list( DOMNode $node ): ?string {
		return $this->render_block( 'list', [], $this->get_node_html( $node ) );
	}

	/**
	 * Convert an ordered list element.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function ordered_list( DOMNode $node ): ?string {
		return $this->render_block( 'list', [ 'ordered' => true ], $this->get_node_html( $node ) );
	}

	/**
	 * Convert a standalone <img> element into an image block.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function image( DOMNode $node ): ?string {
		if ( ! $node instanceof DOMElement ) {
			return null;
		}

		$this->maybe_sideload_image( $node );

		$img_html = $this->get_node_html( $node );
		$content  = '<figure class="wp-block-image">' . $img_html . '</figure>';

		return $this->render_block( 'image', [], $content );
	}

	/**
	 * Convert a <figure> element into an image block.
	 *
	 * Only converts figures that contain an image (with optional figcaption).
	 * Falls back to an HTML block for unsupported figure structures.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function figure( DOMNode $node ): ?string {
		if ( ! $this->is_supported_figure( $node ) ) {
			return $this->html_block( $node );
		}

		if ( $this->sideload_images ) {
			$this->sideload_child_images( $node );
		}

		if ( $node instanceof DOMElement ) {
			$node->setAttribute( 'class', 'wp-block-image' );
		}

		return $this->render_block( 'image', [], $this->get_node_html( $node ) );
	}

	/**
	 * Check if a <figure> element contains a supported image structure.
	 *
	 * Supported structures:
	 * - <figure><img></figure>
	 * - <figure><img><figcaption>...</figcaption></figure>
	 * - <figure><a><img></a></figure>
	 *
	 * @param DOMNode $node The figure node.
	 * @return bool Whether this figure is supported.
	 *
	 * @since 1.1.0
	 */
	protected function is_supported_figure( DOMNode $node ): bool {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}

		$images = $node->getElementsByTagName( 'img' );

		return $images->length > 0;
	}

	/**
	 * Convert a <blockquote> element into a quote block.
	 *
	 * Recursively converts child elements within the blockquote.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function blockquote( DOMNode $node ): ?string {
		$content = $this->convert_with_children( $node );

		return $this->render_block( 'quote', [], $content );
	}

	/**
	 * Convert an <hr> element into a separator block.
	 *
	 * @return string Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function separator(): string {
		return $this->render_block( 'separator', [], '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
	}

	/**
	 * Fallback handler: wrap any unsupported element in an HTML block.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string|null Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function html_block( DOMNode $node ): ?string {
		return $this->render_block( 'html', [], $this->get_node_html( $node ) );
	}

	/**
	 * Attempt to convert a URL into an embed block.
	 *
	 * Handles Twitter/X.com URL normalization, then checks if WordPress
	 * can oEmbed the URL. Returns null if no embed is available.
	 *
	 * @param string $url The URL to check.
	 * @return string|null Embed block markup or null.
	 *
	 * @since 1.1.0
	 */
	protected function maybe_embed( string $url ): ?string {
		// Normalize X.com URLs to Twitter for oEmbed compatibility.
		if ( str_contains( $url, '//x.com' ) || str_contains( $url, '//www.x.com' ) ) {
			$url = str_replace( 'x.com', 'twitter.com', $url );
		}

		// Check Instagram.
		if ( str_contains( $url, 'instagram.com' ) ) {
			return $this->build_embed_block( $url, 'rich', 'instagram' );
		}

		// Check Facebook.
		if ( str_contains( $url, 'facebook.com' ) ) {
			return $this->build_embed_block(
				$url,
				'rich',
				'embed-handler',
				[ 'previewable' => false ]
			);
		}

		// Generic oEmbed check.
		if ( function_exists( 'wp_oembed_get' ) && wp_oembed_get( $url ) !== false ) {
			return $this->build_oembed_block( $url );
		}

		return null;
	}

	/**
	 * Build an embed block from oEmbed data.
	 *
	 * Fetches oEmbed metadata to determine provider, type, and aspect ratio.
	 *
	 * @param string $url The URL to embed.
	 * @return string|null Embed block markup or null.
	 *
	 * @since 1.1.0
	 */
	protected function build_oembed_block( string $url ): ?string {
		$oembed = _wp_oembed_get_object();
		$data   = $oembed->get_data( $url, [] );

		if ( ! $data ) {
			return null;
		}

		$type          = $data->type ?? 'rich';
		$provider_slug = sanitize_title( $data->provider_name ?? '' );

		// Calculate aspect ratio.
		$aspect_ratio = '';
		$width        = $data->width ?? 0;
		$height       = $data->height ?? 0;

		if ( $width > 0 && $height > 0 ) {
			$ratio = round( $width / $height, 2 );
			if ( $ratio === 1.78 ) {
				$aspect_ratio = '16-9';
			} elseif ( $ratio === 1.33 ) {
				$aspect_ratio = '4-3';
			}
		}

		$extra_atts = [];

		if ( ! empty( $aspect_ratio ) ) {
			$extra_atts['className'] = sprintf(
				'wp-embed-aspect-%s wp-has-aspect-ratio',
				$aspect_ratio
			);
		}

		return $this->build_embed_block( $url, $type, $provider_slug, $extra_atts, $aspect_ratio );
	}

	/**
	 * Build the comment-delimited markup for an embed block.
	 *
	 * @param string $url           The embed URL.
	 * @param string $type          The embed type (video, photo, rich, etc.).
	 * @param string $provider_slug Provider name slug.
	 * @param array  $extra_atts    Extra block attributes.
	 * @param string $aspect_ratio  Aspect ratio class suffix (e.g. '16-9').
	 * @return string Embed block markup.
	 *
	 * @since 1.1.0
	 */
	protected function build_embed_block(
		string $url,
		string $type,
		string $provider_slug,
		array $extra_atts = [],
		string $aspect_ratio = ''
	): string {
		$atts = array_merge(
			[
				'url'              => $url,
				'type'             => $type,
				'providerNameSlug' => $provider_slug,
				'responsive'       => true,
			],
			$extra_atts
		);

		$aspect_class = $aspect_ratio ? ' ' . $aspect_ratio : '';

		$content = sprintf(
			'<figure class="wp-block-embed is-type-%s is-provider-%s wp-block-embed-%s%s"><div class="wp-block-embed__wrapper">
%s
</div></figure>',
			esc_attr( $type ),
			esc_attr( $provider_slug ),
			esc_attr( $provider_slug ),
			esc_attr( $aspect_class ),
			esc_url( $url )
		);

		return $this->render_block( 'embed', $atts, $content );
	}

	/**
	 * Render a Gutenberg block using WordPress core's comment-delimited format.
	 *
	 * @param string      $block_name Block name (without core/ prefix).
	 * @param array       $attributes Block attributes.
	 * @param string|null $content   Block inner content.
	 * @return string Block markup.
	 *
	 * @since 1.1.0
	 */
	protected function render_block( string $block_name, array $attributes = [], ?string $content = null ): string {
		return get_comment_delimited_block_content( $block_name, $attributes, $content );
	}

	/**
	 * Recursively convert children of a node and return the outer HTML
	 * with children replaced by their block equivalents.
	 *
	 * Used for elements like blockquote that need recursive conversion.
	 *
	 * @param DOMNode $node The parent node.
	 * @return string Converted HTML.
	 *
	 * @since 1.1.0
	 */
	protected function convert_with_children( DOMNode $node ): string {
		$children = '';

		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeName === '#text' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$children .= $child->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			if ( strtolower( $child->nodeName ) === 'cite' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$children .= trim( $this->get_node_html( $child ) );
				continue;
			}

			$child_block = $this->convert_node( $child );
			if ( $child_block !== null ) {
				$children .= $this->minify_block( $child_block );
			}
		}

		// Replace node content with converted children.
		$node->nodeValue = '__CHILDREN__'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$outer           = $this->get_node_html( $node );

		return str_replace( '__CHILDREN__', $children, $outer );
	}

	/**
	 * Parse an HTML string into a DOMNodeList.
	 *
	 * @param string $html The HTML to parse.
	 * @return DOMNodeList|false List of body elements.
	 *
	 * @since 1.1.0
	 */
	protected function parse_html( string $html ) {
		$dom    = new DOMDocument();
		$errors = libxml_use_internal_errors( true );

		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

		libxml_use_internal_errors( $errors );

		return $dom->getElementsByTagName( 'body' );
	}

	/**
	 * Serialize a DOM node back to HTML.
	 *
	 * @param DOMNode $node The DOM node.
	 * @return string HTML string.
	 *
	 * @since 1.1.0
	 */
	protected function get_node_html( DOMNode $node ): string {
		return $node->ownerDocument->saveHTML( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Collapse excessive whitespace in a block string.
	 *
	 * Embed blocks only collapse horizontal whitespace to preserve structure.
	 *
	 * @param string $block The block markup.
	 * @return string Minified block markup.
	 *
	 * @since 1.1.0
	 */
	protected function minify_block( string $block ): string {
		if ( str_contains( $block, 'wp-block-embed' ) ) {
			$pattern = '/(\h){2,}/s';
		} else {
			$pattern = '/(\s){2,}/s';
		}

		if ( preg_match( $pattern, $block ) === 1 ) {
			return preg_replace( $pattern, '', $block );
		}

		return $block;
	}

	/**
	 * Remove known empty block patterns from the output.
	 *
	 * @param string $html The converted block HTML.
	 * @return string Cleaned HTML.
	 *
	 * @since 1.1.0
	 */
	protected function remove_empty_blocks( string $html ): string {
		// Remove common empty block patterns.
		$empty_patterns = [
			'<!-- wp:html --><div></div><!-- /wp:html -->',
			'<!-- wp:paragraph --><p><br></p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>&nbsp;</p><!-- /wp:paragraph -->',
			'<!-- wp:heading {"level":1} --><h1></h1><!-- /wp:heading -->',
			'<!-- wp:heading {"level":2} --><h2></h2><!-- /wp:heading -->',
			'<!-- wp:heading {"level":3} --><h3></h3><!-- /wp:heading -->',
			'<!-- wp:heading {"level":4} --><h4></h4><!-- /wp:heading -->',
			'<!-- wp:heading {"level":5} --><h5></h5><!-- /wp:heading -->',
			'<!-- wp:heading {"level":6} --><h6></h6><!-- /wp:heading -->',
		];

		$html = str_replace( $empty_patterns, '', $html );

		// Remove empty paragraph blocks with optional whitespace.
		$html = preg_replace(
			'/(\<\!\-\- wp\:paragraph \-\-\>[\s\n\r]*?\<p\>[\s\n\r]*?\<\/p\>[\s\n\r]*?\<\!\-\- \/wp\:paragraph \-\-\>)/',
			'',
			$html
		);

		return trim( $html );
	}

	/**
	 * Sideload a single image element if sideloading is enabled.
	 *
	 * @param DOMElement $img_node The <img> element.
	 *
	 * @since 1.1.0
	 */
	protected function maybe_sideload_image( DOMElement $img_node ): void {
		if ( ! $this->sideload_images ) {
			return;
		}

		$data_srcset = $img_node->getAttribute( 'data-srcset' );
		$src         = ! empty( $data_srcset ) ? $data_srcset : $img_node->getAttribute( 'src' );
		if ( empty( $src ) ) {
			return;
		}

		$alt = $img_node->getAttribute( 'alt' );

		try {
			$local_url = $this->upload_image( $src, $alt );

			if ( $local_url ) {
				$img_node->setAttribute( 'src', $local_url );

				if ( $img_node->hasAttribute( 'srcset' ) ) {
					$img_node->removeAttribute( 'srcset' );
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Silently fail — the original src remains.
		}
	}

	/**
	 * Recursively sideload images within a parent node.
	 *
	 * @param DOMNode $node The parent node to scan for images.
	 *
	 * @since 1.1.0
	 */
	protected function sideload_child_images( DOMNode $node ): void {
		if ( ! $node->hasChildNodes() ) {
			return;
		}

		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child->nodeName === 'img' && $child instanceof DOMElement ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$this->maybe_sideload_image( $child );
			} elseif ( $child->hasChildNodes() ) {
				$this->sideload_child_images( $child );
			}
		}
	}

	/**
	 * Upload (sideload) a remote image into the WordPress media library.
	 *
	 * Checks for an existing attachment by original URL before uploading.
	 *
	 * @param string $src The image URL.
	 * @param string $alt The image alt text.
	 * @return string The local attachment URL, or empty string on failure.
	 *
	 * @since 1.1.0
	 */
	protected function upload_image( string $src, string $alt ): string {
		// Strip query string from image URL.
		$src = $this->sanitize_image_url( $src );

		if ( empty( $src ) ) {
			return '';
		}

		// Check if this image was already sideloaded.
		$existing = get_posts(
			[
				'fields'         => 'ids',
				'meta_key'       => '_albert_original_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $src, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status'    => 'any',
				'post_type'      => 'attachment',
				'posts_per_page' => 1,
			]
		);

		if ( ! empty( $existing ) ) {
			$attachment_id = (int) $existing[0];
			if ( ! in_array( $attachment_id, $this->created_attachment_ids, true ) ) {
				$this->created_attachment_ids[] = $attachment_id;
			}
			return (string) wp_get_attachment_url( $attachment_id );
		}

		// Require media handling functions.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $src, 0, '', 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return '';
		}

		$this->created_attachment_ids[] = (int) $attachment_id;

		// Store original URL for deduplication.
		update_post_meta( $attachment_id, '_albert_original_url', $src );

		// Set alt text.
		if ( ! empty( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return (string) wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Sanitize an image URL by stripping query string and fragment.
	 *
	 * @param string $url The image URL.
	 * @return string Sanitized URL (scheme://host:port/path).
	 *
	 * @since 1.1.0
	 */
	protected function sanitize_image_url( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		$port   = ! empty( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = $parts['path'] ?? '';

		if ( empty( $scheme ) || empty( $host ) || empty( $path ) ) {
			return '';
		}

		return sprintf( '%s://%s%s%s', $scheme, $host, $port, $path );
	}
}
