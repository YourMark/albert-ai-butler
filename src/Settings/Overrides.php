<?php
/**
 * Settings Overrides bridge
 *
 * @package Albert
 * @subpackage Settings
 * @since      1.4.0
 */

namespace Albert\Settings;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Media\UploadLinks\UploadLinkService;

/**
 * Lets Albert's domain-specific override filters feed the generic chain.
 *
 * Two filters predate {@see Value} and are the documented way to pin these
 * settings in code: `albert/privacy/mode` (1.3.0) and
 * `albert/media/upload_link_max_bytes` (1.4.0). Neither is deprecated. A filter
 * named for its subject reads better at the call site than
 * `albert/settings/value/albert_privacy_mode` would, so renaming them to match a
 * generic scheme would make the API worse, not better.
 *
 * What they lack is a way to tell the Settings screen they are in force.
 * Bridging them onto `albert/settings/value/{option}` supplies it in one place:
 * the control renders read-only and names where the value comes from, rather
 * than offering an editable copy of a value the filter overrides anyway.
 *
 * What decides whether a given override is *usable* is a separate thing and
 * lives in {@see Validators}, deliberately as a static map rather than another
 * set of hooks registered from here. See that class for why.
 *
 * Registered in every context, not just admin — the generic chain is what
 * `albert_get_setting()` reads on MCP and front-end requests too, and a cron
 * sweep resolving a setting differently from the screen that shows it is the
 * bug this class exists to stop.
 *
 * @since 1.4.0
 */
class Overrides implements Hookable {

	/**
	 * Whether this class's own bridge produced the privacy mode last time the
	 * value filter ran.
	 *
	 * Read by {@see self::privacy_mode_source()}, which runs immediately after
	 * within the same {@see Value::override()} call. A flag rather than
	 * `has_filter( 'albert/privacy/mode' )`: that is true whenever anything is
	 * *attached* to the hook, including a callback that deferred by returning
	 * null, so it would name the wrong source.
	 *
	 * @since 1.4.0
	 * @var bool
	 */
	private bool $privacy_mode_bridged = false;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_filter( Value::filter_name( 'albert_privacy_mode' ), [ $this, 'privacy_mode' ] );
		add_filter( Value::filter_name( UploadLinkService::MAX_BYTES_OPTION ), [ $this, 'upload_max_mb' ] );

		// So the screen names the hook the site actually wrote, not the generic
		// one this class answers on its behalf.
		add_filter( 'albert/settings/value_source/albert_privacy_mode', [ $this, 'privacy_mode_source' ] );
	}

	/**
	 * Bridge `albert/privacy/mode` onto the generic chain.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $value Value resolved so far, null when nothing has claimed it.
	 *
	 * @return mixed
	 */
	public function privacy_mode( $value ) {
		$this->privacy_mode_bridged = false;

		if ( $value !== null ) {
			return $value;
		}

		/**
		 * Filters the active privacy mode.
		 *
		 * Return one of `strict`, `balanced`, or `off` to override the stored
		 * option. Return null (the default) to defer to the option/default. A
		 * value outside that vocabulary is ignored, and resolution continues to
		 * the stored value.
		 *
		 * @since 1.3.0
		 *
		 * @param string|null $mode The overriding mode value, or null to defer.
		 */
		$legacy = apply_filters( 'albert/privacy/mode', null );

		if ( ! is_string( $legacy ) || $legacy === '' ) {
			return null;
		}

		$this->privacy_mode_bridged = true;

		return $legacy;
	}

	/**
	 * Bridge `albert/media/upload_link_max_bytes` onto the generic chain.
	 *
	 * That filter speaks bytes; the option it overrides stores megabytes, so
	 * the two are not interchangeable and this converts. Rounding is **up**, and
	 * never to zero: the field's own minimum is 1, and rendering `value="0"`
	 * against it would be invalid. The exact size is not lost — the field's hint
	 * carries it, which is why that one callback survives while the rest went.
	 *
	 * {@see UploadLinkService::default_max_bytes()} does not read this value. It
	 * reads the byte filter directly, so the limit actually enforced is exact
	 * rather than rounded to a megabyte.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $value Value resolved so far, null when nothing has claimed it.
	 *
	 * @return mixed Megabytes, or the untouched input.
	 */
	public function upload_max_mb( $value ) {
		if ( $value !== null ) {
			return $value;
		}

		$state = UploadLinkService::get_default_max_bytes_filter_state();

		if ( $state['state'] !== 'active' ) {
			return null;
		}

		return max( 1, (int) ceil( $state['value'] / UploadLinkService::BYTES_PER_MB ) );
	}

	/**
	 * Name `albert/privacy/mode` as the source, but only when it is genuinely
	 * what answered.
	 *
	 * Decided by {@see self::$privacy_mode_bridged}, set a moment earlier in
	 * the same resolution, rather than by asking whether anything is attached
	 * to the legacy hook. See that property for what the difference cost.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $name Source name resolved so far.
	 *
	 * @return mixed
	 */
	public function privacy_mode_source( $name ) {
		if ( is_string( $name ) && $name !== '' ) {
			return $name;
		}

		return $this->privacy_mode_bridged ? 'albert/privacy/mode' : $name;
	}
}
