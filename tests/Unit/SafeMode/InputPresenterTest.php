<?php
/**
 * Unit tests for staged-input presentation.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\SafeMode\InputPresenter;
use PHPUnit\Framework\TestCase;

/**
 * InputPresenter tests.
 *
 * @covers \Albert\SafeMode\InputPresenter
 */
class InputPresenterTest extends TestCase {

	/**
	 * Reset hook globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_filter_returns'] = [];
	}

	/**
	 * A secret-looking key is masked and an ordinary one is left alone.
	 *
	 * @return void
	 */
	public function test_masks_secret_looking_keys_only(): void {
		$shown = InputPresenter::for_display(
			[
				'title'    => 'Hello',
				'password' => 'hunter2',
			],
			'test/ability'
		);

		$this->assertSame( 'Hello', $shown['title'] );
		$this->assertSame( InputPresenter::MASK, $shown['password'] );
	}

	/**
	 * Masking reaches every depth, unlike the top-level output redaction.
	 *
	 * @return void
	 */
	public function test_masks_nested_values(): void {
		$shown = InputPresenter::for_display(
			[ 'auth' => [ 'api_key' => 'abc123' ] ],
			'test/ability'
		);

		$this->assertSame( InputPresenter::MASK, $shown['auth']['api_key'] );
	}

	/**
	 * Variant spellings are caught, because the match is a substring.
	 *
	 * @return void
	 */
	public function test_matches_variant_spellings(): void {
		$shown = InputPresenter::for_display(
			[
				'user_pass'     => 'a',
				'refreshToken'  => 'b',
				'client_secret' => 'c',
			],
			'test/ability'
		);

		foreach ( [ 'user_pass', 'refreshToken', 'client_secret' ] as $key ) {
			$this->assertSame( InputPresenter::MASK, $shown[ $key ], $key . ' should be masked.' );
		}
	}

	/**
	 * An empty value is left as it is, so the screen can still show it was blank.
	 *
	 * @return void
	 */
	public function test_leaves_empty_values_alone(): void {
		$shown = InputPresenter::for_display( [ 'password' => '' ], 'test/ability' );

		$this->assertSame( '', $shown['password'] );
	}

	/**
	 * A site can mask something Albert could never guess.
	 *
	 * @return void
	 */
	public function test_the_filter_can_mask_an_unguessable_key(): void {
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/display_input'] = [ 'wibble' => 'masked' ];

		$shown = InputPresenter::for_display( [ 'wibble' => 'a-credential' ], 'test/ability' );

		$this->assertSame( [ 'wibble' => 'masked' ], $shown );
	}
}
