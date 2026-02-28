<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Psr7\Response as SlimResponse;
use Throwable;

/**
 * Custom Error Handler
 * 
 * In production (APP_DEBUG=false): Shows generic error message, logs details
 * In development (APP_DEBUG=true): Shows full stack trace
 */
class ErrorHandlerMiddleware implements ErrorHandlerInterface
{
    private bool $debug;
    
    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }
    
    public function __invoke(
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): Response {
        // Always log the error
        $this->logError($request, $exception);
        
        $response = new SlimResponse();
        $statusCode = 500;
        
        // Get status code from HTTP exceptions
        if ($exception instanceof HttpException) {
            $statusCode = $exception->getCode();
        }
        
        // Check if HTMX request
        $isHtmx = $request->hasHeader('HX-Request');
        
        // Check if JSON response expected
        $acceptHeader = $request->getHeaderLine('Accept');
        $isJson = str_contains($acceptHeader, 'application/json');
        
        if ($isJson) {
            return $this->jsonResponse($response, $exception, $statusCode);
        }
        
        if ($isHtmx) {
            return $this->htmxResponse($response, $exception, $statusCode);
        }
        
        return $this->htmlResponse($response, $exception, $statusCode);
    }
    
    private function logError(Request $request, Throwable $exception): void
    {
        $message = sprintf(
            "[%s] %s: %s in %s:%d\nURL: %s %s\nTrace: %s",
            date('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $request->getMethod(),
            $request->getUri()->getPath(),
            $exception->getTraceAsString()
        );
        
        error_log($message);
    }
    
    private function jsonResponse(Response $response, Throwable $exception, int $statusCode): Response
    {
        $error = [
            'error' => true,
            'message' => $this->debug ? $exception->getMessage() : 'An error occurred',
        ];
        
        if ($this->debug) {
            $error['exception'] = get_class($exception);
            $error['file'] = $exception->getFile();
            $error['line'] = $exception->getLine();
            $error['trace'] = explode("\n", $exception->getTraceAsString());
        }
        
        $response->getBody()->write(json_encode($error));
        return $response
            ->withStatus($statusCode)
            ->withHeader('Content-Type', 'application/json');
    }
    
    private function htmxResponse(Response $response, Throwable $exception, int $statusCode): Response
    {
        $message = $this->debug 
            ? htmlspecialchars($exception->getMessage())
            : 'An error occurred. Please try again.';
        
        $html = sprintf(
            '<div class="error-message" style="padding: 12px; background: rgba(220,53,69,0.1); border: 1px solid rgba(220,53,69,0.3); border-radius: 6px; color: #dc3545;">%s</div>',
            $message
        );
        
        $response->getBody()->write($html);
        return $response->withStatus($statusCode)->withHeader('Content-Type', 'text/html');
    }
    
    private function htmlResponse(Response $response, Throwable $exception, int $statusCode): Response
    {
        $title = $statusCode === 404 ? 'Page Not Found' : 'Error';
        $message = $this->debug 
            ? htmlspecialchars($exception->getMessage())
            : 'An unexpected error occurred. Please try again later.';
        
        $debugInfo = '';
        if ($this->debug) {
            $debugInfo = sprintf(
                '<div style="margin-top: 20px; padding: 12px; background: #1a1a1a; border-radius: 6px; font-family: monospace; font-size: 12px; white-space: pre-wrap; overflow-x: auto;">'
                . '<strong>%s</strong> in %s:%d\n\n%s</div>',
                htmlspecialchars(get_class($exception)),
                htmlspecialchars($exception->getFile()),
                $exception->getLine(),
                htmlspecialchars($exception->getTraceAsString())
            );
        }
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - EZ-MU</title>
    <link rel="stylesheet" href="/static/style.css">
</head>
<body style="display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0;">
    <div style="max-width: 600px; padding: 20px; text-align: center;">
        <h1 style="font-size: 72px; margin: 0;">🎵</h1>
        <h2 style="margin: 20px 0;">{$title}</h2>
        <p style="color: var(--text-secondary);">{$message}</p>
        <a href="/" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background: var(--accent); color: white; text-decoration: none; border-radius: 6px;">Go Home</a>
        {$debugInfo}
    </div>
</body>
</html>
HTML;
        
        $response->getBody()->write($html);
        return $response->withStatus($statusCode)->withHeader('Content-Type', 'text/html');
    }
}
