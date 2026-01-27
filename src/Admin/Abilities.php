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
 * Uses a blocklist model: all abilities are enabled by default,
 * only explicitly disabled abilities are stored.
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
	private string $page_slug = 'albert-abilities';

	/**
	 * Option group name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option_group = 'albert_settings';

	/**
	 * Option name for storing disabled abilities (blocklist).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $option_name = 'albert_disabled_abilities';

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
	 * Render the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert' ) );
		}

		// Require WP 6.9+ Abilities API.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			?>
			<div class="wrap albert-settings">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'WordPress 6.9+ Required', 'albert' ); ?></strong>
						<?php esc_html_e( 'The Abilities API requires WordPress 6.9 or later. Please update WordPress to use this feature.', 'albert' ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		$grouped = AbilitiesRegistry::get_abilities_grouped_by_category();

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

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Beta Version:', 'albert' ); ?></strong>
					<?php esc_html_e( 'This plugin is currently in beta and is intended for testing purposes only. Please use with caution and do not use on production sites.', 'albert' ); ?>
				</p>
			</div>

			<?php settings_errors(); ?>

			<?php $this->render_mobile_nav( $grouped, $disabled_abilities ); ?>

			<div class="ea-page-layout">
				<?php $this->render_sidebar( $grouped, $disabled_abilities ); ?>

				<div class="ea-main-content">
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
		<aside class="ea-sidebar" aria-label="<?php esc_attr_e( 'Abilities navigation', 'albert' ); ?>">
			<div class="ea-sidebar-save">
				<?php submit_button( __( 'Save Changes', 'albert' ), 'primary', 'submit', false, [ 'form' => 'albert-form' ] ); ?>
			</div>
			<h2 class="ea-sidebar-title"><?php esc_html_e( 'Categories', 'albert' ); ?></h2>
			<nav>
				<ul class="ea-sidebar-nav">
					<?php foreach ( $grouped as $slug => $data ) : ?>
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
								<span class="ea-nav-count"><?php echo esc_html( $enabled . '/' . $total ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
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
	 * @since 1.1.0
	 */
	private function render_mobile_nav( array $grouped, array $disabled_abilities ): void {
		if ( empty( $grouped ) ) {
			return;
		}
		?>
		<nav class="ea-sidebar-mobile" aria-label="<?php esc_attr_e( 'Abilities categories', 'albert' ); ?>">
			<ul class="ea-sidebar-mobile-nav">
				<?php foreach ( $grouped as $slug => $data ) : ?>
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
							<span class="ea-nav-count"><?php echo esc_html( $enabled . '/' . $total ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
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
	 * @since 1.1.0
	 */
	private function get_category_icon( string $category ): string {
		$icons = [
			'site'        => 'dashicons-admin-site',
			'user'        => 'dashicons-admin-users',
			'content'     => 'dashicons-admin-post',
			'taxonomy'    => 'dashicons-category',
			'comments'    => 'dashicons-admin-comments',
			'commerce'    => 'dashicons-cart',
			'seo'         => 'dashicons-search',
			'fields'      => 'dashicons-editor-table',
			'forms'       => 'dashicons-feedback',
			'lms'         => 'dashicons-welcome-learn-more',
			'maintenance' => 'dashicons-admin-tools',
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
	 * Get tooltip text for a source type badge.
	 *
	 * @param string $source_type Source type identifier.
	 *
	 * @return string Tooltip text.
	 * @since 1.1.0
	 */
	private function get_source_tooltip( string $source_type ): string {
		$tooltips = [
			'core'        => __( 'Built into WordPress core', 'albert' ),
			'albert'      => __( 'Provided by the Albert plugin', 'albert' ),
			'premium'     => __( 'Requires Albert Pro', 'albert' ),
			'third-party' => __( 'Provided by a third-party plugin', 'albert' ),
		];

		return $tooltips[ $source_type ] ?? '';
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
		<form method="post" action="options.php" id="albert-form" aria-label="<?php esc_attr_e( 'Albert Abilities Settings', 'albert' ); ?>">
			<?php settings_fields( $this->option_group ); ?>

			<?php
			// Hidden trigger to ensure the option is submitted even when no checkboxes change.
			// The sanitize callback reads the real data from albert_presented_abilities and albert_enabled_on_page.
			?>
			<input type="hidden" name="<?php echo esc_attr( $this->option_name ); ?>" value="" />

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

			<?php if ( ! empty( $grouped ) ) : ?>
				<div class="ea-groups-grid">
					<?php foreach ( $grouped as $slug => $data ) : ?>
						<?php $this->render_category_section( $slug, $data, $disabled_abilities ); ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'No abilities are currently registered. Abilities will appear here once they are registered.', 'albert' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $grouped ) ) : ?>
				<div class="ea-mobile-save">
					<?php submit_button( __( 'Save Changes', 'albert' ), 'primary', 'submit-mobile', false ); ?>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render a category section with abilities split into read/write subgroups.
	 *
	 * @param string               $slug                Category slug.
	 * @param array<string, mixed> $category_data       Category data with 'category' and 'abilities'.
	 * @param array<string>        $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.1.0
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

		// Split abilities into read and write subgroups.
		$read_abilities  = [];
		$write_abilities = [];

		foreach ( $abilities as $ability ) {
			$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
			if ( $this->is_write_ability( $name ) ) {
				$write_abilities[] = $ability;
			} else {
				$read_abilities[] = $ability;
			}
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
					<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true" style="color: var(--ea-text-secondary);"></span>
					<div>
						<h2 class="ability-group-title" id="<?php echo esc_attr( 'title-' . $card_id ); ?>">
							<?php echo esc_html( $label ); ?>
						</h2>
						<?php if ( ! empty( $description ) ) : ?>
							<p class="ability-group-description"><?php echo esc_html( $description ); ?></p>
						<?php endif; ?>
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
									echo esc_attr( sprintf( __( 'Enable all %s abilities', 'albert' ), $label ) );
								?>
								"
						/>
						<span class="albert-toggle-slider" aria-hidden="true"></span>
					</label>
					<label for="<?php echo esc_attr( $toggle_id ); ?>">
						<?php esc_html_e( 'Enable All', 'albert' ); ?>
					</label>
				</div>
			</div>

			<div class="ability-group-items" id="<?php echo esc_attr( $items_id ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'title-' . $card_id ); ?>">
				<?php if ( ! empty( $read_abilities ) ) : ?>
					<?php $this->render_subgroup( $slug, 'read', __( 'Read', 'albert' ), $read_abilities, $disabled_abilities ); ?>
				<?php endif; ?>

				<?php if ( ! empty( $write_abilities ) ) : ?>
					<?php $this->render_subgroup( $slug, 'write', __( 'Write', 'albert' ), $write_abilities, $disabled_abilities ); ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a subgroup (read or write) within a category section.
	 *
	 * @param string        $category_slug       Parent category slug.
	 * @param string        $subgroup_type       Subgroup type ('read' or 'write').
	 * @param string        $subgroup_label      Display label for the subgroup.
	 * @param list<object>  $abilities           Abilities in this subgroup.
	 * @param array<string> $disabled_abilities  Currently disabled ability slugs.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function render_subgroup( string $category_slug, string $subgroup_type, string $subgroup_label, array $abilities, array $disabled_abilities ): void {
		$subgroup_key = $category_slug . '-' . $subgroup_type;
		$toggle_id    = 'toggle-subgroup-' . sanitize_key( $subgroup_key );
		?>
		<div class="ability-subgroup" data-subgroup="<?php echo esc_attr( $subgroup_type ); ?>">
			<div class="ability-subgroup-header">
				<span class="ability-subgroup-label">
					<span class="dashicons <?php echo esc_attr( 'write' === $subgroup_type ? 'dashicons-edit' : 'dashicons-visibility' ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $subgroup_label ); ?>
				</span>
				<div class="ability-subgroup-toggle">
					<label class="albert-toggle albert-toggle--small" for="<?php echo esc_attr( $toggle_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $toggle_id ); ?>"
								class="toggle-subgroup-abilities"
								data-category="<?php echo esc_attr( $category_slug ); ?>"
								data-subgroup="<?php echo esc_attr( $subgroup_type ); ?>"
								aria-label="
								<?php
									/* translators: %s: subgroup name (Read or Write) */
									echo esc_attr( sprintf( __( 'Enable all %s abilities', 'albert' ), $subgroup_label ) );
								?>
								"
						/>
						<span class="albert-toggle-slider" aria-hidden="true"></span>
					</label>
				</div>
			</div>
			<div class="ability-subgroup-items">
				<?php foreach ( $abilities as $ability ) : ?>
					<?php $this->render_ability_row( $ability, $disabled_abilities, $subgroup_type ); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Determine if an ability is a write (mutating) ability based on its slug.
	 *
	 * @param string $ability_name Ability name/slug.
	 *
	 * @return bool True if this is a write ability.
	 * @since 1.1.0
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
	 * Render a single ability row.
	 *
	 * Every ability gets a toggle. Premium abilities have a disabled toggle.
	 * Each row includes a hidden "presented" input so the sanitize callback
	 * can compute disabled = presented - enabled.
	 *
	 * @param object        $ability             Ability object from WP Abilities API.
	 * @param array<string> $disabled_abilities  Currently disabled ability slugs.
	 * @param string        $subgroup            Subgroup type ('read' or 'write').
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function render_ability_row( object $ability, array $disabled_abilities, string $subgroup = '' ): void {
		$name        = method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
		$label       = method_exists( $ability, 'get_label' ) ? $ability->get_label() : $name;
		$description = method_exists( $ability, 'get_description' ) ? $ability->get_description() : '';
		$category    = method_exists( $ability, 'get_category' ) ? $ability->get_category() : '';

		$source     = AbilitiesRegistry::get_ability_source( $name );
		$is_premium = AbilitiesRegistry::is_premium_ability( $name );
		$is_enabled = ! in_array( $name, $disabled_abilities, true );

		$field_id = 'ability-' . sanitize_key( str_replace( '/', '-', $name ) );

		$row_classes = [ 'ability-item' ];
		if ( $is_premium ) {
			$row_classes[] = 'ability-item--premium';
		}
		?>
		<div class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
			data-source="<?php echo esc_attr( $source['type'] ); ?>"
			data-ability="<?php echo esc_attr( $name ); ?>"
			data-category="<?php echo esc_attr( $category ); ?>">

			<?php // Track that this ability was presented on the form. ?>
			<input type="hidden" name="albert_presented_abilities[]" value="<?php echo esc_attr( $name ); ?>" />

			<div class="ability-item-toggle">
				<?php if ( $is_premium ) : ?>
					<label class="albert-toggle albert-toggle--disabled" for="<?php echo esc_attr( $field_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $field_id ); ?>"
								disabled
								class="ability-checkbox"
						/>
						<span class="albert-toggle-slider" aria-hidden="true"></span>
					</label>
				<?php else : ?>
					<label class="albert-toggle" for="<?php echo esc_attr( $field_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $field_id ); ?>"
								name="albert_enabled_on_page[]"
								value="<?php echo esc_attr( $name ); ?>"
								class="ability-checkbox ability-checkbox-category"
								data-category="<?php echo esc_attr( $category ); ?>"
								data-subgroup="<?php echo esc_attr( $subgroup ); ?>"
								<?php if ( ! empty( $description ) ) : ?>
									aria-describedby="<?php echo esc_attr( $field_id . '-description' ); ?>"
								<?php endif; ?>
								<?php checked( $is_enabled ); ?>
						/>
						<span class="albert-toggle-slider" aria-hidden="true"></span>
					</label>
				<?php endif; ?>
			</div>
			<div class="ability-item-content">
				<div class="ability-item-header">
					<label class="ability-item-label" for="<?php echo esc_attr( $field_id ); ?>">
						<?php echo esc_html( $label ); ?>
					</label>
					<span class="ea-source-badge ea-source-<?php echo esc_attr( $source['type'] ); ?>" title="<?php echo esc_attr( $this->get_source_tooltip( $source['type'] ) ); ?>">
						<?php echo esc_html( $source['label'] ); ?>
					</span>
					<?php if ( $is_premium ) : ?>
						<span class="ea-premium-lock dashicons dashicons-lock" aria-hidden="true"></span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $description ) ) : ?>
					<p class="ability-item-description" id="<?php echo esc_attr( $field_id . '-description' ); ?>">
						<?php echo esc_html( $description ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
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

		// Get existing disabled list.
		$existing_disabled = get_option( $this->option_name, [] );
		if ( ! is_array( $existing_disabled ) ) {
			$existing_disabled = [];
		}

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
	 * @since 1.1.0
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
	 * Get currently enabled permissions (legacy compatibility).
	 *
	 * @return array<string> Array of enabled permission keys.
	 * @since 1.0.0
	 * @deprecated 1.1.0 Use get_disabled_abilities() instead.
	 */
	public static function get_enabled_permissions(): array {
		return get_option( 'albert_enabled_permissions', AbilitiesRegistry::get_default_permissions() );
	}

	/**
	 * Check if ability slug is valid.
	 *
	 * Accepts any slug in the format namespace/ability-name.
	 *
	 * @param string $ability_slug Ability slug.
	 *
	 * @return bool
	 * @since 1.1.0
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
		if ( 'albert_page_' . $this->page_slug !== $hook ) {
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
	}
}
