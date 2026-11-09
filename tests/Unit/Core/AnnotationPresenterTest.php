<?php
/**
 * Unit tests for AnnotationPresenter.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

use Albert\Core\Annotations;
use Albert\Core\AnnotationPresenter;
use PHPUnit\Framework\TestCase;

/**
 * AnnotationPresenter tests.
 *
 * Covers each annotation preset from {@see Annotations}, the "Not declared"
 * chip for an ability that declares nothing, and the is_destructive helper.
 */
class AnnotationPresenterTest extends TestCase {

	/**
	 * Read-only annotations produce a single neutral "Read" chip with a description.
	 *
	 * @return void
	 */
	public function test_read_annotation_yields_read_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::read() );

		$this->assertCount( 1, $chips );
		$this->assertSame( 'read', $chips[0]['key'] );
		$this->assertSame( 'neutral', $chips[0]['tone'] );
		$this->assertNotEmpty( $chips[0]['description'] );
	}

	/**
	 * Create annotations produce a single "Write" chip.
	 *
	 * @return void
	 */
	public function test_create_annotation_yields_write_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::create() );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'write' ], $keys );
	}

	/**
	 * Update annotations produce a "Write" chip only.
	 *
	 * @return void
	 */
	public function test_update_annotation_yields_write_only(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::update() );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'write' ], $keys );
		$this->assertNotContains( 'destructive', $keys );
	}

	/**
	 * Delete annotations produce a "Delete" chip with the danger tone.
	 *
	 * @return void
	 */
	public function test_delete_annotation_yields_destructive_chip(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::delete() );
		$keys  = array_column( $chips, 'key' );
		$tones = array_column( $chips, 'tone' );

		$this->assertSame( [ 'destructive' ], $keys );
		$this->assertContains( 'danger', $tones );
	}

	/**
	 * Action annotations produce only a "Write" chip.
	 *
	 * @return void
	 */
	public function test_action_annotation_yields_write_only(): void {
		$chips = AnnotationPresenter::chips_for( Annotations::action() );
		$keys  = array_column( $chips, 'key' );

		$this->assertSame( [ 'write' ], $keys );
	}

	/**
	 * Every chip carries a non-empty description used for tooltips.
	 *
	 * @return void
	 */
	public function test_every_chip_has_a_description(): void {
		foreach ( [ Annotations::read(), Annotations::create(), Annotations::update(), Annotations::delete(), Annotations::action() ] as $preset ) {
			$chips = AnnotationPresenter::chips_for( $preset );
			foreach ( $chips as $chip ) {
				$this->assertArrayHasKey( 'description', $chip );
				$this->assertNotEmpty( $chip['description'], "Chip {$chip['key']} should have a description." );
			}
		}
	}

	/**
	 * No annotations means "Not declared", whatever the id suggests.
	 *
	 * @return void
	 */
	public function test_missing_annotations_yield_not_declared_for_any_id(): void {
		foreach ( [ 'vendor/find-things', 'vendor/delete-things', 'vendor/create-things' ] as $id ) {
			$keys = array_column( AnnotationPresenter::chips_for( [], $id ), 'key' );

			$this->assertSame( [ 'undeclared' ], $keys, $id );
		}
	}

	/**
	 * Core's defaults (every flag null) are not a declaration.
	 *
	 * @return void
	 */
	public function test_core_null_defaults_yield_not_declared(): void {
		$chips = AnnotationPresenter::chips_for(
			[
				'readonly'    => null,
				'destructive' => null,
				'idempotent'  => null,
			]
		);

		$this->assertSame( [ 'undeclared' ], array_column( $chips, 'key' ) );
	}

	/**
	 * The "Not declared" chip is neutral and explains itself for the tooltip.
	 *
	 * @return void
	 */
	public function test_not_declared_chip_is_neutral_with_a_description(): void {
		$chip = AnnotationPresenter::chips_for( [] )[0];

		$this->assertSame( 'Not declared', $chip['label'] );
		$this->assertSame( 'neutral', $chip['tone'] );
		$this->assertNotEmpty( $chip['description'] );
	}

	/**
	 * Declaring only readonly is enough.
	 *
	 * @return void
	 */
	public function test_readonly_alone_is_a_declaration(): void {
		$keys = array_column( AnnotationPresenter::chips_for( [ 'readonly' => true ] ), 'key' );

		$this->assertSame( [ 'read' ], $keys );
	}

	/**
	 * Declaring only destructive false is enough, and means a write.
	 *
	 * @return void
	 */
	public function test_destructive_false_alone_is_a_write(): void {
		$keys = array_column( AnnotationPresenter::chips_for( [ 'destructive' => false ] ), 'key' );

		$this->assertSame( [ 'write' ], $keys );
	}

	/**
	 * A non-boolean readonly is not read-only, matching the fresh-install default and safe mode.
	 *
	 * @return void
	 */
	public function test_non_boolean_readonly_is_not_read(): void {
		$chips = AnnotationPresenter::chips_for(
			[
				'readonly'    => 1,
				'destructive' => false,
			]
		);

		$this->assertSame( [ 'write' ], array_column( $chips, 'key' ) );
	}

	/**
	 * Only a boolean flag declares anything.
	 *
	 * @return void
	 */
	public function test_is_declared_needs_a_boolean_flag(): void {
		$this->assertFalse( AnnotationPresenter::is_declared( [] ) );
		$this->assertFalse( AnnotationPresenter::is_declared( [ 'readonly' => null ] ) );
		$this->assertFalse( AnnotationPresenter::is_declared( [ 'instructions' => 'Use it well.' ] ) );
		$this->assertTrue( AnnotationPresenter::is_declared( [ 'readonly' => false ] ) );
		$this->assertTrue( AnnotationPresenter::is_declared( [ 'destructive' => true ] ) );
	}

	/**
	 * The deprecated slug heuristic still answers, with a notice.
	 *
	 * @return void
	 */
	public function test_heuristic_chips_is_deprecated_but_still_works(): void {
		$GLOBALS['albert_test_deprecated_calls'] = [];

		$keys = array_column( AnnotationPresenter::heuristic_chips( 'albert/delete-post' ), 'key' );

		$this->assertSame( [ 'destructive' ], $keys );
		$this->assertCount( 1, $GLOBALS['albert_test_deprecated_calls'] );
	}

	/**
	 * Destructive flag in annotations always wins regardless of id.
	 *
	 * @return void
	 */
	public function test_is_destructive_respects_annotation_flag(): void {
		$this->assertTrue(
			AnnotationPresenter::is_destructive( Annotations::delete(), 'albert/find-posts' )
		);
		$this->assertFalse(
			AnnotationPresenter::is_destructive( Annotations::read(), 'albert/delete-post' )
		);
	}

	/**
	 * Without annotations an ability counts as destructive, whatever its id.
	 *
	 * @return void
	 */
	public function test_is_destructive_when_annotations_missing(): void {
		$this->assertTrue( AnnotationPresenter::is_destructive( [], 'albert/find-posts' ) );
	}

	/**
	 * A declared read-only ability without a destructive flag is not destructive.
	 *
	 * @return void
	 */
	public function test_is_destructive_false_for_declared_readonly(): void {
		$this->assertFalse( AnnotationPresenter::is_destructive( [ 'readonly' => true ] ) );
	}

	/**
	 * A name on safe mode's high-risk list is destructive, whatever it annotates.
	 *
	 * @return void
	 */
	public function test_high_risk_name_is_destructive_over_its_annotation(): void {
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/high_risk_abilities'] = [ 'other/grant-access' ];

		$chips       = AnnotationPresenter::chips_for( Annotations::update(), 'other/grant-access' );
		$destructive = AnnotationPresenter::is_destructive( Annotations::update(), 'other/grant-access' );

		unset( $GLOBALS['albert_test_filter_returns']['albert/safe_mode/high_risk_abilities'] );

		$this->assertSame( [ 'destructive' ], array_column( $chips, 'key' ) );
		$this->assertSame( 'Destructive', $chips[0]['label'] );
		$this->assertTrue( $destructive );
	}

	/**
	 * The destructive preset lands in the Destructive group.
	 *
	 * @return void
	 */
	public function test_destructive_preset_yields_destructive_chip(): void {
		$this->assertSame( [ 'destructive' ], array_column( AnnotationPresenter::chips_for( Annotations::destructive() ), 'key' ) );
	}

	/**
	 * Claiming read-only does not hide a destructive flag.
	 *
	 * @return void
	 */
	public function test_contradictory_annotations_read_as_destructive(): void {
		$annotations = [
			'readonly'    => true,
			'destructive' => true,
		];

		$this->assertSame( [ 'destructive' ], array_column( AnnotationPresenter::chips_for( $annotations ), 'key' ) );
	}
}
