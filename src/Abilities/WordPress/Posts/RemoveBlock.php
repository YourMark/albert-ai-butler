<?php
/**
 * Remove Post Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\AbstractRemoveBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Delete one block from a post by its position, preserving every other block.
 *
 * @since 1.2.0
 */
class RemoveBlock extends AbstractRemoveBlock {

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->id          = 'albert/remove-post-block';
		$this->label       = __( 'Remove Post Block', 'albert-ai-butler' );
		$this->description = __( 'Delete a single block from a post by its position (path), leaving every other block untouched. Read the post first to get each block\'s path.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->remove_input_schema();
		$this->output_schema = $this->shared_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::destructive(
				'Address the block by `path` from a fresh `albert/view-post` read with `"format": '
				. '["blocks"]`, and pass `expect` set to the block name you read there, without it, a post '
				. 'that changed since your read means you delete the wrong block. Every op returns the '
				. 'refreshed tree; the paths of later blocks shift after a delete, so take your next path '
				. 'from that response.'
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
