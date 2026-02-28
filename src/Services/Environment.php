<?php

namespace App\Services;

/**
 * Environment Detection Service
 * 
 * Detects available tools and capabilities for shared hosting compatibility.
 * Auto-configures the application based on what's available.
 */
class Environment
{
    private static ?array $capabilities = null;

    /**
     * Get all detected capabilities
     */
    public static function getCapabilities(): array
    {
        if (self::$capabilities === null) {
            self::$capabilities = self::detectCapabilities();
        }
        return self::$capabilities;
    }

    /**
     * Check if running in shared hosting mode (limited capabilities)
     */
    public static function isSharedHosting(): bool
    {
        $caps = self::getCapabilities();
        // Shared hosting if we don't have yt-dlp or ffmpeg
        return !$caps['yt_dlp'] || !$caps['ffmpeg'];
    }

    /**
     * Check if a specific capability is available
     */
    public static function has(string $capability): bool
    {
        $caps = self::getCapabilities();
        return $caps[$capability] ?? false;
    }

    /**
     * Get path to a binary if available
     */
    public static function getBinaryPath(string $binary): ?string
    {
        $caps = self::getCapabilities();
        $key = $binary . '_path';
        return $caps[$key] ?? null;
    }

    /**
     * Detect all capabilities
     */
    private static function detectCapabilities(): array
    {
        return [
            // Core PHP capabilities
            'curl' => extension_loaded('curl'),
            'sqlite' => extension_loaded('pdo_sqlite'),
            'zip' => extension_loaded('zip'),
            'mbstring' => extension_loaded('mbstring'),
            
            // External binaries
            'yt_dlp' => self::findBinary('yt-dlp') !== null,
            'yt_dlp_path' => self::findBinary('yt-dlp'),
            
            'ffmpeg' => self::findBinary('ffmpeg') !== null,
            'ffmpeg_path' => self::findBinary('ffmpeg'),
            
            'ffprobe' => self::findBinary('ffprobe') !== null,
            'ffprobe_path' => self::findBinary('ffprobe'),
            
            'fpcalc' => self::findBinary('fpcalc') !== null,
            'fpcalc_path' => self::findBinary('fpcalc'),
            
            'metaflac' => self::findBinary('metaflac') !== null,
            'metaflac_path' => self::findBinary('metaflac'),
            
            // Pure PHP alternatives (always available)
            'getid3' => class_exists('\getID3'),
            'pure_php_flac_writer' => true,
            
            // Feature availability
            'can_search_youtube' => self::findBinary('yt-dlp') !== null,
            'can_search_soundcloud' => self::findBinary('yt-dlp') !== null,
            'can_search_monochrome' => extension_loaded('curl'),
            'can_download_youtube' => self::findBinary('yt-dlp') !== null,
            'can_download_soundcloud' => self::findBinary('yt-dlp') !== null,
            'can_download_monochrome' => extension_loaded('curl'),
            'can_fingerprint' => self::findBinary('fpcalc') !== null,
            'can_convert_audio' => self::findBinary('ffmpeg') !== null,
        ];
    }

    /**
     * Find a binary in common locations
     */
    private static function findBinary(string $name): ?string
    {
        // Check configured path first
        $configPath = self::getConfiguredPath($name);
        if ($configPath && is_executable($configPath)) {
            return $configPath;
        }

        // Common locations to check
        $locations = [
            // User's home directory
            getenv('HOME') . '/.local/bin/' . $name,
            getenv('HOME') . '/bin/' . $name,
            
            // Application directory (for uploaded static binaries)
            __DIR__ . '/../../bin/' . $name,
            __DIR__ . '/../../vendor/bin/' . $name,
            
            // System paths
            '/usr/bin/' . $name,
            '/usr/local/bin/' . $name,
            '/opt/bin/' . $name,
        ];

        foreach ($locations as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try 'which' command as fallback (may not work on all hosts)
        // Use escapeshellarg() to prevent command injection
        $which = @shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null');
        if ($which) {
            $path = trim($which);
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get configured binary path from settings or environment
     */
    private static function getConfiguredPath(string $name): ?string
    {
        // Check environment variable
        $envKey = strtoupper(str_replace('-', '_', $name)) . '_PATH';
        $envPath = getenv($envKey);
        if ($envPath) {
            return $envPath;
        }

        return null;
    }

    /**
     * Get a summary of the environment for display
     */
    public static function getSummary(): array
    {
        $caps = self::getCapabilities();
        
        return [
            'mode' => self::isSharedHosting() ? 'Shared Hosting (Limited)' : 'Full Features',
            'search_sources' => array_filter([
                'monochrome' => $caps['can_search_monochrome'] ? 'Available' : 'Unavailable',
                'soundcloud' => $caps['can_search_soundcloud'] ? 'Available' : 'Unavailable',
                'youtube' => $caps['can_search_youtube'] ? 'Available' : 'Unavailable',
            ]),
            'features' => [
                'Monochrome/Tidal Downloads' => $caps['can_download_monochrome'],
                'YouTube Downloads' => $caps['can_download_youtube'],
                'SoundCloud Downloads' => $caps['can_download_soundcloud'],
                'Audio Fingerprinting' => $caps['can_fingerprint'],
                'Audio Conversion' => $caps['can_convert_audio'],
                'FLAC Tagging' => true, // Always available with pure PHP
            ],
            'binaries' => [
                'yt-dlp' => $caps['yt_dlp_path'] ?? 'Not found',
                'ffmpeg' => $caps['ffmpeg_path'] ?? 'Not found',
                'ffprobe' => $caps['ffprobe_path'] ?? 'Not found',
                'fpcalc' => $caps['fpcalc_path'] ?? 'Not found',
                'metaflac' => $caps['metaflac_path'] ?? 'Not found',
            ],
        ];
    }
}
