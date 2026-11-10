<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * Provides lightweight implementations of WordPress core functions
 * used by the plugin. Only loaded when the real functions are not available
 * (i.e. in unit tests that run without a full WordPress environment).
 *
 * These are intentionally minimal stubs, not full WordPress re-implementations.
 *
 * @package Albert\Tests
 */

// -- Block serialisation (wp-includes/blocks.php) --

if ( ! function_exists( 'strip_core_block_namespace' ) ) {
	function strip_core_block_namespace( $block_name = null ) {
		if ( is_string( $block_name ) && str_starts_with( $block_name, 'core/' ) ) {
			return substr( $block_name, 5 );
		}
		return $block_name;
	}
}

if ( ! function_exists( 'serialize_block_attributes' ) ) {
	function serialize_block_attributes( $block_attributes ) {
		$encoded = json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$encoded = preg_replace( '/--/', '\\u002d\\u002d', $encoded );
		$encoded = preg_replace( '/</', '\\u003c', $encoded );
		$encoded = preg_replace( '/>/', '\\u003e', $encoded );
		$encoded = preg_replace( '/&/', '\\u0026', $encoded );
		$encoded = preg_replace( '/\\\\\"/', '\\u0022', $encoded );
		return $encoded;
	}
}

if ( ! function_exists( 'get_comment_delimited_block_content' ) ) {
	function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
		if ( is_null( $block_name ) ) {
			return $block_content;
		}

		$serialized_block_name = strip_core_block_namespace( $block_name );
		$serialized_attributes = empty( $block_attributes )
			? ''
			: serialize_block_attributes( $block_attributes ) . ' ';

		if ( empty( $block_content ) ) {
			return sprintf( '<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes );
		}

		return sprintf(
			'<!-- wp:%s %s-->%s<!-- /wp:%s -->',
			$serialized_block_name,
			$serialized_attributes,
			$block_content,
			$serialized_block_name
		);
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	/**
	 * Faithful port of wp-includes/blocks.php serialize_block().
	 *
	 * @param array $block Parsed block array.
	 * @return string
	 */
	function serialize_block( $block ) {
		$block_content = '';

		$index = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			$block_content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index++ ] );
		}

		if ( ! is_array( $block['attrs'] ) ) {
			$block['attrs'] = [];
		}

		return get_comment_delimited_block_content(
			$block['blockName'],
			$block['attrs'],
			$block_content
		);
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	/**
	 * Faithful port of wp-includes/blocks.php serialize_blocks().
	 *
	 * @param array[] $blocks Array of parsed block arrays.
	 * @return string
	 */
	function serialize_blocks( $blocks ) {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}
}

require_once __DIR__ . '/Unit/stubs/WP_Block_Supports.php';

if ( ! function_exists( 'parse_blocks' ) ) {
	// Load the real WP block parser classes (pure PHP, no WP deps).
	require_once __DIR__ . '/Unit/stubs/class-wp-block-parser.php';

	/**
	 * Faithful port of wp-includes/blocks.php parse_blocks() using the real
	 * WP_Block_Parser class copied into the test stubs.
	 *
	 * @param string $content Post content.
	 * @return array[]
	 */
	function parse_blocks( $content ) {
		$parser = new WP_Block_Parser();
		return $parser->parse( $content );
	}
}

if ( ! function_exists( 'do_shortcode' ) ) {
	/**
	 * Minimal stub for do_shortcode() — returns content unchanged.
	 *
	 * The unit suite has no registered shortcodes, so do_blocks() needs this only
	 * to leave content untouched the way WordPress would when none match.
	 *
	 * @param string $content Content possibly containing shortcodes.
	 * @return string
	 */
	function do_shortcode( $content ) {
		return (string) $content;
	}
}

if ( ! function_exists( 'do_blocks' ) ) {
	/**
	 * Minimal port of wp-includes/blocks.php do_blocks() for unit tests.
	 *
	 * Parses block markup with the real parser (already used by parse_blocks
	 * above) and renders each top-level block. Static blocks render to their
	 * inner content (inner blocks spliced into innerContent, mirroring core's
	 * render_block default path). There are no registered dynamic-block
	 * render callbacks in the unit suite, so a block's stored inner content is
	 * its rendered output — which is exactly what the read-ability `html`
	 * representation needs to assert against.
	 *
	 * @param string $content Post content (block markup).
	 * @return string Rendered HTML.
	 */
	function do_blocks( $content ) {
		$blocks = parse_blocks( $content );
		$output = '';

		foreach ( $blocks as $block ) {
			$output .= albert_test_render_block( $block );
		}

		// Mirror core: do_blocks() runs the rendered output through do_shortcode().
		return do_shortcode( $output );
	}

	/**
	 * Render a single parsed block to HTML (static-block path).
	 *
	 * Walks innerContent, substituting each null marker with the rendered inner
	 * block at the matching index — the same interleaving serialize_block() and
	 * core's frontend renderer use.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	function albert_test_render_block( $block ) {
		$inner_content = isset( $block['innerContent'] ) && is_array( $block['innerContent'] )
			? $block['innerContent']
			: [ $block['innerHTML'] ?? '' ];

		$html  = '';
		$index = 0;

		foreach ( $inner_content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$html .= $chunk;
			} else {
				$child = $block['innerBlocks'][ $index ] ?? null;
				++$index;
				if ( is_array( $child ) ) {
					$html .= albert_test_render_block( $child );
				}
			}
		}

		return $html;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal port of wp_strip_all_tags().
	 *
	 * @param string $text          Text to strip.
	 * @param bool   $remove_breaks Whether to collapse whitespace.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );

		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

// -- Escaping (wp-includes/formatting.php) --

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		// Simplified stub — real WP version does more validation.
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Minimal allowlist-based stub for wp_kses().
	 *
	 * Faithful enough for the block serializer tests: it preserves tags present
	 * in $allowed_html (keeping only their allowed attributes) and strips any tag
	 * not on the list — including <script>…</script> and its contents. Event
	 * handler attributes (on*) and javascript: URLs are dropped, mirroring the
	 * security guarantee the real wp_kses() provides.
	 *
	 * This is intentionally not a full HTML sanitiser; it covers the inline
	 * allowlist the serializer uses.
	 *
	 * @param string                            $content      Content to filter.
	 * @param array<string, array<string, bool>> $allowed_html Allowed tags → allowed attributes.
	 * @return string Sanitised HTML.
	 */
	function wp_kses( $content, $allowed_html ) {
		$content = (string) $content;

		// Drop <script>/<style> blocks entirely (tag + contents).
		$content = preg_replace( '@<(script|style)\b[^>]*>.*?</\\1>@is', '', $content ) ?? $content;

		// Process each tag: keep allowlisted ones (filtered attrs), strip the rest.
		return preg_replace_callback(
			'/<\/?([a-zA-Z0-9]+)\b([^>]*)>/',
			static function ( $m ) use ( $allowed_html ) {
				$tag        = strtolower( $m[1] );
				$is_closing = str_starts_with( $m[0], '</' );

				if ( ! isset( $allowed_html[ $tag ] ) ) {
					// Not allowed — strip the tag (its text content stays).
					return '';
				}

				if ( $is_closing ) {
					return '</' . $tag . '>';
				}

				$allowed_attrs = $allowed_html[ $tag ];
				$kept          = '';

				if ( preg_match_all( '/([a-zA-Z0-9_-]+)\s*=\s*"([^"]*)"/', $m[2], $attrs, PREG_SET_ORDER ) ) {
					foreach ( $attrs as $attr ) {
						$name  = strtolower( $attr[1] );
						$value = $attr[2];

						// Never keep event handlers.
						if ( str_starts_with( $name, 'on' ) ) {
							continue;
						}

						if ( empty( $allowed_attrs[ $name ] ) ) {
							continue;
						}

						// Drop dangerous protocols on URL-bearing attributes.
						if ( in_array( $name, [ 'href', 'src' ], true ) && preg_match( '/^\s*javascript:/i', $value ) ) {
							continue;
						}

						$kept .= ' ' . $name . '="' . $value . '"';
					}
				}

				return '<' . $tag . $kept . '>';
			},
			$content
		) ?? $content;
	}
}

// -- Sanitisation helpers --

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( trim( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $title ), '-' ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

// -- URL helpers --

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

// -- oEmbed stubs (controllable via globals) --

if ( ! function_exists( 'wp_oembed_get' ) ) {
	/**
	 * Stub for wp_oembed_get.
	 *
	 * Set $GLOBALS['albert_test_oembed_urls'][ $url ] = '<html>' to make
	 * a URL return embed HTML, or leave unset to return false.
	 */
	function wp_oembed_get( $url ) {
		if ( isset( $GLOBALS['albert_test_oembed_urls'][ $url ] ) ) {
			return $GLOBALS['albert_test_oembed_urls'][ $url ];
		}
		return false;
	}
}

if ( ! function_exists( '_wp_oembed_get_object' ) ) {
	/**
	 * Stub for _wp_oembed_get_object.
	 *
	 * Set $GLOBALS['albert_test_oembed_data'][ $url ] to a stdClass with
	 * type, provider_name, width, height to control oEmbed metadata.
	 */
	function _wp_oembed_get_object() {
		return new class() {
			public function get_data( $url, $args ) {
				if ( isset( $GLOBALS['albert_test_oembed_data'][ $url ] ) ) {
					return $GLOBALS['albert_test_oembed_data'][ $url ];
				}
				return false;
			}
		};
	}
}

if ( ! function_exists( 'albert_test_register_block_type' ) ) {
	/**
	 * Register a stub block type for unit tests.
	 *
	 * Adds (or replaces) an entry in $GLOBALS['albert_test_block_types'].
	 * When $dynamic is true the block type carries a render_callback, so
	 * BlockSchema::is_dynamic() reports it as dynamic.
	 *
	 * @param string               $name       Block name (e.g. 'core/paragraph').
	 * @param bool                 $dynamic    Whether the block renders via a render_callback.
	 * @param array<string, mixed> $attributes Optional attribute schema.
	 * @param array<string, mixed> $supports   Optional supports map.
	 * @return void
	 */
	function albert_test_register_block_type( $name, $dynamic = false, $attributes = [], $supports = [] ) {
		if ( ! isset( $GLOBALS['albert_test_block_types'] ) || ! is_array( $GLOBALS['albert_test_block_types'] ) ) {
			$GLOBALS['albert_test_block_types'] = [];
		}

		$block_type = (object) [
			'attributes'      => $attributes,
			'supports'        => $supports,
			'render_callback' => $dynamic ? static function () {
				return '';
			} : null,
		];

		$GLOBALS['albert_test_block_types'][ $name ] = $block_type;
	}
}

if ( ! function_exists( 'albert_test_register_default_block_types' ) ) {
	/**
	 * Register the common block types used across the block serializer tests.
	 *
	 * Covers the templated core blocks (so untemplated/registry logic does not
	 * mis-flag them as unknown), an explicit core/html, and a dynamic block.
	 * Note: 'core/hero' is intentionally NOT registered so tests can exercise
	 * the unknown-block error path.
	 *
	 * @return void
	 */
	function albert_test_register_default_block_types() {
		$GLOBALS['albert_test_block_types'] = [];

		$static_blocks = [
			'core/html',
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/image',
			'core/columns',
			'core/column',
			'core/group',
			'core/buttons',
			'core/button',
			'core/separator',
			'core/spacer',
			'core/code',
			'core/pullquote',
		];

		// Paragraphs and list items carry no generated wp-block-* class.
		$no_generated_class = [ 'core/paragraph', 'core/list-item' ];

		foreach ( $static_blocks as $block_name ) {
			albert_test_register_block_type( $block_name, false, [], in_array( $block_name, $no_generated_class, true ) ? [ 'className' => false ] : [] );
		}

		// A registered dynamic block (renders via a render_callback).
		albert_test_register_block_type( 'core/latest-posts', true );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub of sanitize_key(): lowercase, allow only a-z0-9_- .
	 *
	 * @param string $key Raw key.
	 * @return string Sanitised key.
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Stub of get_post(): resolve a post object from
	 * $GLOBALS['albert_test_posts'] keyed by ID, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Post-like object or null.
	 */
	function get_post( $post_id = 0 ) {
		$posts = (array) ( $GLOBALS['albert_test_posts'] ?? [] );

		return $posts[ (int) $post_id ] ?? null;
	}
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
if ( ! class_exists( 'WP_Block_Editor_Context' ) ) {
	/**
	 * Minimal stub of WP_Block_Editor_Context used by BlockCatalog.
	 *
	 * Records the settings it was constructed with so the get_allowed_block_types
	 * stub can branch on the context if a test needs to.
	 */
	class WP_Block_Editor_Context {

		/**
		 * The post attached to the context, if any.
		 *
		 * @var object|null
		 */
		public $post = null;

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $settings Context settings.
		 */
		public function __construct( $settings = [] ) {
			if ( isset( $settings['post'] ) ) {
				$this->post = $settings['post'];
			}
		}
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub of wp_json_encode(): plain json_encode() for unit tests.
	 *
	 * @param mixed $data    Value to encode.
	 * @param int   $options JSON encode options.
	 * @param int   $depth   Maximum depth.
	 * @return string|false JSON string, or false on failure.
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'has_blocks' ) ) {
	/**
	 * Stub of has_blocks(): detects block markup in a content string.
	 *
	 * Mirrors core's check for the block delimiter comment. Tests pass a content
	 * string directly (the post-object overload is not needed in the unit suite).
	 *
	 * @param string $content Post content.
	 * @return bool Whether the content contains block markup.
	 */
	function has_blocks( $content = '' ) {
		return str_contains( (string) $content, '<!-- wp:' );
	}
}

if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
	/**
	 * Stub of use_block_editor_for_post_type().
	 *
	 * Driven by $GLOBALS['albert_test_block_editor_post_types']: a map of
	 * post type → bool. When the map is unset, or a post type is missing from it,
	 * the block editor is assumed (the WordPress default).
	 *
	 * @param string $post_type Post type slug.
	 * @return bool Whether the post type uses the block editor.
	 */
	function use_block_editor_for_post_type( $post_type ) {
		$map = $GLOBALS['albert_test_block_editor_post_types'] ?? null;

		if ( ! is_array( $map ) || ! array_key_exists( $post_type, $map ) ) {
			return true;
		}

		return (bool) $map[ $post_type ];
	}
}

if ( ! function_exists( 'use_block_editor_for_post' ) ) {
	/**
	 * Stub of use_block_editor_for_post().
	 *
	 * Driven by $GLOBALS['albert_test_block_editor_posts']: a map of post id →
	 * bool. When the map is unset, or a post id is missing from it, the decision
	 * falls back to the post type via use_block_editor_for_post_type().
	 *
	 * @param object|int $post Post object or ID.
	 * @return bool Whether the post uses the block editor.
	 */
	function use_block_editor_for_post( $post ) {
		$id  = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		$map = $GLOBALS['albert_test_block_editor_posts'] ?? null;

		if ( is_array( $map ) && array_key_exists( $id, $map ) ) {
			return (bool) $map[ $id ];
		}

		$post_type = is_object( $post ) ? (string) ( $post->post_type ?? 'post' ) : 'post';

		return use_block_editor_for_post_type( $post_type );
	}
}

if ( ! function_exists( 'get_allowed_block_types' ) ) {
	/**
	 * Stub of get_allowed_block_types(): returns
	 * $GLOBALS['albert_test_allowed_block_types'] when set, otherwise true
	 * (all registered blocks allowed). When the value is callable it is invoked
	 * with the context so tests can branch on post type.
	 *
	 * @param object $context Block editor context.
	 * @return true|array<int, string> Allowed block names, or true for all.
	 */
	function get_allowed_block_types( $context = null ) {
		if ( ! array_key_exists( 'albert_test_allowed_block_types', $GLOBALS ) ) {
			return true;
		}

		$allowed = $GLOBALS['albert_test_allowed_block_types'];

		if ( is_callable( $allowed ) ) {
			return $allowed( $context );
		}

		return $allowed;
	}
}
