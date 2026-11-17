<?php
/**
 * Add Post Meta Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\AbstractPostMetaList;
use Albert\Core\Annotations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds values to a multi-value custom field on any post type.
 *
 * @since 1.6.0
 */
class AddMeta extends AbstractPostMetaList {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/add-post-meta';
		$this->label       = __( 'Add Post Meta', 'albert-ai-butler' );
		$this->description = __( 'Add values to a custom field that holds a list on a content item you can edit. Read the item first with albert/view-post (albert/view-page for a page) to see its fields. Use albert/remove-post-meta to take values out; set a single-value field through "meta" on albert/update-post (albert/update-page for a page).', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->list_input_schema( 'Values to append to the list, each matching the field\'s type. A value already in the list is added again.' );
		$this->output_schema = $this->list_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => [ 'idempotent' => false ] + Annotations::update(),
		];

		parent::__construct();
	}

	/**
	 * Append the values.
	 *
	 * @param array<int, mixed> $current Stored values.
	 * @param array<int, mixed> $values  Checked values from the input.
	 * @return array<int, mixed>
	 * @since 1.6.0
	 */
	protected function apply_values( array $current, array $values ): array {
		return array_merge( $current, $values );
	}

	/**
	 * Meta capability core checks for adding a value.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	protected function meta_capability(): string {
		return 'add_post_meta';
	}
}
