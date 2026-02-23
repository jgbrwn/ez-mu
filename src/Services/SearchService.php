<?php

namespace App\Services;

class SearchService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function getYtDlpPath(): string
    {
        $userPath = getenv('HOME') . '/.local/bin/yt-dlp';
        return file_exists($userPath) ? $userPath : 'yt-dlp';
    }

    /**
     * Search YouTube using yt-dlp
     */
    public function searchYouTube(string $query, int $limit = 15): array
    {
        $searchQuery = "ytsearch{$limit}:{$query}";
        
        $cmd = [
            $this->getYtDlpPath(),
            '--dump-json',
            '--flat-playlist',
            '--no-warnings',
            '--ignore-errors',
            $searchQuery
        ];

        $output = $this->runCommand($cmd);
        if (empty($output)) {
            return [];
        }

        $results = [];
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $data = json_decode($line, true);
            if (!$data) continue;

            $results[] = $this->formatYouTubeResult($data);
        }

        // Log the search
        $this->logSearch($query, 'youtube', count($results));

        return $results;
    }

    /**
     * Search SoundCloud using yt-dlp
     */
    public function searchSoundCloud(string $query, int $limit = 15): array
    {
        $searchQuery = "scsearch{$limit}:{$query}";
        
        $cmd = [
            $this->getYtDlpPath(),
            '--dump-json',
            '--flat-playlist',
            '--no-warnings',
            '--ignore-errors',
            $searchQuery
        ];

        $output = $this->runCommand($cmd);
        if (empty($output)) {
            return [];
        }

        $results = [];
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $data = json_decode($line, true);
            if (!$data) continue;

            $results[] = $this->formatSoundCloudResult($data);
        }

        $this->logSearch($query, 'soundcloud', count($results));

        return $results;
    }

    /**
     * Search all sources
     */
    public function searchAll(string $query, int $limit = 10): array
    {
        // Search YouTube and SoundCloud in parallel would be nice,
        // but for simplicity we'll do sequential
        $youtube = $this->searchYouTube($query, $limit);
        $soundcloud = $this->searchSoundCloud($query, $limit);

        // Merge and sort by quality indicators
        $results = array_merge($youtube, $soundcloud);
        
        // Sort: prioritize by source quality hints
        usort($results, function($a, $b) {
            // Prefer SoundCloud (often higher quality)
            $sourceOrder = ['soundcloud' => 0, 'youtube' => 1];
            $aOrder = $sourceOrder[$a['source']] ?? 2;
            $bOrder = $sourceOrder[$b['source']] ?? 2;
            return $aOrder <=> $bOrder;
        });

        return array_slice($results, 0, $limit * 2);
    }

    private function formatYouTubeResult(array $data): array
    {
        $title = $data['title'] ?? 'Unknown';
        $artist = $data['uploader'] ?? $data['channel'] ?? 'Unknown';
        
        // Try to parse "Artist - Title" format
        if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/', $title, $matches)) {
            $artist = trim($matches[1]);
            $title = trim($matches[2]);
            // Clean up common suffixes
            $title = preg_replace('/\s*\(?\s*(Official\s*)?(Music\s*)?(Video|Audio|Lyrics?)\s*\)?\s*$/i', '', $title);
            $title = preg_replace('/\s*(ft\.?|feat\.?)\s*.+$/i', '', $title);
        }

        // Get thumbnail from thumbnails array or construct it
        $thumbnail = '';
        if (!empty($data['thumbnails']) && is_array($data['thumbnails'])) {
            $thumbnail = $data['thumbnails'][0]['url'] ?? '';
        } elseif (!empty($data['thumbnail'])) {
            $thumbnail = $data['thumbnail'];
        }
        if (empty($thumbnail) && !empty($data['id'])) {
            $thumbnail = $this->getYouTubeThumbnail($data['id']);
        }

        // Get duration - could be int or string
        $duration = 0;
        if (isset($data['duration'])) {
            $duration = is_numeric($data['duration']) ? (int)$data['duration'] : 0;
        }

        return [
            'id' => $data['id'] ?? '',
            'video_id' => $data['id'] ?? '',
            'title' => $title,
            'artist' => $artist,
            'duration' => $duration,
            'duration_string' => $data['duration_string'] ?? $this->formatDuration($duration),
            'thumbnail' => $thumbnail,
            'url' => $data['url'] ?? $data['webpage_url'] ?? "https://www.youtube.com/watch?v=" . ($data['id'] ?? ''),
            'source' => 'youtube',
            'view_count' => $data['view_count'] ?? 0,
        ];
    }

    private function formatSoundCloudResult(array $data): array
    {
        return [
            'id' => $data['id'] ?? md5($data['url'] ?? ''),
            'video_id' => $data['id'] ?? '',
            'title' => $data['title'] ?? 'Unknown',
            'artist' => $data['uploader'] ?? 'Unknown',
            'duration' => $data['duration'] ?? 0,
            'duration_string' => $this->formatDuration($data['duration'] ?? 0),
            'thumbnail' => $data['thumbnail'] ?? '',
            'url' => $data['url'] ?? '',
            'source' => 'soundcloud',
            'view_count' => $data['view_count'] ?? 0,
        ];
    }

    private function getYouTubeThumbnail(string $videoId): string
    {
        if (empty($videoId)) return '';
        return "https://i.ytimg.com/vi/{$videoId}/mqdefault.jpg";
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) return '0:00';
        
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        
        if ($minutes >= 60) {
            $hours = floor($minutes / 60);
            $minutes = $minutes % 60;
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        
        return sprintf('%d:%02d', $minutes, $secs);
    }

    private function runCommand(array $cmd): string
    {
        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    private function logSearch(string $query, string $source, int $count): void
    {
        $this->db->execute(
            'INSERT INTO search_log (query, source, results_count) VALUES (?, ?, ?)',
            [$query, $source, $count]
        );
    }
}
