<?php

namespace MathiasGrimm\GlimpseCli\Commands\Concerns;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use MathiasGrimm\GlimpseCli\Glimpse\Config;
use MathiasGrimm\GlimpsePhp\ApiException;
use MathiasGrimm\GlimpsePhp\AuthException;
use MathiasGrimm\GlimpsePhp\ForbiddenException;
use MathiasGrimm\GlimpsePhp\RateLimitException;
use MathiasGrimm\GlimpsePhp\ValidationException;

trait GuardsApiErrors
{
    /**
     * @param  Closure(): (int|null)  $callback
     */
    protected function runGuarded(Closure $callback): int
    {
        try {
            return $callback() ?? self::SUCCESS;
        } catch (ValidationException $e) {
            $this->diagnostic($e->getMessage());

            foreach ($e->errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->diagnostic("{$field}: {$message}");
                }
            }

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->diagnostic('Could not reach the Glimpse API: '.$e->getMessage());

            return self::FAILURE;
        } catch (AuthException $e) {
            $this->diagnostic($e->getMessage().' Run: glimpse auth');
            // A rejected built-in token means this CLI build carries a
            // rotated-out token; the hint points at the way forward.
            $this->publicTokenHint();

            return self::FAILURE;
        } catch (RateLimitException $e) {
            $this->diagnostic($e->getMessage().($e->retryAfterSeconds !== null
                ? sprintf(' Retry after %d seconds.', $e->retryAfterSeconds)
                : ''));
            $this->publicTokenHint();

            return self::FAILURE;
        } catch (ForbiddenException $e) {
            $this->diagnostic($e->getMessage());
            $this->publicTokenHint();

            return self::FAILURE;
        } catch (ApiException $e) {
            $this->diagnostic($e->getMessage());

            return self::FAILURE;
        }
    }

    private function diagnostic(string $message): void
    {
        $machineOutput = ($this->hasOption('json') && $this->option('json'))
            || ($this->hasOption('output') && $this->option('output') === '-');

        if ($machineOutput) {
            fwrite(STDERR, $message.PHP_EOL);

            return;
        }

        $this->error($message);
    }

    /**
     * When the failed request went out with the built-in public CI token,
     * point at the real fix: a personal token.
     */
    private function publicTokenHint(): void
    {
        if (! app(Config::class)->usingPublicToken()) {
            return;
        }

        $this->diagnostic('You are using the built-in public CI token. It supports image commands with shared limits. Get your own free token at https://glimpseimg.com and set GLIMPSE_TOKEN for higher limits and your own usage dashboard.');
    }
}
