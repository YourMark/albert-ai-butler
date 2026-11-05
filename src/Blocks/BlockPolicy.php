<?php
/**
 * Block Policy
 *
 * Write-time enforcement of the per-user allowed-block set. Where BlockCatalog
 * answers "which blocks may this user use", BlockPolicy applies that answer to a
 * tree of submitted block specs: it collects every block name in the tree
 * (recursively, including innerBlocks), checks each distinct name against
 * BlockCatalog::is_allowed() for the current user and context, and turns any
 * disallowed name into a block-validation error so the write ability can reject
 * the save before anything is persisted.
 *
 * Enforcement is gated by the `albert/blocks/enforce_allowed` filter so a site
 * can opt out entirely.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the allowed-block set to submitted block specs at write time.
 *
 * @phpstan-type Issue array{severity: string, message: string}
 *
 * @since 1.2.0
 */
class BlockPolicy {

	/**
	 * Allowed-block catalog for the current user.
	 *
	 * @since 1.2.0
	 * @var BlockCatalog
	 */
	private BlockCatalog $catalog;

	/**
	 * Block reader, used to collect block names from existing post content.
	 *
	 * @since 1.2.0
	 * @var BlockReader
	 */
	private BlockReader $reader;

	/**
	 * Constructor.
	 *
	 * @param BlockCatalog|null $catalog Optional catalog service (injectable for tests).
	 * @param BlockReader|null  $reader  Optional block reader (injectable for tests).
	 *
	 * @since 1.2.0
	 */
	public function __construct( ?BlockCatalog $catalog = null, ?BlockReader $reader = null ) {
		$this->catalog = $catalog ?? new BlockCatalog();
		$this->reader  = $reader ?? new BlockReader();
	}

	/**
	 * Build block-validation error issues for any disallowed block in the specs.
	 *
	 * Collects every distinct block name in the submitted spec tree and checks
	 * each against the current user's allowed set for the given context. Names
	 * listed in $exempt_names are skipped (used on Update to grandfather blocks
	 * already present in the post). Returns one error-severity issue per
	 * disallowed name, shaped exactly like BlockSerializer issues so the caller
	 * can hand them straight to {@see BlockIssues::to_wp_error()}.
	 *
	 * The update exemption is by block **name**, not per instance. If a now-
	 * disallowed block type already exists anywhere in the target post, every
	 * instance of that same type is permitted on this update — including any new
	 * instances the submitted specs add. This is intentional for the
	 * whole-content-replace edit model: a write replaces the entire post body, so
	 * there is no stable per-instance identity to grandfather individually. The
	 * grandfathered set is the distinct names already present in the post, and the
	 * exemption follows the name, not a specific occurrence of it.
	 *
	 * When enforcement is disabled via the `albert/blocks/enforce_allowed`
	 * filter, or the input is not a list of specs, an empty list is returned.
	 *
	 * @param array<int, array<string, mixed>>|mixed $blocks       Submitted block specs.
	 * @param string|null                            $post_type    Post type for the editor context ('post' or 'page').
	 * @param int|null                               $post_id      Target post ID on Update, null on Create.
	 * @param array<int, string>                     $exempt_names Block names to exempt from enforcement.
	 *
	 * @return array<int, Issue> Error-severity issues for disallowed blocks (empty when all allowed).
	 * @since 1.2.0
	 */
	public function enforce( $blocks, ?string $post_type = null, ?int $post_id = null, array $exempt_names = [] ): array {
		/**
		 * Filters whether write-time block enforcement is active.
		 *
		 * When false, write abilities skip the allowed-block check entirely and
		 * any registered block may be saved, regardless of the per-user
		 * allow-list. Defaults to true.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enforce Whether to enforce the allowed-block set on writes.
		 */
		$enforce = (bool) apply_filters( 'albert/blocks/enforce_allowed', true );

		if ( ! $enforce || ! is_array( $blocks ) ) {
			return [];
		}

		$names  = $this->collect_names( $blocks );
		$exempt = array_flip( array_map( 'strval', $exempt_names ) );
		$issues = [];

		foreach ( $names as $name ) {
			if ( isset( $exempt[ $name ] ) ) {
				continue;
			}

			if ( $this->catalog->is_allowed( $name, $post_type, $post_id ) ) {
				continue;
			}

			$issues[] = [
				'severity' => BlockSerializer::SEVERITY_ERROR,
				'message'  => sprintf(
					/* translators: %s: block type name, e.g. core/cover. */
					__( "Block '%s' is not available to you on this site. Call albert/list-block-types to see the blocks you may use, then replace it.", 'albert-ai-butler' ),
					$name
				),
			];
		}

		return $issues;
	}

	/**
	 * Collect the distinct block names already present in a post's content.
	 *
	 * Used by Update enforcement to exempt blocks that are part of the existing
	 * post, so a now-disallowed legacy block does not prevent further edits. A
	 * non-positive post ID, a missing post, or empty content all yield an empty
	 * list.
	 *
	 * @param int|null $post_id Target post ID.
	 *
	 * @return array<int, string> Distinct block names found in the existing content.
	 * @since 1.2.0
	 */
	public function existing_block_names( ?int $post_id ): array {
		if ( $post_id === null || $post_id < 1 || ! function_exists( 'get_post' ) ) {
			return [];
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return [];
		}

		return $this->collect_names_from_tree( $this->reader->read( (string) $post->post_content ) );
	}

	/**
	 * Collect every distinct block name from a tree of submitted block specs.
	 *
	 * Walks innerBlocks, and block markup in `html`, recursively. Block names are
	 * read from the `name` (or legacy `blockName`) key, so a parse_blocks() tree
	 * walks too; blank or non-string names are ignored: the
	 * serializer already reports those as their own errors.
	 *
	 * @param array<int, mixed> $specs Block specs.
	 *
	 * @return array<int, string> Distinct, non-empty block names.
	 * @since 1.2.0
	 */
	private function collect_names( array $specs ): array {
		$names = [];

		$this->walk_specs( $specs, $names );

		return array_values( array_unique( $names ) );
	}

	/**
	 * Recursively gather block names from a list of specs into $names.
	 *
	 * @param array<int, mixed>  $specs Block specs.
	 * @param array<int, string> $names Collector (by reference).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function walk_specs( array $specs, array &$names ): void {
		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}

			$name = $spec['name'] ?? $spec['blockName'] ?? null;
			$name = is_string( $name ) ? trim( $name ) : '';

			if ( $name !== '' ) {
				$names[] = $name;
			}

			$inner = $spec['innerBlocks'] ?? [];
			if ( is_array( $inner ) ) {
				$this->walk_specs( $inner, $names );
			}

			// Block markup sent in `html` becomes inner blocks too.
			$html = $spec['html'] ?? null;
			if ( is_string( $html ) && has_blocks( $html ) ) {
				$this->walk_specs( array_values( parse_blocks( $html ) ), $names );
			}
		}
	}

	/**
	 * Collect distinct block names from a shaped BlockReader tree.
	 *
	 * @param array<int, array<string, mixed>> $tree Shaped block tree.
	 *
	 * @return array<int, string> Distinct, non-empty block names.
	 * @since 1.2.0
	 */
	private function collect_names_from_tree( array $tree ): array {
		$names = [];

		$this->walk_tree( $tree, $names );

		return array_values( array_unique( $names ) );
	}

	/**
	 * Recursively gather block names from a shaped tree into $names.
	 *
	 * @param array<int, array<string, mixed>> $tree  Shaped block tree.
	 * @param array<int, string>               $names Collector (by reference).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function walk_tree( array $tree, array &$names ): void {
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name = $node['name'] ?? null;
			if ( is_string( $name ) && $name !== '' ) {
				$names[] = $name;
			}

			$inner = $node['innerBlocks'] ?? [];
			if ( is_array( $inner ) ) {
				$this->walk_tree( $inner, $names );
			}
		}
	}
}
