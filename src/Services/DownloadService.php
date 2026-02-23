<?php

namespace App\Services;

use App\Services\Audio\AudioInfo;
use Exception;

class DownloadService
{
    private Database $db;
    private MonochromeService $monochrome;
    private RateLimiter $rateLimiter;
    private ?MetadataService $metadata;
    private string $musicDir;
    private string $singlesDir;

    public function __construct(
        Database $db,
        MonochromeService $monochrome,
        RateLimiter $rateLimiter,
        ?MetadataService $metadata,
        string $musicDir,
        string $singlesDir
    ) {
        $this->db = $db;
        $this->monochrome = $monochrome;
        $this->rateLimiter = $rateLimiter;
        $this->metadata = $metadata;
        $this->musicDir = $musicDir;
        $this->singlesDir = $singlesDir;
    }

    /**
     * Queue a download job
     */
    public function queueDownload(array $data): string
    {
        $id = $this->generateId();
        
        $this->db->execute(
            'INSERT INTO jobs (id, video_id, source, title, artist, url, thumbnail, status, download_type, convert_to_flac)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $data['video_id'] ?? '',
                $data['source'] ?? 'monochrome',
                $data['title'] ?? 'Unknown',
                $data['artist'] ?? 'Unknown',
                $data['url'] ?? '',
                $data['thumbnail'] ?? '',
                'queued',
                $data['download_type'] ?? 'single',
                $data['convert_to_flac'] ?? 1,
            ]
        );

        return $id;
    }

    /**
     * Process a queued download
     */
    public function processDownload(string $jobId): bool
    {
        // Prevent timeout during download (shared hosting compatible)
        set_time_limit(0);
        
        $job = $this->db->queryOne('SELECT * FROM jobs WHERE id = ?', [$jobId]);
        if (!$job) {
            throw new Exception("Job not found: {$jobId}");
        }

        $this->db->execute(
            "UPDATE jobs SET status = 'processing', started_at = datetime('now') WHERE id = ?",
            [$jobId]
        );

        try {
            $source = $job['source'] ?? 'youtube';
            
            if ($source === 'monochrome') {
                $result = $this->downloadFromMonochrome($job);
            } else {
                $result = $this->downloadWithYtDlp($job);
            }

            // Enrich metadata for non-Monochrome sources
            // (Monochrome/Tidal metadata is authoritative - only enrich year)
            if ($this->metadata && !empty($result['file_path'])) {
                $result = $this->enrichMetadata($job, $result, $source === 'monochrome');
            }
            
            $this->db->execute(
                "UPDATE jobs SET 
                    status = 'completed', 
                    file_path = ?,
                    codec = ?,
                    bitrate = ?,
                    duration = ?,
                    metadata_source = ?,
                    completed_at = datetime('now')
                 WHERE id = ?",
                [
                    $result['file_path'],
                    $result['codec'] ?? 'unknown',
                    $result['bitrate'] ?? 0,
                    $result['duration'] ?? 0,
                    $result['metadata_source'] ?? 'source',
                    $jobId
                ]
            );

            $this->addToLibrary($job, $result);
            return true;

        } catch (Exception $e) {
            $this->db->execute(
                "UPDATE jobs SET status = 'failed', error = ?, completed_at = datetime('now') WHERE id = ?",
                [$e->getMessage(), $jobId]
            );
            return false;
        }
    }

    /**
     * Download from Monochrome/Tidal (lossless FLAC)
     */
    private function downloadFromMonochrome(array $job): array
    {
        $trackId = $job['video_id'];
        
        $info = $this->monochrome->getTrackInfo($trackId);
        if (!$info) {
            throw new Exception("Failed to get track info for {$trackId}");
        }

        $artist = $this->sanitizeFilename($info['artist']['name'] ?? $job['artist'] ?? 'Unknown');
        $title = $this->sanitizeFilename($info['title'] ?? $job['title'] ?? 'Unknown');
        $album = $info['album']['title'] ?? 'Singles';
        $coverUuid = $info['album']['cover'] ?? '';

        $outputDir = $this->musicDir . '/' . $this->singlesDir . '/' . $artist;
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputPath = $outputDir . '/' . $title . '.flac';
        
        $result = $this->monochrome->downloadTrack($trackId, $outputPath);
        
        return [
            'file_path' => $outputPath,
            'codec' => 'flac',
            'bitrate' => $result['bitrate'] ?? 1411,
            'duration' => $result['duration'] ?? 0,
            'artist' => $result['artist'] ?? $artist,
            'title' => $result['title'] ?? $title,
            'album' => $result['album'] ?? $album,
            'thumbnail' => $result['thumbnail'] ?? $this->monochrome->getCoverUrl($coverUuid),
        ];
    }

    private function getYtDlpPath(): ?string
    {
        return Environment::getBinaryPath('yt-dlp');
    }

    /**
     * Download using yt-dlp (YouTube/SoundCloud)
     */
    private function downloadWithYtDlp(array $job): array
    {
        $ytDlpPath = $this->getYtDlpPath();
        if (!$ytDlpPath) {
            throw new Exception('yt-dlp not available. YouTube/SoundCloud downloads require yt-dlp to be installed.');
        }

        $source = $job['source'] ?? 'youtube';
        
        // Rate limit based on source
        if ($source === 'youtube') {
            $this->rateLimiter->wait('youtube', 10, 60);
        } else {
            $this->rateLimiter->wait('soundcloud', 10, 60);
        }

        $outputDir = $this->musicDir . '/' . $this->singlesDir;
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $artist = $this->sanitizeFilename($job['artist'] ?? 'Unknown');
        $title = $this->sanitizeFilename($job['title'] ?? 'Unknown');
        
        $artistDir = $outputDir . '/' . $artist;
        if (!is_dir($artistDir)) {
            mkdir($artistDir, 0755, true);
        }

        $outputTemplate = $artistDir . '/' . $title;
        $convertToFlac = (bool)($job['convert_to_flac'] ?? true);

        $url = $job['url'] ?? '';
        if (empty($url) && !empty($job['video_id'])) {
            if ($source === 'youtube') {
                $url = 'https://www.youtube.com/watch?v=' . $job['video_id'];
            }
        }

        if (empty($url)) {
            throw new Exception('No URL to download');
        }

        $cmd = [
            $ytDlpPath,
            '-x',
            '--audio-quality', '0',
            '--embed-thumbnail',
            '--embed-metadata',
            '--no-playlist',
            '-o', $outputTemplate . '.%(ext)s',
        ];

        if ($convertToFlac) {
            $cmd[] = '--audio-format';
            $cmd[] = 'flac';
        } else {
            $cmd[] = '--audio-format';
            $cmd[] = 'best';
        }

        $cmd[] = $url;

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
            throw new Exception('Failed to start yt-dlp');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new Exception('Download failed: ' . $stderr);
        }

        $extension = $convertToFlac ? 'flac' : '*';
        $pattern = $artistDir . '/' . $title . '.*';
        $files = glob($pattern);
        
        if (empty($files)) {
            throw new Exception('Downloaded file not found');
        }

        $filePath = $files[0];
        $fileInfo = $this->getAudioInfo($filePath);

        return [
            'file_path' => $filePath,
            'codec' => $fileInfo['codec'] ?? pathinfo($filePath, PATHINFO_EXTENSION),
            'bitrate' => $fileInfo['bitrate'] ?? 0,
            'duration' => $fileInfo['duration'] ?? 0,
        ];
    }

    /**
     * Enrich metadata using AcoustID/MusicBrainz
     * 
     * For Monochrome (Tidal): only fill in year if missing, keep everything else
     * For YouTube/SoundCloud: use fingerprint/MB lookup to get canonical metadata
     */
    private function enrichMetadata(array $job, array $result, bool $isMonochrome = false): array
    {
        $artist = $result['artist'] ?? $job['artist'] ?? 'Unknown';
        $title = $result['title'] ?? $job['title'] ?? 'Unknown';
        $filePath = $result['file_path'];

        error_log("Enriching metadata for: {$artist} - {$title}");

        $mbData = $this->metadata->lookupMetadata($artist, $title, $filePath);
        if (!$mbData) {
            error_log("No MusicBrainz data found");
            return $result;
        }

        error_log("MusicBrainz data: " . json_encode($mbData));

        if ($isMonochrome) {
            // For Monochrome/Tidal: only enrich year from MusicBrainz
            // Tidal metadata is authoritative for artist/title/album
            if (!empty($mbData['year']) && empty($result['year'])) {
                $result['year'] = $mbData['year'];
            }
            $result['metadata_source'] = 'tidal';
            
            // Apply Tidal's metadata to the file (CDN files don't have tags)
            $this->metadata->applyMetadataToFile(
                $filePath,
                $artist,
                $title,
                $result['album'] ?? null,
                $result['year'] ?? null
            );
        } else {
            // For YouTube/SoundCloud: use MusicBrainz canonical data
            $oldArtist = $artist;
            $oldTitle = $title;

            if (!empty($mbData['artist'])) {
                $result['artist'] = $mbData['artist'];
            }
            if (!empty($mbData['title'])) {
                $result['title'] = $mbData['title'];
            }
            if (!empty($mbData['album'])) {
                $result['album'] = $mbData['album'];
            }
            if (!empty($mbData['year'])) {
                $result['year'] = $mbData['year'];
            }
            $result['metadata_source'] = $mbData['metadata_source'] ?? 'musicbrainz';

            // Apply tags to the file
            $this->metadata->applyMetadataToFile(
                $filePath,
                $result['artist'] ?? $artist,
                $result['title'] ?? $title,
                $result['album'] ?? null,
                $result['year'] ?? null
            );

            // If artist changed significantly, relocate file
            $newArtist = $this->sanitizeFilename($result['artist'] ?? $artist);
            $newTitle = $this->sanitizeFilename($result['title'] ?? $title);
            
            if ($newArtist !== $this->sanitizeFilename($oldArtist) ||
                $newTitle !== $this->sanitizeFilename($oldTitle)) {
                $result = $this->relocateFile($result, $newArtist, $newTitle);
            }
        }

        return $result;
    }

    /**
     * Relocate file to correct artist/title path after metadata enrichment
     */
    private function relocateFile(array $result, string $newArtist, string $newTitle): array
    {
        $oldPath = $result['file_path'];
        if (!file_exists($oldPath)) {
            return $result;
        }

        $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
        $newDir = $this->musicDir . '/' . $this->singlesDir . '/' . $newArtist;
        
        if (!is_dir($newDir)) {
            mkdir($newDir, 0755, true);
        }

        $newPath = $newDir . '/' . $newTitle . '.' . $ext;
        
        // Avoid overwriting existing file
        if (file_exists($newPath) && $newPath !== $oldPath) {
            $i = 1;
            while (file_exists($newPath)) {
                $newPath = $newDir . '/' . $newTitle . ' (' . $i . ').' . $ext;
                $i++;
            }
        }

        if ($oldPath !== $newPath) {
            if (rename($oldPath, $newPath)) {
                error_log("Relocated file: {$oldPath} -> {$newPath}");
                $result['file_path'] = $newPath;

                // Clean up empty old directory
                $oldDir = dirname($oldPath);
                if (is_dir($oldDir) && count(glob($oldDir . '/*')) === 0) {
                    rmdir($oldDir);
                }
            }
        }

        return $result;
    }

    private function addToLibrary(array $job, array $result): void
    {
        $id = $this->generateId();
        
        $this->db->execute(
            'INSERT INTO library (id, job_id, title, artist, album, file_path, file_size, duration, codec, bitrate, thumbnail, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $job['id'],
                $result['title'] ?? $job['title'],
                $result['artist'] ?? $job['artist'],
                $result['album'] ?? 'Singles',
                $result['file_path'],
                file_exists($result['file_path']) ? filesize($result['file_path']) : 0,
                $result['duration'] ?? 0,
                $result['codec'] ?? 'unknown',
                $result['bitrate'] ?? 0,
                $result['thumbnail'] ?? $job['thumbnail'],
                $job['source'],
            ]
        );
    }

    /**
     * Get audio file information using pure PHP (shared hosting compatible)
     */
    private function getAudioInfo(string $filePath): array
    {
        return AudioInfo::analyze($filePath);
    }

    private function sanitizeFilename(string $name): string
    {
        // Remove path traversal attempts
        $name = str_replace(['..', '/', '\\'], '', $name);
        // Remove other dangerous characters
        $name = preg_replace('/[:*?"<>|]/', '', $name);
        // Remove control characters
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        // Trim dots and spaces from ends
        $name = trim($name, '. ');
        // Limit length to prevent filesystem issues
        if (strlen($name) > 200) {
            $name = substr($name, 0, 200);
        }
        return $name ?: 'Unknown';
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function getPendingCount(): int
    {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM jobs WHERE status IN ('queued', 'processing')"
        );
        return (int)($result['count'] ?? 0);
    }

    public function getNextQueuedJob(): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
        );
    }
}
