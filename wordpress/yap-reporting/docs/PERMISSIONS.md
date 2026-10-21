# Permissions and capabilities

| Capability | Grants |
|---|---|
| `yapr_manage_settings` | All configuration pages; sees every selected Area |
| `yapr_manage_access` | Access Control page |
| `yapr_view_reports` | `[yap_reporting]`, private Area dashboards, Dashboard/Reports pages |
| `yapr_view_call_details` | `[yap_phoneline_admin]`, Call Detail page, call timelines |
| `yapr_view_volunteers` | Volunteer statistics and "answered by" in call lists |
| `yapr_export_reports` | CSV / printable downloads (plus the capability for the underlying report) |

Roles created on activation:

| Role | Capabilities |
|---|---|
| Administrator (existing) | all six |
| Phoneline Administrator | view reports, call details, volunteers, export |
| Area Reporter | view reports, export |
| Public viewer (not logged in) | published aggregate figures only |

## Area grants

1. Administrators (`yapr_manage_settings`) see every Area selected on BMLT Configuration.
2. Otherwise, a **per-user** grant (Access Control → Individual users) replaces the role grant.
3. Otherwise, the union of the user's **role** grants applies.
4. The result is always limited to the selected Areas. No grant = no data.

Enforcement points: every REST route (`/wp-json/yapr/v1/*`), every export, every shortcode render, and the
call-detail route (which checks the call's Area against the user even though the site credential may cover more).
The site's API credential is never used to widen a user's view.

The webservice applies its own, independent scope to the site's credential, so a compromised WordPress site can
reach only the Areas and data levels the YAP administrator granted to that credential.
