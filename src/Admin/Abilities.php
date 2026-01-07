<?php
/**
 * Abilities Admin Page
 *
 * @package    ExtendedAbilities
 * @subpackage Admin
 * @since      1.0.0
 */

namespace ExtendedAbilities\Admin;

use ExtendedAbilities\Contracts\Interfaces\Hookable;

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
	private string $page_slug = 'extended-abilities';

	/**
	 * Option group name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option_group = 'extended_abilities_settings';

	/**
	 * Option name for storing settings.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option_name = 'extended_abilities_options';

	/**
	 * Available tabs.
	 *
	 * @since 1.0.0
	 * @var array|null
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
	 * @return array Array of tab slug => label pairs.
	 * @since 1.0.0
	 */
	private function get_tabs(): array {
		if ( null === $this->tabs ) {
			$this->tabs = [
				'core' => __( 'Core', 'extended-abilities' ),
			];

			// Add WooCommerce tab if WooCommerce is active.
			if ( class_exists( 'WooCommerce' ) ) {
				$this->tabs['woocommerce'] = __( 'WooCommerce', 'extended-abilities' );
			}

			// Add ACF tab if ACF is active.
			if ( class_exists( 'ACF' ) ) {
				$this->tabs['acf'] = __( 'ACF', 'extended-abilities' );
			}

			/**
			 * Filter the available abilities tabs.
			 *
			 * @param array $tabs Array of tab slug => label pairs.
			 *
			 * @since 1.0.0
			 */
			$this->tabs = apply_filters( 'extended_abilities_abilities_tabs', $this->tabs );
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
			$tab = array_key_first( $tabs );
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
		// Add top-level menu.
		add_menu_page(
			__( 'Extended Abilities', 'extended-abilities' ),
			__( 'Abilities', 'extended-abilities' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ],
			'dashicons-superhero-alt',
			30
		);

		// Add Abilities submenu (points to parent).
		add_submenu_page(
			$this->page_slug,
			__( 'Abilities', 'extended-abilities' ),
			__( 'Abilities', 'extended-abilities' ),
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
		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Abilities tabs', 'extended-abilities' ); ?>">
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
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'extended-abilities' ) );
		}

		$current_tab = $this->get_current_tab();
		?>
		<div class="wrap extended-abilities-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Beta Version:', 'extended-abilities' ); ?></strong>
					<?php esc_html_e( 'This plugin is currently in beta and is intended for testing purposes only. Please use with caution and do not use on production sites.', 'extended-abilities' ); ?>
				</p>
			</div>

			<?php settings_errors(); ?>

			<?php $this->render_tabs(); ?>

			<div class="extended-abilities-tab-content">
				<?php $this->render_abilities_content( $current_tab ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render abilities content for the current tab.
	 *
	 * @param string $tab Current tab slug.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_abilities_content( string $tab ): void {
		$grouped_abilities = $this->get_abilities_for_tab( $tab );
		$options           = get_option( $this->option_name, [] );
		?>
		<form method="post" action="options.php" aria-label="<?php esc_attr_e( 'Extended Abilities Settings', 'extended-abilities' ); ?>">
			<?php settings_fields( $this->option_group ); ?>

			<p class="description extended-abilities-description">
				<?php esc_html_e( 'Configure which abilities are available to AI assistants. Enable only the abilities you trust AI assistants to use on your site.', 'extended-abilities' ); ?>
			</p>

			<?php foreach ( $grouped_abilities as $category => $data ) : ?>
				<section class="ability-group" aria-labelledby="<?php echo esc_attr( 'group-title-' . $category ); ?>">
					<div class="ability-group-header">
						<div class="ability-group-title-wrapper">
							<button
									type="button"
									class="ability-group-collapse-toggle"
									aria-expanded="true"
									aria-controls="<?php echo esc_attr( 'group-items-' . $category ); ?>"
							>
								<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
							</button>
							<h2 class="ability-group-title" id="<?php echo esc_attr( 'group-title-' . $category ); ?>">
								<?php echo esc_html( $data['title'] ); ?>
							</h2>
						</div>
						<div class="ability-group-toggle-all">
							<?php $toggle_all_id = 'toggle-all-' . esc_attr( $category ); ?>
							<label class="extended-abilities-toggle" for="<?php echo esc_attr( $toggle_all_id ); ?>">
								<input
										type="checkbox"
										id="<?php echo esc_attr( $toggle_all_id ); ?>"
										class="toggle-all-abilities"
										data-group="<?php echo esc_attr( $category ); ?>"
										<?php /* translators: %s: ability group name */ ?>
										aria-label="<?php echo esc_attr( sprintf( __( 'Enable all %s abilities', 'extended-abilities' ), $data['title'] ) ); ?>"
								/>
								<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
							</label>
							<label for="<?php echo esc_attr( $toggle_all_id ); ?>">
								<?php esc_html_e( 'Enable All', 'extended-abilities' ); ?>
							</label>
						</div>
					</div>

					<div class="ability-group-items" id="<?php echo esc_attr( 'group-items-' . $category ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'group-title-' . $category ); ?>">
						<?php
						// Render ungrouped abilities first.
						if ( ! empty( $data['abilities']['ungrouped'] ) ) {
							foreach ( $data['abilities']['ungrouped'] as $ability_id => $ability ) {
								$this->render_ability_item( $ability_id, $ability, $category, $options );
							}
						}

						// Render grouped abilities.
						if ( ! empty( $data['abilities']['groups'] ) ) {
							foreach ( $data['abilities']['groups'] as $group_id => $group_data ) {
								$this->render_ability_subgroup( $group_id, $group_data, $category, $options );
							}
						}
						?>
					</div>
				</section>
			<?php endforeach; ?>

			<?php if ( empty( $grouped_abilities ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'No abilities are currently registered for this category. Abilities will appear here once they are registered.', 'extended-abilities' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $grouped_abilities ) ) : ?>
				<div class="submit">
					<?php submit_button( null, 'primary', 'submit', false ); ?>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Get abilities for a specific tab.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return array Grouped abilities for the tab.
	 * @since 1.0.0
	 */
	private function get_abilities_for_tab( string $tab ): array {
		$grouped = [];

		switch ( $tab ) {
			case 'core':
				$wordpress_abilities = apply_filters( 'extended_abilities_wordpress_abilities', [] );
				if ( ! empty( $wordpress_abilities ) ) {
					$grouped['wordpress'] = [
						'title'     => __( 'WordPress Abilities', 'extended-abilities' ),
						'abilities' => $this->organize_abilities_by_group( $wordpress_abilities ),
					];
				}
				break;

			case 'woocommerce':
				$woocommerce_abilities = apply_filters( 'extended_abilities_woocommerce_abilities', [] );
				if ( ! empty( $woocommerce_abilities ) ) {
					$grouped['woocommerce'] = [
						'title'     => __( 'WooCommerce Abilities', 'extended-abilities' ),
						'abilities' => $this->organize_abilities_by_group( $woocommerce_abilities ),
					];
				}
				break;

			case 'acf':
				$acf_abilities = apply_filters( 'extended_abilities_acf_abilities', [] );
				if ( ! empty( $acf_abilities ) ) {
					$grouped['acf'] = [
						'title'     => __( 'ACF Abilities', 'extended-abilities' ),
						'abilities' => $this->organize_abilities_by_group( $acf_abilities ),
					];
				}
				break;

			default:
				$plugin_abilities = apply_filters( 'extended_abilities_plugin_abilities', [] );
				if ( ! empty( $plugin_abilities ) ) {
					$grouped['plugins'] = [
						'title'     => __( 'Plugin Integrations', 'extended-abilities' ),
						'abilities' => $this->organize_abilities_by_group( $plugin_abilities ),
					];
				}
				break;
		}

		return $grouped;
	}

	/**
	 * Organize abilities by their group property.
	 *
	 * @param array $abilities Flat array of abilities.
	 *
	 * @return array Organized abilities with groups.
	 * @since 1.0.0
	 */
	private function organize_abilities_by_group( array $abilities ): array {
		$organized = [
			'ungrouped' => [],
			'groups'    => [],
		];

		foreach ( $abilities as $ability_id => $ability ) {
			$group = $ability['group'] ?? '';

			if ( empty( $group ) ) {
				$organized['ungrouped'][ $ability_id ] = $ability;
			} else {
				if ( ! isset( $organized['groups'][ $group ] ) ) {
					$organized['groups'][ $group ] = [
						'label'     => $this->get_group_label( $group ),
						'abilities' => [],
					];
				}
				$organized['groups'][ $group ]['abilities'][ $ability_id ] = $ability;
			}
		}

		return $organized;
	}

	/**
	 * Get human-readable label for a group.
	 *
	 * @param string $group Group identifier.
	 *
	 * @return string Group label.
	 * @since 1.0.0
	 */
	private function get_group_label( string $group ): string {
		$labels = [
			'posts'      => __( 'Posts', 'extended-abilities' ),
			'pages'      => __( 'Pages', 'extended-abilities' ),
			'media'      => __( 'Media', 'extended-abilities' ),
			'users'      => __( 'Users', 'extended-abilities' ),
			'comments'   => __( 'Comments', 'extended-abilities' ),
			'plugins'    => __( 'Plugins', 'extended-abilities' ),
			'themes'     => __( 'Themes', 'extended-abilities' ),
			'taxonomies' => __( 'Taxonomies', 'extended-abilities' ),
		];

		/**
		 * Filter group labels.
		 *
		 * @param array $labels Group labels.
		 *
		 * @since 1.0.0
		 */
		$labels = apply_filters( 'extended_abilities_group_labels', $labels );

		return $labels[ $group ] ?? ucfirst( $group );
	}

	/**
	 * Render an ability subgroup with toggle all functionality.
	 *
	 * @param string $group_id Group identifier.
	 * @param array  $group_data Group data including label and abilities.
	 * @param string $category Parent category.
	 * @param array  $options Current options.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_ability_subgroup( string $group_id, array $group_data, string $category, array $options ): void {
		$subgroup_toggle_id = 'toggle-subgroup-' . esc_attr( $category . '-' . $group_id );
		$subgroup_class     = 'subgroup-' . esc_attr( $category . '-' . $group_id );
		$subgroup_items_id  = 'subgroup-items-' . esc_attr( $category . '-' . $group_id );
		?>
		<div class="ability-subgroup">
			<div class="ability-subgroup-header">
				<div class="ability-subgroup-title-wrapper">
					<button
							type="button"
							class="ability-subgroup-collapse-toggle"
							aria-expanded="true"
							aria-controls="<?php echo esc_attr( $subgroup_items_id ); ?>"
					>
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</button>
					<h3 class="ability-subgroup-title"><?php echo esc_html( $group_data['label'] ); ?></h3>
				</div>
				<div class="ability-subgroup-toggle-all">
					<label class="extended-abilities-toggle" for="<?php echo esc_attr( $subgroup_toggle_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $subgroup_toggle_id ); ?>"
								class="toggle-subgroup-abilities"
								data-subgroup="<?php echo esc_attr( $subgroup_class ); ?>"
								<?php /* translators: %s: ability subgroup name */ ?>
								aria-label="<?php echo esc_attr( sprintf( __( 'Enable all %s abilities', 'extended-abilities' ), $group_data['label'] ) ); ?>"
						/>
						<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
					</label>
					<label for="<?php echo esc_attr( $subgroup_toggle_id ); ?>">
						<?php
						printf(
							/* translators: %s: group name */
							esc_html__( 'Enable All %s', 'extended-abilities' ),
							esc_html( $group_data['label'] )
						);
						?>
					</label>
				</div>
			</div>
			<div class="ability-subgroup-items" id="<?php echo esc_attr( $subgroup_items_id ); ?>">
				<?php
				foreach ( $group_data['abilities'] as $ability_id => $ability ) {
					$this->render_ability_item( $ability_id, $ability, $category, $options, $subgroup_class );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single ability item.
	 *
	 * @param string $ability_id Ability ID.
	 * @param array  $ability Ability data.
	 * @param string $category Parent category.
	 * @param array  $options Current options.
	 * @param string $subgroup_class Optional subgroup class for grouped abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_ability_item( string $ability_id, array $ability, string $category, array $options, string $subgroup_class = '' ): void {
		$field_name      = $this->option_name . '[' . $ability_id . ']';
		$field_id        = 'ability-' . sanitize_key( $ability_id );
		$checked         = isset( $options[ $ability_id ] ) && $options[ $ability_id ];
		$has_description = ! empty( $ability['description'] );
		$checkbox_class  = 'ability-checkbox';

		if ( ! empty( $subgroup_class ) ) {
			$checkbox_class .= ' ability-checkbox-subgroup ' . esc_attr( $subgroup_class );
		}
		?>
		<div class="ability-item">
			<div class="ability-item-toggle">
				<label class="extended-abilities-toggle" for="<?php echo esc_attr( $field_id ); ?>">
					<input
							type="checkbox"
							id="<?php echo esc_attr( $field_id ); ?>"
							name="<?php echo esc_attr( $field_name ); ?>"
							value="1"
							class="<?php echo esc_attr( $checkbox_class ); ?>"
							data-group="<?php echo esc_attr( $category ); ?>"
						<?php if ( $has_description ) : ?>
							aria-describedby="<?php echo esc_attr( $field_id . '-description' ); ?>"
						<?php endif; ?>
						<?php checked( $checked ); ?>
					/>
					<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
				</label>
			</div>
			<div class="ability-item-content">
				<label class="ability-item-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $ability['label'] ); ?>
				</label>
				<?php if ( $has_description ) : ?>
					<p class="ability-item-description" id="<?php echo esc_attr( $field_id . '-description' ); ?>">
						<?php echo esc_html( $ability['description'] ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input settings.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function sanitize_settings( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];

		// Preserve existing settings for abilities not shown on current tab.
		$existing = get_option( $this->option_name, [] );

		foreach ( $existing as $key => $value ) {
			if ( $this->is_valid_ability_id( $key ) ) {
				$sanitized[ $key ] = (bool) $value;
			}
		}

		// Update with new input.
		foreach ( $input as $key => $value ) {
			if ( $this->is_valid_ability_id( $key ) ) {
				$sanitized[ $key ] = (bool) $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Check if ability ID is valid.
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_valid_ability_id( string $ability_id ): bool {
		$wordpress_abilities   = apply_filters( 'extended_abilities_wordpress_abilities', [] );
		$woocommerce_abilities = apply_filters( 'extended_abilities_woocommerce_abilities', [] );
		$acf_abilities         = apply_filters( 'extended_abilities_acf_abilities', [] );
		$plugin_abilities      = apply_filters( 'extended_abilities_plugin_abilities', [] );

		$all_abilities = array_merge(
			$wordpress_abilities,
			$woocommerce_abilities,
			$acf_abilities,
			$plugin_abilities
		);

		return array_key_exists( $ability_id, $all_abilities );
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
		if ( 'toplevel_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'extended-abilities-admin',
			EXTENDED_ABILITIES_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			EXTENDED_ABILITIES_VERSION
		);

		wp_enqueue_script(
			'extended-abilities-admin',
			EXTENDED_ABILITIES_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'jquery' ],
			EXTENDED_ABILITIES_VERSION,
			true
		);
	}
}
