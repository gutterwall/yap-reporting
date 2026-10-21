<?php

declare(strict_types=1);

use YapReporting\Auth\Client;

return [
    'client cannot query an unauthorized Area' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Area 10 site', false, [10], false, Client::ALL_SCOPES, []);
        $res = $app->handle(Fixture::request('/api/v1/summary', ['areas' => '20'], $token));
        Assert::same(403, $res->status);
        $res = $app->handle(Fixture::request('/api/v1/summary', ['areas' => '10,20'], $token));
        Assert::same(403, $res->status);
        $res = $app->handle(Fixture::request('/api/v1/area-dashboard', ['area_id' => '20'], $token));
        Assert::same(403, $res->status);
    },
    'unassigned activity requires explicit permission' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Area 10 site', false, [10], false, [Client::SCOPE_SUMMARY], []);
        $res = $app->handle(Fixture::request('/api/v1/summary', ['include_unassigned' => 'true'], $token));
        Assert::same(403, $res->status);
    },
    'missing scope is refused' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Public dashboard', false, [10], false, [Client::SCOPE_SUMMARY], []);
        Assert::same(403, $app->handle(Fixture::request('/api/v1/calls', ['areas' => '10'], $token))->status);
        Assert::same(403, $app->handle(Fixture::request('/api/v1/volunteer-summary', ['areas' => '10'], $token))->status);
    },
    'call detail from another Area is indistinguishable from not found' => function (): void {
        [$app] = Fixture::app();
        [, $all] = $app->clients()->create('Region', true, [], true, Client::ALL_SCOPES, []);
        [, $area10] = $app->clients()->create('Area 10', false, [10], false, Client::ALL_SCOPES, []);
        $list = $app->handle(Fixture::request('/api/v1/calls', ['areas' => '20', 'start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'timezone' => 'UTC'], $all));
        Assert::same(200, $list->status);
        $id = $list->body['data']['calls'][0]['id'];
        $res = $app->handle(Fixture::request('/api/v1/calls/' . $id, ['timezone' => 'UTC'], $area10));
        Assert::same(404, $res->status);
        $res = $app->handle(Fixture::request('/api/v1/calls/c_20260610_00000000000000000000', ['timezone' => 'UTC'], $area10));
        Assert::same(404, $res->status);
    },
    'SQL injection attempts are rejected by validation' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('All', true, [], true, Client::ALL_SCOPES, []);
        foreach ([
            ['areas' => "10 OR 1=1"],
            ['areas' => "10;DROP TABLE records"],
            ['start_date' => "2026-06-10' --", 'end_date' => '2026-06-10'],
            ['sort' => 'start_time; DELETE FROM records'],
            ['timezone' => "UTC'"],
            ['period' => '30d UNION SELECT'],
            ['unexpected' => '1'],
        ] as $q) {
            $res = $app->handle(Fixture::request('/api/v1/calls', $q, $token));
            Assert::same(400, $res->status, json_encode($q));
        }
    },
    'excessive ranges and page sizes are rejected' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('All', true, [], true, Client::ALL_SCOPES, []);
        Assert::same(400, $app->handle(Fixture::request('/api/v1/summary', ['start_date' => '2020-01-01', 'end_date' => '2026-01-01'], $token))->status);
        Assert::same(400, $app->handle(Fixture::request('/api/v1/calls', ['per_page' => '5000'], $token))->status);
        Assert::same(400, $app->handle(Fixture::request('/api/v1/summary', ['start_date' => '2026-06-10'], $token))->status);
        Assert::same(400, $app->handle(Fixture::request('/api/v1/summary', ['start_date' => '2026-06-12', 'end_date' => '2026-06-10'], $token))->status);
    },
];
