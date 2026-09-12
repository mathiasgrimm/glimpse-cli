<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use MathiasGrimm\GlimpseCli\Support\BaselineFile;
use MathiasGrimm\GlimpseCli\Support\Paths;
use MathiasGrimm\GlimpsePhp\ApiException;
use MathiasGrimm\GlimpsePhp\ImageFormat;
use Symfony\Component\Process\Process;

class SkipCommand extends GlimpseCommand
{
    protected $signature = 'skip
        {input : Path to the image to restore and skip}
        {--from=HEAD : Git commit containing the original image}';

    protected $description = 'Restore an image from Git and skip its unchanged contents in future checks';

    public function handle(): int
    {
        return $this->runGuarded(function (): int {
            $root = Paths::root();
            $gitRoot = trim($this->git(['rev-parse', '--show-toplevel']));

            if (Paths::canonical($gitRoot) !== $root) {
                throw new ApiException('Run glimpse skip from the Git repository root.');
            }

            $input = $this->inputArgument();
            $directory = realpath(dirname($input));

            if ($directory === false || is_link($input)) {
                throw new ApiException('The image must have an existing directory and must not be a symlink.');
            }

            for ($parent = dirname($input); $parent !== '.' && $parent !== dirname($parent); $parent = dirname($parent)) {
                if (is_link($parent) && Paths::contains($root, Paths::canonical(dirname($parent)).'/'.basename($parent))) {
                    throw new ApiException('The image path must not pass through a symlink.');
                }
            }

            $path = $directory.'/'.basename($input);

            if (! Paths::contains($root, $path) || is_dir($path)) {
                throw new ApiException('The image must be a file inside the repository.');
            }

            $relative = Paths::relativePath($root, $path);
            $from = $this->option('from');
            $commit = trim($this->git(['rev-parse', '--verify', '--end-of-options', (is_string($from) ? $from : 'HEAD').'^{commit}']));
            $entry = $this->git(['ls-tree', '-z', $commit, '--', $relative]);

            if (! preg_match('/\A(100644|100755) blob ([0-9a-f]+)\t/', $entry, $matches)) {
                throw new ApiException("No original regular file exists in {$commit}: {$relative}. Nothing changed.");
            }

            $original = $this->git(['cat-file', 'blob', $matches[2]]);

            if (ImageFormat::tryFromBinary($original) === null) {
                throw new ApiException("The original file is not a supported image: {$relative}.");
            }

            if (is_link($root.'/'.BaselineFile::FILENAME)) {
                throw new ApiException('The baseline must not be a symlink. Nothing changed.');
            }

            $baseline = BaselineFile::load($root, forUpdate: true);
            $previous = is_file($path) ? @file_get_contents($path) : null;

            if ($previous === false) {
                throw new ApiException("Could not read {$relative}. Nothing changed.");
            }

            $mode = is_file($path) ? (fileperms($path) & 0777) : ($matches[1] === '100755' ? 0755 : 0644);
            $baseline->put($relative, strlen($original), hash('xxh128', $original), 'skip');
            $this->writeFile($path, $original, $mode);

            try {
                $baseline->save($root);
            } catch (ApiException $exception) {
                if ($previous === null) {
                    if (! @unlink($path)) {
                        throw new ApiException("Baseline update failed and could not remove restored file {$relative}.", previous: $exception);
                    }
                } else {
                    $this->writeFile($path, $previous, $mode);
                }

                throw $exception;
            }

            $this->info("Restored {$relative} from {$commit} and recorded via: skip. Commit the image and ".BaselineFile::FILENAME.' together.');

            return self::SUCCESS;
        });
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = new Process(['git', '--literal-pathspecs', ...$arguments], Paths::root());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ApiException('Could not read the original from Git: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    private function writeFile(string $path, string $bytes, int $mode): void
    {
        $temporary = tempnam(dirname($path), '.glimpse-skip-');

        if ($temporary === false) {
            throw new ApiException("Could not prepare {$path} for writing.");
        }

        try {
            if (@file_put_contents($temporary, $bytes) !== strlen($bytes)
                || ! @chmod($temporary, $mode)
                || ! @rename($temporary, $path)) {
                throw new ApiException("Could not write {$path}.");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
