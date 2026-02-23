<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\DownloadService;

class DownloadController
{
    private Twig $twig;
    private DownloadService $downloadService;

    public function __construct(Twig $twig, DownloadService $downloadService)
    {
        $this->twig = $twig;
        $this->downloadService = $downloadService;
    }

    /**
     * Queue a download (HTMX endpoint)
     */
    public function queue(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        try {
            $jobId = $this->downloadService->queueDownload([
                'video_id' => $data['video_id'] ?? '',
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
}
