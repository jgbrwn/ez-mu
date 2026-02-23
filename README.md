# EZ-MU 🎵

A self-hosted music acquisition service built with PHP, HTMX, and Slim 4.

**🌐 Shared Hosting Compatible** - Works on standard PHP hosting without shell access or special binaries.

Refactored from [MusicGrabber](https://gitlab.com/g33kphr33k/musicgrabber) - a Python/FastAPI app - into a lightweight PHP implementation.

## Features

- **🎵 Monochrome/Tidal** - Lossless FLAC downloads from Tidal CDN (no account needed)
- **📝 Playlist Import** - Import from Spotify, Apple Music, YouTube Music, Tidal
- **🎧 Library & Playback** - Stream music directly in the browser (HTML5 audio)
- **🎯 MusicBrainz** - Automatic metadata enrichment (artist, album, year)
- **⬇️ Export** - Download multiple tracks as a zip file
- **🌙 Dark/Light Theme** - Toggle with saved preference
- **📱 Mobile-friendly** - Responsive design

### Full Features (VPS/Dedicated)

- **YouTube Search** - Via yt-dlp (requires cookies)
- **SoundCloud Search** - Via yt-dlp
- **Audio Fingerprinting** - AcoustID for precise metadata matching

## Tech Stack

- **Backend**: PHP 8.1+ with Slim 4 microframework
- **Frontend**: HTMX + Twig templates (minimal JavaScript)
- **Database**: SQLite (zero configuration)
- **Audio**: Pure PHP FLAC handling (no ffmpeg required for core features)
- **CSS**: Custom dark theme (Spotify-inspired)

## Requirements

### Minimum (Shared Hosting)

- PHP 8.1+ with extensions: `pdo_sqlite`, `curl`, `mbstring`, `zip`
- Composer (or upload vendor folder)

### Full Features (VPS)

- Everything above, plus:
- yt-dlp (for YouTube/SoundCloud)
- ffmpeg (for audio conversion)
- fpcalc (for audio fingerprinting)

## Quick Start

```bash
# Clone the repository
git clone <repo-url>
cd ez-mu

# Install PHP dependencies
composer install

# Create directories
mkdir -p data music/Singles

# Start the development server
php -S 0.0.0.0:8000 -t public
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for detailed shared hosting and production deployment instructions.

## Configuration

Settings are stored in SQLite and can be managed via the Settings page:

- **Convert to FLAC** - Auto-convert downloads to FLAC format
- **Organize by Artist** - Create artist subdirectories
- **Theme** - Dark or light mode

## Usage

### Search & Download

1. Enter a song name or artist in the search box
2. Click "Download" on any result
3. Track will be queued and processed automatically
4. Find completed downloads in the Library tab

### Library & Playback

1. Go to the Library tab
2. Click the play button (▶) on any track to stream it
3. Use the audio player controls at the bottom

### Download Selected Tracks

1. Check the boxes next to tracks you want
2. Click "Download Selected"
3. For multiple tracks, you'll get a zip file

## YouTube Authentication

YouTube may require authentication for some videos. Options:

1. **Cookies** - Export cookies from your browser and configure yt-dlp
2. **Use SoundCloud** - SoundCloud results work without authentication

See [yt-dlp wiki](https://github.com/yt-dlp/yt-dlp/wiki/FAQ#how-do-i-pass-cookies-to-yt-dlp) for cookie setup.

## Project Structure

```
ez-mu/
├── public/           # Web root
│   ├── index.php     # Front controller
│   └── static/       # CSS, images
├── src/
│   ├── Controllers/  # Route handlers
│   └── Services/     # Business logic
├── templates/        # Twig templates
├── config/           # App configuration
├── data/             # SQLite database
└── music/            # Downloaded files
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | / | Home page |
| POST | /search | Search music (HTMX) |
| POST | /download | Queue a download |
| GET | /queue | Queue page |
| GET | /library | Library page |
| GET | /stream/{id} | Stream audio file |
| POST | /library/download | Download selected tracks |
| GET | /settings | Settings page |

## Credits

- Original [MusicGrabber](https://gitlab.com/g33kphr33k/musicgrabber) by Karl
- [HTMX](https://htmx.org/) for the hypermedia approach
- [Slim Framework](https://www.slimframework.com/)
- [yt-dlp](https://github.com/yt-dlp/yt-dlp) for audio extraction

## License

MIT - Do whatever you want with it. 🤷
