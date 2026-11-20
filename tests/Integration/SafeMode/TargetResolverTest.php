<?php
/**
 * Integration tests for naming the target of a held call.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\SafeMode\TargetResolver;
use Albert\Tests\TestCase;

/**
 * TargetResolver integration tests.
 *
 * @covers \Albert\SafeMode\TargetResolver
 */
class TargetResolverTest extends TestCase {

	/**
	 * A page addressed by page_id is named, so a held call can warn when it was edited since.
	 *
	 * @return void
	 */
	public function test_a_page_id_names_the_page(): void {
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'About us',
			]
		);

		$target = ( new TargetResolver() )->describe( 'albert/replace-page-text', [ 'page_id' => $page_id ] );

		$this->assertIsArray( $target );
		$this->assertSame( $page_id, $target['id'] );
		$this->assertSame( 'About us', $target['label'] );
	}
}
