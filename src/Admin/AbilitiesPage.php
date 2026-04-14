<?php
/**
 * Abilities Admin Page
 *
 * Single unified page that lists every registered ability in a flat,
 * client-filterable list. Replaces the category-grouped Core/ACF/WooCommerce
 * pages from 1.0.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\AnnotationPresenter;

/**
 * AbilitiesPage class
 *
 * Renders the Albert → Abilities admin page: a sticky filter toolbar
 * (search + category + supplier + view toggle) followed by a flat list
 * of every registered ability with per-row toggle and expandable details.
 *
 * All filtering, pagination, and row expansion happens client-side in
 * assets/js/admin-settings.js; the server just renders every row once
 * and relies on the standard WordPress Settings API for persistence.
 *
 * @since 1.1.0
 */
class AbilitiesPage implements Hookable {

	/**
	 * Option name for storing the disabled-abilities blocklist.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DISABLED_ABILITIES_OPTION = 'albert_disabled_abilities';

	/**
	 * Admin page slug.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-abilities';

	/**
	 * Option name for the persisted view-mode preference.
	 *
	 * Stores either `list` or `paginated`. The value is rendered into the
	 * initial HTML on every page load so the JavaScript module never has to
	 * "re-apply" the user's preference after the page paints — that race
	 * caused a visible jump from list to paginated view on slow loads.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const VIEW_MODE_OPTION = 'albert_abilities_view_mode';

	/**
	 * Number of rows per page in paginated view.
	 *
	 * Surfaced to the JavaScript module via the `data-rows-per-page`
	 * attribute on `#albert-abilities-list`; both sides must agree so the
	 * server can pre-hide rows beyond the first page without flashing.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const ROWS_PER_PAGE = 25;

	/**
	 * Register WordPress hooks.
	 *
	 * Wires the admin menu entry, the page-scoped asset enqueue, and the
	 * two AJAX endpoints used by the abilities admin UI:
	 *
	 *   - `wp_ajax_albert_toggle_ability`  — enable/disable a single ability
	 *   - `wp_ajax_albert_save_view_mode`  — persist the list/paginated preference
	 *
	 * The page no longer uses the Settings API: the disabled-abilities
	 * option is mutated per-row via the AJAX endpoint, which means there is
	 * no Save Changes button and no bulk submit form.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_albert_toggle_ability', [ $this, 'ajax_toggle_ability' ] );
		add_action( 'wp_ajax_albert_save_view_mode', [ $this, 'ajax_save_view_mode' ] );
	}

	/**
	 * AJAX handler that toggles a single ability on or off.
	 *
	 * Reads the current disabled-abilities option, adds or removes the
	 * requested ability id, and writes it back. The endpoint accepts only
	 * one ability id per call (never the full list) so two concurrent
	 * toggles can't accidentally overwrite each other's state.
	 *
	 * Returns success with the new state, or an error with a translated
	 * message that the JS surfaces to the user.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function ajax_toggle_ability(): void {
		check_ajax_referer( 'albert_toggle_ability', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Insufficient permissions.', 'albert-ai-butler' ) ],
				403
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field() below.
		$ability_id = sanitize_text_field( wp_unslash( (string) ( $_POST['ability_id'] ?? '' ) ) );

		// Accept the explicit string forms the JS sends ("1" / "true"); anything
		// else is treated as disable. Doesn't rely on PHP truthy coercion so
		// "false" or "0" reach us as the user expects.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to literals below.
		$enabled_raw = isset( $_POST['enabled'] ) ? wp_unslash( (string) $_POST['enabled'] ) : '';
		$enabled     = $enabled_raw === '1' || $enabled_raw === 'true';

		if ( ! $this->is_valid_ability_slug( $ability_id ) ) {
			wp_send_json_error(
				[ 'message' => __( 'Invalid ability id.', 'albert-ai-butler' ) ],
				400
			);
		}

		$disabled = self::get_disabled_abilities();
		// Re-sanitize stored values defensively before mutating.
		$disabled = array_filter(
			array_map( 'sanitize_text_field', $disabled ),
			[ $this, 'is_valid_ability_slug' ]
		);

		if ( $enabled ) {
			$disabled = array_values( array_diff( $disabled, [ $ability_id ] ) );
		} elseif ( ! in_array( $ability_id, $disabled, true ) ) {
			$disabled[] = $ability_id;
		}

		update_option( self::DISABLED_ABILITIES_OPTION, $disabled );
		update_option( 'albert_abilities_saved', true );

		wp_send_json_success(
			[
				'ability_id' => $ability_id,
				'enabled'    => $enabled,
			]
		);
	}

	/**
	 * AJAX handler that persists the view-mode preference.
	 *
	 * Called by the view toggle in the toolbar; returns success silently.
	 * The capability check matches the abilities page itself.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function ajax_save_view_mode(): void {
		check_ajax_referer( 'albert_view_mode', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'albert-ai-butler' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_key() on the next line.
		$mode = self::normalize_view_mode( sanitize_key( wp_unslash( (string) ( $_POST['mode'] ?? '' ) ) ) );

		update_option( self::VIEW_MODE_OPTION, $mode, false );

		wp_send_json_success( [ 'mode' => $mode ] );
	}

	/**
	 * Get the persisted view-mode preference, defaulting to "list".
	 *
	 * @return string Either `list` or `paginated`.
	 * @since 1.1.0
	 */
	public static function get_view_mode(): string {
		return self::normalize_view_mode( get_option( self::VIEW_MODE_OPTION, 'list' ) );
	}

	/**
	 * Normalize an arbitrary string to a valid view mode.
	 *
	 * Anything that isn't `paginated` collapses to `list` so an unexpected
	 * value (corrupted option, bad AJAX payload) never breaks rendering.
	 *
	 * @param string $mode Raw mode string.
	 *
	 * @return string Either `list` or `paginated`.
	 * @since 1.1.0
	 */
	private static function normalize_view_mode( string $mode ): string {
		return $mode === 'paginated' ? 'paginated' : 'list';
	}

	/**
	 * Register the submenu page under Albert.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'albert',
			__( 'Abilities', 'albert-ai-butler' ),
			__( 'Abilities', 'albert-ai-butler' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		// WP 6.9+ Abilities API required.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			?>
			<div class="wrap albert-wrap">
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

		$abilities          = self::collect_abilities();
		$disabled_abilities = self::get_disabled_abilities();
		$categories         = self::collect_filter_options( $abilities, 'category_slug', 'category_label' );
		$suppliers          = self::collect_filter_options( $abilities, 'supplier_slug', 'supplier_label' );
		$annotations        = self::collect_filter_options( $abilities, 'annotation_slug', 'annotation_label' );
		$view_mode          = self::get_view_mode();
		$total_count        = count( $abilities );
		$enabled_count      = count(
			array_filter(
				$abilities,
				static fn( array $row ): bool => ! in_array( $row['id'], $disabled_abilities, true )
			)
		);
		?>
		<div class="wrap albert-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<div class="albert-abilities-page">
				<header class="albert-abilities-header">
					<p class="albert-abilities-intro">
						<?php esc_html_e( 'Enable or disable the abilities AI assistants can call. Each row is labelled with what it can do — read data, make changes, or delete data — so you can decide at a glance which to allow.', 'albert-ai-butler' ); ?>
					</p>
				</header>

				<?php $this->render_toolbar( $categories, $suppliers, $annotations, $enabled_count, $total_count, $view_mode ); ?>

				<div
					class="albert-abilities-error"
					id="albert-abilities-error"
					role="alert"
					aria-live="assertive"
					hidden
				></div>

				<div
					class="albert-abilities-list"
					id="albert-abilities-list"
					role="list"
					data-view-mode="<?php echo esc_attr( $view_mode ); ?>"
					data-rows-per-page="<?php echo esc_attr( (string) self::ROWS_PER_PAGE ); ?>"
				>
					<?php foreach ( $abilities as $index => $row ) { ?>
						<?php $pre_hidden = ( $view_mode === 'paginated' ) && ( $index >= self::ROWS_PER_PAGE ); ?>
						<?php $this->render_ability_row( $row, $disabled_abilities, $pre_hidden ); ?>
					<?php } ?>

					<p class="albert-abilities-empty" hidden>
						<?php esc_html_e( 'No abilities match your filters.', 'albert-ai-butler' ); ?>
					</p>
				</div>

				<nav
					class="albert-abilities-pagination"
					aria-label="<?php esc_attr_e( 'Abilities pagination', 'albert-ai-butler' ); ?>"
					<?php
					if ( $view_mode !== 'paginated' ) {
						?>
						hidden<?php } ?>
				>
					<button type="button" class="button albert-pagination-prev" data-direction="prev">
						<?php esc_html_e( 'Previous', 'albert-ai-butler' ); ?>
					</button>
					<span class="albert-pagination-pages" aria-live="polite"></span>
					<button type="button" class="button albert-pagination-next" data-direction="next">
						<?php esc_html_e( 'Next', 'albert-ai-butler' ); ?>
					</button>
				</nav>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the sticky filter toolbar.
	 *
	 * @param array<string, string> $categories    Category slug => label.
	 * @param array<string, string> $suppliers     Supplier slug => label.
	 * @param array<string, string> $annotations   Annotation slug => label.
	 * @param int                   $enabled_count Number of currently-enabled abilities.
	 * @param int                   $total_count   Total ability count.
	 * @param string                $view_mode     Current view mode (`list` or `paginated`).
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function render_toolbar( array $categories, array $suppliers, array $annotations, int $enabled_count, int $total_count, string $view_mode ): void {
		$is_paginated = $view_mode === 'paginated';
		?>
		<div class="albert-abilities-toolbar" role="region" aria-label="<?php esc_attr_e( 'Filter abilities', 'albert-ai-butler' ); ?>">
			<div class="albert-toolbar-filters">
				<label class="albert-toolbar-field albert-toolbar-field--search">
					<span class="albert-toolbar-label"><?php esc_html_e( 'Search', 'albert-ai-butler' ); ?></span>
					<input
						type="search"
						id="albert-abilities-search"
						class="albert-search"
						placeholder="<?php esc_attr_e( 'Search by name, description, or ID', 'albert-ai-butler' ); ?>"
						aria-controls="albert-abilities-list"
						autocomplete="off"
					/>
				</label>

				<label class="albert-toolbar-field">
					<span class="albert-toolbar-label"><?php esc_html_e( 'Category', 'albert-ai-butler' ); ?></span>
					<select id="albert-abilities-filter-category" class="albert-filter-category">
						<option value=""><?php esc_html_e( 'All categories', 'albert-ai-butler' ); ?></option>
						<?php foreach ( $categories as $slug => $label ) { ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php } ?>
					</select>
				</label>

				<label class="albert-toolbar-field">
					<span class="albert-toolbar-label"><?php esc_html_e( 'Supplier', 'albert-ai-butler' ); ?></span>
					<select id="albert-abilities-filter-supplier" class="albert-filter-supplier">
						<option value=""><?php esc_html_e( 'All suppliers', 'albert-ai-butler' ); ?></option>
						<?php foreach ( $suppliers as $slug => $label ) { ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php } ?>
					</select>
				</label>

				<label class="albert-toolbar-field">
					<span class="albert-toolbar-label"><?php esc_html_e( 'Type', 'albert-ai-butler' ); ?></span>
					<select id="albert-abilities-filter-annotation" class="albert-filter-annotation">
						<option value=""><?php esc_html_e( 'All types', 'albert-ai-butler' ); ?></option>
						<?php foreach ( $annotations as $slug => $label ) { ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php } ?>
					</select>
				</label>
			</div>

			<div class="albert-toolbar-meta">
				<div class="albert-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View mode', 'albert-ai-butler' ); ?>">
					<button
						type="button"
						class="albert-view-toggle-btn<?php echo $is_paginated ? '' : ' is-active'; ?>"
						data-view="list"
						aria-pressed="<?php echo $is_paginated ? 'false' : 'true'; ?>"
					>
						<?php esc_html_e( 'List', 'albert-ai-butler' ); ?>
					</button>
					<button
						type="button"
						class="albert-view-toggle-btn<?php echo $is_paginated ? ' is-active' : ''; ?>"
						data-view="paginated"
						aria-pressed="<?php echo $is_paginated ? 'true' : 'false'; ?>"
					>
						<?php esc_html_e( 'Paginated', 'albert-ai-butler' ); ?>
					</button>
				</div>
				<p
					class="albert-toolbar-stats"
					id="albert-abilities-stats"
					aria-live="polite"
					<?php /* translators: 1: visible count, 2: total count, 3: enabled count. */ ?>
					data-template-all="<?php esc_attr_e( 'Showing %1$s of %2$s · %3$s enabled', 'albert-ai-butler' ); ?>"
					data-total="<?php echo esc_attr( (string) $total_count ); ?>"
					data-enabled-count="<?php echo esc_attr( (string) $enabled_count ); ?>"
				>
					<?php
					printf(
						/* translators: 1: visible count, 2: total count, 3: enabled count. */
						esc_html__( 'Showing %1$s of %2$s · %3$s enabled', 'albert-ai-butler' ),
						esc_html( (string) $total_count ),
						esc_html( (string) $total_count ),
						esc_html( (string) $enabled_count )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single ability row.
	 *
	 * @param array<string, mixed> $row                Normalized ability row data.
	 * @param array<string>        $disabled_abilities List of disabled ability ids.
	 * @param bool                 $pre_hidden         Whether to pre-render the row hidden
	 *                                                 (used for paginated view to avoid a
	 *                                                 flash of all rows before JS runs).
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function render_ability_row( array $row, array $disabled_abilities, bool $pre_hidden = false ): void {
		$id              = $row['id'];
		$label           = $row['label'];
		$description     = $row['description'];
		$category_slug   = $row['category_slug'];
		$category_lbl    = $row['category_label'];
		$supplier_slug   = $row['supplier_slug'];
		$supplier_lbl    = $row['supplier_label'];
		$annotations     = $row['annotations'];
		$annotation_slug = $row['annotation_slug'];
		$chips           = AnnotationPresenter::chips_for( $annotations, $id );
		$is_destruct     = AnnotationPresenter::is_destructive( $annotations, $id );
		$is_enabled      = ! in_array( $id, $disabled_abilities, true );

		$dom_id     = 'albert-ability-' . sanitize_html_class( str_replace( '/', '-', $id ) );
		$details_id = $dom_id . '-details';
		$toggle_id  = $dom_id . '-toggle';

		$search_haystack = strtolower( $label . ' ' . $description . ' ' . $id );
		?>
		<div
			class="ability-row"
			role="listitem"
			data-ability-id="<?php echo esc_attr( $id ); ?>"
			data-category="<?php echo esc_attr( $category_slug ); ?>"
			data-supplier="<?php echo esc_attr( $supplier_slug ); ?>"
			data-annotation="<?php echo esc_attr( $annotation_slug ); ?>"
			data-search="<?php echo esc_attr( $search_haystack ); ?>"
			data-destructive="<?php echo $is_destruct ? '1' : '0'; ?>"
			data-enabled="<?php echo $is_enabled ? '1' : '0'; ?>"
			<?php
			if ( $pre_hidden ) {
				?>
				hidden<?php } ?>
		>

			<div class="ability-row-main">
				<button
					type="button"
					class="ability-row-expand"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $details_id ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ability label. */ __( 'Show details for %s', 'albert-ai-butler' ), $label ) ); ?>"
				>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</button>

				<div class="ability-row-body">
					<label for="<?php echo esc_attr( $toggle_id ); ?>" class="ability-row-label"><?php echo esc_html( $label ); ?></label>
					<?php if ( $description !== '' ) { ?>
						<p class="ability-row-description"><?php echo esc_html( $description ); ?></p>
					<?php } ?>

					<?php if ( ! empty( $chips ) ) { ?>
						<ul class="ability-row-annotations" aria-label="<?php esc_attr_e( 'What this ability does', 'albert-ai-butler' ); ?>">
							<?php foreach ( $chips as $chip ) { ?>
								<?php $desc_id = $dom_id . '-chip-' . $chip['key'] . '-desc'; ?>
								<li
									class="ability-chip ability-chip--<?php echo esc_attr( $chip['tone'] ); ?>"
									tabindex="0"
									aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
								>
									<?php if ( $chip['tone'] === 'danger' ) { ?>
										<span class="screen-reader-text"><?php esc_html_e( 'Warning: ', 'albert-ai-butler' ); ?></span>
									<?php } ?>
									<span class="dashicons <?php echo esc_attr( $chip['icon'] ); ?>" aria-hidden="true"></span>
									<span class="ability-chip-label"><?php echo esc_html( $chip['label'] ); ?></span>
									<span
										class="ability-chip-desc"
										id="<?php echo esc_attr( $desc_id ); ?>"
										role="tooltip"
									><?php echo esc_html( $chip['description'] ); ?></span>
								</li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>

				<div class="ability-row-toggle">
					<label class="albert-toggle" for="<?php echo esc_attr( $toggle_id ); ?>">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $toggle_id ); ?>"
							class="ability-row-checkbox"
							value="<?php echo esc_attr( $id ); ?>"
							<?php checked( $is_enabled ); ?>
						/>
						<span class="albert-toggle-state" aria-hidden="true">
							<span class="albert-toggle-state-word albert-toggle-state-word--on"><?php esc_html_e( 'Enabled', 'albert-ai-butler' ); ?></span>
							<span class="albert-toggle-state-word albert-toggle-state-word--off"><?php esc_html_e( 'Disabled', 'albert-ai-butler' ); ?></span>
						</span>
						<span class="albert-toggle-slider" aria-hidden="true"></span>
						<span class="screen-reader-text">
							<?php echo esc_html( sprintf( /* translators: %s: ability label. */ __( 'Enable %s', 'albert-ai-butler' ), $label ) ); ?>
						</span>
					</label>
				</div>
			</div>

			<div class="ability-row-details" id="<?php echo esc_attr( $details_id ); ?>" hidden>
				<dl class="ability-row-details-grid">
					<dt><?php esc_html_e( 'Ability ID', 'albert-ai-butler' ); ?></dt>
					<dd>
						<code class="ability-row-id"><?php echo esc_html( $id ); ?></code>
					</dd>

					<dt><?php esc_html_e( 'Supplier', 'albert-ai-butler' ); ?></dt>
					<dd><?php echo esc_html( $supplier_lbl ); ?></dd>

					<?php if ( $category_lbl !== '' ) { ?>
						<dt><?php esc_html_e( 'Category', 'albert-ai-butler' ); ?></dt>
						<dd><?php echo esc_html( $category_lbl ); ?></dd>
					<?php } ?>
				</dl>
			</div>
		</div>
		<?php
	}

	/**
	 * Collect every registered ability into normalized row data.
	 *
	 * Sorted by category label, then ability label.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.1.0
	 */
	private static function collect_abilities(): array {
		$all        = wp_get_abilities();
		$categories = wp_get_ability_categories();
		$rows       = [];

		foreach ( $all as $ability ) {
			$id          = $ability->get_name();
			$meta        = (array) $ability->get_meta();
			$source      = AbilitiesRegistry::get_ability_source( $id );
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];

			$chips           = AnnotationPresenter::chips_for( $annotations, $id );
			$annotation_slug = ! empty( $chips ) ? $chips[0]['key'] : '';

			$rows[] = [
				'id'               => $id,
				'label'            => $ability->get_label(),
				'description'      => $ability->get_description(),
				'category_slug'    => $ability->get_category(),
				'category_label'   => self::resolve_category_label( $ability->get_category(), $categories ),
				'supplier_slug'    => $source['slug'],
				'supplier_label'   => $source['label'],
				'annotations'      => $annotations,
				'annotation_slug'  => $annotation_slug,
				'annotation_label' => self::resolve_annotation_label( $annotation_slug ),
			];
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$cat = strcasecmp( $a['category_label'], $b['category_label'] );
				if ( 0 !== $cat ) {
					return $cat;
				}
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $rows;
	}

	/**
	 * Build a deduplicated, sorted map of filter dropdown options.
	 *
	 * Used for both the category and supplier filter dropdowns. Extracts
	 * unique slug → label pairs from the collected rows, skipping empty
	 * slugs, and sorts alphabetically by label.
	 *
	 * @param array<int, array<string, mixed>> $abilities Collected rows.
	 * @param string                           $slug_key  Row key for the slug value.
	 * @param string                           $label_key Row key for the label value.
	 *
	 * @return array<string, string>
	 * @since 1.1.0
	 */
	private static function collect_filter_options( array $abilities, string $slug_key, string $label_key ): array {
		$options = [];
		foreach ( $abilities as $row ) {
			$slug = $row[ $slug_key ];
			if ( $slug !== '' && ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = $row[ $label_key ];
			}
		}
		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );
		return $options;
	}

	/**
	 * Resolve a category slug to its human label.
	 *
	 * @param string               $slug       Category slug.
	 * @param array<string, mixed> $categories Map from wp_get_ability_categories().
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private static function resolve_category_label( string $slug, array $categories ): string {
		if ( $slug === '' ) {
			return '';
		}
		if ( isset( $categories[ $slug ] ) ) {
			$category = $categories[ $slug ];
			if ( is_object( $category ) && method_exists( $category, 'get_label' ) ) {
				return (string) $category->get_label();
			}
			if ( is_array( $category ) && isset( $category['label'] ) ) {
				return (string) $category['label'];
			}
		}
		return ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	/**
	 * Resolve an annotation slug to its human label.
	 *
	 * @param string $slug Annotation slug (read, write, delete).
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private static function resolve_annotation_label( string $slug ): string {
		$labels = [
			'read'   => __( 'Read', 'albert-ai-butler' ),
			'write'  => __( 'Write', 'albert-ai-butler' ),
			'delete' => __( 'Delete', 'albert-ai-butler' ),
		];

		return $labels[ $slug ] ?? ucfirst( $slug );
	}

	/**
	 * Get currently disabled abilities.
	 *
	 * On fresh install returns the default blocklist (Albert write abilities).
	 *
	 * @return array<int, string>
	 * @since 1.1.0
	 */
	public static function get_disabled_abilities(): array {
		$disabled = get_option( self::DISABLED_ABILITIES_OPTION, [] );

		if ( empty( $disabled ) && ! get_option( 'albert_abilities_saved' ) ) {
			return AbilitiesRegistry::get_default_disabled_abilities();
		}

		return (array) $disabled;
	}

	/**
	 * Validate an ability slug.
	 *
	 * @param string $ability_slug Slug to validate.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	private function is_valid_ability_slug( string $ability_slug ): bool {
		return (bool) preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/', $ability_slug );
	}

	/**
	 * Enqueue admin assets for this page only.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'albert_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-admin-utils',
			ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
			[],
			ALBERT_VERSION,
			true
		);

		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'albert-admin-utils' ],
			ALBERT_VERSION,
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'toggleAbilityNonce' => wp_create_nonce( 'albert_toggle_ability' ),
				'viewModeNonce'      => wp_create_nonce( 'albert_view_mode' ),
				'i18n'               => [
					'copied'             => __( 'Copied!', 'albert-ai-butler' ),
					'copyFailed'         => __( 'Copy failed', 'albert-ai-butler' ),
					/* translators: 1: visible count, 2: total count, 3: enabled count. */
					'statsTemplate'      => __( 'Showing %1$s of %2$s · %3$s enabled', 'albert-ai-butler' ),
					/* translators: 1: current page number, 2: total page count. */
					'pageTemplate'       => __( 'Page %1$s of %2$s', 'albert-ai-butler' ),
					'destructiveConfirm' => __( 'This ability can permanently delete data. Are you sure you want to enable it?', 'albert-ai-butler' ),
					'noMatches'          => __( 'No abilities match your filters.', 'albert-ai-butler' ),
					'saveError'          => __( 'Could not save your change. Please try again.', 'albert-ai-butler' ),
					'sessionExpired'     => __( 'Your session has expired. Reload the page and try again.', 'albert-ai-butler' ),
				],
			]
		);
	}
}
