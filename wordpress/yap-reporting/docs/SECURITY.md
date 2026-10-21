# Security and privacy notes (WordPress plugin)

| Threat | Control |
|---|---|
| Credential disclosure | Token encrypted with libsodium using a key from wp-config (`YAPR_ENCRYPTION_KEY`) or WP salts; write-only field; only the non-secret client id is ever displayed; never sent to browsers; constants/env vars supported. |
| SSRF | API and BMLT URLs must be https; resolved addresses must be public (loopback, RFC1918, link-local/metadata, CGNAT, ULA blocked); redirects disabled; `wp_safe_remote_get` re-checks at connect time. Private targets only via `YAPR_ALLOW_PRIVATE_HOSTS` in wp-config. Only admins can change URLs. |
| Open proxy | REST routes map to fixed webservice endpoints with parameter allowlists; the API client refuses any other endpoint. |
| Broken object/function level authorization | Capability check in every `permission_callback` and handler; Area grants resolved server-side; requested Areas can only narrow, never widen; call detail re-checked against the user's grant. |
| CSRF | REST uses the `wp_rest` nonce; exports and admin actions use per-action nonces; nonces are never treated as authentication. |
| XSS | PHP output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`); the JS app builds DOM with `textContent` only. |
| Formula injection | CSV cells starting with `= + - @` tab/CR are prefixed with `'`. |
| Cached data leaks | Only aggregates cached; keys include visibility and Areas; authorization runs before cache reads; REST and export responses send `Cache-Control: private, no-store`. |
| Public re-identification | Public mode is opt-in per Area and per figure; no names or call records; public periods restricted. Small counts are shown (your choice) — prefer monthly periods for low-volume Areas. |
| Resource abuse | Public pages hit cache; at most one API refresh per cache entry per TTL; webservice rate limits apply. |
| Uninstall | Credential, caches and jobs always removed; other data removed unless explicitly kept. |
