<?php
/**
 * Tests for the SkillLoader.
 *
 * @package Albert\Tests\Unit\MCP\Skills
 */

namespace Albert\Tests\Unit\MCP\Skills;

use Albert\MCP\Skills\SkillLoader;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\McpSchema\Schema;
use WP\McpSchema\Schemas;
use PHPUnit\Framework\TestCase;

// Load WordPress function/class stubs (apply_filters, WP_Error, __, etc.).
require_once dirname( __DIR__, 3 ) . '/Unit/stubs/wordpress.php';

/**
 * SkillLoader unit tests.
 *
 * Verifies skill files are turned into MCP prompts, the `albert/skills/files`
 * filter extends the file list, and invalid files are skipped without fatalling.
 */
class SkillLoaderTest extends TestCase {

	/**
	 * Directory holding temporary skill fixtures for a single test.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Reset hook state and create a temp dir for fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_filter_returns'] = [];

		$this->temp_dir = sys_get_temp_dir() . '/albert-skills-' . uniqid( '', true );
		mkdir( $this->temp_dir );
	}

	/**
	 * Remove the temp dir and its fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$files = glob( $this->temp_dir . '/*' );
		foreach ( is_array( $files ) ? $files : [] as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->temp_dir ) ) {
			rmdir( $this->temp_dir );
		}
		unset( $GLOBALS['albert_test_filter_returns'] );
		parent::tearDown();
	}

	/**
	 * The schema a prompt is projected through to read its protocol fields.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		return Schemas::create()->forVersion( Schemas::V2025_11_25 );
	}

	/**
	 * Write a fixture skill file and return its absolute path.
	 *
	 * @param string $name     The file base name (without extension).
	 * @param string $contents The file contents.
	 *
	 * @return string
	 */
	private function write_skill( string $name, string $contents ): string {
		$path = $this->temp_dir . '/' . $name . '.md';
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Point the `albert/skills/files` filter at the given file list.
	 *
	 * @param mixed $files Absolute file paths (or any value, to test fallback).
	 *
	 * @return void
	 */
	private function set_skill_files( mixed $files ): void {
		$GLOBALS['albert_test_filter_returns']['albert/skills/files'] = $files;
	}

	/**
	 * A valid skill file is built into an McpPrompt with the parsed name.
	 *
	 * @return void
	 */
	public function test_builds_prompt_from_skill_file(): void {
		$file = $this->write_skill(
			'block-editor',
			"---\nname: block-editor\ndescription: A playbook.\n---\nThe body."
		);
		$this->set_skill_files( [ $file ] );

		$prompts = ( new SkillLoader() )->prompts();

		$this->assertCount( 1, $prompts );
		$this->assertInstanceOf( McpPrompt::class, $prompts[0] );
		$this->assertSame( 'block-editor', $prompts[0]->get_name() );
		$this->assertSame( 'A playbook.', $prompts[0]->get_protocol_record( $this->schema() )->getDescription() );
	}

	/**
	 * The prompt handler returns the body as a single user text message.
	 *
	 * @return void
	 */
	public function test_handler_returns_body_in_message_shape(): void {
		$file = $this->write_skill(
			'block-editor',
			"---\nname: block-editor\n---\nThe playbook body."
		);
		$this->set_skill_files( [ $file ] );

		$prompts = ( new SkillLoader() )->prompts();
		$result  = $prompts[0]->execute( [] );

		$this->assertArrayHasKey( 'messages', $result );
		$this->assertCount( 1, $result['messages'] );
		$this->assertSame( 'user', $result['messages'][0]['role'] );
		$this->assertSame( 'text', $result['messages'][0]['content']['type'] );
		$this->assertSame( 'The playbook body.', $result['messages'][0]['content']['text'] );
	}

	/**
	 * The `albert/skills/files` filter can add external skill files.
	 *
	 * @return void
	 */
	public function test_filter_adds_external_skill_files(): void {
		$one = $this->write_skill( 'one', "---\nname: one\n---\nBody one." );
		$two = $this->write_skill( 'two', "---\nname: two\n---\nBody two." );
		$this->set_skill_files( [ $one, $two ] );

		$prompts = ( new SkillLoader() )->prompts();

		$names = array_map( static fn( McpPrompt $p ): string => $p->get_name(), $prompts );
		$this->assertSame( [ 'one', 'two' ], $names );
	}

	/**
	 * A file without a name in its frontmatter is skipped.
	 *
	 * @return void
	 */
	public function test_skips_file_without_name(): void {
		$valid    = $this->write_skill( 'valid', "---\nname: valid\n---\nBody." );
		$nameless = $this->write_skill( 'nameless', "---\ndescription: no name\n---\nBody." );
		$this->set_skill_files( [ $nameless, $valid ] );

		$prompts = ( new SkillLoader() )->prompts();

		$this->assertCount( 1, $prompts );
		$this->assertSame( 'valid', $prompts[0]->get_name() );
	}

	/**
	 * A missing file path is skipped without fatalling.
	 *
	 * @return void
	 */
	public function test_skips_missing_file(): void {
		$this->set_skill_files( [ $this->temp_dir . '/does-not-exist.md' ] );

		$prompts = ( new SkillLoader() )->prompts();

		$this->assertSame( [], $prompts );
	}

	/**
	 * Non-string filter entries are ignored.
	 *
	 * @return void
	 */
	public function test_ignores_non_string_filter_entries(): void {
		$valid = $this->write_skill( 'valid', "---\nname: valid\n---\nBody." );
		$this->set_skill_files( [ $valid, 42, null, [ 'nope' ] ] );

		$prompts = ( new SkillLoader() )->prompts();

		$this->assertCount( 1, $prompts );
		$this->assertSame( 'valid', $prompts[0]->get_name() );
	}

	/**
	 * Optional frontmatter arguments flow through to the prompt definition.
	 *
	 * @return void
	 */
	public function test_arguments_are_passed_to_prompt(): void {
		$file = $this->write_skill(
			'with-args',
			"---\nname: with-args\narguments: [{\"name\":\"slug\",\"required\":true}]\n---\nBody."
		);
		$this->set_skill_files( [ $file ] );

		$prompts   = ( new SkillLoader() )->prompts();
		$arguments = $prompts[0]->get_protocol_record( $this->schema() )->getArguments();

		$this->assertCount( 1, $arguments );
		$this->assertSame( 'slug', $arguments[0]->getName() );
	}

	/**
	 * A named skill with an empty body is skipped, not registered.
	 *
	 * @return void
	 */
	public function test_skips_named_skill_with_empty_body(): void {
		$empty = $this->write_skill( 'empty', "---\nname: empty\n---\n   \n" );
		$valid = $this->write_skill( 'valid', "---\nname: valid\n---\nBody." );
		$this->set_skill_files( [ $empty, $valid ] );

		$prompts = ( new SkillLoader() )->prompts();

		$this->assertCount( 1, $prompts );
		$this->assertSame( 'valid', $prompts[0]->get_name() );
	}

	/**
	 * The same absolute path passed twice yields a single prompt (dedup).
	 *
	 * @return void
	 */
	public function test_duplicate_paths_yield_single_prompt(): void {
		$file = $this->write_skill( 'dupe', "---\nname: dupe\n---\nBody." );
		$this->set_skill_files( [ $file, $file ] );

		$prompts = ( new SkillLoader() )->prompts();

		$this->assertCount( 1, $prompts );
		$this->assertSame( 'dupe', $prompts[0]->get_name() );
	}

	/**
	 * A non-array filter return falls back to the default skill list.
	 *
	 * With no bundled skills available in the unit context the default list is
	 * empty, so the loader yields no prompts — and crucially does not fatal.
	 *
	 * @return void
	 */
	public function test_non_array_filter_falls_back_to_default(): void {
		$this->set_skill_files( false );
		$this->assertSame( [], ( new SkillLoader() )->prompts() );

		$this->set_skill_files( 'not-an-array' );
		$this->assertSame( [], ( new SkillLoader() )->prompts() );
	}
}
