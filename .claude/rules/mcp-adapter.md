---
paths:
  - "src/MCP/**"
  - "lib/**"
  - "albert-ai-butler.php"
  - "composer.json"
  - "src/Core/Plugin.php"
  - "tests/Integration/Compat/**"
  - "tests/**/MCP/**"
---

# Bundled MCP adapter

## Bundled MCP adapter (plugin first, shared, not scoped)

`wordpress/mcp-adapter` provides the `WP\MCP\*` namespace (with `wordpress/php-mcp-schema`,
its own dependency, not Albert's). Since 0.7.0 upstream ships it as the **MCP Adapter plugin** on
WordPress.org and deprecates bundling it. Other plugins still bundle it (WooCommerce does), so
more than one copy on a site is normal and expected.

**The plugin supplies the adapter; Albert's copy is a fallback.** Albert keeps a copy in
`lib/mcp-adapter/` for sites without the plugin and offers it to Jetpack Autoloader only when
the plugin is not active (`Albert\MCP\AdapterSource`). Offered alongside an equal-version
plugin, Jetpack picks the plugin read first, `albert-ai-butler` sorts before `mcp-adapter`, and
the plugin then shows a red notice on every admin screen blaming Albert as an outdated plugin
bundling its own copy.

**Albert does not scope it, and must not.** The adapter is loaded unscoped and shared, via
Jetpack Autoloader. That is upstream's supported model and the only one that works.

### Why scoping was removed

Albert used to scope both packages with Mozart into `Albert\Vendor\WP\MCP\`. That looks
like the safe choice and is the opposite. Mozart rewrites class names; it does not rewrite
WordPress, and the adapter coordinates through three things Mozart cannot touch:

- the hook name `mcp_adapter_init`, a literal string,
- the default server id `mcp-adapter-default-server`, hard-coded,
- the REST route of the same name.

So a scoped copy is a *second class firing the same global action*. Both copies'
`DefaultServerFactory::create` stay hooked — different class names, so WordPress keeps both —
and each writes to its own singleton with the same id. The second write returns
`duplicate_server_id`, no server registers, and `/albert/v1/mcp` answers `401 rest_forbidden`,
which is also what a healthy install answers when handed no token. Nothing outside the site
distinguishes them, so the symptom sends people to debug authentication.

[WordPress/mcp-adapter#172](https://github.com/WordPress/mcp-adapter/issues/172) describes this
and was closed `NOT_PLANNED`: prefixed copies are unsupported, and guarding `DefaultServerFactory`
alone "would not make the other shared hooks, filters, routes, and identifiers safe."

### How it works now

- The adapter is required by its own Composer project, **`lib/mcp-adapter/`** (own
  `composer.json`, lock and `vendor/`), not by the root. The root's `post-install-cmd` /
  `post-update-cmd` run `composer install` there, so one root install sets up both, in CI and
  releases too.
- **`automattic/jetpack-autoloader` is a requirement of both projects**. It generates nothing
  when pulled in only transitively, and `config.allow-plugins` must keep it **`true`** in both;
  if it goes back to `false`, the manifests stop being generated and the plugin silently loses
  its adapter.
- `albert-ai-butler.php` calls `AdapterSource::offer_fallback()`, which adds `lib/mcp-adapter`
  to Jetpack's `$jetpack_autoloader_activating_plugins_paths` unless the plugin is active, then
  requires **`vendor/autoload_packages.php`**, Jetpack's entry point. It calls WordPress
  functions, which is safe in a plugin file but *not* before WordPress loads, which is why the
  test bootstraps use `vendor/autoload.php` plus `lib/mcp-adapter/vendor/autoload.php`.
- Jetpack reads each plugin's `vendor/composer/jetpack_autoload_classmap.php` (ours included,
  through that list) and loads a single newest copy site-wide. One class, one singleton, one
  `mcp_adapter_init` firing.
- Jetpack caches the plugin paths it read in the `jetpack_autoloader_plugin_paths` transient,
  so `lib/mcp-adapter` can stay a candidate for a request after the plugin activates. Ties go to
  active plugins first, so an equal version still loads the plugin's copy, and the cache is
  rewritten without our path on the next request.
- The adapter reports a "load the plugin instead" deprecation whenever a bundled copy starts.
  Albert leaves it alone: over REST core sends it as a response header, on WP-CLI it goes to
  stderr, and it is upstream's signal to developers.
- The request that activates the plugin counts it as active (same check as Jetpack's), so the
  fallback is not offered on that request either. WP-CLI activation cannot be detected that way,
  and logs `Constant WP_MCP_VERSION already defined` once (an unguarded `define()` upstream).
- Known limits, all harmless (the fallback loads and Albert works, the plugin may show its
  notice): a plugin folder renamed from `mcp-adapter/` (a GitHub zip as `mcp-adapter-main/`) is
  not recognised, and on multisite a plugin activated on one site is not seen from network admin.
- Albert's code references bare **`WP\MCP\…`**. Never reintroduce `Albert\Vendor\`, and never
  require the adapter or `php-mcp-schema` from the root `composer.json`: that puts Albert's copy
  back in the race with the plugin's.

### Consequences worth knowing

- **Albert may run against the plugin's or another plugin's newer copy.** That is the accepted
  trade-off of the shared model, and the reason `Albert\MCP\AdapterStatus` feature-detects the
  classes Albert calls rather than checking a version number. If any are missing,
  `Albert\MCP\AdapterHealth` reports it in an admin notice and in Site Health, naming the path
  the loaded copy came from.
- **`lib/mcp-adapter/vendor/` ships.** `.distignore` excludes only the adapter's own
  `mcp-adapter.php` and `readme.txt`, and the release workflow asserts both halves.
- **Albert asks owners to install the plugin** while it is missing or inactive
  (`Albert\Admin\AdapterNudge`, wording and links in `Albert\MCP\AdapterPlugin`): the
  Dashboard's attention card and setup step 1, a notice on every other Albert screen, a notice
  on Albert's row on the Plugins screen, and Site Health. None is dismissible; each clears once
  the plugin is active. The button uses core's own `install-plugin` and `activate-plugin` ajax
  actions, falling back to core's installer (which asks for FTP details when needed). An admin
  request never starts Albert's copy, which keeps activation clean. WordPress.org forbids
  installing without the user's click, so Free never auto-installs. `discover-abilities` also
  carries a `notice` field for users who can act on it, so their assistant passes it on;
  `albert/mcp/adapter_notice` returning `''` turns it off.
- **Bumping the adapter:** `composer update --working-dir=lib/mcp-adapter`. Nothing to
  regenerate; the lock and the Jetpack manifests are rebuilt by Composer's own hook.
- **Verify a shared install:** exactly one adapter class, one firing, both servers present.

```bash
wp eval '
$loaded = array_filter( get_declared_classes(), fn( $c ) => str_ends_with( $c, "\\MCP\\Core\\McpAdapter" ) );
echo implode( ", ", $loaded ), "\n";'
```

## Known Compatibility Issues

### WooCommerce mcp-adapter timing bug (admin pages)

**Affected versions:** WooCommerce 10.4+ (ships `wordpress/mcp-adapter`)

**Symptom:** `_doing_it_wrong` notices for `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` on Albert admin pages when WooCommerce is active.

**Root cause:** The mcp-adapter's `DefaultServerFactory` hooks `register_default_abilities()` on `wp_abilities_api_init`, a one-shot action. On Albert admin pages, `wp_get_abilities()` fires that action during page render (before `rest_api_init`). WooCommerce then preloads REST data via `Settings::add_component_settings()` → `rest_preload_api_request()`, which triggers `rest_api_init` → `McpAdapter::init()` → `mcp_adapter_init` → `DefaultServerFactory::create()`. The factory calls `wp_get_ability()` for its three tools, but they were never registered because `wp_abilities_api_init` already fired. The upstream fix would be for `maybe_create_default_server()` to check `did_action('wp_abilities_api_init')` and call `register_default_abilities()` directly if the action already fired.

**Our fix:** `Plugin::init()` only calls `McpAdapter::instance()` when `! is_admin()`. REST API requests (`/wp-json/...`) have `is_admin() === false`, so the adapter initializes normally. Admin pages skip initialization entirely, avoiding the timing conflict. The adapter is not needed on admin pages: Albert only needs it for serving MCP REST endpoints.

**If this breaks in the future:** The fix relies on `is_admin()` being `false` for REST API requests. If WordPress changes this behavior, `McpAdapter::instance()` may need to be deferred differently. Check `wp-includes/load.php` for `is_admin()` definition.
