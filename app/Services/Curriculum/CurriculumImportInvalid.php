<?php

namespace App\Services\Curriculum;

use RuntimeException;

final class CurriculumImportInvalid extends RuntimeException
{
    /** @param  array<string, mixed>  $validation */
    public function __construct(public readonly array $validation)
    {
        parent::__construct('The import draft has unresolved issues.');
    }
}
