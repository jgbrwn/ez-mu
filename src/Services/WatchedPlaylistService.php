<?php

namespace App\Services;

use PDO;

class WatchedPlaylistService
{
    private PDO $db;
    private PlaylistService $playlistService;
    private QueueService $queueService;
    private DownloadService $downloadService;
    private SearchService $searchService;

    public function __construct(
        PDO $db,
        PlaylistService $playlistService,
        QueueService $queueService,
        DownloadService $downloadService,
        SearchService $searchService
    ) {
        $this->db = $db;
        $this->playlistService = $playlistService;
        $this->queueService = $queueService;
        $this->downloadService = $downloadService;
        $this->searchService = $searchService;
    }

    /**
     * Generate a unique hash for a track based on artist and title
     */
    public function generateTrackHash(string $artist, string $title): string
    {
        $normalized = strtolower(trim($artist)) . '|' . strtolower(trim($title));
        return hash('sha256', $normalized);
    }

    /**
     * Parse track string/array into normalized format
     * Handles both "Artist - Title" strings and associative arrays
     */
    /**
     * Check if a track is already in the library (by artist/title fuzzy match)
     */
    private function isTrackInLibrary(string $artist, string $title): bool
    {
        // Normalize for comparison
        $artistNorm = strtolower(trim($artist));
        $titleNorm = strtolower(trim($title));
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM library 
            WHERE LOWER(artist) = ? AND LOWER(title) = ?
        ");
        $stmt->execute([$artistNorm, $titleNorm]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Parse track string/array into normalized format
     */
    private function parseTrack($track): array
    {
        if (is_string($track)) {
            // Format: "Artist - Title"
            $parts = explode(' - ', $track, 2);
            return [
                'artist' => trim($parts[0] ?? 'Unknown Artist'),
                'title' => trim($parts[1] ?? $parts[0] ?? 'Unknown Title'),
                'video_id' => null
            ];
        } elseif (is_array($track)) {
            return [
                'artist' => $track['artist'] ?? 'Unknown Artist',
                'title' => $track['title'] ?? 'Unknown Title',
                'video_id' => $track['video_id'] ?? $track['id'] ?? null
            ];
        }
        return ['artist' => 'Unknown Artist', 'title' => 'Unknown Title', 'video_id' => null];
    }

    /**
     * Detect platform from URL
     */
    public function detectPlatform(string $url): ?string
    {
        if (preg_match('/spotify\.com/', $url)) {
            return 'spotify';
        } elseif (preg_match('/youtube\.com|youtu\.be/', $url)) {
            return 'youtube';
        } elseif (preg_match('/music\.amazon/', $url)) {
            return 'amazon';
        } elseif (preg_match('/tidal\.com/', $url)) {
            return 'tidal';
        }
        return null;
    }

    /**
     * Add a new watched playlist
     */
    /**
     * Add only the playlist entry (no tracks) - used when importing
     * Tracks will be populated on first refresh
     */
    public function addPlaylistEntryOnly(string $url, array $options = []): array
    {
        $platform = $this->detectPlatform($url);
        if (!$platform) {
            return ['success' => false, 'error' => 'Unsupported playlist URL'];
        }

        $id = bin2hex(random_bytes(8));
        $name = $options['name'] ?? 'Untitled Playlist';
        $syncMode = $options['sync_mode'] ?? 'append';
        $makeM3u = isset($options['make_m3u']) ? (int)$options['make_m3u'] : 1;
        $refreshInterval = $options['refresh_interval_hours'] ?? 24;

        try {
            $stmt = $this->db->prepare("
                INSERT INTO watched_playlists (id, url, name, platform, sync_mode, make_m3u, refresh_interval_hours, last_track_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([$id, $url, $name, $platform, $syncMode, $makeM3u, $refreshInterval]);

            return [
                'success' => true,
                'playlist_id' => $id,
                'name' => $name,
                'tracks_count' => 0,
                'platform' => $platform,
                'message' => 'Playlist will be populated on first refresh after import completes'
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Add a new watched playlist with tracks
     */
    public function addPlaylist(string $url, array $options = []): array
    {
        $platform = $this->detectPlatform($url);
        if (!$platform) {
            return ['success' => false, 'error' => 'Unsupported playlist URL'];
        }

        // Fetch initial playlist info
        $playlistData = $this->playlistService->fetchPlaylist($url);
        if (!$playlistData || empty($playlistData['tracks'])) {
            return ['success' => false, 'error' => 'Could not fetch playlist or playlist is empty'];
        }

        $id = bin2hex(random_bytes(8));
        $name = $options['name'] ?? $playlistData['name'] ?? 'Untitled Playlist';
        $syncMode = $options['sync_mode'] ?? 'append';
        $makeM3u = isset($options['make_m3u']) ? (int)$options['make_m3u'] : 1;
        $refreshInterval = $options['refresh_interval_hours'] ?? 24;

        try {
            $this->db->beginTransaction();

            // Insert playlist
            $stmt = $this->db->prepare("
                INSERT INTO watched_playlists (id, url, name, platform, sync_mode, make_m3u, refresh_interval_hours, last_checked, last_track_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?)
            ");
            $stmt->execute([$id, $url, $name, $platform, $syncMode, $makeM3u, $refreshInterval, count($playlistData['tracks'])]);

            // Add tracks
            $tracksAdded = 0;
            foreach ($playlistData['tracks'] as $track) {
                $parsed = $this->parseTrack($track);
                $artist = $parsed['artist'];
                $title = $parsed['title'];
                $trackHash = $this->generateTrackHash($artist, $title);

                $stmt = $this->db->prepare("
                    INSERT OR IGNORE INTO watched_playlist_tracks (playlist_id, track_hash, artist, title, video_id, status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([$id, $trackHash, $artist, $title, $parsed['video_id']]);
                $tracksAdded++;
            }

            $this->db->commit();

            return [
                'success' => true,
                'playlist_id' => $id,
                'name' => $name,
                'tracks_count' => $tracksAdded,
                'platform' => $platform
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Get all watched playlists
     */
    public function getAllPlaylists(): array
    {
        $stmt = $this->db->query("
            SELECT wp.*,
                   COUNT(wpt.id) as total_tracks,
                   SUM(CASE WHEN wpt.status = 'downloaded' THEN 1 ELSE 0 END) as downloaded_tracks,
                   SUM(CASE WHEN wpt.status = 'pending' THEN 1 ELSE 0 END) as pending_tracks,
                   SUM(CASE WHEN wpt.status = 'queued' THEN 1 ELSE 0 END) as queued_tracks,
                   SUM(CASE WHEN wpt.status = 'failed' THEN 1 ELSE 0 END) as failed_tracks
            FROM watched_playlists wp
            LEFT JOIN watched_playlist_tracks wpt ON wp.id = wpt.playlist_id AND wpt.removed_at IS NULL
            GROUP BY wp.id
            ORDER BY wp.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single playlist by ID
     */
    public function getPlaylist(string $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT wp.*,
                   COUNT(wpt.id) as total_tracks,
                   SUM(CASE WHEN wpt.status = 'downloaded' THEN 1 ELSE 0 END) as downloaded_tracks,
                   SUM(CASE WHEN wpt.status = 'pending' THEN 1 ELSE 0 END) as pending_tracks,
                   SUM(CASE WHEN wpt.status = 'queued' THEN 1 ELSE 0 END) as queued_tracks,
                   SUM(CASE WHEN wpt.status = 'failed' THEN 1 ELSE 0 END) as failed_tracks
            FROM watched_playlists wp
            LEFT JOIN watched_playlist_tracks wpt ON wp.id = wpt.playlist_id AND wpt.removed_at IS NULL
            WHERE wp.id = ?
            GROUP BY wp.id
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get tracks for a playlist
     */
    public function getPlaylistTracks(string $playlistId, ?string $statusFilter = null, int $page = 1, int $perPage = 25): array
    {
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM watched_playlist_tracks WHERE playlist_id = ?";
        $params = [$playlistId];
        
        if ($statusFilter) {
            $countSql .= " AND status = ?";
            $params[] = $statusFilter;
        }
        
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get paginated tracks
        $sql = "SELECT * FROM watched_playlist_tracks WHERE playlist_id = ?";
        $params = [$playlistId];

        if ($statusFilter) {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY added_at DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'tracks' => $tracks,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage)
        ];
    }

    /**
     * Delete a watched playlist
     */
    public function deletePlaylist(string $id): bool
    {
        // Delete M3U file if exists
        $playlist = $this->getPlaylist($id);
        if ($playlist && $playlist['make_m3u']) {
            $m3uPath = $this->getM3uPath($playlist['name']);
            if (file_exists($m3uPath)) {
                unlink($m3uPath);
            }
        }

        // CASCADE will handle watched_playlist_tracks
        $stmt = $this->db->prepare("DELETE FROM watched_playlists WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Toggle playlist enabled state
     */
    public function togglePlaylist(string $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE watched_playlists
            SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    /**
     * Check if a playlist needs refresh
     */
    public function needsRefresh(array $playlist): bool
    {
        if (!$playlist['enabled']) {
            return false;
        }

        if (!$playlist['last_checked']) {
            return true;
        }

        $lastChecked = strtotime($playlist['last_checked']);
        $intervalSeconds = $playlist['refresh_interval_hours'] * 3600;

        return (time() - $lastChecked) >= $intervalSeconds;
    }

    /**
     * Refresh a playlist - fetch new tracks
     */
    public function refreshPlaylist(string $id): array
    {
        $playlist = $this->getPlaylist($id);
        if (!$playlist) {
            return ['success' => false, 'error' => 'Playlist not found'];
        }

        // Fetch current tracks from source
        $playlistData = $this->playlistService->fetchPlaylist($playlist['url']);
        if (!$playlistData) {
            return ['success' => false, 'error' => 'Could not fetch playlist'];
        }

        $newTracks = 0;
        $removedTracks = 0;

        try {
            $this->db->beginTransaction();

            // Get current track hashes for mirror mode
            $currentHashes = [];
            if ($playlist['sync_mode'] === 'mirror') {
                $stmt = $this->db->prepare("
                    SELECT track_hash FROM watched_playlist_tracks
                    WHERE playlist_id = ? AND removed_at IS NULL
                ");
                $stmt->execute([$id]);
                $currentHashes = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'track_hash');
            }

            $fetchedHashes = [];

            // Process fetched tracks
            foreach ($playlistData['tracks'] as $track) {
                $parsed = $this->parseTrack($track);
                $artist = $parsed['artist'];
                $title = $parsed['title'];
                $trackHash = $this->generateTrackHash($artist, $title);
                $fetchedHashes[] = $trackHash;

                // Check if track already exists
                $stmt = $this->db->prepare("
                    SELECT id, status, removed_at FROM watched_playlist_tracks
                    WHERE playlist_id = ? AND track_hash = ?
                ");
                $stmt->execute([$id, $trackHash]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    // New track - check if already in library
                    $alreadyDownloaded = $this->isTrackInLibrary($artist, $title);
                    $status = $alreadyDownloaded ? 'downloaded' : 'pending';
                    
                    $stmt = $this->db->prepare("
                        INSERT INTO watched_playlist_tracks (playlist_id, track_hash, artist, title, video_id, status, downloaded_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $downloadedAt = $alreadyDownloaded ? date('Y-m-d H:i:s') : null;
                    $stmt->execute([$id, $trackHash, $artist, $title, $parsed['video_id'], $status, $downloadedAt]);
                    $newTracks++;
                } elseif ($existing['removed_at']) {
                    // Track was previously removed but is back (re-added upstream)
                    $stmt = $this->db->prepare("
                        UPDATE watched_playlist_tracks
                        SET removed_at = NULL
                        WHERE id = ?
                    ");
                    $stmt->execute([$existing['id']]);
                }
            }

            // Mirror mode: mark removed tracks
            if ($playlist['sync_mode'] === 'mirror') {
                $toRemove = array_diff($currentHashes, $fetchedHashes);
                if (!empty($toRemove)) {
                    $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
                    $stmt = $this->db->prepare("
                        UPDATE watched_playlist_tracks
                        SET removed_at = datetime('now')
                        WHERE playlist_id = ? AND track_hash IN ($placeholders) AND removed_at IS NULL
                    ");
                    $stmt->execute(array_merge([$id], array_values($toRemove)));
                    $removedTracks = count($toRemove);
                }
            }

            // Update last checked
            $stmt = $this->db->prepare("
                UPDATE watched_playlists
                SET last_checked = datetime('now'), last_track_count = ?
                WHERE id = ?
            ");
            $stmt->execute([count($playlistData['tracks']), $id]);

            $this->db->commit();

            return [
                'success' => true,
                'new_tracks' => $newTracks,
                'removed_tracks' => $removedTracks,
                'total_tracks' => count($playlistData['tracks'])
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Queue pending tracks for download
     */
    public function queuePendingTracks(string $playlistId, int $limit = 10): array
    {
        $playlist = $this->getPlaylist($playlistId);
        if (!$playlist) {
            return ['success' => false, 'error' => 'Playlist not found'];
        }

        // Get pending tracks
        $stmt = $this->db->prepare("
            SELECT * FROM watched_playlist_tracks
            WHERE playlist_id = ? AND status = 'pending'
            LIMIT ?
        ");
        $stmt->execute([$playlistId, $limit]);
        $pendingTracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $queued = 0;
        $errors = [];

        foreach ($pendingTracks as $track) {
            $result = $this->queueTrack($track, $playlist);
            if ($result['success']) {
                $queued++;
            } else {
                $errors[] = "{$track['artist']} - {$track['title']}: {$result['error']}";
            }
        }

        return [
            'success' => true,
            'queued' => $queued,
            'errors' => $errors
        ];
    }

    /**
     * Queue a single track for download
     */
    private function queueTrack(array $track, array $playlist): array
    {
        // If we have a video_id from the playlist source, use it directly
        if (!empty($track['video_id'])) {
            // Check if already in library
            if ($this->queueService->isAlreadyQueued($track['video_id'])) {
                $this->markTrackDownloaded($track['id'], $track['video_id']);
                return ['success' => true, 'skipped' => true];
            }

            // Queue the download directly
            $jobId = $this->queueDirectDownload($track, $playlist);
            if ($jobId) {
                $this->updateTrackStatus($track['id'], 'queued', $jobId, $track['video_id']);
                return ['success' => true, 'job_id' => $jobId];
            }
        }

        // Search for the track using Monochrome
        $query = "{$track['artist']} {$track['title']}";
        $searchResult = $this->searchService->searchMonochrome($query);

        if (empty($searchResult['results'])) {
            $this->updateTrackStatus($track['id'], 'failed');
            return ['success' => false, 'error' => 'No results found'];
        }

        // Find best match (first result for now)
        $match = $searchResult['results'][0];
        $videoId = $match['video_id'] ?? $match['id'] ?? null;

        if (!$videoId) {
            $this->updateTrackStatus($track['id'], 'failed');
            return ['success' => false, 'error' => 'No track ID in search result'];
        }

        // Check if already in library
        if ($this->queueService->isAlreadyQueued($videoId)) {
            $this->markTrackDownloaded($track['id'], $videoId);
            return ['success' => true, 'skipped' => true];
        }

        // Queue the download
        $jobData = [
            'video_id' => $videoId,
            'title' => $match['title'] ?? $track['title'],
            'artist' => $match['artist'] ?? $track['artist'],
            'url' => $match['url'] ?? '',
            'thumbnail' => $match['thumbnail'] ?? '',
            'source' => 'monochrome',
            'download_type' => 'single'
        ];

        $jobId = $this->downloadService->queueDownload($jobData);
        if ($jobId) {
            $this->updateTrackStatus($track['id'], 'queued', $jobId, $videoId);
            return ['success' => true, 'job_id' => $jobId];
        }

        $this->updateTrackStatus($track['id'], 'failed');
        return ['success' => false, 'error' => 'Failed to queue job'];
    }

    /**
     * Queue a direct download (when we have video_id from playlist source)
     */
    private function queueDirectDownload(array $track, array $playlist): ?string
    {
        $source = $playlist['platform'] === 'tidal' ? 'monochrome' : $playlist['platform'];

        $jobData = [
            'video_id' => $track['video_id'],
            'title' => $track['title'],
            'artist' => $track['artist'],
            'url' => '',
            'thumbnail' => '',
            'source' => $source,
            'download_type' => 'single'
        ];

        return $this->downloadService->queueDownload($jobData);
    }

    /**
     * Update track status
     */
    private function updateTrackStatus(int $trackId, string $status, ?string $jobId = null, ?string $videoId = null): void
    {
        $sql = "UPDATE watched_playlist_tracks SET status = ?";
        $params = [$status];

        if ($jobId !== null) {
            $sql .= ", job_id = ?";
            $params[] = $jobId;
        }

        if ($videoId !== null) {
            $sql .= ", video_id = ?";
            $params[] = $videoId;
        }

        if ($status === 'downloaded') {
            $sql .= ", downloaded_at = datetime('now')";
        }

        $sql .= " WHERE id = ?";
        $params[] = $trackId;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Mark track as downloaded (when already in library)
     */
    private function markTrackDownloaded(int $trackId, string $videoId): void
    {
        $this->updateTrackStatus($trackId, 'downloaded', null, $videoId);
    }

    /**
     * Retry failed tracks
     */
    public function retryFailedTracks(string $playlistId): array
    {
        $stmt = $this->db->prepare("
            UPDATE watched_playlist_tracks
            SET status = 'pending', job_id = NULL
            WHERE playlist_id = ? AND status = 'failed'
        ");
        $stmt->execute([$playlistId]);
        $count = $stmt->rowCount();

        return ['success' => true, 'reset_count' => $count];
    }

    /**
     * Sync track statuses with job completions
     */
    public function syncTrackStatuses(): int
    {
        // Update tracks whose jobs completed successfully
        $stmt = $this->db->prepare("
            UPDATE watched_playlist_tracks
            SET status = 'downloaded', downloaded_at = datetime('now')
            WHERE status = 'queued'
            AND job_id IN (SELECT id FROM jobs WHERE status = 'completed')
        ");
        $stmt->execute();
        $downloaded = $stmt->rowCount();

        // Update tracks whose jobs failed
        $stmt = $this->db->prepare("
            UPDATE watched_playlist_tracks
            SET status = 'failed'
            WHERE status = 'queued'
            AND job_id IN (SELECT id FROM jobs WHERE status = 'failed')
        ");
        $stmt->execute();

        // Regenerate M3U for playlists with newly downloaded tracks
        if ($downloaded > 0) {
            $this->regenerateAllM3u();
        }

        return $downloaded;
    }

    /**
     * Regenerate M3U files for all playlists that have M3U enabled
     */
    public function regenerateAllM3u(): void
    {
        $stmt = $this->db->query("SELECT id FROM watched_playlists WHERE make_m3u = 1");
        $playlists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($playlists as $playlist) {
            $this->generateM3u($playlist['id']);
        }
    }

    /**
     * Get playlists that need refresh
     */
    public function getPlaylistsDueForRefresh(): array
    {
        $playlists = $this->getAllPlaylists();
        return array_filter($playlists, fn($p) => $this->needsRefresh($p));
    }

    /**
     * Get M3U file path for a playlist
     */
    public function getM3uPath(string $playlistName): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $playlistName);
        $safeName = trim($safeName) ?: 'playlist';
        // Use absolute path based on project root
        $baseDir = dirname(__DIR__, 2);  // Go up from src/Services to project root
        return "{$baseDir}/music/Playlists/{$safeName}.m3u";
    }

    /**
     * Generate M3U file for a playlist
     */
    public function generateM3u(string $playlistId): array
    {
        $playlist = $this->getPlaylist($playlistId);
        if (!$playlist) {
            return ['success' => false, 'error' => 'Playlist not found'];
        }

        if (!$playlist['make_m3u']) {
            return ['success' => false, 'error' => 'M3U generation disabled for this playlist'];
        }

        // Get downloaded tracks
        $stmt = $this->db->prepare("
            SELECT wpt.*, l.file_path
            FROM watched_playlist_tracks wpt
            JOIN library l ON wpt.video_id = l.video_id
            WHERE wpt.playlist_id = ?
            AND wpt.status = 'downloaded'
            AND wpt.removed_at IS NULL
            ORDER BY wpt.added_at ASC
        ");
        $stmt->execute([$playlistId]);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure directory exists
        $dir = dirname($this->getM3uPath($playlist['name']));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Build M3U content
        $m3u = "#EXTM3U\n";
        $m3u .= "#PLAYLIST:{$playlist['name']}\n";

        $baseDir = dirname(__DIR__, 2);  // Project root
        $musicDir = realpath("{$baseDir}/music");

        foreach ($tracks as $track) {
            $filePath = $track['file_path'];
            if (!empty($filePath) && file_exists($filePath)) {
                // Convert to relative path from music/Playlists/
                $realPath = realpath($filePath);
                if ($realPath && $musicDir && strpos($realPath, $musicDir) === 0) {
                    // Path is under music/ - make relative from Playlists folder
                    $relativePath = '../' . substr($realPath, strlen($musicDir) + 1);
                } else {
                    // Fallback to absolute path
                    $relativePath = $realPath ?: $filePath;
                }
                $m3u .= "#EXTINF:-1,{$track['artist']} - {$track['title']}\n";
                $m3u .= "{$relativePath}\n";
            }
        }

        $path = $this->getM3uPath($playlist['name']);
        file_put_contents($path, $m3u);

        return [
            'success' => true,
            'path' => $path,
            'track_count' => count($tracks)
        ];
    }
}
