# Laravel VictoriaLogs Tail

Tail Laravel log files and ship them to [VictoriaLogs](https://docs.victoriametrics.com/victorialogs/).

## Installation

```bash
composer require wndr/laravel-victoria-logs-tail
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=victoria-logs-tail-config
```

### Environment Variables

Configure the following environment variables:

```env
VICTORIALOGS_ENDPOINT=http://victorialogs:9428
VICTORIALOGS_APP_NAME=my-laravel-app
```

| Variable | Default | Description |
|----------|---------|-------------|
| `VICTORIALOGS_ENDPOINT` | `http://localhost:9428` | Base URL of your VictoriaLogs instance |
| `VICTORIALOGS_APP_NAME` | `APP_NAME` or `laravel` | Application name tag for log entries |
| `VICTORIALOGS_TIMEOUT` | `30` | HTTP timeout in seconds |
| `VICTORIALOGS_AUTH_USERNAME` | `null` | Basic auth username (optional) |
| `VICTORIALOGS_AUTH_PASSWORD` | `null` | Basic auth password (optional) |

### Basic Authentication

If your VictoriaLogs instance requires authentication, configure basic auth credentials:

```env
VICTORIALOGS_AUTH_USERNAME=your-username
VICTORIALOGS_AUTH_PASSWORD=your-password
```

Leave `VICTORIALOGS_AUTH_USERNAME` empty to disable basic auth.

### Extra Fields

You can add extra fields to every log entry by editing the published config file:

```php
// config/victoria-logs-tail.php
'extra_fields' => [
    'environment' => env('APP_ENV'),
    'hostname' => gethostname(),
    'version' => config('app.version'),
],
```

## Usage

Run the command to start shipping logs:

```bash
php artisan victorialogs:ship-logs
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--dir` | `storage/logs` | Directory to watch for log files |
| `--pattern` | `*.log` | File pattern to match |
| `--app` | Config value | Application name |
| `--endpoint` | Config value | VictoriaLogs endpoint |
| `--cursor` | `victorialogs-cursors.json` | Cursor file (only used when cleanup is disabled) |
| `--flush-seconds` | `60` | Flush interval in seconds |
| `--max-bytes` | `900000` | Max batch bytes per stream before flush |
| `--refresh-seconds` | `120` | How often to rescan directory for new files |
| `--sleep-ms` | `200` | Loop sleep when idle (milliseconds) |
| `--cleanup-after-ship` | `true` | Truncate shipped files and delete old rotated logs |

### Running as a Service

For production, run the command as a supervised service. Example using Supervisor:

```ini
[program:victorialogs-tail]
command=php /path/to/artisan victorialogs:ship-logs
directory=/path/to/your/app
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/victorialogs-tail.log
```

### Running in Docker

Add to your Docker Compose:

```yaml
services:
  victorialogs-tail:
    image: your-laravel-image
    command: php artisan victorialogs:ship-logs
    volumes:
      - ./storage/logs:/app/storage/logs
    environment:
      - VICTORIALOGS_ENDPOINT=http://victorialogs:9428
      - VICTORIALOGS_APP_NAME=my-app
      # Optional: basic auth
      # - VICTORIALOGS_AUTH_USERNAME=user
      # - VICTORIALOGS_AUTH_PASSWORD=secret
    depends_on:
      - victorialogs
```

## Log Format

Logs are shipped to VictoriaLogs in JSON Lines format with the following fields:

| Field | Description |
|-------|-------------|
| `_msg` | The log message content |
| `_time` | Timestamp in milliseconds |
| `app` | Application name |
| `stream` | Log file name (e.g., `laravel.log`) |
| + any extra fields | Custom fields from config |

## Querying Logs

Once shipped, query your logs using VictoriaLogs' LogsQL:

```bash
# All logs from your app
curl 'http://victorialogs:9428/select/logsql/query' -d 'query=app:"my-laravel-app"'

# Error logs only
curl 'http://victorialogs:9428/select/logsql/query' -d 'query=app:"my-laravel-app" AND _msg:~"error"'

# Logs from a specific stream
curl 'http://victorialogs:9428/select/logsql/query' -d 'query=app:"my-laravel-app" AND stream:"laravel.log"'
```

## Features

- **Multi-line log support**: Automatically groups stack traces and multi-line log entries
- **Log rotation handling**: Detects file rotation and truncation
- **Batched shipping**: Groups log entries for efficient HTTP transport
- **Graceful shutdown**: Flushes all pending logs on SIGTERM/SIGINT
- **Automatic cleanup**: Optionally truncates shipped files and deletes old rotated logs
- **Cursor persistence**: Tracks read position to resume after restarts (when cleanup is disabled)
- **Basic authentication**: Optional HTTP basic auth for secured VictoriaLogs instances

## License

MIT
