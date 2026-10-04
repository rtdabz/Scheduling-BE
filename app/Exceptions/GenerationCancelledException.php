<?php

namespace App\Exceptions;

use RuntimeException;

class GenerationCancelledException extends RuntimeException
{
    public function __construct(string $message = 'Generation was cancelled.')
    {
        parent::__construct($message);
    }
}
