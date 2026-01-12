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
				'core' => __( 'Core', 'extended-abilities' ),
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
			$this->tabs = apply_filters( 'extended_abilities/abilities/tabs', $this->tabs );
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

		$current_tab       = $this->get_current_tab();
		$grouped_abilities = $this->get_abilities_for_tab( $current_tab );
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

			<div class="ea-page-layout">
				<?php $this->render_sidebar( $grouped_abilities ); ?>

				<div class="ea-main-content">
					<div class="extended-abilities-tab-content">
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
		<aside class="ea-sidebar" aria-label="<?php esc_attr_e( 'Abilities navigation', 'extended-abilities' ); ?>">
			<div class="ea-sidebar-save">
				<?php submit_button( __( 'Save Changes', 'extended-abilities' ), 'primary', 'submit', false, [ 'form' => 'extended-abilities-form' ] ); ?>
			</div>
			<h2 class="ea-sidebar-title"><?php esc_html_e( 'Quick Nav', 'extended-abilities' ); ?></h2>
			<nav>
				<ul class="ea-sidebar-nav">
					<?php foreach ( $grouped_abilities as $category => $data ) : ?>
						<?php
						if ( empty( $data['abilities']['groups'] ) ) {
							continue;
						}
						foreach ( $data['abilities']['groups'] as $group_id => $group_data ) :
							$group_anchor  = 'group-' . sanitize_key( $category . '-' . $group_id );
							$ability_count = count( $group_data['abilities'] );
							$enabled_count = 0;
							foreach ( $group_data['abilities'] as $ability_id => $ability ) {
								if ( ! empty( $options[ $ability_id ] ) ) {
									++$enabled_count;
								}
							}
							$icon = $this->get_group_icon( $group_id );
							?>
							<li>
								<a href="#<?php echo esc_attr( $group_anchor ); ?>">
									<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
									<?php echo esc_html( $group_data['label'] ); ?>
									<span class="ea-nav-count"><?php echo esc_html( $enabled_count . '/' . $ability_count ); ?></span>
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
		];

		/**
		 * Filter group icons.
		 *
		 * @param array $icons Group icon mappings.
		 *
		 * @since 1.0.0
		 */
		$icons = apply_filters( 'extended_abilities/abilities/group_icons', $icons );

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
		<form method="post" action="options.php" id="extended-abilities-form" aria-label="<?php esc_attr_e( 'Extended Abilities Settings', 'extended-abilities' ); ?>">
			<?php settings_fields( $this->option_group ); ?>

			<div class="ea-content-header">
				<p class="ea-content-description">
					<?php esc_html_e( 'Select which abilities AI assistants can use on your site. Only enable abilities you trust.', 'extended-abilities' ); ?>
				</p>
				<span class="ea-content-actions">
					<button type="button" class="ea-action-link" id="ea-expand-all"><?php esc_html_e( 'Expand all', 'extended-abilities' ); ?></button>
					<span class="ea-action-separator" aria-hidden="true">·</span>
					<button type="button" class="ea-action-link" id="ea-collapse-all"><?php esc_html_e( 'Collapse all', 'extended-abilities' ); ?></button>
				</span>
			</div>

			<?php if ( ! empty( $grouped_abilities ) ) : ?>
				<div class="ea-groups-grid">
					<?php foreach ( $grouped_abilities as $category => $data ) : ?>
						<?php
						// Render each subgroup as a separate card.
						if ( ! empty( $data['abilities']['groups'] ) ) {
							foreach ( $data['abilities']['groups'] as $group_id => $group_data ) {
								$this->render_ability_group_card( $group_id, $group_data, $category, $options );
							}
						}

						// Render ungrouped abilities in their own card if any exist.
						if ( ! empty( $data['abilities']['ungrouped'] ) ) {
							$this->render_ungrouped_abilities_card( $data['abilities']['ungrouped'], $category, $options );
						}
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( empty( $grouped_abilities ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'No abilities are currently registered for this category. Abilities will appear here once they are registered.', 'extended-abilities' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $grouped_abilities ) ) : ?>
				<div class="ea-mobile-save">
					<?php submit_button( __( 'Save Changes', 'extended-abilities' ), 'primary', 'submit-mobile', false ); ?>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render an ability group as a card.
	 *
	 * @param string               $group_id   Group identifier.
	 * @param array<string, mixed> $group_data Group data including label and abilities.
	 * @param string               $category   Parent category.
	 * @param array<string, mixed> $options    Current options.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_ability_group_card( string $group_id, array $group_data, string $category, array $options ): void {
		$card_id        = 'group-' . sanitize_key( $category . '-' . $group_id );
		$toggle_all_id  = 'toggle-all-' . sanitize_key( $category . '-' . $group_id );
		$items_id       = 'group-items-' . sanitize_key( $category . '-' . $group_id );
		$subgroup_class = 'subgroup-' . esc_attr( $category . '-' . $group_id );
		$icon           = $this->get_group_icon( $group_id );
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
						<?php echo esc_html( $group_data['label'] ); ?>
					</h2>
				</div>
				<div class="ability-group-toggle-all">
					<label class="extended-abilities-toggle" for="<?php echo esc_attr( $toggle_all_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $toggle_all_id ); ?>"
								class="toggle-subgroup-abilities"
								data-subgroup="<?php echo esc_attr( $subgroup_class ); ?>"
								<?php /* translators: %s: ability group name */ ?>
								aria-label="<?php echo esc_attr( sprintf( __( 'Enable all %s abilities', 'extended-abilities' ), $group_data['label'] ) ); ?>"
						/>
						<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
					</label>
					<label for="<?php echo esc_attr( $toggle_all_id ); ?>">
						<?php esc_html_e( 'Enable All', 'extended-abilities' ); ?>
					</label>
				</div>
			</div>

			<div class="ability-group-items" id="<?php echo esc_attr( $items_id ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
				<div class="ability-subgroup-items">
					<?php
					foreach ( $group_data['abilities'] as $ability_id => $ability ) {
						$this->render_ability_item( $ability_id, $ability, $category, $options, $subgroup_class );
					}
					?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Render ungrouped abilities in a card.
	 *
	 * @param array<string, mixed> $abilities Ungrouped abilities.
	 * @param string               $category  Parent category.
	 * @param array<string, mixed> $options   Current options.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_ungrouped_abilities_card( array $abilities, string $category, array $options ): void {
		$card_id        = 'group-' . sanitize_key( $category . '-other' );
		$toggle_all_id  = 'toggle-all-' . sanitize_key( $category . '-other' );
		$items_id       = 'group-items-' . sanitize_key( $category . '-other' );
		$subgroup_class = 'subgroup-' . esc_attr( $category . '-other' );
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
					<span class="dashicons dashicons-admin-generic" aria-hidden="true" style="color: var(--ea-text-secondary);"></span>
					<h2 class="ability-group-title" id="<?php echo esc_attr( 'title-' . $card_id ); ?>">
						<?php esc_html_e( 'Other', 'extended-abilities' ); ?>
					</h2>
				</div>
				<div class="ability-group-toggle-all">
					<label class="extended-abilities-toggle" for="<?php echo esc_attr( $toggle_all_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $toggle_all_id ); ?>"
								class="toggle-subgroup-abilities"
								data-subgroup="<?php echo esc_attr( $subgroup_class ); ?>"
								aria-label="<?php esc_attr_e( 'Enable all other abilities', 'extended-abilities' ); ?>"
						/>
						<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
					</label>
					<label for="<?php echo esc_attr( $toggle_all_id ); ?>">
						<?php esc_html_e( 'Enable All', 'extended-abilities' ); ?>
					</label>
				</div>
			</div>

			<div class="ability-group-items" id="<?php echo esc_attr( $items_id ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
				<div class="ability-subgroup-items">
					<?php
					foreach ( $abilities as $ability_id => $ability ) {
						$this->render_ability_item( $ability_id, $ability, $category, $options, $subgroup_class );
					}
					?>
				</div>
			</div>
		</section>
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
		$grouped = [];

		if ( $tab === 'core' ) {
			$wordpress_abilities = apply_filters( 'extended_abilities/abilities/wordpress', [] );
			if ( ! empty( $wordpress_abilities ) ) {
				$grouped['wordpress'] = [
					'title'     => __( 'WordPress Abilities', 'extended-abilities' ),
					'abilities' => $this->organize_abilities_by_group( $wordpress_abilities ),
				];
			}
		} else {
			/**
			 * Filter to provide abilities for a custom tab.
			 *
			 * Use this filter to add abilities to your custom tab.
			 * The dynamic portion of the hook name, `$tab`, refers to the tab slug.
			 *
			 * @param array $abilities Array of ability data.
			 *
			 * @since 1.0.0
			 */
			$tab_abilities = apply_filters( "extended_abilities/abilities/{$tab}", [] );
			if ( ! empty( $tab_abilities ) ) {
				$grouped[ $tab ] = [
					'title'     => $this->get_tabs()[ $tab ] ?? ucfirst( $tab ),
					'abilities' => $this->organize_abilities_by_group( $tab_abilities ),
				];
			}
		}

		return $grouped;
	}

	/**
	 * Organize abilities by their group property.
	 *
	 * @param array<string, mixed> $abilities Flat array of abilities.
	 *
	 * @return array<string, mixed> Organized abilities with groups.
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
		$labels = apply_filters( 'extended_abilities/abilities/group_labels', $labels );

		return $labels[ $group ] ?? ucfirst( $group );
	}

	/**
	 * Render an ability subgroup with toggle all functionality.
	 *
	 * @param string               $group_id   Group identifier.
	 * @param array<string, mixed> $group_data Group data including label and abilities.
	 * @param string               $category   Parent category.
	 * @param array<string, mixed> $options    Current options.
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
	 * @param string               $ability_id     Ability ID.
	 * @param array<string, mixed> $ability        Ability data.
	 * @param string               $category       Parent category.
	 * @param array<string, mixed> $options        Current options.
	 * @param string               $subgroup_class Optional subgroup class for grouped abilities.
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
	 * Only checked checkboxes are submitted. We simply store those as enabled.
	 *
	 * @param array<string, mixed>|mixed $input Input settings.
	 *
	 * @return array<string, bool>
	 * @since 1.0.0
	 */
	public function sanitize_settings( $input ): array {
		$sanitized = [];

		if ( is_array( $input ) ) {
			foreach ( $input as $ability_id => $value ) {
				if ( $this->is_valid_ability_id( $ability_id ) ) {
					$sanitized[ $ability_id ] = true;
				}
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
		$all_abilities = apply_filters( 'extended_abilities/abilities/wordpress', [] );

		// Include abilities from all registered tabs.
		$tabs = $this->get_tabs();
		foreach ( array_keys( $tabs ) as $tab_slug ) {
			if ( $tab_slug !== 'core' ) {
				$tab_abilities = apply_filters( "extended_abilities/abilities/{$tab_slug}", [] );
				$all_abilities = array_merge( $all_abilities, $tab_abilities );
			}
		}

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
			[],
			EXTENDED_ABILITIES_VERSION,
			true
		);
	}
}
