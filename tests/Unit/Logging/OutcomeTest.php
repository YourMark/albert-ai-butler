<?php
/**
 * Unit tests for the Outcome classifier.
 *
 * Verifies the three-way classification, the stage-beats-code rule, the
 * `_not_found` and `_permission_denied` conventions, the API-surface codes
 * held back from the not-found rule, the codes deliberately left as errors,
 * and the `albert/logging/outcome` filter.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Logging;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Logging\Outcome;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Outcome unit tests.
 *
 * @covers \Albert\Logging\Outcome
 */
class OutcomeTest extends TestCase {

	/**
	 * Reset hook state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']            = [];
		$GLOBALS['albert_test_filter_returns']   = [];
		$GLOBALS['albert_test_filter_callbacks'] = [];
	}

	// ─── success ─────────────────────────────────────────────────────

	/**
	 * A non-error result is a success.
	 *
	 * @return void
	 */
	public function test_array_result_is_success(): void {
		$this->assertSame( 'success', Outcome::classify( [ 'posts' => [] ], 'albert/find-posts' ) );
	}

	/**
	 * A success is never handed to the filter — there is nothing to reclassify.
	 *
	 * @return void
	 */
	public function test_success_does_not_fire_the_filter(): void {
		Outcome::classify( [ 'ok' => true ], 'albert/find-posts' );

		$this->assertSame( [], $this->outcome_filter_calls() );
	}

	/**
	 * A truthful negative answer is a success, not a status of its own.
	 *
	 * `ViewTerm( 999 )` was asked whether a term exists, looked, and answered.
	 * An empty answer is a legitimate answer, and the run went fine.
	 *
	 * @param string $code An error code that means "it is not there".
	 *
	 * @dataProvider provide_not_found_codes
	 * @return void
	 */
	public function test_not_found_codes_are_successes( string $code ): void {
		$error = new WP_Error( $code, 'Not there.' );

		$this->assertSame( 'success', Outcome::classify( $error, 'albert/view-term' ) );
	}

	/**
	 * Codes that classify as a truthful negative answer.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_not_found_codes(): array {
		return [
			'term'               => [ 'term_not_found' ],
			'post'               => [ 'post_not_found' ],
			'product'            => [ 'product_not_found' ],
			'session'            => [ 'session_not_found' ],
			'third party suffix' => [ 'acme_widget_not_found' ],
			'bare not_found'     => [ 'not_found' ],
			'invalid_post'       => [ 'invalid_post' ],
			'invalid_attachment' => [ 'invalid_attachment' ],
		];
	}

	// ─── warning ─────────────────────────────────────────────────────

	/**
	 * A request the site refused on purpose is a warning, not a failure.
	 *
	 * Painting a correctly configured permission system red every time it does
	 * its job is the misinformation this classifier exists to remove.
	 *
	 * @param string $code An error code that means "you may not".
	 *
	 * @dataProvider provide_policy_codes
	 * @return void
	 */
	public function test_policy_codes_are_warnings( string $code ): void {
		$error = new WP_Error( $code, 'Denied.' );

		$this->assertSame( 'warning', Outcome::classify( $error, 'albert/delete-post' ) );
	}

	/**
	 * Codes that mean the site said no on purpose.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_policy_codes(): array {
		return [
			'albert capability'   => [ 'ability_permission_denied' ],
			'premium enforcer'    => [ 'albert_permission_denied' ],
			'third party suffix'  => [ 'acme_permission_denied' ],
			'core generic'        => [ 'ability_invalid_permissions' ],
			'switched off'        => [ 'ability_disabled' ],
			'upload link revoked' => [ 'capability_revoked' ],
			'core rest'           => [ 'rest_forbidden' ],
			'albert per-object'   => [ 'forbidden' ],
		];
	}

	/**
	 * The `permission` stage wins over the error code, always.
	 *
	 * The stage is the robust signal: a writer that watched the call stop in
	 * the permission check knows more than any spelling convention. An add-on
	 * whose permission callback returns `acme_nope` still classifies correctly.
	 *
	 * @return void
	 */
	public function test_permission_stage_beats_an_unrecognised_code(): void {
		$error = new WP_Error( 'acme_nope', 'Not allowed.' );

		$this->assertSame( 'warning', Outcome::for_error( $error, 'acme/search', 'permission' ) );
	}

	/**
	 * The `permission` stage wins over the `_not_found` rule too.
	 *
	 * @return void
	 */
	public function test_permission_stage_beats_the_not_found_rule(): void {
		$error = new WP_Error( 'term_not_found', 'No such term.' );

		$this->assertSame( 'warning', Outcome::for_error( $error, 'albert/view-term', 'permission' ) );
	}

	/**
	 * Any other stage leaves the code rules in charge.
	 *
	 * Only `permission` names a policy block. `execute` is where a genuine
	 * fault lands, and `short_circuit` covers more than policy, so neither may
	 * promote a row to `warning` on its own.
	 *
	 * @param string $stage A failure stage that is not `permission`.
	 *
	 * @dataProvider provide_non_permission_stages
	 * @return void
	 */
	public function test_other_stages_do_not_force_a_warning( string $stage ): void {
		$error = new WP_Error( 'upload_failed', 'Disk full.' );

		$this->assertSame( 'error', Outcome::for_error( $error, 'albert/upload-media', $stage ) );
	}

	/**
	 * Stages that carry no policy meaning of their own.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_non_permission_stages(): array {
		return [
			'short circuit' => [ 'short_circuit' ],
			'input'         => [ 'input' ],
			'execute'       => [ 'execute' ],
			'output'        => [ 'output' ],
		];
	}

	// ─── error ───────────────────────────────────────────────────────

	/**
	 * Faults and malformed requests stay errors.
	 *
	 * `token_already_used` and `link_already_used` are the interesting entries:
	 * they read close to "nothing to do", but their own message says "invalid
	 * **or** has already been used", so the benign reading is not the only one
	 * and silencing them would silence a real rejection too.
	 *
	 * @param string $code An error code that means something broke.
	 *
	 * @dataProvider provide_error_codes
	 * @return void
	 */
	public function test_fault_codes_are_errors( string $code ): void {
		$error = new WP_Error( $code, 'Nope.' );

		$this->assertSame( 'error', Outcome::classify( $error, 'albert/create-post' ) );
	}

	/**
	 * Codes that must keep shouting.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_error_codes(): array {
		return [
			'input'               => [ 'ability_invalid_input' ],
			'ambiguous token'     => [ 'token_already_used' ],
			'ambiguous link'      => [ 'link_already_used' ],
			'expired link'        => [ 'link_expired' ],
			'malformed taxonomy'  => [ 'invalid_taxonomy' ],
			'upload failure'      => [ 'upload_failed' ],
			'rest invalid id'     => [ 'rest_post_invalid_id' ],
			'not_found substring' => [ 'not_found_handler_broken' ],
		];
	}

	/**
	 * An API-surface miss is a client bug, not a truthful negative answer.
	 *
	 * Abilities, tools and skills are enumerated to the assistant before it
	 * calls anything, so naming one that is not on the list means the caller
	 * asked for something that was never advertised.
	 *
	 * @param string $code An error code naming something that was never advertised.
	 *
	 * @dataProvider provide_api_surface_codes
	 * @return void
	 */
	public function test_api_surface_codes_are_errors_despite_the_suffix( string $code ): void {
		$error = new WP_Error( $code, 'No such thing.' );

		$this->assertFalse( Outcome::is_not_found_code( $code ) );
		$this->assertSame( 'error', Outcome::classify( $error, 'albert/run-ability' ) );
	}

	/**
	 * The `*_not_found` codes held back from the suffix rule.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_api_surface_codes(): array {
		return [
			'free ability'    => [ 'albert_ability_not_found' ],
			'premium ability' => [ 'albert_premium_ability_not_found' ],
			'bare ability'    => [ 'ability_not_found' ],
			'mcp tool'        => [ 'tool_not_found' ],
			'skill'           => [ 'skill_not_found' ],
		];
	}

	/**
	 * An error carrying no code at all is an error.
	 *
	 * @return void
	 */
	public function test_empty_code_is_an_error(): void {
		$this->assertFalse( Outcome::is_not_found_code( '' ) );
		$this->assertFalse( Outcome::is_policy_code( '' ) );
		$this->assertSame( 'error', Outcome::for_error( new WP_Error(), 'albert/create-post' ) );
	}

	// ─── the filter ──────────────────────────────────────────────────

	/**
	 * The filter can promote an unrecognised code to a success.
	 *
	 * This is the escape hatch for third-party abilities, which have no reason
	 * to follow Albert's `_not_found` convention.
	 *
	 * @return void
	 */
	public function test_filter_can_turn_an_error_into_a_success(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/outcome'] = 'success';

		$error = new WP_Error( 'acme_nothing_matched', 'No rows.' );

		$this->assertSame( 'success', Outcome::classify( $error, 'acme/search' ) );
	}

	/**
	 * The filter can promote an unrecognised refusal to a warning.
	 *
	 * @return void
	 */
	public function test_filter_can_turn_an_error_into_a_warning(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/outcome'] = 'warning';

		$error = new WP_Error( 'acme_not_licensed', 'Your plan does not include this.' );

		$this->assertSame( 'warning', Outcome::classify( $error, 'acme/search' ) );
	}

	/**
	 * The filter can demote a `_not_found` code back to an error.
	 *
	 * @return void
	 */
	public function test_filter_can_turn_a_success_into_an_error(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/outcome'] = 'error';

		$error = new WP_Error( 'acme_index_not_found', 'The search index is missing.' );

		$this->assertSame( 'error', Outcome::classify( $error, 'acme/search' ) );
	}

	/**
	 * The filter receives the computed status, the ability name and the error.
	 *
	 * The stage is deliberately not a fourth argument: the signature is public
	 * API and adding to it would break every registered callback.
	 *
	 * @return void
	 */
	public function test_filter_receives_the_documented_arguments(): void {
		$error = new WP_Error( 'term_not_found', 'No such term.' );

		Outcome::classify( $error, 'albert/view-term' );

		$calls = $this->outcome_filter_calls();

		$this->assertCount( 1, $calls );
		$this->assertCount( 3, $calls[0]['args'] );
		$this->assertSame( 'success', $calls[0]['args'][0] );
		$this->assertSame( 'albert/view-term', $calls[0]['args'][1] );
		$this->assertSame( $error, $calls[0]['args'][2] );
	}

	/**
	 * A value outside the three known statuses is ignored.
	 *
	 * A filter that returns junk must not be able to write junk into the
	 * `status` column, where every downstream screen would fail to match it.
	 *
	 * @return void
	 */
	public function test_filter_returning_an_unknown_value_is_ignored(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/outcome'] = 'no_match';

		$error = new WP_Error( 'term_not_found', 'No such term.' );

		$this->assertSame( 'success', Outcome::classify( $error, 'albert/view-term' ) );
	}

	/**
	 * Every recorded call to the outcome filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function outcome_filter_calls(): array {
		return array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $hook ): bool => $hook['hook'] === 'albert/logging/outcome'
			)
		);
	}
}
