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
        
        return $this->db->query(
            "SELECT * FROM library ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
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
     * Delete a track
     */
    public function deleteTrack(string $id): bool
    {
        $track = $this->getTrack($id);
        if (!$track) {
            return false;
        }

        // Delete file if it exists
        if (!empty($track['file_path']) && file_exists($track['file_path'])) {
            unlink($track['file_path']);
            
            // Try to remove empty artist directory
            $dir = dirname($track['file_path']);
            if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
                rmdir($dir);
            }
        }

        return $this->db->execute('DELETE FROM library WHERE id = ?', [$id]) > 0;
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
     * Get tracks grouped by artist
     */
    public function getArtists(): array
    {
        return $this->db->query(
            'SELECT artist, COUNT(*) as track_count FROM library GROUP BY artist ORDER BY artist'
        );
    }
}
