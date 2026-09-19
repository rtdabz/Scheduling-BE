<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use App\Services\Scheduling\Support\SchedulingPolicy;
use InvalidArgumentException;

final readonly class GenerationConfiguration implements SchedulingContract
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<int>  $courseIds
     * @param  array<int, string|null>  $preferredPatternsByCourseId
     * @param  list<int>  $selectedSplitSessionCourseIds
     * @param  list<int>  $balancedSplitCourseIds
     * @param  list<int>  $hybridSplitCourseIds
     * @param  array<int, string>  $deliveryModesByCourseId
     * @param  array<int, array<string, mixed>>  $requirementsByCourseId
     * @param  array<int, list<array<string, mixed>>>  $anchoredSchedulesByCourseId
     * @param  list<array<string, mixed>>  $tentativeSchedules
     */
    public function __construct(
        public int $sectionId,
        public array $courseIds,
        public string $deliveryMode = 'on-site',
        public bool $isHybrid = false,
        public array $preferredPatternsByCourseId = [],
        public array $selectedSplitSessionCourseIds = [],
        public array $balancedSplitCourseIds = [],
        public array $hybridSplitCourseIds = [],
        public array $deliveryModesByCourseId = [],
        public array $requirementsByCourseId = [],
        public array $anchoredSchedulesByCourseId = [],
        public array $tentativeSchedules = [],
        public int $maxSolutions = 2,
        public int $maxIterations = 250_000,
        public float $timeoutSeconds = 8.0,
        public ?int $seed = null,
        public bool $throwOnEmptyDomain = true,
        public bool $allowRoomTbaFallback = true,
        public bool $allowOnlineFallback = true,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->sectionId <= 0 || $this->courseIds === []) {
            throw new InvalidArgumentException('Generation requires a section and at least one course.');
        }

        if (! in_array($this->deliveryMode, SchedulingPolicy::DELIVERY_MODES, true)) {
            throw new InvalidArgumentException('Unsupported generation delivery mode.');
        }

        if ($this->maxSolutions <= 0 || $this->maxIterations <= 0 || $this->timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Generation search limits must be positive.');
        }

        foreach ($this->preferredPatternsByCourseId as $pattern) {
            if (! SchedulingPolicy::isValidPreferredPattern($pattern)) {
                throw new InvalidArgumentException('Unsupported preferred meeting pattern.');
            }
        }

        foreach ($this->deliveryModesByCourseId as $mode) {
            if (! in_array($mode, SchedulingPolicy::DELIVERY_MODES, true)) {
                throw new InvalidArgumentException('Unsupported course delivery mode.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $courseIds = self::positiveIntList($payload['course_ids'] ?? $payload['courseIds'] ?? []);

        return new self(
            sectionId: (int) ($payload['section_id'] ?? $payload['sectionId'] ?? 0),
            courseIds: $courseIds,
            deliveryMode: (string) ($payload['delivery_mode'] ?? $payload['deliveryMode'] ?? $payload['mode'] ?? 'on-site'),
            isHybrid: filter_var($payload['is_hybrid'] ?? $payload['isHybrid'] ?? false, FILTER_VALIDATE_BOOLEAN),
            preferredPatternsByCourseId: self::intKeyedMap($payload['preferred_patterns'] ?? $payload['preferredPatternsByCourseId'] ?? []),
            selectedSplitSessionCourseIds: self::positiveIntList($payload['selected_split_session_course_ids'] ?? $payload['selectedSplitSessionCourseIds'] ?? []),
            balancedSplitCourseIds: self::positiveIntList($payload['balanced_split_course_ids'] ?? $payload['balancedSplitCourseIds'] ?? []),
            hybridSplitCourseIds: self::positiveIntList($payload['hybrid_split_course_ids'] ?? $payload['hybridSplitCourseIds'] ?? []),
            deliveryModesByCourseId: self::intKeyedMap($payload['delivery_modes_by_course_id'] ?? $payload['deliveryModesByCourseId'] ?? []),
            requirementsByCourseId: self::intKeyedMap($payload['requirements_by_course_id'] ?? $payload['requirementsByCourseId'] ?? []),
            anchoredSchedulesByCourseId: self::intKeyedMap($payload['anchored_schedules'] ?? $payload['anchoredSchedules'] ?? []),
            tentativeSchedules: array_values(is_array($payload['tentative_schedules'] ?? null) ? $payload['tentative_schedules'] : []),
            maxSolutions: (int) ($payload['max_solutions'] ?? $payload['maxSolutions'] ?? 2),
            maxIterations: (int) ($payload['max_iterations'] ?? $payload['maxIterations'] ?? 250_000),
            timeoutSeconds: (float) ($payload['timeout_seconds'] ?? $payload['timeoutSeconds'] ?? 8.0),
            seed: isset($payload['seed']) ? (int) $payload['seed'] : null,
            throwOnEmptyDomain: filter_var($payload['throw_on_empty_domain'] ?? $payload['throwOnEmptyDomain'] ?? true, FILTER_VALIDATE_BOOLEAN),
            allowRoomTbaFallback: filter_var($payload['allow_room_tba_fallback'] ?? $payload['allowRoomTbaFallback'] ?? true, FILTER_VALIDATE_BOOLEAN),
            allowOnlineFallback: filter_var($payload['allow_online_fallback'] ?? $payload['allowOnlineFallback'] ?? true, FILTER_VALIDATE_BOOLEAN),
            schemaVersion: (int) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'section_id' => $this->sectionId,
            'course_ids' => $this->courseIds,
            'delivery_mode' => $this->deliveryMode,
            'is_hybrid' => $this->isHybrid,
            'preferred_patterns' => $this->preferredPatternsByCourseId,
            'selected_split_session_course_ids' => $this->selectedSplitSessionCourseIds,
            'balanced_split_course_ids' => $this->balancedSplitCourseIds,
            'hybrid_split_course_ids' => $this->hybridSplitCourseIds,
            'delivery_modes_by_course_id' => $this->deliveryModesByCourseId,
            'requirements_by_course_id' => $this->requirementsByCourseId,
            'anchored_schedules' => $this->anchoredSchedulesByCourseId,
            'tentative_schedules' => $this->tentativeSchedules,
            'max_solutions' => $this->maxSolutions,
            'max_iterations' => $this->maxIterations,
            'timeout_seconds' => $this->timeoutSeconds,
            'seed' => $this->seed,
            'throw_on_empty_domain' => $this->throwOnEmptyDomain,
            'allow_room_tba_fallback' => $this->allowRoomTbaFallback,
            'allow_online_fallback' => $this->allowOnlineFallback,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return list<int> */
    private static function positiveIntList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = array_map('intval', $values);
        $normalized = array_values(array_filter($normalized, static fn (int $value): bool => $value > 0));

        return array_values(array_unique($normalized));
    }

    /** @return array<int, mixed> */
    private static function intKeyedMap(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $id = (int) $key;
            if ($id > 0) {
                $normalized[$id] = $value;
            }
        }

        return $normalized;
    }
}
