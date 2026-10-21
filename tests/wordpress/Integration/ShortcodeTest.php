<?php

declare(strict_types=1);

namespace YAPR\Tests\Integration;

use YAPR\Settings;
use YAPR\Tests\FakeService;
use YAPR\Tests\TestCase;

/**
 * The three shortcodes as visitors, reporters and administrators see them. The Area dashboard is rendered on the
 * server, so its figures are checked in the HTML.
 */
final class ShortcodeTest extends TestCase
{
    /** @return array<string, string> metric => figure as shown */
    private static function figures(string $html): array
    {
        preg_match_all('/yapr-metric-([a-z_]+)[^"]*">.*?<span class="yapr-figure">([^<]*)<\/span>/s', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = html_entity_decode($row[2]);
        }
        return $out;
    }

    public function test_area_dashboard_shows_the_areas_figures_to_an_authorized_user(): void
    {
        wp_set_current_user(self::$reporter);
        $html = do_shortcode('[yap_area_dashboard area="1001" period="30d"]');
        $f = self::figures($html);
        [$from, $to] = self::lastDays(30);
        $e = FakeService::expected([1001], false, $from, $to);
        $all = FakeService::expected(null, true, $from, $to);
        $this->assertSame(number_format_i18n($e['volunteer_routed_calls']), $f['volunteer_calls']);
        $this->assertSame(number_format_i18n($e['answered_calls']), $f['calls_answered']);
        $this->assertSame(number_format_i18n($e['answer_rate'], 1) . '%', $f['answer_rate']);
        // YAP stores JFT lookups without a service body: the labelled server-wide figure is shown instead of 0.
        $this->assertSame(number_format_i18n($all['jft_calls']), $f['jft']);
        $this->assertStringContainsString('All lines on this phone system', $html);
        $this->assertStringContainsString('<h3 class="yapr-ad-title"', $html);
        $this->assertStringContainsString('Riverside Area', $html);
        $this->assertArrayNotHasKey('calls_forwarded', $f, 'shown only when non-zero');
    }

    public function test_forwarded_calls_appear_for_the_area_that_forwards(): void
    {
        wp_set_current_user(self::$admin);
        $f = self::figures(do_shortcode('[yap_area_dashboard area="1003" period="90d"]'));
        [$from, $to] = self::lastDays(90);
        $this->assertSame(number_format_i18n(FakeService::expected([1003], false, $from, $to)['forwarded_to_helpline_calls']), $f['calls_forwarded']);
    }

    public function test_private_dashboards_show_visitors_nothing(): void
    {
        wp_set_current_user(0);
        $this->assertSame('', do_shortcode('[yap_area_dashboard area="1001"]'));
        wp_set_current_user(self::$subscriber);
        $this->assertStringContainsString('do not have access', do_shortcode('[yap_area_dashboard area="1001"]'));
        wp_set_current_user(self::$reporter);
        $this->assertStringContainsString('do not have access', do_shortcode('[yap_area_dashboard area="1002"]'), 'another Area');
    }

    public function test_published_dashboards_show_visitors_only_the_published_figures(): void
    {
        Settings::update(['public_areas' => [1001], 'public_metrics' => ['volunteer_calls', 'calls_answered']]);
        wp_set_current_user(0);
        $html = do_shortcode('[yap_area_dashboard area="1001" period="30d" metrics="volunteer_calls,calls_answered,answer_rate,jft"]');
        $this->assertSame(['volunteer_calls', 'calls_answered'], array_keys(self::figures($html)));
        [$from, $to] = self::lastDays(30);
        $this->assertSame(number_format_i18n(FakeService::expected([1001], false, $from, $to)['answered_calls']), self::figures($html)['calls_answered']);
        $this->assertSame('', do_shortcode('[yap_area_dashboard area="1002"]'), 'only published Areas');
        $this->assertStringContainsString('Last 30 days', do_shortcode('[yap_area_dashboard area="1001" period="today"]'), 'visitors cannot ask for periods outside the public list');

        // The public copy is a separate cache entry holding dashboard figures only (which of them are shown is
        // decided when rendering), never the private payload's scope or other data.
        global $wpdb;
        $payloads = $wpdb->get_col("SELECT payload FROM {$wpdb->prefix}yapr_aggregate_cache WHERE visibility = 'public'");
        $this->assertNotEmpty($payloads);
        $allowed = array_merge(\YAPR\Renderer::METRIC_KEYS, array_map(static fn ($k) => $k . '_installation_wide', \YAPR\Renderer::FALLBACK_KEYS), ['labels']);
        foreach ($payloads as $p) {
            $data = json_decode($p, true);
            $this->assertSame([], array_diff(array_keys($data['data']['current']), $allowed));
            $this->assertSame(['generated_at', 'range', 'answered_definition'], array_keys($data['meta']));
        }
    }

    public function test_selectable_period_and_options(): void
    {
        wp_set_current_user(self::$reporter);
        $_GET['yapr_period'] = '7d';
        $_GET['page_id'] = '12"><script>';
        $html = do_shortcode('[yap_area_dashboard area="1001" selectable="yes" show_sms="no" show_meditations="no" style="compact" title="Our &lt;Area&gt;"]');
        unset($_GET['yapr_period'], $_GET['page_id']);
        $this->assertStringContainsString('<option value="7d" selected=\'selected\'>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('yapr-style-compact', $html);
        $f = self::figures($html);
        $this->assertArrayNotHasKey('sms_jft', $f);
        $this->assertArrayNotHasKey('jft', $f);
        [$from, $to] = self::lastDays(7);
        $this->assertSame(number_format_i18n(FakeService::expected([1001], false, $from, $to)['volunteer_routed_calls']), $f['volunteer_calls']);
    }

    public function test_reporting_app_container_carries_settings_but_no_secret(): void
    {
        wp_set_current_user(self::$phoneline);
        $html = do_shortcode('[yap_reporting view="all" period="7d" areas="1001,1002"]');
        $this->assertMatchesRegularExpression('/data-yapr-config="[^"]*&quot;areas&quot;:\[1001,1002\]/', $html);
        $this->assertTrue(wp_script_is('yapr-app', 'enqueued'));
        $boot = wp_scripts()->get_data('yapr-app', 'data');
        $this->assertStringContainsString('"regionWide":"1"', $boot, 'wp_localize_script sends top-level flags as strings');
        $this->assertStringContainsString('"calls":true', $boot);
        $this->assertStringNotContainsString(FakeService::$token, $boot);
        $this->assertStringNotContainsString(FakeService::API, $boot, 'the browser only talks to WordPress');

        wp_set_current_user(self::$reporter);
        $this->assertStringContainsString('do not have access', do_shortcode('[yap_reporting areas="1002"]'));
        $this->assertStringContainsString('permission', do_shortcode('[yap_phoneline_admin]'));
        wp_set_current_user(0);
        $this->assertStringContainsString('Log in', do_shortcode('[yap_reporting]'));
    }

    public function test_unknown_area_is_explained_to_editors_only(): void
    {
        wp_set_current_user(self::$editor);
        $this->assertStringContainsString('set area=', do_shortcode('[yap_area_dashboard area="424242"]'));
        wp_set_current_user(0);
        $this->assertSame('', do_shortcode('[yap_area_dashboard area="424242"]'));
    }

    public function test_service_outage_shows_last_good_figures_marked_stale(): void
    {
        wp_set_current_user(self::$reporter);
        $good = self::figures(do_shortcode('[yap_area_dashboard area="1001" period="30d"]'));
        // Make the cached copy old, then take the service down.
        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->prefix}yapr_aggregate_cache SET fetched_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY), expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)");
        FakeService::$override = static fn () => new \WP_Error('http_request_failed', 'cURL error 28: Connection timed out');
        $html = do_shortcode('[yap_area_dashboard area="1001" period="30d"]');
        $this->assertSame($good, self::figures($html));
        $this->assertStringContainsString('may be out of date', $html);
    }
}
