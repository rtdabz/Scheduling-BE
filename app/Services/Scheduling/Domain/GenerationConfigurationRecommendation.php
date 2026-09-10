<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use InvalidArgumentException;

final readonly class GenerationConfigurationRecommendation implements SchedulingContract
{
    /** @param list<array<string, mixed>> $adjustments */
    public function __construct(
        public string $id,
        public string $title,
        public string $detectedCause,
        public string $suggestedAdjustment,
        public string $impact,
        public array $adjustments = [],
        public ?int $sectionId = null,
        public ?string $sectionName = null,
        public ?int $courseId = null,
        public ?string $courseCode = null,
    ) {
        if ($this->id === '' || $this->title === '' || $this->detectedCause === '' || $this->suggestedAdjustment === '') {
            throw new InvalidArgumentException('Configuration recommendation content is incomplete.');
        }

        if (! in_array($this->impact, ['low', 'medium', 'high'], true)) {
            throw new InvalidArgumentException('Unsupported recommendation impact.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) ($payload['id'] ?? ''),
            title: (string) ($payload['title'] ?? ''),
            detectedCause: (string) ($payload['detected_cause'] ?? ''),
            suggestedAdjustment: (string) ($payload['suggested_adjustment'] ?? ''),
            impact: (string) ($payload['impact'] ?? 'medium'),
            adjustments: array_values(is_array($payload['adjustments'] ?? null) ? $payload['adjustments'] : []),
            sectionId: self::nullablePositiveInt($payload['section_id'] ?? null),
            sectionName: self::nullableString($payload['section_name'] ?? null),
            courseId: self::nullablePositiveInt($payload['course_id'] ?? null),
            courseCode: self::nullableString($payload['course_code'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'detected_cause' => $this->detectedCause,
            'suggested_adjustment' => $this->suggestedAdjustment,
            'section_id' => $this->sectionId,
            'section_name' => $this->sectionName,
            'course_id' => $this->courseId,
            'course_code' => $this->courseCode,
            'impact' => $this->impact,
            'adjustments' => $this->adjustments,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
