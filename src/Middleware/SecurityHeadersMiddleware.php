<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Security Headers Middleware
 * 
 * Adds security-related HTTP headers to all responses.
 * CSP is configured to allow HTMX and inline styles needed by the app.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);
        
        return $response
            // Prevent MIME-type sniffing
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Prevent clickjacking
            ->withHeader('X-Frame-Options', 'DENY')
            // XSS protection (legacy, but doesn't hurt)
            ->withHeader('X-XSS-Protection', '1; mode=block')
            // Referrer policy - send origin only for cross-origin
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            // Content Security Policy - allows HTMX, inline styles (needed for app)
            // Note: 'unsafe-inline' for style-src is needed for inline styles
            // script-src allows specific CDNs for HTMX and Google Fonts
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://unpkg.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' https: data:",
                "connect-src 'self'",
                "media-src 'self'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "base-uri 'self'",
            ]));
    }
}
