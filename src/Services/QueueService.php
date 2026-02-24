<?php

namespace App\Services;

class QueueService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Get all jobs with optional status filter
     */
    public function getJobs(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        if ($status) {
            return $this->db->query(
                'SELECT * FROM jobs WHERE status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
                [$status, $limit, $offset]
            );
        }
        
        return $this->db->query(
            'SELECT * FROM jobs ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    /**
     * Get job by ID
     */
    public function getJob(string $id): ?array
    {
        return $this->db->queryOne('SELECT * FROM jobs WHERE id = ?', [$id]);
    }

    /**
     * Get queue statistics
     */
    public function getStats(): array
    {
        $stats = [
            'queued' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0,
        ];

        $results = $this->db->query(
            'SELECT status, COUNT(*) as count FROM jobs GROUP BY status'
        );

        foreach ($results as $row) {
            $stats[$row['status']] = (int)$row['count'];
            $stats['total'] += (int)$row['count'];
        }

        return $stats;
    }

    /**
     * Retry a failed job
     */
    public function retryJob(string $id): bool
    {
        return $this->db->execute(
            "UPDATE jobs SET status = 'queued', error = NULL, started_at = NULL, completed_at = NULL WHERE id = ? AND status = 'failed'",
            [$id]
        ) > 0;
    }

    /**
     * Delete a job
     */
    public function deleteJob(string $id): bool
    {
        return $this->db->execute('DELETE FROM jobs WHERE id = ?', [$id]) > 0;
    }

    /**
     * Clear jobs by status
     */
    public function clearJobs(?string $status = null): int
    {
        // Only delete jobs that aren't referenced by library entries
        // (library.job_id has a FK constraint to jobs.id)
        if ($status === 'completed') {
            return $this->db->execute(
                "DELETE FROM jobs WHERE status = 'completed' 
                 AND id NOT IN (SELECT job_id FROM library WHERE job_id IS NOT NULL)"
            );
        } elseif ($status === 'failed') {
            return $this->db->execute("DELETE FROM jobs WHERE status = 'failed'");
        } else {
            return $this->db->execute(
                "DELETE FROM jobs WHERE status IN ('completed', 'failed')
                 AND id NOT IN (SELECT job_id FROM library WHERE job_id IS NOT NULL)"
            );
        }
    }

    /**
     * Check if a track is already queued, processing, or in library (by video_id)
     * For completed items, verifies the file actually exists on disk
     */
    public function isAlreadyQueued(string $videoId): bool
    {
        // Check for active (queued/processing) jobs first
        $activeJob = $this->db->queryOne(
            "SELECT id FROM jobs WHERE video_id = ? AND status IN ('queued', 'processing')",
            [$videoId]
        );
        if ($activeJob) {
            return true;
        }
        
        // Check library directly (most reliable source of truth)
        $libraryTrack = $this->db->queryOne(
            "SELECT id, file_path FROM library WHERE video_id = ?",
            [$videoId]
        );
        
        if ($libraryTrack) {
            // If file exists, it's truly in the library
            if (!empty($libraryTrack['file_path']) && file_exists($libraryTrack['file_path'])) {
                return true;
            }
            // File is missing - remove the orphaned library entry
            $this->db->execute('DELETE FROM library WHERE id = ?', [$libraryTrack['id']]);
        }
        
        // Check for completed jobs (fallback - library should be authoritative)
        $completedJob = $this->db->queryOne(
            "SELECT id, file_path FROM jobs WHERE video_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1",
            [$videoId]
        );
        
        if ($completedJob) {
            // If file exists, it's truly complete
            if (!empty($completedJob['file_path']) && file_exists($completedJob['file_path'])) {
                return true;
            }
            // File is missing - mark as failed so it can be re-downloaded
            $this->db->execute(
                "UPDATE jobs SET status = 'failed', error = 'File missing from disk' WHERE id = ?",
                [$completedJob['id']]
            );
            return false;
        }
        
        return false;
    }

    /**
     * Find existing job by video_id
     */
    public function findByVideoId(string $videoId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM jobs WHERE video_id = ? ORDER BY created_at DESC LIMIT 1",
            [$videoId]
        );
    }

    /**
     * Clear completed jobs older than X minutes
     * Only deletes jobs that don't have library entries (to avoid FK constraint issues)
     */
    public function clearOldCompleted(int $minutes = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));
        return $this->db->execute(
            "DELETE FROM jobs WHERE status = 'completed' AND completed_at < ? 
             AND id NOT IN (SELECT job_id FROM library WHERE job_id IS NOT NULL)",
            [$cutoff]
        );
    }
}
