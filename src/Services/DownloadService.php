<?php

namespace App\Services;

use Exception;

class DownloadService
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
                $data['source'] ?? 'youtube',
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
        $job = $this->db->queryOne('SELECT * FROM jobs WHERE id = ?', [$jobId]);
        if (!$job) {
            throw new Exception("Job not found: {$jobId}");
        }

        // Update status to processing
        $this->db->execute(
            "UPDATE jobs SET status = 'processing', started_at = datetime('now') WHERE id = ?",
            [$jobId]
        );

        try {
            $result = $this->downloadWithYtDlp($job);
            
            // Update job with success
            $this->db->execute(
                "UPDATE jobs SET 
                    status = 'completed', 
                    file_path = ?,
                    codec = ?,
                    bitrate = ?,
                    duration = ?,
                    completed_at = datetime('now')
                 WHERE id = ?",
                [
                    $result['file_path'],
                    $result['codec'] ?? 'unknown',
                    $result['bitrate'] ?? 0,
                    $result['duration'] ?? 0,
                    $jobId
                ]
            );

            // Add to library
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

    private function getYtDlpPath(): string
    {
        $userPath = getenv('HOME') . '/.local/bin/yt-dlp';
        return file_exists($userPath) ? $userPath : 'yt-dlp';
    }

    /**
     * Download using yt-dlp
     */
    private function downloadWithYtDlp(array $job): array
    {
        $outputDir = $this->musicDir . '/' . $this->singlesDir;
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $artist = $this->sanitizeFilename($job['artist'] ?? 'Unknown');
        $title = $this->sanitizeFilename($job['title'] ?? 'Unknown');
        
        // Create artist subdirectory
        $artistDir = $outputDir . '/' . $artist;
        if (!is_dir($artistDir)) {
            mkdir($artistDir, 0755, true);
        }

        $outputTemplate = $artistDir . '/' . $title;
        $convertToFlac = (bool)($job['convert_to_flac'] ?? true);

        // Determine URL to download
        $url = $job['url'] ?? '';
        if (empty($url) && !empty($job['video_id'])) {
            if ($job['source'] === 'youtube') {
                $url = 'https://www.youtube.com/watch?v=' . $job['video_id'];
            }
        }

        if (empty($url)) {
            throw new Exception('No URL to download');
        }

        // Build yt-dlp command
        $cmd = [
            $this->getYtDlpPath(),
            '-x',  // Extract audio
            '--audio-quality', '0',  // Best quality
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

        // Execute download
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

        // Find the downloaded file
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
     * Add downloaded track to library
     */
    private function addToLibrary(array $job, array $result): void
    {
        $id = $this->generateId();
        
        $this->db->execute(
            'INSERT INTO library (id, job_id, title, artist, file_path, file_size, duration, codec, bitrate, thumbnail, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $job['id'],
                $job['title'],
                $job['artist'],
                $result['file_path'],
                filesize($result['file_path']),
                $result['duration'],
                $result['codec'],
                $result['bitrate'],
                $job['thumbnail'],
                $job['source'],
            ]
        );
    }

    /**
     * Get audio file information using ffprobe
     */
    private function getAudioInfo(string $filePath): array
    {
        $cmd = [
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $filePath
        ];

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
            return [];
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $data = json_decode($output, true);
        if (!$data) {
            return [];
        }

        $format = $data['format'] ?? [];
        $audioStream = null;
        foreach (($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? '') === 'audio') {
                $audioStream = $stream;
                break;
            }
        }

        return [
            'duration' => (int)($format['duration'] ?? 0),
            'bitrate' => (int)(($format['bit_rate'] ?? 0) / 1000),
            'codec' => $audioStream['codec_name'] ?? 'unknown',
        ];
    }

    private function sanitizeFilename(string $name): string
    {
        // Remove or replace invalid characters
        $name = preg_replace('/[\\\/:*?"<>|]/', '', $name);
        $name = trim($name, '. ');
        return $name ?: 'Unknown';
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Get pending jobs count
     */
    public function getPendingCount(): int
    {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM jobs WHERE status IN ('queued', 'processing')"
        );
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get next queued job
     */
    public function getNextQueuedJob(): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
        );
    }
}
