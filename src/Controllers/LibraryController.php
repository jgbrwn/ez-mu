<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\MusicLibrary;
use App\Services\SettingsService;
use App\Services\FileStreamer;

class LibraryController
{
    private Twig $twig;
    private MusicLibrary $library;
    private SettingsService $settings;

    public function __construct(Twig $twig, MusicLibrary $library, SettingsService $settings)
    {
        $this->twig = $twig;
        $this->library = $library;
        $this->settings = $settings;
    }

    /**
     * Library page
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = $params['q'] ?? '';
        $sort = $params['sort'] ?? 'recent';
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        
        if (!empty($query)) {
            $tracks = $this->library->searchTracks($query, $sort, $perPage, $offset);
            $totalTracks = $this->library->getSearchCount($query);
        } else {
            $tracks = $this->library->getTracks($sort, $perPage, $offset);
            $totalTracks = $this->library->getTrackCount();
        }
        
        $totalPages = (int)ceil($totalTracks / $perPage);
        $stats = $this->library->getStats();
        $artists = $this->library->getArtists();

        return $this->twig->render($response, 'library.twig', [
            'tracks' => $tracks,
            'stats' => $stats,
            'artists' => $artists,
            'query' => $query,
            'sort' => $sort,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalTracks' => $totalTracks,
            'perPage' => $perPage,
            'autoplayNext' => $this->settings->get('autoplay_next', '0') === '1',
            'is_htmx' => false, // Initial page load, don't send OOB swap
        ]);
    }

    /**
     * Library partial for HTMX
     */
    public function libraryPartial(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = $params['q'] ?? '';
        $sort = $params['sort'] ?? 'recent';
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        
        if (!empty($query)) {
            $tracks = $this->library->searchTracks($query, $sort, $perPage, $offset);
            $totalTracks = $this->library->getSearchCount($query);
        } else {
            $tracks = $this->library->getTracks($sort, $perPage, $offset);
            $totalTracks = $this->library->getTrackCount();
        }
        
        $totalPages = (int)ceil($totalTracks / $perPage);
        $stats = $this->library->getStats();

        return $this->twig->render($response, 'partials/library_list.twig', [
            'tracks' => $tracks,
            'query' => $query,
            'sort' => $sort,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalTracks' => $totalTracks,
            'stats' => $stats,
        ]);
    }

    /**
     * Download selected tracks as zip
     */
    public function downloadSelected(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $trackIds = $data['tracks'] ?? [];

        if (empty($trackIds)) {
            $response->getBody()->write('No tracks selected');
            return $response->withStatus(400);
        }

        // If only one track, stream it directly
        if (count($trackIds) === 1) {
            $track = $this->library->getTrack($trackIds[0]);
            if ($track && file_exists($track['file_path'])) {
                $filename = $track['artist'] . ' - ' . $track['title'] . '.' . pathinfo($track['file_path'], PATHINFO_EXTENSION);
                
                // Use chunked streaming for large FLAC files
                FileStreamer::streamFile(
                    $response,
                    $track['file_path'],
                    $filename,
                    'audio/flac',
                    false // Don't delete the original file
                );
                // streamFile exits, so this won't be reached
                return $response;
            }
        }

        try {
            $zipPath = $this->library->createZip($trackIds);
            
            // Generate random 6-character hash for unique filename
            // Uses bin2hex(random_bytes) which is available in PHP 7+ and shared hosting compatible
            $hash = substr(bin2hex(random_bytes(3)), 0, 6);
            
            // Use chunked streaming for zip files (shared hosting compatible)
            FileStreamer::streamFile(
                $response,
                $zipPath,
                "ez-mu-download-{$hash}.zip",
                'application/zip',
                true // Delete temp zip after streaming
            );
            // streamFile exits, so this won't be reached
            return $response;
        } catch (\Exception $e) {
            $response->getBody()->write('Failed to create download: ' . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    /**
     * Delete a track
     */
    public function deleteTrack(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $success = $this->library->deleteTrack($id);

        if ($success) {
            return $response->withStatus(200);
        }

        $response->getBody()->write('Track not found');
        return $response->withStatus(404);
    }

    /**
     * Delete all tracks from library
     */
    public function deleteAll(Request $request, Response $response): Response
    {
        $count = $this->library->deleteAllTracks();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'deleted' => $count
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
