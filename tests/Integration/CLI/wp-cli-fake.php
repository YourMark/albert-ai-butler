<?php
/**
 * A stand-in for WP-CLI's output API, so commands run inside the test suite.
 *
 * Records what a command prints; error() throws instead of exiting.
 *
 * @package Albert
 */

// phpcs:ignoreFile -- A global class and a namespaced function need two namespace blocks.

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Records WP-CLI output.
		 */
		class WP_CLI {

			/**
			 * Lines printed, as `[ type, message ]`.
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public static array $output = [];

			/**
			 * The rows the last format_items() call was given.
			 *
			 * @var array<int, array<string, int|string>>
			 */
			public static array $items = [];

			/**
			 * Forget everything recorded.
			 *
			 * @return void
			 */
			public static function reset(): void {
				self::$output = [];
				self::$items  = [];
			}

			/**
			 * Record a line.
			 *
			 * @param string $message The message.
			 *
			 * @return void
			 */
			public static function log( $message ): void {
				self::$output[] = [ 'log', $message ];
			}

			/**
			 * Record a success.
			 *
			 * @param string $message The message.
			 *
			 * @return void
			 */
			public static function success( $message ): void {
				self::$output[] = [ 'success', $message ];
			}

			/**
			 * Record a warning.
			 *
			 * @param string $message The message.
			 *
			 * @return void
			 */
			public static function warning( $message ): void {
				self::$output[] = [ 'warning', $message ];
			}

			/**
			 * Record an error and stop the command.
			 *
			 * @param string $message The message.
			 *
			 * @return never
			 * @throws \RuntimeException Always.
			 */
			public static function error( $message ) {
				self::$output[] = [ 'error', $message ];
				throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test fake.
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
		/**
		 * Record the rows a command would print.
		 *
		 * @param string                                $format The format.
		 * @param array<int, array<string, int|string>> $items  The rows.
		 * @param array<int, string>|string             $fields The columns.
		 *
		 * @return void
		 */
		function format_items( $format, $items, $fields ): void {
			\WP_CLI::$items = $items;
		}
	}
}
