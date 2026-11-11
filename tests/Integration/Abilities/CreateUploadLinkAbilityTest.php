<?php
/**
 * Integration tests for the CreateUploadLink ability.
 *
 * The generic ability-contract, execute/schema, input-validation, and
 * permission-failure suites already cover this ability via
 * ProvidesAbilities auto-discovery — this file covers the parts specific
 * to it: that execute() actually delegates to UploadLinkService and a
 * minted link is genuinely redeemable end to end.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Media\CreateUploadLink;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * CreateUploadLink ability integration tests.
 *
 * @covers \Albert\Abilities\WordPress\Media\CreateUploadLink
 */
class CreateUploadLinkAbilityTest extends TestCase {

	/**
	 * Run as administrator with all abilities enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// A link only redeems for somebody still allowed to connect.
		add_action( 'user_register', [ AllowedUsers::class, 'add' ] );

		Installer::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * A minted link is genuinely redeemable through the same service the
	 * REST controller uses — proves the ability isn't just shaping a schema.
	 *
	 * @return void
	 */
	public function test_minted_link_is_redeemable(): void {
		$result = ( new CreateUploadLink() )->execute( [] );

		$this->assertIsArray( $result );

		$service = new UploadLinkService();
		$context = $service->redeem_link( $result['upload_token'] );

		$this->assertIsArray( $context );
		$this->assertSame( get_current_user_id(), $context['user_id'] );
	}

	/**
	 * The caller gets the real token; observers get it masked.
	 *
	 * Albert's own logger writes a row an administrator can read months later,
	 * and add-ons capture the whole success payload, so a credential the token
	 * table deliberately stores only as a hash must not arrive in a log column
	 * in the clear. The assistant still needs it, so redaction happens on the
	 * copy handed to `after_execute`, not on the return value.
	 *
	 * @return void
	 */
	public function test_observers_never_see_the_raw_token(): void {
		$observed = [];

		add_action(
			'albert/abilities/after_execute',
			static function ( $ability_id, $args, $result ) use ( &$observed ): void {
				if ( $ability_id === 'albert/create-upload-link' ) {
					$observed = $result;
				}
			},
			10,
			4
		);

		$returned = ( new CreateUploadLink() )->guarded_execute( [] );

		$this->assertIsArray( $returned );
		$this->assertNotEmpty( $returned['upload_token'] );

		$this->assertSame( '[redacted]', $observed['upload_token'] );
		// The curl example embeds the same token a second time.
		$this->assertSame( '[redacted]', $observed['curl_example'] );

		// Masked, not dropped: a log should still record that a link was minted.
		$this->assertSame( $returned['expires_at'], $observed['expires_at'] );
		$this->assertStringNotContainsString( $returned['upload_token'], wp_json_encode( $observed ) );

		// And the real one still works, which is the whole point of redacting
		// the copy rather than the result.
		$this->assertIsArray( ( new UploadLinkService() )->redeem_link( $returned['upload_token'] ) );
	}

	/**
	 * A user without upload_files is denied at the ability's own permission
	 * check, before ever reaching the service.
	 *
	 * @return void
	 */
	public function test_check_permission_denies_without_upload_files(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = ( new CreateUploadLink() )->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
