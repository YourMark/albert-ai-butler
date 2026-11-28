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
 * Runs core's Site Health tests as WordPress's weekly check does.
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
	 * A background check, not requested.
	 *
	 * @since 1.6.0
	 */
	private const NOT_RUN_BACKGROUND = 'background';

	/**
	 * A check that needs the request to carry the browser's login.
	 *
	 * @since 1.6.0
	 */
	private const NOT_RUN_NEEDS_BROWSER = 'needs_browser';

	/**
	 * A check core skips on development sites.
	 *
	 * @since 1.6.0
	 */
	private const NOT_RUN_DEVELOPMENT_SITE = 'development_site';

	/**
	 * A check that threw, could not be called or returned no valid result.
	 *
	 * @since 1.6.0
	 */
	private const NOT_RUN_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-site-health';
		$this->label       = __( 'View Site Health', 'albert-ai-butler' );
		$this->description = __( 'Report the site\'s Site Health results; use it when the person asks what on the site needs attention.', 'albert-ai-butler' );
		$this->category    = 'maintenance';
		$this->group       = 'site-health';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Each result has a status (critical, recommended or good), a label, a category and an explanation, as the Site Health screen shows it. Checks listed in not_run have no result from this call, and their reason says why: report them as not checked and never guess their result. A check whose reason is failed broke while running; say so. Set run_background_checks when the question is about a critical issue or when counts differ from last_screen_counts (what the dashboard shows); it can take a while. Checks that need the browser\'s login are never run by this call; the owner can run them on the Site Health screen (site_health_url).'
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
				'include_passed'        => [
					'type'        => 'boolean',
					'description' => 'Include the checks that passed. Set false to get only what needs attention.',
					'default'     => true,
				],
				'run_background_checks' => [
					'type'        => 'boolean',
					'description' => 'Also run the checks the Site Health screen runs in the background, such as WordPress.org communication, background updates, loopback requests, HTTPS and page cache. Use it for a critical issue or when the counts differ from the dashboard. Slower: these checks make more requests, to WordPress.org and to the site itself. On a server that handles one request at a time, such as some local setups, the loopback check can fail because this call is still running.',
					'default'     => false,
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
		$counts = [
			'type'       => 'object',
			'properties' => [
				'critical'    => [ 'type' => 'integer' ],
				'recommended' => [ 'type' => 'integer' ],
				'good'        => [ 'type' => 'integer' ],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'counts'             => array_merge(
					$counts,
					[ 'description' => 'Counts of the results returned. Checks in not_run are not counted, so these can differ from the dashboard.' ]
				),
				'last_screen_counts' => array_merge(
					$counts,
					[
						'type'        => [ 'object', 'null' ],
						'description' => 'The counts the Site Health screen or WordPress\'s weekly check last stored, which the dashboard shows. Null when none are stored.',
					]
				),
				'results'            => [
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
				'not_run'            => [
					'type'        => 'array',
					'description' => 'Checks with no result from this call. Their result is unknown.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'test'   => [ 'type' => 'string' ],
							'label'  => [ 'type' => 'string' ],
							'reason' => [
								'type'        => 'string',
								'enum'        => [ self::NOT_RUN_BACKGROUND, self::NOT_RUN_NEEDS_BROWSER, self::NOT_RUN_DEVELOPMENT_SITE, self::NOT_RUN_FAILED ],
								'description' => 'background: run_background_checks was not set. needs_browser: only the Site Health screen can run it. development_site: skipped on development sites, as on the screen. failed: the check broke or returned no valid result.',
							],
						],
					],
				],
				'site_health_url'    => [ 'type' => 'string' ],
			],
			'required'   => [ 'counts', 'last_screen_counts', 'results', 'not_run' ],
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

		$site_health    = WP_Site_Health::get_instance();
		$tests          = WP_Site_Health::get_tests();
		$run_background = ! empty( $args['run_background_checks'] );

		$counts  = [
			'critical'    => 0,
			'recommended' => 0,
			'good'        => 0,
		];
		$results = [];
		$not_run = [];

		// The screen skips this check on development sites; so do we.
		if ( $site_health->is_development_environment() && isset( $tests['async']['https_status'] ) ) {
			$not_run[] = $this->not_run_entry( 'https_status', (array) $tests['async']['https_status'], self::NOT_RUN_DEVELOPMENT_SITE );
			unset( $tests['async']['https_status'] );
		}

		// Core skips skip_cron tests in its scheduled check; an MCP call has no login cookie either.
		$runnable = [];
		foreach ( (array) ( $tests['direct'] ?? [] ) as $slug => $test ) {
			$test = is_array( $test ) ? $test : [];
			if ( ! empty( $test['skip_cron'] ) ) {
				$not_run[] = $this->not_run_entry( (string) $slug, $test, self::NOT_RUN_NEEDS_BROWSER );
				continue;
			}
			$callback = $this->direct_callback( $site_health, $test );
			if ( $callback === null ) {
				$not_run[] = $this->not_run_entry( (string) $slug, $test, self::NOT_RUN_FAILED );
				continue;
			}
			$runnable[] = [ (string) $slug, $callback, $test ];
		}
		foreach ( (array) ( $tests['async'] ?? [] ) as $slug => $test ) {
			$test   = is_array( $test ) ? $test : [];
			$runner = $test['async_direct_test'] ?? null;

			// Without a server-side runner the test needs a request carrying the browser's login.
			if ( ! empty( $test['skip_cron'] ) || ! is_callable( $runner ) ) {
				$not_run[] = $this->not_run_entry( (string) $slug, $test, self::NOT_RUN_NEEDS_BROWSER );
				continue;
			}
			if ( ! $run_background ) {
				$not_run[] = $this->not_run_entry( (string) $slug, $test, self::NOT_RUN_BACKGROUND );
				continue;
			}
			$runnable[] = [ (string) $slug, $runner, $test ];
		}

		foreach ( $runnable as [ $slug, $callback, $test ] ) {
			// One broken check, often a plugin's, must not cost the report every other result.
			try {
				$result = apply_filters( 'site_status_test_result', call_user_func( $callback ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			} catch ( \Throwable $e ) {
				$result = null;
			}

			$status = is_array( $result ) && is_string( $result['status'] ?? null ) ? $result['status'] : '';
			if ( ! isset( self::STATUS_ORDER[ $status ] ) ) {
				$not_run[] = $this->not_run_entry( $slug, $test, self::NOT_RUN_FAILED );
				continue;
			}
			++$counts[ $status ];

			$results[] = [
				'test'        => is_string( $result['test'] ?? null ) ? $result['test'] : $slug,
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

		return [
			'counts'             => $counts,
			'last_screen_counts' => $this->last_screen_counts(),
			'results'            => $results,
			'not_run'            => $not_run,
			'site_health_url'    => admin_url( 'site-health.php' ),
		];
	}

	/**
	 * The counts the Site Health screen or core's weekly check last stored, which the dashboard widget shows.
	 *
	 * @return array{critical: int, recommended: int, good: int}|null
	 * @since 1.6.0
	 */
	private function last_screen_counts(): ?array {
		$stored = get_transient( 'health-check-site-status-result' );
		$counts = is_string( $stored ) ? json_decode( $stored, true ) : null;

		if ( ! is_array( $counts ) ) {
			return null;
		}

		// The screen posts its counts as strings.
		return [
			'critical'    => (int) ( $counts['critical'] ?? 0 ),
			'recommended' => (int) ( $counts['recommended'] ?? 0 ),
			'good'        => (int) ( $counts['good'] ?? 0 ),
		];
	}

	/**
	 * Describe a check that was not run.
	 *
	 * @param string               $slug   The test's key. Not its `test` value: for REST-run tests that is a URL.
	 * @param array<string, mixed> $test   The test definition.
	 * @param string               $reason One of the NOT_RUN_* constants.
	 * @return array{test: string, label: string, reason: string}
	 * @since 1.6.0
	 */
	private function not_run_entry( string $slug, array $test, string $reason ): array {
		return [
			'test'   => $slug,
			'label'  => $this->plain_text( $test['label'] ?? '' ),
			'reason' => $reason,
		];
	}

	/**
	 * Resolve a direct test's runner as core does.
	 *
	 * A string test names a `get_test_{name}` method on WP_Site_Health; anything
	 * else must be a callable.
	 *
	 * @param WP_Site_Health       $site_health Core's Site Health instance.
	 * @param array<string, mixed> $test        The test definition.
	 * @return callable|null The runner, or null when the test cannot run.
	 * @since 1.6.0
	 */
	private function direct_callback( WP_Site_Health $site_health, array $test ): ?callable {
		$name = $test['test'] ?? null;

		if ( is_string( $name ) ) {
			$method = 'get_test_' . $name;
			if ( method_exists( $site_health, $method ) && is_callable( [ $site_health, $method ] ) ) {
				return [ $site_health, $method ];
			}
		}

		return is_callable( $name ) ? $name : null;
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
	 * Turn a test's HTML into readable plain text, keeping link targets.
	 *
	 * @param mixed $html HTML from a test result.
	 * @return string
	 * @since 1.6.0
	 */
	private function plain_text( mixed $html ): string {
		if ( ! is_string( $html ) ) {
			return '';
		}

		// "Text (URL)", so the assistant can point the person at the fix.
		$html = (string) preg_replace_callback(
			'#<a\s[^>]*?href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				// Drops the "(opens in a new tab)" hint, which means nothing outside the browser.
				$text = (string) preg_replace( '#<span[^>]*screen-reader-text[^>]*>.*?</span>#is', '', $m[3] );
				$url  = trim( $m[2] );

				if ( $url === '' || str_starts_with( $url, '#' ) || stripos( $url, 'javascript:' ) === 0 ) {
					return $text;
				}

				return trim( $text ) . ' (' . $url . ')';
			},
			$html
		);

		// Keep paragraph and list breaks, which wp_strip_all_tags() would merge.
		$html = (string) preg_replace( '#</(p|li|h[1-6]|div)>|<br\s*/?>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/\s*\n\s*/', "\n", $text );

		return trim( $text );
	}
}
