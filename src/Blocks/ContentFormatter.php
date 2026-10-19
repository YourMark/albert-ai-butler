<?php
/**
 * Content Formatter
 *
 * Resolves the optional `format` selector shared by the read abilities
 * (view-post, view-page, find-posts, find-pages) into the concrete set of
 * content representations to include in an ability's result.
 *
 * The five representations are:
 *   - content   → raw block markup (post_content), verbatim.
 *   - blocks    → the structured block tree from BlockReader::read().
 *   - plaintext → flattened human-readable text from BlockReader::plaintext_of().
 *   - html      → rendered output via do_blocks() (the only way to see the
 *                 output of dynamic/server-rendered blocks).
 *   - markdown  → Markdown rendering via BlockMarkdown::render().
 *
 * Keeping this in one place guarantees every read ability honours the same
 * default and the same backward-compatible field set.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the requested content representations for a piece of block markup.
 *
 * @since 1.2.0
 */
class ContentFormatter {

	/**
	 * Every representation this formatter can produce.
	 *
	 * @var array<int, string>
	 *
	 * @since 1.2.0
	 */
	public const FORMATS = [ 'content', 'blocks', 'plaintext', 'html', 'markdown' ];

	/**
	 * The default representations when no `format` is requested.
	 *
	 * Preserves the pre-1.2.0 output (content + blocks + plaintext) so existing
	 * callers keep working unchanged.
	 *
	 * @var array<int, string>
	 *
	 * @since 1.2.0
	 */
	public const DEFAULT_FORMATS = [ 'content', 'blocks', 'plaintext' ];

	/**
	 * Default number of top-level blocks per window on the view-* (paginate) path.
	 *
	 * Deliberately high: it is a backstop, not the primary limiter. Size — the
	 * per-field byte cap ({@see DEFAULT_MAX_BYTES}) — is what normally triggers
	 * pagination, so an ordinary post is returned whole in a single call. This
	 * cap only bounds pathological posts with hundreds of blocks (and, since the
	 * `blocks` tree is not byte-capped, keeps that tree from growing unbounded).
	 *
	 * Overridable via the `albert/blocks/read_block_limit` filter (0 = unlimited).
	 *
	 * @var int
	 *
	 * @since 1.2.0
	 */
	public const DEFAULT_BLOCK_LIMIT = 200;

	/**
	 * Default per-field byte cap applied to text representations on every read.
	 *
	 * Conservative default well under the ~1 MB tool-result limits of the major
	 * MCP clients. Overridable via the `albert/blocks/read_max_bytes` filter.
	 *
	 * @var int
	 *
	 * @since 1.2.0
	 */
	public const DEFAULT_MAX_BYTES = 50000;

	/**
	 * The text representations the byte cap applies to.
	 *
	 * The structured `blocks` tree is never byte-capped — on the view-* path it is
	 * bounded by the block window, and on the find-* path by the lean default
	 * format.
	 *
	 * @var array<int, string>
	 *
	 * @since 1.2.0
	 */
	private const TEXT_FORMATS = [ 'content', 'plaintext', 'html', 'markdown' ];

	/**
	 * Build the shared `format` input-schema property used by the read abilities.
	 *
	 * The view-post, view-page, find-posts and find-pages abilities all expose
	 * the same optional `format` selector; defining it here keeps the description
	 * and the allowed-value enum in one place.
	 *
	 * @return array<string, mixed> JSON Schema fragment for the optional format selector.
	 *
	 * @since 1.2.0
	 */
	public static function input_schema_property(): array {
		return [
			'type'        => 'array',
			'description' => 'Which content representations to return. Optional; when omitted or empty, defaults to ["content","blocks","plaintext"] for backward compatibility. '
				. 'Allowed values: "content" (raw block markup), "blocks" (structured block tree), "plaintext" (flattened text), '
				. '"html" (rendered HTML via do_blocks, the only way to see dynamic-block output), "markdown" (Markdown rendering). '
				. 'Only the requested representations are included in the result.',
			'items'       => [
				'type' => 'string',
				'enum' => self::FORMATS,
			],
		];
	}

	/**
	 * Build the requested content representations for raw block markup.
	 *
	 * Truncation hardening (1.2.0+): read output is bounded by two mechanisms so a
	 * long post cannot blow an MCP client's tool-result limit and get silently cut:
	 *
	 *   1. Block-window pagination (view-* path only) — when `$options['paginate']`
	 *      is true the top-level blocks are sliced by `offset`/`limit` and every
	 *      requested representation is derived from the same window, with an
	 *      actionable `_meta` object describing the slice and pointing the model at
	 *      the next `offset`. find-* abilities already page at the post level, so
	 *      they skip windowing and pass `paginate => false`.
	 *   2. A per-field byte cap (both paths) — each text representation (content,
	 *      plaintext, html, markdown) is cut on a UTF-8 boundary at `max_bytes` with
	 *      a `…[truncated, N more characters]` marker. The `blocks` tree is never
	 *      byte-capped: it is bounded by the window count on view-* and by the lean
	 *      default format on find-*.
	 *
	 * Backward compatibility: called as `build( $raw, $format )` with no `$options`
	 * the output matches the pre-1.2.0 key set, with one addition that was not
	 * possible before — a top-level `truncated => true` key may appear when a text
	 * representation exceeds the byte cap. `_meta` only ever appears on the
	 * paginate path; `truncated` only when a byte trim actually fired.
	 *
	 * @param string               $raw_content Raw block markup (post_content).
	 * @param array<int, string>   $format      Requested representations; falls back
	 *                                           to {@see DEFAULT_FORMATS} when empty.
	 * @param array<string, mixed> $options     {
	 *     Optional truncation options.
	 *
	 *     @type int  $offset    First top-level block to include (paginate path). Default 0.
	 *     @type int  $limit     Max top-level blocks to include; 0 = no windowing.
	 *                           Defaults to the `albert/blocks/read_block_limit` filter
	 *                           ({@see DEFAULT_BLOCK_LIMIT}) on the paginate path.
	 *     @type int  $max_bytes Per-field byte cap; defaults to the
	 *                           `albert/blocks/read_max_bytes` filter ({@see DEFAULT_MAX_BYTES}).
	 *     @type bool $paginate  When true, window the blocks and emit `_meta`.
	 *                           When false/absent, byte cap only (find-* path).
	 * }
	 * @return array<string, mixed> Map of representation key → value, plus `_meta`
	 *                              on the paginate path and a `truncated` flag on the
	 *                              find path when a representation was byte-capped.
	 *
	 * @since 1.2.0
	 */
	public static function build( string $raw_content, array $format = [], array $options = [] ): array {
		$requested = self::normalize( $format );
		$paginate  = ! empty( $options['paginate'] );
		if ( isset( $options['max_bytes'] ) ) {
			$max_bytes = max( 0, (int) $options['max_bytes'] );
		} else {
			/**
			 * Filters the per-field byte cap applied to read text representations.
			 *
			 * Each of `content`, `plaintext`, `html` and `markdown` is cut on a
			 * UTF-8 boundary at this many bytes (0 disables the cap). Keeps a read
			 * response well under an MCP client's tool-result limit.
			 *
			 * @since 1.2.0
			 *
			 * @param int $max_bytes Per-field byte cap. Default {@see DEFAULT_MAX_BYTES}.
			 */
			$max_bytes = (int) apply_filters( 'albert/blocks/read_max_bytes', self::DEFAULT_MAX_BYTES );
		}

		if ( $paginate ) {
			return self::build_windowed( $raw_content, $requested, $options, $max_bytes );
		}

		return self::build_capped( $raw_content, $requested, $max_bytes );
	}

	/**
	 * Build representations with the byte cap only (no windowing) — find-* path.
	 *
	 * Every requested representation is derived from the whole raw content; each
	 * text representation is then byte-capped. A lean top-level `truncated => true`
	 * flag is added only when a trim actually fired, keeping find output lean.
	 *
	 * @param string             $raw_content Raw block markup.
	 * @param array<int, string> $requested   Normalised representation keys.
	 * @param int                $max_bytes   Per-field byte cap (0 = uncapped).
	 * @return array<string, mixed> Representations, plus `truncated` when trimmed.
	 *
	 * @since 1.2.0
	 */
	private static function build_capped( string $raw_content, array $requested, int $max_bytes ): array {
		$needs_tree = in_array( 'blocks', $requested, true ) || in_array( 'plaintext', $requested, true );
		$blocks     = $needs_tree ? ( new BlockReader() )->read( $raw_content ) : [];

		$out = self::representations( $requested, $raw_content, $blocks );

		$trimmed = false;
		self::apply_byte_cap( $out, $max_bytes, $trimmed );

		if ( $trimmed ) {
			$out['truncated'] = true;
		}

		return $out;
	}

	/**
	 * Build representations from a top-level block window — view-* path.
	 *
	 * Drops the empty-freeform whitespace separators parse_blocks() emits (same
	 * rule as BlockReader) so the window index matches the `blocks` representation,
	 * slices by offset/limit, re-serialises the slice, and derives every requested
	 * representation from that window markup. Emits an actionable `_meta` object.
	 *
	 * @param string               $raw_content Raw block markup.
	 * @param array<int, string>   $requested   Normalised representation keys.
	 * @param array<string, mixed> $options     Truncation options (offset/limit).
	 * @param int                  $max_bytes   Per-field byte cap (0 = uncapped).
	 * @return array<string, mixed> Representations plus `_meta`.
	 *
	 * @since 1.2.0
	 */
	private static function build_windowed( string $raw_content, array $requested, array $options, int $max_bytes ): array {
		$offset = isset( $options['offset'] ) ? max( 0, (int) $options['offset'] ) : 0;
		if ( isset( $options['limit'] ) ) {
			$limit = max( 0, (int) $options['limit'] );
		} else {
			/**
			 * Filters the default number of top-level blocks returned per read window.
			 *
			 * Applies to the paginated read path (view-* abilities) when no explicit
			 * `limit` is given. A value of 0 disables windowing (return all blocks).
			 *
			 * @since 1.2.0
			 *
			 * @param int $limit Top-level blocks per window. Default {@see DEFAULT_BLOCK_LIMIT}.
			 */
			$limit = max( 0, (int) apply_filters( 'albert/blocks/read_block_limit', self::DEFAULT_BLOCK_LIMIT ) );
		}

		// Filter out the empty-freeform separators so the index lines up with the
		// shaped `blocks` representation (same rule as BlockReader::shape_blocks()).
		$parsed   = parse_blocks( $raw_content );
		$filtered = array_values(
			array_filter(
				$parsed,
				static function ( $block ): bool {
					return ! BlockReader::is_empty_separator( $block );
				}
			)
		);

		$total_blocks = count( $filtered );
		$window       = array_slice( $filtered, $offset, $limit > 0 ? $limit : null );
		$returned     = count( $window );

		$window_markup = serialize_blocks( $window );
		$shaped        = ( new BlockReader() )->shape( $window );

		$out = self::representations( $requested, $window_markup, $shaped );

		$trimmed = false;
		self::apply_byte_cap( $out, $max_bytes, $trimmed );

		$has_more    = ( $offset + $returned ) < $total_blocks;
		$next_offset = $has_more ? $offset + $returned : null;

		$meta = [
			'total_blocks'    => $total_blocks,
			'offset'          => $offset,
			'limit'           => $limit,
			'returned_blocks' => $returned,
			'truncated'       => $has_more || $trimmed,
		];

		if ( $next_offset !== null ) {
			$meta['next_offset'] = $next_offset;
		}

		$meta['note'] = self::window_note( $offset, $returned, $total_blocks, $next_offset, $trimmed );

		$out['_meta'] = $meta;

		return $out;
	}

	/**
	 * Build the actionable `_meta.note` for a read window.
	 *
	 * The signals are composed (not mutually exclusive) so a single window can
	 * carry several at once — e.g. "more blocks remain" AND "text was byte-capped"
	 * together, which the model needs both of to behave correctly. The note always
	 * states the slice it actually returned (never "showing all" for a partial
	 * slice), appends the next-offset hint when more blocks remain, and appends a
	 * byte-cap warning when a text representation was trimmed.
	 *
	 * @param int      $offset       The window start that was applied.
	 * @param int      $returned     How many blocks this window contains.
	 * @param int      $total_blocks Total top-level blocks in the post.
	 * @param int|null $next_offset  Offset to request next, or null when none remain.
	 * @param bool     $trimmed      Whether a text representation was byte-capped.
	 * @return string Actionable note for the model.
	 *
	 * @since 1.2.0
	 */
	private static function window_note( int $offset, int $returned, int $total_blocks, ?int $next_offset, bool $trimmed ): string {
		if ( $total_blocks === 0 ) {
			return 'The post has no blocks.';
		}

		if ( $returned === 0 ) {
			return sprintf(
				/* translators: 1: requested offset, 2: total blocks, 3: highest valid offset. */
				'Offset %1$d is past the end of the post (%2$d block(s) total). Use an offset between 0 and %3$d.',
				$offset,
				$total_blocks,
				$total_blocks - 1
			);
		}

		if ( $offset === 0 && $returned === $total_blocks ) {
			$note = sprintf(
				/* translators: %d: total blocks. */
				'Showing all %d block(s).',
				$total_blocks
			);
		} else {
			$note = sprintf(
				/* translators: 1: first block index, 2: last block index, 3: total blocks. */
				'Showing blocks %1$d–%2$d of %3$d.',
				$offset,
				$offset + $returned - 1,
				$total_blocks
			);
		}

		if ( $next_offset !== null ) {
			$note .= sprintf(
				/* translators: %d: next offset to request. */
				' Re-request with offset=%d for the next slice.',
				$next_offset
			);
		}

		if ( $trimmed ) {
			$note .= ' Some text was byte-capped; request a smaller limit or a single representation to see the full text.';
		}

		return $note;
	}

	/**
	 * Derive the requested representations from a piece of block markup + its tree.
	 *
	 * Shared by the windowed and capped paths: on the capped path the inputs are
	 * the whole content + full tree; on the windowed path they are the re-serialised
	 * window markup + the shaped window. Only the requested keys are produced.
	 *
	 * @param array<int, string>               $requested Normalised representation keys.
	 * @param string                           $markup    Block markup to derive text from.
	 * @param array<int, array<string, mixed>> $blocks    Shaped block tree for `markup`.
	 * @return array<string, mixed> Map of representation key → value.
	 *
	 * @since 1.2.0
	 */
	private static function representations( array $requested, string $markup, array $blocks ): array {
		$out = [];

		foreach ( $requested as $key ) {
			switch ( $key ) {
				case 'content':
					$out['content'] = $markup;
					break;

				case 'blocks':
					$out['blocks'] = $blocks;
					break;

				case 'plaintext':
					$out['plaintext'] = BlockReader::plaintext_of( $blocks );
					break;

				case 'html':
					$out['html'] = do_blocks( $markup );
					break;

				case 'markdown':
					$out['markdown'] = ( new BlockMarkdown() )->render( $markup );
					break;
			}
		}

		return $out;
	}

	/**
	 * Byte-cap each text representation in place on a valid UTF-8 boundary.
	 *
	 * Fields over `$max_bytes` (by byte length) are cut with mb_strcut() — which
	 * never splits a multibyte sequence — and a `…[truncated, N more characters]`
	 * marker is appended (N counts characters, mirroring the Premium-logging
	 * marker). The `blocks` tree is intentionally not capped.
	 *
	 * @param array<string, mixed> $out       Representations, modified by reference.
	 * @param int                  $max_bytes Per-field byte cap; 0 disables capping.
	 * @param bool                 $trimmed   Set true (by reference) if any field was cut.
	 * @return void
	 *
	 * @since 1.2.0
	 */
	private static function apply_byte_cap( array &$out, int $max_bytes, bool &$trimmed ): void {
		if ( $max_bytes <= 0 ) {
			return;
		}

		foreach ( self::TEXT_FORMATS as $key ) {
			if ( ! isset( $out[ $key ] ) || ! is_string( $out[ $key ] ) ) {
				continue;
			}

			$value = $out[ $key ];
			if ( strlen( $value ) <= $max_bytes ) {
				continue;
			}

			$kept      = mb_strcut( $value, 0, $max_bytes, 'UTF-8' );
			$remaining = mb_strlen( $value, 'UTF-8' ) - mb_strlen( $kept, 'UTF-8' );

			$out[ $key ] = $kept . sprintf( '…[truncated, %d more characters]', $remaining );
			$trimmed     = true;
		}
	}

	/**
	 * Normalise a raw `format` input into a clean, ordered, deduped list of
	 * recognised representation keys.
	 *
	 * An empty or all-invalid input yields {@see DEFAULT_FORMATS}. The output is
	 * ordered to match {@see FORMATS} so result keys are deterministic.
	 *
	 * @param mixed $format Raw input (expected array of strings, but defensive).
	 * @return array<int, string> Recognised representation keys.
	 *
	 * @since 1.2.0
	 */
	public static function normalize( $format ): array {
		if ( ! is_array( $format ) ) {
			return self::DEFAULT_FORMATS;
		}

		$requested = [];
		foreach ( $format as $value ) {
			if ( is_string( $value ) && in_array( $value, self::FORMATS, true ) ) {
				$requested[] = $value;
			}
		}

		if ( $requested === [] ) {
			return self::DEFAULT_FORMATS;
		}

		// Order by FORMATS and dedupe for deterministic output.
		return array_values( array_filter( self::FORMATS, static fn ( string $f ): bool => in_array( $f, $requested, true ) ) );
	}
}
