<?php

namespace App\Commands\Concerns;

use App\Support\BaselineFile;

trait UpdatesBaseline
{
    /**
     * Keep an existing .glimpse-baseline.json current after writing an output
     * image: the nearest baseline up from the input gains entries for the
     * written file and for the source when it still exists (an in-place
     * write that changed the extension deletes it). A baseline is never
     * created here; that is `analyze --update-baseline`'s job. Stdin input
     * and stdout output have no path to record and are skipped, as is any
     * output written outside the baseline's root.
     */
    protected function recordInBaseline(string $input, string $outputPath): void
    {
        if ($input === '-') {
            return;
        }

        $dir = realpath(dirname($input));

        if ($dir === false) {
            return;
        }

        $root = BaselineFile::findRoot($dir);

        if ($root === null) {
            return;
        }

        $baseline = BaselineFile::load($root);
        $prefix = rtrim($root, '/').'/';
        $recorded = false;

        foreach (array_unique([$outputPath, $input]) as $path) {
            if ($path === '-') {
                continue;
            }

            $absolute = realpath($path);

            if ($absolute === false || ! is_file($absolute) || ! str_starts_with($absolute, $prefix)) {
                continue;
            }

            $baseline->record(substr($absolute, strlen($prefix)), $absolute);
            $recorded = true;
        }

        if ($recorded) {
            $baseline->save($root);
        }
    }
}
