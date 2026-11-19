<?php
/**
 * A stand-in for the WP-CLI API the commands use, so they run inside the test suite.
 *
 * @package Albert
 */

// phpcs:ignoreFile -- Test fake: several namespaces in one file.

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/** @var array<int, array{0: string, 1: string}> Lines printed, as [ type, message ]. */
			public static array $output = [];

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
			public static function error( $message ) {
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

			public function __construct( &$assoc_args, $fields = null ) {}

			public function display_items( $items ): void {
				self::$items = $items;
			}
		}
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
