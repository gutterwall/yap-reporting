# API reference (v1)

Base URL: `https://<your-yap-host>/<yap-path>/reporting-api/api/v1/`
All responses are JSON. All endpoints are `GET`. Machine-readable spec: `openapi.yaml`.

## Common parameters

| Parameter | Format | Notes |
|---|---|---|
| `areas` | `123,456` | BMLT service body ids. Must all be in the client's scope. Omit for all authorized. |
| `area_id` | `123` | Single-id alias. |
| `include_unassigned` | `true/false` | Activity with no service body. Requires `--all` or `--allow-unassigned`. |
| `period` | `today, yesterday, 7d, 30d, 45d, 60d, 90d, this_week, last_week, this_month, last_month, this_quarter, last_quarter, this_year, last_year, custom` | Default `30d`. Rolling periods include today. |
| `start_date`, `end_date` | `YYYY-MM-DD` | Inclusive. Use instead of `period`. Max 400 days. |
| `timezone` | IANA name | Default from config. Boundaries are local midnights (DST-safe). |

Unknown parameters are rejected with `400`.

## Response envelope

```json
{
  "data": { … },
  "meta": {
    "generated_at": "2026-10-09T14:30:00+00:00",
    "range": {"period": "30d", "start_date": "2026-09-10", "end_date": "2026-10-09", "timezone": "America/New_York", "days": 30, …},
    "service_bodies": {"service_body_ids": [123], "include_unassigned": false},
    "answered_definition": "A volunteer answered, accepted the call and was connected to the caller (as in YAP).",
    "cache_ttl_seconds": 60,
    "api_version": "v1"
  }
}
```

Metric objects:
```json
{"value": 71, "available": true, "level": "call"}
{"value": 84.5, "available": true, "level": "call", "unit": "percent"}
{"value": null, "available": false, "level": "message", "reason": "This YAP build does not define event(s): SPAD_LOOKUP_SMS."}
```
A rate with no denominator is `{"value": null, "available": true}`.

Errors:
```json
{"error": {"code": "area_not_authorized", "message": "…", "request_id": "9f2c…"}}
```
Codes: `unauthorized` 401, `insufficient_scope` / `area_not_authorized` / `https_required` / `ip_not_allowed` 403,
`not_found` 404, `method_not_allowed` 405, `range_too_large` 400/413, `rate_limited` 429,
`invalid_parameter` / `unknown_parameter` / `invalid_range` 400, `not_initialised` / `schema_unsupported` 503.

## Endpoints

| Endpoint | Scope | Extra parameters | Returns |
|---|---|---|---|
| `health` | none | — | `{status, service, api_version}` |
| `capabilities` | summary | — | Client scope, detected YAP schema & events, metric availability, definitions, limits |
| `areas` | summary | default period 90d | Service body ids with activity counts |
| `summary` | summary | `compare`, `group_by=service_body` | All summary metrics (+ previous period, + per service body) |
| `trends` | summary | `granularity=day/week/month/quarter/year`, `compare` | Zero-filled series |
| `distributions` | summary | — | Calls by hour and weekday, answer rate per hour, peak hour, weekend vs weekday |
| `area-dashboard` | summary | `compare` | The seven compact-dashboard figures with labels and scopes |
| `meeting-lookups` | summary | — | Voice calls/actions, SMS lookups, per service body, trend |
| `meditations` | summary | — | JFT/SPAD Area-attributed and installation-wide (labelled) |
| `sms-summary` | summary | — | SMS totals by event type and per day |
| `calls` | call_details | `category, outcome, answered, voicemail, volunteer_id, min_duration, max_duration, sort=asc/desc, page, per_page` | Paginated calls (no caller data) |
| `calls/{id}` | call_details | `timezone` | One call with its event timeline |
| `volunteers` | volunteer_stats | — | Volunteer ids (+ names with `volunteer_names`) |
| `volunteer-summary` | volunteer_stats | `sort=answered_calls/answer_rate/total_conversation_seconds/routed_attempts` | Per-volunteer statistics with attribution caveats |

### Summary metrics
`total_calls, volunteer_requested_calls, volunteer_routed_calls, answered_calls, routed_not_answered_calls,
connected_too_short_calls, connected_unknown_duration_calls, answer_rate, missed_rate, voicemail_calls,
volunteer_calls_to_voicemail, voicemail_rate, abandoned_volunteer_calls, forwarded_to_helpline_calls, ended_before_selection_calls,
volunteer_dial_attempts, attempts_not_answered, attempts_rejected, attempts_busy, attempts_bad_number, average_attempts_per_routed_call,
total_call_seconds, average_call_seconds, median_call_seconds, min_call_seconds, max_call_seconds,
calls_with_known_duration, total_conversation_seconds, average_conversation_seconds, meeting_lookup_calls,
meeting_lookup_actions, sms_meeting_lookups, sms_volunteer_requests, jft_calls, jft_voice_plays, jft_sms,
spad_calls, spad_voice_plays, spad_sms`

Definitions: `GET capabilities` → `data.definitions`, and `DATA-MAPPING.md`.

### Area dashboard example
```json
{"data": {"current": {
  "volunteer_calls": {"value": 84, "available": true, "level": "call"},
  "calls_answered":  {"value": 71, "available": true, "level": "call"},
  "answer_rate":     {"value": 84.5, "available": true, "level": "call", "unit": "percent"},
  "meeting_lookups": {"value": 126, "available": true, "level": "call"},
  "sms_meeting_lookups": {"value": 43, "available": true, "level": "message"},
  "jft":  {"value": 2, "available": true, "level": "interaction", "scope": "area"},
  "spad": {"value": 1, "available": true, "level": "interaction", "scope": "area"},
  "jft_installation_wide":  {"value": 28, "available": true, "level": "interaction", "scope": "installation"},
  "spad_installation_wide": {"value": 19, "available": true, "level": "interaction", "scope": "installation"},
  "labels": {…}
}}}
```
(Fictional numbers.)

### Call object (`calls`)
```json
{"id": "c_20261008_4f9a0c2e7b1d33a8e6f1", "started_at": "2026-10-08T21:14:03-04:00", "date": "2026-10-08", "time": "21:14:03",
 "service_body_id": 123, "attribution": "routing_event", "category": "volunteer", "outcome": "answered",
 "volunteer_requested": true, "routed_to_volunteers": true, "volunteer_attempts": 2, "answered": true,
 "answer_status": "answered", "duration_seconds": 412, "conversation_seconds": 388, "voicemail": false,
 "meeting_lookups": 0, "jft": 0, "spad": 0,
 "answered_by": {"volunteer_id": "v_1c9e44b0d2aa7f13", "name": "…"}}
```
`answered_by` appears only with `volunteer_stats`; `name` only with `volunteer_names`.
Call ids embed the local date so `calls/{id}` searches a 3-day window; pass the same `timezone` used to list.

### Volunteer summary fields (1.3.0)
`volunteer_id`, `name`*, `number_hint`*, `routed_attempts`, `picked_up`, `answered_calls`, `pickups_taken_by_other`, `pickups_on_unanswered_calls`,
`declined_without_key`, `calls_lost_after_first_pickup`, `possible_voicemail_pickup`, `not_answered_attempts`,
`rejected_attempts`, `busy_attempts`, `bad_number_attempts`, `answer_rate`, `total_conversation_seconds`,
`average_conversation_seconds`, `last_answered_at`, `last_activity_at`. (*only with the `volunteer_names` scope)
