# Compatibility

## With YAP
| YAP | Webservice |
|---|---|
| 4.5.x (verified against 4.5 constants and a production schema) | 1.0.3+ |
| 5.0 (unreleased `main`) | expected to work; run the setup page's checks |
| 4.0–4.4 | expected to work; SPAD needs YAP 4.3+ |
| < 3.5 | not supported |

## With the WordPress plugin
| Webservice | API | Works with plugin | Notes |
|---|---|---|---|
| 1.6.0 | v1 | 1.0.0 and newer (1.6.0 for the meeting-lookup fallback) | Server-wide meeting / text totals on Area dashboards. |
| 1.5.1 | v1 | 1.0.0 and newer (plugin 1.5.1 for the warning) | Callers never connected are no longer "unknown". |
| 1.5.0 | v1 | 1.0.0 and newer (1.5.0 to show unrecorded outcomes) | Correct handling of calls YAP did not fully log; SMS fix. |
| 1.4.1 | v1 | 1.0.0 and newer | Setup page can edit credential permissions. |
| 1.4.0 | v1 | 1.0.0 and newer (1.4.0 for the new pick-up columns) | Correct answering volunteer and time zones. Upgrade recommended for everyone. |
| 1.3.0 | v1 | 1.0.0 and newer (1.2.0 to show pick-up diagnostics) | Volunteer pick-up diagnostics; names from group lists. |
| 1.2.0 | v1 | 1.0.0 and newer (1.1.0 to show text figures) | YAP answered rule; separate phone / text figures on the Area dashboard. |
| 1.1.1 | v1 | 1.0.0 and newer | Rewrite fixes for subdomain / IONOS hosting; no API changes. |
| 1.1.0 | v1 | 1.0.0 and newer | Adds the browser setup page; no API changes. |
| 1.0.3 | v1 | 1.0.0 and newer | Adds forwarded-call figures (used by plugin 1.0.2+). |
| 1.0.2 | v1 | 1.0.0 and newer | Adds busy / failed-number figures (used by plugin 1.0.1+). |
| 1.0.0–1.0.1 | v1 | 1.0.0 and newer | |

Within API v1, changes are additive: new figures may appear, existing ones keep their names and meaning.
A breaking change would become API v2 at a new URL prefix (`/api/v2/`), and v1 would be kept alongside it.

## Release files
Release files are named `yap-reporting-webservice-<version>.zip`. The folder inside is always `reporting-api`,
so URLs and the WordPress plugin's settings do not change between versions.

Upgrading over SFTP: upload the new `reporting-api` folder over the old one, but **keep** your
`config/reporting-config.php` and the whole `data/` folder (they hold your settings, secret key and credentials).
The installed version is shown at the bottom of the setup page, in `VERSION`, and in `GET /api/v1/capabilities`.
