<?php

namespace App\Services\Curriculum;

use RuntimeException;

final class CurriculumCourseHoursBlocked extends RuntimeException
{
    /** @param  array<string, mixed>  $context */
    public function __construct(string $message, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
