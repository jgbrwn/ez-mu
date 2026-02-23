# EZ-MU 🎵

A self-hosted music acquisition service built with PHP, HTMX, and Slim 4.

Refactored from [MusicGrabber](https://gitlab.com/g33kphr33k/musicgrabber) - a Python/FastAPI app - into a lightweight PHP implementation.

## Features

- **Search** - YouTube and SoundCloud search via yt-dlp
- **Download** - Queue-based downloads with automatic FLAC conversion
- **Library** - Browse and manage your downloaded music
- **🎧 Play** - Stream music directly in the browser (HTML5 audio)
- **⬇️ Export** - Select and download multiple tracks as a zip file
- **Dark/Light Theme** - Toggle with saved preference
- **Mobile-friendly** - Responsive design

## Tech Stack

- **Backend**: PHP 8.x with Slim 4 microframework
- **Frontend**: HTMX + Twig templates (minimal JavaScript)
- **Database**: SQLite
- **Audio**: yt-dlp + ffmpeg
- **CSS**: Ported from MusicGrabber's modern dark theme

## Requirements

- PHP 8.1+ with extensions: sqlite3, pdo_sqlite, zip, curl, mbstring
- Composer
- yt-dlp (latest version recommended)
- ffmpeg/ffprobe

## Installation

```bash
# Clone the repository
git clone <repo-url>
cd ez-mu

# Install PHP dependencies
composer install

# Create directories
mkdir -p data music/Singles var/cache

# Start the development server
php -S 0.0.0.0:8000 -t public
```

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
