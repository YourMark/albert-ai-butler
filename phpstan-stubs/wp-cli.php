<?php
/**
 * Stubs for the WP-CLI API Albert's commands call. WP-CLI is not a dependency.
 *
 * @package Albert
 */

namespace {
	class WP_CLI {
		/**
		 * @param string               $name
		 * @param class-string|callable $callable
		 * @param array<string, mixed> $args
		 */
		public static function add_command( $name, $callable, $args = [] ): bool {
			return true;
		}

		/** @param string $message */
		public static function log( $message ): void {}

		/** @param string $message */
		public static function success( $message ): void {}

		/** @param string $message */
		public static function warning( $message ): void {}

		/**
		 * @param string   $message
		 * @param bool|int $exit
		 */
		public static function error( $message, $exit = true ): never {
			exit( 1 );
		}
	}
}

namespace WP_CLI {
	class Formatter {
		/**
		 * @param array<string, mixed>      $assoc_args
		 * @param array<int, string>|null   $fields
		 * @param string|false              $prefix
		 */
		public function __construct( &$assoc_args, $fields = null, $prefix = false ) {}

		/**
		 * @param iterable<mixed> $items
		 * @param bool|array<int, string> $ascii_pre_colorized
		 */
		public function display_items( $items, $ascii_pre_colorized = false ): void {}
	}
}

namespace WP_CLI\Dispatcher {
	class CommandNamespace {}
}

namespace WP_CLI\Fetchers {
	class User {
		/**
		 * @param array<int, string> $args
		 * @return array<int, \WP_User>
		 */
		public function get_many( $args ) {
			return [];
		}
	}
}
