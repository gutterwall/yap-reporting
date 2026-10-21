<?php

declare(strict_types=1);

/*
 * Webservice integration tests against the fake YAP backend on MySQL / MariaDB:
 *
 *   php tests/service/run.php [filter]
 *
 * Needs an account that may create databases and users and set the global time zone (see Harness.php for the
 * environment variables). The suite runs once per database time zone in YAPR_TEST_ZONES (default
 * "+00:00,-04:00": a UTC server and one like the production host), recreating and reseeding the database each
 * time, and restores the server's original global time zone afterwards.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../../webservice/reporting-api/src/autoload.php';
require __DIR__ . '/../../fake-yap/src/YapWriter.php';
require __DIR__ . '/../../fake-yap/src/Scenario.php';
require __DIR__ . '/../../fake-yap/src/Installer.php';
require __DIR__ . '/../../webservice/reporting-api/tests/Assert.php';
require __DIR__ . '/Harness.php';

$filter = $argv[1] ?? '';
$h = new Harness();
$admin = $h->admin();
$original = (string) $admin->query('SELECT @@global.time_zone')->fetchColumn();
$restore = static function () use ($h, $original): void {
    $h->admin()->exec('SET GLOBAL time_zone = ' . $h->admin()->quote($original));
    $h->admin()->exec('DROP DATABASE IF EXISTS `' . $h->db . '`');
    $h->cleanup();
};
register_shutdown_function($restore);

$cases = [];
foreach (glob(__DIR__ . '/cases/*Test.php') ?: [] as $file) {
    foreach ((require $file) as $name => $fn) {
        $cases[basename($file, '.php') . ' :: ' . $name] = $fn;
    }
}

$passed = 0;
$failed = [];
foreach (array_filter(explode(',', Harness::env('YAPR_TEST_ZONES', '+00:00,-04:00'))) as $zone) {
    $started = microtime(true);
    $h->install($zone);
    fwrite(STDOUT, sprintf("Database time zone %s (offset %+d s), %d calls and messages seeded in %.1f s\n", $zone, $h->dbOffset, count($h->scenario->calls()), microtime(true) - $started));
    foreach ($cases as $name => $fn) {
        if ($filter !== '' && stripos($name, $filter) === false) {
            continue;
        }
        $label = '[' . $zone . '] ' . $name;
        try {
            $fn($h);
            $passed++;
            fwrite(STDOUT, "  ok    {$label}\n");
        } catch (\Throwable $e) {
            $failed[] = $label;
            fwrite(STDOUT, "  FAIL  {$label}\n        " . get_class($e) . ': ' . $e->getMessage() . "\n        at " . $e->getFile() . ':' . $e->getLine() . "\n");
        }
        $h->cleanup();
    }
}
fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $passed, count($failed)));
exit($failed === [] ? 0 : 1);
