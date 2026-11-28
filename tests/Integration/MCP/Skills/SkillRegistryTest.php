<?php
/**
 * Integration tests for the one skill registry and the folder format.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP\Skills;

use Albert\Context\SkillIndex;
use Albert\MCP\Skills\Skill;
use Albert\MCP\Skills\SkillCheck;
use Albert\MCP\Skills\SkillLoader;
use Albert\MCP\Skills\SkillRegistry;
use Albert\Tests\TestCase;

/**
 * Bundled folders, add-on registrations and 1.6.0 skill files all land in one registry,
 * and the prompts list is the index.
 *
 * @covers \Albert\MCP\Skills\SkillRegistry
 * @covers \Albert\MCP\Skills\SkillLoader
 * @covers \Albert\MCP\Skills\Skill
 */
class SkillRegistryTest extends TestCase {

	/**
	 * Files written by a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	/**
	 * Start from a fresh registry.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		SkillRegistry::reset_cache();
		SkillIndex::reset_cache();
	}

	/**
	 * Remove written files and reset the registry.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		SkillRegistry::reset_cache();
		SkillIndex::reset_cache();
		parent::tear_down();
	}

	/**
	 * Every bundled skill is a valid Agent Skills folder: named as its folder, described, with a body,
	 * known preconditions and links only to Albert abilities that exist.
	 *
	 * The entry-point test: a broken file fails here instead of quietly disappearing.
	 *
	 * @return void
	 */
	public function test_every_bundled_skill_is_valid(): void {
		$folders = glob( ALBERT_PLUGIN_DIR . 'skills/*', GLOB_ONLYDIR );
		$this->assertNotEmpty( $folders );
		$this->assertSame( [], glob( ALBERT_PLUGIN_DIR . 'skills/*.md' ), 'Skills live in folders.' );

		foreach ( $folders as $folder ) {
			$slug = basename( $folder );

			$this->assertSame( [], SkillCheck::problems( $folder . '/SKILL.md' ), $slug );
			$this->assertNotNull( SkillRegistry::get( $slug ), "{$slug}: not in the registry." );
		}
	}

	/**
	 * The check names what is wrong with a broken file.
	 *
	 * @return void
	 */
	public function test_the_check_names_problems(): void {
		$file = $this->write( "---\nname: Not Valid\nmetadata:\n  requires: nobody_defines_this\n  abilities: albert/does-not-exist\n---\n" );

		$problems = implode( ' ', SkillCheck::problems( $file ) );

		$this->assertStringContainsString( 'lowercase', $problems );
		$this->assertStringContainsString( 'no description', $problems );
		$this->assertStringContainsString( 'body is empty', $problems );
		$this->assertStringContainsString( 'nobody_defines_this', $problems );
		$this->assertStringContainsString( 'albert/does-not-exist', $problems );
	}

	/**
	 * A plugin can withhold a skill from a user or connection, never add one Albert does not offer.
	 *
	 * @return void
	 */
	public function test_the_available_filter_only_narrows(): void {
		add_filter( 'albert/skills/available', static fn( bool $available, $skill ): bool => $skill->slug() !== 'menus', 10, 2 );
		add_filter(
			'albert/skills/registry',
			static fn( array $skills ): array => $skills + [
				'unmet' => [
					'body'     => 'B',
					'requires' => 'nobody_defines_this',
				],
			]
		);
		add_filter( 'albert/skills/available', '__return_true', 20 );

		$available = SkillRegistry::available();

		$this->assertArrayNotHasKey( 'unmet', $available );
		$this->assertArrayHasKey( 'block-editor', $available );
	}

	/**
	 * Bundled skills carry their links from `metadata`.
	 *
	 * @return void
	 */
	public function test_bundled_skills_carry_their_links(): void {
		$this->assertContains( 'albert/find-menus', SkillRegistry::get( 'menus' )->abilities() );
		$this->assertContains( 'albert/edit-post-block', SkillRegistry::get( 'block-editor' )->abilities() );
	}

	/**
	 * The prompts list names exactly the skills the index names.
	 *
	 * @return void
	 */
	public function test_prompts_are_the_index(): void {
		$prompts = array_map( static fn( $prompt ): string => $prompt->get_name(), ( new SkillLoader() )->prompts() );
		$index   = array_column( SkillIndex::entries(), 'slug' );

		sort( $prompts );
		sort( $index );

		$this->assertNotEmpty( $prompts );
		$this->assertSame( $index, $prompts );
	}

	/**
	 * A registered skill is a prompt too, with its arguments.
	 *
	 * @return void
	 */
	public function test_a_registered_skill_is_a_prompt(): void {
		add_filter(
			'albert/skills/registry',
			static fn( array $skills ): array => $skills + [
				'shop-voice' => [
					'summary'   => 'How we write.',
					'body'      => 'Short sentences.',
					'arguments' => [ [ 'name' => 'topic' ] ],
				],
			]
		);

		$prompts = [];
		foreach ( ( new SkillLoader() )->prompts() as $prompt ) {
			$prompts[ $prompt->get_name() ] = $prompt;
		}

		$this->assertArrayHasKey( 'shop-voice', $prompts );
		$this->assertTrue( $prompts['shop-voice']->check_permission( [] ) );
		$this->assertSame( 'Short sentences.', $prompts['shop-voice']->execute( [] )['messages'][0]['content']['text'] );
		$this->assertSame( 'topic', $prompts['shop-voice']->get_protocol_record( \WP\McpSchema\Schemas::create()->forVersion( \WP\McpSchema\Schemas::V2025_11_25 ) )->getArguments()[0]->getName() );
	}

	/**
	 * A 1.6.0 skill file is read into the registry, with a deprecation notice.
	 *
	 * @return void
	 */
	public function test_a_legacy_skill_file_joins_the_registry(): void {
		$this->setExpectedDeprecated( 'albert/skills/files' );
		$file = $this->write( "---\nname: legacy-guide\ndescription: An old file.\n---\nOld body." );
		add_filter( 'albert/skills/files', static fn( array $files ): array => array_merge( $files, [ $file ] ) );

		$skill = SkillRegistry::get( 'legacy-guide' );

		$this->assertNotNull( $skill );
		$this->assertSame( 'Old body.', $skill->body() );
		$this->assertContains( 'legacy-guide', array_column( SkillIndex::entries(), 'slug' ) );
	}

	/**
	 * A file whose skill is also registered by slug is not deprecated and not doubled.
	 *
	 * @return void
	 */
	public function test_a_legacy_file_also_registered_is_quiet(): void {
		$file = $this->write( "---\nname: both-ways\n---\nFrom the file." );
		add_filter( 'albert/skills/files', static fn( array $files ): array => array_merge( $files, [ $file ] ) );
		add_filter(
			'albert/skills/registry',
			static fn( array $skills ): array => $skills + [
				'both-ways' => [
					'summary' => 'Registered.',
					'file'    => $file,
				],
			]
		);

		$this->assertSame( 'Registered.', SkillRegistry::get( 'both-ways' )->summary() );
		$this->assertCount( 1, array_filter( array_keys( SkillRegistry::all() ), static fn( $slug ): bool => $slug === 'both-ways' ) );
		// No deprecation is expected; WP_UnitTestCase fails the test on an unexpected one.
	}

	/**
	 * A file without a name is skipped.
	 *
	 * @return void
	 */
	public function test_a_nameless_file_is_skipped(): void {
		$before = count( SkillRegistry::all() );
		SkillRegistry::reset_cache();

		$file = $this->write( "---\ndescription: No name.\n---\nBody." );
		add_filter( 'albert/skills/files', static fn( array $files ): array => array_merge( $files, [ $file ] ) );

		$this->assertCount( $before, SkillRegistry::all() );
	}

	/**
	 * An oversize or empty-bodied file is skipped, and the rest still load.
	 *
	 * @return void
	 */
	public function test_oversize_and_empty_files_are_skipped(): void {
		$this->setExpectedDeprecated( 'albert/skills/files' );
		$big   = $this->write( "---\nname: too-big\n---\n" . str_repeat( 'x', Skill::MAX_BODY_BYTES + 1 ) );
		$empty = $this->write( "---\nname: no-body\n---\n   " );
		$good  = $this->write( "---\nname: fine\n---\nBody." );
		add_filter( 'albert/skills/files', static fn( array $files ): array => array_merge( $files, [ $big, $empty, $good ] ) );

		$this->assertNull( SkillRegistry::get( 'too-big' ) );
		$this->assertNull( SkillRegistry::get( 'no-body' ) );
		$this->assertNotNull( SkillRegistry::get( 'fine' ) );
	}

	/**
	 * An add-on's own condition works; an unknown one still fails closed; Albert's cannot be replaced.
	 *
	 * @return void
	 */
	public function test_add_on_conditions(): void {
		add_filter(
			'albert/skills/conditions',
			static fn( array $conditions ): array => $conditions + [
				'always_here' => [
					'check'       => '__return_true',
					'requirement' => 'Always',
					'active'      => 'Always here',
				],
				'woocommerce' => [ 'check' => '__return_true' ],
			]
		);
		add_filter(
			'albert/skills/registry',
			static fn( array $skills ): array => $skills + [
				'with-addon'   => [
					'body'     => 'B',
					'requires' => 'always_here',
				],
				'with-unknown' => [
					'body'     => 'B',
					'requires' => 'nobody_defines_this',
				],
				'with-woo'     => [
					'body'     => 'B',
					'requires' => 'woocommerce',
				],
			]
		);

		$this->assertTrue( SkillRegistry::get( 'with-addon' )->is_available() );
		$this->assertFalse( SkillRegistry::get( 'with-unknown' )->is_available() );
		$this->assertSame( class_exists( 'WooCommerce' ), SkillRegistry::get( 'with-woo' )->is_available() );
	}

	/**
	 * A skill linked only to abilities nobody can use is not offered; one usable link is enough.
	 *
	 * @return void
	 */
	public function test_links_decide_availability(): void {
		add_filter(
			'albert/skills/registry',
			static fn( array $skills ): array => $skills + [
				'dead-links' => [
					'body'      => 'B',
					'abilities' => 'albert/does-not-exist',
				],
				'one-live'   => [
					'body'      => 'B',
					'abilities' => [ 'albert/does-not-exist', 'albert/find-posts' ],
				],
			]
		);

		$this->assertFalse( SkillRegistry::get( 'dead-links' )->is_available() );
		$this->assertTrue( SkillRegistry::get( 'one-live' )->is_available() );
	}

	/**
	 * Write a temporary skill file.
	 *
	 * @param string $contents File contents.
	 *
	 * @return string Its path.
	 */
	private function write( string $contents ): string {
		$file = get_temp_dir() . 'albert-skill-' . wp_generate_password( 8, false ) . '.md';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file, $contents );
		$this->files[] = $file;

		return $file;
	}
}
