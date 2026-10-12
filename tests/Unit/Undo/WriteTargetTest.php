<?php
/**
 * Unit tests for the change journal's table check.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Undo;

use Albert\Undo\Coverage\WriteTarget;
use PHPUnit\Framework\TestCase;

/**
 * WriteTarget tests.
 *
 * @covers \Albert\Undo\Coverage\WriteTarget
 */
class WriteTargetTest extends TestCase {

	/**
	 * What wpdb's own helpers produce is read exactly.
	 *
	 * @return void
	 */
	public function test_reads_the_table_of_ordinary_writes(): void {
		$this->assertWrite( 'wp_posts', "INSERT INTO `wp_posts` (`post_title`) VALUES ('a')" );
		$this->assertWrite( 'wp_options', "UPDATE `wp_options` SET `option_value` = 'x' WHERE `option_name` = 'y'" );
		$this->assertWrite( 'wp_postmeta', 'DELETE FROM `wp_postmeta` WHERE `meta_id` IN (1,2)' );
		$this->assertWrite( 'wp_options', "REPLACE INTO wp_options (option_name) VALUES ('a')" );
		$this->assertWrite( 'wp_options', "INSERT IGNORE INTO wp_options (option_name) VALUES ('a') ON DUPLICATE KEY UPDATE option_value = 'b'" );
		$this->assertWrite( 'wp_posts', 'UPDATE LOW_PRIORITY wp_posts p SET p.post_status = 1' );
		$this->assertWrite( 'wp_posts', 'DELETE FROM wp_posts AS p WHERE p.ID = 1' );
		$this->assertWrite( 'wp_posts', "UPDATE `wp_posts` SET `post_content` = '/*! banner */ a{}' WHERE `ID` = 1" );
	}

	/**
	 * Reads are left alone, whatever comments come first.
	 *
	 * @return void
	 */
	public function test_reads_are_reads(): void {
		foreach ( [ 'SELECT * FROM wp_posts', "  \n(SELECT 1) UNION (SELECT 2)", 'SHOW TABLES', 'SET NAMES utf8mb4', 'START TRANSACTION', 'ROLLBACK', '/* note */ SELECT 1', "-- note\nSELECT 1", "# note\nSELECT 1", '' ] as $sql ) {
			$this->assertSame( WriteTarget::READ, WriteTarget::of( $sql )->kind, $sql );
		}
	}

	/**
	 * A comment in front of a write does not hide it.
	 *
	 * @return void
	 */
	public function test_leading_comments_do_not_hide_a_write(): void {
		$this->assertWrite( 'acme_log', '/* x */ INSERT INTO acme_log (note) VALUES (1)' );
		$this->assertWrite( 'acme_log', "--\tnote\nINSERT INTO acme_log (note) VALUES (1)" );
		$this->assertWrite( 'acme_log', "# note\nUPDATE acme_log SET note = 1" );
	}

	/**
	 * Anything it cannot read exactly is unreadable, never a read.
	 *
	 * @return void
	 */
	public function test_what_it_cannot_read_is_unreadable(): void {
		$cases = [
			'/*!50000 UPDATE wp_options SET option_value = 1 */',
			'SELECT 1 /*! ; DELETE FROM wp_posts */',
			'/*M!100100 DELETE FROM wp_posts */',
			"UPDATE wp_posts SET post_title = 'it\\' /*! x */",
			'INSERT INTO other_db.wp_posts (ID) VALUES (1)',
			'WITH x AS (SELECT 1) DELETE FROM wp_posts',
			'CALL acme_cleanup()',
			"LOAD DATA INFILE 'x' INTO TABLE wp_posts",
			'--x INSERT INTO acme_log VALUES (1)',
			'/* never closed INSERT INTO acme_log VALUES (1)',
			'UPDATE wp_posts, wp_postmeta SET wp_posts.post_title = 1',
			'UPDATE wp_posts JOIN wp_postmeta ON 1 SET post_title = 1',
			'DELETE p FROM wp_posts p JOIN wp_postmeta m ON 1',
			'DELETE FROM wp_posts USING wp_posts JOIN wp_postmeta',
			'DELETE FROM wp_posts, wp_postmeta',
			'HANDLER wp_posts OPEN',
		];

		foreach ( $cases as $sql ) {
			$this->assertSame( WriteTarget::UNREADABLE, WriteTarget::of( $sql )->kind, $sql );
		}
	}

	/**
	 * Structure changes are writes, and say so.
	 *
	 * @return void
	 */
	public function test_structure_changes_name_their_table(): void {
		foreach ( [ 'TRUNCATE TABLE acme_log', 'ALTER TABLE `wp_posts` ADD x int', 'DROP TABLE IF EXISTS acme_log', 'CREATE TABLE IF NOT EXISTS acme_log (id int)' ] as $sql ) {
			$target = WriteTarget::of( $sql );
			$this->assertTrue( $target->is_write(), $sql );
			$this->assertTrue( $target->structure, $sql );
			$this->assertNotNull( $target->table, $sql );
		}
	}

	/**
	 * Assert a query is a plain write to a table.
	 *
	 * @param string $table Expected table.
	 * @param string $sql   Query.
	 *
	 * @return void
	 */
	private function assertWrite( string $table, string $sql ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors PHPUnit's assert naming.
		$target = WriteTarget::of( $sql );

		$this->assertSame( WriteTarget::WRITE, $target->kind, $sql );
		$this->assertSame( $table, $target->table, $sql );
		$this->assertFalse( $target->structure, $sql );
	}
}
