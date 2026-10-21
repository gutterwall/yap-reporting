# Changelog

## 1.7.0 (recommends webservice 1.7.0)
* Block themes (Twenty Twenty-Four, Twenty Twenty-Five and other full-site-editing themes): `[yap_reporting]` and
  `[yap_phoneline_admin]` stayed at "Loading phoneline report…" because the page content is rendered before
  WordPress registers scripts. The plugin now registers its script and styles when a shortcode needs them.
* Calls without a service body (meeting searches and other region-wide activity) listed under "All my Areas" can
  now be opened; before, their details answered "Call not found".
* "All my Areas" CSV and printable exports include region-wide activity, matching the dashboard they come from.
  Malformed export links are refused instead of silently falling back to all Areas.
* Regions expand to their Areas in a fixed order.
* WordPress 6.7+: no more "translation loading was triggered too early" notice after an upgrade; translations and
  upgrades run on `init`.
* Cached figures keep their number format (23.0 stayed 23.0, not 23).
* Recommends webservice 1.7.0, which stops counting text messages as phone calls in region-wide totals.
* Development: a public repository with a fake YAP backend and automated tests from the database to the browser
  (see the repository README). Release zips are built from tagged commits and no longer include the plugin's
  test files.

## 1.6.0 (needs webservice 1.6.0)
* "All my Areas" includes meeting searches, Just for Today, SPAD and text messages, which YAP stores without a
  service body (new setting "Region-wide activity", on by default; needs the credential's "Include unassigned
  activity" permission). Diagnostics and Settings say when the credential does not allow it.
* Area dashboards show the labelled server-wide figure for meeting and text lookups when the Area's own count is 0.
* Single-Area reports explain why these figures are 0.

## 1.5.1
* Warning and card when callers were never connected because YAP logged no volunteer response.

## 1.5.0 (needs webservice 1.5.0)
* "Result not recorded by YAP" card, volunteer column and explanation; those calls are no longer shown as missed.

## 1.4.1
* Volunteers tab and Diagnostics explain why names are hidden (credential permission missing, or the site setting is off).

## 1.4.0 (needs webservice 1.4.0)
* Volunteers tab: "Accepted (answered)", "Taken by another volunteer" and "Picked up, nobody answered" replace
  "Picked up, not accepted", matching how YAP blasts calls to a whole group.

## 1.3.0
* One fixed colour scheme: navy and white backgrounds, light grey boxes and lines; charts in navy, dark orange,
  dark green and purple on white. The light/dark/auto theme setting and the `theme`, `color` and `background`
  shortcode attributes were removed (old shortcodes still work; those attributes are ignored).

## 1.2.0 (needs webservice 1.3.0)
* Volunteers tab: picked up, picked up but not accepted, no key pressed, and calls lost after the volunteer's
  phone picked up first. Volunteers whose phone keeps picking up without accepting (typically voicemail or call
  forwarding) are flagged at the top of the table.
* Volunteer names show the last four digits of the number, so volunteers with no name can be found in YAP.

## 1.1.0 (needs webservice 1.2.0)
* Area dashboard and reports show phone and text figures separately: meeting lookups, volunteer requests by
  text, Just for Today and Spiritual Principle a Day by phone and by text. New shortcode metric keys
  `sms_volunteer_lookups`, `sms_jft`, `sms_spad`; `show_sms="no"` hides all text figures.
* "Answered" now follows YAP: the volunteer answered and accepted the call.
* Higher-contrast light and dark themes. Dashboards always paint their own background, so they stay readable
  on any WordPress theme; text meets WCAG AAA (7:1) and borders meet 3:1.

## 1.0.3
* Compatibility check against the webservice's reported API and service version (Test connection, Diagnostics, admin notice).
* Release files are now named with their version, e.g. `yap-reporting-wordpress-1.0.3.zip`.

## 1.0.2
* Show calls YAP forwarded to an outside helpline number (dashboard row and KPI appear only when non-zero).

## 1.0.1
* Volunteer table and export show busy and failed-number attempts (webservice 1.0.2+).

## 1.0.0
* Shortcodes `[yap_reporting]`, `[yap_phoneline_admin]`, `[yap_area_dashboard]`.
* Admin menu: Dashboard, Reports, Call Detail, Volunteers, Area Dashboards, Settings, BMLT Configuration,
  Cache & Synchronization, Access Control, Diagnostics, Setup.
* Encrypted credential storage (libsodium), SSRF-safe outbound requests, per-user/per-role Area grants.
* Background synchronization of aggregate figures with locking, retry back-off and stale-data warnings.
* CSV (formula-safe) and printable exports.
