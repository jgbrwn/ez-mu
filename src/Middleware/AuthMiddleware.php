<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Authentication Middleware
 * 
 * Provides session-based authentication with login/logout.
 * Configurable via APP_USER and APP_PASSWORD environment variables.
 * If not configured, the app runs in "open" mode (no auth required).
 */
class AuthMiddleware implements MiddlewareInterface
{
    private bool $authEnabled;
    private string $username;
    private string $passwordHash;
    
    /** @var array<string> Routes that don't require authentication */
    private array $publicRoutes = [
        '/login',
        '/logout',
        '/static/',
        '/cron/process', // Has its own auth via CRON_SECRET
    ];

    public function __construct()
    {
        $this->username = $_ENV['APP_USER'] ?? '';
        $password = $_ENV['APP_PASSWORD'] ?? '';
        
        // Auth is enabled if both username and password are set
        $this->authEnabled = !empty($this->username) && !empty($password);
        
        // Store password hash for secure comparison
        $this->passwordHash = $this->authEnabled ? password_hash($password, PASSWORD_DEFAULT) : '';
    }

    /**
     * Check if authentication is enabled
     */
    public function isEnabled(): bool
    {
        return $this->authEnabled;
    }

    /**
     * Process request through authentication middleware
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        // If auth is disabled, allow all requests
        if (!$this->authEnabled) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        
        // Check if route is public
        if ($this->isPublicRoute($path)) {
            return $handler->handle($request);
        }

        // Start session if not started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check if user is authenticated
        if (!$this->isAuthenticated()) {
            // For HTMX requests, return 401 with redirect header
            if ($request->hasHeader('HX-Request')) {
                $response = new SlimResponse();
                return $response
                    ->withStatus(401)
                    ->withHeader('HX-Redirect', '/login');
            }
            
            // For regular requests, redirect to login
            $response = new SlimResponse();
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/login');
        }

        return $handler->handle($request);
    }

    /**
     * Check if a route is public (doesn't require auth)
     */
    private function isPublicRoute(string $path): bool
    {
        foreach ($this->publicRoutes as $route) {
            if (str_starts_with($path, $route)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if current session is authenticated
     */
    public function isAuthenticated(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    /**
     * Attempt to authenticate with username and password
     * 
     * @return bool True if authentication successful
     */
    public function authenticate(string $username, string $password): bool
    {
        if (!$this->authEnabled) {
            return false;
        }

        // Timing-safe comparison for username
        $usernameMatch = hash_equals($this->username, $username);
        
        // Verify password
        $storedPassword = $_ENV['APP_PASSWORD'] ?? '';
        $passwordMatch = hash_equals($storedPassword, $password);
        
        if ($usernameMatch && $passwordMatch) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['login_time'] = time();
            
            return true;
        }
        
        return false;
    }

    /**
     * Log out current user
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Get current username (or null if not authenticated)
     */
    public function getUsername(): ?string
    {
        if (!$this->isAuthenticated()) {
            return null;
        }
        return $_SESSION['username'] ?? null;
    }
}
