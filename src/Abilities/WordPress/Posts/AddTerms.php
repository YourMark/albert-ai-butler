<?php
/**
 * Add Post Terms Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abilities\WordPress\Shared\PostTerms;
use Albert\Abstracts\AbstractPostTerms;
use Albert\Core\Annotations;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds categories or tags to a post, keeping the ones it has.
 *
 * @since 1.6.0
 */
class AddTerms extends AbstractPostTerms {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/add-post-terms';
		$this->label       = __( 'Add Post Terms', 'albert-ai-butler' );
		$this->description = __( 'Add terms of one taxonomy to a post, keeping the terms it already has. Use it to categorise a post or tag it, instead of the categories and tags fields of albert/update-post.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->terms_input_schema(
			'Category IDs (numbers) for "category", found with albert/find-terms. Tag names (text) for "post_tag"; a tag that does not exist yet is created.'
		);
		$this->output_schema = $this->terms_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => [ 'idempotent' => true ] + Annotations::update(
				'Terms already on the post stay, and sending one it already has changes nothing. '
				. 'Check the result\'s `terms` for the full list afterwards.'
			),
		];

		parent::__construct();
	}

	/**
	 * Add the sent IDs to the current ones.
	 *
	 * @param array<int, int> $current IDs on the post now.
	 * @param array<int, int> $sent    IDs resolved from the input.
	 * @return array<int, int>
	 * @since 1.6.0
	 */
	protected function apply( array $current, array $sent ): array {
		return array_merge( $current, $sent );
	}

	/**
	 * Tag IDs for the names, creating the tags that do not exist yet.
	 *
	 * @param array<int, string> $names Tag names.
	 * @return array<int, int>|WP_Error
	 * @since 1.6.0
	 */
	protected function resolve_tags( array $names ): array|WP_Error {
		return PostTerms::tag_ids( $names, 'terms' );
	}
}
