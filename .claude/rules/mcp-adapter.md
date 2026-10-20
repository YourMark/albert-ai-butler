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

`wordpress/mcp-adapter` (namespace `WP\MCP\`) ships as the MCP Adapter plugin on WordPress.org. Other plugins bundle it too (WooCommerce does), so several copies on one site is normal.

## Rules
- The plugin supplies the adapter; `lib/mcp-adapter/` is a fallback. `AdapterSource::offer_fallback()` offers it to Jetpack Autoloader only while the plugin is inactive. Offered alongside an equal version, Jetpack would load Albert's copy (`albert-ai-butler` sorts before `mcp-adapter`) and the plugin would blame Albert in a notice on every screen. The request that activates the plugin already counts it as active. Jetpack caches plugin paths in the `jetpack_autoloader_plugin_paths` transient, so our path can linger one request; ties go to active plugins, so that is harmless.
- Never scope it (Mozart, `Albert\Vendor\`) and never require it or `php-mcp-schema` from the root `composer.json`. Code uses bare `WP\MCP\`.
- Why: the adapter coordinates through a hook name (`mcp_adapter_init`), a server id and a REST route, none of which scoping renames. A scoped copy registers the same server twice, gets `duplicate_server_id`, and `/albert/v1/mcp` answers `401 rest_forbidden`, which looks exactly like a missing token. Upstream declined to support it ([mcp-adapter#172](https://github.com/WordPress/mcp-adapter/issues/172)).
- `lib/mcp-adapter/` is its own Composer project; the root's install and update scripts run it. `automattic/jetpack-autoloader` is required in both, with `allow-plugins` `true` in both, or the manifests stop generating and the adapter silently disappears.
- `albert-ai-butler.php` loads `vendor/autoload_packages.php`, which calls WordPress functions. Test bootstraps use `vendor/autoload.php` plus `lib/mcp-adapter/vendor/autoload.php` instead.
- Albert may run against a newer copy from the plugin or another plugin. `AdapterStatus` feature-detects the classes Albert calls rather than checking versions; `AdapterHealth` reports anything missing.
- `lib/mcp-adapter/vendor/` ships. `.distignore` drops only the adapter's `mcp-adapter.php` and `readme.txt`; the release workflow checks both.
- Free never installs the plugin itself (WordPress.org requires the owner's click). `AdapterNudge` asks, non-dismissibly, through core's install and activate actions, until the plugin is active. `discover-abilities` carries a `notice` for users who can act on it (`albert/mcp/adapter_notice`, `''` turns it off).
- Leave the adapter's "load the plugin instead" deprecation alone; it is upstream's signal.

## Known limits (harmless: the fallback loads)
- WP-CLI activation of the plugin logs `Constant WP_MCP_VERSION already defined` once.
- A renamed plugin folder (`mcp-adapter-main/`) is not recognised, and on multisite a plugin active on one site is not seen from network admin.

## Bumping and checking
- Bump: `composer update --working-dir=lib/mcp-adapter`. The lock and Jetpack manifests rebuild themselves.
- Check a site loads one copy:

```bash
wp eval '$c = array_filter( get_declared_classes(), fn( $c ) => str_ends_with( $c, "\\MCP\\Core\\McpAdapter" ) ); echo implode( ", ", $c ), "\n";'
```

## WooCommerce timing bug (admin pages)
`Plugin::init()` starts the adapter only when `! is_admin()`. On an admin page, `wp_get_abilities()` has already fired the one-shot `wp_abilities_api_init`; WooCommerce's REST preload then starts the adapter, whose default server asks for abilities that were never registered, giving `_doing_it_wrong` notices. Albert needs the adapter only on REST requests, where `is_admin()` is false. If WordPress ever changes that, revisit this guard (see `WooCommerceMcpAdapterTimingTest`).
