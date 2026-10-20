---
paths:
  - "src/OAuth/**"
  - "tests/**/OAuth/**"
---

# OAuth

The authorize page is not a REST route. `/oauth/authorize` is a rewrite rule
that `AuthorizationPage` handles on `template_redirect`, so REST behaviour
(`rest_send_cors_headers`, `WP_REST_Response`, the REST permission callbacks)
never applies to it. Token, register, metadata and jwks are REST routes under
`Plugin::rest_namespace()` (`albert/v1` unless filtered). `ServerMetadata` builds the URLs clients are told about.
