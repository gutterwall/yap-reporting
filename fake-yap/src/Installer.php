<?php

declare(strict_types=1);

namespace FakeYap;

use PDO;

/** Creates the fake YAP database, its accounts and its installation folder. */
final class Installer
{
    public const SCHEMA = __DIR__ . '/../schema/yap-4.5.2.sql';
    public const EVENT_IDS = __DIR__ . '/../yap-root/app/Constants/EventId.php';

    /**
     * Database with YAP's schema; $user gets every right on it (YAP's own account, which the reporting service
     * reuses), $reader may read only what docs/DATA-MAPPING.md says the reporting service reads.
     */
    public static function database(PDO $admin, string $db, string $user, string $pass, string $reader, string $readerPass, bool $fresh): void
    {
        foreach ([$db, $user, $reader] as $name) {
            if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $name) !== 1) {
                throw new \InvalidArgumentException('Not a safe name: ' . $name);
            }
        }
        if ($fresh) {
            $admin->exec("DROP DATABASE IF EXISTS `$db`");
        }
        $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $admin->exec("USE `$db`");
        $schema = preg_replace('/^--.*$/m', '', (string) file_get_contents(self::SCHEMA)) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $admin->exec($statement);
        }
        foreach ([[$user, $pass], [$reader, $readerPass]] as [$name, $password]) {
            foreach (['%', 'localhost'] as $host) {
                $admin->exec("DROP USER IF EXISTS '$name'@'$host'");
                $admin->prepare("CREATE USER '$name'@'$host' IDENTIFIED BY ?")->execute([$password]);
            }
        }
        foreach (['%', 'localhost'] as $host) {
            $admin->exec("GRANT ALL PRIVILEGES ON `$db`.* TO '$user'@'$host'");
            $admin->exec("GRANT SELECT ON `$db`.`records_events` TO '$reader'@'$host'");
            $admin->exec("GRANT SELECT (id, callsid, start_time, end_time, to_number, payload, duration, type) ON `$db`.`records` TO '$reader'@'$host'");
            $admin->exec("GRANT SELECT (id, `timestamp`, conferencesid, callsid, role) ON `$db`.`conference_participants` TO '$reader'@'$host'");
            $admin->exec("GRANT SELECT (parent_callsid, callsid, service_body_id) ON `$db`.`cache_records_conference_participants` TO '$reader'@'$host'");
            $admin->exec("GRANT SELECT (id, service_body_id, data, data_type, parent_id, status) ON `$db`.`config` TO '$reader'@'$host'");
        }
        $admin->exec('FLUSH PRIVILEGES');
    }

    /** Fill the database through YAP's own account (whose session uses the server's default time zone, like YAP). */
    public static function seed(PDO $yap, Scenario $scenario): int
    {
        return $scenario->write(new YapWriter($yap, YapWriter::eventIds(self::EVENT_IDS)));
    }

    /** A YAP installation folder: config.php (database settings as YAP writes them) and the event constants. */
    public static function yapRoot(string $dir, string $host, int $port, string $db, string $user, string $pass): void
    {
        if (!is_dir($dir . '/app/Constants') && !mkdir($dir . '/app/Constants', 0755, true)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        copy(self::EVENT_IDS, $dir . '/app/Constants/EventId.php');
        $q = static fn (string $v): string => '"' . addcslashes($v, "\\\"\$") . '"';
        file_put_contents($dir . '/config.php', "<?php\n// Fake YAP installation for testing. Fictional values only.\n"
            . 'static $title = "Example helpline (fake YAP for testing)";' . "\n"
            . 'static $twilio_account_sid = "ACfake00000000000000000000000000";' . "\n"
            . 'static $twilio_auth_token = "fake-token-not-a-secret";' . "\n"
            . 'static $bmlt_root_server = "https://bmlt.example.invalid/main_server";' . "\n"
            . 'static $mysql_hostname = ' . $q($port === 3306 ? $host : $host . ':' . $port) . ";\n"
            . 'static $mysql_username = ' . $q($user) . ";\n"
            . 'static $mysql_password = ' . $q($pass) . ";\n"
            . 'static $mysql_database = ' . $q($db) . ";\n");
    }
}
