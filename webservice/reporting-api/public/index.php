<?php

declare(strict_types=1);

/*
 * YAP Reporting Webservice — front controller.
 * This is the ONLY file that should ever be reachable over HTTP.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$baseDir = dirname(__DIR__);

if (is_file($baseDir . '/vendor/autoload.php')) {
    require $baseDir . '/vendor/autoload.php';
}
require_once $baseDir . '/src/autoload.php';

use YapReporting\App;
use YapReporting\Config;
use YapReporting\Http\Request;
use YapReporting\Http\Response;

try {
    $config = Config::load($baseDir);

    // Browser setup console (replaces bin/yapr on hosts without SSH). Returns 404 unless
    // config/ENABLE_SETUP exists.
    $rawPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($rawPath) && preg_match('#/setup/?$#', $rawPath) === 1) {
        (new \YapReporting\Setup\SetupConsole($config))->handle();
        exit;
    }

    $request = Request::fromGlobals($config);
    $app = new App($config);
    $response = $app->handle($request);
    $response->send($request->secure, $request->method === 'HEAD');
} catch (\Throwable $e) {
    // Last-resort handler: never leak details to the client.
    error_log('[yap-reporting] bootstrap failure: ' . get_class($e));
    (Response::json([
        'error' => ['code' => 'service_unavailable', 'message' => 'The reporting service is not configured correctly.'],
    ], 503))->send(false, false);
}
