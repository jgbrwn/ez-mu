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
└── .env            (optional, for AcoustID - see below)
```

> **Note:** The `.env` file is optional on shared hosting since audio fingerprinting
> requires `fpcalc` which isn't available. If you do use it, ensure it's placed in
> the project root (outside `public/`) so it's not web-accessible.

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

### Method 1: .htaccess Basic Auth (Recommended)

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

### Method 2: DirectAdmin Password Protected Directory

1. Log into DirectAdmin
2. Go to **Advanced Features** → **Password Protected Directories**
3. Select the `public/` directory
4. Add users with passwords
5. Enable protection

### Method 3: Application-Level Auth (Future)

A future version may include built-in user authentication.

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

# Set document root to public/
ENV SERVER_NAME=:80
ENV FRANKENPHP_CONFIG="root * /app/public"

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

:80 {
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
      - caddy_data:/data
      - caddy_config:/config
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
docker compose up -d
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

| Variable | Description | Required |
|----------|-------------|----------|
| `ACOUSTID_API_KEY` | API key for audio fingerprinting | No (VPS only) |

Get an AcoustID API key at: https://acoustid.org/api-key

> **Shared Hosting:** The `.env` file is optional since fingerprinting requires
> `fpcalc` which isn't available on shared hosting. Text-based MusicBrainz
> lookups work without it.

You can also customize binary paths via environment variables:

```bash
export YT_DLP_PATH=/usr/local/bin/yt-dlp
export FFMPEG_PATH=/usr/bin/ffmpeg
export FPCALC_PATH=/usr/bin/fpcalc
```

### Application Settings

Settings are stored in SQLite and managed via the web interface:

- **Convert to FLAC** - Auto-convert downloads
- **Organize by Artist** - Create artist subdirectories  
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

1. **Always use password protection** - Either .htaccess or DirectAdmin/cPanel
2. **Keep .htpasswd outside public/** - Store in app root, not web-accessible
3. **Use HTTPS** - Get a free SSL cert from Let's Encrypt
4. **Regular backups** - Backup `data/` and `music/` directories
5. **Keep updated** - Update yt-dlp regularly for best results

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
