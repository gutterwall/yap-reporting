<?php

declare(strict_types=1);

namespace YapReporting\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use YapReporting\Http\HttpException;

/**
 * A half-open reporting interval [start, end) expressed in the reporting timezone.
 * Boundaries are always local midnights, so DST transitions produce correct 23h / 25h days.
 *
 * Rolling periods ("7d", "30d" …) include today: 7d = the 6 previous whole days plus today so far.
 */
final class DateRange
{
    public const PERIODS = [
        'today', 'yesterday', '7d', '30d', '45d', '60d', '90d',
        'this_week', 'last_week', 'this_month', 'last_month',
        'this_quarter', 'last_quarter', 'this_year', 'last_year', 'custom',
    ];

    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly DateTimeZone $timezone,
        public readonly string $period,
    ) {
        if ($end <= $start) {
            throw new HttpException(400, 'invalid_range', 'end_date must be on or after start_date.');
        }
    }

    public static function resolve(
        ?string $period,
        ?DateTimeImmutable $startDate,
        ?DateTimeImmutable $endDate,
        DateTimeZone $tz,
        string $weekStartsOn,
        int $maxDays,
        ?DateTimeImmutable $now = null,
    ): self {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
        $today = $now->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');

        if ($startDate !== null || $endDate !== null) {
            if ($startDate === null || $endDate === null) {
                throw new HttpException(400, 'invalid_range', 'Both start_date and end_date are required for a custom range.');
            }
            $start = new DateTimeImmutable($startDate->format('Y-m-d') . ' 00:00:00', $tz);
            // end_date is inclusive for callers; internally the range ends at the following midnight.
            $end = (new DateTimeImmutable($endDate->format('Y-m-d') . ' 00:00:00', $tz))->modify('+1 day');
            $period = 'custom';
        } else {
            $period = $period ?? '30d';
            [$start, $end] = match ($period) {
                'today' => [$today, $tomorrow],
                'yesterday' => [$today->modify('-1 day'), $today],
                '7d', '30d', '45d', '60d', '90d' => [$today->modify('-' . ((int) $period - 1) . ' days'), $tomorrow],
                'this_week' => [self::weekStart($today, $weekStartsOn), $tomorrow],
                'last_week' => [self::weekStart($today, $weekStartsOn)->modify('-7 days'), self::weekStart($today, $weekStartsOn)],
                'this_month' => [$today->modify('first day of this month'), $tomorrow],
                'last_month' => [$today->modify('first day of last month'), $today->modify('first day of this month')],
                'this_quarter' => [self::quarterStart($today), $tomorrow],
                'last_quarter' => [self::quarterStart($today)->modify('-3 months'), self::quarterStart($today)],
                'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1), $tomorrow],
                'last_year' => [$today->setDate((int) $today->format('Y') - 1, 1, 1), $today->setDate((int) $today->format('Y'), 1, 1)],
                default => throw new HttpException(400, 'invalid_parameter', 'Unknown period. Use one of: ' . implode(', ', self::PERIODS)),
            };
        }

        $range = new self($start, $end, $tz, $period);
        if ($range->days() > $maxDays) {
            throw new HttpException(400, 'range_too_large', sprintf('The maximum reporting range is %d days.', $maxDays));
        }
        return $range;
    }

    /** The immediately preceding interval of equal calendar length (for comparisons). */
    public function previous(): self
    {
        $days = $this->days();
        $prevStart = $this->start->modify('-' . $days . ' days');
        return new self($prevStart, $this->start, $this->timezone, 'previous');
    }

    public function days(): int
    {
        // Count local calendar days, independent of DST hour shifts.
        return (int) $this->start->setTime(0, 0)->diff($this->end->setTime(0, 0))->days;
    }

    public function startTs(): int
    {
        return $this->start->getTimestamp();
    }

    public function endTs(): int
    {
        return $this->end->getTimestamp();
    }

    public function contains(int $ts): bool
    {
        return $ts >= $this->startTs() && $ts < $this->endTs();
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'period' => $this->period,
            'start_date' => $this->start->format('Y-m-d'),
            'end_date' => $this->end->modify('-1 day')->format('Y-m-d'),
            'start' => $this->start->format(DATE_ATOM),
            'end_exclusive' => $this->end->format(DATE_ATOM),
            'timezone' => $this->timezone->getName(),
            'days' => $this->days(),
        ];
    }

    public static function weekStart(DateTimeImmutable $day, string $weekStartsOn): DateTimeImmutable
    {
        $dow = (int) $day->format('w'); // 0 = Sunday
        $first = $weekStartsOn === 'monday' ? 1 : 0;
        $back = ($dow - $first + 7) % 7;
        return $day->modify('-' . $back . ' days')->setTime(0, 0);
    }

    public static function quarterStart(DateTimeImmutable $day): DateTimeImmutable
    {
        $month = (int) $day->format('n');
        $qMonth = intdiv($month - 1, 3) * 3 + 1;
        return $day->setDate((int) $day->format('Y'), $qMonth, 1)->setTime(0, 0);
    }
}
