<?php

namespace App\Services;

use Psr\Http\Message\ResponseInterface;

/**
 * FileStreamer - Shared hosting compatible file streaming
 * 
 * Streams files in chunks to avoid memory limits and timeouts.
 */
class FileStreamer
{
    private const CHUNK_SIZE = 8192; // 8KB chunks
    
    /**
     * Stream a file to the response with chunked transfer
     * 
     * This method bypasses PSR-7 body handling and writes directly
     * to php://output for better memory efficiency on shared hosting.
     */
    public static function streamFile(
        ResponseInterface $response,
        string $filePath,
        string $filename,
        string $contentType = 'application/octet-stream',
        bool $deleteAfter = false
    ): void {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('File not found: ' . $filePath);
        }

        $fileSize = filesize($filePath);
        
        // Disable output buffering for streaming
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Prevent script timeout for large files
        set_time_limit(0);
        
        // Send headers directly (bypass PSR-7 for streaming)
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // For Apache mod_xsendfile support (if available)
        // header('X-Sendfile: ' . $filePath);
        
        // Stream the file in chunks
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open file for reading');
        }
        
        while (!feof($handle)) {
            echo fread($handle, self::CHUNK_SIZE);
            flush();
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
        
        fclose($handle);
        
        // Delete temp file if requested
        if ($deleteAfter && file_exists($filePath)) {
            unlink($filePath);
        }
        
        exit; // Stop script execution after streaming
    }
    
    /**
     * Stream using PSR-7 response (fallback for smaller files)
     * More compatible but may use more memory
     */
    public static function streamWithResponse(
        ResponseInterface $response,
        string $filePath,
        string $filename,
        string $contentType = 'application/octet-stream'
    ): ResponseInterface {
        if (!file_exists($filePath)) {
            $response->getBody()->write('File not found');
            return $response->withStatus(404);
        }
        
        $stream = fopen($filePath, 'rb');
        
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"')
            ->withHeader('Content-Length', (string)filesize($filePath))
            ->withHeader('Cache-Control', 'no-cache, must-revalidate')
            ->withBody(new \Slim\Psr7\Stream($stream));
    }
}
