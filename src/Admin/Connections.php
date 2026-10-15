<?php
/**
 * Connections Page
 *
 * Everything about getting an AI assistant talking to this site, and about what
 * is talking to it right now: whether the address can be reached at all, how to
 * set each client up, who is allowed to connect, and which connections exist.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Connections\ClientSetupGuides;
use Albert\Admin\Connections\UserPickerModal;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Database\Tables;
use Albert\MCP\Server as McpServer;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Repositories\RefreshTokenRepository;

/**
 * The Albert → Connections screen.
 *
 * The order answers the two questions a visitor arrives with, in the order they
 * arrive: "can this even work here, and what is my URL" first, then "what is
 * connected" beside "who may connect", then "how do I connect the thing I use".
 *
 * Two rules run through the whole screen and are worth stating once:
 *
 *  1. **The screen renders identically regardless of row count.** The filter
 *     field, the Group-by control and the Select button are always there, quiet
 *     and unused on a three-row site, exactly the way a WordPress list table's
 *     search box exists whether it holds three rows or three thousand. An admin
 *     screen that grows controls at some hidden threshold reads as a bug, and a
 *     support screenshot stops matching what the person is looking at.
 *  2. **A connection's name is self-reported by the client.** Every Claude
 *     Desktop connection registers as literally "Claude". The owner's own label
 *     is the only thing that reliably tells two rows apart, so the affordance to
 *     write one is on every row, always visible, never hover-only.
 *
 * @since 1.0.0
 */
class Connections implements Hookable {

	/**
	 * Page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'albert-connections';

	/**
	 * The id of the form the row checkboxes submit through.
	 *
	 * The checkboxes live inside the rows and the form element does not wrap
	 * them: a row also carries its own label-editing form, and forms cannot
	 * nest. HTML's `form` attribute associates the two across the DOM.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private const BULK_FORM_ID = 'albert-bulk-revoke';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], Menu::POSITION_CONNECTIONS );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_' . UserPickerModal::ACTION, [ $this, 'handle_add_allowed_users' ] );
		add_action( 'admin_post_albert_set_connection_label', [ $this, 'handle_set_connection_label' ] );
		add_action( 'admin_post_albert_revoke_selected', [ $this, 'handle_revoke_selected' ] );
	}

	/**
	 * Add the connections page to admin menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Connections', 'albert-ai-butler' ),
			__( 'Connections', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/*
	---------------------------------------------------------------------
	 * Action handling
	 * ------------------------------------------------------------------
	 */

	/**
	 * Handle user actions (GET-based).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in each handler.
		if ( ! isset( $_GET['action'], $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in each handler.
		if ( $this->page_slug !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in each handler.
		$action = sanitize_key( $_GET['action'] );

		switch ( $action ) {
			case 'revoke_client':
				$this->handle_revoke_client( false );
				break;
			case 'revoke_client_full':
				$this->handle_revoke_client( true );
				break;
			case 'revoke_all':
				$this->handle_revoke_all_connections();
				break;
			case 'remove_allowed_user':
				$this->handle_remove_allowed_user();
				break;
			case 'revoke_user_session':
				$this->handle_revoke_user_session();
				break;
			case 'revoke_all_user_sessions':
				$this->handle_revoke_all_user_sessions();
				break;
		}
	}

	/**
	 * Revoke one client's connection.
	 *
	 * Two depths, because they mean different things to the person clicking.
	 * Revoking the access token alone drops the current session; the client
	 * still holds a refresh token and comes back within the hour, which is what
	 * you want when an assistant is misbehaving rather than unwelcome. Revoking
	 * the refresh tokens too ends the authorisation: the client has to be
	 * approved again by an allowed user.
	 *
	 * @param bool $full Whether to revoke refresh tokens as well.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function handle_revoke_client( bool $full ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$client_id = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';

		if ( $client_id === '' ) {
			return;
		}

		$nonce_action = ( $full ? 'albert_revoke_client_full_' : 'albert_revoke_client_' ) . $client_id;

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability();

		$this->revoke_client_tokens( $client_id, $full );

		$this->notify(
			'connection_revoked',
			$full
				? __( 'Connection ended. The assistant has to be approved again before it can reconnect.', 'albert-ai-butler' )
				: __( 'Connection signed out. The assistant reconnects on its own within the hour.', 'albert-ai-butler' )
		);

		$this->redirect_to_page();
	}

	/**
	 * Revoke every currently active connection.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function handle_revoke_all_connections(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'albert_revoke_all_connections' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability();

		foreach ( $this->get_connections() as $connection ) {
			$this->revoke_client_tokens( (string) $connection['client_id'], true );
		}

		$this->notify(
			'all_connections_revoked',
			__( 'Every assistant was disconnected. Each one has to be approved again before it can reconnect.', 'albert-ai-butler' )
		);

		$this->redirect_to_page();
	}

	/**
	 * Revoke the connections ticked in selection mode.
	 *
	 * A bulk revoke ends the authorisation outright rather than only signing the
	 * assistants out: somebody who has entered selection mode, ticked several
	 * rows and pressed a danger-coloured button is clearing them out, not asking
	 * them all to sign back in within the hour.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function handle_revoke_selected(): void {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['albert_bulk_nonce'] ?? '' ) ), 'albert_revoke_selected' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability();

		$posted = isset( $_POST['client_ids'] ) ? (array) wp_unslash( $_POST['client_ids'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per element below.

		// Both the flat and the grouped list render a checkbox for the same
		// connection, and a connection authorised by two people appears twice in
		// the grouped list as well, so the same id can legitimately arrive more
		// than once.
		$client_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $posted ) ) ) );

		if ( empty( $client_ids ) ) {
			$this->notify(
				'nothing_selected',
				__( 'Nothing was selected, so nothing was revoked.', 'albert-ai-butler' ),
				'warning'
			);
			$this->redirect_to_page();
		}

		foreach ( $client_ids as $client_id ) {
			$this->revoke_client_tokens( $client_id, true );
		}

		$count = count( $client_ids );

		$this->notify(
			'selection_revoked',
			sprintf(
				/* translators: %d: number of connections revoked. */
				_n(
					'%d connection ended. It has to be approved again before it can reconnect.',
					'%d connections ended. Each has to be approved again before it can reconnect.',
					$count,
					'albert-ai-butler'
				),
				$count
			)
		);

		$this->redirect_to_page();
	}

	/**
	 * Revoke a client's tokens.
	 *
	 * @param string $client_id       The OAuth client identifier.
	 * @param bool   $include_refresh Whether to revoke refresh tokens too.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function revoke_client_tokens( string $client_id, bool $include_refresh ): void {
		/*
		 * The full disconnect is ClientRepository::revokeAllTokens(), not a
		 * copy of it. These two were byte-identical until now, which is exactly
		 * why the refresh-token bug had to be fixed in both and why a review
		 * caught it in only one of them.
		 */
		if ( $include_refresh ) {
			( new ClientRepository() )->revokeAllTokens( $client_id );

			return;
		}

		global $wpdb;

		// Sign-out only: the access tokens go, the refresh tokens stay, and the
		// assistant reconnects on its own. That is the difference this branch
		// exists to express.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			Tables::oauth()['access_tokens'],
			[ 'revoked' => 1 ],
			[ 'client_id' => $client_id ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Add one or more users to the allowed list.
	 *
	 * The picker is a multi-select because setup is a real argument: an agency
	 * onboarding a site adds three editors at once, and doing that one modal at
	 * a time is three round trips for one decision.
	 *
	 * Responds two ways depending on how it was called. The picker's own JS
	 * calls this via `fetch()` when it is opened from the Connections screen
	 * (there is an allowed-users list on the page to update in place) and
	 * sends back JSON: a message, the ids actually added, and the freshly
	 * rendered list body. A native form submission, which is what the
	 * Dashboard's onboarding checklist still does, gets the original
	 * queue-a-notice-and-redirect behaviour. Both paths run the exact same
	 * validation and write; only the response differs.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function handle_add_allowed_users(): void {
		$is_ajax = $this->is_ajax_request();

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['albert_add_users_nonce'] ?? '' ) ), UserPickerModal::ACTION ) ) {
			if ( $is_ajax ) {
				wp_send_json_error( [ 'message' => __( 'Security check failed.', 'albert-ai-butler' ) ], 403 );
			}

			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability( __( 'You do not have permission to manage MCP access.', 'albert-ai-butler' ), $is_ajax );

		$raw = isset( $_POST['albert_user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['albert_user_ids'] ) ) : '';
		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );

		$return_to = isset( $_POST['albert_return'] ) ? sanitize_key( wp_unslash( $_POST['albert_return'] ) ) : 'connections';

		if ( empty( $ids ) ) {
			$message = __( 'Nobody was chosen, so nothing changed. Search for someone and tick their name.', 'albert-ai-butler' );

			if ( $is_ajax ) {
				wp_send_json_error( [ 'message' => $message ] );
			}

			$this->notify( 'nobody_chosen', $message, 'warning' );
			$this->redirect_after_add( $return_to );
		}

		$added_ids = [];

		foreach ( $ids as $user_id ) {
			if ( ! get_user_by( 'id', $user_id ) ) {
				continue;
			}

			if ( AllowedUsers::add( $user_id ) ) {
				$added_ids[] = $user_id;
			}
		}

		$added   = count( $added_ids );
		$message = $added === 0
			? __( 'Everyone you chose could already approve an assistant, so nothing changed.', 'albert-ai-butler' )
			: sprintf(
				/* translators: %d: number of users added. */
				_n( '%d person can now approve an assistant.', '%d people can now approve an assistant.', $added, 'albert-ai-butler' ),
				$added
			);

		if ( $is_ajax ) {
			if ( $added === 0 ) {
				wp_send_json_error( [ 'message' => $message ] );
			}

			ob_start();
			$this->render_allowed_users_body( AllowedUsers::all() );
			$body_html = (string) ob_get_clean();

			wp_send_json_success(
				[
					'message'  => $message,
					'addedIds' => $added_ids,
					'bodyHtml' => $body_html,
				]
			);
		}

		$this->notify( 'users_added', $message );
		$this->redirect_after_add( $return_to );
	}

	/**
	 * Handle removing a user from the allowed users list.
	 *
	 * Removing revokes. The allowed list is checked when an authorisation
	 * *starts*, so without this "Remove" would only mean "cannot connect a new
	 * assistant" while every token they already hold keeps working, which is
	 * not what the word says.
	 *
	 * Unlike adding a user, removing one can change the *other* card too: it
	 * revokes every token this person holds, on every client, so a connection
	 * they were the only authoriser of disappears, and one they co-authorised
	 * loses their name from its "Authorised by" line. So the AJAX response (the
	 * same detection and JSON-vs-redirect split as
	 * {@see self::handle_add_allowed_users()}) sends back a fresh copy of both
	 * cards, not just the one the link lives on; anything narrower would leave
	 * "Connected assistants" silently wrong until the next reload.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_remove_allowed_user(): void {
		$is_ajax = $this->is_ajax_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! $user_id ) {
			if ( $is_ajax ) {
				wp_send_json_error( [ 'message' => __( 'Something went wrong. Reload the page and try again.', 'albert-ai-butler' ) ] );
			}

			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'remove_user_' . $user_id ) ) {
			if ( $is_ajax ) {
				wp_send_json_error( [ 'message' => __( 'Security check failed.', 'albert-ai-butler' ) ], 403 );
			}

			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability( __( 'You do not have permission to manage MCP access.', 'albert-ai-butler' ), $is_ajax );

		AllowedUsers::remove( $user_id );

		Settings::revoke_user_tokens( $user_id );

		$message = __( 'User removed. Every assistant they had connected was signed out.', 'albert-ai-butler' );

		if ( $is_ajax ) {
			ob_start();
			$this->render_allowed_users_body( AllowedUsers::all() );
			$users_html = (string) ob_get_clean();

			$connections = $this->get_connections();

			ob_start();
			$this->render_connections_body( $connections );
			$connections_html = (string) ob_get_clean();

			ob_start();
			$this->render_connections_count_badge( count( $connections ) );
			$count_html = (string) ob_get_clean();

			wp_send_json_success(
				[
					'message'              => $message,
					'removedId'            => $user_id,
					'usersBodyHtml'        => $users_html,
					'connectionsHtml'      => $connections_html,
					'connectionsCountHtml' => $count_html,
					'hasConnections'       => ! empty( $connections ),
				]
			);
		}

		$this->notify( 'user_removed', $message );

		$this->redirect_to_page();
	}

	/**
	 * Handle revoking a single user session.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_user_session(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$token_id = isset( $_GET['token_id'] ) ? absint( $_GET['token_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! $token_id || ! $user_id ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_session_' . $token_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability( __( 'You do not have permission to revoke sessions.', 'albert-ai-butler' ) );

		global $wpdb;

		$tables = Tables::oauth();

		/*
		 * Revoke what can revive it, before revoking it.
		 *
		 * Revoking the access token alone left the refresh token live, so the
		 * assistant minted a replacement within the hour while the screen said
		 * "Session revoked." That made revoking one session quietly weaker than
		 * revoking all of them, which has always gone through
		 * Settings::revoke_user_tokens() and does revoke both.
		 *
		 * The refresh row names the access token by its `token_id` string, not
		 * by the row id the link carries, so it has to be read first.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$access_token_id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT token_id FROM %i WHERE id = %d',
				$tables['access_tokens'],
				$token_id
			)
		);

		if ( is_string( $access_token_id ) && $access_token_id !== '' ) {
			( new RefreshTokenRepository() )->revokeForAccessTokens( [ $access_token_id ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$tables['access_tokens'],
			[ 'revoked' => 1 ],
			[ 'id' => $token_id ],
			[ '%d' ],
			[ '%d' ]
		);

		$this->notify( 'session_revoked', __( 'Session revoked.', 'albert-ai-butler' ) );

		$this->redirect_to_user_sessions( $user_id );
	}

	/**
	 * Handle revoking all sessions for a user.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_all_user_sessions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! $user_id ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_all_sessions_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability( __( 'You do not have permission to revoke sessions.', 'albert-ai-butler' ) );

		Settings::revoke_user_tokens( $user_id );

		$this->notify( 'all_sessions_revoked', __( 'All sessions revoked.', 'albert-ai-butler' ) );

		$this->redirect_to_user_sessions( $user_id );
	}

	/**
	 * Save (or clear) the owner's own name for a connection.
	 *
	 * Who wrote the label and when are stored with it, and rendered on the row.
	 * A label is a display name one administrator can write onto somebody else's
	 * connection, on the screen where an owner decides what looks trustworthy;
	 * the attribution is what keeps a misleading rename visible on sight.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function handle_set_connection_label(): void {
		$client_id = isset( $_POST['albert_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['albert_client_id'] ) ) : '';

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['albert_label_nonce'] ?? '' ) ), 'albert_set_connection_label_' . $client_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		$this->require_capability( __( 'You do not have permission to manage connections.', 'albert-ai-butler' ) );

		$label = isset( $_POST['albert_connection_label'] ) ? sanitize_text_field( wp_unslash( $_POST['albert_connection_label'] ) ) : '';

		$saved = ( new ClientRepository() )->updateClientLabel( $client_id, $label, get_current_user_id() );

		if ( ! $saved ) {
			$this->notify( 'label_save_failed', __( 'Could not save the label. Please try again.', 'albert-ai-butler' ), 'error' );
			$this->redirect_to_page();
			return;
		}

		$this->notify(
			'label_saved',
			$label === ''
				? __( 'Label removed.', 'albert-ai-butler' )
				: __( 'Label saved.', 'albert-ai-butler' )
		);

		$this->redirect_to_page();
	}

	/**
	 * Stop anyone without the capability, with a message that says which action failed.
	 *
	 * @param string $message The message to die with. Defaults to the revoke wording.
	 * @param bool   $is_ajax Send a JSON error instead of dying, for a handler the
	 *                        picker's own `fetch()` call may have made.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function require_capability( string $message = '', bool $is_ajax = false ): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = $message !== ''
			? $message
			: __( 'You do not have permission to manage connections.', 'albert-ai-butler' );

		if ( $is_ajax ) {
			wp_send_json_error( [ 'message' => $message ], 403 );
		}

		wp_die( esc_html( $message ) );
	}

	/**
	 * Whether this request is the picker's own `fetch()` call rather than a
	 * native form submission.
	 *
	 * Only the Connections screen has an allowed-users list on the page to
	 * update in place; the Dashboard's onboarding checklist opens the same
	 * dialog but has nowhere to insert a new row, so it keeps the plain
	 * submit-and-redirect behaviour. The header is set explicitly by the
	 * picker's JS, this is not `admin-ajax.php` so `wp_doing_ajax()` would
	 * always be false here regardless of transport.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	private function is_ajax_request(): bool {
		return isset( $_SERVER['HTTP_X_REQUESTED_WITH'] )
			&& strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ) === 'xmlhttprequest';
	}

	/**
	 * Queue a notice that survives the redirect back to the screen.
	 *
	 * `add_settings_error()` alone does not: the message lives in a global that
	 * the redirect throws away. WordPress reads the transient back only when the
	 * URL carries `settings-updated`, which {@see self::redirect_to_page()} adds.
	 *
	 * @param string $code    Notice identifier.
	 * @param string $message The message to show.
	 * @param string $type    `success`, `error`, `warning` or `info`.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function notify( string $code, string $message, string $type = 'success' ): void {
		add_settings_error( 'albert_connections', $code, $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	/**
	 * Redirect back to the Connections screen.
	 *
	 * @param bool $updated Whether to flag the redirect as a completed update.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function redirect_to_page( bool $updated = true ): void {
		$args = [ 'page' => $this->page_slug ];

		if ( $updated ) {
			$args['settings-updated'] = 'true';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Send the picker back to whichever screen opened it.
	 *
	 * The value is matched against a fixed set rather than trusted: it arrives
	 * in the request, and an open redirect built out of a "which screen was I
	 * on" field is a classic way to get one.
	 *
	 * @param string $return_to The posted screen key.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function redirect_after_add( string $return_to ): void {
		if ( $return_to === 'dashboard' ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'             => 'albert',
						'settings-updated' => 'true',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$this->redirect_to_page();
	}

	/**
	 * Redirect back to a user's session drill-down.
	 *
	 * @param int $user_id The user whose sessions were being viewed.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function redirect_to_user_sessions( int $user_id ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => $this->page_slug,
					'action'           => 'view_user_sessions',
					'user_id'          => $user_id,
					'settings-updated' => 'true',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/*
	---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------
	 */

	/**
	 * Render the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$view_sessions = isset( $_GET['action'] ) && $_GET['action'] === 'view_user_sessions';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$view_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( $view_sessions && $view_user_id ) {
			$this->render_user_sessions_view( $view_user_id );
			return;
		}

		$endpoint       = McpServer::get_endpoint_url();
		$external_state = McpServer::get_external_url_state();

		?>
		<div class="wrap albert-connections">
			<div class="albert-page albert-connections__page">
				<div class="albert-page__header">
					<div class="albert-page__text">
						<h1 class="albert-page__title"><?php esc_html_e( 'Connections', 'albert-ai-butler' ); ?></h1>
						<p class="albert-page__description">
							<?php esc_html_e( 'Choose who may connect an AI assistant, and review the assistants that are connected right now.', 'albert-ai-butler' ); ?>
						</p>
					</div>
				</div>
				<hr class="wp-header-end">

				<?php Notices::render( 'albert_connections' ); ?>

				<div class="albert-page__body">
					<?php $this->render_endpoint_card( $endpoint, $external_state ); ?>

					<div class="albert-connections__grid">
						<?php $this->render_connections_card(); ?>
						<?php $this->render_allowed_users_card(); ?>
					</div>

					<?php $this->render_setup_card( $endpoint ); ?>
				</div>
			</div>
		</div>
		<?php

		$this->render_disconnect_dialog();
		UserPickerModal::render( 'connections' );
	}

	/**
	 * The endpoint card: what this URL is, and whether it can be reached.
	 *
	 * Reachability comes with the URL rather than after it. A `.test` site shows
	 * a perfectly valid endpoint that no cloud assistant can ever open, and
	 * finding that out an hour later is the single most expensive way to learn
	 * it. At most one hint shows at a time: stacking two boxes under one field
	 * is how a calm card turns into a wall.
	 *
	 * @param string                              $endpoint       The MCP endpoint URL.
	 * @param array{state: string, value: string} $external_state External-URL filter state.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_endpoint_card( string $endpoint, array $external_state ): void {
		?>
		<section class="albert-card albert-connections__endpoint">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'MCP endpoint', 'albert-ai-butler' ); ?></h2>
					<p class="albert-card__description"><?php esc_html_e( 'Add this URL to your AI assistant as an MCP connector.', 'albert-ai-butler' ); ?></p>
				</div>
			</div>
			<div class="albert-card__body">
				<div class="albert-endpoint">
					<label class="screen-reader-text" for="albert-endpoint-url"><?php esc_html_e( 'MCP endpoint address', 'albert-ai-butler' ); ?></label>
					<input
						type="text"
						id="albert-endpoint-url"
						class="albert-endpoint__field"
						value="<?php echo esc_url( $endpoint ); ?>"
						readonly
					/>
					<button type="button" class="button button-secondary albert-copy-button" data-copy-target="albert-endpoint-url">
						<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
					</button>
				</div>

				<?php $this->render_endpoint_hint( $external_state ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * The one hint under the endpoint field, if any.
	 *
	 * Reachability messaging (a warning when this site cannot be reached from
	 * the internet) is deliberately not built yet: few sites are run this way,
	 * a proper local-site story is coming separately (doc 60, `wp albert serve`,
	 * over stdio rather than a web address at all), and a generic warning here
	 * now would only need rewriting once that lands; worse, on the exact sites
	 * doc 60 is for, it read as confusing rather than helpful.
	 * {@see self::render_setup_card()} does not gate on reachability either, for
	 * the same reason: a guide that silently disappears is more confusing than
	 * one that fails against a site the internet cannot reach.
	 *
	 * The one thing still surfaced unconditionally is the external-URL override,
	 * because it answers "why does the address above not match this site's own
	 * web address" and nothing else on this screen does.
	 *
	 * @param array{state: string, value: string} $external_state External-URL filter state.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_endpoint_hint( array $external_state ): void {
		if ( $external_state['state'] === 'invalid' ) {
			$this->render_external_url_invalid_hint( $external_state );
			return;
		}

		if ( $external_state['state'] !== 'active' ) {
			return;
		}

		?>
		<div class="albert-hint albert-hint--info albert-connections__reach">
			<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
			<p>
				<?php
				printf(
					/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name. */
					esc_html__( 'External override active: the address above comes from the %1$salbert/mcp/external_url%2$s filter, not from this site\'s own web address.', 'albert-ai-butler' ),
					'<code>',
					'</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * The hint shown when the external-URL filter returns something unusable.
	 *
	 * @param array{state: string, value: string} $state Filter state.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_external_url_invalid_hint( array $state ): void {
		?>
		<div class="albert-hint albert-hint--warning albert-connections__reach">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<div>
				<p>
					<strong><?php esc_html_e( 'The external address filter is returning something Albert cannot use.', 'albert-ai-butler' ); ?></strong>
				</p>
				<p>
					<?php
					printf(
						/* translators: 1: opening <code>, 2: closing </code> for the filter name, 3: opening <code>, 4: closing </code> for the invalid value, 5: the invalid value. */
						esc_html__( 'The %1$salbert/mcp/external_url%2$s filter returned %3$s%5$s%4$s, which is not a valid web address. Albert is ignoring it and using this site\'s own address instead.', 'albert-ai-butler' ),
						'<code>',
						'</code>',
						'<code>',
						'</code>',
						esc_html( (string) $state['value'] )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/*
	---------------------------------------------------------------------
	 * Connected assistants
	 * ------------------------------------------------------------------
	 */

	/**
	 * The count badge's contents, without the wrapping `<span>`: reused as-is
	 * so a caller that only needs to refresh the count (removing an allowed
	 * user can revoke a connection's only token elsewhere on the page) sends
	 * back the exact same escaped, pluralised markup a full page load would.
	 *
	 * @param int $count How many connections there are.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_connections_count_badge( int $count ): void {
		echo esc_html( number_format_i18n( $count ) );
		?>
		<span class="screen-reader-text">
			<?php
			printf(
				/* translators: %d: number of connected assistants. */
				esc_html( _n( '%d connection', '%d connections', $count, 'albert-ai-butler' ) ),
				(int) $count
			);
			?>
		</span>
		<?php
	}

	/**
	 * The part of the connections card that changes when a token is revoked:
	 * either the empty state or the row list. Its own method for the same
	 * reason as {@see self::render_allowed_users_body()}: removing an allowed
	 * user can revoke a connection elsewhere on the page, and that response
	 * sends back exactly what a fresh page load would render here, not a
	 * second copy of this markup living in JS.
	 *
	 * @param array<int, array<string, mixed>> $connections Rows from {@see self::get_connections()}.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_connections_body( array $connections ): void {
		if ( empty( $connections ) ) {
			?>
			<div class="albert-card__body">
				<p class="albert-connections__empty">
					<?php esc_html_e( 'Nothing is connected yet. Add someone under "Who may connect", then follow the steps for your assistant at the bottom of this page.', 'albert-ai-butler' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="albert-card__body albert-card__body--flush albert-connlist">
			<ul class="albert-connlist__rows" aria-label="<?php esc_attr_e( 'Connected assistants', 'albert-ai-butler' ); ?>">
				<?php foreach ( $connections as $index => $connection ) { ?>
					<?php $this->render_connection_row( $connection, 'flat-' . $index ); ?>
				<?php } ?>
			</ul>
		</div>

		<p class="screen-reader-text" aria-live="polite" data-albert-selection-status></p>
		<?php
	}

	/**
	 * The connections card: one row per connected client, not one per token.
	 *
	 * A client refreshes its access token roughly hourly, so a list keyed on
	 * tokens shows the same assistant several times over and calls each one a
	 * connection. The thing an owner recognises, labels and revokes is the
	 * client.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_connections_card(): void {
		$connections = $this->get_connections();
		$count       = count( $connections );

		?>
		<section class="albert-card albert-connections__assistants" data-albert-connections>
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Connected assistants', 'albert-ai-butler' ); ?></h2>
					<p class="albert-card__description"><?php esc_html_e( 'Names come from the connecting app, so several may look alike. Give one a label to tell them apart.', 'albert-ai-butler' ); ?></p>
				</div>
				<span class="albert-badge" data-albert-connections-count>
					<?php $this->render_connections_count_badge( $count ); ?>
				</span>
			</div>

			<?php $this->render_connections_toolbar( $count ); ?>

			<div data-albert-connections-body>
				<?php $this->render_connections_body( $connections ); ?>
			</div>

			<div class="albert-card__body albert-connections__footer">
				<?php
				$revoke_all_url = wp_nonce_url(
					add_query_arg(
						[
							'page'   => $this->page_slug,
							'action' => 'revoke_all',
						],
						admin_url( 'admin.php' )
					),
					'albert_revoke_all_connections'
				);
				?>
				<p class="albert-connections__footnote">
					<?php esc_html_e( 'Revoking signs that assistant out immediately.', 'albert-ai-butler' ); ?>
					<a href="<?php echo esc_url( $revoke_all_url ); ?>"
						class="albert-danger-link"
						data-albert-disconnect-all
						onclick="return confirm('<?php echo esc_js( __( 'Disconnect every assistant? Each one has to be approved again before it can reconnect.', 'albert-ai-butler' ) ); ?>');"
						<?php echo $count > 0 ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Disconnect all', 'albert-ai-butler' ); ?>
					</a>
				</p>
			</div>
		</section>
		<?php
	}

	/**
	 * Filter and Select, always rendered, whatever the row count.
	 *
	 * The bulk form lives here as an empty shell. Row checkboxes join it through
	 * the HTML `form` attribute rather than by being wrapped in it, because each
	 * row also carries its own label-editing form and forms cannot nest.
	 *
	 * @param int $count How many connections there are.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_connections_toolbar( int $count ): void {
		?>
		<form
			id="<?php echo esc_attr( self::BULK_FORM_ID ); ?>"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="albert-connlist__form"
			data-albert-bulk-form>
			<?php wp_nonce_field( 'albert_revoke_selected', 'albert_bulk_nonce' ); ?>
			<input type="hidden" name="action" value="albert_revoke_selected" />
		</form>

		<div class="albert-card__body albert-connlist__toolbar">
			<div class="albert-search albert-connlist__filter">
				<span class="dashicons dashicons-search albert-search__icon" aria-hidden="true"></span>
				<input
					type="search"
					class="albert-search__input"
					data-albert-filter
					autocomplete="off"
					aria-label="<?php esc_attr_e( 'Filter connections by label or person', 'albert-ai-butler' ); ?>"
					placeholder="<?php esc_attr_e( 'Filter by label or person', 'albert-ai-butler' ); ?>"
				/>
			</div>

			<button
				type="button"
				class="albert-toggle-button"
				data-albert-select-toggle
				aria-pressed="false">
				<?php esc_html_e( 'Select', 'albert-ai-butler' ); ?>
			</button>
		</div>

		<div class="albert-bulkbar" data-albert-bulkbar hidden>
			<span class="albert-bulkbar__check">
				<input type="checkbox" id="albert-bulk-all" data-albert-bulk-all />
				<label for="albert-bulk-all" data-albert-bulk-count><?php esc_html_e( 'Nothing selected', 'albert-ai-butler' ); ?></label>
			</span>

			<button type="button" class="albert-link-button" data-albert-select-filtered>
				<?php
				printf(
					/* translators: %d: number of connections matching the current filter. */
					esc_html__( 'Select all %d that match this filter', 'albert-ai-butler' ),
					(int) $count
				);
				?>
			</button>

			<button
				type="submit"
				form="<?php echo esc_attr( self::BULK_FORM_ID ); ?>"
				class="albert-button-danger"
				data-albert-bulk-revoke
				data-albert-confirm="<?php esc_attr_e( 'Revoke the selected connections? Each one has to be approved again before it can reconnect.', 'albert-ai-butler' ); ?>"
				disabled>
				<?php esc_html_e( 'Revoke selected', 'albert-ai-butler' ); ?>
			</button>

			<button type="button" class="button" data-albert-select-done><?php esc_html_e( 'Done', 'albert-ai-butler' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render one connection row.
	 *
	 * Every place a label is echoed goes through this one method (the visible
	 * name, the filter's search index, the checkbox's accessible name, the
	 * dialog's data attribute and the edit field's value), so "escaped at every
	 * render site" is a property of one function rather than a promise spread
	 * over five.
	 *
	 * @param array<string, mixed> $connection A row from {@see self::get_connections()}.
	 * @param string               $scope      A per-view suffix keeping element ids unique.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_connection_row( array $connection, string $scope ): void {
		$client_id  = (string) $connection['client_id'];
		$name       = (string) $connection['name'];
		$label      = (string) $connection['label'];
		$title      = $label !== '' ? $label : $name;
		$never_used = (int) $connection['last_used_ts'] === 0;
		$people     = $this->people_for( $connection );

		$checkbox_id = 'albert-conn-' . $scope;
		$label_id    = 'albert-conn-label-' . $scope;
		$meta        = $this->format_connection_meta( $connection, $people );

		$revoke_url = wp_nonce_url(
			add_query_arg(
				[
					'page'      => $this->page_slug,
					'action'    => 'revoke_client',
					'client_id' => $client_id,
				],
				admin_url( 'admin.php' )
			),
			'albert_revoke_client_' . $client_id
		);

		$revoke_full_url = wp_nonce_url(
			add_query_arg(
				[
					'page'      => $this->page_slug,
					'action'    => 'revoke_client_full',
					'client_id' => $client_id,
				],
				admin_url( 'admin.php' )
			),
			'albert_revoke_client_full_' . $client_id
		);

		$classes = 'albert-conn' . ( $never_used ? ' albert-conn--quiet' : '' );

		$select_label = sprintf(
			/* translators: 1: connection name, 2: the row's own meta line. */
			__( 'Select %1$s. %2$s', 'albert-ai-butler' ),
			$title,
			$meta
		);

		?>
		<li
			class="<?php echo esc_attr( $classes ); ?>"
			data-albert-row
			data-client-id="<?php echo esc_attr( $client_id ); ?>"
			data-search="<?php echo esc_attr( $this->search_index( $title, $name, $people ) ); ?>">
			<input
				type="checkbox"
				class="albert-conn__check"
				id="<?php echo esc_attr( $checkbox_id ); ?>"
				name="client_ids[]"
				form="<?php echo esc_attr( self::BULK_FORM_ID ); ?>"
				value="<?php echo esc_attr( $client_id ); ?>"
				data-albert-row-check
				aria-label="<?php echo esc_attr( $select_label ); ?>"
				disabled />

			<div class="albert-conn__body">
				<div class="albert-conn__head">
					<label class="albert-conn__title" id="<?php echo esc_attr( $label_id ); ?>" for="<?php echo esc_attr( $checkbox_id ); ?>" data-albert-naming-display>
						<?php echo esc_html( $title ); ?>
					</label>

					<?php if ( $label !== '' ) { ?>
						<span class="albert-conn__client"><?php echo esc_html( $name ); ?></span>
					<?php } ?>

					<button
						type="button"
						class="albert-link-button albert-conn__naming-trigger"
						data-albert-naming-trigger
						data-albert-naming-display>
						<?php echo $label === '' ? esc_html__( '+ Name this connection', 'albert-ai-butler' ) : esc_html__( 'Edit', 'albert-ai-butler' ); ?>
					</button>

					<form
						method="post"
						action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						class="albert-conn__naming-form"
						data-albert-naming-form
						hidden>
						<?php
						// Written out rather than wp_nonce_field(): that helper gives every
						// field an id taken from its name, and this form is rendered once per
						// row, so the page would otherwise carry the same element id once per
						// connection.
						?>
						<input type="hidden" name="albert_label_nonce" value="<?php echo esc_attr( wp_create_nonce( 'albert_set_connection_label_' . $client_id ) ); ?>" />
						<input type="hidden" name="action" value="albert_set_connection_label" />
						<input type="hidden" name="albert_client_id" value="<?php echo esc_attr( $client_id ); ?>" />
						<label class="screen-reader-text" for="albert-label-<?php echo esc_attr( $scope ); ?>">
							<?php
							printf(
								/* translators: %s: the client's own name. */
								esc_html__( 'Your name for the %s connection', 'albert-ai-butler' ),
								esc_html( $name )
							);
							?>
						</label>
						<input
							type="text"
							id="albert-label-<?php echo esc_attr( $scope ); ?>"
							name="albert_connection_label"
							value="<?php echo esc_attr( $label ); ?>"
							maxlength="255"
							placeholder="<?php esc_attr_e( 'Studio iMac', 'albert-ai-butler' ); ?>"
							class="albert-conn__naming-input"
							data-albert-naming-input
							aria-describedby="albert-naming-help-<?php echo esc_attr( $scope ); ?>"
						/>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'albert-ai-butler' ); ?></button>
						<button type="button" class="albert-link-button" data-albert-naming-cancel>
							<?php esc_html_e( 'Cancel', 'albert-ai-butler' ); ?>
						</button>
						<span class="screen-reader-text" id="albert-naming-help-<?php echo esc_attr( $scope ); ?>">
							<?php esc_html_e( 'Leave it empty to remove the label.', 'albert-ai-butler' ); ?>
						</span>
					</form>

					<button
						type="button"
						class="albert-button-danger albert-conn__revoke albert-disconnect-trigger"
						data-client-name="<?php echo esc_attr( $title ); ?>"
						data-revoke-url="<?php echo esc_url( $revoke_url ); ?>"
						data-revoke-full-url="<?php echo esc_url( $revoke_full_url ); ?>">
						<?php esc_html_e( 'Revoke', 'albert-ai-butler' ); ?>
					</button>
				</div>

				<p class="albert-conn__meta"><?php echo esc_html( $meta ); ?></p>

				<?php $attribution = $this->format_label_attribution( $connection ); ?>
				<?php if ( $attribution !== '' ) { ?>
					<p class="albert-conn__attribution"><?php echo esc_html( $attribution ); ?></p>
				<?php } ?>
			</div>
		</li>
		<?php
	}

	/**
	 * The two-option disconnect dialog.
	 *
	 * Immediate and, for the second option, irreversible without the assistant
	 * being approved again, so it gets a confirmation step, and the confirmation
	 * is where the difference between the two is explained rather than being
	 * guessed from a button label.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_disconnect_dialog(): void {
		?>
		<dialog id="albert-disconnect-dialog" class="albert-dialog" aria-labelledby="albert-disconnect-dialog-title">
			<div class="albert-dialog__header">
				<div class="albert-dialog__heading">
					<h2 class="albert-dialog__title" id="albert-disconnect-dialog-title"><?php esc_html_e( 'Disconnect?', 'albert-ai-butler' ); ?></h2>
				</div>
				<button type="button" class="albert-dialog__close albert-disconnect-dialog-close" aria-label="<?php esc_attr_e( 'Close', 'albert-ai-butler' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			</div>
			<div class="albert-dialog__body">
				<div class="albert-dialog__options">
					<a href="#" id="albert-disconnect-connection" class="albert-dialog__option">
						<strong><?php esc_html_e( 'Sign it out', 'albert-ai-butler' ); ?></strong>
						<span><?php esc_html_e( 'Ends the current session. The assistant signs back in on its own within the hour, without anyone approving anything.', 'albert-ai-butler' ); ?></span>
					</a>
					<a href="#" id="albert-disconnect-session" class="albert-dialog__option albert-dialog__option--destructive">
						<strong><?php esc_html_e( 'Disconnect completely', 'albert-ai-butler' ); ?></strong>
						<span><?php esc_html_e( 'Ends the session and the sign-in behind it. An allowed user has to approve the assistant again before it can come back.', 'albert-ai-butler' ); ?></span>
					</a>
				</div>
			</div>
			<div class="albert-dialog__footer">
				<button type="button" class="button albert-disconnect-cancel">
					<?php esc_html_e( 'Cancel', 'albert-ai-butler' ); ?>
				</button>
			</div>
		</dialog>
		<?php
	}

	/*
	---------------------------------------------------------------------
	 * Who may connect
	 * ------------------------------------------------------------------
	 */

	/**
	 * The part of the allowed-users card that changes: either the empty state
	 * or the row list. Its own method so the picker's AJAX response can render
	 * exactly what the page would have rendered, rather than a second copy of
	 * this markup living in JS.
	 *
	 * @param array<int, array{added_at: int|null, authorised_at: int|null, expires_at: int|null}> $allowed_users The allowed users, from {@see \Albert\OAuth\AllowedUsers::all()}.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_allowed_users_body( array $allowed_users ): void {
		if ( empty( $allowed_users ) ) {
			?>
			<div class="albert-card__body">
				<p class="albert-connections__empty">
					<?php esc_html_e( 'Nobody may connect an assistant yet. Choose someone with "Add user".', 'albert-ai-butler' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="albert-card__body albert-card__body--flush albert-userlist">
			<ul class="albert-userlist__rows" aria-label="<?php esc_attr_e( 'Users who may approve an assistant', 'albert-ai-butler' ); ?>">
				<?php foreach ( $allowed_users as $user_id => $entry ) { ?>
					<?php $this->render_allowed_user_row( (int) $user_id, $entry ); ?>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * The allowed-users card.
	 *
	 * The search field is in the modal, so the only control beside the title is
	 * the button that opens it. The filter in the body filters the list that is
	 * already here, which is a different question from "who else exists".
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_allowed_users_card(): void {
		$allowed_users = AllowedUsers::all();

		?>
		<section class="albert-card albert-connections__allowed">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Who may connect', 'albert-ai-butler' ); ?></h2>
					<p class="albert-card__description"><?php esc_html_e( 'Only these users can approve an AI assistant. Everyone else is refused at sign-in.', 'albert-ai-butler' ); ?></p>
				</div>
				<button type="button" class="button button-primary" data-albert-open-userpicker>
					<?php esc_html_e( 'Add user', 'albert-ai-butler' ); ?>
				</button>
			</div>

			<div class="albert-card__body albert-userlist__toolbar">
				<div class="albert-search">
					<span class="dashicons dashicons-search albert-search__icon" aria-hidden="true"></span>
					<input
						type="search"
						class="albert-search__input"
						data-albert-user-filter
						autocomplete="off"
						aria-label="<?php esc_attr_e( 'Filter the allowed users below by name or email', 'albert-ai-butler' ); ?>"
						placeholder="<?php esc_attr_e( 'Filter by name or email', 'albert-ai-butler' ); ?>"
					/>
				</div>
			</div>

			<div data-albert-userlist-body>
				<?php $this->render_allowed_users_body( $allowed_users ); ?>
			</div>

			<div class="albert-card__body albert-connections__footer">
				<p class="albert-connections__footnote">
					<?php esc_html_e( 'Removing someone revokes their access immediately, including any assistant they had approved.', 'albert-ai-butler' ); ?>
				</p>
			</div>
		</section>
		<?php
	}

	/**
	 * Render one allowed user.
	 *
	 * @param int                                                                      $user_id The user ID.
	 * @param array{added_at: int|null, authorised_at: int|null, expires_at: int|null} $entry   Their entry from {@see \Albert\OAuth\AllowedUsers::all()}.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_allowed_user_row( int $user_id, array $entry ): void {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return;
		}

		$session_count = $this->get_user_session_count( $user_id );

		$remove_url = wp_nonce_url(
			add_query_arg(
				[
					'page'    => $this->page_slug,
					'action'  => 'remove_allowed_user',
					'user_id' => $user_id,
				],
				admin_url( 'admin.php' )
			),
			'remove_user_' . $user_id
		);

		$sessions_url = add_query_arg(
			[
				'page'    => $this->page_slug,
				'action'  => 'view_user_sessions',
				'user_id' => $user_id,
			],
			admin_url( 'admin.php' )
		);

		$meta   = $user->user_email . ' · ' . $this->role_label_for( $user );
		$expiry = $this->format_allowed_user_expiry( $entry );

		?>
		<li class="albert-user-item" data-albert-user-row data-user-id="<?php echo esc_attr( (string) $user_id ); ?>" data-search="<?php echo esc_attr( $this->normalise( $user->display_name . ' ' . $meta ) ); ?>">
			<span class="albert-user-item__avatar" aria-hidden="true">
				<span class="dashicons dashicons-admin-users"></span>
			</span>
			<div class="albert-user-item__text">
				<strong class="albert-user-item__name"><?php echo esc_html( $user->display_name ); ?></strong>
				<span class="albert-user-item__meta"><?php echo esc_html( $meta ); ?></span>
				<?php if ( $expiry !== '' ) { ?>
					<span class="albert-user-item__expiry"><?php echo esc_html( $expiry ); ?></span>
				<?php } ?>
			</div>
			<div class="albert-user-item__sessions">
				<?php if ( $session_count > 0 ) { ?>
					<a href="<?php echo esc_url( $sessions_url ); ?>" class="albert-badge albert-badge--success">
						<?php
						printf(
							/* translators: %d: number of sessions. */
							esc_html( _n( '%d session', '%d sessions', $session_count, 'albert-ai-butler' ) ),
							(int) $session_count
						);
						?>
					</a>
				<?php } else { ?>
					<span class="albert-badge"><?php esc_html_e( 'No sessions', 'albert-ai-butler' ); ?></span>
				<?php } ?>
			</div>
			<div class="albert-user-item__actions">
				<a href="<?php echo esc_url( $remove_url ); ?>"
					class="albert-danger-link"
					data-albert-remove-user
					data-albert-confirm="<?php echo esc_attr__( 'Remove this user? Every assistant they have connected is signed out straight away.', 'albert-ai-butler' ); ?>">
					<?php esc_html_e( 'Remove', 'albert-ai-butler' ); ?>
				</a>
			</div>
		</li>
		<?php
	}

	/*
	---------------------------------------------------------------------
	 * Connect an assistant
	 * ------------------------------------------------------------------
	 */

	/**
	 * The per-client setup card.
	 *
	 * Every guide is shown regardless of whether this host is reachable from
	 * the internet: {@see self::render_endpoint_hint()} explains why Albert does
	 * not run that check.
	 *
	 * @param string $endpoint The MCP endpoint URL.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_setup_card( string $endpoint ): void {
		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Connect an assistant', 'albert-ai-butler' ); ?></h2>
					<p class="albert-card__description"><?php esc_html_e( 'Steps for each assistant we have checked. Open the one you use.', 'albert-ai-butler' ); ?></p>
				</div>
			</div>
			<div class="albert-card__body albert-card__body--flush">
				<?php foreach ( ClientSetupGuides::all( $endpoint ) as $guide ) { ?>
					<?php $this->render_setup_guide( $guide ); ?>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render one client's setup steps.
	 *
	 * A `<details>` rather than a scripted accordion: it opens with the keyboard,
	 * it is announced correctly, and it works before any JavaScript loads.
	 *
	 * @param array<string, mixed> $guide One entry from {@see ClientSetupGuides}.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_setup_guide( array $guide ): void {
		$id          = (string) $guide['id'];
		$snippet_id  = 'albert-snippet-' . $id;
		$config_path = (string) $guide['config_path'];
		$deeplink    = (string) $guide['deeplink'];

		?>
		<details class="albert-guide" id="albert-guide-<?php echo esc_attr( $id ); ?>">
			<summary class="albert-guide__summary">
				<span class="dashicons dashicons-arrow-right-alt2 albert-guide__chevron" aria-hidden="true"></span>
				<span class="albert-guide__label"><?php echo esc_html( (string) $guide['label'] ); ?></span>
				<span class="albert-guide__description"><?php echo esc_html( (string) $guide['description'] ); ?></span>
			</summary>
			<div class="albert-guide__body">
				<?php if ( $config_path !== '' ) { ?>
					<p class="albert-guide__path">
						<span class="albert-guide__path-label"><?php esc_html_e( 'File', 'albert-ai-butler' ); ?></span>
						<code><?php echo esc_html( $config_path ); ?></code>
					</p>
				<?php } ?>

				<ol class="albert-guide__steps">
					<?php foreach ( (array) $guide['steps'] as $step ) { ?>
						<li><?php echo esc_html( (string) $step ); ?></li>
					<?php } ?>
				</ol>

				<?php if ( (string) $guide['snippet'] !== '' ) { ?>
					<div class="albert-guide__snippet">
						<div class="albert-guide__snippet-head">
							<span class="albert-guide__snippet-label" id="<?php echo esc_attr( $snippet_id ); ?>-label">
								<?php echo esc_html( (string) $guide['snippet_label'] ); ?>
							</span>
							<button
								type="button"
								class="button button-small albert-copy-button"
								data-copy-target="<?php echo esc_attr( $snippet_id ); ?>"
								aria-describedby="<?php echo esc_attr( $snippet_id ); ?>-label">
								<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
							</button>
						</div>
						<pre class="albert-guide__code" id="<?php echo esc_attr( $snippet_id ); ?>"><?php echo esc_html( (string) $guide['snippet'] ); ?></pre>
					</div>
				<?php } ?>

				<?php if ( $deeplink !== '' ) { ?>
					<p class="albert-guide__deeplink">
						<a class="button button-secondary" href="<?php echo esc_url( $deeplink, [ 'http', 'https', 'cursor', 'vscode' ] ); ?>">
							<?php echo esc_html( (string) $guide['deeplink_label'] ); ?>
						</a>
						<span class="albert-guide__deeplink-note"><?php esc_html_e( 'Opens the app and fills this in for you.', 'albert-ai-butler' ); ?></span>
					</p>
				<?php } ?>
			</div>
		</details>
		<?php
	}

	/*
	---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------
	 */

	/**
	 * Every currently connected client, one entry each, most recently used
	 * first. Delegates to {@see ClientRepository::getLiveConnections()},
	 * which is now the single source of truth for "what counts as a
	 * connection": {@see \Albert\OAuth\ConnectionRetention}'s automatic
	 * sweeps read the exact same list, so a client is never dropped as
	 * never-used or idle while it is still something this screen shows as
	 * connected.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.4.0
	 */
	private function get_connections(): array {
		return ( new ClientRepository() )->getLiveConnections();
	}

	/**
	 * The display names of the people who authorised a connection.
	 *
	 * @param array<string, mixed> $connection A connection row.
	 *
	 * @return array<int, string>
	 * @since 1.4.0
	 */
	private function people_for( array $connection ): array {
		$names = [];

		foreach ( (array) $connection['user_ids'] as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( $user ) {
				$names[] = $user->display_name;
			}
		}

		return $names;
	}

	/**
	 * The one muted line under a connection's name.
	 *
	 * "Never used" is spelled out in words rather than left to the row's quieter
	 * colours: colour is the reinforcement, never the only signal.
	 *
	 * @param array<string, mixed> $connection A connection row.
	 * @param array<int, string>   $people     Display names of the authorising users.
	 *
	 * @return string A translated sentence.
	 * @since 1.4.0
	 */
	private function format_connection_meta( array $connection, array $people ): string {
		$authorised = empty( $people )
			? __( 'Authorised by an account that no longer exists', 'albert-ai-butler' )
			: sprintf(
				/* translators: %s: comma-separated list of user names. */
				__( 'Authorised by %s', 'albert-ai-butler' ),
				implode( ', ', $people )
			);

		$connected = sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 hours ago". */
			__( 'connected %s', 'albert-ai-butler' ),
			$this->format_ago( $connection['created_ts'] )
		);

		$used = (int) $connection['last_used_ts'] > 0
			? sprintf(
				/* translators: %s: human-readable time difference, e.g. "2 hours ago". */
				__( 'used %s', 'albert-ai-butler' ),
				$this->format_ago( $connection['last_used_ts'] )
			)
			: __( 'never used', 'albert-ai-butler' );

		return implode( ' · ', [ $authorised, $connected, $used ] );
	}

	/**
	 * "Labelled by Mark Jansen on 3 August 2026", or '' when there is nothing to say.
	 *
	 * A label is the one thing on this row that a person wrote about somebody
	 * else's connection, so it carries its own byline. Nothing is rendered for an
	 * unlabelled connection, or for a label written before the attribution
	 * columns existed: an invented byline would be worse than none.
	 *
	 * @param array<string, mixed> $connection A connection row.
	 *
	 * @return string A translated sentence, or ''.
	 * @since 1.4.0
	 */
	private function format_label_attribution( array $connection ): string {
		if ( (string) $connection['label'] === '' ) {
			return '';
		}

		$author_id = (int) $connection['label_set_by'];
		$moment    = (int) $connection['label_set_at'];

		if ( $author_id === 0 || $moment === 0 ) {
			return '';
		}

		$author = get_userdata( $author_id );

		return sprintf(
			/* translators: 1: the person who wrote the label, 2: the date they wrote it. */
			__( 'Labelled by %1$s on %2$s', 'albert-ai-butler' ),
			$author ? $author->display_name : __( 'a deleted account', 'albert-ai-butler' ),
			date_i18n( (string) get_option( 'date_format' ), $moment )
		);
	}

	/**
	 * Everything the client-side filter should match a row on.
	 *
	 * @param string             $title  The row's visible name.
	 * @param string             $name   The client's own name.
	 * @param array<int, string> $people Display names of the authorising users.
	 *
	 * @return string A lowercase haystack.
	 * @since 1.4.0
	 */
	private function search_index( string $title, string $name, array $people ): string {
		return $this->normalise( $title . ' ' . $name . ' ' . implode( ' ', $people ) );
	}

	/**
	 * Lowercase, collapsed whitespace: the shape both filters compare against.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 * @since 1.4.0
	 */
	private function normalise( string $value ): string {
		/*
		 * `mb_strtolower`, not `strtolower`. The value this produces is matched
		 * in the browser against `term.toLowerCase()`, which is Unicode-aware,
		 * while PHP's `strtolower` is byte-based and leaves anything outside
		 * ASCII alone. "Émile" indexed as "Émile" never matches a typed
		 * "émile", so the person disappears from a list they are plainly on.
		 */
		$lower = function_exists( 'mb_strtolower' )
			? mb_strtolower( $value, 'UTF-8' )
			: strtolower( $value );

		return trim( (string) preg_replace( '/\s+/', ' ', $lower ) );
	}

	/**
	 * "3 hours ago", or "never" for an empty timestamp.
	 *
	 * @param mixed $timestamp A UNIX timestamp, or 0/null when there is none.
	 *
	 * @return string A translated phrase.
	 * @since 1.4.0
	 */
	private function format_ago( $timestamp ): string {
		$timestamp = (int) $timestamp;

		if ( $timestamp <= 0 ) {
			return __( 'never', 'albert-ai-butler' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			__( '%s ago', 'albert-ai-butler' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * "Added 3 days ago", "Expires at 21 August 2026, 14:32", or "Invitation
	 * expired 20 August 2026, 09:14".
	 *
	 * An exercised invitation (an `authorised_at`) or one with no stored
	 * `expires_at` (expiry was off when it was granted) just says when the
	 * person was added: neither is ever refused, so there is nothing to show
	 * a deadline for. Otherwise it is always the exact date and time, not a
	 * relative countdown: a fixed deadline is simpler to act on at a glance
	 * than "in 11 hours" is, and it does not go stale the way a relative
	 * phrase would between page loads.
	 *
	 * @param array{added_at: int|null, authorised_at: int|null, expires_at: int|null} $entry The user's entry.
	 *
	 * @return string A translated phrase, or '' when there is no added_at to show.
	 * @since 1.4.0
	 */
	private function format_allowed_user_expiry( array $entry ): string {
		$added_at = $entry['added_at'];

		if ( $added_at === null ) {
			return '';
		}

		if ( $entry['authorised_at'] !== null || $entry['expires_at'] === null ) {
			return sprintf(
				/* translators: %s: human-readable time since they were added, e.g. "3 days ago". */
				__( 'Added %s', 'albert-ai-butler' ),
				$this->format_ago( $added_at )
			);
		}

		$expires_at = $entry['expires_at'];
		$when       = $this->format_datetime( $expires_at );

		if ( $expires_at <= time() ) {
			return sprintf(
				/* translators: %s: the date and time it expired. */
				__( 'Invitation expired %s', 'albert-ai-butler' ),
				$when
			);
		}

		return sprintf(
			/* translators: %s: the exact date and time it expires. */
			__( 'Expires at %s', 'albert-ai-butler' ),
			$when
		);
	}

	/**
	 * A UTC timestamp, converted to the site's timezone and formatted for
	 * the viewing admin's own language, e.g. "Aug 21, 2026, 2:32 PM" for an
	 * English admin, "21 aug. 2026, 14:32" for a Dutch one on the same site.
	 *
	 * Two things a naive `date_i18n()` call gets wrong, both fixed here:
	 *
	 * 1. Timezone: passed a raw timestamp, `date_i18n()` formats it as-is
	 *    without applying the site's UTC offset, which is correct for a
	 *    `current_time()`-derived value but silently wrong for a true UTC
	 *    one like the timestamps stored here. On any site not actually set
	 *    to UTC, that reads as the right time in the wrong zone.
	 * 2. Convention, not just translation: the site's `time_format` option
	 *    is one fixed pattern for the whole site (typically whatever the
	 *    installer's own language defaulted to), so relying on it always
	 *    shows the same 12- or 24-hour convention to every admin regardless
	 *    of which language *they* use. `IntlDateFormatter` derives the
	 *    correct convention (and date ordering) from
	 *    {@see get_user_locale()} instead, using ICU's own locale data
	 *    rather than a hand-maintained guess: no assumption here about
	 *    which languages prefer which.
	 *
	 * Falls back to the site's configured format (still timezone-corrected
	 * via `wp_date()`) when the `intl` extension is not loaded, so this
	 * degrades rather than fatals on a host without it.
	 *
	 * @param int $timestamp A UNIX (UTC) timestamp.
	 *
	 * @return string
	 * @since 1.4.0
	 */
	private function format_datetime( int $timestamp ): string {
		if ( class_exists( '\IntlDateFormatter' ) ) {
			$formatter = new \IntlDateFormatter(
				get_user_locale(),
				\IntlDateFormatter::MEDIUM,
				\IntlDateFormatter::SHORT,
				wp_timezone()
			);

			$formatted = $formatter->format( $timestamp );

			if ( $formatted !== false ) {
				return $formatted;
			}
		}

		$format    = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
		$formatted = wp_date( $format, $timestamp );

		return $formatted !== false ? $formatted : '';
	}

	/**
	 * A user's role, in the language of the admin.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return string The translated role name, or a dash when the user has none.
	 * @since 1.4.0
	 */
	private function role_label_for( \WP_User $user ): string {
		$names = wp_roles()->get_names();
		$role  = $user->roles[0] ?? '';

		if ( $role === '' || ! isset( $names[ $role ] ) ) {
			return __( 'No role on this site', 'albert-ai-butler' );
		}

		return translate_user_role( $names[ $role ] );
	}

	/**
	 * Get the number of active sessions for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return int The number of active sessions.
	 * @since 1.0.0
	 */
	private function get_user_session_count( int $user_id ): int {
		global $wpdb;

		$tables = Tables::oauth();

		// The same definition of "connected" the connections card uses, so the
		// pill beside a user and the list below it cannot disagree.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT( DISTINCT t.client_id )
				FROM %i t
				LEFT JOIN %i r
					ON r.access_token_id = t.token_id
					AND r.revoked = 0
					AND r.expires_at > UTC_TIMESTAMP()
				WHERE t.user_id = %d
					AND ( ( t.revoked = 0 AND t.expires_at > UTC_TIMESTAMP() ) OR r.id IS NOT NULL )',
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				$user_id
			)
		);
	}

	/**
	 * Render sessions view for a specific user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_user_sessions_view( int $user_id ): void {
		global $wpdb;

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'albert-ai-butler' ) . '</p></div></div>';
			return;
		}

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.client_id,
					MAX(t.id) as id,
					COALESCE(c.label, c.name, 'Unknown') as client_name,
					MIN( UNIX_TIMESTAMP( t.created_at ) ) as first_connected_ts
				FROM %i t
				LEFT JOIN %i c ON t.client_id = c.client_id
				WHERE t.user_id = %d AND t.revoked = 0
				GROUP BY t.client_id, c.label, c.name
				ORDER BY first_connected_ts DESC",
				$tables['access_tokens'],
				$tables['clients'],
				$user_id
			)
		);

		$back_url = add_query_arg( [ 'page' => $this->page_slug ], admin_url( 'admin.php' ) );

		?>
		<div class="wrap albert-connections">
			<div class="albert-page albert-connections__page">
				<div class="albert-page__header">
					<div class="albert-page__text">
						<h1 class="albert-page__title"><?php esc_html_e( 'Sessions', 'albert-ai-butler' ); ?></h1>
						<p class="albert-page__description">
							<?php
							printf(
								/* translators: 1: user display name, 2: user email address. */
								esc_html__( 'Assistants approved by %1$s (%2$s).', 'albert-ai-butler' ),
								esc_html( $user->display_name ),
								esc_html( $user->user_email )
							);
							?>
						</p>
					</div>
					<div class="albert-page__actions">
						<a href="<?php echo esc_url( $back_url ); ?>" class="button">
							<?php esc_html_e( 'Back to Connections', 'albert-ai-butler' ); ?>
						</a>
					</div>
				</div>
				<hr class="wp-header-end">

				<?php Notices::render( 'albert_connections' ); ?>

				<div class="albert-page__body">
					<section class="albert-card">
						<div class="albert-card__header">
							<div class="albert-card__text">
								<h2 class="albert-card__title"><?php esc_html_e( 'Approved assistants', 'albert-ai-butler' ); ?></h2>
								<p class="albert-card__description"><?php esc_html_e( 'Revoking a session disconnects that assistant straight away.', 'albert-ai-butler' ); ?></p>
							</div>
						</div>

						<?php if ( empty( $sessions ) ) { ?>
							<div class="albert-card__body">
								<p class="albert-connections__empty"><?php esc_html_e( 'No sessions. This user has not approved an assistant yet.', 'albert-ai-butler' ); ?></p>
							</div>
						<?php } else { ?>
							<div class="albert-card__body albert-card__body--flush albert-userlist">
								<ul class="albert-userlist__rows">
									<?php foreach ( $sessions as $session ) { ?>
										<?php
										$revoke_url = wp_nonce_url(
											add_query_arg(
												[
													'page' => $this->page_slug,
													'action' => 'revoke_user_session',
													'token_id' => $session->id,
													'user_id' => $user_id,
												],
												admin_url( 'admin.php' )
											),
											'revoke_session_' . $session->id
										);
										?>
										<li class="albert-user-item">
											<div class="albert-user-item__text">
												<strong class="albert-user-item__name"><?php echo esc_html( (string) $session->client_name ); ?></strong>
												<span class="albert-user-item__meta">
													<?php
													printf(
														/* translators: %s: human-readable time difference. */
														esc_html__( 'Connected %s', 'albert-ai-butler' ),
														esc_html( $this->format_ago( $session->first_connected_ts ) )
													);
													?>
												</span>
											</div>
											<div class="albert-user-item__actions">
												<a href="<?php echo esc_url( $revoke_url ); ?>"
													class="albert-danger-link"
													onclick="return confirm('<?php echo esc_js( __( 'Revoke this session?', 'albert-ai-butler' ) ); ?>');">
													<?php esc_html_e( 'Revoke', 'albert-ai-butler' ); ?>
												</a>
											</div>
										</li>
									<?php } ?>
								</ul>
							</div>

							<?php
							$revoke_all_url = wp_nonce_url(
								add_query_arg(
									[
										'page'    => $this->page_slug,
										'action'  => 'revoke_all_user_sessions',
										'user_id' => $user_id,
									],
									admin_url( 'admin.php' )
								),
								'revoke_all_sessions_' . $user_id
							);
							?>
							<div class="albert-card__body">
								<a href="<?php echo esc_url( $revoke_all_url ); ?>"
									class="albert-danger-link"
									onclick="return confirm('<?php echo esc_js( __( 'Revoke every session for this user?', 'albert-ai-butler' ) ); ?>');">
									<?php esc_html_e( 'Revoke all sessions', 'albert-ai-butler' ); ?>
								</a>
							</div>
						<?php } ?>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	/*
	---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------
	 */

	/**
	 * The capability a user must have to be offered in the picker.
	 *
	 * @return string A capability name, or '' to offer every user.
	 * @since 1.4.0
	 */
	public static function allowed_user_capability(): string {
		return UserPickerModal::candidate_capability();
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( Menu::PARENT_SLUG . '_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-connections',
			ALBERT_PLUGIN_URL . 'assets/css/admin-connections.css',
			[ Assets::PRIMITIVES_HANDLE ],
			Assets::version( 'assets/css/admin-connections.css' )
		);

		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'albert-admin-utils' ],
			Assets::version( 'assets/js/admin-settings.js' ),
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'albert_oauth_nonce' ),
				'i18n'    => [
					'copied'          => __( 'Copied!', 'albert-ai-butler' ),
					'copyFailed'      => __( 'Copy failed', 'albert-ai-butler' ),
					/* translators: %s: the connection's name. */
					'disconnectTitle' => __( 'Disconnect %s?', 'albert-ai-butler' ),
				],
			]
		);

		UserPickerModal::enqueue( 'connections' );

		wp_enqueue_script(
			'albert-admin-popover',
			ALBERT_PLUGIN_URL . 'assets/js/admin-popover.js',
			[],
			Assets::version( 'assets/js/admin-popover.js' ),
			true
		);
	}
}
