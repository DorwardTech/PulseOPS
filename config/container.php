<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;
use App\Services\SettingsService;
use App\Services\NayaxService;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        'settings' => function () {
            return require __DIR__ . '/settings.php';
        },

        Database::class => function (ContainerInterface $c) {
            $dbSettings = $c->get('settings')['database'];
            return new Database($dbSettings);
        },

        SettingsService::class => function (ContainerInterface $c) {
            return new SettingsService($c->get(Database::class));
        },

        AuthService::class => function (ContainerInterface $c) {
            $securitySettings = $c->get('settings')['security'] ?? [];
            return new AuthService($c->get(Database::class), $securitySettings);
        },

        NayaxService::class => function (ContainerInterface $c) {
            $settings = $c->get('settings')['nayax'];
            $appSettings = $c->get(SettingsService::class);
            return new NayaxService($settings, $c->get(Database::class), $appSettings);
        },

        Twig::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');
            $twig = Twig::create(__DIR__ . '/../templates', [
                'cache' => $settings['app']['debug'] ? false : __DIR__ . '/../var/cache/twig',
                'debug' => $settings['app']['debug'],
                'auto_reload' => true,
            ]);

            $env = $twig->getEnvironment();

            // Add global variables
            $env->addGlobal('app_name', $settings['app']['name']);
            $env->addGlobal('currency', $settings['currency']);

            // Add format_currency function
            $env->addFunction(new \Twig\TwigFunction('format_currency', function ($amount) use ($settings) {
                return $settings['currency']['symbol'] . number_format((float)$amount, $settings['currency']['decimal_places']);
            }));

            // Add format_date function
            $env->addFunction(new \Twig\TwigFunction('format_date', function ($date, $format = 'd/m/Y') {
                if (!$date) return '';
                return date($format, strtotime($date));
            }));

            // Add format_datetime function
            $env->addFunction(new \Twig\TwigFunction('format_datetime', function ($date, $format = 'd/m/Y g:i A') {
                if (!$date) return '';
                return date($format, strtotime($date));
            }));

            // Add asset function
            $env->addFunction(new \Twig\TwigFunction('asset', function ($path) use ($settings) {
                return rtrim($settings['app']['url'], '/') . '/' . ltrim($path, '/');
            }));

            return $twig;
        },
    ]);
};
