<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader so the service runs without `composer install`.
 * Composer's autoloader (if present) is loaded first by public/index.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'YapReporting\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
