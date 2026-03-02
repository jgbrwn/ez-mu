<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Method Override Middleware
 * 
 * Allows using POST requests with _METHOD parameter to simulate
 * PUT, DELETE, PATCH methods. Essential for shared hosting environments
 * that don't allow these HTTP methods.
 * 
 * Supports:
 * - Form field: <input type="hidden" name="_METHOD" value="DELETE">
 * - Header: X-HTTP-Method-Override: DELETE
 */
class MethodOverrideMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        
        // Only override POST requests
        if ($method === 'POST') {
            $overrideMethod = null;
            
            // Check X-HTTP-Method-Override header first
            $headerOverride = $request->getHeaderLine('X-HTTP-Method-Override');
            if (!empty($headerOverride)) {
                $overrideMethod = strtoupper($headerOverride);
            }
            
            // Check _METHOD in parsed body
            if (!$overrideMethod) {
                $body = $request->getParsedBody();
                if (is_array($body) && isset($body['_METHOD'])) {
                    $overrideMethod = strtoupper($body['_METHOD']);
                }
            }
            
            // Check _METHOD in query params (for links)
            if (!$overrideMethod) {
                $queryParams = $request->getQueryParams();
                if (isset($queryParams['_METHOD'])) {
                    $overrideMethod = strtoupper($queryParams['_METHOD']);
                }
            }
            
            // Apply override if valid method
            if ($overrideMethod && in_array($overrideMethod, ['PUT', 'DELETE', 'PATCH'])) {
                $request = $request->withMethod($overrideMethod);
            }
        }
        
        return $handler->handle($request);
    }
}
