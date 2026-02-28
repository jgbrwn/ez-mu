# EZ-MU Deployment Guide

This guide covers deploying EZ-MU to shared hosting (DirectAdmin, cPanel, etc.) and VPS/dedicated servers.

## Table of Contents

1. [Requirements](#requirements)
2. [Shared Hosting Deployment](#shared-hosting-deployment)
3. [VPS/Dedicated Server Deployment](#vpsdedicated-server-deployment)
   - [Traditional (Nginx/Apache + PHP-FPM)](#install-system-dependencies)
   - [Caddy + PHP-FPM](#caddy-configuration)
   - [FrankenPHP (Recommended)](#frankenphp-deployment)
4. [Password Protection](#password-protection)
5. [Configuration](#configuration)
6. [Troubleshooting](#troubleshooting)

---

## Requirements

### Minimum (Shared Hosting - Monochrome Only)

- PHP 8.1+ with extensions:
  - `pdo_sqlite`
  - `curl`
  - `mbstring`
  - `zip`
- Composer (for dependency installation)
- Write access to `data/` and `music/` directories

### Full Features (VPS/Dedicated)

All of the above, plus:
- `yt-dlp` - For YouTube and SoundCloud support
- `ffmpeg` - For audio conversion
- `fpcalc` (chromaprint) - For audio fingerprinting (optional)

---

## Shared Hosting Deployment

### What Works on Shared Hosting

EZ-MU **automatically detects** available capabilities and adjusts features accordingly.
No configuration needed - unavailable features are gracefully hidden from the UI.

✅ **Monochrome/Tidal Downloads** - Lossless FLAC from Tidal CDN  
✅ **Playlist Import** - Spotify, Apple Music, Tidal playlists  
✅ **MusicBrainz Lookups** - Text-based metadata enrichment  
✅ **Library Management** - Browse, play, download tracks  
✅ **FLAC Tagging** - Pure PHP implementation (no external tools)

❌ **YouTube Downloads** - Requires yt-dlp (auto-disabled if not found)  
❌ **SoundCloud Downloads** - Requires yt-dlp (auto-disabled if not found)  
❌ **Audio Fingerprinting** - Requires fpcalc (falls back to text search)  

### Step-by-Step Instructions

#### 1. Upload Files

Download or clone the repository to your local machine:

```bash
git clone https://github.com/jgbrwn/ez-mu.git
cd ez-mu
composer install --no-dev --optimize-autoloader
```

Upload the following to your hosting:

```
ez-mu/
├── config/
├── data/           (create if missing)
├── music/          (create if missing)
├── public/         → Point domain here
│   ├── index.php
│   ├── static/
│   └── .htaccess
├── src/
├── templates/
├── vendor/
└── .env            (copy from .env.example)
```

> **Note:** The `ACOUSTID_API_KEY` setting in `.env` is optional on shared hosting
> unless you can install a static `fpcalc` binary. For public-facing deployments,
> set `APP_USER`/`APP_PASSWORD` to enable authentication. Ensure `.env` is placed
> in the project root (outside `public/`) so it's not web-accessible.

#### 2. Configure Document Root

In DirectAdmin or cPanel, set your domain's document root to the `public/` directory:

- **DirectAdmin**: Domain Setup → Select domain → Document Root → `/domains/yourdomain.com/public_html/ez-mu/public`
- **cPanel**: Domains → Document Root → `/home/username/public_html/ez-mu/public`

#### 3. Set Up .htaccess

```bash
cd public/
cp .htaccess.example .htaccess
```

Edit `.htaccess` to configure URL rewriting (usually works by default).

#### 4. Create Required Directories

```bash
mkdir -p data music/Singles
chmod 755 data music music/Singles
```

#### 5. Set File Permissions

```bash
# Make directories writable
chmod 755 data/
chmod 755 music/
chmod -R 755 music/Singles/

# Protect sensitive files
chmod 644 config/*.php
chmod 644 .htaccess
```

#### 6. Test the Installation

Visit your domain. You should see the EZ-MU interface.

- Search for music → Should show Monochrome/Tidal results
- Download a track → Should save FLAC to library
- Check Library → Should show downloaded tracks with playback

---

## Password Protection

EZ-MU offers two authentication options:

### Method 1: Built-in Application Login (Recommended)

EZ-MU includes a built-in session-based login system. To enable:

1. Edit your `.env` file:
   ```env
   APP_USER=admin
   APP_PASSWORD=your-secure-password
   ```

2. That's it! Users will see a login page when accessing the app.

**Features:**
- Session-based authentication (uses standard PHP sessions)
- CSRF protection on all forms
- Rate limiting on login attempts (5 per minute per IP)
- Logout button in header
- Works with HTMX requests (redirects properly)
- **Shared hosting compatible** - no special extensions or configuration needed

### Method 2: .htaccess Basic Auth

For additional security or if you prefer web server authentication:

#### 1. Create Password File

SSH into your hosting and run:

```bash
# Navigate to your app root (NOT public/)
cd /home/username/ez-mu

# Create password file with first user
htpasswd -c .htpasswd yourusername
# Enter password when prompted

# Add additional users (without -c flag)
htpasswd .htpasswd anotheruser
```

#### 2. Configure .htaccess

Edit `public/.htaccess` and uncomment the authentication section:

```apache
AuthType Basic
AuthName "EZ-MU - Login Required"
AuthUserFile /home/username/ez-mu/.htpasswd
Require valid-user
```

**Important**: Update `/home/username/ez-mu/.htpasswd` to your actual path!

#### 3. Test Authentication

Visit your site - you should see a login prompt.

### Method 3: DirectAdmin Password Protected Directory

1. Log into DirectAdmin
2. Go to **Advanced Features** → **Password Protected Directories**
3. Select the `public/` directory
4. Add users with passwords
5. Enable protection

> **Tip:** You can combine Method 1 (built-in) with Method 2 or 3 for defense in depth.

---

## VPS/Dedicated Server Deployment

For full functionality including YouTube and SoundCloud.

> **💡 Using FrankenPHP?** Skip the PHP installation below and see the
> [FrankenPHP Deployment](#frankenphp-deployment) section instead.

### Install System Dependencies

#### Debian/Ubuntu (Traditional PHP-FPM)

```bash
# PHP and extensions
sudo apt update
sudo apt install php8.2-fpm php8.2-sqlite3 php8.2-curl php8.2-mbstring php8.2-zip

# Optional: Full features
sudo apt install ffmpeg flac

# yt-dlp (latest version)
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp

# Chromaprint for audio fingerprinting (optional)
sudo apt install libchromaprint-tools
```

### Deploy Application

```bash
cd /var/www
git clone https://github.com/jgbrwn/ez-mu.git
cd ez-mu
composer install --no-dev --optimize-autoloader
mkdir -p data music/Singles
chown -R www-data:www-data data/ music/
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/ez-mu/public;
    index index.php;

    # Basic auth (optional)
    # auth_basic "EZ-MU";
    # auth_basic_user_file /var/www/ez-mu/.htpasswd;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(ht|git) {
        deny all;
    }

    location /music {
        internal;
        alias /var/www/ez-mu/music;
    }
}
```

### Apache Virtual Host

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/ez-mu/public
    
    <Directory /var/www/ez-mu/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Basic auth (optional)
    # <Location />
    #     AuthType Basic
    #     AuthName "EZ-MU"
    #     AuthUserFile /var/www/ez-mu/.htpasswd
    #     Require valid-user
    # </Location>
</VirtualHost>
```

### Caddy Configuration

Caddy provides automatic HTTPS and simple configuration:

```caddyfile
yourdomain.com {
    root * /var/www/ez-mu/public
    php_fastcgi unix//var/run/php/php8.2-fpm.sock
    file_server
    
    # Route all requests through index.php (Slim framework)
    try_files {path} {path}/ /index.php?{query}
    
    # Block access to sensitive files
    @blocked {
        path /.* /composer.* /vendor/*
    }
    respond @blocked 403
    
    # Basic auth (optional)
    # basicauth /* {
    #     # Generate hash: caddy hash-password
    #     admin $2a$14$HASH_FROM_CADDY_HASH_PASSWORD
    # }
}
```

**To enable basic auth:**

1. Generate a password hash:
   ```bash
   caddy hash-password
   # Enter your password when prompted
   ```

2. Uncomment the `basicauth` block and replace the hash:
   ```caddyfile
   basicauth /* {
       yourusername $2a$14$yourGeneratedHashHere
   }
   ```

3. Reload Caddy:
   ```bash
   sudo systemctl reload caddy
   ```

> **💡 Want an all-in-one solution?** See [FrankenPHP Deployment](#frankenphp-deployment)
> for Caddy + PHP in a single binary with automatic HTTPS.

---

### FrankenPHP Deployment

[FrankenPHP](https://frankenphp.dev) combines Caddy and PHP into a single binary with automatic
HTTPS, HTTP/2, and HTTP/3 support. No separate PHP-FPM installation needed.

#### Option 1: Docker (Recommended)

Create a `Dockerfile` in your project:

```dockerfile
FROM dunglas/frankenphp

# Install required PHP extensions
RUN install-php-extensions \
    pdo_sqlite \
    curl \
    mbstring \
    zip \
    opcache

# Install optional tools for full functionality
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg \
    flac \
    libchromaprint-tools \
    && rm -rf /var/lib/apt/lists/*

# Install yt-dlp for YouTube/SoundCloud support
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp && chmod +x /usr/local/bin/yt-dlp

# Copy application
COPY . /app

# Default to port 80 (override with SERVER_NAME env var for production)
# For automatic HTTPS, set SERVER_NAME=yourdomain.com
ENV SERVER_NAME=:80

# Create required directories
RUN mkdir -p /app/data /app/music/Singles && \
    chown -R www-data:www-data /app/data /app/music

WORKDIR /app
```

Create a `Caddyfile` in your project root:

```caddyfile
{
    # Global options
    frankenphp
    order php_server before file_server
}

# Uses SERVER_NAME env var - defaults to :80 for local dev
# Set SERVER_NAME=yourdomain.com for automatic HTTPS in production
{$SERVER_NAME:localhost} {
    root * /app/public
    
    # Encode responses
    encode zstd br gzip
    
    # Block sensitive files
    @blocked {
        path /.env /.git/* /composer.* /vendor/*
    }
    respond @blocked 403
    
    # Basic auth (optional - uncomment to enable)
    # basicauth /* {
    #     admin $2a$14$YOUR_HASH_HERE
    # }
    
    # Serve PHP
    php_server
}
```

> **Note:** When `SERVER_NAME` is set to a domain (e.g., `yourdomain.com`), Caddy automatically:
> - Provisions Let's Encrypt certificates
> - Redirects HTTP → HTTPS  
> - Enables HTTP/2 and HTTP/3
>
> For local development, leave `SERVER_NAME=:80` or `SERVER_NAME=localhost`.

Create `compose.yaml`:

```yaml
services:
  ezmu:
    build: .
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"  # HTTP/3
    volumes:
      - ./data:/app/data
      - ./music:/app/music
      - ./Caddyfile:/etc/caddy/Caddyfile  # Custom Caddyfile
      - caddy_data:/data
      - caddy_config:/config
    environment:
      # For production: set to your domain for automatic HTTPS
      # For local dev: use :80 or localhost
      - SERVER_NAME=${SERVER_NAME:-localhost}
    restart: unless-stopped

volumes:
  caddy_data:
  caddy_config:
```

Deploy:

```bash
git clone https://github.com/jgbrwn/ez-mu.git
cd ez-mu
composer install --no-dev --optimize-autoloader

# Local development
docker compose up -d

# Production (with your domain for automatic HTTPS)
SERVER_NAME=yourdomain.com docker compose up -d
```

#### Option 2: Standalone Binary

Download FrankenPHP:

```bash
# Download latest FrankenPHP
curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 -o /usr/local/bin/frankenphp
chmod +x /usr/local/bin/frankenphp
```

> **Note:** The standalone binary includes common extensions. Verify required extensions:
> ```bash
> frankenphp php-cli -m | grep -E "pdo_sqlite|curl|mbstring|zip"
> ```
> If any are missing, use the Docker method or build a custom binary.

Deploy the application:

```bash
cd /var/www
git clone https://github.com/jgbrwn/ez-mu.git
cd ez-mu
composer install --no-dev --optimize-autoloader
mkdir -p data music/Singles
```

Create `/var/www/ez-mu/Caddyfile`:

```caddyfile
{
    frankenphp
    order php_server before file_server
}

yourdomain.com {
    root * /var/www/ez-mu/public
    
    encode zstd br gzip
    
    @blocked {
        path /.env /.git/* /composer.* /vendor/*
    }
    respond @blocked 403
    
    # Basic auth (optional)
    # Generate hash: frankenphp hash-password
    # basicauth /* {
    #     admin $2a$14$YOUR_HASH_HERE
    # }
    
    php_server
}
```

Create systemd service `/etc/systemd/system/ezmu.service`:

```ini
[Unit]
Description=EZ-MU (FrankenPHP)
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/ez-mu
ExecStart=/usr/local/bin/frankenphp run --config /var/www/ez-mu/Caddyfile
Restart=always
RestartSec=5

# Environment for optional tools
Environment="PATH=/usr/local/bin:/usr/bin:/bin"

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable ezmu
sudo systemctl start ezmu
```

#### Installing Optional Tools (FrankenPHP)

YouTube/SoundCloud and fingerprinting tools are installed separately:

```bash
# yt-dlp for YouTube/SoundCloud
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp

# ffmpeg and flac for audio conversion
sudo apt install ffmpeg flac

# Chromaprint for audio fingerprinting (optional)
sudo apt install libchromaprint-tools
```

---

## Configuration

### Environment Variables

EZ-MU uses a `.env` file for configuration. Copy the example file:

```bash
cp .env.example .env
```

Available settings:

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_USER` | Username for built-in login | (none - login disabled) |
| `APP_PASSWORD` | Password for built-in login | (none - login disabled) |
| `APP_DEBUG` | Show detailed error messages | `false` |
| `CRON_SECRET` | Secret key for `/cron/process` endpoint | (none - endpoint disabled) |
| `ACOUSTID_API_KEY` | API key for audio fingerprinting | (none) |

**Security Variables:**

- `APP_USER` + `APP_PASSWORD`: Both must be set to enable login
- `APP_DEBUG`: **Always** set to `false` in production
- `CRON_SECRET`: **Required** if using the cron endpoint

Get an AcoustID API key at: https://acoustid.org/api-key

> **Shared Hosting Note:** The `ACOUSTID_API_KEY` setting is optional on shared
> hosting unless you're able to install a static `fpcalc` binary. Text-based
> MusicBrainz lookups work without it. For public-facing deployments, setting
> `APP_USER`/`APP_PASSWORD` is strongly recommended.

You can also customize binary paths via environment variables:

```bash
export YT_DLP_PATH=/usr/local/bin/yt-dlp
export FFMPEG_PATH=/usr/bin/ffmpeg
export FPCALC_PATH=/usr/bin/fpcalc
```

### Application Settings

Settings are stored in SQLite and managed via the web interface:

- **Organize by Artist** - Create artist subdirectories  
- **Convert to FLAC** - Convert YouTube/SoundCloud downloads to FLAC (only shown when yt-dlp available)
- **MusicBrainz Lookup** - Enrich metadata from MusicBrainz
- **YouTube** - Enable/disable YouTube search (requires yt-dlp)

### Database Location

The SQLite database is stored at `data/ez-mu.db`. To backup:

```bash
cp data/ez-mu.db data/ez-mu.db.backup
```

### Automatic Capability Detection

EZ-MU automatically detects available tools and adjusts features accordingly.
No manual configuration needed - it just works with whatever is available.

**How it works:**

1. On startup, the `Environment` service scans for available binaries
2. Features requiring unavailable tools are gracefully disabled
3. Pure PHP fallbacks are used where possible (e.g., FLAC metadata writing)

**Check current status:**

Go to **Settings → System Information** to see:
- Current mode ("Full Features" or "Shared Hosting (Limited)")
- Available search sources
- Feature availability matrix
- Detected binary paths

**Binary search locations:**

```
~/.local/bin/          # User installs (yt-dlp recommended location)
~/bin/                 # User binaries
/usr/bin/              # System binaries
/usr/local/bin/        # Local installs
<project>/bin/         # Bundled binaries (for shared hosting)
```

**Override paths via environment:**

```bash
export YT_DLP_PATH=/custom/path/to/yt-dlp
export FFMPEG_PATH=/custom/path/to/ffmpeg
export FPCALC_PATH=/custom/path/to/fpcalc
```

---

## Troubleshooting

### "500 Internal Server Error"

1. Check PHP error logs
2. Verify `mod_rewrite` is enabled
3. Check file permissions on `data/` directory

### "No results found" for searches

1. Verify internet connectivity from server
2. Check if `curl` extension is enabled: `php -m | grep curl`
3. Test Monochrome API: `curl https://api.monochrome.tf/v1/health`

### Downloads failing

1. Check write permissions on `music/` directory
2. Verify disk space
3. Check PHP `max_execution_time` (should be 300+)

### Tags not being written

1. On shared hosting: Pure PHP FlacWriter is used (no external tools needed)
2. On VPS: Verify `metaflac` is installed: `which metaflac`

### "yt-dlp not found"

This is expected on shared hosting. Only Monochrome/Tidal downloads will work.

For VPS, install yt-dlp:
```bash
sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
sudo chmod a+rx /usr/local/bin/yt-dlp
```

### Checking Feature Availability

Visit `/settings` to see which features are available on your hosting.

---

## Security Recommendations

### Essential Security Measures

1. **Enable authentication** - Set `APP_USER`/`APP_PASSWORD` in `.env` for built-in login, or use .htaccess
2. **Disable debug mode** - Set `APP_DEBUG=false` in production (this is the default)
3. **Use HTTPS** - Get a free SSL cert from Let's Encrypt (automatic with Caddy/FrankenPHP)
4. **Keep .htpasswd outside public/** - If using basic auth, store password file in app root
5. **Regular backups** - Backup `data/` and `music/` directories
6. **Keep updated** - Update yt-dlp regularly for best results

### Built-in Security Features

EZ-MU includes several security measures:

| Feature | Description |
|---------|-------------|
| CSRF Protection | All forms include CSRF tokens |
| Security Headers | X-Frame-Options, CSP, X-Content-Type-Options |
| Rate Limiting | Login attempts, search queries, API calls |
| Path Traversal Protection | File operations validate paths |
| Session Security | Secure session handling with regeneration |
| Error Handling | Production mode hides stack traces |

### Protected Paths

The following directories/files should NEVER be publicly accessible:

| Path | Contains | Protection |
|------|----------|------------|
| `data/` | SQLite database, settings | Outside web root |
| `config/` | PHP configuration | Outside web root |
| `vendor/` | Composer dependencies | Outside web root |
| `.env` | API keys, secrets | Outside web root + .htaccess |
| `.htpasswd` | Password hashes | Outside web root |
| `music/` | Downloaded audio files | Outside web root (streamed via PHP) |
| `public/music/` | M3U playlist files | Protected by .htaccess auth |

### Cron Endpoint Security

The `/cron/process` endpoint allows external services to process download jobs.
This endpoint is **disabled by default** and requires a secret key:

1. Add to your `.env` file:
   ```
   CRON_SECRET=your-random-secret-here
   ```

2. Configure your cron service to include the key:
   ```
   https://yourdomain.com/cron/process?key=your-random-secret-here&count=5
   ```
   
   Or use the Authorization header:
   ```
   Authorization: Bearer your-random-secret-here
   ```

**Important:** Without `CRON_SECRET` set, the endpoint returns 403 Forbidden.
This is intentional - if you're not using external cron, leave it unset.

### Nginx Security Config

```nginx
# Block access to sensitive paths
location ~ /\.(ht|git|env) {
    deny all;
}

location ~ ^/(data|config|vendor)/ {
    deny all;
}

# Cron endpoint - optionally restrict by IP
location = /cron/process {
    # Option 1: Restrict to specific IPs (cron service IPs)
    # allow 1.2.3.4;
    # deny all;
    
    # Option 2: Rely on CRON_SECRET in .env
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root/public/index.php;
    include fastcgi_params;
}
```

### Caddy Security Config

```caddyfile
yourdomain.com {
    # ... other config ...
    
    # Block sensitive paths
    @blocked {
        path /.env /.git/* /data/* /config/* /vendor/* /composer.*
    }
    respond @blocked 403
    
    # Optional: Restrict cron endpoint by IP
    # @cron_allowed {
    #     path /cron/process
    #     remote_ip 1.2.3.4
    # }
    # handle @cron_allowed {
    #     php_fastcgi unix//var/run/php/php8.2-fpm.sock
    # }
}
```

---

## Support

If you encounter issues:

1. Check the [Troubleshooting](#troubleshooting) section
2. Review PHP error logs
3. Open an issue on GitHub with:
   - Hosting type (shared/VPS)
   - PHP version (`php -v`)
   - Error messages
   - Steps to reproduce
