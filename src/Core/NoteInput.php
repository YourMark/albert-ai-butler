<?php
/**
 * The optional `note` input Albert adds to write abilities.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.7.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use WP_Error;

/**
 * One place that knows whether an ability's `note` is Albert's: a one-line
 * reason for the site owner, kept with the recorded change and never passed
 * to the ability.
 *
 * An ability may declare a `note` of its own (a WooCommerce order note is
 * text a customer reads). That one is the ability's input and is never read
 * as the assistant's reason, so every reader asks {@see self::is_albert_note()}
 * instead of looking for the key.
 *
 * @since 1.7.0
 */
final class NoteInput implements Hookable {

	/**
	 * The input's name.
	 *
	 * @var string
	 */
	public const NAME = 'note';

	/**
	 * Longest note, in characters.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 140;

	/**
	 * Abilities whose `note` is Albert's, by id.
	 *
	 * @var array<string, true>
	 */
	private static array $abilities = [];

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_filter( 'wp_ability_validate_input', [ self::class, 'validate' ], 10, 3 );
	}

	/**
	 * The schema of the input.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	public static function schema(): array {
		return [
			'type'        => 'string',
			'maxLength'   => self::MAX_LENGTH,
			'description' => 'Optional one-line reason, shown to the site owner',
		];
	}

	/**
	 * Record that an ability was given Albert's `note`.
	 *
	 * @param string $ability_id The ability.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function mark( string $ability_id ): void {
		self::$abilities[ strtolower( $ability_id ) ] = true;
	}

	/**
	 * Whether an ability's `note` input is Albert's.
	 *
	 * @param string $ability_id The ability.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public static function is_albert_note( string $ability_id ): bool {
		return isset( self::$abilities[ strtolower( $ability_id ) ] );
	}

	/**
	 * The assistant's note in an input, when the ability's `note` is Albert's.
	 *
	 * @param string $ability_id The ability.
	 * @param mixed  $input      Its input.
	 *
	 * @return mixed The note as sent, or null.
	 * @since 1.7.0
	 */
	public static function of( string $ability_id, $input ) {
		return is_array( $input ) && self::is_albert_note( $ability_id ) ? ( $input[ self::NAME ] ?? null ) : null;
	}

	/**
	 * An input without Albert's note; an ability's own `note` is kept.
	 *
	 * @param string               $ability_id The ability.
	 * @param array<string, mixed> $input      Its input.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	public static function without( string $ability_id, array $input ): array {
		if ( self::is_albert_note( $ability_id ) ) {
			unset( $input[ self::NAME ] );
		}

		return $input;
	}

	/**
	 * The `note` never counts towards what an ability requires: a call is
	 * checked again without it against the schema without it, so `{id, note}`
	 * is refused where `minProperties` or `anyOf` asks for a real change.
	 * Bound to `wp_ability_validate_input`, so a refusal is an input error.
	 *
	 * @param true|WP_Error $is_valid     The result so far.
	 * @param mixed         $input        The input.
	 * @param string        $ability_name The ability.
	 *
	 * @return true|WP_Error
	 * @since 1.7.0
	 */
	public static function validate( $is_valid, $input, $ability_name ) {
		if ( $is_valid !== true || ! is_array( $input ) || ! array_key_exists( self::NAME, $input ) || ! self::is_albert_note( (string) $ability_name ) ) {
			return $is_valid;
		}

		$ability = wp_get_ability( (string) $ability_name );
		$schema  = $ability !== null ? $ability->get_input_schema() : [];
		if ( $schema === [] ) {
			return $is_valid;
		}

		$schema['properties'] = (array) ( $schema['properties'] ?? [] );
		unset( $schema['properties'][ self::NAME ] );

		$valid = rest_validate_value_from_schema( self::without( (string) $ability_name, $input ), $schema, 'input' );
		if ( ! is_wp_error( $valid ) ) {
			return $is_valid;
		}

		return new WP_Error(
			'ability_invalid_input',
			sprintf(
				/* translators: 1: ability name, 2: why the input was refused. */
				__( 'Ability "%1$s" has invalid input. Reason: %2$s A note alone is not a change.', 'albert-ai-butler' ),
				(string) $ability_name,
				$valid->get_error_message()
			)
		);
	}
}
