<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use JsonSerializable;

interface SchedulingContract extends JsonSerializable
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    /** @return array<string, mixed> */
    public function jsonSerialize(): array;
}
