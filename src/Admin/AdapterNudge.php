<?php
/**
 * Asks the owner to install the MCP Adapter plugin.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.5.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\MCP\AdapterPlugin;
use Albert\MCP\AdapterSource;

/**
 * Puts "install the MCP Adapter plugin" wherever an owner will see it.
 *
 * The Dashboard's attention card, a notice on every other Albert screen, a
 * notice on Albert's row on the Plugins screen, and Site Health. Each goes away
 * by itself once the plugin is active, so none is dismissible.
 *
 * @since 1.5.0
 */
class AdapterNudge implements Hookable {

	/**
	 * Script handle for the one-click install.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const SCRIPT_HANDLE = 'albert-adapter-install';

	/**
	 * Query argument the one-click button reloads with once it succeeds.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const DONE_ARG = 'albert-mcp-adapter';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', [ $this, 'add_test' ] );
		add_action( 'admin_notices', [ $this, 'render_done_notice' ] );
		add_filter( 'removable_query_args', [ $this, 'removable_query_args' ] );

		if ( ! AdapterPlugin::needs_attention() ) {
			return;
		}

		add_filter( 'albert/dashboard/attention', [ $this, 'add_attention_item' ] );
		add_action( 'admin_notices', [ $this, 'render_screen_notice' ] );
		add_action( 'after_plugin_row_' . ALBERT_PLUGIN_BASENAME, [ $this, 'render_plugin_row' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_script' ] );
	}

	/**
	 * Add the Dashboard item.
	 *
	 * A warning rather than danger: Albert works today on its own copy.
	 *
	 * @param mixed $items Attention items so far.
	 *
	 * @return mixed
	 * @since 1.5.0
	 */
	public function add_attention_item( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}

		$can_fix = AdapterPlugin::current_user_can_fix();

		$items[] = [
			'id'          => 'mcp-adapter-plugin',
			'tone'        => 'warning',
			'tone_label'  => __( 'Needed soon', 'albert-ai-butler' ),
			'title'       => AdapterPlugin::title(),
			'detail'      => $can_fix
				? AdapterPlugin::explanation()
				: AdapterPlugin::explanation() . ' ' . AdapterPlugin::guidance(),
			'action'      => $can_fix
				? [
					'label'      => AdapterPlugin::action_label(),
					'url'        => AdapterPlugin::fix_url(),
					'attributes' => self::one_click_attributes(),
				]
				: null,
			'dismissible' => false,
		];

		return $items;
	}

	/**
	 * The notice on every Albert screen except the Dashboard, which has its card.
	 *
	 * Shown to everyone who reaches those screens: someone who cannot install
	 * it is told who can.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_screen_notice(): void {
		if ( ! Menu::is_albert_screen() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen !== null && $screen->id === 'toplevel_page_' . Menu::PARENT_SLUG ) {
			return;
		}

		wp_admin_notice(
			$this->message(),
			[
				'type'               => 'warning',
				'additional_classes' => [ 'albert-adapter-notice' ],
				'paragraph_wrap'     => false,
			]
		);
	}

	/**
	 * Confirm the one-click install once the page reloads.
	 *
	 * Only when the plugin really is active, so the query argument alone proves
	 * nothing.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_done_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, and checked against the real state below.
		if ( ! isset( $_GET[ self::DONE_ARG ] ) || AdapterPlugin::needs_attention() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		wp_admin_notice(
			'<strong>' . esc_html__( 'The MCP Adapter plugin is installed and active.', 'albert-ai-butler' ) . '</strong> ' . esc_html__( 'Albert now uses it to talk to your AI assistants.', 'albert-ai-butler' ),
			[
				'type'               => 'success',
				'dismissible'        => true,
				'additional_classes' => [ 'albert-adapter-notice' ],
			]
		);
	}

	/**
	 * Let core strip the confirmation argument from the address bar.
	 *
	 * @param mixed $args Removable query arguments.
	 *
	 * @return mixed
	 * @since 1.5.0
	 */
	public function removable_query_args( $args ) {
		if ( is_array( $args ) ) {
			$args[] = self::DONE_ARG;
		}

		return $args;
	}

	/**
	 * The notice under Albert on the Plugins screen, for owners who never open Albert.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_plugin_row(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$list_table = $GLOBALS['wp_list_table'] ?? null;
		$columns    = is_object( $list_table ) && method_exists( $list_table, 'get_column_count' ) ? (int) $list_table->get_column_count() : 3;

		$link = AdapterPlugin::current_user_can_fix()
			? sprintf(
				' <a href="%1$s"%2$s>%3$s</a>.',
				esc_url( AdapterPlugin::fix_url() ),
				self::attribute_string(),
				esc_html( AdapterPlugin::action_label() )
			)
			: ' ' . esc_html( AdapterPlugin::guidance() );

		// Core's own "new version available" row, so it reads as part of Albert's.
		printf(
			'<tr class="plugin-update-tr active albert-adapter-row"><td colspan="%1$d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%2$s%3$s</p></div></td></tr>',
			(int) $columns,
			esc_html( AdapterPlugin::explanation() ),
			$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		);
	}

	/**
	 * Load the one-click script where a button can appear, and the Plugins row's styling.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function enqueue_script( string $hook ): void {
		if ( $hook === 'plugins.php' ) {
			wp_enqueue_style(
				'albert-adapter-plugins-row',
				ALBERT_PLUGIN_URL . 'assets/css/admin-plugins-row.css',
				[],
				Assets::version( 'assets/css/admin-plugins-row.css' )
			);
		}

		if ( ! AdapterPlugin::supports_one_click() ) {
			return;
		}

		if ( $hook !== 'plugins.php' && ! Menu::is_albert_screen() ) {
			return;
		}

		// The Plugins screen does not load Albert's shared helpers.
		if ( ! wp_script_is( 'albert-admin-utils', 'registered' ) ) {
			wp_register_script(
				'albert-admin-utils',
				ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
				[],
				Assets::version( 'assets/js/albert-admin-utils.js' ),
				true
			);
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ALBERT_PLUGIN_URL . 'assets/js/admin-adapter-install.js',
			[ 'albert-admin-utils' ],
			Assets::version( 'assets/js/admin-adapter-install.js' ),
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'albertAdapterInstall',
			[
				// Core's own install and activate actions, the ones its Plugins
				// screen uses. An admin request never starts Albert's copy of the
				// adapter, so activating the plugin there stays clean.
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'updates' ),
				'slug'    => AdapterPlugin::SLUG,
				'plugin'  => AdapterSource::PLUGIN_FILE,
				'name'    => __( 'MCP Adapter', 'albert-ai-butler' ),
				'i18n'    => [
					'installing' => __( 'Installing the MCP Adapter plugin…', 'albert-ai-butler' ),
					'activating' => __( 'Activating the MCP Adapter plugin…', 'albert-ai-butler' ),
					'done'       => __( 'The MCP Adapter plugin is active. Reloading…', 'albert-ai-butler' ),
					'fallback'   => __( 'That did not work here. Opening the WordPress screen for it instead…', 'albert-ai-butler' ),
				],
				'doneArg' => self::DONE_ARG,
			]
		);
	}

	/**
	 * Register the Site Health test.
	 *
	 * @param mixed $tests Registered tests.
	 *
	 * @return mixed
	 * @since 1.5.0
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['albert_mcp_adapter_plugin'] = [
			'label' => __( 'MCP Adapter plugin', 'albert-ai-butler' ),
			'test'  => [ $this, 'run_test' ],
		];

		return $tests;
	}

	/**
	 * The Site Health result.
	 *
	 * @return array<string, mixed>
	 * @since 1.5.0
	 */
	public function run_test(): array {
		$result = [
			'label'       => __( 'The MCP Adapter plugin is active', 'albert-ai-butler' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Albert', 'albert-ai-butler' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Albert talks to AI assistants through the MCP Adapter plugin.', 'albert-ai-butler' ) . '</p>',
			'test'        => 'albert_mcp_adapter_plugin',
		];

		if ( ! AdapterPlugin::needs_attention() ) {
			return $result;
		}

		$result['label']          = AdapterPlugin::title();
		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		$result['description']    = '<p>' . esc_html( AdapterPlugin::explanation() ) . '</p>';

		if ( AdapterPlugin::current_user_can_fix() ) {
			$result['actions'] = sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( AdapterPlugin::fix_url() ),
				esc_html( AdapterPlugin::action_label() )
			);
		} else {
			$result['description'] .= '<p>' . esc_html( AdapterPlugin::guidance() ) . '</p>';
		}

		return $result;
	}

	/**
	 * The button that installs or activates it, or what to do instead.
	 *
	 * Shared by the Dashboard's setup checklist, so it matches the notices.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function render_action(): void {
		if ( ! AdapterPlugin::current_user_can_fix() ) {
			printf( '<p>%s</p>', esc_html( AdapterPlugin::guidance() ) );

			return;
		}

		printf(
			'<a class="button button-primary" href="%1$s"%2$s>%3$s</a>',
			esc_url( AdapterPlugin::fix_url() ),
			self::attribute_string(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in attribute_string().
			esc_html( AdapterPlugin::action_label() )
		);
	}

	/**
	 * The data attributes that turn the link into a one-click install.
	 *
	 * Empty where one click cannot do it, so the link goes to core's own screen.
	 *
	 * @return array<string, string>
	 * @since 1.5.0
	 */
	public static function one_click_attributes(): array {
		if ( ! AdapterPlugin::supports_one_click() ) {
			return [];
		}

		return [ 'data-albert-adapter-install' => AdapterPlugin::is_installed() ? 'activate' : 'install' ];
	}

	/**
	 * The one-click attributes as escaped HTML.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function attribute_string(): string {
		$html = '';

		foreach ( self::one_click_attributes() as $name => $value ) {
			$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		return $html;
	}

	/**
	 * The notice body: title, explanation, and the button or what to do instead.
	 *
	 * @return string Escaped HTML.
	 * @since 1.5.0
	 */
	private function message(): string {
		$html = sprintf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html( AdapterPlugin::title() . '.' ),
			esc_html( AdapterPlugin::explanation() )
		);

		if ( ! AdapterPlugin::current_user_can_fix() ) {
			return $html . '<p>' . esc_html( AdapterPlugin::guidance() ) . '</p>';
		}

		return $html . sprintf(
			'<p><a class="button button-primary" href="%1$s"%2$s>%3$s</a></p>',
			esc_url( AdapterPlugin::fix_url() ),
			self::attribute_string(),
			esc_html( AdapterPlugin::action_label() )
		);
	}
}
