<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\PlaylistService;
use App\Services\SearchService;
use App\Services\DownloadService;
use App\Services\QueueService;
use App\Services\WatchedPlaylistService;

class ImportController
{
    private Twig $twig;
    private PlaylistService $playlistService;
    private SearchService $searchService;
    private DownloadService $downloadService;
    private QueueService $queueService;
    private WatchedPlaylistService $watchedService;

    public function __construct(
        Twig $twig,
        PlaylistService $playlistService,
        SearchService $searchService,
        DownloadService $downloadService,
        QueueService $queueService,
        WatchedPlaylistService $watchedService
    ) {
        $this->twig = $twig;
        $this->playlistService = $playlistService;
        $this->searchService = $searchService;
        $this->downloadService = $downloadService;
        $this->queueService = $queueService;
        $this->watchedService = $watchedService;
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

        $addToWatched = !empty($data['add_to_watched']);

        try {
            $playlist = $this->playlistService->fetchPlaylist($url);
            
            // Add to watched playlists if requested (entry only, tracks added on first refresh)
            $watchedResult = null;
            if ($addToWatched) {
                // Only create the playlist entry, not the tracks
                // Tracks will be populated on first refresh, at which point
                // already-downloaded tracks will be detected and marked appropriately
                $watchedResult = $this->watchedService->addPlaylistEntryOnly($url, [
                    'name' => $playlist['name'] ?? null,
                    'sync_mode' => 'append',
                    'make_m3u' => true
                ]);
            }
            
            return $this->twig->render($response, 'partials/import_result.twig', [
                'playlist' => $playlist,
                'url' => $url,
                'watched_result' => $watchedResult,
            ]);
        } catch (\Exception $e) {
            return $this->twig->render($response, 'partials/import_result.twig', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start batch import - stores tracks in session and returns UI for batch processing
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
        
        // Clean up lines
        $tracks = [];
        foreach ($lines as $line) {
            $line = preg_replace('/^\d+\.?\s*/', '', $line); // Remove track numbers
            $line = preg_replace('/[\x{2013}\x{2014}]/u', '-', $line); // Normalize dashes
            if (!empty($line)) {
                $tracks[] = $line;
            }
        }

        $totalTracks = count($tracks);
        if ($totalTracks === 0) {
            return $this->twig->render($response, 'partials/import_status.twig', [
                'error' => 'No valid tracks found',
            ]);
        }

        if ($totalTracks > 200) {
            return $this->twig->render($response, 'partials/import_status.twig', [
                'error' => "Too many tracks ($totalTracks). Please import 200 or fewer tracks at a time.",
            ]);
        }

        // Clean up old import sessions (older than 1 hour)
        $this->cleanupOldImportSessions();
        
        // Generate import session ID
        $importId = bin2hex(random_bytes(8));
        
        // Store tracks in a temp file (shared hosting compatible - no Redis/sessions needed)
        $importFile = sys_get_temp_dir() . "/ezmu_import_{$importId}.json";
        
        // Check import data size (max 512KB)
        $importData = json_encode([
            'tracks' => $tracks,
            'processed' => 0,
            'queued' => 0,
            'skipped' => 0,
            'failed' => 0,
            'results' => [],
            'created_at' => time()
        ]);
        
        // Size limit check (512KB max)
        if (strlen($importData) > 524288) {
            return $this->twig->render($response, 'partials/import_status.twig', [
                'error' => 'Import data too large. Please reduce the number of tracks.',
            ]);
        }
        
        file_put_contents($importFile, $importData);

        return $this->twig->render($response, 'partials/import_batch.twig', [
            'import_id' => $importId,
            'total' => $totalTracks,
        ]);
    }

    /**
     * Process next batch of import (called via JS polling)
     */
    public function importBatch(Request $request, Response $response): Response
    {
        $importId = $request->getAttribute('id');
        $importFile = sys_get_temp_dir() . "/ezmu_import_{$importId}.json";
        
        if (!file_exists($importFile)) {
            $response->getBody()->write(json_encode(['error' => 'Import session not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $data = json_decode(file_get_contents($importFile), true);
        $tracks = $data['tracks'];
        $processed = $data['processed'];
        $batchSize = 5;

        // Process next batch
        $batch = array_slice($tracks, $processed, $batchSize);
        $batchResults = [];

        foreach ($batch as $line) {
            $result = $this->processTrack($line);
            $batchResults[] = $result;
            
            if ($result['status'] === 'queued') {
                $data['queued']++;
            } elseif ($result['status'] === 'skipped') {
                $data['skipped']++;
            } else {
                $data['failed']++;
            }
            $data['processed']++;
        }

        $data['results'] = array_merge($data['results'], $batchResults);
        
        // Save updated state
        file_put_contents($importFile, json_encode($data));

        $remaining = count($tracks) - $data['processed'];
        $complete = $remaining === 0;

        // Clean up if complete
        if ($complete) {
            unlink($importFile);
        }

        $response->getBody()->write(json_encode([
            'processed' => $data['processed'],
            'total' => count($tracks),
            'queued' => $data['queued'],
            'skipped' => $data['skipped'],
            'failed' => $data['failed'],
            'remaining' => $remaining,
            'complete' => $complete,
            'batch_results' => $batchResults,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Process a single track (search and queue)
     */
    private function processTrack(string $line): array
    {
        // Search for the track (prioritize Monochrome)
        $monoResponse = $this->searchService->searchMonochrome($line, 3);
        $searchResults = $monoResponse['results'] ?? [];
        
        if (empty($searchResults)) {
            // Fallback to SoundCloud
            $searchResults = $this->searchService->searchSoundCloud($line, 3);
        }

        if (!empty($searchResults)) {
            $best = $searchResults[0];
            
            // Check for duplicates
            if ($this->queueService->isAlreadyQueued($best['video_id'])) {
                return [
                    'track' => $line,
                    'status' => 'skipped',
                    'match' => $best['title'] . ' - ' . $best['artist']
                ];
            }
            
            try {
                $this->downloadService->queueDownload([
                    'video_id' => $best['video_id'],
                    'source' => $best['source'],
                    'title' => $best['title'],
                    'artist' => $best['artist'],
                    'url' => $best['url'],
                    'thumbnail' => $best['thumbnail'] ?? '',
                ]);
                return [
                    'track' => $line,
                    'status' => 'queued',
                    'match' => $best['artist'] . ' - ' . $best['title']
                ];
            } catch (\Exception $e) {
                return [
                    'track' => $line,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'track' => $line,
            'status' => 'not_found'
        ];
    }
    
    /**
     * Clean up old import session files (older than 1 hour)
     */
    private function cleanupOldImportSessions(): void
    {
        $tempDir = sys_get_temp_dir();
        $pattern = $tempDir . '/ezmu_import_*.json';
        $maxAge = 3600; // 1 hour
        $now = time();
        
        foreach (glob($pattern) as $file) {
            if ($now - filemtime($file) > $maxAge) {
                @unlink($file);
            }
        }
    }
}
