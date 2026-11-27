<?php
/**
 * Settings Value resolution
 *
 * @package Albert
 * @subpackage Settings
 * @since      1.4.0
 */

namespace Albert\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What a setting's value actually is, and whether the site owner still owns it.
 *
 * Before this, every setting that could be overridden in code hand-rolled its
 * own precedence: {@see \Albert\Privacy\PrivacyMode::resolve()} was constant →
 * filter → option → default, {@see \Albert\Media\UploadLinks\UploadLinkService}
 * was filter → option → ceiling, and connection retention was option → default
 * with no override at all. Three chains, three spellings, and only one of them
 * told the Settings screen it was in force — so a site filtering the privacy
 * mode showed the *stored* value on screen and let somebody save over it,
 * which is a lie about what the site does.
 *
 * The chain, highest priority first:
 *
 * 1. **A PHP constant** named after the option in upper case, so
 *    `albert_privacy_mode` is set by `ALBERT_PRIVACY_MODE`. This is how a
 *    `wp-config.php` pins a value across a fleet.
 * 2. **The filter `albert/settings/value/{option_name}`**, which returns null
 *    to defer — the same idiom as `albert/privacy/mode` and
 *    `albert/media/upload_link_max_bytes`, both of which now feed this chain.
 * 3. **The stored option**, and failing that the caller's default.
 *
 * **Deliberately knows nothing about the settings schema.** Resolution happens
 * on MCP and front-end requests, and reading the schema means firing
 * `albert/settings/register`, which would run every add-on's registration
 * callback on requests that will never render a form. So this is a resolver
 * over an option *name*, nothing more. {@see Storage} is what makes the
 * *stored* value trustworthy; this is only about which layer wins.
 *
 * **And that is why this is not in `Admin\`.** It lived at
 * `Albert\Admin\Settings\Value` while `PrivacyMode`, `AllowedUsers`,
 * `ConnectionRetention` and `UploadLinkService` all read through it, which put
 * an `Admin` namespace in the path of every MCP request and both cron sweeps.
 * It also sat a `Settings` namespace beside the `Albert\Admin\Settings` page
 * class, which PHP allows and nobody enjoys. Settings resolution is its own
 * bounded context; the form that edits them is the admin's.
 *
 * **A constant is a global name.** `albert_privacy_mode` becomes
 * `ALBERT_PRIVACY_MODE`, and nothing reserves that space: an option named
 * `myplugin_debug` binds to whatever `MYPLUGIN_DEBUG` already means on the
 * site. Namespace option names the way Albert does. A declared validator is the
 * safety net when one collides, since an unusable value is skipped rather than
 * pinning the site.
 *
 * **A validator belongs to the option, not to the call site**, and is reachable
 * from the option name alone: {@see Validators} for Free's own, and
 * `albert/settings/validator/{option_name}` for anybody else's. The reason is
 * that every caller has to reach the same verdict. When the rule lived at the
 * call site, {@see \Albert\Privacy\PrivacyMode::resolve()} applied one and the
 * Settings screen did not, so `define( 'ALBERT_PRIVACY_MODE', 'bananas' )` made
 * the screen render the field read-only, showing `bananas` and naming the
 * constant, while the site went on running the stored mode. The screen locked
 * an owner out of a setting the constant did not control, which is the exact
 * failure this class exists to prevent. An explicit `$validator` argument still
 * wins, for a caller with a narrower question than the option's own.
 *
 * @since 1.4.0
 */
class Value {

	/**
	 * The value in force for one setting.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name   The option name.
	 * @param mixed         $default_value Returned when nothing is stored.
	 * @param callable|null $validator     Optional `fn( $value ): bool`. An
	 *                                     override it rejects is skipped and
	 *                                     resolution continues to the next layer.
	 *                                     Omit to use the option's own, if it
	 *                                     has registered one.
	 *
	 * @return mixed
	 */
	public static function get( string $option_name, $default_value = null, ?callable $validator = null ) {
		$override = self::override( $option_name, $validator );

		if ( $override !== null ) {
			return $override['value'];
		}

		return get_option( $option_name, $default_value );
	}

	/**
	 * The override in force, if any: what it is and where it came from.
	 *
	 * One method rather than a pair, because the source and the value have to
	 * agree — asking twice invites a filter that answers differently in
	 * between, and the screen would then name one source while displaying
	 * another's value.
	 *
	 * @since 1.4.0
	 *
	 * A validator makes a layer skippable: an override it rejects is passed over
	 * rather than taking the site down, so a typo in a `wp-config.php` constant
	 * falls through to the filter or the stored value instead of resolving to
	 * nonsense. When none is passed, the option's own is used. See the class
	 * docblock on why that has to be reachable from the name alone.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name The option name.
	 * @param callable|null $validator   Optional `fn( $value ): bool`. Omit to
	 *                                   use the option's own.
	 *
	 * @return array{source: string, value: mixed, name: string}|null Null when the
	 *                                                                stored value wins.
	 */
	public static function override( string $option_name, ?callable $validator = null ): ?array {
		$validator = $validator ?? self::validator( $option_name );
		$constant  = self::constant_name( $option_name );

		if ( $constant !== '' && defined( $constant ) ) {
			$value = constant( $constant );

			if ( $validator === null || (bool) $validator( $value ) ) {
				return [
					'source' => 'constant',
					'value'  => $value,
					'name'   => $constant,
				];
			}
		}

		$hook = self::filter_name( $option_name );

		/**
		 * Filters the value in force for one Albert setting.
		 *
		 * The hook name carries the option name, so
		 * `albert/settings/value/albert_privacy_mode` overrides the privacy
		 * mode. Return null (the default) to defer to the stored value.
		 *
		 * A setting under an active override renders read-only on the Settings
		 * screen, with a note saying where the value comes from: an owner is
		 * never shown a control that would not actually change anything.
		 *
		 * @since 1.4.0
		 *
		 * @param mixed $value The overriding value, or null to defer.
		 */
		$filtered = apply_filters( $hook, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- albert/settings/{option}.

		if ( $filtered !== null && ( $validator === null || (bool) $validator( $filtered ) ) ) {
			/**
			 * Filters the name reported as the source of an override.
			 *
			 * Only useful to something that answers the value filter on another
			 * hook's behalf. `albert/privacy/mode` is bridged onto this chain by
			 * {@see Overrides}, so without this the screen would tell an owner
			 * their value came from `albert/settings/value/albert_privacy_mode`
			 * — a hook nobody wrote, and one they would not find by grepping
			 * their own code. Return null to report the hook that answered.
			 *
			 * @since 1.4.0
			 *
			 * @param string|null $name The hook or constant to name, or null.
			 */
			$reported = apply_filters( 'albert/settings/value_source/' . $option_name, null );

			return [
				'source' => 'filter',
				'value'  => $filtered,
				'name'   => is_string( $reported ) && $reported !== '' ? $reported : $hook,
			];
		}

		return null;
	}

	/**
	 * Whether code, rather than the stored option, decides this setting.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name The option name.
	 * @param callable|null $validator   Optional `fn( $value ): bool`.
	 *
	 * @return bool
	 */
	public static function is_overridden( string $option_name, ?callable $validator = null ): bool {
		return self::override( $option_name, $validator ) !== null;
	}

	/**
	 * Where an override comes from: `constant`, `filter`, or null for neither.
	 *
	 * @since 1.4.0
	 *
	 * @param string        $option_name The option name.
	 * @param callable|null $validator   Optional `fn( $value ): bool`.
	 *
	 * @return string|null
	 */
	public static function override_source( string $option_name, ?callable $validator = null ): ?string {
		$override = self::override( $option_name, $validator );

		return $override === null ? null : $override['source'];
	}

	/**
	 * The option's own validator, if it has declared one.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name The option name.
	 *
	 * @return callable|null `fn( $value ): bool`, or null when the option
	 *                       accepts anything.
	 */
	public static function validator( string $option_name ): ?callable {
		/**
		 * Filters the validator that decides whether an override is usable.
		 *
		 * Return `fn( $value ): bool`. An override it rejects is skipped and
		 * resolution continues to the next layer, so a malformed constant falls
		 * through to the stored value instead of pinning the site to nonsense.
		 *
		 * Declare one for any setting with a closed vocabulary or a range. It is
		 * what stops the Settings screen reporting an override that the code
		 * reading the setting would refuse: both ask this same question.
		 *
		 * @since 1.4.0
		 *
		 * @param callable|null $validator The validator, or null to accept anything.
		 */
		$validator = apply_filters( self::validator_name( $option_name ), null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- albert/settings/validator/{option}.

		if ( is_callable( $validator ) ) {
			return $validator;
		}

		// Free's own, which are a plain static map rather than something
		// published on a hook: this has to answer correctly on the very first
		// request, before anything has had a chance to register.
		return Validators::for_option( $option_name );
	}

	/**
	 * The filter that overrides one setting.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name The option name.
	 *
	 * @return non-empty-string Always: the prefix alone guarantees it.
	 */
	public static function filter_name( string $option_name ): string {
		return 'albert/settings/value/' . $option_name;
	}

	/**
	 * The filter that declares one setting's validator.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name The option name.
	 *
	 * @return non-empty-string Always: the prefix alone guarantees it.
	 */
	public static function validator_name( string $option_name ): string {
		return 'albert/settings/validator/' . $option_name;
	}

	/**
	 * The constant that overrides one setting, or '' when the option name
	 * cannot spell a legal constant.
	 *
	 * Option names are lower case by convention and already namespaced, so
	 * upper-casing is the whole rule: `albert_privacy_mode` →
	 * `ALBERT_PRIVACY_MODE`. Guarded because an option name is not required to
	 * be a legal PHP identifier, and `defined()` on a malformed name is a
	 * question worth not asking.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name The option name.
	 *
	 * @return string
	 */
	public static function constant_name( string $option_name ): string {
		if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $option_name ) !== 1 ) {
			return '';
		}

		return strtoupper( $option_name );
	}
}
