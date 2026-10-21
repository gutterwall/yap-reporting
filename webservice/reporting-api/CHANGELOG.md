# Changelog

## 1.7.0
* Text messages are no longer counted as phone calls. YAP writes a `records` row (type 2, the SmsSid) for every
  inbound text; each one was counted in `total_calls` and `ended_before_selection_calls` wherever unassigned
  activity was included (region-wide reports, "All my Areas" in WordPress). Area-only figures were not affected.
* Trend and hour/weekday answer rates use the same definition as the summary: calls whose outcome YAP did not
  record are left out of the denominator. Before, the same month could show two different answer rates.
* Verified against a fake YAP 4.5.2 database on MySQL 8 and MariaDB, under a UTC server and a server four hours
  behind UTC, running as a database account that can read only the documented columns (see the repository's
  `fake-yap/` and `tests/service/`).

## 1.6.0
* Area dashboard also returns server-wide totals (`*_installation_wide`, labelled `scope: installation`) for
  meeting lookups, text meeting lookups and text volunteer requests. Production YAP stores meeting searches,
  JFT/SPAD and all texts without a service body, so per-Area counts for them are normally 0.

## 1.5.1
* Production check: on calls where YAP logged nothing after the dial, the caller's conference row exists but no
  volunteer row — no volunteer ever joined. Those calls are now outcome `no_volunteer_response_logged`
  (`no_volunteer_response_logged_calls`): not answered at call level, but not held against the dialed volunteers.
  `outcome_not_recorded` remains for calls with no conference record at all.

## 1.5.0 — fixes from 500 production rows (Sept–Oct)
* Calls where YAP logged only the dial and the caller hanging up (no pick-up, accept, decline, no-answer, busy —
  the norm on this server since mid-September) are no longer counted as missed. If a volunteer line joined the
  caller's conference the call is answered (`answered_from_conference_record`), credited to the volunteer when only
  one number was dialed; otherwise the outcome is `outcome_not_recorded`, counted in
  `routed_outcome_not_recorded_calls` and per volunteer in `attempts_outcome_not_recorded`, and left out of answer
  rates.
* Outcome events on volunteer legs YAP did not link to the call are matched by dialed number and time.
* Text messages: `records_events.type = 2` (and SM/MM ids) mark texts, so `MEETING_SEARCH_LOCATION_GATHERED` on
  a text no longer creates a phantom call.
* `declined_without_key` counts only on calls nobody took; phones still ringing when another volunteer accepts are
  logged by YAP as declined with `"digits": null` and are no longer counted against them.

## 1.4.1
* Setup page: "Change permissions" for each credential (add Volunteer names etc.) without issuing a new token.
  Credentials without the Volunteer names permission are highlighted.

## 1.4.0 — fixes found with a production call trace
* **Who answered:** credited only from `VOLUNTEER_IN_CONFERENCE` (the volunteer pressed the key). YAP adds every
  phone that picks up to `conference_participants` before anyone accepts; earlier versions read those rows as
  answers and credited the first phone to pick up (often the wrong volunteer, especially in group call blasts).
* **Time zones:** stop forcing the database session to UTC. YAP writes UTC strings into TIMESTAMP columns through
  the server's default zone, so reading through '+00:00' shifted every event by the server offset (4 h on IONOS
  in summer), moving calls across days and hours. New optional `database_time_zone` setting.
* `conference_participants` rows are fetched by call / conference id, and their clock offset against YAP's event
  clock is detected and removed (`participant_clock_offset_seconds` in summary attribution).
* Volunteer pick-ups are classified per call: accepted, taken by another volunteer (`pickups_taken_by_other`), or
  on a call nobody answered (`pickups_on_unanswered_calls`, replaces `picked_up_not_accepted`).
  `possible_voicemail_pickup` no longer flags volunteers who simply lose the race in a call blast.

## 1.3.0
* Volunteer summary adds `picked_up`, `picked_up_not_accepted`, `declined_without_key` (YAP recorded the
  volunteer declining with no key pressed), `calls_lost_after_first_pickup` and `possible_voicemail_pickup`.
  Pick-ups are matched to calls by number and time, so it works whether YAP logs them on the caller's or the
  volunteer's call leg.
* `number_hint` (last four digits) for clients with `volunteer_names`.
* Volunteer names: phone numbers stored as JSON numbers, alternate key names and JSON nested inside JSON are
  now read. Setup page adds a "Volunteer names check" (structure only: JSON key names and counts) and lists
  dialed numbers that have no name by their last four digits.

## 1.2.0
* Answered calls follow YAP by default: a volunteer answered and accepted the call (joined the caller's
  conference), with no minimum length. The previous rule is available as `answered_rule => 'min_seconds'`.
  Conversation time is reported separately (capability `conversation_time`).
* Area dashboard returns phone and text figures separately: `sms_volunteer_lookups`, `jft` (phone calls),
  `sms_jft`, `spad` (phone calls), `sms_spad`, plus `*_installation_wide` for each meditation figure.
  `jft` / `spad` on the Area dashboard no longer add phone and text together.

## 1.1.1
* `.htaccess` now works out its own URL path, so no `RewriteBase` is needed on hosts such as IONOS, whether
  YAP is at a subdomain root (`https://yap.example.org/`) or in a folder. Commented `RewriteBase` lines are
  included for hosts where detection is not enough.
* The service also answers at `…/reporting-api/public/index.php/…` (works even without URL rewriting).
* `yap_root` is found automatically when `reporting-api` was uploaded inside YAP's `public/` folder.
* `config/ENABLE_SETUP.txt` is accepted as well as `ENABLE_SETUP`.

## 1.1.0
* Release files are now named with their version, e.g. `yap-reporting-webservice-1.1.0.zip`; added `VERSION` and `COMPATIBILITY.md`.
* Browser setup console at `/setup` for hosts with only SFTP + HTTPS: installation checks, list of service body ids
  found in YAP, initialise, create / rotate / revoke / update credentials, self-test, clear cache, finish and lock.
  Unlocked by creating `config/ENABLE_SETUP` over SFTP and entering the code it writes to `config/setup-code.txt`.

## 1.0.3
* Verified against production `records_events` rows: `to_number` meta key confirmed for events 5/6/7/12.
* New outcome `forwarded` and metric `forwarded_to_helpline_calls` for service bodies that forward to an outside
  helpline number (`HELPLINE_ROUTE` with no volunteer dialed). These calls no longer count as abandoned.
* Area dashboard includes `calls_forwarded`.

## 1.0.2
* Verified against YAP 4.5 constants (EventId, CallRole, DataType, Status) and config/database.php.
* New events: force dial counts as a volunteer request; busy and bad-number attempts reported
  (`attempts_busy`, `attempts_bad_number`, per-volunteer `busy_attempts`, `bad_number_attempts`).
* Volunteer names read only from `_YAP_VOLUNTEERS_V2_` / `_YAP_GROUP_VOLUNTEERS_V2_`, skipping deleted rows.
* Follow YAP's `ENVIRONMENT` → `config.<env>.php` rule, `DB_HOSTNAME`, and `MYSQL_ATTR_SSL_CA`.

## 1.0.1
* Verified against a production YAP 4.x schema dump.
* Pin the MySQL/MariaDB session to UTC (YAP uses TIMESTAMP columns, which follow the session time zone).
* Use YAP's `cache_records_conference_participants` to link volunteer legs to calls and as an Area fallback.
* Narrow time windows to primary-key ranges, since YAP has no index on its time columns.
* Lower the PHP requirement to 8.1.

## 1.0.0
* Initial release: read-only reporting API for YAP 4.5.x and 5.0.
* Bearer-token authentication with hashed storage, rotation grace period and revocation.
* Per-client service-body scope and capability scopes.
* Session grouping of multi-leg calls; answered = volunteer connected > 5 s (configurable).
* Endpoints: health, capabilities, areas, summary, trends, distributions, area-dashboard,
  meeting-lookups, meditations, sms-summary, calls, calls/{id}, volunteers, volunteer-summary.
* CLI: init, check, discover, client:create/list/update/rotate/revoke, cache:clear.
