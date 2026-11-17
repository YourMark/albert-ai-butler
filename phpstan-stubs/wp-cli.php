<?php
/**
 * Stubs for the WP-CLI API Albert's commands call.
 *
 * WP-CLI is not a dependency, so only the signatures used are declared.
 *
 * @package Albert
 */

namespace {
	/**
	 * WP-CLI's static API.
	 */
	class WP_CLI {

		/**
		 * Register a command.
		 *
		 * @param string               $name     The command name.
		 * @param callable|string      $callable A class name, object or callable.
		 * @param array<string, mixed> $args     Registration arguments.
		 *
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = [] ) {
			return true;
		}

		/**
		 * Print a line.
		 *
		 * @param string $message The message.
		 *
		 * @return void
		 */
		public static function log( $message ) {}

		/**
		 * Print a success message.
		 *
		 * @param string $message The message.
		 *
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * Print a warning.
		 *
		 * @param string $message The message.
		 *
		 * @return void
		 */
		public static function warning( $message ) {}

		/**
		 * Print an error and exit.
		 *
		 * @param string   $message The message.
		 * @param bool|int $exit    Exit code, or false to continue.
		 *
		 * @return never
		 */
		public static function error( $message, $exit = true ) {
			exit( 1 );
		}
	}
}

namespace WP_CLI\Utils {
	/**
	 * Render a list of items in a format.
	 *
	 * @param string                               $format The format.
	 * @param array<int, array<string, int|string>> $items  The rows.
	 * @param array<int, string>|string            $fields The columns.
	 *
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {}
}
