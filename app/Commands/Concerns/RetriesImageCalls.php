<?php

namespace MathiasGrimm\GlimpseCli\Commands\Concerns;

use Closure;
use MathiasGrimm\GlimpseCli\Support\Sleeper;
use MathiasGrimm\GlimpsePhp\RateLimitException;

trait RetriesImageCalls
{
    private const RATE_LIMIT_MAX_RETRIES = 3;

    private const RATE_LIMIT_DEFAULT_DELAY_SECONDS = 5;

    private const RATE_LIMIT_MAX_DELAY_SECONDS = 60;

    /**
     * Run one image API call with bounded retries. Wait out the
     * Retry-After delay for a few attempts, then give up and let the
     * rate limit exception propagate. A delay beyond the cap means the
     * limit window outlives any sane retry budget (retrying inside it is
     * a guaranteed 429), so that gives up right away.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    protected function imageWithRetry(Closure $call): mixed
    {
        $retries = 0;

        while (true) {
            try {
                return $call();
            } catch (RateLimitException $exception) {
                $delay = $exception->retryAfterSeconds ?? self::RATE_LIMIT_DEFAULT_DELAY_SECONDS;

                if ($retries++ >= self::RATE_LIMIT_MAX_RETRIES || $delay > self::RATE_LIMIT_MAX_DELAY_SECONDS) {
                    throw $exception;
                }

                // Stderr, so --json consumers reading stdout stay parseable,
                // and the wait never looks like a hang.
                fwrite(STDERR, sprintf('Rate limited; retrying in %ds.%s', $delay, PHP_EOL));

                app(Sleeper::class)->sleep($delay);
            }
        }
    }
}
