<?php

namespace App\Services;

use Exception;

/**
 * Playlist Import Service
 * 
 * Fetches track lists from Spotify, YouTube Music, Amazon Music, and Tidal playlists.
 */
class PlaylistService
{
    private const TIMEOUT = 30;

    /**
     * Detect platform from URL and extract ID
     */
    public function detectPlatform(string $url): array
    {
        $url = trim($url);

        // Spotify playlist
        if (preg_match('#https?://open\.spotify\.com/playlist/([a-zA-Z0-9]+)#', $url, $m)) {
            return ['platform' => 'spotify', 'id' => $m[1], 'type' => 'playlist'];
        }

        // Spotify album
        if (preg_match('#https?://open\.spotify\.com/album/([a-zA-Z0-9]+)#', $url, $m)) {
            return ['platform' => 'spotify', 'id' => $m[1], 'type' => 'album'];
        }

        // YouTube/YouTube Music playlist
        if (preg_match('#https?://(www\.|music\.)?(youtube\.com|youtu\.be)/playlist\?list=([a-zA-Z0-9_-]+)#', $url, $m)) {
            return ['platform' => 'youtube', 'id' => $m[3], 'type' => 'playlist'];
        }

        // Tidal playlist
        if (preg_match('#https?://(?:www\.)?tidal\.com/(?:browse/)?playlist/([0-9a-f-]{36})#i', $url, $m)) {
            return ['platform' => 'tidal', 'id' => $m[1], 'type' => 'playlist'];
        }

        // Amazon Music playlist
        if (preg_match('#https?://music\.amazon\.[a-z.]+/(user-playlists|playlists)/\S+#', $url)) {
            return ['platform' => 'amazon', 'id' => $url, 'type' => 'playlist'];
        }

        throw new Exception('Unsupported playlist URL. Supported: Spotify, YouTube, YouTube Music, Tidal, Amazon Music.');
    }

    /**
     * Fetch tracks from a playlist URL
     */
    public function fetchPlaylist(string $url): array
    {
        $info = $this->detectPlatform($url);

        switch ($info['platform']) {
            case 'spotify':
                return $this->fetchSpotify($info['id'], $info['type']);
            case 'youtube':
                return $this->fetchYouTube($info['id']);
            case 'tidal':
                return $this->fetchTidal($info['id']);
            case 'amazon':
                return $this->fetchAmazon($info['id']);
            default:
                throw new Exception('Unknown platform');
        }
    }

    /**
     * Fetch Spotify playlist via embed endpoint
     */
    private function fetchSpotify(string $id, string $type): array
    {
        $embedUrl = "https://open.spotify.com/embed/{$type}/{$id}";
        
        $html = $this->httpGet($embedUrl, [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);

        if (!$html) {
            throw new Exception('Failed to fetch Spotify playlist');
        }

        // Extract playlist name
        $name = ucfirst($type);
        if (preg_match('/"title":"([^"]+)"/', $html, $m)) {
            $name = $this->decodeJson($m[1]);
        }

        // Extract tracks
        $tracks = [];
        preg_match_all('/"title":"([^"]+)"/', $html, $titles);
        preg_match_all('/"subtitle":"([^"]+)"/', $html, $artists);

        if (count($titles[1]) > 1 && count($artists[1]) > 1) {
            $trackTitles = array_slice($titles[1], 1);
            $trackArtists = array_slice($artists[1], 1);

            for ($i = 0; $i < min(count($trackTitles), count($trackArtists)); $i++) {
                $title = $this->decodeJson($trackTitles[$i]);
                $artist = $this->decodeJson($trackArtists[$i]);
                if ($artist !== 'Spotify' && !empty($title)) {
                    $tracks[] = "{$artist} - {$title}";
                }
            }
        }

        if (empty($tracks)) {
            throw new Exception('Could not extract tracks from Spotify. Playlist may be empty or private.');
        }

        return [
            'name' => $name,
            'platform' => 'spotify',
            'tracks' => $tracks,
            'count' => count($tracks),
        ];
    }

    /**
     * Fetch YouTube playlist via yt-dlp
     */
    private function fetchYouTube(string $playlistId): array
    {
        $url = "https://www.youtube.com/playlist?list={$playlistId}";
        
        $ytdlp = getenv('HOME') . '/.local/bin/yt-dlp';
        if (!file_exists($ytdlp)) {
            $ytdlp = 'yt-dlp';
        }

        $cmd = [
            $ytdlp,
            '--dump-json',
            '--flat-playlist',
            '--no-warnings',
            $url
        ];

        $output = $this->runCommand($cmd, 60);
        if (empty($output)) {
            throw new Exception('Failed to fetch YouTube playlist');
        }

        $tracks = [];
        $playlistName = 'YouTube Playlist';
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (empty($line)) continue;
            $data = json_decode($line, true);
            if (!$data) continue;

            $title = $data['title'] ?? '';
            if (empty($title)) continue;

            // Try to parse Artist - Title
            if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/', $title, $m)) {
                $tracks[] = trim($m[1]) . ' - ' . trim($m[2]);
            } else {
                $channel = $data['channel'] ?? $data['uploader'] ?? '';
                $tracks[] = $channel ? "{$channel} - {$title}" : $title;
            }

            if ($playlistName === 'YouTube Playlist' && !empty($data['playlist_title'])) {
                $playlistName = $data['playlist_title'];
            }
        }

        if (empty($tracks)) {
            throw new Exception('No tracks found in YouTube playlist');
        }

        return [
            'name' => $playlistName,
            'platform' => 'youtube',
            'tracks' => $tracks,
            'count' => count($tracks),
        ];
    }

    /**
     * Fetch Tidal playlist via Monochrome API
     */
    private function fetchTidal(string $playlistId): array
    {
        $url = "https://api.monochrome.tf/playlist/?id={$playlistId}";
        
        $response = $this->httpGet($url);
        if (!$response) {
            throw new Exception('Failed to fetch Tidal playlist');
        }

        $data = json_decode($response, true);
        $playlistData = $data['data'] ?? null;
        
        if (!$playlistData) {
            throw new Exception('Invalid Tidal playlist response');
        }

        $name = $playlistData['title'] ?? 'Tidal Playlist';
        $tracks = [];

        foreach (($playlistData['tracks'] ?? []) as $track) {
            $title = $track['title'] ?? '';
            $artist = $track['artist']['name'] ?? '';
            if ($title && $artist) {
                $tracks[] = "{$artist} - {$title}";
            }
        }

        if (empty($tracks)) {
            throw new Exception('No tracks found in Tidal playlist');
        }

        return [
            'name' => $name,
            'platform' => 'tidal',
            'tracks' => $tracks,
            'count' => count($tracks),
        ];
    }

    /**
     * Fetch Amazon Music playlist (basic scraping)
     */
    private function fetchAmazon(string $url): array
    {
        // Amazon requires JavaScript, so we'll do basic scraping
        // For full support, would need headless browser
        $html = $this->httpGet($url, [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        if (!$html) {
            throw new Exception('Failed to fetch Amazon Music playlist. Amazon requires authentication for most playlists.');
        }

        // Try to extract tracks from page
        $tracks = [];
        $name = 'Amazon Music Playlist';

        // Extract playlist title
        if (preg_match('/<h1[^>]*>([^<]+)<\/h1>/', $html, $m)) {
            $name = html_entity_decode(trim($m[1]));
        }

        // This is a very basic attempt - Amazon heavily relies on JS
        if (preg_match_all('/"title":"([^"]+)".*?"artist":"([^"]+)"/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tracks[] = $match[2] . ' - ' . $match[1];
            }
        }

        if (empty($tracks)) {
            throw new Exception('Could not extract tracks from Amazon Music. The playlist may be private or require authentication.');
        }

        return [
            'name' => $name,
            'platform' => 'amazon',
            'tracks' => $tracks,
            'count' => count($tracks),
        ];
    }

    private function decodeJson(string $str): string
    {
        try {
            return json_decode('"' . $str . '"') ?? $str;
        } catch (Exception $e) {
            return $str;
        }
    }

    private function httpGet(string $url, array $headers = []): ?string
    {
        $ch = curl_init($url);
        
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => $curlHeaders,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $response;
        }

        return null;
    }

    private function runCommand(array $cmd, int $timeout = 30): string
    {
        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);
        
        stream_set_blocking($pipes[1], false);
        $output = '';
        $startTime = time();
        
        while (true) {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]);
            
            if (!$status['running']) break;
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
}
