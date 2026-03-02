<?php

namespace App\Services\Audio;

/**
 * Pure PHP FLAC Vorbis Comment Writer
 * 
 * FLAC files store metadata in Vorbis Comment blocks.
 * This class can read and write these blocks without external tools.
 * 
 * FLAC Format Reference: https://xiph.org/flac/format.html
 * Vorbis Comment Reference: https://www.xiph.org/vorbis/doc/v-comment.html
 */
class FlacWriter
{
    private const FLAC_MARKER = 'fLaC';
    private const BLOCK_VORBIS_COMMENT = 4;
    private const BLOCK_PADDING = 1;

    /**
     * Write Vorbis comments to a FLAC file
     * 
     * @param string $filePath Path to FLAC file
     * @param array $tags Associative array of tags (ARTIST, TITLE, ALBUM, DATE, etc.)
     * @return bool Success
     */
    public static function writeTags(string $filePath, array $tags): bool
    {
        if (!@file_exists($filePath) || !is_writable($filePath)) {
            return false;
        }

        $handle = fopen($filePath, 'r+b');
        if (!$handle) {
            return false;
        }

        try {
            // Verify FLAC marker
            $marker = fread($handle, 4);
            if ($marker !== self::FLAC_MARKER) {
                fclose($handle);
                return false;
            }

            // Parse metadata blocks to find structure
            $blocks = self::parseMetadataBlocks($handle);
            
            // Build new Vorbis comment block
            $newCommentData = self::buildVorbisCommentBlock($tags);
            
            // Find existing comment block or suitable insertion point
            $commentBlockIndex = null;
            $paddingBlockIndex = null;
            
            foreach ($blocks as $i => $block) {
                if ($block['type'] === self::BLOCK_VORBIS_COMMENT) {
                    $commentBlockIndex = $i;
                }
                if ($block['type'] === self::BLOCK_PADDING) {
                    $paddingBlockIndex = $i;
                }
            }

            // Strategy: Replace existing comment block if present
            // Otherwise, use padding block or rewrite file
            if ($commentBlockIndex !== null) {
                $result = self::replaceBlock($filePath, $blocks, $commentBlockIndex, $newCommentData);
            } else {
                // Insert after STREAMINFO (first block)
                $result = self::insertBlock($filePath, $blocks, 0, $newCommentData, self::BLOCK_VORBIS_COMMENT);
            }

            fclose($handle);
            return $result;

        } catch (\Exception $e) {
            fclose($handle);
            error_log("FlacWriter error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Read existing Vorbis comments from a FLAC file
     */
    public static function readTags(string $filePath): array
    {
        if (!@file_exists($filePath)) {
            return [];
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return [];
        }

        try {
            $marker = fread($handle, 4);
            if ($marker !== self::FLAC_MARKER) {
                fclose($handle);
                return [];
            }

            $blocks = self::parseMetadataBlocks($handle);
            
            foreach ($blocks as $block) {
                if ($block['type'] === self::BLOCK_VORBIS_COMMENT) {
                    fseek($handle, $block['data_offset']);
                    $data = fread($handle, $block['length']);
                    fclose($handle);
                    return self::parseVorbisComment($data);
                }
            }

            fclose($handle);
            return [];

        } catch (\Exception $e) {
            fclose($handle);
            return [];
        }
    }

    /**
     * Parse all metadata blocks from FLAC file
     */
    private static function parseMetadataBlocks($handle): array
    {
        $blocks = [];
        $position = 4; // After 'fLaC' marker
        fseek($handle, $position);

        while (true) {
            $header = fread($handle, 4);
            if (strlen($header) < 4) {
                break;
            }

            $byte0 = ord($header[0]);
            $isLast = ($byte0 & 0x80) !== 0;
            $blockType = $byte0 & 0x7F;
            $length = (ord($header[1]) << 16) | (ord($header[2]) << 8) | ord($header[3]);

            $blocks[] = [
                'type' => $blockType,
                'is_last' => $isLast,
                'length' => $length,
                'header_offset' => $position,
                'data_offset' => $position + 4,
            ];

            $position += 4 + $length;
            fseek($handle, $position);

            if ($isLast) {
                break;
            }
        }

        return $blocks;
    }

    /**
     * Build a Vorbis comment block from tags array
     */
    private static function buildVorbisCommentBlock(array $tags): string
    {
        $vendor = 'EZ-MU/1.1.0';
        
        // Vendor string (length + string)
        $data = pack('V', strlen($vendor)) . $vendor;
        
        // Filter empty tags and build comments
        $comments = [];
        foreach ($tags as $key => $value) {
            if (!empty($value)) {
                $key = strtoupper(trim($key));
                $value = trim($value);
                $comments[] = "{$key}={$value}";
            }
        }
        
        // Comment count
        $data .= pack('V', count($comments));
        
        // Each comment (length + string)
        foreach ($comments as $comment) {
            $data .= pack('V', strlen($comment)) . $comment;
        }
        
        return $data;
    }

    /**
     * Parse Vorbis comment data into array
     */
    private static function parseVorbisComment(string $data): array
    {
        $tags = [];
        $offset = 0;
        
        // Vendor string length
        $vendorLength = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4 + $vendorLength;
        
        // Comment count
        $commentCount = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        
        // Read each comment
        for ($i = 0; $i < $commentCount; $i++) {
            $length = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;
            $comment = substr($data, $offset, $length);
            $offset += $length;
            
            $parts = explode('=', $comment, 2);
            if (count($parts) === 2) {
                $tags[strtoupper($parts[0])] = $parts[1];
            }
        }
        
        return $tags;
    }

    /**
     * Replace an existing metadata block
     */
    private static function replaceBlock(string $filePath, array $blocks, int $blockIndex, string $newData): bool
    {
        $block = $blocks[$blockIndex];
        $newLength = strlen($newData);
        $oldLength = $block['length'];
        
        // If same size, we can write in place
        if ($newLength === $oldLength) {
            $handle = fopen($filePath, 'r+b');
            fseek($handle, $block['data_offset']);
            fwrite($handle, $newData);
            fclose($handle);
            return true;
        }
        
        // Different size - need to rewrite file
        return self::rewriteFile($filePath, $blocks, $blockIndex, $newData, $block['type']);
    }

    /**
     * Insert a new metadata block after specified index
     */
    private static function insertBlock(string $filePath, array $blocks, int $afterIndex, string $newData, int $blockType): bool
    {
        return self::rewriteFile($filePath, $blocks, -1, $newData, $blockType, $afterIndex);
    }

    /**
     * Rewrite the entire file with modified metadata blocks
     */
    private static function rewriteFile(
        string $filePath, 
        array $blocks, 
        int $replaceIndex, 
        string $newData, 
        int $newBlockType,
        int $insertAfterIndex = -1
    ): bool {
        $tempFile = $filePath . '.tmp';
        
        $sourceHandle = fopen($filePath, 'rb');
        $destHandle = fopen($tempFile, 'wb');
        
        if (!$sourceHandle || !$destHandle) {
            return false;
        }

        try {
            // Write FLAC marker
            fwrite($destHandle, self::FLAC_MARKER);
            fseek($sourceHandle, 4);

            $lastBlockWritten = false;
            $totalBlocks = count($blocks);
            $needToInsert = $insertAfterIndex >= 0;
            
            for ($i = 0; $i < $totalBlocks; $i++) {
                $block = $blocks[$i];
                $isOriginalLast = $block['is_last'];
                
                // Skip block if we're replacing it
                if ($i === $replaceIndex) {
                    // Write new block instead
                    $isLast = $isOriginalLast && !$needToInsert;
                    self::writeBlockHeader($destHandle, $newBlockType, strlen($newData), $isLast);
                    fwrite($destHandle, $newData);
                    
                    // Skip original block data
                    fseek($sourceHandle, $block['data_offset'] + $block['length']);
                    continue;
                }
                
                // Determine if this block should be marked as last
                $willInsertAfterThis = $needToInsert && $i === $insertAfterIndex;
                $isLast = $isOriginalLast && !$willInsertAfterThis && $replaceIndex < 0;
                
                // Write block header
                self::writeBlockHeader($destHandle, $block['type'], $block['length'], $isLast);
                
                // Copy block data
                fseek($sourceHandle, $block['data_offset']);
                $data = fread($sourceHandle, $block['length']);
                fwrite($destHandle, $data);
                
                // Insert new block after this one if needed
                if ($willInsertAfterThis) {
                    $isLast = $isOriginalLast;
                    self::writeBlockHeader($destHandle, $newBlockType, strlen($newData), $isLast);
                    fwrite($destHandle, $newData);
                    $needToInsert = false;
                }
            }

            // Copy audio data (everything after metadata)
            $lastBlock = $blocks[$totalBlocks - 1];
            $audioStart = $lastBlock['data_offset'] + $lastBlock['length'];
            fseek($sourceHandle, $audioStart);
            
            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, 8192);
                if ($chunk !== false && strlen($chunk) > 0) {
                    fwrite($destHandle, $chunk);
                }
            }

            fclose($sourceHandle);
            fclose($destHandle);

            // Replace original with temp file
            unlink($filePath);
            rename($tempFile, $filePath);
            
            return true;

        } catch (\Exception $e) {
            fclose($sourceHandle);
            fclose($destHandle);
            if (@file_exists($tempFile)) {
                unlink($tempFile);
            }
            return false;
        }
    }

    /**
     * Write a metadata block header
     */
    private static function writeBlockHeader($handle, int $type, int $length, bool $isLast): void
    {
        $byte0 = $type | ($isLast ? 0x80 : 0x00);
        fwrite($handle, chr($byte0));
        fwrite($handle, chr(($length >> 16) & 0xFF));
        fwrite($handle, chr(($length >> 8) & 0xFF));
        fwrite($handle, chr($length & 0xFF));
    }
}
