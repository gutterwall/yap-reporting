# Security model and threat analysis

## Assets
| Asset | Classification | Where it lives |
|---|---|---|
| Caller phone numbers, SMS bodies, voicemail recordings/transcripts | **Restricted PII** | YAP database / Twilio. Never selected, cached, logged or returned by this service. |
| Volunteer phone numbers | Restricted PII | Read in memory only as an attribution key; output only as HMAC pseudonyms. |
| Volunteer names | Personal | Returned only to clients holding `volunteer_names`. |
| Call timing and outcomes per call | Sensitive | Returned only to `call_details` clients; never written to the disk cache. |
| Aggregate counts | Internal / publishable by choice | Cached up to `cache_ttl_seconds`. |
| API tokens | Secret | Shown once at creation. Stored as HMAC-SHA256 keyed by `data/secret.key`. |
| YAP DB credentials | Secret | Read from YAP's `config.php` at request time; never logged, cached or returned. |

## Controls by threat
| Threat | Control |
|---|---|
| Credential theft from disk | Only HMACs of tokens are stored; verifying requires both `clients.json` and `secret.key` (both 0600, outside or denied from the web root). |
| Credential in transit / logs | HTTPS enforced (`403` on HTTP). Tokens rejected in query strings. Logs scrub tokens, SIDs and numbers. |
| Brute force / enumeration | 256-bit secrets; constant-time comparison; same work for unknown ids; per-IP auth-failure lockout (default 20 / 15 min). |
| Replay | Bearer tokens over TLS only; short-lived rotation grace; revocation is immediate. (HMAC request signing was deliberately not combined with bearer tokens — one well-tested scheme.) |
| Broken object-level authorization | Area filter is derived from the client's stored scope; any requested id outside it returns `403`. Call detail lookups return identical `404` for "not found" and "other Area". |
| Broken function-level authorization | Each endpoint checks a named scope. |
| SQL injection | All SQL is fixed text with bound parameters; request values only ever bind as parameters. Only `SELECT/SHOW/PRAGMA` statements are accepted, and the MySQL session is `READ ONLY`. |
| Writes to YAP | Session `SET SESSION TRANSACTION READ ONLY`; no write statements exist in the code base. |
| Reading unrelated YAP data | Only the five reporting tables are queried. `users` (password hashes), `sessions` (PINs), `alerts`, `cache`, `metrics`, `event_status` are never read; a test seeds `users` and checks nothing leaks. |
| PII leakage | Column allowlists; `from_number` never selected; `meta` selected only for volunteer-dial events and reduced to one normalised number; `payload` reduced to the Twilio `Direction` value. Output built from explicit field lists. Automated tests scan every endpoint for fixture phone numbers, SIDs and recording URLs. |
| Re-identification via ids | Call and volunteer ids are truncated HMACs with a server-only key. |
| Cache poisoning / cross-client cache | Cache keys include the client's scope fingerprint and resolved Area filter; person-level endpoints are never cached. |
| Resource exhaustion | Max range (400 days), max page size, max Areas per request, max event rows (`413`), DB connect and statement timeouts, per-client rate limit. |
| Error disclosure | Generic error bodies with a request id; details go only to the scrubbed log. `display_errors` off. |
| Direct file access | Root `.htaccess` routes everything to `public/index.php`; every internal directory has a deny-all `.htaccess`; nginx example denies them explicitly. |
| Clickjacking / sniffing / caching | `X-Frame-Options: DENY`, CSP `default-src 'none'`, `nosniff`, `Cache-Control: private, no-store`, HSTS on HTTPS. |
| Browser setup console | Exists only while `config/ENABLE_SETUP` is present (otherwise 404). Unlocking requires a random 24-character code that the console writes to `config/setup-code.txt` (web-denied), so only someone with SFTP access can sign in. Code expires after 1 h and is replaced after 20 failed attempts; 5 attempts per IP per 15 min; HTTPS only; signed, HttpOnly, SameSite=Strict session cookie (30 min) bound to the code; CSRF token on every action; `noindex`, `no-store`, strict CSP. "Finish and lock" deletes the flag and the code. Never displays database passwords or stored credentials. |
| Running inside the YAP web root | `bin/yapr` refuses to run outside the CLI. `config.php` is tokenised, not included, so nothing in YAP's config executes in this service. |

## Residual risks and operator responsibilities
* The service uses YAP's database account, which can write. Read-only enforcement is at the session level.
  For defence in depth you may create a dedicated `SELECT`-only MySQL user and set it in `database`.
* `data/` inside the web root relies on `.htaccess`. Prefer `data_dir` outside the web root.
* Small counts are not suppressed (by your choice). If the WordPress plugin publishes figures publicly,
  publish only aggregate metrics.
* Volunteer attribution is inferred (and reported as such) when YAP did not record which volunteer joined.

## Reporting a vulnerability
Contact the maintainer of your deployment privately; do not open public issues containing tokens or data.
