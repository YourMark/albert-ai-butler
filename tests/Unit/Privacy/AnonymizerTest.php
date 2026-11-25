<?php
/**
 * Unit tests for the PII Anonymizer.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Privacy;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Privacy\Anonymizer;
use PHPUnit\Framework\TestCase;

/**
 * Anonymizer unit tests.
 *
 * @covers \Albert\Privacy\Anonymizer
 */
class AnonymizerTest extends TestCase {

	/**
	 * Reset the hook recorder before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_filter_returns'] = [];
	}

	/**
	 * A full customer payload is masked field-by-field.
	 *
	 * @return void
	 */
	public function test_customer_payload_is_masked(): void {
		$out = Anonymizer::anonymize(
			[
				'customer' => [
					'id'           => 42,
					'email'        => 'john@example.com',
					'first_name'   => 'John',
					'last_name'    => 'Doe',
					'display_name' => 'John Doe',
					'order_count'  => 3,
					'billing'      => [
						'first_name' => 'John',
						'last_name'  => 'Doe',
						'email'      => 'john@example.com',
						'phone'      => '0612345678',
						'address_1'  => 'Main Street 1',
						'address_2'  => 'Apt 4',
						'city'       => 'Amsterdam',
						'postcode'   => '1011',
						'country'    => 'NL',
					],
				],
			]
		);

		$customer = $out['customer'];
		$billing  = $customer['billing'];

		// Node with an id → names collapse to a reference.
		$this->assertSame( 42, $customer['id'] );
		$this->assertSame( 'Customer #42', $customer['first_name'] );
		$this->assertSame( 'Customer #42', $customer['last_name'] );
		$this->assertSame( 'Customer #42', $customer['display_name'] );
		$this->assertSame( 'j***@e***.com', $customer['email'] );
		$this->assertSame( 3, $customer['order_count'] );

		// Nested billing node has no id → initials fallback.
		$this->assertSame( 'J.', $billing['first_name'] );
		$this->assertSame( 'D.', $billing['last_name'] );
		$this->assertSame( 'j***@e***.com', $billing['email'] );
		$this->assertSame( '********78', $billing['phone'] );
		$this->assertSame( '', $billing['address_1'] );
		$this->assertSame( '', $billing['address_2'] );
		$this->assertSame( 'Amsterdam', $billing['city'] );
		$this->assertSame( '10**', $billing['postcode'] );
		$this->assertSame( 'NL', $billing['country'] );
	}

	/**
	 * WordPress user-account fields are masked: username/user_login collapse like
	 * a name, url is emptied, and the free-text description is redacted. These
	 * previously leaked from the user abilities.
	 *
	 * @return void
	 */
	public function test_user_account_fields_are_masked(): void {
		$out = Anonymizer::anonymize(
			[
				'user' => [
					'id'          => 12,
					'username'    => 'jdoe',
					'user_login'  => 'jdoe',
					'email'       => 'john@example.com',
					'url'         => 'https://johndoe.example',
					'description' => 'Long personal bio that may contain PII.',
				],
			],
			[ 'mask_context_names' => true ]
		);

		$user = $out['user'];
		// Node has an id → name-group keys collapse to a reference.
		$this->assertSame( 'Customer #12', $user['username'] );
		$this->assertSame( 'Customer #12', $user['user_login'] );
		$this->assertSame( 'j***@e***.com', $user['email'] );
		$this->assertSame( '', $user['url'] );
		$this->assertSame( '[redacted]', $user['description'] );
	}

	/**
	 * Product line-item names, SKUs and titles are never touched.
	 *
	 * @return void
	 */
	public function test_product_data_survives(): void {
		$out = Anonymizer::anonymize(
			[
				'order' => [
					'id'            => 100,
					'customer_id'   => 42,
					'items'         => [
						[
							'name'     => 'Blue T-Shirt',
							'sku'      => 'TS-001',
							'quantity' => 2,
						],
						[
							'name' => 'Red Hat',
							'sku'  => 'RH-9',
						],
					],
					'billing'       => [
						'first_name' => 'Jane',
						'last_name'  => 'Roe',
						'email'      => 'jane@acme.com',
					],
					'customer_note' => 'Please gift wrap',
				],
			]
		);

		$order = $out['order'];

		$this->assertSame( 'Blue T-Shirt', $order['items'][0]['name'] );
		$this->assertSame( 'TS-001', $order['items'][0]['sku'] );
		$this->assertSame( 'Red Hat', $order['items'][1]['name'] );
		$this->assertSame( 'RH-9', $order['items'][1]['sku'] );

		// Billing (a personal-data context) is still masked.
		$this->assertSame( 'J.', $order['billing']['first_name'] );
		$this->assertSame( 'j***@a***.com', $order['billing']['email'] );

		// Free-text note redacted.
		$this->assertSame( '[redacted]', $order['customer_note'] );
	}

	/**
	 * A numeric-indexed list of records is masked per record.
	 *
	 * @return void
	 */
	public function test_array_of_records_is_masked(): void {
		$out = Anonymizer::anonymize(
			[
				'customers' => [
					[
						'id'           => 7,
						'email'        => 'a@b.com',
						'first_name'   => 'Alice',
						'last_name'    => 'Anderson',
						'display_name' => 'Alice Anderson',
					],
					[
						'id'         => 8,
						'email'      => 'c@d.com',
						'first_name' => 'Bob',
						'last_name'  => 'Brown',
					],
				],
			]
		);

		$this->assertSame( 'Customer #7', $out['customers'][0]['first_name'] );
		$this->assertSame( 'Customer #7', $out['customers'][0]['display_name'] );
		$this->assertSame( 'a***@b***.com', $out['customers'][0]['email'] );
		$this->assertSame( 'Customer #8', $out['customers'][1]['first_name'] );
		$this->assertSame( 'c***@d***.com', $out['customers'][1]['email'] );
	}

	/**
	 * Names without an id in the node fall back to initials.
	 *
	 * @return void
	 */
	public function test_name_initials_fallback(): void {
		$out = Anonymizer::anonymize(
			[
				'billing' => [
					'first_name' => 'Wolfgang',
					'last_name'  => 'von Habsburg',
				],
			]
		);

		$this->assertSame( 'W.', $out['billing']['first_name'] );
		$this->assertSame( 'V.H.', $out['billing']['last_name'] );
	}

	/**
	 * The ambiguous `name` key is masked only inside a personal-data context.
	 *
	 * @return void
	 */
	public function test_context_name_key(): void {
		$out = Anonymizer::anonymize(
			[
				'shipping' => [ 'name' => 'John Doe' ],
				'product'  => [ 'name' => 'Blue T-Shirt' ],
			]
		);

		$this->assertSame( 'J.D.', $out['shipping']['name'] );
		$this->assertSame( 'Blue T-Shirt', $out['product']['name'] );
	}

	/**
	 * Free alone strips NO payment/card keys: the default `payment_keys` filter
	 * is empty, so gateway keys survive anonymisation (generic PII is the free
	 * basis; payment protection is contributed by add-ons).
	 *
	 * @return void
	 */
	public function test_payment_keys_survive_without_filter(): void {
		$out = Anonymizer::anonymize(
			[
				'order' => [
					'id'                   => 5,
					'card_number'          => '4242424242424242',
					'cvv'                  => '123',
					'_stripe_source_id'    => 'src_abc',
					'payment_method_title' => 'Visa',
					'customer_ip_address'  => '203.0.113.7',
				],
			]
		);

		$order = $out['order'];
		// No payment matcher registered → payment/card keys are left in place.
		$this->assertSame( '4242424242424242', $order['card_number'] );
		$this->assertSame( '123', $order['cvv'] );
		$this->assertSame( 'src_abc', $order['_stripe_source_id'] );
		$this->assertSame( 'Visa', $order['payment_method_title'] );
		// customer_ip_address is generic PII (strip group), so it is still removed.
		$this->assertArrayNotHasKey( 'customer_ip_address', $order );
	}

	/**
	 * With the `payment_keys` filter populated, both an exact key and a prefix
	 * match are hard-removed by anonymize() at any depth.
	 *
	 * @return void
	 */
	public function test_payment_keys_removed_when_filter_registered(): void {
		$GLOBALS['albert_test_filter_returns']['albert/privacy/payment_keys'] = [
			'keys'     => [ 'card_number', 'cvv' ],
			'prefixes' => [ '_stripe' ],
		];

		$out = Anonymizer::anonymize(
			[
				'order' => [
					'id'                   => 5,
					'card_number'          => '4242424242424242',
					'cvv'                  => '123',
					'_stripe_source_id'    => 'src_abc',
					'payment_method_title' => 'Visa',
				],
			]
		);

		$order = $out['order'];
		$this->assertArrayNotHasKey( 'card_number', $order );      // exact key.
		$this->assertArrayNotHasKey( 'cvv', $order );              // exact key.
		$this->assertArrayNotHasKey( '_stripe_source_id', $order ); // prefix match.
		$this->assertSame( 'Visa', $order['payment_method_title'] );
	}

	/**
	 * The strip_payment_data() path removes registered payment keys (only) and
	 * leaves PII intact — the reveal/off path, proving payment is always stripped
	 * once a matcher is registered even when personal data is deliberately shown.
	 *
	 * @return void
	 */
	public function test_strip_payment_data_respects_filter(): void {
		$GLOBALS['albert_test_filter_returns']['albert/privacy/payment_keys'] = [
			'keys'     => [ 'card_number' ],
			'prefixes' => [ '_stripe' ],
		];

		$out = Anonymizer::strip_payment_data(
			[
				'customer' => [
					'first_name'        => 'John',
					'email'             => 'john@example.com',
					'card_number'       => '4242424242424242',
					'_stripe_charge_id' => 'ch_123',
				],
			]
		);

		// PII is untouched by strip_payment_data.
		$this->assertSame( 'John', $out['customer']['first_name'] );
		$this->assertSame( 'john@example.com', $out['customer']['email'] );
		// Registered payment keys/prefixes are removed.
		$this->assertArrayNotHasKey( 'card_number', $out['customer'] );
		$this->assertArrayNotHasKey( '_stripe_charge_id', $out['customer'] );
	}

	/**
	 * Without a registered matcher, strip_payment_data removes nothing (and keeps
	 * PII intact) — Free's empty default.
	 *
	 * @return void
	 */
	public function test_strip_payment_data_keeps_everything_without_filter(): void {
		$out = Anonymizer::strip_payment_data(
			[
				'customer' => [
					'first_name'  => 'John',
					'email'       => 'john@example.com',
					'card_number' => '4242424242424242',
				],
			]
		);

		$this->assertSame( 'John', $out['customer']['first_name'] );
		$this->assertSame( 'john@example.com', $out['customer']['email'] );
		$this->assertSame( '4242424242424242', $out['customer']['card_number'] );
	}

	/**
	 * Anonymisation is idempotent — re-masking is a no-op.
	 *
	 * @return void
	 */
	public function test_idempotent(): void {
		$input = [
			'customer' => [
				'id'         => 42,
				'email'      => 'john@example.com',
				'first_name' => 'John',
				'billing'    => [
					'first_name' => 'Jane',
					'phone'      => '0612345678',
					'postcode'   => '1011',
					'address_1'  => 'Main Street 1',
				],
			],
		];

		$once  = Anonymizer::anonymize( $input );
		$twice = Anonymizer::anonymize( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * Email masking follows the `j***@e***.com` shape.
	 *
	 * @return void
	 */
	public function test_email_masking_shape(): void {
		$out = Anonymizer::anonymize( [ 'email' => 'john.doe@sub.example.co.uk' ] );
		$this->assertSame( 'j***@s***.uk', $out['email'] );
	}

	/**
	 * Without the option, a top-level `name` (outside a person context) is
	 * treated as ambiguous and left untouched so product data survives.
	 *
	 * @return void
	 */
	public function test_top_level_name_untouched_by_default(): void {
		$out = Anonymizer::anonymize( [ 'name' => 'Blue T-Shirt' ] );
		$this->assertSame( 'Blue T-Shirt', $out['name'] );
	}

	/**
	 * With `mask_context_names`, a top-level `name` is masked everywhere — for
	 * person-record results that carry no product data.
	 *
	 * @return void
	 */
	public function test_mask_context_names_masks_top_level_name(): void {
		$out = Anonymizer::anonymize(
			[ 'name' => 'John Doe' ],
			[ 'mask_context_names' => true ]
		);
		$this->assertSame( 'J.D.', $out['name'] );
	}

	/**
	 * A site record is not a person: its name, tagline and address stay, its admin email is masked.
	 *
	 * @return void
	 */
	public function test_site_info_keeps_its_name_and_url(): void {
		$out = Anonymizer::anonymize(
			[
				'name'        => 'My Shop',
				'description' => 'Just another site',
				'url'         => 'https://shop.example',
				'admin_email' => 'owner@shop.example',
			]
		);

		$this->assertSame( 'My Shop', $out['name'] );
		$this->assertSame( 'Just another site', $out['description'] );
		$this->assertSame( 'https://shop.example', $out['url'] );
		$this->assertSame( 'o***@s***.example', $out['admin_email'] );
	}

	/**
	 * A record carrying a person's own key is a person, wherever it sits.
	 *
	 * @return void
	 */
	public function test_person_record_is_recognised_without_a_context_key(): void {
		$out = Anonymizer::anonymize(
			[
				'members' => [
					[
						'id'          => 5,
						'user_login'  => 'jdoe',
						'name'        => 'John Doe',
						'slug'        => 'jdoe',
						'link'        => 'https://example.com/author/jdoe',
						'description' => 'My bio',
						'avatar_urls' => [ '24' => 'https://gravatar.com/avatar/abc' ],
						'roles'       => [ 'editor' ],
					],
				],
			]
		);

		$member = $out['members'][0];
		$this->assertSame( 'Customer #5', $member['name'] );
		$this->assertSame( 'Customer #5', $member['slug'] );
		$this->assertSame( '', $member['link'] );
		$this->assertSame( '[redacted]', $member['description'] );
		$this->assertArrayNotHasKey( 'avatar_urls', $member );
		$this->assertSame( [ 'editor' ], $member['roles'] );
	}

	/**
	 * Keys match case-insensitively, and by suffix when no category names them.
	 *
	 * @return void
	 */
	public function test_keys_match_by_case_and_suffix(): void {
		$out = Anonymizer::anonymize(
			[
				'comment_author_IP'  => '203.0.113.9',
				'last_login_ip'      => '203.0.113.9',
				'notification_email' => 'jane@example.com',
				'send_email'         => true,
				'emergency_phone'    => '0612345678',
				'User_Email'         => 'jane@example.com',
			]
		);

		$this->assertArrayNotHasKey( 'comment_author_IP', $out );
		$this->assertArrayNotHasKey( 'last_login_ip', $out );
		$this->assertSame( 'j***@e***.com', $out['notification_email'] );
		$this->assertTrue( $out['send_email'] );
		$this->assertSame( '********78', $out['emergency_phone'] );
		$this->assertSame( 'j***@e***.com', $out['User_Email'] );
	}

	/**
	 * A value that is wholly an email address is masked under any key; an image name is not.
	 *
	 * @return void
	 */
	public function test_email_values_are_masked_under_any_key(): void {
		$out = Anonymizer::anonymize(
			[
				'recipient' => 'jane@example.com',
				'list'      => [ 'a@b.org', 'kept' ],
				'retina'    => 'logo@2x.png',
				'sentence'  => 'Mail jane@example.com today',
			]
		);

		$this->assertSame( 'j***@e***.com', $out['recipient'] );
		$this->assertSame( [ 'a***@b***.org', 'kept' ], $out['list'] );
		$this->assertSame( 'logo@2x.png', $out['retina'] );
		$this->assertSame( 'Mail jane@example.com today', $out['sentence'] );
	}

	/**
	 * Credentials and session data are removed.
	 *
	 * @return void
	 */
	public function test_credentials_are_removed(): void {
		$out = Anonymizer::anonymize(
			[
				'user_pass'           => '$P$hash',
				'user_activation_key' => 'key',
				'session_tokens'      => [ 'abc' => [] ],
				'id'                  => 3,
			]
		);

		$this->assertSame( [ 'id' => 3 ], $out );
	}
}
