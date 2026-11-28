<?php
/**
 * Unit tests for the optional `note` input on write abilities.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Abstracts;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Abstracts\BaseAbility;
use PHPUnit\Framework\TestCase;

/**
 * Note input tests.
 *
 * @covers \Albert\Abstracts\BaseAbility
 */
class NoteInputTest extends TestCase {

	/**
	 * Reset globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']                = [];
		$GLOBALS['albert_test_user_id']              = 1;
		$GLOBALS['albert_test_options']              = [
			'albert_abilities_saved'    => true,
			'albert_disabled_abilities' => [],
		];
		$GLOBALS['albert_test_registered_abilities'] = [];
	}

	/**
	 * A write ability is offered `note`, a plain string of at most 140 characters.
	 *
	 * @return void
	 */
	public function test_a_write_ability_is_offered_a_note(): void {
		$schema = $this->registered( new NoteFixture( 'albert/note-write', [ 'readonly' => false ] ) );

		$this->assertSame( 'string', $schema['properties']['note']['type'] );
		$this->assertSame( 140, $schema['properties']['note']['maxLength'] );
		$this->assertArrayHasKey( 'title', $schema['properties'], 'Its own input is kept.' );
	}

	/**
	 * A read-only ability, or one that opted out, is not.
	 *
	 * @return void
	 */
	public function test_reads_and_opted_out_abilities_are_not(): void {
		$read   = $this->registered( new NoteFixture( 'albert/note-read', [ 'readonly' => true ] ) );
		$opted  = $this->registered( new NoteFixture( 'albert/note-opted', [ 'readonly' => false ], false ) );
		$custom = $this->registered( new NoteFixture( 'albert/note-own', [ 'readonly' => false ], true, true ) );

		$this->assertArrayNotHasKey( 'note', $read['properties'] );
		$this->assertArrayNotHasKey( 'note', $opted['properties'] );
		$this->assertSame( 'integer', $custom['properties']['note']['type'], 'An ability\'s own `note` is left alone.' );
	}

	/**
	 * The ability never sees the note; an ability's own `note` reaches it.
	 *
	 * @return void
	 */
	public function test_the_note_is_stripped_before_execute(): void {
		$write = new NoteFixture( 'albert/note-write', [ 'readonly' => false ] );
		$own   = new NoteFixture( 'albert/note-own', [ 'readonly' => false ], true, true );
		$write->register_ability();
		$own->register_ability();

		$write->guarded_execute(
			[
				'title' => 'A',
				'note'  => 'Why',
			]
		);
		$own->guarded_execute( [ 'note' => 3 ] );

		$this->assertSame( [ 'title' => 'A' ], $write->received );
		$this->assertSame( [ 'note' => 3 ], $own->received );
	}

	/**
	 * The schema an ability registered.
	 *
	 * @param BaseAbility $ability The ability.
	 *
	 * @return array<string, mixed>
	 */
	private function registered( BaseAbility $ability ): array {
		$ability->register_ability();

		return $GLOBALS['albert_test_registered_abilities'][ $ability->get_id() ]['input_schema'];
	}
}

/**
 * An ability with configurable annotations and note handling.
 */
final class NoteFixture extends BaseAbility {

	/**
	 * The input execute() received.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $received = null;

	/**
	 * Constructor.
	 *
	 * @param string               $id           Ability id.
	 * @param array<string, mixed> $annotations  Annotations.
	 * @param bool                 $accepts_note Whether it takes the note.
	 * @param bool                 $own_note     Whether it declares a `note` of its own.
	 */
	public function __construct( string $id, array $annotations, bool $accepts_note = true, bool $own_note = false ) {
		$this->id           = $id;
		$this->label        = 'Note fixture';
		$this->description  = 'Fixture.';
		$this->category     = 'test';
		$this->meta         = [ 'annotations' => $annotations ];
		$this->accepts_note = $accepts_note;
		$this->input_schema = [
			'type'       => 'object',
			'properties' => $own_note ? [ 'note' => [ 'type' => 'integer' ] ] : [ 'title' => [ 'type' => 'string' ] ],
		];

		parent::__construct();
	}

	/**
	 * Remember the input.
	 *
	 * @param array<string, mixed> $args Input.
	 *
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array {
		$this->received = $args;

		return [ 'ok' => true ];
	}

	/**
	 * Always allowed.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return true;
	}
}
