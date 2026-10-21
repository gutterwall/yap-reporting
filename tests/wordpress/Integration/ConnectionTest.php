<?php

declare(strict_types=1);

namespace YAPR\Tests\Integration;

use YAPR\Access;
use YAPR\ApiClient;
use YAPR\Areas;
use YAPR\BmltClient;
use YAPR\Crypto;
use YAPR\Settings;
use YAPR\Tests\FakeService;
use YAPR\Tests\TestCase;
use YAPR\UrlGuard;

/** The connection to the webservice and BMLT: URL policy, credential storage, compatibility, failures. */
final class ConnectionTest extends TestCase
{
    public function test_test_connection_stores_capabilities_and_reports_compatibility(): void
    {
        $r = ApiClient::test_connection();
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Connected', $r['message']);
        $caps = ApiClient::capabilities();
        $this->assertSame('v1', $caps['api_version']);
        $this->assertSame('ok', ApiClient::compatibility()['status']);
        $this->assertTrue(Access::credential_allows_unassigned());

        $this->assertSame('incompatible', ApiClient::compatibility(['api_version' => 'v2', 'service_version' => '9.0.0'])['status']);
        $this->assertSame('outdated', ApiClient::compatibility(['api_version' => 'v1', 'service_version' => '1.3.0'])['status']);
        $this->assertSame('unknown', ApiClient::compatibility([])['status']);
    }

    public function test_requests_carry_the_token_only_in_the_header_and_follow_no_redirects(): void
    {
        $before = count(FakeService::$log);
        ApiClient::get('summary', ['period' => '7d', 'areas' => '1001', 'token' => 'x', 'evil' => 'y']);
        $req = FakeService::$log[$before];
        $this->assertSame('Bearer ' . FakeService::$token, $req['authorization']);
        $this->assertStringNotContainsString('token', $req['url'], 'unknown query keys are dropped');
        $this->assertStringNotContainsString('evil', $req['url']);
        $this->assertSame(0, $req['args']['redirection']);
        $this->assertTrue($req['args']['reject_unsafe_urls']);

        FakeService::$override = static fn () => FakeService::response(301, '', ['location' => 'https://elsewhere.example/']);
        $this->assertSame('yapr_redirect', ApiClient::get('summary')->get_error_code());
    }

    public function test_only_known_endpoints_can_be_called(): void
    {
        foreach (['../admin', 'users', 'calls/../../health', 'calls/c_1_abc'] as $endpoint) {
            $this->assertSame('yapr_endpoint', ApiClient::get($endpoint)->get_error_code(), $endpoint);
        }
    }

    public function test_url_policy(): void
    {
        UrlGuard::$resolver = null;
        $this->assertSame('yapr_https_required', UrlGuard::validate('http://yap.example.org/reporting-api')->get_error_code());
        $this->assertSame('yapr_bad_url', UrlGuard::validate('https://user:pass@yap.example.org/x')->get_error_code());
        $this->assertSame('yapr_bad_url', UrlGuard::validate('https://yap.example.org/x?y=1')->get_error_code());
        foreach (['127.0.0.1', '10.1.2.3', '192.168.1.5', '169.254.169.254', '100.64.0.1', '[::1]', '[fd00::1]'] as $host) {
            $this->assertSame('yapr_private_host', UrlGuard::validate('https://' . $host . '/reporting-api')->get_error_code(), $host);
        }
        UrlGuard::$resolver = static fn () => ['10.0.0.8'];
        $this->assertSame('yapr_private_host', UrlGuard::validate('https://internal.example.org/x')->get_error_code(), 'a public name pointing inward');
        UrlGuard::$resolver = static fn () => [];
        $this->assertSame('yapr_dns', UrlGuard::validate('https://nowhere.invalid/x')->get_error_code());
    }

    public function test_credential_is_encrypted_at_rest_and_never_shown_whole(): void
    {
        $stored = (string) get_option(Settings::TOKEN_OPTION);
        $this->assertStringStartsWith('yapr1:', $stored);
        $this->assertStringNotContainsString(FakeService::$token, $stored);
        $this->assertSame(FakeService::$token, Settings::api_token());
        $this->assertSame('database', Settings::token_source());
        $this->assertMatchesRegularExpression('/^yapr_[0-9a-f]{12}_•+$/u', Settings::token_hint());
        $this->assertNull(Crypto::decrypt('yapr1:' . base64_encode(random_bytes(40))), 'tampered or other key');
        $this->assertNull(Crypto::decrypt('plain-text'));
        Settings::clear_token();
        $this->assertSame('', Settings::api_token());
        $this->assertSame('yapr_no_token', ApiClient::get('summary')->get_error_code());
        $this->assertTrue(is_array(ApiClient::get('health')), 'health needs no credential');
    }

    public function test_bmlt_refresh_keeps_selection_and_flags_vanished_service_bodies(): void
    {
        $this->assertSame(7, count(Areas::all()));
        $this->assertSame(['Example Region of NA', 'Hillcrest Area', 'Lakeshore Area', 'Riverside Area'], array_map([Areas::class, 'name'], Areas::managed_ids()), 'listed by name');
        $this->assertSame('rs', Areas::all()[1000]['type']);
        $this->assertSame(1000, Areas::all()[1001]['parent_id']);

        // BMLT no longer lists Area 1003: it stays (calls may refer to it) but is flagged.
        FakeService::$override = static function (string $url) {
            if (!str_contains($url, 'GetServiceBodies')) {
                return null;
            }
            $bodies = array_values(array_filter(\FakeYap\Scenario::bmltServiceBodies(), static fn ($b) => '1003' !== $b['id']));
            return FakeService::response(200, (string) wp_json_encode($bodies));
        };
        $this->assertSame(6, BmltClient::refresh());
        $this->assertFalse(Areas::all()[1003]['present_in_bmlt']);
        $this->assertTrue(Areas::is_managed(1003), 'still selected');
    }

    public function test_bmlt_errors_are_explained(): void
    {
        FakeService::$override = static fn () => FakeService::response(404, 'Not Found', ['content-type' => 'text/html']);
        $this->assertSame('yapr_bmlt_status', BmltClient::service_bodies()->get_error_code());
        FakeService::$override = static fn () => FakeService::response(200, '<html>login</html>', ['content-type' => 'text/html']);
        $this->assertSame('yapr_bmlt_json', BmltClient::service_bodies()->get_error_code());
        FakeService::$override = static fn () => FakeService::response(200, '[]');
        $this->assertSame('yapr_bmlt_empty', BmltClient::service_bodies()->get_error_code());
        FakeService::$override = static fn () => FakeService::response(200, '[{"id":"5","parent_id":"0","name":"<b>X</b>","type":"A S"}]');
        $bodies = BmltClient::service_bodies();
        $this->assertSame('X', $bodies[0]['name']);
        $this->assertSame('as', $bodies[0]['type']);
        Settings::update(['bmlt_root' => '']);
        $this->assertSame('yapr_bmlt_missing', BmltClient::service_bodies()->get_error_code());
    }

    public function test_a_credential_limited_to_some_areas_turns_off_region_wide_activity(): void
    {
        Settings::set_token(FakeService::credential(false, [1001, 1002, 1003], ['summary'], false));
        $this->assertTrue(ApiClient::test_connection()['ok']);
        $this->assertFalse(Access::credential_allows_unassigned());
        $this->assertFalse(Access::can_see_unassigned(self::$phoneline));
        $r = $this->rest('/report', ['view' => 'summary', 'areas' => '1001,1002,1003', 'period' => '7d'], self::$phoneline);
        $this->assertSame(200, $r->get_status());
        $r = $this->rest('/report', ['view' => 'summary', 'period' => '7d'], self::$phoneline);
        $this->assertSame(502, $r->get_status(), 'Region 1000 is not in this credential');
    }
}
