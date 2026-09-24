<?php
/**
 * Settings Bootstrap
 *
 * Provides the built-in sections that Free always registers with the
 * SettingsRegistry on page load.
 *
 * @package    Albert
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\ConnectionRetention;
use Albert\Privacy\PrivacyMode;
use Albert\SafeMode\Gate;
use Albert\Support\WpCompat;

/**
 * SettingsBootstrap class.
 *
 * Returns the built-in section schemas and provides the static render
 * callbacks the schemas point at. The class is intentionally stateless —
 * the page calls {@see self::get_builtin_sections()} once per request.
 *
 * @since 1.1.0
 */
class SettingsBootstrap {

	/**
	 * Get the built-in sections registered by Free.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_builtin_sections(): array {
		return [
			[
				'id'          => 'albert/safe-mode',
				'title'       => __( 'Safe mode', 'albert-ai-butler' ),
				'priority'    => 55,
				'icon'        => 'shield',
				'description' => __( 'Whether destructive actions an assistant requests wait for your approval.', 'albert-ai-butler' ),
				'fields'      => [
					[
						'id'                => 'enabled',
						'type'              => 'radio-cards',
						// Not "Safe mode" again: the section heading above already
						// says that, and every other section names its controls
						// for what they do rather than repeating itself.
						'label'             => __( 'Hold destructive actions', 'albert-ai-butler' ),
						'option_name'       => Gate::OPTION,
						'default'           => Gate::DEFAULT_VALUE,
						'options'           => [
							'on'  => [
								'label'       => __( 'On', 'albert-ai-butler' ),
								'description' => __( 'Deletions and other destructive changes are held on the Approvals screen until you approve them.', 'albert-ai-butler' ),
								'recommended' => true,
							],
							'off' => [
								'label'       => __( 'Off', 'albert-ai-butler' ),
								'description' => __( 'Assistants can make destructive changes immediately, without approval.', 'albert-ai-butler' ),
							],
						],
						'sanitize_callback' => [ Gate::class, 'sanitize' ],
						'disabled'          => [ self::class, 'safe_mode_unavailable' ],
						'hint'              => [ self::class, 'safe_mode_hint' ],
					],
				],
			],
			[
				'id'          => 'albert/privacy',
				'title'       => __( 'Privacy', 'albert-ai-butler' ),
				'priority'    => 60,
				'icon'        => 'privacy',
				'description' => __( 'What AI assistants may see of your customers and users.', 'albert-ai-butler' ),
				'fields'      => [
					[
						'id'                => 'mode',
						'type'              => 'radio-cards',
						'label'             => __( 'Privacy mode', 'albert-ai-butler' ),
						// Balanced, and deliberately not Strict, even though a
						// new install is set to Strict on activation
						// ({@see \Albert\Core\Plugin::activate()}). This is the
						// value a site falls back to when nothing is stored, and
						// the sites in that position are the ones that installed
						// Albert before the option existed. Changing what *they*
						// do without anybody choosing it is the thing
						// docs/features/70-admin-design-system.md §4 forbids.
						// A new install never reaches this: it has a stored value
						// from its first request.
						'default'           => PrivacyMode::Balanced->value,
						'options'           => [
							PrivacyMode::Strict->value   => [
								'label'       => __( 'Strict', 'albert-ai-butler' ),
								'description' => __( 'Personal data is always anonymised, in every request.', 'albert-ai-butler' ),
								'recommended' => true,
							],
							PrivacyMode::Balanced->value => [
								'label'       => __( 'Balanced', 'albert-ai-butler' ),
								'description' => __( 'Anonymised by default, but an authorised request can reveal it.', 'albert-ai-butler' ),
							],
							PrivacyMode::Off->value      => [
								'label'       => __( 'Off', 'albert-ai-butler' ),
								'description' => __( 'Personal data is passed through as-is. Payment data is still always removed.', 'albert-ai-butler' ),
							],
						],
						'sanitize_callback' => [ PrivacyMode::class, 'sanitize' ],
					],
				],
			],
			[
				'id'          => 'albert/connections',
				'title'       => __( 'Connections', 'albert-ai-butler' ),
				'priority'    => 65,
				'icon'        => 'admin-users',
				'description' => __( 'Standing invitations and unused connections.', 'albert-ai-butler' ),
				'fields'      => [
					[
						'id'          => 'invitation_expiry_days',
						'type'        => 'number',
						'label'       => __( 'Invitation expiry', 'albert-ai-butler' ),
						'suffix'      => __( 'days', 'albert-ai-butler' ),
						'description' => __( 'How long someone has to approve their first assistant.', 'albert-ai-butler' ),
						'info'        => __( 'Once they approve one, their access no longer expires. Set to 0 for no deadline.', 'albert-ai-butler' ),
						'option_name' => AllowedUsers::EXPIRY_OPTION,
						'default'     => AllowedUsers::DEFAULT_EXPIRY_DAYS,
						'attributes'  => [
							'min'  => 0,
							'max'  => 3650,
							'step' => 1,
						],
					],
					[
						'id'          => 'connection_never_used_days',
						'type'        => 'number',
						'label'       => __( 'Drop never-used connections', 'albert-ai-butler' ),
						'suffix'      => __( 'days', 'albert-ai-butler' ),
						'description' => __( 'Removes connections that were approved but never used.', 'albert-ai-butler' ),
						'info'        => __( 'Once a connection has been used, this rule stops applying to it. Set to 0 to keep them.', 'albert-ai-butler' ),
						'option_name' => ConnectionRetention::NEVER_USED_OPTION,
						'default'     => ConnectionRetention::DEFAULT_NEVER_USED_DAYS,
						'attributes'  => [
							'min'  => 0,
							'max'  => 3650,
							'step' => 1,
						],
					],
					[
						'id'          => 'connection_idle_days',
						'type'        => 'number',
						'label'       => __( 'Expire idle connections', 'albert-ai-butler' ),
						'suffix'      => __( 'days', 'albert-ai-butler' ),
						'description' => __( 'Removes connections that have gone quiet.', 'albert-ai-butler' ),
						'info'        => __( 'Off by default, because a quiet assistant is not always an abandoned one. Set to 0 to keep them.', 'albert-ai-butler' ),
						'option_name' => ConnectionRetention::IDLE_OPTION,
						'default'     => ConnectionRetention::DEFAULT_IDLE_DAYS,
						'attributes'  => [
							'min'  => 0,
							'max'  => 3650,
							'step' => 1,
						],
					],
				],
			],
			[
				'id'          => 'albert/media',
				'title'       => __( 'Uploads', 'albert-ai-butler' ),
				'priority'    => 68,
				'icon'        => 'upload',
				'description' => __( 'How assistants send files to your site.', 'albert-ai-butler' ),
				'fields'      => [
					[
						// An ordinary number field. It used to be 'custom' purely
						// because the renderer had no way to express "disabled,
						// because a filter is overriding this". It now works that
						// out itself from Settings\Value, so this field declares
						// no `disabled` and no `display_value` at all. The `hint`
						// stays: the generated one names the filter, but only this
						// field can give the exact size in force, which matters
						// because the control rounds up to whole megabytes.
						'id'                => 'default_max_mb',
						'type'              => 'number',
						'label'             => __( 'Default upload size limit', 'albert-ai-butler' ),
						'suffix'            => __( 'MB', 'albert-ai-butler' ),
						'description'       => __( 'Used when an assistant does not ask for a specific limit.', 'albert-ai-butler' ),
						'info'              => sprintf(
							/* translators: %s: this server's own upload size ceiling, human-readable (e.g. "64 MB") */
							__( 'An assistant can ask for a smaller or larger limit per upload. This server never accepts more than %s, whatever is set here.', 'albert-ai-butler' ),
							self::server_upload_ceiling()
						),
						'option_name'       => UploadLinkService::MAX_BYTES_OPTION,
						'default'           => UploadLinkService::DEFAULT_MAX_MB,
						'attributes'        => [
							'min'  => 1,
							'max'  => UploadLinkService::MAX_SETTABLE_MB,
							'step' => 1,
						],
						'hint'              => [ self::class, 'max_mb_hint' ],
						'sanitize_callback' => [ self::class, 'sanitize_max_mb' ],
					],
				],
			],
			[
				// Always last — add-ons sit between the shared Settings card (50) and Licenses (9000).
				'id'       => 'albert/licenses',
				'title'    => __( 'Licenses', 'albert-ai-butler' ),
				'priority' => 9000,
				'icon'     => 'admin-network',
				'fields'   => [
					[
						'id'                => 'licenses_table',
						'type'              => 'custom',
						'label'             => '',
						'render_callback'   => [ self::class, 'render_licenses_block' ],
						'sanitize_callback' => '__return_null',
					],
				],
			],
		];
	}

	/**
	 * This server's own upload ceiling, human-readable, for the Uploads field description.
	 *
	 * @since 1.4.0
	 *
	 * @return string e.g. "64 MB".
	 */
	private static function server_upload_ceiling(): string {
		// size_format() can return false; wp_max_upload_size() can't actually trigger that.
		$formatted = size_format( wp_max_upload_size() );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Whether this WordPress cannot enforce safe mode at all.
	 *
	 * The gate is `wp_pre_execute_ability`, which arrived in WordPress 7.1.
	 * Albert supports 6.9, where the filter never fires and nothing is held. The
	 * control is locked there rather than left inviting, because a switch that
	 * reads On while every deletion runs unattended is the exact false
	 * reassurance safe mode exists to prevent.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	public static function safe_mode_unavailable(): bool {
		return ! WpCompat::supports_execution_lifecycle();
	}

	/**
	 * Why the safe mode control is locked, when it is.
	 *
	 * @since 1.5.0
	 *
	 * @return array{text: string, tone: string}|null
	 */
	public static function safe_mode_hint(): ?array {
		if ( ! self::safe_mode_unavailable() ) {
			return null;
		}

		return [
			'text' => sprintf(
				/* translators: %s: the WordPress version this site is running. */
				__( 'Safe mode needs WordPress 7.1, which added the check it relies on. This site runs %s, so destructive actions are not being held. Update WordPress to switch it on.', 'albert-ai-butler' ),
				get_bloginfo( 'version' )
			),
			'tone' => 'warning',
		];
	}

	/**
	 * What to say under the control when a filter is involved, if anything.
	 *
	 * Returns null in the ordinary case — most of the time there is no filter
	 * and the field needs no hint at all.
	 *
	 * @since 1.4.0
	 *
	 * @return array{text: string, tone: string}|null
	 */
	public static function max_mb_hint(): ?array {
		$state = UploadLinkService::get_default_max_bytes_filter_state();

		if ( $state['state'] !== 'active' ) {
			return null;
		}

		if ( $state['requested'] > $state['value'] ) {
			return [
				'text' => sprintf(
					/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name, 3: the value the filter requested (MB), 4: the maximum allowed (MB) */
					__( 'The %1$salbert/media/upload_link_max_bytes%2$s filter is requesting %3$d MB, above the %4$d MB maximum, so %4$d MB is being used instead.', 'albert-ai-butler' ),
					'<code>',
					'</code>',
					(int) round( $state['requested'] / UploadLinkService::BYTES_PER_MB ),
					UploadLinkService::MAX_SETTABLE_MB
				),
				'tone' => 'warning',
			];
		}

		return [
			'text' => sprintf(
				/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name, 3: the effective size, human-readable (e.g. "500 B") */
				__( 'The %1$salbert/media/upload_link_max_bytes%2$s filter is overriding what\'s saved here. The limit in effect is %3$s.', 'albert-ai-butler' ),
				'<code>',
				'</code>',
				size_format( $state['value'] )
			),
			'tone' => 'info',
		];
	}

	/**
	 * Sanitize the Settings-screen field for {@see UploadLinkService::MAX_BYTES_OPTION}.
	 *
	 * While the filter is active the field renders disabled, so it's never
	 * submitted; return the stored value as a no-op rather than resetting it.
	 *
	 * @param mixed $value Raw value from the settings form.
	 *
	 * @return int MB, clamped to [1, UploadLinkService::MAX_SETTABLE_MB].
	 * @since 1.4.0
	 */
	public static function sanitize_max_mb( $value ): int {
		$stored = (int) get_option( UploadLinkService::MAX_BYTES_OPTION, UploadLinkService::DEFAULT_MAX_MB );

		// null means the field was not submitted, which is what a disabled
		// field does. Keyed on the value rather than only on the filter's
		// state at save time: the filter can go inactive between the render
		// that disabled the field and the save, and treating "absent" as
		// "invalid" would then overwrite a stored value nobody touched.
		if ( $value === null || UploadLinkService::get_default_max_bytes_filter_state()['state'] === 'active' ) {
			return $stored;
		}

		// (int) cast, not absint(): a negative input must fall through to the default below.
		$mb = is_scalar( $value ) ? (int) $value : 0;

		if ( $mb < 1 ) {
			// Say so, exactly as an over-range value does. Silently rewriting
			// a 0 to 10 leaves somebody staring at a number they did not type.
			add_settings_error(
				'albert_settings',
				'upload_link_max_mb_too_low',
				sprintf(
					/* translators: %d: the default that was saved instead (MB) */
					__( 'The default upload size limit must be at least 1 MB, so %d MB was saved instead.', 'albert-ai-butler' ),
					UploadLinkService::DEFAULT_MAX_MB
				),
				'warning'
			);

			return UploadLinkService::DEFAULT_MAX_MB;
		}

		if ( $mb > UploadLinkService::MAX_SETTABLE_MB ) {
			// Reuses the page's own "Settings saved" notice mechanism.
			add_settings_error(
				'albert_settings',
				'upload_link_max_mb_clamped',
				sprintf(
					/* translators: 1: the value that was requested (MB), 2: the maximum allowed (MB) */
					__( 'The default upload size limit can\'t be set above %2$d MB. %1$d MB was requested, so %2$d MB was saved instead.', 'albert-ai-butler' ),
					$mb,
					UploadLinkService::MAX_SETTABLE_MB
				),
				'warning'
			);
		}

		return min( $mb, UploadLinkService::MAX_SETTABLE_MB );
	}

	/**
	 * Render the licenses block (table or empty state).
	 *
	 * Delegates to {@see Settings} for the table/empty-state markup so the
	 * AJAX refresh handler keeps a single source of truth.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Field definition (unused).
	 * @param mixed                $current_value Current value (unused).
	 *
	 * @return void
	 */
	public static function render_licenses_block( array $field, $current_value ): void {
		unset( $field, $current_value );

		$has_addons = class_exists( '\Albert\Abstracts\AbstractAddon' )
			&& ! empty( \Albert\Abstracts\AbstractAddon::get_registered_addons() );

		if ( $has_addons ) {
			?>
			<div id="albert-license-notice" class="albert-license-notice" hidden></div>
			<div class="albert-license-form">
				<input
					type="text"
					id="albert-license-key"
					class="albert-text-input"
					placeholder="<?php esc_attr_e( 'Enter your license key', 'albert-ai-butler' ); ?>"
					autocomplete="off"
				/>
				<button type="button" id="albert-activate-btn" class="button button-primary">
					<?php esc_html_e( 'Activate', 'albert-ai-butler' ); ?>
				</button>
			</div>
			<p class="albert-field-description albert-license-hint">
				<?php esc_html_e( 'Enter your license key. It will be automatically matched to the correct addon.', 'albert-ai-butler' ); ?>
			</p>
			<div id="albert-addons-table-wrap">
				<?php Settings::render_licenses_table(); ?>
			</div>
			<?php
		} else {
			Settings::render_licenses_empty_state();
		}
	}
}
