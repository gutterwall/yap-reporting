<?php

declare(strict_types=1);

use YapReporting\Http\HttpException;
use YapReporting\Reporting\DateRange;

$ny = new DateTimeZone('America/New_York');
$now = new DateTimeImmutable('2026-03-15 12:00:00', $ny);

return [
    'spring-forward day is 23 hours' => function () use ($ny): void {
        $d = new DateTimeImmutable('2026-03-08', new DateTimeZone('UTC'));
        $r = DateRange::resolve(null, $d, $d, $ny, 'sunday', 400);
        Assert::same(23 * 3600, $r->endTs() - $r->startTs());
        Assert::same(1, $r->days());
    },
    'fall-back day is 25 hours' => function () use ($ny): void {
        $d = new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC'));
        $r = DateRange::resolve(null, $d, $d, $ny, 'sunday', 400);
        Assert::same(25 * 3600, $r->endTs() - $r->startTs());
    },
    '7d includes today and six previous days' => function () use ($ny, $now): void {
        $r = DateRange::resolve('7d', null, null, $ny, 'sunday', 400, $now);
        Assert::same('2026-03-09', $r->start->format('Y-m-d'));
        Assert::same('2026-03-16', $r->end->format('Y-m-d'));
        Assert::same(7, $r->days());
    },
    'last_month and last_quarter' => function () use ($ny, $now): void {
        $m = DateRange::resolve('last_month', null, null, $ny, 'sunday', 400, $now);
        Assert::same('2026-02-01', $m->start->format('Y-m-d'));
        Assert::same('2026-03-01', $m->end->format('Y-m-d'));
        $q = DateRange::resolve('last_quarter', null, null, $ny, 'sunday', 400, $now);
        Assert::same('2025-10-01', $q->start->format('Y-m-d'));
        Assert::same('2026-01-01', $q->end->format('Y-m-d'));
    },
    'week start is configurable' => function () use ($ny, $now): void {
        Assert::same('2026-03-15', DateRange::resolve('this_week', null, null, $ny, 'sunday', 400, $now)->start->format('Y-m-d'));
        Assert::same('2026-03-09', DateRange::resolve('this_week', null, null, $ny, 'monday', 400, $now)->start->format('Y-m-d'));
    },
    'previous period has equal length' => function () use ($ny, $now): void {
        $r = DateRange::resolve('30d', null, null, $ny, 'sunday', 400, $now);
        Assert::same(30, $r->previous()->days());
        Assert::same($r->startTs(), $r->previous()->endTs());
    },
    'range limit enforced' => function () use ($ny): void {
        $a = new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));
        Assert::throws(fn () => DateRange::resolve(null, $a, $b, $ny, 'sunday', 400), HttpException::class, 400);
    },
];
