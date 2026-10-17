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
		if ( ! self::is_registered_redirect_uri( $client->getRedirectUri(), $redirect_uri ) ) {
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
			$this->render_access_denied_page( $current_user, $client, $redirect_uri, $state );
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
	 * Whether a redirect URI is one the client registered, exactly.
	 *
	 * Exact string match, as RFC 6749 §3.1.2.3 asks: no normalising, so a
	 * private-use scheme (cursor://) works and nothing near-identical does.
	 *
	 * @param string|string[] $registered The client's registered URIs.
	 * @param string          $uri        The URI from the request.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_registered_redirect_uri( $registered, string $uri ): bool {
		return $uri !== '' && in_array( $uri, (array) $registered, true );
	}

	/**
	 * The schemes esc_url() may keep for a link back to an app.
	 *
	 * @param string $url The app's callback URL.
	 *
	 * @return array<int, string>
	 * @since 1.5.0
	 */
	private static function link_protocols( string $url ): array {
		$protocols = [ 'https', 'http' ];

		if ( ! self::is_dangerous_scheme( $url ) ) {
			$protocols[] = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		}

		return array_values( array_unique( $protocols ) );
	}

	/**
	 * Whether a URL's scheme may never reach a link or a redirect.
	 *
	 * Registration already refuses these; checking again where the URL is
	 * printed keeps a client stored any other way from slipping through.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_dangerous_scheme( string $url ): bool {
		// Browsers drop control characters and spaces before reading a scheme,
		// so "java\tscript:" runs as javascript: unless they are dropped here too.
		$scheme = strtolower( (string) strstr( (string) preg_replace( '/[\x00-\x20\x7f]/', '', $url ), ':', true ) );

		return $scheme === '' || in_array( $scheme, ClientRegistration::DANGEROUS_SCHEMES, true );
	}

	/**
	 * Read an OAuth request parameter from the source appropriate to this request.
	 *
	 * GET on the initial authorize request, POST on the consent form's submission
	 * (see the hidden fields in render_consent_page()).
	 *
	 * @param string $key           The parameter name.
	 * @param string $default_value The default when the parameter is absent.
	 * @param bool   $verbatim      Keep as sent, trimmed only: for the redirect URI,
	 *                              which must then exactly match a registered one.
	 *
	 * @return string The sanitized value.
	 * @since 1.4.0
	 * @since 1.5.0 `$is_url` became `$verbatim`.
	 */
	private function read_oauth_param( string $key, string $default_value = '', bool $verbatim = false ): string {
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- OAuth flow doesn't use WP nonces for GET; the POST branch's nonce is verified in handle_authorization() before any state changes.
		$source = $is_post ? $_POST : $_GET;

		if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) ) {
			return $default_value;
		}

		return $verbatim
			? trim( wp_unslash( $source[ $key ] ) )
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
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth requires external redirects.
			wp_redirect( self::error_redirect_url( $redirect_uri, 'access_denied', 'The user denied the authorization request.', $state ) );
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
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- OAuth requires external redirects.
			wp_redirect( self::error_redirect_url( $redirect_uri, $e->getErrorType(), $e->getMessage(), $state ) );
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
	 * Refuse someone not on the allowed list, and say who can fix it.
	 *
	 * An unlisted administrator gets the way to add themselves; an expired
	 * invitation reads differently from never being invited.
	 *
	 * @param \WP_User                            $user         The current user.
	 * @param \Albert\OAuth\Entities\ClientEntity $client       The app asking to connect.
	 * @param string                              $redirect_uri The validated redirect URI.
	 * @param string                              $state        The OAuth state, returned with the refusal.
	 *
	 * @return void
	 * @since 1.0.0
	 * @since 1.5.0 Takes the client, names it, and offers a way back to it.
	 */
	private function render_access_denied_page( \WP_User $user, $client, string $redirect_uri, string $state ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( 403 );

		$this->enqueue_oauth_styles();

		echo $this->access_denied_html( $user, $client, $redirect_uri, $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is built.
		exit;
	}

	/**
	 * The refusal page's markup, separate from sending it so it can be tested.
	 *
	 * The way back to the app is a link to an address the app registered for
	 * itself, so the page shows where it goes and how new the app is, the
	 * same two signals the consent page gives: dynamic registration is open
	 * to anyone, under any name.
	 *
	 * @param \WP_User                            $user         The current user.
	 * @param \Albert\OAuth\Entities\ClientEntity $client       The app asking to connect.
	 * @param string                              $redirect_uri The validated redirect URI.
	 * @param string                              $state        The OAuth state, returned with the refusal.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function access_denied_html( \WP_User $user, $client, string $redirect_uri, string $state ): string {
		$site_name   = get_bloginfo( 'name' );
		$client_name = (string) $client->getName();
		$expired     = AllowedUsers::has_expired_invitation( $user->ID );
		$is_admin    = user_can( $user, 'manage_options' );
		$current_url = $this->get_current_url();
		$leave_url   = self::error_redirect_url( $redirect_uri, 'access_denied', 'The user is not allowed to connect an assistant to this site.', $state );
		$age_notice  = $this->format_client_age_notice( $client );

		ob_start();
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
					if ( $expired ) {
						/* translators: %s: the name of the connecting app. */
						$refusal = __( 'Your invitation to connect an assistant expired before it was used. Ask an administrator to add you again on Albert’s Connections page, then start connecting again from %s.', 'albert-ai-butler' );
					} else {
						/* translators: %s: the name of the connecting app. */
						$refusal = __( 'Your account isn’t on this site’s list of people who may connect an assistant. Ask an administrator to add you on Albert’s Connections page, then start connecting again from %s.', 'albert-ai-butler' );
					}
					echo esc_html( sprintf( $refusal, $client_name ) );
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
					<div class="albert-oauth__actions">
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>" target="_blank" rel="noopener">
							<?php
							printf(
								/* translators: %s: an arrow between "Albert" and "Connections", hidden from screen readers. */
								esc_html__( 'Open Albert %s Connections', 'albert-ai-butler' ),
								'<span aria-hidden="true">→</span>'
							);
							?>
							<svg class="albert-oauth__external" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M9 2h5v5M14 2 7 9M12 9.5V14H2V4h4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
						</a>
						<a class="button button-secondary" href="<?php echo esc_url( $current_url ); ?>"><?php esc_html_e( 'Try again', 'albert-ai-butler' ); ?></a>
					</div>
				</section>
			<?php } ?>

			<footer class="albert-oauth__footer">
				<?php // An app may register its own callback scheme (cursor://), so that one is allowed for this link. ?>
				<a href="<?php echo esc_url( $leave_url, self::link_protocols( $redirect_uri ) ); ?>">
					<?php
					/* translators: %s: the name of the connecting app. */
					echo esc_html( sprintf( __( 'Return to %s without connecting', 'albert-ai-butler' ), $client_name ) );
					?>
				</a>
				<p class="albert-oauth__destination">
					<?php
					/* translators: %s: the host the link goes to, e.g. "claude.ai". */
					echo esc_html( sprintf( __( 'This link goes to %s.', 'albert-ai-butler' ), $this->format_redirect_destination( $redirect_uri ) ) );
					if ( $age_notice !== '' ) {
						echo ' ' . esc_html( $age_notice );
					}
					?>
				</p>
			</footer>
		</article>
	</main>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The redirect URI with an OAuth error appended, every value encoded.
	 *
	 * `add_query_arg()` does not encode values, so a `state` holding `&` or
	 * `#` would add parameters or a fragment of its own to the redirect. An
	 * empty state is left out; a state of "0" is a state.
	 *
	 * @param string $redirect_uri The validated redirect URI.
	 * @param string $error        The OAuth error code.
	 * @param string $description  The error description.
	 * @param string $state        The state the client sent, or ''.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function error_redirect_url( string $redirect_uri, string $error, string $description, string $state ): string {
		$params = [
			'error'             => $error,
			'error_description' => $description,
		];

		if ( $state !== '' ) {
			$params['state'] = $state;
		}

		$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		return $redirect_uri . ( str_contains( $redirect_uri, '?' ) ? '&' : '?' ) . $query;
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
	 * After someone approves: say so, then hand the app its code.
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

		$this->enqueue_oauth_styles();

		wp_enqueue_script( 'albert-oauth-redirect' );
		wp_add_inline_script( 'albert-oauth-redirect', self::redirect_script( $redirect_url ) );

		echo $this->success_html( $redirect_url, $client_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is built.
		exit;
	}

	/**
	 * The approval page's script: tell an open connect dialog, then hand over.
	 *
	 * The redirect is what completes OAuth, so it runs on its own after three
	 * seconds, and stops for good the moment someone interacts, leaving them
	 * the link (WCAG 2.2.1). The broadcast is only a hint for the connect
	 * dialog (admin-connect.js listens on the same channel name), which asks
	 * the server rather than believing it.
	 *
	 * @param string $redirect_url The OAuth callback URL with authorization code.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function redirect_script( string $redirect_url ): string {
		$flags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$script = 'try{new BroadcastChannel("albert-connect").postMessage("approved");}catch(e){}';

		if ( ! self::is_dangerous_scheme( $redirect_url ) ) {
			$script .= 'var t=setTimeout(function(){window.location.href=' . wp_json_encode( $redirect_url, $flags ) . ';},3000);'
				. 'var stop=function(){clearTimeout(t);var n=document.querySelector("[data-albert-redirect-note]");if(n){n.textContent=' . wp_json_encode( __( 'Stopped. Use the link below to go back.', 'albert-ai-butler' ), $flags ) . ';}};'
				. '["keydown","pointerdown","focusin"].forEach(function(e){document.addEventListener(e,stop,{once:true});});';
		}

		return '(function(){' . $script . '})();';
	}

	/**
	 * The approval page's markup, separate from sending it so it can be tested.
	 *
	 * Says "Approved" rather than "Connected": the app still has to exchange
	 * the code it is about to receive, and the connect dialog only says
	 * connected once that has happened.
	 *
	 * @param string $redirect_url The OAuth callback URL with authorization code.
	 * @param string $client_name  The name of the authorized client.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function success_html( string $redirect_url, string $client_name ): string {
		$site_name = get_bloginfo( 'name' );
		$user      = wp_get_current_user();
		// No link or redirect to a script scheme; registration refuses those,
		// so this only shows for a client stored some other way.
		$can_return = ! self::is_dangerous_scheme( $redirect_url );

		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( 'Approved', 'albert-ai-butler' ); ?> - <?php echo esc_html( $site_name ); ?></title>
		<?php wp_print_styles( 'albert-oauth-pages' ); ?>
</head>
<body>
	<main class="albert-oauth">
		<p class="albert-oauth__site"><?php echo esc_html( $site_name ); ?></p>
		<article class="albert-oauth__card">
			<p class="albert-oauth__eyebrow">
				<?php
				/* translators: %s: the name the connecting app registered, e.g. "Cursor". */
				echo esc_html( sprintf( __( '%s asked to connect to this site', 'albert-ai-butler' ), $client_name ) );
				?>
			</p>
			<h1 class="albert-oauth__title albert-oauth__title--done">
				<svg class="albert-oauth__check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="11"/><path d="M7 12.5l3.2 3.2L17 9"/></svg>
				<?php esc_html_e( 'Approved', 'albert-ai-butler' ); ?>
			</h1>
			<p class="albert-oauth__text" data-albert-redirect-note aria-live="polite">
				<?php
				echo esc_html(
					$can_return
						/* translators: %s: the name of the connected app. */
						? sprintf( __( 'Taking you back to %s…', 'albert-ai-butler' ), $client_name )
						/* translators: %s: the name of the connected app. */
						: sprintf( __( 'Go back to %s to finish connecting.', 'albert-ai-butler' ), $client_name )
				);
				?>
			</p>
			<p class="albert-oauth__meta">
				<?php
				printf(
					/* translators: 1: the user's display name, 2: their role, e.g. "Administrator". */
					esc_html__( 'Signed in as %1$s (%2$s).', 'albert-ai-butler' ),
					esc_html( $user->display_name ),
					esc_html( $this->role_label( $user ) )
				);
				if ( user_can( $user, 'manage_options' ) ) {
					echo ' ';
					esc_html_e( 'You can see or revoke this connection on Albert’s Connections page.', 'albert-ai-butler' );
				}
				?>
			</p>
			<?php if ( $can_return ) { ?>
				<footer class="albert-oauth__footer">
					<a href="<?php echo esc_url( $redirect_url, self::link_protocols( $redirect_url ) ); ?>">
						<?php
						/* translators: %s: the name of the connected app. */
						echo esc_html( sprintf( __( 'If nothing happens, return to %s', 'albert-ai-butler' ), $client_name ) );
						?>
					</a>
				</footer>
			<?php } ?>
		</article>
	</main>
		<?php wp_print_scripts( 'albert-oauth-redirect' ); ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
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
