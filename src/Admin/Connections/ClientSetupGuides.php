<?php
/**
 * Per-app connect instructions.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.4.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * The curated list of apps Albert tells you how to connect, as data.
 *
 * One place, deliberately: the Connections screen, the connect dialog and a
 * future docs page all draw from it, and a list that lives inside a render
 * method can only ever appear once. Everything a surface needs is on the
 * entry: numbered steps with their one action inline (a link, a download, a
 * command to copy), requirement notes, manual setup, and for "Other apps" the
 * per-app snippets.
 *
 * Every app is listed whatever the site's location. An app that needs a public
 * site is marked as such on a local one and says why, rather than vanishing:
 * detection is a guess ({@see SiteLocation}), and a guide that disappears is
 * more confusing than one that explains itself.
 *
 * Short and accurate beats long and stale. A wrong instruction costs more than
 * a missing one, so an app only earns a one-click action when it publishes
 * one, and app-side button labels are kept to the few that are documented.
 *
 * @phpstan-type AlbertGuideAction array{type: string, label: string, value: string, meta: string}
 * @phpstan-type AlbertGuideStep array{text: string, action: AlbertGuideAction|null}
 * @phpstan-type AlbertGuideNote array{text: string, command: string, after: bool}
 * @phpstan-type AlbertGuideSnippet array{id: string, label: string, hint: string, snippet: string, quiet: bool}
 * @phpstan-type AlbertGuideManual array{steps: array<int, string>, path: string, snippet: string, snippet_label: string}
 * @phpstan-type AlbertClientGuide array{
 *     id: string,
 *     label: string,
 *     icon: string,
 *     needs_public: bool,
 *     intro: string,
 *     steps: array<int, AlbertGuideStep>,
 *     notes: array<int, AlbertGuideNote>,
 *     manual: AlbertGuideManual|null,
 *     more: array<int, AlbertGuideSnippet>,
 *     unavailable: string,
 *     blocked: string
 * }
 *
 * @since 1.4.0
 */
class ClientSetupGuides {

	/**
	 * Every app's guide for this site, in the order the connect flow lists them.
	 *
	 * On a local site the apps that need a public site come last, flagged
	 * `needs_public` with an `unavailable` reason.
	 *
	 * @param string $endpoint The live MCP endpoint URL for this site.
	 * @param string $location {@see SiteLocation::PUBLIC} or {@see SiteLocation::LOCAL}.
	 *
	 * @return array<int, AlbertClientGuide> Every guide, in display order.
	 * @since 1.5.0
	 */
	public static function for_location( string $endpoint, string $location ): array {
		$host    = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$context = [
			'endpoint' => $endpoint,
			'local'    => $location === SiteLocation::LOCAL,
			'http'     => wp_parse_url( $endpoint, PHP_URL_SCHEME ) === 'http',
		];

		// OAuth sends the token request only over https, or plain http to
		// this computer by name (localhost and loopback addresses). The MCP
		// SDK most apps build on refuses anything else.
		$context['insecure'] = $context['http'] && ! in_array( strtolower( trim( $host, '[]' ) ), [ 'localhost', '127.0.0.1', '::1' ], true );

		if ( $context['local'] ) {
			return [
				self::claude_desktop( $context ),
				self::claude_code( $context ),
				self::cursor( $context ),
				self::vs_code( $context ),
				self::other_apps( $context ),
				self::claude( $context ),
				self::chatgpt( $context ),
			];
		}

		return [
			self::claude( $context ),
			self::chatgpt( $context ),
			self::cursor( $context ),
			self::vs_code( $context ),
			self::claude_code( $context ),
			self::other_apps( $context ),
		];
	}

	/**
	 * One app's guide for this site.
	 *
	 * @param string $id       The guide id.
	 * @param string $endpoint The live MCP endpoint URL.
	 * @param string $location {@see SiteLocation::PUBLIC} or {@see SiteLocation::LOCAL}.
	 *
	 * @return AlbertClientGuide|null Null for an unknown id.
	 * @since 1.5.0
	 */
	public static function get( string $id, string $endpoint, string $location ): ?array {
		foreach ( self::for_location( $endpoint, $location ) as $guide ) {
			if ( $guide['id'] === $id ) {
				return $guide;
			}
		}

		return null;
	}

	/**
	 * Claude: claude.ai, and the desktop and phone apps' connectors.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function claude( array $context ): array {
		$endpoint = $context['endpoint'];

		// The install link Anthropic publishes opens "Add custom connector" with
		// the name and address filled in, and still asks the person to confirm.
		// Named after the site rather than Albert, so several sites stay apart
		// in the list.
		$deeplink = 'https://claude.ai/customize/connectors?modal=add-custom-connector'
			. '&connectorName=' . rawurlencode( self::site_title() )
			. '&connectorUrl=' . rawurlencode( $endpoint );

		$notes = [];
		if ( $context['http'] ) {
			$notes[] = self::note( __( 'Claude needs this site on an https address.', 'albert-ai-butler' ) );
		}
		$notes[] = self::note( __( 'A free Claude account can add one custom connector. On a Team or Enterprise plan, an owner adds it for the organisation first.', 'albert-ai-butler' ), '', true );

		return self::guide(
			[
				'id'           => 'claude',
				'label'        => $context['local'] ? __( 'Claude on the web and phone', 'albert-ai-butler' ) : __( 'Claude', 'albert-ai-butler' ),
				'icon'         => 'claude',
				'needs_public' => $context['local'],
				'steps'        => [
					self::step( __( 'Click Add to Claude.', 'albert-ai-butler' ), self::link( __( 'Add to Claude', 'albert-ai-butler' ), $deeplink ) ),
					self::step( __( 'Claude asks to add the connector: choose Add.', 'albert-ai-butler' ) ),
					self::approve_step(),
				],
				'notes'        => $notes,
				'manual'       => self::manual(
					[
						__( 'In Claude, open Settings, then Connectors.', 'albert-ai-butler' ),
						__( 'Choose "Add custom connector".', 'albert-ai-butler' ),
						__( 'Paste the address below as the connector URL and save.', 'albert-ai-butler' ),
					],
					$endpoint,
					__( 'Connector URL', 'albert-ai-butler' )
				),
				'unavailable'  => __( 'Claude on the web and phone connects from Anthropic’s servers, which cannot reach a site on your computer.', 'albert-ai-butler' ),
			]
		);
	}

	/**
	 * Claude Desktop on a local site: an install file instead of a connector.
	 *
	 * Its connectors run on Anthropic's servers, which cannot reach the site;
	 * the file runs a bridge on this computer instead. See
	 * {@see ClaudeDesktopBundle}. On a public site the Claude guide covers
	 * the desktop app too, so this one is only offered locally.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.5.0
	 */
	private static function claude_desktop( array $context ): array {
		$download = [
			'type'  => 'download',
			'label' => __( 'Download for Claude Desktop', 'albert-ai-butler' ),
			'value' => ClaudeDesktopBundle::url(),
			'meta'  => ClaudeDesktopBundle::filename(),
		];

		return self::guide(
			[
				'id'      => 'claude-desktop',
				'label'   => __( 'Claude Desktop', 'albert-ai-butler' ),
				'icon'    => 'claude',
				'steps'   => [
					self::step( __( 'Download the install file for this site.', 'albert-ai-butler' ), $download ),
					self::step( __( 'Open the file. Claude Desktop shows what it adds: choose Install.', 'albert-ai-butler' ) ),
					self::step( __( 'The first time Claude uses Albert, your browser opens this site: sign in as someone listed under Who may connect, and choose Authorize.', 'albert-ai-butler' ) ),
				],
				'notes'   => ClaudeDesktopBundle::is_available() ? [] : [ self::note( __( 'This server is missing PHP’s Zip extension, so it cannot build the file. Use Claude Code, Cursor or another app on this computer instead.', 'albert-ai-butler' ) ) ],
				'blocked' => $context['insecure'] ? __( 'Claude Desktop needs this site on a secure https address, or on localhost. Turn on https for the site in the tool that runs it (for example Secure in Herd, or SSL in Local), then come back for the file.', 'albert-ai-butler' ) : '',
			]
		);
	}

	/**
	 * Claude Code.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function claude_code( array $context ): array {
		$command = sprintf( 'claude mcp add --transport http --scope user albert %s', $context['endpoint'] );

		$notes = self::http_note( $context );
		if ( $context['local'] && ! $context['http'] ) {
			$notes[] = self::note(
				__( 'If Claude Code says it can’t verify the site’s certificate, start it with this command instead:', 'albert-ai-butler' ),
				sprintf( 'NODE_EXTRA_CA_CERTS="%s" claude', self::local_root_certificate() ),
				true
			);
		}

		return self::guide(
			[
				'id'    => 'claude-code',
				'label' => __( 'Claude Code', 'albert-ai-butler' ),
				'icon'  => 'claude-code',
				'steps' => [
					self::step( __( 'Run this command in your terminal.', 'albert-ai-butler' ), self::copy( __( 'Copy', 'albert-ai-butler' ), $command ) ),
					/* translators: %s: the /mcp slash command. */
					self::step( sprintf( __( 'In Claude Code, type %s, pick albert and choose Authenticate.', 'albert-ai-butler' ), '/mcp' ) ),
					self::approve_step(),
				],
				'notes' => $notes,
			]
		);
	}

	/**
	 * Cursor.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function cursor( array $context ): array {
		$server_config = [
			'url' => $context['endpoint'],
		];

		// Cursor publishes this scheme for one-click installs; the config is the
		// server object, base64-encoded. Encoding here is Cursor's wire format,
		// not obfuscation.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$deeplink = 'cursor://anysphere.cursor-deeplink/mcp/install?name=albert&config=' . rawurlencode( base64_encode( (string) wp_json_encode( $server_config ) ) );

		return self::guide(
			[
				'id'     => 'cursor',
				'label'  => __( 'Cursor', 'albert-ai-butler' ),
				'icon'   => 'cursor',
				'steps'  => [
					self::step( __( 'Click Add to Cursor.', 'albert-ai-butler' ), self::link( __( 'Add to Cursor', 'albert-ai-butler' ), $deeplink ) ),
					self::step( __( 'Cursor asks to install the server: choose Install.', 'albert-ai-butler' ) ),
					self::approve_step(),
				],
				'notes'  => self::http_note( $context ),
				'manual' => self::manual(
					[
						__( 'Open the file named below, which applies to every project.', 'albert-ai-butler' ),
						__( 'Add the block below. If the file already has an "mcpServers" section, add the "albert" entry inside it.', 'albert-ai-butler' ),
						__( 'Reopen Cursor, then approve the sign-in it offers.', 'albert-ai-butler' ),
					],
					self::json_snippet( [ 'mcpServers' => [ 'albert' => $server_config ] ] ),
					'mcp.json',
					'~/.cursor/mcp.json'
				),
			]
		);
	}

	/**
	 * Visual Studio Code with GitHub Copilot.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function vs_code( array $context ): array {
		$server_config = [
			'type' => 'http',
			'url'  => $context['endpoint'],
		];

		// VS Code's documented install URI: the query string is the server
		// object with its name, URL-encoded.
		$deeplink = 'vscode:mcp/install?' . rawurlencode( (string) wp_json_encode( array_merge( [ 'name' => 'albert' ], $server_config ) ) );

		return self::guide(
			[
				'id'     => 'vscode',
				'label'  => __( 'VS Code', 'albert-ai-butler' ),
				'icon'   => 'vscode',
				'steps'  => [
					self::step( __( 'Click Add to VS Code.', 'albert-ai-butler' ), self::link( __( 'Add to VS Code', 'albert-ai-butler' ), $deeplink ) ),
					self::step( __( 'VS Code asks to install the server: choose Install.', 'albert-ai-butler' ) ),
					self::approve_step(),
				],
				'notes'  => array_merge( [ self::note( __( 'Needs GitHub Copilot in agent mode.', 'albert-ai-butler' ) ) ], self::http_note( $context ) ),
				'manual' => self::manual(
					[
						__( 'Create the file named below in your project folder.', 'albert-ai-butler' ),
						__( 'Add the block below, then use the Start action VS Code shows above the entry.', 'albert-ai-butler' ),
					],
					self::json_snippet( [ 'servers' => [ 'albert' => $server_config ] ] ),
					'mcp.json',
					'.vscode/mcp.json'
				),
			]
		);
	}

	/**
	 * ChatGPT.
	 *
	 * No install link: OpenAI publishes none, and the settings it would open
	 * have moved more than once, so the steps name the setting rather than a
	 * menu path that may be stale by the time somebody reads it.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function chatgpt( array $context ): array {
		$notes = [];
		if ( $context['http'] ) {
			$notes[] = self::note( __( 'ChatGPT needs this site on an https address.', 'albert-ai-butler' ) );
		}
		$notes[] = self::note( __( 'Custom connectors need ChatGPT’s Developer mode, which is not on every plan.', 'albert-ai-butler' ), '', true );

		return self::guide(
			[
				'id'           => 'chatgpt',
				'label'        => __( 'ChatGPT', 'albert-ai-butler' ),
				'icon'         => 'chatgpt',
				'needs_public' => $context['local'],
				'steps'        => [
					self::step( __( 'In ChatGPT’s settings, turn on Developer mode.', 'albert-ai-butler' ) ),
					self::step( __( 'Create a connector, paste this address as its URL and choose OAuth.', 'albert-ai-butler' ), self::copy( __( 'Copy', 'albert-ai-butler' ), $context['endpoint'] ) ),
					self::approve_step(),
				],
				'notes'        => $notes,
				'unavailable'  => __( 'ChatGPT connects from OpenAI’s servers, which cannot reach a site on your computer.', 'albert-ai-butler' ),
			]
		);
	}

	/**
	 * Every other app: the URL first, then the common ones by name, then the
	 * Node.js bridge for apps that can only start a program.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.5.0
	 */
	private static function other_apps( array $context ): array {
		$endpoint = $context['endpoint'];

		return self::guide(
			[
				'id'    => 'other',
				'label' => __( 'Other apps', 'albert-ai-butler' ),
				'icon'  => 'other',
				'steps' => [
					self::step( __( 'Copy the MCP URL.', 'albert-ai-butler' ), self::copy( __( 'Copy', 'albert-ai-butler' ), $endpoint ) ),
					self::step( __( 'Paste it where your app adds an MCP server. If it asks how to sign in, choose OAuth.', 'albert-ai-butler' ) ),
					self::approve_step(),
				],
				'notes' => self::http_note( $context ),
				'more'  => [
					self::snippet(
						'codex',
						'Codex',
						__( 'Run in your terminal. The second command opens the sign-in.', 'albert-ai-butler' ),
						sprintf( "codex mcp add albert --url %s\ncodex mcp login albert", $endpoint )
					),
					self::snippet(
						'windsurf',
						'Windsurf',
						/* translators: %s: the Windsurf config file path. */
						sprintf( __( 'Add to %s', 'albert-ai-butler' ), '~/.codeium/windsurf/mcp_config.json' ),
						self::json_snippet( [ 'mcpServers' => [ 'albert' => [ 'serverUrl' => $endpoint ] ] ] )
					),
					self::snippet(
						'gemini',
						'Gemini CLI',
						/* translators: %s: the Gemini CLI sign-in command. */
						sprintf( __( 'Run in your terminal, then type %s in Gemini CLI to sign in.', 'albert-ai-butler' ), '/mcp auth albert' ),
						sprintf( 'gemini mcp add --transport http albert %s', $endpoint )
					),
					self::snippet(
						'cline',
						'Cline',
						__( 'In Cline, open MCP Servers, then Remote Servers, and paste this address.', 'albert-ai-butler' ),
						$endpoint
					),
					self::bridge( $context ),
				],
			]
		);
	}

	/**
	 * The Node.js bridge, for apps that can only start a local program.
	 *
	 * Pinned, so a new release of the bridge cannot change what runs on the
	 * user's computer without Albert shipping it.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return AlbertGuideSnippet The snippet.
	 * @since 1.5.0
	 */
	private static function bridge( array $context ): array {
		$server = [
			'command' => 'npx',
			'args'    => [ '-y', 'mcp-remote@' . ClaudeDesktopBundle::BRIDGE_VERSION, $context['endpoint'] ],
		];

		// A local https site's certificate is one Node does not trust by default.
		if ( $context['local'] && ! $context['http'] ) {
			$server['env'] = [ 'NODE_EXTRA_CA_CERTS' => self::local_root_certificate() ];
		}

		return self::snippet(
			'bridge',
			__( 'For apps that can’t connect to a URL. Needs Node.js 22 or newer.', 'albert-ai-butler' ),
			$context['insecure'] ? __( 'Needs this site on a secure https address, or on localhost.', 'albert-ai-butler' ) : '',
			self::json_snippet( [ 'mcpServers' => [ 'albert' => $server ] ] ),
			true
		);
	}

	/**
	 * The local root certificate's path, or a placeholder the person fills in.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function local_root_certificate(): string {
		return LocalCertificate::path() ?? __( '<path to your local root certificate>', 'albert-ai-butler' );
	}

	/**
	 * The last step every app shares: approving on this site.
	 *
	 * @return AlbertGuideStep The step.
	 * @since 1.5.0
	 */
	private static function approve_step(): array {
		return self::step( __( 'Your browser opens this site: sign in as someone listed under Who may connect, and choose Authorize.', 'albert-ai-butler' ) );
	}

	/**
	 * The note for apps that may refuse a plain http address other than localhost.
	 *
	 * @param array{endpoint: string, local: bool, http: bool, insecure: bool} $context The site.
	 *
	 * @return array<int, AlbertGuideNote> One note, or none on https.
	 * @since 1.5.0
	 */
	private static function http_note( array $context ): array {
		return $context['insecure'] ? [ self::note( __( 'This app may need a secure https address for your site.', 'albert-ai-butler' ) ) ] : [];
	}

	/**
	 * A guide with every key present, so each surface reads one shape.
	 *
	 * @param array<string, mixed> $fields The fields that differ from the defaults.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.5.0
	 */
	private static function guide( array $fields ): array {
		/**
		 * Every key is set by the defaults.
		 *
		 * @var AlbertClientGuide $guide
		 */
		$guide = array_merge(
			[
				'id'           => '',
				'label'        => '',
				'icon'         => '',
				'needs_public' => false,
				'intro'        => '',
				'steps'        => [],
				'notes'        => [],
				'manual'       => null,
				'more'         => [],
				'unavailable'  => '',
				'blocked'      => '',
			],
			$fields
		);

		return $guide;
	}

	/**
	 * A numbered step.
	 *
	 * @param string                 $text   What to do.
	 * @param AlbertGuideAction|null $action The step's one action, if it has one.
	 *
	 * @return AlbertGuideStep The step.
	 * @since 1.5.0
	 */
	private static function step( string $text, ?array $action = null ): array {
		return [
			'text'   => $text,
			'action' => $action,
		];
	}

	/**
	 * A link that opens an app.
	 *
	 * @param string $label The button label.
	 * @param string $url   The URL, often an app's own scheme.
	 *
	 * @return AlbertGuideAction The action.
	 * @since 1.5.0
	 */
	private static function link( string $label, string $url ): array {
		return [
			'type'  => 'link',
			'label' => $label,
			'value' => $url,
			'meta'  => '',
		];
	}

	/**
	 * Something to copy: a command or an address.
	 *
	 * @param string $label The button label.
	 * @param string $text  What is copied.
	 *
	 * @return AlbertGuideAction The action.
	 * @since 1.5.0
	 */
	private static function copy( string $label, string $text ): array {
		return [
			'type'  => 'copy',
			'label' => $label,
			'value' => $text,
			'meta'  => '',
		];
	}

	/**
	 * A requirement note, with an optional command to copy.
	 *
	 * A requirement that decides whether the steps can work at all (https,
	 * Copilot) goes above them; a plan limit or a fix for a problem that may
	 * come up goes below, so it does not stand between the person and step 1.
	 *
	 * @param string $text    The note.
	 * @param string $command A command to copy, or ''.
	 * @param bool   $after   Whether it goes below the steps.
	 *
	 * @return AlbertGuideNote The note.
	 * @since 1.5.0
	 */
	private static function note( string $text, string $command = '', bool $after = false ): array {
		return [
			'text'    => $text,
			'command' => $command,
			'after'   => $after,
		];
	}

	/**
	 * Manual setup, collapsed under "Set it up by hand".
	 *
	 * @param array<int, string> $steps         The steps.
	 * @param string             $snippet       What to paste.
	 * @param string             $snippet_label What the snippet is.
	 * @param string             $path          The file it goes in, or ''.
	 *
	 * @return AlbertGuideManual The manual setup.
	 * @since 1.5.0
	 */
	private static function manual( array $steps, string $snippet, string $snippet_label, string $path = '' ): array {
		return [
			'steps'         => $steps,
			'path'          => $path,
			'snippet'       => $snippet,
			'snippet_label' => $snippet_label,
		];
	}

	/**
	 * One app's snippet under Other apps.
	 *
	 * @param string $id      A stable id.
	 * @param string $label   The app's name.
	 * @param string $hint    Where it goes.
	 * @param string $snippet What to copy.
	 * @param bool   $quiet   Whether to render it as the last, quietest option.
	 *
	 * @return AlbertGuideSnippet The snippet.
	 * @since 1.5.0
	 */
	private static function snippet( string $id, string $label, string $hint, string $snippet, bool $quiet = false ): array {
		return [
			'id'      => $id,
			'label'   => $label,
			'hint'    => $hint,
			'snippet' => $snippet,
			'quiet'   => $quiet,
		];
	}

	/**
	 * The site title as plain text, for an app that shows it as a name.
	 *
	 * Entities decoded so "Tom &amp; Jerry" is not encoded a second time.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function site_title(): string {
		$title = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return $title !== '' ? $title : 'Albert';
	}

	/**
	 * Pretty-print a configuration fragment for a copy button.
	 *
	 * @param array<string, mixed> $config The configuration structure.
	 *
	 * @return string Indented JSON, or an empty string if encoding fails.
	 * @since 1.4.0
	 */
	private static function json_snippet( array $config ): string {
		$json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return $json === false ? '' : $json;
	}
}
