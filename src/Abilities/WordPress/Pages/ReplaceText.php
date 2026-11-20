<?php
/**
 * Replace Page Text Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\AbstractReplaceText;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Replace literal text in a page's content, title or excerpt.
 *
 * @since 1.6.0
 */
class ReplaceText extends AbstractReplaceText {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/replace-page-text';
		$this->label       = __( 'Replace Page Text', 'albert-ai-butler' );
		$this->description = __( 'Replace exact text in a page field without resending the page; read it with albert/view-page first.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->replace_input_schema();
		$this->output_schema = $this->replace_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => array_merge(
				Annotations::update(
					'Use this to fix a word, a name, a price or a link in a long page: send only the text to find '
					. 'and its replacement. Copy `search` from a fresh `albert/view-page` read with `"format": ["content"]`, '
					. 'so it matches the stored markup exactly. Nothing is saved when the text occurs a different number '
					. 'of times than `expected_count`, or when the change would alter blocks rather than their text. '
					. 'To change a block\'s settings, or add, remove or move blocks, use the block abilities instead.'
				),
				// Repeating a replacement can match again, e.g. "1" with "11".
				[ 'idempotent' => false ]
			),
		];

		parent::__construct();
	}

	/**
	 * The post type this ability targets.
	 *
	 * @return string Post type slug.
	 * @since 1.6.0
	 */
	protected function post_type(): string {
		return 'page';
	}

	/**
	 * The REST base used to save.
	 *
	 * @return string REST base.
	 * @since 1.6.0
	 */
	protected function rest_base(): string {
		return 'pages';
	}

	/**
	 * The capability gate.
	 *
	 * @return string Capability.
	 * @since 1.6.0
	 */
	protected function edit_capability(): string {
		return 'edit_pages';
	}
}
