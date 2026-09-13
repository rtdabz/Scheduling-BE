<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised inside the generator when the durable run has been marked cancelled
 * while the search was still going. It is not a generation failure: the run
 * record is already terminal, so the job unwinds without touching the status.
 */
class GenerationCancelledException extends RuntimeException
{
    public function __construct(string $message = 'Generation was cancelled.')
    {
        parent::__construct($message);
    }
}
