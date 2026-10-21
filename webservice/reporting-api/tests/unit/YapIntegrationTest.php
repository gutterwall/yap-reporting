<?php

declare(strict_types=1);

use YapReporting\Reporting\DatasetLoader;
use YapReporting\Security\IpMatcher;
use YapReporting\Support\Logger;
use YapReporting\Yap\EventCatalog;
use YapReporting\Yap\YapConfigLoader;

return [
    'config.php is parsed without executing it' => function (): void {
        $vars = YapConfigLoader::parseStaticAssignments(
            (string) file_get_contents(__DIR__ . '/../fixtures/config.php'),
            ['mysql_hostname', 'mysql_username', 'mysql_password', 'mysql_database']
        );
        Assert::same('db.example.test:3307', $vars['mysql_hostname']);
        Assert::same('yap_user', $vars['mysql_username']);
        Assert::same('pa\'ss"word\\x', $vars['mysql_password']);
        Assert::same('yap_db', $vars['mysql_database']);
    },
    'host:port is split' => function (): void {
        $config = Fixture::config(Fixture::tempDir(), ['yap_config_file' => __DIR__ . '/../fixtures/config.php']);
        $c = (new YapConfigLoader($config))->databaseCredentials();
        Assert::same('db.example.test', $c['host']);
        Assert::same(3307, $c['port']);
        Assert::same('yap-config.php', $c['source']);
    },
    'event constants are read from the installed file' => function (): void {
        $map = EventCatalog::parse((string) file_get_contents(__DIR__ . '/../fixtures/EventId.php'));
        Assert::same(105, $map['VOLUNTEER_DIALED']);
        Assert::same(123, $map['SPAD_LOOKUP']);
        Assert::true(!isset($map['SPAD_LOOKUP_SMS']));
    },
    'only the volunteer number is taken from meta' => function (): void {
        $vol = DatasetLoader::volunteerFromMeta('{"to_number":"+1 (555) 555-0171","caller":"+15555550123"}', ['to_number']);
        Assert::same('5555550171', $vol);
    },
    'direction is read from payload' => function (): void {
        Assert::same('outbound-api', DatasetLoader::directionFromPayload('{"Direction":"outbound-api","From":"+15555550123"}'));
        Assert::same('inbound', DatasetLoader::directionFromPayload('Direction=inbound&From=%2B15555550123'));
    },
    'logger scrubs numbers and tokens' => function (): void {
        $s = Logger::scrub('token yapr_abcdef123456_xyz from +1 555 555 0123 ip 203.0.113.5');
        Assert::notContains('xyz', $s);
        Assert::notContains('555 0123', $s);
        Assert::true(str_contains($s, '203.0.113.5'), 'IP addresses survive scrubbing');
    },
    'CIDR matching' => function (): void {
        Assert::true(IpMatcher::matches('203.0.113.77', '203.0.113.0/24'));
        Assert::true(!IpMatcher::matches('203.0.114.1', '203.0.113.0/24'));
        Assert::true(IpMatcher::matches('2001:db8::1', '2001:db8::/32'));
    },
    'real YAP 4.5 EventId.php parses completely' => function (): void {
        $map = EventCatalog::parse((string) file_get_contents(__DIR__ . '/../fixtures/EventId.yap-4.5.php'));
        Assert::same(28, count($map));
        foreach (['VOLUNTEER_DIALED' => 5, 'VOLUNTEER_IN_CONFERENCE' => 12, 'CALLER_HUP' => 13, 'MEETING_SEARCH_SMS' => 19,
                  'SPAD_LOOKUP' => 23, 'SPAD_LOOKUP_SMS' => 24, 'VOLUNTEER_NUMBER_BUSY' => 27, 'STIR_VERSTAT_RECEIVED' => 28] as $name => $id) {
            Assert::same($id, $map[$name], $name);
        }
        Assert::true(EventCatalog::isSmsName('SMS_BLACKHOLED'));
        Assert::true(!EventCatalog::isSmsName('STIR_VERSTAT_RECEIVED'));
    },
    'ENVIRONMENT selects config.<env>.php like YAP does' => function (): void {
        $root = Fixture::tempDir();
        file_put_contents($root . '/config.php', '<?php static $mysql_database = "prod_db";');
        file_put_contents($root . '/config.staging.php', '<?php static $mysql_database = "staging_db";');
        $config = Fixture::config(Fixture::tempDir(), ['yap_root' => $root]);
        $before = getenv('ENVIRONMENT');
        putenv('ENVIRONMENT=staging');
        try {
            Assert::same('staging_db', (new YapConfigLoader($config))->databaseCredentials()['database']);
        } finally {
            $before === false ? putenv('ENVIRONMENT') : putenv('ENVIRONMENT=' . $before);
        }
        Assert::same('prod_db', (new YapConfigLoader($config))->databaseCredentials()['database']);
    },
    'double-encoded meta JSON still yields the volunteer' => function (): void {
        Assert::same('5555550171', DatasetLoader::volunteerFromMeta(json_encode(json_encode(['to_number' => '+15555550171'])), ['to_number']));
    },
];

