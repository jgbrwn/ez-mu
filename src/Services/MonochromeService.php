<?php

namespace App\Services;

use Exception;

/**
 * Monochrome/Tidal API Service
 * 
 * Provides lossless FLAC downloads directly from Tidal CDN.
 */
class MonochromeService
{
    private const DEFAULT_API_URL = 'https://triton.squid.wtf';
    private string $apiUrl;
    private const COVER_BASE = 'https://resources.tidal.com/images';
    private const TIMEOUT = 15;

    private RateLimiter $rateLimiter;

    public function __construct(RateLimiter $rateLimiter)
    {
        $this->rateLimiter = $rateLimiter;
        // Allow overriding API URL via environment variable
        $this->apiUrl = $_ENV['MONOCHROME_API_URL'] ?? self::DEFAULT_API_URL;
    }

    /**
     * Search Monochrome/Tidal for tracks
     * 
     * @return array{results: array, error: string|null}
     */
    public function search(string $query, int $limit = 15): array
    {
        if (empty(trim($query))) {
            return ['results' => [], 'error' => null];
        }

        // Rate limit: 10 requests per minute for Monochrome
        $this->rateLimiter->wait('monochrome', 10, 60);

        try {
            $url = $this->apiUrl . '/search/?' . http_build_query(['s' => $query]);
            $http = $this->httpGet($url);
            
            // cURL error
            if ($http['error']) {
                return ['results' => [], 'error' => 'Monochrome: ' . $http['error']];
            }
            
            // No response body
            if (!$http['body']) {
                return ['results' => [], 'error' => 'Monochrome API unavailable'];
            }

            $data = json_decode($http['body'], true);
            
            // Check for API error response (can be 'error' or 'detail')
            $apiError = $data['error'] ?? $data['detail'] ?? null;
            if ($apiError) {
                $errorMsg = is_string($apiError) ? $apiError : 'Unknown error';
                return ['results' => [], 'error' => "Monochrome: {$errorMsg}"];
            }
            
            // Non-2xx status without error message in body
            if ($http['code'] < 200 || $http['code'] >= 300) {
                return ['results' => [], 'error' => "Monochrome: HTTP {$http['code']}"];
            }
            
            $items = $data['data']['items'] ?? [];

            $results = [];
            foreach ($items as $item) {
                if (!($item['streamReady'] ?? false)) {
                    continue;
                }

                $trackId = (string)($item['id'] ?? '');
                $title = $item['title'] ?? 'Unknown';
                $artist = $item['artist']['name'] ?? 'Unknown';
                $album = $item['album']['title'] ?? 'Singles';
                $coverUuid = $item['album']['cover'] ?? '';
                $duration = (int)($item['duration'] ?? 0);
                $quality = $item['audioQuality'] ?? '';

                $results[] = [
                    'id' => $trackId,
                    'video_id' => $trackId,
                    'title' => $title,
                    'artist' => $artist,
                    'album' => $album,
                    'duration' => $duration,
                    'duration_string' => $this->formatDuration($duration),
                    'thumbnail' => $this->getCoverUrl($coverUuid),
                    'url' => "https://monochrome.tf/track/{$trackId}",
                    'source' => 'monochrome',
                    'quality' => $quality,
                    'quality_score' => $this->scoreResult($item, $query),
                    'cover_uuid' => $coverUuid,
                    'isrc' => $item['isrc'] ?? null,
                ];
            }

            // Sort by quality score
            usort($results, fn($a, $b) => $b['quality_score'] <=> $a['quality_score']);

            return ['results' => array_slice($results, 0, $limit), 'error' => null];

        } catch (Exception $e) {
            error_log("Monochrome search error: " . $e->getMessage());
            return ['results' => [], 'error' => 'Monochrome: ' . $e->getMessage()];
        }
    }

    /**
     * Get track info from Monochrome API
     */
    public function getTrackInfo(string $trackId): ?array
    {
        $this->rateLimiter->wait('monochrome', 10, 60);

        try {
            $url = $this->apiUrl . '/info/?' . http_build_query(['id' => $trackId]);
            $http = $this->httpGet($url);
            
            if (!$http['body'] || $http['code'] < 200 || $http['code'] >= 300) {
                return null;
            }

            $data = json_decode($http['body'], true);
            return $data['data'] ?? null;

        } catch (Exception $e) {
            error_log("Monochrome track info error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get stream URL for downloading
     */
    public function getStreamUrl(string $trackId, string $quality = 'LOSSLESS'): ?array
    {
        $this->rateLimiter->wait('monochrome', 10, 60);

        $qualities = ['LOSSLESS', 'HIGH'];
        if ($quality !== 'LOSSLESS') {
            $qualities = [$quality, 'LOSSLESS', 'HIGH'];
        }

        foreach ($qualities as $q) {
            try {
                $url = $this->apiUrl . '/track/?' . http_build_query(['id' => $trackId, 'quality' => $q]);
                $http = $this->httpGet($url);
                
                if (!$http['body'] || $http['code'] < 200 || $http['code'] >= 300) {
                    continue;
                }

                $data = json_decode($http['body'], true);
                $trackData = $data['data'] ?? null;
                
                if (!$trackData || empty($trackData['manifest'])) {
                    continue;
                }

                $manifest = json_decode(base64_decode($trackData['manifest']), true);
                
                $encryption = $manifest['encryptionType'] ?? 'NONE';
                if ($encryption !== 'NONE') {
                    error_log("Track {$trackId} is encrypted: {$encryption}");
                    continue;
                }

                $urls = $manifest['urls'] ?? [];
                if (empty($urls)) {
                    continue;
                }

                return [
                    'url' => $urls[0],
                    'mime_type' => $manifest['mimeType'] ?? 'audio/flac',
                    'codec' => $manifest['codecs'] ?? 'flac',
                    'bit_depth' => $trackData['bitDepth'] ?? null,
                    'sample_rate' => $trackData['sampleRate'] ?? null,
                    'quality' => $trackData['audioQuality'] ?? $q,
                ];

            } catch (Exception $e) {
                error_log("Monochrome stream URL error ({$q}): " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Download FLAC directly from Tidal CDN
     */
    public function downloadTrack(string $trackId, string $outputPath): array
    {
        $info = $this->getTrackInfo($trackId);
        if (!$info) {
            throw new Exception("Failed to get track info for track {$trackId}");
        }

        $stream = $this->getStreamUrl($trackId);
        if (!$stream) {
            throw new Exception("Failed to get stream URL for track {$trackId}");
        }

        // Ensure directory exists
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Download the FLAC file with streaming (memory efficient)
        // Prevent PHP timeout during large downloads
        set_time_limit(0);
        
        $ch = curl_init($stream['url']);
        $fp = fopen($outputPath, 'wb');
        
        if ($fp === false) {
            throw new Exception("Failed to open output file: {$outputPath}");
        }
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 600, // 10 minutes for large FLAC files
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_LOW_SPEED_LIMIT => 1024, // Abort if speed < 1KB/s
            CURLOPT_LOW_SPEED_TIME => 30,    // for 30 seconds
            CURLOPT_USERAGENT => 'EZ-MU/1.0',
        ]);
        
        $success = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode >= 400) {
            // Clean up failed download
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
            throw new Exception("Download failed (HTTP {$httpCode}): " . ($error ?: 'Unknown error'));
        }
        
        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new Exception("Download resulted in empty file");
        }

        // Embed cover art if available
        $coverUuid = $info['album']['cover'] ?? '';
        if ($coverUuid) {
            $this->embedCoverArt($outputPath, $coverUuid);
        }

        return [
            'file_path' => $outputPath,
            'title' => $info['title'] ?? 'Unknown',
            'artist' => $info['artist']['name'] ?? 'Unknown',
            'album' => $info['album']['title'] ?? 'Singles',
            'duration' => $info['duration'] ?? 0,
            'codec' => 'flac',
            'bitrate' => $stream['bit_depth'] ? ($stream['sample_rate'] * $stream['bit_depth'] * 2 / 1000) : 1411,
            'quality' => $stream['quality'],
            'thumbnail' => $this->getCoverUrl($coverUuid),
        ];
    }

    /**
     * Embed cover art into FLAC file using metaflac
     */
    private function embedCoverArt(string $audioFile, string $coverUuid): void
    {
        if (empty($coverUuid)) {
            return;
        }

        try {
            $coverUrl = $this->getCoverUrl($coverUuid, 640);
            $http = $this->httpGet($coverUrl);
            
            if (!$http['body'] || $http['code'] < 200 || $http['code'] >= 300) {
                return;
            }

            // Save cover temporarily
            $tempCover = sys_get_temp_dir() . '/ez-mu-cover-' . md5($coverUuid) . '.jpg';
            file_put_contents($tempCover, $http['body']);

            // Use metaflac to embed (if available)
            if (shell_exec('which metaflac')) {
                $cmd = sprintf(
                    'metaflac --import-picture-from=%s %s 2>&1',
                    escapeshellarg($tempCover),
                    escapeshellarg($audioFile)
                );
                exec($cmd);
            }

            unlink($tempCover);
        } catch (Exception $e) {
            error_log("Cover embed failed: " . $e->getMessage());
        }
    }

    /**
     * Get cover art URL from Tidal CDN
     */
    public function getCoverUrl(string $coverUuid, int $size = 320): string
    {
        if (empty($coverUuid)) {
            return '';
        }
        $path = str_replace('-', '/', $coverUuid);
        return self::COVER_BASE . "/{$path}/{$size}x{$size}.jpg";
    }

    /**
     * Score a search result for ranking
     */
    private function scoreResult(array $item, string $query): int
    {
        $score = 0;
        
        // Quality bonuses - the main point of Monochrome
        $qualityBonuses = [
            'HI_RES_LOSSLESS' => 120,
            'LOSSLESS' => 100,
            'HIGH' => 30,
        ];
        $quality = $item['audioQuality'] ?? '';
        $score += $qualityBonuses[$quality] ?? 0;

        // Title match bonus
        $title = strtolower($item['title'] ?? '');
        $artist = strtolower($item['artist']['name'] ?? '');
        $queryLower = strtolower($query);
        
        if (str_contains($title, $queryLower) || str_contains($queryLower, $title)) {
            $score += 50;
        }
        if (str_contains($artist, $queryLower) || str_contains($queryLower, $artist)) {
            $score += 30;
        }

        // Popularity tiebreaker
        $popularity = $item['popularity'] ?? 0;
        $score += min($popularity / 10, 15);

        return $score;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) return '0:00';
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * HTTP GET with full response info
     * 
     * @return array{body: string|false, code: int, error: string|null}
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => 'EZ-MU/1.0',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'body' => $response,
            'code' => $httpCode,
            'error' => $curlError ?: null,
        ];
    }
}
