<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Middleware\AuthMiddleware;
use App\Services\RateLimiter;

/**
 * Authentication Controller
 * 
 * Handles login form display and authentication.
 */
class AuthController
{
    private Twig $twig;
    private AuthMiddleware $auth;
    private RateLimiter $rateLimiter;

    public function __construct(Twig $twig, AuthMiddleware $auth, RateLimiter $rateLimiter)
    {
        $this->twig = $twig;
        $this->auth = $auth;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Display login form
     */
    public function loginForm(Request $request, Response $response): Response
    {
        // If already authenticated, redirect to home
        if ($this->auth->isAuthenticated()) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/');
        }

        // If auth is not enabled, redirect to home
        if (!$this->auth->isEnabled()) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/');
        }

        return $this->twig->render($response, 'login.twig', [
            'csrf_token' => $request->getAttribute('csrf_token'),
        ]);
    }

    /**
     * Process login attempt
     */
    public function login(Request $request, Response $response): Response
    {
        // Rate limit login attempts (5 per minute per IP)
        $ip = $this->getClientIp($request);
        $this->rateLimiter->wait('login_' . $ip, 5, 60);

        $data = $request->getParsedBody();
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if ($this->auth->authenticate($username, $password)) {
            // Successful login
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/');
        }

        // Failed login - show error
        return $this->twig->render($response, 'login.twig', [
            'error' => 'Invalid username or password',
            'username' => $username,
            'csrf_token' => $request->getAttribute('csrf_token'),
        ]);
    }

    /**
     * Log out
     */
    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        
        return $response
            ->withStatus(302)
            ->withHeader('Location', '/login');
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        // Check common proxy headers
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'CF-Connecting-IP', // Cloudflare
        ];

        foreach ($headers as $header) {
            $value = $request->getHeaderLine($header);
            if (!empty($value)) {
                // X-Forwarded-For may contain multiple IPs - take the first
                $ips = array_map('trim', explode(',', $value));
                return $ips[0];
            }
        }

        // Fallback to server params
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
