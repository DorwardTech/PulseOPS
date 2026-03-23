<?php

declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

return function (App $app) {
    // Parse JSON body
    $app->addBodyParsingMiddleware();

    // Twig-View Middleware
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));

    // Routing middleware
    $app->addRoutingMiddleware();

    // Error middleware (should be last)
    $settings = $app->getContainer()->get('settings');
    $errorMiddleware = $app->addErrorMiddleware(
        $settings['app']['debug'],
        true,
        true
    );
};
