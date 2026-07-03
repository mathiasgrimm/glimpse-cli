<?php

namespace App\Glimpse;

class ValidationException extends ApiException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
