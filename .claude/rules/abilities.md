---
paths:
  - "src/Abilities/**"
  - "src/Abstracts/**"
  - "src/Core/AbilitiesManager.php"
  - "src/Core/Plugin.php"
  - "tests/**/Abilities/**"
---

# Abilities

Every ability extends `Albert\Abstracts\BaseAbility` and is added in `Plugin::register_abilities()` (add-ons: the `albert/abilities/register` action). Copy a sibling, such as `Posts/ViewPost.php`, rather than starting blank.

- Always set `meta.annotations` with `Core\Annotations::read|create|update|delete|action()`. Safe mode always holds an ability without `destructive => false`.
- `check_permission(): bool|WP_Error`, usually `$this->require_capability( '...' )`.
- `execute( array $args ): array|WP_Error`. Never call your ability's `execute()` directly; WordPress registers `guarded_execute()` as the callback, which fires the before and after hooks.
- List any top-level result key holding a credential in `$sensitive_output_keys`, so observers and logs see `[redacted]`.
- IDs are `albert/{verb}-{noun}`. Text domain `albert-ai-butler`.
