<?php
/**
 * Integration tests for UploadLinkService.
 *
 * Covers every acceptance criterion in docs/features/32-media-uploads.md:
 * mint -> redeem once -> second redemption fails; expiry; content-sniffed
 * rejection of a renamed file, independent of unfiltered_upload; the MIME
 * allowlist reflecting the issuing user's own capabilities and shrinking on
 * a role downgrade; and a successful upload landing as a normal media
 * library item with thumbnails.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Media\UploadLinks;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * UploadLinkService integration tests.
 *
 * @covers \Albert\Media\UploadLinks\UploadLinkService
 */
class UploadLinkServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var UploadLinkService
	 */
	private UploadLinkService $service;

	/**
	 * An administrator, current user for most tests.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Reset state before each test.
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

		$this->service  = new UploadLinkService();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Lift `wp_max_upload_size()` out of the way for a test that is about
	 * default/option/filter precedence rather than the server ceiling.
	 *
	 * A stock PHP ships `upload_max_filesize = 2M`, which is below every
	 * value the precedence tests deal in, so without this they assert the
	 * ceiling instead of the precedence they name — passing on a dev machine
	 * with a generous php.ini and failing on CI. `wp_max_upload_size()` runs
	 * its result through `upload_size_limit`, so raising it needs no ini
	 * change. The ceiling itself is covered separately by
	 * {@see self::test_default_max_bytes_is_still_capped_by_server_ceiling()}.
	 *
	 * @return void
	 */
	private function lift_server_upload_ceiling(): void {
		// Above MAX_SETTABLE_MB, so nothing this class can express hits it.
		add_filter(
			'upload_size_limit',
			static fn (): int => ( UploadLinkService::MAX_SETTABLE_MB + 1 ) * UploadLinkService::BYTES_PER_MB
		);
	}

	/**
	 * Path to a real fixture JPEG from the WP test suite, or skip.
	 *
	 * @return string
	 */
	private function jpg_fixture(): string {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined — WP test suite fixtures unavailable.' );
		}

		return DIR_TESTDATA . '/images/sugarloaf-mountain.jpg';
	}

	// ─── mint() ─────────────────────────────────────────────────────

	/**
	 * Mint() returns a complete, usable link.
	 *
	 * @return void
	 */
	public function test_mint_returns_link_fields(): void {
		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertIsArray( $link );
		$this->assertNotEmpty( $link['upload_token'] );
		$this->assertSame( UploadLinkService::TOKEN_HEADER, $link['token_header'] );
		$this->assertGreaterThan( 0, $link['max_bytes'] );
		$this->assertNotEmpty( $link['accepted_types'] );
		$this->assertStringContainsString( $link['upload_token'], $link['curl_example'] );
		$this->assertStringContainsString( UploadLinkService::TOKEN_HEADER, $link['curl_example'] );
	}

	/**
	 * Narrows accepted_types to a caller's request.
	 *
	 * @return void
	 */
	public function test_mint_narrows_accepted_types_to_request(): void {
		$link = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'image/jpeg' ] ] );

		$this->assertSame( [ 'image/jpeg' ], $link['accepted_types'] );
	}

	/**
	 * Requesting only disallowed types fails outright rather than silently
	 * minting a link nothing can ever be uploaded through.
	 *
	 * @return void
	 */
	public function test_mint_rejects_a_request_matching_nothing_allowed(): void {
		$link = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'application/x-totally-not-real' ] ] );

		$this->assertInstanceOf( WP_Error::class, $link );
		$this->assertSame( 'no_accepted_types', $link->get_error_code() );
	}

	/**
	 * A user without upload_files cannot mint a link.
	 *
	 * @return void
	 */
	public function test_mint_requires_upload_files_capability(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$link = $this->service->mint( $subscriber, [] );

		$this->assertInstanceOf( WP_Error::class, $link );
	}

	/**
	 * A caller's requested max_bytes is honoured when under the server ceiling.
	 *
	 * @return void
	 */
	public function test_mint_honours_requested_max_bytes(): void {
		$link = $this->service->mint( $this->admin_id, [ 'max_bytes' => 500 ] );

		$this->assertSame( 500, $link['max_bytes'] );
	}

	/**
	 * A caller cannot request a cap above the server's own upload ceiling.
	 *
	 * @return void
	 */
	public function test_mint_caps_max_bytes_to_server_ceiling(): void {
		$link = $this->service->mint( $this->admin_id, [ 'max_bytes' => PHP_INT_MAX ] );

		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $link['max_bytes'] );
	}

	// ─── Default max_bytes: option + filter precedence ───────────────

	/**
	 * With no filter and no stored option, mint() falls back to the
	 * built-in conservative default.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_falls_back_to_constant(): void {
		$this->lift_server_upload_ceiling();
		delete_option( UploadLinkService::MAX_BYTES_OPTION );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( UploadLinkService::DEFAULT_MAX_BYTES, $link['max_bytes'] );
	}

	/**
	 * The site owner's own Settings-screen value (stored in MB) is honoured
	 * when no filter overrides it.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_honours_the_stored_option(): void {
		$this->lift_server_upload_ceiling();
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 5 * UploadLinkService::BYTES_PER_MB, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * The filter overrides the stored option outright.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_filter_overrides_the_option(): void {
		$this->lift_server_upload_ceiling();
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 3 * UploadLinkService::BYTES_PER_MB );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 3 * UploadLinkService::BYTES_PER_MB, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * The filter returning null defers to the option, not the constant
	 * default — a filter that isn't trying to override shouldn't silently
	 * mask the site owner's own setting.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_filter_returning_null_defers_to_the_option(): void {
		$this->lift_server_upload_ceiling();
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => null );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertSame( 5 * UploadLinkService::BYTES_PER_MB, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * A caller's own explicit max_bytes still wins over the site default,
	 * filtered or not — the filter/option only apply when nothing was requested.
	 *
	 * @return void
	 */
	public function test_explicit_request_overrides_the_default_entirely(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, 5 );

		$link = $this->service->mint( $this->admin_id, [ 'max_bytes' => 777 ] );

		$this->assertSame( 777, $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * The site default is still ceilinged by the server's real upload limit,
	 * exactly like an explicit request would be.
	 *
	 * @return void
	 */
	public function test_default_max_bytes_is_still_capped_by_server_ceiling(): void {
		update_option( UploadLinkService::MAX_BYTES_OPTION, UploadLinkService::MAX_SETTABLE_MB );

		$link = $this->service->mint( $this->admin_id, [] );

		$this->assertLessThanOrEqual( (int) wp_max_upload_size(), $link['max_bytes'] );

		delete_option( UploadLinkService::MAX_BYTES_OPTION );
	}

	/**
	 * A cap stored at mint time is re-ceilinged when the link is redeemed.
	 *
	 * The server's own limit can be lowered between the two, and the stored
	 * number would then promise more than core will accept, so the link would
	 * advertise a size that fails once the bytes actually arrive.
	 *
	 * @return void
	 */
	public function test_redeem_reapplies_a_lowered_server_ceiling(): void {
		$this->lift_server_upload_ceiling();

		$link = $this->service->mint( $this->admin_id, [ 'max_bytes' => 50 * UploadLinkService::BYTES_PER_MB ] );

		$this->assertSame( 50 * UploadLinkService::BYTES_PER_MB, $link['max_bytes'] );

		// The host tightens its limit after the link was handed out.
		remove_all_filters( 'upload_size_limit' );
		add_filter( 'upload_size_limit', static fn (): int => 1024 );

		$context = $this->service->redeem_link( $link['upload_token'] );

		$this->assertIsArray( $context );
		$this->assertSame( 1024, $context['max_bytes'] );
	}

	// ─── Settings-screen filter-override surface ───────────────────────

	/**
	 * With no filter hooked, the state is inactive.
	 *
	 * @return void
	 */
	public function test_filter_state_is_inactive_by_default(): void {
		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'inactive', $state['state'] );
	}

	/**
	 * A filter returning a positive int reports active, with that value.
	 *
	 * @return void
	 */
	public function test_filter_state_is_active_when_the_filter_overrides(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 4 * UploadLinkService::BYTES_PER_MB );

		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( 4 * UploadLinkService::BYTES_PER_MB, $state['value'] );
	}

	/**
	 * The filter accepts php.ini-style shorthand strings ("10M", "10MB",
	 * "2G") via wp_convert_hr_to_bytes() — the same parser WordPress itself
	 * uses for memory_limit/upload_max_filesize — not just a raw int.
	 *
	 * @return void
	 */
	public function test_filter_accepts_shorthand_size_strings(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10M' );
		$this->assertSame( 10 * UploadLinkService::BYTES_PER_MB, UploadLinkService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * "10MB" behaves identically to "10M" — wp_convert_hr_to_bytes() only
	 * checks for the presence of the unit letter, not an exact suffix.
	 *
	 * @return void
	 */
	public function test_filter_accepts_mb_suffix_identically_to_m(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10MB' );
		$this->assertSame( 10 * UploadLinkService::BYTES_PER_MB, UploadLinkService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * A gigabyte shorthand resolves correctly too.
	 *
	 * @return void
	 */
	public function test_filter_accepts_gigabyte_shorthand(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '1G' );
		$this->assertSame( 1024 * UploadLinkService::BYTES_PER_MB, UploadLinkService::get_default_max_bytes_filter_state()['value'] );
	}

	/**
	 * A string wp_convert_hr_to_bytes() can't make sense of is treated as
	 * "not overriding", not as a crash or a silent zero-byte cap.
	 *
	 * @return void
	 */
	public function test_filter_ignores_an_unparseable_string(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => 'not-a-size' );
		$this->assertSame( 'inactive', UploadLinkService::get_default_max_bytes_filter_state()['state'] );
	}

	/**
	 * A filter accidentally set to something absurd (e.g. a typo producing
	 * "10G" instead of "10M") is clamped to the same absolute ceiling the
	 * Settings screen's own field is bound to — a filter can't set
	 * something the UI itself would refuse.
	 *
	 * @return void
	 */
	public function test_filter_value_is_clamped_to_the_settable_ceiling(): void {
		add_filter( 'albert/media/upload_link_max_bytes', static fn () => '10G' ); // 10240 MB, well past the 2048 MB ceiling.

		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( UploadLinkService::MAX_SETTABLE_MB * UploadLinkService::BYTES_PER_MB, $state['value'] );
	}

	/**
	 * An invalid post_id is rejected at mint time.
	 *
	 * @return void
	 */
	public function test_mint_rejects_nonexistent_post(): void {
		$link = $this->service->mint( $this->admin_id, [ 'post_id' => 999999 ] );

		$this->assertInstanceOf( WP_Error::class, $link );
		$this->assertSame( 'cannot_attach', $link->get_error_code() );
	}

	/**
	 * A negative post_id is treated as "not requested" (post_id: 0), not
	 * flipped to its positive magnitude — absint(-12) would return 12 and
	 * silently validate/attach against a real post the caller never asked
	 * for, the exact bug already fixed once for max_bytes in this method.
	 *
	 * @return void
	 */
	public function test_mint_treats_negative_post_id_as_not_requested(): void {
		// Guarantee a post with a small positive ID exists, so a sign-flip
		// bug (-3 -> 3) would have something real to wrongly validate against.
		self::factory()->post->create_many( 5 );

		$link = $this->service->mint( $this->admin_id, [ 'post_id' => -3 ] );

		$this->assertIsArray( $link );
		$this->assertSame( 0, $link['post_id'] );
	}

	// ─── redeem_link() ────────────────────────────────────────────

	/**
	 * A fresh link redeems to the issuing user's context.
	 *
	 * @return void
	 */
	public function test_redeem_link_returns_context(): void {
		$link = $this->service->mint( $this->admin_id, [] );

		$context = $this->service->redeem_link( $link['upload_token'] );

		$this->assertIsArray( $context );
		$this->assertSame( $this->admin_id, $context['user_id'] );
	}

	/**
	 * Mint -> redeem once -> second redemption fails (the doc's primary
	 * acceptance criterion).
	 *
	 * @return void
	 */
	public function test_second_redemption_fails(): void {
		$link = $this->service->mint( $this->admin_id, [] );

		$first  = $this->service->redeem_link( $link['upload_token'] );
		$second = $this->service->redeem_link( $link['upload_token'] );

		$this->assertIsArray( $first );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'link_already_used', $second->get_error_code() );
	}

	/**
	 * An expired link fails cleanly with a distinct error code.
	 *
	 * @return void
	 */
	public function test_expired_link_fails_cleanly(): void {
		global $wpdb;

		$link = $this->service->mint( $this->admin_id, [] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup: force expiry.
		$wpdb->update(
			Tables::single_use_tokens(),
			[ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ],
			[ 'token_hash' => hash( 'sha256', $link['upload_token'] ) ]
		);

		$result = $this->service->redeem_link( $link['upload_token'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'link_expired', $result->get_error_code() );
	}

	/**
	 * A role downgrade between mint and redemption revokes the link, even
	 * though it hasn't expired and was never used.
	 *
	 * @return void
	 */
	public function test_role_downgrade_between_mint_and_redemption_revokes_link(): void {
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$link      = $this->service->mint( $editor_id, [] );

		$editor = get_userdata( $editor_id );
		$editor->set_role( 'subscriber' );

		$result = $this->service->redeem_link( $link['upload_token'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'capability_revoked', $result->get_error_code() );

		// The resolved user_id rides in the error data so a caller (the REST
		// controller's execution-log call) can still attribute this failure
		// to the real, identifiable user rather than logging it as user 0.
		$this->assertSame( $editor_id, $result->get_error_data()['user_id'] );
	}

	/**
	 * A capability change that narrows (but doesn't remove) upload rights
	 * between mint and redemption narrows the effective allowlist rather
	 * than honouring the wider one captured at mint time.
	 *
	 * @return void
	 */
	public function test_narrowed_capability_shrinks_effective_allowlist(): void {
		$admin = get_userdata( $this->admin_id );
		$link  = $this->service->mint( $this->admin_id, [] );

		$this->assertContains( 'text/plain', $link['accepted_types'] );

		// Narrow what this user is allowed to upload via the same filter
		// get_allowed_mime_types() itself consults.
		add_filter(
			'upload_mimes',
			static function () {
				return [ 'jpg|jpeg|jpe' => 'image/jpeg' ];
			}
		);

		$context = $this->service->redeem_link( $link['upload_token'] );

		$this->assertIsArray( $context );
		$this->assertSame( [ 'image/jpeg' ], array_values( $context['mime_allowlist'] ) );
	}

	// ─── finalize_upload() ──────────────────────────────────────────

	/**
	 * A legitimate upload becomes a normal media library attachment with
	 * generated thumbnails.
	 *
	 * @return void
	 */
	public function test_finalize_upload_creates_attachment_with_thumbnails(): void {
		$link    = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		$tmp = wp_tempnam();
		copy( $this->jpg_fixture(), $tmp );

		$result = $this->service->finalize_upload( $tmp, 'photo.jpg', $context );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );

		$metadata = wp_get_attachment_metadata( $result['attachment_id'] );
		$this->assertArrayHasKey( 'sizes', $metadata );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * A .php file renamed to .jpg is rejected by real content sniffing, even
	 * when the uploading user holds `unfiltered_upload` — that capability is
	 * never consulted by this path, for any user.
	 *
	 * @return void
	 */
	public function test_php_renamed_to_jpg_is_rejected_even_with_unfiltered_upload(): void {
		$admin = get_userdata( $this->admin_id );
		$admin->add_cap( 'unfiltered_upload' );

		$link    = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		$tmp = wp_tempnam();
		file_put_contents( $tmp, "<?php echo 'pwned'; " ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Test fixture, not user input.

		$result = $this->service->finalize_upload( $tmp, 'evil.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * A real file whose type simply isn't in the link's (narrowed)
	 * allowlist is rejected, and the response carries the accepted list.
	 *
	 * @return void
	 */
	public function test_finalize_upload_rejects_type_outside_narrowed_allowlist(): void {
		$link    = $this->service->mint( $this->admin_id, [ 'accepted_types' => [ 'application/pdf' ] ] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		$tmp = wp_tempnam();
		copy( $this->jpg_fixture(), $tmp );

		$result = $this->service->finalize_upload( $tmp, 'photo.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'type_not_allowed', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( [ 'application/pdf' ], $data['accepted_types'] );
		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * The two default constants express the same size, so neither can drift.
	 *
	 * @return void
	 */
	public function test_the_byte_and_megabyte_defaults_agree(): void {
		$this->assertSame(
			UploadLinkService::DEFAULT_MAX_MB * UploadLinkService::BYTES_PER_MB,
			UploadLinkService::DEFAULT_MAX_BYTES
		);
	}

	/**
	 * A filter returning a float overrides, rather than being silently dropped.
	 *
	 * @return void
	 */
	public function test_a_float_filter_value_is_honoured(): void {
		add_filter(
			'albert/media/upload_link_max_bytes',
			static function () {
				return 1.5 * UploadLinkService::BYTES_PER_MB;
			}
		);

		$state = UploadLinkService::get_default_max_bytes_filter_state();

		$this->assertSame( 'active', $state['state'] );
		$this->assertSame( (int) ( 1.5 * UploadLinkService::BYTES_PER_MB ), $state['value'] );
	}

	/**
	 * A core-side upload refusal is a 4xx with its own code, not a bare 500
	 * colliding with the controller's own 'upload_error'.
	 *
	 * @return void
	 */
	public function test_a_core_upload_refusal_is_recoded_as_a_client_error(): void {
		$link    = $this->service->mint( $this->admin_id, [] );
		$context = $this->service->redeem_link( $link['upload_token'] );

		// Make core's own sideload fail after our checks have passed.
		add_filter(
			'wp_handle_sideload_prefilter',
			static function ( $file ) {
				$file['error'] = 'forced failure';

				return $file;
			}
		);

		$tmp = wp_tempnam();
		copy( $this->jpg_fixture(), $tmp );

		$result = $this->service->finalize_upload( $tmp, 'photo.jpg', $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'upload_failed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ─── 1.5.1: who may still redeem ──────────────────────────────

	/**
	 * Mint and redeem a link as `$user_id` over a connection holding one token.
	 *
	 * @param int $user_id The user.
	 *
	 * @return array{client_id: string, token: string}
	 */
	private function mint_over_a_connection( int $user_id ): array {
		global $wpdb;

		$client = ( new ClientRepository() )->createClient( 'Test client', 'https://client.example/cb' );
		$this->assertIsArray( $client );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['access_tokens'],
			[
				'token_id'   => wp_generate_password( 40, false ),
				'client_id'  => $client['client_id'],
				'user_id'    => $user_id,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);

		ConnectionContext::set( $client['client_id'] );

		try {
			$link = $this->service->mint( $user_id, [] );
		} finally {
			ConnectionContext::reset();
		}

		return [
			'client_id' => $client['client_id'],
			'token'     => $link['upload_token'],
		];
	}

	/**
	 * A link minted over a live connection redeems.
	 *
	 * @return void
	 */
	public function test_link_from_a_live_connection_redeems(): void {
		$link = $this->mint_over_a_connection( $this->admin_id );

		$this->assertIsArray( $this->service->redeem_link( $link['token'] ) );
	}

	/**
	 * Disconnecting the assistant ends its outstanding links.
	 *
	 * @return void
	 */
	public function test_link_dies_with_its_connection(): void {
		$link = $this->mint_over_a_connection( $this->admin_id );

		( new ClientRepository() )->revokeUserTokens( $link['client_id'], $this->admin_id );

		$result = $this->service->redeem_link( $link['token'] );

		$this->assertWPError( $result );
		$this->assertSame( 'capability_revoked', $result->get_error_code() );
	}

	/**
	 * Removing somebody from the allowed list ends their outstanding links.
	 *
	 * @return void
	 */
	public function test_link_dies_when_the_user_is_removed(): void {
		$link = $this->service->mint( $this->admin_id, [] );

		AllowedUsers::remove( $this->admin_id );

		$result = $this->service->redeem_link( $link['upload_token'] );

		$this->assertWPError( $result );
		$this->assertSame( 'capability_revoked', $result->get_error_code() );
	}

	/**
	 * A link cannot attach to a post its user may not edit, at mint or redeem.
	 *
	 * @return void
	 */
	public function test_attaching_needs_edit_post_at_mint_and_redeem(): void {
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$foreign = self::factory()->post->create( [ 'post_author' => $this->admin_id ] );
		$own     = self::factory()->post->create( [ 'post_author' => $author ] );

		$refused = $this->service->mint( $author, [ 'post_id' => $foreign ] );
		$this->assertWPError( $refused );
		$this->assertSame( 'cannot_attach', $refused->get_error_code() );

		$link = $this->service->mint( $author, [ 'post_id' => $own ] );
		$this->assertIsArray( $link );

		// The post changes hands before the link is used.
		wp_update_post(
			[
				'ID'          => $own,
				'post_author' => $this->admin_id,
			]
		);

		$result = $this->service->redeem_link( $link['upload_token'] );
		$this->assertWPError( $result );
		$this->assertSame( 'capability_revoked', $result->get_error_code() );
	}
}
