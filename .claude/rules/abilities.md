---
paths:
  - "src/Abilities/**"
  - "src/Abstracts/**"
  - "src/Core/AbilitiesManager.php"
  - "src/Core/Plugin.php"
  - "tests/**/Abilities/**"
---

# Abilities

Every ability extends `Albert\Abstracts\BaseAbility` and is added in `Plugin::build_abilities()` (add-ons: the `albert/abilities/register` action). Copy a sibling, such as `Posts/ViewPost.php`, rather than starting blank.

- Always set `meta.annotations` with `Core\Annotations::read|create|update|delete|action()`. Safe mode always holds an ability without `destructive => false`.
- `check_permission(): bool|WP_Error`, usually `$this->require_capability( '...' )`.
- `execute( array $args ): array|WP_Error`. Never call your ability's `execute()` directly; WordPress registers `guarded_execute()` as the callback, which fires the before and after hooks.
- List any top-level result key holding a credential in `$sensitive_output_keys`, so observers and logs see `[redacted]`.
- Abilities are built on `wp_abilities_api_init`, never on `init`, so a frontend request loads none of their files. Read them through `Plugin::get_abilities_manager()`, which initialises the Abilities API if it has not run.
- IDs are `albert/{verb}-{noun}`. Text domain `albert-ai-butler`.
