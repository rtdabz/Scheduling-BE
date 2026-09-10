<?php

namespace App\Services\Scheduling;

use App\Models\Curriculum;
use App\Models\Sections;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single answer to "which curriculum does this section follow?".
 *
 * Every scheduling stage — preflight, feasibility, snapshot, solver, validator —
 * must resolve a course's year level and semester from the *same* curriculum. If
 * two stages disagree, a placement the validator refuses becomes a candidate the
 * solver happily produces, and the run fails with a message that describes
 * neither problem. Routing every lookup through here is what keeps them aligned.
 *
 * There is deliberately no fallback to "the department's active curriculum". That
 * guess is exactly what this feature removes: a department mid-transition has
 * several, and picking one silently would schedule a cohort against the wrong
 * course list.
 */
class SectionCurriculumResolver
{
    /** @var array<int, Curriculum> */
    private array $cache = [];

    /**
     * @throws InvalidArgumentException when the section has no usable curriculum
     */
    public function forSection(Sections $section): Curriculum
    {
        $sectionId = (int) $section->id;
        if (isset($this->cache[$sectionId])) {
            return $this->cache[$sectionId];
        }

        $curriculumId = $section->curriculum_id === null
            ? $this->adoptUnambiguousCurriculum($section)
            : (int) $section->curriculum_id;

        if ($curriculumId === null) {
            throw new InvalidArgumentException(sprintf(
                'Section %s is not assigned to a curriculum. Choose the curriculum this year level follows before generating a schedule.',
                (string) $section->section_name,
            ));
        }

        $curriculum = $section->relationLoaded('curriculum') && $section->curriculum !== null
            ? $section->curriculum
            : Curriculum::query()->find($curriculumId);

        if ($curriculum === null) {
            throw new InvalidArgumentException(sprintf(
                'Section %s points at a curriculum that no longer exists. Reassign the section before generating a schedule.',
                (string) $section->section_name,
            ));
        }

        if ((string) $curriculum->status !== 'active') {
            throw new InvalidArgumentException(sprintf(
                'Section %s follows "%s", which is %s. Activate that curriculum or move the section to an active one.',
                (string) $section->section_name,
                (string) $curriculum->name,
                (string) $curriculum->status,
            ));
        }

        if ((int) $curriculum->department_id !== (int) $section->department_id) {
            throw new InvalidArgumentException(sprintf(
                'Section %s follows a curriculum belonging to another department.',
                (string) $section->section_name,
            ));
        }

        return $this->cache[$sectionId] = $curriculum;
    }

    /**
     * Adopts the department's curriculum when there is only one to adopt.
     *
     * Sections are routinely created before a curriculum is published, so the
     * column being null does not mean somebody declined to choose — it usually
     * means there was nothing to choose from yet. Once exactly one selectable
     * curriculum exists it is the only possible answer, so take it and persist
     * it, which keeps every other consumer agreeing with the generator.
     *
     * Two or more and this returns null: the caller must ask a human.
     */
    private function adoptUnambiguousCurriculum(Sections $section): ?int
    {
        $selectable = Curriculum::query()
            ->selectableFor(
                (int) $section->department_id,
                $section->program_id === null ? null : (int) $section->program_id,
            )
            ->pluck('id');

        if ($selectable->count() !== 1) {
            return null;
        }

        $curriculumId = (int) $selectable->first();

        $section->curriculum_id = $curriculumId;
        $section->saveQuietly();

        return $curriculumId;
    }

    /** The curriculum id per section, for callers that batch by section. */
    public function idsBySection(iterable $sections): array
    {
        $ids = [];
        foreach ($sections as $section) {
            $ids[(int) $section->id] = (int) $this->forSection($section)->id;
        }

        return $ids;
    }

    /**
     * Course placements within one curriculum, keyed by course id.
     *
     * @param  list<int>  $courseIds  empty means every course in the curriculum
     * @return Collection<int, object{course_id: int, year_level: int, semester: int}>
     */
    public function periods(int $curriculumId, array $courseIds = []): Collection
    {
        return DB::table('curriculum_course')
            ->where('curriculum_id', $curriculumId)
            ->when($courseIds !== [], fn ($query) => $query->whereIn('course_id', $courseIds))
            ->orderBy('course_id')
            ->get(['course_id', 'year_level', 'semester'])
            ->keyBy(static fn (object $period): int => (int) $period->course_id);
    }

    /**
     * Placements across several curricula, keyed "curriculumId:courseId".
     *
     * A single generation run can span curricula when a year level is mid-split,
     * so a course-id-keyed map is not enough — the same course can sit at
     * different year levels in each.
     *
     * @param  list<int>  $curriculumIds
     * @param  list<int>  $courseIds
     */
    public function periodsForMany(array $curriculumIds, array $courseIds = []): Collection
    {
        if ($curriculumIds === []) {
            return collect();
        }

        return DB::table('curriculum_course')
            ->whereIn('curriculum_id', $curriculumIds)
            ->when($courseIds !== [], fn ($query) => $query->whereIn('course_id', $courseIds))
            ->orderBy('curriculum_id')
            ->orderBy('course_id')
            ->get(['curriculum_id', 'course_id', 'year_level', 'semester'])
            ->keyBy(static fn (object $period): string => self::periodKey((int) $period->curriculum_id, (int) $period->course_id));
    }

    public static function periodKey(int $curriculumId, int $courseId): string
    {
        return $curriculumId.':'.$courseId;
    }
}
