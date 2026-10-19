<?php
/**
 * Site Health reporting for the MCP endpoint.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * AdapterHealth class
 *
 * Puts "can the MCP endpoint work at all" somewhere a site owner can look on
 * purpose, and says it in an admin notice they cannot miss.
 *
 * Worth stating twice because the failure is invisible from outside: with no
 * usable MCP library nothing registers a server, and the endpoint answers
 * `401 rest_forbidden` — indistinguishable from a healthy install that was
 * handed no token. Whoever is debugging it is looking at their token.
 *
 * @since 1.4.0
 */
class AdapterHealth implements Hookable {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', [ $this, 'add_test' ] );
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );

		if ( $this->missing() !== [] ) {
			add_action( 'admin_notices', [ $this, 'render_notice' ] );
		}
	}

	/**
	 * Symbols the loaded MCP library does not provide.
	 *
	 * A seam, not indirection for its own sake: every branch worth testing here
	 * is the unhealthy one, and it cannot be reached on a correctly installed
	 * site. Overriding this in a test is how the critical path gets exercised
	 * at all — without it the only testable branch is the one that says
	 * everything is fine.
	 *
	 * @return array<int, string>
	 * @since 1.4.0
	 */
	protected function missing(): array {
		return AdapterStatus::missing_classes();
	}

	/**
	 * Whether the adapter entry point is loadable at all.
	 *
	 * Separate from {@see self::missing()} because absent and outdated are
	 * different faults with opposite remedies, and the message has to say which.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	protected function present(): bool {
		return AdapterStatus::adapter_present();
	}

	/**
	 * Register the direct Site Health test.
	 *
	 * @param array<string, mixed> $tests Registered tests.
	 *
	 * @return array<string, mixed> Tests with ours added.
	 * @since 1.4.0
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['albert_mcp_adapter'] = [
			'label' => __( 'Albert MCP endpoint', 'albert-ai-butler' ),
			'test'  => [ $this, 'run_test' ],
		];

		return $tests;
	}

	/**
	 * The test result.
	 *
	 * @return array<string, mixed> Site Health result array.
	 * @since 1.4.0
	 */
	public function run_test(): array {
		if ( $this->missing() === [] ) {
			return $this->result(
				'good',
				__( 'Albert&#8217;s MCP endpoint is available', 'albert-ai-butler' ),
				'<p>' . esc_html__( 'The MCP library is present and offers everything Albert calls.', 'albert-ai-butler' ) . '</p>'
			);
		}

		return $this->result(
			'critical',
			__( 'Albert&#8217;s MCP endpoint is switched off', 'albert-ai-butler' ),
			'<p>' . $this->explanation() . '</p>'
		);
	}

	/**
	 * The admin notice shown when the endpoint cannot work.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function render_notice(): void {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Albert:', 'albert-ai-butler' ),
			wp_kses_post( $this->explanation() )
		);
	}

	/**
	 * What is wrong and what to do, in one sentence plus the remedy.
	 *
	 * Two different faults share one symptom, and they have opposite remedies,
	 * so they are told apart here rather than left to the reader.
	 *
	 * @return string Escaped HTML.
	 * @since 1.4.0
	 */
	private function explanation(): string {
		$preamble = esc_html__( 'The MCP endpoint is switched off, so every request to it fails with an authentication error even though no token would work: there is nothing registered to authenticate against.', 'albert-ai-butler' );

		if ( ! $this->present() ) {
			return $preamble . ' ' . sprintf(
				/* translators: %s: the composer command that rebuilds dependencies */
				esc_html__( 'The MCP library is not installed. Reinstall Albert from an official release, or if you are running it from source, install its dependencies with %s.', 'albert-ai-butler' ),
				'<code>composer install</code>'
			);
		}

		return $preamble . ' ' . sprintf(
			/* translators: 1: path of the loaded library, 2: list of missing class names */
			esc_html__( 'A copy of the MCP library is loaded from %1$s, but it is older than Albert needs and does not provide: %2$s. Update the plugin that supplies it, or deactivate it so Albert&#8217;s own copy is used.', 'albert-ai-butler' ),
			'<code>' . esc_html( (string) AdapterStatus::adapter_path() ) . '</code>',
			'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $this->missing() ) ) . '</code>'
		);
	}

	/**
	 * Add the same facts to the Site Health debug report, for support copy-paste.
	 *
	 * @param array<string, mixed> $info Debug information.
	 *
	 * @return array<string, mixed> Info with ours added.
	 * @since 1.4.0
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$missing = $this->missing();

		$info['albert'] = [
			'label'  => __( 'Albert', 'albert-ai-butler' ),
			'fields' => [
				'mcp_library'   => [
					'label' => __( 'MCP library', 'albert-ai-butler' ),
					'value' => $missing === []
						? __( 'usable', 'albert-ai-butler' )
						: __( 'unusable: the MCP endpoint cannot work', 'albert-ai-butler' ),
				],
				'mcp_loaded_at' => [
					'label' => __( 'MCP library loaded from', 'albert-ai-butler' ),
					'value' => AdapterStatus::adapter_path() ?? __( 'not loaded', 'albert-ai-butler' ),
				],
				'mcp_missing'   => [
					'label' => __( 'Missing from that copy', 'albert-ai-butler' ),
					'value' => $missing === [] ? __( 'nothing', 'albert-ai-butler' ) : implode( ', ', $missing ),
				],
			],
		];

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
	 * @since 1.4.0
	 */
	private function result( string $status, string $label, string $description ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Albert', 'albert-ai-butler' ),
				'color' => $status === 'good' ? 'blue' : 'red',
			],
			'description' => $description,
			'test'        => 'albert_mcp_adapter',
		];
	}
}
