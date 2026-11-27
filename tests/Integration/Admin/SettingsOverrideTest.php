<?php
/**
 * Integration tests for settings values owned by code.
 *
 * A setting can be decided by a `wp-config.php` constant or by a filter instead
 * of by the stored option. Before 1.4.0 each setting that allowed this
 * hand-rolled its own precedence, and only the upload size limit told the
 * Settings screen about it — so a site filtering the privacy mode saw the
 * stored value on screen, fully editable, and could save over it without
 * changing what the site actually did.
 *
 * These tests are integration rather than unit because the behaviour now runs
 * through real `add_filter()` plumbing: the unit suite's `apply_filters` stub
 * returns configured values without invoking callbacks, so it cannot see the
 * bridge that carries the domain-specific filters into the shared chain.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Settings\Lock;
use Albert\Settings\Overrides;
use Albert\Settings\Schema;
use Albert\Settings\Value;
use Albert\Admin\SettingsRegistry;
use Albert\Admin\SettingsRenderer;
use Albert\Admin\SettingsSanitizer;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\ConnectionRetention;
use Albert\Privacy\PrivacyMode;
use Albert\Tests\TestCase;

/**
 * Override resolution and the read-only UI it produces.
 *
 * @covers \Albert\Settings\Value
 * @covers \Albert\Settings\Overrides
 */
class SettingsOverrideTest extends TestCase {

	/**
	 * Register the bridge, as Plugin::init() does on a real request.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		SettingsRegistry::reset();
		Schema::reset_cache();
		( new Overrides() )->register_hooks();
	}

	/**
	 * Drop anything a test hooked so it cannot leak into the next.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'albert/privacy/mode' );
		remove_all_filters( 'albert/media/upload_link_max_bytes' );
		remove_all_filters( Value::filter_name( 'albert_privacy_mode' ) );
		remove_all_filters( Value::filter_name( UploadLinkService::MAX_BYTES_OPTION ) );

		SettingsRegistry::reset();
		Schema::reset_cache();

		parent::tear_down();
	}

	/**
	 * The 1.3.0 filter is not deprecated and still decides the mode.
	 *
	 * @return void
	 */
	public function test_the_domain_filter_still_overrides_the_stored_mode(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$this->assertSame( PrivacyMode::Off, PrivacyMode::resolve() );
	}

	/**
	 * ...and the screen now says so, instead of offering an editable control
	 * whose value the filter would discard.
	 *
	 * @return void
	 */
	public function test_a_filtered_mode_renders_read_only_and_names_the_filter(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$html = $this->render_field( 'albert_privacy_mode' );

		$this->assertStringContainsString( 'albert-radio-cards--disabled', $html );
		$this->assertSame(
			3,
			substr_count( $html, 'disabled=' ),
			'Every choice should be disabled, not only the one in force.'
		);
		// Names the hook the site actually wrote. Reporting the generic hook this
		// is bridged onto would send somebody grepping for a string that appears
		// nowhere in their code.
		$this->assertStringContainsString( 'albert/privacy/mode', $html );
		$this->assertStringNotContainsString( 'albert/settings/value/albert_privacy_mode', $html );

		// The value shown is the one in force, not the one stored.
		$this->assertMatchesRegularExpression( '/value="off"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="strict"[^>]*checked/', $html );
	}

	/**
	 * With nothing overriding it the control is ordinary: editable, no note.
	 *
	 * @return void
	 */
	public function test_an_unoverridden_field_stays_editable(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		$html = $this->render_field( 'albert_privacy_mode' );

		$this->assertStringNotContainsString( 'albert-radio-cards--disabled', $html );
		$this->assertStringNotContainsString( 'disabled=', $html );
		$this->assertStringNotContainsString( 'albert-field-hint', $html );
		$this->assertMatchesRegularExpression( '/value="strict"[^>]*checked/', $html );
	}

	/**
	 * A constant beats a filter, and is named on screen.
	 *
	 * Uses a setting invented for this test: a constant cannot be undefined, so
	 * defining one for a real option would follow the suite into every later
	 * test in the process.
	 *
	 * @return void
	 */
	public function test_a_constant_wins_and_is_reported(): void {
		define( 'ALBERT_TEST_OVERRIDE_OPTION', 'from-constant' );

		add_filter( Value::filter_name( 'albert_test_override_option' ), static fn () => 'from-filter' );
		update_option( 'albert_test_override_option', 'from-option' );

		$override = Value::override( 'albert_test_override_option' );

		$this->assertNotNull( $override );
		$this->assertSame( 'constant', $override['source'] );
		$this->assertSame( 'ALBERT_TEST_OVERRIDE_OPTION', $override['name'] );
		$this->assertSame( 'from-constant', Value::get( 'albert_test_override_option' ) );
		$this->assertSame( 'from-constant', albert_get_setting( 'albert_test_override_option' ) );

		delete_option( 'albert_test_override_option' );
	}

	/**
	 * The upload limit keeps its own hint rather than taking the generated one,
	 * because only it can state the exact size in force — the control rounds up
	 * to whole megabytes, and 500 KB would otherwise display as "1 MB" with
	 * nothing to correct the impression.
	 *
	 * @return void
	 */
	public function test_the_upload_field_keeps_its_more_precise_hint(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 500 * 1024 );

		$html = $this->render_field( UploadLinkService::MAX_BYTES_OPTION );

		// `readonly` rather than `disabled` for a text-shaped control, so it
		// stays focusable; see test_a_locked_text_control_stays_reachable...().
		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( size_format( 500 * 1024 ), $html );
		$this->assertStringContainsString( 'albert/media/upload_link_max_bytes', $html );

		// Rounded up for display, never to zero: the control's own minimum is 1.
		$this->assertStringContainsString( 'value="1"', $html );
	}

	/**
	 * The byte filter still sets the limit that is actually enforced, exactly,
	 * rather than the megabyte-rounded figure the screen displays.
	 *
	 * @return void
	 */
	public function test_the_enforced_upload_limit_is_not_rounded(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 500 * 1024 );

		$service    = new UploadLinkService();
		$reflection = new \ReflectionMethod( $service, 'default_max_bytes' );

		$this->assertSame( 500 * 1024, $reflection->invoke( $service ) );
	}

	/**
	 * Render one registered field and return its markup.
	 *
	 * @param string $option_name The option the field writes.
	 *
	 * @return string
	 */
	private function render_field( string $option_name ): string {
		$field = null;

		foreach ( Schema::collect() as $section ) {
			foreach ( $section['fields'] ?? [] as $candidate ) {
				if ( is_array( $candidate ) && ( $candidate['option_name'] ?? '' ) === $option_name ) {
					$field = $candidate;
				}
			}
		}

		$this->assertNotNull( $field, sprintf( 'No registered field writes "%s".', $option_name ) );

		$default = array_key_exists( 'default', $field ) ? $field['default'] : '';

		ob_start();
		( new SettingsRenderer() )->render_field( $field, get_option( $option_name, $default ) );

		return (string) ob_get_clean();
	}

	/**
	 * Saving the form while an override is active must not touch what is
	 * stored.
	 *
	 * The control renders read-only, so the browser submits nothing for it, and
	 * an absent value sanitises to a bounded number's minimum — or, here, to the
	 * privacy default. Writing that would destroy the owner's own choice the
	 * first time they saved any other setting on the page, and they would have
	 * no way to notice, because the screen shows the override rather than the
	 * stored value.
	 *
	 * Asserted against the save loop's own guard rather than by running
	 * handle_save_settings(), which redirects and exits.
	 *
	 * @return void
	 */
	public function test_the_save_loop_skips_an_overridden_field(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$field = $this->field_for( 'albert_privacy_mode' );
		$this->assertNotNull( $field );

		// The value the loop would otherwise write is not the stored one, so
		// the guard is load-bearing rather than belt-and-braces.
		$this->assertNotSame(
			'strict',
			( new SettingsSanitizer() )->sanitize_field( $field, null ),
			'An absent value must sanitise to something destructive for this test to mean anything.'
		);

		$this->assertTrue( Lock::is_locked( $field, Value::override( 'albert_privacy_mode' ) ) );
	}

	/**
	 * An ordinary field is not skipped, or nothing could ever be saved.
	 *
	 * @return void
	 */
	public function test_the_save_loop_does_not_skip_an_ordinary_field(): void {
		$field = $this->field_for( 'albert_privacy_mode' );

		$this->assertFalse( Lock::is_locked( $field, Value::override( 'albert_privacy_mode' ) ) );
	}

	/**
	 * An override the option's own validator rejects locks nothing.
	 *
	 * The screen and the code reading a setting have to agree about whether an
	 * override is in force. They did not: `PrivacyMode::resolve()` applied a
	 * vocabulary check and the screen did not, so a typo'd constant rendered the
	 * field read-only, showing the typo, naming the constant, and skipped by
	 * the save loop, while the site went on running the stored mode. The owner
	 * was locked out of a setting the constant did not control.
	 *
	 * @return void
	 */
	public function test_an_invalid_override_neither_locks_the_field_nor_changes_the_mode(): void {
		update_option( 'albert_privacy_mode', 'off' );

		add_filter( 'albert/privacy/mode', static fn () => 'bananas' );

		$field = $this->field_for( 'albert_privacy_mode' );
		$this->assertNotNull( $field );

		$this->assertNull(
			Value::override( 'albert_privacy_mode' ),
			'A value outside the vocabulary is not an override.'
		);
		$this->assertFalse( Lock::is_locked( $field, Value::override( 'albert_privacy_mode' ) ) );
		$this->assertSame( PrivacyMode::Off, PrivacyMode::resolve() );
	}

	/**
	 * The same, one layer up: a constant is skipped too, so resolution falls
	 * through to the stored value rather than pinning the site to nonsense.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_an_invalid_constant_falls_through_to_the_stored_mode(): void {
		define( 'ALBERT_PRIVACY_MODE', 'bananas' );
		update_option( 'albert_privacy_mode', 'off' );

		$this->assertNull( Value::override( 'albert_privacy_mode' ) );
		$this->assertSame( PrivacyMode::Off, PrivacyMode::resolve() );
	}

	/**
	 * A validator answers to the option, so it applies without anything having
	 * been booted.
	 *
	 * Free's own live in a static map rather than on a hook for exactly this
	 * reason: a privacy question asked before `plugins_loaded` must still get
	 * the fall-through.
	 *
	 * @return void
	 */
	public function test_the_validator_does_not_depend_on_registration(): void {
		remove_all_filters( Value::validator_name( 'albert_privacy_mode' ) );

		$validator = Value::validator( 'albert_privacy_mode' );

		$this->assertIsCallable( $validator );
		$this->assertTrue( $validator( 'strict' ) );
		$this->assertFalse( $validator( 'bananas' ) );
	}

	/**
	 * Every setting the screen can report as overridden is read through the
	 * chain that decides that, so the two cannot describe the site differently.
	 *
	 * A constant used to lock the connection-retention fields on screen while
	 * the sweeps went on reading the stored option: a countdown to a date
	 * nothing was ever going to act on, and a field nobody could edit.
	 *
	 * @return void
	 */
	public function test_the_retention_settings_resolve_through_the_chain(): void {
		$cases = [
			ConnectionRetention::NEVER_USED_OPTION => 'sweep_never_used',
			ConnectionRetention::IDLE_OPTION       => 'sweep_idle',
			AllowedUsers::EXPIRY_OPTION            => null,
		];

		foreach ( $cases as $option_name => $unused ) {
			update_option( $option_name, 5 );

			add_filter( Value::filter_name( $option_name ), static fn () => 30 );

			$this->assertSame(
				30,
				Value::get( $option_name, 0 ),
				$option_name . ' must resolve through the settings chain.'
			);

			remove_all_filters( Value::filter_name( $option_name ) );
			delete_option( $option_name );
		}
	}

	/**
	 * The retention sweep uses the overridden window, not the stored one.
	 *
	 * @return void
	 */
	public function test_the_idle_sweep_reads_the_override(): void {
		update_option( ConnectionRetention::IDLE_OPTION, 0 );

		// 0 disables the sweep; the override switches it back on, which is only
		// observable if the sweep is reading the same chain the screen does.
		add_filter( Value::filter_name( ConnectionRetention::IDLE_OPTION ), static fn () => 30 );

		$this->assertIsArray( ConnectionRetention::sweep_idle() );
		$this->assertSame( 30, (int) Value::get( ConnectionRetention::IDLE_OPTION, 0 ) );

		remove_all_filters( Value::filter_name( ConnectionRetention::IDLE_OPTION ) );
		delete_option( ConnectionRetention::IDLE_OPTION );
	}

	/**
	 * An override that means the right thing but is spelled differently still
	 * marks its own card.
	 *
	 * A validator judges *meaning*, not spelling: `albert_privacy_mode`'s asks
	 * {@see PrivacyMode::try_parse()}, which trims and lower-cases. So `'Strict'`
	 * is accepted, the site genuinely runs Strict, and {@see Value} hands back
	 * the string the constant actually held. The control compares that against
	 * its option keys exactly, so it marked nothing at all: a group with no
	 * selection, on the screen whose whole job is to say what is in force.
	 *
	 * @return void
	 */
	public function test_an_override_spelled_differently_still_marks_its_card(): void {
		update_option( 'albert_privacy_mode', 'off' );

		add_filter( 'albert/privacy/mode', static fn () => '  Strict  ' );

		// The site is running Strict, so the screen has to show Strict.
		$this->assertSame( PrivacyMode::Strict, PrivacyMode::resolve() );

		$html = $this->render_field( 'albert_privacy_mode' );

		$this->assertMatchesRegularExpression( '/value="strict"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="off"[^>]*checked/', $html );
	}

	/**
	 * The legacy hook is named as the source only when it is what answered.
	 *
	 * This used to be decided by `has_filter( 'albert/privacy/mode' )`, which is
	 * true whenever anything is *attached* to that hook, a callback returning
	 * null included. A site that set the mode through the generic filter while
	 * something else merely listened on the legacy one was told its value came
	 * from the legacy one, and went looking in the wrong place. Naming a source
	 * is only worth doing if the name is right.
	 *
	 * @return void
	 */
	public function test_the_source_names_the_hook_that_actually_answered(): void {
		// Attached, but declining: this must not be credited with the value.
		add_filter( 'albert/privacy/mode', static fn () => null );
		add_filter( Value::filter_name( 'albert_privacy_mode' ), static fn () => 'off' );

		$override = Value::override( 'albert_privacy_mode' );

		$this->assertNotNull( $override );
		$this->assertSame( 'off', $override['value'] );
		$this->assertSame(
			Value::filter_name( 'albert_privacy_mode' ),
			$override['name'],
			'The generic filter answered, so the generic filter is the source.'
		);
	}

	/**
	 * ...and it is named when it genuinely did answer.
	 *
	 * @return void
	 */
	public function test_the_legacy_hook_is_named_when_it_answered(): void {
		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$override = Value::override( 'albert_privacy_mode' );

		$this->assertNotNull( $override );
		$this->assertSame( 'albert/privacy/mode', $override['name'] );
	}

	/**
	 * A locked text-shaped control stays reachable, and points at the sentence
	 * that explains why it is locked.
	 *
	 * It rendered `disabled`, which takes a control out of the tab order. A
	 * keyboard or screen-reader user could then reach neither the value in
	 * force nor the hint underneath saying what owns it: the one part of the
	 * field that answers the question the state raises was the part that became
	 * unreachable. `readonly` keeps it focusable, and the save loop skips a
	 * locked field outright ({@see Lock}), so it not being submitted was never
	 * what the lock depended on.
	 *
	 * @return void
	 */
	public function test_a_locked_text_control_stays_reachable_and_explains_itself(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 500 * 1024 );

		$html = $this->render_field( UploadLinkService::MAX_BYTES_OPTION );

		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( 'aria-disabled="true"', $html );

		// The bare attribute, not `aria-disabled`, which of course contains it.
		$this->assertDoesNotMatchRegularExpression( '/\sdisabled[\s\/>=]/', $html );

		// The hint carries an id, and the control points at it.
		$this->assertMatchesRegularExpression(
			'/aria-describedby="[^"]*albert-field-' . preg_quote( UploadLinkService::MAX_BYTES_OPTION, '/' ) . '-hint/',
			$html
		);
		$this->assertStringContainsString(
			'id="albert-field-' . UploadLinkService::MAX_BYTES_OPTION . '-hint"',
			$html
		);
	}

	/**
	 * A group of radios has no `readonly` to reach for, so it stays disabled,
	 * and says so.
	 *
	 * @return void
	 */
	public function test_a_locked_radio_group_stays_disabled(): void {
		add_filter( 'albert/privacy/mode', static fn () => 'off' );

		$html = $this->render_field( 'albert_privacy_mode' );

		$this->assertStringContainsString( 'albert-radio-cards--disabled', $html );
		$this->assertStringNotContainsString( 'readonly', $html );
	}

	/**
	 * The registered field that writes one option.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return array<string, mixed>|null
	 */
	private function field_for( string $option_name ): ?array {
		foreach ( Schema::collect() as $section ) {
			foreach ( $section['fields'] ?? [] as $field ) {
				if ( is_array( $field ) && ( $field['option_name'] ?? '' ) === $option_name ) {
					return $field;
				}
			}
		}

		return null;
	}
}
