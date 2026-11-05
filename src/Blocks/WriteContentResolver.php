<?php
/**
 * Write Content Resolver
 *
 * Single decision point for turning a write ability's `content`/`blocks` input
 * into the content string to store, shared by Posts and Pages Create/Update.
 *
 * The four write abilities previously repeated the same branch: detect the
 * editor for the target, reject structured `blocks` on a classic-editor target,
 * serialize `blocks`/`content` to valid block markup on a block-editor target,
 * enforce the per-user allowed-block set (with an Update exemption for blocks
 * already present in the post), partition the resulting issues into fatal errors
 * and non-fatal warnings, and either abort with a WP_Error or return the markup
 * plus its warnings. That whole decision now lives here.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the content a write ability should store from its `content`/`blocks` input.
 *
 * @since 1.2.0
 */
class WriteContentResolver {

	/**
	 * Block serializer used to turn specs (or a string) into block markup.
	 *
	 * @since 1.2.0
	 * @var BlockSerializer
	 */
	private BlockSerializer $serializer;

	/**
	 * Allowed-block policy used to reject blocks the user may not use.
	 *
	 * @since 1.2.0
	 * @var BlockPolicy
	 */
	private BlockPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param BlockSerializer|null $serializer Optional serializer (injectable for tests).
	 * @param BlockPolicy|null     $policy     Optional policy (injectable for tests).
	 *
	 * @since 1.2.0
	 */
	public function __construct( ?BlockSerializer $serializer = null, ?BlockPolicy $policy = null ) {
		$this->serializer = $serializer ?? new BlockSerializer();
		$this->policy     = $policy ?? new BlockPolicy();
	}

	/**
	 * Resolve the content string a write ability should store.
	 *
	 * On a classic-editor target a non-empty structured `blocks` field is
	 * rejected (the classic editor stores plain HTML, so block specs make no
	 * sense) and the raw `content` HTML is passed straight through. On a
	 * block-editor target the input is serialized to valid block markup —
	 * structured `blocks` take precedence over the `content` string — the
	 * allowed-block set is enforced (exempting blocks already present in the post
	 * on Update), and any fatal block issue aborts with a WP_Error while
	 * recoverable warnings ride along in `block_issues`.
	 *
	 * @param array<string, mixed> $args      Ability input. Reads `content` (string) and `blocks` (array of specs).
	 * @param string               $post_type Post type slug for the editor context ('post' or 'page').
	 * @param int|null             $post_id   Target post ID on Update, null on Create.
	 *
	 * @return array{content: string, block_issues: array<int, string>}|WP_Error Resolved content and warnings, or a WP_Error to return instead of saving.
	 * @since 1.2.0
	 */
	public function resolve( array $args, string $post_type, ?int $post_id = null ): array|WP_Error {
		$has_blocks_input = ! empty( $args['blocks'] ) && is_array( $args['blocks'] );

		if ( ! EditorMode::is_block_editor( $post_type, $post_id ) ) {
			return $this->resolve_classic( $args, $post_type, $has_blocks_input );
		}

		return $this->resolve_block( $args, $post_type, $post_id, $has_blocks_input );
	}

	/**
	 * Resolve the content string a block pattern (a wp_block) should store.
	 *
	 * A pattern is always block content, so there is no classic-editor branch: it
	 * is not tied to a post type and is not narrowed to a post's or page's allowed
	 * block set. Enforcement runs against the site-wide allowed set (a block a site
	 * removes globally is still rejected), and serialization and warning handling
	 * are the same as the post/page block path.
	 *
	 * @param array<string, mixed> $args    Ability input. Reads `content` (string) and `blocks` (array of specs).
	 * @param int|null             $post_id Target wp_block ID on Update, null on Create.
	 *
	 * @return array{content: string, block_issues: array<int, string>}|WP_Error Resolved content and warnings, or a WP_Error to return instead of saving.
	 * @since 1.5.0
	 */
	public function resolve_pattern( array $args, ?int $post_id = null ): array|WP_Error {
		$has_blocks_input = ! empty( $args['blocks'] ) && is_array( $args['blocks'] );

		return $this->resolve_block( $args, null, $post_id, $has_blocks_input );
	}

	/**
	 * Resolve content for a classic-editor target.
	 *
	 * Structured `blocks` do not apply to the classic editor, so a non-empty
	 * `blocks` field is rejected up front. Otherwise the raw `content` HTML is
	 * returned verbatim (the REST endpoint still runs wp_kses_post per the user's
	 * caps) and there are never any block issues.
	 *
	 * @param array<string, mixed> $args             Ability input.
	 * @param string               $post_type        Post type slug.
	 * @param bool                 $has_blocks_input Whether a non-empty `blocks` field was sent.
	 *
	 * @return array{content: string, block_issues: array<int, string>}|WP_Error Resolved content, or a WP_Error.
	 * @since 1.2.0
	 */
	private function resolve_classic( array $args, string $post_type, bool $has_blocks_input ): array|WP_Error {
		if ( $has_blocks_input ) {
			return new WP_Error(
				'classic_editor_blocks_unsupported',
				sprintf(
					/* translators: %s: post type label (e.g. post or page). */
					__( 'This %s uses the classic editor. Send HTML in the `content` field instead of structured `blocks`.', 'albert-ai-butler' ),
					$post_type
				),
				[ 'status' => 400 ]
			);
		}

		// Return the raw HTML as-is. It is NOT sanitised here on purpose: the write
		// ability persists it through the REST endpoint (wp_insert_post), which runs
		// capability-aware KSES (wp_filter_post_kses for users without
		// `unfiltered_html`) — the same protection the classic editor relies on.
		// Re-running wp_kses_post() here would ignore the user's capability and strip
		// HTML privileged users are allowed to store. Keep persistence on the REST
		// path so that KSES layer always applies.
		return [
			'content'      => (string) ( $args['content'] ?? '' ),
			'block_issues' => [],
		];
	}

	/**
	 * Resolve content for a block-editor target.
	 *
	 * Structured `blocks` take precedence over the `content` string; both routes
	 * go through {@see BlockSerializer::serialize_with_issues()} (specs → markup,
	 * or a string → BlockConverter fallback for HTML/Markdown). Allowed-block
	 * enforcement runs on submitted specs, exempting block names already present
	 * in the post on Update. Enforcement and serializer errors both abort the
	 * save; only warnings ride along.
	 *
	 * @param array<string, mixed> $args             Ability input.
	 * @param string|null          $post_type        Post type slug for the editor context, or null for the site-wide (pattern) scope.
	 * @param int|null             $post_id          Target post ID on Update, null on Create.
	 * @param bool                 $has_blocks_input Whether a non-empty `blocks` field was sent.
	 *
	 * @return array{content: string, block_issues: array<int, string>}|WP_Error Resolved content, or a WP_Error.
	 * @since 1.2.0
	 */
	private function resolve_block( array $args, ?string $post_type, ?int $post_id, bool $has_blocks_input ): array|WP_Error {
		$policy_issues = [];

		if ( $has_blocks_input ) {
			$exempt        = $post_id ? $this->policy->existing_block_names( $post_id ) : [];
			$policy_issues = $this->policy->enforce( $args['blocks'], $post_type, $post_id, $exempt );
			$serialized    = $this->serializer->serialize_with_issues( $args['blocks'] );
		} else {
			$serialized = $this->serializer->serialize_with_issues( (string) ( $args['content'] ?? '' ) );
		}

		// Enforcement errors and serializer errors both abort the save; only
		// warnings ride along.
		$block_error = BlockIssues::to_wp_error( array_merge( $policy_issues, $serialized['issues'] ) );
		if ( $block_error instanceof WP_Error ) {
			return $block_error;
		}

		return [
			'content'      => $serialized['markup'],
			'block_issues' => BlockIssues::warning_messages( $serialized['issues'] ),
		];
	}
}
