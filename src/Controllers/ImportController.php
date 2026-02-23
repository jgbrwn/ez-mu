<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\PlaylistService;
use App\Services\SearchService;
use App\Services\DownloadService;

class ImportController
{
    private Twig $twig;
    private PlaylistService $playlistService;
    private SearchService $searchService;
    private DownloadService $downloadService;

    public function __construct(
        Twig $twig,
        PlaylistService $playlistService,
        SearchService $searchService,
        DownloadService $downloadService
    ) {
        $this->twig = $twig;
        $this->playlistService = $playlistService;
        $this->searchService = $searchService;
        $this->downloadService = $downloadService;
    }

    /**
     * Import page
     */
    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'import.twig', []);
    }

    /**
     * Fetch playlist tracks
     */
    public function fetchPlaylist(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $url = trim($data['url'] ?? '');

        if (empty($url)) {
            return $this->twig->render($response, 'partials/import_result.twig', [
                'error' => 'Please enter a playlist URL',
            ]);
        }

        try {
            $playlist = $this->playlistService->fetchPlaylist($url);
            
            return $this->twig->render($response, 'partials/import_result.twig', [
                'playlist' => $playlist,
            ]);
        } catch (\Exception $e) {
            return $this->twig->render($response, 'partials/import_result.twig', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Import tracks from text input
     */
    public function importTracks(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tracksText = trim($data['tracks'] ?? '');

        if (empty($tracksText)) {
            return $this->twig->render($response, 'partials/import_status.twig', [
                'error' => 'No tracks provided',
            ]);
        }

        // Parse tracks (one per line, format: "Artist - Title")
        $lines = array_filter(array_map('trim', explode("\n", $tracksText)));
        $queued = 0;
        $failed = 0;
        $results = [];

        foreach ($lines as $line) {
            // Clean up the line
            $line = preg_replace('/^\d+\.?\s*/', '', $line); // Remove track numbers
            $line = preg_replace('/[\x{2013}\x{2014}]/u', '-', $line); // Normalize dashes
            
            if (empty($line)) continue;

            // Search for the track (prioritize Monochrome)
            $searchResults = $this->searchService->searchMonochrome($line, 3);
            
            if (empty($searchResults)) {
                // Fallback to SoundCloud
                $searchResults = $this->searchService->searchSoundCloud($line, 3);
            }

            if (!empty($searchResults)) {
                $best = $searchResults[0];
                try {
                    $this->downloadService->queueDownload([
                        'video_id' => $best['video_id'],
                        'source' => $best['source'],
                        'title' => $best['title'],
                        'artist' => $best['artist'],
                        'url' => $best['url'],
                        'thumbnail' => $best['thumbnail'] ?? '',
                    ]);
                    $queued++;
                    $results[] = ['track' => $line, 'status' => 'queued', 'match' => $best['title'] . ' - ' . $best['artist']];
                } catch (\Exception $e) {
                    $failed++;
                    $results[] = ['track' => $line, 'status' => 'failed', 'error' => $e->getMessage()];
                }
            } else {
                $failed++;
                $results[] = ['track' => $line, 'status' => 'not_found'];
            }

            // Small delay between searches
            usleep(500000); // 500ms
        }

        return $this->twig->render($response, 'partials/import_status.twig', [
            'queued' => $queued,
            'failed' => $failed,
            'total' => count($lines),
            'results' => $results,
        ]);
    }
}
