<?php
/**
 * Block Serializer
 *
 * Converts structured block specs into valid WordPress block markup. This is
 * the write-side counterpart to BlockReader: an AI assistant sends a tree of
 * `{ name, attributes, innerBlocks }` specs and gets back comment-delimited
 * block markup that survives a parse_blocks()/serialize_blocks() round-trip
 * without the editor flagging "unexpected or invalid content".
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

use Albert\Utilities\BlockConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Serializes block specs to markup and reports field-level validation issues.
 *
 * Per-block innerHTML templates mirror each core block's JavaScript save()
 * output so the saved content validates in the editor. Blocks without a
 * template are resolved against the block type registry: registered dynamic
 * blocks serialize as a safe comment + attributes (no innerHTML), registered
 * static blocks serialize best-effort with a warning, and unregistered block
 * names are flagged as errors (with an actionable hint) while still emitting
 * fallback markup so serialize() never returns broken output.
 *
 * Issues carry a severity so callers can decide whether to save:
 *   - 'error'   — the markup is degraded in a way the caller should fix before
 *                 saving (unknown block, dropped content, round-trip mismatch).
 *   - 'warning' — a recoverable degradation (clamped value, missing image url,
 *                 a registered-but-untemplated static block).
 *
 * The ParsedBlock shape mirrors what serialize_blocks() expects (see its
 * WordPress stub @phpstan-param), so the blocks built here can be handed
 * straight to it without a type mismatch.
 *
 * @phpstan-type Issue array{severity: string, message: string}
 * @phpstan-type ParsedBlock array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int, mixed>, innerHTML: string, innerContent: array<int, string|null>}
 * @phpstan-type TemplateResult array{0: array<string, mixed>, 1: string, 2: array<int, string>}
 *
 * @since 1.2.0
 */
class BlockSerializer {

	/**
	 * Severity for issues that degrade the markup and should block saving.
	 *
	 * @var string
	 *
	 * @since 1.2.0
	 */
	public const SEVERITY_ERROR = 'error';

	/**
	 * Severity for recoverable degradations that do not block saving.
	 *
	 * @var string
	 *
	 * @since 1.2.0
	 */
	public const SEVERITY_WARNING = 'warning';

	/**
	 * Block type registry reader, used to classify untemplated block names.
	 *
	 * @var BlockSchema
	 *
	 * @since 1.2.0
	 */
	private BlockSchema $schema;

	/**
	 * Builds the HTML a block's supports put on its markup.
	 *
	 * @var BlockSupports
	 *
	 * @since 1.6.0
	 */
	private BlockSupports $supports;

	/**
	 * The block delimiter pattern WP_Block_Parser uses.
	 *
	 * @var string
	 *
	 * @since 1.6.0
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Attributes each template writes into the HTML, or knows the block keeps in
	 * its comment only. Supports are checked against the registry instead.
	 *
	 * @var array<string, array<int, string>>
	 *
	 * @since 1.6.0
	 */
	private const TEMPLATE_ATTRIBUTES = [
		'core/paragraph' => [ 'content', 'dropCap', 'direction', 'placeholder' ],
		'core/heading'   => [ 'content', 'level', 'levelOptions', 'placeholder' ],
		'core/list'      => [ 'ordered', 'type', 'start', 'reversed', 'placeholder' ],
		'core/list-item' => [ 'content', 'placeholder' ],
		'core/quote'     => [ 'citation' ],
		'core/image'     => [ 'url', 'alt', 'caption', 'align', 'href', 'rel', 'linkClass', 'linkTarget', 'width', 'height', 'aspectRatio', 'scale', 'focalPoint', 'id', 'sizeSlug', 'title', 'isDecorative', 'linkDestination', 'lightbox', 'borderColor', 'style.border', 'style.shadow' ],
		'core/columns'   => [ 'verticalAlignment', 'isStackedOnMobile' ],
		'core/column'    => [ 'verticalAlignment', 'width' ],
		'core/group'     => [ 'tagName' ],
		'core/buttons'   => [],
		'core/button'    => [ 'tagName', 'type', 'url', 'title', 'text', 'linkTarget', 'rel', 'placeholder', 'backgroundColor', 'textColor', 'gradient', 'fontSize', 'fontFamily', 'borderColor', 'style.color', 'style.border', 'style.spacing', 'style.shadow', 'style.typography', 'style.dimensions' ],
		'core/separator' => [ 'opacity', 'tagName', 'backgroundColor', 'gradient', 'style.color' ],
		'core/spacer'    => [ 'height', 'width' ],
		'core/code'      => [ 'content' ],
		'core/pullquote' => [ 'value', 'citation' ],
	];

	/**
	 * Attributes a template writes only on WordPress versions whose block declares
	 * them (WordPress 6.9: text alignment as an attribute, a button's width).
	 *
	 * @var array<string, array<int, string>>
	 *
	 * @since 1.6.0
	 */
	private const DECLARED_TEMPLATE_ATTRIBUTES = [
		'core/paragraph' => [ 'align' ],
		'core/heading'   => [ 'textAlign' ],
		'core/quote'     => [ 'textAlign' ],
		'core/pullquote' => [ 'textAlign' ],
		'core/button'    => [ 'width' ],
	];

	/**
	 * Construct the serializer.
	 *
	 * @param BlockSchema|null   $schema   Optional registry reader (defaults to a fresh instance).
	 * @param BlockSupports|null $supports Optional supports builder.
	 *
	 * @since 1.2.0
	 * @since 1.6.0 Added $supports.
	 */
	public function __construct( ?BlockSchema $schema = null, ?BlockSupports $supports = null ) {
		$this->schema   = $schema ?? new BlockSchema();
		$this->supports = $supports ?? new BlockSupports( $this->schema );
	}

	/**
	 * Convert block specs (or a string) into block markup.
	 *
	 * @param array<int, array<string, mixed>>|string $input Block specs or an HTML/Markdown string.
	 * @return string Comment-delimited block markup.
	 *
	 * @since 1.2.0
	 */
	public function serialize( $input ): string {
		return $this->serialize_with_issues( $input )['markup'];
	}

	/**
	 * Convert block specs (or a string) into markup and collect validation issues.
	 *
	 * Each issue is an `array{severity: string, message: string}` where the
	 * message is actionable and path-qualified for an LLM to self-correct, e.g.
	 * "content[0].attributes.level must be between 1 and 6".
	 *
	 * @param array<int, array<string, mixed>>|string $input Block specs or an HTML/Markdown string.
	 * @return array{markup: string, issues: array<int, Issue>} Markup plus a list of issues.
	 *
	 * @since 1.2.0
	 */
	public function serialize_with_issues( $input ): array {
		if ( is_string( $input ) ) {
			if ( has_blocks( $input ) ) {
				return $this->pass_through( $input );
			}

			return [
				'markup' => ( new BlockConverter( $input ) )->convert(),
				'issues' => [],
			];
		}

		$issues = [];
		$blocks = $this->build_blocks( $input, 'content', $issues );
		$markup = serialize_blocks( $blocks );

		// Round-trip self-check: confirm every top-level block name survived.
		$this->verify_round_trip( $blocks, $markup, $issues );

		return [
			'markup' => $markup,
			'issues' => $issues,
		];
	}

	/**
	 * Build a single parsed block from one block spec.
	 *
	 * The write-side counterpart used by the granular block-edit abilities
	 * ({@see BlockTreeEditor}) — they need exactly one ParsedBlock to splice into
	 * a parse_blocks() tree, not a full top-level serialize. This wraps the same
	 * private {@see build_block()} the whole-content path uses, so the resulting
	 * block carries the same per-block-template innerHTML/innerContent and is
	 * byte-correct when serialize_blocks() rebuilds it next to untouched siblings.
	 *
	 * Returns the built block (or null when the spec produced nothing, e.g. an
	 * unknown block name with no content to preserve) alongside the same
	 * severity-tagged issue list {@see serialize_with_issues()} reports, so callers
	 * can fold them through {@see BlockIssues} exactly as whole-content writes do.
	 *
	 * @param array<string, mixed> $spec Single block spec ({ name, attributes, innerBlocks }).
	 * @return array{block: ParsedBlock|null, issues: array<int, Issue>} Built block (or null) and issues.
	 *
	 * @since 1.2.0
	 */
	public function build_one( array $spec ): array {
		$issues = [];
		$block  = $this->build_block( $spec, 'block', $issues );

		return [
			'block'  => $block,
			'issues' => $issues,
		];
	}

	/**
	 * Keep block markup as sent.
	 *
	 * The editor wrote this markup; any rebuild from attributes would lose what
	 * PHP cannot regenerate (a block's save() runs in JavaScript only). It is
	 * checked, not changed: unregistered names and unbalanced delimiters are
	 * errors, and only HTML outside every block is converted to blocks.
	 *
	 * @param string $markup Content containing block delimiters.
	 * @return array{markup: string, issues: array<int, Issue>}
	 *
	 * @since 1.6.0
	 */
	private function pass_through( string $markup ): array {
		$issues = [];
		$spans  = $this->top_level_spans( $markup, 'content', $issues );
		$output = '';
		$cursor = 0;
		$stray  = false;

		foreach ( $spans as [ $start, $end ] ) {
			$output .= $this->convert_stray( substr( $markup, $cursor, $start - $cursor ), $stray );
			$output .= substr( $markup, $start, $end - $start );
			$cursor  = $end;
		}

		$output .= $this->convert_stray( substr( $markup, $cursor ), $stray );

		if ( $stray ) {
			$issues[] = $this->issue( self::SEVERITY_WARNING, 'content had HTML outside any block; it was converted to blocks. The block markup was kept as sent.' );
		}

		return [
			'markup' => $output,
			'issues' => $issues,
		];
	}

	/**
	 * Convert HTML found between top-level blocks; whitespace is kept as is.
	 *
	 * @param string $html  Text between two blocks.
	 * @param bool   $stray Set to true when anything was converted.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function convert_stray( string $html, bool &$stray ): string {
		if ( trim( $html ) === '' ) {
			return $html;
		}

		$stray = true;

		return "\n" . ( new BlockConverter( $html ) )->convert() . "\n";
	}

	/**
	 * Check every block delimiter in markup and find the top-level blocks.
	 *
	 * Flags a closer without its opener, an opener never closed, and a block
	 * name the site has not registered.
	 *
	 * @param string            $markup Block markup.
	 * @param string            $path   Field name for issue messages.
	 * @param array<int, Issue> $issues Issues collector (by reference).
	 * @return array<int, array{0: int, 1: int}> Byte offsets [ start, end ) of each top-level block.
	 *
	 * @since 1.6.0
	 */
	private function top_level_spans( string $markup, string $path, array &$issues ): array {
		preg_match_all( self::DELIMITER, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		$spans      = [];
		$stack      = [];
		$unknown    = [];
		$span_start = 0;

		foreach ( $matches as $match ) {
			$offset = (int) $match[0][1];
			$end    = $offset + strlen( $match[0][0] );
			$name   = ( $match['namespace'][0] !== '' ? $match['namespace'][0] : 'core/' ) . $match['name'][0];
			$closer = $match['closer'][0] === '/';
			$void   = isset( $match['void'] );

			if ( $closer ) {
				if ( $stack === [] || end( $stack ) !== $name ) {
					$issues[] = $this->issue(
						self::SEVERITY_ERROR,
						sprintf( "%s has a closing <!-- /wp:%s --> that does not match an open block. Check that every block's delimiters are balanced.", $path, $this->short_name( $name ) )
					);
					continue;
				}

				array_pop( $stack );
				if ( $stack === [] ) {
					$spans[] = [ $span_start, $end ];
				}
				continue;
			}

			if ( ! $this->schema->is_registered( $name ) && ! isset( $unknown[ $name ] ) ) {
				$unknown[ $name ] = true;
				$issues[]         = $this->issue(
					self::SEVERITY_ERROR,
					"{$path} contains block '{$name}', which is not registered on this site. Call albert/list-block-types to see the blocks you may use."
				);
			}

			if ( $stack === [] ) {
				$span_start = $offset;
			}

			if ( $void ) {
				if ( $stack === [] ) {
					$spans[] = [ $offset, $end ];
				}
				continue;
			}

			$stack[] = $name;
		}

		foreach ( array_reverse( $stack ) as $name ) {
			$issues[] = $this->issue(
				self::SEVERITY_ERROR,
				sprintf( '%1$s opens <!-- wp:%2$s --> but never closes it. Add <!-- /wp:%2$s -->.', $path, $this->short_name( $name ) )
			);
		}

		return $stack === [] ? $spans : [];
	}

	/**
	 * A block name as it is written in a delimiter (core/ is left out).
	 *
	 * @param string $name Block name.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function short_name( string $name ): string {
		return str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
	}

	/**
	 * Build an issue array.
	 *
	 * @param string $severity One of the SEVERITY_* constants.
	 * @param string $message  Actionable, path-qualified message.
	 * @return Issue
	 *
	 * @since 1.2.0
	 */
	private function issue( string $severity, string $message ): array {
		return [
			'severity' => $severity,
			'message'  => $message,
		];
	}

	/**
	 * Build a list of parsed-block arrays from a list of specs.
	 *
	 * @param array<int, array<string, mixed>> $specs  Block specs.
	 * @param string                           $path   Dotted path prefix for issue messages.
	 * @param array<int, Issue>                $issues Issues collector (by reference).
	 * @return array<int, ParsedBlock> Parsed-block arrays ready for serialize_blocks().
	 *
	 * @since 1.2.0
	 */
	private function build_blocks( array $specs, string $path, array &$issues ): array {
		$blocks = [];

		foreach ( $specs as $index => $spec ) {
			$item_path = "{$path}[{$index}]";

			if ( ! is_array( $spec ) ) {
				$issues[] = $this->issue(
					self::SEVERITY_ERROR,
					"{$item_path} must be an object with a 'name' and optional 'attributes'/'innerBlocks'."
				);
				continue;
			}

			$block = $this->build_block( $spec, $item_path, $issues );
			if ( $block !== null ) {
				$blocks[] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * Build a single parsed-block array from a spec.
	 *
	 * @param array<string, mixed> $spec   Block spec.
	 * @param string               $path   Dotted path for issue messages.
	 * @param array<int, Issue>    $issues Issues collector (by reference).
	 * @return ParsedBlock|null Parsed-block array, or null to skip.
	 *
	 * @since 1.2.0
	 */
	private function build_block( array $spec, string $path, array &$issues ): ?array {
		$name = $spec['name'] ?? $spec['blockName'] ?? null;
		$name = is_string( $name ) ? trim( $name ) : '';

		if ( $name === '' ) {
			// An explicit null name is classic (pre-block-editor) content, the
			// spelling BlockReader emits for it, so a block read from a view-* call
			// can be written back unchanged. An *absent* name is a forgotten field
			// and stays an error.
			if ( array_key_exists( 'name', $spec ) && $spec['name'] === null ) {
				return $this->classic_block( $spec );
			}

			$issues[] = $this->issue( self::SEVERITY_ERROR, "{$path}.name is required (e.g. 'core/paragraph')." );
			return null;
		}

		$attributes = $spec['attributes'] ?? $spec['attrs'] ?? [];
		if ( ! is_array( $attributes ) ) {
			$issues[]   = $this->issue( self::SEVERITY_ERROR, "{$path}.attributes must be an object." );
			$attributes = [];
		}

		$inner_specs = $spec['innerBlocks'] ?? [];
		if ( ! is_array( $inner_specs ) ) {
			$issues[]    = $this->issue( self::SEVERITY_ERROR, "{$path}.innerBlocks must be an array of block specs." );
			$inner_specs = [];
		}

		$html = isset( $spec['html'] ) && is_string( $spec['html'] ) && trim( $spec['html'] ) !== '' ? $spec['html'] : null;

		if ( $html !== null && $name !== 'core/html' && $this->schema->is_registered( $name ) ) {
			if ( $inner_specs !== [] ) {
				$issues[] = $this->issue( self::SEVERITY_ERROR, "{$path} has both 'html' and 'innerBlocks'. Send one: 'html' already holds the inner blocks' markup." );
				return null;
			}

			return $this->block_from_html( $name, $attributes, $html, $path, $issues );
		}

		$plaintext    = isset( $spec['plaintext'] ) ? (string) $spec['plaintext'] : '';
		$inner_blocks = $this->build_blocks( $inner_specs, "{$path}.innerBlocks", $issues );

		$template = $this->template_for( $name );

		// No per-block template here — fall back to registry classification.
		if ( $template === null ) {
			return $this->serialize_untemplated( $name, $spec, $attributes, $inner_blocks, $path, $issues );
		}

		$context = new BlockContext( $attributes, $plaintext, $path );

		// Templates take exactly one argument (the context) and return
		// [ array $attrs, string $inner_html, array $tpl_issues ]. Any messages
		// the template generated are recoverable degradations (clamped value,
		// missing url, …), so they are recorded as warnings.
		[ $attrs, $inner_html, $tpl_issues ] = $template( $context );

		foreach ( $tpl_issues as $message ) {
			$issues[] = $this->issue( self::SEVERITY_WARNING, $message );
		}

		foreach ( $this->supports->unwritten( $name, $attributes, $this->template_attributes( $name ) ) as $attribute ) {
			$issues[] = $this->issue(
				self::SEVERITY_ERROR,
				"{$path}.attributes.{$attribute} is not written into {$name}'s HTML by this server, so the editor would flag the block as invalid. Remove it, or send the block's saved markup in 'html'."
			);
		}

		// Inner blocks under a leaf template (no %children% slot): make_block()
		// keeps them, but the block's save() won't nest them, so the editor may
		// drop them on next save. Warn.
		if ( $inner_blocks !== [] && ! str_contains( $inner_html, '%children%' ) ) {
			$issues[] = $this->issue(
				self::SEVERITY_WARNING,
				sprintf(
					"%s.name '%s' does not nest inner blocks; the %d supplied child block(s) were kept in the markup but the editor may drop them on the next save. Use a block that accepts inner blocks (e.g. core/group).",
					$path,
					$name,
					count( $inner_blocks )
				)
			);
		}

		return $this->make_block( $name, $attrs, $inner_html, $inner_blocks );
	}

	/**
	 * Attributes a block's template writes, including those only some WordPress
	 * versions declare.
	 *
	 * @param string $name Block name.
	 * @return array<int, string>
	 *
	 * @since 1.6.0
	 */
	private function template_attributes( string $name ): array {
		$written = self::TEMPLATE_ATTRIBUTES[ $name ] ?? [];

		foreach ( self::DECLARED_TEMPLATE_ATTRIBUTES[ $name ] ?? [] as $attribute ) {
			if ( $this->declares( $name, $attribute ) ) {
				$written[] = $attribute;
			}
		}

		return $written;
	}

	/**
	 * Whether a block's registered schema declares an attribute.
	 *
	 * @param string $name      Block name.
	 * @param string $attribute Attribute name.
	 * @return bool
	 *
	 * @since 1.6.0
	 */
	private function declares( string $name, string $attribute ): bool {
		$schema = $this->schema->block_schema( $name );

		return is_array( $schema['attributes'] ?? null ) && array_key_exists( $attribute, $schema['attributes'] );
	}

	/**
	 * Build a block whose saved markup the caller sent in `html`.
	 *
	 * The markup is the block's inner HTML as the editor stores it. Nested block
	 * delimiters become real inner blocks, so the block stays editable and the
	 * allowed-block check sees them.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Comment attributes.
	 * @param string               $html       The block's inner HTML.
	 * @param string               $path       Dotted path for issue messages.
	 * @param array<int, Issue>    $issues     Issues collector (by reference).
	 * @return ParsedBlock|null
	 *
	 * @since 1.6.0
	 */
	private function block_from_html( string $name, array $attributes, string $html, string $path, array &$issues ): ?array {
		$html_issues = [];
		$this->top_level_spans( $html, "{$path}.html", $html_issues );
		$issues = array_merge( $issues, $html_issues );

		if ( $html_issues !== [] ) {
			return null;
		}

		$inner_blocks  = [];
		$inner_content = [];

		foreach ( parse_blocks( $html ) as $parsed ) {
			if ( $parsed['blockName'] === null ) {
				$inner_content[] = (string) $parsed['innerHTML'];
				continue;
			}

			$inner_blocks[]  = $parsed;
			$inner_content[] = null;
		}

		$attributes = array_diff_key( $attributes, array_flip( $this->schema->unvalidatable_sourced_attributes( $name ) ) );

		return [
			'blockName'    => $name,
			'attrs'        => $attributes,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Serialize a block that has no per-block template here, using the registry.
	 *
	 * Resolution order:
	 *   - core/html (registered) — legitimate raw HTML, emitted with no issue.
	 *   - registered dynamic     — emit comment + attrs, empty innerHTML (valid).
	 *   - registered static      — best-effort markup + one "untemplated" warning.
	 *   - unregistered           — error with an actionable hint; still emit a
	 *                              core/html fallback so output is never broken.
	 *
	 * @param string                  $name         Block name.
	 * @param array<string, mixed>    $spec         Original spec (for raw html/plaintext fallbacks).
	 * @param array<string, mixed>    $attributes   Normalized attributes.
	 * @param array<int, ParsedBlock> $inner_blocks Already-built inner blocks.
	 * @param string                  $path         Dotted path for issue messages.
	 * @param array<int, Issue>       $issues       Issues collector (by reference).
	 * @return ParsedBlock|null Parsed-block array, or null when nothing to emit.
	 *
	 * @since 1.2.0
	 */
	private function serialize_untemplated( string $name, array $spec, array $attributes, array $inner_blocks, string $path, array &$issues ): ?array {
		// Explicit, registered core/html is legitimate: emit it as-is, no issue.
		if ( $name === 'core/html' ) {
			$raw = $this->raw_html_from_spec( $spec );

			return $this->make_block( 'core/html', [], $raw, [] );
		}

		// Unregistered/unknown block name — this is an error.
		if ( ! $this->schema->is_registered( $name ) ) {
			return $this->unknown_block_fallback( $name, $spec, $path, $issues );
		}

		// Self-close only a block that stores nothing recoverable. Content lives
		// in inner blocks or raw html/plaintext (best_effort_inner_html), or a
		// markup-sourced attribute (markup_attribute_html); everything else is in
		// the comment JSON that serialize_blocks() always writes. Replaces the old
		// is_dynamic() gate that dropped hybrid blocks' children (e.g. core/cover).
		$inner_html   = $this->best_effort_inner_html( $spec, $inner_blocks );
		$uncapturable = false;

		if ( $inner_html === '' && $inner_blocks === [] ) {
			[ $inner_html, $uncapturable ] = $this->markup_attribute_html( $name, $attributes );
		}

		// Nothing stored in markup: self-closing is lossless.
		if ( $inner_blocks === [] && $inner_html === '' && ! $uncapturable ) {
			return $this->make_block( $name, $attributes, '', $inner_blocks );
		}

		// Content we can't represent as innerHTML (structural source, non-scalar):
		// refuse rather than self-close and drop it with a success response.
		if ( $inner_blocks === [] && $inner_html === '' && $uncapturable ) {
			$issues[] = $this->issue(
				self::SEVERITY_ERROR,
				"{$path}.name '{$name}' stores content in markup this server cannot reproduce (an element attribute or a structured 'query' value), so saving it would silently drop that content. Provide the content as innerBlocks or plaintext, or use a fully supported block (e.g. core/paragraph, core/heading, core/group)."
			);
			return null;
		}

		// Content preserved; only the block's own wrapper is best-effort.
		$issues[] = $this->issue(
			self::SEVERITY_WARNING,
			"{$path}.name '{$name}' is a registered block without dedicated handling. Its content is preserved, but the block's own markup could not be reproduced exactly; verify the result in the editor, or use a fully supported block (e.g. core/paragraph, core/heading, core/group)."
		);

		return $this->make_block( $name, $attributes, $inner_html, $inner_blocks );
	}

	/**
	 * Preserve a block's markup-sourced attribute content when it has no inner
	 * blocks or raw html/plaintext.
	 *
	 * Returns every populated text-sourced attribute's value, as sent, as
	 * innerHTML (core/verse `content`, …). All of them, not the first: a
	 * 'rich-text' one is kept out of the comment JSON, so markup is the only
	 * place it survives. When nothing text-sourced is
	 * capturable, the bool is true if a populated but unreproducible value remains
	 * (structural source or non-scalar) — the signal to refuse rather than
	 * self-close and lose it.
	 *
	 * @param string               $name       Block name.
	 * @param array<string, mixed> $attributes Caller-supplied attributes.
	 * @return array{0: string, 1: bool} [ innerHTML, has-unreproducible-content ].
	 * @since 1.5.0
	 */
	private function markup_attribute_html( string $name, array $attributes ): array {
		$parts = [];

		foreach ( $this->schema->text_sourced_attributes( $name ) as $attribute_name ) {
			$value = $attributes[ $attribute_name ] ?? null;

			if ( is_scalar( $value ) && (string) $value !== '' ) {
				$parts[] = (string) $value;
			}
		}

		if ( $parts !== [] ) {
			return [ implode( "\n", $parts ), false ];
		}

		// No text content: flag any other populated (unreproducible) markup value.
		foreach ( $this->schema->markup_sourced_attributes( $name ) as $attribute_name ) {
			$value = $attributes[ $attribute_name ] ?? null;

			if ( ( is_scalar( $value ) && (string) $value !== '' ) || ( is_array( $value ) && $value !== [] ) ) {
				return [ '', true ];
			}
		}

		return [ '', false ];
	}

	/**
	 * Build a best-effort innerHTML string for an untemplated static block.
	 *
	 * Uses raw HTML / plaintext from the spec, and leaves a `%children%` marker
	 * for inner blocks when present so make_block() can splice them in.
	 *
	 * @param array<string, mixed>    $spec         Original spec.
	 * @param array<int, ParsedBlock> $inner_blocks Already-built inner blocks.
	 * @return string Inner HTML (possibly containing a `%children%` marker).
	 *
	 * @since 1.2.0
	 */
	private function best_effort_inner_html( array $spec, array $inner_blocks ): string {
		$raw = $this->raw_html_from_spec( $spec );

		if ( $inner_blocks !== [] ) {
			return $raw . '%children%';
		}

		return $raw;
	}

	/**
	 * Rebuild a classic-content block from a spec whose name is explicitly null.
	 *
	 * Freeform content is the raw HTML with no block delimiters, so it is built
	 * here rather than through {@see self::make_block()}, which types its name as
	 * a string. Empty content yields null: that is the whitespace separator the
	 * parser emits between blocks, and {@see BlockReader::is_empty_separator()}
	 * already drops it on the way out, so re-emitting it would only add noise.
	 *
	 * @param array<string, mixed> $spec Block spec with a null `name`.
	 * @return ParsedBlock|null Freeform block, or null when there is nothing to keep.
	 *
	 * @since 1.4.0
	 */
	private function classic_block( array $spec ): ?array {
		// Emptiness is decided on the source values rather than through
		// raw_html_from_spec(), which wraps plaintext in a <p> — whitespace-only
		// text would come back through it looking like content.
		$html = $spec['attributes']['html'] ?? $spec['html'] ?? null;
		$raw  = is_string( $html ) && trim( $html ) !== '' ? $html : '';

		if ( $raw === '' ) {
			$text = trim( isset( $spec['plaintext'] ) ? (string) $spec['plaintext'] : '' );
			$raw  = $text === '' ? '' : '<p>' . esc_html( $text ) . '</p>';
		}

		if ( $raw === '' ) {
			return null;
		}

		return [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $raw,
			'innerContent' => [ $raw ],
		];
	}

	/**
	 * Extract raw inner HTML from a spec for html/best-effort serialization.
	 *
	 * @param array<string, mixed> $spec Original spec.
	 * @return string Raw HTML, or an empty string.
	 *
	 * @since 1.2.0
	 */
	private function raw_html_from_spec( array $spec ): string {
		if ( isset( $spec['attributes']['html'] ) && is_string( $spec['attributes']['html'] ) ) {
			return $spec['attributes']['html'];
		}

		if ( isset( $spec['html'] ) && is_string( $spec['html'] ) ) {
			return $spec['html'];
		}

		if ( isset( $spec['plaintext'] ) && is_string( $spec['plaintext'] ) && $spec['plaintext'] !== '' ) {
			return '<p>' . esc_html( $spec['plaintext'] ) . '</p>';
		}

		return '';
	}

	/**
	 * Resolve the innerHTML template callable for a block name.
	 *
	 * @param string $name Block name.
	 * @return (callable(BlockContext): TemplateResult)|null Template callable, or null for untemplated blocks.
	 *
	 * @since 1.2.0
	 */
	private function template_for( string $name ): ?callable {
		$map = [
			'core/paragraph' => [ $this, 'tpl_paragraph' ],
			'core/heading'   => [ $this, 'tpl_heading' ],
			'core/list'      => [ $this, 'tpl_list' ],
			'core/list-item' => [ $this, 'tpl_list_item' ],
			'core/quote'     => [ $this, 'tpl_quote' ],
			'core/image'     => [ $this, 'tpl_image' ],
			'core/columns'   => [ $this, 'tpl_wrapper_columns' ],
			'core/column'    => [ $this, 'tpl_wrapper_column' ],
			'core/group'     => [ $this, 'tpl_wrapper_group' ],
			'core/buttons'   => [ $this, 'tpl_wrapper_buttons' ],
			'core/button'    => [ $this, 'tpl_button' ],
			'core/separator' => [ $this, 'tpl_separator' ],
			'core/spacer'    => [ $this, 'tpl_spacer' ],
			'core/code'      => [ $this, 'tpl_code' ],
			'core/pullquote' => [ $this, 'tpl_pullquote' ],
		];

		return $map[ $name ] ?? null;
	}

	/**
	 * Assemble a parsed-block array, computing innerContent for serialize_blocks().
	 *
	 * The innerContent array interleaves literal HTML strings with null markers
	 * that indicate where each inner block is spliced in. For blocks whose
	 * template already produced the full inner HTML (no nested blocks),
	 * innerContent is just the single HTML string.
	 *
	 * @param string                  $name         Block name.
	 * @param array<string, mixed>    $attrs        Block attributes.
	 * @param string                  $inner_html   The block's own inner HTML.
	 * @param array<int, ParsedBlock> $inner_blocks Already-built inner blocks.
	 * @return ParsedBlock Parsed-block array.
	 *
	 * @since 1.2.0
	 */
	private function make_block( string $name, array $attrs, string $inner_html, array $inner_blocks ): array {
		// Wrapper templates embed a "%children%" placeholder where children
		// belong. We split on it to build the alternating innerContent array.
		if ( str_contains( $inner_html, '%children%' ) && $inner_blocks !== [] ) {
			[ $before, $after ] = explode( '%children%', $inner_html, 2 );

			$inner_content = [ $before ];
			foreach ( $inner_blocks as $unused ) {
				$inner_content[] = null;
			}
			$inner_content[] = $after;

			// The literal innerHTML stored on the block is the wrapper minus markers.
			$flat_inner_html = $before . $after;
		} else {
			$flat_inner_html = str_replace( '%children%', '', $inner_html );
			$inner_content   = $flat_inner_html === '' ? [] : [ $flat_inner_html ];

			// serialize_blocks() emits a child only where innerContent holds a
			// null. Without a %children% marker the children would vanish, so
			// append one null per child.
			foreach ( $inner_blocks as $unused ) {
				$inner_content[] = null;
			}
		}

		// Core validates comment-JSON attributes on every render and raises a
		// notice for a type it doesn't know ('rich-text'). Narrower than the
		// editor, which drops every sourced attribute: BlockReader reads only the
		// comment, so a `url` or `alt` kept there stays visible to view-post.
		$attrs = array_diff_key( $attrs, array_flip( $this->schema->unvalidatable_sourced_attributes( $name ) ) );

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $flat_inner_html,
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Build a core/html fallback for an unregistered/unknown block name.
	 *
	 * Always records an error: an unknown name must never be silently accepted.
	 * When there is content to preserve it is wrapped as core/html so the
	 * serialized output stays valid; otherwise the block is dropped (still an
	 * error, since its content was lost).
	 *
	 * @param string               $name   Original (unknown) block name.
	 * @param array<string, mixed> $spec   Original spec.
	 * @param string               $path   Dotted path for issue messages.
	 * @param array<int, Issue>    $issues Issues collector (by reference).
	 * @return ParsedBlock|null core/html block, or null if nothing to wrap.
	 *
	 * @since 1.2.0
	 */
	private function unknown_block_fallback( string $name, array $spec, string $path, array &$issues ): ?array {
		$raw = $this->raw_html_from_spec( $spec );

		if ( trim( $raw ) === '' ) {
			$issues[] = $this->issue(
				self::SEVERITY_ERROR,
				"{$path}.name '{$name}' is not a registered block and was dropped (no 'html' or 'plaintext' content to preserve). Use a registered block name (e.g. core/paragraph, core/heading) or core/html for raw HTML."
			);
			return null;
		}

		$issues[] = $this->issue(
			self::SEVERITY_ERROR,
			"{$path}.name '{$name}' is not a registered block. Use a registered block name (e.g. core/paragraph, core/heading) or core/html for raw HTML."
		);

		return $this->make_block( 'core/html', [], $raw, [] );
	}

	/**
	 * Round-trip self-check: re-parse the markup and flag dropped top-level names.
	 *
	 * @param array<int, ParsedBlock> $blocks Built blocks (top level).
	 * @param string                  $markup Serialized markup.
	 * @param array<int, Issue>       $issues Issues collector (by reference).
	 * @return void
	 *
	 * @since 1.2.0
	 */
	private function verify_round_trip( array $blocks, string $markup, array &$issues ): void {
		if ( $markup === '' ) {
			return;
		}

		$reparsed = parse_blocks( $markup );

		// Keep only the meaningful (non-empty-freeform) reparsed names.
		$reparsed_names = [];
		foreach ( $reparsed as $block ) {
			$name = $block['blockName'] ?? null;
			if ( $name === null && trim( (string) ( $block['innerHTML'] ?? '' ) ) === '' ) {
				continue;
			}
			$reparsed_names[] = $name;
		}

		$expected_names = array_map( static fn ( $block ) => $block['blockName'], $blocks );

		if ( $expected_names !== $reparsed_names ) {
			$issues[] = $this->issue(
				self::SEVERITY_ERROR,
				sprintf(
					'content round-trip mismatch: expected blocks [%s] but serialized markup re-parsed as [%s]. Check nesting and attributes.',
					implode( ', ', array_map( static fn ( $n ) => (string) $n, $expected_names ) ),
					implode( ', ', array_map( static fn ( $n ) => (string) $n, $reparsed_names ) )
				)
			);
		}
	}

	// Per-block templates
	//
	// Each takes a single BlockContext and returns
	// [ array $attrs, string $inner_html, array $issues ]. Wrapper blocks place
	// a literal "%children%" marker where their inner blocks are spliced in.
	// Template issues are recoverable, so the caller records them as warnings.
	// Each mirrors that block's save(): the wrapper's supports come from
	// BlockSupports, the block's own attributes are written here.

	/**
	 * Template: core/paragraph.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_paragraph( BlockContext $context ): array {
		$attributes = $context->attributes;
		$content    = $this->text_content( $attributes, [ 'content' ], $context->plaintext );
		$text_align = $attributes['style']['typography']['textAlign'] ?? null;
		$end        = function_exists( 'is_rtl' ) && is_rtl() ? 'left' : 'right';
		$classes    = $this->declared_text_align( 'core/paragraph', 'align', $attributes );

		if ( ! empty( $attributes['dropCap'] ) && $text_align !== 'center' && $text_align !== $end ) {
			$classes[] = 'has-drop-cap';
		}

		$wrapper = $this->supports->wrapper(
			'core/paragraph',
			$attributes,
			$classes,
			[],
			[ 'dir' => $this->string_attribute( $attributes, 'direction' ) ]
		);

		return [ $attributes, '<p' . $wrapper . '>' . $content . '</p>', [] ];
	}

	/**
	 * Template: core/heading.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_heading( BlockContext $context ): array {
		$attributes = $context->attributes;
		$issues     = [];

		$level = isset( $attributes['level'] ) ? (int) $attributes['level'] : 2;

		if ( $level < 1 || $level > 6 ) {
			$issues[] = "{$context->path}.attributes.level must be between 1 and 6 (got {$level}). Defaulting to 2.";
			$level    = 2;
		}

		$attributes['level'] = $level;
		$content             = $this->text_content( $attributes, [ 'content' ], $context->plaintext );
		$wrapper             = $this->supports->wrapper( 'core/heading', $attributes, $this->declared_text_align( 'core/heading', 'textAlign', $attributes ) );

		return [ $attributes, sprintf( '<h%1$d%2$s>%3$s</h%1$d>', $level, $wrapper, $content ), $issues ];
	}

	/**
	 * Template: core/list (wrapper around list-item children).
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_list( BlockContext $context ): array {
		$attributes = $context->attributes;
		$ordered    = ! empty( $attributes['ordered'] );
		$tag        = $ordered ? 'ol' : 'ul';
		$type       = $this->string_attribute( $attributes, 'type' );
		$start      = isset( $attributes['start'] ) && is_numeric( $attributes['start'] ) ? (string) (int) $attributes['start'] : null;

		$wrapper = $this->supports->wrapper(
			'core/list',
			$attributes,
			[],
			[ 'list-style-type' => $ordered && $type !== 'decimal' ? (string) $type : '' ],
			[
				'reversed' => ! empty( $attributes['reversed'] ) ? true : null,
				'start'    => $start,
			]
		);

		return [ $attributes, sprintf( '<%1$s%2$s>%%children%%</%1$s>', $tag, $wrapper ), [] ];
	}

	/**
	 * Template: core/list-item; a nested list goes after its text.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_list_item( BlockContext $context ): array {
		$content = $this->text_content( $context->attributes, [ 'content' ], $context->plaintext );

		return [ $context->attributes, '<li' . $this->supports->wrapper( 'core/list-item', $context->attributes ) . '>' . $content . '%children%</li>', [] ];
	}

	/**
	 * Template: core/quote (wrapper).
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_quote( BlockContext $context ): array {
		$attributes = $context->attributes;
		$wrapper    = $this->supports->wrapper( 'core/quote', $attributes, $this->declared_text_align( 'core/quote', 'textAlign', $attributes ) );

		return [ $attributes, '<blockquote' . $wrapper . '>%children%' . $this->citation( $attributes ) . '</blockquote>', [] ];
	}

	/**
	 * Template: core/image.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_image( BlockContext $context ): array {
		$attributes = $context->attributes;
		$issues     = [];

		$url = isset( $attributes['url'] ) ? (string) $attributes['url'] : '';
		if ( $url === '' ) {
			$issues[] = "{$context->path}.attributes.url is required for core/image.";
		}

		// Width and height are strings in the block's schema; the editor drops a number.
		foreach ( [ 'width', 'height' ] as $dimension ) {
			if ( isset( $attributes[ $dimension ] ) && ( is_int( $attributes[ $dimension ] ) || is_float( $attributes[ $dimension ] ) ) ) {
				$attributes[ $dimension ] = $attributes[ $dimension ] . 'px';
			}
		}

		$border = $this->supports->element( $attributes, [ 'border' ] );
		$shadow = $this->supports->element( $attributes, [ 'shadow' ] );
		$width  = $this->string_attribute( $attributes, 'width' );
		$height = $this->string_attribute( $attributes, 'height' );
		$scale  = $this->string_attribute( $attributes, 'scale' );

		$img_style = [
			'aspect-ratio' => (string) $this->string_attribute( $attributes, 'aspectRatio' ),
			'object-fit'   => (string) $scale,
		];

		$focal = $attributes['focalPoint'] ?? null;
		if ( $scale !== null && is_array( $focal ) && isset( $focal['x'], $focal['y'] ) ) {
			$img_style['object-position'] = sprintf( '%d%% %d%%', round( (float) $focal['x'] * 100 ), round( (float) $focal['y'] * 100 ) );
		}

		if ( $width !== null || $height !== null ) {
			$img_style['width']  = (string) $width;
			$img_style['height'] = $height ?? 'auto';
		}

		$id        = isset( $attributes['id'] ) ? (int) $attributes['id'] : 0;
		$img_class = array_merge( $border['class'], $id > 0 ? [ 'wp-image-' . $id ] : [] );

		// alt is always written, empty or not, as the editor does.
		$img = sprintf( '<img src="%s" alt="%s"', esc_url( $url ), esc_attr( (string) ( $attributes['alt'] ?? '' ) ) )
			. $this->supports->html_attributes(
				[
					'class' => implode( ' ', $img_class ),
					'style' => $border['style'] . $shadow['style'] . $this->declarations( $img_style ),
					'title' => $this->string_attribute( $attributes, 'title' ),
					'role'  => ! empty( $attributes['isDecorative'] ) ? 'none' : null,
				]
			) . '/>';

		$href = $this->string_attribute( $attributes, 'href' );
		if ( $href !== null ) {
			$img = '<a' . $this->supports->html_attributes(
				[
					'class'  => $this->string_attribute( $attributes, 'linkClass' ),
					'href'   => esc_url( $href ),
					'target' => $this->string_attribute( $attributes, 'linkTarget' ),
					'rel'    => $this->string_attribute( $attributes, 'rel' ),
				]
			) . '>' . $img . '</a>';
		}

		$caption = '';
		if ( isset( $attributes['caption'] ) && is_scalar( $attributes['caption'] ) && (string) $attributes['caption'] !== '' ) {
			$caption = '<figcaption class="wp-element-caption">' . (string) $attributes['caption'] . '</figcaption>';
		}

		$figure_classes = [
			( $attributes['align'] ?? null ) === 'none' ? 'alignnone' : '',
			$this->string_attribute( $attributes, 'sizeSlug' ) !== null ? 'size-' . $attributes['sizeSlug'] : '',
			$width !== null || $height !== null ? 'is-resized' : '',
			$border['class'] !== [] || $border['style'] !== '' ? 'has-custom-border' : '',
		];

		$wrapper = $this->supports->wrapper( 'core/image', $attributes, $figure_classes );

		return [ $attributes, '<figure' . $wrapper . '>' . $img . $caption . '</figure>', $issues ];
	}

	/**
	 * Template: core/columns (wrapper).
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_wrapper_columns( BlockContext $context ): array {
		$attributes = $context->attributes;
		$align      = $this->string_attribute( $attributes, 'verticalAlignment' );
		$classes    = [
			$align !== null ? 'are-vertically-aligned-' . $align : '',
			( $attributes['isStackedOnMobile'] ?? true ) === false ? 'is-not-stacked-on-mobile' : '',
		];

		return [ $attributes, '<div' . $this->supports->wrapper( 'core/columns', $attributes, $classes ) . '>%children%</div>', [] ];
	}

	/**
	 * Template: core/column (wrapper).
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_wrapper_column( BlockContext $context ): array {
		$attributes = $context->attributes;
		$align      = $this->string_attribute( $attributes, 'verticalAlignment' );
		$width      = $attributes['width'] ?? null;
		$basis      = '';

		if ( is_int( $width ) || is_float( $width ) ) {
			$basis = $width . '%';
		} elseif ( is_string( $width ) && preg_match( '/\d/', $width ) ) {
			$basis = str_ends_with( $width, '%' ) ? round( (float) $width, 12 ) . '%' : $width;
		}

		$wrapper = $this->supports->wrapper(
			'core/column',
			$attributes,
			[ $align !== null ? 'is-vertically-aligned-' . $align : '' ],
			[ 'flex-basis' => $basis ]
		);

		return [ $attributes, '<div' . $wrapper . '>%children%</div>', [] ];
	}

	/**
	 * Template: core/group (wrapper).
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_wrapper_group( BlockContext $context ): array {
		$attributes = $context->attributes;

		// Restrict tagName to Gutenberg's core/group allowlist so a hostile or
		// mistaken value (e.g. "script") can never produce an executable wrapper
		// element. Anything outside the allowlist falls back to <div>.
		$allowed = [ 'div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'figure' ];
		$tag     = isset( $attributes['tagName'] ) ? strtolower( (string) $attributes['tagName'] ) : 'div';
		if ( ! in_array( $tag, $allowed, true ) ) {
			$tag = 'div';
		}

		return [ $attributes, sprintf( '<%1$s%2$s>%%children%%</%1$s>', $tag, $this->supports->wrapper( 'core/group', $attributes ) ), [] ];
	}

	/**
	 * Template: core/buttons (wrapper).
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_wrapper_buttons( BlockContext $context ): array {
		$attributes = $context->attributes;
		$classes    = [ $this->has_custom_font_size( $attributes ) ? 'has-custom-font-size' : '' ];

		return [ $attributes, '<div' . $this->supports->wrapper( 'core/buttons', $attributes, $classes ) . '>%children%</div>', [] ];
	}

	/**
	 * Template: core/button. Colour, border, spacing, shadow and typography go on
	 * the link, not the wrapper.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_button( BlockContext $context ): array {
		$attributes = $context->attributes;
		$text       = $this->text_content( $attributes, [ 'text' ], $context->plaintext );
		$is_button  = ( $attributes['tagName'] ?? 'a' ) === 'button';
		$element    = $this->supports->element( $attributes, [ 'border', 'color', 'spacing', 'shadow', 'typography' ] );
		$url        = $this->string_attribute( $attributes, 'url' );

		$classes = array_merge(
			[ 'wp-block-button__link' ],
			$element['class'],
			[
				( $attributes['style']['border']['radius'] ?? null ) === 0 ? 'no-border-radius' : '',
				$this->has_custom_font_size( $attributes ) ? 'has-custom-font-size' : '',
				'wp-element-button',
			]
		);

		$link = $this->supports->html_attributes(
			[
				'type'   => $is_button ? ( $this->string_attribute( $attributes, 'type' ) ?? 'button' ) : null,
				'class'  => implode( ' ', array_filter( $classes ) ),
				'href'   => ! $is_button && $url !== null ? esc_url( $url ) : null,
				'title'  => $this->string_attribute( $attributes, 'title' ),
				'style'  => $element['style'],
				'target' => $is_button ? null : $this->string_attribute( $attributes, 'linkTarget' ),
				'rel'    => $is_button ? null : $this->string_attribute( $attributes, 'rel' ),
			]
		);

		$tag     = $is_button ? 'button' : 'a';
		$width   = $this->declares( 'core/button', 'width' ) && is_numeric( $attributes['width'] ?? null ) ? (string) $attributes['width'] : null;
		$wrapper = $this->supports->wrapper(
			'core/button',
			$attributes,
			$width !== null ? [ 'has-custom-width', 'wp-block-button__width-' . $width ] : []
		);

		return [ $attributes, '<div' . $wrapper . '><' . $tag . $link . '>' . $text . '</' . $tag . '></div>', [] ];
	}

	/**
	 * Template: core/separator. Its colour is written by hand, as its save() does.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_separator( BlockContext $context ): array {
		$attributes = $context->attributes;
		$background = $this->string_attribute( $attributes, 'backgroundColor' );
		$custom     = $attributes['style']['color']['background'] ?? null;
		$custom     = is_string( $custom ) && $custom !== '' ? $this->css_value( $custom ) : null;
		$opacity    = $attributes['opacity'] ?? 'alpha-channel';
		$color      = $this->supports->element( $attributes, [ 'color' ] );
		$tag        = ( $attributes['tagName'] ?? 'hr' ) === 'div' ? 'div' : 'hr';

		$classes = array_merge(
			[
				$background !== null || $custom !== null ? 'has-text-color' : '',
				$background !== null ? 'has-' . $background . '-color' : '',
				$opacity === 'css' ? 'has-css-opacity' : '',
				$opacity === 'alpha-channel' ? 'has-alpha-channel-opacity' : '',
			],
			$color['class']
		);

		$wrapper = $this->supports->wrapper(
			'core/separator',
			$attributes,
			$classes,
			[
				'background-color' => (string) $custom,
				'color'            => $background === null ? (string) $custom : '',
			]
		);

		return [ $attributes, '<' . $tag . $wrapper . ( $tag === 'hr' ? '/>' : '></div>' ), [] ];
	}

	/**
	 * Template: core/spacer.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_spacer( BlockContext $context ): array {
		$attributes = $context->attributes;
		$stretch    = $attributes['style']['layout']['selfStretch'] ?? null;
		$height     = isset( $attributes['height'] ) ? (string) $attributes['height'] : '100px';
		$width      = isset( $attributes['width'] ) ? (string) $attributes['width'] : '';

		$wrapper = $this->supports->wrapper(
			'core/spacer',
			$attributes,
			[],
			[
				'height' => $stretch === 'fill' || $stretch === 'fit' ? '' : $this->css_value( $height ),
				'width'  => $this->css_value( $width ),
			],
			[ 'aria-hidden' => 'true' ]
		);

		return [ $attributes, '<div' . $wrapper . '></div>', [] ];
	}

	/**
	 * Template: core/code.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_code( BlockContext $context ): array {
		// core/code content is literal source, never rich text — always escape it
		// (whether from the `content` attribute or the plaintext fallback) so
		// inline HTML is shown verbatim rather than interpreted.
		$content = $this->plain_text( $context->attributes, [ 'content' ], $context->plaintext );

		return [ $context->attributes, '<pre' . $this->supports->wrapper( 'core/code', $context->attributes ) . '><code>' . esc_html( $content ) . '</code></pre>', [] ];
	}

	/**
	 * Template: core/pullquote.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_pullquote( BlockContext $context ): array {
		$attributes = $context->attributes;
		$value      = $this->text_content( $attributes, [ 'value' ], $context->plaintext );
		$wrapper    = $this->supports->wrapper( 'core/pullquote', $attributes, $this->declared_text_align( 'core/pullquote', 'textAlign', $attributes ) );

		return [
			$attributes,
			'<figure' . $wrapper . '><blockquote><p>' . $value . '</p>' . $this->citation( $attributes ) . '</blockquote></figure>',
			[],
		];
	}

	/**
	 * A quote's citation element, or ''.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function citation( array $attributes ): string {
		$citation = $attributes['citation'] ?? null;

		return is_scalar( $citation ) && (string) $citation !== '' ? '<cite>' . (string) $citation . '</cite>' : '';
	}

	/**
	 * The `has-text-align-*` class for a text alignment the block declares as
	 * its own attribute (WordPress 6.9; later versions use a support).
	 *
	 * @param string               $name       Block name.
	 * @param string               $attribute  Attribute holding the alignment.
	 * @param array<string, mixed> $attributes Attributes.
	 * @return array<int, string>
	 *
	 * @since 1.6.0
	 */
	private function declared_text_align( string $name, string $attribute, array $attributes ): array {
		$align = $this->string_attribute( $attributes, $attribute );

		if ( $align === null || ! $this->declares( $name, $attribute ) ) {
			return [];
		}

		// Paragraph `align` is text alignment only where no align support exists.
		$type = $this->schema->block_type( $name );
		if ( $attribute === 'align' && is_object( $type ) && ! empty( $type->supports['align'] ) ) {
			return [];
		}

		return [ 'has-text-align-' . $align ];
	}

	/**
	 * Whether the editor marks a block as having a custom font size.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return bool
	 *
	 * @since 1.6.0
	 */
	private function has_custom_font_size( array $attributes ): bool {
		return ! empty( $attributes['fontSize'] ) || ! empty( $attributes['style']['typography']['fontSize'] );
	}

	/**
	 * A non-empty scalar attribute as a string, or null.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $key        Attribute name.
	 * @return string|null
	 *
	 * @since 1.6.0
	 */
	private function string_attribute( array $attributes, string $key ): ?string {
		$value = $attributes[ $key ] ?? null;

		return is_scalar( $value ) && ! is_bool( $value ) && (string) $value !== '' ? (string) $value : null;
	}

	/**
	 * A CSS value, with a `var:preset|kind|slug` reference turned into its custom property.
	 *
	 * @param string $value Raw value.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function css_value( string $value ): string {
		if ( preg_match( '/^var:preset\|([a-z0-9-]+)\|([a-zA-Z0-9-]+)$/', $value, $match ) ) {
			return 'var(--wp--preset--' . $match[1] . '--' . $match[2] . ')';
		}

		return $value;
	}

	/**
	 * Render CSS declarations, skipping empty values.
	 *
	 * @param array<string, string> $declarations Property => value.
	 * @return string
	 *
	 * @since 1.6.0
	 */
	private function declarations( array $declarations ): string {
		$css = '';

		foreach ( $declarations as $property => $value ) {
			if ( $value !== '' ) {
				$css .= $property . ':' . $value . ';';
			}
		}

		return $css;
	}

	/**
	 * Resolve and render text content for a block's innerHTML.
	 *
	 * A rich-text attribute value is HTML and goes in as sent: the REST save
	 * applies core's capability-aware KSES, as a normal editor save does, so a
	 * second, stricter filter here would strip tags the user may store (a
	 * plugin's `<ta>`). The plaintext fallback is plain text, so it is escaped.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param array<int, string>   $keys       Candidate attribute keys (in priority order).
	 * @param string               $plaintext  Fallback plaintext.
	 * @return string Render-ready HTML for the block's innerHTML.
	 *
	 * @since 1.2.0
	 * @since 1.6.0 Rich-text values are no longer filtered.
	 */
	private function text_content( array $attributes, array $keys, string $plaintext ): string {
		foreach ( $keys as $key ) {
			if ( isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ) {
				return (string) $attributes[ $key ];
			}
		}

		return esc_html( $plaintext );
	}

	/**
	 * Resolve plain (non-rich-text) content from the first matching attribute
	 * key, falling back to the spec's plaintext.
	 *
	 * Unlike {@see text_content()} this returns the raw resolved string without
	 * escaping; the caller is responsible for escaping it (e.g. core/code, whose
	 * content is literal source that must be shown verbatim).
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param array<int, string>   $keys       Candidate attribute keys (in priority order).
	 * @param string               $plaintext  Fallback plaintext.
	 * @return string Resolved raw content (unescaped).
	 *
	 * @since 1.2.0
	 */
	private function plain_text( array $attributes, array $keys, string $plaintext ): string {
		foreach ( $keys as $key ) {
			if ( isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ) {
				return (string) $attributes[ $key ];
			}
		}

		return $plaintext;
	}
}
