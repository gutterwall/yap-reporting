# YAP data mapping, metric definitions and compatibility

## What was verified, and what is detected at runtime

The table structure below was **verified against a production YAP 4.x schema dump** (MariaDB 11.8, Laravel
migrations dated 2024-03/04). Event numbering is still read from your installed `app/Constants/EventId.php`,
and `php bin/yapr check` / `discover` print what your server actually has.

| Table | Columns used | Never selected |
|---|---|---|
| `records_events` | `id`, `callsid`, `event_time` (TIMESTAMP), `event_id`, `service_body_id`, `meta` (volunteer-dial events only) | `type` |
| `records` | `id`, `callsid`, `start_time`, `end_time` (TIMESTAMP), `duration`, `to_number` (your line), `payload` (Direction only) | **`from_number`** (caller) |
| `conference_participants` | `id`, `timestamp`, `conferencesid`, `callsid`, `role` | `friendlyname` |
| `cache_records_conference_participants` | `parent_callsid`, `callsid`, `service_body_id` | `guid` |
| `config` | `service_body_id`, `data`, `data_type` (volunteer names only) | |
| `users`, `sessions`, `alerts`, `cache`, `metrics`, `event_status`, `flags`, `migrations*` | — | **all** (password hashes, PINs, etc.) |

| Fact | Status | How the service handles it |
|---|---|---|
| DB settings are `$mysql_*` in `config.php` | Verified (YAP docs) | Tokenised, never executed; `.env` fallback. |
| All date columns are `TIMESTAMP` | Verified (schema and production trace) | See "Time zones" below: read through the server's default zone, like YAP. |
| No index on `records_events.event_time`, `records.start_time`, `conference_participants.timestamp` | Verified (schema) | Time windows are converted to primary-key ranges by a binary search over `id` (~30 indexed lookups), so report queries do not scan whole tables. Disable with `use_id_range => false`. |
| Indexed: `records_events.callsid`, `records_events.service_body_id`, `records.callsid`, `conference_participants.callsid/conferencesid`, both columns of `cache_records_conference_participants` | Verified (schema) | Link lookups use these. |
| `cache_records_conference_participants` maps child legs to the parent call | Verified (schema; name and indexes) | Used as a second leg-linking source and as an Area fallback (`attribution: yap_call_cache`) when no event carries a service body. It is a cache YAP fills itself, so it may be partial; conference links still apply. |
| Event ids are constants in `App\Constants\EventId` | Verified (YAP source) | Parsed from your installation. |
| `conference_participants.role` caller = 1, volunteer = 2 | **Verified** (`App\Constants\CallRole`) | Defaults match; still configurable. |
| Event ids 1–28 | **Verified** (`App\Constants\EventId`, YAP 4.5) | Still read from your install at runtime; a copy is in `tests/fixtures/EventId.yap-4.5.php`. |
| Volunteer lists in `config` | **Verified** (`App\Constants\DataType`): `_YAP_VOLUNTEERS_V2_`, `_YAP_GROUP_VOLUNTEERS_V2_`; `config.data` is JSON; `status = 1` is deleted (`App\Constants\Status`) | Only those two data types are read, deleted rows are skipped, only `volunteer_name` / `volunteer_phone_number` are used. |
| Database settings | **Verified** (`config/database.php`): YAP loads `config.php`, or `config.<ENVIRONMENT>.php` when the `ENVIRONMENT` variable is set; optional `MYSQL_ATTR_SSL_CA` | The service follows the same rule and honours `MYSQL_ATTR_SSL_CA`. |
| Dialed volunteer number is in `records_events.meta` under `to_number` | **Verified** (production rows: events 5, 6, 7, 12; event 5 stores 10 digits, 6/7/12 store `+1…`) | Numbers are normalised, so both forms map to the same volunteer. |
| `meta` of events 1 and 14 holds the caller's typed location and coordinates; event 4 holds the recording URL; event 15 holds the forwarding number | **Verified** (production rows) | `meta` is selected **only** for volunteer events (5, 6, 7, 8, 9, 12, 26, 27), so caller locations and recording URLs are never read. |
| Meeting searches (events 2, 14) carry `service_body_id = 0` | **Verified** (production rows) | Counted as unassigned unless the helpline number is mapped in `attribution.line_mapping`. |
| Event 15 (`HELPLINE_ROUTE`) appears **instead of** volunteer dialing when a service body forwards to an outside number | **Verified** (production rows: 1 → 15, no 5/10/12) | Reported as outcome `forwarded` / `forwarded_to_helpline_calls`; excluded from routed, answered, missed and abandoned. |
| `records.payload` contains the Twilio `Direction` | Expected | Optional; outbound legs are also recognised by conference role and the leg cache. |

The dump contained no rows, so meta formats and role values could not be confirmed from data. After installing,
run `php bin/yapr check` and look at a recent call in `GET /api/v1/calls/{id}`: if volunteer legs appear as separate
calls or `unattributed_attempts` is high, adjust the two configurable rows above.

## Event names used

| Purpose | Event name(s) |
|---|---|
| Caller asked for a volunteer | `VOLUNTEER_SEARCH`, `HELPLINE_ROUTE` |
| Routing attempt | `VOLUNTEER_DIALED` |
| Caller asked for a volunteer (direct) | `VOLUNTEER_SEARCH_FORCE_DIALED` |
| Attempt outcome | `VOLUNTEER_NOANSWER`, `VOLUNTEER_REJECTED`, `VOLUNTEER_NUMBER_BUSY`, `VOLUNTEER_NUMBER_BAD`, `VOLUNTEER_ANSWERED_BUT_CALLER_HUP` |
| Shown in timelines only | `STIR_VERSTAT_RECEIVED`, `MEETING_SEARCH_LOCATION_GATHERED`, `PROVINCE_LOOKUP_LIST`, `VOICEMAIL_PLAYBACK` (retired) |
| SMS, not counted as lookups | `SMS_BLACKHOLED` |
| Volunteer connected | `VOLUNTEER_IN_CONFERENCE` (+ `conference_participants` volunteer row) |
| Hang-up | `VOLUNTEER_HUP`, `CALLER_HUP` (+ `records.end_time`) |
| Voicemail | `VOICEMAIL` |
| Meeting search | `MEETING_SEARCH` (voice), `MEETING_SEARCH_SMS` |
| Meditations | `JFT_LOOKUP`, `SPAD_LOOKUP`, `JFT_LOOKUP_SMS`, `SPAD_LOOKUP_SMS` (if the build defines it) |
| SMS volunteer request | `VOLUNTEER_SEARCH_SMS` |
| Dialback | `DIALBACK` |

Any event whose name ends in `_SMS` or starts with `SMS_` is treated as an SMS message, never a call.

## Building one call from many legs

1. Every leg (Twilio CallSid) seen in events, records, `conference_participants` or YAP's leg cache is a node.
2. Legs that share a `conferencesid`, or that YAP's `cache_records_conference_participants` lists under the same
   `parent_callsid`, are joined (union-find). A `parent_callsid` is always treated as a caller leg.
3. In each group, caller legs are those with the caller role, or inbound records, or caller-side events.
   Volunteer legs are those with the volunteer role, outbound records, or only volunteer-leg events.
4. A group with no caller leg is an **orphan volunteer leg** (e.g. a volunteer phone that rang and was not
   answered). It is not a call; its attempt events still count at attempt level.
5. The call's start is the earliest caller record start or event. Only calls that **start** in the range are
   counted; the query window is padded by 6 hours to capture straddling calls.

## Answered calls (verified against a production call trace)

YAP blasts a call to every volunteer on shift. For each phone that picks up, YAP logs `VOLUNTEER_ANSWERED` (6)
**on that volunteer's own call leg**, with `to_number` = that volunteer, and adds the leg to
`conference_participants` (role 2) **at pick-up, before any key is pressed**. Several phones can pick up; the
volunteer who presses the key is logged as `VOLUNTEER_IN_CONFERENCE` (12) on their leg, with their number.

So:

* **Answered** = a `VOLUNTEER_IN_CONFERENCE` event. The volunteer credited is the number on that event (or,
  if YAP left it out, the number on that leg's other events, then the most recently dialed number).
* A `conference_participants` volunteer row is **not** an answer — it is a pick-up. (Versions before 1.4.0 treated
  it as one, which credited the first phone to pick up instead of the volunteer who accepted.)
* Conversation time: from the accept to the earliest `VOLUNTEER_HUP` on that leg, `CALLER_HUP`, or the caller's
  record end.

Per volunteer, every pick-up on a call is one of: **accepted** by them, **taken by another volunteer** (normal in a
blast), or on a call **nobody answered**.

`answered_rule = min_seconds` (optional) additionally requires the conversation to last more than
`answered_min_seconds`.

## When YAP does not log the volunteer's side (verified in production)

On the production server most calls since mid-September have only `VOLUNTEER_SEARCH` (1), `CALLER_IN_CONFERENCE`
(10), `VOLUNTEER_DIALED` (5) and `CALLER_HUP` (13): nothing about pick-up, accept or decline. For such a call:

1. If a volunteer leg joined the caller's conference (`conference_participants`, role 2), it is **answered**
   (`answer_source: conference_join`, counted in `answered_from_conference_record`). If exactly one number was
   dialed, that volunteer is credited; otherwise nobody is guessed.
2. If the caller's own conference row exists but no volunteer row does, no volunteer ever joined: outcome
   **`no_volunteer_response_logged`**. The caller was not helped, so the call counts as not answered; the dialed
   volunteers are not blamed, because YAP logged no response from their phones (verified in production).
3. Otherwise, unless it went to voicemail, its outcome is **`outcome_not_recorded`**: not answered, not missed,
   and left out of answer rates (`routed_outcome_not_recorded_calls`; per volunteer
   `attempts_outcome_not_recorded`).

If many calls show "not recorded", YAP's volunteer webhooks are probably failing; YAP issue #1644 (Oct 2026)
describes `helplineAnswerResponse` / `helplineOutdialResponse` returning errors. Check YAP's logs and version.

## Time zones (verified in production)

`records_events.event_time` and `records.start_time / end_time` are TIMESTAMP columns that YAP fills with UTC
strings through the database server's **default** time zone. `conference_participants.timestamp` is filled by the
database clock itself. On a server whose default zone is not UTC these two clocks differ by the server's offset
(4 hours on the production IONOS host in summer).

* The service reads through the server's default zone (like YAP), so event and record times come back exactly as
  YAP wrote them (UTC). Set `database_time_zone` only if YAP's own connection uses a different zone.
* Participant times are aligned automatically: the median difference between each caller's
  `CALLER_IN_CONFERENCE` and its conference join (and each volunteer leg's pick-up and join) is rounded to
  15 minutes and removed. The applied correction is reported as `participant_clock_offset_seconds`.
* Participant rows are found through the call ids in the window and their conference ids (both indexed), never
  by comparing the two clocks.

## Call outcomes

| category | outcome values |
|---|---|
| `volunteer` | `answered`, `voicemail`, `connected_short`, `connected_unknown_duration`, `not_answered` (dialed, caller left), `forwarded` (sent to an outside helpline number), `not_routed` (asked for a volunteer, nobody dialed) |
| `voicemail` | `voicemail` (voicemail without volunteer request) |
| `meeting_lookup` | `automated` |
| `meditation` | `automated` |
| `dialback` | `dialback` |
| `menu_only` | `ended_before_selection` |

## Metric levels

| Level | Counts | Examples |
|---|---|---|
| `call` | unique incoming calls | `total_calls`, `volunteer_routed_calls`, `answered_calls`, `meeting_lookup_calls` |
| `attempt` | individual volunteer dials | `volunteer_dial_attempts`, `attempts_not_answered` |
| `interaction` | individual automated actions on calls | `meeting_lookup_actions`, `jft_voice_plays` |
| `message` | individual inbound SMS | `sms_meeting_lookups`, `jft_sms` |

## Area (service body) attribution

> **Verified in production:** `MEETING_SEARCH`, `MEETING_SEARCH_LOCATION_GATHERED`, `JFT_LOOKUP`, `SPAD_LOOKUP`,
> `PROVINCE_LOOKUP_LIST` and every text-message event are stored with `service_body_id` NULL. They can only be
> reported region-wide (credential needs "Include unassigned activity") or per Area through `line_mapping`.

| Activity | Attributed by | Fallback |
|---|---|---|
| Calls | `attribution.policy` (default `routing`: service body on the last `HELPLINE_ROUTE` / `VOLUNTEER_DIALED` / `VOLUNTEER_SEARCH` / `VOLUNTEER_SEARCH_FORCE_DIALED` / `VOICEMAIL` event) | first event with a service body → `line_mapping` → YAP's leg cache → **unassigned** |
| Meeting searches, JFT, SPAD (voice) | service body on that event | `line_mapping` → unassigned |
| SMS | service body on that event | unassigned |
| Volunteer attempts | service body on the attempt event | unassigned |

* A call is counted in exactly **one** service body — never duplicated across Areas.
* Unassigned activity is visible only to clients with `--all` or `--allow-unassigned`.
* Service body *names* are not stored in YAP; the WordPress plugin resolves ids against BMLT.
* Region totals: request `areas=<id>,<id>,…` (all must be in scope). Expanding a Region to its child Areas
  uses the BMLT hierarchy and is done by the WordPress plugin.
* Meditation figures: Area figures include only requests tied to that Area. Installation-wide totals are
  returned separately and labelled `scope: installation`.

## Not available from YAP data

| Requested metric | Why |
|---|---|
| Successful vs failed meeting searches | YAP does not record whether a search returned meetings. Returned as unavailable. |
| After-hours activity | Requires per-line operating hours; not stored in YAP. The WordPress plugin can compute it from `distributions`. |
| Volunteer availability / shifts at call time | Only current schedules are stored, not history. |
| Custom extensions | YAP has a menu option for them (`SearchType::CUSTOM_EXTENSIONS`) but records no event, so those calls appear as `menu_only`. |

## Compatibility matrix

| YAP | Status |
|---|---|
| 5.0.x (`main`) | Supported. Same reporting tables; Laravel 12. |
| 4.5.x (4.5.0–4.5.2) | Supported (primary target). |
| 4.0–4.4 | Expected to work; SPAD metrics need ≥ 4.3/4.4. Run `bin/yapr check`. |
| 3.5–3.10 (pre-Laravel) | Partially: tables exist since 3.5; the event file location differs — set `event_ids_file`. |
| < 3.5 | Not supported (no database call records). |

## Optional indexes for very large installations

The service never alters YAP's schema, and the primary-key range search makes these unnecessary for most
helplines. A DBA may still add them for faster ad-hoc queries:

```sql
CREATE INDEX yapr_events_time ON records_events (event_time);
CREATE INDEX yapr_records_start ON records (start_time);
CREATE INDEX yapr_cp_time ON conference_participants (`timestamp`);
```
