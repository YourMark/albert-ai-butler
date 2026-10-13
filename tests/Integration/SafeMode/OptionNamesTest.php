<?php
/**
 * Integration tests for comparing option names the way the database does.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\SafeMode\OptionNames;
use Albert\Tests\TestCase;

/**
 * OptionNames integration tests.
 *
 * Each spelling is checked against what MySQL actually does with it: the
 * matcher must call a name protected exactly when a lookup by that name finds
 * the protected row.
 *
 * @covers \Albert\SafeMode\OptionNames
 */
class OptionNamesTest extends TestCase {

	/**
	 * The option every spelling is compared to.
	 *
	 * @var string
	 */
	private const OPTION = 'albert_safe_mode';

	/**
	 * Store the option under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( self::OPTION, 'on' );
	}

	/**
	 * The spellings a database typically treats as the same name.
	 *
	 * @return array<string, array{string}>
	 */
	public static function lookalikes(): array {
		return [
			'upper case'         => [ 'ALBERT_SAFE_MODE' ],
			'mixed case'         => [ 'Albert_Safe_Mode' ],
			'accent'             => [ 'albért_safe_mode' ],
			'accents'            => [ 'àlbërt_sâfé_mödè' ],
			'full width'         => [ 'ａｌｂｅｒｔ＿ｓａｆｅ＿ｍｏｄｅ' ],
			'zero-width space'   => [ "albert\u{200B}_safe_mode" ],
			'soft hyphen'        => [ "albert\u{00AD}_safe_mode" ],
			'control character'  => [ "albert\x01_safe_mode" ],
			'no-break space end' => [ "albert_safe_mode\u{00A0}" ],
			'trailing space'     => [ 'albert_safe_mode ' ],
		];
	}

	/**
	 * A spelling that reaches the row is found, whatever the collation.
	 *
	 * @dataProvider lookalikes
	 *
	 * @param string $name The spelling.
	 *
	 * @return void
	 */
	public function test_a_spelling_is_matched_exactly_when_the_database_finds_the_row( string $name ): void {
		$this->assertSame(
			$this->reaches_the_row( $name ),
			( new OptionNames() )->find( $name, [ 'siteurl', self::OPTION ] ) === self::OPTION,
			json_encode( $name )
		);
	}

	/**
	 * Every character a name could carry, inserted into the option name: the
	 * matcher agrees with the database on each one. Covers the ignorable
	 * characters nobody thought to list.
	 *
	 * @return void
	 */
	public function test_the_matcher_agrees_with_the_database_for_every_inserted_character(): void {
		$names     = new OptionNames();
		$codes     = array_merge( range( 0x01, 0x7F ), range( 0xA0, 0x24F ), [ 0x034F, 0x200B, 0x200C, 0x200D, 0x2060, 0xFEFF, 0xFF3F ] );
		$disagreed = [];

		foreach ( $codes as $code ) {
			$name = 'albert' . mb_chr( $code, 'UTF-8' ) . '_safe_mode';

			if ( $this->reaches_the_row( $name ) !== ( $names->find( $name, [ self::OPTION ] ) === self::OPTION ) ) {
				$disagreed[] = sprintf( 'U+%04X', $code );
			}
		}

		$this->assertSame( [], $disagreed );
	}

	/**
	 * The typical collation does treat these as the protected name, so the
	 * tests above are not passing because nothing matched.
	 *
	 * @return void
	 */
	public function test_the_test_database_matches_lookalikes(): void {
		foreach ( [ 'ALBERT_SAFE_MODE', 'albért_safe_mode', "albert\u{200B}_safe_mode" ] as $name ) {
			$this->assertTrue( $this->reaches_the_row( $name ), json_encode( $name ) );
		}
	}

	/**
	 * `ß` and `ss` are one name to the database.
	 *
	 * @return void
	 */
	public function test_sharp_s_matches_double_s(): void {
		$this->assertSame( 'albert_pass_test', ( new OptionNames() )->find( 'albert_paß_test', [ 'albert_pass_test' ] ) );
	}

	/**
	 * Different names stay different.
	 *
	 * @return void
	 */
	public function test_a_different_name_is_not_matched(): void {
		$names = new OptionNames();

		$this->assertNull( $names->find( 'albert_safe_mode_2', [ self::OPTION ] ) );
		$this->assertNull( $names->find( 'albert-safe-mode', [ self::OPTION ] ) );
		$this->assertNull( $names->find( 'blogname', [ self::OPTION, 'siteurl' ] ) );
	}

	/**
	 * Whether a lookup by this name finds the stored option.
	 *
	 * @param string $name The spelling.
	 *
	 * @return bool
	 */
	private function reaches_the_row( string $name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The database's own answer is the point.
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s', $wpdb->options, $name ) );

		return $found === self::OPTION;
	}
}
