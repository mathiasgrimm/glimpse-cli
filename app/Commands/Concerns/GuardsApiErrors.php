<?php

namespace App\Commands\Concerns;

use App\Glimpse\ApiException;
use App\Glimpse\ValidationException;
use Closure;
use Illuminate\Http\Client\ConnectionException;

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
            $this->error($e->getMessage());

            foreach ($e->errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->line("  <fg=red>{$field}</>: {$message}");
                }
            }

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error('Could not reach the Glimpse API: '.$e->getMessage());

            return self::FAILURE;
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
