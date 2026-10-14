<?php

namespace App\Services\YouTube;

use RuntimeException;

class ApiFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false)
    {
        // Never include Google/Guzzle messages, response bodies, URLs or the previous exception.
        parent::__construct($reason);
    }
}
