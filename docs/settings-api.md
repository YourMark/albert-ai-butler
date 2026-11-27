# Albert Settings API

Albert ships a single **Settings** admin screen that Free and every add-on
contribute to. Free renders one `<form>`, validates one nonce, runs one save
loop, and persists each field to `wp_options`.

There are two APIs:

1. **`albert_register_setting()`** — the simple, public API add-ons should use.
   Registers a single field on the shared Albert Settings card.
2. **`albert_register_settings_section()`** — the advanced API for add-ons that
   need a whole card of their own (custom render callbacks, conditional
   visibility, etc.). Free uses it internally for the Licenses card.

## Public API: `albert_register_setting()`

Hook `albert/settings/register` and register each setting as a flat array:

```php
add_action( 'albert/settings/register', static function (): void {
    if ( ! function_exists( 'albert_register_setting' ) ) {
        return;
    }

    albert_register_setting( [
        'title'       => __( 'Retention (days)', 'albert-premium-service' ),
        'option_name' => 'premium_activity_log_retention_days',
        'type'        => 'number',
        'description' => __( 'How long to keep activity log entries before pruning.', 'albert-premium-service' ),
        'default'     => 30,
        'attributes'  => [ 'min' => 1, 'max' => 365, 'step' => 1 ],
        'badge'       => __( 'Premium', 'albert-premium-service' ),
    ] );
} );
```

Read the saved value anywhere with:

```php
$retention = (int) get_option( 'premium_activity_log_retention_days', 30 );
```

### Schema

| Key | Type | Required | Notes |
|-----|------|----------|-------|
| `title` | string | **yes** | Visible label above the input. |
| `option_name` | string | **yes** | Exact `wp_options` key used for storage. |
| `type` | string | **yes** | One of `text`, `url`, `number`, `textarea`, `select`, `checkbox`, `radio-cards`. |
| `description` | string | no | Help text rendered with the label. Keep it to one line and put the rest in `info`. |
| `default` | mixed | no | Returned when no value is stored. Also registered with WordPress, so `get_option()` serves it without callers restating it — on admin requests; see *Storage* below. |
| `options` | array | **yes for `select` and `radio-cards`** | `value => label` pairs for `select`; for `radio-cards`, `value => [ 'label' =>, 'description' =>, 'recommended' => ]`. |
| `min` / `max` | int\|float | no | The allowed range for a `number` field. Drives **both** the control's attributes and the sanitiser, so a value outside it cannot be stored, whatever posted it. *Since 1.4.0.* |
| `attributes` | array | no | Extra HTML attributes (e.g. `placeholder`, `step`). `min` and `max` work here too, identically. Reserved keys (`name`, `id`, `type`, `value`, `checked`) are ignored. |
| `badge` | string | no | Small pill rendered next to the label (e.g. `"Premium"`). |
| `suffix` | string | no | The unit shown beside the control ("days", "MB"), tied to it with `aria-describedby`. |
| `info` | string | no | Text for an info "(i)" beside the label, for the detail that will not fit on the description's one line. May contain `<code>`. |
| `section` | string | no | Id of the card to land in. Must be namespaced (`myplugin/logging`). *Since 1.4.0.* |
| `section_title` | string | no | Creates the card named by `section` if it does not exist yet, with this heading. *Since 1.4.0.* |
| `section_priority` | int | no | Orders that card against the rest (default `50`; Free's own run 60–68, Licenses last at 9000). *Since 1.4.0.* |

### Where the field lands

Give your settings a heading that says what they are about. One call does it:

```php
albert_register_setting( [
    'title'         => __( 'Log retention', 'my-addon' ),
    'suffix'        => __( 'days', 'my-addon' ),
    'option_name'   => 'my_addon_log_retention_days',
    'type'          => 'number',
    'min'           => 0,
    'max'           => 3650,
    'section'       => 'my-addon/logging',
    'section_title' => __( 'Logging', 'my-addon' ),
] );
```

`min` and `max` there are enforced, not decorative: they reach the `<input>`
*and* the sanitiser, so 99999 in that field is stored as 3650 and the person
saving is told.

Every later field naming `my-addon/logging` joins that card — including one
registered by a different add-on, which is how two plugins can share a heading
rather than each growing their own. Naming a card that already exists (one of
Free's, such as `albert/connections`) adds to it instead.

Omit `section` and the field goes to a shared card titled **Other**. That exists
so an add-on written against 1.1.0 keeps working; it is not the place to aim
for. It cannot be named after what is inside it, because it holds whatever
nobody placed — a heading borrowed from the first occupant is wrong the moment
an unrelated second setting lands beside it.

Missing a required key — or registering a `select` without `options` — logs a
`_doing_it_wrong()` notice and skips the field.

### Built-in field types

| Type | Renders | Default sanitizer |
|------|---------|-------------------|
| `text` | `<input type="text">` | `sanitize_text_field` |
| `url` | `<input type="url">` | `esc_url_raw` then `rtrim($v, '/')` |
| `number` | `<input type="number">` | `absint` (or `floatval` when `attributes.step` contains `.`) |
| `textarea` | `<textarea>` | `sanitize_textarea_field` |
| `select` | `<select>` | Validates against `options` keys; falls back to `default`. |
| `checkbox` | hidden `0` + visible `1` | Bool — `true` only when raw value is `'1' / 1 / true`. |

The checkbox renders a paired hidden input so unchecked submissions still
post a `0`. Without it WordPress would never know the user unchecked the box.

## Styling (1.4.0+)

Anything rendered through this API inherits Albert's design system — you do not
style it yourself, and you should not need to. Fields, sections and the page
shell are drawn with the shared primitives, so a setting registered by an add-on
looks identical to a core one and follows the admin colour scheme.

If an add-on renders its own screen under the Albert menu, declare the shared
stylesheet as a dependency rather than restating any value:

```php
wp_enqueue_style( 'my-screen', $url, [ 'albert-primitives' ], $version );
```

Use the literal handle, not `Albert\Admin\Assets::PRIMITIVES_HANDLE`, so an
older Albert without that class cannot fatal your plugin. Every token and
primitive is listed in `docs/design-system.md`; the add-on contract, including
menu ordering, is in `.claude/skills/albert-hooks/SKILL.md`.

Rendering your own `add_settings_error()` notices on a custom admin page under
the Albert menu? Use `Albert\Admin\Notices::render( $group )` rather than
calling `settings_errors( $group )` directly — it wraps the same output in
`aria-live="polite"`, which WordPress's own function does not do on its own,
so a notice announces itself to a screen reader identically wherever it
appears in Albert.

## Storage: WordPress owns the option, Albert owns the screen (1.4.0+)

Every field registered through this API is handed to WordPress's
`register_setting()` by `Albert\Settings\Storage`. Only the storage half
of WordPress's Settings API is used — `add_settings_field()`,
`do_settings_sections()` and `settings_fields()` are never called, so no
WordPress markup reaches the screen. Albert renders every control itself and the
form posts to `admin-post.php` with its own nonce.

**What this changes for you.** Your `sanitize_callback` now runs on *every*
write to the option, not just on the settings-form POST, because
`register_setting()` hooks `sanitize_option_{$option}` and `update_option()`
applies it. An invalid value can no longer be stored by code that bypasses the
form. Your declared `default` is also served by `get_option()` without callers
passing one, so a default no longer has to be repeated in the class that reads
it.

**Two consequences worth knowing.**

*Sanitisers must be idempotent.* The save loop sanitises, then
`update_option()` sanitises the result again. A callback must return clean
input unchanged and must not repeat a side effect (such as an
`add_settings_error()`) on the second pass.

*The guarantee is admin-only.* Registration runs on `admin_init`, so it covers
admin requests, admin-ajax and the settings POST — **not** WP-Cron and not
WP-CLI. Two practical rules follow:

- Do not assume a value written by cron or WP-CLI was sanitised.
- **Keep passing an explicit default to `get_option()`** in code that can run
  outside admin. The registered default does not exist there, so
  `get_option( 'your_option' )` returns `false`. Free's own cron sweeps and MCP
  request paths all still pass their defaults, with a comment saying why.

Registration is skipped for a read-only `custom` field (one whose
`sanitize_callback` is `'__return_null'`), because it stores nothing.

`show_in_rest` is opt-in per field and defaults to `false`; a setting is not
exposed over REST merely because it exists. Pass `'show_in_rest' => true` on the
field to publish it.

**Your registration callback now runs on every admin request.** Registration
happens on `admin_init`, so `albert/settings/register` fires on ordinary admin
pages and on admin-ajax, not only when the Settings screen is being drawn.
Before 1.4.0 it fired only on that screen. The schema is collected once per
request, but keep the callback to declaring fields: no queries, no HTTP, no side
effects.

## Overrides: when code owns a setting (1.4.0+)

A setting can be pinned in code, and the screen says so rather than offering a
control that would not change anything. Highest priority first:

1. **A constant named after the option in upper case.** `albert_privacy_mode`
   is set by `ALBERT_PRIVACY_MODE`. This is how a `wp-config.php` pins a value
   across a fleet.
2. **The filter `albert/settings/value/{option_name}`.** Return `null` (the
   default) to defer.
3. **The stored option**, then the declared default.

A field under an active override renders read-only, names its source, and is
skipped by the save loop, so submitting the form cannot overwrite a value the
owner was never shown a control for.

**Read-only means `readonly`, not `disabled`,** wherever the element has it
(`text`, `url`, `number`, `textarea`). A disabled control leaves the tab order,
which put the value in force and the sentence explaining the lock out of reach
of exactly the people who most need the explanation. `select`, checkbox and
radio have no `readonly`, so those stay `disabled`; `aria-disabled="true"`
states it either way, and the control's `aria-describedby` points at the hint.
The lock never depended on the browser withholding the value: the save loop
skips a locked field outright.

**Read your own setting through the chain.** `get_option()` sees only layer 3,
so a reader that bypasses `albert_get_setting()` will use a different value from
the one the screen reports:

```php
$days = (int) albert_get_setting( 'my_addon_log_retention_days', 30 );
```

**Declare a validator for anything with a range or a closed vocabulary.**

```php
add_filter( 'albert/settings/validator/my_addon_log_retention_days', static function (): callable {
    return static fn( $value ): bool => is_numeric( $value ) && (int) $value >= 0;
} );
```

An override the validator rejects is skipped and resolution continues to the
next layer, so a typo in a constant falls through to the stored value instead of
pinning the site to nonsense. It also has to hang off the option name rather
than the call site: the screen and the code reading the setting both consult it,
and that is the only thing that stops the two disagreeing about whether an
override is in force.

Answering the value filter on another hook's behalf? Name the hook the site
actually wrote, with `albert/settings/value_source/{option_name}`, so the screen
points somebody at code they can find.

## MCP external URL filter

The MCP endpoint URL is rendered on the **Connections** screen. There is no
admin input for overriding it — sites that need a different host (tunnels,
reverse proxies, local development) hook the `albert/mcp/external_url`
filter:

```php
add_filter( 'albert/mcp/external_url', static function (): string {
    if ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) {
        return 'https://albert.test';
    }
    return '';
} );
```

The filter must return a fully-qualified URL including the scheme
(`https://…` or `http://…`). Return an empty string to disable the override
and use `rest_url()` as normal.

Returned values are validated with `wp_http_validate_url()`. If the filter
returns a non-empty string that fails validation, Albert logs a
`_doing_it_wrong()` notice, falls back to the default endpoint, and the
Connections screen displays a warning explaining what is wrong.

## Advanced API: `albert_register_settings_section()`

Use this when a single field isn't enough — for example an activity log card
that includes a table plus two controls, or a feature toggle that pulls in a
custom render callback.

```php
add_action( 'albert/settings/register', static function (): void {
    if ( ! function_exists( 'albert_register_settings_section' ) ) {
        return;
    }

    albert_register_settings_section( [
        'id'         => 'premium/activity-log',
        'title'      => __( 'Activity Log', 'albert-premium-service' ),
        'priority'   => 20,
        'icon'       => 'list-view',
        'badge'      => __( 'Premium', 'albert-premium-service' ),
        'show_if'    => static fn (): bool => albert_has_valid_license( 'albert-premium-service' ),
        'capability' => 'manage_options',
        'fields'     => [
            [
                'id'      => 'enabled',
                'type'    => 'checkbox',
                'label'   => __( 'Enable activity log', 'albert-premium-service' ),
                'default' => true,
            ],
            [
                'id'          => 'retention_days',
                'type'        => 'number',
                'label'       => __( 'Retention (days)', 'albert-premium-service' ),
                'default'     => 30,
                'attributes'  => [ 'min' => 1, 'max' => 365, 'step' => 1 ],
                'show_if'     => static fn (): bool => (bool) get_option( 'premium_activity_log_enabled', true ),
            ],
        ],
    ] );
} );
```

### Section schema

| Key | Type | Required | Default | Notes |
|-----|------|----------|---------|-------|
| `id` | string | yes | — | Must contain `/` (namespace prefix). Reused id replaces the previous section. |
| `title` | string | yes | — | Heading shown in the section card. |
| `description` | string | no | `''` | Short paragraph below the title. |
| `priority` | int | no | `10` | Lower runs earlier. Free uses 50 for the shared Settings card and 9000 for Licenses. |
| `show_if` | callable | no | always-true | Returning `false` skips the section for both render and save. |
| `icon` | string | no | `''` | Dashicon slug **without** the `dashicons-` prefix. |
| `badge` | string | no | `''` | Pill rendered next to the title. |
| `capability` | string | no | `'manage_options'` | Required capability to view and save the section. |
| `fields` | array | yes | — | Indexed list of field arrays (see below). |

### Section-field schema

| Key | Type | Required | Notes |
|-----|------|----------|-------|
| `id` | string | yes | Combined with the section id to form the option name (slashes → underscores). |
| `type` | string | yes | One of `text`, `url`, `number`, `textarea`, `select`, `checkbox`, `radio-cards`, `custom`. |
| `label` | string | yes (except `custom`) | Visible label above the input. Custom fields may pass `''`. |
| `description` | string | no | Help text. |
| `default` | mixed | no | Returned when no value is stored. |
| `badge` | string | no | Pill rendered next to the label. |
| `show_if` | callable | no | Field-level conditional (same semantics as section `show_if`). |
| `render_callback` | callable | yes for `custom` | `function(array $field, mixed $current_value): void` — echo input HTML only. |
| `sanitize_callback` | callable | yes for `custom`, optional override otherwise | `function(mixed $raw): mixed`. Use `'__return_null'` to mark a custom field read-only. |
| `options` | array | yes for `select` | `value => label` pairs. |
| `attributes` | array | no | Extra HTML attributes (e.g. `placeholder`, `step`). |
| `min` / `max` | int\|float | no | The allowed range for a `number` field. Drives **both** the control's attributes and the sanitiser, so a value outside it cannot be stored, whatever posted it. Declaring them under `attributes` works identically and is what fields did before 1.4.0. *Since 1.4.0.* |
| `option_name` | string | no | Use a literal `wp_options` key instead of the auto-generated one. |
| `show_in_rest` | bool | no | Expose this option over the REST API. Opt-in, default `false`. *Since 1.4.0.* |
| `suffix` | string | no | The unit shown beside the control ("days", "MB"). Associated with it via `aria-describedby`, so "90" and "90 days" are not the same field to a screen reader. |
| `info` | string | no | Text for an info "(i)" beside the label. **Keep `description` to one line and put the rest here** — the edge case, the consequence, what `0` does. May contain `<code>`. The description must still read correctly without opening it. |
| `disabled` | bool\|callable | no | Renders the control read-only. Rarely needed: an active constant or filter does this on its own (see below). Use it for a condition the resolver cannot see. A callable is evaluated at render time, so a field declares the *condition* rather than a snapshot. |
| `hint` | array\|callable | no | `[ 'text' => string, 'tone' => 'info'\|'warning' ]`, shown under the control. A callable may return `null` when there is nothing to say. `text` may contain `<code>` and nothing else. An overridden field generates its own hint, so supply one only when you can say more. |
| `display_value` | callable | no | `function( mixed $stored ): mixed` — what to *show*, when that differs from what is stored. An override is displayed automatically; this is the escape hatch for a value the resolver cannot express. |

### Bounded numbers

`min` and `max` are enforced, not decorative. They used to reach the `<input>`
and stop there, which meant the browser was the only thing holding the line: a
field declaring `max => 3650` accepted 99999 from a crafted POST, and — now that
options are registered — from any `update_option()` call as well.

Clamping is also *said*. Rewriting somebody's 99999 to 3650 without a word
leaves them looking at a number they did not type, so the sanitiser raises a
warning naming both values. That behaviour existed as a one-off on Free's upload
size limit; it is now what every bounded number field does.

One consequence worth knowing: without a declared `min`, a number field is run
through `absint()`, so `-5` is stored as `5`. **With** a `min`, the declaration
is treated as the authority on the lower bound and `-5` clamps to it. Declare
`min => 0` rather than relying on `absint()` if what you mean is "not negative".

### When something else owns the value

A setting can be decided by a `wp-config.php` constant or by a filter rather
than by the stored option. When one is, the control renders read-only and says
where the value comes from: a box somebody can type into and save, whose value
code then discards, is a lie about what the site does.

**You do not declare any of this.** `Albert\Settings\Value` resolves it
and `SettingsRenderer` reacts, so a new overridable setting is an ordinary field
definition and nothing more. Free's upload size limit used to carry three
callbacks (`max_mb_is_filtered`, `max_mb_display_value`, `max_mb_hint`) to say
"a filter is overriding this"; two of them are gone, and only the hint remains,
because only that field can state the exact size in force — the control rounds
up to whole megabytes, so 500 KB would otherwise show as "1 MB" with nothing to
correct the impression.

The chain, highest priority first:

1. **A constant** named after the option in upper case: `albert_privacy_mode` is
   pinned by `ALBERT_PRIVACY_MODE`.
2. **The filter `albert/settings/value/{option_name}`**, returning `null` to
   defer.
3. **The stored option**, then the declared default.

**Namespace your option names.** Layer 1 reads a *global* constant, and nothing
reserves that space: an option called `myplugin_debug` binds to whatever
`MYPLUGIN_DEBUG` already means on the site, whoever defined it and for whatever
reason. A declared validator (below) is the safety net when a name does collide,
since a value it rejects is skipped rather than pinning the site to it.

**Spelling is not meaning.** A validator decides whether a value is *usable*,
not whether it is written canonically, so `ALBERT_PRIVACY_MODE = 'Strict'` is
accepted and the site runs Strict. For a `select` or `radio-cards` field the
renderer runs the value back through the field's own `sanitize_callback` when it
is not one of the declared option keys, so the control still marks the right
choice. Declare a `sanitize_callback` on any closed-vocabulary field: without
one there is nothing to ask, and the control renders with nothing selected.

Read a setting with the helper rather than `get_option()` when you want the
value actually in force:

```php
$mode = albert_get_setting( 'albert_privacy_mode', 'balanced' );
```

**Bridging a filter you already publish.** A domain-specific filter name reads
better at a call site than a generic one, so Albert's own two are not
deprecated: `albert/privacy/mode` and `albert/media/upload_link_max_bytes` still
work, and `Settings\Overrides` feeds them into the chain so the screen sees
them. Answer `albert/settings/value_source/{option_name}` with your own hook
name when you do this — otherwise the screen reports the generic hook, which the
site owner will not find anywhere in their code.

```php
add_filter( 'albert/settings/value/my_option', fn ( $v ) => $v ?? apply_filters( 'my/own/filter', null ) );
add_filter( 'albert/settings/value_source/my_option', fn ( $n ) => $n ?? 'my/own/filter' );
```

**An override is not sanitised.** It never passes through `update_option()`, so
nothing runs the field's `sanitize_callback` over it. Pass a validator when the
setting has a closed vocabulary, and a layer it rejects is skipped rather than
accepted — a typo in a constant then falls through to the filter or the stored
value instead of resolving to nonsense:

```php
Value::get( 'albert_privacy_mode', '', fn ( $v ) => PrivacyMode::normalize( (string) $v ) !== null );
```

`disabled`, `hint` and `display_value` remain as manual escape hatches for a
condition the resolver cannot see — a licence state, a network policy — and a
field's own values win over the automatic ones.

### Option name resolution

By default the option name is `{section_id_with_slashes_replaced}_{field_id}`:

| Section id | Field id | Option name |
|------------|----------|-------------|
| `premium/activity-log` | `retention_days` | `premium_activity_log_retention_days` |

Pass an explicit `option_name` to override the auto-generated name.

### Radio cards

`radio-cards` is for a choice important enough to explain rather than list
bare in a `<select>` — Free's own privacy mode is one. `options` takes the
same `value => …` shape `select` does, but each value is an array carrying a
`label`, an optional `description`, and an optional `recommended` flag that
renders a "Recommended" badge:

```php
'type'    => 'radio-cards',
'options' => [
    'strict' => [
        'label'       => __( 'Strict', 'my-addon' ),
        'description' => __( 'Personal data is always anonymised.', 'my-addon' ),
        'recommended' => true,
    ],
    'off'    => [
        'label'       => __( 'Off', 'my-addon' ),
        'description' => __( 'Personal data is passed through as-is.', 'my-addon' ),
    ],
],
```

A bare string value works too (`'off' => __( 'Off', 'my-addon' )`), matching
`select`'s shape when there's nothing to add beyond the label.

### Custom fields

Custom fields are the escape hatch for inputs the built-ins can't model
(license tables, OAuth wizards, copy-to-clipboard widgets). Two callbacks
are required:

```php
'render_callback' => static function ( array $field, mixed $current_value ): void {
    // Echo the input HTML only. No <form>, no submit button, no <label>
    // wrapper (the renderer already added one if `label` is non-empty).
},

'sanitize_callback' => static fn ( $raw ) => is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '',
```

For a read-only custom field (display only, never saved), pass
`'__return_null'` as the sanitize callback. The save loop skips persistence
for that field.

If a render callback throws, the renderer catches the error, writes a line
to `error_log`, and prints an inline admin notice in place of the field.

## Hooks reference

| Hook | Type | Fires |
|------|------|-------|
| `albert/settings/register` | action | Before sections are collected. Add-ons hook here. |
| `albert/settings/sections` | filter | Final pass after the registry returns its list. |
| `albert/settings/saved` | action | After a successful save. Receives `array<option_name, sanitized_value>`. |
| `albert/mcp/external_url` | filter | Return a fully-qualified URL to override the MCP endpoint host. Empty string disables the override. Invalid URLs are ignored. |

## Versioning / feature detection

The functions live in Free. Add-ons that may load against an older Free
version should guard their calls:

```php
if ( ! function_exists( 'albert_register_setting' ) ) {
    return;
}
```
