<?php
/**
 * Integration tests for the Connections screen.
 *
 * Four things are covered here, all of which fail silently: the label's
 * attribution columns and the write path behind them, escaping of a label at
 * every place the screen renders one, the bulk revoke handler, and the
 * multi-select allowed-users handler.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Connections;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;

/**
 * Connections screen integration tests.
 *
 * @covers \Albert\Admin\Connections
 * @covers \Albert\OAuth\Repositories\ClientRepository
 */
class ConnectionsTest extends TestCase {

	/**
	 * The screen under test.
	 *
	 * @var Connections
	 */
	private Connections $screen;

	/**
	 * The client repository.
	 *
	 * @var ClientRepository
	 */
	private ClientRepository $clients;

	/**
	 * An administrator, since every action on this screen is manage_options.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Fresh tables and an administrator for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		$this->screen  = new Connections();
		$this->clients = new ClientRepository();

		$this->admin_id = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'display_name' => 'Mark Jansen',
			]
		);

		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$tables = Tables::oauth();

		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables[ $key ] ) );
		}

		delete_option( 'albert_allowed_users' );
		delete_option( AllowedUsers::EXPIRY_OPTION );

		// Every handler ends in wp_safe_redirect() + exit(), which would take
		// the test runner with it.
		add_filter( 'wp_redirect', [ $this, 'catch_redirect' ], 10, 1 );
	}

	/**
	 * Turn a redirect into an exception the test can assert on.
	 *
	 * @param string $location Where the handler wanted to go.
	 *
	 * @return string Never returns.
	 * @throws \RuntimeException Always.
	 */
	public function catch_redirect( $location ): string {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output; a test-only exception message.
		throw new \RuntimeException( 'redirect:' . $location );
	}

	/**
	 * Create a client with one live access token.
	 *
	 * @param string $name    The client's self-reported name.
	 * @param int    $user_id Who authorised it.
	 *
	 * @return string The client identifier.
	 */
	private function connect( string $name, int $user_id ): string {
		global $wpdb;

		$created = $this->clients->createClient( $name, 'https://example.test/cb' );
		$tables  = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			$tables['access_tokens'],
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => $created['client_id'],
				'user_id'    => $user_id,
				'scopes'     => '[]',
				'revoked'    => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);

		return (string) $created['client_id'];
	}

	/**
	 * Whether a client still holds a live, unrevoked access token.
	 *
	 * @param string $client_id The client identifier.
	 *
	 * @return bool
	 */
	private function is_connected( string $client_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$live = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE client_id = %s AND revoked = 0',
				Tables::oauth()['access_tokens'],
				$client_id
			)
		);

		return $live > 0;
	}

	/**
	 * Render the whole screen and hand back the HTML.
	 *
	 * @return string
	 */
	private function render_screen(): string {
		ob_start();
		$this->screen->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Run a handler that is expected to end in a redirect.
	 *
	 * @param callable $handler The handler to run.
	 *
	 * @return void
	 */
	private function expect_redirect( callable $handler ): void {
		try {
			$handler();
			$this->fail( 'The handler should have redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
		}
	}

	/**
	 * Post the picker's form with a given id list.
	 *
	 * @param string $ids Comma-separated user ids.
	 *
	 * @return void
	 */
	private function submit_picker( string $ids ): void {
		$_POST['albert_add_users_nonce'] = wp_create_nonce( 'albert_add_allowed_users' );
		$_POST['albert_user_ids']        = $ids;
		$_POST['albert_return']          = 'connections';

		$this->expect_redirect(
			function (): void {
				$this->screen->handle_add_allowed_users();
			}
		);
	}

	/*
	---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------
	 */

	/**
	 * The clients table carries the label's attribution columns.
	 *
	 * @return void
	 */
	public function test_clients_table_has_label_attribution_columns(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::oauth()['clients'] ) );

		$this->assertContains( 'label', $columns );
		$this->assertContains( 'label_set_by', $columns );
		$this->assertContains( 'label_set_at', $columns );
	}

	/**
	 * A connection nobody has named reads back with no label and no byline.
	 *
	 * @return void
	 */
	public function test_a_client_without_a_label_has_no_attribution(): void {
		$client_id = $this->connect( 'Claude', $this->admin_id );
		$client    = $this->clients->getClientEntity( $client_id );

		$this->assertNull( $client->getLabel() );
		$this->assertNull( $client->getLabelSetBy() );
		$this->assertNull( $client->getLabelSetAt() );
	}

	/*
	---------------------------------------------------------------------
	 * Label attribution
	 * ------------------------------------------------------------------
	 */

	/**
	 * Setting a label records who wrote it and when.
	 *
	 * @return void
	 */
	public function test_setting_a_label_records_its_author(): void {
		$client_id = $this->connect( 'Claude', $this->admin_id );

		$this->clients->updateClientLabel( $client_id, 'Studio iMac', $this->admin_id );

		$client = $this->clients->getClientEntity( $client_id );

		$this->assertSame( 'Studio iMac', $client->getLabel() );
		$this->assertSame( $this->admin_id, $client->getLabelSetBy() );
		$this->assertInstanceOf( \DateTimeImmutable::class, $client->getLabelSetAt() );
	}

	/**
	 * Clearing the label clears its attribution with it: there is nothing left
	 * to attribute, and a byline under an empty name would be a lie.
	 *
	 * @return void
	 */
	public function test_clearing_a_label_clears_its_attribution(): void {
		$client_id = $this->connect( 'Claude', $this->admin_id );

		$this->clients->updateClientLabel( $client_id, 'Studio iMac', $this->admin_id );
		$this->clients->updateClientLabel( $client_id, '', $this->admin_id );

		$client = $this->clients->getClientEntity( $client_id );

		$this->assertNull( $client->getLabel() );
		$this->assertNull( $client->getLabelSetBy() );
		$this->assertNull( $client->getLabelSetAt() );
	}

	/**
	 * A relabel is attributed to whoever did it, not to whoever did it first.
	 *
	 * @return void
	 */
	public function test_relabelling_records_the_new_author(): void {
		$other_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$client_id = $this->connect( 'Claude', $this->admin_id );

		$this->clients->updateClientLabel( $client_id, 'First name', $this->admin_id );
		$this->clients->updateClientLabel( $client_id, 'Second name', $other_id );

		$client = $this->clients->getClientEntity( $client_id );

		$this->assertSame( 'Second name', $client->getLabel() );
		$this->assertSame( $other_id, $client->getLabelSetBy() );
	}

	/**
	 * The screen's own save path attributes the label to the current user.
	 *
	 * @return void
	 */
	public function test_the_screen_attributes_a_saved_label_to_the_current_user(): void {
		$client_id = $this->connect( 'Claude', $this->admin_id );

		$_POST['albert_client_id']        = $client_id;
		$_POST['albert_connection_label'] = 'Studio iMac';
		$_POST['albert_label_nonce']      = wp_create_nonce( 'albert_set_connection_label_' . $client_id );

		$this->expect_redirect(
			function (): void {
				$this->screen->handle_set_connection_label();
			}
		);

		$client = $this->clients->getClientEntity( $client_id );

		$this->assertSame( 'Studio iMac', $client->getLabel() );
		$this->assertSame( $this->admin_id, $client->getLabelSetBy() );
	}

	/**
	 * The attribution line is rendered on the row once a label has one.
	 *
	 * @return void
	 */
	public function test_the_row_shows_who_wrote_the_label(): void {
		$client_id = $this->connect( 'Claude', $this->admin_id );

		$this->clients->updateClientLabel( $client_id, 'Studio iMac', $this->admin_id );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'Labelled by Mark Jansen on', $html );
	}

	/**
	 * An unlabelled connection gets no byline at all.
	 *
	 * @return void
	 */
	public function test_an_unlabelled_row_shows_no_attribution_line(): void {
		$this->connect( 'Claude', $this->admin_id );

		$html = $this->render_screen();

		$this->assertStringNotContainsString( 'Labelled by', $html );
	}

	/*
	---------------------------------------------------------------------
	 * Escaping
	 * ------------------------------------------------------------------
	 */

	/**
	 * A label carrying markup is escaped everywhere the screen renders it.
	 *
	 * The label is the one field here that one person types and another reads,
	 * and it reaches five render sites on a single row: the visible title, the
	 * filter's search index, the checkbox's accessible name, the disconnect
	 * dialog's data attribute and the edit field's value. A CVE of exactly this
	 * shape exists in a comparable nickname field, so this asserts the rendered
	 * page rather than any one call site.
	 *
	 * @return void
	 */
	public function test_a_label_containing_markup_never_reaches_the_page_raw(): void {
		$client_id = $this->connect( 'Claude', $this->admin_id );

		$this->clients->updateClientLabel( $client_id, '<script>alert("xss")</script>', $this->admin_id );

		$html = $this->render_screen();

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * A label engineered to break out of an attribute cannot.
	 *
	 * @return void
	 */
	public function test_a_label_cannot_break_out_of_an_attribute(): void {
		$client_id = $this->connect( 'Claude', $this->admin_id );

		$this->clients->updateClientLabel( $client_id, '" onfocus="alert(1)', $this->admin_id );

		$html = $this->render_screen();

		// The escaped form still contains the characters "onfocus=", which is
		// harmless; what must never appear is the unescaped quote that would
		// end the attribute and start a new one.
		$this->assertStringNotContainsString( '" onfocus="alert(1)', $html );
		$this->assertStringContainsString( '&quot; onfocus=&quot;alert(1)', $html );
	}

	/*
	---------------------------------------------------------------------
	 * The screen renders the same whatever the row count
	 * ------------------------------------------------------------------
	 */

	/**
	 * The filter and Select are there with no rows at all.
	 *
	 * @return void
	 */
	public function test_the_toolbar_renders_with_no_connections(): void {
		$html = $this->render_screen();

		$this->assertStringContainsString( 'data-albert-filter', $html );
		$this->assertStringContainsString( 'data-albert-select-toggle', $html );
		$this->assertStringContainsString( 'data-albert-bulkbar', $html );
	}

	/**
	 * And they are the same controls once rows exist.
	 *
	 * @return void
	 */
	public function test_the_toolbar_is_unchanged_with_connections(): void {
		$this->connect( 'Claude', $this->admin_id );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'data-albert-filter', $html );
		$this->assertStringContainsString( 'data-albert-select-toggle', $html );
		$this->assertStringContainsString( 'data-albert-bulkbar', $html );
	}

	/**
	 * Row checkboxes ship disabled, so nothing is submittable until somebody
	 * has explicitly entered selection mode.
	 *
	 * @return void
	 */
	public function test_row_checkboxes_start_disabled(): void {
		$this->connect( 'Claude', $this->admin_id );

		$html = $this->render_screen();

		$this->assertStringContainsString( 'data-albert-row-check', $html );
		$this->assertMatchesRegularExpression( '/data-albert-row-check[^>]*disabled/', $html );
	}

	/*
	---------------------------------------------------------------------
	 * Bulk revoke
	 * ------------------------------------------------------------------
	 */

	/**
	 * "Revoke selected" revokes exactly the ticked connections.
	 *
	 * @return void
	 */
	public function test_bulk_revoke_revokes_only_the_selected_connections(): void {
		$one   = $this->connect( 'Claude', $this->admin_id );
		$two   = $this->connect( 'Claude', $this->admin_id );
		$three = $this->connect( 'Cursor', $this->admin_id );

		$_POST['albert_bulk_nonce'] = wp_create_nonce( 'albert_revoke_selected' );
		$_POST['client_ids']        = [ $one, $three ];

		$this->expect_redirect(
			function (): void {
				$this->screen->handle_revoke_selected();
			}
		);

		$this->assertFalse( $this->is_connected( $one ) );
		$this->assertTrue( $this->is_connected( $two ) );
		$this->assertFalse( $this->is_connected( $three ) );
	}

	/**
	 * The same id arriving twice revokes it once and does not error.
	 *
	 * @return void
	 */
	public function test_bulk_revoke_tolerates_duplicate_ids(): void {
		$one = $this->connect( 'Claude', $this->admin_id );

		$_POST['albert_bulk_nonce'] = wp_create_nonce( 'albert_revoke_selected' );
		$_POST['client_ids']        = [ $one, $one ];

		$this->expect_redirect(
			function (): void {
				$this->screen->handle_revoke_selected();
			}
		);

		$this->assertFalse( $this->is_connected( $one ) );
	}

	/**
	 * An empty selection revokes nothing.
	 *
	 * @return void
	 */
	public function test_bulk_revoke_with_nothing_selected_revokes_nothing(): void {
		$one = $this->connect( 'Claude', $this->admin_id );

		$_POST['albert_bulk_nonce'] = wp_create_nonce( 'albert_revoke_selected' );
		$_POST['client_ids']        = [];

		$this->expect_redirect(
			function (): void {
				$this->screen->handle_revoke_selected();
			}
		);

		$this->assertTrue( $this->is_connected( $one ) );
	}

	/**
	 * A bad nonce stops the handler dead.
	 *
	 * @return void
	 */
	public function test_bulk_revoke_refuses_a_bad_nonce(): void {
		$one = $this->connect( 'Claude', $this->admin_id );

		$_POST['albert_bulk_nonce'] = 'not-a-nonce';
		$_POST['client_ids']        = [ $one ];

		$this->expectException( \WPDieException::class );

		$this->screen->handle_revoke_selected();
	}

	/**
	 * Somebody without the capability cannot bulk revoke, nonce or no nonce.
	 *
	 * @return void
	 */
	public function test_bulk_revoke_refuses_a_user_without_the_capability(): void {
		$one = $this->connect( 'Claude', $this->admin_id );

		$_POST['albert_bulk_nonce'] = wp_create_nonce( 'albert_revoke_selected' );
		$_POST['client_ids']        = [ $one ];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->expectException( \WPDieException::class );

		$this->screen->handle_revoke_selected();
	}

	/*
	---------------------------------------------------------------------
	 * Adding allowed users
	 * ------------------------------------------------------------------
	 */

	/**
	 * Several people are added in one submission.
	 *
	 * @return void
	 */
	public function test_the_picker_adds_every_chosen_user(): void {
		$one = self::factory()->user->create( [ 'role' => 'editor' ] );
		$two = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->submit_picker( $one . ',' . $two );

		$this->assertSame( [ $one, $two ], AllowedUsers::ids() );
	}

	/**
	 * Somebody already on the list is not added a second time.
	 *
	 * @return void
	 */
	public function test_the_picker_does_not_add_a_duplicate(): void {
		$one = self::factory()->user->create( [ 'role' => 'editor' ] );

		AllowedUsers::add( $one );

		$this->submit_picker( (string) $one );

		$this->assertSame( [ $one ], AllowedUsers::ids() );
	}

	/**
	 * Someone without the picker's capability is not added, even by a crafted request.
	 *
	 * @return void
	 */
	public function test_the_picker_does_not_add_a_user_it_would_not_offer(): void {
		$editor     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->submit_picker( $editor . ',' . $subscriber );

		$this->assertSame( [ $editor ], AllowedUsers::ids() );
	}

	/**
	 * An id nobody owns is ignored rather than stored.
	 *
	 * @return void
	 */
	public function test_the_picker_ignores_an_unknown_user_id(): void {
		$one = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->submit_picker( $one . ',999999' );

		$this->assertSame( [ $one ], AllowedUsers::ids() );
	}

	/**
	 * Choosing nobody changes nothing.
	 *
	 * @return void
	 */
	public function test_the_picker_with_an_empty_selection_changes_nothing(): void {
		$one = self::factory()->user->create( [ 'role' => 'editor' ] );

		AllowedUsers::add( $one );

		$this->submit_picker( '' );

		$this->assertSame( [ $one ], AllowedUsers::ids() );
	}

	/**
	 * The picker refuses a bad nonce.
	 *
	 * @return void
	 */
	public function test_the_picker_refuses_a_bad_nonce(): void {
		$_POST['albert_add_users_nonce'] = 'not-a-nonce';
		$_POST['albert_user_ids']        = '1';

		$this->expectException( \WPDieException::class );

		$this->screen->handle_add_allowed_users();
	}

	/**
	 * The picker refuses somebody without the capability.
	 *
	 * @return void
	 */
	public function test_the_picker_refuses_a_user_without_the_capability(): void {
		$one = self::factory()->user->create( [ 'role' => 'editor' ] );

		$_POST['albert_add_users_nonce'] = wp_create_nonce( 'albert_add_allowed_users' );
		$_POST['albert_user_ids']        = (string) $one;

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->expectException( \WPDieException::class );

		$this->screen->handle_add_allowed_users();
	}

	/**
	 * Opening the picker from the Dashboard sends the person back there.
	 *
	 * @return void
	 */
	public function test_the_picker_returns_to_the_screen_it_was_opened_from(): void {
		$one = self::factory()->user->create( [ 'role' => 'editor' ] );

		$_POST['albert_add_users_nonce'] = wp_create_nonce( 'albert_add_allowed_users' );
		$_POST['albert_user_ids']        = (string) $one;
		$_POST['albert_return']          = 'dashboard';

		try {
			$this->screen->handle_add_allowed_users();
			$this->fail( 'The handler should have redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'page=albert&', $e->getMessage() );
		}
	}

	/**
	 * An unrecognised return value falls back to Connections rather than
	 * redirecting wherever the request asked to go.
	 *
	 * @return void
	 */
	public function test_the_picker_ignores_an_unrecognised_return_target(): void {
		$one = self::factory()->user->create( [ 'role' => 'editor' ] );

		$_POST['albert_add_users_nonce'] = wp_create_nonce( 'albert_add_allowed_users' );
		$_POST['albert_user_ids']        = (string) $one;
		$_POST['albert_return']          = 'https://evil.test';

		try {
			$this->screen->handle_add_allowed_users();
			$this->fail( 'The handler should have redirected.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'page=albert-connections', $e->getMessage() );
			$this->assertStringNotContainsString( 'evil.test', $e->getMessage() );
		}
	}
}
