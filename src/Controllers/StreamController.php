<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MusicLibrary;
use Slim\Psr7\Stream;

class StreamController
{
    private MusicLibrary $library;

    public function __construct(MusicLibrary $library)
    {
        $this->library = $library;
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

        return $response
            ->withStatus(206) // Partial Content
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string)$length)
            ->withHeader('Content-Range', "bytes {$start}-{$end}/{$fileSize}")
            ->withHeader('Accept-Ranges', 'bytes')
            ->withBody(new Stream($stream));
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
