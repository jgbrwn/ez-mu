<?php

namespace App\Services;

use PDO;
use PDOException;

class Database
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
        $this->connect();
        $this->initSchema();
    }

    private function connect(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
    }

    private function initSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id TEXT PRIMARY KEY,
                video_id TEXT,
                source TEXT DEFAULT 'youtube',
                title TEXT,
                artist TEXT,
                url TEXT,
                thumbnail TEXT,
                status TEXT DEFAULT 'queued',
                error TEXT,
                file_path TEXT,
                codec TEXT,
                bitrate INTEGER,
                duration INTEGER,
                download_type TEXT DEFAULT 'single',
                convert_to_flac INTEGER DEFAULT 1,
                metadata_source TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                started_at TEXT,
                completed_at TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS library (
                id TEXT PRIMARY KEY,
                job_id TEXT,
                title TEXT NOT NULL,
                artist TEXT,
                album TEXT DEFAULT 'Singles',
                file_path TEXT NOT NULL,
                file_size INTEGER,
                duration INTEGER,
                codec TEXT,
                bitrate INTEGER,
                thumbnail TEXT,
                source TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                FOREIGN KEY (job_id) REFERENCES jobs(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TEXT DEFAULT (datetime('now'))
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS search_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query TEXT,
                source TEXT,
                results_count INTEGER,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");

        // Create indexes
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_created ON jobs(created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_artist ON library(artist)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_title ON library(title)');
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }
}
