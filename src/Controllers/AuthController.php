<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\AuthService;

class AuthController
{
    public function __construct(
        private Twig $twig,
        private Database $db,
        private AuthService $auth
    ) {}

    /**
     * Show login form. Redirect to dashboard if already authenticated.
     */
    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->auth->isAuthenticated()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $flashError = $_SESSION['flash_error'] ?? null;
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $this->twig->render($response, 'admin/auth/login.twig', [
            'flash_error' => $flashError,
            'flash_success' => $flashSuccess,
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ]);
    }

    /**
     * Process login form submission.
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $remember = !empty($data['remember']);

        if ($email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Please enter your email and password.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        if ($this->auth->attempt($email, $password, $remember)) {
            $_SESSION['flash_success'] = 'Welcome back!';
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $_SESSION['flash_error'] = 'Invalid email or password.';
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    /**
     * Log out the current user and redirect to login.
     */
    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
