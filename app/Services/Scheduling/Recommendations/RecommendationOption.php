<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Recommendations;

use App\Services\Scheduling\Domain\SchedulingContract;

final readonly class RecommendationOption implements SchedulingContract
{
    /** @param array<string, mixed> $payload Exact legacy option, including any existing identity/rank. */
    public function __construct(
        public array $payload,
        public string $verificationStatus,
        public string $verificationScope,
    ) {}

    public function toArray(): array
    {
        return [
            'payload' => $this->payload,
            'verification' => [
                'status' => $this->verificationStatus,
                'scope' => $this->verificationScope,
                'complete_timetable_verified' => false,
                'requires_application_validation' => true,
            ],
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
