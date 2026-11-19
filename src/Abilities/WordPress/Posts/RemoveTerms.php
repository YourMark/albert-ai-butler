<?php
/**
 * Remove Post Terms Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\AbstractPostTerms;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Removes categories or tags from a post, keeping the rest.
 *
 * @since 1.6.0
 */
class RemoveTerms extends AbstractPostTerms {

	/**
	 * Removing a tag never creates one; an unknown name is skipped.
	 *
	 * @var bool
	 * @since 1.6.0
	 */
	protected const CREATE_TAGS = false;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/remove-post-terms';
		$this->label       = __( 'Remove Post Terms', 'albert-ai-butler' );
		$this->description = __( 'Remove terms of one taxonomy from a post, keeping its other terms. Use it to take a category or tag off a post; a post always keeps one category, so removing its last one puts the site\'s default category back.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->terms_input_schema(
			'Category IDs (numbers) for "category", tag names (text) for "post_tag". Read the post\'s current terms with albert/view-post. A tag name that does not exist is skipped.'
		);
		$this->output_schema = $this->terms_output_schema();

		$this->output_schema['properties']['default_category_applied'] = [
			'type'        => 'boolean',
			'description' => 'True when the post\'s last category was removed and WordPress put the default category back.',
		];

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => [ 'idempotent' => true ] + Annotations::update(
				'Only the terms you send are removed. Removing a post\'s last category puts the site\'s '
				. 'default category back, and `default_category_applied` says so.'
			),
		];

		parent::__construct();
	}

	/**
	 * Remove the sent IDs from the current ones.
	 *
	 * @param array<int, int> $current IDs on the post now.
	 * @param array<int, int> $sent    IDs resolved from the input.
	 * @return array<int, int>
	 * @since 1.6.0
	 */
	protected function apply( array $current, array $sent ): array {
		return array_values( array_diff( $current, $sent ) );
	}

	/**
	 * The result, saying whether the default category was put back.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $taxonomy        Taxonomy name.
	 * @param bool   $default_applied Whether the default category was put back.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function result( int $post_id, string $taxonomy, bool $default_applied ): array {
		return parent::result( $post_id, $taxonomy, $default_applied ) + [
			'default_category_applied' => $default_applied,
		];
	}
}
