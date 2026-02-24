<?php

namespace App\Services;

use Psr\Http\Message\StreamInterface;

/**
 * A stream wrapper that limits reading to a specific number of bytes.
 * Used for HTTP Range request support where we need to return only
 * a portion of a file.
 */
class LimitedStream implements StreamInterface
{
    private $stream;
    private int $limit;
    private int $bytesRead = 0;

    public function __construct($stream, int $limit)
    {
        $this->stream = $stream;
        $this->limit = $limit;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function detach()
    {
        $stream = $this->stream;
        $this->stream = null;
        return $stream;
    }

    public function getSize(): ?int
    {
        return $this->limit;
    }

    public function tell(): int
    {
        return $this->bytesRead;
    }

    public function eof(): bool
    {
        return $this->bytesRead >= $this->limit || feof($this->stream);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \RuntimeException('LimitedStream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('LimitedStream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('LimitedStream is not writable');
    }

    public function isReadable(): bool
    {
        return is_resource($this->stream);
    }

    public function read($length): string
    {
        if ($this->eof()) {
            return '';
        }

        // Don't read more than the remaining limit
        $remaining = $this->limit - $this->bytesRead;
        $toRead = min($length, $remaining);
        
        if ($toRead <= 0) {
            return '';
        }

        $data = fread($this->stream, $toRead);
        if ($data === false) {
            return '';
        }
        
        $this->bytesRead += strlen($data);
        return $data;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }
        return $contents;
    }

    public function getMetadata($key = null)
    {
        if (!is_resource($this->stream)) {
            return $key ? null : [];
        }
        
        $meta = stream_get_meta_data($this->stream);
        return $key ? ($meta[$key] ?? null) : $meta;
    }
}
