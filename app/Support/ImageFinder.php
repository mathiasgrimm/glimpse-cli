<?php

namespace MathiasGrimm\GlimpseCli\Support;

use FilesystemIterator;
use MathiasGrimm\GlimpsePhp\ImageFormat;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ImageFinder
{
    /**
     * Find every image file under the directory, recursively, sorted by
     * pathname. Dot entries (directories and files) are skipped: .git is
     * never wanted, and macOS AppleDouble files (._photo.jpg) carry image
     * extensions without being images. Symlinked files and directories
     * are skipped so scans cannot read or overwrite targets outside the
     * directory or bypass its ignore rules through an alias. A
     * .glimpseignore file in the current working directory excludes
     * further paths, matched by their working-directory-relative path; a
     * directory scanned from outside the working directory is beyond the
     * ignore file's reach, so nothing in it is excluded.
     *
     * @return list<string>
     */
    public function find(string $directory): array
    {
        $root = rtrim($directory, '/');
        $prefix = Paths::keyPrefix(Paths::root(), $directory);
        $ignore = $prefix === null ? IgnoreFile::none() : IgnoreFile::load(Paths::root());

        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) use ($root, $prefix, $ignore): bool {
                if (str_starts_with($file->getFilename(), '.')) {
                    return false;
                }

                if ($file->isLink()) {
                    return false;
                }

                if (! $file->isDir() && ImageFormat::fromExtension($file->getExtension()) === null) {
                    return false;
                }

                $relative = $prefix.ltrim(substr($file->getPathname(), strlen($root)), '/\\');

                return ! $ignore->ignores($relative, $file->isDir());
            },
        ));

        $paths = [];

        foreach ($files as $file) {
            $paths[] = $file->getPathname();
        }

        sort($paths, SORT_STRING);

        return $paths;
    }
}
