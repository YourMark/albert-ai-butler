<?php
/**
 * OAuth Authorization Page
 *
 * Handles the browser-based OAuth authorization flow.
 * This outputs HTML for user login and consent, not JSON.
 *
 * @package Albert
 * @subpackage OAuth\Endpoints
 * @since      1.0.0
 */

namespace Albert\OAuth\Endpoints;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Entities\UserEntity;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Server\AuthorizationServerFactory;
use Albert\OAuth\Server\DomainGuard;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * AuthorizationPage class
 *
 * Provides HTML-based OAuth authorization flow for browser clients.
 *
 * @since 1.0.0
 */
class AuthorizationPage implements Hookable {

	/**
	 * Query var for authorization page.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const QUERY_VAR = 'albert_oauth_authorize';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'init', [ $this, 'register_assets' ] );
		add_action( 'template_redirect', [ $this, 'handle_authorization' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_filter( 'redirect_canonical', [ $this, 'prevent_canonical_redirect' ], 10, 2 );
	}

	/**
	 * Register CSS and JS assets for OAuth pages.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_assets(): void {
		wp_register_style(
			'albert-oauth-pages',
			ALBERT_PLUGIN_URL . 'assets/css/oauth-pages.css',
			[],
			ALBERT_VERSION
		);

		wp_register_script(
			'albert-oauth-redirect',
			false,
			[],
			ALBERT_VERSION,
			true
		);
	}

	/**
	 * Enqueue the OAuth page stylesheet.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function enqueue_oauth_styles(): void {
		wp_enqueue_style( 'albert-oauth-pages' );
	}

	/**
	 * Prevent WordPress canonical redirect for OAuth endpoints.
	 *
	 * @param string $redirect_url  The redirect URL.
	 * @param string $requested_url The requested URL.
	 *
	 * @return string|false The redirect URL or false to prevent redirect.
	 * @since 1.0.0
	 */
	public function prevent_canonical_redirect( string $redirect_url, string $requested_url ): string|false {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Add rewrite rules for OAuth endpoints.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_rewrite_rules(): void {
		// /oauth/authorize - Authorization endpoint.
		add_rewrite_rule(
			'^oauth/authorize/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Add custom query vars.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 *
	 * @return array<int, string> Modified query vars.
	 * @since 1.0.0
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Handle authorization request.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_authorization(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Get OAuth parameters. On POST (the consent decision) these are read from
		// $_POST — the hidden fields the consent form resubmits — instead of
		// relying on the form having no `action` attribute to keep the same values
		// reachable via $_GET. That reliance worked only by accident: an explicit
		// `action`, or anything that strips the query string on POST, would have
		// silently reduced the entire OAuth context to empty strings.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth flow doesn't use WP nonces; the POST branch's nonce is verified below before any state changes.
		$client_id             = $this->read_oauth_param( 'client_id' );
		$redirect_uri          = $this->read_oauth_param( 'redirect_uri', '', true );
		$response_type         = $this->read_oauth_param( 'response_type' );
		$state                 = $this->read_oauth_param( 'state' );
		$scope                 = $this->read_oauth_param( 'scope', 'default' );
		$code_challenge        = $this->read_oauth_param( 'code_challenge' );
		$code_challenge_method = $this->read_oauth_param( 'code_challenge_method' );
		// phpcs:enable

		// Validate required parameters.
		if ( empty( $client_id ) || empty( $redirect_uri ) || $response_type !== 'code' ) {
			$this->render_error_page(
				__( 'Invalid Request', 'albert-ai-butler' ),
				__( 'Missing or invalid OAuth parameters.', 'albert-ai-butler' )
			);
			return;
		}

		// Validate client.
		$client_repo = new ClientRepository();
		$client      = $client_repo->getClientEntity( $client_id );

		if ( ! $client ) {
			$this->render_error_page(
				__( 'Unknown Application', 'albert-ai-butler' ),
				__( 'The application requesting access is not registered.', 'albert-ai-butler' )
			);
			return;
		}

		// Validate redirect URI: must exactly match a registered URI. There is no
		// wildcard: a client that did not register this exact URI is rejected.
		$allowed_uris = $client->getRedirectUri();
		if ( is_string( $allowed_uris ) ) {
			$allowed_uris = [ $allowed_uris ];
		}

		if ( ! in_array( $redirect_uri, $allowed_uris, true ) ) {
			$this->render_error_page(
				__( 'Invalid Redirect', 'albert-ai-butler' ),
				__( 'The redirect URI is not allowed for this application.', 'albert-ai-butler' )
			);
			return;
		}

		// PKCE policy: this server accepts only the S256 challenge method, and a
		// public (native/loopback) client MUST use PKCE: a secret alone cannot
		// protect it (RFC 8252). league enforces presence for public clients at
		// token time; we reject a non-S256 method and a missing challenge up front.
		if ( $code_challenge !== '' && $code_challenge_method !== 'S256' ) {
			$this->render_error_page(
				__( 'Invalid Request', 'albert-ai-butler' ),
				__( 'This site requires PKCE using the S256 code challenge method.', 'albert-ai-butler' )
			);
			return;
		}

		if ( ! $client->isConfidential() && $code_challenge === '' ) {
			$this->render_error_page(
				__( 'Invalid Request', 'albert-ai-butler' ),
				__( 'This application must use PKCE (a code challenge) to connect.', 'albert-ai-butler' )
			);
			return;
		}

		// If user is not logged in, redirect to WordPress login.
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( $this->get_current_url() );
			wp_safe_redirect( $login_url );
			exit;
		}

		// Check if user is allowed to access MCP.
		$current_user = wp_get_current_user();

		if ( ! AllowedUsers::is_allowed( $current_user->ID ) ) {
			$this->render_access_denied_page( $current_user, $client->getName(), $redirect_uri, $state );
			return;
		}

		// Handle form submission (user approved or denied).
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			if ( ! isset( $_POST['_albert_nonce'] )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_albert_nonce'] ) ), 'albert_oauth_authorize' )
			) {
				$this->render_error_page(
					__( 'Invalid Request', 'albert-ai-butler' ),
					__( 'Security verification failed. Please try again.', 'albert-ai-butler' )
				);
				return;
			}

			$this->handle_authorization_decision(
				$client,
				$redirect_uri,
				$state,
				$scope,
				$code_challenge,
				$code_challenge_method
			);
			return;
		}

		// Show consent page.
		$this->render_consent_page( $client, $redirect_uri, $state, $scope, $code_challenge, $code_challenge_method );
	}

	/**
	 * Read an OAuth request parameter from the source appropriate to this request.
	 *
	 * GET on the initial authorize request, POST on the consent form's submission
	 * (see the hidden fields in render_consent_page()).
	 *
	 * @param string $key           The parameter name.
	 * @param string $default_value The default when the parameter is absent.
	 * @param bool   $is_url        Whether to sanitize as a URL rather than plain text.
	 *
	 * @return string The sanitized value.
	 * @since 1.4.0
	 */
	private function read_oauth_param( string $key, string $default_value = '', bool $is_url = false ): string {
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- OAuth flow doesn't use WP nonces for GET; the POST branch's nonce is verified in handle_authorization() before any state changes.
		$source = $is_post ? $_POST : $_GET;

		if ( ! isset( $source[ $key ] ) ) {
			return $default_value;
		}

		return $is_url
			? esc_url_raw( wp_unslash( $source[ $key ] ) )
			: sanitize_text_field( wp_unslash( $source[ $key ] ) );
	}

	/**
	 * Handle authorization decision (approve/deny).
	 *
	 * @param \Albert\OAuth\Entities\ClientEntity $client                The client entity.
	 * @param string                              $redirect_uri          The redirect URI.
	 * @param string                              $state                 The state parameter.
	 * @param string                              $scope                 The requested scope.
	 * @param string                              $code_challenge        PKCE code challenge.
	 * @param string                              $code_challenge_method PKCE challenge method.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_authorization_decision( $client, $redirect_uri, $state, $scope, $code_challenge, $code_challenge_method ): void {
		// Nonce already verified in handle_authorization() before calling this method.
		$approved = isset( $_POST['approve'] ) && sanitize_text_field( wp_unslash( $_POST['approve'] ) ) === 'yes'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $approved ) {
			// User denied - redirect with error.
			$error_params = [
				'error'             => 'access_denied',
				'error_description' => 'The user denied the authorization request.',
			];
			if ( $state ) {
				$error_params['state'] = $state;
			}
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth requires external redirects.
			wp_redirect( add_query_arg( $error_params, $redirect_uri ) );
			exit;
		}

		// User approved - generate authorization code.
		try {
			$server = AuthorizationServerFactory::create();

			// Create a PSR-7 request with the OAuth parameters.
			$query_params = [
				'response_type'         => 'code',
				'client_id'             => $client->getIdentifier(),
				'redirect_uri'          => $redirect_uri,
				'scope'                 => $scope,
				'state'                 => $state,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
			];

			$psr_request = Psr7Bridge::create_server_request( 'GET', home_url( '/oauth/authorize' ) )
				->withQueryParams( $query_params );

			// Validate and complete the authorization request.
			$auth_request = $server->validateAuthorizationRequest( $psr_request );
			$auth_request->setUser( new UserEntity( get_current_user_id() ) );
			$auth_request->setAuthorizationApproved( true );

			$psr_response = $server->completeAuthorizationRequest(
				$auth_request,
				Psr7Bridge::create_response()
			);

			// Record the address this connection was authorised against.
			// Recording only: suspending a connection when the site's address
			// changes is designed but deliberately not enforced yet
			// (docs/features/31-connections.md §6). Nothing reads this column to
			// refuse a request. It is written now so the history exists on the
			// day the control ships, rather than every existing connection
			// looking like a fresh unknown. See Albert\OAuth\Server\DomainGuard.
			DomainGuard::record_connection( (string) $client->getIdentifier() );

			// The invitation has now been exercised: exempt for good from the
			// invitation-expiry sweep, however long this or any later connection
			// of theirs lives for. First-time only; see AllowedUsers::mark_authorised().
			AllowedUsers::mark_authorised( get_current_user_id() );

			// An optional label, offered here because this is the one moment the
			// approving person has real context ("this is my laptop") that nobody
			// reviewing the Connections screen later will have. Skippable: a blank
			// field here is simply never saved, not cleared, so there is nothing to
			// undo by leaving it empty.
			$label = isset( $_POST['albert_connection_label'] ) ? sanitize_text_field( wp_unslash( $_POST['albert_connection_label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_authorization() before calling this method.

			if ( $label !== '' ) {
				( new ClientRepository() )->updateClientLabel( (string) $client->getIdentifier(), $label, get_current_user_id() );
			}

			// Get redirect location from response.
			$location = $psr_response->getHeader( 'Location' );
			if ( ! empty( $location[0] ) ) {
				$this->render_success_page( $location[0], $client->getName() );
				return;
			}
		} catch ( OAuthServerException $e ) {
			$error_params = [
				'error'             => $e->getErrorType(),
				'error_description' => $e->getMessage(),
			];
			if ( $state ) {
				$error_params['state'] = $state;
			}
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth requires external redirects.
			wp_redirect( add_query_arg( $error_params, $redirect_uri ) );
			exit;
		} catch ( \Exception $e ) {
			$this->render_error_page(
				__( 'Server Error', 'albert-ai-butler' ),
				$e->getMessage()
			);
		}
	}

	/**
	 * Render the consent page.
	 *
	 * @param \Albert\OAuth\Entities\ClientEntity $client                The client entity.
	 * @param string                              $redirect_uri          The redirect URI.
	 * @param string                              $state                 The state parameter.
	 * @param string                              $scope                 The requested scope.
	 * @param string                              $code_challenge        PKCE code challenge.
	 * @param string                              $code_challenge_method PKCE challenge method.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_consent_page( $client, $redirect_uri, $state, $scope, $code_challenge, $code_challenge_method ): void {
		$current_user = wp_get_current_user();
		$client_name  = $client->getName();
		$user_name    = $current_user->display_name;
		$site_name    = get_bloginfo( 'name' );
		$destination  = $this->format_redirect_destination( $redirect_uri );
		$age_notice   = $this->format_client_age_notice( $client );

		// Start output.
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$this->enqueue_oauth_styles();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html__( 'Authorize Application', 'albert-ai-butler' ); ?> - <?php echo esc_html( $site_name ); ?></title>
		<?php wp_print_styles( 'albert-oauth-pages' ); ?>
</head>
<body>
	<div class="auth-container">
		<div class="auth-header">
			<h1><?php esc_html_e( 'Authorize Application', 'albert-ai-butler' ); ?></h1>
			<p><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div class="client-info">
			<div class="client-name"><?php echo esc_html( $client_name ); ?></div>
			<div class="permission-text">
				<?php esc_html_e( 'This application wants to access your WordPress site on your behalf.', 'albert-ai-butler' ); ?>
			</div>
			<div class="redirect-destination">
				<span class="redirect-destination__label">
					<?php esc_html_e( 'After you authorize, the access code is sent to:', 'albert-ai-butler' ); ?>
				</span>
				<span class="redirect-destination__value"><?php echo esc_html( $destination ); ?></span>
			</div>
			<?php if ( $age_notice !== '' ) { ?>
			<div class="client-age-notice">
				<?php echo esc_html( $age_notice ); ?>
			</div>
			<?php } ?>
		</div>

		<div class="user-info">
			<?php
			printf(
				/* translators: %s: user display name */
				esc_html__( 'Logged in as %s', 'albert-ai-butler' ),
				'<strong>' . esc_html( $user_name ) . '</strong>'
			);
			?>
		</div>

		<form method="post">
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->getIdentifier() ); ?>">
			<input type="hidden" name="redirect_uri" value="<?php echo esc_attr( $redirect_uri ); ?>">
			<?php /* Required: the POST branch reads every OAuth parameter from $_POST, and the handler rejects anything whose response_type is not "code". */ ?>
			<input type="hidden" name="response_type" value="code">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>">
			<?php wp_nonce_field( 'albert_oauth_authorize', '_albert_nonce' ); ?>

			<div class="connection-label">
				<label for="albert-connection-label"><?php esc_html_e( 'Name this connection (optional)', 'albert-ai-butler' ); ?></label>
				<input
					type="text"
					id="albert-connection-label"
					name="albert_connection_label"
					maxlength="255"
					placeholder="<?php esc_attr_e( 'e.g. My laptop', 'albert-ai-butler' ); ?>"
					autocomplete="off"
				>
				<p class="connection-label__hint">
					<?php esc_html_e( 'Helps you tell it apart later on the Connections screen. You can leave this blank and add or change it anytime.', 'albert-ai-butler' ); ?>
				</p>
			</div>

			<div class="button-group">
				<button type="submit" name="approve" value="no" class="button button-secondary">
					<?php esc_html_e( 'Deny', 'albert-ai-butler' ); ?>
				</button>
				<button type="submit" name="approve" value="yes" class="button button-primary">
					<?php esc_html_e( 'Authorize', 'albert-ai-butler' ); ?>
				</button>
			</div>
		</form>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Render an error page.
	 *
	 * @param string $title   Error title.
	 * @param string $message Error message.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_error_page( string $title, string $message ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( 400 );

		$this->enqueue_oauth_styles();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $title ); ?></title>
		<?php wp_print_styles( 'albert-oauth-pages' ); ?>
</head>
<body>
	<div class="error-container">
		<h1><?php echo esc_html( $title ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Render the page that refuses someone not on the allowed list.
	 *
	 * It names who can fix it, because "access denied" alone sends people to
	 * support. An administrator who is not listed can fix it themselves, so
	 * they get the way there instead of being told to ask somebody. An
	 * expired invitation reads differently from never being invited:
	 * {@see \Albert\OAuth\AllowedUsers::has_expired_invitation()} knows which.
	 *
	 * @param \WP_User $user         The current user.
	 * @param string   $client_name  The app asking to connect, as it registered itself.
	 * @param string   $redirect_uri The validated redirect URI, for leaving without connecting.
	 * @param string   $state        The OAuth state, returned with the refusal.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_access_denied_page( \WP_User $user, string $client_name, string $redirect_uri, string $state ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( 403 );

		$site_name   = get_bloginfo( 'name' );
		$expired     = AllowedUsers::has_expired_invitation( $user->ID );
		$is_admin    = user_can( $user, 'manage_options' );
		$current_url = $this->get_current_url();
		$leave_url   = add_query_arg(
			array_filter(
				[
					'error'             => 'access_denied',
					'error_description' => 'The user is not allowed to connect an assistant to this site.',
					'state'             => $state,
				]
			),
			$redirect_uri
		);

		$this->enqueue_oauth_styles();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( 'This account can’t connect an assistant', 'albert-ai-butler' ); ?> - <?php echo esc_html( $site_name ); ?></title>
		<?php wp_print_styles( 'albert-oauth-pages' ); ?>
</head>
<body>
	<main class="albert-oauth">
		<p class="albert-oauth__site"><?php echo esc_html( $site_name ); ?></p>
		<article class="albert-oauth__card">
			<p class="albert-oauth__eyebrow">
				<?php
				/* translators: %s: the name the connecting app registered, e.g. "Claude". */
				echo esc_html( sprintf( __( '%s asked to connect to this site', 'albert-ai-butler' ), $client_name ) );
				?>
			</p>
			<h1 class="albert-oauth__title"><?php esc_html_e( 'This account can’t connect an assistant', 'albert-ai-butler' ); ?></h1>

			<?php if ( ! $is_admin ) { ?>
				<p class="albert-oauth__text">
					<?php
					echo esc_html(
						$expired
							/* translators: %s: the name of the connecting app. */
							? sprintf( __( 'Your invitation to connect an assistant expired before it was used. Ask an administrator to add you again under Albert → Connections, then start connecting again from %s.', 'albert-ai-butler' ), $client_name )
							/* translators: %s: the name of the connecting app. */
							: sprintf( __( 'Your account isn’t on this site’s list of people who may connect an assistant. Ask an administrator to add you under Albert → Connections, then start connecting again from %s.', 'albert-ai-butler' ), $client_name )
					);
					?>
				</p>
			<?php } ?>

			<p class="albert-oauth__meta">
				<?php
				printf(
					/* translators: 1: the user's display name, 2: their role, e.g. "Editor". */
					esc_html__( 'Signed in as %1$s (%2$s).', 'albert-ai-butler' ),
					esc_html( $user->display_name ),
					esc_html( $this->role_label( $user ) )
				);
				?>
				<a href="<?php echo esc_url( wp_logout_url( $current_url ) ); ?>"><?php esc_html_e( 'Sign in as someone else', 'albert-ai-butler' ); ?></a>
			</p>

			<?php if ( $is_admin ) { ?>
				<section class="albert-oauth__fix">
					<h2 class="albert-oauth__subtitle"><?php esc_html_e( 'You can add yourself', 'albert-ai-butler' ); ?></h2>
					<p class="albert-oauth__text"><?php esc_html_e( 'You are an administrator. Add yourself on the Connections page, then come back and try again.', 'albert-ai-butler' ); ?></p>
					<p class="albert-oauth__actions">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Open Albert → Connections', 'albert-ai-butler' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
						</a>
						<a class="button button-secondary" href="<?php echo esc_url( $current_url ); ?>"><?php esc_html_e( 'Try again', 'albert-ai-butler' ); ?></a>
					</p>
				</section>
			<?php } ?>

			<footer class="albert-oauth__footer">
				<?php // Its own scheme allowed: a desktop app's redirect URI is often not http(s). ?>
				<a href="<?php echo esc_url( $leave_url, [ (string) wp_parse_url( $redirect_uri, PHP_URL_SCHEME ) ] ); ?>">
					<?php
					/* translators: %s: the name of the connecting app. */
					echo esc_html( sprintf( __( 'Return to %s without connecting', 'albert-ai-butler' ), $client_name ) );
					?>
				</a>
			</footer>
		</article>
	</main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * The user's role as WordPress shows it, translated.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return string The first role's name, or '' when they have none.
	 * @since 1.5.0
	 */
	private function role_label( \WP_User $user ): string {
		$role  = $user->roles[0] ?? '';
		$names = wp_roles()->get_names();

		return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : '';
	}

	/**
	 * Render the success page after authorization.
	 *
	 * Completes the OAuth callback and shows a success message.
	 *
	 * @param string $redirect_url The OAuth callback URL with authorization code.
	 * @param string $client_name  The name of the authorized client.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_success_page( string $redirect_url, string $client_name ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$site_name = get_bloginfo( 'name' );

		$this->enqueue_oauth_styles();

		wp_enqueue_script( 'albert-oauth-redirect' );
		wp_add_inline_script(
			'albert-oauth-redirect',
			'setTimeout(function(){window.location.href=' . wp_json_encode( $redirect_url ) . ';},1500);'
		);

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( 'Authorization Successful', 'albert-ai-butler' ); ?> - <?php echo esc_html( $site_name ); ?></title>
		<?php wp_print_styles( 'albert-oauth-pages' ); ?>
</head>
<body>
	<div class="success-container">
		<div class="success-icon">
			<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
				<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
			</svg>
		</div>
		<h1><?php esc_html_e( 'Authorization Successful', 'albert-ai-butler' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: %s: client/application name */
				esc_html__( '%s has been authorized to access your site.', 'albert-ai-butler' ),
				'<span class="client-name">' . esc_html( $client_name ) . '</span>'
			);
			?>
		</p>
		<p class="close-message">
			<?php esc_html_e( 'Redirecting back to the application...', 'albert-ai-butler' ); ?>
		</p>
		<p class="fallback-message">
			<?php esc_html_e( "If the application doesn't open automatically, please click the button below.", 'albert-ai-butler' ); ?>
		</p>
		<a href="<?php echo esc_url( $redirect_url ); ?>" class="button"><?php esc_html_e( 'Return to Application', 'albert-ai-butler' ); ?></a>
	</div>
		<?php wp_print_scripts( 'albert-oauth-redirect' ); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Format the redirect destination shown on the consent screen.
	 *
	 * The destination is the primary trust signal: for an https/http URI show
	 * the host; for a private-use scheme show the (short) URI itself.
	 *
	 * @param string $redirect_uri The validated redirect URI.
	 *
	 * @return string The destination to display.
	 * @since 1.3.1
	 */
	private function format_redirect_destination( string $redirect_uri ): string {
		$host = wp_parse_url( $redirect_uri, PHP_URL_HOST );

		if ( is_string( $host ) && $host !== '' ) {
			return $host;
		}

		return $redirect_uri;
	}

	/**
	 * Build an advisory notice when the client was registered very recently.
	 *
	 * A freshly-registered client is a weak signal of a consent-phishing attempt,
	 * so the owner is told when the application first appeared.
	 *
	 * @param \Albert\OAuth\Entities\ClientEntity $client The client entity.
	 *
	 * @return string The notice text, or '' when the client is not recent/unknown.
	 * @since 1.3.1
	 */
	private function format_client_age_notice( $client ): string {
		$created = $client->getCreatedAt();

		if ( ! $created instanceof \DateTimeImmutable ) {
			return '';
		}

		$age = time() - $created->getTimestamp();

		if ( $age < 0 || $age >= 10 * MINUTE_IN_SECONDS ) {
			return '';
		}

		$minutes = max( 1, (int) round( $age / MINUTE_IN_SECONDS ) );

		return sprintf(
			/* translators: %d: number of minutes since the application registered */
			_n(
				'This application registered %d minute ago. Only continue if you are expecting it.',
				'This application registered %d minutes ago. Only continue if you are expecting it.',
				$minutes,
				'albert-ai-butler'
			),
			$minutes
		);
	}

	/**
	 * Get the current request URL.
	 *
	 * @return string The current URL.
	 * @since 1.0.0
	 */
	private function get_current_url(): string {
		$protocol = is_ssl() ? 'https' : 'http';
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return $protocol . '://' . $host . $uri;
	}

	/**
	 * Flush rewrite rules on activation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function activate(): void {
		$instance = new self();
		$instance->add_rewrite_rules();
		flush_rewrite_rules();
	}
}
