<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\DownloadService;
use App\Services\QueueService;

class DownloadController
{
    private Twig $twig;
    private DownloadService $downloadService;
    private QueueService $queueService;

    public function __construct(
        Twig $twig, 
        DownloadService $downloadService,
        QueueService $queueService
    ) {
        $this->twig = $twig;
        $this->downloadService = $downloadService;
        $this->queueService = $queueService;
    }

    /**
     * Queue a download (HTMX endpoint)
     */
    public function queue(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $videoId = $data['video_id'] ?? '';
        
        $title = $data['title'] ?? 'Unknown';
        $artist = $data['artist'] ?? 'Unknown';
        
        // Check if already queued/completed
        if ($this->queueService->isAlreadyQueued($videoId)) {
            $existing = $this->queueService->findByVideoId($videoId);
            $status = $existing['status'] ?? 'queued';
            
            return $this->twig->render($response, 'partials/download_button.twig', [
                'status' => $status === 'completed' ? 'already_downloaded' : 'already_queued',
                'message' => $status === 'completed' ? 'Already in library' : 'Already in queue',
                'video_id' => $videoId,
                'title' => $title,
                'artist' => $artist,
            ]);
        }
        
        try {
            $jobId = $this->downloadService->queueDownload([
                'video_id' => $videoId,
                'source' => $data['source'] ?? 'youtube',
                'title' => $data['title'] ?? 'Unknown',
                'artist' => $data['artist'] ?? 'Unknown',
                'url' => $data['url'] ?? '',
                'thumbnail' => $data['thumbnail'] ?? '',
                'download_type' => 'single',
                'convert_to_flac' => $data['convert_to_flac'] ?? '1',
            ]);

            // Return an HTMX response that updates the button state
            return $this->twig->render($response, 'partials/download_button.twig', [
                'job_id' => $jobId,
                'status' => 'queued',
                'message' => 'Added to queue',
                'video_id' => $videoId,
                'title' => $title,
                'artist' => $artist,
            ]);
        } catch (\Exception $e) {
            return $this->twig->render($response, 'partials/download_button.twig', [
                'status' => 'error',
                'message' => 'Failed: ' . $e->getMessage(),
                'video_id' => $videoId,
                'title' => $title,
                'artist' => $artist,
            ]);
        }
    }

    /**
     * Process next queued download (called by background worker)
     */
    public function process(Request $request, Response $response): Response
    {
        $job = $this->downloadService->getNextQueuedJob();
        
        if (!$job) {
            $response->getBody()->write(json_encode(['status' => 'idle', 'message' => 'No jobs in queue']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $success = $this->downloadService->processDownload($job['id']);

        $response->getBody()->write(json_encode([
            'status' => $success ? 'completed' : 'failed',
            'job_id' => $job['id'],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Cron endpoint for external services (e.g., cron-job.org)
     * 
     * GET /cron/process?key=YOUR_SECRET&count=5
     * 
     * - key: Required secret key (set CRON_SECRET in .env)
     * - count: Number of jobs to process (default 5, max 20)
     * 
     * If CRON_SECRET is not set, this endpoint is disabled for security.
     */
    public function cronProcess(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        // Get configured secret - if not set, endpoint is disabled
        $configuredKey = $_ENV['CRON_SECRET'] ?? '';
        
        // If no secret is configured, disable the endpoint entirely
        if (empty($configuredKey)) {
            $response->getBody()->write(json_encode([
                'error' => 'Cron endpoint disabled',
                'message' => 'Set CRON_SECRET in .env to enable this endpoint',
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        // Get provided key from query param or Authorization header
        $providedKey = $params['key'] ?? '';
        if (empty($providedKey)) {
            // Check Authorization header as alternative
            $authHeader = $request->getHeaderLine('Authorization');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $providedKey = substr($authHeader, 7);
            }
        }
        
        // Use timing-safe comparison to prevent timing attacks
        if (empty($providedKey) || !hash_equals($configuredKey, $providedKey)) {
            // Log failed attempt for monitoring
            error_log('Cron endpoint: Invalid authentication attempt from ' . 
                ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'));
            
            $response->getBody()->write(json_encode([
                'error' => 'Invalid or missing key',
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        
        // How many jobs to process
        $count = min(20, max(1, (int)($params['count'] ?? 5)));
        
        $processed = 0;
        $results = [];
        
        for ($i = 0; $i < $count; $i++) {
            $job = $this->downloadService->getNextQueuedJob();
            if (!$job) {
                break;
            }
            
            try {
                $success = $this->downloadService->processDownload($job['id']);
                $results[] = [
                    'job_id' => $job['id'],
                    'title' => $job['title'] ?? 'Unknown',
                    'status' => $success ? 'completed' : 'failed',
                ];
                $processed++;
            } catch (\Exception $e) {
                $results[] = [
                    'job_id' => $job['id'],
                    'title' => $job['title'] ?? 'Unknown',
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // Get queue stats
        $stats = $this->queueService->getStats();
        
        $response->getBody()->write(json_encode([
            'processed' => $processed,
            'results' => $results,
            'queue' => $stats,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
