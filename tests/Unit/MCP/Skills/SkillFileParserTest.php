<?php
/**
 * Tests for the SkillFileParser.
 *
 * @package Albert\Tests\Unit\MCP\Skills
 */

namespace Albert\Tests\Unit\MCP\Skills;

use Albert\MCP\Skills\SkillFileParser;
use PHPUnit\Framework\TestCase;

/**
 * SkillFileParser unit tests.
 *
 * Verifies the parser splits a skill file into its frontmatter fields and body.
 */
class SkillFileParserTest extends TestCase {

	/**
	 * Parser instance under test.
	 *
	 * @var SkillFileParser
	 */
	private SkillFileParser $parser;

	/**
	 * Set up a fresh parser for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new SkillFileParser();
	}

	/**
	 * Frontmatter name and description are parsed and the body is returned.
	 *
	 * @return void
	 */
	public function test_parses_name_description_and_body(): void {
		$contents = "---\nname: block-editor\ndescription: A playbook.\n---\n# Body heading\n\nSome content.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'block-editor', $result['name'] );
		$this->assertSame( 'A playbook.', $result['description'] );
		$this->assertSame( "# Body heading\n\nSome content.", $result['body'] );
		$this->assertSame( [], $result['arguments'] );
	}

	/**
	 * Quoted frontmatter values have their surrounding quotes stripped.
	 *
	 * @return void
	 */
	public function test_strips_surrounding_quotes(): void {
		$contents = "---\nname: \"block-editor\"\ndescription: 'Quoted desc.'\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'block-editor', $result['name'] );
		$this->assertSame( 'Quoted desc.', $result['description'] );
	}

	/**
	 * Carriage-return line endings are normalised before parsing.
	 *
	 * @return void
	 */
	public function test_normalises_crlf_line_endings(): void {
		$contents = "---\r\nname: skill\r\ndescription: Desc.\r\n---\r\nBody line.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'skill', $result['name'] );
		$this->assertSame( 'Body line.', $result['body'] );
	}

	/**
	 * Files with no frontmatter fence return the whole input as the body.
	 *
	 * @return void
	 */
	public function test_no_frontmatter_returns_body_only(): void {
		$contents = 'Just a plain body with no fence.';

		$result = $this->parser->parse( $contents );

		$this->assertNull( $result['name'] );
		$this->assertNull( $result['description'] );
		$this->assertSame( 'Just a plain body with no fence.', $result['body'] );
	}

	/**
	 * Inline JSON arguments are parsed into a list of definitions.
	 *
	 * @return void
	 */
	public function test_parses_inline_json_arguments(): void {
		$contents = "---\nname: skill\narguments: [{\"name\":\"slug\",\"required\":true}]\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertCount( 1, $result['arguments'] );
		$this->assertSame( 'slug', $result['arguments'][0]['name'] );
		$this->assertTrue( $result['arguments'][0]['required'] );
	}

	/**
	 * Malformed argument values are ignored rather than fatalling.
	 *
	 * @return void
	 */
	public function test_invalid_arguments_yield_empty_list(): void {
		$contents = "---\nname: skill\narguments: not-json\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( [], $result['arguments'] );
	}

	/**
	 * Argument entries without a name are dropped.
	 *
	 * @return void
	 */
	public function test_arguments_without_name_are_dropped(): void {
		$contents = "---\nname: skill\narguments: [{\"description\":\"no name\"},{\"name\":\"ok\"}]\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertCount( 1, $result['arguments'] );
		$this->assertSame( 'ok', $result['arguments'][0]['name'] );
	}

	/**
	 * Comment and blank lines inside the frontmatter are ignored.
	 *
	 * @return void
	 */
	public function test_ignores_comments_and_blank_lines(): void {
		$contents = "---\n# a comment\n\nname: skill\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'skill', $result['name'] );
	}

	/**
	 * Trailing whitespace on the opening fence still parses the frontmatter.
	 *
	 * @return void
	 */
	public function test_tolerates_trailing_space_on_opening_fence(): void {
		$contents = "--- \nname: skill\ndescription: Desc.\n---\nBody.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'skill', $result['name'] );
		$this->assertSame( 'Desc.', $result['description'] );
		$this->assertSame( 'Body.', $result['body'] );
	}

	/**
	 * A `---` line inside the body does not truncate the frontmatter or body.
	 *
	 * @return void
	 */
	public function test_dashes_in_body_keep_full_body(): void {
		$contents = "---\nname: skill\n---\nIntro line.\n\n---\n\nAfter the rule.";

		$result = $this->parser->parse( $contents );

		$this->assertSame( 'skill', $result['name'] );
		$this->assertSame( "Intro line.\n\n---\n\nAfter the rule.", $result['body'] );
	}

	/**
	 * Albert's keys under `metadata`, as space-separated strings.
	 *
	 * @return void
	 */
	public function test_reads_metadata_strings(): void {
		$result = $this->parser->parse( "---\nname: alt\ndescription: Alt text.\nmetadata:\n  requires: \"woocommerce block_editor\"\n  abilities: \"albert/update-media albert/find-media\"\n  priority: \"5\"\n---\nBody." );

		$this->assertSame( [ 'woocommerce', 'block_editor' ], $result['requires'] );
		$this->assertSame( [ 'albert/update-media', 'albert/find-media' ], $result['abilities'] );
		$this->assertSame( 5, $result['priority'] );
		$this->assertSame( 'Body.', $result['body'] );
	}

	/**
	 * The same keys written as YAML lists.
	 *
	 * @return void
	 */
	public function test_reads_metadata_lists(): void {
		$result = $this->parser->parse( "---\nname: alt\nmetadata:\n  abilities:\n    - albert/update-media\n    - \"albert/find-media\"\n  requires:\n    - woocommerce\n---\nBody." );

		$this->assertSame( [ 'albert/update-media', 'albert/find-media' ], $result['abilities'] );
		$this->assertSame( [ 'woocommerce' ], $result['requires'] );
		$this->assertNull( $result['priority'] );
	}

	/**
	 * A top-level `requires`, as 1.6.0 files wrote it, still works; a metadata one wins.
	 *
	 * @return void
	 */
	public function test_top_level_requires_still_works(): void {
		$this->assertSame( [ 'woocommerce', 'multisite' ], $this->parser->parse( "---\nname: a\nrequires: WooCommerce, multisite\n---\nB" )['requires'] );
		$this->assertSame( [ 'block_editor' ], $this->parser->parse( "---\nname: a\nrequires: woocommerce\nmetadata:\n  requires: block_editor\n---\nB" )['requires'] );
	}

	/**
	 * Keys Albert does not know, like `allowed-tools`, grant nothing and are ignored.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_ignored(): void {
		$result = $this->parser->parse( "---\nname: a\nallowed-tools: Bash(rm:*)\nlicense: MIT\nmetadata:\n  author: someone\n---\nB" );

		$this->assertSame( 'a', $result['name'] );
		$this->assertSame( [], $result['abilities'] );
		$this->assertArrayNotHasKey( 'allowed-tools', $result );
	}

	/**
	 * A list at the start of the line is a list too, so a precondition is never lost to indentation.
	 *
	 * @return void
	 */
	public function test_reads_an_unindented_list(): void {
		$this->assertSame( [ 'woocommerce' ], $this->parser->parse( "---\nname: a\nrequires:\n- woocommerce\n---\nB" )['requires'] );
	}

	/**
	 * A trailing comment is not read as values; a hash inside quotes is kept.
	 *
	 * @return void
	 */
	public function test_trailing_comments_are_dropped(): void {
		$result = $this->parser->parse( "---\nname: a # the slug\ndescription: \"Use # signs\"\nmetadata:\n  requires: woocommerce # needs a shop\n---\nB" );

		$this->assertSame( 'a', $result['name'] );
		$this->assertSame( 'Use # signs', $result['description'] );
		$this->assertSame( [ 'woocommerce' ], $result['requires'] );
	}
}
