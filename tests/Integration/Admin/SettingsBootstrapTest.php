<?php
/**
 * Integration tests for SettingsBootstrap's built-in sections.
 *
 * Covers only the Uploads section added for the media-upload-link byte
 * cap — the other built-in sections (Privacy, Connections, Licenses)
 * predate this file and have no dedicated coverage of their own yet.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\SettingsBootstrap;
use Albert\Admin\SettingsRenderer;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\Tests\TestCase;

/**
 * SettingsBootstrap integration tests.
 *
 * @covers \Albert\Admin\SettingsBootstrap
 */
class SettingsBootstrapTest extends TestCase {

	/**
	 * Find the Uploads section from the built-in sections list.
	 *
	 * @return array<string, mixed>
	 */
	private function uploads_section(): array {
		foreach ( SettingsBootstrap::get_builtin_sections() as $section ) {
			if ( $section['id'] === 'albert/media' ) {
				return $section;
			}
		}

		$this->fail( 'albert/media section not found in built-in sections.' );
	}

	/**
	 * The Uploads section is registered with the expected single field,
	 * wired to UploadLinkService's option and sanitizer.
	 *
	 * @return void
	 */
	public function test_uploads_section_field_is_wired_correctly(): void {
		$section = $this->uploads_section();

		$this->assertCount( 1, $section['fields'] );

		$field = $section['fields'][0];

		// A plain number field, and one that declares nothing about being
		// overridable: the renderer works that out from Settings\Value. The
		// hint is the exception, and earns its place by giving the exact size
		// in force, which a control rounded to whole megabytes cannot show.
		$this->assertSame( 'number', $field['type'] );
		$this->assertSame( UploadLinkService::MAX_BYTES_OPTION, $field['option_name'] );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, $field['default'] );
		$this->assertArrayNotHasKey( 'render_callback', $field );
		$this->assertArrayNotHasKey( 'disabled', $field );
		$this->assertArrayNotHasKey( 'display_value', $field );
		$this->assertSame( [ SettingsBootstrap::class, 'max_mb_hint' ], $field['hint'] );
		$this->assertSame( [ SettingsBootstrap::class, 'sanitize_max_mb' ], $field['sanitize_callback'] );
	}

	/**
	 * The server's actual upload ceiling is surfaced, so an owner isn't left
	 * guessing why a large value has no effect.
	 *
	 * It lives in the field's info tip rather than its description: the
	 * description is held to one line, and the ceiling is the kind of detail a
	 * reader wants once rather than every time. Asserted on the rendered output
	 * so this covers the tip actually reaching the screen, not just the schema
	 * carrying the string.
	 *
	 * @return void
	 */
	public function test_uploads_field_surfaces_the_server_ceiling(): void {
		$ceiling = size_format( wp_max_upload_size() );

		$this->assertStringContainsString( $ceiling, $this->uploads_section()['fields'][0]['info'] );
		$this->assertStringContainsString( $ceiling, $this->render_uploads_field( 10 ) );
	}

	/**
	 * The info tip renders as the shared control, with the trigger and its
	 * popover adjacent — `admin-popover.js` finds the popover with
	 * `nextElementSibling`, so anything between them breaks it.
	 *
	 * The popover is a `<span>`, not a `<div>`: the whole control is phrasing
	 * content so it stays legal inside a `<legend>`, a `<p>` or a `<label>`,
	 * which is where a tip beside a field's name has to sit. Attribute order
	 * is not asserted: WordPress trunk's kses writes attributes alphabetically.
	 *
	 * @return void
	 */
	public function test_uploads_field_renders_the_shared_info_control(): void {
		$html = $this->render_uploads_field( 10 );

		$this->assertStringContainsString( 'albert-tip__trigger', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertMatchesRegularExpression(
			'/<button[^>]*aria-controls="([^"]+)"[^>]*>.*?<\/button><span(?=[^>]*class="albert-tip__popover")(?=[^>]*id="\1")[^>]*>/s',
			$html
		);
	}

	/**
	 * Whether a settings error with the given code is currently registered.
	 *
	 * @param string $code The settings-error code to look for.
	 *
	 * @return bool
	 */
	private function has_settings_error( string $code ): bool {
		foreach ( get_settings_errors( 'albert_settings' ) as $error ) {
			if ( $error['code'] === $code ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reset the settings-errors global before each test.
	 *
	 * $wp_settings_errors is a plain global, not reset between tests by WP's
	 * own hook-backup mechanism — reset it explicitly so a warning added in
	 * one test can't leak into another's assertions.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_settings_errors;
		$wp_settings_errors = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation, not production code overriding a WP internal.
	}

	// ─── The Uploads field, rendered ──────────────────────────────────

	/**
	 * Render the real Uploads field through the shared renderer.
	 *
	 * Goes through `SettingsRenderer::render_field()` with the field exactly as
	 * `get_builtin_sections()` declares it, rather than calling a bespoke
	 * callback: that is the path the Settings screen actually takes, so these
	 * assertions cover the `disabled` / `display_value` / `hint` wiring as well
	 * as the values themselves.
	 *
	 * @param int $stored The stored option value, in MB.
	 *
	 * @return string
	 */
	private function render_uploads_field( int $stored ): string {
		$field = $this->uploads_section()['fields'][0];

		ob_start();
		( new SettingsRenderer() )->render_field( $field, $stored );

		return (string) ob_get_clean();
	}

	/**
	 * With no filter hooked, the field is editable, shows the stored value,
	 * and says nothing extra.
	 *
	 * @return void
	 */
	public function test_uploads_field_is_editable_without_a_filter(): void {
		$html = $this->render_uploads_field( 9 );

		$this->assertStringContainsString( 'value="9"', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'albert-hint', $html );
	}

	/**
	 * With the filter active the field shows the filter's value rather than
	 * the stored one, disabled, with a notice naming the filter.
	 *
	 * @return void
	 */
	public function test_uploads_field_shows_filtered_value_when_overridden(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 12 * UploadLinkService::BYTES_PER_MB );

		$html = $this->render_uploads_field( 9 );

		$this->assertStringContainsString( 'value="12"', $html );
		$this->assertStringNotContainsString( 'value="9"', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'albert/media/upload_link_max_bytes', $html );
		$this->assertStringContainsString( 'albert-hint--info', $html );
		$this->assertStringNotContainsString( 'albert-hint--warning', $html );
	}

	/**
	 * When the filter asks for more than the ceiling allows, the hint turns
	 * warning and names both the requested and the applied value — the plain
	 * "overridden" notice would not explain why the number looks capped.
	 *
	 * @return void
	 */
	public function test_uploads_field_warns_when_filter_value_is_clamped(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10G' ); // 10240 MB, clamped to 2048.

		$html = $this->render_uploads_field( 9 );

		$this->assertStringContainsString( 'value="' . UploadLinkService::MAX_SETTABLE_MB . '"', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'albert-hint--warning', $html );
		$this->assertStringNotContainsString( 'albert-hint--info', $html );
		$this->assertStringContainsString( '10240', $html ); // The requested value, for context.
		$this->assertStringContainsString( (string) UploadLinkService::MAX_SETTABLE_MB, $html );
	}

	/**
	 * A sub-megabyte filter value never renders as 0 against the field's own min="1".
	 *
	 * @return void
	 */
	public function test_a_sub_megabyte_filter_value_never_renders_as_zero(): void {
		add_filter(
			'albert/media/upload_link_max_bytes',
			static function () {
				return 500;
			}
		);

		$html = $this->render_uploads_field( 10 );

		$this->assertStringNotContainsString( 'value="0"', $html );
		$this->assertStringContainsString( 'value="1"', $html );
		// The rounding is not allowed to mislead: the hint carries the real size.
		$this->assertStringContainsString( size_format( 500 ), $html );
	}

	/**
	 * The unit renders beside the control and is associated with it, so a
	 * screen reader hears "10 MB" rather than a bare number.
	 *
	 * @return void
	 */
	public function test_uploads_field_associates_its_unit_with_the_control(): void {
		$html = $this->render_uploads_field( 10 );

		$this->assertStringContainsString( 'albert-field-suffix', $html );
		$this->assertMatchesRegularExpression( '/aria-describedby="[^"]*-suffix"/', $html );
	}

	// ─── sanitize_max_mb() ────────────────────────────────────────────

	/**
	 * A valid value passes through unchanged.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_passes_through_a_valid_value(): void {
		$this->assertSame( 42, SettingsBootstrap::sanitize_max_mb( '42' ) );
	}

	/**
	 * Zero, negative, and non-numeric input fall back to the default rather
	 * than persisting a byte cap of zero.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_rejects_invalid_input(): void {
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, SettingsBootstrap::sanitize_max_mb( '0' ) );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, SettingsBootstrap::sanitize_max_mb( '-5' ) );
		$this->assertSame( UploadLinkService::DEFAULT_MAX_MB, SettingsBootstrap::sanitize_max_mb( 'not-a-number' ) );
	}

	/**
	 * Falling back to the default is reported, exactly as clamping down from
	 * above the ceiling is. Silently rewriting a 0 into 10 leaves somebody
	 * looking at a number they did not type and no reason for it.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_warns_when_input_is_below_the_minimum(): void {
		SettingsBootstrap::sanitize_max_mb( '0' );

		$this->assertTrue( $this->has_settings_error( 'upload_link_max_mb_too_low' ) );
	}

	/**
	 * A value above the settable ceiling is clamped, not rejected outright.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_clamps_to_the_settable_ceiling(): void {
		$this->assertSame(
			UploadLinkService::MAX_SETTABLE_MB,
			SettingsBootstrap::sanitize_max_mb( UploadLinkService::MAX_SETTABLE_MB + 1000 )
		);
	}

	/**
	 * Saving a value above the ceiling registers a settings-error warning —
	 * shown via the page's existing settings_errors('albert_settings') call,
	 * the same mechanism the "Settings saved" message already uses — so the
	 * clamp isn't silent.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_warns_when_clamping(): void {
		SettingsBootstrap::sanitize_max_mb( UploadLinkService::MAX_SETTABLE_MB + 1000 );

		$this->assertTrue( $this->has_settings_error( 'upload_link_max_mb_clamped' ) );
	}

	/**
	 * A value within bounds never registers the clamp warning.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_does_not_warn_within_bounds(): void {
		SettingsBootstrap::sanitize_max_mb( 42 );

		$this->assertFalse( $this->has_settings_error( 'upload_link_max_mb_clamped' ) );
	}

	/**
	 * While the filter is active, sanitize_max_mb() is a no-op: it returns
	 * whatever's already stored rather than reading $value at all. The
	 * field renders disabled in this state, so a save submits no value for
	 * it ($_POST simply lacks the key) — without this, that missing value
	 * would sanitize down to "invalid" and silently reset the stored
	 * option to the default on every unrelated settings save.
	 *
	 * @return void
	 */
	public function test_sanitize_max_mb_is_a_noop_while_the_filter_overrides(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 33 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 12 * UploadLinkService::BYTES_PER_MB );

		// A real browser submission never happens while disabled, but even
		// if something did POST a value (or the field is simply absent),
		// the stored option must survive unchanged either way.
		$this->assertSame( 33, SettingsBootstrap::sanitize_max_mb( null ) );
		$this->assertSame( 33, SettingsBootstrap::sanitize_max_mb( '999' ) );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * A settings save that does not carry this field leaves the stored value
	 * alone, even once the filter that disabled the field has gone away.
	 *
	 * @return void
	 */
	public function test_an_unsubmitted_field_does_not_reset_the_stored_value(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 50 );

		$this->assertSame( 50, SettingsBootstrap::sanitize_max_mb( null ) );
	}
}
