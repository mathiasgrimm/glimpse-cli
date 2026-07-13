<?php

namespace App\Support;

use GlimpseImg\ApiException;
use stdClass;

/**
 * A .glimpse-baseline.json file: a JSON list of already-processed files at the
 * scan root, so analyze and check skip them. Entries match on relative
 * path plus size plus xxh128 content hash; a file whose content changed
 * re-enters the scan. The hash is for change detection, not security, so
 * a fast non-cryptographic digest is the right tool.
 */
final class BaselineFile
{
    public const FILENAME = '.glimpse-baseline.json';

    /**
     * @param  array<string, array{size: int, xxh128: string}>  $files
     */
    private function __construct(private array $files) {}

    /**
     * Load the baseline at the directory. A missing or empty file is an
     * empty baseline; a malformed one fails loudly so a typo never turns
     * into a silently un-baselined (or fully skipped) scan.
     */
    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/').'/'.self::FILENAME;

        if (! is_file($path)) {
            return new self([]);
        }

        $content = trim((string) file_get_contents($path));

        if ($content === '') {
            return new self([]);
        }

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

        return new self($files);
    }

    /**
     * Walk up from the directory to the filesystem root and return the
     * first directory containing a baseline file, or null when there is
     * none. Used by convert/optimize to keep an existing baseline current
     * without ever creating one.
     */
    public static function findRoot(string $directory): ?string
    {
        $dir = rtrim($directory, '/');

        if ($dir === '') {
            $dir = '/';
        }

        while (true) {
            if (is_file($dir.'/'.self::FILENAME)) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
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

        return hash_file('xxh128', $absolute) === $entry['xxh128'];
    }

    /**
     * Add or refresh the entry for the file.
     */
    public function record(string $relative, string $absolute): void
    {
        $this->files[$relative] = [
            'size' => (int) filesize($absolute),
            'xxh128' => (string) hash_file('xxh128', $absolute),
        ];
    }

    /**
     * Drop entries whose file no longer exists under the directory.
     */
    public function prune(string $directory): void
    {
        $root = rtrim($directory, '/');

        foreach (array_keys($this->files) as $relative) {
            if (! is_file($root.'/'.$relative)) {
                unset($this->files[$relative]);
            }
        }
    }

    public function count(): int
    {
        return count($this->files);
    }

    public function save(string $directory): void
    {
        ksort($this->files);

        $files = $this->files === [] ? new stdClass : $this->files;

        file_put_contents(
            rtrim($directory, '/').'/'.self::FILENAME,
            json_encode(['files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }
}
