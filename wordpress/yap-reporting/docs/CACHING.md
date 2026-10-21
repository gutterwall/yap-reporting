# Caching and synchronization

## What is cached
Only **aggregate** figures (summary, trends, distributions, Area dashboard, meeting lookups, meditations, SMS)
are stored, in `{prefix}yapr_aggregate_cache`. Individual calls and volunteer statistics are always fetched live
and never stored in WordPress. (`yapr_event_cache` from the original design is intentionally not created.)

Cache keys combine visibility (private/public), endpoint, sorted Area ids, period parameters and timezone.
Public entries contain only published figures and are kept separate from private ones.

## Jobs

| Job | Default | Refreshes |
|---|---|---|
| Recent figures | 15 min | Area dashboards for the "periods kept ready", plus summary/trends for the default period, per Area and combined; published public dashboards |
| Past periods | 6 h | last month, last quarter, this year; monthly trends |
| Service bodies | 24 h | BMLT service body list |
| Cache prune | daily | removes figures older than the retention setting |

Each run takes a lock (stale after 15 minutes), records duration, item count, errors and failed Areas, and
after a failed run backs off exponentially (1 min → max 6 h). Manual *Sync now* ignores the back-off.

Because the webservice recomputes from YAP's raw records every time, late-arriving or corrected call events are
reconciled on the next refresh of any window that contains them.

## Page loads
Dashboards read the cache. If an entry is missing or expired, one request (per entry) goes to the API under a
60-second lock; concurrent visitors get the previous copy. If the API is down, the last good copy is served with
a "may be out of date" notice once it is older than *stale factor* × the refresh interval.

## Reliable scheduling
WP-Cron only runs when the site gets traffic. For dependable syncs:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```
```cron
*/5 * * * * curl -fsS https://your-site.org/wp-cron.php?doing_wp_cron >/dev/null 2>&1
# or, with WP-CLI:
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

## Performance targets (guidance)
| Operation | Target |
|---|---|
| Area dashboard from cache | < 50 ms server time, one DB query |
| Full report page from cache | 5–6 REST calls, each < 100 ms |
| Recent sync, 10 Areas | ~70 API requests; typically under a minute |
| Call list page (live) | dominated by the webservice; usually < 2 s for 30 days |
Measure on your host with Query Monitor and the *Duration* column on the Cache page.
