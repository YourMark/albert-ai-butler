<?php
/**
 * Expired Token Cleanup Cron
 *
 * @package Albert
 * @subpackage Cron
 * @since      1.4.0
 */

namespace Albert\Cron;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Tokens\SingleUseTokenRepository;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Repositories\RefreshTokenRepository;

/**
 * Daily WP-Cron job that deletes expired OAuth token rows and expired
 * single-use tokens. Pure hygiene, not a security boundary — an expired
 * token already can't authenticate or redeem regardless of whether this
 * has run; it just keeps the tables from growing without bound.
 *
 * @since 1.4.0
 */
class TokenCleanup implements Hookable {

	/**
	 * Cron hook name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const HOOK = 'albert_cleanup_expired_tokens';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Delete expired access and refresh token rows.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function run(): void {
		try {
			( new AccessTokenRepository() )->cleanupExpiredTokens();
			( new RefreshTokenRepository() )->cleanupExpiredTokens();
			( new SingleUseTokenRepository() )->cleanup_expired();

			/**
			 * Days a client may sit without any token before it is deleted. 0 keeps them.
			 *
			 * @since 1.5.0
			 *
			 * @param int $days Default 7.
			 */
			$days = (int) apply_filters( 'albert/oauth/stale_client_days', 7 );

			if ( $days > 0 ) {
				( new ClientRepository() )->delete_stale_clients( $days );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a cron failure surface to the site.
		}
	}

	/**
	 * Schedule the daily cleanup if not already scheduled.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedule the daily cleanup.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( $timestamp !== false ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}
}
