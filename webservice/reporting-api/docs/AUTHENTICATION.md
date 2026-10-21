# Authentication and credential rotation

## Scheme
Every request (except `/api/v1/health`) must carry:

```
Authorization: Bearer yapr_<12-hex client id>_<43-char secret>
```

* HTTPS only. Plain HTTP receives `403 https_required`.
* Tokens in the URL are refused (`400 credentials_in_query`) so they never reach access logs.
* The service stores `HMAC-SHA256(secret, server key)` only. The server key is `data/secret.key`.
* Failed attempts are rate-limited per source IP (default 20 per 15 minutes → `429`).
* Optional per-client IP allowlist (`--ip=203.0.113.10,2001:db8::/32`).

## Provisioning (YAP administrator)
```sh
php bin/yapr init                         # once
php bin/yapr client:create --name="Example Area website" --areas=123 --scopes=summary
```
Copy the printed token into the WordPress plugin. It cannot be displayed again.

## Rotation (no downtime)
```sh
php bin/yapr client:rotate <client-id> --grace-hours=24
```
1. Paste the new token into WordPress and click *Test Connection*.
2. The old token keeps working until the grace period ends (max 168 hours), then fails.
Use `--grace-hours=0` if the old token may be compromised.

## Revocation
```sh
php bin/yapr client:revoke <client-id>
```
All tokens for the client stop working immediately. Revoked clients remain listed for audit.

## Changing what a client may see
```sh
php bin/yapr client:update <client-id> --areas=123,456
php bin/yapr client:update <client-id> --scopes=summary,call_details
php bin/yapr client:update <client-id> --ip=none
```
The report cache is cleared automatically.

## Rotating the server key
Deleting `data/secret.key` and running `init` invalidates **every** token and changes every pseudonymous
call and volunteer id. Re-issue tokens to every client afterwards.
