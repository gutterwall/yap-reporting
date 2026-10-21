# Shortcode reference

Attributes choose **presentation and defaults only**. Every Area named in a shortcode is re-checked
against the viewer's grant on the server; changing an attribute can never reveal another Area.

## `[yap_reporting]`

| Attribute | Values | Default |
|---|---|---|
| `area` / `areas` | BMLT id, or comma list | all of the viewer's Areas |
| `region` | BMLT id; expands to its selected Areas | — |
| `period` | `today, yesterday, 7d, 30d, 45d, 60d, 90d, this_week, last_week, this_month, last_month, this_quarter, last_quarter, this_year, last_year` | site default |
| `start_date`, `end_date` | `YYYY-MM-DD` (both) | — |
| `view` | `overview, calls, volunteers, voicemail, meetings, meditations, sms, trends, all` | `overview` |
| `charts`, `tables`, `export` | `yes` / `no` | `yes` |
| `comparison` | `yes` / `no` — start with previous-period comparison on | `no` |

Visitors can change Area (within their grant), period, custom dates and comparison interactively.

## `[yap_phoneline_admin]`

| Attribute | Values | Default |
|---|---|---|
| `area` / `areas` / `region` / `period` | as above | |
| `tab` | `calls`, `volunteers` | `calls` |

Requires *view call details*; the Volunteers tab also requires *view volunteers*.

## `[yap_area_dashboard]`

| Attribute | Values | Default |
|---|---|---|
| `area` | BMLT id (must be selected on BMLT Configuration) | site default Area |
| `period` | any period above (public pages: 7d, 30d, 90d, this/last month/quarter/year) | `30d` |
| `style` | `cards`, `compact` | `cards` |
| `title` | text; empty uses the Area name | |
| `show_title`, `show_updated`, `icons` | `yes` / `no` | `yes` |
| `show_meditations` | `yes` / `no` — JFT and SPAD rows (phone and text) | `yes` |
| `show_sms` | `yes` / `no` — all text-message rows | `yes` |
| `comparison` | `yes` shows change vs. previous period | `no` |
| `selectable` | `yes` adds a period menu (page reload, no JavaScript needed) | `no` |
| `metrics` | comma list of `volunteer_calls, calls_answered, answer_rate, calls_forwarded, meeting_lookups, sms_meeting_lookups, sms_volunteer_lookups, jft, sms_jft, spad, sms_spad` | all (`calls_forwarded` only when non-zero) |

Examples:

```
[yap_area_dashboard area="123"]
[yap_area_dashboard area="123" period="7d"]
[yap_area_dashboard area="123" period="30d" style="cards"]
[yap_area_dashboard area="123" period="90d" style="compact"]
[yap_area_dashboard area="123" period="this_month" show_updated="yes"]
[yap_area_dashboard area="123" show_meditations="yes" show_sms="yes" comparison="yes"]
[yap_area_dashboard area="123" metrics="volunteer_calls,calls_answered,sms_jft,sms_spad,sms_meeting_lookups,sms_volunteer_lookups"]
```

### Meeting lookups, JFT, SPAD and texts
YAP does not tie these to a service body. Area dashboards therefore show the server-wide figure, labelled
"All lines on this phone system", when the Area's own figure is 0; full reports include them under
"All my Areas" (Settings → Region-wide activity; the API credential needs "Include unassigned activity").

### Colours
Dashboards use one fixed scheme so they read the same on every WordPress theme: navy and white backgrounds,
light grey boxes and lines, and charts in navy, dark orange, dark green and purple on white.

### Visibility

* **Private (default):** shown only to logged-in users granted that Area. Everyone else sees nothing.
* **Public aggregate:** an administrator ticks the Area and the permitted figures under *Area Dashboards →
  Public aggregate mode*. Anonymous visitors then see only those figures, served from a separate public cache.
  Volunteer names, caller data and individual calls are never public.
