<?php
/**
 * Integration tests for the change journal's recording.
 *
 * Every call goes through the real MCP adapter's `tools/call` handler, and the
 * ability doing the writing is a throwaway one Albert's code never mentions, so
 * "covered out of the box" is proven rather than asserted.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Undo;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Logging\Repository as LogRepository;
use Albert\MCP\Server;
use Albert\OAuth\Server\ConnectionContext;
use Albert\SafeMode\Approver;
use Albert\SafeMode\Repository as SafeModeRepository;
use Albert\Tests\TestCase;
use Albert\Undo\Entity;
use Albert\Undo\Handlers\Handler;
use Albert\Undo\Handlers\Registry;
use Albert\Undo\Journal;
use Albert\Undo\Recorder;
use Albert\Undo\Storage\Repository;
use Albert\Undo\Window;
use Albert\Undo\WindowContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;

/**
 * Change journal recording tests.
 *
 * @covers \Albert\Undo\Journal
 * @covers \Albert\Undo\Recorder
 * @covers \Albert\Undo\Coverage\QueryMonitor
 * @covers \Albert\Undo\Capture\CoreHooks
 */
class ChangeJournalTest extends TestCase {

	/**
	 * The throwaway ability's id: a third-party namespace, on purpose.
	 *
	 * @var string
	 */
	private const ABILITY = 'acme/work';

	/**
	 * Category the test ability is registered under.
	 *
	 * @var string
	 */
	private const CATEGORY = 'albert-test';

	/**
	 * What the ability does when called.
	 *
	 * @var callable
	 */
	private $work;

	/**
	 * Journal storage.
	 *
	 * @var Repository
	 */
	private Repository $journal;

	/**
	 * The administrator the calls run as.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Fresh tables, a connection, and the throwaway ability.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		Installer::install();

		global $wpdb;
		foreach ( [ Tables::changes(), Tables::change_items() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		}

		$this->journal = new Repository();
		$this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->user_id );
		ConnectionContext::set( 'client-acme' );

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		// Safe mode would hold an unannotated call; this suite is about recording.
		add_filter( 'albert/safe_mode/exempt_abilities', static fn( $ids ) => array_merge( (array) $ids, [ self::ABILITY ] ) );

		if ( ! has_filter( 'mcp_adapter_pre_tool_call' ) ) {
			( new Journal() )->register_hooks();
		}

		$this->work = static fn() => null;
		$this->register_ability();
	}

	/**
	 * Close anything left open and forget the connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Window::close();
		ConnectionContext::reset();

		if ( function_exists( 'wp_unregister_ability' ) && wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		parent::tear_down();
	}

	/**
	 * An option, a menu, a term assignment and post meta, written by an ability
	 * Albert has never heard of, are all captured and the entry can be reverted.
	 *
	 * @return void
	 */
	public function test_a_third_party_ability_is_recorded_completely(): void {
		update_option( 'acme_banner', 'old' );
		$post_id  = self::factory()->post->create();
		$category = self::factory()->category->create();
		update_post_meta( $post_id, 'acme_color', 'blue' );

		$menu_id = 0;
		$item_id = 0;

		$this->work = static function () use ( $post_id, $category, &$menu_id, &$item_id ): void {
			update_option( 'acme_banner', 'new' );
			update_post_meta( $post_id, 'acme_color', 'red' );
			wp_set_object_terms( $post_id, [ $category ], 'category' );

			$menu_id = (int) wp_create_nav_menu( 'Acme menu' );
			$item_id = (int) wp_update_nav_menu_item(
				$menu_id,
				0,
				[
					'menu-item-title'  => 'Home',
					'menu-item-url'    => 'https://example.com/',
					'menu-item-type'   => 'custom',
					'menu-item-status' => 'publish',
				]
			);
		};

		$this->call();

		$entry = $this->only_entry();
		$items = $this->items_by_key( (int) $entry['id'] );

		$this->assertSame( self::ABILITY, $entry['ability_name'] );
		$this->assertSame( 'client-acme', $entry['client_id'] );
		$this->assertSame( Recorder::STATUS_RECORDED, $entry['status'] );
		$this->assertNull( $entry['reason'], 'Other tables: ' . (string) $entry['other_tables'] );
		$this->assertNull( $entry['other_tables'] );

		$this->assertSame( 'update', $items['option:acme_banner']['operation'] );
		$this->assertSame( 'old', $items['option:acme_banner']['before']['option_value'] );

		$this->assertSame( 'update', $items[ "meta:post:{$post_id}:acme_color" ]['operation'] );
		$this->assertSame( 'blue', $items[ "meta:post:{$post_id}:acme_color" ]['before']['values'][0]['meta_value'] );

		$this->assertArrayHasKey( "relationships:{$post_id}:category", $items );

		$this->assertSame( 'create', $items[ "term:{$menu_id}" ]['operation'], 'The menu is a nav_menu term.' );
		$this->assertNull( $items[ "term:{$menu_id}" ]['before'] );
		$this->assertSame( 'create', $items[ "post:{$item_id}" ]['operation'], 'The menu item is a nav_menu_item post.' );
		$this->assertArrayHasKey( "meta:post:{$item_id}:_menu_item_url", $items );
		$this->assertArrayHasKey( "relationships:{$item_id}:nav_menu", $items, 'Menu membership is a term relationship.' );
	}

	/**
	 * A raw insert into a table nothing captures makes the entry unrevertable, and names it.
	 *
	 * @return void
	 */
	public function test_a_raw_custom_table_write_is_noted_and_named(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acme_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (id int NOT NULL AUTO_INCREMENT, note varchar(20), PRIMARY KEY (id))', $table ) );

		$this->work = static function () use ( $table ): void {
			global $wpdb;
			update_option( 'acme_banner', 'changed' );
			$wpdb->insert( $table, [ 'note' => 'hi' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The point of the test.
		};

		$this->call();

		$entry = $this->only_entry();

		$this->assertSame( Recorder::REASON_OTHER_TABLE, $entry['reason'] );
		$this->assertSame( [ $table ], json_decode( (string) $entry['other_tables'], true ) );
		$this->assertArrayHasKey( 'option:acme_banner', $this->items_by_key( (int) $entry['id'] ) );
	}

	/**
	 * Writes that all went around the journal still leave an entry, saying so.
	 *
	 * @return void
	 */
	public function test_only_untracked_writes_still_leave_an_entry(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acme_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (id int NOT NULL AUTO_INCREMENT, note varchar(20), PRIMARY KEY (id))', $table ) );

		$this->work = static function () use ( $table ): void {
			global $wpdb;
			$wpdb->insert( $table, [ 'note' => 'hi' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The point of the test.
		};

		$this->call();

		$entry = $this->only_entry();

		$this->assertSame( Recorder::REASON_OTHER_TABLE, $entry['reason'] );
		$this->assertSame( '0', $entry['item_count'] );
	}

	/**
	 * A comment in front of a write, or a query the check cannot read, is noted,
	 * never taken for a read.
	 *
	 * @return void
	 */
	public function test_a_hidden_or_unreadable_write_is_noted(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acme_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (id int NOT NULL AUTO_INCREMENT, note varchar(20), PRIMARY KEY (id))', $table ) );

		$this->work = static function () use ( $table ): void {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The point of the test.
			$wpdb->query( $wpdb->prepare( "/* note */ INSERT INTO %i (note) VALUES ('hi')", $table ) );
			$wpdb->query( '/*!40101 SELECT 1 */' );
			// phpcs:enable
		};

		$this->call();

		$entry = $this->only_entry();

		$this->assertSame( Recorder::REASON_OTHER_TABLE, $entry['reason'] );
		$this->assertEqualsCanonicalizing( [ $table, Recorder::UNREADABLE ], json_decode( (string) $entry['other_tables'], true ) );
	}

	/**
	 * A plugin's rolled-back savepoint takes the early entry row with it; the
	 * journal writes it again, and only what survived the rollback is recorded.
	 *
	 * @return void
	 */
	public function test_a_rollback_does_not_erase_the_record(): void {
		update_option( 'acme_banner', 'old' );

		$this->work = static function (): void {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The point of the test.
			$wpdb->query( 'SAVEPOINT acme' );
			update_option( 'acme_banner', 'rolled back' );
			$wpdb->query( 'ROLLBACK TO SAVEPOINT acme' );
			// phpcs:enable
			wp_cache_delete( 'acme_banner', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			update_option( 'acme_after', 'kept' );
		};

		$this->call();

		$entry = $this->only_entry();
		$items = $this->items_by_key( (int) $entry['id'] );

		$this->assertArrayHasKey( 'option:acme_after', $items );
		$this->assertArrayNotHasKey( 'option:acme_banner', $items, 'The rolled-back change is not recorded.' );
		$this->assertNull( $entry['reason'] );
	}

	/**
	 * Emails the call sent are listed, by count only, so nobody thinks a revert
	 * takes them back.
	 *
	 * @return void
	 */
	public function test_emails_are_listed(): void {
		$this->work = static function (): void {
			update_option( 'acme_banner', 'sent' );
			wp_mail( 'customer@example.com', 'Your order', 'Completed.' );
		};

		$this->call();

		$effects = (string) $this->only_entry()['effects'];

		$this->assertSame( 1, json_decode( $effects, true )['emails']['attempted'] );
		$this->assertStringNotContainsString( 'customer@example.com', $effects );
	}

	/**
	 * The entry links to the ability-log row written for the same call.
	 *
	 * @return void
	 */
	public function test_the_log_row_is_linked(): void {
		$log_id  = 0;
		$user_id = $this->user_id;

		$this->work = static function () use ( &$log_id, $user_id ): void {
			global $wpdb;
			update_option( 'acme_banner', 'logged' );
			( new LogRepository() )->insert( self::ABILITY, $user_id );
			$log_id = (int) $wpdb->insert_id;
		};

		$this->call();

		$this->assertSame( (string) $log_id, $this->only_entry()['log_id'] );
	}

	/**
	 * A handler may not declare WordPress's or Albert's own tables, and one that
	 * fails to open is dropped, so its table is noted rather than trusted.
	 *
	 * @return void
	 */
	public function test_handlers_cannot_hide_writes(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acme_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (id int NOT NULL AUTO_INCREMENT, note varchar(20), PRIMARY KEY (id))', $table ) );

		$greedy = $this->handler( 'greedy', [ 'options', 'acme_log' ], false );
		$hook   = static fn() => [ $greedy ];
		add_filter( 'albert/undo/handlers', $hook );

		try {
			$registry = Registry::load();
			$this->assertFalse( $registry->covers_table( $wpdb->options ), 'A core table is never a handler\'s.' );
			$this->assertTrue( $registry->covers_table( $table ) );

			remove_filter( 'albert/undo/handlers', $hook );
			$broken = $this->handler( 'broken', [ 'acme_log' ], true );
			$hook   = static fn() => [ $broken ];
			add_filter( 'albert/undo/handlers', $hook );

			$this->work = static function () use ( $table ): void {
				global $wpdb;
				$wpdb->insert( $table, [ 'note' => 'hi' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The point of the test.
			};

			$this->call();

			$this->assertSame( [ $table, 'broken (handler failed)' ], json_decode( (string) $this->only_entry()['other_tables'], true ) );
		} finally {
			remove_filter( 'albert/undo/handlers', $hook );
		}
	}

	/**
	 * Credentials never reach either table; a change to one is noted by name and
	 * makes the entry unrevertable.
	 *
	 * @return void
	 */
	public function test_credentials_are_never_stored(): void {
		$target = self::factory()->user->create( [ 'user_email' => 'before@example.com' ] );
		$hash   = get_userdata( $target )->user_pass;
		update_user_meta( $target, 'session_tokens', [ 'secret-session' => [] ] );

		$this->work = static function () use ( $target ): void {
			wp_update_user(
				[
					'ID'         => $target,
					'user_email' => 'after@example.com',
					'user_pass'  => 'a-new-password',
				]
			);
			update_user_meta( $target, 'session_tokens', [ 'another-secret' => [] ] );
		};

		$this->call();

		$entry = $this->only_entry();
		$items = $this->items_by_key( (int) $entry['id'] );

		$this->assertSame( 'before@example.com', $items[ "user:{$target}" ]['before']['user_email'] );
		$this->assertArrayNotHasKey( 'user_pass', $items[ "user:{$target}" ]['before'] );
		$this->assertArrayNotHasKey( 'user_activation_key', $items[ "user:{$target}" ]['before'] );
		$this->assertArrayNotHasKey( "meta:user:{$target}:session_tokens", $items );

		$stored = serialize( [ $entry, $items ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the whole record.
		foreach ( [ $hash, 'secret-session', 'another-secret', get_userdata( $target )->user_pass ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored );
		}

		$this->assertSame( Recorder::REASON_SECRET, $entry['reason'] );
		$this->assertEqualsCanonicalizing( [ 'session_tokens', 'user_pass' ], json_decode( (string) $entry['effects'], true )['secrets'] );
	}

	/**
	 * A plugin writing WordPress's tables without the hooks, like WooCommerce's
	 * atomic stock update, makes the entry unrevertable and names the writer.
	 *
	 * @return void
	 */
	public function test_a_direct_write_to_a_core_table_names_its_writer(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_stock', '5' );

		$this->work = static fn() => self::reduce_stock( $post_id );

		$this->call();

		$entry = $this->only_entry();

		$this->assertSame( Recorder::REASON_DIRECT_WRITE, $entry['reason'] );
		$this->assertContains( "{$GLOBALS['wpdb']->postmeta}, written directly by " . self::class . '::reduce_stock()', json_decode( (string) $entry['other_tables'], true ) );
	}

	/**
	 * Core functions that write without a hook are flagged the same way.
	 *
	 * @return void
	 */
	public function test_a_hookless_core_write_is_flagged(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		$this->work = static fn() => wp_publish_post( $post_id );

		$this->call();

		$entry = $this->only_entry();

		$this->assertSame( Recorder::REASON_DIRECT_WRITE, $entry['reason'] );
		$this->assertStringContainsString( 'written directly by wp_publish_post() (WordPress)', (string) $entry['other_tables'] );
	}

	/**
	 * What WordPress rewrites in one query as a side effect is captured one by
	 * one, so deleting a parent or trashing a post stays revertable.
	 *
	 * @return void
	 */
	public function test_side_effect_rows_are_captured(): void {
		$parent  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$child   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_parent' => $parent,
			]
		);
		$post_id = self::factory()->post->create();
		$comment = self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );

		$this->work = static function () use ( $parent, $post_id ): void {
			wp_delete_post( $parent, true );
			wp_trash_post( $post_id );
		};

		$this->call();

		$entry = $this->only_entry();
		$items = $this->items_by_key( (int) $entry['id'] );

		$this->assertNull( $entry['reason'], 'Not captured: ' . (string) $entry['other_tables'] );
		$this->assertSame( (string) $parent, (string) $items[ "post:{$child}" ]['before']['post_parent'] );
		$this->assertSame( '1', (string) $items[ "comment:{$comment}" ]['before']['comment_approved'] );
	}

	/**
	 * A credential-looking option name, or a credential inside a settings
	 * array, is a secret: noted by name, never stored.
	 *
	 * @return void
	 */
	public function test_credential_looking_values_are_secrets(): void {
		update_option(
			'woocommerce_acme_gateway_settings',
			[
				'enabled'    => 'yes',
				'secret_key' => 'sk_live_before',
			]
		);

		$this->work = static function (): void {
			update_option( 'mailserver_pass', 'smtp-password' );
			update_option(
				'woocommerce_acme_gateway_settings',
				[
					'enabled'    => 'no',
					'secret_key' => 'sk_live_before',
				]
			);
		};

		$this->call();

		$entry  = $this->only_entry();
		$stored = serialize( [ $entry, $this->items_by_key( (int) $entry['id'] ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the whole record.

		$this->assertSame( Recorder::REASON_SECRET, $entry['reason'] );
		$this->assertEqualsCanonicalizing( [ 'mailserver_pass', 'woocommerce_acme_gateway_settings' ], json_decode( (string) $entry['effects'], true )['secrets'] );
		$this->assertStringNotContainsString( 'sk_live_before', $stored );
		$this->assertStringNotContainsString( 'smtp-password', $stored );
	}

	/**
	 * Credentials in rows and in objects are never stored: a post's password,
	 * a password set through wp_set_password(), a key held in a serialized
	 * object or as a name/value pair.
	 *
	 * @return void
	 */
	public function test_hidden_credentials_are_secrets(): void {
		$post_id = self::factory()->post->create( [ 'post_password' => 'door-before' ] );
		$user_id = self::factory()->user->create();
		$object  = new \stdClass();

		$object->settings = [ 'apiKey' => 'object-key-before' ];
		update_option( 'acme_object_settings', $object );
		update_option(
			'acme_pairs',
			[
				[
					'name'  => 'stripe_api_key',
					'value' => 'pair-key-before',
				],
			]
		);

		$this->work = static function () use ( $post_id, $user_id ): void {
			wp_update_post(
				[
					'ID'            => $post_id,
					'post_password' => 'door-after',
					'post_title'    => 'Edited',
				]
			);
			wp_set_password( 'a-new-password', $user_id );
			update_option( 'acme_object_settings', 'replaced' );
			update_option( 'acme_pairs', [] );
		};

		$this->call();

		$entry  = $this->only_entry();
		$stored = serialize( [ $entry, $this->items_by_key( (int) $entry['id'] ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the whole record.

		$this->assertSame( Recorder::REASON_SECRET, $entry['reason'], (string) $entry['other_tables'] );
		$this->assertEqualsCanonicalizing( [ 'post_password', 'user_pass', 'acme_object_settings', 'acme_pairs' ], json_decode( (string) $entry['effects'], true )['secrets'] );
		foreach ( [ 'door-before', 'door-after', 'object-key-before', 'pair-key-before' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored );
		}
	}

	/**
	 * Core writes that fire no hook are named, not assumed covered.
	 *
	 * @return void
	 */
	public function test_an_autoload_change_is_a_direct_write(): void {
		add_option( 'acme_autoloaded', 'v', '', true );

		$this->work = static fn() => wp_set_option_autoload( 'acme_autoloaded', false );

		$this->call();

		$this->assertStringContainsString( 'written directly by wp_set_option_autoload_values() (WordPress)', (string) $this->only_entry()['other_tables'] );
	}

	/**
	 * Past the item limit nothing more is held, and the entry says why.
	 *
	 * @return void
	 */
	public function test_a_call_past_the_limit_is_too_large(): void {
		$this->work = static function (): void {
			for ( $i = 0; $i <= Recorder::MAX_ITEMS + 5; $i++ ) {
				add_option( "acme_bulk_{$i}", 'v', '', false );
			}
		};

		$this->call();

		$entry = $this->only_entry();

		$this->assertSame( Recorder::REASON_TOO_LARGE, $entry['reason'] );
		$this->assertLessThanOrEqual( Recorder::MAX_ITEMS, (int) $entry['item_count'] );
	}

	/**
	 * Deleting a user cannot be reverted: the account, and its password, are gone.
	 *
	 * @return void
	 */
	public function test_a_deleted_user_is_removed(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$target = self::factory()->user->create();

		$this->work = static fn() => wp_delete_user( $target );

		$this->call();

		$entry = $this->only_entry();
		$this->assertSame( Recorder::REASON_REMOVED, $entry['reason'], (string) $entry['other_tables'] );
	}

	/**
	 * Renaming a meta row by id (the custom fields box does) records the old
	 * key, which the hook does not name.
	 *
	 * @return void
	 */
	public function test_a_renamed_meta_row_records_its_old_key(): void {
		$post_id = self::factory()->post->create();
		$meta_id = (int) add_post_meta( $post_id, 'acme_old', 'v' );

		$this->work = static fn() => update_metadata_by_mid( 'post', $meta_id, 'v', 'acme_new' );

		$this->call();

		$entry = $this->only_entry();
		$items = $this->items_by_key( (int) $entry['id'] );

		$this->assertNull( $entry['reason'], (string) $entry['other_tables'] );
		$this->assertSame( 'delete', $items[ "meta:post:{$post_id}:acme_old" ]['operation'] );
		$this->assertSame( 'create', $items[ "meta:post:{$post_id}:acme_new" ]['operation'] );
	}

	/**
	 * A secret deleted for every user at once (everyone logged out) is named.
	 *
	 * @return void
	 */
	public function test_a_bulk_secret_delete_is_named(): void {
		update_user_meta( $this->user_id, 'session_tokens', [ 'live-session' => [] ] );

		$this->work = static fn() => delete_metadata( 'user', 0, 'session_tokens', '', true );

		$this->call();

		$this->assertSame( [ 'session_tokens' ], json_decode( (string) $this->only_entry()['effects'], true )['secrets'] );
	}

	/**
	 * Deleting a user and handing their posts to someone else records the
	 * posts' previous author; the entry stays unrevertable because the account is gone.
	 *
	 * @return void
	 */
	public function test_reassigned_posts_are_recorded(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$leaving = self::factory()->user->create();
		$post_id = self::factory()->post->create( [ 'post_author' => $leaving ] );

		$this->work = fn() => wp_delete_user( $leaving, $this->user_id );

		$this->call();

		$entry = $this->only_entry();
		$items = $this->items_by_key( (int) $entry['id'] );

		$this->assertSame( Recorder::REASON_REMOVED, $entry['reason'] );
		$this->assertSame( (string) $leaving, (string) $items[ "post:{$post_id}" ]['before']['post_author'] );
	}

	/**
	 * Reordering terms on a sorted taxonomy rewrites their order with no
	 * hook, so it is a direct write, not a silent change.
	 *
	 * @return void
	 */
	public function test_a_sorted_term_reorder_is_flagged(): void {
		register_taxonomy( 'acme_sorted', 'post', [ 'sort' => true ] );
		$post_id = self::factory()->post->create();
		$first   = self::factory()->term->create( [ 'taxonomy' => 'acme_sorted' ] );
		$second  = self::factory()->term->create( [ 'taxonomy' => 'acme_sorted' ] );
		wp_set_object_terms( $post_id, [ $first, $second ], 'acme_sorted' );

		$this->work = static fn() => wp_set_object_terms( $post_id, [ $second, $first ], 'acme_sorted' );

		try {
			$this->call();
		} finally {
			unregister_taxonomy( 'acme_sorted' );
		}

		$this->assertSame( Recorder::REASON_DIRECT_WRITE, $this->only_entry()['reason'] );
	}

	/**
	 * A transient mentioned in a value does not make a direct option write noise.
	 *
	 * @return void
	 */
	public function test_a_transient_in_a_value_hides_nothing(): void {
		$this->work = static function (): void {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The direct write under test.
			$wpdb->update( $wpdb->options, [ 'option_value' => 'about _transient_ things' ], [ 'option_name' => 'blogname' ] );
		};

		$this->call();

		$this->assertSame( Recorder::REASON_DIRECT_WRITE, $this->only_entry()['reason'] );
	}

	/**
	 * A handler that throws when the call ends costs that item's certainty,
	 * never the entry: it is closed, and says it is not revertable.
	 *
	 * @return void
	 */
	public function test_a_failing_reread_closes_the_entry(): void {
		$handler = $this->rereading_handler();
		$hook    = static fn() => [ $handler ];
		add_filter( 'albert/undo/handlers', $hook );

		$this->work = static fn() => update_option( 'acme_banner', wp_generate_password( 8, false ) );

		try {
			$this->call();
		} finally {
			remove_filter( 'albert/undo/handlers', $hook );
		}

		$entry = $this->only_entry();

		$this->assertSame( Recorder::STATUS_RECORDED, $entry['status'] );
		$this->assertSame( Recorder::REASON_RECORD_FAILED, $entry['reason'] );
		$this->assertArrayHasKey( 'option:acme_banner', $this->items_by_key( (int) $entry['id'] ) );
	}

	/**
	 * A failed read is a gap, never "did not exist": that would record an
	 * existing option as created, and a revert would delete it.
	 *
	 * @return void
	 */
	public function test_a_failed_read_is_never_a_create(): void {
		update_option( 'acme_banner', 'before' );
		$break = static fn( $query ) => is_string( $query ) && str_contains( $query, 'SELECT option_name, option_value, autoload' ) ? 'SELECT broken FROM nowhere_at_all' : $query;

		$this->work = static function () use ( $break ): void {
			add_filter( 'query', $break );
			update_option( 'acme_banner', 'after' );
			remove_filter( 'query', $break );
		};

		$suppress = $GLOBALS['wpdb']->suppress_errors( true );
		try {
			$this->call();
		} finally {
			$GLOBALS['wpdb']->suppress_errors( $suppress );
			remove_filter( 'query', $break );
		}

		$entry = $this->only_entry();

		$this->assertSame( Recorder::REASON_OTHER_TABLE, $entry['reason'] );
		$this->assertArrayNotHasKey( 'option:acme_banner', $this->items_by_key( (int) $entry['id'] ) );
	}

	/**
	 * Secret names are matched as MySQL finds the row: ignoring case and
	 * trailing spaces, and by the name actually stored.
	 *
	 * @return void
	 */
	public function test_secret_names_match_as_mysql_does(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'session_tokens', [ 'live-session' => [] ] );
		update_option( 'acme_header', 'Bearer abc.def.ghi' );

		$this->work = static function () use ( $user_id ): void {
			update_user_meta( $user_id, 'Session_Tokens ', [ 'other' => [] ] );
			update_option( 'acme_header', 'none' );
		};

		$this->call();

		$entry  = $this->only_entry();
		$stored = serialize( [ $entry, $this->items_by_key( (int) $entry['id'] ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the whole record.

		$this->assertSame( Recorder::REASON_SECRET, $entry['reason'] );
		$this->assertStringNotContainsString( 'live-session', $stored );
		$this->assertStringNotContainsString( 'abc.def.ghi', $stored );
	}

	/**
	 * A comment's IP address and browser are never kept: no edit changes them.
	 *
	 * @return void
	 */
	public function test_a_comment_ip_is_not_stored(): void {
		$comment = self::factory()->comment->create(
			[
				'comment_author_IP' => '203.0.113.9',
				'comment_agent'     => 'AcmeBrowser/1.0',
			]
		);

		$this->work = static fn() => wp_update_comment(
			[
				'comment_ID'      => $comment,
				'comment_content' => 'Edited',
			]
		);

		$this->call();

		$before = $this->items_by_key( (int) $this->only_entry()['id'] )[ "comment:{$comment}" ]['before'];

		$this->assertArrayNotHasKey( 'comment_author_IP', $before );
		$this->assertArrayNotHasKey( 'comment_agent', $before );
	}

	/**
	 * A call writing many tables keeps a bounded, valid list of them.
	 *
	 * @return void
	 */
	public function test_a_long_list_of_tables_is_capped(): void {
		$this->work = static function (): void {
			global $wpdb;
			$suppress = $wpdb->suppress_errors( true );
			for ( $i = 0; $i < 60; $i++ ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes the monitor notes.
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (a) VALUES (1)', "acme_missing_{$i}" ) );
			}
			$wpdb->suppress_errors( $suppress );
		};

		$this->call();

		$tables = json_decode( (string) $this->only_entry()['other_tables'], true );

		$this->assertCount( 51, $tables );
		$this->assertSame( '+10 more', end( $tables ) );
	}

	/**
	 * Two chats on one connection are two sessions, and a session cannot
	 * outlast a day however busy it is.
	 *
	 * @return void
	 */
	public function test_sessions_follow_the_mcp_session_and_end_after_a_day(): void {
		$context = static fn( ?string $session ) => new WindowContext( WindowContext::SOURCE_MCP, 'acme/work', 1, 'client-acme', null, null, $session );

		$first  = $this->journal->create_entry( $context( 'chat-1' ) );
		$second = $this->journal->create_entry( $context( 'chat-2' ) );
		$again  = $this->journal->create_entry( $context( 'chat-1' ) );

		$this->assertNotSame( $first['run_id'], $second['run_id'] );
		$this->assertSame( $first['run_id'], $again['run_id'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Ages the session's first call.
		$wpdb->update( Tables::changes(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - Repository::RUN_MAX_SECONDS - 60 ) ], [ 'id' => $first['id'] ] );

		$this->assertNotSame( $first['run_id'], $this->journal->create_entry( $context( 'chat-1' ) )['run_id'] );
	}

	/**
	 * A handler may not declare another blog's core tables either.
	 *
	 * @return void
	 */
	public function test_handlers_cannot_claim_another_blogs_core_tables(): void {
		global $wpdb;

		$hook = fn() => [ $this->handler( 'acme', [ '2_posts' ], false ) ];
		add_filter( 'albert/undo/handlers', $hook );
		try {
			$this->assertFalse( Registry::load()->covers_table( $wpdb->base_prefix . '2_posts' ) );
		} finally {
			remove_filter( 'albert/undo/handlers', $hook );
		}
	}

	/**
	 * A read-only call leaves nothing behind.
	 *
	 * @return void
	 */
	public function test_a_read_only_call_leaves_no_entry(): void {
		$this->work = static fn() => get_posts();

		$this->call();

		$this->assertSame( 0, $this->entry_count() );
	}

	/**
	 * A call that writes a value back unchanged leaves nothing behind either.
	 *
	 * @return void
	 */
	public function test_an_unchanged_value_leaves_no_entry(): void {
		update_option( 'acme_banner', 'same' );
		$this->work = static fn() => update_option( 'acme_banner', 'same' );

		$this->call();

		$this->assertSame( 0, $this->entry_count() );
	}

	/**
	 * Without a connection nothing is recorded: WP-CLI, cron and the editor look like this.
	 *
	 * @return void
	 */
	public function test_nothing_is_recorded_without_a_connection(): void {
		ConnectionContext::reset();
		$this->work = static fn() => update_option( 'acme_banner', 'from-cli' );

		$this->call();

		$this->assertSame( 0, $this->entry_count() );
	}

	/**
	 * Noise is ignored by both the capture and the table check.
	 *
	 * @return void
	 */
	public function test_noise_is_not_recorded(): void {
		$post_id    = self::factory()->post->create();
		$this->work = static function () use ( $post_id ): void {
			set_transient( 'acme_cache', 'x' );
			update_post_meta( $post_id, '_edit_lock', time() . ':1' );
		};

		$this->call();

		$this->assertSame( 0, $this->entry_count() );
	}

	/**
	 * The journal's own queries leave wpdb's state as the caller had it: the id
	 * add_post_meta() reads back is the row it inserted, though a capture ran
	 * its own query first.
	 *
	 * @return void
	 */
	public function test_the_callers_insert_id_is_untouched(): void {
		$post_id  = self::factory()->post->create();
		$observed = [];

		$this->work = static function () use ( $post_id, &$observed ): void {
			global $wpdb;
			$observed['meta_id'] = (int) add_post_meta( $post_id, 'acme_raw', 'v' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading back the row.
			$observed['row'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $post_id, 'acme_raw' ) );
		};

		$this->call();

		$this->assertGreaterThan( 0, $observed['meta_id'] );
		$this->assertSame( $observed['row'], $observed['meta_id'] );
		$this->assertArrayHasKey( "meta:post:{$post_id}:acme_raw", $this->items_by_key( (int) $this->only_entry()['id'] ) );
	}

	/**
	 * A window the call never closed (the adapter swallowed a throw before its
	 * result filter) is closed by shutdown as interrupted, keeping what it captured.
	 *
	 * @return void
	 */
	public function test_an_unclosed_window_is_closed_as_interrupted(): void {
		apply_filters( 'mcp_adapter_pre_tool_call', [], 'acme-work', null, null );
		update_option( 'acme_banner', 'half-done' );

		Window::interrupt();

		$entry = $this->only_entry();

		$this->assertSame( Recorder::STATUS_INTERRUPTED, $entry['status'] );
		$this->assertSame( Recorder::REASON_INTERRUPTED, $entry['reason'] );
		$this->assertArrayHasKey( 'option:acme_banner', $this->items_by_key( (int) $entry['id'] ) );
	}

	/**
	 * Calls close together from one connection share a session; a long gap starts another.
	 *
	 * @return void
	 */
	public function test_calls_from_one_connection_group_into_sessions(): void {
		$this->work = static fn() => update_option( 'acme_banner', wp_generate_password( 8, false ) );

		$this->call();
		$this->call();

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, run_id FROM %i ORDER BY id', Tables::changes() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
		$this->assertCount( 2, $rows );
		$this->assertSame( $rows[0]['run_id'], $rows[1]['run_id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Age the run past the gap.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s, finished_at = %s', Tables::changes(), gmdate( 'Y-m-d H:i:s', time() - 3600 ), gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );

		$this->call();

		$last = $wpdb->get_row( $wpdb->prepare( 'SELECT id, run_id FROM %i ORDER BY id DESC LIMIT 1', Tables::changes() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
		$this->assertSame( $last['id'], $last['run_id'], 'After the gap a call starts its own session.' );
	}

	/**
	 * A safe-mode approved run is recorded as the requester's call, against its row.
	 *
	 * @return void
	 */
	public function test_an_approved_run_is_recorded_against_its_pending_action(): void {
		$safe_mode = new SafeModeRepository();
		$requester = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$action    = $safe_mode->stage( self::ABILITY, [], $requester, 'client-acme', 'Acme', DAY_IN_SECONDS );

		ConnectionContext::reset();
		$this->work = static fn() => update_option( 'acme_banner', 'approved' );

		( new Approver( $safe_mode ) )->approve( $action, $this->user_id );

		$entry = $this->only_entry();

		$this->assertSame( 'approval', $entry['source'] );
		$this->assertSame( (string) $action->id, $entry['pending_action_id'] );
		$this->assertSame( (string) $requester, $entry['user_id'] );
		$this->assertNull( $entry['reason'] );
	}

	/**
	 * Run the throwaway ability through the adapter's real tools/call handler.
	 *
	 * The server is a stand-in exposing the ability as a tool directly: the
	 * adapter's meta-abilities are not registered in the test environment. What
	 * matters here, `ToolsHandler::call_tool()` and its pre/result filters, is real.
	 *
	 * @return void
	 */
	private function call(): void {
		$tool   = McpTool::fromAbility( wp_get_ability( self::ABILITY ) );
		$server = $this->createMock( McpServer::class );
		$server->method( 'get_server_id' )->willReturn( Server::SERVER_ID );
		$server->method( 'get_mcp_tool' )->willReturn( $tool );
		$server->method( 'get_error_handler' )->willReturn( new NullMcpErrorHandler() );

		( new ToolsHandler( $server ) )->call_tool(
			[
				'name'      => 'acme-work',
				'arguments' => [],
			]
		);
	}

	/**
	 * Register the throwaway ability, which runs `$this->work`.
	 *
	 * @return void
	 */
	private function register_ability(): void {
		global $wp_current_filter;

		if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( self::CATEGORY ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern.
			$wp_current_filter[] = 'wp_abilities_api_categories_init';
			try {
				wp_register_ability_category(
					self::CATEGORY,
					[
						'label'       => 'Albert Test',
						'description' => 'Test-only category.',
					]
				);
			} finally {
				array_pop( $wp_current_filter );
			}
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			wp_register_ability(
				self::ABILITY,
				[
					'label'               => 'Acme work',
					'description'         => 'A third-party ability Albert knows nothing about.',
					'category'            => self::CATEGORY,
					'input_schema'        => [
						'type'       => 'object',
						'properties' => new \stdClass(),
					],
					'execute_callback'    => fn() => [ 'result' => ( $this->work )() === null ? 'done' : 'done' ],
					'permission_callback' => '__return_true',
					'meta'                => [ 'mcp' => [ 'public' => true ] ],
				]
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Lower stock the way WooCommerce does: one atomic query, no meta hook.
	 *
	 * @param int $post_id Product.
	 *
	 * @return void
	 */
	private static function reduce_stock( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The direct write under test.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET meta_value = meta_value - 1 WHERE post_id = %d AND meta_key = '_stock'", $wpdb->postmeta, $post_id ) );
	}

	/**
	 * A handler that captures one entity of its own and fails to read it again
	 * when the call ends.
	 *
	 * @return Handler
	 */
	private function rereading_handler(): Handler {
		return new class() implements Handler {

			/**
			 * Reads so far.
			 *
			 * @var int
			 */
			private int $reads = 0;

			/**
			 * {@inheritDoc}
			 */
			public function id(): string {
				return 'acme_reread';
			}

			/**
			 * {@inheritDoc}
			 */
			public function tables(): array {
				return [];
			}

			/**
			 * {@inheritDoc}
			 */
			public function derived_tables(): array {
				return [];
			}

			/**
			 * {@inheritDoc}
			 */
			public function claims( Entity $entity ): bool {
				return false;
			}

			/**
			 * {@inheritDoc}
			 */
			public function open( Recorder $recorder ): void {
				$recorder->capture( Entity::custom( 'acme_reread', 'one' ) );
			}

			/**
			 * {@inheritDoc}
			 */
			public function close(): void {}

			/**
			 * {@inheritDoc}
			 *
			 * @throws \RuntimeException On the second read.
			 */
			public function read( string $key ): ?array {
				if ( ++$this->reads > 1 ) {
					throw new \RuntimeException( 'gone' );
				}
				return [ 'value' => 'v' ];
			}
		};
	}

	/**
	 * A handler declaring tables, optionally failing to open.
	 *
	 * @param string             $id     Handler id.
	 * @param array<int, string> $tables Tables it claims to capture.
	 * @param bool               $throws Whether open() throws.
	 *
	 * @return Handler
	 */
	private function handler( string $id, array $tables, bool $throws ): Handler {
		return new class( $id, $tables, $throws ) implements Handler {

			/**
			 * Build.
			 *
			 * @param string             $id     Id.
			 * @param array<int, string> $tables Tables.
			 * @param bool               $throws Whether open() throws.
			 */
			public function __construct( private string $id, private array $tables, private bool $throws ) {}

			/**
			 * {@inheritDoc}
			 */
			public function id(): string {
				return $this->id;
			}

			/**
			 * {@inheritDoc}
			 */
			public function tables(): array {
				return $this->tables;
			}

			/**
			 * {@inheritDoc}
			 */
			public function derived_tables(): array {
				return [];
			}

			/**
			 * {@inheritDoc}
			 */
			public function claims( Entity $entity ): bool {
				return false;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @throws \RuntimeException When asked to.
			 */
			public function open( Recorder $recorder ): void {
				if ( $this->throws ) {
					throw new \RuntimeException( 'broken handler' );
				}
			}

			/**
			 * {@inheritDoc}
			 */
			public function close(): void {}

			/**
			 * {@inheritDoc}
			 */
			public function read( string $key ): ?array {
				return null;
			}
		};
	}

	/**
	 * The single entry the call produced.
	 *
	 * @return array<string, mixed>
	 */
	private function only_entry(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', Tables::changes() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
		$this->assertCount( 1, $rows, 'Expected exactly one journal entry.' );

		return $rows[0];
	}

	/**
	 * How many entries exist.
	 *
	 * @return int
	 */
	private function entry_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::changes() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
	}

	/**
	 * An entry's items keyed `type:key`.
	 *
	 * @param int $change_id Entry id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function items_by_key( int $change_id ): array {
		$items = [];
		foreach ( $this->journal->items( $change_id ) as $item ) {
			$items[ $item['entity_type'] . ':' . $item['entity_key'] ] = $item;
		}

		return $items;
	}
}
