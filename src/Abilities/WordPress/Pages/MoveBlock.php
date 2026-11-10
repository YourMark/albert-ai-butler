<?php
/**
 * Move Page Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\AbstractMoveBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Reorder one block within a page among its siblings, preserving every block.
 *
 * @since 1.2.0
 */
class MoveBlock extends AbstractMoveBlock {

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		$this->id          = 'albert/move-page-block';
		$this->label       = __( 'Move Page Block', 'albert-ai-butler' );
		$this->description = __( 'Reorder a single block within a page among its siblings (same parent) by its position (path). Read the page first to get block paths.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->move_input_schema();
		$this->output_schema = $this->shared_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Address the block by `path` from a fresh `albert/view-page` read with `"format": '
				. '["blocks"]`, and pass `to_index`, the new position among that block\'s own siblings, not '
				. 'a global position. Pass `expect` set to the block name you read at that path. Every op '
				. 'returns the refreshed tree; take your next path from that response.'
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
