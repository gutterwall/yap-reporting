<?php

/*
 * php -S router that serves the webservice the way its .htaccess does: every request under a
 * .../reporting-api/ path goes to public/index.php; nothing in the package is served as a file.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^(.*/reporting-api)/#', $path, $m) === 1) {
    $_SERVER['SCRIPT_NAME'] = $m[1] . '/public/index.php';
    require __DIR__ . '/../../webservice/reporting-api/public/index.php';
    return true;
}
http_response_code(404);
return true;
