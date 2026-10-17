<?php
/**
 * The "who may approve an assistant" picker.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Assets;
use Albert\OAuth\AllowedUsers;

/**
 * One picker, two entry points.
 *
 * The Connections screen's "Add user" button and the Dashboard checklist's
 * "Choose users" button open the same dialog, from the same markup, driven by
 * the same script. Two pickers answering one question is two things to keep
 * permission-correct, and they drift.
 *
 * The dialog is this codebase's existing `<dialog>` primitive shown with
 * `showModal()`: the browser gives the focus trap, the Escape handling and the
 * `role="dialog"` for free, and the screen already had one for the Revoke
 * confirmation.
 *
 * @since 1.4.0
 */
final class UserPickerModal {

	/**
	 * The dialog element's id, shared by both entry points.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const DIALOG_ID = 'albert-adduser-dialog';

	/**
	 * The `admin-post.php` action the dialog submits to.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const ACTION = 'albert_add_allowed_users';

	/**
	 * The capability a user must have to be offered in the picker.
	 *
	 * A user who cannot edit content could authorise an assistant that then has
	 * nothing it may do, so offering them is noise, and on a shop it is twenty
	 * thousand rows of noise.
	 *
	 * @return string A capability name, or '' to offer every user.
	 * @since 1.4.0
	 */
	public static function candidate_capability(): string {
		/**
		 * Filters the capability a user needs to appear in the allowed-users picker.
		 *
		 * Return an empty string to offer every user, or another capability to
		 * widen or narrow the candidate list. This only changes who is *offered*:
		 * the authorisation gate itself is the stored allowed-users list.
		 *
		 * @since 1.4.0
		 *
		 * @param string $capability Default `edit_posts`.
		 */
		return (string) apply_filters( 'albert/connections/allowed_user_capability', 'edit_posts' );
	}

	/**
	 * Register the picker's script and styles for the current screen.
	 *
	 * Both screens already load the primitives stylesheet, which is where the
	 * dialog shell lives; only the behaviour has to be added here.
	 *
	 * @param string $return_to Which screen the form should come back to.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function enqueue( string $return_to ): void {
		wp_enqueue_script(
			'albert-admin-utils',
			ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
			[],
			Assets::version( 'assets/js/albert-admin-utils.js' ),
			true
		);

		wp_enqueue_script(
			'albert-connections',
			ALBERT_PLUGIN_URL . 'assets/js/admin-connections.js',
			[ 'albert-admin-utils' ],
			Assets::version( 'assets/js/admin-connections.js' ),
			true
		);

		wp_localize_script(
			'albert-connections',
			'albertConnections',
			[
				// Core's own users route. `search`, `roles` and `capabilities` all
				// require `list_users`; these screens are manage_options, so an
				// administrator is fine. If a narrower capability is ever allowed
				// to manage connections, the picker degrades and needs revisiting.
				'usersUrl'   => rest_url( 'wp/v2/users' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'capability' => self::candidate_capability(),
				'roles'      => self::role_names(),
				'allowed'    => self::allowed_user_ids(),
				'returnTo'   => $return_to,
				'i18n'       => [
					// The connected-assistants list shares this object: it is
					// the same screen, the same script file, and one localised
					// blob is one thing to keep translated.
					'selectedNone'            => __( 'Nothing selected', 'albert-ai-butler' ),
					/* translators: %d: number of selected connections. */
					'selectedCount'           => __( '%d selected', 'albert-ai-butler' ),
					/* translators: %d: number of connections matching the current filter. */
					'selectAllFiltered'       => __( 'Select all %d that match this filter', 'albert-ai-butler' ),
					/* translators: 1: number of selected connections, 2: number of connections currently shown. */
					'selectionStatus'         => __( '%1$d of %2$d connections selected', 'albert-ai-butler' ),
					'selectModeOn'            => __( 'Selection mode on, nothing selected', 'albert-ai-butler' ),
					'selectModeOff'           => __( 'Selection mode off', 'albert-ai-butler' ),

					'searching'               => __( 'Searching…', 'albert-ai-butler' ),
					'noResults'               => __( 'Nobody matched that.', 'albert-ai-butler' ),
					'failed'                  => __( 'Could not search users. Reload the page and try again.', 'albert-ai-butler' ),
					'prompt'                  => __( 'Type a name, email address or user ID to search.', 'albert-ai-butler' ),
					'loadingDefaults'         => __( 'Loading…', 'albert-ai-butler' ),
					// Both plural forms, chosen in the browser: the count is only
					// known there, and _n() cannot be called after the page renders.
					/* translators: %s: the search term. */
					'matchesOne'              => __( 'One match for “%s”, among users who can edit content.', 'albert-ai-butler' ),
					/* translators: 1: number of matches, 2: the search term. */
					'matchesMany'             => __( '%1$d matches for “%2$s”, among users who can edit content.', 'albert-ai-butler' ),
					'defaultSuggestionOne'    => __( 'One person you can add. Search to find someone else.', 'albert-ai-butler' ),
					/* translators: %d: number of suggested people. */
					'defaultSuggestionsMany'  => __( '%d people you can add. Search to find someone else.', 'albert-ai-butler' ),
					'defaultSuggestionsEmpty' => __( 'Everyone on this site can already approve an assistant. Search to find someone else.', 'albert-ai-butler' ),
					'alreadyAllowed'          => __( 'Already allowed', 'albert-ai-butler' ),
					/* translators: %s: the person's name. */
					'removeChip'              => __( 'Remove %s from the selection', 'albert-ai-butler' ),
					'addNone'                 => __( 'Add user', 'albert-ai-butler' ),
					'addOne'                  => __( 'Add 1 user', 'albert-ai-butler' ),
					/* translators: %d: number of selected people. */
					'addMany'                 => __( 'Add %d users', 'albert-ai-butler' ),
					'addFailed'               => __( 'Could not add the selected people. Reload the page and try again.', 'albert-ai-butler' ),
					'emailSent'               => __( 'Email sent', 'albert-ai-butler' ),
					'emailFailed'             => __( 'Email failed, copy the link instead', 'albert-ai-butler' ),
					'noRole'                  => __( 'No role on this site', 'albert-ai-butler' ),
					/* translators: %s: the person's name. */
					'chosen'                  => __( '%s added to the selection.', 'albert-ai-butler' ),
					/* translators: %s: the person's name. */
					'unchosen'                => __( '%s removed from the selection.', 'albert-ai-butler' ),
				],
			]
		);
	}

	/**
	 * Render the dialog.
	 *
	 * Rendered once per screen, next to whichever button opens it. Everything
	 * inside is a real form posting to `admin-post.php`: the script fills the
	 * hidden field with the chosen ids, it does not submit anything itself.
	 *
	 * @param string $return_to Which screen the form should come back to.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function render( string $return_to ): void {
		?>
		<dialog id="<?php echo esc_attr( self::DIALOG_ID ); ?>" class="albert-dialog albert-dialog--wide" aria-labelledby="albert-adduser-title" data-albert-userpicker>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="albert-picker">
				<?php wp_nonce_field( self::ACTION, 'albert_add_users_nonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="albert_return" value="<?php echo esc_attr( $return_to ); ?>" />
				<input type="hidden" name="albert_user_ids" value="" data-albert-picker-ids />

				<div class="albert-dialog__header">
					<div class="albert-dialog__heading">
						<h2 class="albert-dialog__title" id="albert-adduser-title"><?php esc_html_e( 'Choose who may approve an assistant', 'albert-ai-butler' ); ?></h2>
						<p class="albert-dialog__description"><?php esc_html_e( 'Search everyone on this site. You can add more people later on the Connections screen.', 'albert-ai-butler' ); ?></p>
					</div>
					<button type="button" class="albert-dialog__close" data-albert-picker-close aria-label="<?php esc_attr_e( 'Close', 'albert-ai-butler' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>

				<div class="albert-dialog__body">
					<div class="albert-search">
						<span class="dashicons dashicons-search albert-search__icon" aria-hidden="true"></span>
						<input
							type="search"
							class="albert-search__input"
							data-albert-picker-search
							autocomplete="off"
							aria-label="<?php esc_attr_e( 'Search all users on this site by name, email or ID', 'albert-ai-butler' ); ?>"
							aria-describedby="albert-adduser-count"
							placeholder="<?php esc_attr_e( 'Search by name, email or ID', 'albert-ai-butler' ); ?>"
						/>
					</div>

					<ul class="albert-chips" data-albert-picker-chips aria-label="<?php esc_attr_e( 'Selected people', 'albert-ai-butler' ); ?>"></ul>

					<div class="albert-picker__status">
						<p class="albert-picker__count" id="albert-adduser-count" data-albert-picker-count role="status">
							<?php esc_html_e( 'Type a name, email address or user ID to search.', 'albert-ai-butler' ); ?>
						</p>
						<p class="albert-picker__selected-count" data-albert-picker-selected-count hidden></p>
					</div>

					<ul class="albert-picker__results" data-albert-picker-results></ul>
				</div>

				<div class="albert-dialog__footer">
					<label class="albert-picker__email">
						<input type="checkbox" name="albert_email_setup" value="1" />
						<?php esc_html_e( 'Email them how to connect', 'albert-ai-butler' ); ?>
					</label>
					<button type="button" class="button" data-albert-picker-close><?php esc_html_e( 'Cancel', 'albert-ai-butler' ); ?></button>
					<button type="submit" class="button button-primary" data-albert-picker-confirm disabled>
						<?php esc_html_e( 'Add user', 'albert-ai-butler' ); ?>
					</button>
				</div>
			</form>
		</dialog>
		<?php
	}

	/**
	 * The current allowed-user ids, so the picker can mark them as such.
	 *
	 * @return array<int, int>
	 * @since 1.4.0
	 */
	private static function allowed_user_ids(): array {
		return AllowedUsers::ids();
	}

	/**
	 * Role slug to translated name, for labelling picker results.
	 *
	 * @return array<string, string>
	 * @since 1.4.0
	 */
	private static function role_names(): array {
		$labels = [];

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$labels[ $slug ] = translate_user_role( $name );
		}

		return $labels;
	}
}
