<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\MusicLibrary;
use App\Services\SettingsService;

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

        return $this->twig->render($response, 'partials/library_list.twig', [
            'tracks' => $tracks,
            'query' => $query,
            'sort' => $sort,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalTracks' => $totalTracks,
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
                
                return $response
                    ->withHeader('Content-Type', 'application/octet-stream')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Content-Length', (string)filesize($track['file_path']))
                    ->withBody(new \Slim\Psr7\Stream(fopen($track['file_path'], 'r')));
            }
        }

        try {
            $zipPath = $this->library->createZip($trackIds);
            
            $response = $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="ez-mu-download.zip"')
                ->withHeader('Content-Length', (string)filesize($zipPath));

            // Stream the zip file
            $stream = fopen($zipPath, 'r');
            $response = $response->withBody(new \Slim\Psr7\Stream($stream));

            // Clean up temp file after sending (register shutdown function)
            register_shutdown_function(function () use ($zipPath) {
                if (file_exists($zipPath)) {
                    unlink($zipPath);
                }
            });

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
}
