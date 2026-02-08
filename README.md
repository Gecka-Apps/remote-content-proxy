# Remote Content Proxy

[![Tests](https://github.com/Gecka-Apps/remote-content-proxy/actions/workflows/tests.yml/badge.svg)](https://github.com/Gecka-Apps/remote-content-proxy/actions/workflows/tests.yml)
[![Release](https://img.shields.io/github/v/release/Gecka-Apps/remote-content-proxy)](https://github.com/Gecka-Apps/remote-content-proxy/releases/latest)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4)](https://php.net)

Secure remote content proxy for email clients.

## About

Remote Content Proxy is a server that safely loads remote images, CSS, fonts, and videos on behalf of email clients, protecting user privacy and preventing SSRF attacks, DNS rebinding, and malicious content injection.

## Features

- Security-first: SSRF protection, DNS rebinding prevention, SVG/CSS sanitization
- API authentication: 256-bit API keys with revocation support
- Rate limiting: per key, with an allowance per key, per IP without a valid key
- Intelligent caching: file or Redis backend with LRU eviction
- Retry logic: exponential backoff on connection failures and 5xx answers
- Content validation: allowlisted MIME types, magic byte detection, size cap enforced while downloading
- Comprehensive security headers (CSP, X-Frame-Options, etc.)

## Requirements

- PHP 8.2+
- Extensions: `curl`, `dom`, `fileinfo`, `libxml`, `mbstring`, `openssl`
- Database: `pdo_sqlite` or `pdo_mysql` (depending on your DB driver)
- Git and Composer

## License

This project is released under the [GNU Affero General Public License Version 3](https://www.gnu.org/licenses/agpl-3.0.html).

## Quick Start

```bash
git clone https://github.com/Gecka-Apps/remote-content-proxy.git
cd remote-content-proxy
composer run setup
php artisan apikey:create "My App"
php artisan serve
```

`composer run setup` installs the dependencies, creates `.env` with an application key, the SQLite database and runs the migrations.

For production (a release tag, Nginx or Apache, updates, scheduler), see the [Deployment guide](docs/deployment.md). Releases are tags on this repository; `main` is the development branch.

## Usage

Encode the content URL in URL-safe Base64:

```php
$encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
```

Request the content, with the key in a header:

```
GET /i/{encoded_url}
Authorization: Bearer your_key
```

`X-API-Key: your_key` and `?api_key=your_key` are accepted too. See the [API reference](docs/api.md).

## Documentation

- [Deployment](docs/deployment.md) - Nginx, Apache, and production setup
- [Configuration](docs/configuration.md) - Environment variables and settings
- [API Reference](docs/api.md) - Endpoints and URL encoding
- [CLI Commands](docs/cli.md) - API key and cache management
- [Security](docs/security.md) - Protection mechanisms

## Authors

- **Laurent Dinclaux** <laurent@gecka.nc> - Gecka

## Related Projects

- [roundcube-remote_content_proxy](https://github.com/Gecka-Apps/roundcube-remote_content_proxy) - Companion plugin for Roundcube
- [thunderbird-remote-content-proxy](https://github.com/Gecka-Apps/thunderbird-remote-content-proxy) - Companion extension for Thunderbird

---

Built with 🥥 and ☕ by [Gecka](https://gecka.nc) — Kanaky-New Caledonia 🇳🇨
