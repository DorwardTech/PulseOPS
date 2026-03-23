<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => $_ENV['APP_NAME'] ?? 'PulseOPS',
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'url' => $_ENV['APP_URL'] ?? 'http://localhost:3000',
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Australia/Darwin',
    ],
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'database' => $_ENV['DB_DATABASE'] ?? 'pulseops',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ],
    'nayax' => [
        'api_url' => $_ENV['NAYAX_API_URL'] ?? 'https://lynx.nayax.com',
        'api_token' => $_ENV['NAYAX_API_TOKEN'] ?? '',
        'operator_id' => $_ENV['NAYAX_OPERATOR_ID'] ?? '',
        'environment' => $_ENV['NAYAX_ENVIRONMENT'] ?? 'production',
    ],
    'session' => [
        'name' => 'pulseops_session',
        'lifetime' => 7200,
    ],
    'security' => [
        'cron_key' => $_ENV['CRON_KEY'] ?? '',
    ],
    'currency' => [
        'symbol' => '$',
        'code' => 'AUD',
        'decimal_places' => 2,
    ],
];
