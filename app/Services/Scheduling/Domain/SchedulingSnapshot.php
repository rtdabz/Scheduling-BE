<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SchedulingSnapshot implements SchedulingContract
{
    /**
     * 2: sections carry curriculum_id and placements are keyed per curriculum.
     *
     * The bump matters as much as the fields: the fingerprint feeds a cache, and
     * a version-1 payload would otherwise be replayed against curriculum-aware
     * code that expects the new keys.
     */
    public const SCHEMA_VERSION = 2;

    /**
     * @param  array<int, array<string, mixed>>  $sectionsById
     * @param  array<int, array<string, mixed>>  $coursesById
     * @param  array<int, array<string, mixed>>  $roomsById
     * @param  list<array<string, mixed>>  $persistedSchedules
     * @param  array<int, array<string, mixed>>  $facultiesById
     * @param  array<int, string>  $forcedDaysByCourseId
     * @param  list<string>  $fieldCourseCodes
     * @param  array<int, array<string, mixed>>  $curriculumPeriodsByCourseId
     * @param  array<string, array<string, mixed>>  $curriculumPeriodsByCurriculumCourse  keyed "curriculumId:courseId"
     * @param  array<int, int>  $curriculumIdBySectionId
     * @param  array<string, int>  $resourceLimits
     * @param  array<string, int|string>  $operatingHours
     * @param  array<string, mixed>  $departmentSettings
     * @param  array<string, mixed>  $term
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $fingerprint,
        public DateTimeImmutable $capturedAt,
        public int $termId,
        public int $departmentId,
        public array $sectionsById = [],
        public array $coursesById = [],
        public array $roomsById = [],
        public array $persistedSchedules = [],
        public array $facultiesById = [],
        public array $forcedDaysByCourseId = [],
        public array $fieldCourseCodes = [],
        public array $curriculumPeriodsByCourseId = [],
        public array $curriculumPeriodsByCurriculumCourse = [],
        public array $curriculumIdBySectionId = [],
        public array $resourceLimits = [],
        public array $operatingHours = [],
        public array $departmentSettings = [],
        public array $term = [],
        public array $metadata = [],
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($this->fingerprint === '' || $this->termId <= 0 || $this->departmentId <= 0) {
            throw new InvalidArgumentException('Scheduling snapshot identity is incomplete.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            fingerprint: (string) ($payload['fingerprint'] ?? ''),
            capturedAt: new DateTimeImmutable((string) ($payload['captured_at'] ?? 'now')),
            termId: (int) ($payload['term_id'] ?? 0),
            departmentId: (int) ($payload['department_id'] ?? 0),
            sectionsById: self::intKeyedRecords($payload['sections'] ?? []),
            coursesById: self::intKeyedRecords($payload['courses'] ?? []),
            roomsById: self::intKeyedRecords($payload['rooms'] ?? []),
            persistedSchedules: array_values(is_array($payload['persisted_schedules'] ?? null) ? $payload['persisted_schedules'] : []),
            facultiesById: self::intKeyedRecords($payload['faculties'] ?? []),
            forcedDaysByCourseId: self::intKeyedRecords($payload['forced_days_by_course_id'] ?? []),
            fieldCourseCodes: array_values(array_map('strval', is_array($payload['field_course_codes'] ?? null) ? $payload['field_course_codes'] : [])),
            curriculumPeriodsByCourseId: self::intKeyedRecords($payload['curriculum_periods_by_course_id'] ?? []),
            curriculumPeriodsByCurriculumCourse: is_array($payload['curriculum_periods_by_curriculum_course'] ?? null)
                ? $payload['curriculum_periods_by_curriculum_course']
                : [],
            curriculumIdBySectionId: array_map('intval', self::intKeyedRecords($payload['curriculum_id_by_section_id'] ?? [])),
            resourceLimits: is_array($payload['resource_limits'] ?? null) ? $payload['resource_limits'] : [],
            operatingHours: is_array($payload['operating_hours'] ?? null) ? $payload['operating_hours'] : [],
            departmentSettings: is_array($payload['department_settings'] ?? null) ? $payload['department_settings'] : [],
            term: is_array($payload['term'] ?? null) ? $payload['term'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            schemaVersion: (int) ($payload['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'fingerprint' => $this->fingerprint,
            'captured_at' => $this->capturedAt->format(DATE_ATOM),
            'term_id' => $this->termId,
            'department_id' => $this->departmentId,
            'sections' => $this->sectionsById,
            'courses' => $this->coursesById,
            'rooms' => $this->roomsById,
            'persisted_schedules' => $this->persistedSchedules,
            'faculties' => $this->facultiesById,
            'forced_days_by_course_id' => $this->forcedDaysByCourseId,
            'field_course_codes' => $this->fieldCourseCodes,
            'curriculum_periods_by_course_id' => $this->curriculumPeriodsByCourseId,
            'curriculum_periods_by_curriculum_course' => $this->curriculumPeriodsByCurriculumCourse,
            'curriculum_id_by_section_id' => $this->curriculumIdBySectionId,
            'resource_limits' => $this->resourceLimits,
            'operating_hours' => $this->operatingHours,
            'department_settings' => $this->departmentSettings,
            'term' => $this->term,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Where a course sits in the curriculum *this section* follows.
     *
     * A run can span curricula when a year level is mid-transition, and the same
     * course can sit at different year levels in each, so asking by course id
     * alone is not a well-formed question. The course-keyed map remains as the
     * fallback for snapshots captured before placements were curriculum-scoped.
     *
     * @return array<string, mixed>|null
     */
    public function periodFor(int $sectionId, int $courseId): ?array
    {
        $curriculumId = $this->curriculumIdBySectionId[$sectionId] ?? null;

        if ($curriculumId !== null) {
            $scoped = $this->curriculumPeriodsByCurriculumCourse[$curriculumId.':'.$courseId] ?? null;
            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $this->curriculumPeriodsByCourseId[$courseId] ?? null;
    }

    /** @return array<int, mixed> */
    private static function intKeyedRecords(mixed $records): array
    {
        if (! is_array($records)) {
            return [];
        }

        $normalized = [];
        foreach ($records as $key => $record) {
            $id = (int) $key;
            if ($id > 0) {
                $normalized[$id] = $record;
            }
        }

        return $normalized;
    }
}
