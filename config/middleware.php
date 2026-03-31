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

    // Custom error handler that logs the request URL
    $errorMiddleware->setDefaultErrorHandler(function (
        \Psr\Http\Message\ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails
    ) use ($app) {
        $uri = (string) $request->getUri();
        $method = $request->getMethod();
        $code = $exception->getCode() ?: 500;

        // Only log non-404 errors (404s are just bots/scanners)
        if (!($exception instanceof \Slim\Exception\HttpNotFoundException)) {
            error_log("{$code} {$method} {$uri} - {$exception->getMessage()}");
        }

        $response = $app->getResponseFactory()->createResponse();

        if ($exception instanceof \Slim\Exception\HttpNotFoundException) {
            $response->getBody()->write('<h1>404 - Page Not Found</h1><p><a href="/">Go Home</a></p>');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/html');
        }

        if ($exception instanceof \Slim\Exception\HttpMethodNotAllowedException) {
            $response->getBody()->write('<h1>405 - Method Not Allowed</h1><p><a href="/">Go Home</a></p>');
            return $response->withStatus(405)->withHeader('Content-Type', 'text/html');
        }

        // Real errors - show details
        if ($displayErrorDetails) {
            $response->getBody()->write("<h1>Error</h1><p>{$exception->getMessage()}</p><pre>{$exception->getTraceAsString()}</pre>");
        } else {
            $response->getBody()->write('<h1>Server Error</h1><p>Something went wrong.</p>');
        }
        $httpCode = is_int($code) && $code >= 100 && $code < 600 ? $code : 500;
        return $response->withStatus($httpCode)->withHeader('Content-Type', 'text/html');
    });
};
