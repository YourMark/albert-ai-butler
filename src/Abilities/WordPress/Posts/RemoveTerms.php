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
	 * Tags can be removed by the IDs albert/view-post returns.
	 *
	 * @var bool
	 * @since 1.6.0
	 */
	protected const TAG_IDS = true;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/remove-post-terms';
		$this->label       = __( 'Remove Post Terms', 'albert-ai-butler' );
		$this->description = __( 'Remove terms of one taxonomy from a post, keeping its other terms. Use it to take a category or tag off a post. A post always keeps one category: to move a post to another category, add the new one with albert/add-post-terms first, then remove the old one. Removing its last category puts the site\'s default category back.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->terms_input_schema(
			'Category IDs for "category". Tag names (text) or tag IDs (numbers) for "post_tag". Read the post\'s current terms with albert/view-post. A tag that does not exist is skipped.'
		);
		$this->output_schema = $this->terms_output_schema();

		$this->output_schema['properties']['default_category_applied'] = [
			'type'        => 'boolean',
			'description' => 'True when the post\'s last category was removed and WordPress put the default category back.',
		];
		$this->output_schema['properties']['note']                     = [
			'type'        => 'string',
			'description' => 'Why nothing changed. Present only when the default category was the post\'s only category and so stayed.',
		];

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
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
	 * The result, saying whether the default category was put back, or why it stayed.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $taxonomy        Taxonomy name.
	 * @param bool   $default_applied Whether the default category was put back.
	 * @param bool   $default_kept    Whether the post kept its only category, the default.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function result( int $post_id, string $taxonomy, bool $default_applied, bool $default_kept ): array {
		$result = parent::result( $post_id, $taxonomy, $default_applied, $default_kept ) + [
			'default_category_applied' => $default_applied,
		];

		if ( $default_kept ) {
			$result['note'] = __( 'Nothing changed: this is the post\'s only category, and a post always keeps one. To move the post to another category, add that category with albert/add-post-terms first, then remove this one.', 'albert-ai-butler' );
		}

		return $result;
	}
}
