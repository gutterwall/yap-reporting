# Fake YAP backend

A YAP 4.5.2 installation for testing the YAP Reporting Webservice and the WordPress plugin without a phone system.
Everything in it is fictional: the Region, Areas and volunteers are made up and every phone number is in the
NANP 555-01xx fictional range.

| Part | What it is |
|---|---|
| `schema/yap-4.5.2.sql` | YAP's tables, translated from YAP 4.5.2's Laravel migrations, including the tables the service must never read |
| `yap-root/app/Constants/EventId.php` | YAP 4.5.2's event constants, unchanged (MIT License) |
| `src/YapWriter.php` | writes rows the way YAP's code does (see below) |
| `src/Scenario.php` | a seeded stretch of helpline activity and the figures the reports must show for it |
| `src/Installer.php` | database, accounts and installation folder |
| `bin/fake-yap` | command line for all of the above |
| `bmlt/index.php` | a fake BMLT root server answering `client_interface/json/?switcher=GetServiceBodies` |

## How rows are written

Faithful to YAP 4.5.2 (`app/Repositories/ReportsRepository.php` and the controllers that call it), because the
service's hardest problems come from exactly these details:

* `records_events.event_time` and `records.start_time/end_time` are UTC strings stored through the connection's
  **default** time zone (YAP never sets one), so on a server whose default zone is not UTC the stored instants are
  shifted, exactly as in production.
* `conference_participants.timestamp` comes from the database clock (`FROM_UNIXTIME()`, what `CURRENT_TIMESTAMP` would
  store), so it differs from event times by the server's offset.
* `meta` is `json_encode()` of what YAP passes: dialed numbers as typed on the volunteer list (10 digits) for
  `VOLUNTEER_DIALED`, Twilio's E.164 `Called` for pick-ups, accepts and declines; `"digits": null` for phones still
  connected when another volunteer accepts, `""` when a carrier voicemail answered.
* A call's `records` row is written by the status callback when the call **ends**; every inbound text gets a
  `records` row of type 2 with its SmsSid. Rows are inserted in the order YAP would write them, across overlapping
  calls, so primary keys are not in start-time order.
* Text-message events and meeting / JFT / SPAD lookups carry no service body (verified in production).

## The scenario and its oracle

`Scenario` generates, from a seed, the call flows observed on a production YAP server: calls blasted to several
volunteers where one accepts and the others are logged as declined; single-volunteer calls, including very short
ones; voicemail (also after a carrier voicemail picked up); callers leaving while phones ring; forced dialing with a
busy and a failed number; nobody on shift; forwarding to an outside helpline; calls where YAP logged only the dial
and the hang-up (with and without the caller's conference row, and with a volunteer line that joined); automated
meeting searches (some tied to an Area, as older YAP builds did), JFT, SPAD, dialback and menu-only calls; and texts
for meetings, volunteers, JFT and SPAD.

Each call carries its expected contribution to every reported figure, written down from the definitions in
`webservice/reporting-api/docs/DATA-MAPPING.md` rather than computed by the service's code. `expected()`,
`expectedVolunteers()` and `expectedDaily()` sum them for any Area filter and time window, so tests compare the
service with an independent oracle.

Service bodies: Region 1000 with Areas 1001-1004, and Region 2000 with Area 2001 (BMLT ids). Area 1003 forwards
half of its calls to an outside helpline number. Volunteers have names on YAP's lists, one is on a group list, one has
no name, and a deleted list names a current volunteer differently (it must be ignored).

## Accounts

`install-db` creates two accounts on the database:

* `--user` (default `yap`): every right on the database, like YAP's own account, which the reporting service reuses.
* `--reader-user` (default `yap_reader`): may read only what DATA-MAPPING.md says the service reads (column-level
  grants; no `users`, `sessions`, `alerts`, caller numbers or conference names). Running the service as this account
  proves it never reads anything else: MySQL refuses the query if it tries.

The other tables are filled with canary values (`CANARY-…`) that tests look for in every response.

## Command line

```sh
php fake-yap/bin/fake-yap help
php fake-yap/bin/fake-yap install-db --db=fake_yap --admin-user=root --admin-pass=… --fresh
php fake-yap/bin/fake-yap seed --db=fake_yap --end=today --days=60 --seed=1
php fake-yap/bin/fake-yap yap-root --dir=/var/www/yap --db=fake_yap --user=yap --pass=yap
php fake-yap/bin/fake-yap expected --end=today --days=60 --seed=1 --now=<seed time> --from=2026-09-01 --to=2026-09-30 --areas=1001
php fake-yap/bin/fake-yap expected … --volunteers     # per volunteer
php fake-yap/bin/fake-yap expected … --daily          # per day
php fake-yap/bin/fake-yap bmlt-json
```

`expected` must be given the same `--end`, `--days`, `--seed`, `--tz` and (with `--end=today`) `--now` as `seed`.
Choose windows without a daylight-saving change on servers whose default zone observes one: YAP itself cannot store
times in the repeated or skipped hour correctly there.
