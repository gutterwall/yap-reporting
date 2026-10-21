# YAP Reporting Webservice

A secure, **read-only** JSON reporting API that installs inside an existing
[YAP](https://github.com/bmlt-enabled/yap) installation. Its job is to give the *YAP Reporting for WordPress*
plugin aggregate phoneline statistics without exposing caller identities, without duplicating
database credentials, and without touching YAP itself.

* Targets YAP **4.5.x** (latest release 4.5.2) and the **5.0** line on `main`. Requires PHP 8.1+ and MySQL 8.0+ / MariaDB 10.3+ (verified schema: YAP 4.x on MariaDB 11.8).
* Reads YAP's database settings from YAP's own `config.php` (parsed, never executed).
* Reads event definitions from the installed YAP's `app/Constants/EventId.php`, so numbering always matches your build.
* Never modifies YAP files or tables. The database session is forced `READ ONLY` and pinned to UTC.
* Never reads YAP's `users`, `sessions` or other non-reporting tables, and never selects caller numbers.
* No dependencies beyond PHP extensions (`pdo_mysql`, `json`, `tokenizer`). Composer is optional.

## Contents

| Path | Purpose |
|---|---|
| `public/index.php` | The only web-reachable file (front controller) |
| `bin/yapr` | Command-line administration: setup, diagnostics, credentials |
| `config/reporting-config.example.php` | All settings, with secure defaults |
| `src/` | Application code |
| `data/` | Created by `init`: secret key, hashed client registry, logs, rate-limit state |
| `docs/` | API reference, OpenAPI spec, data mapping, authentication guide |
| `tests/` | Automated tests (`php tests/run.php`) with fictional fixtures |
| `deploy/` | Apache and nginx configuration examples |

## Installation without a command line (SFTP + browser only)

Most shared hosts (IONOS, GoDaddy, etc.) give you SFTP and a website but no shell. Everything `bin/yapr`
does is also available from a setup page that is **switched on and off over SFTP**:

1. **Upload.** In your SFTP program, upload the `reporting-api` folder into your YAP folder (the one that
   contains YAP's `config.php`), so you have `…/yap/reporting-api/`.
2. **Permissions.** Right-click the `reporting-api/data` folder → *Permissions* → `755`. Do the same for
   `reporting-api/config`. (Most hosts run PHP as your SFTP user, so this is all that is needed.)
3. **Unlock setup.** In `reporting-api/config`, rename `ENABLE_SETUP.off` to `ENABLE_SETUP`.
4. **Open** `https://your-site/yap/reporting-api/setup` in your browser. The page writes a one-time code to
   `reporting-api/config/setup-code.txt`.
5. **Get the code.** Download `setup-code.txt` with SFTP, open it, and type the code into the page.
6. On the setup page, work down the sections:
   * **Check the installation.** Every row should say OK. It also lists the service body ids that appear in
     YAP's call records; you need these for the next step.
   * **Initialise.**
   * **Create a credential** for your WordPress site. Copy it immediately; it is shown once.
   * **Self-test** (optional; needs the `pdo_sqlite` PHP extension, which most hosts have).
7. **Finish and lock setup.** This deletes `ENABLE_SETUP` and the code. If the page says it could not, delete
   `config/ENABLE_SETUP` yourself with SFTP.
8. **Test** in a browser: `https://your-site/yap/reporting-api/api/v1/health` should show `{"status":"ok",…}`,
   and `https://your-site/yap/reporting-api/data/clients.json` must **not** download (403 or 404).

To rotate or revoke a credential later, rename `ENABLE_SETUP.off` → `ENABLE_SETUP` again (or create an empty
file with that name) and repeat steps 4–7. Optional settings: download `config/reporting-config.example.php`,
save it as `reporting-config.php`, edit it in a text editor, and upload it back to `config/`.

## Installation with SSH

1. **Upload.** Unzip so the folder sits directly inside your YAP directory:
   ```
   /var/www/html/yap/            ← YAP (config.php lives here)
   /var/www/html/yap/reporting-api/
   ```
2. **Permissions.** Files readable by the web-server user; only `data/` writable.
   ```sh
   cd /var/www/html/yap/reporting-api
   find . -type d -exec chmod 755 {} \; && find . -type f -exec chmod 644 {} \;
   chmod 755 bin/yapr
   chown -R www-data:www-data data && chmod 700 data
   ```
3. **Web server.** On Apache (YAP's supported server) the shipped `.htaccess` files route requests to
   `public/index.php` and deny everything else. `mod_rewrite` and `AllowOverride All` are required, which
   YAP already needs. For nginx, or to keep `data/` outside the web root, see `deploy/`.
4. **Configure (optional).** `cp config/reporting-config.example.php config/reporting-config.php` and set
   `default_timezone`, and `trusted_proxies` if TLS terminates at a proxy. Database settings are read from YAP automatically.
5. **Initialise** (run as the web-server user so it owns the files):
   ```sh
   sudo -u www-data php bin/yapr init
   sudo -u www-data php bin/yapr check
   ```
   `check` confirms the YAP database settings were found, connects read-only, lists the YAP tables and
   columns it found, and prints which metrics this YAP build supports and why any are unavailable.
6. **Create a credential for the WordPress site.** Use the BMLT service body ids the site may report on:
   ```sh
   sudo -u www-data php bin/yapr client:create --name="Example Area website" --areas=123 \
        --scopes=summary,call_details,volunteer_stats,volunteer_names
   ```
   The token is printed **once**. Paste it into *YAP Reporting → Settings → API credential* in WordPress.
   Use `--all` instead of `--areas` for a Region site that may see every service body.
7. **Verify HTTPS and test.**
   ```sh
   curl -s https://example.org/yap/reporting-api/api/v1/health
   curl -s -H "Authorization: Bearer yapr_…" "https://example.org/yap/reporting-api/api/v1/summary?period=30d"
   curl -si http://example.org/yap/reporting-api/api/v1/health   # must NOT return data over plain HTTP
   curl -si https://example.org/yap/reporting-api/data/clients.json   # must be 403/404
   ```

## Scopes

| Scope | Grants |
|---|---|
| `summary` | Aggregates: summary, trends, distributions, area dashboard, meeting lookups, meditations, SMS, areas, capabilities |
| `call_details` | Individual call list and call timelines (no caller numbers, ever) |
| `volunteer_stats` | Per-volunteer statistics under pseudonymous ids (`v_…`) |
| `volunteer_names` | Adds volunteer display names from YAP's volunteer lists |

A public-facing site should hold `summary` only. Give each WordPress site its own client.

## Credential lifecycle

```sh
php bin/yapr client:list
php bin/yapr client:rotate <id> --grace-hours=24   # new token; old one works for 24 h
php bin/yapr client:revoke <id>                    # immediate
php bin/yapr client:update <id> --areas=123,456 --scopes=summary
```
See `docs/AUTHENTICATION.md`.

## How calls are counted

* **One incoming call = one call**, however many volunteers were dialed. Volunteer legs that joined the
  caller's conference are folded into the caller's call via `conference_participants`.
* **Answered** = as in YAP: a volunteer answered, **accepted** the call and was connected to the caller.
  (A minimum length can be required with `answered_rule => 'min_seconds'`.) Ringing, declining, voicemail, menus, or a volunteer picking up after the caller
  left never count. A connection with no recorded hang-up is reported separately as
  `connected_unknown_duration`, not as answered.
* Every metric states its **level** (`call`, `attempt`, `interaction`, `message`). Metrics this YAP build
  cannot support are returned with `available: false` and a reason — never as zero.

Full definitions and the YAP data mapping are in `docs/DATA-MAPPING.md`.

## Upgrading

See `COMPATIBILITY.md` for which webservice and plugin versions work together.

1. `cp -a reporting-api reporting-api.bak`
2. Unzip the new release over the folder **keeping** `config/reporting-config.php` and `data/`.
3. `php bin/yapr check` and `php bin/yapr cache:clear`.

Upgrading YAP itself needs no action; after a YAP upgrade run `php bin/yapr check` to confirm the event
catalog and schema are still detected.

## Testing

```sh
php tests/run.php        # requires pdo_sqlite; uses only fictional data
```

## Troubleshooting

### "404 Not Found"
Open `https://your-site/reporting-api/api/v1/health` (add `/yap` or your folder if YAP is not at the site root).

| What you see | Meaning | Fix |
|---|---|---|
| `{"status":"ok",...}` | Rewriting works. | If `/setup` still says not found, `config/ENABLE_SETUP` is missing or misnamed. |
| `{"error":{"code":"not_found"...}}` | Rewriting works; the address is wrong. | Check the spelling of the path. |
| YAP's own 404 page, or the web host's 404 page | Apache is not running `reporting-api/.htaccess`. | See below. |

If rewriting is not working:
1. Try `https://your-site/reporting-api/public/index.php/api/v1/health`. If that shows `"status":"ok"`, PHP is fine
   and only rewriting needs fixing. (You can use `…/public/index.php/setup` for setup meanwhile, and even put
   `…/reporting-api/public/index.php` as the API URL in WordPress.)
2. Open `reporting-api/.htaccess` and uncomment the `RewriteBase` line that matches your address:
   `RewriteBase /reporting-api/` when YAP is at the root of a subdomain such as `https://yap.example.org/`.
3. Make sure `reporting-api` is inside the folder your (sub)domain points to. On IONOS this is set under
   *Domains & SSL → (your subdomain) → Change destination*. If the subdomain points at YAP's `public` folder,
   put `reporting-api` inside `public`.
4. Make sure the `.htaccess` files were uploaded: many SFTP programs hide files whose names start with a dot.
   In FileZilla: *Server → Force showing hidden files*.


| Symptom | Fix |
|---|---|
| `401` with a correct token | The `Authorization` header is being stripped. Confirm `CGIPassAuth On` / the rewrite rule in `.htaccess`, or `fastcgi_param HTTP_AUTHORIZATION` on nginx. |
| `403 https_required` behind a proxy | Add the proxy to `trusted_proxies` so `X-Forwarded-Proto` is honoured. |
| `503 not_initialised` | Run `php bin/yapr init` as the web-server user. |
| `503 service_unavailable` | Run `php bin/yapr check`; usually YAP's `config.php` could not be found or read. Set `yap_root`. |
| A metric shows "unavailable" | `php bin/yapr check` lists the missing event or column. Answered calls need the Twilio status callback (`status.php`) configured on every number. |
| Area figures look low, Unassigned high | YAP records meeting searches and menu-only calls before any service body is known. Map your helpline numbers in `attribution.line_mapping`. |
| Logs | `data/logs/yapr-YYYY-MM-DD.log` (JSON lines, numbers and tokens scrubbed). |
