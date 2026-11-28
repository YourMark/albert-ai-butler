---
paths:
  - "src/OAuth/**"
  - "tests/**/OAuth/**"
---

# OAuth

Built on `league/oauth2-server`.

- `/oauth/authorize` is a rewrite rule handled by `AuthorizationPage` on `template_redirect`, not a REST route, so REST behaviour (CORS headers, `WP_REST_Response`, permission callbacks) never applies to it.
- Token, register, metadata and jwks are REST routes under `Plugin::rest_namespace()` (`albert/v1` unless filtered). `ServerMetadata` builds the URLs clients are told about.
- Bearer tokens are checked by `TokenValidator::validate_request()`, which also sets `ConnectionContext`. Only Albert's MCP route uses it; a token never authenticates core's REST API.
- The MCP Adapter plugin may ship its own OAuth server (WordPress/mcp-adapter#437). `AdapterOAuthGuard`, registered from the main plugin file so load order cannot matter, keeps it off and keeps Albert's routes out of it. Its discovery documents would otherwise answer for Albert's issuer. Migration plan: #174.
