<?php
/**
 * Remove Post Meta Ability
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
 * Removes values from a multi-value custom field on any post type.
 *
 * Destructive: core keeps no revision of custom fields, so a removed value is gone.
 *
 * @since 1.6.0
 */
class RemoveMeta extends AbstractPostMetaList {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/remove-post-meta';
		$this->label       = __( 'Remove Post Meta', 'albert-ai-butler' );
		$this->description = __( 'Remove values from a custom field that holds a list on a content item you can edit. Read the item first with albert/view-post (albert/view-page for a page) to see the current values. Use albert/add-post-meta to add values.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->list_input_schema( 'Values to take out of the list, each matching the field\'s type. Every copy of a value is removed; a value that is not in the list is skipped.' );
		$this->output_schema = $this->list_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => [ 'idempotent' => true ] + Annotations::destructive( 'A removed value cannot be restored from a revision. Removing the last value leaves the field empty.' ),
		];

		parent::__construct();
	}

	/**
	 * Drop every stored copy of the values.
	 *
	 * @param array<int, mixed> $current Stored values.
	 * @param array<int, mixed> $values  Checked values from the input.
	 * @return array<int, mixed>
	 * @since 1.6.0
	 */
	protected function apply_values( array $current, array $values ): array {
		return array_values( array_filter( $current, static fn( mixed $stored ): bool => ! in_array( $stored, $values, true ) ) );
	}

	/**
	 * Meta capability core checks for removing a value.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	protected function meta_capability(): string {
		return 'delete_post_meta';
	}
}
