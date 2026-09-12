<?php

namespace MathiasGrimm\GlimpseCli\Commands;

use MathiasGrimm\GlimpseCli\Commands\Concerns\UpdatesBaseline;
use MathiasGrimm\GlimpsePhp\Client;

class ThumbnailCommand extends GlimpseCommand
{
    use UpdatesBaseline;

    protected $signature = 'thumbnail
        {input : Path to the image, or - for stdin}
        {--width= : Maximum width in pixels (API default 300)}
        {--height= : Maximum height in pixels (API default 300)}
        {--quality= : Re-encode quality 1-100 (API default 60)}
        {--o|output= : Output path, or - for stdout}
        {--i|in-place : Write the result over the input file}
        {--json : Print the result metadata as JSON}
        {--force : Overwrite the output file if it exists}';

    protected $description = 'Create an optimized thumbnail of an image';

    public function handle(Client $client): int
    {
        return $this->runGuarded(function () use ($client) {
            $input = $this->inputArgument();
            $output = $this->resolveOutput($input);

            $bytes = $this->readImage($input);
            $width = $this->intOption('width');
            $height = $this->intOption('height');
            $quality = $this->intOption('quality');

            $result = $this->imageWithRetry(fn () => $client->thumbnail(
                $bytes, $width, $height, $quality,
            ));

            $path = $this->writeResult($input, $output, 'thumb', $result);
            $this->recordInBaseline($input, $path, recordSource: false);
            $this->emit($result, $path);

            return self::SUCCESS;
        });
    }
}
