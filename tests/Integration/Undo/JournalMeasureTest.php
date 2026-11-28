<?php
/**
 * Measures the change journal on a large block page.
 *
 * Skipped unless ALBERT_MEASURE=1:
 *
 *     ALBERT_MEASURE=1 vendor/bin/phpunit -c phpunit.xml.dist tests/Integration/Undo/JournalMeasureTest.php
 *
 * Prints time, peak memory and stored bytes for one recorded content edit on
 * a page of about 100 KB, and the R3 check: whether block hashes survive the
 * editor re-serialising the page, and how duplicate blocks behave.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Undo;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;
use Albert\Undo\BlockTree;
use Albert\Undo\Window;
use Albert\Undo\WindowContext;

/**
 * Journal measurements.
 *
 * @group measure
 * @coversNothing
 */
class JournalMeasureTest extends TestCase {

	/**
	 * Skip unless asked for, and start from empty journal tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( getenv( 'ALBERT_MEASURE' ) !== '1' ) {
			$this->markTestSkipped( 'Set ALBERT_MEASURE=1 to measure.' );
		}

		Installer::install();
		ConnectionContext::reset();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * One content edit on a 100 KB page, with and without a revision holding
	 * the old content.
	 *
	 * @return void
	 */
	public function test_a_content_edit_on_a_large_page(): void {
		$page = self::page( 100 * 1024 );

		foreach ( [
			'own copy'        => false,
			'revision-backed' => true,
		] as $label => $revised ) {
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'page',
					'post_content' => $revised ? 'Draft' : $page,
				]
			);
			if ( $revised ) {
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $page,
					]
				);
			}

			$edited = preg_replace( '/Paragraph 7 /', 'Paragraph seven, rewritten ', $page, 1 );

			memory_reset_peak_usage();
			$base  = memory_get_usage();
			$start = hrtime( true );

			Window::open( new WindowContext( WindowContext::SOURCE_MCP, 'albert/update-page', get_current_user_id(), 'client-measure' ) );
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $edited,
				]
			);
			$entry_id = Window::close();

			$total_ms = ( hrtime( true ) - $start ) / 1e6;
			$peak     = memory_get_peak_usage() - $base;

			// The same write without the journal, for the recording's own cost.
			memory_reset_peak_usage();
			$base_plain = memory_get_usage();
			$start      = hrtime( true );
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $page,
				]
			);
			$plain_ms   = ( hrtime( true ) - $start ) / 1e6;
			$plain_peak = memory_get_peak_usage() - $base_plain;

			$row = $this->item_row( (int) $entry_id, $post_id );
			$this->assertNotNull( $row );

			$blocks = count( BlockTree::hashes( BlockTree::of( $page ) ) );
			self::report(
				"Content edit, {$label}",
				[
					'page bytes'                   => strlen( $page ),
					'blocks (all levels)'          => $blocks,
					'time with journal (ms)'       => round( $total_ms, 1 ),
					'time without journal (ms)'    => round( $plain_ms, 1 ),
					'peak memory with journal'     => size_format( $peak, 1 ),
					'peak memory without journal'  => size_format( $plain_peak, 1 ),
					'before_image bytes'           => (int) $row['before_bytes'],
					'after_blocks bytes'           => (int) $row['blocks_bytes'],
					'after_preview bytes'          => (int) $row['preview_bytes'],
					'after_hashes bytes'           => (int) $row['hashes_bytes'],
					'content_revision_id set'      => $row['content_revision_id'] !== null ? 'yes' : 'no',
					'after_blocks bytes per block' => round( (int) $row['blocks_bytes'] / max( 1, $blocks ), 1 ),
				]
			);

			$this->assertSame( $revised, $row['content_revision_id'] !== null );
		}
	}

	/**
	 * R3: hashes survive a parse and serialize round trip and the editor's
	 * formatting; duplicate blocks share a hash and still diff as one change.
	 *
	 * @return void
	 */
	public function test_block_hashes_are_stable(): void {
		$page      = self::page( 100 * 1024 );
		$original  = BlockTree::hashes( BlockTree::of( $page ) );
		$roundtrip = BlockTree::hashes( BlockTree::of( serialize_blocks( parse_blocks( $page ) ) ) );

		// The editor puts inner blocks on their own lines and writes attributes in its own order.
		$formatted = str_replace( [ '-->', '<!-- /wp:' ], [ "-->\n", "\n<!-- /wp:" ], $page );
		$formatted = str_replace( '{"tagName":"section","layout":{"type":"constrained"}}', '{"layout":{"type":"constrained"},"tagName":"section"}', $formatted );
		$reformat  = BlockTree::hashes( BlockTree::of( $formatted ) );

		$counts     = array_count_values( $original );
		$duplicated = array_filter( $counts, static fn( $n ) => $n > 1 );

		// Edit one of two identical paragraphs: the multiset diff must find exactly one change.
		$twin      = '<!-- wp:paragraph --><p>Same words twice.</p><!-- /wp:paragraph -->';
		$before    = $page . $twin . $twin;
		$after     = $page . $twin . str_replace( 'twice', 'once', $twin );
		$remaining = array_count_values( BlockTree::hashes( BlockTree::of( $before ) ) );
		$changed   = 0;
		foreach ( BlockTree::hashes( BlockTree::of( $after ) ) as $hash ) {
			if ( ( $remaining[ $hash ] ?? 0 ) > 0 ) {
				--$remaining[ $hash ];
			} else {
				++$changed;
			}
		}

		self::report(
			'R3 hash stability',
			[
				'blocks'                                 => count( $original ),
				'unchanged after parse/serialize'        => self::same( $original, $roundtrip ),
				'unchanged after editor-style format'    => self::same( $original, $reformat ),
				'distinct hashes shared by 2+ blocks'    => count( $duplicated ),
				'blocks in those shared hashes'          => array_sum( $duplicated ),
				'blocks changed when one twin is edited' => $changed,
			]
		);

		$this->assertSame( $original, $roundtrip );
		$this->assertSame( $original, $reformat );
		$this->assertSame( 1, $changed );
	}

	/**
	 * A block page of at least `$bytes`, with nesting and repeated blocks the
	 * way real pages have them.
	 *
	 * @param int $bytes Target size.
	 *
	 * @return string
	 */
	private static function page( int $bytes ): string {
		$spacer = '<!-- wp:spacer {"height":"40px"} --><div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->';
		$parts  = [];
		$n      = 0;
		$size   = 0;

		while ( $size < $bytes ) {
			++$n;
			$text    = "Paragraph {$n} " . str_repeat( 'with some ordinary words about plans and prices ', 3 );
			$parts[] = '<!-- wp:heading --><h2 class="wp-block-heading">Section ' . $n . '</h2><!-- /wp:heading -->';
			$parts[] = '<!-- wp:group {"tagName":"section","layout":{"type":"constrained"}} --><section class="wp-block-group">'
				. '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->'
				. '<!-- wp:columns --><div class="wp-block-columns">'
				. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Left ' . $n . '</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
				. '<!-- wp:column --><div class="wp-block-column"><!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Item ' . $n . '</li><!-- /wp:list-item --></ul><!-- /wp:list --></div><!-- /wp:column -->'
				. '</div><!-- /wp:columns -->'
				. '</section><!-- /wp:group -->';
			$parts[] = $spacer;
			$size    = strlen( implode( "\n\n", $parts ) );
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * The stored sizes of one item.
	 *
	 * @param int $entry_id Entry.
	 * @param int $post_id  Post.
	 *
	 * @return array<string, mixed>|null
	 */
	private function item_row( int $entry_id, int $post_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Measurement read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT LENGTH(before_image) AS before_bytes, LENGTH(after_blocks) AS blocks_bytes, LENGTH(after_preview) AS preview_bytes, LENGTH(after_hashes) AS hashes_bytes, content_revision_id FROM %i WHERE change_id = %d AND entity_type = 'post' AND entity_key = %s",
				Tables::change_items(),
				$entry_id,
				(string) $post_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * How many hashes two lists share in place, as "n of m".
	 *
	 * @param array<int, string> $a One list.
	 * @param array<int, string> $b The other.
	 *
	 * @return string
	 */
	private static function same( array $a, array $b ): string {
		$same = count( array_filter( array_keys( $a ), static fn( $i ) => ( $b[ $i ] ?? null ) === $a[ $i ] ) );

		return "{$same} of " . count( $a );
	}

	/**
	 * Print a block of figures.
	 *
	 * @param string               $title   Heading.
	 * @param array<string, mixed> $figures Label => value.
	 *
	 * @return void
	 */
	private static function report( string $title, array $figures ): void {
		$lines = [ '', "== {$title} ==" ];
		foreach ( $figures as $label => $value ) {
			$lines[] = sprintf( '  %-40s %s', $label, (string) $value );
		}

		fwrite( STDERR, implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Measurement output.
	}
}
