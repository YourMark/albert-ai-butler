<?php
/**
 * Integration tests for albert/add-post-meta and albert/remove-post-meta.
 *
 * Needs real meta registration and core's REST meta handling, which decide
 * which keys exist, their item schema and who may write them.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Posts\AddMeta;
use Albert\Abilities\WordPress\Posts\RemoveMeta;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Multi-value custom field tests.
 */
class PostMetaListAbilityTest extends TestCase {

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
			'related',
			[
				'type'         => 'string',
				'single'       => false,
				'show_in_rest' => true,
			]
		);
		register_post_meta(
			'post',
			'scores',
			[
				'type'         => 'integer',
				'single'       => false,
				'show_in_rest' => true,
			]
		);
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
			'locked_list',
			[
				'type'          => 'string',
				'single'        => false,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			]
		);
		register_post_meta(
			'post',
			'_private_list',
			[
				'type'          => 'string',
				'single'        => false,
				'show_in_rest'  => true,
				'auth_callback' => '__return_true',
			]
		);
		register_post_meta(
			'page',
			'page_tags',
			[
				'type'         => 'string',
				'single'       => false,
				'show_in_rest' => true,
			]
		);

		$this->post_id = self::factory()->post->create( [ 'post_title' => 'List host' ] );
		add_post_meta( $this->post_id, 'related', 'a' );
		add_post_meta( $this->post_id, 'related', 'b' );
	}

	/**
	 * Unregister the fields again.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( [ 'related', 'scores', 'subtitle', 'locked_list', '_private_list' ] as $key ) {
			unregister_post_meta( 'post', $key );
		}
		unregister_post_meta( 'page', 'page_tags' );

		parent::tear_down();
	}

	/**
	 * Values are appended in one call, and the result lists the whole field.
	 *
	 * @return void
	 */
	public function test_add_appends_every_value(): void {
		$result = ( new AddMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'related',
				'values'  => [ 'c', 'd' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'a', 'b', 'c', 'd' ], $result['values'] );
		$this->assertSame(
			[
				'id'   => $this->post_id,
				'name' => 'List host',
			],
			$result['post']
		);
		$this->assertSame( [ 'a', 'b', 'c', 'd' ], get_post_meta( $this->post_id, 'related' ) );
	}

	/**
	 * Values are cast to the field's type, so "3" is stored as the integer field expects.
	 *
	 * @return void
	 */
	public function test_add_casts_to_the_field_type(): void {
		$result = ( new AddMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'scores',
				'values'  => [ 3, '4' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 3, 4 ], $result['values'] );
	}

	/**
	 * A value the item schema rejects is refused and nothing is saved.
	 *
	 * @return void
	 */
	public function test_add_refuses_a_value_of_the_wrong_type(): void {
		$result = ( new AddMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'scores',
				'values'  => [ 3, 'many' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_values', $result->get_error_code() );
		$this->assertSame( [], get_post_meta( $this->post_id, 'scores' ) );
	}

	/**
	 * Every copy of a value is removed, and the other values stay in order.
	 *
	 * @return void
	 */
	public function test_remove_drops_the_values(): void {
		add_post_meta( $this->post_id, 'related', 'a' );

		$result = ( new RemoveMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'related',
				'values'  => [ 'a' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'b' ], $result['values'] );
		$this->assertSame( [ 'b' ], get_post_meta( $this->post_id, 'related' ) );
	}

	/**
	 * Removing a value that is not there is not an error, so a retry is safe.
	 *
	 * @return void
	 */
	public function test_remove_of_a_missing_value_changes_nothing(): void {
		$saves = did_action( 'save_post' );

		$result = ( new RemoveMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'related',
				'values'  => [ 'zzz' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'a', 'b' ], $result['values'] );
		$this->assertSame( $saves, did_action( 'save_post' ), 'Nothing to remove, so nothing is saved.' );
	}

	/**
	 * Removing every value named leaves the field empty.
	 *
	 * @return void
	 */
	public function test_remove_of_every_value_empties_the_field(): void {
		$result = ( new RemoveMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'related',
				'values'  => [ 'a', 'b' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['values'] );
		$this->assertSame( [], get_post_meta( $this->post_id, 'related' ) );
	}

	/**
	 * A single-value field is refused and points to update-post's `meta`.
	 *
	 * @return void
	 */
	public function test_a_single_value_field_points_to_update_post(): void {
		$result = ( new AddMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'subtitle',
				'values'  => [ 'x' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_key', $result->get_error_code() );
		$this->assertStringContainsString( 'albert/update-post', $result->get_error_message() );
	}

	/**
	 * An unregistered or protected key is refused, naming the list fields that exist.
	 *
	 * @return void
	 */
	public function test_unregistered_and_protected_keys_are_refused(): void {
		add_post_meta( $this->post_id, '_private_list', 'secret' );

		foreach ( [ 'no_such_field', '_private_list' ] as $key ) {
			$result = ( new RemoveMeta() )->execute(
				[
					'post_id' => $this->post_id,
					'key'     => $key,
					'values'  => [ 'secret' ],
				]
			);

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'albert_invalid_key', $result->get_error_code() );
			$this->assertStringContainsString( 'related', $result->get_error_message() );
		}

		$this->assertSame( [ 'secret' ], get_post_meta( $this->post_id, '_private_list' ) );
	}

	/**
	 * The key's auth callback decides who may change it.
	 *
	 * @return void
	 */
	public function test_auth_callback_is_respected(): void {
		$result = ( new AddMeta() )->execute(
			[
				'post_id' => $this->post_id,
				'key'     => 'locked_list',
				'values'  => [ 'x' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_meta_permission_denied', $result->get_error_code() );
		$this->assertSame( [], get_post_meta( $this->post_id, 'locked_list' ) );
	}

	/**
	 * A post that does not exist is reported as not found.
	 *
	 * @return void
	 */
	public function test_missing_post_is_not_found(): void {
		$result = ( new AddMeta() )->execute(
			[
				'post_id' => 999999,
				'key'     => 'related',
				'values'  => [ 'x' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_post_not_found', $result->get_error_code() );
	}

	/**
	 * The same abilities change a page's own list fields.
	 *
	 * @return void
	 */
	public function test_works_on_a_page(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new AddMeta() )->execute(
			[
				'post_id' => $page_id,
				'key'     => 'page_tags',
				'values'  => [ 'x' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ 'x' ], get_post_meta( $page_id, 'page_tags' ) );
	}

	/**
	 * The permission check asks the post type's REST controller about this post.
	 *
	 * @return void
	 */
	public function test_permission_follows_the_post(): void {
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$own_post    = self::factory()->post->create(
			[
				'post_author' => $contributor,
				'post_status' => 'draft',
			]
		);
		wp_set_current_user( $contributor );

		$ability = new AddMeta();
		$this->assertTrue( $ability->check_permission( [ 'post_id' => $own_post ] ) );

		$denied = $ability->check_permission( [ 'post_id' => $this->post_id ] );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'albert_post_permission_denied', $denied->get_error_code() );

		$this->assertTrue( $ability->check_permission( [ 'post_id' => 999999 ] ), 'A missing post is left to execute().' );
	}

	/**
	 * The schema refuses an empty list and more than the maximum.
	 *
	 * @return void
	 */
	public function test_schema_bounds_the_values(): void {
		$ability = wp_get_ability( 'albert/add-post-meta' );
		if ( ! $ability ) {
			$this->markTestSkipped( 'albert/add-post-meta is not registered.' );
		}

		foreach ( [ [], array_fill( 0, AddMeta::MAX_VALUES + 1, 'x' ) ] as $values ) {
			$result = $ability->execute(
				[
					'post_id' => $this->post_id,
					'key'     => 'related',
					'values'  => $values,
				]
			);

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}

		$this->assertSame( [ 'a', 'b' ], get_post_meta( $this->post_id, 'related' ) );
	}

	/**
	 * Safe mode holds both abilities.
	 *
	 * @return void
	 */
	public function test_safe_mode_holds_both(): void {
		$gate  = new Gate();
		$input = [
			'post_id' => $this->post_id,
			'key'     => 'related',
			'values'  => [ 'x' ],
		];

		foreach ( [ 'albert/add-post-meta', 'albert/remove-post-meta' ] as $name ) {
			$ability = wp_get_ability( $name );
			if ( $ability === null ) {
				$this->markTestSkipped( $name . ' is not registered.' );
			}

			$this->assertTrue( $gate->must_gate( $ability, $input ), $name );
		}
	}
}
