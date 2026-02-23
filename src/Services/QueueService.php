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
        if ($status === 'completed') {
            return $this->db->execute("DELETE FROM jobs WHERE status = 'completed'");
        } elseif ($status === 'failed') {
            return $this->db->execute("DELETE FROM jobs WHERE status = 'failed'");
        } else {
            return $this->db->execute("DELETE FROM jobs WHERE status IN ('completed', 'failed')");
        }
    }

    /**
     * Check if a track is already queued or completed (by video_id)
     */
    public function isAlreadyQueued(string $videoId): bool
    {
        $result = $this->db->queryOne(
            "SELECT id FROM jobs WHERE video_id = ? AND status IN ('queued', 'processing', 'completed')",
            [$videoId]
        );
        return $result !== null;
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
}
