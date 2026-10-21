<?php

declare(strict_types=1);

require_once __DIR__ . '/../HttpServer.php';

use YapReporting\Auth\ClientStore;
use YapReporting\Config;

/*
 * The real front controller (public/index.php) over HTTP, configured the way an operator does: a
 * reporting-config.php file. Plain HTTP is allowed here only because the client is on the loopback address;
 * the HTTPS rules themselves are covered end to end behind Apache and nginx (bin/e2e.sh).
 */

$serve = static function (Harness $h, array $extra = []): array {
    $data = $h->tempDir();
    $config = array_replace_recursive([
        'yap_root' => __DIR__ . '/../../../fake-yap/yap-root',
        'data_dir' => $data,
        'cache_ttl_seconds' => 0,
        'allow_http_from_loopback' => true,
        'database' => ['host' => $h->host, 'port' => $h->port, 'database' => $h->db, 'username' => 'yapr_t_reader', 'password' => 'reader-pass'],
    ], $extra);
    $store = new ClientStore($data);
    $store->initialise();
    [, $token] = $store->create('http test', false, [1001, 1002], true, ['summary', 'call_details'], []);
    return [new HttpServer($config, $h->tempDir()), $token];
};

return [
    'health, authentication and response headers' => function (Harness $h) use ($serve): void {
        [$s, $token] = $serve($h);
        try {
            $r = $s->request('GET', '/yap/reporting-api/api/v1/health');
            Assert::same(200, $r['status']);
            Assert::same(['status' => 'ok', 'service' => 'yap-reporting', 'api_version' => 'v1'], $r['json']);
            Assert::same('private, no-store, max-age=0', $r['headers']['cache-control']);
            Assert::same('nosniff', $r['headers']['x-content-type-options']);
            Assert::true(!isset($r['headers']['x-powered-by']), 'no X-Powered-By');

            $r = $s->request('GET', '/yap/reporting-api/api/v1/summary?period=30d');
            Assert::same(401, $r['status']);
            Assert::same('Bearer realm="yap-reporting"', $r['headers']['www-authenticate']);
            Assert::same(401, $s->request('GET', '/yap/reporting-api/api/v1/summary', ['Authorization' => 'Bearer yapr_000000000000_' . str_repeat('a', 43)])['status']);

            $r = $s->request('GET', '/yap/reporting-api/api/v1/summary?start_date=2026-09-01&end_date=2026-09-30&timezone=America/New_York&areas=1001', ['Authorization' => 'Bearer ' . $token]);
            Assert::same(200, $r['status']);
            [$a, $b] = $h->window('2026-09-01', '2026-09-30');
            Assert::same($h->scenario->expected([1001], false, $a, $b)['total_calls'], $r['json']['data']['metrics']['total_calls']['value']);
            Assert::same(200, $s->request('GET', '/reporting-api/api/v1/health')['status'], 'any base path');
        } finally {
            $s->stop();
        }
    },

    'requests that are refused' => function (Harness $h) use ($serve): void {
        [$s, $token] = $serve($h);
        try {
            $auth = ['Authorization' => 'Bearer ' . $token];
            Assert::same(400, $s->request('GET', '/yap/reporting-api/api/v1/summary?token=' . $token)['status'], 'credentials in the URL');
            Assert::same('credentials_in_query', $s->request('GET', '/yap/reporting-api/api/v1/summary?access_token=x', $auth)['json']['error']['code']);
            $r = $s->request('POST', '/yap/reporting-api/api/v1/summary', $auth);
            Assert::same(405, $r['status']);
            Assert::same('GET, HEAD', $r['headers']['allow']);
            Assert::same(400, $s->request('GET', '/yap/reporting-api/api/v1/summary?period=forever', $auth)['status']);
            Assert::same(400, $s->request('GET', '/yap/reporting-api/api/v1/summary?areas=1001;DROP', $auth)['status']);
            Assert::same(400, $s->request('GET', '/yap/reporting-api/api/v1/summary?start_date=2024-01-01&end_date=2026-09-30', $auth)['status'], 'longer than the maximum range');
            Assert::same(404, $s->request('GET', '/yap/reporting-api/api/v1/nothing', $auth)['status']);
            Assert::same(403, $s->request('GET', '/yap/reporting-api/api/v1/volunteer-summary', $auth)['status'], 'scope');
            $r = $s->request('HEAD', '/yap/reporting-api/api/v1/health');
            Assert::same(200, $r['status']);
            Assert::same('', $r['body']);
        } finally {
            $s->stop();
        }
    },

    'nothing in the package is served as a file' => function (Harness $h) use ($serve): void {
        [$s] = $serve($h);
        try {
            foreach (['data/clients.json', 'data/secret.key', 'config/reporting-config.example.php', 'src/App.php', 'bin/yapr', 'composer.json', 'README.md'] as $file) {
                $r = $s->request('GET', '/yap/reporting-api/' . $file);
                Assert::same(404, $r['status'], $file);
                Assert::notContains('<?php', $r['body'], $file);
                Assert::notContains('clients', $r['body'], $file);
            }
            Assert::same(404, $s->request('GET', '/yap/reporting-api/setup')['status'], 'setup console is off unless config/ENABLE_SETUP exists');
        } finally {
            $s->stop();
        }
    },

    'plain HTTP is refused unless explicitly allowed from loopback' => function (Harness $h) use ($serve): void {
        [$s, $token] = $serve($h, ['allow_http_from_loopback' => false]);
        try {
            $r = $s->request('GET', '/yap/reporting-api/api/v1/summary', ['Authorization' => 'Bearer ' . $token]);
            Assert::same(403, $r['status']);
            Assert::same('https_required', $r['json']['error']['code']);
            Assert::same(403, $s->request('GET', '/yap/reporting-api/api/v1/health')['status'], 'health too');
        } finally {
            $s->stop();
        }
    },

    'repeated bad credentials are rate limited' => function (Harness $h) use ($serve): void {
        [$s, $token] = $serve($h, ['rate_limit' => ['auth_failures_per_15_minutes' => 3]]);
        try {
            $bad = ['Authorization' => 'Bearer yapr_000000000000_' . str_repeat('b', 43)];
            $codes = [];
            for ($i = 0; $i < 5; $i++) {
                $codes[] = $s->request('GET', '/yap/reporting-api/api/v1/summary', $bad)['status'];
            }
            Assert::same(429, $codes[4], implode(',', $codes));
            Assert::same(429, $s->request('GET', '/yap/reporting-api/api/v1/summary', ['Authorization' => 'Bearer ' . $token])['status'], 'the address is blocked for a while');
        } finally {
            $s->stop();
        }
    },

    'an unreadable configuration fails closed without details' => function (Harness $h) use ($serve): void {
        [$s, $token] = $serve($h, ['database' => ['password' => 'wrong-password-123']]);
        try {
            $r = $s->request('GET', '/yap/reporting-api/api/v1/summary?period=30d', ['Authorization' => 'Bearer ' . $token]);
            Assert::same(500, $r['status']);
            Assert::notContains('wrong-password-123', $r['body']);
            Assert::notContains('SQLSTATE', $r['body']);
            Assert::true(preg_match('/^[0-9a-f]{16}$/', (string) ($r['json']['error']['request_id'] ?? '')) === 1, 'request id for the log');
        } finally {
            $s->stop();
        }
    },
];
