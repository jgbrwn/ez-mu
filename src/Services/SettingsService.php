<?php

namespace App\Services;

class SettingsService
{
    private Database $db;

    private array $defaults = [
        'convert_to_flac' => '1',
        'organize_by_artist' => '1',
        'theme' => 'dark',
        'youtube_enabled' => '0',
        'youtube_cookies_path' => '',
        'autoplay_next' => '0',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $result = $this->db->queryOne('SELECT value FROM settings WHERE key = ?', [$key]);
        
        if ($result) {
            return $result['value'];
        }
        
        return $default ?? ($this->defaults[$key] ?? null);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime("now"))',
            [$key, $value]
        );
    }

    public function getAll(): array
    {
        $settings = $this->defaults;
        
        $rows = $this->db->query('SELECT key, value FROM settings');
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        
        return $settings;
    }

    public function updateAll(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, (string)$value);
        }
    }

    /**
     * Save YouTube cookies content
     * 
     * @param string $cookiesContent Cookie file content
     * @return string Path where cookies were saved
     * @throws \InvalidArgumentException If content is too large or invalid format
     */
    public function saveYouTubeCookies(string $cookiesContent): string
    {
        // Size limit: 1MB max (cookies files are typically much smaller)
        $maxSize = 1024 * 1024;
        if (strlen($cookiesContent) > $maxSize) {
            throw new \InvalidArgumentException('Cookies file too large (max 1MB)');
        }
        
        // Basic format validation - should look like Netscape cookie format
        // First non-comment line should have tab-separated fields
        $lines = explode("\n", $cookiesContent);
        $hasValidLine = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            // Netscape format has 7 tab-separated fields
            $fields = explode("\t", $line);
            if (count($fields) >= 6) {
                $hasValidLine = true;
                break;
            }
        }
        
        if (!$hasValidLine && strlen(trim($cookiesContent)) > 0) {
            throw new \InvalidArgumentException('Invalid cookie file format. Expected Netscape cookie format.');
        }
        
        $dataDir = dirname($this->db->getPdo()->query("PRAGMA database_list")->fetchColumn(2));
        $cookiesPath = $dataDir . '/youtube_cookies.txt';
        
        // Write with restrictive permissions
        file_put_contents($cookiesPath, $cookiesContent);
        chmod($cookiesPath, 0600);
        
        $this->set('youtube_cookies_path', $cookiesPath);
        
        return $cookiesPath;
    }
}
