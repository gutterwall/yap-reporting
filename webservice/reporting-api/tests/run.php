<?php

declare(strict_types=1);

/*
 * Dependency-free test runner:  php tests/run.php
 * Requires the pdo_sqlite extension. All fixture data is fictional.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/Assert.php';
require __DIR__ . '/fixtures/Fixture.php';

$tests = [];
foreach (glob(__DIR__ . '/unit/*Test.php') ?: [] as $file) {
    $suite = require $file;
    foreach ($suite as $name => $fn) {
        $tests[basename($file, '.php') . ' :: ' . $name] = $fn;
    }
}

$passed = 0;
$failed = [];
foreach ($tests as $name => $fn) {
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "  ok    {$name}\n");
    } catch (\Throwable $e) {
        $failed[] = $name;
        fwrite(STDOUT, "  FAIL  {$name}\n        " . get_class($e) . ': ' . $e->getMessage() . "\n        at " . $e->getFile() . ':' . $e->getLine() . "\n");
    }
}
fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $passed, count($failed)));
exit($failed === [] ? 0 : 1);
