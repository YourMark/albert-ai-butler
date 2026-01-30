<?php
/**
 * Abstract Abilities Page
 *
 * Base class for all abilities admin pages. Contains shared rendering
 * logic extracted from the original Abilities page.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;

/**
 * AbstractAbilitiesPage class
 *
 * Base class for plugin-specific abilities pages. Each subclass renders
 * a submenu page showing only abilities from its plugin. All pages share
 * the same blocklist option (albert_disabled_abilities).
 *
 * @since 1.0.0
 */
abstract class AbstractAbilitiesPage implements Hookable {

	/**
	 * Option name for storing disabled abilities (blocklist).
	 *
	 * Shared across all abilities pages.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_NAME = 'albert_disabled_abilities';

	/**
	 * Option group name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_GROUP = 'albert_settings';

	/**
	 * Get the page slug.
	 *
	 * @return string Page slug (e.g. 'albert-abilities').
	 * @since 1.0.0
	 */
	abstract protected function get_page_slug(): string;

	/**
	 * Get the page title shown in the browser tab / page heading.
	 *
	 * @return string Page title.
	 * @since 1.0.0
	 */
	abstract protected function get_page_title(): string;

	/**
	 * Get the menu title shown in the admin sidebar.
	 *
	 * @return string Menu title.
	 * @since 1.0.0
	 */
	abstract protected function get_menu_title(): string;

	/**
	 * Filter the grouped abilities to only those relevant to this page.
	 *
	 * @param array<string, mixed> $grouped Grouped abilities from AbilitiesRegistry.
	 *
	 * @return array<string, mixed> Filtered grouped abilities.
	 * @since 1.0.0
	 */
	abstract protected function filter_abilities( array $grouped ): array;

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
	 * Add menu pages.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_pages(): void {
		add_submenu_page(
			'albert',
			$this->get_page_title(),
			$this->get_menu_title(),
			'manage_options',
			$this->get_page_slug(),
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
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		// Require WP 6.9+ Abilities API.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			?>
			<div class="wrap albert-settings">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'WordPress 6.9+ Required', 'albert-ai-butler' ); ?></strong>
						<?php esc_html_e( 'The Abilities API requires WordPress 6.9 or later. Please update WordPress to use this feature.', 'albert-ai-butler' ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		$grouped = AbilitiesRegistry::get_abilities_grouped_by_category();

		// Let the subclass filter to its own abilities.
		$grouped = $this->filter_abilities( $grouped );

		// Filter out empty categories.
		$grouped = array_filter(
			$grouped,
			function ( $data ) {
				return ! empty( $data['abilities'] );
			}
		);

		// Sort by predefined order.
		$grouped = AbilitiesRegistry::sort_grouped_categories( $grouped );

		$disabled_abilities = self::get_disabled_abilities();
		?>
		<div class="wrap albert-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<?php $this->render_mobile_nav( $grouped, $disabled_abilities ); ?>

			<div class="albert-page-layout">
				<?php $this->render_sidebar( $grouped, $disabled_abilities ); ?>

				<div class="albert-main-content">
					<div class="albert-tab-content">
						<?php $this->render_abilities_content( $grouped, $disabled_abilities ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sidebar navigation.
	 *
	 * @param array<string, mixed> $grouped             Grouped abilities data.
	 * @param array<string>        $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_sidebar( array $grouped, array $disabled_abilities ): void {
		if ( empty( $grouped ) ) {
			return;
		}
		?>
		<aside class="albert-sidebar" aria-label="<?php esc_attr_e( 'Abilities navigation', 'albert-ai-butler' ); ?>">
			<div class="albert-sidebar-save">
				<?php submit_button( __( 'Save Changes', 'albert-ai-butler' ), 'primary', 'submit', false, [ 'form' => 'albert-form' ] ); ?>
			</div>
			<h2 class="albert-sidebar-title"><?php esc_html_e( 'Categories', 'albert-ai-butler' ); ?></h2>
			<nav>
				<ul class="albert-sidebar-nav">
					<?php foreach ( $grouped as $slug => $data ) { ?>
						<?php
						$category  = $data['category'];
						$abilities = $data['abilities'];
						$icon      = $this->get_category_icon( $slug );
						$label     = is_object( $category ) && method_exists( $category, 'get_label' ) ? $category->get_label() : ( $category['label'] ?? ucfirst( $slug ) );

						// Count all abilities and enabled abilities in this category.
						$total   = count( $abilities );
						$enabled = 0;
						foreach ( $abilities as $ability ) {
							$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
							if ( ! in_array( $name, $disabled_abilities, true ) ) {
								++$enabled;
							}
						}
						?>
						<li>
							<a href="#category-<?php echo esc_attr( $slug ); ?>">
								<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
								<?php echo esc_html( $label ); ?>
								<span class="albert-nav-count"><?php echo esc_html( $enabled . '/' . $total ); ?></span>
							</a>
						</li>
					<?php } ?>
				</ul>
			</nav>
		</aside>
		<?php
	}

	/**
	 * Render mobile horizontal category navigation.
	 *
	 * Shown only on screens where the sidebar is hidden (<= 1200px).
	 *
	 * @param array<string, mixed> $grouped             Grouped abilities data.
	 * @param array<string>        $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_mobile_nav( array $grouped, array $disabled_abilities ): void {
		if ( empty( $grouped ) ) {
			return;
		}
		?>
		<nav class="albert-sidebar-mobile" aria-label="<?php esc_attr_e( 'Abilities categories', 'albert-ai-butler' ); ?>">
			<ul class="albert-sidebar-mobile-nav">
				<?php foreach ( $grouped as $slug => $data ) { ?>
					<?php
					$category  = $data['category'];
					$abilities = $data['abilities'];
					$icon      = $this->get_category_icon( $slug );
					$label     = is_object( $category ) && method_exists( $category, 'get_label' ) ? $category->get_label() : ( $category['label'] ?? ucfirst( $slug ) );

					$total   = count( $abilities );
					$enabled = 0;
					foreach ( $abilities as $ability ) {
						$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
						if ( ! in_array( $name, $disabled_abilities, true ) ) {
							++$enabled;
						}
					}
					?>
					<li>
						<a href="#category-<?php echo esc_attr( $slug ); ?>">
							<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
							<?php echo esc_html( $label ); ?>
							<span class="albert-nav-count"><?php echo esc_html( $enabled . '/' . $total ); ?></span>
						</a>
					</li>
				<?php } ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Get icon class for a category.
	 *
	 * @param string $category Category slug.
	 *
	 * @return string Dashicon class.
	 * @since 1.0.0
	 */
	private function get_category_icon( string $category ): string {
		$icons = [
			'site'          => 'dashicons-admin-site',
			'user'          => 'dashicons-admin-users',
			'content'       => 'dashicons-admin-post',
			'taxonomy'      => 'dashicons-category',
			'comments'      => 'dashicons-admin-comments',
			'commerce'      => 'dashicons-cart',
			'woo-products'  => 'dashicons-products',
			'woo-orders'    => 'dashicons-clipboard',
			'woo-customers' => 'dashicons-groups',
			'seo'           => 'dashicons-search',
			'fields'        => 'dashicons-editor-table',
			'forms'         => 'dashicons-feedback',
			'lms'           => 'dashicons-welcome-learn-more',
			'maintenance'   => 'dashicons-admin-tools',
		];

		/**
		 * Filters the category icon mapping.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $icons Map of category slug to dashicon class.
		 */
		$icons = apply_filters( 'albert/abilities_icons', $icons );

		return $icons[ $category ] ?? 'dashicons-admin-generic';
	}

	/**
	 * Render abilities content.
	 *
	 * @param array<string, mixed> $grouped             Grouped abilities data.
	 * @param array<string>        $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_abilities_content( array $grouped, array $disabled_abilities ): void {
		?>
		<form method="post" action="options.php" id="albert-form" aria-label="<?php esc_attr_e( 'Albert Abilities Settings', 'albert-ai-butler' ); ?>">
			<?php settings_fields( self::OPTION_GROUP ); ?>

			<?php
			// Hidden trigger to ensure the option is submitted even when no checkboxes change.
			// The sanitize callback reads the real data from albert_presented_abilities and albert_enabled_on_page.
			?>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>" value="" />

			<div class="albert-content-header">
				<p class="albert-content-description">
					<?php esc_html_e( 'Select which abilities AI assistants can use on your site. Only enable abilities you trust.', 'albert-ai-butler' ); ?>
				</p>
				<span class="albert-content-actions">
					<button type="button" class="albert-action-link" id="albert-expand-all"><?php esc_html_e( 'Expand all', 'albert-ai-butler' ); ?></button>
					<span class="albert-action-separator" aria-hidden="true">·</span>
					<button type="button" class="albert-action-link" id="albert-collapse-all"><?php esc_html_e( 'Collapse all', 'albert-ai-butler' ); ?></button>
				</span>
			</div>

			<?php if ( ! empty( $grouped ) ) { ?>
				<?php $this->render_collapse_preload_script(); ?>
				<div class="albert-groups-grid">
					<?php foreach ( $grouped as $slug => $data ) { ?>
						<?php $this->render_category_section( $slug, $data, $disabled_abilities ); ?>
					<?php } ?>
				</div>
			<?php } else { ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'No abilities are currently registered. Abilities will appear here once they are registered.', 'albert-ai-butler' ); ?>
					</p>
				</div>
			<?php } ?>

			<?php if ( ! empty( $grouped ) ) { ?>
				<div class="albert-mobile-save">
					<?php submit_button( __( 'Save Changes', 'albert-ai-butler' ), 'primary', 'submit-mobile', false ); ?>
				</div>
			<?php } ?>
		</form>
		<?php
	}

	/**
	 * Render an inline script that pre-applies collapsed state before paint.
	 *
	 * Reads the same localStorage key used by CollapseModule and injects
	 * CSS rules to hide collapsed categories immediately, preventing a
	 * flash of expanded content on page load.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_collapse_preload_script(): void {
		wp_print_scripts( 'albert-collapse-preload' );
	}

	/**
	 * Render a category section with simplified content type rows.
	 *
	 * @param string               $slug                Category slug.
	 * @param array<string, mixed> $category_data       Category data with 'category' and 'abilities'.
	 * @param array<string>        $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_category_section( string $slug, array $category_data, array $disabled_abilities ): void {
		$category    = $category_data['category'];
		$abilities   = $category_data['abilities'];
		$card_id     = 'category-' . sanitize_key( $slug );
		$items_id    = 'category-items-' . sanitize_key( $slug );
		$toggle_id   = 'toggle-all-' . sanitize_key( $slug );
		$icon        = $this->get_category_icon( $slug );
		$label       = is_object( $category ) && method_exists( $category, 'get_label' ) ? $category->get_label() : ( $category['label'] ?? ucfirst( $slug ) );
		$description = is_object( $category ) && method_exists( $category, 'get_description' ) ? $category->get_description() : ( $category['description'] ?? '' );

		// Get the predefined content type groupings.
		$content_types = $this->get_content_types_for_category( $slug, $abilities );

		if ( empty( $content_types ) ) {
			return;
		}

		// Single content type: promote Read/Write toggles to card header,
		// render abilities directly in the card body (no intermediate row).
		if ( 1 === count( $content_types ) ) {
			$this->render_single_type_section( $slug, $card_id, $items_id, $icon, $label, $description, reset( $content_types ), $disabled_abilities );
			return;
		}

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
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true" style="color: var(--albert-text-secondary);"></span>
					<div>
						<h2 class="ability-group-title" id="<?php echo esc_attr( 'title-' . $card_id ); ?>">
							<?php echo esc_html( $label ); ?>
						</h2>
						<?php if ( ! empty( $description ) ) { ?>
							<p class="ability-group-description"><?php echo esc_html( $description ); ?></p>
						<?php } ?>
					</div>
				</div>
				<div class="ability-group-toggle-all">
					<label class="albert-toggle" for="<?php echo esc_attr( $toggle_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $toggle_id ); ?>"
								class="toggle-category-abilities"
								data-category="<?php echo esc_attr( $slug ); ?>"
								aria-label="
								<?php
									/* translators: %s: category name */
									echo esc_attr( sprintf( __( 'Enable all %s abilities', 'albert-ai-butler' ), $label ) );
								?>
								"
						/>
						<span class="albert-toggle-slider" aria-hidden="true"></span>
					</label>
					<label for="<?php echo esc_attr( $toggle_id ); ?>">
						<?php esc_html_e( 'Enable All', 'albert-ai-butler' ); ?>
					</label>
				</div>
			</div>

			<div class="ability-group-items" id="<?php echo esc_attr( $items_id ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
				<?php foreach ( $content_types as $type_key => $type_data ) { ?>
					<?php $this->render_content_type_row( $slug, $type_key, $type_data, $disabled_abilities ); ?>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a single-type category section.
	 *
	 * When a category contains only one content type, the Read/Write toggles
	 * are placed directly in the card header and abilities are rendered in
	 * the card body without an intermediate content type row.
	 *
	 * @param string               $slug                Category slug.
	 * @param string               $card_id             Card element ID.
	 * @param string               $items_id            Items container element ID.
	 * @param string               $icon                Dashicon class.
	 * @param string               $label               Category label.
	 * @param string               $description         Category description.
	 * @param array<string, mixed> $type_data           The single content type data.
	 * @param array<string>        $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_single_type_section( string $slug, string $card_id, string $items_id, string $icon, string $label, string $description, array $type_data, array $disabled_abilities ): void {
		$read_abilities  = $type_data['read_abilities'];
		$write_abilities = $type_data['write_abilities'];

		$read_id             = $card_id . '-read';
		$write_id            = $card_id . '-write';
		$has_read            = ! empty( $read_abilities );
		$has_write           = ! empty( $write_abilities );
		$read_ability_names  = $has_read ? array_values( array_map( 'strval', array_keys( $read_abilities ) ) ) : [];
		$write_ability_names = $has_write ? array_values( array_map( 'strval', array_keys( $write_abilities ) ) ) : [];
		$read_enabled        = $has_read && $this->are_all_abilities_enabled( $read_ability_names, $disabled_abilities );
		$write_enabled       = $has_write && $this->are_all_abilities_enabled( $write_ability_names, $disabled_abilities );

		?>
		<section class="ability-group ability-group--single-type" id="<?php echo esc_attr( $card_id ); ?>" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
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
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true" style="color: var(--albert-text-secondary);"></span>
					<div>
						<h2 class="ability-group-title" id="<?php echo esc_attr( 'title-' . $card_id ); ?>">
							<?php echo esc_html( $label ); ?>
						</h2>
						<?php if ( ! empty( $description ) ) { ?>
							<p class="ability-group-description"><?php echo esc_html( $description ); ?></p>
						<?php } ?>
					</div>
				</div>

				<div class="ability-type-toggles">
					<?php if ( $has_read ) { ?>
						<div class="ability-type-toggle">
							<label class="albert-toggle" for="<?php echo esc_attr( $read_id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $read_id ); ?>"
									class="ability-group-checkbox"
									data-category="<?php echo esc_attr( $slug ); ?>"
									data-mode="read"
									data-abilities="<?php echo esc_attr( (string) wp_json_encode( $read_ability_names ) ); ?>"
									<?php checked( $read_enabled ); ?>
								/>
								<span class="albert-toggle-slider" aria-hidden="true"></span>
							</label>
							<label class="ability-type-toggle-label" for="<?php echo esc_attr( $read_id ); ?>">
								<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
								<?php esc_html_e( 'Read', 'albert-ai-butler' ); ?>
							</label>
						</div>
					<?php } ?>

					<?php if ( $has_write ) { ?>
						<div class="ability-type-toggle">
							<label class="albert-toggle" for="<?php echo esc_attr( $write_id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $write_id ); ?>"
									class="ability-group-checkbox"
									data-category="<?php echo esc_attr( $slug ); ?>"
									data-mode="write"
									data-abilities="<?php echo esc_attr( (string) wp_json_encode( $write_ability_names ) ); ?>"
									<?php checked( $write_enabled ); ?>
								/>
								<span class="albert-toggle-slider" aria-hidden="true"></span>
							</label>
							<label class="ability-type-toggle-label" for="<?php echo esc_attr( $write_id ); ?>">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<?php esc_html_e( 'Write', 'albert-ai-butler' ); ?>
							</label>
						</div>
					<?php } ?>
				</div>
			</div>

			<div class="ability-group-items" id="<?php echo esc_attr( $items_id ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
				<?php
				$has_both_modes = $has_read && $has_write;

				if ( $has_both_modes ) {
					?>
					<div class="ability-mode-label ability-mode-label--read">
						<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
						<?php esc_html_e( 'Read', 'albert-ai-butler' ); ?>
					</div>
					<?php
				}
				foreach ( $read_abilities as $ability ) {
					$this->render_ability_item( $ability, $disabled_abilities, 'read', $read_id );
				}
				if ( $has_both_modes ) {
					?>
					<div class="ability-mode-label ability-mode-label--write">
						<span class="dashicons dashicons-edit" aria-hidden="true"></span>
						<?php esc_html_e( 'Write', 'albert-ai-butler' ); ?>
					</div>
					<?php
				}
				foreach ( $write_abilities as $ability ) {
					$this->render_ability_item( $ability, $disabled_abilities, 'write', $write_id );
				}
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Get content types for a category with their abilities grouped by read/write.
	 *
	 * @param string        $category_slug Category slug.
	 * @param array<object> $abilities     Abilities in this category.
	 *
	 * @return array<string, array<string, mixed>> Content types with their abilities.
	 * @since 1.0.0
	 */
	private function get_content_types_for_category( string $category_slug, array $abilities ): array {
		// Get predefined groupings from AbilitiesRegistry.
		$groups = AbilitiesRegistry::get_ability_groups();

		// Build a lookup of ability name to ability object.
		$ability_lookup = [];
		foreach ( $abilities as $ability ) {
			$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
			if ( $name ) {
				$ability_lookup[ $name ] = $ability;
			}
		}

		$content_types = [];

		// Check each group for matching abilities.
		foreach ( $groups as $group ) {
			if ( ! isset( $group['types'] ) ) {
				continue;
			}

			foreach ( $group['types'] as $type_key => $type_data ) {
				$type_label      = $type_data['label'] ?? ucfirst( $type_key );
				$read_abilities  = [];
				$write_abilities = [];

				// Collect read abilities (store full objects for details).
				if ( isset( $type_data['read']['abilities'] ) ) {
					foreach ( $type_data['read']['abilities'] as $ability_name ) {
						if ( isset( $ability_lookup[ $ability_name ] ) ) {
							$read_abilities[ $ability_name ] = $ability_lookup[ $ability_name ];
						}
					}
				}

				// Collect write abilities (store full objects for details).
				if ( isset( $type_data['write']['abilities'] ) ) {
					foreach ( $type_data['write']['abilities'] as $ability_name ) {
						if ( isset( $ability_lookup[ $ability_name ] ) ) {
							$write_abilities[ $ability_name ] = $ability_lookup[ $ability_name ];
						}
					}
				}

				// Only add if we found at least one ability.
				if ( ! empty( $read_abilities ) || ! empty( $write_abilities ) ) {
					$content_types[ $type_key ] = [
						'label'           => $type_label,
						'read_abilities'  => $read_abilities,
						'write_abilities' => $write_abilities,
						'category'        => $category_slug,
					];
				}
			}
		}

		// Handle any abilities not in predefined groups (core, third-party).
		$grouped_abilities = [];
		foreach ( $content_types as $type_data ) {
			$grouped_abilities = array_merge( $grouped_abilities, array_keys( $type_data['read_abilities'] ), array_keys( $type_data['write_abilities'] ) );
		}

		$ungrouped = array_diff( array_keys( $ability_lookup ), $grouped_abilities );
		foreach ( $ungrouped as $ability_name ) {
			$ability = $ability_lookup[ $ability_name ];
			$label   = method_exists( $ability, 'get_label' ) ? $ability->get_label() : $ability_name;

			// Create a unique key from the ability name.
			$type_key = sanitize_key( str_replace( '/', '-', $ability_name ) );

			// Determine if read or write.
			if ( $this->is_write_ability( $ability_name ) ) {
				$content_types[ $type_key ] = [
					'label'           => $label,
					'read_abilities'  => [],
					'write_abilities' => [ $ability_name => $ability ],
					'category'        => $category_slug,
				];
			} else {
				$content_types[ $type_key ] = [
					'label'           => $label,
					'read_abilities'  => [ $ability_name => $ability ],
					'write_abilities' => [],
					'category'        => $category_slug,
				];
			}
		}

		return $content_types;
	}

	/**
	 * Get tooltip text for a source type badge.
	 *
	 * @param string $source_type Source type identifier.
	 *
	 * @return string Tooltip text.
	 * @since 1.0.0
	 */
	private function get_source_tooltip( string $source_type ): string {
		$tooltips = [
			'core'        => __( 'Built into WordPress core', 'albert-ai-butler' ),
			'albert'      => __( 'Provided by the Albert plugin', 'albert-ai-butler' ),
			'third-party' => __( 'Provided by a third-party plugin', 'albert-ai-butler' ),
		];

		return $tooltips[ $source_type ] ?? '';
	}

	/**
	 * Determine the primary source for a content type based on its abilities.
	 *
	 * @param array<string, object> $read_abilities  Read abilities.
	 * @param array<string, object> $write_abilities Write abilities.
	 *
	 * @return array{type: string, label: string} Source info.
	 * @since 1.0.0
	 */
	private function get_content_type_source( array $read_abilities, array $write_abilities ): array {
		$all_abilities = array_merge( array_keys( $read_abilities ), array_keys( $write_abilities ) );

		if ( empty( $all_abilities ) ) {
			return [
				'type'  => 'unknown',
				'label' => '',
			];
		}

		// Use the first ability to determine source.
		return AbilitiesRegistry::get_ability_source( $all_abilities[0] );
	}

	/**
	 * Render a content type row with Read and Write toggles.
	 *
	 * @param string               $category_slug       Parent category slug.
	 * @param string               $type_key            Content type key (e.g., 'posts', 'pages').
	 * @param array<string, mixed> $type_data           Content type data.
	 * @param array<string>        $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_content_type_row( string $category_slug, string $type_key, array $type_data, array $disabled_abilities ): void {
		$label           = $type_data['label'];
		$read_abilities  = $type_data['read_abilities'];
		$write_abilities = $type_data['write_abilities'];

		$row_id              = 'type-' . sanitize_key( $category_slug . '-' . $type_key );
		$details_id          = $row_id . '-details';
		$read_id             = $row_id . '-read';
		$write_id            = $row_id . '-write';
		$has_read            = ! empty( $read_abilities );
		$has_write           = ! empty( $write_abilities );
		$read_ability_names  = $has_read ? array_values( array_map( 'strval', array_keys( $read_abilities ) ) ) : [];
		$write_ability_names = $has_write ? array_values( array_map( 'strval', array_keys( $write_abilities ) ) ) : [];
		$read_enabled        = $has_read && $this->are_all_abilities_enabled( $read_ability_names, $disabled_abilities );
		$write_enabled       = $has_write && $this->are_all_abilities_enabled( $write_ability_names, $disabled_abilities );

		// Get source badge info.
		$source = $this->get_content_type_source( $read_abilities, $write_abilities );

		// Determine if this row has sub-items (more than 1 ability total).
		$total_abilities = count( $read_abilities ) + count( $write_abilities );
		$has_sub_items   = $total_abilities > 1;
		?>
		<div class="ability-type-row ability-type-row--expandable" data-category="<?php echo esc_attr( $category_slug ); ?>" data-type="<?php echo esc_attr( $type_key ); ?>">
			<div class="ability-type-header">
				<button type="button" class="ability-type-expand" aria-expanded="false" aria-controls="<?php echo esc_attr( $details_id ); ?>">
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</button>

				<div class="ability-type-label">
					<?php echo esc_html( $label ); ?>
					<span class="albert-source-badge albert-source-<?php echo esc_attr( $source['type'] ); ?>" title="<?php echo esc_attr( $this->get_source_tooltip( $source['type'] ) ); ?>">
						<?php echo esc_html( $source['label'] ); ?>
					</span>
				</div>

				<div class="ability-type-toggles">
					<?php if ( $has_read ) { ?>
						<div class="ability-type-toggle">
							<label class="albert-toggle" for="<?php echo esc_attr( $read_id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $read_id ); ?>"
									class="ability-group-checkbox"
									data-category="<?php echo esc_attr( $category_slug ); ?>"
									data-type="<?php echo esc_attr( $type_key ); ?>"
									data-mode="read"
									data-abilities="<?php echo esc_attr( (string) wp_json_encode( $read_ability_names ) ); ?>"
									<?php checked( $read_enabled ); ?>
								/>
								<span class="albert-toggle-slider" aria-hidden="true"></span>
							</label>
							<label class="ability-type-toggle-label" for="<?php echo esc_attr( $read_id ); ?>">
								<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
								<?php esc_html_e( 'Read', 'albert-ai-butler' ); ?>
							</label>
						</div>
					<?php } ?>

					<?php if ( $has_write ) { ?>
						<div class="ability-type-toggle">
							<label class="albert-toggle" for="<?php echo esc_attr( $write_id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $write_id ); ?>"
									class="ability-group-checkbox"
									data-category="<?php echo esc_attr( $category_slug ); ?>"
									data-type="<?php echo esc_attr( $type_key ); ?>"
									data-mode="write"
									data-abilities="<?php echo esc_attr( (string) wp_json_encode( $write_ability_names ) ); ?>"
									<?php checked( $write_enabled ); ?>
								/>
								<span class="albert-toggle-slider" aria-hidden="true"></span>
							</label>
							<label class="ability-type-toggle-label" for="<?php echo esc_attr( $write_id ); ?>">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<?php esc_html_e( 'Write', 'albert-ai-butler' ); ?>
							</label>
						</div>
					<?php } ?>
				</div>
			</div>

			<div class="ability-type-details" id="<?php echo esc_attr( $details_id ); ?>" hidden>
				<?php if ( $has_sub_items ) { ?>
					<?php
					// Render individual ability rows.
					$has_both_modes = ! empty( $read_abilities ) && ! empty( $write_abilities );

					if ( $has_both_modes ) {
						?>
						<div class="ability-mode-label ability-mode-label--read">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							<?php esc_html_e( 'Read', 'albert-ai-butler' ); ?>
						</div>
						<?php
					}
					foreach ( $read_abilities as $ability_name => $ability ) {
						$this->render_ability_item( $ability, $disabled_abilities, 'read', $read_id );
					}
					if ( $has_both_modes ) {
						?>
						<div class="ability-mode-label ability-mode-label--write">
							<span class="dashicons dashicons-edit" aria-hidden="true"></span>
							<?php esc_html_e( 'Write', 'albert-ai-butler' ); ?>
						</div>
						<?php
					}
					foreach ( $write_abilities as $ability_name => $ability ) {
						$this->render_ability_item( $ability, $disabled_abilities, 'write', $write_id );
					}
					?>
				<?php } else { ?>
					<?php
					// Single ability - show description and hidden input.
					$single_ability = ! empty( $read_abilities ) ? reset( $read_abilities ) : reset( $write_abilities );
					$single_name    = ! empty( $read_abilities ) ? array_key_first( $read_abilities ) : array_key_first( $write_abilities );
					$description    = is_object( $single_ability ) && method_exists( $single_ability, 'get_description' ) ? $single_ability->get_description() : '';
					?>
					<input type="hidden" name="albert_presented_abilities[]" value="<?php echo esc_attr( $single_name ); ?>" />
					<?php if ( ! empty( $description ) ) { ?>
						<p class="ability-type-description"><?php echo esc_html( $description ); ?></p>
					<?php } ?>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single ability item within an expanded content type.
	 *
	 * @param object        $ability             Ability object.
	 * @param array<string> $disabled_abilities  Currently disabled ability slugs.
	 * @param string        $mode                Mode ('read' or 'write').
	 * @param string        $group_checkbox_id   ID of the parent group checkbox.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_ability_item( object $ability, array $disabled_abilities, string $mode, string $group_checkbox_id ): void {
		$name        = method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
		$label       = method_exists( $ability, 'get_label' ) ? $ability->get_label() : $name;
		$description = method_exists( $ability, 'get_description' ) ? $ability->get_description() : '';
		$category    = method_exists( $ability, 'get_category' ) ? $ability->get_category() : '';

		$is_enabled = ! in_array( $name, $disabled_abilities, true );
		$field_id   = 'ability-' . sanitize_key( str_replace( '/', '-', $name ) );
		$source     = AbilitiesRegistry::get_ability_source( $name );
		?>
		<div class="ability-item"
			data-ability="<?php echo esc_attr( $name ); ?>"
			data-category="<?php echo esc_attr( $category ); ?>"
			data-mode="<?php echo esc_attr( $mode ); ?>">

			<input type="hidden" name="albert_presented_abilities[]" value="<?php echo esc_attr( $name ); ?>" />

			<div class="ability-item-toggle">
				<label class="albert-toggle" for="<?php echo esc_attr( $field_id ); ?>">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $field_id ); ?>"
						name="albert_enabled_on_page[]"
						value="<?php echo esc_attr( $name ); ?>"
						class="ability-checkbox ability-item-checkbox"
						data-category="<?php echo esc_attr( $category ); ?>"
						data-mode="<?php echo esc_attr( $mode ); ?>"
						data-group-checkbox="<?php echo esc_attr( $group_checkbox_id ); ?>"
						<?php if ( ! empty( $description ) ) { ?>
							aria-describedby="<?php echo esc_attr( $field_id . '-description' ); ?>"
						<?php } ?>
						<?php checked( $is_enabled ); ?>
					/>
					<span class="albert-toggle-slider" aria-hidden="true"></span>
				</label>
			</div>
			<div class="ability-item-content">
				<label class="ability-item-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $label ); ?>
					<span class="albert-source-badge albert-source-<?php echo esc_attr( $source['type'] ); ?>" title="<?php echo esc_attr( $this->get_source_tooltip( $source['type'] ) ); ?>">
						<?php echo esc_html( $source['label'] ); ?>
					</span>
				</label>
				<?php if ( ! empty( $description ) ) { ?>
					<p class="ability-item-description" id="<?php echo esc_attr( $field_id . '-description' ); ?>">
						<?php echo esc_html( $description ); ?>
					</p>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Check if all abilities in a list are enabled.
	 *
	 * @param array<string> $abilities          List of ability names.
	 * @param array<string> $disabled_abilities Currently disabled ability slugs.
	 *
	 * @return bool True if all abilities are enabled.
	 * @since 1.0.0
	 */
	private function are_all_abilities_enabled( array $abilities, array $disabled_abilities ): bool {
		foreach ( $abilities as $ability_name ) {
			if ( in_array( $ability_name, $disabled_abilities, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine if an ability is a write (mutating) ability based on its slug.
	 *
	 * @param string $ability_name Ability name/slug.
	 *
	 * @return bool True if this is a write ability.
	 * @since 1.0.0
	 */
	private function is_write_ability( string $ability_name ): bool {
		// Extract the action part after the namespace prefix.
		$parts  = explode( '/', $ability_name, 2 );
		$action = $parts[1] ?? $parts[0];

		$write_prefixes = [ 'create-', 'update-', 'delete-', 'upload-', 'set-' ];

		foreach ( $write_prefixes as $prefix ) {
			if ( str_starts_with( $action, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize settings.
	 *
	 * Computes the disabled abilities list from what was presented on the page
	 * versus what was checked (enabled). Abilities not on the page are preserved
	 * in their current state.
	 *
	 * @param mixed $input Raw input (hidden trigger field value, not used directly).
	 *
	 * @return array<string> Sanitized array of disabled ability slugs.
	 * @since 1.0.0
	 */
	public function sanitize_settings( $input ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by settings API; sanitized below with array_map.
		$presented_raw = isset( $_POST['albert_presented_abilities'] ) ? wp_unslash( $_POST['albert_presented_abilities'] ) : [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by settings API; sanitized below with array_map.
		$enabled_raw = isset( $_POST['albert_enabled_on_page'] ) ? wp_unslash( $_POST['albert_enabled_on_page'] ) : [];

		$presented = array_map( 'sanitize_text_field', (array) $presented_raw );
		$enabled   = array_map( 'sanitize_text_field', (array) $enabled_raw );

		// Validate slugs.
		$presented = array_filter( $presented, [ $this, 'is_valid_ability_slug' ] );
		$enabled   = array_filter( $enabled, [ $this, 'is_valid_ability_slug' ] );

		// Abilities on this page that were unchecked = newly disabled.
		$newly_disabled = array_diff( $presented, $enabled );

		// Get existing disabled list — sanitize and validate stored values.
		$existing_disabled = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $existing_disabled ) ) {
			$existing_disabled = [];
		}
		$existing_disabled = array_map( 'sanitize_text_field', $existing_disabled );
		$existing_disabled = array_filter( $existing_disabled, [ $this, 'is_valid_ability_slug' ] );

		// Remove from disabled list anything that was on this page and is now enabled.
		$existing_disabled = array_diff( $existing_disabled, $enabled );

		// Merge with newly disabled.
		$disabled = array_values( array_unique( array_merge( $existing_disabled, $newly_disabled ) ) );

		// Mark that abilities have been saved at least once.
		update_option( 'albert_abilities_saved', true );

		return $disabled;
	}

	/**
	 * Get currently disabled abilities.
	 *
	 * @return array<string> Array of disabled ability slugs.
	 * @since 1.0.0
	 */
	public static function get_disabled_abilities(): array {
		$disabled = get_option( 'albert_disabled_abilities', [] );

		// On fresh install, use default disabled list (Albert write abilities).
		if ( empty( $disabled ) && ! get_option( 'albert_abilities_saved' ) ) {
			return AbilitiesRegistry::get_default_disabled_abilities();
		}

		return (array) $disabled;
	}

	/**
	 * Check if ability slug is valid.
	 *
	 * Accepts any slug in the format namespace/ability-name.
	 *
	 * @param string $ability_slug Ability slug.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_valid_ability_slug( string $ability_slug ): bool {
		return (bool) preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/', $ability_slug );
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
		if ( 'albert_page_' . $this->get_page_slug() !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[],
			ALBERT_VERSION,
			true
		);

		wp_register_script(
			'albert-collapse-preload',
			ALBERT_PLUGIN_URL . 'assets/js/admin-collapse-preload.js',
			[],
			ALBERT_VERSION,
			false
		);
	}
}
