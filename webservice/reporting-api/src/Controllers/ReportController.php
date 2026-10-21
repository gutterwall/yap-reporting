<?php

declare(strict_types=1);

namespace YapReporting\Controllers;

use DateTimeImmutable;
use YapReporting\App;
use YapReporting\Auth\Client;
use YapReporting\Http\HttpException;
use YapReporting\Http\Request;
use YapReporting\Http\Response;
use YapReporting\Privacy\Pseudonymizer;
use YapReporting\Reporting\AreaFilter;
use YapReporting\Reporting\CallPresenter;
use YapReporting\Reporting\DateRange;
use YapReporting\Reporting\MetricEngine;
use YapReporting\Validation\Params;

final class ReportController
{
    private const COMMON = ['areas', 'area_id', 'include_unassigned', 'period', 'start_date', 'end_date', 'timezone'];

    /** @var array<string, array<string, mixed>> per-request dataset memo, keyed by range */
    private array $datasets = [];

    public function __construct(private readonly App $app)
    {
    }

    // ---- Public ---------------------------------------------------------------------------

    /** @param array<string, string> $route */
    public function health(Request $r, ?Client $c, array $route): Response
    {
        Params::from($r->query, []);
        return Response::json(['status' => 'ok', 'service' => 'yap-reporting', 'api_version' => App::API_VERSION]);
    }

    // ---- Aggregate reports (scope: summary) ----------------------------------------------

    /** @param array<string, string> $route */
    public function capabilities(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        Params::from($r->query, []);
        $catalog = $this->app->catalog();
        return Response::json([
            'data' => [
                'service_version' => App::VERSION,
                'api_version' => App::API_VERSION,
                'client' => $c->describe(),
                'yap' => [
                    'event_catalog_source' => $catalog->source,
                    'event_names' => array_keys($catalog->all()),
                    'schema' => $this->app->schema()->describe(),
                ],
                'metrics' => $this->app->capabilities()->matrix(),
                'definitions' => self::definitions(\YapReporting\Reporting\DatasetLoader::answeredDefinition($this->app->config())),
                'limits' => $this->app->config()->get('limits'),
                'periods' => DateRange::PERIODS,
                'granularities' => ['day', 'week', 'month', 'quarter', 'year'],
                'attribution_policy' => $this->app->config()->get('attribution.policy'),
            ],
        ]);
    }

    /** @param array<string, string> $route */
    public function areas(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, [], '90d');
        $data = $this->cached($c, 'areas', $p, $filter, $range, function () use ($filter, $range): array {
            $ds = $this->dataset($range);
            $counts = [];
            $bump = static function (array &$counts, ?int $sb, string $field): void {
                $k = $sb === null || $sb <= 0 ? 'unassigned' : (string) $sb;
                $counts[$k][$field] = ($counts[$k][$field] ?? 0) + 1;
            };
            foreach ($ds['sessions'] as $s) {
                if ($filter->allows($s['sb'])) {
                    $bump($counts, $s['sb'], 'calls');
                }
                foreach ($s['interactions'] as $i) {
                    if ($filter->allows($i['sb'])) {
                        $bump($counts, $i['sb'], 'automated_interactions');
                    }
                }
            }
            foreach ($ds['sms'] as $e) {
                if ($filter->allows($e['sb'])) {
                    $bump($counts, $e['sb'], 'sms_events');
                }
            }
            if ($filter->ids !== null) {
                foreach ($filter->ids as $id) {
                    $counts[(string) $id] ??= [];
                }
            }
            $out = [];
            foreach ($counts as $k => $v) {
                $out[] = [
                    'service_body_id' => $k === 'unassigned' ? null : (int) $k,
                    'calls' => $v['calls'] ?? 0,
                    'automated_interactions' => $v['automated_interactions'] ?? 0,
                    'sms_events' => $v['sms_events'] ?? 0,
                ];
            }
            usort($out, static fn ($a, $b) => [$a['service_body_id'] === null, $a['service_body_id']] <=> [$b['service_body_id'] === null, $b['service_body_id']]);
            return ['service_bodies' => $out, 'note' => 'Names are not stored in YAP; resolve service_body_id against your BMLT root server.'];
        });
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function summary(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, ['compare', 'group_by']);
        $compare = (bool) $p->bool('compare', false);
        $groupBy = $p->enum('group_by', ['service_body'], null);

        $data = $this->cached($c, 'summary', $p, $filter, $range, function () use ($filter, $range, $compare, $groupBy): array {
            $engine = $this->engine($range, $filter);
            $out = ['metrics' => $engine->summary(), 'attribution' => $engine->attributionReport()];
            if ($compare) {
                $prev = $range->previous();
                $out['previous_period'] = ['range' => $prev->describe(), 'metrics' => $this->engine($prev, $filter)->summary()];
            }
            if ($groupBy === 'service_body') {
                $out['by_service_body'] = $this->bySb($range, $filter);
            }
            return $out;
        });
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function trends(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, ['granularity', 'compare']);
        $granularity = (string) $p->enum('granularity', ['day', 'week', 'month', 'quarter', 'year'], 'day');
        if ($granularity === 'day' && $range->days() > 400) {
            throw new HttpException(400, 'invalid_parameter', 'Use weekly or monthly granularity for ranges this long.');
        }
        $compare = (bool) $p->bool('compare', false);
        $data = $this->cached($c, 'trends', $p, $filter, $range, function () use ($filter, $range, $granularity, $compare): array {
            $out = $this->engine($range, $filter)->trends($granularity);
            if ($compare) {
                $prev = $range->previous();
                $out['previous_period'] = ['range' => $prev->describe()] + $this->engine($prev, $filter)->trends($granularity);
            }
            return $out;
        });
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function distributions(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, []);
        $data = $this->cached($c, 'distributions', $p, $filter, $range, fn (): array => $this->engine($range, $filter)->distributions());
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function areaDashboard(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, ['compare']);
        $compare = (bool) $p->bool('compare', false);
        $data = $this->cached($c, 'area-dashboard', $p, $filter, $range, function () use ($filter, $range, $compare): array {
            $out = ['current' => $this->engine($range, $filter)->areaDashboard($this->installationEngine($range))];
            if ($compare) {
                $prev = $range->previous();
                $out['previous'] = $this->engine($prev, $filter)->areaDashboard($this->installationEngine($prev));
                $out['previous_range'] = $prev->describe();
            }
            return $out;
        });
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function meetingLookups(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, []);
        $data = $this->cached($c, 'meeting-lookups', $p, $filter, $range, function () use ($filter, $range): array {
            $engine = $this->engine($range, $filter);
            return $engine->meetingLookups() + ['trend' => $engine->trends($range->days() > 120 ? 'week' : 'day')];
        });
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function meditations(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, []);
        $data = $this->cached($c, 'meditations', $p, $filter, $range, fn (): array => $this->engine($range, $filter)->meditations($this->installationEngine($range)));
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function smsSummary(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_SUMMARY);
        [$p, $filter, $range] = $this->context($r, $c, []);
        $data = $this->cached($c, 'sms-summary', $p, $filter, $range, fn (): array => $this->engine($range, $filter)->smsSummary());
        return $this->respond($data, $range, $filter);
    }

    // ---- Call detail (scope: call_details) -------------------------------------------------

    /** @param array<string, string> $route */
    public function calls(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_CALL_DETAILS);
        $limits = (array) $this->app->config()->get('limits');
        [$p, $filter, $range] = $this->context($r, $c, [
            'category', 'outcome', 'answered', 'voicemail', 'volunteer_id', 'min_duration', 'max_duration', 'sort', 'page', 'per_page',
        ]);
        $category = $p->enum('category', ['volunteer', 'voicemail', 'meeting_lookup', 'meditation', 'dialback', 'menu_only']);
        $outcome = $p->enum('outcome', ['answered', 'voicemail', 'connected_short', 'connected_unknown_duration', 'not_answered', 'outcome_not_recorded', 'no_volunteer_response_logged', 'forwarded', 'not_routed', 'automated', 'dialback', 'ended_before_selection']);
        $answered = $p->bool('answered');
        $voicemail = $p->bool('voicemail');
        $volunteerId = $p->pattern('volunteer_id', '/^v_[0-9a-f]{16}$/', 'volunteer_id is not valid.');
        if ($volunteerId !== null) {
            $this->requireScope($c, Client::SCOPE_VOLUNTEER_STATS);
        }
        $minDur = $p->int('min_duration', null, 0, 86400);
        $maxDur = $p->int('max_duration', null, 0, 86400);
        $sort = $p->enum('sort', ['asc', 'desc'], 'desc');
        $page = (int) $p->int('page', 1, 1, 100000);
        $perPage = (int) $p->int('per_page', (int) ($limits['default_per_page'] ?? 50), 1, (int) ($limits['max_per_page'] ?? 200));

        $data = $this->uncached(function () use ($c, $filter, $range, $category, $outcome, $answered, $voicemail, $volunteerId, $minDur, $maxDur, $sort, $page, $perPage): array {
            $engine = $this->engine($range, $filter);
            $pseudo = $this->app->pseudonymizer();
            $rows = array_filter($engine->calls(), static function (array $s) use ($category, $outcome, $answered, $voicemail, $volunteerId, $minDur, $maxDur, $pseudo): bool {
                if ($category !== null && $s['category'] !== $category) {
                    return false;
                }
                if ($outcome !== null && $s['outcome'] !== $outcome) {
                    return false;
                }
                if ($answered !== null && $s['answered'] !== $answered) {
                    return false;
                }
                if ($voicemail !== null && $s['voicemail'] !== $voicemail) {
                    return false;
                }
                if ($volunteerId !== null && ($s['answered_by'] === null || $pseudo->volunteerId($s['answered_by']) !== $volunteerId)) {
                    return false;
                }
                if ($minDur !== null && ($s['dur'] === null || $s['dur'] < $minDur)) {
                    return false;
                }
                if ($maxDur !== null && ($s['dur'] === null || $s['dur'] > $maxDur)) {
                    return false;
                }
                return true;
            });
            $rows = array_values($rows);
            if ($sort === 'desc') {
                $rows = array_reverse($rows);
            }
            $total = count($rows);
            $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
            $presenter = $this->presenter($c, $range);
            return [
                'calls' => array_map([$presenter, 'summary'], $slice),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int) max(1, ceil($total / $perPage)),
                ],
            ];
        });
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function callDetail(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_CALL_DETAILS);
        $p = Params::from($r->query, ['timezone', 'include_unassigned']);
        $parsed = Pseudonymizer::parseCallId($route['id'] ?? '');
        if ($parsed === null) {
            throw new HttpException(404, 'not_found', 'Call not found.');
        }
        $tz = $p->timezone('timezone', (string) $this->app->config()->get('default_timezone'));
        $day = DateTimeImmutable::createFromFormat('!Ymd', $parsed[0], $tz);
        if ($day === false) {
            throw new HttpException(404, 'not_found', 'Call not found.');
        }
        $range = new DateRange($day->modify('-1 day'), $day->modify('+2 days'), $tz, 'custom');
        $filter = AreaFilter::forClient($c, null, $p->bool('include_unassigned'));
        $presenter = $this->presenter($c, $range);
        foreach ($this->engine($range, $filter)->calls() as $s) {
            $detail = $presenter->summary($s);
            if (hash_equals($detail['id'], (string) $route['id'])) {
                return $this->respond(['call' => $presenter->detail($s)], $range, $filter);
            }
        }
        // Identical response whether the call does not exist or belongs to another Area.
        throw new HttpException(404, 'not_found', 'Call not found.');
    }

    // ---- Volunteers (scope: volunteer_stats) ----------------------------------------------

    /** @param array<string, string> $route */
    public function volunteers(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_VOLUNTEER_STATS);
        [$p, $filter, $range] = $this->context($r, $c, []);
        $data = $this->uncached(function () use ($c, $filter, $range): array {
            $summary = $this->volunteerData($c, $filter, $range);
            $list = array_map(static fn (array $v): array => array_intersect_key($v, array_flip(['volunteer_id', 'name', 'last_activity_at'])), $summary['volunteers']);
            return ['available' => $summary['available'], 'reason' => $summary['reason'] ?? null, 'volunteers' => $list];
        });
        return $this->respond($data, $range, $filter);
    }

    /** @param array<string, string> $route */
    public function volunteerSummary(Request $r, Client $c, array $route): Response
    {
        $this->requireScope($c, Client::SCOPE_VOLUNTEER_STATS);
        [$p, $filter, $range] = $this->context($r, $c, ['sort']);
        $sort = (string) $p->enum('sort', ['answered_calls', 'answer_rate', 'total_conversation_seconds', 'routed_attempts'], 'answered_calls');
        $data = $this->uncached(function () use ($c, $filter, $range, $sort): array {
            $summary = $this->volunteerData($c, $filter, $range);
            usort($summary['volunteers'], static fn ($a, $b) => ($b[$sort] ?? -1) <=> ($a[$sort] ?? -1));
            return $summary;
        });
        return $this->respond($data, $range, $filter);
    }

    // ---- Helpers --------------------------------------------------------------------------

    /**
     * @param string[] $extra
     * @return array{0: Params, 1: AreaFilter, 2: DateRange}
     */
    private function context(Request $r, Client $c, array $extra, string $defaultPeriod = '30d'): array
    {
        $config = $this->app->config();
        $p = Params::from($r->query, array_merge(self::COMMON, $extra));
        $maxAreas = $config->int('limits.max_areas', 50);
        $areas = $p->idList('areas', $maxAreas) ?? $p->idList('area_id', 1);
        $filter = AreaFilter::forClient($c, $areas, $p->bool('include_unassigned'));
        $period = $p->enum('period', DateRange::PERIODS, null);
        $start = $p->date('start_date');
        $end = $p->date('end_date');
        if ($period === 'custom' && ($start === null || $end === null)) {
            throw new HttpException(400, 'invalid_range', 'period=custom requires start_date and end_date.');
        }
        if ($period !== null && $period !== 'custom' && ($start !== null || $end !== null)) {
            throw new HttpException(400, 'invalid_range', 'Send either period or start_date/end_date, not both.');
        }
        $range = DateRange::resolve(
            $period === 'custom' ? null : ($period ?? ($start === null && $end === null ? $defaultPeriod : null)),
            $start,
            $end,
            $p->timezone('timezone', (string) $config->get('default_timezone')),
            (string) $config->get('week_starts_on', 'sunday'),
            $config->int('limits.max_range_days', 400),
        );
        return [$p, $filter, $range];
    }

    private function requireScope(Client $c, string $scope): void
    {
        if (!$c->hasScope($scope)) {
            throw new HttpException(403, 'insufficient_scope', 'This client is not permitted to use this endpoint.');
        }
    }

    /** @return array<string, mixed> */
    private function dataset(DateRange $range): array
    {
        $key = $range->startTs() . ':' . $range->endTs();
        return $this->datasets[$key] ??= $this->app->loader()->load($range);
    }

    private function engine(DateRange $range, AreaFilter $filter): MetricEngine
    {
        return new MetricEngine($this->dataset($range), $filter, $this->app->capabilities(), $range, (string) $this->app->config()->get('week_starts_on', 'sunday'));
    }

    private function installationEngine(DateRange $range): ?MetricEngine
    {
        if (!(bool) $this->app->config()->get('expose_installation_meditation_totals', true)) {
            return null;
        }
        return $this->engine($range, new AreaFilter(null, true));
    }

    /** @return array<int, array<string, mixed>> */
    private function bySb(DateRange $range, AreaFilter $filter): array
    {
        $ids = $filter->ids;
        if ($ids === null) {
            $ids = [];
            foreach ($this->dataset($range)['sessions'] as $s) {
                if ($s['sb'] !== null && $s['sb'] > 0) {
                    $ids[$s['sb']] = true;
                }
            }
            $ids = array_keys($ids);
            sort($ids);
        }
        $out = [];
        foreach (array_slice($ids, 0, $this->app->config()->int('limits.max_areas', 50)) as $id) {
            $out[] = ['service_body_id' => $id, 'metrics' => $this->engine($range, new AreaFilter([(int) $id], false))->summary()];
        }
        if ($filter->includeUnassigned) {
            $out[] = ['service_body_id' => null, 'metrics' => $this->engine($range, new AreaFilter([], true))->summary()];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function volunteerData(Client $c, AreaFilter $filter, DateRange $range): array
    {
        $pseudo = $this->app->pseudonymizer();
        $nameFor = null;
        if ($c->hasScope(Client::SCOPE_VOLUNTEER_NAMES) && $this->app->volunteerDirectory()->available()) {
            $dir = $this->app->volunteerDirectory();
            $nameFor = static fn (string $n): ?string => $dir->nameFor($n);
        }
        return $this->engine($range, $filter)->volunteerSummary(static fn (string $n): string => $pseudo->volunteerId($n), $nameFor);
    }

    private function presenter(Client $c, DateRange $range): CallPresenter
    {
        $showVolunteers = $c->hasScope(Client::SCOPE_VOLUNTEER_STATS);
        $nameFor = null;
        if ($showVolunteers && $c->hasScope(Client::SCOPE_VOLUNTEER_NAMES) && $this->app->volunteerDirectory()->available()) {
            $dir = $this->app->volunteerDirectory();
            $nameFor = static fn (string $n): ?string => $dir->nameFor($n);
        }
        return new CallPresenter($this->app->pseudonymizer(), $range->timezone, $showVolunteers, $nameFor);
    }

    /**
     * Cache computed payloads. The key always includes the client's scope fingerprint and the
     * resolved Area filter, so one client's cached result can never be served to another.
     *
     * @param callable(): array<string, mixed> $producer
     * @return array<string, mixed>
     */
    private function cached(Client $c, string $endpoint, Params $p, AreaFilter $filter, DateRange $range, callable $producer): array
    {
        $key = implode('|', [
            'v1', $endpoint, $c->scopeFingerprint(), $filter->key(),
            $range->startTs(), $range->endTs(), $range->timezone->getName(),
            $this->app->config()->int('answered_min_seconds', 5),
            \YapReporting\Reporting\DatasetLoader::answeredRule($this->app->config()),
            hash('sha256', (string) json_encode($this->queryForKey($p))),
        ]);
        return $this->app->cache()->remember($key, $producer);
    }

    /** @return array<string, mixed> */
    private function queryForKey(Params $p): array
    {
        return $p->all();
    }

    /**
     * Person-level results (individual calls, volunteer statistics) are never written to the disk cache.
     *
     * @param callable(): array<string, mixed> $producer
     * @return array<string, mixed>
     */
    private function uncached(callable $producer): array
    {
        return $producer();
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data, DateRange $range, AreaFilter $filter): Response
    {
        $ttl = $this->app->config()->int('cache_ttl_seconds', 60);
        return Response::json([
            'data' => $data,
            'meta' => [
                'generated_at' => gmdate(DATE_ATOM),
                'range' => $range->describe(),
                'service_bodies' => $filter->describe(),
                'answered_definition' => \YapReporting\Reporting\DatasetLoader::answeredDefinition($this->app->config()),
                'cache_ttl_seconds' => $ttl,
                'api_version' => App::API_VERSION,
            ],
        ]);
    }

    /** @return array<string, string> */
    public static function definitions(string $answered): array
    {
        return [
            'total_calls' => 'Unique incoming calls. Volunteer legs linked by conference are folded into the caller\'s call.',
            'volunteer_requested_calls' => 'Calls where the caller selected the volunteer option or YAP began helpline routing.',
            'volunteer_routed_calls' => 'Calls for which YAP dialed at least one volunteer.',
            'answered_calls' => $answered . ' Ringing, declining, voicemail, or a volunteer who picked up after the caller hung up do not count.',
            'connected_too_short_calls / connected_unknown_duration_calls' => 'Only used when answered_rule is min_seconds.',
            'answer_rate' => 'answered_calls / (volunteer_routed_calls − routed_outcome_not_recorded_calls).',
            'routed_outcome_not_recorded_calls' => 'Volunteers were dialed but YAP logged nothing afterwards (no pick-up, accept, decline, no-answer, busy) and the call did not go to voicemail. Neither answered nor missed.',
            'no_volunteer_response_logged_calls' => 'Volunteers were dialed and the caller waited in the conference, but YAP logged no response from any volunteer phone and no volunteer joined. Counted as not answered; not held against the volunteers. Usually means YAP\'s volunteer webhooks are failing.',
            'answered_from_conference_record' => 'Answered calls where YAP logged no accept event but a volunteer line joined the caller\'s conference.',
            'missed_rate' => 'routed_not_answered_calls / volunteer_routed_calls.',
            'voicemail_rate' => 'volunteer_calls_to_voicemail / volunteer_requested_calls.',
            'abandoned_volunteer_calls' => 'Caller asked for a volunteer, was never connected, did not leave voicemail, and was not forwarded to an outside helpline.',
            'forwarded_to_helpline_calls' => 'Calls YAP forwarded to an outside helpline number (HELPLINE_ROUTE with no volunteer dialed). Not counted as routed, answered or missed.',
            'ended_before_selection_calls' => 'Calls with no volunteer, meeting, meditation, voicemail or dialback activity.',
            'volunteer_dial_attempts' => 'Individual volunteer dial attempts (attempt level).',
            'attempts_busy / attempts_bad_number' => 'Dial attempts YAP recorded as a busy line or a failed number (attempt level).',
            'volunteer_requested_calls (force dial)' => 'Includes calls YAP sent straight to volunteers (VOLUNTEER_SEARCH_FORCE_DIALED).',
            'meeting_lookup_calls' => 'Unique calls containing at least one meeting search.',
            'meeting_lookup_actions' => 'Individual meeting searches on calls (a call may search more than once).',
            'sms_meeting_lookups' => 'Inbound SMS meeting search requests.',
            'jft_calls / spad_calls' => 'Unique calls that played the meditation.',
            'area attribution' => 'Calls: service body on the last routing event (configurable). Automated interactions and SMS: service body on the event itself, or line mapping.',
        ];
    }
}
