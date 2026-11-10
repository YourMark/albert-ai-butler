<?php
/**
 * Remove Page Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\AbstractRemoveBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Delete one block from a page by its position, preserving every other block.
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
		$this->id          = 'albert/remove-page-block';
		$this->label       = __( 'Remove Page Block', 'albert-ai-butler' );
		$this->description = __( 'Delete a single block from a page by its position (path), leaving every other block untouched. Read the page first to get each block\'s path.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->remove_input_schema();
		$this->output_schema = $this->shared_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Address the block by `path` from a fresh `albert/view-page` read with `"format": '
				. '["blocks"]`, and pass `expect` set to the block name you read there, without it, a page '
				. 'that changed since your read means you delete the wrong block. Every op returns the '
				. 'refreshed tree; the paths of later blocks shift after a delete, so take your next path '
				. 'from that response.'
			),
		];

		$this->capability = 'edit_pages';

		parent::__construct();
	}

	/**
	 * The post type this ability targets.
	 *
	 * @return string Post type slug.
	 * @since 1.2.0
	 */
	protected function post_type(): string {
		return 'page';
	}

	/**
	 * The REST base for persistence.
	 *
	 * @return string REST base.
	 * @since 1.2.0
	 */
	protected function rest_base(): string {
		return 'pages';
	}
}
