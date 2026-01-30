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
use Albert\OAuth\Entities\UserEntity;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Server\AuthorizationServerFactory;
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

		// Get OAuth parameters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth flow doesn't use WP nonces.
		$client_id             = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '';
		$redirect_uri          = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '';
		$response_type         = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '';
		$state                 = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$scope                 = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : 'default';
		$code_challenge        = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '';
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '';
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

		// Validate redirect URI.
		$allowed_uris = $client->getRedirectUri();
		if ( is_string( $allowed_uris ) ) {
			$allowed_uris = [ $allowed_uris ];
		}

		$is_wildcard = in_array( '*', $allowed_uris, true );
		if ( ! $is_wildcard && ! in_array( $redirect_uri, $allowed_uris, true ) ) {
			$this->render_error_page(
				__( 'Invalid Redirect', 'albert-ai-butler' ),
				__( 'The redirect URI is not allowed for this application.', 'albert-ai-butler' )
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
		$allowed_users = get_option( 'albert_allowed_users', [] );
		$current_user  = wp_get_current_user();

		if ( ! in_array( $current_user->ID, $allowed_users, true ) ) {
			$this->render_access_denied_page( $current_user );
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
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $code_challenge ); ?>">
			<input type="hidden" name="code_challenge_method" value="<?php echo esc_attr( $code_challenge_method ); ?>">
			<?php wp_nonce_field( 'albert_oauth_authorize', '_albert_nonce' ); ?>

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
	 * Render an access denied page for users not in the allowed list.
	 *
	 * @param \WP_User $user The current user.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_access_denied_page( \WP_User $user ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( 403 );

		$site_name = get_bloginfo( 'name' );

		$this->enqueue_oauth_styles();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( 'Access Denied', 'albert-ai-butler' ); ?> - <?php echo esc_html( $site_name ); ?></title>
		<?php wp_print_styles( 'albert-oauth-pages' ); ?>
</head>
<body>
	<div class="access-denied-container">
		<div class="icon">🚫</div>
		<h1><?php esc_html_e( 'Access Not Authorized', 'albert-ai-butler' ); ?></h1>
		<p>
			<?php esc_html_e( 'Your account has not been granted access to connect AI tools to this site.', 'albert-ai-butler' ); ?>
		</p>
		<div class="user-info">
			<?php
			printf(
				/* translators: %s: user display name */
				esc_html__( 'Logged in as %s', 'albert-ai-butler' ),
				'<strong>' . esc_html( $user->display_name ) . '</strong>'
			);
			?>
			<br>
			<small><?php echo esc_html( $user->user_email ); ?></small>
		</div>
		<p class="contact-admin">
			<?php esc_html_e( 'Please contact your site administrator to request access.', 'albert-ai-butler' ); ?>
		</p>
	</div>
</body>
</html>
		<?php
		exit;
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
