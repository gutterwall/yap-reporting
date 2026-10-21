<?php

declare(strict_types=1);

use YapReporting\Auth\Client;

return [
    'no caller numbers, CallSids or recording URLs in any response' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $q = ['start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'timezone' => 'UTC'];
        $bodies = '';
        foreach (['/api/v1/summary', '/api/v1/calls', '/api/v1/volunteer-summary', '/api/v1/volunteers', '/api/v1/meeting-lookups',
                     '/api/v1/meditations', '/api/v1/sms-summary', '/api/v1/trends', '/api/v1/area-dashboard', '/api/v1/areas',
                     '/api/v1/distributions'] as $path) {
            $res = $app->handle(Fixture::request($path, $q, $token));
            Assert::same(200, $res->status, $path . ' ' . $res->encode());
            $bodies .= $res->encode();
        }
        $list = $app->handle(Fixture::request('/api/v1/calls', $q, $token));
        foreach ($list->body['data']['calls'] as $call) {
            $bodies .= $app->handle(Fixture::request('/api/v1/calls/' . $call['id'], ['timezone' => 'UTC'], $token))->encode();
        }
        foreach (['5555550123', '555-0123', '5555550171', '5555550172', '5555550100', 'CA_ANS', 'CA_VM', 'SM_1', 'Recordings', 'RE0123', 'from_number', 'CA_LINK', 'CA_FORCE', 'CA_FWD', '5555550199', '5555550173', 'CA_GRP', 'fixturehash', 'Admin Example', 'Deleted Example'] as $needle) {
            Assert::notContains($needle, $bodies, 'leak');
        }
    },
    'volunteer names only with volunteer_names scope' => function (): void {
        [$app] = Fixture::app();
        [, $without] = $app->clients()->create('Stats', true, [], true, [Client::SCOPE_SUMMARY, Client::SCOPE_VOLUNTEER_STATS], []);
        [, $with] = $app->clients()->create('Admin', true, [], true, Client::ALL_SCOPES, []);
        $q = ['start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'timezone' => 'UTC'];
        Assert::notContains('Blair Example', $app->handle(Fixture::request('/api/v1/volunteer-summary', $q, $without))->encode());
        Assert::true(str_contains($app->handle(Fixture::request('/api/v1/volunteer-summary', $q, $with))->encode(), 'Blair Example'));
    },
    'unavailable metrics are null with a reason, not zero' => function (): void {
        [$app] = Fixture::app();
        [, $token] = $app->clients()->create('All', true, [], true, Client::ALL_SCOPES, []);
        $res = $app->handle(Fixture::request('/api/v1/summary', ['start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'timezone' => 'UTC'], $token));
        $m = $res->body['data']['metrics'];
        // The fixture catalog defines no SPAD_LOOKUP_SMS event.
        Assert::same(false, $m['spad_sms']['available']);
        Assert::same(null, $m['spad_sms']['value']);
        Assert::true(is_string($m['spad_sms']['reason']));
    },
];
