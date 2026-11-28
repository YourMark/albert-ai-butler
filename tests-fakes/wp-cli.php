<?php
/**
 * A stand-in for the WP-CLI API the commands use, so they run inside the test suite.
 *
 * Kept outside tests/: the Jetpack autoloader maps every class under an autoload
 * path, dev ones included, and its prepended loader would serve these instead of
 * the real WP-CLI's on any install with dev dependencies.
 *
 * @package Albert
 */

// phpcs:ignoreFile -- Test fake: several namespaces in one file.

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/** @var array<int, array{0: string, 1: string}> Lines printed, as [ type, message ]. */
			public static array $output = [];

			/** @var array<int, string> Command names, in the order they were registered. */
			public static array $commands = [];

			/** @var array<string, string> Subcommands waiting for their parent, as [ name => parent ]. */
			public static array $deferred = [];

			// Like WP-CLI: `a b` is held back, and add_command() returns false, until `a` exists.
			public static function add_command( $name, $callable, $args = [] ): bool {
				$space = strrpos( $name, ' ' );

				if ( $space !== false && ! in_array( substr( $name, 0, $space ), self::$commands, true ) ) {
					self::$deferred[ $name ] = substr( $name, 0, $space );
					return false;
				}

				self::$commands[] = $name;

				foreach ( array_keys( self::$deferred, $name, true ) as $child ) {
					unset( self::$deferred[ $child ] );
					self::add_command( $child, null );
				}

				return true;
			}

			public static function log( $message ): void {
				self::$output[] = [ 'log', $message ];
			}

			public static function success( $message ): void {
				self::$output[] = [ 'success', $message ];
			}

			public static function warning( $message ): void {
				self::$output[] = [ 'warning', $message ];
			}

			// Throws instead of exiting, so a test can carry on.
			public static function error( $message ): never {
				self::$output[] = [ 'error', $message ];
				throw new \RuntimeException( $message );
			}
		}
	}
}

namespace WP_CLI {
	if ( ! class_exists( __NAMESPACE__ . '\Formatter' ) ) {
		class Formatter {
			/** @var array<int, mixed> What the last display_items() call was given. */
			public static array $items = [];

			// Like WP-CLI's: the format arguments are taken out of the caller's array.
			public function __construct( &$assoc_args, $fields = null ) {
				unset( $assoc_args['format'], $assoc_args['fields'], $assoc_args['field'] );
			}

			public function display_items( $items ): void {
				self::$items = $items;
			}
		}
	}
}

namespace WP_CLI\Dispatcher {
	if ( ! class_exists( __NAMESPACE__ . '\CommandNamespace' ) ) {
		class CommandNamespace {}
	}
}

namespace WP_CLI\Fetchers {
	if ( ! class_exists( __NAMESPACE__ . '\User' ) ) {
		// WP-CLI's lookup order: numeric is an ID, an email falls back to login.
		class User {
			public function get_many( $args ): array {
				$users = [];

				foreach ( $args as $arg ) {
					if ( is_numeric( $arg ) ) {
						$user = get_user_by( 'id', $arg );
					} elseif ( is_email( $arg ) ) {
						$user = get_user_by( 'email', $arg ) ?: get_user_by( 'login', $arg );
					} else {
						$user = get_user_by( 'login', $arg );
					}

					if ( $user ) {
						$users[] = $user;
					} else {
						\WP_CLI::warning( "Invalid user ID, email or login: '$arg'" );
					}
				}

				return $users;
			}
		}
	}
}
