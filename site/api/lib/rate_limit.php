<?php
/**
 * File-backed rate limiting.
 *
 * No database: this form does not need one, and standing up a schema to hold
 * counters would be more moving parts than the thing it protects. State is a
 * handful of small files in a directory above the web root, pruned whenever
 * they are written, so nothing accumulates.
 *
 * Two limits apply together:
 *   - per submitter: a short burst window and a daily ceiling
 *   - global: an hourly ceiling, so a distributed flood still cannot empty the
 *     mailbox or the SMTP quota
 *
 * The submitter is identified by a keyed hash of the IP address, never the
 * address itself, so the state directory does not become a list of visitors.
 */

declare(strict_types=1);

// Library file: only ever reached through contact.php, which defines this.
if (!defined('ZONEARY_CONTACT')) {
    http_response_code(404);
    exit;
}

final class ContactRateLimit
{
    public const PER_IP_BURST        = 3;      // submissions ...
    public const PER_IP_BURST_WINDOW = 600;    // ... per 10 minutes
    public const PER_IP_DAILY        = 10;     // submissions per 24 hours
    public const GLOBAL_HOURLY       = 60;     // submissions per hour, all sources

    private string $dir;
    private bool $usable;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, "/\\");
        $this->usable = $this->ensureDir();
    }

    /**
     * Record an attempt and say whether it is allowed.
     *
     * Called before the message is sent, so a burst of failures still counts -
     * retrying a broken submission is exactly the pattern worth damping.
     *
     * @return array{allowed:bool, reason?:string, retry_after?:int}
     */
    public function attempt(string $identity, ?int $now = null): array
    {
        $now = $now ?? time();

        if (!$this->usable) {
            // A limiter that cannot write must not become a way to block the
            // form. It fails open, but the size, honeypot and validation
            // controls are all still in force.
            return ['allowed' => true, 'reason' => 'state directory unavailable'];
        }

        $global = $this->push($this->path('global'), $now, 3600);
        if (count($global) > self::GLOBAL_HOURLY) {
            return [
                'allowed'     => false,
                'reason'      => 'global hourly limit',
                'retry_after' => $this->retryAfter($global, 3600, self::GLOBAL_HOURLY, $now),
            ];
        }

        $stamps = $this->push($this->path($this->key($identity)), $now, 86400);

        $burst = array_values(array_filter($stamps, static fn (int $t): bool => $t > $now - self::PER_IP_BURST_WINDOW));
        if (count($burst) > self::PER_IP_BURST) {
            return [
                'allowed'     => false,
                'reason'      => 'per-ip burst limit',
                'retry_after' => $this->retryAfter($burst, self::PER_IP_BURST_WINDOW, self::PER_IP_BURST, $now),
            ];
        }

        if (count($stamps) > self::PER_IP_DAILY) {
            return [
                'allowed'     => false,
                'reason'      => 'per-ip daily limit',
                'retry_after' => $this->retryAfter($stamps, 86400, self::PER_IP_DAILY, $now),
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Append `now`, drop everything older than the window, and persist.
     *
     * @return int[] the retained timestamps, oldest first
     */
    private function push(string $file, int $now, int $window): array
    {
        $stamps = $this->read($file);
        $stamps[] = $now;
        $cutoff = $now - $window;
        $stamps = array_values(array_filter($stamps, static fn (int $t): bool => $t > $cutoff));
        sort($stamps);

        // Bound the file even under a flood: only the newest entries matter.
        $cap = max(self::GLOBAL_HOURLY, self::PER_IP_DAILY) + 5;
        if (count($stamps) > $cap) {
            $stamps = array_slice($stamps, -$cap);
        }

        @file_put_contents($file, implode(',', $stamps), LOCK_EX);
        @chmod($file, 0600);
        $this->prune($now);
        return $stamps;
    }

    /** @return int[] */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int) $part;
            }
        }
        return $out;
    }

    /**
     * When the oldest entry inside the window ages out, one slot frees up.
     *
     * @param int[] $stamps
     */
    private function retryAfter(array $stamps, int $window, int $limit, int $now): int
    {
        sort($stamps);
        $index = max(0, count($stamps) - $limit - 1);
        $oldest = $stamps[$index] ?? $now;
        return max(1, ($oldest + $window) - $now);
    }

    /**
     * Keyed hash of the identity.
     *
     * The key lives beside the counters rather than in the repository, and is
     * generated on first use, so the hashes cannot be reversed with a
     * dictionary of IP addresses by anyone who only has this source.
     */
    private function key(string $identity): string
    {
        $keyFile = $this->path('.hashkey');
        $key = is_file($keyFile) ? (string) @file_get_contents($keyFile) : '';
        if (strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            @file_put_contents($keyFile, $key, LOCK_EX);
            @chmod($keyFile, 0600);
        }
        return hash_hmac('sha256', $identity, $key);
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
    }

    /** Delete counter files nothing has touched for a day. */
    private function prune(int $now): void
    {
        $entries = @scandir($this->dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.hashkey' || $entry === '.gitignore') {
                continue;
            }
            $file = $this->dir . '/' . $entry;
            $mtime = @filemtime($file);
            if (is_file($file) && $mtime !== false && $mtime < $now - 86400) {
                @unlink($file);
            }
        }
    }

    /**
     * Nothing in here may throw. A limiter that fails loudly would take the
     * whole endpoint down over a directory it could not create, which is a
     * worse outcome than not rate limiting - so a bad path degrades to
     * "unusable" and attempt() then fails open.
     */
    private function ensureDir(): bool
    {
        try {
            if ($this->dir === '' || strpos($this->dir, "\0") !== false) {
                return false;
            }
            if (is_dir($this->dir)) {
                return is_writable($this->dir);
            }
            if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                return false;
            }
            @chmod($this->dir, 0700);
            return is_writable($this->dir);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function isUsable(): bool
    {
        return $this->usable;
    }
}
