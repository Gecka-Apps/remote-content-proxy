# Deployment

## Prerequisites

- PHP 8.2+ with extensions: `curl`, `dom`, `fileinfo`, `libxml`, `mbstring`, `openssl`, `pdo_sqlite` (or `pdo_mysql`)
- A web server: Nginx (recommended) or Apache 2.4+
- Git and Composer

## Installation

Install from a Git checkout of a release tag: updating is then a `git checkout` of the next tag, and the files on disk always match a known revision.

```bash
cd /var/www
git clone https://github.com/Gecka-Apps/remote-content-proxy.git
cd remote-content-proxy

# Latest release tag
git checkout "$(git tag --sort=-v:refname | head -n 1)"

composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force

# Set permissions
chown -R www-data:www-data storage database
chmod -R 775 storage database

# Create your first API key
php artisan apikey:create "My App"

# Cache configuration and routes for production
php artisan optimize
```

`main` is the development branch; run a tag, not `main`.

### Updating

```bash
cd /var/www/remote-content-proxy
git fetch --tags
git checkout X.Y.Z
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
systemctl reload php8.2-fpm
```

`php artisan optimize` rebuilds the configuration and route caches for the new code; the PHP-FPM reload drops the opcache so the new files are actually executed. Adjust the service name to the PHP version in use.

### From a release tarball

Each release also ships `remote-content-proxy-X.Y.Z.tar.gz` on [GitHub Releases](https://github.com/Gecka-Apps/remote-content-proxy/releases/latest), with `vendor/` included, for hosts without Git or Composer. It extracts into a `remote-content-proxy/` directory; the steps after `composer install` above apply unchanged. To update, extract the new version next to the old one, copy `.env`, `database/database.sqlite` and `storage/` over, then switch the web server's document root.

## Environment Configuration

Edit `.env` to set at minimum:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://proxy.example.com
```

See [Configuration](configuration.md) for all available options. The configuration is cached by `php artisan optimize`: run it again after every change to `.env`, or the change stays invisible.

## Nginx

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name proxy.example.com;

    # Redirect HTTP to HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name proxy.example.com;

    root /var/www/remote-content-proxy/public;
    index index.php;

    ssl_certificate     /etc/ssl/certs/proxy.example.com.pem;
    ssl_certificate_key /etc/ssl/private/proxy.example.com.key;

    charset utf-8;

    # Security: deny access to dotfiles
    location ~ /\. {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Adjust `fastcgi_pass` to match your PHP-FPM socket path. Common values:
- Debian/Ubuntu: `unix:/run/php/php8.2-fpm.sock` (or `php8.3-fpm.sock`, `php8.4-fpm.sock`)
- RHEL/Rocky: `unix:/run/php-fpm/www.sock`
- TCP fallback: `127.0.0.1:9000`

After creating the config:

```bash
nginx -t && systemctl reload nginx
```

## Apache

Apache 2.4+ with `mod_rewrite` enabled. The `.htaccess` file in `public/` handles URL rewriting automatically.

```apache
<VirtualHost *:80>
    ServerName proxy.example.com
    Redirect permanent / https://proxy.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName proxy.example.com

    DocumentRoot /var/www/remote-content-proxy/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/proxy.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/proxy.example.com.key

    <Directory /var/www/remote-content-proxy/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Deny access to non-public directories
    <DirectoryMatch "^/var/www/remote-content-proxy/(app|bootstrap|config|database|routes|storage|vendor)">
        Require all denied
    </DirectoryMatch>

    ErrorLog ${APACHE_LOG_DIR}/proxy-error.log
    CustomLog ${APACHE_LOG_DIR}/proxy-access.log combined
</VirtualHost>
```

Enable required modules and the site:

```bash
a2enmod rewrite ssl
a2ensite remote-content-proxy
apachectl configtest && systemctl reload apache2
```

## PHP-FPM Tuning

For a dedicated proxy instance, a small pool is sufficient. Example `/etc/php/8.2/fpm/pool.d/proxy.conf`:

```ini
[proxy]
user = www-data
group = www-data
listen = /run/php/php-fpm-proxy.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

; Recycle workers to prevent memory leaks
pm.max_requests = 500
```

## Scheduled Maintenance

The scheduler runs `proxy:cache:maintain` every day at 3:00: expired entries, LRU eviction down to `PROXY_CACHE_MAX_SIZE`, orphaned files and stale locks. Add it to the crontab of the user that owns `storage/`:

```bash
crontab -u www-data -e
```

```cron
* * * * * cd /var/www/remote-content-proxy && php artisan schedule:run >> /dev/null 2>&1
```

Without the cron, the cache still evicts inline: a request that finds the cache above `PROXY_CACHE_CLEANUP_THRESHOLD` runs the cleanup itself, at most once every five minutes. That keeps the size bounded but puts the work in the request path, and orphaned files and stale locks are never removed.

## Production Checklist

- [ ] A release tag checked out, not `main`
- [ ] `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- [ ] `APP_URL` set to the public URL (`PROXY_BASE_URL` too if the proxy sits behind another host name)
- [ ] SSL/TLS configured
- [ ] File permissions: `www-data` owns `storage/` and `database/`
- [ ] At least one API key created (`php artisan apikey:create`), with its own allowance if it serves many users (`--rate-limit`)
- [ ] `php artisan optimize` run after every update
- [ ] Scheduler in the crontab
- [ ] Firewall allows inbound HTTPS (port 443)
- [ ] PHP extensions installed: `curl`, `dom`, `fileinfo`, `libxml`, `mbstring`, `openssl`, `pdo_sqlite`
- [ ] Log rotation configured for `storage/logs/`
- [ ] `/up` polled by your monitoring
