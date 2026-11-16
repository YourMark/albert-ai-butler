<?php
/**
 * Replace Post Text Ability
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\AbstractReplaceText;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Replace literal text in a post's content, title or excerpt.
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
		$this->id          = 'albert/replace-post-text';
		$this->label       = __( 'Replace Post Text', 'albert-ai-butler' );
		$this->description = __( 'Replace exact text in a post\'s content, title or excerpt, without sending the whole post. Refused when the text occurs a different number of times than expected_count, or when the change would alter blocks rather than text.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->replace_input_schema();
		$this->output_schema = $this->replace_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Use this to fix a word, a name, a price or a link in a long post: send only the text to find '
				. 'and its replacement. Copy `search` from a fresh `albert/view-post` read with `"format": ["content"]`, '
				. 'so it matches the stored markup exactly. To change a block\'s settings, or add, remove or move '
				. 'blocks, use the block abilities instead.'
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
		return 'post';
	}

	/**
	 * The REST base used to save.
	 *
	 * @return string REST base.
	 * @since 1.6.0
	 */
	protected function rest_base(): string {
		return 'posts';
	}

	/**
	 * The capability gate.
	 *
	 * @return string Capability.
	 * @since 1.6.0
	 */
	protected function edit_capability(): string {
		return 'edit_posts';
	}
}
