---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Albert-Specific Patterns

## Bounded Contexts

| Context | Location | Abilities |
|---------|----------|-----------|
| Core | `src/Core/`, `src/Abstracts/`, `src/Contracts/` | Plugin bootstrap, ability registration, shared infrastructure |
| WordPress Content | `src/Abilities/WordPress/` | Posts, pages, media, users, taxonomies |
| WooCommerce | `src/Abilities/WooCommerce/` | Products, orders, customers |
| OAuth | `src/OAuth/` | Authorization server, token management, client registration |
| MCP | `src/MCP/` | Protocol handler, transport |
| Admin | `src/Admin/` | Settings UI, ability toggles, connections |

WooCommerce abilities only load when WooCommerce is active.

## Class Patterns

**Hookable interface** — Components with WordPress hooks implement `Hookable`:
```php
class MyComponent implements Hookable {
    public function register_hooks(): void {
        add_action( 'init', [ $this, 'do_something' ] );
    }
}
```

**BaseAbility** — All abilities extend `Albert\Abstracts\BaseAbility`:
- Set `$id`, `$label`, `$description`, `$category`, `$input_schema`, `$meta` in constructor
- Implement `execute(array $args): array|WP_Error`
- Implement `check_permission(): true|WP_Error`
- Call `parent::__construct()` last

## Namespace Examples

- `Albert\Core\Plugin`
- `Albert\Abilities\WordPress\Posts\Create`
- `Albert\OAuth\Endpoints\OAuthController`

Ability IDs: `core/posts/create`, `core/media/upload`, `albert/woo-find-products`

## Testing Patterns

### WordPress Stubs

`tests/Unit/stubs/wordpress.php` provides minimal stubs for WordPress functions
(`do_action`, `apply_filters`, `get_option`, `get_current_user_id`, etc.) that record
calls to `$GLOBALS['albert_test_hooks']` for assertions.

Reset globals in `setUp()`:
```php
protected function setUp(): void {
    parent::setUp();
    $GLOBALS['albert_test_hooks']   = [];
    $GLOBALS['albert_test_user_id'] = 42;
    $GLOBALS['albert_test_options'] = [
        'albert_abilities_saved'    => true,
        'albert_disabled_abilities' => [],
    ];
}
```

### StubAbility Pattern

Use a concrete `BaseAbility` subclass with configurable return values:
```php
$ability = new StubAbility( 'core/posts/create', [ 'id' => 1 ] );
$ability->guarded_execute( [ 'title' => 'Test' ] );

$hooks = $this->get_hooks( 'albert/abilities/after_execute' );
$this->assertSame( 'core/posts/create', $hooks[0]['args'][0] );
```

### Testing Disabled Abilities

Set the disabled list via `$GLOBALS['albert_test_options']`:
```php
$GLOBALS['albert_test_options']['albert_disabled_abilities'] = [ 'test/disabled' ];
$result = $ability->guarded_execute( [] );
$this->assertInstanceOf( WP_Error::class, $result );
```

## CSS / JS Conventions

- CSS classes scoped under `.albert-*` prefix
- CSS custom properties: `--albert-*`
- JS files in `assets/js/`, CSS in `assets/css/`
- OAuth Bearer tokens for API authentication
