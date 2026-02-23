<?php

namespace App\Services;

class SearchService
{
    private Database $db;
    private RateLimiter $rateLimiter;
    private MonochromeService $monochrome;
    private SettingsService $settings;

    public function __construct(
        Database $db,
        RateLimiter $rateLimiter,
        MonochromeService $monochrome,
        SettingsService $settings
    ) {
        $this->db = $db;
        $this->rateLimiter = $rateLimiter;
        $this->monochrome = $monochrome;
        $this->settings = $settings;
    }

    private function getYtDlpPath(): string
    {
        $userPath = getenv('HOME') . '/.local/bin/yt-dlp';
        return file_exists($userPath) ? $userPath : 'yt-dlp';
    }

    /**
     * Search all enabled sources
     */
    public function searchAll(string $query, int $limit = 15): array
    {
        $results = [];
        $youtubeEnabled = $this->settings->getBool('youtube_enabled', false);

        // Primary: Monochrome (Tidal lossless)
        $monoResults = $this->monochrome->search($query, $limit);
        $results = array_merge($results, $monoResults);

        // Secondary: SoundCloud
        $scResults = $this->searchSoundCloud($query, $limit);
        $results = array_merge($results, $scResults);

        // Tertiary: YouTube (only if enabled)
        if ($youtubeEnabled) {
            $ytResults = $this->searchYouTube($query, $limit);
            $results = array_merge($results, $ytResults);
        }

        // Sort by quality score (Monochrome lossless floats to top)
        usort($results, fn($a, $b) => ($b['quality_score'] ?? 0) <=> ($a['quality_score'] ?? 0));

        // Log search
        $this->logSearch($query, 'all', count($results));

        return array_slice($results, 0, $limit * 2);
    }

    /**
     * Search YouTube using yt-dlp
     */
    public function searchYouTube(string $query, int $limit = 15): array
    {
        // Rate limit: 20 requests per minute for YouTube
        $this->rateLimiter->wait('youtube', 20, 60);

        $searchQuery = "ytsearch{$limit}:{$query}";
        
        $cmd = [
            $this->getYtDlpPath(),
            '--dump-json',
            '--flat-playlist',
            '--no-warnings',
            '--ignore-errors',
            $searchQuery
        ];

        // Add cookies if available
        $cookiesFile = $this->settings->get('youtube_cookies_path');
        if ($cookiesFile && file_exists($cookiesFile)) {
            array_splice($cmd, 1, 0, ['--cookies', $cookiesFile]);
        }

        $output = $this->runCommand($cmd, 30);
        if (empty($output)) {
            return [];
        }

        $results = [];
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $data = json_decode($line, true);
            if (!$data) continue;

            $result = $this->formatYouTubeResult($data, $query);
            $result['quality_score'] = $this->scoreYouTubeResult($data, $query);
            $results[] = $result;
        }

        usort($results, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);
        $this->logSearch($query, 'youtube', count($results));

        return array_slice($results, 0, $limit);
    }

    /**
     * Search SoundCloud using yt-dlp
     */
    public function searchSoundCloud(string $query, int $limit = 15): array
    {
        // Rate limit: 15 requests per minute for SoundCloud
        $this->rateLimiter->wait('soundcloud', 15, 60);

        $fetchLimit = max($limit * 2, 20);
        $searchQuery = "scsearch{$fetchLimit}:{$query}";
        
        $cmd = [
            $this->getYtDlpPath(),
            '--dump-json',
            '--flat-playlist',
            '--no-warnings',
            '--ignore-errors',
            $searchQuery
        ];

        $output = $this->runCommand($cmd, 30);
        if (empty($output)) {
            return [];
        }

        $results = [];
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $data = json_decode($line, true);
            if (!$data) continue;
            if (($data['_type'] ?? '') === 'playlist') continue;

            $results[] = $this->formatSoundCloudResult($data, $query);
        }

        usort($results, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);
        $this->logSearch($query, 'soundcloud', count($results));

        return array_slice($results, 0, $limit);
    }

    /**
     * Search Monochrome/Tidal only
     */
    public function searchMonochrome(string $query, int $limit = 15): array
    {
        return $this->monochrome->search($query, $limit);
    }

    private function formatYouTubeResult(array $data, string $query = ''): array
    {
        $title = $data['title'] ?? 'Unknown';
        $artist = $data['uploader'] ?? $data['channel'] ?? 'Unknown';
        
        // Try to parse "Artist - Title" format
        if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/', $title, $matches)) {
            $artist = trim($matches[1]);
            $title = trim($matches[2]);
            $title = preg_replace('/\s*\(?\s*(Official\s*)?(Music\s*)?(Video|Audio|Lyrics?)\s*\)?\s*$/i', '', $title);
            $title = preg_replace('/\s*(ft\.?|feat\.?)\s*.+$/i', '', $title);
        }

        $thumbnail = '';
        if (!empty($data['thumbnails']) && is_array($data['thumbnails'])) {
            $thumbnail = $data['thumbnails'][0]['url'] ?? '';
        } elseif (!empty($data['thumbnail'])) {
            $thumbnail = $data['thumbnail'];
        }
        if (empty($thumbnail) && !empty($data['id'])) {
            $thumbnail = "https://i.ytimg.com/vi/{$data['id']}/mqdefault.jpg";
        }

        $duration = is_numeric($data['duration'] ?? null) ? (int)$data['duration'] : 0;

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
            'quality' => null,
            'quality_score' => 0,
        ];
    }

    private function formatSoundCloudResult(array $data, string $query = ''): array
    {
        $title = $data['title'] ?? 'Unknown';
        $artist = $data['uploader'] ?? 'Unknown';
        $duration = (int)($data['duration'] ?? 0);

        return [
            'id' => $data['id'] ?? md5($data['url'] ?? ''),
            'video_id' => $data['id'] ?? '',
            'title' => $title,
            'artist' => $artist,
            'duration' => $duration,
            'duration_string' => $this->formatDuration($duration),
            'thumbnail' => $data['thumbnail'] ?? '',
            'url' => $data['url'] ?? $data['webpage_url'] ?? '',
            'source' => 'soundcloud',
            'quality' => null,
            'quality_score' => $this->scoreSoundCloudResult($data, $query),
        ];
    }

    private function scoreYouTubeResult(array $data, string $query): int
    {
        $score = 50; // Base score for YouTube (lower than Monochrome)
        
        $title = strtolower($data['title'] ?? '');
        $channel = strtolower($data['channel'] ?? $data['uploader'] ?? '');
        $queryLower = strtolower($query);

        // Title match
        if (str_contains($title, $queryLower)) {
            $score += 30;
        }

        // Official channels get bonus
        if (str_contains($title, 'official') || str_contains($channel, 'official')) {
            $score += 20;
        }

        // View count tiebreaker
        $views = $data['view_count'] ?? 0;
        $score += min(log10(max($views, 1)) * 2, 20);

        return $score;
    }

    private function scoreSoundCloudResult(array $data, string $query): int
    {
        $score = 70; // SoundCloud gets moderate priority
        
        $title = strtolower($data['title'] ?? '');
        $uploader = strtolower($data['uploader'] ?? '');
        $queryLower = strtolower($query);

        if (str_contains($title, $queryLower)) {
            $score += 30;
        }
        if (str_contains($uploader, $queryLower)) {
            $score += 20;
        }

        return $score;
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

    private function runCommand(array $cmd, int $timeout = 30): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $startTime = time();
        
        while (true) {
            $status = proc_get_status($process);
            
            $output .= stream_get_contents($pipes[1]);
            
            if (!$status['running']) {
                break;
            }
            
            if (time() - $startTime > $timeout) {
                proc_terminate($process);
                break;
            }
            
            usleep(10000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    private function logSearch(string $query, string $source, int $count): void
    {
        try {
            $this->db->execute(
                'INSERT INTO search_log (query, source, results_count) VALUES (?, ?, ?)',
                [$query, $source, $count]
            );
        } catch (\Exception $e) {
            // Non-critical
        }
    }
}
