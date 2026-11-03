<?php
/**
 * Integration tests for registered custom fields on posts and pages.
 *
 * Needs real meta registration and core's REST meta handling, which decide
 * which keys exist, their schema and who may write them.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Registered meta tests.
 */
class RegisteredMetaAbilityTest extends TestCase {

	/**
	 * Post used by most tests.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Register fields and run as an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		register_post_meta(
			'post',
			'subtitle',
			[
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'post',
			'reading_minutes',
			[
				'type'         => 'integer',
				'single'       => true,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'post',
			'locked_note',
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			]
		);
		register_post_meta(
			'post',
			'_plugin_private',
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => '__return_true',
			]
		);
		register_post_meta(
			'post',
			'not_in_rest',
			[
				'type'   => 'string',
				'single' => true,
			]
		);
		register_post_meta(
			'page',
			'hero_text',
			[
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			]
		);

		$this->post_id = self::factory()->post->create( [ 'post_title' => 'Meta host' ] );
		update_post_meta( $this->post_id, 'subtitle', 'Old subtitle' );
		update_post_meta( $this->post_id, '_plugin_private', 'secret' );
		update_post_meta( $this->post_id, 'not_in_rest', 'hidden' );
	}

	/**
	 * Unregister the fields again.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( [ 'subtitle', 'reading_minutes', 'locked_note', '_plugin_private', 'not_in_rest' ] as $key ) {
			unregister_post_meta( 'post', $key );
		}
		unregister_post_meta( 'page', 'hero_text' );

		parent::tear_down();
	}

	/**
	 * View lists registered REST fields only, never protected or unregistered ones.
	 *
	 * @return void
	 */
	public function test_view_post_returns_only_registered_rest_meta(): void {
		$result = ( new ViewPost() )->execute( [ 'id' => $this->post_id ] );

		$this->assertIsArray( $result );
		$meta = $result['post']['meta'];
		$this->assertSame( 'Old subtitle', $meta['subtitle'] );
		$this->assertSame( 0, $meta['reading_minutes'], 'An unset field shows its default, so the assistant sees it exists.' );
		$this->assertArrayNotHasKey( '_plugin_private', $meta );
		$this->assertArrayNotHasKey( 'not_in_rest', $meta );
	}

	/**
	 * Update writes registered fields through core's REST endpoint.
	 *
	 * @return void
	 */
	public function test_update_post_writes_registered_meta(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $this->post_id,
				'meta' => [
					'subtitle'        => 'New subtitle',
					'reading_minutes' => 4,
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New subtitle', get_post_meta( $this->post_id, 'subtitle', true ) );
		$this->assertSame( '4', get_post_meta( $this->post_id, 'reading_minutes', true ) );
		$this->assertSame( 'New subtitle', $result['meta']['subtitle'] );
	}

	/**
	 * Null clears a field, as in core's REST API.
	 *
	 * @return void
	 */
	public function test_null_clears_a_field(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $this->post_id,
				'meta' => [ 'subtitle' => null ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', get_post_meta( $this->post_id, 'subtitle', true ) );
	}

	/**
	 * An unregistered key is refused and the post is left untouched.
	 *
	 * @return void
	 */
	public function test_unregistered_key_is_refused_before_anything_is_saved(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'    => $this->post_id,
				'title' => 'Changed title',
				'meta'  => [ 'not_in_rest' => 'x' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'meta_not_registered', $result->get_error_code() );
		$this->assertStringContainsString( 'subtitle', $result->get_error_message(), 'The refusal names the fields that can be written.' );
		$this->assertSame( 'Meta host', get_post( $this->post_id )->post_title );
		$this->assertSame( 'hidden', get_post_meta( $this->post_id, 'not_in_rest', true ) );
	}

	/**
	 * A protected key is refused even when registered for REST with a permissive auth callback.
	 *
	 * @return void
	 */
	public function test_protected_key_is_refused_even_when_registered(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $this->post_id,
				'meta' => [ '_plugin_private' => 'overwritten' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'meta_not_registered', $result->get_error_code() );
		$this->assertSame( 'secret', get_post_meta( $this->post_id, '_plugin_private', true ) );
	}

	/**
	 * A value its schema rejects is refused.
	 *
	 * @return void
	 */
	public function test_value_that_fails_the_schema_is_refused(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $this->post_id,
				'meta' => [ 'reading_minutes' => 'four' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'meta_invalid', $result->get_error_code() );
	}

	/**
	 * The key's auth callback decides who may write it.
	 *
	 * @return void
	 */
	public function test_auth_callback_is_respected(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $this->post_id,
				'meta' => [ 'locked_note' => 'x' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'meta_permission_denied', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( $this->post_id, 'locked_note', true ) );
	}

	/**
	 * A list is not a key/value object.
	 *
	 * @return void
	 */
	public function test_a_list_is_refused(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $this->post_id,
				'meta' => [ 'subtitle' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'meta_invalid', $result->get_error_code() );
	}

	/**
	 * Pages read and write their own registered fields; a post's field is not a page's.
	 *
	 * @return void
	 */
	public function test_pages_read_and_write_their_registered_meta(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$view = ( new ViewPage() )->execute( [ 'id' => $page_id ] );
		$this->assertIsArray( $view );
		$this->assertArrayHasKey( 'hero_text', $view['page']['meta'] );
		$this->assertArrayNotHasKey( 'subtitle', $view['page']['meta'] );

		$result = ( new UpdatePage() )->execute(
			[
				'id'   => $page_id,
				'meta' => [ 'hero_text' => 'Welcome' ],
			]
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'Welcome', get_post_meta( $page_id, 'hero_text', true ) );

		$refused = ( new UpdatePage() )->execute(
			[
				'id'   => $page_id,
				'meta' => [ 'subtitle' => 'x' ],
			]
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'meta_not_registered', $refused->get_error_code() );
	}

	/**
	 * Safe mode holds an update that overwrites meta, and passes one that does not.
	 *
	 * @return void
	 */
	public function test_safe_mode_holds_a_meta_overwrite_only(): void {
		$gate    = new Gate();
		$ability = wp_get_ability( 'albert/update-post' );

		if ( $ability === null ) {
			$this->markTestSkipped( 'albert/update-post is not registered.' );
		}

		$this->assertTrue(
			$gate->must_gate(
				$ability,
				[
					'id'   => $this->post_id,
					'meta' => [ 'subtitle' => 'x' ],
				]
			)
		);
		$this->assertFalse(
			$gate->must_gate(
				$ability,
				[
					'id'    => $this->post_id,
					'title' => 'x',
				]
			)
		);
	}
}
