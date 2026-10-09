<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Domain\SchedulingContract;

final readonly class SessionDescription implements SchedulingContract
{
    /** @param list<array<string, mixed>> $meetings Supplied rows or existing requirement-builder output; not feasibility evidence. */
    public function __construct(
        public string $kind,
        public string $delivery,
        public int $expectedMeetings,
        public bool $sameTimeRequired,
        public array $meetings,
        public ?string $legacyShape = null,
    ) {}

    public function label(): string
    {
        return match ($this->kind) {
            'balanced_split' => match ($this->delivery) {
                'online' => 'Online (All)',
                'on-site' => 'Split On-site',
                default => 'Split',
            },
            'hybrid_split' => 'Hybrid Split',
            'integrated' => match ($this->delivery) {
                'on-site' => 'Integrated On-site',
                'hybrid' => 'Integrated Hybrid',
                default => 'Integrated',
            },
            'consecutive' => 'Consecutive Days',
            'field' => 'Field',
            'regular' => 'Regular',
            default => 'Linked meetings',
        };
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'delivery' => $this->delivery,
            'label' => $this->label(),
            'expected_meetings' => $this->expectedMeetings,
            'same_time_required' => $this->sameTimeRequired,
            'distinct_days_required' => $this->expectedMeetings > 1,
            'meetings' => $this->meetings,
            'legacy_shape' => $this->legacyShape,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
