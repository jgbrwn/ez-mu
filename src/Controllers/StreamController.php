<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MusicLibrary;
use App\Services\LimitedStream;
use Slim\Psr7\Stream;

class StreamController
{
    private MusicLibrary $library;
    private string $musicDir;

    public function __construct(MusicLibrary $library, string $musicDir)
    {
        $this->library = $library;
        $this->musicDir = $musicDir;
    }

    /**
     * Stream an audio file
     */
    public function stream(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $track = $this->library->getTrack($id);

        if (!$track) {
            $response->getBody()->write('Track not found');
            return $response->withStatus(404);
        }

        $filePath = $track['file_path'];
        if (!file_exists($filePath)) {
            $response->getBody()->write('File not found');
            return $response->withStatus(404);
        }
        
        // Security: Validate file path is within allowed music directory
        // This prevents path traversal attacks if database records are manipulated
        $realPath = realpath($filePath);
        $musicDirReal = realpath($this->musicDir);
        
        if (!$realPath || !$musicDirReal || !str_starts_with($realPath, $musicDirReal)) {
            error_log('StreamController: Path traversal attempt blocked for track ' . $id . 
                     ': ' . $filePath . ' (resolved: ' . ($realPath ?: 'false') . ')');
            $response->getBody()->write('Access denied');
            return $response->withStatus(404); // Use 404 to not reveal path validation
        }

        $fileSize = filesize($filePath);
        $mimeType = $this->getMimeType($filePath);

        // Handle range requests for seeking
        $range = $request->getHeaderLine('Range');
        
        if ($range) {
            return $this->handleRangeRequest($response, $filePath, $fileSize, $mimeType, $range);
        }

        // Full file response - use chunked streaming for large files
        // Disable output buffering for better memory efficiency
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Prevent timeout for large files
        set_time_limit(0);
        
        $stream = fopen($filePath, 'rb');
        
        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string)$fileSize)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Cache-Control', 'public, max-age=31536000')
            ->withBody(new Stream($stream));
    }

    /**
     * Handle HTTP Range requests (for audio seeking)
     */
    private function handleRangeRequest(
        Response $response,
        string $filePath,
        int $fileSize,
        string $mimeType,
        string $range
    ): Response {
        // Parse range header
        if (!preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            return $response->withStatus(416); // Range Not Satisfiable
        }

        $start = (int)$matches[1];
        $end = !empty($matches[2]) ? (int)$matches[2] : $fileSize - 1;

        if ($start > $end || $start >= $fileSize) {
            return $response->withStatus(416);
        }

        $length = $end - $start + 1;

        // Disable output buffering for streaming
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        set_time_limit(0);
        
        $stream = fopen($filePath, 'rb');
        fseek($stream, $start);

        // Use LimitedStream to ensure we only send the requested bytes
        // Regular Stream would read until EOF, ignoring Content-Length
        $limitedStream = new LimitedStream($stream, $length);

        return $response
            ->withStatus(206) // Partial Content
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string)$length)
            ->withHeader('Content-Range', "bytes {$start}-{$end}/{$fileSize}")
            ->withHeader('Accept-Ranges', 'bytes')
            ->withBody($limitedStream);
    }

    /**
     * Get MIME type for audio file
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'flac' => 'audio/flac',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'opus' => 'audio/opus',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
