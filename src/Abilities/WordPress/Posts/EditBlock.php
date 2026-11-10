<?php
/**
 * Edit Post Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\AbstractEditBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Replace one block in a post by its position, preserving every other block.
 *
 * @since 1.2.0
 */
class EditBlock extends AbstractEditBlock {

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->id          = 'albert/edit-post-block';
		$this->label       = __( 'Edit Post Block', 'albert-ai-butler' );
		$this->description = __( 'Replace a single block in a post by its position (path), leaving every other block untouched. Read the post first to get each block\'s path.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->edit_input_schema();
		$this->output_schema = $this->shared_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Address the block by `path`, the position array from a fresh `albert/view-post` read '
				. 'with `"format": ["blocks"]`, not by id. Pass `expect` set to the block name you read at '
				. 'that path, so a post that changed under you is rejected rather than overwritten. Send '
				. 'only the one block, never the whole body. Every op returns the refreshed tree; paths '
				. 'shift after an insert or a delete, so take your next path from that response and not '
				. 'from an earlier read.'
			),
		];

		$this->capability = 'edit_posts';

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
}
