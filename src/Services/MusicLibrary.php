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
        if (!empty($track['file_path']) && file_exists($track['file_path'])) {
            $realPath = realpath($track['file_path']);
            $musicDirReal = realpath($this->musicDir);
            
            // Only delete if file is within music directory (prevent path traversal)
            if ($realPath && $musicDirReal && str_starts_with($realPath, $musicDirReal)) {
                unlink($track['file_path']);
                
                // Try to remove empty artist directory
                $dir = dirname($track['file_path']);
                $dirReal = realpath($dir);
                if ($dirReal && str_starts_with($dirReal, $musicDirReal) && 
                    is_dir($dir) && count(glob($dir . '/*')) === 0) {
                    rmdir($dir);
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
            }
        }

        return $deleted;
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
            if (!empty($track['file_path']) && file_exists($track['file_path'])) {
                $filename = $track['artist'] . ' - ' . $track['title'] . '.' . pathinfo($track['file_path'], PATHINFO_EXTENSION);
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
    public function fileExists(array $track): bool
    {
        return !empty($track['file_path']) && file_exists($track['file_path']);
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
            if (!empty($job['file_path']) && !file_exists($job['file_path'])) {
                $orphanedJobs[] = [
                    'id' => $job['id'],
                    'title' => $job['title'],
                    'artist' => $job['artist'],
                    'file_path' => $job['file_path'],
                ];
            }
        }
        
        return [
            'missing_files' => $missingFiles,
            'orphaned_jobs' => $orphanedJobs,
            'library_issues' => count($missingFiles),
            'job_issues' => count($orphanedJobs),
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
            if (!empty($track['file_path']) && !file_exists($track['file_path'])) {
                $this->db->execute('DELETE FROM library WHERE id = ?', [$track['id']]);
                $libraryRemoved++;
            }
        }
        
        // Mark completed jobs with missing files as failed
        $jobs = $this->db->query(
            "SELECT id, file_path FROM jobs WHERE status = 'completed'"
        );
        foreach ($jobs as $job) {
            if (!empty($job['file_path']) && !file_exists($job['file_path'])) {
                $this->db->execute(
                    "UPDATE jobs SET status = 'failed', error = 'File missing from disk' WHERE id = ?",
                    [$job['id']]
                );
                $jobsMarkedFailed++;
            }
        }
        
        return [
            'library_removed' => $libraryRemoved,
            'jobs_marked_failed' => $jobsMarkedFailed,
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
        
        return !empty($track['file_path']) && file_exists($track['file_path']);
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
}
