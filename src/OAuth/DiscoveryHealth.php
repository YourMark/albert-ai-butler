<?php
/**
 * Site Health reporting for OAuth discovery reachability.
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.4.1
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * DiscoveryHealth class
 *
 * Checks, over real HTTP the way a client would, whether this site actually
 * serves its authorization-server metadata. Albert serves it at a mid-path
 * `.well-known` URL that reaches WordPress on the managed hosts that intercept a
 * root `/.well-known/` (see {@see ServerMetadata::issuer_url()}). The rare host
 * that blocks even a nested `.well-known` fails discovery with no sign of why
 * from inside wp-admin, so the site says so plainly instead.
 *
 * @since 1.4.1
 */
class DiscoveryHealth implements Hookable {

	/**
	 * Transient holding the last probe outcome (one of the status constants).
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const CACHE_KEY = 'albert_discovery_reachable';

	/**
	 * Discovery serves Albert's document, so assistants can sign in.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const OK = 'ok';

	/**
	 * The request completed but the URL did not return Albert's document.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const UNREACHABLE = 'unreachable';

	/**
	 * The self-request could not be made at all (loopback blocked, DNS, etc.).
	 *
	 * Distinct on purpose: it is not evidence of the fault, so it must not raise
	 * the same alarm as {@see self::UNREACHABLE}.
	 *
	 * @since 1.4.1
	 * @var string
	 */
	const INCONCLUSIVE = 'inconclusive';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.1
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', [ $this, 'add_test' ] );
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );
		add_action( 'admin_init', [ $this, 'maybe_refresh_cache' ] );
		add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
	}

	/**
	 * Classify a probe outcome. Pure: no WordPress, no network, unit-testable.
	 *
	 * A 200 carrying the two fields a real metadata document has is the only "ok";
	 * a request that never completed is inconclusive; anything else is the fault.
	 *
	 * @param bool                      $request_failed Whether the HTTP request itself failed.
	 * @param int                       $code           The HTTP status code.
	 * @param array<string, mixed>|null $json           The decoded JSON body, or null.
	 *
	 * @return string One of the status constants.
	 * @since 1.4.1
	 */
	public static function classify( bool $request_failed, int $code, ?array $json ): string {
		if ( $request_failed ) {
			return self::INCONCLUSIVE;
		}

		if ( $code === 200 && is_array( $json ) && isset( $json['issuer'], $json['registration_endpoint'] ) ) {
			return self::OK;
		}

		return self::UNREACHABLE;
	}

	/**
	 * How long a probe outcome is trusted before it is checked again.
	 *
	 * A method so the class carries no load-time dependency on `HOUR_IN_SECONDS`.
	 *
	 * @return int Seconds.
	 * @since 1.4.1
	 */
	protected function ttl(): int {
		return 12 * HOUR_IN_SECONDS;
	}

	/**
	 * The mid-path discovery URL a client falls through to (RFC 8414 §3.1).
	 *
	 * @return string
	 * @since 1.4.1
	 */
	protected function discovery_url(): string {
		return ServerMetadata::issuer_url() . '/.well-known/openid-configuration';
	}

	/**
	 * Fetch the discovery URL over real HTTP and classify what came back.
	 *
	 * A seam for tests, which override it to drive the branches without a network.
	 *
	 * @return string One of the status constants.
	 * @since 1.4.1
	 */
	protected function probe(): string {
		$response = wp_remote_get(
			$this->discovery_url(),
			[
				'timeout'     => 5,
				'sslverify'   => ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ),
				'redirection' => 3,
				'headers'     => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return self::classify( true, 0, null );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return self::classify( false, $code, is_array( $json ) ? $json : null );
	}

	/**
	 * Probe and cache the outcome. Hooked to `shutdown`, so void.
	 *
	 * @return void
	 * @since 1.4.1
	 */
	public function refresh_cache(): void {
		$this->check_and_cache();
	}

	/**
	 * Probe, cache the outcome, and return it for callers that need the value.
	 *
	 * @return string The status recorded.
	 * @since 1.4.1
	 */
	private function check_and_cache(): string {
		$status = $this->probe();

		set_transient( self::CACHE_KEY, $status, $this->ttl() );

		return $status;
	}

	/**
	 * Schedule a probe when the cached outcome has gone stale.
	 *
	 * Deferred to `shutdown` so the HTTP call never adds latency to the admin page;
	 * limited to administrators and non-AJAX requests.
	 *
	 * @return void
	 * @since 1.4.1
	 */
	public function maybe_refresh_cache(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( self::CACHE_KEY ) !== false ) {
			return;
		}

		add_action( 'shutdown', [ $this, 'refresh_cache' ] );
	}

	/**
	 * Warn, unmissably, when discovery is unreachable.
	 *
	 * Only {@see self::UNREACHABLE} warns; the remedy lives in the Site Health test.
	 *
	 * @return void
	 * @since 1.4.1
	 */
	public function maybe_render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( self::CACHE_KEY ) !== self::UNREACHABLE ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Albert:', 'albert-ai-butler' ),
			wp_kses_post( $this->explanation() )
		);
	}

	/**
	 * What is wrong and where to read the fix.
	 *
	 * @return string Escaped HTML.
	 * @since 1.4.1
	 */
	private function explanation(): string {
		return sprintf(
			/* translators: 1: opening Site Health link tag; 2: closing link tag. */
			esc_html__( 'AI assistants cannot sign in to this site yet: the address they use to discover how to connect is not reachable. %1$sSee Site Health for what to do%2$s.', 'albert-ai-butler' ),
			'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">',
			'</a>'
		);
	}

	/**
	 * Register the async Site Health test (async because it makes an HTTP request).
	 *
	 * @param array<string, mixed> $tests Registered tests.
	 *
	 * @return array<string, mixed> Tests with ours added.
	 * @since 1.4.1
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['async']['albert_oauth_discovery'] = [
			'label'             => __( 'Albert assistant sign-in discovery', 'albert-ai-butler' ),
			'test'              => 'albert_oauth_discovery',
			'has_rest'          => false,
			'async_direct_test' => [ $this, 'run_test' ],
		];

		return $tests;
	}

	/**
	 * The test result, from a fresh probe (which also refreshes the cache).
	 *
	 * @return array<string, mixed> Site Health result array.
	 * @since 1.4.1
	 */
	public function run_test(): array {
		$status = $this->check_and_cache();

		if ( $status === self::OK ) {
			return $this->result(
				'good',
				__( 'Assistants can discover how to sign in to this site', 'albert-ai-butler' ),
				'<p>' . esc_html__( 'The discovery address returns Albert&#8217;s data, so an AI assistant can find where to sign in and connect.', 'albert-ai-butler' ) . '</p>'
			);
		}

		if ( $status === self::INCONCLUSIVE ) {
			return $this->result(
				'recommended',
				__( 'Albert could not check the assistant sign-in discovery address', 'albert-ai-butler' ),
				'<p>' . sprintf(
					/* translators: %s: the discovery URL, in a code tag. */
					esc_html__( 'This site could not make a request to its own discovery address (%s). That often just means loopback requests are blocked here rather than a real problem. Opening that address in your browser should return a line of data.', 'albert-ai-butler' ),
					'<code>' . esc_html( $this->discovery_url() ) . '</code>'
				) . '</p>'
			);
		}

		return $this->result(
			'recommended',
			__( 'Assistants cannot discover how to sign in to this site', 'albert-ai-butler' ),
			'<p>' . sprintf(
				/* translators: %s: the discovery URL, in a code tag. */
				esc_html__( 'The discovery address %s does not return Albert&#8217;s data, so connecting an assistant will fail at sign-in until it is fixed.', 'albert-ai-butler' ),
				'<code>' . esc_html( $this->discovery_url() ) . '</code>'
			) . '</p>'
			. '<p>' . esc_html__( 'This is the hosting environment, not Albert: your host is blocking requests whose path contains .well-known before they reach WordPress. Ask your host to allow that path through, or serve it at the edge. Albert&#8217;s data is correct and available at its /wp-json/ address.', 'albert-ai-butler' ) . '</p>'
		);
	}

	/**
	 * Add the last known outcome to the Site Health debug report.
	 *
	 * @param array<string, mixed> $info Debug information.
	 *
	 * @return array<string, mixed> Info with ours added.
	 * @since 1.4.1
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$cached = get_transient( self::CACHE_KEY );

		$info['albert']['fields']['oauth_discovery_url'] = [
			'label' => __( 'Assistant sign-in discovery address', 'albert-ai-butler' ),
			'value' => $this->discovery_url(),
		];

		$info['albert']['fields']['oauth_discovery_reachable'] = [
			'label' => __( 'Assistant sign-in discovery reachable', 'albert-ai-butler' ),
			'value' => is_string( $cached ) ? $cached : __( 'not checked yet', 'albert-ai-butler' ),
		];

		if ( ! isset( $info['albert']['label'] ) ) {
			$info['albert']['label'] = __( 'Albert', 'albert-ai-butler' );
		}

		return $info;
	}

	/**
	 * Build a Site Health result array.
	 *
	 * @param string $status      One of `good`, `recommended` or `critical`.
	 * @param string $label       Result heading.
	 * @param string $description Result body, already escaped HTML.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.1
	 */
	private function result( string $status, string $label, string $description ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Albert', 'albert-ai-butler' ),
				'color' => $status === 'good' ? 'blue' : 'orange',
			],
			'description' => $description,
			'test'        => 'albert_oauth_discovery',
		];
	}
}
