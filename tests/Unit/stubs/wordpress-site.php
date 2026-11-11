<?php
/**
 * WordPress stubs for the site-reading functions the Context readers use.
 *
 * Kept separate from `wordpress.php` because these describe a *site*, a theme,
 * a palette, a set of post types, rather than the hook and option plumbing
 * every test needs. A test that wants a particular site writes it into
 * `$GLOBALS['albert_test_site']`; anything it does not set falls back to the
 * defaults here, so a test only has to state the part it cares about.
 *
 * `wp_strip_all_tags()` is deliberately not here: `tests/wp-function-stubs.php`
 * already carries a faithful port of it, and two stubs of one function would
 * make a test's result depend on which file happened to load first.
 *
 * @package Albert\Tests\Unit\stubs
 */

/**
 * Read one key from the configured test site.
 *
 * @param string $key      Key to read.
 * @param mixed  $fallback Value when the test has not set it.
 *
 * @return mixed
 */
function albert_test_site( string $key, mixed $fallback = null ): mixed {
	return $GLOBALS['albert_test_site'][ $key ] ?? $fallback;
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Stub get_bloginfo.
	 *
	 * @param string $show Field to read.
	 *
	 * @return string
	 */
	function get_bloginfo( string $show = 'name' ): string {
		$map = [
			'name'        => (string) albert_test_site( 'name', 'Test Site' ),
			'description' => (string) albert_test_site( 'tagline', '' ),
			'version'     => (string) albert_test_site( 'wordpress', '7.1' ),
		];

		return $map[ $show ] ?? '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub home_url.
	 *
	 * @param string $path Optional path.
	 *
	 * @return string
	 */
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Stub get_locale.
	 *
	 * @return string
	 */
	function get_locale(): string {
		return (string) albert_test_site( 'locale', 'en_US' );
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/**
	 * Stub wp_timezone_string.
	 *
	 * @return string
	 */
	function wp_timezone_string(): string {
		return (string) albert_test_site( 'timezone', '+00:00' );
	}
}

if ( ! function_exists( 'wp_is_block_theme' ) ) {
	/**
	 * Stub wp_is_block_theme.
	 *
	 * @return bool
	 */
	function wp_is_block_theme(): bool {
		return (bool) albert_test_site( 'block_theme', true );
	}
}

if ( ! function_exists( 'wp_theme_has_theme_json' ) ) {
	/**
	 * Stub wp_theme_has_theme_json.
	 *
	 * @return bool
	 */
	function wp_theme_has_theme_json(): bool {
		return (bool) albert_test_site( 'has_theme_json', true );
	}
}

if ( ! function_exists( 'current_theme_supports' ) ) {
	/**
	 * Stub current_theme_supports.
	 *
	 * @param string $feature Feature name.
	 *
	 * @return bool
	 */
	function current_theme_supports( string $feature ): bool {
		return in_array( $feature, (array) albert_test_site( 'theme_supports', [] ), true );
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	/**
	 * Stub wp_get_theme returning a minimal WP_Theme-like double.
	 *
	 * @return object
	 */
	function wp_get_theme(): object {
		return new Albert_Test_Theme(
			(string) albert_test_site( 'theme', 'Test Theme' ),
			(string) albert_test_site( 'theme_version', '1.0' )
		);
	}
}

if ( ! function_exists( 'wp_get_global_settings' ) ) {
	/**
	 * Stub wp_get_global_settings, origin-keyed like core's.
	 *
	 * @param array<int, string> $path Settings path.
	 *
	 * @return mixed
	 */
	function wp_get_global_settings( array $path ): mixed {
		$key      = implode( '.', $path );
		$settings = (array) albert_test_site( 'global_settings', [] );

		return $settings[ $key ] ?? [];
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * Stub get_post_types.
	 *
	 * @param array<string, mixed> $args   Query args (ignored).
	 * @param string               $output Output type (ignored).
	 *
	 * @return array<int, string>
	 */
	function get_post_types( array $args = [], string $output = 'names' ): array {
		return (array) albert_test_site( 'post_types', [ 'post', 'page', 'attachment' ] );
	}
}

if ( ! function_exists( 'get_taxonomies' ) ) {
	/**
	 * Stub get_taxonomies.
	 *
	 * @param array<string, mixed> $args   Query args (ignored).
	 * @param string               $output Output type (ignored).
	 *
	 * @return array<int, string>
	 */
	function get_taxonomies( array $args = [], string $output = 'names' ): array {
		return (array) albert_test_site( 'taxonomies', [ 'category', 'post_tag' ] );
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Stub post_type_exists.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return bool
	 */
	function post_type_exists( string $post_type ): bool {
		return in_array( $post_type, (array) albert_test_site( 'post_types', [ 'post', 'page' ] ), true );
	}
}

if ( ! function_exists( 'wp_count_posts' ) ) {
	/**
	 * Stub wp_count_posts.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return object
	 */
	function wp_count_posts( string $post_type = 'post' ): object {
		return (object) [ 'publish' => (int) albert_test_site( 'published_' . $post_type, 0 ) ];
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Stub is_multisite.
	 *
	 * @return bool
	 */
	function is_multisite(): bool {
		return (bool) albert_test_site( 'multisite', false );
	}
}

if ( ! class_exists( 'Albert_Test_Theme' ) ) {
	/**
	 * Minimal WP_Theme double: a name, a version, and no parent.
	 */
	class Albert_Test_Theme {
 // phpcs:ignore Universal.Files.SeparateFunctionsFromOO

		/**
		 * Theme name.
		 *
		 * @var string
		 */
		private string $name;

		/**
		 * Theme version.
		 *
		 * @var string
		 */
		private string $version;

		/**
		 * Construct.
		 *
		 * @param string $name    Theme name.
		 * @param string $version Theme version.
		 */
		public function __construct( string $name, string $version ) {
			$this->name    = $name;
			$this->version = $version;
		}

		/**
		 * Read a header field.
		 *
		 * @param string $header Header name.
		 *
		 * @return string
		 */
		public function get( string $header ): string {
			return $header === 'Version' ? $this->version : $this->name;
		}

		/**
		 * Parent theme, if any.
		 *
		 * @return false
		 */
		public function parent(): bool {
			return false;
		}

		/**
		 * Templates offered for a post type, from albert_test_site( 'page_templates' ).
		 *
		 * @param mixed  $post      Unused.
		 * @param string $post_type Post type.
		 *
		 * @return array<string, string>
		 */
		public function get_page_templates( $post = null, string $post_type = 'page' ): array {
			$templates = (array) albert_test_site( 'page_templates', [] );

			return (array) ( $templates[ $post_type ] ?? [] );
		}
	}
}
