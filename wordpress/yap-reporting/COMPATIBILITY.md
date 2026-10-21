# Compatibility

| WordPress plugin | Needs webservice API | Recommended webservice | Notes |
|---|---|---|---|
| 1.7.0 | v1 | 1.7.0 or newer | Works with 1.6.0, but region-wide totals then count text messages as calls. |
| 1.6.0 | v1 | 1.6.0 or newer | Region-wide meeting / JFT / SPAD / text figures. |
| 1.5.1 | v1 | 1.5.0 or newer (1.5.1 for the warning) | Warning for callers never connected. |
| 1.5.0 | v1 | 1.5.0 or newer | Shows calls whose result YAP did not log. |
| 1.4.1 | v1 | 1.4.0 or newer | Explains hidden volunteer names. |
| 1.4.0 | v1 | 1.4.0 or newer | Pick-up columns for call blasts. |
| 1.3.0 | v1 | 1.3.0 or newer | Colour scheme only. |
| 1.2.0 | v1 | 1.3.0 or newer | Pick-up diagnostics and number hints need webservice 1.3.0. |
| 1.1.0 | v1 | 1.2.0 or newer | Text-message figures and YAP answered rule. With an older webservice, those Area dashboard rows show "Not available". |
| 1.0.3 | v1 | 1.0.3 or newer | Adds the compatibility check. |
| 1.0.2 | v1 | 1.0.3 or newer | Forwarded-call figures need webservice 1.0.3. |
| 1.0.1 | v1 | 1.0.2 or newer | Busy / failed-number columns need webservice 1.0.2. |
| 1.0.0 | v1 | 1.0.0 or newer | |

Older webservice versions still work with newer plugins: figures they cannot provide show as "Not available".
The plugin refuses only a webservice that reports a different API version (for example a future v2).

Release files are named `yap-reporting-wordpress-<version>.zip`. The folder inside is always `yap-reporting`,
so WordPress treats a new zip as an upgrade of the same plugin (Plugins → Add New → Upload → Replace current).

Requirements: WordPress 6.2+ (tested with 6.2 and 7.1), PHP 8.1+ with the sodium extension.
