<?php

declare(strict_types=1);

/*
 * Fake BMLT root server: answers the Semantic API call the WordPress plugin makes,
 * .../client_interface/json/?switcher=GetServiceBodies, with the fictional Region in fake-yap/src/Scenario.php.
 * Serve it at any path ending in /main_server/ (php -S with this file as the router, or Apache/nginx).
 */

require __DIR__ . '/../src/Scenario.php';

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('Content-Type: application/json');
if (preg_match('#/client_interface/json/?$#', $path) !== 1) {
    http_response_code(404);
    echo '{"error":"not found"}';
    return;
}
switch ($_GET['switcher'] ?? '') {
    case 'GetServiceBodies':
        echo json_encode(FakeYap\Scenario::bmltServiceBodies());
        break;
    case 'GetServerInfo':
        echo json_encode([['version' => '3.1.0', 'versionInt' => '3001000', 'langs' => 'en', 'nativeLang' => 'en']]);
        break;
    default:
        http_response_code(400);
        echo '{"error":"unsupported switcher"}';
}
