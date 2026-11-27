<?php
/**
 * WP-CLI: the `wp albert` namespace.
 *
 * @package Albert
 * @subpackage CLI
 * @since      1.6.0
 */

namespace Albert\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Albert from the command line.
 *
 * @since 1.6.0
 */
class AlbertNamespace extends \WP_CLI\Dispatcher\CommandNamespace {}
