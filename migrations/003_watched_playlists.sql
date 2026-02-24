-- Watched playlists table
CREATE TABLE IF NOT EXISTS watched_playlists (
    id TEXT PRIMARY KEY,
    url TEXT NOT NULL,
    name TEXT NOT NULL,
    platform TEXT NOT NULL,
    sync_mode TEXT DEFAULT 'append',
    make_m3u INTEGER DEFAULT 1,
    enabled INTEGER DEFAULT 1,
    refresh_interval_hours INTEGER DEFAULT 24,
    last_checked TEXT,
    last_track_count INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now'))
);

-- Tracks belonging to watched playlists
CREATE TABLE IF NOT EXISTS watched_playlist_tracks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    playlist_id TEXT NOT NULL,
    track_hash TEXT NOT NULL,
    artist TEXT,
    title TEXT,
    video_id TEXT,
    job_id TEXT,
    status TEXT DEFAULT 'pending',
    added_at TEXT DEFAULT (datetime('now')),
    downloaded_at TEXT,
    removed_at TEXT,
    FOREIGN KEY (playlist_id) REFERENCES watched_playlists(id) ON DELETE CASCADE,
    UNIQUE(playlist_id, track_hash)
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_watched_tracks_playlist ON watched_playlist_tracks(playlist_id);
CREATE INDEX IF NOT EXISTS idx_watched_tracks_status ON watched_playlist_tracks(status);
CREATE INDEX IF NOT EXISTS idx_watched_tracks_hash ON watched_playlist_tracks(track_hash);
