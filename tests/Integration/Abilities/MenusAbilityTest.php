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
use Albert\Abilities\WordPress\Menus\MoveMenuItem;
use Albert\Abilities\WordPress\Menus\RemoveMenuItem;
use Albert\Abilities\WordPress\Menus\UpdateMenuItem;
use Albert\Abilities\WordPress\Menus\ViewMenu;
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

		$header = ( new FindMenus() )->execute( [] )['header'];

		$this->assertSame( 'classic_copy', $header['type'] );
		$this->assertSame( $menu, $header['id'] );
	}

	/**
	 * A navigation menu's blocks read as a tree with paths.
	 *
	 * @return void
	 */
	public function test_view_menu_reads_a_navigation_menu_as_a_tree(): void {
		$this->use_block_theme();
		$nav = $this->navigation( 'Main' );

		$result = ( new ViewMenu() )->execute( [ 'id' => $nav ] );

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

		$view = ( new ViewMenu() )->execute(
			[
				'id'   => $spare,
				'type' => 'classic',
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

		$menu = ( new CreateMenu() )->execute(
			[
				'name'      => 'Header',
				'locations' => [ 'primary' ],
			]
		);
		$this->assertIsArray( $menu );
		$this->assertTrue( $menu['visible'] );
		$menu_id = $menu['id'];

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
				'type'      => 'post_type',
				'object_id' => $page,
			]
		);
		$this->assertIsArray( $about );
		$this->assertSame( 'About us', $about['title'] );
		$team = ( new AddMenuItem() )->execute(
			[
				'menu_id'  => $menu_id,
				'title'    => 'Team',
				'url'      => 'https://example.com/team',
				'parent'   => $about['id'],
				'position' => 1,
			]
		);
		$this->assertIsArray( $team );

		$view = ( new ViewMenu() )->execute( [ 'id' => $menu_id ] );
		$this->assertSame( [ 'Home', 'About us', 'About us > Team' ], $this->titles( $view['items'] ) );

		$updated = ( new UpdateMenuItem() )->execute(
			[
				'id'              => $home['id'],
				'title'           => 'Start',
				'open_in_new_tab' => true,
			]
		);
		$this->assertSame( 'Start', $updated['title'] );
		$this->assertTrue( $updated['open_in_new_tab'] );

		$moved = ( new MoveMenuItem() )->execute(
			[
				'id'       => $home['id'],
				'position' => 2,
			]
		);
		$this->assertSame( [ 'About us', 'About us > Team', 'Start' ], $this->titles( $moved['items'] ) );

		$removed = ( new RemoveMenuItem() )->execute( [ 'id' => $about['id'] ] );
		$this->assertSame( [ $team['id'] ], $removed['moved_up'] );
		$view = ( new ViewMenu() )->execute( [ 'id' => $menu_id ] );
		$this->assertSame( [ 'Team', 'Start' ], $this->titles( $view['items'] ) );
		$this->assertNotNull( get_post( $page ), 'The linked page is untouched.' );

		$deleted = ( new DeleteMenu() )->execute( [ 'id' => $menu_id ] );
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
				'id'     => $parent,
				'parent' => $child,
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'menu_item_parent_invalid', $result->get_error_code() );
	}

	/**
	 * Assigning a location replaces the menu there; menu_id 0 empties it.
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
		$this->assertSame( $first, $result['previous_menu_id'] );
		$this->assertTrue( $result['visible'] );
		$this->assertSame( $second, get_nav_menu_locations()['primary'] );
		$this->assertSame( $first, get_nav_menu_locations()['footer'], 'Other locations keep their menu.' );

		( new AssignMenuLocation() )->execute(
			[
				'location' => 'footer',
				'menu_id'  => 0,
			]
		);
		$this->assertArrayNotHasKey( 'footer', array_filter( get_nav_menu_locations() ) );

		$missing = ( new AssignMenuLocation() )->execute(
			[
				'location' => 'sidebar',
				'menu_id'  => $first,
			]
		);
		$this->assertSame( 'menu_location_not_found', $missing->get_error_code() );
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
		$this->assertSame( 'menu_not_found', ( new ViewMenu() )->execute( [ 'id' => 999999 ] )->get_error_code() );
		$this->assertSame( 'menu_item_not_found', ( new RemoveMenuItem() )->execute( [ 'id' => 999999 ] )->get_error_code() );
	}

	/**
	 * Every menu ability needs edit_theme_options.
	 *
	 * @return void
	 */
	public function test_editors_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		foreach ( [ new FindMenus(), new ViewMenu(), new CreateMenu(), new AddMenuItem(), new RemoveMenuItem(), new DeleteMenu() ] as $ability ) {
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
			'albert/find-menus'           => false,
			'albert/view-menu'            => false,
			'albert/create-menu'          => false,
			'albert/add-menu-item'        => false,
			'albert/update-menu-item'     => false,
			'albert/move-menu-item'       => false,
			'albert/assign-menu-location' => false,
			'albert/remove-menu-item'     => true,
			'albert/delete-menu'          => true,
		];

		foreach ( $expected as $id => $held ) {
			$ability = wp_get_ability( $id );
			$this->assertNotNull( $ability, $id . ' is registered' );
			$this->assertSame( $held, $gate->must_gate( $ability, [ 'id' => 1 ] ), $id );
		}
	}
}
