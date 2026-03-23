<?php

declare(strict_types=1);

/**
 * Cron Bootstrap
 * Shared bootstrap for all cron scripts - sets up the DI container
 */

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Australia/Darwin');

// Verify cron key (for HTTP access)
if (php_sapi_name() !== 'cli') {
    $cronKey = $_GET['key'] ?? '';
    if (empty($_ENV['CRON_KEY']) || $cronKey !== $_ENV['CRON_KEY']) {
        http_response_code(403);
        echo 'Forbidden';
        exit(1);
    }
}

// Build container
$containerBuilder = new ContainerBuilder();
(require __DIR__ . '/../config/container.php')($containerBuilder);
$container = $containerBuilder->build();

return $container;
