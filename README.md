# YAP Reporting

Phoneline statistics for Narcotics Anonymous service bodies that use [YAP](https://github.com/bmlt-enabled/yap),
in two parts that are released together:

| Package | Folder | Release file | Runs on |
|---|---|---|---|
| YAP Reporting for WordPress | [`wordpress/yap-reporting`](wordpress/yap-reporting) | `yap-reporting-wordpress-<version>.zip` | the Area or Region website |
| YAP Reporting Webservice | [`webservice/reporting-api`](webservice/reporting-api) | `yap-reporting-webservice-<version>.zip` | the YAP server, inside the YAP folder |

The webservice reads YAP's database read-only and answers aggregate, privacy-safe questions; the plugin shows the
answers as private dashboards, call lists for phoneline administrators and an optional public Area dashboard.
WordPress never connects to the YAP database and never stores individual calls. Installation and use are described in
each package's README; which versions work together is in the `COMPATIBILITY.md` files.

Download the zips from [Releases](../../releases). Each release is built by CI from the tagged commit; the zips carry
a GitHub build provenance attestation:

```sh
gh attestation verify yap-reporting-wordpress-1.7.0.zip --repo gutterwall/yap-reporting
sha256sum -c yap-reporting-wordpress-1.7.0.zip.sha256
```

Inside each zip, `build.json` names the commit it was built from and `checksums.sha256` lists every file.

## Fake YAP backend

[`fake-yap/`](fake-yap) is a YAP 4.5.2 installation for testing, without a phone system: YAP's schema, its event
constants, a writer that stores rows exactly the way YAP's code does, a seeded scenario of realistic helpline
activity with an independent oracle of every figure, a restricted database account, and a fake BMLT root server.
See [`fake-yap/README.md`](fake-yap/README.md).

```sh
php fake-yap/bin/fake-yap install-db --db=fake_yap --fresh      # schema and accounts (needs a MySQL admin account)
php fake-yap/bin/fake-yap seed --db=fake_yap --days=60           # activity up to now
php fake-yap/bin/fake-yap yap-root --dir=/tmp/yap --db=fake_yap  # config.php + app/Constants/EventId.php
php fake-yap/bin/fake-yap expected --from=2026-09-01 --to=2026-09-30 --areas=1001   # what the reports must say
```

## Tests

| Command | What it covers | Needs |
|---|---|---|
| `php webservice/reporting-api/tests/run.php` | webservice logic on an SQLite fixture | `pdo_sqlite` |
| `php wordpress/yap-reporting/tests/run.php` | plugin logic with WordPress stand-ins | — |
| `php tests/service/run.php` | the webservice against the fake YAP database: every figure per Area and window vs. the oracle, privacy, read-only access, configuration, HTTP through the front controller; under a UTC server and one four hours behind | MySQL 8 / MariaDB account that may create databases and users (`YAPR_TEST_DB_*`) |
| `vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist` | the plugin on the WordPress test suite, its requests answered by the real webservice over the fake backend: REST routes by role, shortcodes, exports, cache, sync, admin screens, settings, credential storage, install and uninstall | `WP_TESTS_DIR` (`bin/install-wp-tests.sh`), `composer install` |
| `bin/e2e.sh <dir>` | the release zips on Apache (`.htaccess` only) and nginx (`deploy/nginx.conf.example`) over HTTPS, a WordPress site on the default block theme, headless Chrome as each kind of user | Apache, nginx, PHP-FPM, WP-CLI, Chrome, MySQL (see the script's header) |
| `vendor/bin/phpcs`, `vendor/bin/phpstan analyse` | WordPress security rules and PHP 8.1+ compatibility; static analysis | `composer install` |

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs all of them on every push: PHP 8.1 to 8.5, MySQL 8.0
and 8.4, MariaDB 10.6, 11.4 and 11.8, the oldest supported and the latest WordPress, single site and multisite.

## Releasing

`bin/build.sh` checks that every version string agrees and that both changelogs have an entry, then builds both zips
from the committed files (byte-for-byte reproducible). A release is tagged `v<version>`; CI builds, tests and attests
the zips, and those zips are published.

## License

MIT; see [LICENSE](LICENSE). `fake-yap/yap-root/app/Constants/EventId.php` and
`webservice/reporting-api/tests/fixtures/EventId.yap-4.5.php` are copied unchanged from YAP 4.5.2
(bmlt-enabled/yap, MIT License).
