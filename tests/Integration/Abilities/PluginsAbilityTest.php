<?php
/**
 * Integration tests for the find-plugins ability.
 *
 * The plugin list and the update transient are seeded, so the results do not
 * depend on what the test install has on disk.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Plugins\FindPlugins;
use Albert\MCP\AdapterSource;
use Albert\OAuth\Server\ConnectionContext;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Find Plugins ability tests.
 */
class PluginsAbilityTest extends TestCase {

	/**
	 * Run as an administrator with a known plugin list.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		$header = static fn( string $name, string $version, string $author ): array => [
			'Name'    => $name,
			'Version' => $version,
			'Author'  => $author,
		];

		// get_plugins() reads this cache before it reads the disk.
		wp_cache_set(
			'plugins',
			[
				'' => [
					ALBERT_PLUGIN_BASENAME            => $header( 'Albert', ALBERT_VERSION, 'Mark Jansen' ),
					AdapterSource::PLUGIN_FILE        => $header( 'MCP Adapter', '0.5.0', 'WordPress.org' ),
					'akismet/akismet.php'             => $header( 'Akismet', '5.3', 'Automattic' ),
					'hello.php'                       => $header( 'Hello Dolly', '1.7.2', 'Matt Mullenweg' ),
					'fresh-install/fresh-install.php' => $header( 'Fresh Install', '1.0.0', 'Jane Doe' ),
				],
			],
			'plugins'
		);

		update_option( 'active_plugins', [ ALBERT_PLUGIN_BASENAME, AdapterSource::PLUGIN_FILE, 'akismet/akismet.php' ] );

		set_site_transient(
			'update_plugins',
			(object) [
				'last_checked' => 1764000000,
				'checked'      => [
					ALBERT_PLUGIN_BASENAME     => ALBERT_VERSION,
					AdapterSource::PLUGIN_FILE => '0.5.0',
					'akismet/akismet.php'      => '5.3',
					'hello.php'                => '1.7.2',
				],
				'response'     => [
					'akismet/akismet.php' => (object) [
						'slug'        => 'akismet',
						'new_version' => '5.4',
					],
				],
			]
		);
	}

	/**
	 * Drop the seeded list and the connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_cache_delete( 'plugins', 'plugins' );
		delete_site_transient( 'update_plugins' );
		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * Each plugin with its version, activity, update and role in the connection.
	 *
	 * @return void
	 */
	public function test_lists_the_installed_plugins(): void {
		$result = wp_get_ability( 'albert/find-plugins' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['total'] );
		$this->assertSame( '2025-11-24T16:00:00Z', $result['updates_checked_at'] );

		$rows = array_column( $result['plugins'], null, 'plugin' );

		$this->assertTrue( $rows[ ALBERT_PLUGIN_BASENAME ]['is_albert'] );
		$this->assertFalse( $rows[ ALBERT_PLUGIN_BASENAME ]['is_mcp_adapter'] );
		$this->assertTrue( $rows[ AdapterSource::PLUGIN_FILE ]['is_mcp_adapter'] );

		$this->assertSame( 'akismet', $rows['akismet/akismet.php']['slug'] );
		$this->assertTrue( $rows['akismet/akismet.php']['active'] );
		$this->assertTrue( $rows['akismet/akismet.php']['update_available'] );
		$this->assertSame( '5.4', $rows['akismet/akismet.php']['new_version'] );

		$this->assertSame( 'hello', $rows['hello.php']['slug'] );
		$this->assertFalse( $rows['hello.php']['active'] );
		$this->assertFalse( $rows['hello.php']['update_available'] );
		$this->assertArrayNotHasKey( 'new_version', $rows['hello.php'] );

		$this->assertNull( $rows['fresh-install/fresh-install.php']['update_available'], 'A plugin WordPress never checked has no known update.' );
	}

	/**
	 * The status filter.
	 *
	 * @return void
	 */
	public function test_filters_by_status(): void {
		$ability = wp_get_ability( 'albert/find-plugins' );

		$updates  = $ability->execute( [ 'status' => 'update_available' ] );
		$inactive = $ability->execute( [ 'status' => 'inactive' ] );

		$this->assertSame( [ 'akismet/akismet.php' ], array_column( $updates['plugins'], 'plugin' ) );
		$this->assertSame( [ 'hello.php', 'fresh-install/fresh-install.php' ], array_column( $inactive['plugins'], 'plugin' ) );
	}

	/**
	 * Without an update check, updates are unknown rather than absent.
	 *
	 * @return void
	 */
	public function test_without_an_update_check_updates_are_unknown(): void {
		delete_site_transient( 'update_plugins' );
		add_filter( 'pre_http_request', [ $this, 'fail_on_http' ] );

		$result = wp_get_ability( 'albert/find-plugins' )->execute();

		remove_filter( 'pre_http_request', [ $this, 'fail_on_http' ] );

		$this->assertNull( $result['updates_checked_at'] );
		$this->assertSame( [ null ], array_values( array_unique( array_column( $result['plugins'], 'update_available' ) ) ) );
	}

	/**
	 * Privacy mode leaves plugin rows alone on a connected call: an author is not a person's record.
	 *
	 * @return void
	 */
	public function test_a_connected_call_on_balanced_keeps_the_rows(): void {
		update_option( 'albert_privacy_mode', 'balanced' );
		ConnectionContext::set( 'client-1' );

		$result = wp_get_ability( 'albert/find-plugins' )->execute();
		$rows   = array_column( $result['plugins'], null, 'plugin' );

		$this->assertSame( 'Jane Doe', $rows['fresh-install/fresh-install.php']['author'] );
		$this->assertSame( 'Fresh Install', $rows['fresh-install/fresh-install.php']['name'] );
		$this->assertSame( 'fresh-install', $rows['fresh-install/fresh-install.php']['slug'] );
	}

	/**
	 * Only users who may manage plugins can list them.
	 *
	 * @return void
	 */
	public function test_editors_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = ( new FindPlugins() )->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_permission_denied', $result->get_error_code() );
	}

	/**
	 * A read, so safe mode never holds it.
	 *
	 * @return void
	 */
	public function test_safe_mode_passes_it(): void {
		$this->assertFalse( ( new Gate() )->must_gate( wp_get_ability( 'albert/find-plugins' ), [] ) );
	}

	/**
	 * Fail the test on any outgoing request.
	 *
	 * @return false
	 */
	public function fail_on_http(): bool {
		$this->fail( 'find-plugins must not make a network request.' );
	}
}
