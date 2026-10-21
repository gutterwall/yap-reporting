<?php

declare(strict_types=1);

use YapReporting\Auth\Client;
use YapReporting\Auth\ClientStore;

return [
    'valid token is accepted' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $res = $app->handle(Fixture::request('/api/v1/summary', ['areas' => '10', 'start_date' => '2026-06-10', 'end_date' => '2026-06-10'], $token));
        Assert::same(200, $res->status);
    },
    'missing token is rejected' => function (): void {
        [$app] = Fixture::app();
        Assert::same(401, $app->handle(Fixture::request('/api/v1/summary'))->status);
    },
    'wrong secret is rejected' => function (): void {
        [$app] = Fixture::app();
        [$id] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $forged = ClientStore::formatToken($id, ClientStore::newSecret());
        Assert::same(401, $app->handle(Fixture::request('/api/v1/summary', [], $forged))->status);
    },
    'malformed token is rejected' => function (): void {
        [$app] = Fixture::app();
        Assert::same(401, $app->handle(Fixture::request('/api/v1/summary', [], "yapr_' OR 1=1 --"))->status);
    },
    'revoked token is rejected' => function (): void {
        [$app] = Fixture::app();
        [$id, $token] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $app->clients()->revoke($id);
        Assert::same(401, $app->handle(Fixture::request('/api/v1/summary', [], $token))->status);
    },
    'rotation: old token honoured during grace, new token works' => function (): void {
        [$app] = Fixture::app();
        [$id, $old] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $new = $app->clients()->rotate($id, 3600);
        $q = ['areas' => '10', 'period' => '7d'];
        Assert::same(200, $app->handle(Fixture::request('/api/v1/summary', $q, $new))->status);
        Assert::same(200, $app->handle(Fixture::request('/api/v1/summary', $q, $old))->status);
    },
    'rotation without grace invalidates old token' => function (): void {
        [$app] = Fixture::app();
        [$id, $old] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $app->clients()->rotate($id, 0);
        Assert::same(401, $app->handle(Fixture::request('/api/v1/summary', [], $old))->status);
    },
    'secrets are stored only as HMAC' => function (): void {
        [$app, $dir] = Fixture::app();
        [, $token] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $secret = explode('_', $token, 3)[2];
        Assert::notContains($secret, (string) file_get_contents($dir . '/clients.json'));
    },
    'token in query string is refused' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $res = $app->handle(Fixture::request('/api/v1/summary', ['token' => $token], $token));
        Assert::same(400, $res->status);
    },
    'plain HTTP is refused' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], []);
        Assert::same(403, $app->handle(Fixture::request('/api/v1/summary', [], $token, false))->status);
    },
    'IP allowlist is enforced' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('WP', false, [10], false, [Client::SCOPE_SUMMARY], ['198.51.100.0/24']);
        Assert::same(403, $app->handle(Fixture::request('/api/v1/summary', [], $token))->status);
    },
    'non-GET methods are refused' => function (): void {
        [$app] = Fixture::app();
        $req = new \YapReporting\Http\Request('POST', '/api/v1/summary', [], [], true, '203.0.113.5', 't');
        Assert::same(405, $app->handle($req)->status);
    },
    'health is public and minimal' => function (): void {
        [$app] = Fixture::app();
        $res = $app->handle(Fixture::request('/api/v1/health'));
        Assert::same(200, $res->status);
        Assert::same(['status', 'service', 'api_version'], array_keys($res->body));
    },
];
