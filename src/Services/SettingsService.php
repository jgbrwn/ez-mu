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
     */
    public function saveYouTubeCookies(string $cookiesContent): string
    {
        $dataDir = dirname($this->db->getPdo()->query("PRAGMA database_list")->fetchColumn(2));
        $cookiesPath = $dataDir . '/youtube_cookies.txt';
        
        file_put_contents($cookiesPath, $cookiesContent);
        $this->set('youtube_cookies_path', $cookiesPath);
        
        return $cookiesPath;
    }
}
