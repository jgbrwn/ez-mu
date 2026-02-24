<?php

namespace App\Middleware;

use App\Services\DownloadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Processes queued download jobs on page requests.
 * 
 * This provides background processing on shared hosting where
 * cron jobs or workers may not be available. Each page request
 * processes 1-2 jobs transparently.
 */
class BackgroundProcessorMiddleware implements MiddlewareInterface
{
    private DownloadService $downloadService;
    
    // How many jobs to process per request
    private int $jobsPerRequest = 1;
    
    // Skip processing for these paths (AJAX endpoints, static files, etc.)
    private array $skipPaths = [
        '/download/process',  // Avoid double-processing
        '/stream/',           // Don't slow down audio streaming
        '/static/',           // Static files
        '/partials/',         // HTMX partials - process only on full page loads
    ];

    public function __construct(DownloadService $downloadService)
    {
        $this->downloadService = $downloadService;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        // Skip certain paths
        foreach ($this->skipPaths as $skipPath) {
            if (str_starts_with($path, $skipPath)) {
                return $handler->handle($request);
            }
        }
        
        // Process the actual request first (so user doesn't wait)
        $response = $handler->handle($request);
        
        // Then process queued jobs in the background
        // Use register_shutdown_function so it runs after response is sent
        register_shutdown_function(function() {
            $this->processJobs();
        });
        
        return $response;
    }
    
    private function processJobs(): void
    {
        // Flush output to client first
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Process jobs
        for ($i = 0; $i < $this->jobsPerRequest; $i++) {
            $job = $this->downloadService->getNextQueuedJob();
            if (!$job) {
                break;
            }
            
            try {
                $this->downloadService->processDownload($job['id']);
            } catch (\Exception $e) {
                // Log error but don't crash
                error_log("Background job processing failed: " . $e->getMessage());
            }
        }
    }
}
