<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Engine\Solver;

use App\Services\Scheduling\Domain\SchedulingContract;
use InvalidArgumentException;

final readonly class SolverVariableDomain implements SchedulingContract
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $courseId,
        public array $candidates,
        public array $metadata = [],
    ) {
        if ($this->courseId <= 0) {
            throw new InvalidArgumentException('A solver variable requires a course ID.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $known = ['course_id', 'domain', 'candidates'];

        return new self(
            courseId: (int) ($payload['course_id'] ?? 0),
            candidates: array_values(is_array($payload['domain'] ?? null)
                ? $payload['domain']
                : (is_array($payload['candidates'] ?? null) ? $payload['candidates'] : [])),
            metadata: array_diff_key($payload, array_flip($known)),
        );
    }

    public function withCandidates(array $candidates): self
    {
        return new self($this->courseId, array_values($candidates), $this->metadata);
    }

    public function toArray(): array
    {
        return array_merge($this->metadata, [
            'course_id' => $this->courseId,
            'domain' => $this->candidates,
        ]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
