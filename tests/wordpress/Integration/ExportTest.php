<?php

declare(strict_types=1);

namespace YAPR\Tests\Integration;

use YAPR\Export;
use YAPR\Settings;
use YAPR\Tests\FakeService;
use YAPR\Tests\TestCase;

/** CSV and printable exports: the same checks and figures as the dashboards. */
final class ExportTest extends TestCase
{
    /** @return array<int, array<int, string>> */
    private static function csv(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? '';
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }
        return $rows;
    }

    public function test_summary_export_has_the_dashboard_figures(): void
    {
        wp_set_current_user(self::$reporter);
        [$from, $to] = self::lastDays(30);
        $doc = Export::document(['type' => 'summary', 'areas' => '1001', 'start_date' => $from, 'end_date' => $to]);
        $this->assertSame('text/csv; charset=utf-8', $doc['content_type']);
        $this->assertMatchesRegularExpression('/^yap-summary-\d{8}-\d{6}\.csv$/', $doc['filename']);
        $byMetric = [];
        foreach (self::csv($doc['body']) as $row) {
            if (isset($row[1]) && preg_match('/^[a-z_]+$/', $row[0]) === 1) {
                $byMetric[$row[0]] = $row[1];
            }
        }
        $e = FakeService::expected([1001], false, $from, $to);
        foreach (['total_calls', 'answered_calls', 'volunteer_dial_attempts', 'voicemail_calls'] as $k) {
            $this->assertSame((string) $e[$k], $byMetric[$k], $k);
        }
        $this->assertStringContainsString('Riverside Area', $doc['body']);
    }

    public function test_all_my_areas_export_matches_the_all_my_areas_dashboard(): void
    {
        wp_set_current_user(self::$phoneline);
        [$from, $to] = self::lastDays(30);
        $doc = Export::document(['type' => 'summary', 'start_date' => $from, 'end_date' => $to]);
        $rows = array_column(array_filter(self::csv($doc['body']), static fn ($r) => count($r) > 1), 1, 0);
        $this->assertSame((string) FakeService::expected(self::MANAGED, true, $from, $to)['total_calls'], $rows['total_calls'], 'region-wide activity included, as on the dashboard');
        $this->assertStringContainsString('Region-wide activity', $rows['Areas']);
    }

    public function test_call_export_pages_through_every_call(): void
    {
        wp_set_current_user(self::$phoneline);
        [$from, $to] = self::lastDays(60);
        $doc = Export::document(['type' => 'calls', 'areas' => '1001,1002', 'start_date' => $from, 'end_date' => $to]);
        $rows = self::csv($doc['body']);
        $header = array_search('Date', array_column($rows, 0), true);
        $this->assertIsInt($header);
        $this->assertCount(FakeService::expected([1001, 1002], false, $from, $to)['total_calls'], array_slice($rows, $header + 1), 'more than one page of 200');
        $this->assertStringNotContainsString('555', $doc['body'], 'no phone numbers');
    }

    public function test_exports_are_refused_without_permission_or_grant(): void
    {
        foreach ([[self::$reporter, ['type' => 'calls']], [self::$reporter, ['type' => 'volunteers']], [self::$reporter, ['type' => 'summary', 'areas' => '1002']],
            [self::$subscriber, ['type' => 'summary']], [self::$phoneline, ['type' => 'nonsense']], [self::$phoneline, ['type' => 'summary', 'areas' => ['1001']]]] as [$user, $query]) {
            wp_set_current_user($user);
            try {
                Export::document($query);
                $this->fail('exported ' . wp_json_encode($query) . ' for user ' . $user);
            } catch (\WPDieException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        Settings::update(['exports_enabled' => 0]);
        wp_set_current_user(self::$admin);
        $this->expectException(\WPDieException::class);
        Export::document(['type' => 'summary']);
    }

    public function test_spreadsheet_formulas_are_neutralised(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", Export::csv_cell('=HYPERLINK("x")'));
        $this->assertSame("'+1+1", Export::csv_cell('+1+1'));
        $this->assertSame("'@SUM(A1)", Export::csv_cell('@SUM(A1)'));
        $this->assertSame('-12.5', Export::csv_cell('-12.5'), 'numbers stay numbers');
        $this->assertSame('42', Export::csv_cell(42));
        $this->assertSame('yes', Export::csv_cell(true));
        $this->assertSame('', Export::csv_cell(null));
    }

    public function test_area_labels_containing_formulas_are_neutralised_in_the_file(): void
    {
        Settings::update(['area_labels' => [1001 => '=cmd|calc']]);
        wp_set_current_user(self::$admin);
        $doc = Export::document(['type' => 'meetings', 'areas' => '1001', 'period' => '30d']);
        $this->assertStringNotContainsString(',=cmd', $doc['body']);
        $this->assertStringContainsString("'=cmd|calc", $doc['body']);
    }

    public function test_printable_report_escapes_and_shows_the_figures(): void
    {
        Settings::update(['area_labels' => [1001 => '<script>alert(1)</script>']]);
        wp_set_current_user(self::$admin);
        $doc = Export::document(['type' => 'hours', 'areas' => '1001', 'period' => '7d', 'format' => 'html']);
        $this->assertSame('text/html; charset=utf-8', $doc['content_type']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $doc['body']);
        $this->assertStringContainsString('&lt;script&gt;', $doc['body']);
        $this->assertStringContainsString('<th scope="col">Answer rate %</th>', $doc['body']);
    }
}
