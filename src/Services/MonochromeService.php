<?php

namespace App\Services;

use Exception;

/**
 * Monochrome/Tidal API Service
 * 
 * Provides lossless FLAC downloads directly from Tidal CDN.
 * Automatically discovers and falls back to working API mirrors.
 */
class MonochromeService
{
    /**
     * Prioritized list of Monochrome API mirrors
     * See: https://github.com/monochrome-music/monochrome/blob/main/INSTANCES.md
     */
    private const API_MIRRORS = [
        'https://triton.squid.wtf',           // squid.wtf community
        'https://wolf.qqdl.site',             // Lucida/QQDL community
        'https://maus.qqdl.site',             // Lucida/QQDL community
        'https://tidal.kinoplus.online',      // Kinoplus community
        'https://api.monochrome.tf',          // Official (currently down)
        'https://monochrome-api.samidy.com',  // Official secondary
        'https://arran.monochrome.tf',        // Official tertiary
    ];
    
    private const COVER_BASE = 'https://resources.tidal.com/images';
    private const TIMEOUT = 15;
    private const HEALTH_CHECK_TIMEOUT = 5;
    private const MIRROR_CACHE_TTL = 300; // 5 minutes

    private RateLimiter $rateLimiter;
    private ?SettingsService $settings;
    private ?string $workingMirror = null;

    public function __construct(RateLimiter $rateLimiter, ?SettingsService $settings = null)
    {
        $this->rateLimiter = $rateLimiter;
        $this->settings = $settings;
    }
    
    /**
     * Get a working API mirror URL, with caching and fallback
     */
    public function getApiUrl(): string
    {
        // Check for env override first (always respected)
        $envUrl = $_ENV['MONOCHROME_API_URL'] ?? null;
        if ($envUrl) {
            return $envUrl;
        }
        
        // Return cached working mirror if still in memory
        if ($this->workingMirror !== null) {
            return $this->workingMirror;
        }
        
        // Try to load from settings cache
        if ($this->settings) {
            $cached = $this->settings->get('monochrome_mirror_cache');
            if ($cached) {
                $cache = json_decode($cached, true);
                if ($cache && isset($cache['url'], $cache['expires']) && $cache['expires'] > time()) {
                    $this->workingMirror = $cache['url'];
                    return $this->workingMirror;
                }
            }
        }
        
        // Discover a working mirror
        $mirror = $this->discoverWorkingMirror();
        $this->workingMirror = $mirror;
        
        // Cache the result
        if ($this->settings && $mirror) {
            $this->settings->set('monochrome_mirror_cache', json_encode([
                'url' => $mirror,
                'expires' => time() + self::MIRROR_CACHE_TTL,
            ]));
        }
        
        return $mirror ?? self::API_MIRRORS[0];
    }
    
    /**
     * Discover a working API mirror by testing each one
     */
    private function discoverWorkingMirror(): ?string
    {
        foreach (self::API_MIRRORS as $mirror) {
            if ($this->testMirrorHealth($mirror)) {
                error_log("Monochrome: Using mirror {$mirror}");
                return $mirror;
            }
        }
        
        error_log("Monochrome: All mirrors failed health check");
        return null;
    }
    
    /**
     * Test if a mirror is healthy by making a lightweight search request
     */
    private function testMirrorHealth(string $mirrorUrl): bool
    {
        $ch = curl_init($mirrorUrl . '/search/?s=test');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::HEALTH_CHECK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HEALTH_CHECK_TIMEOUT,
            CURLOPT_USERAGENT => 'EZ-MU/1.0',
            CURLOPT_NOBODY => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return false;
        }
        
        // Verify response has expected structure
        $data = json_decode($response, true);
        return isset($data['data']['items']);
    }
    
    /**
     * Invalidate the cached mirror (call when a request fails)
     */
    public function invalidateMirrorCache(): void
    {
        $this->workingMirror = null;
        if ($this->settings) {
            $this->settings->set('monochrome_mirror_cache', '');
        }
    }
    
    /**
     * Get list of all known mirrors (for diagnostics/settings)
     */
    public static function getMirrorList(): array
    {
        return self::API_MIRRORS;
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

        // Try with current/cached mirror first
        $result = $this->doSearch($query, $limit);
        
        // If failed, invalidate cache and try discovering a new mirror
        if ($result['error'] !== null && !isset($_ENV['MONOCHROME_API_URL'])) {
            error_log("Monochrome search failed, trying mirror discovery...");
            $this->invalidateMirrorCache();
            $result = $this->doSearch($query, $limit);
        }
        
        return $result;
    }
    
    /**
     * Perform the actual search request
     */
    private function doSearch(string $query, int $limit): array
    {
        try {
            $apiUrl = $this->getApiUrl();
            $url = $apiUrl . '/search/?' . http_build_query(['s' => $query]);
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
            $apiUrl = $this->getApiUrl();
            $url = $apiUrl . '/info/?' . http_build_query(['id' => $trackId]);
            $http = $this->httpGet($url);
            
            if (!$http['body'] || $http['code'] < 200 || $http['code'] >= 300) {
                // Try mirror discovery on failure
                if (!isset($_ENV['MONOCHROME_API_URL'])) {
                    $this->invalidateMirrorCache();
                    $apiUrl = $this->getApiUrl();
                    $url = $apiUrl . '/info/?' . http_build_query(['id' => $trackId]);
                    $http = $this->httpGet($url);
                    if (!$http['body'] || $http['code'] < 200 || $http['code'] >= 300) {
                        return null;
                    }
                } else {
                    return null;
                }
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

        $apiUrl = $this->getApiUrl();
        
        foreach ($qualities as $q) {
            try {
                $url = $apiUrl . '/track/?' . http_build_query(['id' => $trackId, 'quality' => $q]);
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
