<?php
/**
 * Integration tests for the menu abilities.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Menus\AddMenuItem;
use Albert\Abilities\WordPress\Menus\AssignMenuLocation;
use Albert\Abilities\WordPress\Menus\CreateMenu;
use Albert\Abilities\WordPress\Menus\DeleteMenu;
use Albert\Abilities\WordPress\Menus\FindMenus;
use Albert\Abilities\WordPress\Menus\GetMenu;
use Albert\Abilities\WordPress\Menus\MoveMenuItem;
use Albert\Abilities\WordPress\Menus\RemoveMenuItem;
use Albert\Abilities\WordPress\Menus\UnassignMenuLocation;
use Albert\Abilities\WordPress\Menus\UpdateMenuItem;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Menu abilities on a classic theme and a block theme.
 *
 * Two fixture themes in `fixtures/themes`: a classic one with two menu
 * locations, and a block theme whose index template shows a `header` part
 * holding a navigation block with no menu of its own. A second header part,
 * `unused`, is in no template.
 *
 * @covers \Albert\Abilities\WordPress\Menus\MenuLocator
 * @covers \Albert\Abilities\WordPress\Menus\MenuItemOrder
 */
class MenusAbilityTest extends TestCase {

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
	 * Run as an administrator with every ability on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_theme_directories;
		$this->theme_directories = (array) $wp_theme_directories;
		$this->original_theme    = get_stylesheet();

		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Put the theme and the menu locations back.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_theme_directories, $_wp_registered_nav_menus;

		$_wp_registered_nav_menus = [];
		$wp_theme_directories     = $this->theme_directories;
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
	 * The classic fixture theme with a primary and a footer location.
	 *
	 * @return void
	 */
	private function use_classic_theme(): void {
		$this->switch_to( 'albert-classic-test' );
		register_nav_menus(
			[
				'primary' => 'Primary Menu',
				'footer'  => 'Footer Menu',
			]
		);
	}

	/**
	 * The block fixture theme.
	 *
	 * @return void
	 */
	private function use_block_theme(): void {
		$this->switch_to( 'albert-block-test' );
	}

	/**
	 * A published navigation menu.
	 *
	 * @param string $title Title.
	 * @param string $date  Post date.
	 * @param string $status Post status.
	 *
	 * @return int
	 */
	private function navigation( string $title, string $date = '2026-01-01 10:00:00', string $status = 'publish' ): int {
		return (int) self::factory()->post->create(
			[
				'post_type'    => 'wp_navigation',
				'post_title'   => $title,
				'post_status'  => $status,
				'post_date'    => $date,
				'post_content' => '<!-- wp:navigation-link {"label":"About","url":"https://example.com/about","kind":"custom"} /-->'
					. '<!-- wp:navigation-submenu {"label":"Shop","url":"https://example.com/shop"} -->'
					. '<!-- wp:navigation-link {"label":"Shoes","url":"https://example.com/shoes"} /-->'
					. '<!-- /wp:navigation-submenu -->'
					. '<!-- wp:page-list /-->',
			]
		);
	}

	/**
	 * A classic menu.
	 *
	 * @param string $name Menu name.
	 *
	 * @return int
	 */
	private function classic_menu( string $name ): int {
		$id = wp_create_nav_menu( $name );
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * A custom link in a classic menu.
	 *
	 * @param int    $menu_id  Menu.
	 * @param string $title    Label.
	 * @param int    $parent   Parent item.
	 * @param int    $position Menu order.
	 *
	 * @return int
	 */
	private function link( int $menu_id, string $title, int $parent = 0, int $position = 0 ): int {
		return (int) wp_update_nav_menu_item(
			$menu_id,
			0,
			[
				'menu-item-title'     => $title,
				'menu-item-url'       => 'https://example.com/' . sanitize_title( $title ),
				'menu-item-type'      => 'custom',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent,
				'menu-item-position'  => $position,
			]
		);
	}

	/**
	 * Top-level titles, with children as "Parent > Child".
	 *
	 * @param list<array<string, mixed>> $items Tree.
	 * @param string                     $prefix Parent path.
	 *
	 * @return list<string>
	 */
	private function titles( array $items, string $prefix = '' ): array {
		$titles = [];
		foreach ( $items as $item ) {
			$titles[] = $prefix . $item['title'];
			$titles   = array_merge( $titles, $this->titles( $item['children'], $prefix . $item['title'] . ' > ' ) );
		}
		return $titles;
	}

	/**
	 * On a block theme a navigation block with no menu shows the most recently published one.
	 *
	 * @return void
	 */
	public function test_block_theme_header_falls_back_to_the_newest_published_navigation(): void {
		$this->use_block_theme();
		$older = $this->navigation( 'Older', '2026-01-01 10:00:00' );
		$newer = $this->navigation( 'Newer', '2026-02-01 10:00:00' );
		$this->navigation( 'Draft', '2026-03-01 10:00:00', 'draft' );

		$result = ( new FindMenus() )->execute( [] );

		$this->assertTrue( $result['theme']['block_theme'] );
		$this->assertSame( 'block', $result['header']['type'] );
		$this->assertSame( $newer, $result['header']['id'] );
		$this->assertTrue( $result['header']['fallback'] );
		$this->assertSame( 'header', $result['header']['source'] );

		$by_id = array_column( $result['navigation_menus'], null, 'id' );
		$this->assertTrue( $by_id[ $newer ]['visible'] );
		$this->assertFalse( $by_id[ $older ]['visible'] );
	}

	/**
	 * A navigation block that names a menu shows that one, wherever it sits in the part.
	 *
	 * @return void
	 */
	public function test_block_theme_header_shows_the_referenced_navigation(): void {
		$this->use_block_theme();
		$named = $this->navigation( 'Named', '2026-01-01 10:00:00' );
		$this->navigation( 'Newer', '2026-02-01 10:00:00' );

		// A header saved in the Site Editor, overriding the theme's file.
		$part = self::factory()->post->create(
			[
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header',
				'post_title'   => 'Header',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:navigation {"ref":' . $named . '} /--></div><!-- /wp:group -->',
			]
		);
		wp_set_post_terms( $part, 'albert-block-test', 'wp_theme' );
		wp_set_post_terms( $part, 'header', 'wp_template_part_area' );

		$result = ( new FindMenus() )->execute( [] );

		$this->assertSame( $named, $result['header']['id'] );
		$this->assertFalse( $result['header']['fallback'] );

		$used = array_column( $result['navigation_menus'], null, 'id' )[ $named ]['used_in'];
		$this->assertSame( 'header', $used[0]['slug'] );
		$this->assertTrue( $used[0]['in_use'] );
	}

	/**
	 * A part no template shows is not where visitors see a menu.
	 *
	 * @return void
	 */
	public function test_a_part_no_template_shows_is_not_in_use(): void {
		$this->use_block_theme();
		$nav = $this->navigation( 'Only' );

		$used = ( new FindMenus() )->execute( [] );
		$used = array_column( $used['navigation_menus'], null, 'id' )[ $nav ]['used_in'];

		$by_slug = array_column( $used, null, 'slug' );
		$this->assertTrue( $by_slug['header']['in_use'] );
		$this->assertFalse( $by_slug['unused']['in_use'] );
	}

	/**
	 * Classic menus are reported as hidden on a block theme, and a write says so.
	 *
	 * @return void
	 */
	public function test_classic_menu_on_a_block_theme_is_not_visible_and_writes_warn(): void {
		$this->use_block_theme();
		$this->navigation( 'Header nav' );
		$menu = $this->classic_menu( 'Old menu' );

		$result = ( new FindMenus() )->execute( [] );
		$this->assertFalse( $result['classic_menus'][0]['visible'] );
		$this->assertStringContainsString( 'classic menus are not shown', $result['summary'] );

		$added = ( new AddMenuItem() )->execute(
			[
				'menu_id' => $menu,
				'title'   => 'Contact',
				'url'     => 'https://example.com/contact',
			]
		);

		$this->assertIsArray( $added );
		$this->assertFalse( $added['visible'] );
		$this->assertStringContainsString( 'block theme', $added['notice'] );
	}

	/**
	 * With no navigation menu yet, core would copy the classic menu into one.
	 *
	 * @return void
	 */
	public function test_block_theme_without_navigation_reports_the_classic_copy(): void {
		$this->use_block_theme();
		$menu = $this->classic_menu( 'Legacy' );
		$this->link( $menu, 'Home' );

		$header = ( new FindMenus() )->execute( [] )['header'];

		$this->assertSame( 'classic_copy', $header['type'] );
		$this->assertSame( $menu, $header['id'] );
	}

	/**
	 * Core does not copy an empty classic menu; it lists the pages instead.
	 *
	 * @return void
	 */
	public function test_an_empty_classic_menu_is_not_copied(): void {
		$this->use_block_theme();
		$this->classic_menu( 'Empty' );

		$this->assertSame( 'page_list', ( new FindMenus() )->execute( [] )['header']['type'] );
	}

	/**
	 * With several header parts in use, the header is the one the front page shows.
	 *
	 * @return void
	 */
	public function test_the_front_page_header_wins_over_another_header_part(): void {
		$this->use_block_theme();
		$nav = $this->navigation( 'Main' );

		// A second header part, saved in the Site Editor and used only by the single template.
		$part = self::factory()->post->create(
			[
				'post_type'    => 'wp_template_part',
				'post_name'    => 'header-single',
				'post_title'   => 'Single header',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:navigation {"ref":' . $nav . '} /-->',
			]
		);
		wp_set_post_terms( $part, 'albert-block-test', 'wp_theme' );
		wp_set_post_terms( $part, 'header', 'wp_template_part_area' );

		$template = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'single',
				'post_title'   => 'Single',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:template-part {"slug":"header-single"} /-->',
			]
		);
		wp_set_post_terms( $template, 'albert-block-test', 'wp_theme' );

		$header = ( new FindMenus() )->execute( [] )['header'];

		$this->assertSame( 'header', $header['source'] );
		$this->assertTrue( $header['fallback'] );
	}

	/**
	 * A navigation block inside a draft synced pattern is not rendered, so it does not count.
	 *
	 * @return void
	 */
	public function test_a_draft_synced_pattern_is_not_followed(): void {
		$this->use_block_theme();
		$nav    = $this->navigation( 'Main' );
		$synced = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:navigation {"ref":' . $nav . '} /-->',
			]
		);

		$template = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'page',
				'post_title'   => 'Page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:block {"ref":' . $synced . '} /-->',
			]
		);
		wp_set_post_terms( $template, 'albert-block-test', 'wp_theme' );

		$used = array_column( ( new FindMenus() )->execute( [] )['navigation_menus'], null, 'id' )[ $nav ]['used_in'];

		$this->assertNotContains( 'page', array_column( $used, 'slug' ) );
	}

	/**
	 * A navigation menu's blocks read as a tree with paths.
	 *
	 * @return void
	 */
	public function test_get_menu_reads_a_navigation_menu_as_a_tree(): void {
		$this->use_block_theme();
		$nav = $this->navigation( 'Main' );

		$result = ( new GetMenu() )->execute( [ 'menu_id' => $nav ] );

		$this->assertSame( 'block', $result['type'] );
		$this->assertTrue( $result['visible'] );
		$this->assertSame( [ 'link', 'submenu', 'page-list' ], array_column( $result['items'], 'kind' ) );
		$this->assertSame( 'Shoes', $result['items'][1]['children'][0]['label'] );
		$this->assertSame( [ 1, 0 ], $result['items'][1]['children'][0]['path'] );
	}

	/**
	 * On a classic theme the menu in a location is visible; the header is the primary one.
	 *
	 * @return void
	 */
	public function test_classic_theme_visibility_follows_locations(): void {
		$this->use_classic_theme();
		$main  = $this->classic_menu( 'Main' );
		$spare = $this->classic_menu( 'Spare' );
		set_theme_mod( 'nav_menu_locations', [ 'primary' => $main ] );

		$result = ( new FindMenus() )->execute( [] );

		$this->assertFalse( $result['theme']['block_theme'] );
		$this->assertSame( 'classic', $result['header']['type'] );
		$this->assertSame( $main, $result['header']['id'] );

		$by_id = array_column( $result['classic_menus'], null, 'id' );
		$this->assertTrue( $by_id[ $main ]['visible'] );
		$this->assertSame( [ 'primary' ], $by_id[ $main ]['locations'] );
		$this->assertFalse( $by_id[ $spare ]['visible'] );

		$locations = array_column( $result['locations'], 'menu', 'slug' );
		$this->assertSame(
			[
				'id'   => $main,
				'name' => 'Main',
			],
			$locations['primary']
		);
		$this->assertNull( $locations['footer'] );

		$view = ( new GetMenu() )->execute(
			[
				'menu_id'   => $spare,
				'menu_type' => 'classic',
			]
		);
		$this->assertStringContainsString( 'not assigned to a menu location', $view['notice'] );
	}

	/**
	 * Create, assign, add, update, move, remove and delete through core's routes.
	 *
	 * @return void
	 */
	public function test_classic_crud(): void {
		$this->use_classic_theme();
		$page = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'About us',
			]
		);

		$menu = ( new CreateMenu() )->execute( [ 'name' => 'Header' ] );
		$this->assertIsArray( $menu );
		$this->assertFalse( $menu['visible'], 'A new menu is in no location.' );
		$menu_id = $menu['id'];

		$assigned = ( new AssignMenuLocation() )->execute(
			[
				'location' => 'primary',
				'menu_id'  => $menu_id,
			]
		);
		$this->assertTrue( $assigned['visible'] );

		$home  = ( new AddMenuItem() )->execute(
			[
				'menu_id' => $menu_id,
				'title'   => 'Home',
				'url'     => 'https://example.com/',
			]
		);
		$about = ( new AddMenuItem() )->execute(
			[
				'menu_id'   => $menu_id,
				'item_type' => 'post_type',
				'object_id' => $page,
			]
		);
		$this->assertIsArray( $about );
		$this->assertSame( 'About us', $about['title'] );
		$team = ( new AddMenuItem() )->execute(
			[
				'menu_id'        => $menu_id,
				'title'          => 'Team',
				'url'            => 'https://example.com/team',
				'parent_item_id' => $about['id'],
				'position'       => 1,
			]
		);
		$this->assertIsArray( $team );

		$view = ( new GetMenu() )->execute( [ 'menu_id' => $menu_id ] );
		$this->assertSame( [ 'Home', 'About us', 'About us > Team' ], $this->titles( $view['items'] ) );

		$renamed = ( new UpdateMenuItem() )->execute(
			[
				'menu_item_id' => $about['id'],
				'title'        => 'Who we are',
			]
		);
		$this->assertSame( 'Who we are', $renamed['title'] );
		$restored = ( new UpdateMenuItem() )->execute(
			[
				'menu_item_id'       => $about['id'],
				'use_original_title' => true,
			]
		);
		$this->assertSame( 'About us', $restored['title'], 'The linked page\'s title is back.' );

		$updated = ( new UpdateMenuItem() )->execute(
			[
				'menu_item_id'    => $home['id'],
				'title'           => 'Start',
				'open_in_new_tab' => true,
			]
		);
		$this->assertSame( 'Start', $updated['title'] );
		$this->assertTrue( $updated['open_in_new_tab'] );

		$moved = ( new MoveMenuItem() )->execute(
			[
				'menu_item_id' => $home['id'],
				'position'     => 2,
			]
		);
		$this->assertSame( [ 'About us', 'About us > Team', 'Start' ], $this->titles( $moved['items'] ) );

		$removed = ( new RemoveMenuItem() )->execute( [ 'menu_item_id' => $about['id'] ] );
		$this->assertSame( [ $team['id'] ], $removed['moved_up'] );
		$view = ( new GetMenu() )->execute( [ 'menu_id' => $menu_id ] );
		$this->assertSame( [ 'Team', 'Start' ], $this->titles( $view['items'] ) );
		$this->assertNotNull( get_post( $page ), 'The linked page is untouched.' );

		$deleted = ( new DeleteMenu() )->execute( [ 'menu_id' => $menu_id ] );
		$this->assertTrue( $deleted['deleted'] );
		$this->assertTrue( $deleted['was_visible'] );
		$this->assertFalse( wp_get_nav_menu_object( $menu_id ) );
	}

	/**
	 * An item cannot move under itself or its own child.
	 *
	 * @return void
	 */
	public function test_move_refuses_a_loop(): void {
		$this->use_classic_theme();
		$menu   = $this->classic_menu( 'Loop' );
		$parent = $this->link( $menu, 'Parent', 0, 1 );
		$child  = $this->link( $menu, 'Child', $parent, 2 );

		$result = ( new MoveMenuItem() )->execute(
			[
				'menu_item_id'   => $parent,
				'parent_item_id' => $child,
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_parent_item_id', $result->get_error_code() );
	}

	/**
	 * Assigning a location replaces the menu there; unassigning empties it, and is idempotent.
	 *
	 * @return void
	 */
	public function test_assign_menu_location(): void {
		$this->use_classic_theme();
		$first  = $this->classic_menu( 'First' );
		$second = $this->classic_menu( 'Second' );
		set_theme_mod(
			'nav_menu_locations',
			[
				'primary' => $first,
				'footer'  => $first,
			]
		);

		$result = ( new AssignMenuLocation() )->execute(
			[
				'location' => 'primary',
				'menu_id'  => $second,
			]
		);
		$this->assertSame(
			[
				'id'   => $first,
				'name' => 'First',
			],
			$result['previous_menu']
		);
		$this->assertSame( $second, $result['menu']['id'] );
		$this->assertTrue( $result['visible'] );
		$this->assertSame( $second, get_nav_menu_locations()['primary'] );
		$this->assertSame( $first, get_nav_menu_locations()['footer'], 'Other locations keep their menu.' );

		$emptied = ( new UnassignMenuLocation() )->execute( [ 'location' => 'footer' ] );
		$this->assertSame( $first, $emptied['previous_menu']['id'] );
		$this->assertArrayNotHasKey( 'footer', array_filter( get_nav_menu_locations() ) );
		$this->assertSame( $second, get_nav_menu_locations()['primary'], 'Other locations keep their menu.' );

		$again = ( new UnassignMenuLocation() )->execute( [ 'location' => 'footer' ] );
		$this->assertNull( $again['previous_menu'], 'Emptying an empty location succeeds.' );

		$missing = ( new AssignMenuLocation() )->execute(
			[
				'location' => 'sidebar',
				'menu_id'  => $first,
			]
		);
		$this->assertSame( 'albert_menu_location_not_found', $missing->get_error_code() );
		$this->assertSame( 'albert_menu_location_not_found', ( new UnassignMenuLocation() )->execute( [ 'location' => 'sidebar' ] )->get_error_code() );
	}

	/**
	 * A block theme has no locations to assign, and says where to look instead.
	 *
	 * @return void
	 */
	public function test_assign_location_on_a_block_theme_is_refused(): void {
		$this->use_block_theme();
		$menu = $this->classic_menu( 'Old' );

		$result = ( new AssignMenuLocation() )->execute(
			[
				'location' => 'primary',
				'menu_id'  => $menu,
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'no menu locations', $result->get_error_message() );
	}

	/**
	 * Unknown ids answer with a not-found code.
	 *
	 * @return void
	 */
	public function test_missing_menus_and_items_are_not_found(): void {
		$this->assertSame( 'albert_menu_not_found', ( new GetMenu() )->execute( [ 'menu_id' => 999999 ] )->get_error_code() );
		$this->assertSame( 'albert_menu_item_not_found', ( new RemoveMenuItem() )->execute( [ 'menu_item_id' => 999999 ] )->get_error_code() );
	}

	/**
	 * Every menu ability needs edit_theme_options.
	 *
	 * @return void
	 */
	public function test_editors_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		foreach ( [ new FindMenus(), new GetMenu(), new CreateMenu(), new AddMenuItem(), new RemoveMenuItem(), new DeleteMenu(), new UnassignMenuLocation() ] as $ability ) {
			$this->assertInstanceOf( WP_Error::class, $ability->check_permission(), $ability->get_id() );
		}
	}

	/**
	 * Reads and ordinary writes pass safe mode; removing an item and deleting a menu are held.
	 *
	 * @return void
	 */
	public function test_safe_mode_holds_removals(): void {
		$gate = new Gate();

		$expected = [
			'albert/find-menus'             => false,
			'albert/get-menu'               => false,
			'albert/create-menu'            => false,
			'albert/add-menu-item'          => false,
			'albert/update-menu-item'       => false,
			'albert/move-menu-item'         => false,
			'albert/assign-menu-location'   => false,
			'albert/unassign-menu-location' => false,
			'albert/remove-menu-item'       => true,
			'albert/delete-menu'            => true,
		];

		foreach ( $expected as $id => $held ) {
			$ability = wp_get_ability( $id );
			$this->assertNotNull( $ability, $id . ' is registered' );
			$this->assertSame( $held, $gate->must_gate( $ability, [ 'id' => 1 ] ), $id );
		}
	}

	/**
	 * Two menus sharing an ID need a menu_type.
	 *
	 * @return void
	 */
	public function test_get_menu_asks_for_the_type_when_ids_collide(): void {
		$this->use_block_theme();
		$classic = $this->classic_menu( 'Old' );
		$nav     = (int) self::factory()->post->create(
			[
				'import_id'   => $classic,
				'post_type'   => 'wp_navigation',
				'post_title'  => 'Main',
				'post_status' => 'publish',
			]
		);
		if ( $nav !== $classic ) {
			$this->markTestSkipped( 'A post already has the classic menu\'s ID.' );
		}

		$result = ( new GetMenu() )->execute( [ 'menu_id' => $nav ] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_missing_menu_type', $result->get_error_code() );
		$this->assertSame(
			'block',
			( new GetMenu() )->execute(
				[
					'menu_id'   => $nav,
					'menu_type' => 'block',
				]
			)['type']
		);
	}

	/**
	 * A new item that cannot be placed is removed again, so a retry does not add it twice.
	 *
	 * @return void
	 */
	public function test_add_item_rolls_back_when_placing_fails(): void {
		$this->use_classic_theme();
		$menu = $this->classic_menu( 'Rollback' );
		$this->link( $menu, 'First', 0, 1 );

		$refuse = static function ( $result, $server, $request ) {
			if ( $request->get_method() === 'POST' && preg_match( '#^/wp/v2/menu-items/\d+$#', $request->get_route() ) ) {
				return new WP_Error( 'test_refused', 'Refused.', [ 'status' => 500 ] );
			}
			return $result;
		};
		add_filter( 'rest_pre_dispatch', $refuse, 10, 3 );

		$result = ( new AddMenuItem() )->execute(
			[
				'menu_id'  => $menu,
				'title'    => 'Second',
				'url'      => 'https://example.com/second',
				'position' => 1,
			]
		);

		remove_filter( 'rest_pre_dispatch', $refuse, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [ 'First' ], $this->titles( ( new GetMenu() )->execute( [ 'menu_id' => $menu ] )['items'] ) );
	}

	/**
	 * The schemas refuse calls that would do nothing or need a field for their type.
	 *
	 * @return void
	 */
	public function test_schemas_require_useful_input(): void {
		$schema = static fn( string $id ): array => wp_get_ability( $id )->get_input_schema();
		$valid  = static fn( string $id, array $input ): bool => rest_validate_value_from_schema( $input, $schema( $id ), 'input' ) === true;

		$this->assertTrue(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id' => 1,
					'title'   => 'A',
					'url'     => 'https://example.com',
				]
			)
		);
		$this->assertTrue(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id'   => 1,
					'item_type' => 'post_type',
					'object_id' => 2,
				]
			)
		);
		$this->assertTrue(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id'     => 1,
					'item_type'   => 'post_type_archive',
					'object_type' => 'post',
				]
			)
		);
		$this->assertFalse(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id' => 1,
					'title'   => 'A',
				]
			),
			'A custom link needs a url.'
		);
		$this->assertFalse(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id'   => 1,
					'item_type' => 'custom',
					'url'       => 'https://example.com',
				]
			),
			'A custom link needs a title.'
		);
		$this->assertFalse(
			$valid(
				'albert/add-menu-item',
				[
					'menu_id'   => 1,
					'item_type' => 'post_type',
				]
			),
			'A page link needs object_id.'
		);

		$this->assertFalse( $valid( 'albert/move-menu-item', [ 'menu_item_id' => 1 ] ) );
		$this->assertTrue(
			$valid(
				'albert/move-menu-item',
				[
					'menu_item_id'   => 1,
					'parent_item_id' => 0,
				]
			)
		);
		$this->assertTrue(
			$valid(
				'albert/move-menu-item',
				[
					'menu_item_id' => 1,
					'position'     => 2,
				]
			)
		);

		$this->assertFalse( $valid( 'albert/update-menu-item', [ 'menu_item_id' => 1 ] ) );
		$this->assertFalse(
			$valid(
				'albert/update-menu-item',
				[
					'menu_item_id' => 1,
					'title'        => '',
				]
			),
			'An empty title is not a reset.'
		);
		$this->assertFalse(
			$valid(
				'albert/update-menu-item',
				[
					'menu_item_id'       => 1,
					'use_original_title' => false,
				]
			)
		);
		$this->assertTrue(
			$valid(
				'albert/update-menu-item',
				[
					'menu_item_id'       => 1,
					'use_original_title' => true,
				]
			)
		);

		$this->assertFalse(
			$valid(
				'albert/assign-menu-location',
				[
					'location' => 'primary',
					'menu_id'  => 0,
				]
			),
			'0 does not empty a location.'
		);
	}
}
