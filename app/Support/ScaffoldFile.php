<?php

namespace App\Support;

use GlimpseImg\ApiException;

/**
 * Writes the files init scaffolds from its templates, safely in both
 * modes: creation is exclusive, so a file that appeared between the
 * caller's existence check and the write fails the write instead of
 * being truncated, and replacement goes through a private temporary
 * file swapped in with an atomic rename, so a failed or short write
 * leaves the previous file untouched. Symbolic links are refused
 * outright, because replacing one would silently rewrite whatever it
 * points at. Missing parent directories are created.
 */
final class ScaffoldFile
{
    public static function write(string $path, string $content, bool $replace = false): void
    {
        if (is_link($path)) {
            throw new ApiException("Could not write {$path}: it is a symbolic link.");
        }

        self::ensureDirectory(dirname($path));

        $replace
            ? self::swapIn($path, $content)
            : self::createExclusive($path, $content);
    }

    /**
     * The silenced mkdir plus re-check tolerates a concurrent mkdir of
     * the same path; a parent that exists as a regular file (a repo
     * with a `.github` file, say) still fails cleanly here.
     */
    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new ApiException("Could not create the directory {$directory}.");
        }
    }

    private static function createExclusive(string $path, string $content): void
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            throw new ApiException("Could not write {$path}.");
        }

        $written = @fwrite($handle, $content);
        fclose($handle);

        if ($written !== strlen($content)) {
            @unlink($path);

            throw new ApiException("Could not write {$path}.");
        }
    }

    private static function swapIn(string $path, string $content): void
    {
        $temporary = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        $written = @file_put_contents($temporary, $content);

        if ($written !== strlen($content) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new ApiException("Could not write {$path}.");
        }
    }
}
