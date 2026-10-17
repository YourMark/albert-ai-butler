<?php
/**
 * The Claude Desktop install file for this site.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\MCP\Server as McpServer;

/**
 * Builds and serves the `.mcpb` file that connects Claude Desktop to a local site.
 *
 * Claude Desktop's connectors run on Anthropic's servers and cannot reach a
 * site on the user's computer. An install file runs on the computer instead:
 * Claude Desktop starts the bundled bridge (a pinned build of mcp-remote) on
 * its own Node, and the bridge signs in with OAuth like any other app. So
 * nothing is installed by hand, and the file holds the site's address and no
 * secret: a leaked file connects nobody who cannot also sign in.
 *
 * The bridge is built from `tools/claude-desktop/` into
 * `assets/claude-desktop/bridge.mjs`; the zip is assembled per download
 * because the address, certificate and http flag are this site's.
 *
 * @since 1.5.0
 */
class ClaudeDesktopBundle implements Hookable {

	/**
	 * The admin-post action that serves the file.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const ACTION = 'albert_claude_desktop_bundle';

	/**
	 * The mcp-remote version inside the bridge. Bump with `tools/claude-desktop`.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const BRIDGE_VERSION = '0.15.1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
		add_action( 'rest_api_init', [ $this, 'register_done_route' ] );
	}

	/**
	 * Register the page the bridge sends the browser to after sign-in.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_done_route(): void {
		register_rest_route(
			Plugin::rest_namespace(),
			'/connect/done',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'render_done_page' ],
				// Says only "you can go back to Claude Desktop": nothing to protect.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Where the bridge sends the browser once the sign-in has finished.
	 *
	 * Without it the bridge shows its own page, written for its command line
	 * ("return to the CLI"), which reads as an error to somebody who never
	 * opened one. The bridge accepts only an address without a query string,
	 * so a site on plain permalinks keeps the bridge's page.
	 *
	 * @return string The address, or '' when the bridge would refuse it.
	 * @since 1.5.0
	 */
	public static function done_url(): string {
		$url = rest_url( Plugin::rest_namespace() . '/connect/done' );

		return str_contains( $url, '?' ) ? '' : $url;
	}

	/**
	 * Print the page and stop: it is HTML, not a REST response.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_done_page(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$site_name = get_bloginfo( 'name' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( 'Connected', 'albert-ai-butler' ); ?> - <?php echo esc_html( $site_name ); ?></title>
		<?php wp_print_styles( 'albert-oauth-pages' ); ?>
</head>
<body>
	<main class="albert-oauth">
		<p class="albert-oauth__site"><?php echo esc_html( $site_name ); ?></p>
		<article class="albert-oauth__card">
			<h1 class="albert-oauth__title"><?php esc_html_e( 'Claude Desktop is connected', 'albert-ai-butler' ); ?></h1>
			<p class="albert-oauth__text"><?php esc_html_e( 'You can close this tab and go back to Claude Desktop.', 'albert-ai-butler' ); ?></p>
		</article>
	</main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * The download link, nonced for the current user.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * The file name, from the site's host, so two sites' files stay apart.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function filename(): string {
		return 'albert-' . self::slug() . '.mcpb';
	}

	/**
	 * Whether this server can build the file at all.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_available(): bool {
		return class_exists( '\ZipArchive' ) && is_readable( self::bridge_path() );
	}

	/**
	 * Send the file.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function download(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( ConnectCapability::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'albert-ai-butler' ), '', [ 'response' => 403 ] );
		}

		if ( ! self::is_available() ) {
			wp_die( esc_html__( 'This server cannot build the Claude Desktop file: PHP’s Zip extension is missing. Use the steps under “Set it up by hand” instead.', 'albert-ai-butler' ), '', [ 'response' => 500 ] );
		}

		$file = wp_tempnam( self::filename() );

		if ( ! self::build( $file ) ) {
			wp_delete_file( $file );
			wp_die( esc_html__( 'The Claude Desktop file could not be built. Please try again.', 'albert-ai-butler' ), '', [ 'response' => 500 ] );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . self::filename() . '"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a temp file to the browser.
		readfile( $file );
		wp_delete_file( $file );
		exit;
	}

	/**
	 * Write the install file to a path.
	 *
	 * @param string $path Where to write the zip.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function build( string $path ): bool {
		$zip = new \ZipArchive();

		if ( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			return false;
		}

		$manifest = wp_json_encode( self::manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$ok = is_string( $manifest )
			&& $zip->addFromString( 'manifest.json', $manifest )
			&& $zip->addFile( self::bridge_path(), 'server/bridge.mjs' );

		$licenses = self::bridge_path() . '.LEGAL.txt';
		if ( $ok && is_readable( $licenses ) ) {
			$ok = $zip->addFile( $licenses, 'server/LICENSES.txt' );
		}

		return $zip->close() && $ok;
	}

	/**
	 * The MCPB manifest: what Claude Desktop shows and how it starts the bridge.
	 *
	 * @return array<string, mixed>
	 * @since 1.5.0
	 */
	public static function manifest(): array {
		$endpoint = McpServer::get_endpoint_url();
		$title    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$host     = (string) wp_parse_url( $endpoint, PHP_URL_HOST );

		$env = [
			'ALBERT_MCP_URL'     => $endpoint,
			'ALBERT_USER_AGENT'  => 'Albert-ClaudeDesktop/' . ALBERT_VERSION . ' (mcp-remote ' . self::BRIDGE_VERSION . ')',
			// Node ignores the system's trust store by default, and a local
			// tool's certificate is usually only in there.
			'NODE_USE_SYSTEM_CA' => '1',
		];

		if ( self::done_url() !== '' ) {
			$env['ALBERT_AUTH_SUCCESS_URL'] = self::done_url();
		}

		if ( wp_parse_url( $endpoint, PHP_URL_SCHEME ) === 'https' && LocalCertificate::path() !== null ) {
			$env['NODE_EXTRA_CA_CERTS'] = (string) LocalCertificate::path();
		}

		return [
			'manifest_version' => '0.3',
			'name'             => 'albert-' . self::slug(),
			/* translators: %s: the site title. */
			'display_name'     => sprintf( __( 'Albert: %s', 'albert-ai-butler' ), $title !== '' ? $title : $host ),
			'version'          => ALBERT_VERSION,
			/* translators: %s: the site's host name. */
			'description'      => sprintf( __( 'Connects Claude Desktop to the WordPress site %s through Albert. You approve it once in your browser.', 'albert-ai-butler' ), $host ),
			'author'           => [
				'name' => 'Albert',
				'url'  => 'https://albertwp.com',
			],
			'server'           => [
				'type'        => 'node',
				'entry_point' => 'server/bridge.mjs',
				'mcp_config'  => [
					'command' => 'node',
					'args'    => [ '${__dirname}/server/bridge.mjs' ],
					'env'     => $env,
				],
			],
			'license'          => 'GPL-2.0-or-later',
		];
	}

	/**
	 * The site's host as a file-name-safe slug.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function slug(): string {
		$host = (string) wp_parse_url( McpServer::get_endpoint_url(), PHP_URL_HOST );
		$slug = sanitize_title( str_replace( '.', '-', $host ) );

		return $slug !== '' ? $slug : 'site';
	}

	/**
	 * Where the built bridge lives in the plugin.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function bridge_path(): string {
		return ALBERT_PLUGIN_DIR . 'assets/claude-desktop/bridge.mjs';
	}
}
