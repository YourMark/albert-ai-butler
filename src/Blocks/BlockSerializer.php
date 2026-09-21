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
	 * Construct the serializer.
	 *
	 * @param BlockSchema|null $schema Optional registry reader (defaults to a fresh instance).
	 *
	 * @since 1.2.0
	 */
	public function __construct( ?BlockSchema $schema = null ) {
		$this->schema = $schema ?? new BlockSchema();
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
		// Defensive fallback: a string is routed through BlockConverter, which
		// turns HTML/Markdown into block markup (and passes existing block
		// markup through untouched).
		if ( is_string( $input ) ) {
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
			"{$path}.name '{$name}' is a registered block without dedicated handling — its content is preserved, but the block's own markup could not be reproduced exactly; verify the result in the editor, or use a fully supported block (e.g. core/paragraph, core/heading, core/group)."
		);

		return $this->make_block( $name, $attributes, $inner_html, $inner_blocks );
	}

	/**
	 * Preserve a block's markup-sourced attribute content when it has no inner
	 * blocks or raw html/plaintext.
	 *
	 * Returns the first populated text-sourced attribute's sanitised value as
	 * innerHTML (core/verse `content`, …). When nothing text-sourced is
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
		foreach ( $this->schema->text_sourced_attributes( $name ) as $attribute_name ) {
			$value = $attributes[ $attribute_name ] ?? null;

			if ( is_scalar( $value ) && (string) $value !== '' ) {
				return [ $this->rich_text( (string) $value ), false ];
			}
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

	/**
	 * Template: core/paragraph.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_paragraph( BlockContext $context ): array {
		$content = $this->text_content( $context->attributes, [ 'content' ], $context->plaintext );

		return [ $context->attributes, '<p>' . $content . '</p>', [] ];
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

		return [ $attributes, sprintf( '<h%1$d class="wp-block-heading">%2$s</h%1$d>', $level, $content ), $issues ];
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
		$ordered = ! empty( $context->attributes['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';

		// core/list is a dynamic block: its render_callback injects the
		// `wp-block-list` class at render time, so the stored markup must be a
		// bare <ul>/<ol> without that class. Emitting it here would make the
		// editor flag the block as having invalid content.
		// NOTE: still warrants a manual "invalid content" check in the editor.
		return [ $context->attributes, sprintf( '<%1$s>%%children%%</%1$s>', $tag ), [] ];
	}

	/**
	 * Template: core/list-item.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_list_item( BlockContext $context ): array {
		$content = $this->text_content( $context->attributes, [ 'content' ], $context->plaintext );

		return [ $context->attributes, '<li>' . $content . '</li>', [] ];
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

		$citation = '';
		if ( isset( $attributes['citation'] ) && (string) $attributes['citation'] !== '' ) {
			$citation = '<cite>' . $this->rich_text( (string) $attributes['citation'] ) . '</cite>';
		}

		return [ $attributes, '<blockquote class="wp-block-quote">%children%' . $citation . '</blockquote>', [] ];
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
		$alt = isset( $attributes['alt'] ) ? (string) $attributes['alt'] : '';

		if ( $url === '' ) {
			$issues[] = "{$context->path}.attributes.url is required for core/image.";
		}

		$id_class = '';
		if ( isset( $attributes['id'] ) && (int) $attributes['id'] > 0 ) {
			$id_class = ' class="wp-image-' . (int) $attributes['id'] . '"';
		}

		$img = sprintf(
			'<img src="%s" alt="%s"%s/>',
			esc_url( $url ),
			esc_attr( $alt ),
			$id_class
		);

		$caption = '';
		if ( isset( $attributes['caption'] ) && (string) $attributes['caption'] !== '' ) {
			$caption = '<figcaption class="wp-element-caption">' . esc_html( (string) $attributes['caption'] ) . '</figcaption>';
		}

		return [ $attributes, '<figure class="wp-block-image">' . $img . $caption . '</figure>', $issues ];
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
		return [ $context->attributes, '<div class="wp-block-columns">%children%</div>', [] ];
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
		return [ $context->attributes, '<div class="wp-block-column">%children%</div>', [] ];
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

		return [ $attributes, sprintf( '<%1$s class="wp-block-group">%%children%%</%1$s>', $tag ), [] ];
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
		return [ $context->attributes, '<div class="wp-block-buttons">%children%</div>', [] ];
	}

	/**
	 * Template: core/button.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_button( BlockContext $context ): array {
		$attributes = $context->attributes;

		$text = $this->text_content( $attributes, [ 'text' ], $context->plaintext );
		$url  = isset( $attributes['url'] ) ? (string) $attributes['url'] : '';

		$href = $url !== '' ? sprintf( ' href="%s"', esc_url( $url ) ) : '';

		$link = sprintf(
			'<a class="wp-block-button__link wp-element-button"%s>%s</a>',
			$href,
			$text
		);

		return [ $attributes, '<div class="wp-block-button">' . $link . '</div>', [] ];
	}

	/**
	 * Template: core/separator.
	 *
	 * @param BlockContext $context Block context.
	 * @return array{0: array<string, mixed>, 1: string, 2: array<int, string>}
	 *
	 * @since 1.2.0
	 */
	private function tpl_separator( BlockContext $context ): array {
		return [ $context->attributes, '<hr class="wp-block-separator has-alpha-channel-opacity"/>', [] ];
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

		$height = isset( $attributes['height'] ) ? (string) $attributes['height'] : '100px';

		return [
			$attributes,
			sprintf(
				'<div style="height:%s" aria-hidden="true" class="wp-block-spacer"></div>',
				esc_attr( $height )
			),
			[],
		];
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

		return [ $context->attributes, '<pre class="wp-block-code"><code>' . esc_html( $content ) . '</code></pre>', [] ];
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

		$value = $this->text_content( $attributes, [ 'value' ], $context->plaintext );

		$citation = '';
		if ( isset( $attributes['citation'] ) && (string) $attributes['citation'] !== '' ) {
			$citation = '<cite>' . $this->rich_text( (string) $attributes['citation'] ) . '</cite>';
		}

		return [
			$attributes,
			'<figure class="wp-block-pullquote"><blockquote><p>' . $value . '</p>' . $citation . '</blockquote></figure>',
			[],
		];
	}

	/**
	 * Resolve and render text content for a block's innerHTML.
	 *
	 * Rich-text attributes (core/paragraph|heading|list-item `content`,
	 * core/button `text`, core/pullquote `value`) legitimately contain inline
	 * HTML (e.g. <strong>, <em>, <a href>, <code>). When the value comes from such
	 * an attribute it is sanitised with {@see rich_text()} (a tight inline
	 * wp_kses allowlist) so that formatting survives the read→write round trip
	 * while dangerous markup (script tags, on* handlers, javascript: URLs) is
	 * still stripped. When no attribute is present the plaintext fallback is
	 * guaranteed plain, so it is escaped with esc_html().
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param array<int, string>   $keys       Candidate attribute keys (in priority order).
	 * @param string               $plaintext  Fallback plaintext.
	 * @return string Render-ready HTML for the block's innerHTML.
	 *
	 * @since 1.2.0
	 */
	private function text_content( array $attributes, array $keys, string $plaintext ): string {
		foreach ( $keys as $key ) {
			if ( isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ) {
				return $this->rich_text( (string) $attributes[ $key ] );
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

	/**
	 * Sanitise a rich-text attribute value against a tight inline HTML allowlist.
	 *
	 * Only inline formatting tags are permitted — no block-level tags (unlike
	 * wp_kses_post) — so the result is safe to splice into a block's innerHTML.
	 * wp_kses also strips dangerous protocols (javascript:) and on* event
	 * handlers, which is the security benefit retained here.
	 *
	 * @param string $value Raw rich-text attribute value.
	 * @return string Sanitised inline HTML.
	 *
	 * @since 1.2.0
	 */
	private function rich_text( string $value ): string {
		$allowed = [
			'a'      => [
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			],
			'strong' => [],
			'b'      => [],
			'em'     => [],
			'i'      => [],
			'code'   => [],
			's'      => [],
			'del'    => [],
			'ins'    => [],
			'sub'    => [],
			'sup'    => [],
			'mark'   => [],
			'kbd'    => [],
			'abbr'   => [ 'title' => true ],
			'br'     => [],
			'span'   => [ 'class' => true ],
		];

		return wp_kses( $value, $allowed );
	}
}
