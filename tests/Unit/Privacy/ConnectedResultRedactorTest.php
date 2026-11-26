<?php
/**
 * Unit tests for ConnectedResultRedactor.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Privacy;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\OAuth\Server\ConnectionContext;
use Albert\Privacy\ConnectedResultRedactor;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * ConnectedResultRedactor unit tests.
 *
 * @covers \Albert\Privacy\ConnectedResultRedactor
 * @covers \Albert\Privacy\PiiPolicy::redact_value
 */
class ConnectedResultRedactorTest extends TestCase {

	/**
	 * Another plugin's ability.
	 */
	private const ABILITY = 'acme/get-members';

	/**
	 * The redactor under test.
	 *
	 * @var ConnectedResultRedactor
	 */
	private ConnectedResultRedactor $redactor;

	/**
	 * Open a connection on Balanced, as a signed-in user.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_options']        = [];
		$GLOBALS['albert_test_filter_returns'] = [];
		$GLOBALS['albert_test_caps']           = [ 'read' ];
		$GLOBALS['albert_test_abilities']      = [];
		ConnectionContext::set( 'client-1' );

		$this->redactor = new ConnectedResultRedactor();
	}

	/**
	 * Close the connection.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ConnectionContext::reset();
		parent::tearDown();
	}

	/**
	 * A member record as another plugin might return it.
	 *
	 * @return array<string, mixed>
	 */
	private function members(): array {
		return [
			'members' => [
				[
					'ID'         => 7,
					'user_email' => 'jane@example.com',
					'name'       => 'Jane Doe',
					'last_ip'    => '203.0.113.9',
					'plan'       => 'gold',
				],
			],
		];
	}

	/**
	 * An ability whose execute() runs the redactor's hooks around the callback, as core does.
	 *
	 * @param string   $name     The ability.
	 * @param callable $callback The execute callback, given the input.
	 *
	 * @return \WP_Ability
	 */
	private function ability( string $name, callable $callback ): \WP_Ability {
		$ability         = new \WP_Ability( $name );
		$ability->runner = function ( \WP_Ability $called, mixed $input ) use ( $callback ): mixed {
			$this->redactor->enter( $called->get_name() );

			return $this->redactor->redact( $callback( $input ), $called->get_name(), $input );
		};

		return $ability;
	}

	/**
	 * Run a call through the redactor as core would.
	 *
	 * @param string $ability The ability.
	 * @param mixed  $result  The callback's result.
	 * @param mixed  $input   The input.
	 *
	 * @return mixed
	 */
	private function call( string $ability, mixed $result, mixed $input = [] ): mixed {
		return $this->ability( $ability, static fn() => $result )->execute( $input );
	}

	/**
	 * Another plugin's ability is redacted on a connected call.
	 *
	 * @return void
	 */
	public function test_redacts_another_plugins_ability(): void {
		$member = $this->call( self::ABILITY, $this->members() )['members'][0];

		$this->assertSame( 'j***@e***.com', $member['user_email'] );
		$this->assertSame( 'J.D.', $member['name'] );
		$this->assertArrayNotHasKey( 'last_ip', $member );
		$this->assertSame( 'gold', $member['plan'] );
	}

	/**
	 * A call without a connection is the site's own and stays untouched.
	 *
	 * @return void
	 */
	public function test_leaves_calls_without_a_connection(): void {
		ConnectionContext::reset();

		$this->assertSame( $this->members(), $this->call( self::ABILITY, $this->members() ) );
	}

	/**
	 * The meta-tool's result is left for its inner call to have redacted.
	 *
	 * @return void
	 */
	public function test_leaves_the_transport_ability(): void {
		$this->assertSame( $this->members(), $this->call( 'mcp-adapter/execute-ability', $this->members() ) );
	}

	/**
	 * An ability called from inside another gets the real data; the outer result is redacted.
	 *
	 * @return void
	 */
	public function test_redacts_only_the_outermost_call(): void {
		$inner = $this->ability( 'core/get-user-info', static fn() => [ 'email' => 'jane@example.com' ] );
		$seen  = null;
		$outer = $this->ability(
			self::ABILITY,
			static function () use ( $inner, &$seen ): array {
				$seen = $inner->execute( [] );

				return [ 'email' => $seen['email'] ];
			}
		)->execute( [] );

		$this->assertSame( 'jane@example.com', $seen['email'] );
		$this->assertSame( 'j***@e***.com', $outer['email'] );
	}

	/**
	 * A callback that throws leaves no open call behind to exempt later calls from redaction.
	 *
	 * @return void
	 */
	public function test_a_throwing_call_does_not_leave_later_calls_unredacted(): void {
		$broken = $this->ability(
			'acme/broken',
			static function (): never {
				throw new \RuntimeException( 'Callback failed.' );
			}
		);

		try {
			$broken->execute( [] );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$next = $this->call( self::ABILITY, $this->members() );

		$outer = $this->ability(
			self::ABILITY,
			function () use ( $broken ): array {
				try {
					$broken->execute( [] );
				} catch ( \RuntimeException $e ) {
					unset( $e );
				}

				return $this->members();
			}
		)->execute( [] );

		$this->assertSame( 'j***@e***.com', $next['members'][0]['user_email'] );
		$this->assertSame( 'j***@e***.com', $outer['members'][0]['user_email'] );
	}

	/**
	 * On Balanced, whoever may run the ability reveals by asking.
	 *
	 * @return void
	 */
	public function test_balanced_reveals_on_request(): void {
		$masked   = $this->call( self::ABILITY, $this->members() );
		$revealed = $this->call( self::ABILITY, $this->members(), [ 'reveal_personal_data' => true ] );

		$this->assertSame( 'j***@e***.com', $masked['members'][0]['user_email'] );
		$this->assertSame( 'jane@example.com', $revealed['members'][0]['user_email'] );
	}

	/**
	 * A site that raises the reveal capability stops a reveal by a user without it.
	 *
	 * @return void
	 */
	public function test_a_stricter_reveal_capability_stops_a_reveal(): void {
		$GLOBALS['albert_test_filter_returns']['albert/privacy/reveal_capability'] = 'manage_options';
		$input = [ 'reveal_personal_data' => true ];

		$GLOBALS['albert_test_caps'] = [ 'read', 'list_users' ];
		$denied                      = $this->call( self::ABILITY, $this->members(), $input );
		$GLOBALS['albert_test_caps'] = [ 'read', 'manage_options' ];
		$allowed                     = $this->call( self::ABILITY, $this->members(), $input );

		$this->assertSame( 'j***@e***.com', $denied['members'][0]['user_email'] );
		$this->assertSame( 'jane@example.com', $allowed['members'][0]['user_email'] );
	}

	/**
	 * A result with nothing personal in it comes back as it was, object or not.
	 *
	 * @return void
	 */
	public function test_returns_untouched_results_as_they_were(): void {
		$empty   = new \stdClass();
		$product = [
			'name'        => 'Blue mug',
			'description' => 'A mug.',
			'url'         => 'https://shop.example/mug',
			'content'     => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
		];

		$this->assertSame( $empty, $this->call( self::ABILITY, $empty ) );
		$this->assertSame( $product, $this->call( self::ABILITY, $product ) );
	}

	/**
	 * A result whose validation never ran does not judge the next call.
	 *
	 * @return void
	 */
	public function test_stale_result_is_dropped_on_the_next_call(): void {
		$this->call( 'acme/get-contact', [ 'email' => 'jane@example.com' ] );

		$verdict = new WP_Error( 'ability_invalid_output', 'Real fault.' );
		$this->call( 'acme/get-contact', [ 'plan' => 'gold' ] );

		$this->assertSame( $verdict, $this->redactor->validate_unredacted( $verdict, [ 'plan' => 'gold' ], 'acme/get-contact' ) );
	}

	/**
	 * Strict mode never reveals, whatever the caller holds.
	 *
	 * @return void
	 */
	public function test_strict_never_reveals(): void {
		$GLOBALS['albert_test_options']['albert_privacy_mode'] = 'strict';

		$result = $this->call( self::ABILITY, $this->members(), [ 'reveal_personal_data' => true ] );

		$this->assertSame( 'j***@e***.com', $result['members'][0]['user_email'] );
	}

	/**
	 * Off passes personal data through.
	 *
	 * @return void
	 */
	public function test_off_passes_through(): void {
		$GLOBALS['albert_test_options']['albert_privacy_mode'] = 'off';

		$this->assertSame( $this->members(), $this->call( self::ABILITY, $this->members() ) );
	}

	/**
	 * An object result is redacted as the JSON it becomes, a bare email string is masked.
	 *
	 * @return void
	 */
	public function test_redacts_objects_and_bare_emails(): void {
		$object = (object) [
			'email' => 'jane@example.com',
			'count' => 3,
		];

		$this->assertSame(
			[
				'email' => 'j***@e***.com',
				'count' => 3,
			],
			$this->call( self::ABILITY, $object )
		);
		$this->assertSame( 'j***@e***.com', $this->call( self::ABILITY, 'jane@example.com' ) );
	}

	/**
	 * Errors pass through untouched.
	 *
	 * @return void
	 */
	public function test_leaves_errors(): void {
		$error = new WP_Error( 'nope', 'jane@example.com was not found' );

		$this->assertSame( $error, $this->call( self::ABILITY, $error ) );
	}

	/**
	 * Output validation judges the unredacted result.
	 *
	 * @return void
	 */
	public function test_output_validation_judges_the_real_result(): void {
		$GLOBALS['albert_test_abilities'][] = new class() {
			/**
			 * Name.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return 'acme/get-contact';
			}

			/**
			 * Output schema.
			 *
			 * @return array<string, mixed>
			 */
			public function get_output_schema(): array {
				return [ 'type' => 'object' ];
			}
		};
		$masked                             = new WP_Error( 'ability_invalid_output', 'Masked email is not an email.' );

		$redacted = $this->call( 'acme/get-contact', [ 'email' => 'jane@example.com' ] );
		$this->assertTrue( $this->redactor->validate_unredacted( $masked, $redacted, 'acme/get-contact' ) );

		// A real fault still fails: a string where the schema wants an object.
		$redacted = $this->call( 'acme/get-contact', 'jane@example.com' );
		$verdict  = $this->redactor->validate_unredacted( true, $redacted, 'acme/get-contact' );
		$this->assertInstanceOf( WP_Error::class, $verdict );
		$this->assertSame( 'ability_invalid_output', $verdict->get_error_code() );
	}

	/**
	 * Output validation of a result that was not redacted is left to core.
	 *
	 * @return void
	 */
	public function test_output_validation_untouched_without_redaction(): void {
		$verdict = new WP_Error( 'ability_invalid_output', 'Real fault.' );

		$this->call( self::ABILITY, [ 'plan' => 'gold' ] );

		$this->assertSame( $verdict, $this->redactor->validate_unredacted( $verdict, [ 'plan' => 'gold' ], self::ABILITY ) );
	}
}
