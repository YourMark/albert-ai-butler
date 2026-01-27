# Developer Guide - Albert for WordPress

## Adding Custom Abilities

### Registering Your Ability

Add your custom ability directly to the abilities manager:

```php
add_action( 'plugins_loaded', function() {
    $manager = \Albert\Core\Plugin::get_instance()->get_abilities_manager();

    if ( $manager ) {
        $manager->add_ability( new MyPlugin\Abilities\CustomAbility() );
    }
}, 20 ); // Priority 20 to run after Albert initializes
```

### Creating a Custom Ability

Extend the `BaseAbility` class and implement the required methods:

```php
<?php
namespace MyPlugin\Abilities;

use Albert\Abstracts\BaseAbility;
use WP_Error;

class CustomAbility extends BaseAbility {
    public function __construct() {
        $this->id          = 'myplugin/custom-action';
        $this->label       = __( 'Custom Action', 'myplugin' );
        $this->description = __( 'Performs a custom action', 'myplugin' );
        $this->category    = 'core'; // or your custom category
        $this->group       = 'custom'; // Optional: for UI grouping

        $this->input_schema  = $this->get_input_schema();
        $this->output_schema = $this->get_output_schema();

        parent::__construct();
    }

    protected function get_input_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'param' => [
                    'type'        => 'string',
                    'description' => 'A parameter',
                ],
            ],
            'required'   => [ 'param' ],
        ];
    }

    protected function get_output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'result' => [ 'type' => 'string' ],
            ],
        ];
    }

    public function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public function execute( array $args ): array|WP_Error {
        // Your implementation here
        return [
            'result' => 'Success',
        ];
    }
}
```

## Built-in Ability Organization

Built-in abilities are organized by entity type:

```
src/Abilities/WordPress/
├── Posts/
│   ├── ListPosts.php
│   ├── Create.php
│   ├── Update.php
│   └── Delete.php
├── Pages/
│   └── ...
└── Users/
    └── ...
```

## How It Works

1. **Plugin Init**: Plugin initializes and creates `AbilitiesManager` instance
2. **Ability Registration**: All built-in abilities are instantiated and added to the manager
3. **Hook Registration**: Manager registers WordPress hooks
4. **Settings Page**: Abilities are available for display in settings (enabled or disabled)
5. **API Registration**: On `wp_abilities_api_init`, enabled abilities are registered with WordPress
6. **Translation Ready**: Abilities are instantiated after translations load

## Benefits

- ✅ **Simple**: Straightforward architecture, easy to understand
- ✅ **Scalable**: Easy to add new abilities
- ✅ **Extensible**: Developers can add custom abilities directly
- ✅ **Translation Ready**: Translations loaded before ability instantiation
- ✅ **Clean Code**: Well-organized ability structure
