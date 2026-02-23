<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\SearchService;

class SearchController
{
    private Twig $twig;
    private SearchService $searchService;

    public function __construct(Twig $twig, SearchService $searchService)
    {
        $this->twig = $twig;
        $this->searchService = $searchService;
    }

    public function search(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $query = trim($data['query'] ?? '');
        $source = $data['source'] ?? 'all';

        if (empty($query)) {
            return $this->twig->render($response, 'partials/results.twig', [
                'results' => [],
                'message' => 'Please enter a search query',
            ]);
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
}
