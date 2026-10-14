<?php
/**
 * Integration tests for the MCP Adapter plugin nudge.
 *
 * The test site has no MCP Adapter plugin installed, so every surface is live.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\AdapterNudge;
use Albert\MCP\AdapterPlugin;
use Albert\MCP\AdapterSource;
use Albert\MCP\DiscoveryContext;
use Albert\MCP\Server;
use Albert\Tests\TestCase;
use WP\MCP\Core\McpServer;

/**
 * AdapterNudge tests.
 *
 * @covers \Albert\Admin\AdapterNudge
 * @covers \Albert\MCP\AdapterPlugin
 */
class AdapterNudgeTest extends TestCase {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Marks the stand-in plugin file, so a run killed mid-test cannot leave it behind for good.
	 *
	 * @var string
	 */
	private const STAND_IN = 'Albert test stand-in';

	/**
	 * Whether this test put a stand-in plugin file in place.
	 *
	 * @var bool
	 */
	private bool $placed_plugin = false;

	/**
	 * Create an administrator and make sure the adapter plugin is absent.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$file = WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE;
		if ( is_readable( $file ) && str_contains( (string) file_get_contents( $file ), self::STAND_IN ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
			$this->placed_plugin = true;
			$this->remove_plugin();
		}

		if ( ! AdapterPlugin::needs_attention() ) {
			$this->markTestSkipped( 'The MCP Adapter plugin is active in this test environment.' );
		}
	}

	/**
	 * Restore the screen.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );

		$this->remove_plugin();

		parent::tear_down();
	}

	/**
	 * Remove the stand-in plugin file, if this test placed one.
	 *
	 * @return void
	 */
	private function remove_plugin(): void {
		if ( ! $this->placed_plugin ) {
			return;
		}

		wp_delete_file( WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE );
		rmdir( dirname( WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE ) );
		$this->placed_plugin = false;
	}

	/**
	 * Put a stand-in for the plugin's main file in place, installed but inactive.
	 *
	 * @return void
	 */
	private function place_plugin(): void {
		$file = WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE;

		if ( file_exists( $file ) ) {
			$this->markTestSkipped( 'The MCP Adapter plugin is installed in this test environment.' );
		}

		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, "<?php\n/*\nPlugin Name: MCP Adapter\nDescription: " . self::STAND_IN . "\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->placed_plugin = true;
	}

	/**
	 * An administrator gets a Dashboard item with the one-click link.
	 *
	 * @return void
	 */
	public function test_attention_item_offers_the_install_to_an_administrator(): void {
		wp_set_current_user( $this->admin_id );

		$items = array_column( ( new AdapterNudge() )->add_attention_item( [] ), null, 'id' );
		$item  = $items['mcp-adapter-plugin'];

		$this->assertSame( 'warning', $item['tone'] );
		$this->assertFalse( $item['dismissible'], 'It clears itself once the plugin is active.' );
		$this->assertSame( 'Install and activate MCP Adapter', $item['action']['label'] );
		$this->assertStringContainsString( 'update.php?action=install-plugin&plugin=mcp-adapter', html_entity_decode( $item['action']['url'] ) );
		$this->assertSame( [ 'data-albert-adapter-install' => 'install' ], $item['action']['attributes'] );
	}

	/**
	 * Someone who cannot install plugins is told who can, with no button.
	 *
	 * @return void
	 */
	public function test_attention_item_without_install_rights_has_no_button(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$item = ( new AdapterNudge() )->add_attention_item( [] )[0];

		$this->assertNull( $item['action'] );
		$this->assertStringContainsString( 'Only a site administrator can install it, so please let them know.', $item['detail'] );
	}

	/**
	 * The notice shows on Albert's screens but not on the Dashboard, which has its card.
	 *
	 * @return void
	 */
	public function test_screen_notice_skips_the_dashboard(): void {
		wp_set_current_user( $this->admin_id );
		$nudge = new AdapterNudge();

		set_current_screen( 'albert_page_albert-settings' );
		$on_settings = get_echo( [ $nudge, 'render_screen_notice' ] );

		set_current_screen( 'toplevel_page_albert' );
		$on_dashboard = get_echo( [ $nudge, 'render_screen_notice' ] );

		set_current_screen( 'edit-post' );
		$elsewhere = get_echo( [ $nudge, 'render_screen_notice' ] );

		$this->assertStringContainsString( 'Install the MCP Adapter plugin', $on_settings );
		$this->assertStringContainsString( 'data-albert-adapter-install="install"', $on_settings );
		$this->assertSame( '', $on_dashboard );
		$this->assertSame( '', $elsewhere );
	}

	/**
	 * Someone who cannot install it still sees the notice, telling them who can.
	 *
	 * @return void
	 */
	public function test_screen_notice_tells_others_who_can_install(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		set_current_screen( 'albert_page_albert-approvals' );

		$notice = get_echo( [ new AdapterNudge(), 'render_screen_notice' ] );

		$this->assertStringContainsString( 'Only a site administrator can install it, so please let them know.', $notice );
		$this->assertStringNotContainsString( 'data-albert-adapter-install', $notice );
	}

	/**
	 * With plugin installs switched off, an administrator is told to deploy it.
	 *
	 * @return void
	 */
	public function test_file_mods_off_tells_the_admin_to_deploy_it(): void {
		wp_set_current_user( $this->admin_id );
		add_filter( 'file_mod_allowed', '__return_false' );

		$item = ( new AdapterNudge() )->add_attention_item( [] )[0];
		set_current_screen( 'albert_page_albert-settings' );
		$notice = get_echo( [ new AdapterNudge(), 'render_screen_notice' ] );

		remove_filter( 'file_mod_allowed', '__return_false' );

		$this->assertNull( $item['action'] );
		$this->assertStringContainsString( 'Plugin installs are switched off on this site', $item['detail'] );
		$this->assertStringContainsString( 'a site administrator needs to look at it', $notice );
		$this->assertStringNotContainsString( 'data-albert-adapter-install', $notice );
	}

	/**
	 * Installed but inactive, the button activates, even with installs switched off.
	 *
	 * Activating is a database setting, which `DISALLOW_FILE_MODS` leaves alone.
	 *
	 * @return void
	 */
	public function test_installed_plugin_is_activated_even_with_file_mods_off(): void {
		$this->place_plugin();
		wp_set_current_user( $this->admin_id );
		add_filter( 'file_mod_allowed', '__return_false' );

		$item = ( new AdapterNudge() )->add_attention_item( [] )[0];

		remove_filter( 'file_mod_allowed', '__return_false' );

		$this->assertSame( 'Activate MCP Adapter', $item['action']['label'] );
		$this->assertSame( [ 'data-albert-adapter-install' => 'activate' ], $item['action']['attributes'] );
		$this->assertStringContainsString( 'plugins.php?action=activate&plugin=' . rawurlencode( AdapterSource::PLUGIN_FILE ), html_entity_decode( $item['action']['url'] ) );
		$this->assertStringContainsString( 'It is installed on this site but not active.', $item['detail'] );
	}

	/**
	 * Installing alone is not enough: the button activates too.
	 *
	 * @return void
	 */
	public function test_install_without_activate_rights_gets_no_button(): void {
		$user = new \WP_User( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$user->add_cap( 'install_plugins' );
		wp_set_current_user( $user->ID );

		$this->assertSame( AdapterPlugin::ROUTE_ASK, AdapterPlugin::route() );
	}

	/**
	 * The confirmation after a one-click install needs the plugin to really be active.
	 *
	 * @return void
	 */
	public function test_confirmation_needs_the_plugin_active(): void {
		wp_set_current_user( $this->admin_id );
		$_GET[ AdapterNudge::DONE_ARG ] = '1';

		$output = get_echo( [ new AdapterNudge(), 'render_done_notice' ] );

		unset( $_GET[ AdapterNudge::DONE_ARG ] );

		$this->assertSame( '', $output, 'The plugin is not active here, so the argument alone must not confirm anything.' );
	}

	/**
	 * Once the plugin is active, the reload confirms it, and core strips the argument.
	 *
	 * @return void
	 */
	public function test_confirmation_shows_once_the_plugin_is_active(): void {
		$this->place_plugin();
		update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', [] ), [ AdapterSource::PLUGIN_FILE ] ) );
		wp_set_current_user( $this->admin_id );
		$_GET[ AdapterNudge::DONE_ARG ] = '1';

		$output = get_echo( [ new AdapterNudge(), 'render_done_notice' ] );

		unset( $_GET[ AdapterNudge::DONE_ARG ] );

		$this->assertStringContainsString( 'The MCP Adapter plugin is installed and active.', $output );
		$this->assertContains( AdapterNudge::DONE_ARG, ( new AdapterNudge() )->removable_query_args( [] ) );
	}

	/**
	 * The Plugins screen row carries the same message, spanning the table.
	 *
	 * @return void
	 */
	public function test_plugin_row_spans_the_table(): void {
		wp_set_current_user( $this->admin_id );
		unset( $GLOBALS['wp_list_table'] );

		$row = get_echo( [ new AdapterNudge(), 'render_plugin_row' ] );

		$this->assertStringContainsString( '<tr class="plugin-update-tr active albert-adapter-row">', $row );
		$this->assertStringContainsString( 'colspan="3"', $row );
		$this->assertStringContainsString( 'Install and activate MCP Adapter', $row );
	}

	/**
	 * Site Health recommends the plugin, with the fix as its action.
	 *
	 * @return void
	 */
	public function test_site_health_recommends_the_plugin(): void {
		wp_set_current_user( $this->admin_id );

		$result = ( new AdapterNudge() )->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Install the MCP Adapter plugin', $result['label'] );
		$this->assertStringContainsString( 'install-plugin', $result['actions'] );
	}

	/**
	 * An administrator's assistant is told; anyone else's is not.
	 *
	 * @return void
	 */
	public function test_discovery_tells_only_someone_who_can_act(): void {
		$context = new DiscoveryContext();
		$server  = $this->createMock( McpServer::class );
		$server->method( 'get_server_id' )->willReturn( Server::SERVER_ID );

		wp_set_current_user( $this->admin_id );
		$for_admin = $context->add_context( [ 'abilities' => [] ], [], DiscoveryContext::DISCOVERY_ABILITY, null, $server );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$for_editor = $context->add_context( [ 'abilities' => [] ], [], DiscoveryContext::DISCOVERY_ABILITY, null, $server );

		$this->assertStringContainsString( 'Install and activate MCP Adapter', $for_admin['notice'] );
		$this->assertArrayNotHasKey( 'notice', $for_editor );
	}

	/**
	 * The assistant notice can be switched off.
	 *
	 * @return void
	 */
	public function test_discovery_notice_can_be_filtered_away(): void {
		$server = $this->createMock( McpServer::class );
		$server->method( 'get_server_id' )->willReturn( Server::SERVER_ID );
		wp_set_current_user( $this->admin_id );
		add_filter( 'albert/mcp/adapter_notice', '__return_empty_string' );

		$result = ( new DiscoveryContext() )->add_context( [ 'abilities' => [] ], [], DiscoveryContext::DISCOVERY_ABILITY, null, $server );

		remove_filter( 'albert/mcp/adapter_notice', '__return_empty_string' );

		$this->assertArrayNotHasKey( 'notice', $result );
	}
}
