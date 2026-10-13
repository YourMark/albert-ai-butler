<?php
/**
 * Dashboard Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Connections\UserPickerModal;
use Albert\Admin\Dashboard\AddonState;
use Albert\Admin\Dashboard\Attention;
use Albert\Admin\Dashboard\Recommendations;
use Albert\Admin\Dashboard\Suggestions;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\Plugin;
use Albert\Logging\Outcome;
use Albert\Logging\Repository as LoggingRepository;
use Albert\MCP\Server as McpServer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Privacy\PrivacyMode;

/**
 * Dashboard class
 *
 * Manages the plugin dashboard page - primary landing page for Albert.
 * Shows a contextual setup checklist for new users and status for returning users.
 *
 * @since 1.0.0
 */
class Dashboard implements Hookable {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $parent_slug = 'albert';

	/**
	 * Dashboard page slug (same as parent to make it the first submenu).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'albert';

	/**
	 * Ability log repository.
	 *
	 * @since 1.1.0
	 * @var LoggingRepository
	 */
	private LoggingRepository $logging_repository;

	/**
	 * How many activity rows are meant to be read.
	 *
	 * A sixth is rendered when there is one, purely to sit under the fade —
	 * see {@see self::get_recent_activity()}.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const ACTIVITY_ROWS = 5;

	/**
	 * Whether the activity list had more events than it shows.
	 *
	 * Set by {@see self::get_recent_activity()}, read when deciding to draw the
	 * fade. Null until that has run.
	 *
	 * @since 1.4.0
	 * @var bool|null
	 */
	private ?bool $activity_truncated = null;

	/**
	 * The live connections for this request, or null before the first read.
	 *
	 * @since 1.4.0
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $live_connections = null;

	/**
	 * The screen id `add_menu_page()` gave this page, or '' before it ran.
	 *
	 * Taken from core rather than written out as `toplevel_page_albert`, which
	 * is what the enqueue guard compared against. That literal is core's own
	 * derivation from the menu slug, so restating it is a second copy of a rule
	 * this class does not own: rename the slug and the assets stop loading,
	 * silently, on a page that still renders.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private string $screen_id = '';

	/**
	 * Constructor.
	 *
	 * @param LoggingRepository $logging_repository Ability log repository.
	 *
	 * @since 1.1.0
	 */
	public function __construct( LoggingRepository $logging_repository ) {
		$this->logging_repository = $logging_repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_' . Attention::DISMISS_ACTION, [ $this, 'handle_dismiss_attention' ] );
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ], Menu::POSITION_DASHBOARD );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add top-level menu and dashboard page.
	 *
	 * Creates the top-level "Albert" menu with Dashboard as the default page,
	 * then adds "Dashboard" as the first submenu (which replaces the auto-generated one).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_pages(): void {
		// Add top-level menu (shows Dashboard by default).
		$this->screen_id = (string) add_menu_page(
			__( 'Albert Dashboard', 'albert-ai-butler' ),
			__( 'Albert', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_dashboard_page' ],
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbDpzcGFjZT0icHJlc2VydmUiIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNNjkuNCAxNC40Yy0uOS44LTEgMy40LTEgMTguOSAwIDE2LjcuMSAxOC4xIDEuMSAxOSAuNi42IDEuNSAxIDIgMXM5LjgtMi4zIDIwLjctNWwxOS44LTUgMi44IDIuOWM3LjggOC4xIDE5LjUgOC4yIDI3LjMuNWwzLjQtMy40IDIwLjEgNS4xYzEyLjggMy4zIDIwLjUgNC45IDIxLjQgNC42LjctLjIgMS42LTEuMSAyLTIgLjMtLjguNi04LjkuNi0xNy45IDAtMTcuOS0uMi0xOS40LTMuMi0xOS43LS45LS4xLTEwLjUgMi0yMS4zIDQuOGwtMTkuNyA1LTIuOC0zLjFjLTMuOS00LjEtNy45LTUuOC0xMy44LTUuOS01LjcgMC0xMC40IDItMTQuMSA2LjJsLTIuNSAyLjgtMTkuNC00LjljLTEwLjYtMi44LTIwLTUtMjAuOS01LS45LjEtMiAuNS0yLjUgMS4xeiIvPjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik0yNS4yIDUyLjRjLTYgMy41LTExLjUgNi44LTEyLjIgNy40LTMuMSAyLjgtMy0xLjctMyA5MS4xIDAgNjMuNC4yIDg3LjEuNyA4OC4yIDEuNyAzLjYtNSAzLjQgMTE3LjMgMy40czExNS43LjIgMTE3LjMtMy40Yy41LTEuMS43LTI0LjIuNy04NS43IDAtODIuOCAwLTg0LjItMS4yLTg2LjYtMS4zLTIuNi0yLTMuMy0xNS40LTEzLjgtNC45LTMuOS05LjEtNi44LTkuMy02LjYtLjIuMi0xOS40IDM3LjEtNDIuNyA4MS45LTIzLjIgNDQuOC00My44IDg0LjMtNDUuNyA4Ny44bC0zLjQgNi4zLTQuMS03LjktNDUuOC04Ny44Yy0yMi45LTQzLjktNDEuNy04MC4xLTQyLTgwLjMtLjEtLjEtNS4yIDIuNS0xMS4yIDZ6Ii8+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTEyNS42IDc2LjdjLTcuOSAxLjUtMTMuNCA5LjgtMTEuNyAxNy44IDIuOCAxMi45IDE5LjQgMTYuNiAyNyA2IDguMS0xMS4yLTEuNy0yNi4zLTE1LjMtMjMuOHpNMTIzLjYgMTI4YTE0LjQgMTQuNCAwIDAgMC04LjIgNy41Yy0zLjEgNi4zLTEuOCAxMy42IDMuMyAxOC4xIDMuMSAyLjcgNS43IDMuNiAxMCAzLjYgNC40IDAgNy42LTEuMiAxMC4zLTMuOSA5LjctOS4zIDQuMS0yNS4yLTkuMy0yNi0yLjQtLjEtNC43LjEtNi4xLjd6Ii8+PC9zdmc+',
			80
		);

		// Add Dashboard submenu (replaces auto-generated first submenu).
		add_submenu_page(
			$this->parent_slug,
			__( 'Dashboard', 'albert-ai-butler' ),
			__( 'Dashboard', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_dashboard_page' ]
		);
	}

	/**
	 * Enqueue dashboard assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on our dashboard page, identified by the screen id core
		// handed back from add_menu_page() rather than by a copy of it.
		if ( $this->screen_id === '' || $hook !== $this->screen_id ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[ Assets::PRIMITIVES_HANDLE ],
			Assets::version( 'assets/css/admin-settings.css' )
		);

		wp_enqueue_script(
			'albert-admin-utils',
			ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
			[],
			Assets::version( 'assets/js/albert-admin-utils.js' ),
			true
		);

		// The same script Connections and Settings load, not a Dashboard-specific
		// one. `admin-dashboard.js` used to exist purely to wire a copy button,
		// against its own `.albert-copy-btn` class, duplicating the handler in
		// `admin-settings.js` that every other screen already used. One endpoint
		// field, one class, one handler.
		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'albert-admin-utils' ],
			Assets::version( 'assets/js/admin-settings.js' ),
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'albert_oauth_nonce' ),
				'dismissNonce' => wp_create_nonce( Attention::DISMISS_ACTION ),
				'i18n'         => [
					'copied'     => __( 'Copied!', 'albert-ai-butler' ),
					'copyFailed' => __( 'Copy failed', 'albert-ai-butler' ),
				],
			]
		);

		// The onboarding checklist's "Choose users" button opens the Connections
		// screen's picker, so the dashboard loads the same script and the same
		// localised data rather than a copy of either.
		UserPickerModal::enqueue( 'dashboard' );
	}

	/**
	 * Render dashboard page.
	 *
	 * Two states, not one layout with conditional cards (handoff §8.1): an
	 * unfinished setup is a task list, a finished one is a status readout, and
	 * they answer different questions.
	 *
	 * There are deliberately no buttons in the header. "Manage abilities" and
	 * "View connections" are navigation, not actions; the submenu and the tab
	 * row already reach both, and on a finished setup there is no single next
	 * action to promote. The only filled button on the screen belongs to the
	 * current onboarding step, where there genuinely is one thing to do next.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		$has_allowed_users  = AllowedUsers::has_any();
		$active_connections = count( $this->get_live_connections() );
		$has_connections    = $active_connections > 0;
		$setup_complete     = $has_allowed_users && $has_connections;
		$abilities          = $this->get_ability_counts();
		$mcp_endpoint       = McpServer::get_endpoint_url();

		?>
		<div class="wrap albert-dashboard-page">
			<div class="albert-page">
				<div class="albert-page__header">
					<div class="albert-page__text">
						<h1 class="albert-page__title"><?php esc_html_e( 'Albert', 'albert-ai-butler' ); ?></h1>
						<p class="albert-page__description">
							<?php esc_html_e( 'Connect your WordPress site to AI assistants like Claude and ChatGPT.', 'albert-ai-butler' ); ?>
						</p>
					</div>
				</div>
				<hr class="wp-header-end">

				<?php Notices::render( 'albert_connections' ); ?>

				<div class="albert-page__body">
					<?php
					if ( $setup_complete ) {
						$this->render_complete_state( $active_connections, $abilities, $mcp_endpoint );
					} else {
						$this->render_onboarding_state( $has_allowed_users, $has_connections, $abilities, $mcp_endpoint );
					}
					?>
				</div>
			</div>
		</div>
		<?php

		// The same picker the Connections screen opens, from the same markup and
		// the same script. Two pickers answering one question drift.
		UserPickerModal::render( 'dashboard' );
	}

	/**
	 * State B: setup complete.
	 *
	 * Status banner, then the figures this site can actually compute, then the
	 * two-column body: what happened recently on the left, where to point an
	 * assistant on the right.
	 *
	 * @param int                             $active_connections Live connection count.
	 * @param array{enabled: int, total: int} $abilities         Ability counts.
	 * @param string                          $mcp_endpoint       The MCP endpoint URL.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_complete_state( int $active_connections, array $abilities, string $mcp_endpoint ): void {
		?>
		<section class="albert-setupdone">
			<span class="albert-setupdone__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<div class="albert-setupdone__text">
				<p class="albert-setupdone__title"><?php esc_html_e( 'Albert is connected and ready', 'albert-ai-butler' ); ?></p>
				<p class="albert-setupdone__detail">
					<?php
					/* translators: 1: number of connected assistants, 2: number of enabled abilities, 3: total abilities. */
					$detail = _n(
						'Setup complete. %1$d assistant connected, %2$d of %3$d abilities enabled.',
						'Setup complete. %1$d assistants connected, %2$d of %3$d abilities enabled.',
						$active_connections,
						'albert-ai-butler'
					);

					printf(
						esc_html( $detail ),
						(int) $active_connections,
						(int) $abilities['enabled'],
						(int) $abilities['total']
					);
					?>
				</p>
			</div>
		</section>

		<?php $this->render_stat_row( $abilities, $active_connections ); ?>

		<?php $this->render_attention_card(); ?>

		<div class="albert-dashboard__split">
			<div class="albert-dashboard__main">
				<?php $this->render_activity_card(); ?>
				<?php $this->render_suggestions_card(); ?>
			</div>

			<div class="albert-dashboard__aside">
				<?php $this->render_endpoint_card( $mcp_endpoint ); ?>
				<?php $this->render_recommendation_card(); ?>
				<?php $this->render_resources_card(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * State A: setup unfinished.
	 *
	 * A progress bar and four numbered steps, with the current one carrying the
	 * only filled button on the screen. Beside it, what an assistant would be
	 * able to do here once connected.
	 *
	 * The handoff's sub line reads "Two steps left, about three minutes." The
	 * step count is computed and kept; the time estimate is dropped, because
	 * doc 70 §0 forbids rendering a number the site cannot compute and we have
	 * no idea how long anyone's setup takes.
	 *
	 * @param bool                            $has_allowed_users Whether anyone may approve an assistant.
	 * @param bool                            $has_connections   Whether anything is connected.
	 * @param array{enabled: int, total: int} $abilities        Ability counts.
	 * @param string                          $mcp_endpoint      The MCP endpoint URL.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_onboarding_state( bool $has_allowed_users, bool $has_connections, array $abilities, string $mcp_endpoint ): void {
		// Step 1 (installed) is always done; 4 (review abilities) is optional and
		// never blocks, so "left" counts the two that actually gate a connection.
		$done      = 1 + ( $has_allowed_users ? 1 : 0 ) + ( $has_connections ? 1 : 0 );
		$total     = 4;
		$remaining = max( 0, 3 - $done );
		?>
		<div class="albert-dashboard__split">
			<section class="albert-card albert-setup">
				<div class="albert-card__header albert-setup__head">
					<div class="albert-card__text">
						<h2 class="albert-card__title"><?php esc_html_e( 'Set up Albert', 'albert-ai-butler' ); ?></h2>
						<p class="albert-card__description">
							<?php
							printf(
								/* translators: %d: number of setup steps still to do. */
								esc_html( _n( '%d step left.', '%d steps left.', $remaining, 'albert-ai-butler' ) ),
								(int) $remaining
							);
							?>
						</p>
					</div>
					<?php
					$percent = (int) round( ( $done / $total ) * 100 );
					?>
					<div
						class="albert-setup__progress"
						role="progressbar"
						aria-valuenow="<?php echo esc_attr( (string) $done ); ?>"
						aria-valuemin="0"
						aria-valuemax="<?php echo esc_attr( (string) $total ); ?>"
						aria-label="<?php esc_attr_e( 'Setup progress', 'albert-ai-butler' ); ?>">
						<span class="albert-setup__progress-fill" style="inline-size: <?php echo esc_attr( (string) $percent ); ?>%;"></span>
					</div>
				</div>

				<ol class="albert-setup__steps">
					<?php
					$this->render_setup_step(
						1,
						true,
						false,
						__( 'Plugin installed', 'albert-ai-butler' ),
						__( 'Done. The OAuth server and MCP endpoint are running.', 'albert-ai-butler' )
					);
					?>

					<?php
					$this->render_setup_step(
						2,
						$has_allowed_users,
						! $has_allowed_users,
						__( 'Add an allowed user', 'albert-ai-butler' ),
						__( 'Only the users you pick here can authorise an AI assistant to act on this site.', 'albert-ai-butler' ),
						function (): void {
							?>
							<div class="albert-setup__actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>" class="button button-primary" data-albert-open-userpicker>
									<?php esc_html_e( 'Choose users', 'albert-ai-butler' ); ?>
								</a>
							</div>
							<?php
						}
					);
		?>

					<?php
					$this->render_setup_step(
						3,
						$has_connections,
						$has_allowed_users && ! $has_connections,
						__( 'Connect an AI assistant', 'albert-ai-butler' ),
						__( 'Paste this endpoint into Claude or ChatGPT as an MCP connector.', 'albert-ai-butler' ),
						function () use ( $mcp_endpoint ): void {
							?>
							<div class="albert-endpoint">
								<label class="screen-reader-text" for="albert-mcp-endpoint"><?php esc_html_e( 'MCP endpoint address', 'albert-ai-butler' ); ?></label>
								<input
									type="text"
									id="albert-mcp-endpoint"
									class="albert-endpoint__field"
									value="<?php echo esc_url( $mcp_endpoint ); ?>"
									readonly
								/>
								<button type="button" class="button button-secondary albert-copy-button" data-copy-target="albert-mcp-endpoint">
									<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
								</button>
							</div>
							<?php
						}
					);
		?>

					<?php
					$review = sprintf(
						/* translators: %d: number of abilities enabled by default. */
						__( '%d abilities are enabled by default.', 'albert-ai-butler' ),
						$abilities['enabled']
					);
					$this->render_setup_step(
						4,
						false,
						false,
						__( 'Choose what Albert may do', 'albert-ai-butler' ),
						$review,
						function (): void {
							?>
							<p class="albert-setup__aside-link">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-abilities' ) ); ?>">
									<?php esc_html_e( 'Review them', 'albert-ai-butler' ); ?>
								</a>
							</p>
							<?php
						}
					);
		?>
				</ol>
			</section>

			<div class="albert-dashboard__aside">
				<?php $this->render_suggestions_card( true ); ?>
				<?php $this->render_resources_card(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one onboarding step.
	 *
	 * The marker is a numbered circle, and its state is carried by the shape
	 * (filled accent for current, success tick for done, outline for pending)
	 * plus the step's own text, never by colour alone.
	 *
	 * @param int           $number      The step's position, 1-indexed.
	 * @param bool          $is_done     Whether the step is complete.
	 * @param bool          $is_current  Whether this is the step to do next.
	 * @param string        $title       Step title.
	 * @param string        $description Step description.
	 * @param callable|null $extra       Optional renderer for the step's controls.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_setup_step( int $number, bool $is_done, bool $is_current, string $title, string $description, ?callable $extra = null ): void {
		$classes = 'albert-setup__step';
		if ( $is_done ) {
			$classes .= ' albert-setup__step--done';
		} elseif ( $is_current ) {
			$classes .= ' albert-setup__step--current';
		}
		?>
		<li class="<?php echo esc_attr( $classes ); ?>">
			<span class="albert-setup__marker" aria-hidden="true">
				<?php if ( $is_done ) { ?>
					<span class="dashicons dashicons-yes-alt"></span>
				<?php } else { ?>
					<?php echo esc_html( (string) $number ); ?>
				<?php } ?>
			</span>
			<div class="albert-setup__body">
				<p class="albert-setup__title">
					<?php echo esc_html( $title ); ?>
					<span class="screen-reader-text">
						<?php
						if ( $is_done ) {
							esc_html_e( '(done)', 'albert-ai-butler' );
						} elseif ( $is_current ) {
							esc_html_e( '(current step)', 'albert-ai-butler' );
						} else {
							esc_html_e( '(not started)', 'albert-ai-butler' );
						}
						?>
					</span>
				</p>
				<p class="albert-setup__description"><?php echo esc_html( $description ); ?></p>
				<?php
				if ( $extra !== null && ! $is_done ) {
					call_user_func( $extra );
				}
				?>
			</div>
		</li>
		<?php
	}

	/**
	 * The stat row: only figures this site can actually compute.
	 *
	 * Free has two. Calls, failures and median duration all need history Free
	 * does not retain ({@see \Albert\Logging\Repository} prunes to two rows per
	 * ability), so rather than a hardcoded Premium check for aggregates that do
	 * not exist yet, this is a seam: an add-on with the history appends its own
	 * tiles. Nothing hooks it today, so nothing renders beyond Free's two —
	 * never a zero, never an empty tile shaped like a promise.
	 *
	 * @param array{enabled: int, total: int} $abilities          Ability counts.
	 * @param int                             $active_connections Live connection count.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_stat_row( array $abilities, int $active_connections ): void {
		$disabled = max( 0, $abilities['total'] - $abilities['enabled'] );

		$abilities_meta = '';
		if ( $disabled > 0 ) {
			$abilities_meta = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=albert-abilities' ) ),
				esc_html(
					sprintf(
						/* translators: %d: number of abilities currently switched off. */
						_n( 'Review the %d you switched off', 'Review the %d you switched off', $disabled, 'albert-ai-butler' ),
						$disabled
					)
				)
			);
		}

		$stats = [
			[
				'label' => __( 'Enabled abilities', 'albert-ai-butler' ),
				'value' => sprintf(
					/* translators: 1: enabled ability count, 2: total ability count. */
					__( '%1$d / %2$d', 'albert-ai-butler' ),
					$abilities['enabled'],
					$abilities['total']
				),
				'meta'  => $abilities_meta,
			],
			[
				'label' => __( 'Connections', 'albert-ai-butler' ),
				'value' => number_format_i18n( $active_connections ),
				'meta'  => esc_html( $this->get_connection_names() ),
			],
		];

		$stats[] = $this->privacy_stat();

		/**
		 * Filters the tiles shown in the Dashboard's stat row.
		 *
		 * Each tile is `[ 'label' => string, 'value' => string, 'meta' => string ]`
		 * plus an optional `indicator`. `label` and `value` are escaped as text;
		 * `meta` may contain a link and is `wp_kses`'d down to `<a href>`;
		 * `indicator` names a status dot (`strict`/`balanced`/`off`) rather than
		 * passing markup.
		 *
		 * Free seeds the figures it can compute from data it retains; an add-on
		 * with its own history (call volume, failure rate, duration) appends
		 * tiles here rather than Free guessing at numbers it cannot verify. See
		 * docs/features/70-admin-design-system.md §4.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array{label: string, value: string, meta: string, indicator?: string}> $stats Stat tiles.
		 */
		$stats = apply_filters( 'albert/dashboard/stats', $stats );

		if ( ! is_array( $stats ) || empty( $stats ) ) {
			return;
		}
		?>
		<div class="albert-stat-row">
			<?php
			foreach ( $stats as $stat ) {
				if ( ! is_array( $stat ) || ! isset( $stat['label'], $stat['value'] ) ) {
					continue;
				}
				$meta = isset( $stat['meta'] ) && is_string( $stat['meta'] ) ? $stat['meta'] : '';
				?>
				<div class="albert-stat">
					<?php
					// sanitize_html_class(), not esc_attr(): this ends up as
					// half a class name, and that is the function WordPress
					// has for turning a string into one. esc_attr() only stops
					// it escaping the attribute, which leaves a filter free to
					// contribute spaces and buy itself extra classes.
					$indicator = isset( $stat['indicator'] ) && is_string( $stat['indicator'] )
						? sanitize_html_class( $stat['indicator'] )
						: '';
					?>
					<span class="albert-stat__label"><?php echo esc_html( (string) $stat['label'] ); ?></span>
					<span class="albert-stat__value<?php echo $indicator !== '' ? ' albert-stat__value--word' : ''; ?>">
						<?php if ( $indicator !== '' ) { ?>
							<span class="albert-degree albert-degree--<?php echo esc_attr( $indicator ); ?>" aria-hidden="true"></span>
						<?php } ?>
						<?php echo esc_html( (string) $stat['value'] ); ?>
					</span>
					<?php if ( $meta !== '' ) { ?>
						<?php // Pre-escaped by the builder above; add-ons are documented to do the same. ?>
						<span class="albert-stat__meta"><?php echo wp_kses( $meta, [ 'a' => [ 'href' => [] ] ] ); ?></span>
					<?php } ?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * The recent-activity card: Free's whole logging surface, alongside the
	 * per-ability "last run" line on the Abilities screen.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_activity_card(): void {
		$recent_activity = $this->get_recent_activity();

		// Three states, not two. Somebody who bought Premium and left it off
		// does not need a sales pitch, they need telling that the history they
		// paid for is being discarded while it sits there.
		$premium_state     = AddonState::of(
			'AlbertPremium\\AlbertPremiumService',
			'albert-premium-service/albert-premium-service.php'
		);
		$premium_installed = $premium_state === AddonState::INACTIVE;
		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Recent activity', 'albert-ai-butler' ); ?></h2>
				</div>
			</div>
			<?php if ( ! empty( $recent_activity ) ) { ?>
				<?php
				/*
				 * overflow-x with no way in. Chrome, Edge and Safari do not
				 * focus a scroll container, and nothing inside this table is
				 * focusable, so on a narrow viewport the columns that overflow
				 * were unreachable without a mouse. Context's PreviewCard
				 * already solves this the same way.
				 */
				?>
				<div
					class="albert-card__body albert-card__body--flush albert-activity-card__body"
					tabindex="0"
					role="region"
					aria-label="<?php esc_attr_e( 'Recent activity', 'albert-ai-butler' ); ?>"
				>
					<table class="albert-log-table">
						<caption class="screen-reader-text"><?php esc_html_e( 'The most recent ability executions and connections', 'albert-ai-butler' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Status', 'albert-ai-butler' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Event', 'albert-ai-butler' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'albert-ai-butler' ); ?></th>
								<th scope="col"><?php esc_html_e( 'When', 'albert-ai-butler' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_activity as $activity ) { ?>
								<tr>
									<td class="albert-log-table__status"><?php $this->render_status_dot( $activity['status'] ); ?></td>
									<td class="albert-log-table__event">
										<span class="albert-log-table__label"><?php echo esc_html( $activity['event'] ); ?></span>
										<?php if ( $activity['id'] !== '' ) { ?>
											<code class="albert-log-table__id"><?php echo esc_html( $activity['id'] ); ?></code>
										<?php } ?>
									</td>
									<td class="albert-log-table__by"><?php echo esc_html( $activity['actor'] ); ?></td>
									<td class="albert-log-table__when"><?php echo esc_html( $activity['time'] ); ?></td>
								</tr>
							<?php } ?>
						</tbody>
					</table>
					<?php if ( $this->activity_truncated ) { ?>
						<div class="albert-activity__fade" aria-hidden="true"></div>
					<?php } ?>
				</div>
			<?php } else { ?>
				<div class="albert-card__body">
					<p class="albert-dashboard__empty">
						<?php esc_html_e( 'Nothing has happened yet. Connect an AI assistant to get started.', 'albert-ai-butler' ); ?>
					</p>
				</div>
			<?php } ?>
			<?php if ( $premium_state !== AddonState::ACTIVE ) { ?>
				<div class="albert-upsell-cta">
					<h3 class="albert-upsell-cta__title">
						<?php
						echo esc_html(
							$premium_installed
								? __( 'Premium is installed but switched off', 'albert-ai-butler' )
								: __( 'This list only goes back a few actions', 'albert-ai-butler' )
						);
						?>
					</h3>
					<p class="albert-upsell-cta__lede">
						<?php
						echo esc_html(
							$premium_installed
								? __( 'Nothing is being kept while it is off. Albert holds only the most recent runs of each ability, so this history is being lost as it happens.', 'albert-ai-butler' )
								: __( 'Albert keeps only the most recent runs of each ability, so anything older than this is already gone.', 'albert-ai-butler' )
						);
						?>
					</p>
					<ul class="albert-upsell-cta__benefits">
						<li><?php esc_html_e( 'Every action kept, for as long as you choose', 'albert-ai-butler' ); ?></li>
						<li><?php esc_html_e( 'Filter by person, assistant or date', 'albert-ai-butler' ); ?></li>
						<li><?php esc_html_e( 'See what was sent and what came back, errors included', 'albert-ai-butler' ); ?></li>
					</ul>
					<?php if ( $premium_installed ) { ?>
						<a class="button button-primary albert-upsell-cta__button" href="<?php echo esc_url( AddonState::activation_url( 'Albert Premium Service' ) ); ?>">
							<?php esc_html_e( 'Switch it on', 'albert-ai-butler' ); ?>
						</a>
					<?php } else { ?>
						<a class="button button-primary albert-upsell-cta__button" href="<?php echo esc_url( 'https://albertwp.com/add-ons/premium-service/' ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Keep your full history', 'albert-ai-butler' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
						</a>
					<?php } ?>
				</div>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * The privacy tile.
	 *
	 * Three tiles, not four, and the fourth column is left for Premium's call
	 * volume. Exposure, access and protection are three different questions;
	 * anything else worth a tile here would be a number the Abilities screen
	 * already answers better.
	 *
	 * The degree is shown as a colour *and* the mode's name, never colour
	 * alone. The dot takes a status-light token rather than the text one, which
	 * is tuned to be read at type sizes and goes muddy at 10px.
	 *
	 * @return array{label: string, value: string, meta: string, indicator: string}
	 * @since 1.4.0
	 */
	private function privacy_stat(): array {
		$mode = PrivacyMode::resolve();

		$copy = [
			PrivacyMode::Strict->value   => [
				'label' => __( 'Strict', 'albert-ai-butler' ),
				'meta'  => __( 'Personal details are removed', 'albert-ai-butler' ),
				'tone'  => 'strict',
			],
			PrivacyMode::Balanced->value => [
				'label' => __( 'Balanced', 'albert-ai-butler' ),
				'meta'  => __( 'Emails and names are masked', 'albert-ai-butler' ),
				'tone'  => 'balanced',
			],
			PrivacyMode::Off->value      => [
				'label' => __( 'Off', 'albert-ai-butler' ),
				'meta'  => __( 'Assistants see personal details in full', 'albert-ai-butler' ),
				'tone'  => 'off',
			],
		];

		$current = $copy[ $mode->value ] ?? $copy[ PrivacyMode::Balanced->value ];

		return [
			'label'     => __( 'Privacy', 'albert-ai-butler' ),
			'value'     => $current['label'],
			// A name, not markup. The renderer escapes `value` as text, so a
			// tile that wanted a coloured dot would otherwise have to smuggle
			// HTML past esc_html() and would simply print its own tags.
			'indicator' => $current['tone'],
			'meta'      => esc_html( $current['meta'] ),
		];
	}

	/**
	 * Dismiss one attention item for the current user.
	 *
	 * Capability first, then the nonce, then the allow-list: only an item that
	 * currently exists and declares itself dismissible can be dismissed, so a
	 * crafted id cannot silence a warning the owner is not allowed to silence.
	 * That matters because the consequential items (a connection about to be
	 * dropped) are exactly the ones somebody might want gone.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function handle_dismiss_attention(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'albert-ai-butler' ) ], 403 );
		}

		check_ajax_referer( Attention::DISMISS_ACTION, 'nonce' );

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		if ( $id === '' ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to dismiss.', 'albert-ai-butler' ) ], 400 );
		}

		$attention = new Attention();
		$allowed   = false;

		foreach ( $attention->items() as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === $id && Attention::is_dismissible( $item ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed ) {
			wp_send_json_error( [ 'message' => __( 'That item cannot be dismissed.', 'albert-ai-butler' ) ], 400 );
		}

		Attention::dismiss( $id, get_current_user_id() );

		wp_send_json_success();
	}

	/**
	 * Render "Needs your attention".
	 *
	 * Always rendered, never hidden when empty. A card that disappears when
	 * there is nothing wrong is indistinguishable from one that is broken, and
	 * the value of this card is that an owner can glance at it and trust the
	 * absence of items.
	 *
	 * What qualifies as an item lives in {@see Attention}; this only draws what
	 * it is handed.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_attention_card(): void {
		$items = ( new Attention() )->items();
		?>
		<?php $empty_text = __( 'Nothing right now. Anything that needs a decision from you shows up here.', 'albert-ai-butler' ); ?>
		<?php
		/*
		 * The count sentence and the dismissal announcement are rewritten in JS
		 * after an item goes, so their translations have to travel with the
		 * markup: this script is not a wp-i18n consumer, and hardcoding English
		 * in it would leave every non-English site with a stale count in one
		 * language and a heading in another.
		 */

		/* translators: %d: how many things need attention */
		$count_one = __( '%d thing Albert noticed on this site.', 'albert-ai-butler' );

		/* translators: %d: how many things need attention */
		$count_many = __( '%d things Albert noticed on this site.', 'albert-ai-butler' );

		$dismissed_text = __( 'Item dismissed.', 'albert-ai-butler' );
		?>
		<section
			class="albert-card albert-attention"
			data-empty-text="<?php echo esc_attr( $empty_text ); ?>"
			data-count-one="<?php echo esc_attr( $count_one ); ?>"
			data-count-many="<?php echo esc_attr( $count_many ); ?>"
			data-dismissed-text="<?php echo esc_attr( $dismissed_text ); ?>"
		>
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Needs your attention', 'albert-ai-butler' ); ?></h2>
					<?php if ( $items !== [] ) { ?>
						<p class="albert-card__description">
							<?php
							printf(
								esc_html(
									/* translators: %d: how many things need attention */
									_n(
										'%d thing Albert noticed on this site.',
										'%d things Albert noticed on this site.',
										count( $items ),
										'albert-ai-butler'
									)
								),
								(int) count( $items )
							);
							?>
						</p>
					<?php } ?>
				</div>
			</div>
			<?php if ( $items === [] ) { ?>
				<div class="albert-card__body albert-attention__empty">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<p><?php echo esc_html( $empty_text ); ?></p>
				</div>
			<?php } else { ?>
				<div class="albert-card__body albert-card__body--flush">
					<ul class="albert-attention__list">
						<?php
						foreach ( $items as $item ) {
							$this->render_attention_item( $item );
						}
						?>
					</ul>
				</div>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * Render one attention item.
	 *
	 * The tone is carried by a word as well as a colour, so the severity
	 * survives for anyone who cannot see the stripe (WCAG 1.4.1).
	 *
	 * @param array<string, mixed> $item Attention item.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_attention_item( array $item ): void {
		$tone = isset( $item['tone'] ) && in_array( $item['tone'], [ 'danger', 'warning', 'info' ], true )
			? (string) $item['tone']
			: 'info';

		$action      = isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : null;
		$dismissible = Attention::is_dismissible( $item );
		?>
		<li class="albert-attention__item albert-attention__item--<?php echo esc_attr( $tone ); ?>">
			<span class="albert-attention__stripe" aria-hidden="true"></span>
			<div class="albert-attention__text">
				<?php if ( ! empty( $item['tone_label'] ) ) { ?>
					<span class="albert-attention__tone"><?php echo esc_html( (string) $item['tone_label'] ); ?></span>
				<?php } ?>
				<p class="albert-attention__title"><?php echo esc_html( (string) $item['title'] ); ?></p>
				<?php if ( ! empty( $item['detail'] ) ) { ?>
					<p class="albert-attention__detail"><?php echo esc_html( (string) $item['detail'] ); ?></p>
				<?php } ?>
			</div>
			<?php if ( $action !== null || $dismissible ) { ?>
				<span class="albert-attention__action">
					<?php if ( $action !== null && ! empty( $action['url'] ) && ! empty( $action['label'] ) ) { ?>
						<a class="button button-small" href="<?php echo esc_url( (string) $action['url'] ); ?>"><?php echo esc_html( (string) $action['label'] ); ?></a>
					<?php } ?>
					<?php if ( $dismissible ) { ?>
						<button
							type="button"
							class="albert-attention__dismiss"
							data-albert-dismiss-attention="<?php echo esc_attr( (string) $item['id'] ); ?>"
						><?php esc_html_e( 'Dismiss', 'albert-ai-butler' ); ?></button>
					<?php } ?>
				</span>
			<?php } ?>
		</li>
		<?php
	}

	/**
	 * Say so when the endpoint address is not this site's own.
	 *
	 * `active` is information: an owner who did not set the filter should know
	 * the address came from somewhere else. `invalid` is a fault, and the more
	 * important of the two, because the filter was ignored and the address on
	 * screen is silently not the one somebody configured. Nothing else in the
	 * plugin surfaces that.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_endpoint_override_notice(): void {
		$state = McpServer::get_external_url_state()['state'];

		if ( $state === 'inactive' ) {
			return;
		}

		$is_invalid = $state === 'invalid';
		?>
		<div class="albert-hint albert-hint--<?php echo esc_attr( $is_invalid ? 'warning' : 'info' ); ?> albert-endpoint__notice">
			<span class="dashicons dashicons-<?php echo esc_attr( $is_invalid ? 'warning' : 'info-outline' ); ?>" aria-hidden="true"></span>
			<p>
				<?php
				if ( $is_invalid ) {
					printf(
						/* translators: 1: opening <code>, 2: closing </code> wrapping a filter name */
						esc_html__( 'An %1$salbert/mcp/external_url%2$s filter returned an address Albert could not use, so this is your site\'s own address instead.', 'albert-ai-butler' ),
						'<code>',
						'</code>'
					);
				} else {
					printf(
						/* translators: 1: opening <code>, 2: closing </code> wrapping a filter name */
						esc_html__( 'This address comes from the %1$salbert/mcp/external_url%2$s filter, not from your site address.', 'albert-ai-butler' ),
						'<code>',
						'</code>'
					);
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render "Try asking your assistant".
	 *
	 * Skipped entirely when nothing qualifies, unlike the attention card: an
	 * absent suggestion list says nothing is wrong, it just means this site has
	 * too little switched on to suggest anything, and an empty card would be
	 * filler.
	 *
	 * One renderer, two framings. During setup the same prompts are a promise
	 * about what finishing gets you, and they carry the sentence that answers
	 * what actually stops people there: who is allowed to authorise, and who
	 * decides what an assistant may do. Afterwards they are something to try,
	 * and gain a copy button. A flag rather than a second card, so the two
	 * cannot drift apart in wording or markup.
	 *
	 * @param bool $before_setup Whether setup is still unfinished.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_suggestions_card( bool $before_setup = false ): void {
		$prompts = ( new Suggestions() )->all();

		if ( $prompts === [] ) {
			return;
		}
		?>
		<section class="albert-card albert-suggestions">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title">
						<?php
						echo esc_html(
							$before_setup
								? __( 'What you will be able to ask', 'albert-ai-butler' )
								: __( 'Try asking your assistant', 'albert-ai-butler' )
						);
						?>
					</h2>
					<p class="albert-card__description">
						<?php
						echo esc_html(
							$before_setup
								? __( 'Once an assistant is connected, on this site, with what you already have switched on.', 'albert-ai-butler' )
								: __( 'Each one works here today, because the abilities it needs are switched on.', 'albert-ai-butler' )
						);
						?>
					</p>
				</div>
			</div>
			<div class="albert-card__body albert-card__body--flush">
				<ul class="albert-suggestions__list">
					<?php foreach ( $prompts as $index => $prompt ) { ?>
						<li class="albert-suggestions__item">
							<span class="albert-suggestions__mark" aria-hidden="true">&ldquo;</span>
							<p class="albert-suggestions__text" id="albert-prompt-<?php echo (int) $index; ?>"><?php echo esc_html( (string) $prompt['text'] ); ?></p>
							<?php if ( ! $before_setup ) { ?>
								<button
									type="button"
									class="button button-small albert-copy-button albert-suggestions__copy"
									data-copy-target="albert-prompt-<?php echo (int) $index; ?>"
								><?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?></button>
							<?php } ?>
						</li>
					<?php } ?>
				</ul>
			</div>
			<?php if ( $before_setup ) { ?>
				<div class="albert-card__body albert-suggestions__assurance">
					<p>
						<?php esc_html_e( 'Only the people you choose can authorise an assistant, and you decide which of these it is allowed to do.', 'albert-ai-butler' ); ?>
					</p>
				</div>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * Render the add-on recommendation, if this site has earned one.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_recommendation_card(): void {
		$addons = ( new Recommendations() )->current();

		if ( $addons === [] ) {
			return;
		}

		foreach ( $addons as $addon ) {
			$this->render_one_recommendation( $addon );
		}
	}

	/**
	 * One add-on card.
	 *
	 * @param array<string, mixed> $addon Recommendation, already state-tagged.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_one_recommendation( array $addon ): void {
		?>
		<section class="albert-card albert-recommend">
			<div class="albert-card__body">
				<div class="albert-recommend__head">
					<?php
					// A dashicon name, which becomes half a class name, so
					// sanitize_html_class() rather than esc_attr(). Falls back
					// when a filter supplies something that is not one.
					$icon = isset( $addon['icon'] ) && is_string( $addon['icon'] )
						? sanitize_html_class( $addon['icon'], 'admin-plugins' )
						: 'admin-plugins';
					?>
					<span class="albert-recommend__mark dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
					<div>
						<p class="albert-recommend__because">
							<?php
							echo esc_html(
								( $addon['state'] ?? '' ) === AddonState::INACTIVE
									? __( 'Installed, but switched off', 'albert-ai-butler' )
									: (string) ( $addon['because'] ?? '' )
							);
							?>
						</p>
						<p class="albert-recommend__title"><?php echo esc_html( (string) $addon['name'] ); ?></p>
					</div>
				</div>
				<p class="albert-recommend__detail">
					<?php
					// The inactive wording belongs to the add-on, not to this
					// card: an entry that does not supply one keeps its ordinary
					// detail rather than being described in somebody else's
					// words. Hardcoding it here is how an installed-but-off
					// Premium came to be offered as a way to "work with your shop".
					$inactive_detail = isset( $addon['inactive_detail'] ) && is_string( $addon['inactive_detail'] )
						? $addon['inactive_detail']
						: (string) ( $addon['detail'] ?? '' );

					echo esc_html(
						( $addon['state'] ?? '' ) === AddonState::INACTIVE
							? $inactive_detail
							: (string) ( $addon['detail'] ?? '' )
					);
					?>
				</p>
				<div class="albert-recommend__actions">
					<?php if ( ( $addon['state'] ?? '' ) === AddonState::INACTIVE ) { ?>
						<a class="button" href="<?php echo esc_url( AddonState::activation_url( (string) $addon['name'] ) ); ?>">
							<?php esc_html_e( 'Switch it on', 'albert-ai-butler' ); ?>
						</a>
					<?php } else { ?>
						<a class="button" href="<?php echo esc_url( (string) ( $addon['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'See what it adds', 'albert-ai-butler' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
						</a>
					<?php } ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * The MCP endpoint card, using the same `.albert-endpoint` markup and the
	 * same copy button as the Connections screen.
	 *
	 * @param string $mcp_endpoint The endpoint URL.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_endpoint_card( string $mcp_endpoint ): void {
		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'MCP endpoint', 'albert-ai-butler' ); ?></h2>
					<p class="albert-card__description"><?php esc_html_e( 'Add this URL to your AI assistant as an MCP connector.', 'albert-ai-butler' ); ?></p>
				</div>
			</div>
			<div class="albert-card__body">
				<div class="albert-endpoint">
					<label class="screen-reader-text" for="albert-dashboard-endpoint"><?php esc_html_e( 'MCP endpoint address', 'albert-ai-butler' ); ?></label>
					<input
						type="text"
						id="albert-dashboard-endpoint"
						class="albert-endpoint__field"
						value="<?php echo esc_url( $mcp_endpoint ); ?>"
						readonly
					/>
					<button type="button" class="button button-secondary albert-copy-button" data-copy-target="albert-dashboard-endpoint">
						<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
					</button>
				</div>
				<?php $this->render_endpoint_override_notice(); ?>
				<p class="albert-endpoint__more">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>">
						<?php esc_html_e( 'Manage connections', 'albert-ai-butler' ); ?>
					</a>
				</p>
			</div>
		</section>
		<?php
	}

	/**
	 * Whether the Resources card renders.
	 *
	 * Off while the pages it links to are still being written: a card whose
	 * links lead somewhere unfinished is worse than no card at all. Nothing
	 * else about it has been removed, so bringing it back is changing this
	 * default to true. The markup, the links and the styles are all still
	 * here.
	 *
	 * A filter rather than a constant so the value is decided at runtime,
	 * which also lets a site turn it on ahead of us.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	private function show_resources(): bool {
		/**
		 * Filters whether the Dashboard's Resources card is shown.
		 *
		 * @since 1.4.0
		 *
		 * @param bool $show Whether to render the card.
		 */
		return (bool) apply_filters( 'albert/dashboard/show_resources', false );
	}

	/**
	 * The resources card, identical in both states.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_resources_card(): void {
		if ( ! $this->show_resources() ) {
			return;
		}

		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Resources', 'albert-ai-butler' ); ?></h2>
				</div>
			</div>
			<div class="albert-card__body">
				<ul class="albert-resources-list">
					<li>
						<span class="dashicons dashicons-book" aria-hidden="true"></span>
						<a href="https://wordpress.org/plugins/albert/" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Documentation', 'albert-ai-butler' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
						</a>
					</li>
					<li>
						<span class="dashicons dashicons-sos" aria-hidden="true"></span>
						<a href="https://github.com/YourMark/albert-ai-butler/issues" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Report an issue', 'albert-ai-butler' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
						</a>
					</li>
				</ul>
			</div>
		</section>
		<?php
	}

	/**
	 * The connections this site actually has, by the one definition of the word.
	 *
	 * {@see ClientRepository::getLiveConnections()} is that definition, and it
	 * is what the Connections screen lists, what both retention sweeps act on,
	 * and what {@see Attention::connections_about_to_go()} counts down. This
	 * screen used to ask its own question instead — `revoked = 0` on the access
	 * tokens alone — and got a different answer in both directions.
	 *
	 * `revoked = 0` has no expiry check in it and never looks at a refresh
	 * token, so this screen counted a client whose every token had expired: for
	 * up to a day, until `Cron\TokenCleanup` removes the rows, and indefinitely
	 * on a site where WP-Cron never runs. The screen then read "1 connection"
	 * and offered the finished-setup state over an assistant that could not
	 * call anything.
	 *
	 * Held for the request because the page asks three times: the setup state,
	 * the stat tile's figure, and the names beneath it.
	 *
	 * @return array<int, array<string, mixed>> Live connections, richest first.
	 * @since 1.4.0
	 */
	private function get_live_connections(): array {
		if ( $this->live_connections === null ) {
			$this->live_connections = ( new ClientRepository() )->getLiveConnections();
		}

		return $this->live_connections;
	}

	/**
	 * Enabled and total ability counts.
	 *
	 * Returns the two numbers rather than a preformatted "65/65" string: the
	 * stat tile needs them apart to work out how many are switched off, and the
	 * status line needs them apart to put them in a translatable sentence.
	 *
	 * @return array{enabled: int, total: int}
	 * @since 1.4.0
	 */
	private function get_ability_counts(): array {
		// Asked of the manager, not counted here. The manager snapshots both
		// figures inside enforce_disabled(), while the registry still holds
		// every ability; by the time this screen renders, the disabled ones
		// have been unregistered and counting the registry reports them as
		// enabled. That is what made the tile read "57 of 57" on a site with
		// 46 abilities switched off, and hid the "review the ones you switched
		// off" link, which only appears when the two numbers differ.
		$manager = Plugin::get_instance()->get_abilities_manager();

		// Nullable until abilities are registered on `init`. This screen renders
		// long after that, so the guard is belt and braces. But a dashboard
		// that fatals rather than showing one fewer figure is the worse trade,
		// and PHPStan does not report nullable method calls below level 8.
		if ( $manager === null ) {
			return [
				'enabled' => 0,
				'total'   => 0,
			];
		}

		return $manager->get_ability_counts();
	}

	/**
	 * The names of the currently connected clients, for the stat tile's meta line.
	 *
	 * Names are self-reported by the connecting app, and the owner's own label
	 * takes precedence where one exists, matching the Connections screen. Past
	 * two, this says "and N more" rather than growing the tile.
	 *
	 * Read off {@see self::get_live_connections()} rather than queried again,
	 * so the names and the figure above them can only ever describe the same
	 * set. They were two separate queries, and a tile reading "2" over a list
	 * of three names is worse than either number alone.
	 *
	 * @return string A short, already-plain list, or an empty string when nothing is connected.
	 * @since 1.4.0
	 */
	private function get_connection_names(): string {
		$names = [];

		foreach ( $this->get_live_connections() as $connection ) {
			$label = isset( $connection['label'] ) ? trim( (string) $connection['label'] ) : '';
			$name  = $label !== '' ? $label : trim( (string) ( $connection['name'] ?? '' ) );

			if ( $name !== '' ) {
				$names[] = $name;
			}
		}

		$names = array_values( array_unique( $names ) );
		sort( $names );

		if ( $names === [] ) {
			return '';
		}

		if ( count( $names ) <= 2 ) {
			return implode( ', ', $names );
		}

		$shown = array_slice( $names, 0, 2 );

		return sprintf(
			/* translators: 1: a comma-separated list of assistant names, 2: how many further assistants are connected. */
			_n( '%1$s and %2$d more', '%1$s and %2$d more', count( $names ) - 2, 'albert-ai-butler' ),
			implode( ', ', $shown ),
			count( $names ) - 2
		);
	}

	/**
	 * Get recent activity from OAuth sessions and ability executions.
	 *
	 * Each row is structured by column so the renderer can lay it out as a
	 * data table: a status (success / error / connection), the resolved event
	 * label, the acting user, and a relative timestamp. Ability slugs are
	 * resolved to human labels via {@see self::resolve_ability_label()}, which
	 * reads from the in-memory abilities manager (avoiding wp_get_ability(),
	 * unsafe in this render context) and falls back to a prettified slug.
	 *
	 * @return array<int, array{status: string, event: string, id: string, actor: string, time: string}> Recent activity rows.
	 * @since 1.0.0
	 */
	private function get_recent_activity(): array {
		global $wpdb;
		$tables = Tables::oauth();

		// Get the most recent distinct connections: one row per client, not per
		// token. A new access-token row is created on every silent refresh (about
		// hourly), so keying on the client and its registration time avoids
		// surfacing a "new connection" each time the session merely refreshes.
		// INNER JOIN ensures the client actually obtained a token, MAX(user_id)
		// recovers the authorizing user (clients register anonymously), and
		// UNIX_TIMESTAMP() yields a time-zone-safe epoch.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$connections = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.client_id, c.name, UNIX_TIMESTAMP( c.created_at ) AS created_ts, MAX( t.user_id ) AS user_id
				FROM %i c
				INNER JOIN %i t ON t.client_id = c.client_id
				GROUP BY c.client_id, c.name, c.created_at
				ORDER BY c.created_at DESC
				LIMIT %d',
				$tables['clients'],
				$tables['access_tokens'],
				5
			)
		);

		$events = [];

		foreach ( $connections as $row ) {
			$user        = get_userdata( $row->user_id );
			$client_name = $row->name ?? '';

			// Action-first event label; append the client name when we have one.
			$event = __( 'New connection', 'albert-ai-butler' );
			if ( $client_name !== '' ) {
				$event = sprintf(
					/* translators: %s: Client name */
					__( 'New connection: %s', 'albert-ai-butler' ),
					$client_name
				);
			}

			$events[] = [
				'status'    => 'connection',
				'timestamp' => (int) $row->created_ts,
				'event'     => $event,
				'id'        => '',
				'actor'     => $user ? $user->display_name : __( 'System', 'albert-ai-butler' ),
			];
		}

		// Merge in recent ability executions.
		foreach ( $this->logging_repository->recent( 6 ) as $row ) {
			$user = get_userdata( (int) $row->user_id );

			// Three outcomes, not two. A `warning` is passed through as itself
			// so the row renders amber: collapsing it into `error` would paint
			// the site's own permission rules red for doing their job, and
			// collapsing it into `success` would hide that the call never ran.
			$row_status = isset( $row->status ) ? (string) $row->status : '';
			$status     = in_array( $row_status, Outcome::STATUSES, true ) ? $row_status : Outcome::SUCCESS;

			$events[] = [
				'status'    => $status,
				'timestamp' => (int) $row->created_ts,
				'event'     => $this->resolve_ability_label( $row->ability_name ),
				'id'        => (string) $row->ability_name,
				'actor'     => $user ? $user->display_name : __( 'Unknown', 'albert-ai-butler' ),
			];
		}

		// Sort by timestamp DESC and keep the most recent 5.
		usort(
			$events,
			static function ( array $a, array $b ): int {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);
		// Whether anything was left over. The fade below is drawn only when this
		// is true, so it always means "there is more" rather than decorating the
		// bottom of a complete list, which is what it did before: it sat over
		// the last real row and implied rows that did not exist.
		$this->activity_truncated = count( $events ) > self::ACTIVITY_ROWS;

		// One row past the five, when there is one. The fade is 56px of opaque
		// gradient pulled up over the bottom of the table, so whichever row
		// ends up underneath it cannot be read — at the gradient's midpoint the
		// text is down to about 2:1. Giving it a sixth row to cover means the
		// five that are meant to be read stay legible, and the fade stops being
		// a thing that hides information and becomes what it claims to be: the
		// next entry, half-visible, and Premium is how you see the rest.
		$events = array_slice( $events, 0, $this->activity_truncated ? self::ACTIVITY_ROWS + 1 : self::ACTIVITY_ROWS );

		$now      = time();
		$activity = [];
		foreach ( $events as $event ) {
			$activity[] = [
				'status' => $event['status'],
				'event'  => $event['event'],
				'id'     => $event['id'],
				'actor'  => $event['actor'],
				'time'   => sprintf(
					/* translators: %s: Time difference */
					__( '%s ago', 'albert-ai-butler' ),
					human_time_diff( $event['timestamp'], $now )
				),
			];
		}

		return $activity;
	}

	/**
	 * Resolve a logged ability slug to its human-readable label.
	 *
	 * Prefers the in-memory abilities manager, whose label data is populated
	 * during bootstrap and is therefore available even on this admin page,
	 * where the WordPress Abilities API registry is not yet populated and
	 * calling wp_get_ability() would emit PHP notices. Abilities the manager
	 * does not hold (e.g. third-party abilities registered directly with
	 * WordPress) fall back to {@see AbilitiesRegistry::label_for()}, which
	 * itself prettifies the slug when the Abilities API is unavailable.
	 *
	 * @param string $slug Ability slug, e.g. `albert/find-posts`.
	 *
	 * @return string Human-readable label.
	 * @since 1.2.0
	 */
	private function resolve_ability_label( string $slug ): string {
		return AbilitiesRegistry::resolve_label( $slug );
	}

	/**
	 * Render a status cell: a glowing dot paired with a visible word.
	 *
	 * The visible word is required: status is never conveyed by colour alone
	 * (WCAG 2.2 AA, 1.4.1).
	 *
	 * @param string $status One of `success`, `warning`, `error`, or `connection`.
	 *
	 * @return void
	 * @since 1.2.0
	 * @since 1.4.0 Renders `warning` as an amber "Blocked".
	 */
	private function render_status_dot( string $status ): void {
		switch ( $status ) {
			case 'error':
				$modifier = 'error';
				$word     = __( 'Failed', 'albert-ai-butler' );
				break;
			case Outcome::WARNING:
				// Amber, and the word says what happened rather than how bad it
				// is. The site refused on purpose; that belongs between the
				// quiet success above it and the loud failure below, not
				// dressed up as either.
				$modifier = 'warning';
				$word     = __( 'Blocked', 'albert-ai-butler' );
				break;
			case 'connection':
				$modifier = 'connection';
				$word     = __( 'Connection', 'albert-ai-butler' );
				break;
			default:
				$modifier = 'success';
				$word     = __( 'Success', 'albert-ai-butler' );
				break;
		}
		?>
		<span class="albert-status-dot albert-status-dot--<?php echo esc_attr( $modifier ); ?>">
			<span class="albert-status-dot__dot" aria-hidden="true"></span>
			<span class="albert-status-dot__label"><?php echo esc_html( $word ); ?></span>
		</span>
		<?php
	}
}
