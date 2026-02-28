<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * CSRF Protection Middleware
 * 
 * Simple CSRF token validation for state-changing requests.
 * Generates tokens per-session and validates on POST/PUT/DELETE/PATCH.
 * 
 * Works with both form submissions and HTMX requests.
 * HTMX requests should include the token in hx-headers or a hidden field.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private const TOKEN_KEY = 'csrf_token';
    private const TOKEN_LENGTH = 32;
    
    /** @var array<string> HTTP methods that require CSRF validation */
    private array $protectedMethods = ['POST', 'PUT', 'DELETE', 'PATCH'];
    
    /** @var array<string> Routes exempt from CSRF (e.g., external API endpoints) */
    private array $exemptRoutes = [
        '/cron/process', // External cron service - uses its own auth
    ];

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Generate token if not exists
        if (!isset($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }

        // Store token in request attributes for templates
        $request = $request->withAttribute('csrf_token', $_SESSION[self::TOKEN_KEY]);
        $request = $request->withAttribute('csrf_token_name', self::TOKEN_KEY);

        // Check if this request needs CSRF validation
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        
        if (in_array($method, $this->protectedMethods) && !$this->isExempt($path)) {
            if (!$this->validateToken($request)) {
                // Return 403 Forbidden for CSRF failures
                $response = new SlimResponse();
                $response->getBody()->write(json_encode([
                    'error' => 'CSRF token validation failed',
                    'message' => 'Invalid or missing CSRF token. Please refresh the page and try again.',
                ]));
                
                // For HTMX, trigger a page reload
                if ($request->hasHeader('HX-Request')) {
                    return $response
                        ->withStatus(403)
                        ->withHeader('Content-Type', 'application/json')
                        ->withHeader('HX-Refresh', 'true');
                }
                
                return $response
                    ->withStatus(403)
                    ->withHeader('Content-Type', 'application/json');
            }
        }

        return $handler->handle($request);
    }

    /**
     * Check if a route is exempt from CSRF protection
     */
    private function isExempt(string $path): bool
    {
        foreach ($this->exemptRoutes as $route) {
            if (str_starts_with($path, $route)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate CSRF token from request
     */
    private function validateToken(Request $request): bool
    {
        $sessionToken = $_SESSION[self::TOKEN_KEY] ?? '';
        
        if (empty($sessionToken)) {
            return false;
        }

        // Check form body first
        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[self::TOKEN_KEY])) {
            return hash_equals($sessionToken, $body[self::TOKEN_KEY]);
        }

        // Check X-CSRF-Token header (for HTMX/AJAX)
        $headerToken = $request->getHeaderLine('X-CSRF-Token');
        if (!empty($headerToken)) {
            return hash_equals($sessionToken, $headerToken);
        }

        // Check query params as fallback (not recommended but some use cases)
        $queryParams = $request->getQueryParams();
        if (isset($queryParams[self::TOKEN_KEY])) {
            return hash_equals($sessionToken, $queryParams[self::TOKEN_KEY]);
        }

        return false;
    }

    /**
     * Get current CSRF token
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }
        
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Get the token field name
     */
    public static function getTokenName(): string
    {
        return self::TOKEN_KEY;
    }
}
