<?php

namespace App\Controllers;

use App\Services\WatchedPlaylistService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class WatchedController
{
    private Twig $view;
    private WatchedPlaylistService $watchedService;

    public function __construct(Twig $view, WatchedPlaylistService $watchedService)
    {
        $this->view = $view;
        $this->watchedService = $watchedService;
    }

    /**
     * List all watched playlists
     */
    public function index(Request $request, Response $response): Response
    {
        // Sync track statuses with completed jobs
        $this->watchedService->syncTrackStatuses();

        // Check for playlists needing refresh
        $dueForRefresh = $this->watchedService->getPlaylistsDueForRefresh();

        $playlists = $this->watchedService->getAllPlaylists();

        return $this->view->render($response, 'watched/index.twig', [
            'playlists' => $playlists,
            'due_for_refresh' => count($dueForRefresh),
            'active_page' => 'watched'
        ]);
    }

    /**
     * Add a new watched playlist
     */
    public function add(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $url = trim($data['url'] ?? '');

        if (empty($url)) {
            return $this->view->render($response, 'watched/_add_result.twig', [
                'error' => 'Please enter a playlist URL'
            ]);
        }

        $options = [
            'name' => $data['name'] ?? null,
            'sync_mode' => $data['sync_mode'] ?? 'append',
            'make_m3u' => isset($data['make_m3u']) ? 1 : 0,
            'refresh_interval_hours' => (int)($data['refresh_interval'] ?? 24)
        ];

        $result = $this->watchedService->addPlaylist($url, $options);

        if (!$result['success']) {
            return $this->view->render($response, 'watched/_add_result.twig', [
                'error' => $result['error']
            ]);
        }

        // Don't auto-queue on add - let user trigger it via the Queue button
        // This prevents timeout on large playlists

        return $this->view->render($response, 'watched/_add_result.twig', [
            'success' => true,
            'playlist_name' => $result['name'],
            'tracks_count' => $result['tracks_count'],
            'queued_count' => 0,
            'playlist_id' => $result['playlist_id']
        ]);
    }

    /**
     * View a single playlist
     */
    public function view(Request $request, Response $response, array $args): Response
    {
        // Sync track statuses with completed jobs
        $this->watchedService->syncTrackStatuses();

        $id = $args['id'];
        $playlist = $this->watchedService->getPlaylist($id);

        if (!$playlist) {
            $response = $response->withStatus(404);
            return $this->view->render($response, 'error.twig', [
                'message' => 'Playlist not found'
            ]);
        }

        $queryParams = $request->getQueryParams();
        $statusFilter = $queryParams['status'] ?? null;
        $page = max(1, (int)($queryParams['page'] ?? 1));
        
        $trackData = $this->watchedService->getPlaylistTracks($id, $statusFilter, $page, 25);

        return $this->view->render($response, 'watched/view.twig', [
            'playlist' => $playlist,
            'tracks' => $trackData['tracks'],
            'pagination' => [
                'page' => $trackData['page'],
                'total_pages' => $trackData['total_pages'],
                'total' => $trackData['total'],
                'per_page' => $trackData['per_page']
            ],
            'status_filter' => $statusFilter,
            'active_page' => 'watched'
        ]);
    }

    /**
     * Delete a watched playlist
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $this->watchedService->deletePlaylist($id);

        // Return updated list for HTMX
        $playlists = $this->watchedService->getAllPlaylists();
        return $this->view->render($response, 'watched/_playlist_list.twig', [
            'playlists' => $playlists
        ]);
    }

    /**
     * Toggle playlist enabled state
     */
    public function toggle(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $this->watchedService->togglePlaylist($id);

        $playlist = $this->watchedService->getPlaylist($id);
        return $this->view->render($response, 'watched/_playlist_row.twig', [
            'playlist' => $playlist
        ]);
    }

    /**
     * Refresh a playlist (check for new tracks)
     */
    public function refresh(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $result = $this->watchedService->refreshPlaylist($id);

        if (!$result['success']) {
            return $this->view->render($response, 'watched/_refresh_result.twig', [
                'error' => $result['error'],
                'playlist_id' => $id
            ]);
        }

        // Queue any new pending tracks
        if ($result['new_tracks'] > 0) {
            $this->watchedService->queuePendingTracks($id, 50);
        }

        $playlist = $this->watchedService->getPlaylist($id);

        return $this->view->render($response, 'watched/_refresh_result.twig', [
            'success' => true,
            'new_tracks' => $result['new_tracks'],
            'removed_tracks' => $result['removed_tracks'],
            'playlist' => $playlist
        ]);
    }

    /**
     * Refresh all due playlists
     */
    public function refreshAll(Request $request, Response $response): Response
    {
        $duePlaylists = $this->watchedService->getPlaylistsDueForRefresh();
        $results = [];

        foreach ($duePlaylists as $playlist) {
            $result = $this->watchedService->refreshPlaylist($playlist['id']);
            $result['name'] = $playlist['name'];
            $results[] = $result;

            if ($result['success'] && $result['new_tracks'] > 0) {
                $this->watchedService->queuePendingTracks($playlist['id'], 50);
            }
        }

        $playlists = $this->watchedService->getAllPlaylists();

        return $this->view->render($response, 'watched/_refresh_all_result.twig', [
            'results' => $results,
            'playlists' => $playlists
        ]);
    }

    /**
     * Queue pending tracks for download
     */
    public function queueTracks(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        // Limit batch size to prevent timeout (each track = 1 API search)
        $result = $this->watchedService->queuePendingTracks($id, 5);

        $playlist = $this->watchedService->getPlaylist($id);
        $trackData = $this->watchedService->getPlaylistTracks($id, null, 1, 25);

        return $this->view->render($response, 'watched/_queue_result.twig', [
            'result' => $result,
            'playlist' => $playlist,
            'tracks' => $trackData['tracks'],
            'pagination' => [
                'page' => $trackData['page'],
                'total_pages' => $trackData['total_pages'],
                'total' => $trackData['total']
            ]
        ]);
    }

    /**
     * Retry failed tracks
     */
    public function retryFailed(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $result = $this->watchedService->retryFailedTracks($id);

        if ($result['reset_count'] > 0) {
            $this->watchedService->queuePendingTracks($id, 50);
        }

        $playlist = $this->watchedService->getPlaylist($id);
        $trackData = $this->watchedService->getPlaylistTracks($id, null, 1, 25);

        return $this->view->render($response, 'watched/_tracks_list.twig', [
            'playlist' => $playlist,
            'tracks' => $trackData['tracks'],
            'pagination' => [
                'page' => $trackData['page'],
                'total_pages' => $trackData['total_pages'],
                'total' => $trackData['total']
            ],
            'reset_count' => $result['reset_count']
        ]);
    }

    /**
     * Generate/regenerate M3U for a playlist
     */
    public function generateM3u(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $result = $this->watchedService->generateM3u($id);

        return $this->view->render($response, 'watched/_m3u_result.twig', [
            'result' => $result,
            'playlist_id' => $id
        ]);
    }

    /**
     * API: Get playlist status (for JS polling)
     */
    public function status(Request $request, Response $response, array $args): Response
    {
        // Sync track statuses first
        $this->watchedService->syncTrackStatuses();

        $id = $args['id'];
        $playlist = $this->watchedService->getPlaylist($id);

        if (!$playlist) {
            $response->getBody()->write(json_encode(['error' => 'Playlist not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $data = [
            'total' => (int)($playlist['total_tracks'] ?? 0),
            'downloaded' => (int)($playlist['downloaded_tracks'] ?? 0),
            'queued' => (int)($playlist['queued_tracks'] ?? 0),
            'pending' => (int)($playlist['pending_tracks'] ?? 0),
            'failed' => (int)($playlist['failed_tracks'] ?? 0),
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Queue next batch (for JS auto-queueing)
     */
    public function queueBatch(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $result = $this->watchedService->queuePendingTracks($id, 5);

        $playlist = $this->watchedService->getPlaylist($id);

        $data = [
            'queued' => $result['queued'] ?? 0,
            'errors' => $result['errors'] ?? [],
            'remaining_pending' => (int)($playlist['pending_tracks'] ?? 0),
            'remaining_queued' => (int)($playlist['queued_tracks'] ?? 0),
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
