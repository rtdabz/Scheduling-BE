<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

use App\Services\Scheduling\Support\SchedulingPolicy;
use InvalidArgumentException;

final readonly class ScheduleRow implements SchedulingContract
{
    public function __construct(
        public int $termId,
        public int $sectionId,
        public int $courseId,
        public int $departmentId,
        public string $day,
        public string $startTime,
        public string $endTime,
        public string $mode,
        public ?int $facultyId = null,
        public ?int $roomId = null,
        public bool $isHybrid = false,
        public ?string $preferredPattern = null,
        public ?string $splitGroupId = null,
        public ?string $meetingType = null,
        public ?int $meetingIndex = null,
        public string $status = 'draft',
    ) {
        foreach ([$this->termId, $this->sectionId, $this->courseId, $this->departmentId] as $id) {
            if ($id <= 0) {
                throw new InvalidArgumentException('Schedule identity values must be positive integers.');
            }
        }

        if (! in_array($this->day, SchedulingPolicy::PERSISTABLE_DAYS, true)) {
            throw new InvalidArgumentException('Unsupported schedule day.');
        }

        if (! in_array($this->mode, SchedulingPolicy::DELIVERY_MODES, true)) {
            throw new InvalidArgumentException('Unsupported delivery mode.');
        }

        if (! in_array($this->status, SchedulingPolicy::SCHEDULE_STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported schedule status.');
        }

        if (SchedulingPolicy::timeToMinutes($this->startTime) >= SchedulingPolicy::timeToMinutes($this->endTime)) {
            throw new InvalidArgumentException('Schedule end time must be after its start time.');
        }

        if ($this->meetingType !== null && ! in_array($this->meetingType, ['lecture', 'laboratory'], true)) {
            throw new InvalidArgumentException('Unsupported meeting type.');
        }

        if ($this->meetingIndex !== null && $this->meetingIndex <= 0) {
            throw new InvalidArgumentException('Meeting index must be a positive integer.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            termId: (int) ($payload['term_id'] ?? 0),
            sectionId: (int) ($payload['section_id'] ?? 0),
            courseId: (int) ($payload['course_id'] ?? $payload['subject_id'] ?? 0),
            departmentId: (int) ($payload['department_id'] ?? 0),
            day: (string) ($payload['day'] ?? ''),
            startTime: (string) ($payload['start_time'] ?? ''),
            endTime: (string) ($payload['end_time'] ?? ''),
            mode: (string) ($payload['mode'] ?? 'on-site'),
            facultyId: self::nullablePositiveInt($payload['faculty_id'] ?? null),
            roomId: self::nullablePositiveInt($payload['room_id'] ?? null),
            isHybrid: filter_var($payload['is_hybrid'] ?? false, FILTER_VALIDATE_BOOLEAN),
            preferredPattern: self::nullableString($payload['preferred_pattern'] ?? null),
            splitGroupId: self::nullableString($payload['split_group_id'] ?? null),
            meetingType: self::nullableString($payload['meeting_type'] ?? null),
            meetingIndex: self::nullablePositiveInt($payload['meeting_index'] ?? null),
            status: (string) ($payload['status'] ?? 'draft'),
        );
    }

    public function isRoomResolved(): bool
    {
        return $this->mode === 'online' || $this->roomId !== null;
    }

    public function toArray(): array
    {
        return [
            'term_id' => $this->termId,
            'section_id' => $this->sectionId,
            'course_id' => $this->courseId,
            'faculty_id' => $this->facultyId,
            'room_id' => $this->roomId,
            'department_id' => $this->departmentId,
            'day' => $this->day,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'mode' => $this->mode,
            'is_hybrid' => $this->isHybrid,
            'preferred_pattern' => $this->preferredPattern,
            'split_group_id' => $this->splitGroupId,
            'meeting_type' => $this->meetingType,
            'meeting_index' => $this->meetingIndex,
            'status' => $this->status,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
