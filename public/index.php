<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

try {
    // Set timezone
    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Australia/Darwin');

    // Start session
    $sessionSettings = [
        'name' => 'pulseops_session',
        'cookie_lifetime' => 7200,
        'cookie_path' => '/',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ];

    session_name($sessionSettings['name']);
    session_set_cookie_params([
        'lifetime' => $sessionSettings['cookie_lifetime'],
        'path' => $sessionSettings['cookie_path'],
        'httponly' => $sessionSettings['cookie_httponly'],
        'samesite' => $sessionSettings['cookie_samesite'],
        'secure' => $sessionSettings['cookie_secure'],
    ]);
    session_start();

    // Generate CSRF token if not exists
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Build DI Container
    $containerBuilder = new ContainerBuilder();

    if (($_ENV['APP_ENV'] ?? '') === 'production') {
        $diCachePath = __DIR__ . '/../var/cache/di';
        // Clear stale DI cache on deploy (keyed by config file modification time)
        $cacheKey = $diCachePath . '/.cache_key';
        $currentKey = md5_file(__DIR__ . '/../config/container.php') . md5_file(__DIR__ . '/../config/routes.php');
        if (!file_exists($cacheKey) || file_get_contents($cacheKey) !== $currentKey) {
            array_map('unlink', glob($diCachePath . '/*.php') ?: []);
            @mkdir($diCachePath, 0755, true);
            file_put_contents($cacheKey, $currentKey);
        }
        $containerBuilder->enableCompilation($diCachePath);
    }

    (require __DIR__ . '/../config/container.php')($containerBuilder);
    $container = $containerBuilder->build();

    // Create App
    AppFactory::setContainer($container);
    $app = AppFactory::create();

    // Register middleware
    (require __DIR__ . '/../config/middleware.php')($app);

    // Register routes
    (require __DIR__ . '/../config/routes.php')($app);

    // Run app
    $app->run();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    if (filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        echo "Error: " . $e->getMessage() . "\n\n" . $e->getTraceAsString();
    } else {
        echo "Internal Server Error. Set APP_DEBUG=true in environment for details.";
    }
}
