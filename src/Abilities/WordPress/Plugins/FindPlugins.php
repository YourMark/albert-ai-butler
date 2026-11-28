<?php
/**
 * Find Plugins Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Plugins
 * @since      1.7.0
 */

namespace Albert\Abilities\WordPress\Plugins;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use Albert\MCP\AdapterSource;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the installed plugins, as the Plugins screen shows them.
 *
 * Updates come from what WordPress already knows (the `update_plugins` site
 * transient), never from a request to WordPress.org, so the call stays fast
 * and works offline.
 *
 * @since 1.7.0
 */
class FindPlugins extends BaseAbility {

	/**
	 * The status filter's values.
	 *
	 * @since 1.7.0
	 * @var string[]
	 */
	private const STATUSES = [ 'all', 'active', 'inactive', 'update_available' ];

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-plugins';
		$this->label       = __( 'Find Plugins', 'albert-ai-butler' );
		$this->description = __( 'List the installed plugins with their version, whether each is active and whether an update is waiting; use it when the person asks which plugins or versions the site runs.', 'albert-ai-butler' );
		$this->category    = 'maintenance';
		$this->group       = 'plugins';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Updates are what WordPress learned at its last update check (updates_checked_at); this call does not ask WordPress.org. '
				. 'update_available is null when WordPress has not checked a plugin, so report it as unknown, not as up to date. '
				. 'is_albert and is_mcp_adapter mark the plugins this connection runs on: switching either off ends it.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.7.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status' => [
					'type'        => 'string',
					'enum'        => self::STATUSES,
					'description' => 'Which plugins to list: all, active, inactive, or only those with an update waiting.',
					'default'     => 'all',
				],
			],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.7.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugins'            => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'             => [ 'type' => 'string' ],
							'plugin'           => [
								'type'        => 'string',
								'description' => 'The plugin file WordPress identifies it by, such as "akismet/akismet.php".',
							],
							'slug'             => [ 'type' => 'string' ],
							'version'          => [ 'type' => 'string' ],
							'author'           => [ 'type' => 'string' ],
							'active'           => [ 'type' => 'boolean' ],
							'network_active'   => [
								'type'        => 'boolean',
								'description' => 'Multisite only: active on every site of the network.',
							],
							'update_available' => [
								'type'        => [ 'boolean', 'null' ],
								'description' => 'Null when WordPress has not checked this plugin for updates.',
							],
							'new_version'      => [
								'type'        => 'string',
								'description' => 'The version an update would install. Only when update_available is true.',
							],
							'is_albert'        => [ 'type' => 'boolean' ],
							'is_mcp_adapter'   => [ 'type' => 'boolean' ],
						],
						'required'   => [ 'name', 'plugin', 'slug', 'version', 'author', 'active', 'update_available', 'is_albert', 'is_mcp_adapter' ],
					],
				],
				'total'              => [ 'type' => 'integer' ],
				'updates_checked_at' => [
					'type'        => [ 'string', 'null' ],
					'description' => 'When WordPress last checked for plugin updates (UTC, ISO 8601), or null when it never has.',
				],
			],
			'required'   => [ 'plugins', 'total', 'updates_checked_at' ],
		];
	}

	/**
	 * Listing plugins needs what the Plugins screen needs.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 * @since 1.7.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'activate_plugins' );
	}

	/**
	 * List the installed plugins.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $status One of all, active, inactive, update_available. Default all.
	 * }
	 * @return array<string, mixed>|WP_Error
	 * @since 1.7.0
	 */
	public function execute( array $args ): array|WP_Error {
		$status = isset( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : 'all';

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates   = get_site_transient( 'update_plugins' );
		$checked   = is_object( $updates ) && isset( $updates->checked ) && is_array( $updates->checked ) ? $updates->checked : [];
		$available = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : [];
		$last      = is_object( $updates ) && ! empty( $updates->last_checked ) ? (int) $updates->last_checked : 0;

		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			$row = $this->to_row( (string) $file, $data, $checked, $available );

			if ( ! $this->matches( $row, $status ) ) {
				continue;
			}

			$plugins[] = $row;
		}

		return [
			'plugins'            => $plugins,
			'total'              => count( $plugins ),
			'updates_checked_at' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : null,
		];
	}

	/**
	 * One plugin as the result lists it.
	 *
	 * @param string                $file      The plugin file, relative to the plugins directory.
	 * @param array<string, mixed>  $data      Its header data from get_plugins().
	 * @param array<string, string> $checked   Versions WordPress checked, by plugin file.
	 * @param array<string, mixed>  $available Waiting updates, by plugin file.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	private function to_row( string $file, array $data, array $checked, array $available ): array {
		$row = [
			'name'    => (string) ( $data['Name'] ?? '' ),
			'plugin'  => $file,
			// A single-file plugin has no directory; its file name is its slug.
			'slug'    => str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'author'  => (string) ( $data['Author'] ?? '' ),
			'active'  => is_plugin_active( $file ),
		];

		if ( is_multisite() ) {
			$row['network_active'] = is_plugin_active_for_network( $file );
		}

		$update = $available[ $file ] ?? null;
		if ( is_object( $update ) && ! empty( $update->new_version ) ) {
			$row['update_available'] = true;
			$row['new_version']      = (string) $update->new_version;
		} else {
			// A plugin installed since the last check was never checked, so its update is unknown.
			$row['update_available'] = isset( $checked[ $file ] ) ? false : null;
		}

		$row['is_albert']      = $file === ALBERT_PLUGIN_BASENAME;
		$row['is_mcp_adapter'] = $file === AdapterSource::PLUGIN_FILE;

		return $row;
	}

	/**
	 * Whether a row passes the status filter.
	 *
	 * @param array<string, mixed> $row    The plugin row.
	 * @param string               $status The filter.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private function matches( array $row, string $status ): bool {
		return match ( $status ) {
			'active'           => $row['active'],
			'inactive'         => ! $row['active'],
			'update_available' => $row['update_available'] === true,
			default            => true,
		};
	}
}
