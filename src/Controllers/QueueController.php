<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\QueueService;
use App\Services\DownloadService;
use App\Services\MusicLibrary;

class QueueController
{
    private Twig $twig;
    private QueueService $queueService;
    private DownloadService $downloadService;
    private MusicLibrary $musicLibrary;

    public function __construct(
        Twig $twig,
        QueueService $queueService,
        DownloadService $downloadService,
        MusicLibrary $musicLibrary
    ) {
        $this->twig = $twig;
        $this->queueService = $queueService;
        $this->downloadService = $downloadService;
        $this->musicLibrary = $musicLibrary;
    }

    /**
     * Queue page
     */
    public function index(Request $request, Response $response): Response
    {
        // Auto-clear completed jobs older than 30 minutes
        $this->queueService->clearOldCompleted(30);
        
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = 25;
        
        $jobs = $this->queueService->getJobs(null, $perPage, ($page - 1) * $perPage);
        $stats = $this->queueService->getStats();
        $totalJobs = $stats['queued'] + $stats['processing'] + $stats['completed'] + $stats['failed'];
        $totalPages = ceil($totalJobs / $perPage);

        return $this->twig->render($response, 'queue.twig', [
            'jobs' => $jobs,
            'stats' => $stats,
            'page' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
        ]);
    }

    /**
     * Queue partial for HTMX polling
     */
    public function queuePartial(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = 25;
        
        $jobs = $this->queueService->getJobs(null, $perPage, ($page - 1) * $perPage);
        $stats = $this->queueService->getStats();
        $totalJobs = $stats['queued'] + $stats['processing'] + $stats['completed'] + $stats['failed'];
        $totalPages = ceil($totalJobs / $perPage);

        return $this->twig->render($response, 'partials/queue_list.twig', [
            'jobs' => $jobs,
            'stats' => $stats,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * Get queue status (JSON)
     */
    public function status(Request $request, Response $response): Response
    {
        $stats = $this->queueService->getStats();
        $pending = $this->downloadService->getPendingCount();

        $response->getBody()->write(json_encode([
            'stats' => $stats,
            'pending' => $pending,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Retry a failed job
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $success = $this->queueService->retryJob($id);

        if ($success) {
            $job = $this->queueService->getJob($id);
            return $this->twig->render($response, 'partials/queue_item.twig', [
                'job' => $job,
            ]);
        }

        $response->getBody()->write('Failed to retry job');
        return $response->withStatus(400);
    }

    /**
     * Delete a job
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $this->queueService->deleteJob($id);

        // Return empty response to remove the element
        return $response->withStatus(200);
    }

    /**
     * Clear completed/failed jobs
     */
    public function clear(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $status = $data['status'] ?? null;
        
        $this->queueService->clearJobs($status);

        // Return updated queue list
        return $this->queuePartial($request, $response);
    }

    /**
     * Get job status partial for search results polling
     */
    public function jobStatusPartial(Request $request, Response $response, array $args): Response
    {
        $videoId = $args['videoId'];
        
        // Always get job info for title/artist/thumbnail
        $job = $this->queueService->findByVideoId($videoId);
        
        // Check if now in library
        if ($this->musicLibrary->trackExistsWithFile($videoId)) {
            // Get track info from library for display
            $track = $this->musicLibrary->getTrackByVideoId($videoId);
            return $this->twig->render($response, 'partials/search_item_status.twig', [
                'status' => 'completed',
                'video_id' => $videoId,
                'job' => $job,
                'track' => $track,
            ]);
        }
        
        if (!$job) {
            // No job found, not in library - shouldn't happen normally
            return $this->twig->render($response, 'partials/search_item_status.twig', [
                'status' => 'unknown',
                'video_id' => $videoId,
            ]);
        }
        
        return $this->twig->render($response, 'partials/search_item_status.twig', [
            'status' => $job['status'],
            'video_id' => $videoId,
            'job' => $job,
        ]);
    }
}
