<?php
declare(strict_types=1);

/**
 * PulseOPS - Application Configuration
 * Multi-tenant Amusement Operations Management System
 * 
 * PHP 8.0+ Required
 */

return [
    // Application Settings
    'name' => 'PulseOPS',
    'version' => '1.0.0-beta.1',
    'debug' => true, // Set to false in production
    'timezone' => 'Australia/Darwin',
    'locale' => 'en_AU',
    
    // Database Configuration
    'database' => [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: 3306,
        'name' => getenv('DB_NAME') ?: 'pulseops',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    
    // Nayax API Configuration
    'nayax' => [
        'api_url' => getenv('NAYAX_API_URL') ?: 'https://lynx.nayax.com',
        'api_key' => getenv('NAYAX_API_KEY') ?: '',
    ],
    
    // Session Configuration
    'session' => [
        'name' => 'pulseops_session',
        'lifetime' => 7200, // 2 hours
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    
    // File Upload Settings
    'uploads' => [
        'max_size' => 10 * 1024 * 1024, // 10MB
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
        'path' => __DIR__ . '/../public/uploads',
    ],
    
    // Security Settings
    'security' => [
        'password_min_length' => 8,
        'login_attempts' => 5,
        'lockout_duration' => 900, // 15 minutes
        'csrf_token_name' => 'csrf_token',
    ],
    
    // Pagination
    'pagination' => [
        'per_page' => 25,
    ],
    
    // Currency
    'currency' => [
        'symbol' => '$',
        'code' => 'AUD',
        'decimal_places' => 2,
    ],
];
