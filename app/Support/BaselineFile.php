<?php

namespace App\Support;

use GlimpseImg\ApiException;
use InvalidArgumentException;
use stdClass;

/**
 * A .glimpse-baseline.json file: a JSON list of already-processed files in
 * the directory glimpse is run from, so analyze and check skip them.
 * Entries match on relative
 * path plus size plus xxh128 content hash; a file whose content changed
 * re-enters the scan. The hash is for change detection, not security, so
 * a fast non-cryptographic digest is the right tool.
 *
 * Concurrency: reads take a shared flock and saves take an exclusive one.
 * A save replays this instance's mutations onto whatever is in the file at
 * that moment instead of writing back its loaded snapshot, so parallel
 * glimpse runs merge their entries rather than clobbering each other.
 */
final class BaselineFile
{
    public const FILENAME = '.glimpse-baseline.json';

    /**
     * Entries put since load, replayed onto the file's current contents
     * at save time.
     *
     * @var array<string, array{size: int, xxh128: string}>
     */
    private array $puts = [];

    /**
     * Keys forgotten since load, removed from the file's current contents
     * at save time.
     *
     * @var array<string, true>
     */
    private array $forgets = [];

    /**
     * @param  array<string, array{size: int, xxh128: string}>  $files
     */
    private function __construct(private array $files) {}

    /**
     * Load the baseline at the directory. A missing or empty file is an
     * empty baseline; an unreadable or malformed one fails loudly so a
     * permission problem or a typo never turns into a silently
     * un-baselined (or fully skipped) scan.
     */
    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/').'/'.self::FILENAME;

        if (! is_file($path)) {
            return new self([]);
        }

        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new ApiException("Could not read {$path}.");
        }

        try {
            flock($handle, LOCK_SH);
            $content = trim((string) stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return new self($content === '' ? [] : self::parse($content, $path));
    }

    /**
     * Decode and validate baseline JSON into the entry map.
     *
     * @return array<string, array{size: int, xxh128: string}>
     */
    private static function parse(string $content, string $path): array
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null)) {
            throw new ApiException("Malformed {$path}: expected a JSON object with a \"files\" object.");
        }

        $files = [];

        foreach ($decoded['files'] as $relative => $entry) {
            if (! is_string($relative) || ! is_array($entry) || ! is_int($entry['size'] ?? null) || ! is_string($entry['xxh128'] ?? null)) {
                throw new ApiException("Malformed {$path}: every entry needs an integer \"size\" and a string \"xxh128\".");
            }

            $files[$relative] = ['size' => $entry['size'], 'xxh128' => $entry['xxh128']];
        }

        return $files;
    }

    /**
     * The directory whose baseline governs this run: always the current
     * working directory, the way composer, phpunit, and phpstan resolve
     * their config. No upward search, so a baseline elsewhere can never
     * capture a scan or a write; run glimpse from the project root to use
     * the project's baseline.
     */
    public static function root(): string
    {
        return rtrim(str_replace('\\', '/', (string) getcwd()), '/');
    }

    /**
     * Whether the path lies strictly inside the directory. Both are
     * expected to be canonical absolute paths; separators are normalized
     * before comparing.
     */
    public static function contains(string $directory, string $path): bool
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $path = str_replace('\\', '/', $path);

        return $directory !== '' && str_starts_with($path, $directory.'/');
    }

    /**
     * The path of a file relative to a directory that contains it, with
     * separators normalized to forward slashes so baseline keys match
     * across platforms. A path outside the directory throws: a blind
     * substr would silently produce a wrong key (and a sibling like
     * /scan/rootbeer under /scan/root a mangled one), quietly corrupting
     * the baseline instead of surfacing the caller's bug.
     */
    public static function relativePath(string $directory, string $path): string
    {
        $directory = str_replace('\\', '/', $directory);
        $path = str_replace('\\', '/', $path);
        $base = rtrim($directory, '/');

        if ($base !== '' && ! str_starts_with($path, $base.'/')) {
            throw new InvalidArgumentException("{$path} is not inside {$directory}.");
        }

        return ltrim(substr($path, strlen($base)), '/');
    }

    /**
     * Whether the file is covered by the baseline: known path, same size,
     * same content. Size is compared first so the hash is only computed
     * when it could possibly match.
     */
    public function skips(string $relative, string $absolute): bool
    {
        $entry = $this->files[$relative] ?? null;

        if ($entry === null) {
            return false;
        }

        if (@filesize($absolute) !== $entry['size']) {
            return false;
        }

        return @hash_file('xxh128', $absolute) === $entry['xxh128'];
    }

    /**
     * Add or refresh the entry for the file from its current on-disk
     * content. A file that vanished or turned unreadable in the meantime
     * is skipped rather than crashing the run; it either no longer needs
     * an entry or will simply re-enter the next scan.
     */
    public function record(string $relative, string $absolute): void
    {
        $size = @filesize($absolute);
        $hash = $size === false ? false : @hash_file('xxh128', $absolute);

        if ($size === false || $hash === false) {
            return;
        }

        $this->put($relative, $size, $hash);
    }

    /**
     * Add or refresh an entry from a size and hash that were already
     * computed, e.g. from bytes the caller had in memory.
     */
    public function put(string $relative, int $size, string $hash): void
    {
        $this->files[$relative] = ['size' => $size, 'xxh128' => $hash];
        $this->puts[$relative] = $this->files[$relative];
        unset($this->forgets[$relative]);
    }

    public function forget(string $relative): void
    {
        unset($this->files[$relative], $this->puts[$relative]);
        $this->forgets[$relative] = true;
    }

    /**
     * Drop entries whose file no longer exists under the directory. Goes
     * through forget() so the removals also land in the file at save
     * time, not just in this instance's snapshot.
     */
    public function prune(string $directory): void
    {
        $root = rtrim($directory, '/');

        foreach (array_keys($this->files) as $relative) {
            if (! is_file($root.'/'.$relative)) {
                $this->forget($relative);
            }
        }
    }

    public function count(): int
    {
        return count($this->files);
    }

    /**
     * Write the baseline out under an exclusive lock, replaying this
     * instance's puts and forgets onto the file's current contents so a
     * parallel run's entries survive. Fails loudly instead of quietly
     * losing entries: an unencodable filename (caught before the file is
     * opened, so a failed save never creates or truncates one), a
     * malformed file, or a failed write must never replace a healthy
     * committed baseline with garbage.
     */
    public function save(string $directory): void
    {
        $path = rtrim($directory, '/').'/'.self::FILENAME;

        if (json_encode($this->files) === false) {
            throw new ApiException("Could not encode {$path}: ".json_last_error_msg().'. Is a filename not valid UTF-8?');
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new ApiException("Could not write {$path}.");
        }

        try {
            flock($handle, LOCK_EX);

            $current = trim((string) stream_get_contents($handle));
            $merged = $current === '' ? [] : self::parse($current, $path);

            foreach (array_keys($this->forgets) as $relative) {
                unset($merged[$relative]);
            }

            foreach ($this->puts as $relative => $entry) {
                $merged[$relative] = $entry;
            }

            ksort($merged);

            $json = json_encode(['files' => $merged === [] ? new stdClass : $merged], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                throw new ApiException("Could not encode {$path}: ".json_last_error_msg().'. Is a filename not valid UTF-8?');
            }

            rewind($handle);
            $written = fwrite($handle, $json.PHP_EOL);

            if ($written === false || ! ftruncate($handle, $written)) {
                throw new ApiException("Could not write {$path}.");
            }

            $this->files = $merged;
            $this->puts = [];
            $this->forgets = [];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
