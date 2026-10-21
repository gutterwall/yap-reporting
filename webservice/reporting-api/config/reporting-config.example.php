<?php

/*
 * YAP Reporting Webservice configuration.
 *
 * Copy to config/reporting-config.php and adjust. Every setting is optional;
 * the defaults in src/Config.php are secure. This file is never web-accessible.
 */

return [
    // The YAP installation this service reads. Default: the parent directory of reporting-api/.
    // 'yap_root' => '/var/www/html/yap',

    // Database credentials are read from YAP's own config.php automatically.
    // Only set this if YAP's config.php builds them dynamically (e.g. getenv()).
    // 'database' => ['host' => 'localhost', 'port' => 3306, 'database' => 'yap', 'username' => 'yap', 'password' => '...'],

    // Keep the data directory OUTSIDE the web root where your host allows it.
    // 'data_dir' => '/var/lib/yap-reporting',

    // Reporting defaults
    'default_timezone' => 'America/New_York',
    'week_starts_on' => 'sunday',          // or 'monday'

    // Answered calls: 'accepted' (as in YAP: volunteer answered and accepted the call) or
    // 'min_seconds' (also require the connection to last more than answered_min_seconds).
    'answered_rule' => 'accepted',
    'answered_min_seconds' => 5,

    // HTTPS is mandatory. If TLS terminates at a reverse proxy / load balancer, list it here so
    // X-Forwarded-Proto and X-Forwarded-For are trusted from it (and only from it).
    'require_https' => true,
    'trusted_proxies' => [
        // '10.0.0.5',
        // '172.16.0.0/12',
    ],

    'attribution' => [
        'policy' => 'routing',              // routing | first | last
        // Map YOUR helpline numbers (never caller numbers) to BMLT service body ids. Used for
        // calls and automated lookups that YAP recorded without a service body.
        'line_mapping' => [
            // '+15555550100' => 123,
        ],
    ],

    'limits' => [
        'max_range_days' => 400,
        'max_per_page' => 200,
        'max_areas' => 50,
        'query_timeout_seconds' => 20,
    ],

    'rate_limit' => [
        'requests_per_minute' => 120,
        'auth_failures_per_15_minutes' => 20,
    ],

    // Seconds to reuse an identical aggregate report. Individual calls and volunteer data are never cached.
    'cache_ttl_seconds' => 60,

    // Show installation-wide JFT / SPAD totals (clearly labelled) alongside Area-attributed figures.
    'expose_installation_meditation_totals' => true,
];
