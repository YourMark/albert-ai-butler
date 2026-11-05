<?php
/**
 * View Site Health Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\SiteHealth
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\SiteHealth;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_Site_Health;

defined( 'ABSPATH' ) || exit;

/**
 * Runs core's direct Site Health tests the way the Site Health screen does, and lists the ones that need the browser as not run.
 *
 * @since 1.6.0
 */
class ViewSiteHealth extends BaseAbility {

	/**
	 * Order the screen groups results in.
	 *
	 * @since 1.6.0
	 */
	private const STATUS_ORDER = [
		'critical'    => 0,
		'recommended' => 1,
		'good'        => 2,
	];

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-site-health';
		$this->label       = __( 'View Site Health', 'albert-ai-butler' );
		$this->description = __( 'Run WordPress\'s Site Health checks and report each result as the Site Health screen shows it: status (critical, recommended or good), label, category and explanation. Checks that need a background request or the browser\'s login are listed as not run.', 'albert-ai-butler' );
		$this->category    = 'maintenance';
		$this->group       = 'site-health';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Report the checks listed in not_run as not checked; never guess their result. The owner can run them on the Site Health screen (site_health_url).'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'include_passed' => [
					'type'        => 'boolean',
					'description' => 'Include the checks that passed. Set false to get only what needs attention.',
					'default'     => true,
				],
			],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'counts'          => [
					'type'       => 'object',
					'properties' => [
						'critical'    => [ 'type' => 'integer' ],
						'recommended' => [ 'type' => 'integer' ],
						'good'        => [ 'type' => 'integer' ],
					],
				],
				'results'         => [
					'type'        => 'array',
					'description' => 'Critical first, then recommended, then good, as on the Site Health screen.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'test'        => [ 'type' => 'string' ],
							'status'      => [
								'type' => 'string',
								'enum' => [ 'critical', 'recommended', 'good' ],
							],
							'label'       => [ 'type' => 'string' ],
							'badge'       => [
								'type'        => 'string',
								'description' => 'The category, such as Security or Performance.',
							],
							'description' => [ 'type' => 'string' ],
							'actions'     => [ 'type' => 'string' ],
						],
					],
				],
				'not_run'         => [
					'type'        => 'array',
					'description' => 'Checks that only the Site Health screen can run, in the background or with the browser\'s login. Their result is unknown.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'test'  => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
					],
				],
				'site_health_url' => [ 'type' => 'string' ],
			],
			'required'   => [ 'counts', 'results', 'not_run' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'view_site_health_checks' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$this->load_site_health();

		$site_health = WP_Site_Health::get_instance();
		$tests       = WP_Site_Health::get_tests();

		// The screen skips this check on development sites; so do we.
		if ( $site_health->is_development_environment() ) {
			unset( $tests['async']['https_status'] );
		}

		$counts  = [
			'critical'    => 0,
			'recommended' => 0,
			'good'        => 0,
		];
		$results = [];
		$not_run = [];

		foreach ( (array) ( $tests['direct'] ?? [] ) as $slug => $test ) {
			$test = is_array( $test ) ? $test : [];

			// Core skips these outside the browser too: they need the viewer's login cookie, which an MCP call lacks.
			if ( ! empty( $test['skip_cron'] ) ) {
				$not_run[] = $this->not_run_entry( (string) $slug, $test );
				continue;
			}

			$result = $this->run_direct_test( $site_health, $test );
			if ( $result === null ) {
				continue;
			}

			$status = isset( self::STATUS_ORDER[ $result['status'] ?? '' ] ) ? (string) $result['status'] : 'good';
			++$counts[ $status ];

			$results[] = [
				'test'        => (string) ( $result['test'] ?? $slug ),
				'status'      => $status,
				'label'       => $this->plain_text( $result['label'] ?? '' ),
				'badge'       => $this->plain_text( is_array( $result['badge'] ?? null ) ? ( $result['badge']['label'] ?? '' ) : '' ),
				'description' => $this->plain_text( $result['description'] ?? '' ),
				'actions'     => $this->plain_text( $result['actions'] ?? '' ),
			];
		}

		usort(
			$results,
			static fn( array $a, array $b ): int => self::STATUS_ORDER[ $a['status'] ] <=> self::STATUS_ORDER[ $b['status'] ]
		);

		if ( isset( $args['include_passed'] ) && $args['include_passed'] === false ) {
			$results = array_values( array_filter( $results, static fn( array $r ): bool => $r['status'] !== 'good' ) );
		}

		foreach ( (array) ( $tests['async'] ?? [] ) as $slug => $test ) {
			$not_run[] = $this->not_run_entry( (string) $slug, is_array( $test ) ? $test : [] );
		}

		return [
			'counts'          => $counts,
			'results'         => $results,
			'not_run'         => $not_run,
			'site_health_url' => admin_url( 'site-health.php' ),
		];
	}

	/**
	 * Describe a check that was not run.
	 *
	 * @param string               $slug The test's key. Not its `test` value: for REST-run tests that is a URL.
	 * @param array<string, mixed> $test The test definition.
	 * @return array{test: string, label: string}
	 * @since 1.6.0
	 */
	private function not_run_entry( string $slug, array $test ): array {
		return [
			'test'  => $slug,
			'label' => $this->plain_text( $test['label'] ?? '' ),
		];
	}

	/**
	 * Run one direct test as the Site Health screen does.
	 *
	 * A string test names a `get_test_{name}` method on WP_Site_Health; anything
	 * else must be a callable. Results pass through `site_status_test_result`,
	 * like the screen's.
	 *
	 * @param WP_Site_Health       $site_health Core's Site Health instance.
	 * @param array<string, mixed> $test        The test definition.
	 * @return array<string, mixed>|null The result, or null when the test cannot run.
	 * @since 1.6.0
	 */
	private function run_direct_test( WP_Site_Health $site_health, array $test ): ?array {
		$callback = null;
		$name     = $test['test'] ?? null;

		if ( is_string( $name ) ) {
			$method = 'get_test_' . $name;
			if ( method_exists( $site_health, $method ) && is_callable( [ $site_health, $method ] ) ) {
				$callback = [ $site_health, $method ];
			}
		}
		if ( $callback === null && is_callable( $name ) ) {
			$callback = $name;
		}
		if ( $callback === null ) {
			return null;
		}

		// Core filter: the same one the Site Health screen applies.
		$result = apply_filters( 'site_status_test_result', call_user_func( $callback ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Load the admin code the direct tests call, and core's admin translations for their text.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function load_site_health(): void {
		if ( ! class_exists( 'WP_Site_Health', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		// Mirrors core's Site Health REST controller outside wp-admin.
		if ( ! is_admin() ) {
			$locale = determine_locale();
			load_textdomain( 'default', WP_LANG_DIR . "/admin-$locale.mo", $locale );
		}
	}

	/**
	 * Turn a test's HTML into readable plain text.
	 *
	 * @param mixed $html HTML from a test result.
	 * @return string
	 * @since 1.6.0
	 */
	private function plain_text( mixed $html ): string {
		if ( ! is_string( $html ) ) {
			return '';
		}

		// Keep paragraph and list breaks, which wp_strip_all_tags() would merge.
		$html = (string) preg_replace( '#</(p|li|h[1-6]|div)>|<br\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/\s*\n\s*/', "\n", $text );

		return trim( $text );
	}
}
