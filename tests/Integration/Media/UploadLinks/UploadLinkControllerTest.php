<?php
/**
 * Integration tests for the UploadLinkController REST endpoint.
 *
 * UploadLinkServiceTest already covers the domain logic in isolation;
 * this file covers the REST wiring itself — route registration, header
 * extraction, multipart file receipt, the byte-cap streaming path, and
 * that a redemption attempt is logged through Albert's execution log.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Media\UploadLinks;

use Albert\Core\Plugin;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Media\UploadLinks\UploadLinkController;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;
use Albert\Undo\Storage\Repository as ChangeRepository;
use Albert\Undo\WindowContext;
use WP_Error;
use WP_REST_Request;

/**
 * UploadLinkController integration tests.
 *
 * @covers \Albert\Media\UploadLinks\UploadLinkController
 */
class UploadLinkControllerTest extends TestCase {

	/**
	 * The link service, shared with the controller under test.
	 *
	 * @var UploadLinkService
	 */
	private UploadLinkService $links;

	/**
	 * The controller under test.
	 *
	 * @var UploadLinkController
	 */
	private UploadLinkController $controller;

	/**
	 * An administrator to mint links for.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Reset state and register REST routes before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::ability_log() ) );

		$this->links      = new UploadLinkService();
		$this->controller = new UploadLinkController( $this->links );
		$this->controller->register_hooks();

		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
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

	/**
	 * Build a redemption request carrying a multipart `file` field.
	 *
	 * Copies the fixture into a fresh temp file — mirroring what PHP itself
	 * hands a real request ($_FILES['tmp_name'] is always a throwaway file)
	 * — rather than pointing `tmp_name` at the shared fixture directly.
	 * finalize_upload() deletes whatever it's given after processing, and a
	 * shared test-data fixture must never be that file.
	 *
	 * @param string $token The upload token.
	 * @param string $path  Path to the fixture file to attach.
	 * @param string $name  Filename to report.
	 *
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function multipart_request( string $token, string $path, string $name ): WP_REST_Request {
		$tmp_copy = wp_tempnam();
		copy( $path, $tmp_copy );

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, $token );
		$request->set_file_params(
			[
				'file' => [
					'name'     => $name,
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp_copy,
					'error'    => 0,
					'size'     => filesize( $tmp_copy ),
				],
			]
		);

		return $request;
	}

	// ─── Route wiring ───────────────────────────────────────────────

	/**
	 * The route is registered under the plugin's REST namespace.
	 *
	 * @return void
	 */
	public function test_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . Plugin::rest_namespace() . '/media/uploads', $routes );
	}

	/**
	 * A request with no token header is rejected without touching the link service.
	 *
	 * @return void
	 */
	public function test_missing_token_is_rejected(): void {
		$request  = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		// Its own code: nothing was presented, so this is not the same event as
		// a spent or forged token, and a client must not read it as one.
		$this->assertSame( 'missing_token', $response->get_data()['code'] );
		$this->assertStringContainsString(
			UploadLinkService::TOKEN_HEADER,
			$response->get_data()['message'],
			'The rejection should name the header the token belongs in.'
		);
	}

	// ─── Happy path ─────────────────────────────────────────────────

	/**
	 * A valid multipart upload redeems the link and creates an attachment.
	 *
	 * @return void
	 */
	public function test_multipart_upload_creates_attachment(): void {
		$link = $this->links->mint( $this->admin_id, [] );

		$request  = $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'attachment_id', $data );
		$this->assertNotNull( get_post( $data['attachment_id'] ) );
	}

	/**
	 * A link minted over a connection records its upload in the change journal
	 * as that connection's call; one minted without a connection does not.
	 *
	 * @return void
	 */
	public function test_an_upload_through_a_connections_link_is_recorded(): void {
		global $wpdb;

		ConnectionContext::set( 'client-acme' );
		try {
			$link = $this->links->mint( $this->admin_id, [] );
		} finally {
			ConnectionContext::reset();
		}

		$response = rest_get_server()->dispatch( $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );
		$this->assertSame( 201, $response->get_status() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Tables::changes() ), ARRAY_A );

		$this->assertSame( 'client-acme', $entry['client_id'] );
		$this->assertSame( WindowContext::SOURCE_UPLOAD, $entry['source'] );
		$this->assertContains( 'post:' . $response->get_data()['attachment_id'], array_map( static fn( $item ) => $item['entity_type'] . ':' . $item['entity_key'], ( new ChangeRepository() )->items( (int) $entry['id'] ) ) );
	}

	/**
	 * The route also accepts PUT, not just POST — a client that reasonably
	 * defaults to PUT for "put these bytes at this URL" must not be 405'd.
	 *
	 * @return void
	 */
	public function test_put_is_also_accepted(): void {
		$link = $this->links->mint( $this->admin_id, [] );

		$request = $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' );
		$request->set_method( 'PUT' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotNull( get_post( $response->get_data()['attachment_id'] ) );
	}

	/**
	 * Mint() reports which methods the endpoint accepts, matching what's
	 * actually registered.
	 *
	 * @return void
	 */
	public function test_mint_reports_accepted_methods(): void {
		$link = $this->links->mint( $this->admin_id, [] );

		// A list, not "POST, PUT": the consumer is a model, and a comma string
		// is something it has to parse before it can use it.
		$this->assertSame( [ 'POST', 'PUT' ], $link['methods'] );

		$routes = rest_get_server()->get_routes()[ '/' . Plugin::rest_namespace() . '/media/uploads' ];
		foreach ( $link['methods'] as $method ) {
			$this->assertArrayHasKey( $method, $routes[0]['methods'], "Route does not accept {$method}" );
		}
	}

	/**
	 * Redeeming the same link twice fails the second time end to end.
	 *
	 * @return void
	 */
	public function test_second_redemption_via_rest_fails(): void {
		$link = $this->links->mint( $this->admin_id, [] );

		$first  = rest_get_server()->dispatch( $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );
		$second = rest_get_server()->dispatch( $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'link_already_used', $second->get_data()['code'] );
	}

	/**
	 * A multipart file larger than the link's cap is rejected without
	 * ever reaching content sniffing or sideload.
	 *
	 * @return void
	 */
	public function test_oversized_multipart_upload_is_rejected(): void {
		$link = $this->links->mint( $this->admin_id, [ 'max_bytes' => 10 ] );

		$request  = $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 413, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'too_large', $data['code'] );
		$this->assertSame( 10, $data['data']['max_bytes'] );
	}

	/**
	 * A zero-byte multipart file is rejected, matching the raw-body path's
	 * existing `$total === 0` check in stream_to_temp_file() — the two
	 * receive paths must enforce the same minimum, not silently accept an
	 * empty file through multipart while rejecting it over raw body.
	 *
	 * @return void
	 */
	public function test_empty_multipart_upload_is_rejected(): void {
		$link = $this->links->mint( $this->admin_id, [] );

		$empty_file = wp_tempnam();

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, $link['upload_token'] );
		$request->set_file_params(
			[
				'file' => [
					'name'     => 'empty.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => $empty_file,
					'error'    => 0,
					'size'     => 0,
				],
			]
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'upload_error', $response->get_data()['code'] );
	}

	/**
	 * A link is spent even when the upload it was redeemed for then fails —
	 * a retry with a valid, correctly-sized file on the same token is refused,
	 * not treated as another chance. Otherwise a failed large upload becomes
	 * a retry oracle.
	 *
	 * @return void
	 */
	public function test_a_failed_upload_does_not_leave_the_link_retryable(): void {
		$link = $this->links->mint( $this->admin_id, [ 'max_bytes' => 10 ] );

		$failed = rest_get_server()->dispatch( $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );
		$this->assertSame( 413, $failed->get_status() );

		// Retry with the same (already-spent) token. Even a small enough file
		// that would otherwise fit the cap must still be refused.
		$retry = rest_get_server()->dispatch( $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );

		$this->assertSame( 400, $retry->get_status() );
		$this->assertSame( 'link_already_used', $retry->get_data()['code'] );
	}

	// ─── Logging ────────────────────────────────────────────────────

	/**
	 * A successful redemption is written to the execution log.
	 *
	 * @return void
	 */
	public function test_successful_redemption_is_logged(): void {
		global $wpdb;

		$link = $this->links->mint( $this->admin_id, [] );
		rest_get_server()->dispatch( $this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				UploadLinkController::LOG_ABILITY_ID
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'success', $row->status );
		$this->assertSame( $this->admin_id, (int) $row->user_id );
	}

	/**
	 * A rejected redemption (bad token) is also logged, as an error.
	 *
	 * @return void
	 */
	public function test_rejected_redemption_is_logged_as_error(): void {
		global $wpdb;

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, 'not-a-real-token' );
		rest_get_server()->dispatch( $request );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				UploadLinkController::LOG_ABILITY_ID
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'error', $row->status );
		$this->assertSame( 'link_already_used', $row->error_code );
	}

	/**
	 * A 'capability_revoked' rejection (the issuing user's upload_files was
	 * revoked between mint and redemption) is logged against that real user,
	 * not user_id 0 — the one failure mode where the issuing user IS known,
	 * and the one an audit trail most needs attributed correctly.
	 *
	 * @return void
	 */
	public function test_capability_revoked_rejection_is_logged_with_the_real_user(): void {
		global $wpdb;

		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		$link      = $this->links->mint( $editor_id, [] );

		get_userdata( $editor_id )->set_role( 'subscriber' );

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, $link['upload_token'] );
		rest_get_server()->dispatch( $request );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s AND error_code = %s',
				Tables::ability_log(),
				UploadLinkController::LOG_ABILITY_ID,
				'capability_revoked'
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $editor_id, (int) $row->user_id );
	}

	/**
	 * The per-ability after_execute/{id} variant fires alongside the generic
	 * hook, matching what BaseAbility::guarded_execute() fires for every
	 * real ability — a listener bound to this specific (synthetic) id must
	 * not be a silent dead end.
	 *
	 * @return void
	 */
	public function test_per_ability_after_execute_hook_fires(): void {
		$fired = false;
		add_action(
			'albert/abilities/after_execute/' . UploadLinkController::LOG_ABILITY_ID,
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, 'not-a-real-token' );
		rest_get_server()->dispatch( $request );

		$this->assertTrue( $fired );
	}

	// ─── Byte-cap streaming (raw body path) ────────────────────────

	/**
	 * A stream under the cap is written to disk in full.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_writes_data_under_the_cap(): void {
		$content = str_repeat( 'a', 1000 );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, 2000 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertIsString( $result );
		$this->assertSame( $content, file_get_contents( $result ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		wp_delete_file( $result );
	}

	/**
	 * A stream over the cap is rejected, and never written to disk in full —
	 * the file that would have been created no longer exists afterwards.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_rejects_a_stream_over_the_cap(): void {
		$content = str_repeat( 'a', 5000 );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, 1000 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_large', $result->get_error_code() );
		$this->assertSame( 1000, $result->get_error_data()['max_bytes'] );
	}

	/**
	 * A stream spanning more than one STREAM_CHUNK_BYTES chunk is assembled
	 * correctly in full — exercises the read loop itself, not just a single
	 * fread() call.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_assembles_multiple_chunks(): void {
		$size    = UploadLinkController::STREAM_CHUNK_BYTES * 2 + 12345;
		$content = str_repeat( 'x', $size );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, $size );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertIsString( $result );
		$this->assertSame( $size, filesize( $result ) );
		$this->assertSame( $content, file_get_contents( $result ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		wp_delete_file( $result );
	}

	/**
	 * The cap is still enforced precisely when it falls in the middle of a
	 * chunk read, not just on a chunk boundary.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_enforces_cap_mid_chunk(): void {
		$size    = UploadLinkController::STREAM_CHUNK_BYTES + 500;
		$content = str_repeat( 'x', $size );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, UploadLinkController::STREAM_CHUNK_BYTES + 100 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_large', $result->get_error_code() );
	}

	/**
	 * The attachment is authored by the user who minted the link, not by 0.
	 *
	 * @return void
	 */
	public function test_attachment_is_authored_by_the_issuing_user(): void {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$link      = $this->links->mint( $author_id, [] );

		$response = $this->controller->handle_upload(
			$this->multipart_request( $link['upload_token'], $this->jpg_fixture(), 'authored.jpg' )
		);

		$this->assertNotWPError( $response );

		$attachment_id = $response->get_data()['attachment_id'];

		$this->assertSame( $author_id, (int) get_post_field( 'post_author', $attachment_id ) );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * A multipart file exactly at the cap is accepted.
	 *
	 * Content-Length on a multipart request is the whole envelope: boundaries,
	 * part headers and the filename field on top of the bytes themselves.
	 * Measuring that against a file-size cap rejected a file that was exactly
	 * at the limit, and reported the limit it had supposedly exceeded. The
	 * declared-size check now belongs to the raw-body path, where
	 * Content-Length really is the file.
	 *
	 * @return void
	 */
	public function test_multipart_file_exactly_at_the_cap_is_accepted(): void {
		$fixture = $this->jpg_fixture();
		$size    = filesize( $fixture );

		// Keep the server's own ceiling out of it: a stock PHP allows 2 MB, and
		// a fixture near that would be clamped, so this would silently stop
		// testing the boundary it names.
		add_filter( 'upload_size_limit', static fn (): int => $size + 1 );

		$link = $this->links->mint( $this->admin_id, [ 'max_bytes' => $size ] );

		$this->assertSame( $size, $link['max_bytes'], 'The cap under test must survive minting.' );

		$had_original = isset( $_SERVER['CONTENT_LENGTH'] );
		$original     = $had_original ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;

		// What a real multipart request declares: the file plus its envelope.
		$_SERVER['CONTENT_LENGTH'] = (string) ( $size + 240 );

		$response = $this->controller->handle_upload(
			$this->multipart_request( $link['upload_token'], $fixture, 'exactly-at-cap.jpg' )
		);

		if ( $had_original ) {
			$_SERVER['CONTENT_LENGTH'] = (string) $original;
		} else {
			unset( $_SERVER['CONTENT_LENGTH'] );
		}

		$this->assertNotWPError( $response );
		$this->assertSame( 201, $response->get_status() );

		wp_delete_attachment( $response->get_data()['attachment_id'], true );
	}

	/**
	 * A body larger than the cap is refused on its declared size, before the
	 * bytes are read, rather than after the whole thing has been handled.
	 *
	 * @return void
	 */
	public function test_declared_content_length_over_the_cap_is_refused(): void {
		$link = $this->links->mint( $this->admin_id, [ 'max_bytes' => 1024 ] );

		$had_original = isset( $_SERVER['CONTENT_LENGTH'] );
		$original     = $had_original ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;

		$_SERVER['CONTENT_LENGTH'] = '99999999';

		$request = new WP_REST_Request( 'PUT', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, $link['upload_token'] );
		$request->set_param( 'filename', 'big.jpg' );

		$result = $this->controller->handle_upload( $request );

		if ( $had_original ) {
			$_SERVER['CONTENT_LENGTH'] = (string) $original;
		} else {
			unset( $_SERVER['CONTENT_LENGTH'] );
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
		$this->assertSame( 1024, $result->get_error_data()['max_bytes'] );
	}

	/**
	 * PHP's own size rejection empties tmp_name; that must still read as
	 * too_large, not as the raw-body branch's "filename is required".
	 *
	 * @return void
	 */
	public function test_php_size_rejection_reports_too_large(): void {
		$link = $this->links->mint( $this->admin_id, [] );

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, $link['upload_token'] );
		$request->set_file_params(
			[
				'file' => [
					'name'     => 'huge.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => '',
					'error'    => UPLOAD_ERR_INI_SIZE,
					'size'     => 0,
				],
			]
		);

		$result = $this->controller->handle_upload( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
	}

	/**
	 * The issuing user's ID is logged but never returned to the unauthenticated caller.
	 *
	 * @return void
	 */
	public function test_capability_revoked_does_not_leak_the_user_id(): void {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$link      = $this->links->mint( $author_id, [] );

		$user = new \WP_User( $author_id );
		$user->set_role( 'subscriber' );

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadLinkService::TOKEN_HEADER, $link['upload_token'] );

		$result = $this->controller->handle_upload( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'capability_revoked', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'user_id', $result->get_error_data() );
	}
}
