<?php
/**
 * Renders one app's connect steps.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a guide from {@see ClientSetupGuides} becomes markup.
 *
 * The Connections screen and the connect dialog both draw a guide, and the
 * steps, notes and copy blocks must read the same in both. Everything is
 * escaped here, once.
 *
 * @phpstan-import-type AlbertClientGuide from ClientSetupGuides
 * @phpstan-import-type AlbertGuideAction from ClientSetupGuides
 *
 * @since 1.5.0
 */
class GuidePanel {

	/**
	 * Schemes the one-click links use. esc_url() drops anything else, so an
	 * app's own scheme has to be named or its button links nowhere.
	 *
	 * @since 1.5.0
	 * @var array<int, string>
	 */
	public const LINK_PROTOCOLS = [ 'https', 'http', 'cursor', 'vscode' ];

	/**
	 * Render a guide: its steps, or why it cannot work here.
	 *
	 * @param AlbertClientGuide $guide     The guide.
	 * @param string            $id_prefix Prefix for element ids, unique per place it is drawn.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function render( array $guide, string $id_prefix ): void {
		$base = $id_prefix . '-' . $guide['id'];

		if ( $guide['needs_public'] ) {
			self::render_unavailable( $guide, $base );
			return;
		}

		if ( $guide['blocked'] !== '' ) {
			?>
			<div class="albert-guide-panel albert-guide-panel--unavailable">
				<p class="albert-guide-panel__intro"><?php echo esc_html( $guide['blocked'] ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="albert-guide-panel">
			<?php if ( $guide['intro'] !== '' ) { ?>
				<p class="albert-guide-panel__intro"><?php echo esc_html( $guide['intro'] ); ?></p>
			<?php } ?>

			<?php self::render_notes( array_filter( $guide['notes'], static fn( array $note ): bool => ! $note['after'] ), $base ); ?>

			<ol class="albert-guide-panel__steps">
				<?php foreach ( $guide['steps'] as $index => $step ) { ?>
					<li class="albert-guide-panel__step">
						<span class="albert-guide-panel__text"><?php echo esc_html( $step['text'] ); ?></span>
						<?php
						if ( $step['action'] !== null ) {
							self::render_action( $step['action'], $base . '-step-' . $index );
						}
						?>
					</li>
				<?php } ?>
			</ol>

			<?php self::render_notes( array_filter( $guide['notes'], static fn( array $note ): bool => $note['after'] ), $base ); ?>

			<?php self::render_more( $guide['more'], $base ); ?>

			<?php if ( $guide['manual'] !== null ) { ?>
				<details class="albert-guide-panel__manual">
					<summary><?php esc_html_e( 'Set it up by hand', 'albert-ai-butler' ); ?></summary>
					<?php if ( $guide['manual']['path'] !== '' ) { ?>
						<p class="albert-guide-panel__path">
							<?php esc_html_e( 'File', 'albert-ai-butler' ); ?>
							<code><?php echo esc_html( $guide['manual']['path'] ); ?></code>
						</p>
					<?php } ?>
					<ol class="albert-guide-panel__manual-steps">
						<?php foreach ( $guide['manual']['steps'] as $manual_step ) { ?>
							<li><?php echo esc_html( $manual_step ); ?></li>
						<?php } ?>
					</ol>
					<?php self::render_code( $guide['manual']['snippet'], $base . '-manual', $guide['manual']['snippet_label'] ); ?>
				</details>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * Why an app that needs a public site cannot connect to this one, and
	 * what to use instead.
	 *
	 * @param AlbertClientGuide $guide The guide.
	 * @param string            $base  Element id base.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_unavailable( array $guide, string $base ): void {
		?>
		<div class="albert-guide-panel albert-guide-panel--unavailable" id="<?php echo esc_attr( $base ); ?>">
			<p class="albert-guide-panel__intro"><?php echo esc_html( $guide['unavailable'] ); ?></p>
			<p class="albert-guide-panel__intro"><?php esc_html_e( 'Use an app on this computer, like Claude Desktop or Cursor. Or make the site reachable from the internet, for example with a tunnel such as Cloudflare Tunnel or ngrok, and mark it as public.', 'albert-ai-butler' ); ?></p>
			<p class="albert-guide-panel__alternatives">
				<button type="button" class="button button-secondary" data-albert-show-guide="claude-desktop"><?php esc_html_e( 'Show Claude Desktop', 'albert-ai-butler' ); ?></button>
				<button type="button" class="button button-secondary" data-albert-show-guide="cursor"><?php esc_html_e( 'Show Cursor', 'albert-ai-butler' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * A step's one action: a link that opens an app, or something to copy.
	 *
	 * @param AlbertGuideAction $action The action.
	 * @param string            $id     Element id for a copy block.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_action( array $action, string $id ): void {
		if ( $action['type'] === 'copy' ) {
			self::render_code( $action['value'], $id );
			return;
		}

		?>
		<span class="albert-guide-panel__action">
			<a class="button button-primary" href="<?php echo esc_url( $action['value'], self::LINK_PROTOCOLS ); ?>"<?php echo $action['type'] === 'download' ? ' download' : ''; ?><?php echo $action['missing'] !== '' ? ' data-albert-app-link' : ''; ?>>
				<?php echo esc_html( $action['label'] ); ?>
			</a>
			<?php if ( $action['meta'] !== '' ) { ?>
				<span class="albert-guide-panel__meta"><?php echo esc_html( $action['meta'] ); ?></span>
			<?php } ?>
		</span>
		<?php if ( $action['missing'] !== '' ) { ?>
			<span class="albert-guide-panel__missing" data-albert-link-missing hidden><?php echo esc_html( $action['missing'] ); ?></span>
		<?php } ?>
		<?php
	}

	/**
	 * Requirement notes: quiet, so a note never reads as an error.
	 *
	 * @param array<int, array{text: string, command: string, after: bool}> $notes The notes.
	 * @param string                                                        $base  Element id base.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_notes( array $notes, string $base ): void {
		foreach ( $notes as $index => $note ) {
			?>
			<div class="albert-guide-panel__note">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<div class="albert-guide-panel__note-body">
					<p><?php echo esc_html( $note['text'] ); ?></p>
					<?php
					if ( $note['command'] !== '' ) {
						self::render_code( $note['command'], $base . '-note-' . $index );
					}
					?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * The named apps and the bridge under Other apps, each collapsed.
	 *
	 * @param array<int, array{id: string, label: string, hint: string, snippet: string, quiet: bool}> $more The snippets.
	 * @param string                                                                                   $base Element id base.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_more( array $more, string $base ): void {
		if ( $more === [] ) {
			return;
		}

		?>
		<div class="albert-guide-panel__more">
			<h3 class="albert-guide-panel__more-title"><?php esc_html_e( 'Steps for a specific app', 'albert-ai-butler' ); ?></h3>
			<?php foreach ( $more as $item ) { ?>
				<details class="albert-guide-panel__fold<?php echo $item['quiet'] ? ' albert-guide-panel__fold--quiet' : ''; ?>">
					<summary><?php echo esc_html( $item['label'] ); ?></summary>
					<?php if ( $item['hint'] !== '' ) { ?>
						<p class="albert-guide-panel__hint"><?php echo esc_html( $item['hint'] ); ?></p>
					<?php } ?>
					<?php self::render_code( $item['snippet'], $base . '-' . $item['id'] ); ?>
				</details>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * A code block with its Copy button.
	 *
	 * @param string $code  What to show and copy.
	 * @param string $id    Element id.
	 * @param string $label A visible label above the block, or ''.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_code( string $code, string $id, string $label = '' ): void {
		?>
		<div class="albert-guide-panel__code">
			<?php if ( $label !== '' ) { ?>
				<span class="albert-guide-panel__code-label" id="<?php echo esc_attr( $id ); ?>-label"><?php echo esc_html( $label ); ?></span>
			<?php } ?>
			<?php if ( str_contains( $code, "\n" ) ) { ?>
				<?php // A config block keeps its indentation and scrolls rather than rewrapping. ?>
				<pre class="albert-guide-panel__block" id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $code ); ?></pre>
			<?php } else { ?>
				<pre id="<?php echo esc_attr( $id ); ?>"><?php echo self::wrap_at_spaces( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every token is escaped inside. ?></pre>
			<?php } ?>
			<button type="button" class="button button-secondary albert-copy-button" data-copy-target="<?php echo esc_attr( $id ); ?>"<?php echo $label !== '' ? ' aria-describedby="' . esc_attr( $id ) . '-label"' : ''; ?>>
				<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * An escaped one-line command whose lines break only at spaces.
	 *
	 * A browser also breaks at hyphens and slashes, which splits a URL or a
	 * flag mid-word and makes a command look like two. Each run of non-space
	 * characters is kept whole; copying still reads the plain text.
	 *
	 * @param string $code The code.
	 *
	 * @return string Escaped HTML.
	 * @since 1.5.0
	 */
	private static function wrap_at_spaces( string $code ): string {
		$parts = preg_split( '/(\s+)/', $code, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$html  = '';

		foreach ( is_array( $parts ) ? $parts : [ $code ] as $part ) {
			$html .= trim( $part ) === ''
				? esc_html( $part )
				: '<span class="albert-guide-panel__token">' . esc_html( $part ) . '</span>';
		}

		return $html;
	}
}
