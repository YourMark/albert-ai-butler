<?php
/**
 * Add Post Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\AbstractAddBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Insert one block into a post relative to a position, preserving every other block.
 *
 * @since 1.2.0
 */
class AddBlock extends AbstractAddBlock {

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->id          = 'albert/add-post-block';
		$this->label       = __( 'Add Post Block', 'albert-ai-butler' );
		$this->description = __( 'Insert a single block, or a pattern by name, into a post before/after a block at a given position (path), or inside it; into an empty post without a path. Every other block is left untouched. Read the post first to get block paths.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->add_input_schema();
		$this->output_schema = $this->add_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Address the target by `path` from a fresh `albert/view-post` read with `"format": '
				. '["blocks"]`, and say where with `position`: `before` or `after` for a sibling, '
				. '`inside_start` or `inside_end` to nest inside a layout block. To append, use `after` '
				. 'with the last top-level block\'s path. Pass `expect` set to the block name you read at '
				. 'that path. Every op returns the refreshed tree; later paths shift after an insert, so '
				. 'take your next path from that response. To place a pattern from `albert/find-patterns`, send '
				. 'its `name` as `pattern` instead of `block`: the server inserts it exactly as stored. On a post with '
				. 'no blocks yet, leave out `path` and `position`. `inserted` in the response gives the paths of the '
				. 'top-level blocks added.'
			),
		];

		parent::__construct();
	}

	/**
	 * The post type this ability targets.
	 *
	 * @return string Post type slug.
	 * @since 1.2.0
	 */
	protected function post_type(): string {
		return 'post';
	}

	/**
	 * The REST base for persistence.
	 *
	 * @return string REST base.
	 * @since 1.2.0
	 */
	protected function rest_base(): string {
		return 'posts';
	}

	/**
	 * The capability gate.
	 *
	 * @return string Capability.
	 * @since 1.2.0
	 */
	protected function edit_capability(): string {
		return 'edit_posts';
	}
}
