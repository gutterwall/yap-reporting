=== YAP Reporting for WordPress ===
Contributors: yap-reporting
Tags: narcotics anonymous, bmlt, yap, helpline, reporting
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.6.0
License: MIT

Private phoneline reports and a compact Area Service dashboard for YAP helplines, using BMLT service bodies.

== Description ==

Connects to the YAP Reporting Webservice installed on your YAP server and shows:

* `[yap_reporting]` — call volume, volunteer answer rates, voicemail, meeting lookups, text messages, JFT and SPAD, with charts, tables and comparisons.
* `[yap_phoneline_admin]` — individual calls (never caller numbers) and volunteer activity for Phoneline Administrators.
* `[yap_area_dashboard area="123"]` — a compact set of Area figures for the Area Service page; private by default, optionally public.

Reports are private. WordPress never connects to the YAP database and never stores individual call records.

== Installation ==

1. Ask your YAP administrator to install the Reporting Webservice and create a credential for this site.
2. Upload and activate this plugin.
3. Open YAP Reporting → Setup and follow the checklist.

== Frequently Asked Questions ==

= Why are some figures "Not available"? =
Your YAP version does not record that event. Diagnostics lists the reason for each figure.

= Why are JFT/SPAD shown "for all lines on this phone system"? =
YAP often records meditation requests before it knows which Area a caller belongs to. When no Area-specific figure exists, the dashboard shows the server-wide number and labels it as such.

== Changelog ==

= 1.0.0 =
* First release.
