<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        // Only validate state-changing methods
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return $handler->handle($request);
        }

        // Generate token if one does not exist yet
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $expectedToken = $_SESSION['csrf_token'];

        // Check form field first, then header
        $parsedBody = $request->getParsedBody();
        $submittedToken = $parsedBody['csrf_token']
            ?? $request->getHeaderLine('X-CSRF-TOKEN')
            ?: '';

        if (!hash_equals($expectedToken, $submittedToken)) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => 'CSRF token mismatch'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        return $handler->handle($request);
    }
}
