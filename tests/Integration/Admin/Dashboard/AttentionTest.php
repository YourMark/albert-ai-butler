<?php
/**
 * Integration tests for the Dashboard's attention items.
 *
 * The card holds standing conditions: things that are true right now and have
 * an action that resolves them. It used to also report any ability whose last
 * run had failed, which on a real install meant red rows about one-off
 * experiments from months earlier — and, more to the point, restated what the
 * activity log directly beneath it already said. Failures are the log's job;
 * this card's job is the things the log will never mention.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin\Dashboard;

use Albert\Admin\Dashboard\Attention;
use Albert\Tests\TestCase;

/**
 * Attention item tests.
 *
 * @covers \Albert\Admin\Dashboard\Attention
 */
class AttentionTest extends TestCase {

	/**
	 * Dismissal is per user and per finding, and a consequential item cannot
	 * be dismissed at all.
	 *
	 * @return void
	 */
	public function test_dismissal_is_scoped_to_the_finding(): void {
		$this->assertTrue( Attention::is_dismissible( [ 'dismissible' => true ] ) );
		$this->assertFalse( Attention::is_dismissible( [ 'dismissible' => false ] ) );
		$this->assertFalse( Attention::is_dismissible( [] ), 'Anything that does not say so is not dismissible.' );

		// A contributed item, since the card no longer sources anything from
		// the log: dismissal scoping is about ids, not about where they came
		// from.
		$this->add_item( 'scoped-thing' );

		$this->assertContains( 'scoped-thing', $this->item_ids() );

		// Dismissing a different finding must not hide this one.
		Attention::dismiss( 'some-other-finding', 1 );
		$this->assertContains( 'scoped-thing', $this->item_ids() );

		Attention::dismiss( 'scoped-thing', 1 );
		$this->assertNotContains( 'scoped-thing', $this->item_ids() );

		delete_user_meta( 1, Attention::DISMISSED_META );
		remove_all_filters( 'albert/dashboard/attention' );
	}

	/**
	 * Plain permalinks are flagged, and cannot be dismissed; any structure clears it.
	 *
	 * @return void
	 */
	public function test_plain_permalinks_are_flagged(): void {
		$original = get_option( 'permalink_structure' );

		update_option( 'permalink_structure', '' );
		$items = array_column( ( new Attention() )->items( 1 ), null, 'id' );

		$this->assertArrayHasKey( 'permalinks-plain', $items );
		$this->assertFalse( Attention::is_dismissible( $items['permalinks-plain'] ) );

		update_option( 'permalink_structure', '/index.php/%postname%/' );
		$this->assertNotContains( 'permalinks-plain', $this->item_ids() );

		update_option( 'permalink_structure', $original );
	}

	/**
	 * Add-ons can contribute, and the filter runs before dismissal is applied
	 * so their items can be dismissed too.
	 *
	 * @return void
	 */
	public function test_addons_can_contribute_items(): void {
		add_filter(
			'albert/dashboard/attention',
			static function ( array $items ): array {
				$items[] = [
					'id'          => 'addon-thing',
					'tone'        => 'warning',
					'title'       => 'An add-on noticed something',
					'dismissible' => true,
				];

				return $items;
			}
		);

		$ids = array_column( ( new Attention() )->items( 1 ), 'id' );
		$this->assertContains( 'addon-thing', $ids );

		Attention::dismiss( 'addon-thing', 1 );

		$ids = array_column( ( new Attention() )->items( 1 ), 'id' );
		$this->assertNotContains( 'addon-thing', $ids );

		delete_user_meta( 1, Attention::DISMISSED_META );
		remove_all_filters( 'albert/dashboard/attention' );
	}

	/**
	 * A dismissal lapses, so a condition still true months later is said again.
	 *
	 * The card's contract is that an item is a condition that is *still true*.
	 * A dismissal that never expired turned one click into a permanent blind
	 * spot: the same finding, arising again a year later, stayed silent.
	 *
	 * @return void
	 */
	public function test_a_dismissal_lapses(): void {
		$this->add_item( 'lapsing-thing' );

		Attention::dismiss( 'lapsing-thing', 1 );
		$this->assertNotContains( 'lapsing-thing', $this->item_ids() );

		// Age the record past the window rather than waiting out a season.
		update_user_meta( 1, Attention::DISMISSED_META, [ 'lapsing-thing' => time() - ( 400 * DAY_IN_SECONDS ) ] );

		$this->assertContains( 'lapsing-thing', $this->item_ids() );

		delete_user_meta( 1, Attention::DISMISSED_META );
		remove_all_filters( 'albert/dashboard/attention' );
	}

	/**
	 * Dismissing prunes what has already lapsed, so the meta cannot grow
	 * without bound on a busy site.
	 *
	 * @return void
	 */
	public function test_dismissing_prunes_lapsed_records(): void {
		update_user_meta(
			1,
			Attention::DISMISSED_META,
			[
				'ancient' => time() - ( 400 * DAY_IN_SECONDS ),
				'recent'  => time(),
			]
		);

		Attention::dismiss( 'new-one', 1 );

		$stored = get_user_meta( 1, Attention::DISMISSED_META, true );

		$this->assertArrayNotHasKey( 'ancient', $stored );
		$this->assertArrayHasKey( 'recent', $stored );
		$this->assertArrayHasKey( 'new-one', $stored );

		delete_user_meta( 1, Attention::DISMISSED_META );
	}

	/**
	 * An item with a tone this class does not know sorts last, not first.
	 *
	 * `array_search()` returns false on a miss and `(int) false` is 0, which is
	 * `danger`'s own rank, so a typo'd tone jumped the queue while the renderer
	 * drew it as `info`. The rank and the rendering have to agree.
	 *
	 * @return void
	 */
	public function test_an_unknown_tone_sorts_last(): void {
		add_filter(
			'albert/dashboard/attention',
			static function ( array $items ): array {
				$items[] = [
					'id'    => 'mystery-tone',
					'tone'  => 'bananas',
					'title' => 'Tone nobody declared',
				];
				$items[] = [
					'id'    => 'plain-info',
					'tone'  => 'info',
					'title' => 'Ordinary information',
				];

				return $items;
			}
		);

		$ids = $this->item_ids();

		$this->assertGreaterThan(
			array_search( 'plain-info', $ids, true ),
			array_search( 'mystery-tone', $ids, true ),
			'An unrecognised tone must not outrank a real one.'
		);

		remove_all_filters( 'albert/dashboard/attention' );
	}

	/**
	 * Register one add-on item, dismissible, so a test has something to act on.
	 *
	 * @param string $id Item id.
	 *
	 * @return void
	 */
	private function add_item( string $id ): void {
		add_filter(
			'albert/dashboard/attention',
			static function ( array $items ) use ( $id ): array {
				$items[] = [
					'id'          => $id,
					'tone'        => 'info',
					'title'       => 'Something to dismiss',
					'dismissible' => true,
				];

				return $items;
			}
		);
	}

	/**
	 * The ids currently on the card for user 1.
	 *
	 * @return array<int, string>
	 */
	private function item_ids(): array {
		return array_column( ( new Attention() )->items( 1 ), 'id' );
	}
}
