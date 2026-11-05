<?php
/**
 * Edit Page Block Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\AbstractEditBlock;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Replace one block in a page by its position, preserving every other block.
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
		$this->id          = 'albert/edit-page-block';
		$this->label       = __( 'Edit Page Block', 'albert-ai-butler' );
		$this->description = __( 'Replace a block in a page by its position (path), leaving every other block untouched. Read the page first to get each block\'s path. To change several blocks, send them together in `edits`: one save, one revision.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->edit_input_schema();
		$this->output_schema = $this->shared_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Address the block by `path`, the position array from a fresh `albert/view-page` read '
				. 'with `"format": ["blocks"]`, not by id. Pass `expect` set to the block name you read at '
				. 'that path, so a page that changed under you is rejected rather than overwritten. Send '
				. 'only the one block, never the whole body. Every op returns the refreshed tree; paths '
				. 'shift after an insert or a delete, so take your next path from that response and not '
				. 'from an earlier read. To change more than one block of this page, send every edit in one '
				. 'call as `edits` instead of one call each: every call saves a revision, which pushes older '
				. 'ones out on a site that keeps only a few. In `edits`, each path is read against the tree as the earlier edits leave it.'
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

	/**
	 * The capability gate.
	 *
	 * @return string Capability.
	 * @since 1.2.0
	 */
	protected function edit_capability(): string {
		return 'edit_pages';
	}
}
