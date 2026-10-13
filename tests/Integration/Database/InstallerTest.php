<?php
/**
 * Integration tests for the Database Installer.
 *
 * Covers schema creation (ability log + OAuth tables), the recorded schema
 * version, idempotent install / maybe_upgrade (safe to re-run on every load),
 * and uninstall cleanup.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Database;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;

/**
 * Database Installer integration tests.
 *
 * @covers \Albert\Database\Installer
 * @covers \Albert\Database\Tables
 */
class InstallerTest extends TestCase {

	/**
	 * The ability-log columns dropped in 1.4.0.
	 *
	 * @var array<int, string>
	 */
	const LEGACY_LOG_COLUMNS = [ 'ip_address', 'referrer', 'request_id' ];

	/**
	 * Ensure the tables exist before each test (normally created at plugin load).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		// DDL is not rolled back with the test transaction, so a legacy column
		// re-added by an earlier test in the run would still be there. Start
		// every test from the current schema regardless of what ran before.
		$this->remove_legacy_log_columns();
	}

	/**
	 * The ability log's current column names.
	 *
	 * @return array<int, string>
	 */
	private function ability_log_columns(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		return $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::ability_log() ) );
	}

	/**
	 * Put the ability log back into its pre-1.4.0 shape by re-adding the three
	 * columns the upgrade is meant to remove. `dbDelta` created the table from
	 * the current schema in `set_up()`, so the legacy state has to be recreated
	 * rather than found.
	 *
	 * @return void
	 */
	private function restore_legacy_log_columns(): void {
		global $wpdb;

		$table = Tables::ability_log();

		foreach ( self::LEGACY_LOG_COLUMNS as $column ) {
			if ( in_array( $column, $this->ability_log_columns(), true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture recreating the legacy schema.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i text DEFAULT NULL', $table, $column ) );
		}
	}

	/**
	 * Drop any legacy ability-log column still present, so each test starts
	 * from the schema `Installer::install()` declares today.
	 *
	 * @return void
	 */
	private function remove_legacy_log_columns(): void {
		global $wpdb;

		$table = Tables::ability_log();

		foreach ( array_intersect( self::LEGACY_LOG_COLUMNS, $this->ability_log_columns() ) as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture resetting the schema between tests.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, $column ) );
		}
	}

	/**
	 * Creates the ability log table with the full column set, and without the
	 * three retired in 1.4.0.
	 *
	 * @return void
	 */
	public function test_install_creates_ability_log_columns(): void {
		$columns = $this->ability_log_columns();

		foreach ( [ 'id', 'ability_name', 'user_id', 'created_at', 'status', 'error_code', 'error_message', 'failure_stage', 'duration_ms', 'user_agent', 'privacy_mode', 'input', 'output', 'client_id', 'client_name' ] as $column ) {
			$this->assertContains( $column, $columns, $column );
		}

		foreach ( self::LEGACY_LOG_COLUMNS as $column ) {
			$this->assertNotContains( $column, $columns, $column );
		}
	}

	/**
	 * Creates every OAuth table (each has an `id` column).
	 *
	 * @return void
	 */
	public function test_install_creates_oauth_tables(): void {
		global $wpdb;

		foreach ( Tables::oauth() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );

			$this->assertContains( 'id', $columns, $table );
		}
	}

	/**
	 * Creates the change journal's two tables, with the columns its recorder
	 * and prune rely on.
	 *
	 * @return void
	 */
	public function test_install_creates_change_journal_tables(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$changes = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::changes() ) );
		$items   = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::change_items() ) );
		// phpcs:enable

		foreach ( [ 'run_id', 'session_id', 'log_id', 'reason', 'other_tables', 'effects' ] as $column ) {
			$this->assertContains( $column, $changes );
		}
		foreach ( [ 'change_id', 'run_id', 'entity_hash', 'before_image', 'after_hashes', 'pruned', 'image_bytes' ] as $column ) {
			$this->assertContains( $column, $items );
		}
	}

	/**
	 * Uninstall leaves no change journal behind: its tables and its failure count.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_the_change_journal(): void {
		update_option( 'albert_undo_failures', [ 'count' => 1 ] );

		// The test suite turns table drops into temporary-table drops, so the
		// statement is what can be checked, not the table's absence.
		$queries = [];
		$watch   = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};

		add_filter( 'query', $watch );
		try {
			Installer::uninstall();
		} finally {
			remove_filter( 'query', $watch );
			Installer::install();
		}

		$this->assertFalse( (bool) get_option( 'albert_undo_failures' ) );
		foreach ( [ Tables::changes(), Tables::change_items() ] as $table ) {
			$this->assertNotEmpty( preg_grep( '/DROP\s+(?:TEMPORARY\s+)?TABLE\s+IF\s+EXISTS\s+`' . preg_quote( $table, '/' ) . '`/i', $queries ), $table );
		}
	}

	/**
	 * Creates the generic single-use token table with the full column set.
	 *
	 * @return void
	 */
	public function test_install_creates_single_use_tokens_columns(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Tables::single_use_tokens() ) );

		foreach ( [ 'id', 'token_hash', 'purpose', 'user_id', 'payload', 'expires_at', 'redeemed_at', 'created_at' ] as $column ) {
			$this->assertContains( $column, $columns, $column );
		}
	}

	/**
	 * Records the installed schema version in its option.
	 *
	 * @return void
	 */
	public function test_install_records_db_version_option(): void {
		$this->assertSame(
			(string) ALBERT_VERSION,
			get_option( Installer::VERSION_OPTION )
		);
	}

	/**
	 * Re-running install() preserves existing rows (idempotent).
	 *
	 * @return void
	 */
	public function test_install_is_idempotent(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			Tables::ability_log(),
			[
				'ability_name' => 'albert/survivor',
				'user_id'      => 1,
			],
			[ '%s', '%d' ]
		);
		$id_before = (int) $wpdb->insert_id;

		Installer::install();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				'albert/survivor'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $id_before, (int) $row->id );
	}

	/**
	 * A no-op once the stored version is current.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_is_noop_when_current(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			Tables::ability_log(),
			[
				'ability_name' => 'albert/keep',
				'user_id'      => 1,
			],
			[ '%s', '%d' ]
		);
		$id_before = (int) $wpdb->insert_id;

		Installer::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				'albert/keep'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $id_before, (int) $row->id );
	}

	/**
	 * Runs the schema build when the stored version is behind, advancing the
	 * version while preserving existing rows (additive migration).
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_triggers_when_behind(): void {
		global $wpdb;

		// Pretend the site is on an older plugin version.
		update_option( Installer::VERSION_OPTION, '0.0.0' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			Tables::ability_log(),
			[
				'ability_name' => 'albert/sentinel',
				'user_id'      => 1,
			],
			[ '%s', '%d' ]
		);
		$id_before = (int) $wpdb->insert_id;

		Installer::maybe_upgrade();

		// The version advanced to the running plugin version.
		$this->assertSame( (string) ALBERT_VERSION, get_option( Installer::VERSION_OPTION ) );

		// The additive migration preserved the existing row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				'albert/sentinel'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $id_before, (int) $row->id );
	}

	/**
	 * Upgrading from below 1.4.0 rewraps a flat `albert_allowed_users` array
	 * into the new per-entry shape, leaving every field `null` rather than
	 * inventing a value for any of them.
	 *
	 * Nothing is guessed: there is no record of when a legacy entry was
	 * actually added or whether it was ever exercised, and a fabricated
	 * `added_at` would be displayed on the row as a factual claim ("Added 2
	 * hours ago") about an account that may predate the feature by years.
	 * `expires_at` staying `null` is what exempts these entries from the
	 * sweep on its own, no fabricated `authorised_at` required: applying the
	 * new, tight default retroactively risks locking out a real connection
	 * within a day of an unrelated update, so only invitations granted after
	 * this upgrade go through the real expire-if-unused lifecycle.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_leaves_legacy_allowed_users_unlabelled_and_exempt(): void {
		$one = self::factory()->user->create();
		$two = self::factory()->user->create();

		// The pre-1.4.0 shape: a flat array of ints, no timestamps at all.
		update_option( AllowedUsers::OPTION, [ $one, $two ] );
		update_option( Installer::VERSION_OPTION, '0.0.0' );

		Installer::maybe_upgrade();

		$this->assertSame( [ $one, $two ], AllowedUsers::ids() );

		foreach ( [ $one, $two ] as $user_id ) {
			$this->assertNull( AllowedUsers::added_at( $user_id ) );
			$this->assertNull( AllowedUsers::authorised_at( $user_id ) );
			$this->assertFalse( AllowedUsers::has_authorised( $user_id ) );
			$this->assertNull( AllowedUsers::expires_at( $user_id ) );

			// Exempt for good via expires_at alone, still allowed regardless.
			$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		}
	}

	/**
	 * A partially-migrated entry (an interrupted upgrade, or one from an
	 * earlier development build of this shape) has its missing fields
	 * filled in without disturbing the ones already correct.
	 *
	 * @return void
	 */
	public function test_migration_fills_in_a_missing_field_on_a_partial_entry(): void {
		$user_id      = self::factory()->user->create();
		$added_moment = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// A partial entry: added_at present, authorised_at and expires_at missing.
		update_option( AllowedUsers::OPTION, [ $user_id => [ 'added_at' => $added_moment ] ] );
		update_option( Installer::VERSION_OPTION, '0.0.0' );

		Installer::maybe_upgrade();

		// The existing field survives untouched.
		$this->assertEqualsWithDelta( time() - DAY_IN_SECONDS, AllowedUsers::added_at( $user_id ), 5 );
		// The missing fields are filled in with safe defaults, not left to error.
		$this->assertNull( AllowedUsers::authorised_at( $user_id ) );
		$this->assertNull( AllowedUsers::expires_at( $user_id ) );
	}

	/**
	 * Re-running the migration (e.g. against a site already on the new shape)
	 * leaves existing entries untouched rather than resetting their clock.
	 *
	 * @return void
	 */
	public function test_allowed_users_migration_is_idempotent(): void {
		$user_id = self::factory()->user->create();

		update_option( Installer::VERSION_OPTION, '0.0.0' );
		Installer::maybe_upgrade();

		AllowedUsers::add( $user_id );
		$added_at = AllowedUsers::added_at( $user_id );

		// Pretend the site is behind again and re-run: the entry is already
		// the new shape, so it must be carried through unchanged.
		update_option( Installer::VERSION_OPTION, '0.0.0' );
		Installer::maybe_upgrade();

		$this->assertSame( $added_at, AllowedUsers::added_at( $user_id ) );
	}

	/**
	 * Upgrading from below 1.4.0 drops `ip_address`, `referrer` and
	 * `request_id` — which `dbDelta` alone never would, being additive — adds
	 * `failure_stage` and `privacy_mode`, and leaves every existing row in
	 * place. Columns go; history stays.
	 *
	 * @return void
	 */
	/**
	 * Activation across a version boundary still runs the data migrations.
	 *
	 * The path that mattered and was not covered: a site on 1.3.x is
	 * deactivated, the files are updated, and it is reactivated. Activation
	 * used to call install(), which stamps the schema version without running
	 * anything version-keyed, so the very next maybe_upgrade() saw "already
	 * current" and skipped the migrations permanently — with the option
	 * claiming the site was up to date and nothing left to notice it by.
	 *
	 * @return void
	 */
	public function test_activation_across_a_version_boundary_runs_the_migrations(): void {
		global $wpdb;

		$this->restore_legacy_log_columns();
		// Below both the 1.4.0 migration gate and ALBERT_VERSION itself. Seeding
		// the running version instead would make maybe_upgrade() return early on
		// 'already current' and never reach the migration under test.
		update_option( Installer::VERSION_OPTION, '1.3.0' );

		\Albert\Core\Plugin::activate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				Tables::ability_log()
			)
		);

		$this->assertNotContains( 'ip_address', $columns );
		$this->assertNotContains( 'referrer', $columns );
		$this->assertNotContains( 'request_id', $columns );
		$this->assertSame( (string) ALBERT_VERSION, get_option( Installer::VERSION_OPTION ) );
	}

	/**
	 * The version is not stamped until the migrations have actually run.
	 *
	 * Stamping first makes every failure permanent: the option says the work is
	 * done, so nothing tries again. This asserts the ordering directly — after
	 * an upgrade the columns are gone AND the version moved, never one without
	 * the other.
	 *
	 * @return void
	 */
	public function test_version_is_stamped_only_after_the_migrations_succeed(): void {
		global $wpdb;

		$this->restore_legacy_log_columns();
		// Below both the 1.4.0 migration gate and ALBERT_VERSION itself. Seeding
		// the running version instead would make maybe_upgrade() return early on
		// 'already current' and never reach the migration under test.
		update_option( Installer::VERSION_OPTION, '1.3.0' );

		Installer::maybe_upgrade();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				Tables::ability_log()
			)
		);

		$stamped = get_option( Installer::VERSION_OPTION );

		$this->assertNotContains( 'ip_address', $columns, 'Columns should be gone before the version is stamped.' );
		$this->assertSame( (string) ALBERT_VERSION, $stamped );
	}

	public function test_maybe_upgrade_drops_the_legacy_log_columns_and_keeps_the_rows(): void {
		global $wpdb;

		$this->restore_legacy_log_columns();

		// Seed rows that carry values in the doomed columns, so the assertion
		// is about surviving the drop rather than about an empty table.
		$ids = [];
		foreach ( [ 'albert/one', 'albert/two' ] as $ability ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
			$wpdb->insert(
				Tables::ability_log(),
				[
					'ability_name' => $ability,
					'user_id'      => 1,
					'ip_address'   => '203.0.113.7',
					'referrer'     => 'https://example.test/',
					'request_id'   => '11111111-2222-3333-4444-555555555555',
				],
				[ '%s', '%d', '%s', '%s', '%s' ]
			);
			$ids[ $ability ] = (int) $wpdb->insert_id;
		}

		update_option( Installer::VERSION_OPTION, '0.0.0' );

		Installer::maybe_upgrade();

		$columns = $this->ability_log_columns();

		foreach ( self::LEGACY_LOG_COLUMNS as $column ) {
			$this->assertNotContains( $column, $columns, $column );
		}

		foreach ( [ 'failure_stage', 'privacy_mode' ] as $column ) {
			$this->assertContains( $column, $columns, $column );
		}

		foreach ( $ids as $ability => $id_before ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE ability_name = %s',
					Tables::ability_log(),
					$ability
				)
			);

			$this->assertNotNull( $row, $ability );
			$this->assertSame( $id_before, (int) $row->id, $ability );
		}
	}

	/**
	 * The drop is safe from a half-applied state: with only one of the three
	 * legacy columns still present, the upgrade removes that one and does not
	 * error over the two that are already gone. MySQL 8 has no
	 * `DROP COLUMN IF EXISTS`, so this is the case the existence check exists
	 * for.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_handles_a_partially_dropped_log_table(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test fixture recreating an interrupted upgrade.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i text DEFAULT NULL', Tables::ability_log(), 'referrer' ) );

		update_option( Installer::VERSION_OPTION, '0.0.0' );

		Installer::maybe_upgrade();

		$this->assertNotContains( 'referrer', $this->ability_log_columns() );
	}

	/**
	 * Running the upgrade a second time, with the columns already dropped, is a
	 * silent no-op rather than an error on a missing column.
	 *
	 * @return void
	 */
	public function test_log_column_migration_is_idempotent(): void {
		global $wpdb;

		$this->restore_legacy_log_columns();

		update_option( Installer::VERSION_OPTION, '0.0.0' );
		Installer::maybe_upgrade();

		$wpdb->last_error = '';

		update_option( Installer::VERSION_OPTION, '0.0.0' );
		Installer::maybe_upgrade();

		$this->assertSame( '', (string) $wpdb->last_error );
		$this->assertNotContains( 'ip_address', $this->ability_log_columns() );
	}

	/**
	 * Uninstall removes the version option.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_version_option(): void {
		Installer::uninstall();

		$this->assertFalse( get_option( Installer::VERSION_OPTION ) );

		// Re-install for any downstream tests in the run.
		Installer::install();
	}

	/**
	 * After uninstall, install() re-creates the version option.
	 *
	 * @return void
	 */
	public function test_install_after_uninstall_recreates_option(): void {
		Installer::uninstall();
		$this->assertFalse( get_option( Installer::VERSION_OPTION ) );

		Installer::install();

		$this->assertSame( (string) ALBERT_VERSION, get_option( Installer::VERSION_OPTION ) );
	}
}
