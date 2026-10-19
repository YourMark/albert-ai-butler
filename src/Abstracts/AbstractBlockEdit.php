<?php
/**
 * Abstract Block Edit Ability
 *
 * Shared base for the granular per-block edit abilities (edit/add/remove/move,
 * for both posts and pages). It centralises the per-op flow so each concrete
 * ability stays tiny: resolve the target post, reject classic-editor targets,
 * parse the stored content, apply the operation to the block tree, enforce the
 * allowed-block policy on any new/changed block, fold the resulting issues
 * through BlockIssues (errors abort, warnings ride along), serialize, persist via
 * the capability-aware REST POST path, and return the refreshed block tree so the
 * model always has current paths for the next edit.
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.2.0
 */

namespace Albert\Abstracts;

use Albert\Blocks\BlockIssues;
use Albert\Blocks\BlockPolicy;
use Albert\Blocks\BlockSerializer;
use Albert\Blocks\BlockTreeEditor;
use Albert\Blocks\ContentFormatter;
use Albert\Blocks\EditorMode;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

use Albert\Blocks\BlockSpecSchema;

/**
 * Base class for the four positional block operations on posts and pages.
 *
 * Subclasses declare which post type and REST base they target and which
 * operation they perform; everything else (permission, load, parse, enforce,
 * persist, refreshed-tree result) lives here.
 *
 * @since 1.2.0
 */
abstract class AbstractBlockEdit extends BaseAbility {

	/**
	 * The post type this ability targets.
	 *
	 * @return string Post type slug ('post' or 'page').
	 * @since 1.2.0
	 */
	abstract protected function post_type(): string;

	/**
	 * The REST base for persistence (e.g. 'posts' or 'pages').
	 *
	 * @return string REST base segment.
	 * @since 1.2.0
	 */
	abstract protected function rest_base(): string;

	/**
	 * The capability gate used by the REST permission check.
	 *
	 * @return string Capability ('edit_posts' or 'edit_pages').
	 * @since 1.2.0
	 */
	abstract protected function edit_capability(): string;

	/**
	 * Apply this ability's operation to the parsed block tree.
	 *
	 * Reads the op-specific args (path/expect/block/position/to_index), builds a
	 * single block via {@see BlockSerializer::build_one()} where the op needs one,
	 * and invokes the matching {@see BlockTreeEditor} method.
	 *
	 * @param BlockTreeEditor      $editor Tree editor.
	 * @param array<int, mixed>    $tree   Parsed block tree (from parse_blocks()).
	 * @param array<string, mixed> $args   Ability input.
	 * @return array<int, mixed>|WP_Error New tree, or an error to return without saving.
	 * @since 1.2.0
	 */
	abstract protected function apply( BlockTreeEditor $editor, array $tree, array $args ): array|WP_Error;

	/**
	 * Whether this operation introduces a new/changed block that must be policy-
	 * checked. Override to false for remove (which adds nothing).
	 *
	 * @return bool True when the op should run BlockPolicy on its new block spec.
	 * @since 1.2.0
	 */
	protected function enforces_policy(): bool {
		return true;
	}

	/**
	 * The block spec this op submits (for policy enforcement), or null.
	 *
	 * Edit/add return their `block` spec; move/remove return null.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<int, array<string, mixed>>|null List with the one submitted spec, or null.
	 * @since 1.2.0
	 */
	protected function policy_specs( array $args ): ?array {
		$block = $args['block'] ?? null;

		return is_array( $block ) ? [ $block ] : null;
	}

	/**
	 * Check if the current user may perform this edit via the REST endpoint.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 * @since 1.2.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->check_rest_permission(
			'/wp/v2/' . $this->rest_base() . '/(?P<id>[\\d]+)',
			'POST',
			$this->edit_capability()
		);
	}

	/**
	 * Execute the granular block operation.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<string, mixed>|WP_Error Result on success, WP_Error on failure.
	 * @since 1.2.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== $this->post_type() ) {
			return new WP_Error(
				'post_not_found',
				sprintf(
					/* translators: 1: post type, 2: post ID. */
					__( 'No %1$s found with ID %2$d.', 'albert-ai-butler' ),
					$this->post_type(),
					$post_id
				),
				[ 'status' => 404 ]
			);
		}

		// Granular block edits only make sense on a block-editor target.
		if ( ! EditorMode::is_block_editor( $this->post_type(), $post_id ) ) {
			return new WP_Error(
				'classic_editor_no_block_edits',
				sprintf(
					/* translators: 1: post type, 2: the update ability id (e.g. albert/update-post). */
					__( "This %1\$s uses the classic editor; granular block edits don't apply. Use %2\$s with HTML content.", 'albert-ai-butler' ),
					$this->post_type(),
					'albert/update-' . $this->post_type()
				),
				[ 'status' => 400 ]
			);
		}

		$tree        = array_values( parse_blocks( (string) $post->post_content ) );
		$result_tree = $this->apply( new BlockTreeEditor(), $tree, $args );

		if ( is_wp_error( $result_tree ) ) {
			return $result_tree;
		}

		// Enforce the allowed-block set + serializer issues for ops that introduce
		// a new/changed block (edit/add/move). Remove introduces nothing.
		$block_issues = [];
		if ( $this->enforces_policy() ) {
			$issues = $this->collect_issues( $args, $post_id );
			$error  = BlockIssues::to_wp_error( $issues );
			if ( $error instanceof WP_Error ) {
				return $error;
			}

			$block_issues = BlockIssues::warning_messages( $issues );
		}

		$markup = serialize_blocks( $result_tree );

		$persisted = $this->persist( $post_id, $markup );
		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}

		return $this->build_result( $post_id, $markup, $persisted, $block_issues );
	}

	/**
	 * Collect policy + serializer issues for the submitted block spec.
	 *
	 * Builds the one submitted spec through the serializer (to surface any
	 * per-block warnings/errors) and runs BlockPolicy on it, exempting blocks
	 * already present in the post so legacy content never blocks an edit.
	 *
	 * @param array<string, mixed> $args    Ability input.
	 * @param int                  $post_id Target post ID.
	 * @return array<int, array{severity: string, message: string}> Issues to fold through BlockIssues.
	 * @since 1.2.0
	 */
	private function collect_issues( array $args, int $post_id ): array {
		$specs = $this->policy_specs( $args );
		if ( $specs === null ) {
			return [];
		}

		$serializer    = new BlockSerializer();
		$policy        = new BlockPolicy();
		$exempt        = $policy->existing_block_names( $post_id );
		$policy_issues = $policy->enforce( $specs, $this->post_type(), $post_id, $exempt );

		$serializer_issues = [];
		foreach ( $specs as $spec ) {
			$built             = $serializer->build_one( $spec );
			$serializer_issues = array_merge( $serializer_issues, $built['issues'] );
		}

		return array_merge( $policy_issues, $serializer_issues );
	}

	/**
	 * Persist the new markup via the capability-aware REST POST path.
	 *
	 * Mirrors the persistence flow in Posts/Update.php so KSES and the user's
	 * capabilities apply exactly as they do on a whole-content update.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $markup  Serialized block markup to store.
	 * @return array<string, mixed>|WP_Error REST response data, or an error.
	 * @since 1.2.0
	 */
	private function persist( int $post_id, string $markup ): array|WP_Error {
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $this->rest_base() . '/' . $post_id );
		$request->set_param( 'content', $markup );

		$response = rest_do_request( $request );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return new WP_Error(
				is_array( $data ) && isset( $data['code'] ) ? $data['code'] : 'rest_error',
				is_array( $data ) && isset( $data['message'] )
					? $data['message']
					: __( 'An error occurred while saving the block change.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Build the ability result, including the refreshed block tree.
	 *
	 * The refreshed `blocks`/`_meta` come from ContentFormatter so the model gets
	 * the same shaped, byte-capped, path-bearing tree the read abilities return —
	 * its paths may have shifted after the edit, so the model must use these for
	 * the next operation rather than its prior read.
	 *
	 * @param int                  $post_id      Target post ID.
	 * @param string               $markup       The stored block markup.
	 * @param array<string, mixed> $data         REST response data.
	 * @param array<int, string>   $block_issues Non-fatal block warnings.
	 * @return array<string, mixed> Result payload.
	 * @since 1.2.0
	 */
	private function build_result( int $post_id, string $markup, array $data, array $block_issues ): array {
		$formatted = ContentFormatter::build( $markup, [ 'blocks' ], [ 'paginate' => true ] );

		$result = [
			'id'        => $post_id,
			'status'    => isset( $data['status'] ) ? $data['status'] : '',
			'permalink' => isset( $data['link'] ) ? $data['link'] : '',
			'edit_url'  => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'blocks'    => $formatted['blocks'] ?? [],
		];

		if ( isset( $formatted['_meta'] ) ) {
			$result['_meta'] = $formatted['_meta'];
		}

		if ( $block_issues !== [] ) {
			$result['block_issues'] = $block_issues;
		}

		return $result;
	}

	/**
	 * Shared input-schema properties common to every block-edit op.
	 *
	 * @return array<string, array<string, mixed>> The id/path/expect properties.
	 * @since 1.2.0
	 */
	protected function common_properties(): array {
		return [
			'id'     => [
				'type'        => 'integer',
				'description' => sprintf(
					/* translators: %s: post type. */
					__( 'The %s ID to edit.', 'albert-ai-butler' ),
					$this->post_type()
				),
				'minimum'     => 1,
			],
			'path'   => [
				'type'        => 'array',
				'items'       => [
					'type'    => 'integer',
					'minimum' => 0,
				],
				'description' => 'Position of the target block, e.g. [2] = the 3rd top-level block, [2,0] = its first child. Get paths from a view-* read (each block carries its "path"). Indexes the same separator-filtered tree the read returns.',
			],
			'expect' => [
				'type'        => 'string',
				'description' => 'Optional. Block name you expect at `path` (e.g. core/heading). The edit is rejected with block_path_mismatch if it does not match, so you can re-read and retry. Pass the "name" of the block you read at that path.',
			],
		];
	}

	/**
	 * The single-block spec input-schema fragment used by edit/add.
	 *
	 * @return array<string, mixed> The `block` property schema.
	 * @since 1.2.0
	 */
	protected function block_property(): array {
		$spec                = BlockSpecSchema::spec();
		$spec['description'] = 'The complete intended block spec for this one block: { "name": "core/paragraph", "attributes": { ... }, "innerBlocks": [ ... ] }. innerBlocks is the same shape recursively for layout blocks (e.g. core/columns > core/column). Put text in attributes (content/text/value) or in "plaintext". The server regenerates this one block; untouched blocks are preserved byte-for-byte.';

		return $spec;
	}

	/**
	 * The output schema shared by every block-edit op.
	 *
	 * @return array<string, mixed> Output JSON schema.
	 * @since 1.2.0
	 */
	protected function shared_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'status'       => [ 'type' => 'string' ],
				'permalink'    => [ 'type' => 'string' ],
				'edit_url'     => [ 'type' => 'string' ],
				'block_issues' => [
					'type'        => 'array',
					'description' => 'Optional, non-fatal block validation warnings (the change was still saved). Fatal block problems come back as a WP_Error with code "block_validation_failed" and nothing is saved.',
					'items'       => [ 'type' => 'string' ],
				],
				'blocks'       => [
					'type'        => 'array',
					'description' => 'The refreshed block tree after the edit. Each node has { name, attributes, innerBlocks, plaintext, path }. Paths may have shifted from your prior read, so use THESE paths for the next edit.',
					'items'       => [ 'type' => 'object' ],
				],
				'_meta'        => [
					'type'        => 'object',
					'description' => 'Block-window pagination metadata for the refreshed "blocks" tree (see view-post). When "truncated" is true, re-read with view-* and the suggested "next_offset" for the rest.',
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * Read the `path` arg as a clean list of non-negative ints.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<int, int> Sanitised path.
	 * @since 1.2.0
	 */
	protected function read_path( array $args ): array {
		$raw = $args['path'] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$path = [];
		foreach ( $raw as $segment ) {
			$path[] = absint( $segment );
		}

		return $path;
	}

	/**
	 * Read the optional `expect` arg.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return string|null Expected block name, or null when not given.
	 * @since 1.2.0
	 */
	protected function read_expect( array $args ): ?string {
		$expect = $args['expect'] ?? null;

		return is_string( $expect ) && $expect !== '' ? $expect : null;
	}

	/**
	 * Build the one block spec from the `block` arg, or return an error.
	 *
	 * Shared by the edit and add operations: validates the `block` input, builds it
	 * through {@see BlockSerializer::build_one()}, and on a null result surfaces the
	 * serializer issues as `block_validation_failed` — the same fatal error the
	 * whole-content write path returns — so the model gets an actionable,
	 * block-naming message rather than an opaque "could not build" error.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<string, mixed>|WP_Error The built ParsedBlock, or an error to return without saving.
	 * @since 1.2.0
	 */
	protected function build_block_or_error( array $args ): array|WP_Error {
		$spec = $args['block'] ?? null;
		if ( ! is_array( $spec ) ) {
			return new WP_Error(
				'block_required',
				__( 'The `block` parameter is required and must be a block spec object.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$built = ( new BlockSerializer() )->build_one( $spec );
		if ( $built['block'] === null ) {
			$error = BlockIssues::to_wp_error( $built['issues'] );

			return $error instanceof WP_Error ? $error : new WP_Error(
				'block_validation_failed',
				__( 'The submitted block could not be built. Check the block name and attributes.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return $built['block'];
	}
}
