<?php

namespace App\Services;

use ZipArchive;
use Exception;

class MusicLibrary
{
    private Database $db;
    private string $musicDir;
    private string $singlesDir;

    public function __construct(Database $db, string $musicDir, string $singlesDir)
    {
        $this->db = $db;
        $this->musicDir = $musicDir;
        $this->singlesDir = $singlesDir;
    }

    /**
     * Get all tracks in library with pagination and sorting
     * Adds 'file_missing' flag if file doesn't exist on disk
     * 
     * @param string $sort Sort option: 'recent', 'artist', 'title'
     * @param int $limit Items per page
     * @param int $offset Starting offset
     */
    public function getTracks(string $sort = 'recent', int $limit = 25, int $offset = 0): array
    {
        $orderBy = match ($sort) {
            'artist' => 'artist ASC, title ASC',
            'title' => 'title ASC, artist ASC',
            default => 'created_at DESC',
        };
        
        $tracks = $this->db->query(
            "SELECT * FROM library ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
        
        // Check file existence for each track
        foreach ($tracks as &$track) {
            $track['file_missing'] = !$this->fileExists($track);
        }
        
        return $tracks;
    }

    /**
     * Get total track count
     */
    public function getTrackCount(): int
    {
        $result = $this->db->queryOne('SELECT COUNT(*) as count FROM library');
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get track by ID
     */
    public function getTrack(string $id): ?array
    {
        return $this->db->queryOne('SELECT * FROM library WHERE id = ?', [$id]);
    }

    /**
     * Get track by video_id
     */
    public function getTrackByVideoId(string $videoId): ?array
    {
        return $this->db->queryOne('SELECT * FROM library WHERE video_id = ?', [$videoId]);
    }

    /**
     * Search tracks with pagination and sorting
     */
    public function searchTracks(string $query, string $sort = 'recent', int $limit = 25, int $offset = 0): array
    {
        $searchQuery = '%' . $query . '%';
        $orderBy = match ($sort) {
            'artist' => 'artist ASC, title ASC',
            'title' => 'title ASC, artist ASC',
            default => 'created_at DESC',
        };
        
        return $this->db->query(
            "SELECT * FROM library WHERE title LIKE ? OR artist LIKE ? ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            [$searchQuery, $searchQuery, $limit, $offset]
        );
    }

    /**
     * Get search result count
     */
    public function getSearchCount(string $query): int
    {
        $searchQuery = '%' . $query . '%';
        $result = $this->db->queryOne(
            'SELECT COUNT(*) as count FROM library WHERE title LIKE ? OR artist LIKE ?',
            [$searchQuery, $searchQuery]
        );
        return (int)($result['count'] ?? 0);
    }

    /**
     * Delete a track - removes file, library entry, and related job record
     */
    public function deleteTrack(string $id): bool
    {
        $track = $this->getTrack($id);
        if (!$track) {
            return false;
        }

        // Delete file if it exists and is within our music directory (security check)
        // Use @ to suppress open_basedir warnings on shared hosting
        if (!empty($track['file_path']) && @file_exists($track['file_path'])) {
            $realPath = @realpath($track['file_path']);
            $musicDirReal = @realpath($this->musicDir);
            
            // Only delete if file is within music directory (prevent path traversal)
            if ($realPath && $musicDirReal && str_starts_with($realPath, $musicDirReal)) {
                @unlink($track['file_path']);
                
                // Try to remove empty artist directory
                $dir = dirname($track['file_path']);
                $dirReal = @realpath($dir);
                if ($dirReal && str_starts_with($dirReal, $musicDirReal) && 
                    @is_dir($dir) && count(@glob($dir . '/*') ?: []) === 0) {
                    @rmdir($dir);
                }
            }
        }

        // Store job info for cleanup after library delete
        $jobId = $track['job_id'] ?? null;
        $videoId = $track['video_id'] ?? null;
        
        // Delete library entry first (has FK to jobs)
        $deleted = $this->db->execute('DELETE FROM library WHERE id = ?', [$id]) > 0;
        
        if ($deleted) {
            // Now delete the related job record to prevent orphaned "completed" jobs
            // This ensures the track can be re-downloaded later if desired
            if ($jobId) {
                $this->db->execute('DELETE FROM jobs WHERE id = ?', [$jobId]);
            }
            
            // Also delete any jobs that reference this track by video_id
            // (handles cases where job_id wasn't properly linked, or multiple download attempts)
            if ($videoId) {
                $this->db->execute(
                    "DELETE FROM jobs WHERE video_id = ? AND status IN ('completed', 'failed')",
                    [$videoId]
                );
                
                // Reset watched playlist track status to 'pending' so it can be re-downloaded
                $this->db->execute(
                    "UPDATE watched_playlist_tracks SET status = 'pending', downloaded_at = NULL, job_id = NULL WHERE video_id = ?",
                    [$videoId]
                );
            }
        }

        return $deleted;
    }

    /**
     * Delete all tracks from library
     * @return int Number of tracks deleted
     */
    public function deleteAllTracks(): int
    {
        $tracks = $this->db->query('SELECT id, file_path, job_id, video_id FROM library');
        $musicDirReal = @realpath($this->musicDir);
        $count = 0;

        foreach ($tracks as $track) {
            // Delete file if it exists and is within our music directory
            if (!empty($track['file_path']) && @file_exists($track['file_path'])) {
                $realPath = @realpath($track['file_path']);
                if ($realPath && $musicDirReal && str_starts_with($realPath, $musicDirReal)) {
                    unlink($track['file_path']);
                }
            }
            $count++;
        }

        // Clear library table
        $this->db->execute('DELETE FROM library');
        
        // Clear related jobs
        $this->db->execute("DELETE FROM jobs WHERE status IN ('completed', 'failed')");
        
        // Reset all watched playlist tracks to 'pending' so they can be re-downloaded
        $this->db->execute(
            "UPDATE watched_playlist_tracks SET status = 'pending', downloaded_at = NULL, job_id = NULL WHERE status = 'downloaded'"
        );

        // Clean up empty artist directories
        $singlesDir = $this->musicDir . '/Singles';
        if (is_dir($singlesDir)) {
            $dirs = glob($singlesDir . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $dirReal = @realpath($dir);
                if ($dirReal && str_starts_with($dirReal, $musicDirReal) && 
                    count(glob($dir . '/*')) === 0) {
                    rmdir($dir);
                }
            }
        }

        return $count;
    }

    /**
     * Create a zip file of selected tracks
     */
    public function createZip(array $trackIds): string
    {
        if (empty($trackIds)) {
            throw new Exception('No tracks selected');
        }

        $placeholders = implode(',', array_fill(0, count($trackIds), '?'));
        $tracks = $this->db->query(
            "SELECT * FROM library WHERE id IN ({$placeholders})",
            $trackIds
        );

        if (empty($tracks)) {
            throw new Exception('No tracks found');
        }

        // Create temp zip file
        $zipPath = sys_get_temp_dir() . '/ez-mu-download-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new Exception('Failed to create zip file');
        }

        foreach ($tracks as $track) {
            if (!empty($track['file_path']) && @file_exists($track['file_path'])) {
                // Sanitize filename to prevent directory traversal in zip archive
                $artist = $this->sanitizeZipFilename($track['artist']);
                $title = $this->sanitizeZipFilename($track['title']);
                $extension = pathinfo($track['file_path'], PATHINFO_EXTENSION);
                $filename = "{$artist} - {$title}.{$extension}";
                $zip->addFile($track['file_path'], $filename);
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Get library statistics
     */
    public function getStats(): array
    {
        $result = $this->db->queryOne(
            'SELECT COUNT(*) as count, SUM(file_size) as total_size, SUM(duration) as total_duration FROM library'
        );

        return [
            'track_count' => (int)($result['count'] ?? 0),
            'total_size' => (int)($result['total_size'] ?? 0),
            'total_duration' => (int)($result['total_duration'] ?? 0),
        ];
    }
    
    /**
     * Check if a track's file exists on disk
     */
    /**
     * Check if a track's file exists on disk
     * Uses @ to suppress open_basedir warnings on shared hosting
     */
    public function fileExists(array $track): bool
    {
        return !empty($track['file_path']) && @file_exists($track['file_path']);
    }
    
    /**
     * Validate library integrity - find orphaned records and missing files
     * 
     * @return array{missing_files: array, orphaned_jobs: array, fixed: int}
     */
    public function validateIntegrity(): array
    {
        $missingFiles = [];
        $orphanedJobs = [];
        $fixed = 0;
        
        // Find library entries with missing files
        $tracks = $this->db->query('SELECT id, title, artist, file_path FROM library');
        foreach ($tracks as $track) {
            if (!$this->fileExists($track)) {
                $missingFiles[] = [
                    'id' => $track['id'],
                    'title' => $track['title'],
                    'artist' => $track['artist'],
                    'file_path' => $track['file_path'],
                ];
            }
        }
        
        // Find completed jobs with missing files
        $jobs = $this->db->query(
            "SELECT id, title, artist, file_path FROM jobs WHERE status = 'completed'"
        );
        foreach ($jobs as $job) {
            if (!empty($job['file_path']) && !@file_exists($job['file_path'])) {
                $orphanedJobs[] = [
                    'id' => $job['id'],
                    'title' => $job['title'],
                    'artist' => $job['artist'],
                    'file_path' => $job['file_path'],
                ];
            }
        }
        
        // Find watched playlist tracks marked as 'downloaded' but not actually in library
        $orphanedWatchedTracks = [];
        $watchedTracks = $this->db->query(
            "SELECT wpt.id, wpt.title, wpt.artist, wpt.video_id, wp.name as playlist_name 
             FROM watched_playlist_tracks wpt
             JOIN watched_playlists wp ON wpt.playlist_id = wp.id
             WHERE wpt.status = 'downloaded'"
        );
        foreach ($watchedTracks as $track) {
            // Check if video_id exists in library
            $inLibrary = $this->db->queryOne(
                "SELECT 1 FROM library WHERE video_id = ?",
                [$track['video_id']]
            );
            if (!$inLibrary) {
                $orphanedWatchedTracks[] = [
                    'id' => $track['id'],
                    'title' => $track['title'],
                    'artist' => $track['artist'],
                    'video_id' => $track['video_id'],
                    'playlist_name' => $track['playlist_name'],
                ];
            }
        }
        
        return [
            'missing_files' => $missingFiles,
            'orphaned_jobs' => $orphanedJobs,
            'orphaned_watched_tracks' => $orphanedWatchedTracks,
            'library_issues' => count($missingFiles),
            'job_issues' => count($orphanedJobs),
            'watched_issues' => count($orphanedWatchedTracks),
        ];
    }
    
    /**
     * Fix integrity issues - remove orphaned library entries and mark jobs as failed
     * 
     * @return array{library_removed: int, jobs_marked_failed: int}
     */
    public function fixIntegrityIssues(): array
    {
        $libraryRemoved = 0;
        $jobsMarkedFailed = 0;
        
        // Remove library entries with missing files
        $tracks = $this->db->query('SELECT id, file_path FROM library');
        foreach ($tracks as $track) {
            if (!empty($track['file_path']) && !@file_exists($track['file_path'])) {
                $this->db->execute('DELETE FROM library WHERE id = ?', [$track['id']]);
                $libraryRemoved++;
            }
        }
        
        // Mark completed jobs with missing files as failed
        $jobs = $this->db->query(
            "SELECT id, file_path FROM jobs WHERE status = 'completed'"
        );
        foreach ($jobs as $job) {
            if (!empty($job['file_path']) && !@file_exists($job['file_path'])) {
                $this->db->execute(
                    "UPDATE jobs SET status = 'failed', error = 'File missing from disk' WHERE id = ?",
                    [$job['id']]
                );
                $jobsMarkedFailed++;
            }
        }
        
        // Reset watched playlist tracks that claim to be 'downloaded' but aren't in library
        $watchedTracksReset = $this->db->execute(
            "UPDATE watched_playlist_tracks 
             SET status = 'pending', downloaded_at = NULL, job_id = NULL 
             WHERE status = 'downloaded' 
             AND video_id NOT IN (SELECT video_id FROM library WHERE video_id IS NOT NULL)"
        );
        
        return [
            'library_removed' => $libraryRemoved,
            'jobs_marked_failed' => $jobsMarkedFailed,
            'watched_tracks_reset' => $watchedTracksReset,
        ];
    }
    
    /**
     * Check if a specific track exists in library AND file exists on disk
     */
    public function trackExistsWithFile(string $videoId): bool
    {
        $track = $this->db->queryOne(
            'SELECT file_path FROM library WHERE video_id = ?',
            [$videoId]
        );
        
        if (!$track) {
            return false;
        }
        
        return !empty($track['file_path']) && @file_exists($track['file_path']);
    }

    /**
     * Get all video IDs that are in the library
     */
    public function getLibraryVideoIds(): array
    {
        $rows = $this->db->query('SELECT video_id FROM library WHERE file_path IS NOT NULL');
        return array_column($rows, 'video_id');
    }

    /**
     * Get tracks grouped by artist
     */
    public function getArtists(): array
    {
        return $this->db->query(
            'SELECT artist, COUNT(*) as track_count FROM library GROUP BY artist ORDER BY artist'
        );
    }
    
    /**
     * Sanitize a string for use in a zip filename
     * Removes path separators and other dangerous characters
     */
    private function sanitizeZipFilename(string $name): string
    {
        // Remove path separators and null bytes
        $name = str_replace(['/', '\\', "\0"], '', $name);
        
        // Remove other problematic characters for zip entries
        $name = preg_replace('/[<>:"|?*]/', '', $name);
        
        // Limit length
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
        }
        
        // Fallback if empty
        if (empty(trim($name))) {
            $name = 'Unknown';
        }
        
        return trim($name);
    }
}
