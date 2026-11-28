<?php
/**
 * Integration tests for privacy mode on WordPress 7.2's user and content queries.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Privacy;

use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;

/**
 * Core's user and content queries mask people as `find-users` does, and its settings mask the admin email.
 *
 * On WordPress 7.2 the real abilities run. Before 7.2 a stand-in returning
 * core's documented shape takes their name, so the redaction is still tested.
 *
 * @covers \Albert\Privacy\ConnectedResultRedactor
 */
class CoreAbilitiesRedactionTest extends TestCase {

	/**
	 * Test-only ability category.
	 *
	 * @var string
	 */
	private const TEST_CATEGORY = 'albert-test';

	/**
	 * Every user field core returns, for the tests that need all of them.
	 *
	 * @var list<string>
	 */
	private const ALL_USER_FIELDS = [ 'id', 'name', 'description', 'url', 'link', 'slug', 'avatar_urls', 'username', 'email', 'first_name', 'last_name', 'nickname', 'roles' ];

	/**
	 * Abilities registered by this test, to remove afterwards.
	 *
	 * @var list<string>
	 */
	private array $stand_ins = [];

	/**
	 * The user both queries are asked about.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * A post by that user.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Create the user and post, connect as an administrator, and register stand-ins before 7.2.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		$this->user_id = self::factory()->user->create(
			[
				'user_login'   => 'jane_login',
				'user_email'   => 'jane@albert.test',
				'display_name' => 'Jane Doe',
				'first_name'   => 'Jane',
				'last_name'    => 'Doe',
				'user_url'     => 'https://jane.example',
				'description'  => 'Lives in Utrecht.',
				'role'         => 'author',
			]
		);
		$this->post_id = self::factory()->post->create( [ 'post_author' => $this->user_id ] );

		$this->register_stand_ins();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ConnectionContext::set( 'client-1' );
	}

	/**
	 * Remove the stand-ins and close the connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->stand_ins as $id ) {
			wp_unregister_ability( $id );
		}

		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * Modes under which personal data is masked.
	 *
	 * @return array<string, array{string}>
	 */
	public static function maskingModes(): array {
		return [
			'strict'   => [ 'strict' ],
			'balanced' => [ 'balanced' ],
		];
	}

	/**
	 * Core's user query masks the same user as `find-users` does.
	 *
	 * @dataProvider maskingModes
	 *
	 * @param string $mode Privacy mode.
	 *
	 * @return void
	 */
	public function test_users_query_is_masked_as_find_users( string $mode ): void {
		$this->set_mode( $mode );

		$core   = $this->users_query()['items'][0];
		$albert = wp_get_ability( 'albert/find-users' )->execute( [ 'id' => $this->user_id ] )['users'][0];

		foreach ( [ 'email', 'username', 'name', 'url', 'description' ] as $key ) {
			$this->assertSame( $albert[ $key ], $core[ $key ], "{$key} under {$mode}" );
		}
		$this->assertNotSame( 'jane@albert.test', $core['email'] );
		$this->assertStringNotContainsString( 'Jane', $core['first_name'] . $core['last_name'] . $core['nickname'] . $core['slug'] );
		$this->assertArrayNotHasKey( 'avatar_urls', $core );
		$this->assertSame( $this->user_id, $core['id'] );
	}

	/**
	 * A public author, only an id and a name, is still a person.
	 *
	 * @return void
	 */
	public function test_a_bare_public_author_is_masked(): void {
		$this->set_mode( 'strict' );

		$core = $this->users_query( [ 'id', 'name' ] )['items'][0];

		$this->assertSame( [ 'id', 'name' ], array_keys( $core ) );
		$this->assertStringNotContainsString( 'Jane', $core['name'] );
	}

	/**
	 * On Balanced the reveal input returns the real user, as for Albert's own.
	 *
	 * @return void
	 */
	public function test_users_query_reveals_on_balanced(): void {
		$this->set_mode( 'balanced' );

		$core = $this->users_query( self::ALL_USER_FIELDS, true )['items'][0];

		$this->assertSame( 'jane@albert.test', $core['email'] );
		$this->assertSame( 'Jane Doe', $core['name'] );
	}

	/**
	 * With privacy mode off nothing is masked.
	 *
	 * @return void
	 */
	public function test_users_query_is_real_with_privacy_off(): void {
		$this->set_mode( 'off' );

		$this->assertSame( 'jane_login', $this->users_query()['items'][0]['username'] );
	}

	/**
	 * The admin email in core's settings is masked; the site's own URL and tagline are not.
	 *
	 * @return void
	 */
	public function test_settings_get_masks_the_email_only(): void {
		$this->set_mode( 'strict' );
		update_option( 'blogdescription', 'Just another site' );

		$result = wp_get_ability( 'core/settings-get' )->execute( [ 'fields' => [ 'email', 'url', 'description' ] ] );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotSame( get_option( 'admin_email' ), $result['email'] );
		$this->assertStringContainsString( '***', $result['email'] );
		$this->assertSame( get_option( 'siteurl' ), $result['url'] );
		$this->assertSame( 'Just another site', $result['description'] );
	}

	/**
	 * A post's author is masked, the post itself is not.
	 *
	 * @return void
	 */
	public function test_content_query_masks_the_author_only(): void {
		$this->set_mode( 'strict' );

		$item = $this->content_query()['items'][0];

		$this->assertSame( $this->user_id, $item['author']['id'] );
		$this->assertStringNotContainsString( 'Jane', (string) $item['author']['name'] );
		$this->assertStringNotContainsString( 'jane', (string) $item['author']['slug'] );
		$this->assertSame( get_post_field( 'post_name', $this->post_id ), $item['slug'] );
		$this->assertSame( get_post_field( 'post_title', $this->post_id ), $item['title']['raw'] ?? $item['title'] );
		$this->assertSame( get_permalink( $this->post_id ), $item['link'] );
	}

	/**
	 * On Balanced the reveal input returns the real author.
	 *
	 * @return void
	 */
	public function test_content_query_reveals_the_author_on_balanced(): void {
		$this->set_mode( 'balanced' );

		$this->assertSame( 'Jane Doe', $this->content_query( true )['items'][0]['author']['name'] );
	}

	/**
	 * Set the privacy mode, which a connection may not write.
	 *
	 * @param string $mode Privacy mode.
	 *
	 * @return void
	 */
	private function set_mode( string $mode ): void {
		ConnectionContext::reset();
		update_option( 'albert_privacy_mode', $mode );
		ConnectionContext::set( 'client-1' );
	}

	/**
	 * Run core's user query for the test user.
	 *
	 * @param list<string> $fields Fields to ask for.
	 * @param bool         $reveal Whether to ask for the real data.
	 *
	 * @return array<string, mixed>
	 */
	private function users_query( array $fields = self::ALL_USER_FIELDS, bool $reveal = false ): array {
		$input = [
			'where'  => [ 'ids' => [ $this->user_id ] ],
			'fields' => $fields,
		];
		if ( $reveal ) {
			$input['reveal_personal_data'] = true;
		}

		$result = wp_get_ability( 'core/users-query' )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $result;
	}

	/**
	 * Run core's content query for the test post.
	 *
	 * @param bool $reveal Whether to ask for the real data.
	 *
	 * @return array<string, mixed>
	 */
	private function content_query( bool $reveal = false ): array {
		$input = [
			'where'  => [
				'type' => 'post',
				'ids'  => [ $this->post_id ],
			],
			'fields' => [ 'id', 'slug', 'title', 'link', 'author' ],
		];
		if ( $reveal ) {
			$input['reveal_personal_data'] = true;
		}

		$result = wp_get_ability( 'core/content-query' )->execute( $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $result;
	}

	/**
	 * Register core's two queries, or stand-ins with their names and shapes before 7.2.
	 *
	 * WordPress's test suite unhooks core's abilities, so on 7.2 its classes
	 * are registered here.
	 *
	 * @return void
	 */
	private function register_stand_ins(): void {
		$core = class_exists( 'WP_Abilities_Users' ) && class_exists( 'WP_Abilities_Content' ) && class_exists( 'WP_Abilities_Settings' );

		$this->during(
			'wp_abilities_api_categories_init',
			static function () use ( $core ): void {
				foreach ( $core ? [ 'site', 'user', 'content' ] : [ self::TEST_CATEGORY ] as $slug ) {
					if ( ! wp_has_ability_category( $slug ) ) {
						wp_register_ability_category(
							$slug,
							[
								'label'       => ucfirst( $slug ),
								'description' => 'Test category.',
							]
						);
					}
				}
			}
		);

		$this->during(
			'wp_abilities_api_init',
			function () use ( $core ): void {
				if ( $core ) {
					$classes = [
						'core/users-query'   => 'WP_Abilities_Users',
						'core/content-query' => 'WP_Abilities_Content',
						'core/settings-get'  => 'WP_Abilities_Settings',
					];
					foreach ( $classes as $id => $class ) {
						if ( ! wp_has_ability( $id ) ) {
							( new $class() )->register();
							$this->stand_ins[] = $id;
						}
					}

					return;
				}

				$callbacks = [
					'core/users-query'   => [ $this, 'fake_users_query' ],
					'core/content-query' => [ $this, 'fake_content_query' ],
					'core/settings-get'  => [ $this, 'fake_settings_get' ],
				];
				foreach ( $callbacks as $id => $callback ) {
					wp_register_ability(
						$id,
						[
							'label'               => $id,
							'description'         => 'Stand-in for WordPress 7.2.',
							'category'            => self::TEST_CATEGORY,
							'input_schema'        => [
								'type'       => 'object',
								'properties' => [
									'where'  => [ 'type' => 'object' ],
									'fields' => [ 'type' => 'array' ],
									'group'  => [ 'type' => 'string' ],
								],
							],
							'execute_callback'    => $callback,
							'permission_callback' => '__return_true',
							'meta'                => [
								'annotations' => [
									'readonly'    => true,
									'destructive' => false,
								],
								'mcp'         => [ 'public' => true ],
							],
						]
					);
					$this->stand_ins[] = $id;
				}
			}
		);
	}

	/**
	 * Core's user query for one id, in core's shape.
	 *
	 * @param array<string, mixed> $input Input.
	 *
	 * @return array<string, mixed>
	 */
	public function fake_users_query( array $input ): array {
		$user = get_userdata( (int) $input['where']['ids'][0] );
		$item = [
			'id'          => $user->ID,
			'name'        => $user->display_name,
			'description' => $user->description,
			'url'         => $user->user_url,
			'link'        => get_author_posts_url( $user->ID ),
			'slug'        => $user->user_nicename,
			'avatar_urls' => [ '96' => 'https://secure.gravatar.com/avatar/x' ],
			'username'    => $user->user_login,
			'email'       => $user->user_email,
			'first_name'  => $user->first_name,
			'last_name'   => $user->last_name,
			'nickname'    => $user->nickname,
			'roles'       => array_values( $user->roles ),
		];
		$item = array_intersect_key( $item, array_flip( $input['fields'] ) );

		return [
			'items'      => [ $item ],
			'pagination' => [
				'total'       => 1,
				'total_pages' => 1,
			],
		];
	}

	/**
	 * Core's content query for one id, in core's shape.
	 *
	 * @param array<string, mixed> $input Input.
	 *
	 * @return array<string, mixed>
	 */
	public function fake_content_query( array $input ): array {
		$post   = get_post( (int) $input['where']['ids'][0] );
		$author = get_userdata( (int) $post->post_author );

		return [
			'items'      => [
				[
					'id'     => $post->ID,
					'slug'   => $post->post_name,
					'title'  => [ 'raw' => $post->post_title ],
					'link'   => get_permalink( $post ),
					'author' => [
						'id'   => $author->ID,
						'name' => $author->display_name,
						'slug' => $author->user_nicename,
					],
				],
			],
			'pagination' => [
				'total'       => 1,
				'total_pages' => 1,
			],
		];
	}

	/**
	 * Core's settings map, in core's shape and REST names.
	 *
	 * @return array<string, mixed>
	 */
	public function fake_settings_get(): array {
		return [
			'email'       => get_option( 'admin_email' ),
			'url'         => get_option( 'siteurl' ),
			'description' => get_option( 'blogdescription' ),
		];
	}

	/**
	 * Run a callback while the given one-shot action reports as in progress.
	 *
	 * @param string   $action   Action name.
	 * @param callable $callback What to run.
	 *
	 * @return void
	 */
	private function during( string $action, callable $callback ): void {
		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern.
		$wp_current_filter[] = $action;

		try {
			$callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
