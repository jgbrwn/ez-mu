<?php

namespace App\Services\Audio;

/**
 * Pure PHP Audio Information Reader
 * 
 * Uses getID3 library to read audio metadata without external tools.
 * Replaces ffprobe for shared hosting compatibility.
 */
class AudioInfo
{
    private static ?\getID3 $getID3 = null;

    /**
     * Get audio file information
     * 
     * @param string $filePath Path to audio file
     * @return array{duration: int, bitrate: int, codec: string, sample_rate: int, channels: int}
     */
    public static function analyze(string $filePath): array
    {
        $default = [
            'duration' => 0,
            'bitrate' => 0,
            'codec' => 'unknown',
            'sample_rate' => 0,
            'channels' => 0,
        ];

        if (!file_exists($filePath)) {
            return $default;
        }

        try {
            $getID3 = self::getGetID3();
            $info = $getID3->analyze($filePath);

            return [
                'duration' => (int)($info['playtime_seconds'] ?? 0),
                'bitrate' => (int)(($info['bitrate'] ?? 0) / 1000), // Convert to kbps
                'codec' => self::determineCodec($info),
                'sample_rate' => (int)($info['audio']['sample_rate'] ?? 0),
                'channels' => (int)($info['audio']['channels'] ?? 0),
            ];
        } catch (\Exception $e) {
            error_log("AudioInfo::analyze error: " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Get basic file info without full analysis (faster)
     */
    public static function getBasicInfo(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return [
            'extension' => $extension,
            'size' => file_exists($filePath) ? filesize($filePath) : 0,
            'codec' => self::extensionToCodec($extension),
        ];
    }

    /**
     * Check if file is a valid audio file
     */
    public static function isValidAudio(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        try {
            $getID3 = self::getGetID3();
            $info = $getID3->analyze($filePath);
            
            return isset($info['audio']) && !isset($info['error']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get or create getID3 instance
     */
    private static function getGetID3(): \getID3
    {
        if (self::$getID3 === null) {
            self::$getID3 = new \getID3();
            self::$getID3->option_md5_data = false;
            self::$getID3->option_md5_data_source = false;
            self::$getID3->encoding = 'UTF-8';
        }
        return self::$getID3;
    }

    /**
     * Determine codec from getID3 info
     */
    private static function determineCodec(array $info): string
    {
        // Check for specific format info
        if (isset($info['audio']['dataformat'])) {
            $format = strtolower($info['audio']['dataformat']);
            
            $codecMap = [
                'flac' => 'flac',
                'mp3' => 'mp3',
                'ogg' => 'vorbis',
                'opus' => 'opus',
                'aac' => 'aac',
                'alac' => 'alac',
                'wav' => 'pcm',
                'wma' => 'wma',
            ];
            
            if (isset($codecMap[$format])) {
                return $codecMap[$format];
            }
            
            return $format;
        }

        // Fallback to file type
        if (isset($info['fileformat'])) {
            return strtolower($info['fileformat']);
        }

        return 'unknown';
    }

    /**
     * Map file extension to codec name
     */
    private static function extensionToCodec(string $extension): string
    {
        $map = [
            'flac' => 'flac',
            'mp3' => 'mp3',
            'ogg' => 'vorbis',
            'opus' => 'opus',
            'm4a' => 'aac',
            'aac' => 'aac',
            'wav' => 'pcm',
            'wma' => 'wma',
            'alac' => 'alac',
        ];

        return $map[$extension] ?? $extension;
    }
}
