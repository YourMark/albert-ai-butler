<?php
/**
 * Abilities Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;

/**
 * Abilities class
 *
 * Manages the abilities listing and configuration page.
 *
 * @since 1.0.0
 */
class Abilities implements Hookable {

	/**
	 * Page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'ai-bridge-abilities';

	/**
	 * Option group name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option_group = 'aibridge_settings';

	/**
	 * Option name for storing enabled permissions.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option_name = 'aibridge_enabled_permissions';

	/**
	 * Available tabs.
	 *
	 * @since 1.0.0
	 * @var array<string, string>|null
	 */
	private ?array $tabs = null;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Get available tabs.
	 *
	 * Tabs are lazy-loaded to avoid translation functions being called too early.
	 *
	 * @return array<string, string> Array of tab slug => label pairs.
	 * @since 1.0.0
	 */
	private function get_tabs(): array {
		if ( $this->tabs === null ) {
			$this->tabs = [
				'core' => __( 'Core', 'albert' ),
			];

			/**
			 * Filter the available abilities tabs.
			 *
			 * Use this filter to add custom tabs for plugin integrations.
			 * Example: $tabs['woocommerce'] = __( 'WooCommerce', 'my-plugin' );
			 *
			 * @param array $tabs Array of tab slug => label pairs.
			 *
			 * @since 1.0.0
			 */
			$this->tabs = apply_filters( 'aibridge/abilities/tabs', $this->tabs );
		}

		return $this->tabs;
	}

	/**
	 * Get the current active tab.
	 *
	 * @return string The current tab slug.
	 * @since 1.0.0
	 */
	private function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation doesn't require nonce.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'core';

		$tabs = $this->get_tabs();

		// Ensure the tab exists, fallback to first tab.
		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = (string) array_key_first( $tabs );
		}

		return $tab;
	}

	/**
	 * Add menu pages.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_pages(): void {
		// Add Abilities submenu under AI Bridge (created by Dashboard).
		add_submenu_page(
			'albert', // Parent slug.
			__( 'Abilities', 'albert' ),
			__( 'Abilities', 'albert' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting(
			$this->option_group,
			$this->option_name,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Render the tab navigation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_tabs(): void {
		$current_tab = $this->get_current_tab();
		$tabs        = $this->get_tabs();

		if ( count( $tabs ) <= 1 ) {
			return;
		}
		?>
		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Abilities tabs', 'albert' ); ?>">
			<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->page_slug . '&tab=' . $tab_slug ) ); ?>"
					class="nav-tab <?php echo $current_tab === $tab_slug ? 'nav-tab-active' : ''; ?>"
					<?php echo $current_tab === $tab_slug ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert' ) );
		}

		$current_tab       = $this->get_current_tab();
		$grouped_abilities = $this->get_abilities_for_tab( $current_tab );
		?>
		<div class="wrap aibridge-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Beta Version:', 'albert' ); ?></strong>
					<?php esc_html_e( 'This plugin is currently in beta and is intended for testing purposes only. Please use with caution and do not use on production sites.', 'albert' ); ?>
				</p>
			</div>

			<?php settings_errors(); ?>

			<?php $this->render_tabs(); ?>

			<div class="ea-page-layout">
				<?php $this->render_sidebar( $grouped_abilities ); ?>

				<div class="ea-main-content">
					<div class="aibridge-tab-content">
						<?php $this->render_abilities_content( $current_tab, $grouped_abilities ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sidebar navigation.
	 *
	 * @param array<string, mixed> $grouped_abilities Grouped abilities data.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_sidebar( array $grouped_abilities ): void {
		if ( empty( $grouped_abilities ) ) {
			return;
		}

		$options = get_option( $this->option_name, [] );
		?>
		<aside class="ea-sidebar" aria-label="<?php esc_attr_e( 'Abilities navigation', 'albert' ); ?>">
			<div class="ea-sidebar-save">
				<?php submit_button( __( 'Save Changes', 'albert' ), 'primary', 'submit', false, [ 'form' => 'aibridge-form' ] ); ?>
			</div>
			<h2 class="ea-sidebar-title"><?php esc_html_e( 'Quick Nav', 'albert' ); ?></h2>
			<nav>
				<ul class="ea-sidebar-nav">
					<?php foreach ( $grouped_abilities as $category => $data ) : ?>
						<?php
						if ( empty( $data['types'] ) ) {
							continue;
						}
						foreach ( $data['types'] as $type_key => $type_data ) :
							$group_anchor = 'group-' . sanitize_key( $category . '-' . $type_key );

							// Count total permissions (read + write).
							$permission_count = 0;
							if ( isset( $type_data['read'] ) ) {
								++$permission_count;
							}
							if ( isset( $type_data['write'] ) ) {
								++$permission_count;
							}

							// Count enabled permissions.
							$enabled_count = 0;
							if ( isset( $type_data['read'] ) && in_array( $type_key . '_read', $options, true ) ) {
								++$enabled_count;
							}
							if ( isset( $type_data['write'] ) && in_array( $type_key . '_write', $options, true ) ) {
								++$enabled_count;
							}

							$icon = $this->get_group_icon( $type_key );
							?>
							<li>
								<a href="#<?php echo esc_attr( $group_anchor ); ?>">
									<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
									<?php echo esc_html( $type_data['label'] ); ?>
									<span class="ea-nav-count"><?php echo esc_html( $enabled_count . '/' . $permission_count ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</ul>
			</nav>
		</aside>
		<?php
	}

	/**
	 * Get icon class for a group.
	 *
	 * @param string $group Group identifier.
	 *
	 * @return string Dashicon class.
	 * @since 1.0.0
	 */
	private function get_group_icon( string $group ): string {
		$icons = [
			'posts'      => 'dashicons-admin-post',
			'pages'      => 'dashicons-admin-page',
			'media'      => 'dashicons-admin-media',
			'users'      => 'dashicons-admin-users',
			'comments'   => 'dashicons-admin-comments',
			'plugins'    => 'dashicons-admin-plugins',
			'themes'     => 'dashicons-admin-appearance',
			'taxonomies' => 'dashicons-category',
			'site'       => 'dashicons-admin-site',
		];

		/**
		 * Filter group icons.
		 *
		 * @param array $icons Group icon mappings.
		 *
		 * @since 1.0.0
		 */
		$icons = apply_filters( 'aibridge/abilities/group_icons', $icons );

		return $icons[ $group ] ?? 'dashicons-admin-generic';
	}

	/**
	 * Render abilities content for the current tab.
	 *
	 * @param string               $tab Current tab slug.
	 * @param array<string, mixed> $grouped_abilities Pre-fetched grouped abilities data.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_abilities_content( string $tab, array $grouped_abilities ): void {
		$options = get_option( $this->option_name, [] );
		?>
		<form method="post" action="options.php" id="aibridge-form" aria-label="<?php esc_attr_e( 'AI Bridge Settings', 'albert' ); ?>">
			<?php settings_fields( $this->option_group ); ?>

			<div class="ea-content-header">
				<p class="ea-content-description">
					<?php esc_html_e( 'Select which abilities AI assistants can use on your site. Only enable abilities you trust.', 'albert' ); ?>
				</p>
				<span class="ea-content-actions">
					<button type="button" class="ea-action-link" id="ea-expand-all"><?php esc_html_e( 'Expand all', 'albert' ); ?></button>
					<span class="ea-action-separator" aria-hidden="true">·</span>
					<button type="button" class="ea-action-link" id="ea-collapse-all"><?php esc_html_e( 'Collapse all', 'albert' ); ?></button>
				</span>
			</div>

			<?php if ( ! empty( $grouped_abilities ) ) : ?>
				<div class="ea-groups-grid">
					<?php foreach ( $grouped_abilities as $category => $data ) : ?>
						<?php
						// Render each content type as a separate card.
						if ( ! empty( $data['types'] ) ) {
							foreach ( $data['types'] as $type_key => $type_data ) {
								$this->render_permission_group_card( $type_key, $type_data, $category, $options );
							}
						}
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( empty( $grouped_abilities ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'No abilities are currently registered for this category. Abilities will appear here once they are registered.', 'albert' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $grouped_abilities ) ) : ?>
				<div class="ea-mobile-save">
					<?php submit_button( __( 'Save Changes', 'albert' ), 'primary', 'submit-mobile', false ); ?>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render a permission group card (e.g., Posts with Read/Write toggles).
	 *
	 * @param string               $type_key   Content type key (e.g., 'posts', 'pages').
	 * @param array<string, mixed> $type_data  Type data including label, read, and write permissions.
	 * @param string               $category   Parent category.
	 * @param array<string>        $options    Current enabled permission keys.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_permission_group_card( string $type_key, array $type_data, string $category, array $options ): void {
		$card_id        = 'group-' . sanitize_key( $category . '-' . $type_key );
		$toggle_all_id  = 'toggle-all-' . sanitize_key( $category . '-' . $type_key );
		$items_id       = 'group-items-' . sanitize_key( $category . '-' . $type_key );
		$subgroup_class = 'subgroup-' . esc_attr( $category . '-' . $type_key );
		$icon           = $this->get_group_icon( $type_key );

		// Permission keys.
		$read_permission  = $type_key . '_read';
		$write_permission = $type_key . '_write';

		// Check if permissions are enabled.
		$read_enabled  = in_array( $read_permission, $options, true );
		$write_enabled = in_array( $write_permission, $options, true );
		?>
		<section class="ability-group" id="<?php echo esc_attr( $card_id ); ?>" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
			<div class="ability-group-header">
				<div class="ability-group-title-wrapper">
					<button
							type="button"
							class="ability-group-collapse-toggle"
							aria-expanded="true"
							aria-controls="<?php echo esc_attr( $items_id ); ?>"
					>
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</button>
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true" style="color: var(--ea-text-secondary);"></span>
					<h2 class="ability-group-title" id="<?php echo esc_attr( 'title-' . $card_id ); ?>">
						<?php echo esc_html( $type_data['label'] ); ?>
					</h2>
				</div>
				<div class="ability-group-toggle-all">
					<label class="aibridge-toggle" for="<?php echo esc_attr( $toggle_all_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $toggle_all_id ); ?>"
								class="toggle-subgroup-abilities"
								data-subgroup="<?php echo esc_attr( $subgroup_class ); ?>"
								<?php /* translators: %s: content type name */ ?>
								aria-label="<?php echo esc_attr( sprintf( __( 'Enable all %s permissions', 'albert' ), $type_data['label'] ) ); ?>"
						/>
						<span class="aibridge-toggle-slider" aria-hidden="true"></span>
					</label>
					<label for="<?php echo esc_attr( $toggle_all_id ); ?>">
						<?php esc_html_e( 'Enable All', 'albert' ); ?>
					</label>
				</div>
			</div>

			<div class="ability-group-items" id="<?php echo esc_attr( $items_id ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
				<div class="ability-subgroup-items">
					<?php
					// Render Read permission.
					if ( isset( $type_data['read'] ) ) {
						$this->render_permission_item( $read_permission, $type_data['read'], $subgroup_class, $read_enabled );
					}

					// Render Write permission.
					if ( isset( $type_data['write'] ) ) {
						$this->render_permission_item( $write_permission, $type_data['write'], $subgroup_class, $write_enabled );
					}
					?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a single permission item (Read or Write).
	 *
	 * @param string               $permission_key  Permission key (e.g., 'posts_read').
	 * @param array<string, mixed> $permission_data Permission data including label and description.
	 * @param string               $subgroup_class  CSS class for grouping.
	 * @param bool                 $enabled         Whether this permission is enabled.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_permission_item( string $permission_key, array $permission_data, string $subgroup_class, bool $enabled ): void {
		$field_name      = $this->option_name . '[]';
		$field_id        = 'permission-' . sanitize_key( $permission_key );
		$has_description = ! empty( $permission_data['description'] );
		$checkbox_class  = 'ability-checkbox';

		if ( ! empty( $subgroup_class ) ) {
			$checkbox_class .= ' ability-checkbox-subgroup ' . esc_attr( $subgroup_class );
		}
		?>
		<div class="ability-item">
			<div class="ability-item-toggle">
				<label class="aibridge-toggle" for="<?php echo esc_attr( $field_id ); ?>">
					<input
							type="checkbox"
							id="<?php echo esc_attr( $field_id ); ?>"
							name="<?php echo esc_attr( $field_name ); ?>"
							value="<?php echo esc_attr( $permission_key ); ?>"
							class="<?php echo esc_attr( $checkbox_class ); ?>"
						<?php if ( $has_description ) : ?>
							aria-describedby="<?php echo esc_attr( $field_id . '-description' ); ?>"
						<?php endif; ?>
						<?php checked( $enabled ); ?>
					/>
					<span class="aibridge-toggle-slider" aria-hidden="true"></span>
				</label>
			</div>
			<div class="ability-item-content">
				<label class="ability-item-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $permission_data['label'] ); ?>
				</label>
				<?php if ( $has_description ) : ?>
					<p class="ability-item-description" id="<?php echo esc_attr( $field_id . '-description' ); ?>">
						<?php echo esc_html( $permission_data['description'] ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}


	/**
	 * Get abilities for a specific tab.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return array<string, mixed> Grouped abilities for the tab.
	 * @since 1.0.0
	 */
	private function get_abilities_for_tab( string $tab ): array {
		$all_groups = AbilitiesRegistry::get_ability_groups();
		$grouped    = [];

		// Map tabs to group keys.
		if ( $tab === 'core' && isset( $all_groups['wordpress'] ) ) {
			$grouped['wordpress'] = $all_groups['wordpress'];
		} elseif ( isset( $all_groups[ $tab ] ) ) {
			$grouped[ $tab ] = $all_groups[ $tab ];
		}

		return $grouped;
	}





	/**
	 * Sanitize settings.
	 *
	 * Only checked checkboxes are submitted. We simply store those as enabled.
	 *
	 * @param array<string, mixed>|mixed $input Input settings.
	 *
	 * @return array<string, bool>
	 * @since 1.0.0
	 */
	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw input from form submission.
	 *
	 * @return array<string> Sanitized array of permission keys.
	 * @since 1.0.0
	 */
	public function sanitize_settings( $input ): array {
		$sanitized = [];

		if ( is_array( $input ) ) {
			foreach ( $input as $permission_key ) {
				$permission_key = sanitize_key( $permission_key );
				if ( $this->is_valid_permission_key( $permission_key ) ) {
					$sanitized[] = $permission_key;
				}
			}
		}

		return array_unique( $sanitized );
	}

	/**
	 * Get currently enabled permissions.
	 *
	 * @return array<string> Array of enabled permission keys.
	 * @since 1.0.0
	 */
	public static function get_enabled_permissions(): array {
		return get_option( 'aibridge_enabled_permissions', self::get_default_permissions() );
	}

	/**
	 * Get default permissions (all read enabled, write disabled).
	 *
	 * @return array<string> Array of default permission keys.
	 * @since 1.0.0
	 */
	public static function get_default_permissions(): array {
		return AbilitiesRegistry::get_default_permissions();
	}

	/**
	 * Check if permission key is valid.
	 *
	 * @param string $permission_key Permission key (e.g., 'posts_read', 'posts_write').
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_valid_permission_key( string $permission_key ): bool {
		$groups = AbilitiesRegistry::get_ability_groups();

		foreach ( $groups as $group ) {
			foreach ( $group['types'] as $type_key => $type ) {
				foreach ( [ 'read', 'write' ] as $permission ) {
					if ( ! isset( $type[ $permission ] ) ) {
						continue;
					}

					if ( $type_key . '_' . $permission === $permission_key ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		// Hook format for submenu is: {parent_slug}_page_{menu_slug}.
		if ( 'ai-bridge_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'aibridge-admin',
			AIBRIDGE_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			AIBRIDGE_VERSION
		);

		wp_enqueue_script(
			'aibridge-admin',
			AIBRIDGE_PLUGIN_URL . 'assets/js/admin-settings.js',
			[],
			AIBRIDGE_VERSION,
			true
		);
	}
}
