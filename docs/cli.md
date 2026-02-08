# CLI Commands

## API Key Management

### Create API Key

```bash
php artisan apikey:create "Key Name"

# With an allowance of its own instead of PROXY_RATE_LIMIT_MAX_REQUESTS
php artisan apikey:create "Webmail" --rate-limit=5000
```

Creates a new API key. The key is displayed only once and cannot be retrieved later.

### Set the Rate Limit of an API Key

```bash
# Requests per window for this key
php artisan apikey:limit <id> 5000

# Back to the global PROXY_RATE_LIMIT_MAX_REQUESTS
php artisan apikey:limit <id> default
```

The window length (`PROXY_RATE_LIMIT_WINDOW`) is the same for every key.

### List API Keys

```bash
# List active keys
php artisan apikey:list

# List revoked keys
php artisan apikey:list --revoked

# List all keys
php artisan apikey:list --all
```

### Revoke API Key

```bash
php artisan apikey:revoke <id>
```

Revokes an API key by its ID. Requires confirmation.

## Cache Management

### View Cache Info

```bash
php artisan proxy:cache:info
```

Displays cache statistics:
- Total items and size
- Maximum cache size
- Cache driver and path
- Cache TTL
- Oldest and newest entries

### Clear Cache

```bash
# Clear all entries (with confirmation)
php artisan proxy:cache:clear

# Clear all entries without confirmation
php artisan proxy:cache:clear --force

# Clear only expired entries
php artisan proxy:cache:clear --expired
```

### Cache Maintenance

```bash
# Full maintenance
php artisan proxy:cache:maintain

# Preview changes (dry run)
php artisan proxy:cache:maintain --dry-run

# Show detailed output
php artisan proxy:cache:maintain --details
```

Maintenance includes:
- Removing expired entries
- Enforcing size limits (LRU eviction)
- Cleaning orphaned files
- Cleaning stale lock files

## Scheduled Tasks

`proxy:cache:maintain` is scheduled daily at 3:00 (`routes/console.php`), with `withoutOverlapping()` and its output appended to `storage/logs/proxy-cache-maintenance.log`. It runs once the Laravel scheduler is in the crontab:

```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Without it, expired entries are still dropped on read and an oversized cache is trimmed inline by the request that notices it, but orphaned files and stale locks accumulate. See [Deployment](deployment.md#scheduled-maintenance).
