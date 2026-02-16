# Licensing Unification — Summary of Changes

## Problem

Albert Free and the EDD SL SDK ran **two separate AJAX handlers** for license activation, causing:

1. **Option key mismatch**: Albert's handler wrote to `{slug}_license` (e.g. `extended-service_license`), but the EDD SDK wrote to `{option_slug}_license` (e.g. `albert-extended-service_license`). The SDK derives its slug via `basename(dirname($file))`, ignoring the `id` passed during registration.
2. **`albert_has_valid_license()` read the wrong key**: It used the addon's display slug (`extended-service`), so licenses activated from the Plugins page (via the SDK) appeared invalid.
3. **Duplicate API logic**: Two codepaths doing the same store API call with different error handling.

### Root cause of "activation not working"

The EDD SDK's `API::make_request()` caches failed requests for 1 hour in `wp_options` (`eddsdk_failed_request_{md5(url)}`). An SSL certificate error on `https://albert.test` (cURL error 60) caused a silent failure, and every subsequent attempt was blocked by the cache without making any API call.

---

## Solution

Removed Albert's custom AJAX handlers. The Settings page now calls the EDD SDK's own AJAX endpoints directly. One activation path, one set of option keys.

---

## Files Changed (albert plugin)

### `src/AddonSdk/AbstractAddon.php`

- Added `option_slug` to the addon registry, derived from `basename(dirname($data['file']))` — matches how the EDD SDK derives its slug.
- Updated `@var` and `@return` docblocks to include `option_slug`.

### `src/functions.php`

- `albert_has_valid_license()` now resolves the `option_slug` from the addon registry before reading `{option_slug}_license` from `wp_options`.
- Callers can still pass the display slug (e.g. `'extended-service'`) and it resolves to the correct option key (`albert-extended-service_license`).

### `src/Admin/Settings.php`

- **`enqueue_assets()`**: Generates EDD SDK token, timestamp, and nonce server-side (via `Tokenizer::tokenize()`). Passes addon list with `option_slug` values to JS.
- **`render_licenses_table()`**: Uses `option_slug` for reading both `_license` and `_license_key` options.
- **`render_actions()`**: Accepts `$license_key` parameter. Deactivate button now has `data-option-slug` and `data-license-key` attributes (instead of `data-addon-slug`).

### `assets/js/albert-licenses.js`

- **`handleActivate()`**: Calls `edd_sl_sdk_activate_{option_slug}` for each registered addon in parallel via `Promise.allSettled`. Sends `license`, `token`, `timestamp`, `nonce` as POST params.
- **`handleDeactivate()`**: Calls `edd_sl_sdk_deactivate_{option_slug}` with the stored license key from `data-license-key`.
- **`refreshTable()`**: New method — calls `albert_refresh_licenses_table` AJAX endpoint after activate/deactivate to get fresh table HTML.

### `src/Licensing/functions.php`

- **Removed**: `albert_handle_license_activation` and `albert_handle_license_deactivation` (the old custom AJAX handlers).
- **Added**: `albert_refresh_licenses_table` — simple AJAX endpoint that returns rendered table HTML.

---

## Files NOT Changed

- **`albert-extended-service`**: No changes needed. The addon's `slug: 'extended-service'` stays. `albert_has_valid_license('extended-service')` resolves via the registry.

---

## How It Works Now

### Activation (Settings page)

1. JS reads addon list + EDD SDK credentials from `window.albertLicenses`
2. For each addon, JS POSTs to `wp_ajax_edd_sl_sdk_activate_{option_slug}`
3. EDD SDK validates token + nonce, calls the store API, writes options
4. JS calls `albert_refresh_licenses_table` to update the table

### Activation (Plugins page)

1. EDD SDK's own modal and JS handle everything
2. Writes to the same `{option_slug}_license` / `{option_slug}_license_key` options

### License validation

1. `albert_has_valid_license('extended-service')` looks up registry → finds `option_slug: 'albert-extended-service'`
2. Reads `albert-extended-service_license` from `wp_options`
3. Both activation paths write to this same key — no mismatch

---

## Key Technical Details

- EDD SDK slug = `basename(dirname($file))` = `albert-extended-service`
- Addon registry slug = `extended-service` (display slug)
- AJAX actions: `edd_sl_sdk_activate_albert-extended-service`, `edd_sl_sdk_deactivate_albert-extended-service`
- Token generation: `\EasyDigitalDownloads\Updater\Utilities\Tokenizer::tokenize($timestamp)`
- Nonce action: `edd_sl_sdk_license_handler`
- The SDK caches failed API requests for 1 hour in `eddsdk_failed_request_{md5(store_url)}` — if activation silently fails, check this option.
