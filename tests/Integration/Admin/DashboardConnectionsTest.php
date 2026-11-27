<?php
/**
 * Integration tests for the Dashboard's idea of "connected".
 *
 * The Dashboard used to ask its own question — `revoked = 0` on the access
 * tokens, nothing else — while the Connections screen, both retention sweeps
 * and the Dashboard's own attention card all used
 * {@see ClientRepository::getLiveConnections()}. Its predicate has no expiry
 * check and never looks at a refresh token, so it counted a client whose every
 * token had expired: for up to a day, until `Cron\TokenCleanup` removes the
 * rows, and indefinitely on a site where WP-Cron never runs. A screen reading
 * "1 connection" and offering the finished-setup state over an assistant that
 * cannot call anything is the failure this pins shut.
 *
 * There is a second thing here worth stating, because it is easy to assume the
 * opposite: a refresh token cannot keep a connection visible on its own once
 * the access-token row it points at has been deleted. `getLiveConnections()`
 * selects `FROM access_tokens`, so no row means no connection, everywhere. That
 * is a token-lifecycle question in `ClientRepository`/`Cron\TokenCleanup`
 * rather than anything this screen decides, and the Dashboard's job is only to
 * give the same answer as everything else. That is what
 * {@see self::test_the_dashboard_agrees_with_the_connections_screen()} holds it
 * to.
 *
 * Plus the narrower thing that started it: the count and the names beneath it
 * came from two separate queries and could describe two different sets.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Dashboard;
use Albert\Database\Tables;
use Albert\Logging\Repository as LoggingRepository;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\RefreshTokenEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Repositories\RefreshTokenRepository;
use Albert\Tests\TestCase;
use DateTimeImmutable;

/**
 * What the Dashboard counts as a connection.
 *
 * @covers \Albert\Admin\Dashboard
 */
class DashboardConnectionsTest extends TestCase {

	/**
	 * Start from no clients and no tokens, so a count means what this test put
	 * there.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$tables = Tables::oauth();

		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables[ $key ] ) );
		}
	}

	/**
	 * A connection whose access token has expired but whose refresh token has
	 * not is live: that is exactly the client that refreshes hourly and has
	 * simply been quiet.
	 *
	 * @return void
	 */
	public function test_a_refresh_token_alone_keeps_a_connection_live(): void {
		$this->create_connection( 'Quiet assistant', '-2 hours', '+30 days' );

		$this->assertSame( 1, $this->counted() );
	}

	/**
	 * Whatever the Connections screen lists, this screen counts. One definition
	 * of the word, wherever it is read.
	 *
	 * Deliberately asserted against the repository rather than against a number
	 * this test works out for itself: the point is not that the figure is 2, it
	 * is that there is no second opinion about it. A later change to what counts
	 * as live should move both together or fail here.
	 *
	 * @return void
	 */
	public function test_the_dashboard_agrees_with_the_connections_screen(): void {
		$this->create_connection( 'Live one', '+1 hour', '+30 days' );
		$this->create_connection( 'Quiet one', '-2 hours', '+30 days' );
		$this->create_connection( 'Dead one', '-2 hours', '-1 hour' );

		$this->assertSame(
			count( ( new ClientRepository() )->getLiveConnections() ),
			$this->counted()
		);
	}

	/**
	 * A client with nothing live is not a connection, even while its expired
	 * rows are still sitting in the table waiting for the sweep.
	 *
	 * @return void
	 */
	public function test_expired_tokens_awaiting_the_sweep_are_not_counted(): void {
		$this->create_connection( 'Dead assistant', '-2 hours', '-1 hour' );

		$this->assertSame( 0, $this->counted() );
		$this->assertSame( '', $this->names() );
	}

	/**
	 * The figure and the names beneath it describe one set.
	 *
	 * They were two queries with two different predicates, so a tile could read
	 * "2" over a list of three names. Read off one array now, which is what
	 * makes that unrepresentable rather than merely unlikely.
	 *
	 * @return void
	 */
	public function test_the_count_and_the_names_describe_the_same_set(): void {
		$this->create_connection( 'Live one', '+1 hour', '+30 days' );
		$this->create_connection( 'Live two', '+1 hour', '+30 days' );
		$this->create_connection( 'Dead one', '-2 hours', '-1 hour' );

		$this->assertSame( 2, $this->counted() );

		$names = $this->names();

		$this->assertStringContainsString( 'Live one', $names );
		$this->assertStringContainsString( 'Live two', $names );
		$this->assertStringNotContainsString( 'Dead one', $names );
	}

	/**
	 * The owner's own label is what a connection is called, matching the
	 * Connections screen. Names are self-reported by the connecting app, and in
	 * practice several register as the same word.
	 *
	 * @return void
	 */
	public function test_an_owner_label_is_preferred_over_the_reported_name(): void {
		$client_id = $this->create_connection( 'Claude', '+1 hour', '+30 days' );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->update(
			Tables::oauth()['clients'],
			[ 'label' => 'Marketing laptop' ],
			[ 'client_id' => $client_id ]
		);

		$this->assertStringContainsString( 'Marketing laptop', $this->names() );
		$this->assertStringNotContainsString( 'Claude', $this->names() );
	}

	/**
	 * How many connections the Dashboard believes there are.
	 *
	 * @return int
	 */
	private function counted(): int {
		return count( $this->invoke( 'get_live_connections' ) );
	}

	/**
	 * The names the stat tile would print under that figure.
	 *
	 * @return string
	 */
	private function names(): string {
		return (string) $this->invoke( 'get_connection_names' );
	}

	/**
	 * Call one of the Dashboard's private readers on a fresh instance.
	 *
	 * Fresh each time, because the connection list is held for the life of the
	 * object: a test that changes the data mid-way has to ask a new one.
	 *
	 * @param string $method Method name.
	 *
	 * @return mixed
	 */
	private function invoke( string $method ) {
		$dashboard = new Dashboard( new LoggingRepository() );

		$reflection = new \ReflectionMethod( $dashboard, $method );

		return $reflection->invoke( $dashboard );
	}

	/**
	 * Create a client with an access token and a refresh token whose expiry
	 * dates are given as `strtotime()`-style offsets, so a test can put either
	 * one side of "now".
	 *
	 * @param string $name           The app-reported client name.
	 * @param string $access_expiry  Access token expiry, e.g. `-2 hours`.
	 * @param string $refresh_expiry Refresh token expiry, e.g. `+30 days`.
	 *
	 * @return string The client id.
	 */
	private function create_connection( string $name, string $access_expiry, string $refresh_expiry ): string {
		$created   = ( new ClientRepository() )->createClient( $name, 'https://example.test/cb', true, 1 );
		$client_id = (string) $created['client_id'];

		$client_entity = new ClientEntity();
		$client_entity->setIdentifier( $client_id );

		$access = new AccessTokenEntity();
		$access->setIdentifier( 'tok_' . $client_id );
		$access->setClient( $client_entity );
		$access->setUserIdentifier( '1' );
		$access->setExpiryDateTime( new DateTimeImmutable( $access_expiry ) );
		$access->addScope( new ScopeEntity( 'default' ) );

		( new AccessTokenRepository() )->persistNewAccessToken( $access );

		$refresh = new RefreshTokenEntity();
		$refresh->setIdentifier( 'ref_' . $client_id );
		$refresh->setAccessToken( $access );
		$refresh->setExpiryDateTime( new DateTimeImmutable( $refresh_expiry ) );

		( new RefreshTokenRepository() )->persistNewRefreshToken( $refresh );

		return $client_id;
	}
}
