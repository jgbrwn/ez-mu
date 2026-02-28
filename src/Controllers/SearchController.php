<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\SearchService;
use App\Services\MusicLibrary;
use App\Services\QueueService;
use App\Services\RateLimiter;

class SearchController
{
    private Twig $twig;
    private SearchService $searchService;
    private MusicLibrary $musicLibrary;
    private QueueService $queueService;
    private RateLimiter $rateLimiter;

    public function __construct(
        Twig $twig,
        SearchService $searchService,
        MusicLibrary $musicLibrary,
        QueueService $queueService,
        RateLimiter $rateLimiter
    ) {
        $this->twig = $twig;
        $this->searchService = $searchService;
        $this->musicLibrary = $musicLibrary;
        $this->queueService = $queueService;
        $this->rateLimiter = $rateLimiter;
    }

    public function search(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $query = trim($data['query'] ?? '');
        $source = $data['source'] ?? 'all';
        
        // Input length validation
        if (mb_strlen($query) > 500) {
            return $this->twig->render($response, 'partials/results.twig', [
                'results' => [],
                'error' => 'Search query too long (max 500 characters)',
            ]);
        }

        if (empty($query)) {
            return $this->twig->render($response, 'partials/results.twig', [
                'results' => [],
                'message' => 'Please enter a search query',
            ]);
        }
        
        // Rate limiting: 30 searches per minute per action, 100 total per IP
        $ip = $this->getClientIp($request);
        if (!$this->rateLimiter->checkLimit('search', $ip, 30, 100, 60)) {
            return $this->twig->render($response, 'partials/results.twig', [
                'results' => [],
                'error' => 'Too many search requests. Please wait a moment.',
            ])->withStatus(429);
        }

        try {
            $results = [];
            $errors = [];
            
            switch ($source) {
                case 'youtube':
                    $results = $this->searchService->searchYouTube($query);
                    break;
                case 'soundcloud':
                    $results = $this->searchService->searchSoundCloud($query);
                    break;
                default:
                    $searchResponse = $this->searchService->searchAll($query);
                    $results = $searchResponse['results'];
                    $errors = $searchResponse['errors'];
            }

            // Build message for empty results
            $message = null;
            if (empty($results)) {
                if (!empty($errors)) {
                    $message = implode('; ', $errors);
                } else {
                    $message = 'No results found';
                }
            }

            // Get library and queue status for results
            $libraryVideoIds = $this->musicLibrary->getLibraryVideoIds();
            $queuedVideoIds = $this->queueService->getActiveVideoIds();
            
            // Mark results with their status
            foreach ($results as &$result) {
                $result['in_library'] = in_array($result['video_id'], $libraryVideoIds);
                $result['in_queue'] = in_array($result['video_id'], $queuedVideoIds);
            }

            return $this->twig->render($response, 'partials/results.twig', [
                'results' => $results,
                'query' => $query,
                'message' => $message,
                'warnings' => !empty($results) && !empty($errors) ? $errors : null,
            ]);
        } catch (\Exception $e) {
            return $this->twig->render($response, 'partials/results.twig', [
                'results' => [],
                'error' => 'Search failed: ' . $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        $headers = ['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP'];
        
        foreach ($headers as $header) {
            $value = $request->getHeaderLine($header);
            if (!empty($value)) {
                $ips = array_map('trim', explode(',', $value));
                return $ips[0];
            }
        }
        
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
