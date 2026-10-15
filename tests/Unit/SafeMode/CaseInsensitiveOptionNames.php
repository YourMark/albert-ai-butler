<?php
/**
 * An option-name matcher for unit tests, with no database.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

use Albert\SafeMode\OptionNames;
use RuntimeException;

/**
 * Matches ignoring ASCII case, or throws when told to, so a unit test can pin
 * what its caller does with the answer. The real collation is tested against
 * MySQL in the integration suite.
 */
class CaseInsensitiveOptionNames extends OptionNames {

	/**
	 * Whether every lookup fails, as when the database cannot answer.
	 *
	 * @var bool
	 */
	public bool $fail = false;

	/**
	 * The candidate equal to the name ignoring ASCII case.
	 *
	 * @param string       $name       Option name.
	 * @param list<string> $candidates Names to compare against.
	 *
	 * @return string|null
	 * @throws RuntimeException When told to fail.
	 */
	public function find( string $name, array $candidates ): ?string {
		if ( $this->fail ) {
			throw new RuntimeException( 'No database.' );
		}

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && strtolower( $candidate ) === strtolower( $name ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
