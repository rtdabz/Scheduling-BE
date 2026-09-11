<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Constraints;

use App\Services\Scheduling\Domain\ScheduleRow;
use InvalidArgumentException;

final readonly class SchedulingConstraintEvaluationContext
{
    /**
     * @param  list<ScheduleRow>  $additionalRows
     * @param  list<int>  $ignoreScheduleIds
     */
    public function __construct(
        public array $additionalRows = [],
        public array $ignoreScheduleIds = [],
    ) {
        foreach ($this->additionalRows as $row) {
            if (! $row instanceof ScheduleRow) {
                throw new InvalidArgumentException('Constraint context rows must use ScheduleRow.');
            }
        }
    }
}
