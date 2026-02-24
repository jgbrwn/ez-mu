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
        
        // Check if already queued/completed
        if ($this->queueService->isAlreadyQueued($videoId)) {
            $existing = $this->queueService->findByVideoId($videoId);
            $status = $existing['status'] ?? 'queued';
            
            return $this->twig->render($response, 'partials/download_button.twig', [
                'status' => $status === 'completed' ? 'already_downloaded' : 'already_queued',
                'message' => $status === 'completed' ? 'Already in library' : 'Already in queue',
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
            ]);
        } catch (\Exception $e) {
            return $this->twig->render($response, 'partials/download_button.twig', [
                'status' => 'error',
                'message' => 'Failed: ' . $e->getMessage(),
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
     * - key: Optional secret key (set CRON_SECRET in .env)
     * - count: Number of jobs to process (default 5, max 20)
     */
    public function cronProcess(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        
        // Check secret key if configured
        $configuredKey = $_ENV['CRON_SECRET'] ?? '';
        $providedKey = $params['key'] ?? '';
        
        if ($configuredKey && $providedKey !== $configuredKey) {
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
