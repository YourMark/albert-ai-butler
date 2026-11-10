<?php
/**
 * Unit tests for albert/update-media and albert/delete-media.
 *
 * Drives the abilities against the recording REST stubs: what reaches core's
 * media endpoint, and how its answers come back.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once __DIR__ . '/block-write-rest-stubs.php';

use Albert\Abilities\WordPress\Media\DeleteMedia;
use Albert\Abilities\WordPress\Media\UpdateMedia;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_Error;

/**
 * Media details ability tests.
 */
class MediaDetailsAbilityTest extends TestCase {

	/**
	 * Seed one attachment and one post; reset the REST recorder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_rest_calls']     = [];
		$GLOBALS['albert_test_rest_responses'] = [];
		$GLOBALS['albert_test_posts']          = [
			7 => (object) [
				'ID'         => 7,
				'post_type'  => 'attachment',
				'post_title' => 'Cat',
			],
			8 => (object) [
				'ID'         => 8,
				'post_type'  => 'post',
				'post_title' => 'A post',
			],
		];
	}

	/**
	 * Clean up globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_rest_calls'],
			$GLOBALS['albert_test_rest_responses'],
			$GLOBALS['albert_test_posts']
		);
		parent::tearDown();
	}

	/**
	 * Queue a successful media update response.
	 *
	 * @return void
	 */
	private function queue_saved_media(): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => false,
			'data'     => [
				'id'          => 7,
				'alt_text'    => 'A grey cat on a windowsill',
				'title'       => [ 'raw' => 'Cat' ],
				'caption'     => [ 'raw' => '' ],
				'description' => [ 'raw' => 'Our cat.' ],
				'mime_type'   => 'image/jpeg',
				'source_url'  => 'https://example.com/cat.jpg',
			],
		];
	}

	/**
	 * Read an ability's declared annotations.
	 *
	 * @param object $ability Ability instance.
	 * @return array<string, mixed>
	 */
	private function annotations( object $ability ): array {
		$meta = ( new ReflectionProperty( $ability, 'meta' ) )->getValue( $ability );

		return $meta['annotations'];
	}

	public function test_annotations_are_declared(): void {
		$update = $this->annotations( new UpdateMedia() );
		$delete = $this->annotations( new DeleteMedia() );

		$this->assertFalse( $update['readonly'] );
		$this->assertFalse( $update['destructive'] );
		$this->assertFalse( $delete['readonly'] );
		$this->assertTrue( $delete['destructive'], 'Deleting media is permanent, so it must be held.' );
	}

	public function test_update_sends_only_the_fields_supplied(): void {
		$this->queue_saved_media();

		$result = ( new UpdateMedia() )->execute(
			[
				'id'          => 7,
				'alt_text'    => 'A grey cat on a windowsill',
				'description' => 'Our cat.',
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'A grey cat on a windowsill', $result['media']['alt_text'] );
		$this->assertSame( 'https://example.com/cat.jpg', $result['media']['url'] );

		$call = $GLOBALS['albert_test_rest_calls'][0];
		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( '/wp/v2/media/7', $call['route'] );
		$this->assertSame( 'A grey cat on a windowsill', $call['params']['alt_text'] );
		$this->assertSame( 'Our cat.', $call['params']['description'] );
		$this->assertArrayNotHasKey( 'title', $call['params'] );
		$this->assertArrayNotHasKey( 'caption', $call['params'] );
	}

	public function test_an_empty_string_clears_a_field(): void {
		$this->queue_saved_media();

		( new UpdateMedia() )->execute(
			[
				'id'      => 7,
				'caption' => '',
			]
		);

		$this->assertSame( '', $GLOBALS['albert_test_rest_calls'][0]['params']['caption'] );
	}

	public function test_values_reach_core_unchanged(): void {
		$this->queue_saved_media();

		( new UpdateMedia() )->execute(
			[
				'id'    => 7,
				'title' => 'The <em>best</em> cat',
			]
		);

		$this->assertSame( 'The <em>best</em> cat', $GLOBALS['albert_test_rest_calls'][0]['params']['title'], 'Core sanitises, as in wp-admin.' );
	}

	public function test_other_core_errors_keep_their_code(): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => true,
			'data'     => [
				'code'    => 'rest_post_invalid_id',
				'message' => 'Invalid post ID.',
			],
			'status'   => 404,
		];

		$result = ( new UpdateMedia() )->execute(
			[
				'id'       => 7,
				'alt_text' => 'x',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_update_with_no_fields_is_refused_before_any_write(): void {
		$result = ( new UpdateMedia() )->execute( [ 'id' => 7 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_no_changes', $result->get_error_code() );
		$this->assertSame( [], $GLOBALS['albert_test_rest_calls'] );
	}

	public function test_update_refuses_an_id_that_is_not_an_attachment(): void {
		$result = ( new UpdateMedia() )->execute(
			[
				'id'       => 8,
				'alt_text' => 'x',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
		$this->assertSame( [], $GLOBALS['albert_test_rest_calls'] );
	}

	public function test_core_refusal_becomes_a_permission_denied_code(): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => true,
			'data'     => [
				'code'    => 'rest_cannot_edit',
				'message' => 'Sorry, you are not allowed to edit this post.',
			],
			'status'   => 403,
		];

		$result = ( new UpdateMedia() )->execute(
			[
				'id'       => 7,
				'alt_text' => 'x',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_permission_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_a_401_also_becomes_a_permission_denied_code(): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => true,
			'data'     => [
				'code'    => 'rest_forbidden',
				'message' => 'Sorry, you are not allowed to do that.',
			],
			'status'   => 401,
		];

		$result = ( new UpdateMedia() )->execute(
			[
				'id'       => 7,
				'alt_text' => 'x',
			]
		);

		$this->assertSame( 'media_permission_denied', $result->get_error_code() );
	}

	public function test_a_failure_without_a_code_or_message_falls_back(): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => true,
			'data'     => [],
			'status'   => 500,
		];

		$result = ( new UpdateMedia() )->execute(
			[
				'id'       => 7,
				'alt_text' => 'x',
			]
		);

		$this->assertSame( 'rest_error', $result->get_error_code() );
		$this->assertSame( 'The media file could not be updated.', $result->get_error_message() );
	}

	public function test_delete_is_permanent(): void {
		$GLOBALS['albert_test_rest_responses']['get'] = [
			'is_error' => false,
			'data'     => [
				'deleted'  => true,
				'previous' => [ 'title' => [ 'raw' => 'Cat' ] ],
			],
		];

		$result = ( new DeleteMedia() )->execute( [ 'id' => 7 ] );

		$this->assertSame(
			[
				'id'      => 7,
				'title'   => 'Cat',
				'deleted' => true,
			],
			$result
		);

		$call = $GLOBALS['albert_test_rest_calls'][0];
		$this->assertSame( 'DELETE', $call['method'] );
		$this->assertSame( '/wp/v2/media/7', $call['route'] );
		$this->assertTrue( $call['params']['force'] );
	}

	public function test_delete_refuses_an_id_that_is_not_an_attachment(): void {
		$result = ( new DeleteMedia() )->execute( [ 'id' => 8 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
		$this->assertSame( [], $GLOBALS['albert_test_rest_calls'] );
	}

	public function test_delete_refusal_becomes_a_permission_denied_code(): void {
		$GLOBALS['albert_test_rest_responses']['get'] = [
			'is_error' => true,
			'data'     => [
				'code'    => 'rest_cannot_delete',
				'message' => 'Sorry, you are not allowed to delete this post.',
			],
			'status'   => 403,
		];

		$result = ( new DeleteMedia() )->execute( [ 'id' => 7 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_permission_denied', $result->get_error_code() );
	}
}
