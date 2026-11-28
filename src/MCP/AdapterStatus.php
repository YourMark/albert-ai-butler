<?php
/**
 * MCP adapter availability reporting.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use WP\MCP\Core\McpAdapter;

/**
 * AdapterStatus class
 *
 * Answers whether the MCP library Albert needs is actually present, and usable.
 *
 * Both "absent" and "present but older than Albert expects" end in the same
 * symptom: no MCP server registers, and `/albert/v1/mcp` answers
 * `401 rest_forbidden` — which is also the correct response for a healthy
 * install handed no token. Nothing outside the site can tell those apart, so a
 * site owner debugging it reaches for their token, their client and their proxy
 * before suspecting the plugin. This class exists so they are told instead.
 *
 * Note what is deliberately *not* here: any notion of a conflicting second
 * copy. Jetpack Autoloader resolves one newest copy of `WP\MCP\` across every
 * plugin that ships it, and Albert only offers its own when the MCP Adapter
 * plugin is not active ({@see AdapterSource}), so another copy on the site is
 * normal and not something to warn about.
 *
 * @since 1.4.0
 */
class AdapterStatus {

	/**
	 * The symbols Albert calls into, which a usable copy must provide.
	 *
	 * Detected by symbol rather than by version number, matching how the rest of
	 * this plugin handles capability checks: the site may be running another
	 * plugin's copy of the library, and what matters is whether that copy offers
	 * what Albert calls, not what it is called.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	private const REQUIRED_SYMBOLS = [
		McpAdapter::class,
		\WP\MCP\Core\McpServer::class,
		\WP\MCP\Transport\HttpTransport::class,
		\WP\MCP\Domain\Prompts\McpPrompt::class,
		\WP\MCP\Domain\Resources\McpResource::class,
		\WP\MCP\Abilities\McpAbilityExposure::class,
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface::class,
		// A trait, and the reason the check below uses three functions rather
		// than one: `class_exists()` is false for a trait, so a class-only
		// check called the library usable while
		// {@see \Albert\Logging\ObservabilityHandler} — which does
		// `use McpObservabilityHelperTrait;` — would fatal on load.
		\WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait::class,
	];

	/**
	 * Whether the MCP library is present and offers what Albert calls.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function adapter_available(): bool {
		return self::missing_classes() === [];
	}

	/**
	 * Which of the classes Albert needs are not loadable.
	 *
	 * An empty array means the library is usable. A non-empty one means either
	 * the library is absent altogether, or the copy that won resolution is older
	 * than Albert requires — the two are reported differently, because the
	 * remedies differ.
	 *
	 * @return array<int, string> Fully-qualified class names.
	 * @since 1.4.0
	 */
	public static function missing_classes(): array {
		return array_values(
			array_filter(
				self::REQUIRED_SYMBOLS,
				static fn( string $name ): bool => ! class_exists( $name ) && ! interface_exists( $name ) && ! trait_exists( $name )
			)
		);
	}

	/**
	 * Whether any part of the library is loadable at all.
	 *
	 * Distinguishes "not installed" from "installed but too old": if even the
	 * adapter entry point is absent, no copy was resolved, which on a source
	 * install means the dependencies were never installed.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function adapter_present(): bool {
		return class_exists( McpAdapter::class );
	}

	/**
	 * The file the resolved adapter was loaded from, for support diagnostics.
	 *
	 * Under Jetpack Autoloader this may well be another plugin's copy, which is
	 * expected and is exactly why it is worth reporting.
	 *
	 * @return string|null Path, or null when nothing is loaded.
	 * @since 1.4.0
	 */
	public static function adapter_path(): ?string {
		if ( ! class_exists( McpAdapter::class ) ) {
			return null;
		}

		try {
			$file = ( new \ReflectionClass( McpAdapter::class ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return null;
		}

		if ( ! is_string( $file ) ) {
			return null;
		}

		$plugins = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );

		return str_replace( $plugins, '', wp_normalize_path( $file ) );
	}
}
