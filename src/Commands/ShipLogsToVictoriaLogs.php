<?php

namespace Wndr\VictoriaLogsTail\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ShipLogsToVictoriaLogs extends Command
{
    protected $signature = 'victorialogs:ship-logs
        {--dir= : Directory to watch (default: storage/logs)}
        {--pattern= : File pattern (default: *.log)}
        {--app= : Application name (default: from config)}
        {--endpoint= : VictoriaLogs endpoint (default: from config)}
        {--cursor=victorialogs-cursors.json : Cursor file stored in storage/app (only used when cleanup is disabled)}
        {--flush-seconds=60 : Flush interval in seconds}
        {--max-bytes=900000 : Max batch bytes per stream before flush (keep < 1MB)}
        {--refresh-seconds=120 : How often to rescan directory for new files}
        {--sleep-ms=200 : Loop sleep when idle (milliseconds)}
        {--cleanup-after-ship=true : Truncate shipped files and delete old rotated logs (default: true)}
    ';

    protected $description = 'Tail all log files in a directory and ship lines to VictoriaLogs (one stream per file)';

    // Size limits
    private const SAFE_EVENT_SIZE = 250000; // 250KB per event

    // Patterns
    private const LOG_ENTRY_PATTERN = '/^\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/';
    private const ROTATED_FILE_PATTERN = '/\.log\.\d+$/';
    private const DATED_LOG_PATTERN = '/\d{4}-\d{2}-\d{2}\.log$/';

    /** @var array<string, resource> */
    private array $handles = [];

    /** @var array<string, array{offset:int,inode:int|null}> */
    private array $cursors = [];

    /** @var array<string, array<int, array{timestamp:int,message:string}>> */
    private array $batches = [];

    /** @var array<string, int> */
    private array $batchBytes = [];

    /** @var array<string, float> */
    private array $lastFlushAt = [];

    /** @var array<string, string> */
    private array $lineBuffers = [];

    /** @var array<string, bool> */
    private array $fullyShipped = [];

    private Filesystem $fs;
    private bool $shouldExit = false;

    // Config (set in handle())
    private string $endpoint;
    private string $appName;
    private int $maxBytes;
    private int $flushSeconds;
    private int $timeout;
    private bool $cleanupAfterShip;
    private string $cursorKey;
    private array $extraFields;
    private ?string $authUsername;
    private ?string $authPassword;

    public function handle(): int
    {
        $this->fs = app(Filesystem::class);
        $this->registerSignalHandlers();

        $dir = $this->option('dir') ?: storage_path('logs');
        $pattern = $this->option('pattern') ?: '*.log';

        $this->endpoint = $this->option('endpoint') ?: config('victoria-logs-tail.endpoint');
        if (!$this->endpoint) {
            $this->error('Missing VictoriaLogs endpoint. Set VICTORIALOGS_ENDPOINT in config or pass --endpoint.');
            return 1;
        }
        $this->endpoint = rtrim($this->endpoint, '/') . '/insert/jsonline';

        $this->appName = $this->option('app') ?: config('victoria-logs-tail.app_name', 'laravel');
        $this->timeout = (int) config('victoria-logs-tail.timeout', 30);
        $this->extraFields = config('victoria-logs-tail.extra_fields', []);
        $this->authUsername = config('victoria-logs-tail.auth.username');
        $this->authPassword = config('victoria-logs-tail.auth.password');

        $this->cursorKey = (string) $this->option('cursor');
        $this->flushSeconds = max(1, (int) $this->option('flush-seconds'));
        $this->maxBytes = max(50_000, (int) $this->option('max-bytes'));
        $refreshSeconds = max(5, (int) $this->option('refresh-seconds'));
        $sleepMs = max(50, (int) $this->option('sleep-ms'));
        $this->cleanupAfterShip = $this->option('cleanup-after-ship') !== 'false';

        // Only load cursors if cleanup is disabled (otherwise we don't need them)
        if (!$this->cleanupAfterShip) {
            $this->cursors = $this->loadCursors();
        }

        $lastRefresh = 0.0;

        $this->info("Shipping logs from {$dir}/{$pattern} to VictoriaLogs at '{$this->endpoint}'");
        $this->info("App name: {$this->appName}");
        $this->info("Cleanup after ship: " . ($this->cleanupAfterShip ? 'enabled' : 'disabled'));

        while (!$this->shouldExit) {
            $now = microtime(true);

            if (($now - $lastRefresh) >= $refreshSeconds) {
                $this->refreshFiles($dir, $pattern);
                $lastRefresh = $now;

                if ($this->cleanupAfterShip) {
                    $this->cleanupRotatedFiles($dir);
                }
            }

            $didWork = $this->readFiles();
            $this->flushStaleBuffers($now);
            $this->flushStaleBatches($now);

            // Update shipped status AFTER flushing, so we know data reached VictoriaLogs
            $this->updateAllShippedStatus();

            if ($this->cleanupAfterShip) {
                $this->truncateShippedFiles();
            }

            if (!$didWork) {
                usleep($sleepMs * 1000);
            }
        }

        $this->shutdown();
        return 0;
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, fn() => $this->shouldExit = true);
        pcntl_signal(SIGINT, fn() => $this->shouldExit = true);
        pcntl_async_signals(true);
    }

    private function shutdown(): void
    {
        $this->info('Shutting down, flushing remaining logs...');

        // Flush all line buffers
        foreach (array_keys($this->lineBuffers) as $path) {
            $this->flushLineBuffer($path);
        }

        // Flush all batches to VictoriaLogs
        foreach (array_keys($this->batches) as $stream) {
            if (!empty($this->batches[$stream])) {
                $this->flushStream($stream);
            }
        }

        // Close all file handles
        foreach ($this->handles as $fh) {
            fclose($fh);
        }

        // Save cursors if cleanup is disabled
        if (!$this->cleanupAfterShip) {
            $this->saveCursors();
        }

        $this->info('Shutdown complete.');
    }

    private function readFiles(): bool
    {
        $didWork = false;

        foreach ($this->handles as $path => $fh) {
            $stream = $this->streamNameForFile($path);

            while (($line = fgets($fh)) !== false) {
                $didWork = true;
                $this->processLine($path, $stream, rtrim($line, "\r\n"));
            }

            $this->handleRotationIfNeeded($path);
        }

        return $didWork;
    }

    private function updateAllShippedStatus(): void
    {
        foreach ($this->handles as $path => $fh) {
            $stream = $this->streamNameForFile($path);
            $this->updateFullyShippedStatus($path, $fh, $stream);
        }
    }

    private function processLine(string $path, string $stream, string $msg): void
    {
        if ($msg === '') {
            return;
        }

        $isNewEntry = preg_match(self::LOG_ENTRY_PATTERN, $msg);

        // Flush previous entry if this starts a new one
        if ($isNewEntry && !empty($this->lineBuffers[$path])) {
            $this->emitLogEvent($stream, $this->lineBuffers[$path]);
            $this->lineBuffers[$path] = '';
        }

        // Buffer or append
        if (empty($this->lineBuffers[$path])) {
            $this->lineBuffers[$path] = $msg;
        } else {
            $newSize = strlen($this->lineBuffers[$path]) + 1 + strlen($msg);
            if ($newSize > self::SAFE_EVENT_SIZE) {
                $this->emitLogEvent($stream, $this->lineBuffers[$path]);
                $this->lineBuffers[$path] = $msg;
            } else {
                $this->lineBuffers[$path] .= "\n" . $msg;
            }
        }
    }

    /**
     * Check if file is fully shipped. Only true if:
     * - At EOF
     * - Line buffer is empty
     * - Batch is empty (already sent to VictoriaLogs)
     * - We've successfully flushed at least once (lastFlushAt is set)
     */
    private function updateFullyShippedStatus(string $path, $fh, string $stream): void
    {
        $atEof = feof($fh);
        $bufferEmpty = empty($this->lineBuffers[$path]);
        $batchEmpty = empty($this->batches[$stream] ?? []);

        // Only consider "fully shipped" if we've actually flushed something
        // This prevents truncating before any data has been sent to VictoriaLogs
        $hasEverFlushed = isset($this->lastFlushAt[$stream]);

        $this->fullyShipped[$path] = $atEof && $bufferEmpty && $batchEmpty && $hasEverFlushed;
    }

    private function flushStaleBuffers(float $now): void
    {
        foreach ($this->lineBuffers as $path => $buffer) {
            if ($buffer === '') {
                continue;
            }

            $stream = $this->streamNameForFile($path);

            // Use lastFlushAt if set, otherwise use a time that will trigger immediate flush
            // This ensures we don't hold onto buffers indefinitely
            $lastFlush = $this->lastFlushAt[$stream] ?? ($now - $this->flushSeconds - 1);

            if (($now - $lastFlush) >= $this->flushSeconds) {
                $this->emitLogEvent($stream, $buffer);
                $this->lineBuffers[$path] = '';
            }
        }
    }

    private function flushStaleBatches(float $now): void
    {
        foreach (array_keys($this->batches) as $stream) {
            if (empty($this->batches[$stream])) {
                continue;
            }

            // Use lastFlushAt if set, otherwise use a time that will trigger immediate flush
            $lastFlush = $this->lastFlushAt[$stream] ?? ($now - $this->flushSeconds - 1);

            if (($now - $lastFlush) >= $this->flushSeconds) {
                $this->flushStream($stream);
            }
        }
    }

    private function emitLogEvent(string $stream, string $message): void
    {
        $eventBytes = strlen($message);

        // Truncate oversized messages
        if ($eventBytes > self::SAFE_EVENT_SIZE) {
            $message = substr($message, 0, self::SAFE_EVENT_SIZE) . '…(truncated)';
            $eventBytes = strlen($message);
        }

        $this->batches[$stream] ??= [];
        $this->batchBytes[$stream] ??= 0;

        // Flush if batch would exceed limits
        if (($this->batchBytes[$stream] + $eventBytes) > $this->maxBytes) {
            $this->flushStream($stream);
        }

        $this->batches[$stream][] = [
            'timestamp' => (int) round(microtime(true) * 1000),
            'message' => $message,
        ];
        $this->batchBytes[$stream] += $eventBytes;
    }

    private function streamNameForFile(string $path): string
    {
        return basename($path);
    }

    private function refreshFiles(string $dir, string $pattern): void
    {
        if (!$this->fs->isDirectory($dir)) {
            $this->warn("Directory not found: {$dir}");
            return;
        }

        foreach ($this->fs->files($dir) as $file) {
            $name = $file->getFilename();

            if (!fnmatch($pattern, $name)) {
                continue;
            }

            $path = $file->getRealPath();
            if ($path && !isset($this->handles[$path])) {
                $this->openFile($path);
            }
        }

        // Close handles for deleted files
        foreach (array_keys($this->handles) as $path) {
            clearstatcache(true, $path);
            if (!$this->fs->exists($path)) {
                $this->flushLineBuffer($path);
                fclose($this->handles[$path]);
                unset($this->handles[$path], $this->fullyShipped[$path], $this->lineBuffers[$path]);
            }
        }
    }

    private function openFile(string $path): void
    {
        $fh = @fopen($path, 'r');
        if (!$fh) {
            $this->warn("Unable to open: {$path}");
            return;
        }

        stream_set_blocking($fh, false);

        $inode = @fileinode($path) ?: null;
        $size = @filesize($path) ?: 0;

        // Determine starting offset
        $offset = 0;
        if (!$this->cleanupAfterShip && isset($this->cursors[$path])) {
            // Only use saved cursor if cleanup is disabled AND inode matches AND file didn't shrink
            $savedInode = $this->cursors[$path]['inode'] ?? null;
            $savedOffset = $this->cursors[$path]['offset'] ?? 0;

            if ($savedInode === $inode && $savedOffset <= $size) {
                $offset = $savedOffset;
            }
        }

        $this->cursors[$path] = ['offset' => $offset, 'inode' => $inode];
        @fseek($fh, $offset);

        $this->handles[$path] = $fh;
    }

    private function handleRotationIfNeeded(string $path): void
    {
        clearstatcache(true, $path);

        $currentInode = @fileinode($path) ?: null;
        $currentSize = @filesize($path);
        $savedInode = $this->cursors[$path]['inode'] ?? null;
        $savedOffset = $this->cursors[$path]['offset'] ?? 0;

        $inodeChanged = $currentInode !== null && $savedInode !== null && $currentInode !== $savedInode;
        $fileTruncated = $currentSize !== false && $currentSize < $savedOffset;

        if (!$inodeChanged && !$fileTruncated) {
            return;
        }

        $this->flushLineBuffer($path);

        if (isset($this->handles[$path])) {
            fclose($this->handles[$path]);
            unset($this->handles[$path]);
        }

        $this->cursors[$path] = ['offset' => 0, 'inode' => $currentInode];

        if (!$this->cleanupAfterShip) {
            $this->saveCursors();
        }

        $this->openFile($path);
    }

    private function flushLineBuffer(string $path): void
    {
        if (empty($this->lineBuffers[$path])) {
            return;
        }

        $stream = $this->streamNameForFile($path);
        $this->emitLogEvent($stream, $this->lineBuffers[$path]);
        $this->lineBuffers[$path] = '';
    }

    private function flushStream(string $stream): void
    {
        $events = $this->batches[$stream] ?? [];
        if (empty($events)) {
            return;
        }

        // Build JSON Lines payload
        $payload = '';
        foreach ($events as $event) {
            $entry = array_merge([
                '_msg' => $event['message'],
                '_time' => $event['timestamp'],
                'app' => $this->appName,
                'stream' => $stream,
            ], $this->extraFields);

            $payload .= json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        try {
            $request = Http::timeout($this->timeout);

            if ($this->authUsername) {
                $request = $request->withBasicAuth($this->authUsername, $this->authPassword ?? '');
            }

            $response = $request
                ->withBody($payload, 'application/x-ndjson')
                ->post($this->endpoint);

            if (!$response->successful()) {
                $this->error("Failed to ship logs to VictoriaLogs: HTTP {$response->status()}");
                $this->error($response->body());
                return;
            }
        } catch (\Exception $e) {
            $this->error("Failed to ship logs to VictoriaLogs: " . $e->getMessage());
            return;
        }

        $this->batches[$stream] = [];
        $this->batchBytes[$stream] = 0;
        $this->lastFlushAt[$stream] = microtime(true);

        $this->info("Flushed " . count($events) . " events to VictoriaLogs stream: {$stream}");
    }

    private function loadCursors(): array
    {
        if (!Storage::disk('local')->exists($this->cursorKey)) {
            return [];
        }

        $data = json_decode(Storage::disk('local')->get($this->cursorKey), true);
        return is_array($data) ? $data : [];
    }

    private function saveCursors(): void
    {
        // Update offsets from current file positions
        foreach ($this->handles as $path => $fh) {
            $offset = @ftell($fh);
            if ($offset !== false) {
                $this->cursors[$path]['offset'] = $offset;
            }
        }

        Storage::disk('local')->put($this->cursorKey, json_encode($this->cursors));
    }

    private function cleanupRotatedFiles(string $dir): void
    {
        if (!$this->fs->isDirectory($dir)) {
            return;
        }

        foreach ($this->fs->files($dir) as $file) {
            $name = $file->getFilename();
            $path = $file->getRealPath();

            if (!$path) {
                continue;
            }

            $isRotated = preg_match(self::ROTATED_FILE_PATTERN, $name);
            $isDated = preg_match(self::DATED_LOG_PATTERN, $name);

            if (!$isRotated && !$isDated) {
                continue;
            }

            if (!($this->fullyShipped[$path] ?? false)) {
                continue;
            }

            if (isset($this->handles[$path])) {
                fclose($this->handles[$path]);
                unset($this->handles[$path]);
            }

            if (@unlink($path)) {
                $this->info("Deleted shipped log: {$name}");
                unset($this->cursors[$path], $this->fullyShipped[$path], $this->lineBuffers[$path]);
            }
        }
    }

    private function truncateShippedFiles(): void
    {
        foreach (array_keys($this->handles) as $path) {
            if (!($this->fullyShipped[$path] ?? false)) {
                continue;
            }

            clearstatcache(true, $path);
            $size = @filesize($path);

            if ($size === false || $size === 0) {
                continue;
            }

            // Ensure everything is flushed before truncating
            $this->flushLineBuffer($path);
            $stream = $this->streamNameForFile($path);

            if (!empty($this->batches[$stream] ?? [])) {
                $this->flushStream($stream);
            }

            // Close handle and truncate
            if (isset($this->handles[$path])) {
                fclose($this->handles[$path]);
                unset($this->handles[$path]);
            }

            if ($fh = @fopen($path, 'w')) {
                fclose($fh);
                $this->info("Truncated: " . basename($path));

                clearstatcache(true, $path);
                $inode = @fileinode($path) ?: null;
                $this->cursors[$path] = ['offset' => 0, 'inode' => $inode];
                unset($this->fullyShipped[$path], $this->lineBuffers[$path]);

                $this->openFile($path);
            }
        }
    }
}
