<?php
/**
 * PII Anonymizer
 *
 * @package Albert
 * @subpackage Privacy
 * @since      1.3.0
 */

namespace Albert\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Anonymizer class
 *
 * Generic, WooCommerce-agnostic masking of personal data in an arbitrary
 * array. Redacts by KEY NAME using a filterable allow-list, descending
 * recursively through nested sub-arrays (e.g. `billing`/`shipping`) and
 * numeric-indexed arrays of records (e.g. a customers list).
 *
 * The transformation is idempotent — re-masking already-masked data is a
 * no-op — and depends on no WordPress APIs beyond the `pii_fields` and
 * `payment_keys` filters, so it can be unit-tested in isolation.
 *
 * Free defines only GENERIC PII (email, phone, names, address, postcode, IP,
 * customer note). Payment/card keys are NOT hardcoded here: the strip mechanism
 * stays, but its key/prefix list is sourced from the `albert/privacy/payment_keys`
 * filter (empty by default) so add-ons own their gateway-specific keys.
 *
 * @since 1.3.0
 */
class Anonymizer {

	/**
	 * Marker written in place of a fully redacted free-text value.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	private const REDACTED = '[redacted]';

	/**
	 * Array keys whose sub-array is a personal-data context.
	 *
	 * Inside one of these the otherwise-ambiguous `name` key is treated as
	 * a person's name; outside them (e.g. inside `items`) it is left alone so
	 * product line-item names and catalog data survive untouched.
	 *
	 * @since 1.3.0
	 * @var array<int, string>
	 */
	private const CONTEXT_KEYS = [ 'billing', 'shipping', 'customer' ];

	/**
	 * Anonymize personal data in an array.
	 *
	 * Removes payment/card data and masks every recognised PII field. Safe to
	 * call on any array shape; unknown keys pass through untouched.
	 *
	 * @since 1.3.0
	 *
	 * @since 1.6.0 Added the `rules` and `ref_label` options.
	 *
	 * @param array<int|string, mixed> $data The array to anonymize.
	 * @param array<string, mixed>     $opts Optional. `rules` replaces the allow-list wholesale
	 *                                       (see {@see self::essential_fields()});
	 *                                       `fields` merges category overrides OVER
	 *                                       the filtered allow-list (so the
	 *                                       `albert/privacy/pii_fields` filter is still honoured);
	 *                                       `mask_context_names` (bool) applies the ambiguous
	 *                                       `context_*` keys (`name`, `url`, `description`)
	 *                                       everywhere, not only on a person record — use for
	 *                                       person-record results that carry no product data;
	 *                                       `ref_label` names a masked person with an id
	 *                                       (default `Customer`, giving `Customer #42`).
	 *
	 * @return array<int|string, mixed> The anonymized array.
	 */
	public static function anonymize( array $data, array $opts = [] ): array {
		if ( isset( $opts['rules'] ) && is_array( $opts['rules'] ) ) {
			$fields = $opts['rules'];
		} else {
			// Start from the FILTERED allow-list so add-on `pii_fields` registrations
			// are honoured even when a caller passes its own `fields` override.
			$fields = self::fields();
			if ( isset( $opts['fields'] ) && is_array( $opts['fields'] ) ) {
				$fields = array_merge( $fields, $opts['fields'] );
			}
		}
		$fields = self::lowercase_fields( $fields );

		// Resolve the payment/card matchers ONCE per call and thread them through
		// the recursion — walk() runs per key, so we must not filter per key.
		$payment = self::get_payment_matchers();
		$label   = isset( $opts['ref_label'] ) && is_string( $opts['ref_label'] ) ? $opts['ref_label'] : 'Customer';

		return self::walk( $data, $fields, $payment, '', ! empty( $opts['mask_context_names'] ), $label );
	}

	/**
	 * Remove only payment/card data, leaving everything else intact.
	 *
	 * Used when anonymisation is disabled or personal data is deliberately
	 * revealed — payment data is never allowed through regardless.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int|string, mixed> $data The array to sanitise.
	 *
	 * @return array<int|string, mixed> The array with payment keys removed.
	 */
	public static function strip_payment_data( array $data ): array {
		// Resolve the payment/card matchers ONCE and recurse with them.
		return self::strip( $data, self::get_payment_matchers() );
	}

	/**
	 * Recursively remove payment/card keys using pre-resolved matchers.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int|string, mixed>                                      $data    The array to sanitise.
	 * @param array{keys: array<int, string>, prefixes: array<int, string>} $payment The resolved payment matchers.
	 *
	 * @return array<int|string, mixed> The array with payment keys removed.
	 */
	private static function strip( array $data, array $payment ): array {
		$out = [];
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_payment_key( $key, $payment ) ) {
				continue;
			}
			$out[ $key ] = is_array( $value ) ? self::strip( $value, $payment ) : $value;
		}

		return $out;
	}

	/**
	 * Recursively walk and mask an array node.
	 *
	 * Keys match case-insensitively. A key no category names is still masked
	 * when its suffix says what it holds (`*_email`, `*_phone`, `*_ip`), and a
	 * string that is wholly an email address is masked under any key.
	 *
	 * @since 1.3.0
	 * @since 1.6.0 Matches suffixes, person records and email-shaped values.
	 *
	 * @param array<int|string, mixed>                                      $node    The current array node.
	 * @param array<string, array<int, string>>                             $fields  The PII allow-list, lower-cased.
	 * @param array{keys: array<int, string>, prefixes: array<int, string>} $payment The resolved payment matchers.
	 * @param string                                                        $context Current PII context ('' or 'customer').
	 * @param bool                                                          $always  Mask context keys regardless of context.
	 * @param string                                                        $label   Names a masked person with an id: `{label} #{id}`.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function walk( array $node, array $fields, array $payment, string $context, bool $always, string $label ): array {
		$ref    = self::node_customer_ref( $node );
		$person = $always || $context !== '' || self::is_person_record( $node, $fields );
		$out    = [];

		foreach ( $node as $key => $value ) {
			// Payment/card data is hard-removed at any depth, in any mode.
			if ( is_string( $key ) && self::is_payment_key( $key, $payment ) ) {
				continue;
			}

			$rule = is_string( $key ) ? self::rule_for( strtolower( $key ), $fields, $person ) : null;

			// Before recursing, so a list such as `avatar_urls` goes as a whole.
			if ( $rule === 'strip' ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$out[ $key ] = $rule === 'empty'
					? []
					: self::walk( $value, $fields, $payment, self::child_context( $key, $context ), $always, $label );
				continue;
			}

			$out[ $key ] = match ( $rule ) {
				'empty'    => '',
				'redact'   => ( $value === '' || $value === null ) ? $value : self::REDACTED,
				'email'    => is_string( $value ) ? self::mask_email( $value ) : $value,
				'phone'    => is_scalar( $value ) && ! is_bool( $value ) ? self::mask_phone( (string) $value ) : $value,
				'postcode' => is_scalar( $value ) && ! is_bool( $value ) ? self::mask_postcode( (string) $value ) : $value,
				'name'     => $ref !== null ? $label . ' #' . $ref : self::mask_name( self::to_string( $value ) ),
				default    => self::is_email_value( $value ) ? self::mask_email( (string) $value ) : $value,
			};
		}

		return $out;
	}

	/**
	 * The masking rule for a key, or null when it is not personal data.
	 *
	 * The `context_*` categories hold keys that are personal only on a record
	 * about a person: a user's `url` is their website, a site's is not.
	 *
	 * @since 1.6.0
	 *
	 * @param string                            $key    The lower-cased key.
	 * @param array<string, array<int, string>> $fields The PII allow-list, lower-cased.
	 * @param bool                              $person Whether the node is about a person.
	 *
	 * @return string|null One of strip, empty, redact, email, phone, postcode, name.
	 */
	private static function rule_for( string $key, array $fields, bool $person ): ?string {
		foreach ( [ 'strip', 'empty', 'redact', 'email', 'phone', 'postcode', 'name' ] as $rule ) {
			if ( in_array( $key, $fields[ $rule ] ?? [], true ) ) {
				return $rule;
			}
			if ( $person && in_array( $key, $fields[ 'context_' . $rule ] ?? [], true ) ) {
				return $rule;
			}
		}

		return match ( true ) {
			str_ends_with( $key, '_ip' ), str_ends_with( $key, '_ip_address' ) => 'strip',
			str_ends_with( $key, '_email' ) => 'email',
			str_ends_with( $key, '_phone' ) => 'phone',
			default => null,
		};
	}

	/**
	 * Whether a node is a record about a person, by carrying a key that only
	 * a person has (an email of their own, a login, a first or last name).
	 *
	 * @since 1.6.0
	 *
	 * @param array<int|string, mixed>          $node   The node.
	 * @param array<string, array<int, string>> $fields The PII allow-list, lower-cased.
	 *
	 * @return bool
	 */
	private static function is_person_record( array $node, array $fields ): bool {
		foreach ( array_keys( $node ) as $key ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $fields['person_markers'] ?? [], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a value is wholly an email address.
	 *
	 * Image names such as `logo@2x.png` have the same shape and are left alone.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function is_email_value( mixed $value ): bool {
		if ( ! is_string( $value ) || strlen( $value ) > 254 ) {
			return false;
		}

		return (bool) preg_match( '/^[^\s@\/:*]+@[^\s@\/:*]+\.(?!(?:png|jpe?g|gif|webp|avif|svg|ico|css|js)$)[a-z]{2,}$/i', $value );
	}

	/**
	 * Resolve the child PII context for a nested array.
	 *
	 * Numeric keys preserve the parent context so arrays-of-records inherit
	 * it; a recognised context key ('billing'/'shipping'/'customer') opens a
	 * personal-data context; any other string key resets it so unrelated
	 * branches (e.g. `items`) are never treated as personal data.
	 *
	 * @since 1.3.0
	 *
	 * @param int|string $key     The key being descended into.
	 * @param string     $current The current context.
	 *
	 * @return string
	 */
	private static function child_context( int|string $key, string $current ): string {
		if ( is_int( $key ) ) {
			return $current;
		}

		return in_array( $key, self::CONTEXT_KEYS, true ) ? 'customer' : '';
	}

	/**
	 * Find a customer reference in the current node for name substitution.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int|string, mixed> $node The node to inspect.
	 *
	 * @return string|null Numeric reference as a string, or null when absent.
	 */
	private static function node_customer_ref( array $node ): ?string {
		foreach ( [ 'customer_id', 'id' ] as $candidate ) {
			if ( ! isset( $node[ $candidate ] ) ) {
				continue;
			}
			$value = $node[ $candidate ];
			if ( is_int( $value ) && $value > 0 ) {
				return (string) $value;
			}
			if ( is_string( $value ) && $value !== '' && ctype_digit( $value ) ) {
				return (string) (int) $value;
			}
		}

		return null;
	}

	/**
	 * Keys that are personal data in any plugin's result, grouped by rule.
	 *
	 * Deliberately narrow: no names, slugs, URLs or numbers, which in another
	 * plugin's settings or records are as likely to be the site's own data.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function essential_fields(): array {
		$defaults = self::default_pii_fields();

		return [
			'email' => $defaults['email'],
			'phone' => [ 'phone', 'phone_number', 'mobile_phone', 'telephone', 'billing_phone', 'shipping_phone' ],
			'strip' => [
				'ip',
				'ip_address',
				'customer_ip_address',
				'remote_addr',
				'user_pass',
				'password_hash',
				'user_activation_key',
				'session_tokens',
				'session_token',
				'auth_token',
				'access_token',
				'refresh_token',
			],
		];
	}

	/**
	 * Get the (filtered) PII allow-list.
	 *
	 * @since 1.3.0
	 * @since 1.6.0 Public, renamed from `get_pii_fields()`.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function fields(): array {
		$defaults = self::default_pii_fields();

		/**
		 * Filters the PII field allow-list used by the anonymizer.
		 *
		 * Each category maps to a list of array-key names, matched case-insensitively. Returned
		 * categories are merged OVER the defaults at the category level: a
		 * returned category REPLACES that category wholesale — it does not append
		 * to it. To extend a category, read the incoming value and return it with
		 * your keys added (e.g. `$fields['name'][] = 'customer_name';`). Categories
		 * you omit keep their default values.
		 *
		 * @since 1.3.0
		 * @since 1.6.0 Added the `context_empty`, `context_redact` and `person_markers` categories.
		 *
		 * @param array<string, array<int, string>> $defaults The default allow-list.
		 */
		$filtered = apply_filters( 'albert/privacy/pii_fields', $defaults );

		return is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;
	}

	/**
	 * Lower-case every key name, dropping anything that is not a list of strings.
	 *
	 * @since 1.6.0
	 *
	 * @param array<mixed> $fields The allow-list.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function lowercase_fields( array $fields ): array {
		$out = [];
		foreach ( $fields as $category => $keys ) {
			if ( is_string( $category ) && is_array( $keys ) ) {
				$out[ $category ] = array_values( array_map( 'strtolower', array_filter( $keys, 'is_string' ) ) );
			}
		}

		return $out;
	}

	/**
	 * The default PII allow-list, grouped by masking rule.
	 *
	 * `context_*` categories apply only on a record about a person (see
	 * `person_markers`) or inside billing/shipping/customer.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function default_pii_fields(): array {
		return [
			'email'          => [ 'email', 'user_email', 'admin_email', 'author_email', 'comment_author_email', 'customer_email', 'billing_email', 'shipping_email', 'contact_email' ],
			'phone'          => [ 'phone', 'phone_number', 'mobile', 'mobile_phone', 'telephone', 'tel', 'cell', 'fax', 'billing_phone', 'shipping_phone' ],
			'name'           => [
				'first_name',
				'last_name',
				'middle_name',
				'full_name',
				'given_name',
				'family_name',
				'display_name',
				'nickname',
				'username',
				'user_login',
				'user_nicename',
				'author_name',
				'comment_author',
				'customer_name',
				'billing_first_name',
				'billing_last_name',
				'shipping_first_name',
				'shipping_last_name',
			],
			'context_name'   => [ 'name', 'slug' ],
			'postcode'       => [ 'postcode', 'post_code', 'billing_postcode', 'shipping_postcode', 'zip', 'zipcode', 'zip_code', 'postal_code' ],
			'empty'          => [
				'address',
				'address_1',
				'address_2',
				'address1',
				'address2',
				'address_line_1',
				'address_line_2',
				'street',
				'street_address',
				'billing_address_1',
				'billing_address_2',
				'shipping_address_1',
				'shipping_address_2',
				'user_url',
				'author_url',
				'comment_author_url',
			],
			'context_empty'  => [ 'url', 'link', 'website' ],
			'strip'          => [
				'ip',
				'ip_address',
				'customer_ip_address',
				'remote_addr',
				'user_agent',
				'customer_user_agent',
				'author_user_agent',
				'comment_agent',
				'avatar_urls',
				'user_pass',
				'user_activation_key',
				'session_tokens',
			],
			'redact'         => [ 'customer_note', 'date_of_birth', 'birthdate', 'birthday', 'dob', 'national_id', 'ssn' ],
			'context_redact' => [ 'description', 'bio', 'biography', 'tax_id', 'vat_number', 'iban' ],
			'person_markers' => [ 'email', 'user_email', 'user_login', 'username', 'user_nicename', 'first_name', 'last_name', 'nickname' ],
		];
	}

	/**
	 * Resolve the payment/card key matchers for this call.
	 *
	 * Free ships NO payment keys of its own — generic PII is the free basis, and
	 * payment/card protection is contributed by add-ons (e.g. the WooCommerce
	 * add-on) via the `albert/privacy/payment_keys` filter. The result is
	 * resolved once per {@see anonymize()} / {@see strip_payment_data()} call and
	 * threaded through the recursion, so the filter is never fired per key.
	 *
	 * @since 1.3.0
	 *
	 * @return array{keys: array<int, string>, prefixes: array<int, string>} Lower-cased matchers.
	 */
	private static function get_payment_matchers(): array {
		/**
		 * Filters the payment/card key matchers that are hard-removed at every
		 * depth, in every mode (including reveal/off).
		 *
		 * Add-ons contribute the concrete keys and prefixes for their payment
		 * gateways. Return an array shaped `[ 'keys' => [...], 'prefixes' => [...] ]`;
		 * `keys` are matched exactly (case-insensitively) and `prefixes` match the
		 * start of a key. Free's default is empty.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string, mixed> $matchers The default (empty) matchers, shaped
		 *                                        `[ 'keys' => [], 'prefixes' => [] ]`. An
		 *                                        add-on's return is validated defensively.
		 */
		$matchers = apply_filters(
			'albert/privacy/payment_keys',
			[
				'keys'     => [],
				'prefixes' => [],
			]
		);

		$keys     = ( isset( $matchers['keys'] ) && is_array( $matchers['keys'] ) ) ? $matchers['keys'] : [];
		$prefixes = ( isset( $matchers['prefixes'] ) && is_array( $matchers['prefixes'] ) ) ? $matchers['prefixes'] : [];

		return [
			'keys'     => array_values( array_map( 'strtolower', array_filter( $keys, 'is_string' ) ) ),
			'prefixes' => array_values( array_map( 'strtolower', array_filter( $prefixes, 'is_string' ) ) ),
		];
	}

	/**
	 * Determine whether a key holds payment/card data.
	 *
	 * @since 1.3.0
	 *
	 * @param string                                                        $key     The array key.
	 * @param array{keys: array<int, string>, prefixes: array<int, string>} $payment The resolved payment matchers.
	 *
	 * @return bool
	 */
	private static function is_payment_key( string $key, array $payment ): bool {
		$normalized = strtolower( $key );

		if ( in_array( $normalized, $payment['keys'], true ) ) {
			return true;
		}

		foreach ( $payment['prefixes'] as $prefix ) {
			if ( $prefix !== '' && str_starts_with( $normalized, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Coerce a scalar value to a string for masking.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function to_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Mask an email address (e.g. `john@example.com` -> `j***@e***.com`).
	 *
	 * @since 1.3.0
	 *
	 * @param string $value The email address.
	 *
	 * @return string
	 */
	private static function mask_email( string $value ): string {
		if ( $value === '' || strpos( $value, '*' ) !== false ) {
			return $value;
		}

		$at = strpos( $value, '@' );
		if ( $at === false ) {
			return self::mask_token( $value );
		}

		$local  = substr( $value, 0, $at );
		$domain = substr( $value, $at + 1 );

		$dot = strrpos( $domain, '.' );
		if ( $dot === false ) {
			return self::mask_token( $local ) . '@' . self::mask_token( $domain );
		}

		$host = substr( $domain, 0, $dot );
		$tld  = substr( $domain, $dot + 1 );

		return self::mask_token( $local ) . '@' . self::mask_token( $host ) . '.' . $tld;
	}

	/**
	 * Reduce a token to its first character plus a fixed mask.
	 *
	 * @since 1.3.0
	 *
	 * @param string $token The token.
	 *
	 * @return string
	 */
	private static function mask_token( string $token ): string {
		if ( $token === '' ) {
			return '***';
		}

		return substr( $token, 0, 1 ) . '***';
	}

	/**
	 * Mask a phone number, keeping only the last two digits.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value The phone number.
	 *
	 * @return string
	 */
	private static function mask_phone( string $value ): string {
		if ( $value === '' || strpos( $value, '*' ) !== false ) {
			return $value;
		}

		$digits = (string) preg_replace( '/\D+/', '', $value );
		$length = strlen( $digits );

		if ( $length === 0 ) {
			return $value;
		}

		if ( $length <= 2 ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - 2 ) . substr( $digits, -2 );
	}

	/**
	 * Mask a personal name down to its initials (e.g. `John Doe` -> `J.D.`).
	 *
	 * @since 1.3.0
	 *
	 * @param string $value The name.
	 *
	 * @return string
	 */
	private static function mask_name( string $value ): string {
		$trimmed = trim( $value );
		if ( $trimmed === '' ) {
			return $value;
		}

		// Already initials — leave untouched to stay idempotent.
		if ( preg_match( '/^(?:\p{Lu}\.)+$/u', $trimmed ) ) {
			return $value;
		}

		$parts = preg_split( '/\s+/u', $trimmed );
		if ( ! is_array( $parts ) ) {
			return $value;
		}

		$initials = '';
		foreach ( $parts as $part ) {
			$first = mb_substr( $part, 0, 1 );
			if ( $first !== '' ) {
				$initials .= mb_strtoupper( $first ) . '.';
			}
		}

		return $initials !== '' ? $initials : $value;
	}

	/**
	 * Mask a postcode, keeping only its first two characters.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value The postcode.
	 *
	 * @return string
	 */
	private static function mask_postcode( string $value ): string {
		if ( $value === '' || strpos( $value, '*' ) !== false ) {
			return $value;
		}

		$length = strlen( $value );
		if ( $length <= 2 ) {
			return $value;
		}

		return substr( $value, 0, 2 ) . str_repeat( '*', $length - 2 );
	}
}
