<?php

namespace App\Services;

use Exception;

/**
 * MetadataService - AcoustID fingerprinting + MusicBrainz lookups
 * 
 * Similar to MusicGrabber's metadata.py:
 * 1. Fingerprint audio with fpcalc → query AcoustID
 * 2. If match found, enrich with MusicBrainz recording data
 * 3. Fall back to text-based MusicBrainz search
 */
class MetadataService
{
    private const ACOUSTID_API_KEY = '0NILMQojj4';  // MusicGrabber's API key
    private const ACOUSTID_MIN_SCORE = 0.6;
    private const MB_CONFIDENCE_THRESHOLD = 85;
    private const USER_AGENT = 'EZ-MU/1.1.0 (https://github.com/ez-mu)';
    private const TIMEOUT = 10;

    private Database $db;
    private bool $enabled = true;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->enabled = $this->getSetting('enable_musicbrainz', true);
    }

    /**
     * Look up metadata for a track, trying fingerprinting first
     */
    public function lookupMetadata(string $artist, string $title, ?string $filePath = null): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        // Step 1: Try AcoustID fingerprinting if we have a file
        if ($filePath && file_exists($filePath)) {
            $fpResult = $this->runFpcalc($filePath);
            if ($fpResult) {
                [$duration, $fingerprint] = $fpResult;
                $acoustidMeta = $this->lookupAcoustid($duration, $fingerprint, $artist, $title);
                
                if ($acoustidMeta) {
                    $acoustidMeta['metadata_source'] = 'acoustid_fingerprint';
                    
                    // Step 2: Fill in release date from MusicBrainz if we have recording ID
                    $recordingId = $acoustidMeta['recording_id'] ?? null;
                    if ($recordingId && empty($acoustidMeta['year'])) {
                        $mbExtra = $this->lookupMusicBrainzById($recordingId);
                        if ($mbExtra) {
                            if (!empty($mbExtra['year'])) {
                                $acoustidMeta['year'] = $mbExtra['year'];
                            }
                            if (!empty($mbExtra['album']) && empty($acoustidMeta['album'])) {
                                $acoustidMeta['album'] = $mbExtra['album'];
                            }
                        }
                    }
                    
                    return $acoustidMeta;
                }
            }
        }

        // Step 3: Fall back to text-based MusicBrainz search
        return $this->lookupMusicBrainzText($artist, $title);
    }

    /**
     * Run fpcalc on an audio file and return [duration, fingerprint]
     */
    private function runFpcalc(string $filePath): ?array
    {
        $fpcalc = $this->findFpcalc();
        if (!$fpcalc) {
            error_log('fpcalc not found, skipping fingerprinting');
            return null;
        }

        $cmd = [$fpcalc, '-json', $filePath];
        
        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return null;
        }

        $data = json_decode($output, true);
        if (!$data) {
            return null;
        }

        $duration = (int)($data['duration'] ?? 0);
        $fingerprint = $data['fingerprint'] ?? '';

        if (empty($fingerprint) || $duration < 1) {
            return null;
        }

        return [$duration, $fingerprint];
    }

    private function findFpcalc(): ?string
    {
        foreach (['/usr/bin/fpcalc', '/usr/local/bin/fpcalc', 'fpcalc'] as $path) {
            if (file_exists($path) || shell_exec("which {$path} 2>/dev/null")) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Query AcoustID with a fingerprint
     */
    private function lookupAcoustid(int $duration, string $fingerprint, string $expectedArtist, string $expectedTitle): ?array
    {
        $url = 'https://api.acoustid.org/v2/lookup';
        $params = [
            'client' => self::ACOUSTID_API_KEY,
            'duration' => $duration,
            'fingerprint' => $fingerprint,
            'meta' => 'recordings releasegroups',
        ];

        $response = $this->httpGet($url . '?' . http_build_query($params));
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['results'])) {
            return null;
        }

        // Collect all recordings from results with a good fingerprint score
        $allRecordings = [];
        foreach ($data['results'] as $result) {
            $fpScore = $result['score'] ?? 0;
            if ($fpScore < self::ACOUSTID_MIN_SCORE) {
                continue;
            }
            foreach ($result['recordings'] ?? [] as $rec) {
                if (!empty($rec['title'])) {
                    $allRecordings[] = [$fpScore, $rec];
                }
            }
        }

        if (empty($allRecordings)) {
            $bestScore = $data['results'][0]['score'] ?? 0;
            error_log("AcoustID: no usable recordings (best fingerprint score {$bestScore})");
            return null;
        }

        // Pick the recording that best matches what we expect
        usort($allRecordings, function($a, $b) use ($expectedArtist, $expectedTitle) {
            $scoreA = $this->scoreRecording($a[1], $expectedArtist, $expectedTitle);
            $scoreB = $this->scoreRecording($b[1], $expectedArtist, $expectedTitle);
            return $scoreB <=> $scoreA;
        });

        [$fpScore, $recording] = $allRecordings[0];
        $matchScore = $this->scoreRecording($recording, $expectedArtist, $expectedTitle);

        // Require some positive signal
        if ($matchScore < 0) {
            error_log("AcoustID: best recording match score {$matchScore} is too low, skipping");
            return null;
        }

        $metadata = $this->extractRecordingMetadata($recording);
        error_log(sprintf("AcoustID match (fp %.2f, match %d): %s - %s",
            $fpScore, $matchScore, $metadata['artist'] ?? '?', $metadata['title'] ?? '?'));

        return $metadata;
    }

    /**
     * Score how well a recording matches expected artist/title
     */
    private function scoreRecording(array $recording, string $expectedArtist, string $expectedTitle): int
    {
        $score = 0;
        $artistNames = array_map('strtolower', array_column($recording['artists'] ?? [], 'name'));
        $recTitle = strtolower($recording['title'] ?? '');
        $expArtist = strtolower($expectedArtist);
        $expTitle = strtolower($expectedTitle);

        // Artist match is the strongest signal
        foreach ($artistNames as $name) {
            if (strpos($expArtist, $name) !== false || strpos($name, $expArtist) !== false) {
                $score += 10;
                break;
            }
        }

        // Title match
        if ($expTitle === $recTitle) {
            $score += 8;
        } elseif (strpos($expTitle, $recTitle) !== false || strpos($recTitle, $expTitle) !== false) {
            $score += 5;
        }

        // Penalise covers, remixes, karaoke
        if (preg_match('/cover|karaoke|tribute/i', $recTitle)) {
            $score -= 8;
        }

        // Penalise remastered/live versions
        if (preg_match('/remaster|live|session/i', $recTitle)) {
            $score -= 2;
        }

        // Bonus for having release groups (well-catalogued)
        if (!empty($recording['releasegroups'])) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Extract metadata from an AcoustID recording
     */
    private function extractRecordingMetadata(array $recording): array
    {
        $metadata = [
            'title' => $recording['title'] ?? null,
            'artist' => null,
            'album' => null,
            'year' => null,
            'recording_id' => $recording['id'] ?? null,
        ];

        $artists = $recording['artists'] ?? [];
        if ($artists) {
            $names = array_filter(array_column($artists, 'name'));
            $metadata['artist'] = implode(' & ', $names);
        }

        // Extract album from release groups - prefer albums over singles/compilations
        $releaseGroups = $recording['releasegroups'] ?? [];
        if ($releaseGroups) {
            $albumRg = null;
            foreach ($releaseGroups as $rg) {
                if (($rg['type'] ?? '') === 'Album') {
                    $albumRg = $rg;
                    break;
                }
            }
            $albumRg = $albumRg ?? $releaseGroups[0];
            $metadata['album'] = $albumRg['title'] ?? null;
        }

        return $metadata;
    }

    /**
     * Look up release date from MusicBrainz by recording ID
     */
    private function lookupMusicBrainzById(string $recordingId): ?array
    {
        $url = "https://musicbrainz.org/ws/2/recording/{$recordingId}";
        $params = ['inc' => 'releases', 'fmt' => 'json'];

        // MusicBrainz rate limit: 1 request per second
        usleep(1100000);

        $response = $this->httpGet($url . '?' . http_build_query($params));
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        $releases = $data['releases'] ?? [];
        if (empty($releases)) {
            return null;
        }

        $release = $releases[0];
        $result = [];

        $dateStr = $release['date'] ?? '';
        if ($dateStr && preg_match('/^(\d{4})/', $dateStr, $m)) {
            $result['year'] = $m[1];
        }

        if (!empty($release['title'])) {
            $result['album'] = $release['title'];
        }

        return $result ?: null;
    }

    /**
     * Text-based MusicBrainz search (fallback)
     */
    private function lookupMusicBrainzText(string $artist, string $title): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $url = 'https://musicbrainz.org/ws/2/recording/';
        $params = [
            'query' => 'artist:"' . addslashes($artist) . '" AND recording:"' . addslashes($title) . '"',
            'fmt' => 'json',
            'limit' => 1,
        ];

        // MusicBrainz rate limit: 1 request per second
        usleep(1100000);

        $response = $this->httpGet($url . '?' . http_build_query($params));
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['recordings'])) {
            return null;
        }

        $recording = $data['recordings'][0];
        $mbScore = (int)($recording['score'] ?? 0);

        if ($mbScore < self::MB_CONFIDENCE_THRESHOLD) {
            error_log("MusicBrainz text search score too low ({$mbScore}) for {$artist} - {$title}");
            return null;
        }

        $metadata = [
            'title' => $recording['title'] ?? null,
            'artist' => null,
            'metadata_source' => 'musicbrainz_text',
        ];

        // Artist
        $artistCredit = $recording['artist-credit'] ?? [];
        if ($artistCredit) {
            $metadata['artist'] = $artistCredit[0]['name'] ?? null;
        }

        // Album and date from releases
        $releases = $recording['releases'] ?? [];
        if ($releases) {
            $release = $releases[0];
            $metadata['album'] = $release['title'] ?? null;
            $metadata['date'] = $release['date'] ?? null;

            if (!empty($metadata['date']) && preg_match('/^(\d{4})/', $metadata['date'], $m)) {
                $metadata['year'] = $m[1];
            }
        }

        return $metadata;
    }

    /**
     * Apply metadata tags to a FLAC file
     */
    public function applyMetadataToFile(string $filePath, string $artist, string $title, ?string $album = null, ?string $year = null): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Use metaflac for FLAC files (most reliable)
        if ($ext === 'flac') {
            return $this->applyMetaflac($filePath, $artist, $title, $album, $year);
        }

        // Use ffmpeg for other formats
        return $this->applyFfmpegMetadata($filePath, $artist, $title, $album, $year);
    }

    /**
     * Apply metadata using metaflac
     */
    private function applyMetaflac(string $filePath, string $artist, string $title, ?string $album, ?string $year): bool
    {
        $metaflac = shell_exec('which metaflac 2>/dev/null');
        if (!$metaflac) {
            error_log('metaflac not found, skipping tag update');
            return false;
        }

        // Remove existing tags and add new ones
        $cmd = [
            'metaflac',
            '--remove-tag=ARTIST',
            '--remove-tag=TITLE',
            '--remove-tag=ALBUM',
            '--remove-tag=DATE',
            '--remove-tag=COMMENT',
            '--set-tag=ARTIST=' . $artist,
            '--set-tag=TITLE=' . $title,
        ];

        if ($album) {
            $cmd[] = '--set-tag=ALBUM=' . $album;
        }
        if ($year) {
            $cmd[] = '--set-tag=DATE=' . $year;
        }
        $cmd[] = $filePath;

        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return $exitCode === 0;
    }

    /**
     * Apply metadata using ffmpeg (for non-FLAC)
     */
    private function applyFfmpegMetadata(string $filePath, string $artist, string $title, ?string $album, ?string $year): bool
    {
        // ffmpeg requires output to different file, then move
        $tempPath = $filePath . '.temp.' . pathinfo($filePath, PATHINFO_EXTENSION);

        $cmd = [
            'ffmpeg',
            '-y',
            '-i', $filePath,
            '-c', 'copy',
            '-metadata', 'artist=' . $artist,
            '-metadata', 'title=' . $title,
        ];

        if ($album) {
            $cmd[] = '-metadata';
            $cmd[] = 'album=' . $album;
        }
        if ($year) {
            $cmd[] = '-metadata';
            $cmd[] = 'date=' . $year;
        }
        $cmd[] = $tempPath;

        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0 && file_exists($tempPath)) {
            rename($tempPath, $filePath);
            return true;
        }

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        return false;
    }

    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: ' . self::USER_AGENT,
                'timeout' => self::TIMEOUT,
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        return $response ?: null;
    }

    private function getSetting(string $key, $default = null)
    {
        $row = $this->db->queryOne('SELECT value FROM settings WHERE key = ?', [$key]);
        if ($row) {
            $val = $row['value'];
            if ($val === 'true' || $val === '1') return true;
            if ($val === 'false' || $val === '0') return false;
            return $val;
        }
        return $default;
    }
}
