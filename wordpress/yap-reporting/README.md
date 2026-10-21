# YAP Reporting for WordPress

Private phoneline reporting dashboards for Narcotics Anonymous service bodies that use
[YAP](https://github.com/bmlt-enabled/yap). Data comes **only** from the YAP Reporting Webservice
(package A); WordPress never touches the YAP database and never stores individual calls.

Requires WordPress 6.2+, PHP 8.1+ with the `sodium` extension (bundled with PHP since 7.2).

## Install

1. YAP administrator: install the webservice and run
   `php bin/yapr client:create --name="Example Area website" --areas=<ids> --scopes=summary,call_details,volunteer_stats,volunteer_names`.
   A public-only site needs `--scopes=summary`.
2. WordPress: *Plugins → Add New → Upload* `yap-reporting-wordpress-<version>.zip`, then activate.
3. *YAP Reporting → Setup* walks through the rest:

| Step | Where |
|---|---|
| Enter the Reporting API URL (`https://…/reporting-api`) and paste the credential | Settings |
| Test connection | Diagnostics |
| Enter the BMLT root server URL, refresh service bodies | BMLT Configuration |
| Tick the Regions/Areas this site reports on | BMLT Configuration |
| Check the YAP-to-BMLT mapping table (name any ids not in BMLT) | BMLT Configuration |
| Pick defaults, timezone and caching intervals | Settings |
| Grant Areas to roles or users | Access Control |
| Run the first sync | Cache & Synchronization |
| Paste shortcodes into pages | Area Dashboards |

### Keeping secrets out of the database (optional)

```php
// wp-config.php
define( 'YAPR_API_URL',   'https://example.org/yap/reporting-api' );
define( 'YAPR_API_TOKEN', 'yapr_…' );            // or the YAPR_API_TOKEN environment variable
define( 'YAPR_ENCRYPTION_KEY', '64 random chars' ); // key for the encrypted database copy, if used
// define( 'YAPR_ALLOW_PRIVATE_HOSTS', true );    // only if YAP is on a private network you trust
```

## Shortcodes

| Shortcode | Purpose | Who sees it |
|---|---|---|
| `[yap_reporting]` | Full statistics and charts | Logged-in users with *view reports*, for their Areas |
| `[yap_phoneline_admin]` | Individual calls, volunteer activity | Users with *view call details* |
| `[yap_area_dashboard area="123" period="30d"]` | Compact Area Service figures | Private by default; public only if published |

Full reference: [`docs/SHORTCODES.md`](docs/SHORTCODES.md). Permissions: [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md).
Caching and cron: [`docs/CACHING.md`](docs/CACHING.md). Security model: [`docs/SECURITY.md`](docs/SECURITY.md).

## How figures are defined

Definitions come from the webservice (see its `docs/DATA-MAPPING.md`). The essentials:

* One incoming call counts once, however many volunteers YAP dialed.
* **Answered** = as in YAP: a volunteer answered and accepted the call.
* Figures YAP cannot provide show **Not available**, never zero.
* JFT/SPAD: Area figures count only requests YAP tied to that Area; server-wide totals are labelled.

## Testing

The tests live in the source repository (https://github.com/gutterwall/yap-reporting), not in the plugin zip:

```sh
php wordpress/yap-reporting/tests/run.php                  # pure-PHP unit tests with WordPress stand-ins
vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist     # on WordPress, through the real webservice and a fake YAP
bin/e2e.sh /tmp/e2e                                        # release zips on Apache/nginx, WordPress, Chrome
```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| "The URL points to a private or internal network address" | The API host resolves to a private IP. Use the public hostname, or define `YAPR_ALLOW_PRIVATE_HOSTS`. |
| "answered with a redirect" | The API URL is wrong (e.g. http→https or a trailing path). Redirects are never followed. |
| Test connection: credential not accepted | Token mistyped, revoked, or the YAP admin restricted it by IP. |
| Dashboard shows "older than expected" | Sync failing; see *Cache & Synchronization → Problems*. WP-Cron needs site traffic or a real cron job. |
| A user sees "not been given access to any Area" | Grant Areas to their role or to them on *Access Control*. |
| Area missing from lists | Tick it on *BMLT Configuration*; only selected Areas are reportable. |

## Upgrade

Upload the new zip over the old one (*Plugins → Add New → Upload → Replace current*). Settings, grants and
the credential are kept; database tables upgrade automatically.

## Uninstall

Deleting the plugin removes the credential, caches and scheduled jobs. Settings, Area selections, roles and
grants are removed too unless *Settings → Data → Keep settings on uninstall* is ticked.
