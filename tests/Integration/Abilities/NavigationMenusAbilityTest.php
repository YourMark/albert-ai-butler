<?php
/**
 * Integration tests for editing navigation menus through the menu item abilities.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Menus\NavigationTree;
use Albert\Blocks\EditorMode;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Menu item abilities on a block theme's navigation menus, called as an assistant calls them.
 *
 * The `albert-block-test` fixture theme shows a `header` part; tests that need
 * the header to name a menu save a header part over it with `"ref"`.
 *
 * @covers \Albert\Abilities\WordPress\Menus\NavigationEditor
 * @covers \Albert\Abilities\WordPress\Menus\NavigationWriter
 * @covers \Albert\Abilities\WordPress\Menus\NavigationTree
 */
class NavigationMenusAbilityTest extends TestCase {

	/**
	 * Theme directories before the fixtures were added.
	 *
	 * @var array<int, string>
	 */
	private array $theme_directories = [];

	/**
	 * The theme active before the test.
	 *
	 * @var string
	 */
	private string $original_theme = '';

	/**
	 * Run as an administrator on the block fixture theme.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_theme_directories;
		$this->theme_directories = (array) $wp_theme_directories;
		$this->original_theme    = get_stylesheet();

		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		$this->switch_to( 'albert-block-test' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Put the theme back.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_theme_directories;

		$wp_theme_directories = $this->theme_directories;
		$this->switch_to( $this->original_theme );

		parent::tear_down();
	}

	/**
	 * Activate a theme and drop every cache that remembers the previous one.
	 *
	 * @param string $stylesheet Theme directory name.
	 *
	 * @return void
	 */
	private function switch_to( string $stylesheet ): void {
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( $stylesheet );
		wp_clean_theme_json_cache();
	}

	/**
	 * A published navigation menu.
	 *
	 * @param string $content Block markup.
	 * @param string $date    Post date.
	 *
	 * @return int
	 */
	private function navigation( string $content, string $date = '2026-01-01 10:00:00' ): int {
		return (int) self::factory()->post->create(
			[
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Main',
				'post_status'  => 'publish',
				'post_date'    => $date,
				// Slashed as wp_insert_post() expects, or escaped attribute JSON is corrupted.
				'post_content' => wp_slash( $content ),
			]
		);
	}

	/**
	 * The standard menu: About, Shop with Shoes under it, Blog.
	 *
	 * @return int
	 */
	private function standard_navigation(): int {
		return $this->navigation(
			'<!-- wp:navigation-link {"label":"About","url":"https://example.com/about","kind":"custom","isTopLevelLink":true} /-->' . "\n\n"
			. '<!-- wp:navigation-submenu {"label":"Shop","url":"https://example.com/shop","kind":"custom"} -->'
			. '<!-- wp:navigation-link {"label":"Shoes","url":"https://example.com/shoes","kind":"custom"} /-->'
			. '<!-- /wp:navigation-submenu -->' . "\n\n"
			. '<!-- wp:navigation-link {"label":"Blog","url":"https://example.com/blog","kind":"custom"} /-->'
		);
	}

	/**
	 * Save a header part, as the Site Editor would, whose navigation block shows one menu.
	 *
	 * @param int $navigation `wp_navigation` post id.
	 *
	 * @return void
	 */
	private function header_shows( int $navigation ): void {
		$part = self::factory()->post->create(
			[
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_title'   => 'Header',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation {"ref":' . $navigation . '} /--></div><!-- /wp:group -->',
			]
		);
		wp_set_post_terms( $part, 'albert-block-test', 'wp_theme' );
		wp_set_post_terms( $part, 'header', 'wp_template_part_area' );
	}

	/**
	 * Run an ability the way an assistant does.
	 *
	 * @param string               $id    Ability id.
	 * @param array<string, mixed> $input Input.
	 *
	 * @return mixed
	 */
	private function call( string $id, array $input ) {
		return wp_get_ability( $id )->execute( $input );
	}

	/**
	 * A navigation menu read through view-menu.
	 *
	 * @param int $id Menu.
	 *
	 * @return array<string, mixed>
	 */
	private function view( int $id ): array {
		return $this->call(
			'albert/view-menu',
			[
				'menu_id'   => $id,
				'menu_type' => 'block',
			]
		);
	}

	/**
	 * Labels as "Parent > Child".
	 *
	 * @param list<array<string, mixed>> $items  Tree.
	 * @param string                     $prefix Parent label.
	 *
	 * @return list<string>
	 */
	private function labels( array $items, string $prefix = '' ): array {
		$labels = [];
		foreach ( $items as $item ) {
			$labels[] = $prefix . $item['label'];
			if ( $item['kind'] !== 'page-list' ) {
				$labels = array_merge( $labels, $this->labels( $item['children'], $prefix . $item['label'] . ' > ' ) );
			}
		}
		return $labels;
	}

	/**
	 * The stored blocks of a menu.
	 *
	 * @param int $id Menu.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function stored( int $id ): array {
		clean_post_cache( $id );

		return array_values( array_filter( parse_blocks( get_post( $id )->post_content ), static fn( array $block ): bool => $block['blockName'] !== null ) );
	}

	/**
	 * "Add a Contact link to the menu visitors see": find the header's menu, add to it.
	 *
	 * @return void
	 */
	public function test_an_assistant_adds_a_link_to_the_menu_visitors_see(): void {
		$unused = $this->standard_navigation();
		$main   = $this->standard_navigation();
		$this->header_shows( $main );

		$found = $this->call( 'albert/find-menus', [] );
		$this->assertSame( 'block', $found['header']['type'] );
		$this->assertSame( $main, $found['header']['id'] );

		$added = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'   => $found['header']['id'],
				'menu_type' => 'block',
				'title'     => 'Contact',
				'url'       => 'https://example.com/contact',
			]
		);

		$this->assertIsArray( $added );
		$this->assertSame( [ 3 ], $added['path'] );
		$this->assertSame( 'Contact', $added['title'] );
		$this->assertTrue( $added['visible'] );
		$this->assertArrayNotHasKey( 'notice', $added );
		$this->assertSame( [ 'About', 'Shop', 'Shop > Shoes', 'Blog', 'Contact' ], $this->labels( $added['items'] ) );

		$stored = $this->stored( $main );
		$this->assertSame( 'core/navigation-link', $stored[3]['blockName'] );
		$this->assertSame(
			[
				'label' => 'Contact',
				'url'   => 'https://example.com/contact',
				'kind'  => 'custom',
			],
			$stored[3]['attrs']
		);
		$this->assertSame( [ 'About', 'Shop', 'Shop > Shoes', 'Blog' ], $this->labels( $this->view( $unused )['items'] ), 'The menu nobody sees is untouched.' );
	}

	/**
	 * Page and term links keep kind, type and id, so they follow a slug change.
	 *
	 * @return void
	 */
	public function test_page_and_term_links_keep_their_object(): void {
		$main = $this->standard_navigation();
		$this->header_shows( $main );
		$page = (int) self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Team',
			]
		);
		$term = (int) self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'News',
			]
		);

		$page_link = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'         => $main,
				'menu_type'       => 'block',
				'item_type'       => 'post_type',
				'object_id'       => $page,
				'position'        => 1,
				'open_in_new_tab' => true,
			]
		);
		$term_link = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'item_type' => 'taxonomy',
				'object_id' => $term,
			]
		);

		$this->assertSame( [ 0 ], $page_link['path'] );
		$this->assertSame( 'Team', $page_link['title'] );
		$this->assertTrue( $page_link['open_in_new_tab'] );

		$stored = $this->stored( $main );
		$this->assertSame( 'page', $stored[0]['attrs']['type'] );
		$this->assertSame( $page, $stored[0]['attrs']['id'] );
		$this->assertSame( 'post-type', $stored[0]['attrs']['kind'] );
		$this->assertTrue( $stored[0]['attrs']['opensInNewTab'] );
		$this->assertSame( 'tag', $stored[4]['attrs']['type'] );
		$this->assertSame( $term, $stored[4]['attrs']['id'] );
		$this->assertSame( 'taxonomy', $stored[4]['attrs']['kind'] );
		$this->assertSame( [ 4 ], $term_link['path'] );
	}

	/**
	 * A child turns a link into a submenu; removing the last child turns it back.
	 *
	 * @return void
	 */
	public function test_nested_submenu_add_and_remove(): void {
		$main = $this->standard_navigation();
		$this->header_shows( $main );

		$team = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'     => $main,
				'menu_type'   => 'block',
				'title'       => 'Team',
				'url'         => 'https://example.com/team',
				'parent_path' => [ 0 ],
			]
		);

		$this->assertSame( [ 0, 0 ], $team['path'] );
		$this->assertSame( 'submenu', $team['items'][0]['kind'] );
		$this->assertSame( 'core/navigation-submenu', $this->stored( $main )[0]['blockName'] );
		$this->assertArrayNotHasKey( 'isTopLevelLink', $this->stored( $main )[0]['attrs'] );

		$boots = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'     => $main,
				'menu_type'   => 'block',
				'title'       => 'Boots',
				'url'         => 'https://example.com/boots',
				'parent_path' => [ 1 ],
				'position'    => 1,
			]
		);
		$this->assertSame( [ 'About', 'About > Team', 'Shop', 'Shop > Boots', 'Shop > Shoes', 'Blog' ], $this->labels( $boots['items'] ) );

		$removed = $this->call(
			'albert/remove-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => [ 0, 0 ],
			]
		);

		$this->assertTrue( $removed['deleted'] );
		$this->assertSame( 'Team', $removed['title'] );
		$this->assertSame( 'link', $removed['items'][0]['kind'] );
		$stored = $this->stored( $main );
		$this->assertSame( 'core/navigation-link', $stored[0]['blockName'] );
		$this->assertSame( 'About', $stored[0]['attrs']['label'] );
		$this->assertStringNotContainsString( '<!-- /wp:navigation-link', get_post( $main )->post_content, 'An emptied submenu is saved as a self-closing link.' );
	}

	/**
	 * Removing a submenu moves its items up into its place.
	 *
	 * @return void
	 */
	public function test_removing_a_submenu_moves_its_items_up(): void {
		$main = $this->standard_navigation();

		$removed = $this->call(
			'albert/remove-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => [ 1 ],
			]
		);

		$this->assertSame( [ [ 1 ] ], $removed['moved_up'] );
		$this->assertSame( [ 'About', 'Shoes', 'Blog' ], $this->labels( $removed['items'] ) );
	}

	/**
	 * Moving into a link makes it a submenu, and the old submenu becomes a link when it empties.
	 *
	 * @return void
	 */
	public function test_moving_items_between_levels(): void {
		$main = $this->standard_navigation();

		$moved = $this->call(
			'albert/move-menu-item',
			[
				'menu_id'     => $main,
				'menu_type'   => 'block',
				'path'        => [ 1, 0 ],
				'parent_path' => [ 2 ],
			]
		);

		$this->assertSame( [ 2, 0 ], $moved['path'] );
		$this->assertSame( [ 'About', 'Shop', 'Blog', 'Blog > Shoes' ], $this->labels( $moved['items'] ) );
		$this->assertSame( [ 'link', 'link', 'submenu' ], array_column( $moved['items'], 'kind' ) );

		$back = $this->call(
			'albert/move-menu-item',
			[
				'menu_id'     => $main,
				'menu_type'   => 'block',
				'path'        => [ 0 ],
				'parent_path' => [ 2 ],
				'position'    => 1,
			]
		);

		$this->assertSame( [ 1, 0 ], $back['path'], 'Blog is at [1] once About has left the top level.' );
		$this->assertSame( [ 'Shop', 'Blog', 'Blog > About', 'Blog > Shoes' ], $this->labels( $back['items'] ) );

		$loop = $this->call(
			'albert/move-menu-item',
			[
				'menu_id'     => $main,
				'menu_type'   => 'block',
				'path'        => [ 1 ],
				'parent_path' => [ 1, 0 ],
			]
		);
		$this->assertSame( 'invalid_parent_path', $loop->get_error_code() );
	}

	/**
	 * "Rename Blog to News" in a page list: the list becomes links first, as the Site Editor does.
	 *
	 * @return void
	 */
	public function test_renaming_a_page_in_a_page_list_turns_it_into_links(): void {
		$about = (int) self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'About',
				'menu_order' => 1,
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Team',
				'post_parent' => $about,
			]
		);
		self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Blog',
				'menu_order' => 2,
			]
		);
		$main = $this->navigation( '<!-- wp:navigation-link {"label":"Home","url":"https://example.com/"} /--><!-- wp:page-list /--><!-- wp:navigation-link {"label":"Contact","url":"https://example.com/contact"} /-->' );
		$this->header_shows( $main );

		$view = $this->view( $main );
		$list = $view['items'][1];
		$this->assertSame( 'page-list', $list['kind'] );
		$this->assertSame( [ 'About', 'About > Team', 'Blog' ], $this->labels( $list['children'] ) );
		$this->assertSame( [ 1, 1 ], $list['children'][1]['path'] );
		$this->assertTrue( $list['children'][1]['automatic'] );

		$renamed = $this->call(
			'albert/update-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => $list['children'][1]['path'],
				'title'     => 'News',
			]
		);

		$this->assertIsArray( $renamed );
		$this->assertSame( [ 2 ], $renamed['path'], 'The list\'s pages now sit where it was.' );
		$this->assertSame( 'News', $renamed['title'] );
		$this->assertStringContainsString( 'no longer added', $renamed['notice'] );
		$this->assertSame( [ 'Home', 'About', 'About > Team', 'News', 'Contact' ], $this->labels( $renamed['items'] ) );
		$this->assertStringNotContainsString( 'wp:page-list', get_post( $main )->post_content );

		$stored = $this->stored( $main );
		$this->assertSame( 'core/navigation-submenu', $stored[1]['blockName'] );
		$this->assertSame( $about, $stored[1]['attrs']['id'] );
		$this->assertSame( 'post-type', $stored[1]['attrs']['kind'] );

		self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Later',
			]
		);
		$this->assertNotContains( 'Later', $this->labels( $this->view( $main )['items'] ), 'New pages no longer join the menu.' );
	}

	/**
	 * Hiding and reordering pages of a page list.
	 *
	 * @return void
	 */
	public function test_hiding_and_reordering_page_list_pages(): void {
		foreach ( [ 'One', 'Two', 'Three' ] as $order => $title ) {
			self::factory()->post->create(
				[
					'post_type'  => 'page',
					'post_title' => $title,
					'menu_order' => $order,
				]
			);
		}
		$main = $this->navigation( '<!-- wp:page-list /-->' );

		$hidden = $this->call(
			'albert/remove-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => [ 0, 1 ],
			]
		);
		$this->assertSame( [ 'One', 'Three' ], $this->labels( $hidden['items'] ) );
		$this->assertStringContainsString( 'no longer added', $hidden['notice'] );

		$main  = $this->navigation( '<!-- wp:page-list /-->' );
		$moved = $this->call(
			'albert/move-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => [ 0, 2 ],
				'position'  => 1,
			]
		);
		$this->assertSame( [ 0 ], $moved['path'] );
		$this->assertSame( [ 'Three', 'One', 'Two' ], $this->labels( $moved['items'] ) );

		$into = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'     => $this->navigation( '<!-- wp:page-list /-->' ),
				'menu_type'   => 'block',
				'title'       => 'X',
				'url'         => 'https://example.com/x',
				'parent_path' => [ 0 ],
			]
		);
		$this->assertSame( 'invalid_parent_path', $into->get_error_code() );
	}

	/**
	 * Update changes the label, link and target; a new address makes a page link custom.
	 *
	 * @return void
	 */
	public function test_update_fields(): void {
		$page = (int) self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Pricing',
			]
		);
		$main = $this->navigation( '<!-- wp:navigation-link {"label":"Plans","type":"page","id":' . $page . ',"url":"https://example.com/pricing","kind":"post-type"} /-->' );

		$restored = $this->call(
			'albert/update-menu-item',
			[
				'menu_id'            => $main,
				'menu_type'          => 'block',
				'path'               => [ 0 ],
				'use_original_title' => true,
				'open_in_new_tab'    => true,
				'attr_title'         => 'See the plans',
			]
		);
		$this->assertSame( 'Pricing', $restored['title'] );
		$this->assertTrue( $restored['open_in_new_tab'] );
		$this->assertSame( 'See the plans', $this->stored( $main )[0]['attrs']['title'] );

		$custom = $this->call(
			'albert/update-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => [ 0 ],
				'url'       => 'https://shop.example.com/',
			]
		);
		$attrs  = $this->stored( $main )[0]['attrs'];
		$this->assertSame( 'https://shop.example.com/', $custom['url'] );
		$this->assertSame( 'custom', $attrs['kind'] );
		$this->assertArrayNotHasKey( 'id', $attrs );

		$no_original = $this->call(
			'albert/update-menu-item',
			[
				'menu_id'            => $main,
				'menu_type'          => 'block',
				'path'               => [ 0 ],
				'use_original_title' => true,
			]
		);
		$this->assertSame( 'invalid_use_original_title', $no_original->get_error_code() );

		$missing = $this->call(
			'albert/update-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => [ 5 ],
				'title'     => 'X',
			]
		);
		$this->assertSame( 'invalid_path', $missing->get_error_code() );
	}

	/**
	 * Quotes, ampersands and markup in a label and URL survive the save, and so do other blocks.
	 *
	 * @return void
	 */
	public function test_escaped_attributes_survive_the_save(): void {
		$untouched = '<!-- wp:navigation-link {"label":"\u003cstrong\u003eBold\u003c/strong\u003e \u0022quoted\u0022","url":"https://example.com/?a=1\u0026b=2","kind":"custom"} /-->';
		$main      = $this->navigation( $untouched );

		$added = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'title'     => 'Tom & "Jerry"',
				'url'       => 'https://example.com/?q=a&b="c"',
			]
		);

		$this->assertSame( 'Tom & "Jerry"', $added['title'] );
		$content = get_post( $main )->post_content;
		$this->assertStringStartsWith( $untouched, $content );
		$stored = $this->stored( $main );
		$this->assertSame( '<strong>Bold</strong> "quoted"', $stored[0]['attrs']['label'] );
		$this->assertSame( 'Tom &amp; "Jerry"', $stored[1]['attrs']['label'] );
	}

	/**
	 * A write to a navigation menu visitors do not see says so; the fallback menu says why it shows.
	 *
	 * @return void
	 */
	public function test_hidden_and_fallback_menus_carry_a_notice(): void {
		$older = $this->standard_navigation();
		$newer = $this->navigation( '<!-- wp:navigation-link {"label":"Home","url":"https://example.com/"} /-->', '2026-02-01 10:00:00' );

		$hidden = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'   => $older,
				'menu_type' => 'block',
				'title'     => 'A',
				'url'       => 'https://example.com/a',
			]
		);
		$this->assertFalse( $hidden['visible'] );
		$this->assertStringContainsString( 'Visitors do not see this navigation menu', $hidden['notice'] );

		$fallback = $this->call(
			'albert/add-menu-item',
			[
				'menu_id'   => $newer,
				'menu_type' => 'block',
				'title'     => 'B',
				'url'       => 'https://example.com/b',
			]
		);
		$this->assertTrue( $fallback['visible'] );
		$this->assertStringContainsString( 'newest published navigation menu', $fallback['notice'] );
	}

	/**
	 * Inline links in the header are pointed at, since no menu holds them.
	 *
	 * @return void
	 */
	public function test_inline_header_links_are_pointed_at(): void {
		$part = self::factory()->post->create(
			[
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_title'   => 'Header',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"/"} /--><!-- /wp:navigation -->',
			]
		);
		wp_set_post_terms( $part, 'albert-block-test', 'wp_theme' );
		wp_set_post_terms( $part, 'header', 'wp_template_part_area' );
		$spare = $this->standard_navigation();

		$found = $this->call( 'albert/find-menus', [] );
		$this->assertSame( 'inline', $found['header']['type'] );
		$this->assertStringContainsString( 'Albert cannot change those', $found['summary'] );

		$view = $this->view( $spare );
		$this->assertFalse( $view['visible'] );
		$this->assertStringContainsString( 'Appearance > Editor', $view['notice'] );
	}

	/**
	 * With no navigation menu yet, find-menus says WordPress copies the classic menu.
	 *
	 * @return void
	 */
	public function test_find_menus_names_the_classic_menu_wordpress_copies(): void {
		$old = (int) wp_create_nav_menu( 'Old main' );

		$this->assertStringContainsString( 'lists the published pages', $this->call( 'albert/find-menus', [] )['summary'], 'Core does not copy an empty menu.' );

		wp_update_nav_menu_item(
			$old,
			0,
			[
				'menu-item-title'  => 'Home',
				'menu-item-url'    => 'https://example.com/',
				'menu-item-status' => 'publish',
			]
		);
		$found = $this->call( 'albert/find-menus', [] );

		$this->assertSame( 'none', $found['header']['type'] );
		$this->assertStringContainsString( 'copies the classic menu "Old main"', $found['summary'] );
	}

	/**
	 * A link visitors could not follow is refused, and the menu is left as it was.
	 *
	 * @return void
	 */
	public function test_links_visitors_cannot_open_are_refused(): void {
		$main   = $this->standard_navigation();
		$before = get_post( $main )->post_content;
		$block  = [
			'menu_id'   => $main,
			'menu_type' => 'block',
		];
		$draft  = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
			]
		);

		$script   = $this->call(
			'albert/add-menu-item',
			$block + [
				'title' => 'Script',
				'url'   => 'javascript:alert(1)',
			]
		);
		$page     = $this->call(
			'albert/add-menu-item',
			$block + [
				'item_type' => 'post_type',
				'object_id' => $draft,
			]
		);
		$itself   = $this->call(
			'albert/add-menu-item',
			$block + [
				'item_type' => 'post_type',
				'object_id' => $main,
			]
		);
		$retarget = $this->call(
			'albert/update-menu-item',
			$block + [
				'path' => [ 0 ],
				'url'  => 'javascript:alert(1)',
			]
		);

		$this->assertSame( 'invalid_url', $script->get_error_code() );
		$this->assertStringContainsString( 'not published', $page->get_error_message() );
		$this->assertSame( 'invalid_object_id', $itself->get_error_code(), 'A navigation menu is not a page visitors open.' );
		$this->assertSame( 'invalid_url', $retarget->get_error_code() );
		$this->assertSame( $before, get_post( $main )->post_content );
	}

	/**
	 * Only the pages a page list shows count towards its limit.
	 *
	 * @return void
	 */
	public function test_a_page_list_branch_on_a_large_site_is_listed(): void {
		$parent = (int) self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Services',
			]
		);
		foreach ( [ 'Design', 'Hosting' ] as $title ) {
			self::factory()->post->create(
				[
					'post_type'   => 'page',
					'post_title'  => $title,
					'post_parent' => $parent,
				]
			);
		}
		self::factory()->post->create_many( NavigationTree::MAX_PAGE_LIST, [ 'post_type' => 'page' ] );

		$branch = $this->view( $this->navigation( '<!-- wp:page-list {"parentPageID":' . $parent . '} /-->' ) );
		$this->assertSame( [ 'Design', 'Hosting' ], array_column( $branch['items'][0]['children'], 'label' ) );

		$all = $this->navigation( '<!-- wp:page-list /-->' );
		$this->assertSame( [], $this->view( $all )['items'][0]['children'] );
		$this->assertStringContainsString( 'not listed here', $this->view( $all )['items'][0]['label'] );
	}

	/**
	 * The schemas keep classic and navigation fields apart and require useful input.
	 *
	 * @return void
	 */
	public function test_schemas_separate_the_two_kinds_of_menu(): void {
		$valid = static fn( string $id, array $input ): bool => rest_validate_value_from_schema( $input, wp_get_ability( $id )->get_input_schema(), 'input' ) === true;
		$block = [
			'menu_id'   => 1,
			'menu_type' => 'block',
		];

		$this->assertTrue(
			$valid(
				'albert/add-menu-item',
				$block + [
					'title'       => 'A',
					'url'         => 'https://example.com',
					'parent_path' => [ 0 ],
				]
			)
		);
		$this->assertFalse(
			$valid(
				'albert/add-menu-item',
				$block + [
					'title'          => 'A',
					'url'            => 'https://example.com',
					'parent_item_id' => 3,
				]
			),
			'parent_item_id is for classic menus.'
		);
		$this->assertFalse(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id'     => 1,
					'menu_type'   => 'classic',
					'title'       => 'A',
					'url'         => 'https://example.com',
					'parent_path' => [ 0 ],
				]
			),
			'parent_path is for navigation menus.'
		);
		$this->assertFalse(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id' => 1,
					'title'   => 'A',
					'url'     => 'https://example.com',
				]
			),
			'A menu ID alone can name two menus.'
		);

		$this->assertTrue(
			$valid(
				'albert/update-menu-item',
				$block + [
					'path'  => [ 0 ],
					'title' => 'A',
				]
			)
		);
		$this->assertFalse( $valid( 'albert/update-menu-item', $block + [ 'path' => [ 0 ] ] ), 'An update needs a field.' );
		$this->assertFalse( $valid( 'albert/update-menu-item', $block + [ 'title' => 'A' ] ), 'A navigation item needs its path.' );
		$this->assertFalse(
			$valid(
				'albert/update-menu-item',
				$block + [
					'path'  => [],
					'title' => 'A',
				]
			),
			'An empty path names no item.'
		);
		$this->assertFalse(
			$valid(
				'albert/update-menu-item',
				[
					'menu_item_id' => 1,
					'path'         => [ 0 ],
					'title'        => 'A',
				]
			)
		);

		$this->assertTrue(
			$valid(
				'albert/move-menu-item',
				$block + [
					'path'        => [ 1 ],
					'parent_path' => [],
				]
			)
		);
		$this->assertFalse( $valid( 'albert/move-menu-item', $block + [ 'path' => [ 1 ] ] ), 'A move needs a parent or a position.' );

		$this->assertTrue( $valid( 'albert/remove-menu-item', $block + [ 'path' => [ 1 ] ] ) );
		$this->assertFalse( $valid( 'albert/remove-menu-item', $block ) );
		$this->assertTrue( $valid( 'albert/remove-menu-item', [ 'menu_item_id' => 1 ] ) );
		$this->assertFalse(
			$valid(
				'albert/remove-menu-item',
				[
					'menu_item_id' => 1,
					'menu_type'    => 'block',
				]
			),
			'A classic item is not in a navigation menu.'
		);
	}

	/**
	 * Navigation menus are block content for the block engine.
	 *
	 * @return void
	 */
	public function test_editor_mode_treats_navigation_menus_as_blocks(): void {
		EditorMode::reset_cache();

		$this->assertTrue( EditorMode::is_block_editor( 'wp_navigation', $this->standard_navigation() ) );
	}

	/**
	 * Core's REST route decides: a user who cannot edit the menu changes nothing.
	 *
	 * @return void
	 */
	public function test_a_refused_save_changes_nothing(): void {
		$main   = $this->standard_navigation();
		$before = get_post( $main )->post_content;

		$refuse = static function ( $result, $server, $request ) {
			return preg_match( '#^/wp/v2/navigation/\d+$#', $request->get_route() ) ? new WP_Error( 'rest_cannot_edit', 'Refused.', [ 'status' => 403 ] ) : $result;
		};
		add_filter( 'rest_pre_dispatch', $refuse, 10, 3 );

		$result = $this->call(
			'albert/remove-menu-item',
			[
				'menu_id'   => $main,
				'menu_type' => 'block',
				'path'      => [ 0 ],
			]
		);

		remove_filter( 'rest_pre_dispatch', $refuse, 10 );

		$this->assertSame( 'menu_permission_denied', $result->get_error_code() );
		$this->assertSame( $before, get_post( $main )->post_content );
	}
}
