---
paths:
  - "src/Abilities/**"
  - "src/Abstracts/**"
  - "src/Core/AbilitiesManager.php"
  - "src/Core/Plugin.php"
  - "tests/**/Abilities/**"
---

# Abilities

## 2. Abilities System
Abilities are WordPress operations exposed to AI assistants.

**BaseAbility** (`src/Abstracts/BaseAbility.php`):
- Abstract class all abilities extend
- Defines: `$id`, `$label`, `$description`, `$input_schema`, `$output_schema`
- Implements `register_ability()` to register with WordPress
- Abstract `execute(array $args)` method for implementation

**AbilitiesManager** (`src/Core/AbilitiesManager.php`):
- Collects and registers all abilities
- Calls `wp_register_ability()` for each enabled ability

**Creating a New Ability:**
```php
namespace Albert\Abilities\WordPress\Example;

use Albert\Abstracts\BaseAbility;
use WP_Error;

class MyAbility extends BaseAbility {
    public function __construct() {
        $this->id          = 'albert/my-ability';
        $this->label       = __( 'My Ability', 'albert' );
        $this->description = __( 'Description of what it does.', 'albert' );
        $this->category    = 'core';
        $this->group       = 'example';

        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'param1' => [
                    'type'        => 'string',
                    'description' => 'Parameter description',
                ],
            ],
            'required'   => [ 'param1' ],
        ];

        $this->meta = [
            'mcp' => [ 'public' => true ],
        ];

        parent::__construct();
    }

    public function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public function execute( array $args ): array|WP_Error {
        // Implementation
        return [ 'result' => 'success' ];
    }
}
```

Then register in `Plugin::register_abilities()`:
```php
$this->abilities_manager->add_ability( new MyAbility() );
```
